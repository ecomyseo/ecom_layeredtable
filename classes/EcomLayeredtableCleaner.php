<?php
/**
 * Ecom Layered Table - keeps the ps_facetedsearch filter block cache under control
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Measures and cleans the ps_facetedsearch filter block cache table.
 *
 * Rules of the game, learnt on a real 4.8 GB table:
 *  - never read the "data" column to know its size (LENGTH() fetches the blob: 45 KB per row);
 *  - the size cap is the first thing that runs, and it always gets at least one batch;
 *  - when most of the table has to go, TRUNCATE (the cache regenerates itself);
 *  - every other step works in batches and stops when the time budget is over.
 */
class EcomLayeredtableCleaner
{
    const BLOCK_TABLE = 'layered_filter_block';
    const META_TABLE = 'ecom_layeredtable_meta';
    const LOG_TABLE = 'ecom_layeredtable_log';
    const MB = 1048576;
    const TEXT_LIMIT = 65535;

    /** @var Module */
    private $module;

    /** @var string front|admin|cron|manual */
    private $trigger;

    /** @var float */
    private $deadline;

    /** @var float */
    private $started;

    /** @var Db */
    private $db;

    /** @var int */
    private $batch;

    /** @var array */
    private $conf = array();

    /** @var int rows deleted during this run */
    private $deleted = 0;

    /** @var int bytes of the rows deleted during this run (measured or estimated) */
    private $deletedBytes = 0;

    /** @var float average row size from information_schema, used when a row size is unknown */
    private $avgRow = 0;

    /** @var array */
    private $actions = array();

    /** @var bool */
    private $locked = false;

    /** @var bool|null cached per request */
    private static $dateColumn = null;

    /** @var string|null cached per request */
    private static $columnType = null;

    /**
     * @param Module $module
     * @param string $trigger
     * @param int $budgetSeconds
     */
    public function __construct($module, $trigger = 'front', $budgetSeconds = 5)
    {
        $this->module = $module;
        $this->trigger = (string) $trigger;
        $this->started = microtime(true);
        $this->deadline = $this->started + max(1, (int) $budgetSeconds);
        $this->db = Db::getInstance();
        $this->conf = self::getConf();
        $this->batch = max(100, (int) $this->conf['ELT_BATCH_ROWS']);
    }

    /* ---------------------------------------------------------------------
     * Configuration helpers
     * ------------------------------------------------------------------ */

    /**
     * @return array every ELT_* value, with defaults for the missing ones
     */
    public static function getConf()
    {
        $conf = array();
        foreach (Ecom_Layeredtable::$defaults as $key => $default) {
            $value = Configuration::getGlobalValue($key);
            $conf[$key] = ($value === false || $value === null) ? $default : $value;
        }

        return $conf;
    }

    /**
     * Heavy operations (reconcile, sizing, OPTIMIZE, ALTER) only run outside the front office.
     *
     * @return bool
     */
    private function isHeavyAllowed()
    {
        return $this->trigger !== 'front';
    }

    /**
     * @return float seconds left in the budget
     */
    private function timeLeft()
    {
        return $this->deadline - microtime(true);
    }

    /* ---------------------------------------------------------------------
     * Measurement
     * ------------------------------------------------------------------ */

    /**
     * @return bool
     */
    public static function blockTableExists()
    {
        $row = Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = "' . pSQL(_DB_NAME_) . '" ' .
            'AND TABLE_NAME = "' . pSQL(_DB_PREFIX_ . self::BLOCK_TABLE) . '"'
        );

        return (int) $row > 0;
    }

    /**
     * Size of the block table from information_schema (InnoDB figures are estimates).
     *
     * @param bool $refresh run ANALYZE TABLE first so that the estimate is fresh
     *
     * @return array
     */
    public static function measure($refresh = false)
    {
        return self::measureTable(_DB_PREFIX_ . self::BLOCK_TABLE, $refresh);
    }

    /**
     * @param string $table full table name (with prefix)
     * @param bool $refresh
     *
     * @return array
     */
    public static function measureTable($table, $refresh = false)
    {
        $out = array(
            'exists' => false,
            'table' => $table,
            'engine' => '',
            'rows' => 0,
            'data' => 0,
            'index' => 0,
            'free' => 0,
            'used' => 0,
            'physical' => 0,
            'used_mb' => 0.0,
            'free_mb' => 0.0,
            'physical_mb' => 0.0,
            'avg_row' => 0,
        );
        // ANALYZE/OPTIMIZE go through execute(): in debug mode Db::executeS() throws on anything
        // that is not a SELECT, and a failed refresh must never zero the measurement.
        if ($refresh) {
            try {
                Db::getInstance()->execute('ANALYZE TABLE `' . bqSQL($table) . '`');
            } catch (Exception $e) {
                EcomLayeredtableLogger::log('analyze failed: ' . $e->getMessage());
            }
        }
        try {
            $row = Db::getInstance()->getRow(
                'SELECT ENGINE, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, DATA_FREE, AVG_ROW_LENGTH ' .
                'FROM information_schema.TABLES WHERE TABLE_SCHEMA = "' . pSQL(_DB_NAME_) . '" ' .
                'AND TABLE_NAME = "' . pSQL($table) . '"'
            );
        } catch (Exception $e) {
            $row = false;
        }
        if (!is_array($row) || empty($row)) {
            return $out;
        }
        $out['exists'] = true;
        $out['engine'] = (string) $row['ENGINE'];
        $out['rows'] = (int) $row['TABLE_ROWS'];
        $out['data'] = (int) $row['DATA_LENGTH'];
        $out['index'] = (int) $row['INDEX_LENGTH'];
        $out['free'] = (int) $row['DATA_FREE'];
        $out['used'] = $out['data'] + $out['index'];
        $out['physical'] = $out['used'] + $out['free'];
        $out['used_mb'] = round($out['used'] / self::MB, 2);
        $out['free_mb'] = round($out['free'] / self::MB, 2);
        $out['physical_mb'] = round($out['physical'] / self::MB, 2);
        $out['avg_row'] = $out['rows'] > 0 ? (int) ($out['used'] / $out['rows']) : (int) $row['AVG_ROW_LENGTH'];

        return $out;
    }

    /**
     * Every layered_* table plus the module's own tables.
     *
     * @return array
     */
    public static function measureAll()
    {
        $rows = Db::getInstance()->executeS(
            'SELECT TABLE_NAME, ENGINE, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, DATA_FREE ' .
            'FROM information_schema.TABLES WHERE TABLE_SCHEMA = "' . pSQL(_DB_NAME_) . '" ' .
            'AND (TABLE_NAME LIKE "' . pSQL(_DB_PREFIX_) . 'layered\_%" OR TABLE_NAME LIKE "' . pSQL(_DB_PREFIX_) . 'ecom\_layeredtable\_%") ' .
            'ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC'
        );
        $out = array();
        foreach (is_array($rows) ? $rows : array() as $row) {
            $used = (int) $row['DATA_LENGTH'] + (int) $row['INDEX_LENGTH'];
            $out[] = array(
                'table' => $row['TABLE_NAME'],
                'engine' => $row['ENGINE'],
                'rows' => (int) $row['TABLE_ROWS'],
                'used_mb' => round($used / self::MB, 2),
                'free_mb' => round((int) $row['DATA_FREE'] / self::MB, 2),
                'physical_mb' => round(($used + (int) $row['DATA_FREE']) / self::MB, 2),
                'is_block' => $row['TABLE_NAME'] === _DB_PREFIX_ . self::BLOCK_TABLE,
            );
        }

        return $out;
    }

    /**
     * Does the block table have the date_add column this module adds?
     *
     * @param bool $refresh
     *
     * @return bool
     */
    public static function hasDateColumn($refresh = false)
    {
        if (self::$dateColumn === null || $refresh) {
            $count = Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = "' . pSQL(_DB_NAME_) . '" ' .
                'AND TABLE_NAME = "' . pSQL(_DB_PREFIX_ . self::BLOCK_TABLE) . '" AND COLUMN_NAME = "date_add"'
            );
            self::$dateColumn = ((int) $count > 0);
        }

        return self::$dateColumn;
    }

    /**
     * Adds a creation date to the block table itself. ps_facetedsearch writes with
     * "REPLACE INTO ... (hash, data)" naming its columns, so the default fills the date
     * and the module is not affected; its CREATE TABLE IF NOT EXISTS keeps the column.
     *
     * On a big table a rebuilding ALTER can take minutes and needs the same space again
     * on disk, so above ELT_ALTER_MAX_MB only an instant ALTER (MariaDB 10.3+, MySQL 8.0+)
     * is attempted; otherwise the column waits until the table is small.
     *
     * @param bool $force ignore the size limit
     *
     * @return bool true when the column exists after the call
     */
    public static function ensureDateColumn($force = false)
    {
        if (!self::blockTableExists()) {
            return false;
        }
        if (self::hasDateColumn(true)) {
            return true;
        }
        $db = Db::getInstance();
        $table = '`' . _DB_PREFIX_ . self::BLOCK_TABLE . '`';
        $stats = self::measure(false);
        $maxMb = (int) Configuration::getGlobalValue('ELT_ALTER_MAX_MB');
        $small = $force || $maxMb <= 0 || $stats['used_mb'] <= $maxMb;

        // 1. The column (instant when the server supports it)
        $added = false;
        try {
            $db->execute('ALTER TABLE ' . $table . ' ADD COLUMN `date_add` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, ALGORITHM=INSTANT');
            $added = true;
        } catch (Exception $e) {
            $added = false;
        }
        if (!$added && $small) {
            try {
                $db->execute('ALTER TABLE ' . $table . ' ADD COLUMN `date_add` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
                $added = true;
            } catch (Exception $e) {
                EcomLayeredtableLogger::log('date_add column could not be added: ' . $e->getMessage(), array(), 'admin');
            }
        }
        if (!$added) {
            EcomLayeredtableLogger::log('date_add column postponed: table too big for a rebuilding ALTER', array('used_mb' => $stats['used_mb']), 'admin');

            return false;
        }

        // 2. The index (in place, no rebuild; only when the table is small on old servers)
        try {
            $db->execute('ALTER TABLE ' . $table . ' ADD KEY `date_add` (`date_add`), ALGORITHM=INPLACE, LOCK=NONE');
        } catch (Exception $e) {
            if ($small) {
                try {
                    $db->execute('ALTER TABLE ' . $table . ' ADD KEY `date_add` (`date_add`)');
                } catch (Exception $e2) {
                    EcomLayeredtableLogger::log('date_add index could not be added: ' . $e2->getMessage(), array(), 'admin');
                }
            }
        }
        EcomLayeredtableLogger::log('date_add column added to ' . _DB_PREFIX_ . self::BLOCK_TABLE, array(), 'admin');

        return self::hasDateColumn(true);
    }

    /**
     * Oldest entry of the block table (needs the date column).
     *
     * @return string
     */
    public static function blockOldest()
    {
        if (!self::hasDateColumn()) {
            return '';
        }
        $value = Db::getInstance()->getValue('SELECT MIN(date_add) FROM `' . _DB_PREFIX_ . self::BLOCK_TABLE . '`');

        return $value ? (string) $value : '';
    }

    /**
     * Type of the data column (TEXT = 64 KB per row, LONGTEXT = unlimited).
     *
     * @return string
     */
    public static function getDataColumnType()
    {
        if (self::$columnType === null) {
            $type = Db::getInstance()->getValue(
                'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = "' . pSQL(_DB_NAME_) . '" ' .
                'AND TABLE_NAME = "' . pSQL(_DB_PREFIX_ . self::BLOCK_TABLE) . '" AND COLUMN_NAME = "data"'
            );
            self::$columnType = $type ? Tools::strtoupper((string) $type) : '';
        }

        return self::$columnType;
    }

    /**
     * Aggregates from the meta table.
     *
     * @return array
     */
    public static function metaStats()
    {
        $row = Db::getInstance()->getRow(
            'SELECT COUNT(*) AS tracked, IFNULL(SUM(hits), 0) AS hits, IFNULL(AVG(NULLIF(size, 0)), 0) AS avg_size, ' .
            'IFNULL(MAX(size), 0) AS max_size, IFNULL(SUM(size), 0) AS sum_size, IFNULL(SUM(size > 0), 0) AS sized, ' .
            'IFNULL(SUM(hits = 0), 0) AS never_used, MIN(date_add) AS oldest, MAX(last_used) AS newest ' .
            'FROM `' . _DB_PREFIX_ . self::META_TABLE . '`'
        );
        if (!is_array($row)) {
            $row = array();
        }

        return array(
            'tracked' => isset($row['tracked']) ? (int) $row['tracked'] : 0,
            'hits' => isset($row['hits']) ? (int) $row['hits'] : 0,
            'sized' => isset($row['sized']) ? (int) $row['sized'] : 0,
            'avg_kb' => isset($row['avg_size']) ? round((float) $row['avg_size'] / 1024, 1) : 0,
            'max_kb' => isset($row['max_size']) ? round((float) $row['max_size'] / 1024, 1) : 0,
            'sum_mb' => isset($row['sum_size']) ? round((float) $row['sum_size'] / self::MB, 2) : 0,
            'never_used' => isset($row['never_used']) ? (int) $row['never_used'] : 0,
            'oldest' => isset($row['oldest']) ? (string) $row['oldest'] : '',
            'newest' => isset($row['newest']) ? (string) $row['newest'] : '',
        );
    }

    /**
     * @param int $limit
     *
     * @return array
     */
    public static function history($limit = 30)
    {
        $rows = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . self::LOG_TABLE . '` ORDER BY id_log DESC LIMIT ' . (int) $limit
        );

        return is_array($rows) ? $rows : array();
    }

    /* ---------------------------------------------------------------------
     * Usage tracking (called from the hook on every listing request)
     * ------------------------------------------------------------------ */

    /**
     * @param string $hash md5 of the cache key
     */
    public static function touch($hash)
    {
        if (!preg_match('/^[a-f0-9]{32}$/', (string) $hash)) {
            return;
        }
        try {
            Db::getInstance()->execute(
                'INSERT INTO `' . _DB_PREFIX_ . self::META_TABLE . '` (hash, date_add, last_used, hits, size) ' .
                'VALUES ("' . pSQL($hash) . '", NOW(), NOW(), 1, 0) ' .
                'ON DUPLICATE KEY UPDATE last_used = NOW(), hits = hits + 1'
            );
        } catch (Exception $e) {
            EcomLayeredtableLogger::log('touch failed: ' . $e->getMessage());
        }
    }

    /* ---------------------------------------------------------------------
     * The run
     * ------------------------------------------------------------------ */

    /**
     * @return array
     */
    private function emptySummary()
    {
        return array(
            'trigger' => $this->trigger,
            'ran' => false,
            'skipped' => '',
            'deleted' => 0,
            'deleted_mb' => 0,
            'actions' => array(),
            'before' => null,
            'after' => null,
            'seconds' => 0,
            'continue' => false,
        );
    }

    /**
     * Full cleaning pass, within the time budget.
     *
     * @return array summary
     */
    public function run()
    {
        $summary = $this->emptySummary();

        if (!self::blockTableExists()) {
            $summary['skipped'] = 'no_table';

            return $summary;
        }
        if (!$this->lock()) {
            $summary['skipped'] = 'locked';
            EcomLayeredtableLogger::log('run skipped, another cleaner holds the lock', array(), $this->trigger);

            return $summary;
        }

        Configuration::updateGlobalValue('ELT_LAST_RUN', time());
        $conf = $this->conf;
        $maxBytes = (int) $conf['ELT_MAX_MB'] * self::MB;
        $targetBytes = (int) ($maxBytes * max(10, min(95, (int) $conf['ELT_TARGET_PCT'])) / 100);

        $before = self::measure(true);
        $this->avgRow = (float) $before['avg_row'];
        $summary['before'] = $before;
        EcomLayeredtableLogger::log('run start', array('before' => $before, 'budget' => round($this->timeLeft(), 1)), $this->trigger);
        if (!$before['exists']) {
            // information_schema did not answer: never act on a zero measurement
            $summary['skipped'] = 'measure_failed';
            $this->actions[] = array('action' => 'error', 'rows' => 0, 'message' => 'information_schema returned nothing for the block table');
            $summary['actions'] = $this->actions;
            $this->writeLog('run', $summary);
            $this->unlock();

            return $summary;
        }

        try {
            $summary['ran'] = true;
            $isText = (self::getDataColumnType() === 'TEXT');

            // 0. Meta orphaned by a TRUNCATE done by ps_facetedsearch itself
            if ($before['rows'] === 0) {
                $this->db->execute('TRUNCATE TABLE `' . _DB_PREFIX_ . self::META_TABLE . '`');
            }

            // 1. Size cap. First thing, always gets at least one batch.
            $usedNow = $before['used'];
            if ($usedNow > $maxBytes) {
                $toFree = $usedNow - $targetBytes;
                $bulkPct = (int) $conf['ELT_BULK_TRUNCATE_PCT'];
                if ($bulkPct > 0 && $usedNow > 0 && ($toFree * 100 / $usedNow) >= $bulkPct) {
                    // Most of the table has to go: a TRUNCATE is seconds, row by row would be minutes
                    $this->truncate('bulk');
                    Configuration::updateGlobalValue('ELT_OPTIMIZE_PENDING_SINCE', 0);
                } else {
                    $this->evictBytes($toFree);
                }
                $usedNow = max(0, $before['used'] - $this->deletedBytes);
            }

            // 2. Too many rows
            if ((int) $conf['ELT_MAX_ROWS'] > 0 && $this->timeLeft() > 0) {
                $count = (int) $this->db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::META_TABLE . '`');
                if ($count > (int) $conf['ELT_MAX_ROWS']) {
                    $this->deleteWhere('1', $this->orderBy(), $count - (int) $conf['ELT_MAX_ROWS'], 'rows');
                }
            }

            // 3. Entries that hit the TEXT limit: truncated, unserialize fails, ps_facetedsearch rewrites them on every visit
            if ($isText && $this->timeLeft() > 0) {
                $this->deleteWhere('m.size >= ' . (int) self::TEXT_LIMIT, 'm.size DESC', 0, 'truncated');
            }

            // 4. Rows bigger than the allowed size
            if ((int) $conf['ELT_MAX_ROW_KB'] > 0 && $this->timeLeft() > 0) {
                $this->deleteWhere('m.size > ' . ((int) $conf['ELT_MAX_ROW_KB'] * 1024), 'm.size DESC', 0, 'oversize');
            }

            // 5. Rows older than the TTL: straight from the block table when it has our date column
            if ((int) $conf['ELT_TTL_DAYS'] > 0 && $this->timeLeft() > 0) {
                if (self::hasDateColumn()) {
                    $this->deleteBlockWhere('b.date_add < DATE_SUB(NOW(), INTERVAL ' . (int) $conf['ELT_TTL_DAYS'] . ' DAY)', 'b.date_add ASC', 'ttl');
                } else {
                    $this->deleteWhere('m.date_add < DATE_SUB(NOW(), INTERVAL ' . (int) $conf['ELT_TTL_DAYS'] . ' DAY)', 'm.date_add ASC', 0, 'ttl');
                }
            }

            // 6. Rows not used for N days
            if ((int) $conf['ELT_UNUSED_DAYS'] > 0 && $this->timeLeft() > 0) {
                $this->deleteWhere('m.last_used < DATE_SUB(NOW(), INTERVAL ' . (int) $conf['ELT_UNUSED_DAYS'] . ' DAY)', 'm.last_used ASC', 0, 'unused');
            }

            // 7. Reconcile meta with the real table (heavy: not on the front, only with the time left)
            if ($this->isHeavyAllowed() && $this->timeLeft() > 0) {
                $this->reconcile();
            }

            // 8. Reclaim disk space
            $after = self::measure(true);
            $needsOptimize = ($after['free'] > (int) $conf['ELT_OPTIMIZE_FREE_MB'] * self::MB) || ($after['physical'] > $maxBytes);
            if ($needsOptimize && (int) $conf['ELT_OPTIMIZE'] && $this->isHeavyAllowed()) {
                $this->optimize();
                Configuration::updateGlobalValue('ELT_OPTIMIZE_PENDING_SINCE', 0);
                $after = self::measure(true);
            }

            // 9. Emergency: the file is still above the hard limit
            if ($after['physical'] > $maxBytes && (int) $conf['ELT_EMERGENCY_TRUNCATE']) {
                $truncateNow = $this->isHeavyAllowed();
                if (!$truncateNow) {
                    // Give the back-office / cron a chance to OPTIMIZE instead of throwing the cache away
                    $pendingSince = (int) Configuration::getGlobalValue('ELT_OPTIMIZE_PENDING_SINCE');
                    $grace = max(10, (int) $conf['ELT_AUTO_MINUTES']) * 2 * 60;
                    if ($pendingSince <= 0) {
                        Configuration::updateGlobalValue('ELT_OPTIMIZE_PENDING_SINCE', time());
                        $this->actions[] = array('action' => 'optimize_pending', 'rows' => 0);
                    } elseif ((time() - $pendingSince) > $grace) {
                        $truncateNow = true;
                    }
                }
                if ($truncateNow) {
                    $this->truncate('emergency');
                    Configuration::updateGlobalValue('ELT_OPTIMIZE_PENDING_SINCE', 0);
                    $after = self::measure(true);
                }
            } elseif ($after['physical'] <= $maxBytes) {
                Configuration::updateGlobalValue('ELT_OPTIMIZE_PENDING_SINCE', 0);
            }

            // 10. The date column, if it is still missing and the table is small enough now
            if ($this->isHeavyAllowed() && !self::hasDateColumn() && $this->timeLeft() > 0) {
                if (self::ensureDateColumn()) {
                    $this->actions[] = array('action' => 'date_column_added', 'rows' => 0);
                }
            }

            $summary['after'] = $after;
            // More to do next time? (size still over the limit and progress was made)
            $summary['continue'] = ($after['used'] > $maxBytes && $this->deleted > 0);
        } catch (Exception $e) {
            $this->actions[] = array('action' => 'error', 'rows' => 0, 'message' => $e->getMessage());
            EcomLayeredtableLogger::log('run error: ' . $e->getMessage(), array(), $this->trigger);
            $summary['after'] = self::measure(false);
        }

        $summary['deleted'] = $this->deleted;
        $summary['deleted_mb'] = round($this->deletedBytes / self::MB, 2);
        $summary['actions'] = $this->actions;
        $summary['seconds'] = round(microtime(true) - $this->started, 2);

        $this->saveLastStats($summary);
        $this->writeLog('run', $summary);
        $this->unlock();
        EcomLayeredtableLogger::log('run end', array('deleted' => $this->deleted, 'after' => $summary['after'], 'seconds' => $summary['seconds']), $this->trigger);

        return $summary;
    }

    /**
     * Snapshot for the dashboard and for the "table is full" check in the hook.
     *
     * @param array $summary
     */
    private function saveLastStats($summary)
    {
        $after = is_array($summary['after']) ? $summary['after'] : array();
        Configuration::updateGlobalValue('ELT_LAST_STATS', json_encode(array(
            'time' => time(),
            'trigger' => $this->trigger,
            'used_mb' => isset($after['used_mb']) ? $after['used_mb'] : 0,
            'physical_mb' => isset($after['physical_mb']) ? $after['physical_mb'] : 0,
            'rows' => isset($after['rows']) ? $after['rows'] : 0,
            'deleted' => $summary['deleted'],
            'seconds' => $summary['seconds'],
        )));
    }

    /**
     * Last snapshot saved by a run.
     *
     * @return array
     */
    public static function getLastStats()
    {
        $json = Configuration::getGlobalValue('ELT_LAST_STATS');
        $data = $json ? json_decode($json, true) : null;

        return is_array($data) ? $data : array();
    }

    /* ---------------------------------------------------------------------
     * Steps
     * ------------------------------------------------------------------ */

    /**
     * Is the real size of each entry needed by any active rule?
     *
     * @return bool
     */
    private function sizeNeeded()
    {
        if ((string) $this->conf['ELT_STRATEGY'] === 'biggest') {
            return true;
        }
        $isText = (self::getDataColumnType() === 'TEXT');
        if ($isText) {
            // the "truncated" rule needs it
            return true;
        }
        $maxRowKb = (int) $this->conf['ELT_MAX_ROW_KB'];

        return $maxRowKb > 0;
    }

    /**
     * Give a meta row to every hash of the block table, drop meta rows whose block
     * is gone, and (with the time left, when a rule needs it) measure the sizes.
     */
    public function reconcile()
    {
        $block = '`' . _DB_PREFIX_ . self::BLOCK_TABLE . '`';
        $meta = '`' . _DB_PREFIX_ . self::META_TABLE . '`';
        $added = 0;
        $sized = 0;

        // Orphans
        $this->db->execute('DELETE m FROM ' . $meta . ' m LEFT JOIN ' . $block . ' b ON b.hash = m.hash WHERE b.hash IS NULL');
        $orphans = (int) $this->db->Affected_Rows();

        // Missing meta, keyset pagination on the primary key, never reading the data column.
        // With the date column the creation date is real; the last use is unknown, so the
        // creation date is used as a floor.
        $dateExpr = self::hasDateColumn() ? 'b.date_add' : 'NOW()';
        $last = '';
        do {
            $rows = $this->db->executeS(
                'SELECT b.hash, ' . $dateExpr . ' AS date_add FROM ' . $block . ' b ' .
                'LEFT JOIN ' . $meta . ' m ON m.hash = b.hash ' .
                'WHERE m.hash IS NULL AND b.hash > "' . pSQL($last) . '" ORDER BY b.hash LIMIT ' . (int) $this->batch
            );
            if (!is_array($rows) || empty($rows)) {
                break;
            }
            $values = array();
            foreach ($rows as $row) {
                $date = '"' . pSQL((string) $row['date_add']) . '"';
                $values[] = '("' . pSQL($row['hash']) . '", ' . $date . ', ' . $date . ', 0, 0)';
                $last = $row['hash'];
            }
            $this->db->execute('INSERT IGNORE INTO ' . $meta . ' (hash, date_add, last_used, hits, size) VALUES ' . implode(',', $values));
            $added += count($rows);
        } while (count($rows) >= $this->batch && $this->timeLeft() > 0);

        // Sizes (reads the blobs: only when a rule needs them and only with the time left)
        if ($this->sizeNeeded()) {
            $sized = $this->measureSizes();
        }

        $this->actions[] = array('action' => 'reconcile', 'rows' => $added, 'orphans' => $orphans, 'sized' => $sized);
        EcomLayeredtableLogger::log('reconcile', array('added' => $added, 'orphans' => $orphans, 'sized' => $sized), $this->trigger);
    }

    /**
     * Fill meta.size for the rows that do not have it yet, in batches, while time is left.
     *
     * @return int rows sized
     */
    private function measureSizes()
    {
        $block = '`' . _DB_PREFIX_ . self::BLOCK_TABLE . '`';
        $meta = '`' . _DB_PREFIX_ . self::META_TABLE . '`';
        $sized = 0;
        $sizeBatch = max(50, (int) ($this->batch / 4));
        while ($this->timeLeft() > 0) {
            $rows = $this->db->executeS('SELECT hash FROM ' . $meta . ' WHERE size = 0 LIMIT ' . (int) $sizeBatch);
            if (!is_array($rows) || empty($rows)) {
                break;
            }
            $hashes = array();
            foreach ($rows as $row) {
                $hashes[] = '"' . pSQL($row['hash']) . '"';
            }
            // rows whose block does not exist keep size 0 and are removed as orphans later; mark them 1 to leave the loop
            $this->db->execute(
                'UPDATE ' . $meta . ' m LEFT JOIN ' . $block . ' b ON b.hash = m.hash ' .
                'SET m.size = IFNULL(LENGTH(b.data), 1) WHERE m.hash IN (' . implode(',', $hashes) . ')'
            );
            $sized += (int) $this->db->Affected_Rows();
            if (count($rows) < $sizeBatch) {
                break;
            }
        }

        return $sized;
    }

    /**
     * Delete block rows selected by a condition on the meta table, in batches.
     *
     * @param string $where SQL condition (alias m = meta)
     * @param string $orderBy
     * @param int $limit 0 = all matching rows
     * @param string $label for the log
     *
     * @return int rows deleted
     */
    private function deleteWhere($where, $orderBy = '', $limit = 0, $label = 'delete')
    {
        $meta = '`' . _DB_PREFIX_ . self::META_TABLE . '`';
        $total = 0;
        $bytes = 0;
        do {
            $size = $this->batch;
            if ($limit > 0) {
                $size = min($size, $limit - $total);
                if ($size <= 0) {
                    break;
                }
            }
            $rows = $this->db->executeS(
                'SELECT m.hash, m.size FROM ' . $meta . ' m WHERE ' . $where .
                ($orderBy ? ' ORDER BY ' . $orderBy : '') . ' LIMIT ' . (int) $size
            );
            if (!is_array($rows) || empty($rows)) {
                break;
            }
            $hashes = array();
            foreach ($rows as $row) {
                $hashes[] = $row['hash'];
                $bytes += ((int) $row['size'] > 1) ? (int) $row['size'] : $this->avgRow;
            }
            $total += $this->deleteHashes($hashes);
        } while (count($rows) >= $size && $this->timeLeft() > 0);

        $this->note($label, $total, $bytes);

        return $total;
    }

    /**
     * Delete block rows selected by a condition on the block table itself (alias b), in batches.
     *
     * @param string $where
     * @param string $orderBy
     * @param string $label
     *
     * @return int rows deleted
     */
    private function deleteBlockWhere($where, $orderBy, $label)
    {
        $block = '`' . _DB_PREFIX_ . self::BLOCK_TABLE . '`';
        $total = 0;
        $bytes = 0;
        do {
            $rows = $this->db->executeS(
                'SELECT b.hash FROM ' . $block . ' b WHERE ' . $where .
                ($orderBy ? ' ORDER BY ' . $orderBy : '') . ' LIMIT ' . (int) $this->batch
            );
            if (!is_array($rows) || empty($rows)) {
                break;
            }
            $hashes = array();
            foreach ($rows as $row) {
                $hashes[] = $row['hash'];
                $bytes += $this->avgRow;
            }
            $total += $this->deleteHashes($hashes);
        } while (count($rows) >= $this->batch && $this->timeLeft() > 0);

        $this->note($label, $total, $bytes);

        return $total;
    }

    /**
     * Evict rows (by the configured strategy) until roughly $bytes are freed.
     * Always runs at least one batch, then only while time is left.
     *
     * @param int $bytes
     */
    private function evictBytes($bytes)
    {
        $meta = '`' . _DB_PREFIX_ . self::META_TABLE . '`';
        $block = '`' . _DB_PREFIX_ . self::BLOCK_TABLE . '`';
        $freed = 0;
        $total = 0;
        $first = true;
        while ($freed < $bytes && ($first || $this->timeLeft() > 0)) {
            $first = false;
            $rows = $this->db->executeS(
                'SELECT m.hash, m.size FROM ' . $meta . ' m ORDER BY ' . $this->orderBy() . ' LIMIT ' . (int) $this->batch
            );
            if (!is_array($rows) || empty($rows)) {
                // Nothing tracked: take rows straight from the block table (primary key only, no blobs)
                $rows = $this->db->executeS('SELECT hash, 0 AS size FROM ' . $block . ' ORDER BY hash LIMIT ' . (int) $this->batch);
                if (!is_array($rows) || empty($rows)) {
                    break;
                }
            }
            $hashes = array();
            foreach ($rows as $row) {
                $hashes[] = $row['hash'];
                $freed += ((int) $row['size'] > 1) ? (int) $row['size'] : $this->avgRow;
            }
            $deleted = $this->deleteHashes($hashes);
            $total += $deleted;
            if ($deleted === 0 && count($rows) < $this->batch) {
                break;
            }
        }
        $this->note('evict_' . $this->conf['ELT_STRATEGY'], $total, $freed);
    }

    /**
     * @param string $label
     * @param int $rows
     * @param float $bytes
     */
    private function note($label, $rows, $bytes)
    {
        if ($rows <= 0) {
            return;
        }
        $this->deletedBytes += $bytes;
        $this->actions[] = array('action' => $label, 'rows' => $rows, 'mb' => round($bytes / self::MB, 2));
        EcomLayeredtableLogger::log($label, array('rows' => $rows, 'mb' => round($bytes / self::MB, 2)), $this->trigger);
    }

    /**
     * @param array $hashes
     *
     * @return int rows deleted from the block table
     */
    private function deleteHashes(array $hashes)
    {
        if (empty($hashes)) {
            return 0;
        }
        $list = array();
        foreach ($hashes as $hash) {
            $list[] = '"' . pSQL($hash) . '"';
        }
        $in = implode(',', $list);
        $this->db->execute('DELETE FROM `' . _DB_PREFIX_ . self::BLOCK_TABLE . '` WHERE hash IN (' . $in . ')');
        $deleted = (int) $this->db->Affected_Rows();
        $this->db->execute('DELETE FROM `' . _DB_PREFIX_ . self::META_TABLE . '` WHERE hash IN (' . $in . ')');
        $this->deleted += $deleted;

        return $deleted;
    }

    /**
     * ORDER BY for the eviction strategy.
     *
     * @return string
     */
    private function orderBy()
    {
        switch ((string) $this->conf['ELT_STRATEGY']) {
            case 'lfu':
                return 'm.hits ASC, m.last_used ASC';
            case 'oldest':
                return 'm.date_add ASC';
            case 'biggest':
                return 'm.size DESC';
            case 'lru':
            default:
                return 'm.last_used ASC, m.hits ASC';
        }
    }

    /**
     * OPTIMIZE TABLE: rebuilds the table so that InnoDB gives the space back to the disk.
     *
     * @return bool
     */
    public function optimize()
    {
        $before = self::measure(false);
        $ok = false;
        try {
            $this->db->execute('OPTIMIZE TABLE `' . _DB_PREFIX_ . self::BLOCK_TABLE . '`');
            $this->db->execute('OPTIMIZE TABLE `' . _DB_PREFIX_ . self::META_TABLE . '`');
            $ok = true;
        } catch (Exception $e) {
            EcomLayeredtableLogger::log('optimize failed: ' . $e->getMessage(), array(), $this->trigger);
        }
        $after = self::measure(true);
        $this->actions[] = array(
            'action' => 'optimize',
            'rows' => 0,
            'mb' => round(($before['physical'] - $after['physical']) / self::MB, 2),
            'ok' => $ok,
        );
        EcomLayeredtableLogger::log('optimize', array('ok' => $ok, 'before_mb' => $before['physical_mb'], 'after_mb' => $after['physical_mb']), $this->trigger);

        return $ok;
    }

    /**
     * Empty the cache table (and the meta). ps_facetedsearch regenerates it on demand.
     *
     * @param string $reason
     *
     * @return bool
     */
    public function truncate($reason = 'manual')
    {
        $before = self::measure(false);
        $ok = $this->db->execute('TRUNCATE TABLE `' . _DB_PREFIX_ . self::BLOCK_TABLE . '`');
        $this->db->execute('TRUNCATE TABLE `' . _DB_PREFIX_ . self::META_TABLE . '`');
        $this->deleted += $before['rows'];
        $this->deletedBytes += $before['used'];
        $this->actions[] = array('action' => 'truncate_' . $reason, 'rows' => $before['rows'], 'mb' => $before['physical_mb']);
        EcomLayeredtableLogger::log('truncate', array('reason' => $reason, 'rows' => $before['rows'], 'mb' => $before['physical_mb']), $this->trigger);

        return (bool) $ok;
    }

    /**
     * Stand-alone manual actions (from the admin controller) share the run bookkeeping.
     *
     * @param string $action optimize|truncate|reconcile|datecolumn
     *
     * @return array
     */
    public function single($action)
    {
        $summary = $this->emptySummary();
        if (!self::blockTableExists()) {
            $summary['skipped'] = 'no_table';

            return $summary;
        }
        if (!$this->lock()) {
            $summary['skipped'] = 'locked';

            return $summary;
        }
        $summary['before'] = self::measure(true);
        $this->avgRow = (float) $summary['before']['avg_row'];
        try {
            $summary['ran'] = true;
            if ($action === 'optimize') {
                $this->optimize();
                Configuration::updateGlobalValue('ELT_OPTIMIZE_PENDING_SINCE', 0);
            } elseif ($action === 'truncate') {
                $this->truncate('manual');
                Configuration::updateGlobalValue('ELT_OPTIMIZE_PENDING_SINCE', 0);
            } elseif ($action === 'reconcile') {
                $this->reconcile();
            } elseif ($action === 'datecolumn') {
                $ok = self::ensureDateColumn(true);
                $this->actions[] = array('action' => $ok ? 'date_column_added' : 'date_column_failed', 'rows' => 0);
            }
        } catch (Exception $e) {
            $this->actions[] = array('action' => 'error', 'rows' => 0, 'message' => $e->getMessage());
        }
        $summary['after'] = self::measure(true);
        $summary['deleted'] = $this->deleted;
        $summary['deleted_mb'] = round($this->deletedBytes / self::MB, 2);
        $summary['actions'] = $this->actions;
        $summary['seconds'] = round(microtime(true) - $this->started, 2);
        $this->saveLastStats($summary);
        $this->writeLog($action, $summary);
        $this->unlock();

        return $summary;
    }

    /* ---------------------------------------------------------------------
     * Lock and history
     * ------------------------------------------------------------------ */

    /**
     * MySQL advisory lock so two requests never clean at the same time.
     *
     * @return bool
     */
    private function lock()
    {
        try {
            $got = $this->db->getValue('SELECT GET_LOCK("' . pSQL($this->lockName()) . '", 0)');
        } catch (Exception $e) {
            $got = null;
        }
        if ($got === null || $got === false) {
            // GET_LOCK unavailable: fall back to a timestamp in Configuration
            $since = (int) Configuration::getGlobalValue('ELT_LOCK');
            if ($since > 0 && (time() - $since) < 600) {
                return false;
            }
            Configuration::updateGlobalValue('ELT_LOCK', time());
            $this->locked = true;

            return true;
        }
        $this->locked = ((int) $got === 1);

        return $this->locked;
    }

    private function unlock()
    {
        if (!$this->locked) {
            return;
        }
        try {
            $this->db->getValue('SELECT RELEASE_LOCK("' . pSQL($this->lockName()) . '")');
        } catch (Exception $e) {
            // ignore
        }
        Configuration::updateGlobalValue('ELT_LOCK', 0);
        $this->locked = false;
    }

    /**
     * @return string
     */
    private function lockName()
    {
        return 'elt_clean_' . md5(_DB_NAME_ . _DB_PREFIX_);
    }

    /**
     * @param string $action
     * @param array $summary
     */
    private function writeLog($action, $summary)
    {
        try {
            $before = is_array($summary['before']) ? $summary['before'] : array();
            $after = is_array($summary['after']) ? $summary['after'] : array();
            $this->db->insert(self::LOG_TABLE, array(
                'date_add' => date('Y-m-d H:i:s'),
                'trigger_type' => pSQL($this->trigger),
                'action' => pSQL($action),
                'rows_deleted' => (int) $summary['deleted'],
                'mb_before' => isset($before['physical_mb']) ? (float) $before['physical_mb'] : 0,
                'mb_after' => isset($after['physical_mb']) ? (float) $after['physical_mb'] : 0,
                'rows_after' => isset($after['rows']) ? (int) $after['rows'] : 0,
                'seconds' => (float) $summary['seconds'],
                'details' => pSQL(json_encode($summary['actions'])),
            ));
            $keep = max(20, (int) $this->conf['ELT_LOG_KEEP']);
            $count = (int) $this->db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::LOG_TABLE . '`');
            if ($count > $keep) {
                $this->db->execute('DELETE FROM `' . _DB_PREFIX_ . self::LOG_TABLE . '` ORDER BY id_log ASC LIMIT ' . (int) ($count - $keep));
            }
        } catch (Exception $e) {
            EcomLayeredtableLogger::log('history insert failed: ' . $e->getMessage(), array(), $this->trigger);
        }
    }
}

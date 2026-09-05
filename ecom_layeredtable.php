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

require_once dirname(__FILE__) . '/classes/EcomLayeredtableLogger.php';
require_once dirname(__FILE__) . '/classes/EcomLayeredtableCleaner.php';

class Ecom_Layeredtable extends Module
{
    const FEATURES_VERSION = 1;
    const ADMIN_CONTROLLER = 'AdminEcomLayeredtable';
    const TRANS_DOMAIN = 'Modules.Ecomlayeredtable.Admin';

    /** @var bool the automatic cleaner ran already in this request */
    private static $autoRan = false;

    /** @var string|null skip reason already decided in this request ('' = cache allowed) */
    private static $skipReason = null;

    /** @var string[] hooks the module needs */
    public static $hooks = array(
        'actionProductSearchProviderRunQueryBefore',
        'actionFacetedSearchCacheKeyGeneration',
        'actionAdminControllerSetMedia',
    );

    /** @var array default values of every configuration key */
    public static $defaults = array(
        'ELT_ENABLED' => 1,
        'ELT_MAX_MB' => 950,
        'ELT_TARGET_PCT' => 70,
        'ELT_MAX_ROWS' => 0,
        'ELT_MAX_ROW_KB' => 1024,
        'ELT_STRATEGY' => 'lru',
        'ELT_BULK_TRUNCATE_PCT' => 60,
        'ELT_EMERGENCY_TRUNCATE' => 1,
        'ELT_ALTER_MAX_MB' => 500,
        'ELT_TTL_DAYS' => 7,
        'ELT_UNUSED_DAYS' => 3,
        'ELT_TRACK_USAGE' => 1,
        'ELT_OPTIMIZE' => 1,
        'ELT_OPTIMIZE_FREE_MB' => 200,
        'ELT_SKIP_BOTS' => 1,
        'ELT_BOT_LIST' => "googlebot\nbingbot\nyandex\nbaiduspider\nduckduckbot\nslurp\nahrefsbot\nsemrushbot\nmj12bot\ndotbot\npetalbot\napplebot\nbytespider\ngptbot\nclaudebot\nccbot\namazonbot\nfacebookexternalhit\nseznambot\nscreaming frog\ncurl/\nwget/\npython-requests\ncrawler\nspider",
        'ELT_SKIP_RANGES' => 1,
        'ELT_MAX_FILTERS' => 3,
        'ELT_SKIP_WHEN_FULL' => 1,
        'ELT_AUTO_MINUTES' => 30,
        'ELT_AUTO_FRONT' => 1,
        'ELT_FRONT_BUDGET_SEC' => 3,
        'ELT_ADMIN_BUDGET_SEC' => 20,
        'ELT_CRON_BUDGET_SEC' => 120,
        'ELT_BATCH_ROWS' => 1000,
        'ELT_DEBUG_LOG' => 0,
        'ELT_LOG_KEEP' => 500,
    );

    public function __construct()
    {
        $this->name = 'ecom_layeredtable';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Ecom Experts';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = array('min' => '8.0.0', 'max' => _PS_VERSION_);

        parent::__construct();

        $this->displayName = $this->trans('Faceted search cache guard', array(), self::TRANS_DOMAIN);
        $this->description = $this->trans('Keeps the layered_filter_block table of ps_facetedsearch under a size limit: cleaning by date, by usage and by size, and stopping bots and price ranges from filling it.', array(), self::TRANS_DOMAIN);
        $this->confirmUninstall = $this->trans('The module tables and settings will be removed. The faceted search cache itself is not touched.', array(), self::TRANS_DOMAIN);
    }

    /**
     * Module::trans() is protected; the admin controller and the classes need it.
     */
    public function trans($id, array $parameters = array(), $domain = null, $locale = null)
    {
        return parent::trans($id, $parameters, $domain, $locale);
    }

    public function isUsingNewTranslationSystem()
    {
        return true;
    }

    /* ---------------------------------------------------------------------
     * Install / uninstall / migrations
     * ------------------------------------------------------------------ */

    public function install()
    {
        if (!parent::install()) {
            return false;
        }
        foreach (self::$hooks as $hook) {
            if (!$this->registerHook($hook)) {
                return false;
            }
        }
        if (!$this->installTables() || !$this->installTab()) {
            return false;
        }
        EcomLayeredtableCleaner::ensureDateColumn();
        foreach (self::$defaults as $key => $value) {
            if (Configuration::getGlobalValue($key) === false) {
                Configuration::updateGlobalValue($key, $value);
            }
        }
        Configuration::updateGlobalValue('ELT_CRON_TOKEN', Tools::passwdGen(24));
        Configuration::updateGlobalValue('ELT_FEATURES_VERSION', self::FEATURES_VERSION);
        Configuration::updateGlobalValue('ELT_LAST_RUN', 0);
        Configuration::updateGlobalValue('ELT_OPTIMIZE_PENDING_SINCE', 0);
        Configuration::updateGlobalValue('GMARTOS_LAST_PING_' . $this->name, 0);
        $this->checkGmartosUpdate();

        return true;
    }

    public function uninstall()
    {
        $this->uninstallTab();
        $db = Db::getInstance();
        try {
            $db->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . EcomLayeredtableCleaner::META_TABLE . '`');
            $db->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . EcomLayeredtableCleaner::LOG_TABLE . '`');
        } catch (Exception $e) {
            // nothing to do
        }
        foreach (array_keys(self::$defaults) as $key) {
            Configuration::deleteByName($key);
        }
        foreach (array('ELT_CRON_TOKEN', 'ELT_FEATURES_VERSION', 'ELT_LAST_RUN', 'ELT_LAST_STATS', 'ELT_LOCK', 'ELT_OPTIMIZE_PENDING_SINCE') as $key) {
            Configuration::deleteByName($key);
        }
        Configuration::deleteByName('GMARTOS_LAST_PING_' . $this->name);
        Configuration::deleteByName('GMARTOS_UPDATE_AVAILABLE_' . $this->name);
        Configuration::deleteByName('GMARTOS_LATEST_VERSION_' . $this->name);

        return parent::uninstall();
    }

    /**
     * @return bool
     */
    private function installTables()
    {
        $db = Db::getInstance();
        $ok = $db->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . EcomLayeredtableCleaner::META_TABLE . '` (
                `hash` CHAR(32) NOT NULL,
                `date_add` DATETIME NOT NULL,
                `last_used` DATETIME NOT NULL,
                `hits` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                `size` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`hash`),
                KEY `last_used` (`last_used`),
                KEY `date_add` (`date_add`),
                KEY `size` (`size`),
                KEY `hits` (`hits`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8'
        );
        $ok = $db->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . EcomLayeredtableCleaner::LOG_TABLE . '` (
                `id_log` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                `date_add` DATETIME NOT NULL,
                `trigger_type` VARCHAR(16) NOT NULL DEFAULT "",
                `action` VARCHAR(32) NOT NULL DEFAULT "",
                `rows_deleted` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                `mb_before` DECIMAL(12,2) NOT NULL DEFAULT 0,
                `mb_after` DECIMAL(12,2) NOT NULL DEFAULT 0,
                `rows_after` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                `seconds` DECIMAL(8,2) NOT NULL DEFAULT 0,
                `details` TEXT NULL,
                PRIMARY KEY (`id_log`),
                KEY `date_add` (`date_add`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8'
        ) && $ok;

        return (bool) $ok;
    }

    /**
     * Hidden tab so that the ModuleAdminController gets a token.
     *
     * @return bool
     */
    private function installTab()
    {
        $idTab = (int) Db::getInstance()->getValue(
            'SELECT id_tab FROM `' . _DB_PREFIX_ . 'tab` WHERE class_name = "' . pSQL(self::ADMIN_CONTROLLER) . '"'
        );
        if ($idTab > 0) {
            return true;
        }
        $tab = new Tab();
        $tab->class_name = self::ADMIN_CONTROLLER;
        $tab->module = $this->name;
        $tab->id_parent = -1;
        $tab->active = 1;
        $tab->name = array();
        $languages = Language::getLanguages(false);
        foreach (is_array($languages) ? $languages : array() as $lang) {
            $tab->name[(int) $lang['id_lang']] = 'Ecom Layered Table';
        }

        return (bool) $tab->add();
    }

    private function uninstallTab()
    {
        $rows = Db::getInstance()->executeS(
            'SELECT id_tab FROM `' . _DB_PREFIX_ . 'tab` WHERE class_name = "' . pSQL(self::ADMIN_CONTROLLER) . '"'
        );
        foreach (is_array($rows) ? $rows : array() as $row) {
            $tab = new Tab((int) $row['id_tab']);
            if (Validate::isLoadedObject($tab)) {
                $tab->delete();
            }
        }
    }

    /**
     * Migrations for versions installed before a feature existed. Runs on every
     * visit to the configuration page; guarded by a flag.
     */
    public function addnewfeatures()
    {
        try {
            $current = (int) Configuration::getGlobalValue('ELT_FEATURES_VERSION');
            $this->installTables();
            $this->installTab();
            foreach (self::$hooks as $hook) {
                if (!$this->isRegisteredInHook($hook)) {
                    $this->registerHook($hook);
                }
            }
            EcomLayeredtableCleaner::ensureDateColumn();
            foreach (self::$defaults as $key => $value) {
                if (Configuration::getGlobalValue($key) === false) {
                    Configuration::updateGlobalValue($key, $value);
                }
            }
            if (!Configuration::getGlobalValue('ELT_CRON_TOKEN')) {
                Configuration::updateGlobalValue('ELT_CRON_TOKEN', Tools::passwdGen(24));
            }
            if ($current < self::FEATURES_VERSION) {
                Configuration::updateGlobalValue('ELT_FEATURES_VERSION', self::FEATURES_VERSION);
            }
        } catch (Exception $e) {
            EcomLayeredtableLogger::log('addnewfeatures: ' . $e->getMessage(), array(), 'admin');
        }
    }

    /* ---------------------------------------------------------------------
     * Hooks
     * ------------------------------------------------------------------ */

    /**
     * Core hook, fired by ProductListingFrontController before ANY listing query, on every
     * PrestaShop 1.7.6+. It is the fallback for ps_facetedsearch versions without the
     * cache-key hook (4.0.0 and older): the selected filters are read from the encoded
     * facets of the URL.
     *
     * @param array $params ['query' => ProductSearchQuery]
     */
    public function hookActionProductSearchProviderRunQueryBefore($params)
    {
        if (!(int) Configuration::getGlobalValue('ELT_ENABLED')) {
            return;
        }
        try {
            $query = isset($params['query']) && is_object($params['query']) ? $params['query'] : null;
            $queryType = ($query && method_exists($query, 'getQueryType')) ? (string) $query->getQueryType() : '';
            $encoded = ($query && method_exists($query, 'getEncodedFacets')) ? (string) $query->getEncodedFacets() : '';
            $parsed = $this->parseEncodedFacets($encoded);
            $this->decideCache($queryType, $parsed['ranges'] > 0, $parsed['values'], 'core');
            $this->maybeAutoClean('front');
        } catch (Exception $e) {
            EcomLayeredtableLogger::log('core hook error: ' . $e->getMessage(), array(), 'front');
        }
    }

    /**
     * Fired by ps_facetedsearch 4.0.1+ right before it reads or writes its block cache.
     * Params: filterKey (by ref), query (ProductSearchQuery), facetedSearchFilters (by ref).
     * Here the selected filters are exact, so the same md5 as the module can be computed
     * to track the usage of the entry.
     *
     * @param array $params
     */
    public function hookActionFacetedSearchCacheKeyGeneration($params)
    {
        if (!(int) Configuration::getGlobalValue('ELT_ENABLED')) {
            return;
        }
        try {
            $queryType = '';
            if (isset($params['query']) && is_object($params['query']) && method_exists($params['query'], 'getQueryType')) {
                $queryType = (string) $params['query']->getQueryType();
            }
            $filters = (isset($params['facetedSearchFilters']) && is_array($params['facetedSearchFilters'])) ? $params['facetedSearchFilters'] : array();
            $filterKey = isset($params['filterKey']) ? (string) $params['filterKey'] : $queryType;

            $hasRange = isset($filters['price']) || isset($filters['weight']);
            $reason = $this->decideCache($queryType, $hasRange, $this->countSelectedFilters($filters), 'facetedsearch');
            if ($reason === '' && $this->isCacheableType($queryType) && (int) Configuration::getGlobalValue('ELT_TRACK_USAGE')) {
                EcomLayeredtableCleaner::touch($this->computeHash($filterKey, $filters));
            }
            $this->maybeAutoClean('front');
        } catch (Exception $e) {
            EcomLayeredtableLogger::log('hook error: ' . $e->getMessage(), array(), 'front');
        }
    }

    /**
     * Decide (once per request) whether ps_facetedsearch may use its cache, and switch it
     * off in memory when not. Returns the reason ('' = cache allowed, 'n/a' = not cacheable).
     *
     * @param string $queryType
     * @param bool $hasRange
     * @param int $valueCount
     * @param string $origin
     *
     * @return string
     */
    private function decideCache($queryType, $hasRange, $valueCount, $origin)
    {
        // Makes sure the configuration cache is loaded before a value is overridden in memory
        $cacheEnabled = (int) Configuration::get('PS_LAYERED_CACHE_ENABLED');
        if (!$cacheEnabled || !$this->isCacheableType($queryType)) {
            return 'n/a';
        }
        if (self::$skipReason !== null) {
            return self::$skipReason;
        }
        $reason = $this->getSkipReason($hasRange, $valueCount);
        self::$skipReason = $reason;
        if ($reason !== '') {
            // In-memory only: ps_facetedsearch reads this key in getFromCache() and insertIntoCache()
            Configuration::set('PS_LAYERED_CACHE_ENABLED', 0);
            EcomLayeredtableLogger::log('cache skipped: ' . $reason, array('type' => $queryType, 'values' => $valueCount, 'via' => $origin), 'front');
        }

        return $reason;
    }

    /**
     * @param string $queryType
     *
     * @return bool
     */
    public function isCacheableType($queryType)
    {
        return in_array($queryType, array('category', 'manufacturer', 'supplier'));
    }

    /**
     * Reads the facets encoded in the URL by ps_facetedsearch URLSerializer:
     * "Label-value-value/Label-symbol-min-max", with "\" escaping the separators.
     * A range is a facet with three values whose last two are numbers.
     *
     * @param string $encoded
     *
     * @return array ['facets' => int, 'values' => int, 'ranges' => int]
     */
    public function parseEncodedFacets($encoded)
    {
        $out = array('facets' => 0, 'values' => 0, 'ranges' => 0);
        $encoded = trim((string) $encoded);
        if ($encoded === '') {
            return $out;
        }
        $facets = preg_split('/(?<!\\\\)\//', $encoded);
        foreach (is_array($facets) ? $facets : array() as $facet) {
            if ($facet === '') {
                continue;
            }
            $parts = preg_split('/(?<!\\\\)-/', $facet);
            if (!is_array($parts) || count($parts) < 2) {
                continue;
            }
            array_shift($parts);
            ++$out['facets'];
            if (count($parts) === 3 && is_numeric(str_replace(',', '.', $parts[1])) && is_numeric(str_replace(',', '.', $parts[2]))) {
                ++$out['ranges'];
                ++$out['values'];
            } else {
                $out['values'] += count($parts);
            }
        }

        return $out;
    }

    /**
     * Back-office: assets for our configuration page and the automatic run.
     *
     * @param array $params
     */
    public function hookActionAdminControllerSetMedia($params)
    {
        if (Tools::getValue('configure') === $this->name) {
            $this->context->controller->addCSS($this->_path . 'views/css/admin.css');
            $this->context->controller->addJS($this->_path . 'views/js/admin.js');
            Media::addJsDef(array(
                'elt_cfg' => array(
                    'ajax_url' => $this->context->link->getAdminLink(self::ADMIN_CONTROLLER),
                    'txt' => array(
                        'confirm_truncate' => $this->trans('This empties the whole faceted search cache. It is regenerated on demand. Continue?', array(), self::TRANS_DOMAIN),
                        'confirm_optimize' => $this->trans('OPTIMIZE TABLE rebuilds the table. On a very large table it can take minutes. Continue?', array(), self::TRANS_DOMAIN),
                        'confirm_reindex' => $this->trans('Rebuilding the index can take a while on a large catalogue. Continue?', array(), self::TRANS_DOMAIN),
                        'working' => $this->trans('Working...', array(), self::TRANS_DOMAIN),
                        'done' => $this->trans('Done', array(), self::TRANS_DOMAIN),
                        'error' => $this->trans('Error', array(), self::TRANS_DOMAIN),
                        'locked' => $this->trans('Another cleaning is running. Try again in a moment.', array(), self::TRANS_DOMAIN),
                        'no_table' => $this->trans('The layered_filter_block table does not exist. Is ps_facetedsearch installed?', array(), self::TRANS_DOMAIN),
                        'deleted' => $this->trans('rows deleted', array(), self::TRANS_DOMAIN),
                        'pass' => $this->trans('pass', array(), self::TRANS_DOMAIN),
                        'confirm_datecolumn' => $this->trans('This runs ALTER TABLE on layered_filter_block. On an old MySQL with a big table it can take minutes. Continue?', array(), self::TRANS_DOMAIN),
                        'indexed' => $this->trans('products indexed', array(), self::TRANS_DOMAIN),
                        'no_log' => $this->trans('No log file yet. Enable the debug log and browse a category.', array(), self::TRANS_DOMAIN),
                    ),
                ),
            ));
        }
        if ((int) Configuration::getGlobalValue('ELT_ENABLED')) {
            $this->maybeAutoClean('admin');
        }
    }

    /* ---------------------------------------------------------------------
     * Prevention
     * ------------------------------------------------------------------ */

    /**
     * Why this request must not be cached ('' = cache it).
     *
     * @param bool $hasRange a price or weight range is selected
     * @param int $valueCount number of selected filter values
     *
     * @return string
     */
    public function getSkipReason($hasRange, $valueCount)
    {
        if ((int) Configuration::getGlobalValue('ELT_SKIP_BOTS') && $this->isBot()) {
            return 'bot';
        }
        if ((int) Configuration::getGlobalValue('ELT_SKIP_RANGES') && $hasRange) {
            return 'range';
        }
        $maxFilters = (int) Configuration::getGlobalValue('ELT_MAX_FILTERS');
        if ($maxFilters > 0 && (int) $valueCount > $maxFilters) {
            return 'too_many_filters';
        }
        if ((int) Configuration::getGlobalValue('ELT_SKIP_WHEN_FULL')) {
            $stats = EcomLayeredtableCleaner::getLastStats();
            if (!empty($stats) && (float) $stats['used_mb'] > (float) Configuration::getGlobalValue('ELT_MAX_MB')) {
                return 'full';
            }
        }

        return '';
    }

    /**
     * @return bool
     */
    public function isBot()
    {
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? Tools::strtolower(trim((string) $_SERVER['HTTP_USER_AGENT'])) : '';
        if ($ua === '') {
            return true;
        }
        $list = (string) Configuration::getGlobalValue('ELT_BOT_LIST');
        foreach (preg_split('/[\r\n,]+/', $list) as $needle) {
            $needle = Tools::strtolower(trim($needle));
            if ($needle !== '' && strpos($ua, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Number of selected values (a range counts as one).
     *
     * @param array $filters
     *
     * @return int
     */
    public function countSelectedFilters(array $filters)
    {
        $count = 0;
        foreach ($filters as $type => $values) {
            if ($type === 'price' || $type === 'weight') {
                ++$count;
                continue;
            }
            if (!is_array($values)) {
                ++$count;
                continue;
            }
            foreach ($values as $value) {
                $count += is_array($value) ? count($value) : 1;
            }
        }

        return $count;
    }

    /**
     * Same formula as Ps_Facetedsearch SearchProvider::generateCacheKeyForQuery().
     *
     * @param string $filterKey
     * @param array $filters
     *
     * @return string
     */
    public function computeHash($filterKey, array $filters)
    {
        $context = Context::getContext();

        return md5(sprintf(
            '%d-%d-%d-%s-%d-%s',
            (int) $context->shop->id,
            (int) $context->currency->id,
            (int) $context->language->id,
            $filterKey,
            (int) $context->country->id,
            serialize($filters)
        ));
    }

    /* ---------------------------------------------------------------------
     * Automatic run
     * ------------------------------------------------------------------ */

    /**
     * @param string $origin front|admin
     */
    private function maybeAutoClean($origin)
    {
        if (self::$autoRan) {
            return;
        }
        if ($origin === 'front' && !(int) Configuration::getGlobalValue('ELT_AUTO_FRONT')) {
            return;
        }
        $minutes = (int) Configuration::getGlobalValue('ELT_AUTO_MINUTES');
        if ($minutes <= 0) {
            return;
        }
        $last = (int) Configuration::getGlobalValue('ELT_LAST_RUN');
        if ((time() - $last) < $minutes * 60) {
            return;
        }
        self::$autoRan = true;
        $budget = (int) Configuration::getGlobalValue($origin === 'front' ? 'ELT_FRONT_BUDGET_SEC' : 'ELT_ADMIN_BUDGET_SEC');
        $cleaner = new EcomLayeredtableCleaner($this, $origin, max(1, $budget));
        $cleaner->run();
    }

    /**
     * @return string
     */
    public function getCronUrl()
    {
        return $this->context->link->getModuleLink($this->name, 'cron', array('token' => Configuration::getGlobalValue('ELT_CRON_TOKEN')));
    }

    /* ---------------------------------------------------------------------
     * Configuration page
     * ------------------------------------------------------------------ */

    public function getContent()
    {
        $output = '';

        if ($this->checkGmartosUpdate()) {
            $this->context->smarty->assign(array(
                'gmartos_latest_version' => Configuration::get('GMARTOS_LATEST_VERSION_' . $this->name),
            ));
            $output .= $this->fetch('module:' . $this->name . '/views/templates/admin/gmartos_update.tpl');
        }

        $this->addnewfeatures();

        if (Tools::isSubmit('submitEcomLayeredtable')) {
            $errors = $this->postProcess();
            if (empty($errors)) {
                $output .= $this->displayConfirmation($this->trans('Settings saved.', array(), self::TRANS_DOMAIN));
            } else {
                $output .= $this->displayError(implode('<br>', $errors));
            }
        }

        $output .= $this->renderDashboard();
        $output .= $this->renderForm();

        return $output;
    }

    /**
     * @return array errors
     */
    private function postProcess()
    {
        $errors = array();
        $ints = array(
            'ELT_MAX_MB' => array(50, 1000000),
            'ELT_TARGET_PCT' => array(10, 95),
            'ELT_MAX_ROWS' => array(0, 100000000),
            'ELT_MAX_ROW_KB' => array(0, 1000000),
            'ELT_BULK_TRUNCATE_PCT' => array(0, 100),
            'ELT_ALTER_MAX_MB' => array(0, 1000000),
            'ELT_TTL_DAYS' => array(0, 3650),
            'ELT_UNUSED_DAYS' => array(0, 3650),
            'ELT_OPTIMIZE_FREE_MB' => array(1, 1000000),
            'ELT_MAX_FILTERS' => array(0, 100),
            'ELT_AUTO_MINUTES' => array(0, 100000),
            'ELT_FRONT_BUDGET_SEC' => array(1, 60),
            'ELT_ADMIN_BUDGET_SEC' => array(1, 600),
            'ELT_CRON_BUDGET_SEC' => array(1, 3600),
            'ELT_BATCH_ROWS' => array(100, 50000),
            'ELT_LOG_KEEP' => array(20, 100000),
        );
        foreach ($ints as $key => $range) {
            $value = Tools::getValue($key);
            if ($value === false || $value === '' || !Validate::isInt($value) || (int) $value < $range[0] || (int) $value > $range[1]) {
                $errors[] = sprintf($this->trans('Invalid value for %1$s (allowed: %2$d to %3$d).', array(), self::TRANS_DOMAIN), $key, $range[0], $range[1]);
            }
        }
        $strategy = (string) Tools::getValue('ELT_STRATEGY');
        if (!in_array($strategy, array('lru', 'lfu', 'oldest', 'biggest'))) {
            $errors[] = $this->trans('Invalid eviction strategy.', array(), self::TRANS_DOMAIN);
        }
        if (!empty($errors)) {
            return $errors;
        }

        foreach (array_keys($ints) as $key) {
            Configuration::updateGlobalValue($key, (int) Tools::getValue($key));
        }
        Configuration::updateGlobalValue('ELT_STRATEGY', $strategy);
        foreach (array('ELT_ENABLED', 'ELT_EMERGENCY_TRUNCATE', 'ELT_TRACK_USAGE', 'ELT_OPTIMIZE', 'ELT_SKIP_BOTS', 'ELT_SKIP_RANGES', 'ELT_SKIP_WHEN_FULL', 'ELT_AUTO_FRONT', 'ELT_DEBUG_LOG') as $key) {
            Configuration::updateGlobalValue($key, (int) Tools::getValue($key) ? 1 : 0);
        }
        $botList = trim((string) Tools::getValue('ELT_BOT_LIST'));
        $botList = implode("\n", array_filter(array_map('trim', preg_split('/[\r\n,]+/', $botList))));
        // Configuration::updateValue() escapes the value itself; escaping here would double the backslashes
        Configuration::updateGlobalValue('ELT_BOT_LIST', $botList);
        if (Tools::getValue('ELT_REGENERATE_TOKEN')) {
            Configuration::updateGlobalValue('ELT_CRON_TOKEN', Tools::passwdGen(24));
        }
        EcomLayeredtableLogger::setEnabled((int) Tools::getValue('ELT_DEBUG_LOG'));
        EcomLayeredtableLogger::log('settings saved', array(), 'admin');

        return array();
    }

    /**
     * @return string
     */
    private function renderDashboard()
    {
        $facetedSearch = Module::getInstanceByName('ps_facetedsearch');
        $exists = EcomLayeredtableCleaner::blockTableExists();
        $stats = $exists ? EcomLayeredtableCleaner::measure(false) : array();
        $columnType = $exists ? EcomLayeredtableCleaner::getDataColumnType() : '';
        $maxMb = (int) Configuration::getGlobalValue('ELT_MAX_MB');
        $usedMb = isset($stats['used_mb']) ? (float) $stats['used_mb'] : 0;
        $physicalMb = isset($stats['physical_mb']) ? (float) $stats['physical_mb'] : 0;

        $hookNative = false;
        $providerFile = _PS_MODULE_DIR_ . 'ps_facetedsearch/src/Product/SearchProvider.php';
        if (file_exists($providerFile)) {
            $hookNative = (strpos((string) Tools::file_get_contents($providerFile), 'actionFacetedSearchCacheKeyGeneration') !== false);
        }

        $this->context->smarty->assign(array(
            'elt_hook_native' => $hookNative,
            'elt_date_column' => $exists && EcomLayeredtableCleaner::hasDateColumn(),
            'elt_block_oldest' => $exists ? EcomLayeredtableCleaner::blockOldest() : '',
            'elt_fs_installed' => ($facetedSearch && $facetedSearch->active),
            'elt_fs_version' => $facetedSearch ? $facetedSearch->version : '',
            'elt_fs_cache_enabled' => (int) Configuration::get('PS_LAYERED_CACHE_ENABLED'),
            'elt_table_exists' => $exists,
            'elt_stats' => $stats,
            'elt_column_type' => $columnType,
            'elt_column_is_text' => ($columnType === 'TEXT'),
            'elt_max_mb' => $maxMb,
            'elt_pct_used' => $maxMb > 0 ? min(100, round($usedMb / $maxMb * 100)) : 0,
            'elt_pct_physical' => $maxMb > 0 ? min(100, round($physicalMb / $maxMb * 100)) : 0,
            'elt_over_limit' => ($maxMb > 0 && $physicalMb > $maxMb),
            'elt_tables' => EcomLayeredtableCleaner::measureAll(),
            'elt_meta' => EcomLayeredtableCleaner::metaStats(),
            'elt_history' => EcomLayeredtableCleaner::history(20),
            'elt_last' => EcomLayeredtableCleaner::getLastStats(),
            'elt_last_run' => (int) Configuration::getGlobalValue('ELT_LAST_RUN'),
            'elt_cron_url' => $this->getCronUrl(),
            'elt_multishop' => Shop::isFeatureActive(),
            'elt_enabled' => (int) Configuration::getGlobalValue('ELT_ENABLED'),
            'elt_debug' => (int) Configuration::getGlobalValue('ELT_DEBUG_LOG'),
            'elt_optimize_pending' => (int) Configuration::getGlobalValue('ELT_OPTIMIZE_PENDING_SINCE'),
            'elt_module_version' => $this->version,
        ));

        return $this->display(__FILE__, 'views/templates/admin/dashboard.tpl');
    }

    /**
     * One HelperForm, five tabs, one submit.
     *
     * @return string
     */
    private function renderForm()
    {
        $d = self::TRANS_DOMAIN;
        $switch = function ($name, $label, $desc, $tab) use ($d) {
            return array(
                'type' => 'switch',
                'label' => $label,
                'name' => $name,
                'is_bool' => true,
                'desc' => $desc,
                'tab' => $tab,
                'values' => array(
                    array('id' => $name . '_on', 'value' => 1, 'label' => $this->trans('Yes', array(), $d)),
                    array('id' => $name . '_off', 'value' => 0, 'label' => $this->trans('No', array(), $d)),
                ),
            );
        };
        $number = function ($name, $label, $desc, $tab, $suffix = '') {
            $field = array(
                'type' => 'text',
                'label' => $label,
                'name' => $name,
                'desc' => $desc,
                'tab' => $tab,
                'class' => 'fixed-width-md',
            );
            if ($suffix !== '') {
                $field['suffix'] = $suffix;
            }

            return $field;
        };

        $fields = array();

        // Tab 1: limits
        $fields[] = $switch('ELT_ENABLED', $this->trans('Enable the guard', array(), $d), $this->trans('When disabled nothing runs automatically; the manual buttons keep working.', array(), $d), 'eltlimits');
        $fields[] = $number('ELT_MAX_MB', $this->trans('Maximum size of the table', array(), $d), $this->trans('Hard limit: above it entries are evicted down to the target, and the table is emptied if the disk file still exceeds it.', array(), $d), 'eltlimits', 'MB');
        $fields[] = $number('ELT_TARGET_PCT', $this->trans('Target after eviction', array(), $d), $this->trans('Percentage of the maximum to go down to (70 with 950 MB = 665 MB).', array(), $d), 'eltlimits', '%');
        $fields[] = $number('ELT_MAX_ROWS', $this->trans('Maximum number of entries', array(), $d), $this->trans('0 = no limit. The extra entries are removed following the eviction strategy.', array(), $d), 'eltlimits');
        $fields[] = $number('ELT_MAX_ROW_KB', $this->trans('Maximum size of a single entry', array(), $d), $this->trans('Entries larger than this are removed; 0 = no limit.', array(), $d), 'eltlimits', 'KB');
        $fields[] = array(
            'type' => 'select',
            'label' => $this->trans('Eviction strategy', array(), $d),
            'name' => 'ELT_STRATEGY',
            'desc' => $this->trans('Which entries leave first when the size or row limit is exceeded.', array(), $d),
            'tab' => 'eltlimits',
            'options' => array(
                'query' => array(
                    array('id' => 'lru', 'name' => $this->trans('Least recently used first (LRU)', array(), $d)),
                    array('id' => 'lfu', 'name' => $this->trans('Least used first (LFU)', array(), $d)),
                    array('id' => 'oldest', 'name' => $this->trans('Oldest first', array(), $d)),
                    array('id' => 'biggest', 'name' => $this->trans('Biggest first', array(), $d)),
                ),
                'id' => 'id',
                'name' => 'name',
            ),
        );
        $fields[] = $number('ELT_BULK_TRUNCATE_PCT', $this->trans('Empty the table instead of evicting when more than', array(), $d), $this->trans('Above this percentage a TRUNCATE (one second) replaces row-by-row deletion; 0 = always row by row.', array(), $d), 'eltlimits', '%');
        $fields[] = $switch('ELT_EMERGENCY_TRUNCATE', $this->trans('Emergency: empty the table if the file on disk still exceeds the limit', array(), $d), $this->trans('Only OPTIMIZE or TRUNCATE return disk space; the front office cannot OPTIMIZE, so after a grace period it empties the table.', array(), $d), 'eltlimits');

        // Tab 2: date and usage
        $fields[] = $number('ELT_TTL_DAYS', $this->trans('Delete entries older than', array(), $d), $this->trans('Days since the entry was created. 0 = never.', array(), $d), 'eltclean', $this->trans('days', array(), $d));
        $fields[] = $number('ELT_UNUSED_DAYS', $this->trans('Delete entries not used for', array(), $d), $this->trans('Days since the entry was last requested. 0 = never.', array(), $d), 'eltclean', $this->trans('days', array(), $d));
        $fields[] = $switch('ELT_TRACK_USAGE', $this->trans('Track usage of each entry', array(), $d), $this->trans('One small query per listing page; needed by the "not used for" rule and the LRU/LFU strategies.', array(), $d), 'eltclean');
        $fields[] = $switch('ELT_OPTIMIZE', $this->trans('Run OPTIMIZE TABLE to reclaim disk space', array(), $d), $this->trans('Only from the back-office and the cron.', array(), $d), 'eltclean');
        $fields[] = $number('ELT_OPTIMIZE_FREE_MB', $this->trans('Optimize when the fragmented space exceeds', array(), $d), $this->trans('Space that InnoDB keeps reserved after deletions (DATA_FREE).', array(), $d), 'eltclean', 'MB');

        // Tab 3: prevention
        $fields[] = $switch('ELT_SKIP_BOTS', $this->trans('Do not cache requests from bots', array(), $d), $this->trans('Crawlers create entries nobody reads again; the facets still work for them.', array(), $d), 'eltprevent');
        $fields[] = array(
            'type' => 'textarea',
            'label' => $this->trans('Bot signatures', array(), $d),
            'name' => 'ELT_BOT_LIST',
            'desc' => $this->trans('One per line, matched against the User-Agent; an empty User-Agent counts as a bot.', array(), $d),
            'tab' => 'eltprevent',
            'rows' => 8,
            'cols' => 40,
        );
        $fields[] = $switch('ELT_SKIP_RANGES', $this->trans('Do not cache requests with a price or weight range', array(), $d), $this->trans('Every slider position is a new entry: the main reason the table grows.', array(), $d), 'eltprevent');
        $fields[] = $number('ELT_MAX_FILTERS', $this->trans('Do not cache requests with more than', array(), $d), $this->trans('Combinations of many filters are rarely repeated; 0 = no limit.', array(), $d), 'eltprevent', $this->trans('filters', array(), $d));
        $fields[] = $switch('ELT_SKIP_WHEN_FULL', $this->trans('Do not cache while the table is over the limit', array(), $d), $this->trans('Uses the size measured by the last run, so it costs nothing per request.', array(), $d), 'eltprevent');

        // Tab 4: automation
        $fields[] = $number('ELT_AUTO_MINUTES', $this->trans('Automatic run every', array(), $d), $this->trans('Minimum minutes between two automatic runs; 0 = only by cron or manually.', array(), $d), 'eltauto', $this->trans('minutes', array(), $d));
        $fields[] = $switch('ELT_AUTO_FRONT', $this->trans('Allow the automatic run from front-office visits', array(), $d), $this->trans('One visitor every N minutes pays the time budget; turn it off if you configure the cron.', array(), $d), 'eltauto');
        $fields[] = $number('ELT_FRONT_BUDGET_SEC', $this->trans('Time budget from the front office', array(), $d), $this->trans('The run stops when the budget is over and continues next time.', array(), $d), 'eltauto', $this->trans('seconds', array(), $d));
        $fields[] = $number('ELT_ADMIN_BUDGET_SEC', $this->trans('Time budget from the back-office', array(), $d), '', 'eltauto', $this->trans('seconds', array(), $d));
        $fields[] = $number('ELT_CRON_BUDGET_SEC', $this->trans('Time budget from the cron', array(), $d), $this->trans('The cron URL is in the panel above; every hour is enough.', array(), $d), 'eltauto', $this->trans('seconds', array(), $d));
        $fields[] = $number('ELT_BATCH_ROWS', $this->trans('Rows per batch', array(), $d), $this->trans('Entries deleted per SQL statement.', array(), $d), 'eltauto');
        $fields[] = $number('ELT_ALTER_MAX_MB', $this->trans('Add the date column only when the table is under', array(), $d), $this->trans('Without instant ALTER (MySQL < 8.0, MariaDB < 10.3) adding the date column rebuilds the table, so it waits until the table is this small; 0 = no limit.', array(), $d), 'eltauto', 'MB');
        $fields[] = $switch('ELT_REGENERATE_TOKEN', $this->trans('Regenerate the cron token', array(), $d), $this->trans('The old cron URL stops working.', array(), $d), 'eltauto');

        // Tab 5: log
        $fields[] = $switch('ELT_DEBUG_LOG', $this->trans('Debug log', array(), $d), $this->trans('Writes what the module decides to modules/ecom_layeredtable/logs/; turn it off when done.', array(), $d), 'eltlog');
        $fields[] = $number('ELT_LOG_KEEP', $this->trans('History entries to keep', array(), $d), $this->trans('Rows in the run history shown in the panel.', array(), $d), 'eltlog');

        $form = array(
            'form' => array(
                'legend' => array('title' => $this->trans('Settings', array(), $d), 'icon' => 'icon-cogs'),
                'tabs' => array(
                    'eltlimits' => $this->trans('Limits', array(), $d),
                    'eltclean' => $this->trans('Date and usage', array(), $d),
                    'eltprevent' => $this->trans('Prevention', array(), $d),
                    'eltauto' => $this->trans('Automation', array(), $d),
                    'eltlog' => $this->trans('Log', array(), $d),
                ),
                'input' => $fields,
                'submit' => array('title' => $this->trans('Save', array(), $d)),
            ),
        );

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->title = $this->displayName;
        $helper->show_toolbar = false;
        $helper->submit_action = 'submitEcomLayeredtable';
        $helper->fields_value = $this->getConfigFormValues();
        $helper->tpl_vars = array(
            'fields_value' => $helper->fields_value,
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($form));
    }

    /**
     * @return array
     */
    private function getConfigFormValues()
    {
        $values = array();
        foreach (self::$defaults as $key => $default) {
            $value = Tools::getValue($key, Configuration::getGlobalValue($key));
            $values[$key] = ($value === false || $value === null) ? $default : $value;
        }
        $values['ELT_REGENERATE_TOKEN'] = 0;

        return $values;
    }

    /* ---------------------------------------------------------------------
     * Gmartos registration (skill prestashop_connect_gmartos, native cURL)
     * ------------------------------------------------------------------ */

    /**
     * Registers the install and checks for a newer version, once a week, 3 s timeout, silent.
     *
     * @return bool true when an update is available
     */
    private function checkGmartosUpdate()
    {
        $lastCheck = (int) Configuration::get('GMARTOS_LAST_PING_' . $this->name);
        if ((time() - $lastCheck) < 604800) {
            return (bool) Configuration::get('GMARTOS_UPDATE_AVAILABLE_' . $this->name);
        }
        if (!function_exists('curl_init')) {
            return false;
        }
        $data = array(
            'action' => 'register',
            'module_name' => $this->name,
            'version' => $this->version,
            'url' => Tools::getShopDomainSsl(true),
            'email' => Configuration::get('PS_SHOP_EMAIL'),
        );
        try {
            $ch = \curl_init('https://modules.gmartos.es/api.php');
            \curl_setopt_array($ch, array(
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_SSL_VERIFYPEER => true,
            ));
            $body = \curl_exec($ch);
            $code = (int) \curl_getinfo($ch, CURLINFO_HTTP_CODE);
            \curl_close($ch);
        } catch (Exception $e) {
            return false;
        }
        if ($code !== 200 || !$body) {
            return false;
        }
        $json = json_decode($body, true);
        if (!is_array($json) || empty($json['success'])) {
            return false;
        }
        Configuration::updateValue('GMARTOS_LAST_PING_' . $this->name, time());
        if (!empty($json['update_available'])) {
            Configuration::updateValue('GMARTOS_UPDATE_AVAILABLE_' . $this->name, true);
            Configuration::updateValue('GMARTOS_LATEST_VERSION_' . $this->name, isset($json['latest_version']) ? $json['latest_version'] : '');

            return true;
        }
        Configuration::updateValue('GMARTOS_UPDATE_AVAILABLE_' . $this->name, false);

        return false;
    }
}

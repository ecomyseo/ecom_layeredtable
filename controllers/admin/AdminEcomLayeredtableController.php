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

require_once _PS_MODULE_DIR_ . 'ecom_layeredtable/classes/EcomLayeredtableLogger.php';
require_once _PS_MODULE_DIR_ . 'ecom_layeredtable/classes/EcomLayeredtableCleaner.php';

/**
 * AJAX endpoints for the buttons of the configuration panel.
 */
class AdminEcomLayeredtableController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    /**
     * Opened directly: go to the module configuration page.
     */
    public function initContent()
    {
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules', true, array(), array('configure' => 'ecom_layeredtable')));
    }

    /* ---------------------------------------------------------------------
     * Actions
     * ------------------------------------------------------------------ */

    public function ajaxProcessStats()
    {
        $this->out(array('ok' => true) + $this->stats());
    }

    public function ajaxProcessClean()
    {
        $budget = (int) Configuration::getGlobalValue('ELT_ADMIN_BUDGET_SEC');
        $cleaner = new EcomLayeredtableCleaner($this->module, 'manual', max(1, $budget));
        $summary = $cleaner->run();
        $this->out(array('ok' => true, 'summary' => $summary) + $this->stats());
    }

    public function ajaxProcessReconcile()
    {
        $cleaner = new EcomLayeredtableCleaner($this->module, 'manual', (int) Configuration::getGlobalValue('ELT_ADMIN_BUDGET_SEC'));
        $this->out(array('ok' => true, 'summary' => $cleaner->single('reconcile')) + $this->stats());
    }

    public function ajaxProcessOptimize()
    {
        $cleaner = new EcomLayeredtableCleaner($this->module, 'manual', 600);
        $this->out(array('ok' => true, 'summary' => $cleaner->single('optimize')) + $this->stats());
    }

    public function ajaxProcessDatecolumn()
    {
        $cleaner = new EcomLayeredtableCleaner($this->module, 'manual', 600);
        $this->out(array('ok' => true, 'summary' => $cleaner->single('datecolumn')) + $this->stats());
    }

    public function ajaxProcessTruncate()
    {
        $cleaner = new EcomLayeredtableCleaner($this->module, 'manual', 60);
        $this->out(array('ok' => true, 'summary' => $cleaner->single('truncate')) + $this->stats());
    }

    /**
     * Attributes, features and attribute groups index of ps_facetedsearch (fast).
     */
    public function ajaxProcessReindexAttributes()
    {
        $facetedSearch = $this->getFacetedSearch();
        if (!$facetedSearch) {
            $this->out(array('ok' => false, 'error' => 'ps_facetedsearch not available'));
        }
        try {
            Shop::setContext(Shop::CONTEXT_ALL);
            $facetedSearch->indexAttributes();
            $facetedSearch->indexFeatures();
            $facetedSearch->indexAttributeGroup();
            EcomLayeredtableLogger::log('reindex attributes', array(), 'manual');
        } catch (Exception $e) {
            $this->out(array('ok' => false, 'error' => $e->getMessage()));
        }
        $this->out(array('ok' => true, 'finished' => true) + $this->stats());
    }

    /**
     * Price index of ps_facetedsearch, cursor by cursor (same protocol as its own page).
     */
    public function ajaxProcessReindexPrices()
    {
        $facetedSearch = $this->getFacetedSearch();
        if (!$facetedSearch || !method_exists($facetedSearch, 'fullPricesIndexProcess')) {
            $this->out(array('ok' => false, 'error' => 'ps_facetedsearch not available'));
        }
        $cursor = (int) Tools::getValue('cursor');
        try {
            Shop::setContext(Shop::CONTEXT_ALL);
            $result = $facetedSearch->fullPricesIndexProcess($cursor, true, true);
        } catch (Exception $e) {
            $this->out(array('ok' => false, 'error' => $e->getMessage()));
        }
        $data = is_string($result) ? json_decode($result, true) : null;
        $next = (is_array($data) && isset($data['cursor'])) ? (int) $data['cursor'] : 0;
        $finished = ($next <= 0 || $next === $cursor);
        if ($finished) {
            Configuration::updateGlobalValue('PS_LAYERED_INDEXED', 1);
            EcomLayeredtableLogger::log('reindex prices finished', array(), 'manual');
        }
        $this->out(array(
            'ok' => true,
            'finished' => $finished,
            'cursor' => $next,
            'total' => (is_array($data) && isset($data['total'])) ? (int) $data['total'] : 0,
            'count' => (is_array($data) && isset($data['count'])) ? (int) $data['count'] : 0,
            'raw' => is_string($result) ? Tools::substr($result, 0, 200) : '',
        ));
    }

    public function ajaxProcessLog()
    {
        if (Tools::getValue('clear')) {
            EcomLayeredtableLogger::clear();
        }
        $this->out(array('ok' => true, 'log' => EcomLayeredtableLogger::tail(300)));
    }

    /* ---------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    /**
     * @return Module|false
     */
    private function getFacetedSearch()
    {
        $module = Module::getInstanceByName('ps_facetedsearch');
        if (!$module || !$module->active) {
            return false;
        }

        return $module;
    }

    /**
     * @return array
     */
    private function stats()
    {
        $exists = EcomLayeredtableCleaner::blockTableExists();

        return array(
            'exists' => $exists,
            'stats' => $exists ? EcomLayeredtableCleaner::measure(true) : array(),
            'tables' => EcomLayeredtableCleaner::measureAll(),
            'meta' => EcomLayeredtableCleaner::metaStats(),
            'history' => EcomLayeredtableCleaner::history(20),
            'last' => EcomLayeredtableCleaner::getLastStats(),
            'max_mb' => (int) Configuration::getGlobalValue('ELT_MAX_MB'),
            'column_type' => $exists ? EcomLayeredtableCleaner::getDataColumnType() : '',
            'date_column' => $exists ? EcomLayeredtableCleaner::hasDateColumn(true) : false,
            'block_oldest' => $exists ? EcomLayeredtableCleaner::blockOldest() : '',
            'optimize_pending' => (int) Configuration::getGlobalValue('ELT_OPTIMIZE_PENDING_SINCE'),
        );
    }

    /**
     * Anti-cache headers, no Content-Type (jQuery would parse the JSON twice).
     *
     * @param array $data
     */
    private function out(array $data)
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        die(json_encode($data));
    }
}

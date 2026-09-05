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
 * Cron entry point: /index.php?fc=module&module=ecom_layeredtable&controller=cron&token=...
 * Optional: &action=optimize | truncate | reconcile | stats (default: full run)
 */
class Ecom_layeredtableCronModuleFrontController extends ModuleFrontController
{
    public function __construct()
    {
        parent::__construct();
        $this->ajax = true;
    }

    public function init()
    {
        parent::init();

        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');

        $token = (string) Configuration::getGlobalValue('ELT_CRON_TOKEN');
        if ($token === '' || !hash_equals($token, (string) Tools::getValue('token'))) {
            header('HTTP/1.1 403 Forbidden');
            die(json_encode(array('ok' => false, 'error' => 'bad token')));
        }

        set_time_limit(0);
        $action = (string) Tools::getValue('action');
        $budget = (int) Configuration::getGlobalValue('ELT_CRON_BUDGET_SEC');
        $cleaner = new EcomLayeredtableCleaner($this->module, 'cron', max(5, $budget));

        switch ($action) {
            case 'stats':
                $data = array('ok' => true, 'stats' => EcomLayeredtableCleaner::measure(true), 'meta' => EcomLayeredtableCleaner::metaStats());
                break;
            case 'optimize':
            case 'truncate':
            case 'reconcile':
                $data = array('ok' => true, 'summary' => $cleaner->single($action));
                break;
            default:
                $data = array('ok' => true, 'summary' => $cleaner->run());
        }

        die(json_encode($data));
    }
}

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
 * File logger. Only writes when the debug option (ELT_DEBUG_LOG) is enabled.
 * Files live in modules/ecom_layeredtable/logs/elt-YYYY-MM-DD.log
 */
class EcomLayeredtableLogger
{
    const KEEP_DAYS = 14;

    /** @var int|null cached flag for the current request */
    private static $enabled = null;

    /**
     * @return string
     */
    public static function getDir()
    {
        return _PS_MODULE_DIR_ . 'ecom_layeredtable/logs/';
    }

    /**
     * @return bool
     */
    public static function isEnabled()
    {
        if (self::$enabled === null) {
            self::$enabled = (int) Configuration::getGlobalValue('ELT_DEBUG_LOG');
        }

        return (bool) self::$enabled;
    }

    /**
     * Force the flag for the current request (used by the admin controller).
     *
     * @param bool $enabled
     */
    public static function setEnabled($enabled)
    {
        self::$enabled = $enabled ? 1 : 0;
    }

    /**
     * @param string $message
     * @param array $context
     * @param string $channel front|admin|cron|manual
     */
    public static function log($message, $context = array(), $channel = 'core')
    {
        if (!self::isEnabled()) {
            return;
        }
        try {
            $dir = self::getDir();
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (!is_writable($dir)) {
                return;
            }
            $line = '[' . date('Y-m-d H:i:s') . '] [' . $channel . '] ' . $message;
            if (!empty($context)) {
                $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            @file_put_contents($dir . 'elt-' . date('Y-m-d') . '.log', $line . "\n", FILE_APPEND | LOCK_EX);
            self::rotate($dir);
        } catch (Exception $e) {
            // never break the shop because of a log line
        }
    }

    /**
     * Delete log files older than KEEP_DAYS (runs at most once a day).
     *
     * @param string $dir
     */
    private static function rotate($dir)
    {
        $marker = $dir . '.rotated';
        if (file_exists($marker) && (time() - (int) filemtime($marker)) < 86400) {
            return;
        }
        @touch($marker);
        $files = glob($dir . 'elt-*.log');
        foreach (is_array($files) ? $files : array() as $file) {
            if ((time() - (int) filemtime($file)) > self::KEEP_DAYS * 86400) {
                @unlink($file);
            }
        }
    }

    /**
     * Tail of today's log (for the back-office panel).
     *
     * @param int $lines
     *
     * @return string
     */
    public static function tail($lines = 200)
    {
        $file = self::getDir() . 'elt-' . date('Y-m-d') . '.log';
        if (!file_exists($file)) {
            $files = glob(self::getDir() . 'elt-*.log');
            if (!is_array($files) || empty($files)) {
                return '';
            }
            sort($files);
            $file = end($files);
        }
        $content = @file($file);
        if (!is_array($content)) {
            return '';
        }

        return implode('', array_slice($content, -1 * (int) $lines));
    }

    /**
     * Delete every log file.
     */
    public static function clear()
    {
        $files = glob(self::getDir() . 'elt-*.log');
        foreach (is_array($files) ? $files : array() as $file) {
            @unlink($file);
        }
    }
}

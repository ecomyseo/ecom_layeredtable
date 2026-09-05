/**
 * Ecom Layered Table - keeps the ps_facetedsearch filter block cache under control
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 *
 * Reference copy of the tables created by Ecom_Layeredtable::installTables().
 * PREFIX_ is replaced by the shop prefix; the module runs the same statements from PHP.
 */
CREATE TABLE IF NOT EXISTS `PREFIX_ecom_layeredtable_meta` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `PREFIX_ecom_layeredtable_log` (
    `id_log` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `date_add` DATETIME NOT NULL,
    `trigger_type` VARCHAR(16) NOT NULL DEFAULT '',
    `action` VARCHAR(32) NOT NULL DEFAULT '',
    `rows_deleted` INT(10) UNSIGNED NOT NULL DEFAULT 0,
    `mb_before` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `mb_after` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `rows_after` INT(10) UNSIGNED NOT NULL DEFAULT 0,
    `seconds` DECIMAL(8,2) NOT NULL DEFAULT 0,
    `details` TEXT NULL,
    PRIMARY KEY (`id_log`),
    KEY `date_add` (`date_add`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

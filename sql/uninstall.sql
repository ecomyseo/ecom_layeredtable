/**
 * Ecom Layered Table - keeps the ps_facetedsearch filter block cache under control
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 *
 * Reference copy of what Ecom_Layeredtable::uninstall() runs. The faceted search
 * cache table (PREFIX_layered_filter_block) is never dropped by this module.
 */
DROP TABLE IF EXISTS `PREFIX_ecom_layeredtable_meta`;
DROP TABLE IF EXISTS `PREFIX_ecom_layeredtable_log`;

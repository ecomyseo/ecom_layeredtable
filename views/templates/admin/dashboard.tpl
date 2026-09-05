{**
 * Ecom Layered Table - keeps the ps_facetedsearch filter block cache under control
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 *}
<div id="elt-dashboard" class="panel">
    <div class="panel-heading">
        <i class="icon-database"></i> {l s='Faceted search cache guard' d='Modules.Ecomlayeredtable.Admin'}
        <span class="badge">v{$elt_module_version|escape:'html':'UTF-8'}</span>
        <span class="panel-heading-action">
            <a href="#" class="list-toolbar-btn" data-elt-action="stats" title="{l s='Refresh' d='Modules.Ecomlayeredtable.Admin'}"><i class="process-icon-refresh"></i></a>
        </span>
    </div>

    {if !$elt_fs_installed}
        <div class="alert alert-danger">{l s='ps_facetedsearch is not active.' d='Modules.Ecomlayeredtable.Admin'}</div>
    {elseif !$elt_table_exists}
        <div class="alert alert-danger">{l s='The layered_filter_block table does not exist in the database.' d='Modules.Ecomlayeredtable.Admin'}</div>
    {/if}
    {if !$elt_enabled}
        <div class="alert alert-warning">{l s='Guard disabled: nothing runs automatically.' d='Modules.Ecomlayeredtable.Admin'}</div>
    {/if}
    {if $elt_over_limit}
        <div class="alert alert-danger">{l s='Table over the limit: press "Clean now".' d='Modules.Ecomlayeredtable.Admin'}</div>
    {/if}
    {if $elt_optimize_pending}
        <div class="alert alert-warning">{l s='Disk file over the limit: press "Optimize".' d='Modules.Ecomlayeredtable.Admin'}</div>
    {/if}
    {if $elt_table_exists && !$elt_date_column}
        <div class="alert alert-warning">{l s='No date column yet: press "Add date column".' d='Modules.Ecomlayeredtable.Admin'}</div>
    {/if}

    <div class="panel-body">
        <div class="row elt-cards">
            <div class="col-md-3 col-sm-6">
                <div class="elt-card">
                    <div class="elt-card-label">{l s='Live data' d='Modules.Ecomlayeredtable.Admin'}</div>
                    <div class="elt-card-value"><span id="elt-used-mb">{if $elt_table_exists}{$elt_stats.used_mb|escape:'html':'UTF-8'}{else}0{/if}</span> MB</div>
                    <div class="elt-card-sub">{l s='limit' d='Modules.Ecomlayeredtable.Admin'} <span id="elt-max-mb">{$elt_max_mb|escape:'html':'UTF-8'}</span> MB</div>
                    <div class="progress elt-progress"><div id="elt-bar-used" class="progress-bar {if $elt_pct_used >= 100}progress-bar-danger{elseif $elt_pct_used >= 80}progress-bar-warning{else}progress-bar-success{/if}" style="width:{$elt_pct_used|escape:'html':'UTF-8'}%"></div></div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="elt-card">
                    <div class="elt-card-label">{l s='File on disk' d='Modules.Ecomlayeredtable.Admin'}</div>
                    <div class="elt-card-value"><span id="elt-physical-mb">{if $elt_table_exists}{$elt_stats.physical_mb|escape:'html':'UTF-8'}{else}0{/if}</span> MB</div>
                    <div class="elt-card-sub">{l s='fragmented' d='Modules.Ecomlayeredtable.Admin'} <span id="elt-free-mb">{if $elt_table_exists}{$elt_stats.free_mb|escape:'html':'UTF-8'}{else}0{/if}</span> MB</div>
                    <div class="progress elt-progress"><div id="elt-bar-physical" class="progress-bar {if $elt_pct_physical >= 100}progress-bar-danger{elseif $elt_pct_physical >= 80}progress-bar-warning{else}progress-bar-info{/if}" style="width:{$elt_pct_physical|escape:'html':'UTF-8'}%"></div></div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="elt-card">
                    <div class="elt-card-label">{l s='Cached entries' d='Modules.Ecomlayeredtable.Admin'}</div>
                    <div class="elt-card-value"><span id="elt-rows">{if $elt_table_exists}{$elt_stats.rows|escape:'html':'UTF-8'}{else}0{/if}</span></div>
                    <div class="elt-card-sub"><span id="elt-tracked">{$elt_meta.tracked|escape:'html':'UTF-8'}</span> {l s='tracked' d='Modules.Ecomlayeredtable.Admin'}</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="elt-card">
                    <div class="elt-card-label">{l s='Last run' d='Modules.Ecomlayeredtable.Admin'}</div>
                    <div class="elt-card-value elt-card-small" id="elt-last-run">{if $elt_last_run}{$elt_last_run|date_format:'%d/%m/%Y %H:%M'}{else}{l s='never' d='Modules.Ecomlayeredtable.Admin'}{/if}</div>
                    <div class="elt-card-sub" id="elt-last-sub">{if $elt_last}{$elt_last.trigger|escape:'html':'UTF-8'} &middot; {$elt_last.deleted|escape:'html':'UTF-8'} {l s='rows deleted' d='Modules.Ecomlayeredtable.Admin'} &middot; {$elt_last.seconds|escape:'html':'UTF-8'} s{else}&nbsp;{/if}</div>
                </div>
            </div>
        </div>

        <div class="elt-info">
            <span><strong>ps_facetedsearch</strong> {if $elt_fs_installed}{$elt_fs_version|escape:'html':'UTF-8'}{else}-{/if}{if $elt_fs_installed && !$elt_fs_cache_enabled} &middot; {l s='cache off' d='Modules.Ecomlayeredtable.Admin'}{/if}</span>
            <span><strong>{l s='Column' d='Modules.Ecomlayeredtable.Admin'}</strong> <span id="elt-column-type">{$elt_column_type|escape:'html':'UTF-8'}</span></span>
            <span><strong>{l s='Date column' d='Modules.Ecomlayeredtable.Admin'}</strong> <span id="elt-date-column">{if $elt_date_column}{l s='yes' d='Modules.Ecomlayeredtable.Admin'}{if $elt_block_oldest} &middot; {$elt_block_oldest|escape:'html':'UTF-8'}{/if}{else}{l s='no' d='Modules.Ecomlayeredtable.Admin'}{/if}</span></span>
            <span><strong>{l s='Usage' d='Modules.Ecomlayeredtable.Admin'}</strong> <span id="elt-meta-summary">{$elt_meta.hits|escape:'html':'UTF-8'} {l s='hits' d='Modules.Ecomlayeredtable.Admin'} &middot; {$elt_meta.avg_kb|escape:'html':'UTF-8'} KB &middot; {$elt_meta.max_kb|escape:'html':'UTF-8'} KB &middot; {$elt_meta.never_used|escape:'html':'UTF-8'}</span></span>
            {if $elt_multishop}<span><strong>{l s='Multistore' d='Modules.Ecomlayeredtable.Admin'}</strong> {l s='global settings' d='Modules.Ecomlayeredtable.Admin'}</span>{/if}
        </div>

        <div class="elt-actions">
            <button type="button" class="btn btn-primary" data-elt-action="clean"><i class="icon-eraser"></i> {l s='Clean now' d='Modules.Ecomlayeredtable.Admin'}</button>
            <button type="button" class="btn btn-default" data-elt-action="reconcile"><i class="icon-link"></i> {l s='Reconcile' d='Modules.Ecomlayeredtable.Admin'}</button>
            <button type="button" class="btn btn-default" data-elt-action="optimize" data-elt-confirm="confirm_optimize"><i class="icon-compress"></i> {l s='Optimize' d='Modules.Ecomlayeredtable.Admin'}</button>
            <button type="button" class="btn btn-danger" data-elt-action="truncate" data-elt-confirm="confirm_truncate"><i class="icon-trash"></i> {l s='Empty the cache' d='Modules.Ecomlayeredtable.Admin'}</button>
            {if $elt_table_exists && !$elt_date_column}
            <button type="button" class="btn btn-default" data-elt-action="datecolumn" data-elt-confirm="confirm_datecolumn"><i class="icon-calendar"></i> {l s='Add date column' d='Modules.Ecomlayeredtable.Admin'}</button>
            {/if}
            <span class="elt-sep"></span>
            <button type="button" class="btn btn-default" data-elt-action="reindexAttributes" data-elt-confirm="confirm_reindex"><i class="icon-tags"></i> {l s='Rebuild attribute index' d='Modules.Ecomlayeredtable.Admin'}</button>
            <button type="button" class="btn btn-default" data-elt-action="reindexPrices" data-elt-confirm="confirm_reindex"><i class="icon-money"></i> {l s='Rebuild price index' d='Modules.Ecomlayeredtable.Admin'}</button>
            <span class="elt-sep"></span>
            <button type="button" class="btn btn-default" data-elt-action="log"><i class="icon-file-text"></i> {l s='Show log' d='Modules.Ecomlayeredtable.Admin'}</button>
            <button type="button" class="btn btn-default" data-elt-action="log" data-elt-clear="1"><i class="icon-remove"></i> {l s='Clear log' d='Modules.Ecomlayeredtable.Admin'}</button>
        </div>
        <p class="help-block elt-actions-help">{l s='Clean now applies every rule until the table is under the limit. Optimize gives the disk space back. Empty the cache deletes everything (it regenerates itself). The index buttons do the same as the ps_facetedsearch page.' d='Modules.Ecomlayeredtable.Admin'}</p>

        <div id="elt-result" class="alert alert-info" hidden></div>
        <pre id="elt-log" class="elt-log" hidden></pre>

        <div class="elt-help">
            <a href="#" class="elt-help-toggle" data-elt-toggle="elt-help-body"><i class="icon-chevron-right"></i> {l s='Help: how it works' d='Modules.Ecomlayeredtable.Admin'}</a>
            <div id="elt-help-body" class="elt-help-body" hidden>
                <ul>
                    <li><strong>{l s='Why the table grows' d='Modules.Ecomlayeredtable.Admin'}</strong> {l s='Every combination of filters, currency, language and country is a new entry, price ranges are infinite, bots crawl every URL, and nothing ever expires.' d='Modules.Ecomlayeredtable.Admin'}</li>
                    <li><strong>{l s='Clean now' d='Modules.Ecomlayeredtable.Admin'}</strong> {l s='Runs in passes of a few seconds until the table is under the limit. If more than the configured percentage of the table has to go, it empties it in one second instead of row by row.' d='Modules.Ecomlayeredtable.Admin'}</li>
                    <li><strong>{l s='Reconcile' d='Modules.Ecomlayeredtable.Admin'}</strong> {l s='Gives a date and a size to the entries the module does not know yet.' d='Modules.Ecomlayeredtable.Admin'}</li>
                    <li><strong>{l s='Optimize' d='Modules.Ecomlayeredtable.Admin'}</strong> {l s='InnoDB keeps the space of deleted rows; OPTIMIZE TABLE rebuilds the table and returns it. Never from the front office: it can take minutes.' d='Modules.Ecomlayeredtable.Admin'}</li>
                    <li><strong>{l s='Date column' d='Modules.Ecomlayeredtable.Admin'}</strong> {l s='The module adds date_add to layered_filter_block to clean by age. ps_facetedsearch names its columns when writing, so it is not affected. On old MySQL with a big table the ALTER waits until the table is small.' d='Modules.Ecomlayeredtable.Admin'}</li>
                    <li><strong>{l s='Automatic' d='Modules.Ecomlayeredtable.Admin'}</strong> {l s='One visitor every N minutes runs a short bounded pass; the back-office runs a longer one; the cron URL below runs the complete one with OPTIMIZE.' d='Modules.Ecomlayeredtable.Admin'}</li>
                    <li><strong>{l s='Prevention' d='Modules.Ecomlayeredtable.Admin'}</strong> {l s='Bots, price or weight ranges, too many filters and a full table are not written to the cache. The facets still work; they are just not stored.' d='Modules.Ecomlayeredtable.Admin'}</li>
                    {if $elt_fs_installed && !$elt_hook_native}
                    <li><strong>ps_facetedsearch {$elt_fs_version|escape:'html':'UTF-8'}</strong> {l s='This version has no cache-key hook (added in 4.0.1): prevention works through the core hook, but the usage of each entry cannot be tracked, so entries are cleaned by age.' d='Modules.Ecomlayeredtable.Admin'}</li>
                    {/if}
                    {if $elt_column_is_text}
                    <li><strong>TEXT</strong> {l s='The data column holds 64 KB per entry; larger blocks are silently truncated and rewritten on every visit. The module deletes them.' d='Modules.Ecomlayeredtable.Admin'}</li>
                    {/if}
                    {if $elt_fs_installed && !$elt_fs_cache_enabled}
                    <li><strong>{l s='Cache off' d='Modules.Ecomlayeredtable.Admin'}</strong> {l s='The block cache is disabled in ps_facetedsearch, so the table cannot grow; this module only keeps it clean.' d='Modules.Ecomlayeredtable.Admin'}</li>
                    {/if}
                    {if $elt_multishop}
                    <li><strong>{l s='Multistore' d='Modules.Ecomlayeredtable.Admin'}</strong> {l s='The table is shared by every shop, so all the settings of this module are global.' d='Modules.Ecomlayeredtable.Admin'}</li>
                    {/if}
                </ul>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <h4>{l s='Tables of the faceted search' d='Modules.Ecomlayeredtable.Admin'}</h4>
                <table class="table elt-table" id="elt-tables">
                    <thead><tr>
                        <th>{l s='Table' d='Modules.Ecomlayeredtable.Admin'}</th>
                        <th class="text-right">{l s='Rows' d='Modules.Ecomlayeredtable.Admin'}</th>
                        <th class="text-right">{l s='Data' d='Modules.Ecomlayeredtable.Admin'} (MB)</th>
                        <th class="text-right">{l s='Free' d='Modules.Ecomlayeredtable.Admin'} (MB)</th>
                        <th class="text-right">{l s='Disk' d='Modules.Ecomlayeredtable.Admin'} (MB)</th>
                    </tr></thead>
                    <tbody>
                    {foreach $elt_tables as $t}
                        <tr{if $t.is_block} class="elt-row-block"{/if}>
                            <td>{$t.table|escape:'html':'UTF-8'}</td>
                            <td class="text-right">{$t.rows|escape:'html':'UTF-8'}</td>
                            <td class="text-right">{$t.used_mb|escape:'html':'UTF-8'}</td>
                            <td class="text-right">{$t.free_mb|escape:'html':'UTF-8'}</td>
                            <td class="text-right">{$t.physical_mb|escape:'html':'UTF-8'}</td>
                        </tr>
                    {foreachelse}
                        <tr><td colspan="5">{l s='No layered_* table found.' d='Modules.Ecomlayeredtable.Admin'}</td></tr>
                    {/foreach}
                    </tbody>
                </table>
            </div>
            <div class="col-md-6">
                <h4>{l s='Run history' d='Modules.Ecomlayeredtable.Admin'}</h4>
                <table class="table elt-table" id="elt-history">
                    <thead><tr>
                        <th>{l s='Date' d='Modules.Ecomlayeredtable.Admin'}</th>
                        <th>{l s='Origin' d='Modules.Ecomlayeredtable.Admin'}</th>
                        <th class="text-right">{l s='Deleted' d='Modules.Ecomlayeredtable.Admin'}</th>
                        <th class="text-right">{l s='Before' d='Modules.Ecomlayeredtable.Admin'}</th>
                        <th class="text-right">{l s='After' d='Modules.Ecomlayeredtable.Admin'}</th>
                        <th class="text-right">s</th>
                        <th>{l s='Actions' d='Modules.Ecomlayeredtable.Admin'}</th>
                    </tr></thead>
                    <tbody>
                    {foreach $elt_history as $h}
                        <tr>
                            <td>{$h.date_add|escape:'html':'UTF-8'}</td>
                            <td>{$h.trigger_type|escape:'html':'UTF-8'}</td>
                            <td class="text-right">{$h.rows_deleted|escape:'html':'UTF-8'}</td>
                            <td class="text-right">{$h.mb_before|escape:'html':'UTF-8'}</td>
                            <td class="text-right">{$h.mb_after|escape:'html':'UTF-8'}</td>
                            <td class="text-right">{$h.seconds|escape:'html':'UTF-8'}</td>
                            <td class="elt-details" title="{$h.details|escape:'html':'UTF-8'}">{$h.action|escape:'html':'UTF-8'}</td>
                        </tr>
                    {foreachelse}
                        <tr><td colspan="7">{l s='No run yet.' d='Modules.Ecomlayeredtable.Admin'}</td></tr>
                    {/foreach}
                    </tbody>
                </table>
            </div>
        </div>

        <h4>{l s='Cron' d='Modules.Ecomlayeredtable.Admin'}</h4>
        <div class="input-group">
            <input type="text" class="form-control" readonly value="{$elt_cron_url|escape:'html':'UTF-8'}" id="elt-cron-url">
            <span class="input-group-btn"><button type="button" class="btn btn-default" data-elt-action="copy"><i class="icon-copy"></i> {l s='Copy' d='Modules.Ecomlayeredtable.Admin'}</button></span>
        </div>
        <p class="help-block">{l s='Every hour.' d='Modules.Ecomlayeredtable.Admin'} <code>0 * * * * curl -s "{$elt_cron_url|escape:'html':'UTF-8'}" > /dev/null</code></p>
    </div>
</div>

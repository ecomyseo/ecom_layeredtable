/**
 * Ecom Layered Table - keeps the ps_facetedsearch filter block cache under control
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
(function () {
    'use strict';

    var cfg = window.elt_cfg || { ajax_url: '', txt: {} };
    var busy = false;

    function txt(key) {
        return (cfg.txt && cfg.txt[key]) ? cfg.txt[key] : key;
    }

    function esc(value) {
        return String(value === undefined || value === null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function byId(id) {
        return document.getElementById(id);
    }

    function setText(id, value) {
        var el = byId(id);
        if (el) {
            el.textContent = value;
        }
    }

    function showResult(kind, html) {
        var box = byId('elt-result');
        if (!box) {
            return;
        }
        box.className = 'alert alert-' + kind;
        box.innerHTML = html;
        box.hidden = false;
    }

    function setBusy(state, button) {
        busy = state;
        var buttons = document.querySelectorAll('#elt-dashboard [data-elt-action]');
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].disabled = state;
        }
        if (button) {
            var icon = button.querySelector('i');
            if (icon) {
                icon.classList.toggle('elt-spin', state);
            }
        }
    }

    function request(action, extra) {
        var body = new FormData();
        body.append('ajax', '1');
        body.append('action', action);
        if (extra) {
            Object.keys(extra).forEach(function (key) {
                body.append(key, extra[key]);
            });
        }
        return fetch(cfg.ajax_url, { method: 'POST', body: body, credentials: 'same-origin', cache: 'no-store' })
            .then(function (response) {
                return response.text().then(function (text) {
                    var data;
                    try {
                        data = JSON.parse(text);
                    } catch (e) {
                        throw new Error('HTTP ' + response.status + ': ' + text.substring(0, 300));
                    }
                    if (!response.ok || !data || data.ok === false) {
                        throw new Error((data && data.error) ? data.error : ('HTTP ' + response.status));
                    }
                    return data;
                });
            });
    }

    function barClass(pct, base) {
        if (pct >= 100) {
            return 'progress-bar progress-bar-danger';
        }
        if (pct >= 80) {
            return 'progress-bar progress-bar-warning';
        }
        return 'progress-bar ' + base;
    }

    function render(data) {
        var stats = data.stats || {};
        var maxMb = parseFloat(data.max_mb || 0);
        var used = parseFloat(stats.used_mb || 0);
        var physical = parseFloat(stats.physical_mb || 0);
        setText('elt-used-mb', used);
        setText('elt-physical-mb', physical);
        setText('elt-free-mb', stats.free_mb || 0);
        setText('elt-rows', stats.rows || 0);
        setText('elt-column-type', data.column_type || '');
        if (data.date_column !== undefined) {
            var dateEl = byId('elt-date-column');
            if (dateEl && data.date_column) {
                dateEl.textContent = 'OK' + (data.block_oldest ? ' · ' + data.block_oldest : '');
            }
        }
        if (data.meta) {
            setText('elt-tracked', data.meta.tracked);
            var meta = byId('elt-meta-summary');
            if (meta) {
                meta.textContent = data.meta.hits + ' hits · ' + data.meta.avg_kb + ' KB · ' + data.meta.max_kb + ' KB · ' + data.meta.never_used;
            }
        }
        var pctUsed = maxMb > 0 ? Math.min(100, Math.round(used / maxMb * 100)) : 0;
        var pctPhysical = maxMb > 0 ? Math.min(100, Math.round(physical / maxMb * 100)) : 0;
        var barUsed = byId('elt-bar-used');
        if (barUsed) {
            barUsed.style.width = pctUsed + '%';
            barUsed.className = barClass(pctUsed, 'progress-bar-success');
        }
        var barPhysical = byId('elt-bar-physical');
        if (barPhysical) {
            barPhysical.style.width = pctPhysical + '%';
            barPhysical.className = barClass(pctPhysical, 'progress-bar-info');
        }
        if (data.last && data.last.time) {
            var d = new Date(data.last.time * 1000);
            setText('elt-last-run', d.toLocaleString());
            setText('elt-last-sub', data.last.trigger + ' · ' + data.last.deleted + ' ' + txt('deleted') + ' · ' + data.last.seconds + ' s');
        }
        renderTables(data.tables || []);
        renderHistory(data.history || []);
    }

    function renderTables(tables) {
        var tbody = document.querySelector('#elt-tables tbody');
        if (!tbody) {
            return;
        }
        var html = '';
        tables.forEach(function (t) {
            html += '<tr' + (t.is_block ? ' class="elt-row-block"' : '') + '><td>' + esc(t.table) + '</td>' +
                '<td class="text-right">' + esc(t.rows) + '</td>' +
                '<td class="text-right">' + esc(t.used_mb) + '</td>' +
                '<td class="text-right">' + esc(t.free_mb) + '</td>' +
                '<td class="text-right">' + esc(t.physical_mb) + '</td></tr>';
        });
        tbody.innerHTML = html;
    }

    function renderHistory(rows) {
        var tbody = document.querySelector('#elt-history tbody');
        if (!tbody) {
            return;
        }
        var html = '';
        rows.forEach(function (h) {
            html += '<tr><td>' + esc(h.date_add) + '</td><td>' + esc(h.trigger_type) + '</td>' +
                '<td class="text-right">' + esc(h.rows_deleted) + '</td>' +
                '<td class="text-right">' + esc(h.mb_before) + '</td>' +
                '<td class="text-right">' + esc(h.mb_after) + '</td>' +
                '<td class="text-right">' + esc(h.seconds) + '</td>' +
                '<td class="elt-details" title="' + esc(h.details) + '">' + esc(h.action) + '</td></tr>';
        });
        tbody.innerHTML = html;
    }

    function describe(summary) {
        if (!summary) {
            return '';
        }
        if (summary.skipped === 'locked') {
            return txt('locked');
        }
        if (summary.skipped === 'no_table') {
            return txt('no_table');
        }
        if (summary.skipped === 'measure_failed') {
            return 'information_schema: ' + JSON.stringify(summary.actions || []);
        }
        var parts = [summary.deleted + ' ' + txt('deleted') + ' (' + summary.deleted_mb + ' MB)', summary.seconds + ' s'];
        (summary.actions || []).forEach(function (a) {
            var label = a.action + ': ' + a.rows;
            if (a.mb !== undefined) {
                label += ' / ' + a.mb + ' MB';
            }
            if (a.message) {
                label += ' - ' + a.message;
            }
            parts.push(label);
        });
        if (summary.after) {
            parts.push('→ ' + summary.after.used_mb + ' MB / ' + summary.after.physical_mb + ' MB');
        }
        return parts.join(' · ');
    }

    function reindexPrices(button, cursor, total) {
        return request('reindexPrices', { cursor: cursor }).then(function (data) {
            var done = total + (data.count || 0);
            showResult('info', txt('working') + ' ' + done + (data.total ? ' / ' + data.total : '') + ' ' + txt('indexed'));
            if (!data.finished && data.cursor > cursor) {
                return reindexPrices(button, data.cursor, done);
            }
            showResult('success', txt('done') + ': ' + done + ' ' + txt('indexed'));
            return request('stats').then(render);
        });
    }

    /**
     * "Clean now": one bounded request after another until the table is under the limit
     * (the server says "continue" while it is over the limit and still making progress).
     */
    function cleanLoop(pass, totalDeleted) {
        return request('clean').then(function (data) {
            render(data);
            var summary = data.summary || {};
            totalDeleted += summary.deleted || 0;
            var text = txt('pass') + ' ' + pass + ' · ' + totalDeleted + ' ' + txt('deleted') + ' · ' + esc(describe(summary));
            if (summary['continue'] && pass < 200) {
                showResult('info', txt('working') + ' ' + text);
                return cleanLoop(pass + 1, totalDeleted);
            }
            showResult(summary.skipped ? 'warning' : 'success', txt('done') + ': ' + text);
        });
    }

    function run(button) {
        var action = button.getAttribute('data-elt-action');
        if (busy) {
            return;
        }
        if (action === 'copy') {
            var input = byId('elt-cron-url');
            if (input) {
                input.select();
                try {
                    document.execCommand('copy');
                } catch (e) {
                    // ignore
                }
            }
            return;
        }
        var confirmKey = button.getAttribute('data-elt-confirm');
        if (confirmKey && !window.confirm(txt(confirmKey))) {
            return;
        }
        setBusy(true, button);
        showResult('info', txt('working'));
        var promise;
        if (action === 'reindexPrices') {
            promise = reindexPrices(button, 0, 0);
        } else if (action === 'clean') {
            promise = cleanLoop(1, 0);
        } else if (action === 'log') {
            promise = request('log', { clear: button.getAttribute('data-elt-clear') ? '1' : '' }).then(function (data) {
                var pre = byId('elt-log');
                if (pre) {
                    pre.textContent = data.log || txt('no_log');
                    pre.hidden = false;
                    pre.scrollTop = pre.scrollHeight;
                }
                showResult('success', txt('done'));
            });
        } else {
            promise = request(action).then(function (data) {
                render(data);
                var kind = (data.summary && data.summary.skipped) ? 'warning' : 'success';
                showResult(kind, txt('done') + (data.summary ? ': ' + esc(describe(data.summary)) : ''));
            });
        }
        promise.catch(function (error) {
            showResult('danger', txt('error') + ': ' + esc(error.message));
        }).then(function () {
            setBusy(false, button);
        });
    }

    document.addEventListener('click', function (event) {
        var toggle = event.target.closest ? event.target.closest('[data-elt-toggle]') : null;
        if (toggle) {
            event.preventDefault();
            var body = byId(toggle.getAttribute('data-elt-toggle'));
            if (body) {
                body.hidden = !body.hidden;
                var icon = toggle.querySelector('i');
                if (icon) {
                    icon.className = body.hidden ? 'icon-chevron-right' : 'icon-chevron-down';
                }
            }
            return;
        }
        var button = event.target.closest ? event.target.closest('[data-elt-action]') : null;
        if (!button || !document.getElementById('elt-dashboard')) {
            return;
        }
        event.preventDefault();
        run(button);
    });
})();

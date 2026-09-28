/* ClickHouse demo 页面交互
   页面首屏由服务端渲染，这里负责后续操作：造数 / 清理 / 清空 / 筛选 / 分页 / 图表 hover 提示 */

(function () {
    'use strict';

    var API = '/api/clickhouse_demo';

    var state = {
        page: 1,
        size: 10,
        pages: 1,
        event_type: '',
    };

    function $(selector, root) {
        return (root || document).querySelector(selector);
    }

    function esc(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function fmtInt(value) {
        return Number(value || 0).toLocaleString('en-US');
    }

    // 大数字紧凑显示：1,284 / 12.9K / 4.2M
    function fmtCompact(value) {
        var num = Number(value || 0);

        if (num < 10000) {
            return fmtInt(num);
        }

        if (num < 1000000) {
            return (num / 1000).toFixed(1) + 'K';
        }

        return (num / 1000000).toFixed(1) + 'M';
    }

    function fmtMoney(value) {
        return Number(value || 0).toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    }

    function api(path, options) {
        return fetch(API + path, options).then(function (res) {
            return res.json().then(function (body) {
                if (body.code !== 0) {
                    throw new Error(body.msg || ('请求失败（HTTP ' + res.status + '）'));
                }

                return body.data;
            });
        });
    }

    function post(path, data) {
        return api(path, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(data || {}).toString(),
        });
    }

    /* ---------- 提示与确认弹层 ---------- */

    function toast(message, type) {
        var el = document.createElement('div');

        el.className = 'toast' + (type === 'error' ? ' toast-error' : '');
        el.textContent = message;

        $('#toast-wrap').appendChild(el);

        setTimeout(function () {
            el.remove();
        }, type === 'error' ? 6000 : 3000);
    }

    var modalResolve = null;

    function confirmModal(title, text) {
        $('#modal-title').textContent = title;
        $('#modal-text').textContent = text;
        $('#modal-mask').hidden = false;

        return new Promise(function (resolve) {
            modalResolve = resolve;
        });
    }

    $('#modal-mask').addEventListener('click', function (event) {
        var action = event.target.getAttribute('data-modal');

        // 点弹层外的遮罩等同取消
        if (!action && event.target !== event.currentTarget) {
            return;
        }

        $('#modal-mask').hidden = true;

        if (modalResolve) {
            modalResolve(action === 'ok');
            modalResolve = null;
        }
    });

    /* ---------- 渲染 ---------- */

    function renderOverview(data) {
        $('[data-stat="total"]').textContent = fmtCompact(data.total);
        $('[data-stat="today"]').textContent = fmtInt(data.today);
        $('[data-stat="user_count"]').textContent = fmtInt(data.user_count);
        $('[data-stat="amount"]').textContent = fmtMoney(data.amount);
    }

    function barPercent(count, max) {
        return max > 0 ? Math.round(count * 10000 / max) / 100 : 0;
    }

    function renderTypes(data) {
        var chart = $('#type-chart');
        var max = data.rows.reduce(function (acc, row) {
            return Math.max(acc, Number(row.count));
        }, 0);

        var html = data.rows.map(function (row) {
            return '<div class="hbar" tabindex="0" data-type="' + esc(row.event_type) +
                '" data-count="' + esc(row.count) + '" data-amount="' + esc(row.amount) + '">' +
                '<span class="hbar-label">' + esc(row.event_type) + '</span>' +
                '<div class="hbar-track"><div class="hbar-fill" style="width: ' +
                barPercent(Number(row.count), max) + '%"></div></div>' +
                '<span class="hbar-value">' + fmtInt(row.count) + '</span>' +
                '</div>';
        }).join('');

        if (!data.rows.length) {
            html = '<p class="empty">暂无数据，先点下方的「造数」写入一批事件</p>';
        }

        chart.innerHTML = html;

        // 类型下拉跟随实际数据刷新，保留当前选中项
        var select = $('#type-filter');
        var current = select.value;

        select.innerHTML = '<option value="">全部</option>' + data.types.map(function (type) {
            return '<option value="' + esc(type) + '">' + esc(type) + '</option>';
        }).join('');

        if (data.types.indexOf(current) < 0) {
            // 选中类型已被清掉（清表 / 清理老数据），筛选条件一并复位
            state.event_type = '';
            current = '';
        }

        select.value = current;
    }

    function renderDaily(rows) {
        var chart = $('#daily-chart');
        var max = rows.reduce(function (acc, row) {
            return Math.max(acc, Number(row.count));
        }, 0);

        var html = rows.map(function (row) {
            var isMax = max > 0 && Number(row.count) === max;

            return '<div class="col' + (isMax ? ' is-max' : '') + '" tabindex="0" data-date="' +
                esc(row.event_date) + '" data-count="' + esc(row.count) + '" data-amount="' + esc(row.amount) + '">' +
                '<span class="col-value">' + fmtInt(row.count) + '</span>' +
                '<div class="col-track"><div class="col-fill" style="height: ' +
                barPercent(Number(row.count), max) + '%"></div></div>' +
                '<span class="col-label">' + esc(row.event_date.slice(5)) + '</span>' +
                '</div>';
        }).join('');

        if (!rows.length) {
            html = '<p class="empty">暂无数据，先点下方的「造数」写入一批事件</p>';
        }

        chart.innerHTML = html;
    }

    function renderEvents(data) {
        var body = $('#events-body');

        body.innerHTML = data.list.map(function (event) {
            return '<tr>' +
                '<td class="mono">' + esc(event.event_time) + '</td>' +
                '<td class="num">' + esc(event.user_id) + '</td>' +
                '<td><span class="tag">' + esc(event.event_type) + '</span></td>' +
                '<td>' + esc(event.channel) + '</td>' +
                '<td class="num">' + esc(event.amount) + '</td>' +
                '<td class="num">' + esc(event.duration_ms) + '</td>' +
                '<td class="mono props">' + esc(event.props) + '</td>' +
                '</tr>';
        }).join('');

        $('#events-empty').hidden = data.list.length > 0;

        var pagination = data.pagination;

        // 记录总页数，翻页按钮据此夹取，翻到底不再空跑一次请求
        state.pages = Math.max(pagination.pages, 1);

        $('#pager-info').textContent = '第 ' + pagination.page + ' / ' + state.pages +
            ' 页 · 共 ' + fmtInt(pagination.count) + ' 条';
    }

    /* ---------- 图表 hover / focus 提示 ---------- */

    var tip = $('#chart-tip');

    function showTip(target, event) {
        var isColumn = target.classList.contains('col');

        tip.innerHTML = '<b>' + esc(isColumn ? target.dataset.date : target.dataset.type) + '</b><br>' +
            '事件数 ' + fmtInt(target.dataset.count) + '<br>' +
            '金额 ' + fmtMoney(target.dataset.amount);
        tip.hidden = false;

        var rect = target.getBoundingClientRect();
        var left = event && event.clientX ? event.clientX + 12 : rect.left + rect.width / 2;
        var top = event && event.clientY ? event.clientY + 14 : rect.top - 8;

        tip.style.left = Math.min(left, window.innerWidth - tip.offsetWidth - 12) + 'px';
        tip.style.top = top + 'px';
    }

    function hideTip() {
        tip.hidden = true;
    }

    document.addEventListener('mouseover', function (event) {
        var target = event.target.closest('.hbar, .col');

        if (target) {
            showTip(target, event);
        }
    });

    document.addEventListener('mousemove', function (event) {
        var target = event.target.closest('.hbar, .col');

        if (target) {
            showTip(target, event);
        }
    });

    document.addEventListener('mouseout', function (event) {
        if (event.target.closest('.hbar, .col')) {
            hideTip();
        }
    });

    document.addEventListener('focusin', function (event) {
        var target = event.target.closest('.hbar, .col');

        if (target) {
            showTip(target, null);
        }
    });

    document.addEventListener('focusout', hideTip);

    /* ---------- 刷新与操作 ---------- */

    function refreshAll() {
        var events = $('#events-body');

        events.classList.add('is-loading');

        return Promise.all([
            api('/overview'),
            api('/event_types'),
            api('/daily?days=7'),
            api('/events?page=' + state.page + '&size=' + state.size +
                '&event_type=' + encodeURIComponent(state.event_type)),
        ]).then(function (res) {
            renderOverview(res[0]);
            renderTypes(res[1]);
            renderDaily(res[2]);
            renderEvents(res[3]);
        }).catch(function (error) {
            toast(error.message, 'error');
            throw error;
        }).finally(function () {
            events.classList.remove('is-loading');
        });
    }

    function withBusy(button, promise) {
        button.disabled = true;

        promise.catch(function () {
            // 错误已在 refreshAll / 调用处提示
        }).finally(function () {
            button.disabled = false;
        });
    }

    document.addEventListener('click', function (event) {
        var action = event.target.getAttribute('data-action');
        var page = event.target.getAttribute('data-page');

        if (page) {
            state.page = page === 'prev'
                ? Math.max(1, state.page - 1)
                : Math.min(state.pages, state.page + 1);

            refreshAll();

            return;
        }

        if (action === 'refresh') {
            withBusy(event.target, refreshAll());

            return;
        }

        if (action === 'seed') {
            var rows = Math.min(100000, Math.max(1, parseInt($('#seed-rows').value, 10) || 1000));

            $('#seed-rows').value = rows;

            withBusy(event.target, post('/seed', { rows: rows }).then(function (data) {
                toast('写入 ' + fmtInt(data.written) + ' 行');
                return refreshAll();
            }).catch(function (error) {
                toast(error.message, 'error');
            }));

            return;
        }

        if (action === 'purge') {
            confirmModal('清理 7 天前数据', '将删除 event_date 早于 7 天前的事件，ClickHouse 的 alter delete 是异步 mutation，操作不可撤销。').then(function (ok) {
                if (!ok) {
                    return;
                }

                event.target.disabled = true;

                post('/purge', { days: 7 }).then(function () {
                    toast('已清理 7 天前数据');
                    state.page = 1;
                    return refreshAll();
                }).catch(function (error) {
                    toast(error.message, 'error');
                }).finally(function () {
                    event.target.disabled = false;
                });
            });

            return;
        }

        if (action === 'clear') {
            confirmModal('清空表', '将执行 truncate table demo_user_event，表中数据会全部删除，操作不可撤销。').then(function (ok) {
                if (!ok) {
                    return;
                }

                event.target.disabled = true;

                post('/clear').then(function () {
                    toast('表已清空');
                    state.page = 1;
                    return refreshAll();
                }).catch(function (error) {
                    toast(error.message, 'error');
                }).finally(function () {
                    event.target.disabled = false;
                });
            });
        }
    });

    $('#type-filter').addEventListener('change', function (event) {
        state.event_type = event.target.value;
        state.page = 1;

        refreshAll();
    });

    // 首屏由服务端渲染，这里只把空状态与数字格式对齐到前端口径
    refreshAll().catch(function () {
        // 首屏刷新失败（如 ClickHouse 掉线）时保留服务端渲染结果，错误已 toast
    });
})();

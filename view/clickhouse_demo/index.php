<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ClickHouse 使用 demo</title>
    <link rel="stylesheet" href="/assets/css/clickhouse_demo.css">
</head>
<body>
<div class="page">

    <header class="page-head">
        <div>
            <h1>ClickHouse 使用 demo</h1>
            <p class="page-sub">表 demo_user_event · 批量写入 / 参数绑定 / 聚合 / 分页 / mutation 全链路示例</p>
        </div>
        <span class="status {{ $status['connected'] ? 'status-on' : 'status-off' }}">
            <span class="status-dot"></span>
            {{ $status['connected'] ? '已连接' : '未连接' }}
            @if ($status['version'])
            <em>v{{ $status['version'] }}</em>
            @endif
        </span>
    </header>

    @if ($error)
    <div class="alert">{{{ $error }}}</div>
    @endif

    <section class="overview">
        <div class="card hero-card">
            <span class="stat-label">事件总量</span>
            <strong class="hero-value" data-stat="total">{{ $overview['total'] }}</strong>
            <span class="stat-hint">表 demo_user_event 的行数</span>
        </div>

        <div class="tiles">
            <div class="card">
                <span class="stat-label">今日事件</span>
                <strong class="stat-value" data-stat="today">{{ $overview['today'] }}</strong>
            </div>
            <div class="card">
                <span class="stat-label">独立用户</span>
                <strong class="stat-value" data-stat="user_count">{{ $overview['user_count'] }}</strong>
            </div>
            <div class="card">
                <span class="stat-label">支付总额</span>
                <strong class="stat-value" data-stat="amount">{{ $overview['amount'] }}</strong>
            </div>
        </div>
    </section>

    <section class="charts">
        <div class="card">
            <div class="card-head">
                <h2>事件类型分布</h2>
                <span class="card-sub">按事件数</span>
            </div>
            <div class="hbar-chart" id="type-chart">
                @foreach ($event_type_stats['rows'] as $row)
                <div class="hbar" data-type="{{{ $row['event_type'] }}}" data-count="{{ $row['count'] }}" data-amount="{{{ $row['amount'] }}}">
                    <span class="hbar-label">{{{ $row['event_type'] }}}</span>
                    <div class="hbar-track">
                        <div class="hbar-fill" style="width: {{ $max_type_count > 0 ? round($row['count'] * 100 / $max_type_count, 2) : 0 }}%"></div>
                    </div>
                    <span class="hbar-value">{{ $row['count'] }}</span>
                </div>
                @endforeach
                @if (! $event_type_stats['rows'])
                <p class="empty">暂无数据，先点下方的「造数」写入一批事件</p>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <h2>近 7 天趋势</h2>
                <span class="card-sub">按事件数</span>
            </div>
            <div class="col-chart" id="daily-chart">
                @foreach ($daily as $row)
                <div class="col{{ $row['count'] == $max_daily_count ? ' is-max' : '' }}" data-date="{{{ $row['event_date'] }}}" data-count="{{ $row['count'] }}" data-amount="{{{ $row['amount'] }}}">
                    <span class="col-value">{{ $row['count'] }}</span>
                    <div class="col-track">
                        <div class="col-fill" style="height: {{ $max_daily_count > 0 ? round($row['count'] * 100 / $max_daily_count, 2) : 0 }}%"></div>
                    </div>
                    <span class="col-label">{{ substr($row['event_date'], 5) }}</span>
                </div>
                @endforeach
                @if (! $daily)
                <p class="empty">暂无数据，先点下方的「造数」写入一批事件</p>
                @endif
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card-head">
            <h2>事件明细</h2>
            <div class="actions">
                <label class="field">
                    造数行数
                    <input type="number" id="seed-rows" value="1000" min="1" max="100000" step="100">
                </label>
                <button type="button" class="btn btn-primary" data-action="seed">写入</button>
                <button type="button" class="btn" data-action="purge">清理 7 天前</button>
                <button type="button" class="btn btn-danger" data-action="clear">清空表</button>
            </div>
        </div>

        <div class="filter-row">
            <label class="field">
                事件类型
                <select id="type-filter">
                    <option value="">全部</option>
                    @foreach ($event_type_stats['types'] as $type)
                    <option value="{{{ $type }}}">{{{ $type }}}</option>
                    @endforeach
                </select>
            </label>
            <button type="button" class="btn btn-ghost" data-action="refresh">刷新</button>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th>时间</th>
                    <th class="num">用户 ID</th>
                    <th>事件</th>
                    <th>渠道</th>
                    <th class="num">金额</th>
                    <th class="num">耗时</th>
                    <th>属性</th>
                </tr>
                </thead>
                <tbody id="events-body">
                @foreach ($events['list'] as $event)
                <tr>
                    <td class="mono">{{{ $event['event_time'] }}}</td>
                    <td class="num">{{ $event['user_id'] }}</td>
                    <td><span class="tag">{{{ $event['event_type'] }}}</span></td>
                    <td>{{{ $event['channel'] }}}</td>
                    <td class="num">{{ $event['amount'] }}</td>
                    <td class="num">{{ $event['duration_ms'] }}</td>
                    <td class="mono props">{{{ $event['props'] }}}</td>
                </tr>
                @endforeach
                </tbody>
            </table>
            <p class="empty" id="events-empty" @if ($events['list']) hidden @endif>暂无数据</p>
        </div>

        <div class="pager">
            <span class="pager-info" id="pager-info">第 {{ $events['pagination']['page'] }} / {{ max($events['pagination']['pages'], 1) }} 页 · 共 {{ $events['pagination']['count'] }} 条</span>
            <div class="pager-btns">
                <button type="button" class="btn btn-ghost" data-page="prev">上一页</button>
                <button type="button" class="btn btn-ghost" data-page="next">下一页</button>
            </div>
        </div>
    </section>

    <footer class="page-foot">
        数据来源：ClickHouse HTTP 接口（<code>frame/clickhouse.php</code>） · 接口：<code>/api/clickhouse_demo/*</code>
    </footer>
</div>

<div class="chart-tip" id="chart-tip" hidden></div>

<div class="modal-mask" id="modal-mask" hidden>
    <div class="modal">
        <h3 id="modal-title">确认操作</h3>
        <p id="modal-text"></p>
        <div class="modal-btns">
            <button type="button" class="btn" data-modal="cancel">取消</button>
            <button type="button" class="btn btn-danger" data-modal="ok">确认</button>
        </div>
    </div>
</div>

<div class="toast-wrap" id="toast-wrap"></div>

<script src="/assets/js/clickhouse_demo.js"></script>
</body>
</html>

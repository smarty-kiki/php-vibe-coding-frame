<?php

// ClickHouse 使用 demo 的页面路由：服务端渲染首屏，后续交互由 /api/clickhouse_demo/* 刷新
// 取数逻辑与接口入口共用 domain/knowledge/clickhouse_demo.php

if_get('/clickhouse_demo', function () {

    $status = ['connected' => false, 'version' => null];
    $overview = ['total' => 0, 'today' => 0, 'user_count' => 0, 'amount' => '0'];
    $event_type_stats = ['rows' => [], 'types' => []];
    $daily = [];
    $events = ['list' => [], 'pagination' => ['page' => 1, 'size' => 10, 'count' => 0, 'pages' => 0]];
    $error = '';

    try {

        $status = clickhouse_demo_status();

        if ($status['connected']) {
            $overview = clickhouse_demo_overview();
            $event_type_stats = clickhouse_demo_event_type_stats();
            $daily = clickhouse_demo_daily_trend(7);
            $events = clickhouse_demo_find_events(1, 10);
        } else {
            $error = 'ClickHouse 连接失败，请检查服务是否已启动（php public/cli.php 无 clickhouse:ping 命令，可 curl http://127.0.0.1:8123/ping 验证）';
        }
    } catch (Throwable $ex) {

        // 表没建、SQL 写错等问题在这里兜住，页面给出提示而不是整页 500
        $error = '查询 ClickHouse 失败：'.$ex->getMessage();

        log_exception($ex);
    }

    // 图表需要的最大值，用于把绝对值换算成百分比宽度 / 高度
    $max_type_count = 0;

    foreach ($event_type_stats['rows'] as $row) {
        $max_type_count = max($max_type_count, (int) $row['count']);
    }

    $max_daily_count = 0;

    foreach ($daily as $row) {
        $max_daily_count = max($max_daily_count, (int) $row['count']);
    }

    return render('clickhouse_demo/index', [
        'status' => $status,
        'overview' => $overview,
        'event_type_stats' => $event_type_stats,
        'daily' => $daily,
        'events' => $events,
        'error' => $error,
        'max_type_count' => $max_type_count,
        'max_daily_count' => $max_daily_count,
    ]);
});

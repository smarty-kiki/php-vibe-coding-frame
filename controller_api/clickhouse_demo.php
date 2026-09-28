<?php

// ClickHouse 使用 demo 的接口路由（/api/clickhouse_demo/*）
//
// 路由闭包只做入参校验与响应组装，查询与写入逻辑统一封装在 domain/knowledge/clickhouse_demo.php，
// 与 controller/clickhouse_demo.php 页面入口共用同一套取数函数。
// 表结构见 command/migration/clickhouse_sql/*_create_demo_user_event_table.sql，
// ClickHouse 的使用要点见 domain/knowledge/clickhouse_demo.php 头部注释

// 连通性检查：连接或认证失败返回 false，不抛异常
if_get('/api/clickhouse_demo/ping', function () {

    return clickhouse_demo_status();
});

// 概览指标
if_get('/api/clickhouse_demo/overview', function () {

    return clickhouse_demo_overview();
});

// 按天趋势
if_get('/api/clickhouse_demo/daily', function () {

    $days = (int) input('days', 14);

    otherwise($days >= 1 && $days <= 90, 'days 取值范围 1~90');

    return clickhouse_demo_daily_trend($days);
});

// 事件类型分布
if_get('/api/clickhouse_demo/event_types', function () {

    return clickhouse_demo_event_type_stats();
});

// 事件明细分页
if_get('/api/clickhouse_demo/events', function () {

    $page = (int) input('page', 1);
    $size = (int) input('size', 10);

    otherwise($page >= 1, 'page 必须大于 0');
    otherwise($size >= 1 && $size <= 100, 'size 取值范围 1~100');

    return clickhouse_demo_find_events($page, $size, trim((string) input('event_type', '')));
});

// 最新一条事件
if_get('/api/clickhouse_demo/latest', function () {

    return clickhouse_demo_find_latest();
});

// 造数：批量写入
if_post('/api/clickhouse_demo/seed', function () {

    $rows = (int) input('rows', 1000);

    otherwise($rows >= 1 && $rows <= 100000, 'rows 取值范围 1~100000');

    return ['written' => clickhouse_demo_insert_seed_rows($rows)];
});

// 清空表
if_post('/api/clickhouse_demo/clear', function () {

    clickhouse_demo_clear();

    return ['cleared' => true];
});

// 清理历史数据（异步 mutation，需等待 mutation 完成）
if_post('/api/clickhouse_demo/purge', function () {

    $days = (int) input('days', 30);

    otherwise($days >= 1 && $days <= 365, 'days 取值范围 1~365');

    clickhouse_demo_purge($days);

    return ['purged' => true];
});

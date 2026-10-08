<?php

// ClickHouse 表结构迁移，与 MySQL 版 migrate.php 并列，文件格式沿用同一套 # up / # down 约定
//
// 与 MySQL 版的差异：
// - ClickHouse 没有事务，迁移 SQL 应自带 if not exists / if exists 保证重跑安全
// - 改 ORDER BY / 分区键 / 引擎无法 ALTER，只能重建表，down 会丢数据
// - HTTP 接口不接受一次提交多条语句，按 ; 拆开逐条执行；字符串字面量里的 ; 会被误拆

define('CH_MIGRATION_DIR', COMMAND_DIR.'/migration/clickhouse_sql');
define('CH_MIGRATION_TABLE', 'migrations');
define('CH_MIGRATION_CONFIG_KEY', 'migrate');

function _ch_migration_files()
{
    $files = scandir(CH_MIGRATION_DIR);

    return array_diff($files, ['.', '..', '.gitkeep']);
}

function _ch_migration_file_path($name)
{
    return CH_MIGRATION_DIR.'/'.date('Y_m_d_H_i_s_').$name.'.sql';
}

// 解析单个迁移文件，返回 [up 语句数组, down 语句数组]
//
// 没有直接复用 migrate.php 的 _migration_file_explode()：那个按 ; 裸拆，注释行里的分号会把 -- 标记切断，
// 残片会被当成 SQL 发给 ClickHouse 变成语法错误。这里先把整行 -- 注释剥掉再拆，其余拆分规则与它一致。
// 行尾注释不剥离，交给 ClickHouse 自己解析。
function _ch_migration_file_explode($filepath)
{
    $lines = [];

    foreach (explode("\n", file_get_contents($filepath)) as $line) {

        if (starts_with(trim($line), '--')) {
            continue;
        }

        $lines[] = $line;
    }

    $sections = explode('# down', implode("\n", $lines));

    $downs = _ch_migration_statements(explode(';', $sections[1] ?? ''));

    $ups = _ch_migration_statements(explode(';', explode('# up', $sections[0])[1] ?? ''));

    return [$ups, $downs];
}

// 去掉空语句，未编辑的模板拆出来的就是空数组
function _ch_migration_statements(array $sqls)
{
    $res = [];

    foreach ($sqls as $sql) {

        $sql = trim($sql);

        if ($sql !== '') {

            $res[] = $sql;
        }
    }

    return $res;
}

// 已应用记录，按批次与文件名升序
function _ch_migration_applied()
{
    return ch_query(
        'select `migration`, `batch` from `'.CH_MIGRATION_TABLE.'` order by `batch`, `migration`',
        [],
        CH_MIGRATION_CONFIG_KEY);
}

// 已应用记录里的最大批次，没有记录时返回 0
function _ch_migration_last_batch(array $applied)
{
    $batch = 0;

    foreach ($applied as $row) {
        $batch = max($batch, (int) $row['batch']);
    }

    return $batch;
}

// 执行单个迁移文件的 down 段
function _ch_migration_file_down($filename)
{
    $filepath = CH_MIGRATION_DIR.'/'.$filename;

    if (! is_file($filepath)) {

        echo "migrate $filepath failure down!\n";

        return;
    }

    list($ups, $downs) = _ch_migration_file_explode($filepath);

    foreach ($downs as $down) {
        ch_write($down, [], CH_MIGRATION_CONFIG_KEY);
    }

    echo "migrate $filepath success down!\n";
}

function _ch_migration_run(array $files)
{
    $applied = _ch_migration_applied();

    $new_migrations = array_diff($files, array_column($applied, 'migration'));

    $batch = _ch_migration_last_batch($applied) + 1;

    foreach ($new_migrations as $filename) {

        $filepath = CH_MIGRATION_DIR.'/'.$filename;

        list($ups, $downs) = _ch_migration_file_explode($filepath);

        foreach ($ups as $up) {
            ch_write($up, [], CH_MIGRATION_CONFIG_KEY);
        }

        ch_insert_rows(CH_MIGRATION_TABLE, [[
            'migration' => $filename,
            'batch' => $batch,
            'create_time' => datetime(),
        ]], CH_MIGRATION_CONFIG_KEY);

        echo "migrate $filepath success up!\n";
    }
}

// 回滚指定批次，记录同步删除
function _ch_migration_batch_rollback($batch)
{
    $file_names = [];

    foreach (_ch_migration_applied() as $row) {

        if ((int) $row['batch'] === (int) $batch) {

            $file_names[] = $row['migration'];
        }
    }

    // 反序回滚，后应用上的先撤销
    rsort($file_names);

    foreach ($file_names as $filename) {
        _ch_migration_file_down($filename);
    }

    // mutation 默认异步，不指定 mutations_sync 时紧接着的读取仍会看到已删除的行
    ch_write(
        'alter table `'.CH_MIGRATION_TABLE.'` delete where `batch` = '.(int) $batch.' settings mutations_sync = 2',
        [],
        CH_MIGRATION_CONFIG_KEY);
}

function _ch_migration_reset()
{
    $file_names = array_column(_ch_migration_applied(), 'migration');

    rsort($file_names);

    foreach ($file_names as $filename) {
        _ch_migration_file_down($filename);
    }

    ch_write('truncate table `'.CH_MIGRATION_TABLE.'`', [], CH_MIGRATION_CONFIG_KEY);
}

// 模板正文放注释，未编辑时执行不会报错
function _ch_migration_file_template($filepath)
{
    $string = "# up\n";
    $string .= "-- 例：create table if not exists `demo` (`id` UInt64, `create_time` DateTime64(3)) engine = MergeTree() order by (`id`)\n";
    $string .= "-- 结构化变更请自带 if not exists / if exists，ClickHouse 无事务，失败后重跑会重放已执行的语句\n";
    $string .= "\n# down\n";
    $string .= "-- 例：drop table `demo`\n";
    $string .= "-- 改 ORDER BY / 分区键 / 引擎无法 ALTER，只能重建表，down 会丢数据\n";

    file_put_contents($filepath, $string);
}

command('clickhouse:install', '初始化 clickhouse migrate 所需的表结构', function ()
{
    ch_write(
        'create table if not exists `'.CH_MIGRATION_TABLE.'` (
            `migration` String,
            `batch` UInt64,
            `create_time` DateTime64(3)
        ) engine = MergeTree() order by (`batch`, `migration`)',
        [],
        CH_MIGRATION_CONFIG_KEY);

    echo "clickhouse migrate table installed\n";
});

command('clickhouse:uninstall', '删除 clickhouse migrate 所需的表结构', function ()
{
    ch_write('drop table if exists `'.CH_MIGRATION_TABLE.'`', [], CH_MIGRATION_CONFIG_KEY);
});

command('clickhouse:migrate', '执行 clickhouse migrate', function ()
{
    _ch_migration_run(_ch_migration_files());
});

command('clickhouse:dry-run', '展示 clickhouse 将要跑的 sql', function ()
{
    $applied_names = array_column(_ch_migration_applied(), 'migration');

    $new_migrations = array_diff(_ch_migration_files(), $applied_names);

    if (empty($new_migrations)) {

        echo "没有待执行的迁移\n";

        return;
    }

    foreach ($new_migrations as $filename) {

        list($ups, $downs) = _ch_migration_file_explode(CH_MIGRATION_DIR.'/'.$filename);

        echo '------------'.$filename."-----------\n";

        foreach ($ups as $up) {
            echo $up.";\n";
        }
    }
});

command('clickhouse:rollback', '回滚最后一次 clickhouse migrate', function ()
{
    $batch = _ch_migration_last_batch(_ch_migration_applied());

    if ($batch === 0) {

        echo "没有可回滚的迁移\n";

        return;
    }

    _ch_migration_batch_rollback($batch);
});

command('clickhouse:reset', '回滚所有 clickhouse migrate', function ()
{
    _ch_migration_reset();
});

command('clickhouse:make', '新建 clickhouse migration', function ()
{
    $name = command_paramater('name');

    $file = _ch_migration_file_path($name);

    if (is_file($file)) {

        echo "\033[31mfile exists!\n\033[0m";
        exit;
    }

    _ch_migration_file_template($file);

    echo "generate $file success!\n";
});

command('clickhouse:status', 'clickhouse 迁移状态', function ()
{
    $applied = _ch_migration_applied();

    $pending = array_diff(_ch_migration_files(), array_column($applied, 'migration'));

    echo '已应用 '.count($applied)." 个:\n";

    foreach ($applied as $row) {

        echo '  [batch '.$row['batch'].'] '.$row['migration']."\n";
    }

    echo '待应用 '.count($pending)." 个:\n";

    foreach ($pending as $filename) {

        echo '  '.$filename."\n";
    }
});

<?php

// PDO 连接池，基于 DSN+用户名+密码 复用，支持 TCP 端口和 Unix Socket
function _mysql_connection(array $config)
{
    static $container = [];

    if (empty($config)) {

        return $container = [];
    } else {

        $host = $config['host'];
        $port_or_sock = $config['port'];
        $database = $config['database'];
        $username = $config['username'];
        $password = $config['password'];
        $charset = $config['charset'];
        $collation = $config['collation'];
        $options = $config['options'];

        if (is_numeric($port_or_sock)) {
            $dsn = "mysql:host={$host};port={$port_or_sock};dbname={$database}";
        } else {
            $dsn = "mysql:unix_socket={$port_or_sock};dbname={$database}";
        }

        $identifier = $dsn.'|'.$username.'|'.$password;

        if (!isset($container[$identifier])) {

            $connection = new PDO($dsn, $username, $password, $options);

            $connection->prepare("set names '{$charset}' collate '{$collation}'")->execute();

            $container[$identifier] = $connection;
        }

        return $container[$identifier];
    }
}

// type 为 read/write/schema，事务期间自动强制走写库
function _mysql_database_closure($config_key, $type, closure $closure)
{
    $config = config_midware('mysql', $config_key);

    $type = db_force_type_write()? 'write': $type;

    $connection = _mysql_connection([
        'host' => $host = array_rand($config[$type]),
        'port' => $config[$type][$host],
        'database' => $config['database'],
        'username' => $config['username'],
        'password' => $config['password'],
        'charset' => $config['charset'],
        'collation' => $config['collation'],
        'options' => $config['options'],
    ]);

    return call_user_func($closure, $connection);
}

// binds 中的数组值自动展开为 IN 子句占位符
function _mysql_sql_binds($sql_template, array $binds)
{
    $res_binds = [];

    foreach ($binds as $key => $value) {
        if (is_array($value)) {
            $subbind_keys = [];
            foreach ($value as $i => $sub_value) {
                $subbind_keys[] = $subbind_key = $key.$i.'p';
                $res_binds[$subbind_key] = $sub_value;
            }

            $sql_template = str_replace($key, '('.implode(',', $subbind_keys).')', $sql_template);
        } else {
            $res_binds[$key] = $value;
        }
    }

    // 全链路 trace：SQL 前置注释（MySQL 的 general_log / slow log 靠它关联请求），无上下文时是空串
    return [trace_sql_comment().$sql_template, $res_binds];
}

function db_force_type_write(?bool $bool = null)
{
    static $container = false;

    if (! is_null($bool)) {
        $container = $bool;
    }

    return $container;
}

function db_query($sql_template, array $binds = [], $config_key = 'default')
{
    list($sql_template, $binds) = _mysql_sql_binds($sql_template, $binds);

    return _mysql_database_closure($config_key, 'read', function ($connection) use ($sql_template, $binds) {

        $st = $connection->prepare($sql_template);

        $st->execute($binds);

        return $st->fetchAll(PDO::FETCH_ASSOC);
    });
}

// 自动追加 limit 1，未找到返回 false
function db_query_first($sql_template, array $binds = [], $config_key = 'default')
{
    $sql_template = str_finish($sql_template, ' limit 1');

    list($sql_template, $binds) = _mysql_sql_binds($sql_template, $binds);

    return _mysql_database_closure($config_key, 'read', function ($connection) use ($sql_template, $binds) {

        $st = $connection->prepare($sql_template);

        $st->execute($binds);

        return $st->fetch(PDO::FETCH_ASSOC);
    });
}

function db_query_column($column, $sql_template, array $binds = [], $config_key = 'default')
{
    $rows = db_query($sql_template, $binds, $config_key);

    $res = [];

    foreach ($rows as $row) {
        $res[] = $row[$column];
    }

    return $res;
}

function db_query_value($value, $sql_template, array $binds = [], $config_key = 'default')
{
    $row = db_query_first($sql_template, $binds, $config_key);

    return $row[$value] ?? null;
}

function db_update($sql_template, array $binds = [], $config_key = 'default')
{
    list($sql_template, $binds) = _mysql_sql_binds($sql_template, $binds);

    return _mysql_database_closure($config_key, 'write', function ($connection) use ($sql_template, $binds) {

        $st = $connection->prepare($sql_template);

        $st->execute($binds);

        return $st->rowCount();
    });
}

function db_delete($sql_template, array $binds = [], $config_key = 'default')
{
    list($sql_template, $binds) = _mysql_sql_binds($sql_template, $binds);

    return _mysql_database_closure($config_key, 'write', function ($connection) use ($sql_template, $binds) {

        $st = $connection->prepare($sql_template);

        $st->execute($binds);

        return $st->rowCount();
    });
}

function db_insert($sql_template, array $binds = [], $config_key = 'default')
{
    list($sql_template, $binds) = _mysql_sql_binds($sql_template, $binds);

    return _mysql_database_closure($config_key, 'write', function ($connection) use ($sql_template, $binds) {

        $st = $connection->prepare($sql_template);

        $st->execute($binds);

        return $connection->lastInsertId();
    });
}

function db_write($sql_template, array $binds = [], $config_key = 'default')
{
    list($sql_template, $binds) = _mysql_sql_binds($sql_template, $binds);

    return _mysql_database_closure($config_key, 'write', function ($connection) use ($sql_template, $binds) {

        $st = $connection->prepare($sql_template);

        $st->execute($binds);

        return $st->rowCount();
    });
}

// 使用 schema 连接，不走读写分离
function db_structure($sql, $config_key = 'default')
{
    return _mysql_database_closure($config_key, 'schema', function ($connection) use ($sql) {

        $st = $connection->prepare($sql);

        $st->execute();

        return $st->rowCount();
    });
}

// 事务内强制走写库，异常回滚，finally 恢复
function db_transaction(closure $action, $config_key = 'default')
{
    db_force_type_write(true);

    return _mysql_database_closure($config_key, 'write', function ($connection) use ($action) {

        $began = $connection->beginTransaction();

        if (!$began) {
            throw new Exception('can not start transaction');
        }

        try {
            $res = $action();

            $connection->commit();

            return $res;
        } catch (Exception $ex) {
            $connection->rollBack();

            throw $ex;
        } finally {
            db_force_type_write(false);
        }
    });
}

function db_close()
{
    return _mysql_connection([]);
}

// value 类型决定 SQL 逻辑：数组→IN，null→IS NULL，字符串→=；列名后空格加 not 可反转
function db_simple_where_sql(array $wheres)
{
    if (empty($wheres)) {
        return ['1 = 1', []];
    }

    $where_sqls = $binds = [];

    $n = 0;

    foreach ($wheres as $column => $value) {
        if (is_array($value)) {
            $column_info = explode(' ', $column);
            $column = $column_info[0];
            $bind_key = ':w'.$n.$column;
            $symbol = (isset($column_info[1]) && $column_info[1] === 'not')? 'not in': 'in';

            $where_sqls[] = "`$column` $symbol $bind_key";
            $binds[$bind_key] = $value;
        } elseif (is_null($value)) {
            $column_info = explode(' ', $column);
            $column = $column_info[0];
            $symbol = (isset($column_info[1]) && $column_info[1] === 'not')? 'is not': 'is';

            $where_sqls[] = "`$column` $symbol null";
        } else {
            $column_info = explode(' ', $column);
            $column = $column_info[0];
            $bind_key = ':w'.$n.$column;
            $symbol = $column_info[1] ?? '=';

            $where_sqls[] = "`$column` $symbol $bind_key";
            $binds[$bind_key] = $value;
        }

        $n ++;
    }

    return [implode(' and ', $where_sqls), $binds];
}

function db_simple_insert($table, array $data, $config_key = 'default')
{
    $columns = $values = $binds = [];

    foreach ($data as $column => $value) {
        $columns[] = $column;
        $values[] = ":$column";
        $binds[":$column"] = $value;
    }

    $sql_template = 'insert into `'.$table.'` (`'.implode('`, `', $columns).'`) values ('.implode(', ', $values).')';

    return db_insert($sql_template, $binds, $config_key);
}

// 自动合并列名生成单条 INSERT 多值语句
function db_simple_multi_insert($table, array $datas, $config_key = 'default')
{
    $data_sql_templates = $columns = $binds = [];

    foreach ($datas as $k => $data) {

        $values = [];

        foreach ($data as $column => $value) {
            $columns[$column] = true;
            $values[] = ":i_$column$k";
            $binds[":i_$column$k"] = $value;
        }

        $data_sql_templates[] = '('.implode(', ', $values).')';
    }

    $sql_template = 'insert into `'.$table.'` (`'.implode('`, `', array_keys($columns)).'`) values '.implode(', ', $data_sql_templates);

    return db_insert($sql_template, $binds, $config_key);
}

function db_simple_update($table, array $wheres, array $data, $config_key = 'default')
{
    list($where, $binds) = db_simple_where_sql($wheres);

    $update = [];

    foreach ($data as $column => $value) {
        $update[] = "`$column` = :u_$column";
        $binds[":u_$column"] = $value;
    }

    $sql_template = 'update `'.$table.'` set '.implode(', ', $update).' where '.$where;

    return db_update($sql_template, $binds, $config_key);
}

// 使用 CASE WHEN 在一条 SQL 中更新多行不同值
function db_simple_multi_update($table, array $datas, $where_column = 'id', $config_key = 'default')
{
    $set_sqls = $binds = $where_values = [];

    $keys = array_keys(current($datas));

    foreach ($keys as $column) {

        if ($column == $where_column) {
            continue;
        }

        $set_sql = sprintf("`$column` = case `$where_column`\n");

        foreach ($datas as $i => $data) {

            $when_bind_key = ':'.$where_column.'_'.$i.'_'.$column;
            $then_bind_key = ':'.$column.'_'.$i;

            $set_sql .= "    when $when_bind_key then $then_bind_key\n";

            $binds[$when_bind_key] = $data[$where_column];
            $binds[$then_bind_key] = $data[$column];
            $where_values[] = $data[$where_column];
        }
        $set_sqls[] = $set_sql."end";
    }

    $set_sql_str = implode(",\n", $set_sqls);

    $binds[':where_values'] = $where_values;

    return db_update(
        "update `$table` set\n$set_sql_str where `$where_column` in :where_values",
        $binds,
        $config_key);
}

function db_simple_delete($table, array $wheres, $config_key = 'default')
{
    list($where, $binds) = db_simple_where_sql($wheres);

    $sql_template = 'delete from `'.$table.'` where '.$where;

    return db_delete($sql_template, $binds, $config_key);
}

// option_sql 可追加 order by / limit 等
function db_simple_query($table, array $wheres = [], $option_sql = 'order by id', $config_key = 'default')
{
    list($where, $binds) = db_simple_where_sql($wheres);

    return db_query('select * from `'.$table.'` where '.$where.' '.$option_sql, $binds, $config_key);
}

function db_simple_query_first($table, array $wheres, $option_sql = '', $config_key = 'default')
{
    list($where, $binds) = db_simple_where_sql($wheres);

    return db_query_first('select * from `'.$table.'` where '.$where.' '.$option_sql, $binds, $config_key);
}

function db_simple_query_column($table, $column, array $wheres = [], $option_sql = '', $config_key = 'default')
{
    $datas = db_simple_query($table, $wheres, $option_sql, $config_key);

    $res = [];

    foreach ($datas as $data) {
        $res[] = $data[$column];
    }

    return $res;
}

// 以 indexed 列的值作为返回数组的 key
function db_simple_query_indexed($table, $indexed, array $wheres = [], $option_sql = 'order by id', $config_key = 'default')
{
    $datas = db_simple_query($table, $wheres, $option_sql, $config_key);

    $res = [];

    foreach ($datas as $data) {
        $res[$data[$indexed]] = $data;
    }

    return $res;
}

function db_simple_query_value($table, $value, array $wheres, $option_sql = '', $config_key = 'default')
{
    list($where, $binds) = db_simple_where_sql($wheres);

    return db_query_value($value, "select `$value` from `$table` where $where $option_sql", $binds, $config_key);
}

<?php

command('entity:restep-last-id', '刷新 ID 生成器的最新 id', function ()
{
    // 与 ORM 用同一套连接：表在 entity midware 的库、游标在 idgenter midware 的 Redis，
    // 将来它们指向别的实例时，这里扫的还得是 ORM 真正读写的那份数据
    $res = db_query('show tables', [], 'entity');

    $entity_title = 'entity';
    $last_id_title = 'last_id';
    $col_width = strlen($entity_title) + 3;
    $max_id_infos = [];

    foreach ($res as $v) {

        $table = reset($v);
        if ($table !== MIGRATION_TABLE) {

            $cache_key = $table.IDGENTER_CACHE_KEY_SUFFIX;

            $max_id = db_query_value('id', 'select id from `'.$table.'` order by id desc', [], 'entity');
            if ($max_id > 0) {

                $max_id_info = '';

                $now_last_id = cache_get($cache_key, IDGENTER_CACHE_MIDWARE_KEY);
                if ($now_last_id) {
                    cache_delete($cache_key, IDGENTER_CACHE_MIDWARE_KEY);
                    $max_id_info = "$now_last_id -> ";
                }

                $res = cache_increment($cache_key, $max_id, 0, IDGENTER_CACHE_MIDWARE_KEY);
                $max_id_infos[$table] = $max_id_info.$max_id;
                $col_width = max($col_width, (strlen($table) + 3));
            } else {

                cache_delete($cache_key, IDGENTER_CACHE_MIDWARE_KEY);
            }
        }
    }

    echo str_pad($entity_title, $col_width, ' ').$last_id_title."\n";
    echo str_pad('', $col_width + strlen($last_id_title), '-')."\n";
    foreach ($max_id_infos as $table => $max_id) {
        echo str_pad($table, $col_width, ' ').$max_id."\n";
    }
});

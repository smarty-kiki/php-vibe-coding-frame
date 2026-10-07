<?php

// Kafka 队列的管理命令（与 queue.php 的 beanstalk 版并列，二选一：在 public/cli.php 里 include 谁就用谁）

command('queue:worker', '启动队列 worker', function ()
{
    $topic_key = command_paramater('topic_key', 'default');
    $group = command_paramater('group', '');
    $memory_limit = command_paramater('memory_limit', 1048576 * 128);

    // memory_limit 直接传字节数（裸数字即字节），不带后缀；'b' 不是 PHP ini 可识别的量级后缀
    ini_set('memory_limit', (string) $memory_limit);

    // 每轮只清缓存与数据库连接：kafka 消费者要跨轮保持（消费组会籍靠它维持），不能像 beanstalk 那样每轮重连
    queue_finish_action(function () {
        local_cache_delete_all();
        cache_close();
        db_close();
    });

    echo 'queue worker 启动：topic='.queue_topic($topic_key).' group='.($group ?: _kafka_default_group($topic_key))."\n";

    queue_watch($topic_key, $memory_limit, $group ?: null);
});

command('queue:status', '队列状态（分区 offset 与堆积量）', function ()
{
    $topic_key = command_paramater('topic_key', 'default');
    $group = command_paramater('group', '');

    $rows = queue_status($topic_key, $group ?: null);

    printf("%-10s %-32s %-12s %-12s %-12s %-8s\n", 'partition', 'group', 'low', 'committed', 'high', 'lag');

    $total_lag = 0;

    foreach ($rows as $row) {

        printf(
            "%-10d %-32s %-12d %-12s %-12d %-8d\n",
            $row['partition'],
            $row['group'],
            $row['low'],
            is_null($row['committed']) ? '-' : $row['committed'],
            $row['high'],
            $row['lag']
        );

        $total_lag += $row['lag'];
    }

    echo 'total lag: '.$total_lag."\n";
});

command('queue:reset-offset', '重置消费位点（回溯重放）', function ()
{
    $topic_key = command_paramater('topic_key', 'default');
    $offset = command_paramater('offset', 'earliest');
    $partition = command_paramater('partition', '');
    $group = command_paramater('group', '');

    if (! in_array($offset, ['earliest', 'latest'], true) && ! is_numeric($offset)) {
        echo "offset 只支持 earliest / latest / 具体数值\n";
        exit;
    }

    $is_continue = command_read_bool(
        '警告！这个操作会把 topic:'.queue_topic($topic_key).' 的消费位点重置到 '.$offset
        .'（同组 worker 必须先停掉，重置后这些消息会被重新消费，业务需自行保证幂等），确定开始？'
    );

    if (! $is_continue) {
        exit;
    }

    $targets = queue_reset_offset($topic_key, $offset, $partition ?: null, $group ?: null);

    foreach ($targets as $target) {
        echo $target->getTopic().' 分区 '.$target->getPartition().' 的位点重置为 '.$target->getOffset()."\n";
    }
});

command('queue:dead-letter', '查看与重投死信 topic 里的消息', function ()
{
    $topic_key = command_paramater('topic_key', 'default');
    $group = command_paramater('group', '');

    $dead_letter_topic = queue_dead_letter_topic($topic_key);

    // 每次从头看死信：独立消费组 + 手工分配到 beginning，不回提交 offset——
    // kafka 删不了单条消息，死信 topic 的清理靠留存策略（retention.ms），重投后请自行留意会重复消费
    $conf = _kafka_conf('consumer', $group ?: _kafka_default_group($topic_key).'-dead-letter-tool');
    $conf->set('enable.partition.eof', 'true');

    $consumer = new RdKafka\KafkaConsumer($conf);

    try {
        $partition_ids = _kafka_topic_partitions($consumer, $dead_letter_topic);
    } catch (throwable $exception) {
        echo "读不到死信 topic：".$exception->getMessage()."\n";
        exit;
    }

    $partitions = [];

    foreach ($partition_ids as $partition_id) {
        $partitions[] = new RdKafka\TopicPartition($dead_letter_topic, $partition_id, RD_KAFKA_OFFSET_BEGINNING);
    }

    $consumer->assign($partitions);

    echo "死信 topic：".$dead_letter_topic."（".count($partitions)." 个分区，从头开始看）\n";

    $eof_partitions = [];

    for (;;) {

        if (count($eof_partitions) === count($partitions)) {
            echo "所有分区都看完了。\n";
            break;
        }

        $message = $consumer->consume(_kafka_timeout_ms());

        if (is_null($message) || $message->err === RD_KAFKA_RESP_ERR__TIMED_OUT) {
            continue;
        }

        if ($message->err === RD_KAFKA_RESP_ERR__PARTITION_EOF) {
            $eof_partitions[$message->partition] = true;
            continue;
        }

        if ($message->err !== RD_KAFKA_RESP_ERR_NO_ERROR) {
            echo "\033[31m消费出错：".$message->errstr()."\033[0m\n";
            break;
        }

        $body = json_decode($message->payload, true);
        $body = is_array($body) ? $body : [];

        $fail_topic = array_get($body, 'fail.topic');

        if (empty($fail_topic)) {
            echo "\033[31m消息里没有记录原 topic，无法重投：\033[0m".json($body)."\n";
        }

        $info = [
            'topic' => $message->topic_name,
            'partition' => $message->partition,
            'offset' => $message->offset,
            'job_name' => array_get($body, 'job_name'),
            'data' => array_get($body, 'data'),
            'fail' => array_get($body, 'fail'),
        ];

        echo "\033[32m".json($info)."\033[0m\n";

        // 默认动作取 skip：回车或 Ctrl-D 不该把消息重投出去（重投会带来重复消费）
        $action = command_read('Action', 0, ['skip', 'replay', 'quit']);

        switch ($action) {
            case 'replay':

                if (empty($fail_topic)) {
                    echo "没有原 topic 记录，跳过\n";
                    break;
                }

                // 重投回原 topic 的原始信封（重投后 worker 会再消费一次，业务需幂等）。
                // trace 用当前（操作方 CLI）的上下文——与 queue_push 的口径一致：投递方是谁就带谁的 trace；
                // 原 trace_id 记进模块日志，保留这条消息的来路
                _kafka_produce($fail_topic, [
                    'job_name' => array_get($body, 'job_name'),
                    'data' => array_get($body, 'data'),
                    'trace' => trace_all(),
                ], $message->key ?: '');

                log_module('queue', '死信重投：'.json([
                    'job_name' => array_get($body, 'job_name'),
                    'from_topic' => $message->topic_name,
                    'from_offset' => $message->offset,
                    'to_topic' => $fail_topic,
                    'original_trace_id' => array_get($body, 'trace.trace_id'),
                ]));

                echo "已重投到 ".$fail_topic."\n";

                break;

            case 'quit':
                break 2;

            case 'skip':
            default:
                break;
        }

        echo "\n";
    }

    $consumer->close();
});

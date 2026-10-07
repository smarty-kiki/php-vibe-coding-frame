<?php

// Kafka 队列模块（基于 php-rdkafka 扩展，运行环境需装 rdkafka）。
// 与 queue_beanstalk.php 是两套并列实现、函数同名，由 bootstrap.php include 哪一个决定用哪套——
// 一个项目只用一种队列，切换时命令文件与任务定义要按对应实现的参数口径改写（没有运行期开关）。
//
// 与 beanstalk 版的差异（按 Kafka 语义裁剪）：
//   取消：优先级、延时投递、TTR（queue_job_touch）、暂停派发（queue_pause）、bury 体系
//   新增：消费组（同组多 worker 自动分摊分区）、消息 key（分区保序）、offset 查询与重置（回溯重放）、
//         失败重试超限落死信 topic（替代 bury）

// 队列子系统（生产、消费与管理命令）固定使用 queue midware，业务侧不暴露 config_key 参数
define('QUEUE_KAFKA_MIDWARE_KEY', 'queue');

// rdkafka 未导出 RD_KAFKA_OFFSET_INVALID 常量，这里按 librdkafka 的定义补齐
define('QUEUE_KAFKA_OFFSET_INVALID', -1001);

// ---------- 配置 ----------

function _kafka_config()
{
    $config = config_midware('kafka', QUEUE_KAFKA_MIDWARE_KEY);

    otherwise(
        not_empty(array_get($config, 'brokers')),
        'kafka 配置缺少 brokers，检查 config/kafka.php 的 resources',
        'exception',
        'KAFKA_CONFIG');

    return $config;
}

// queue 配置里的 kafka 段（topic 映射 / 死信后缀 / consumer），缺省即默认值
function _kafka_queue_config($key, $default = null)
{
    return array_get(config('queue'), $key, $default);
}

// 配置统一写秒，librdkafka 要毫秒
function _kafka_second_to_ms($second)
{
    return (int) round($second * 1000);
}

function _kafka_timeout_ms()
{
    return _kafka_second_to_ms(array_get(_kafka_config(), 'timeout', 5));
}

// 消费组名：默认「配置里的 group 前缀 + topic_key」派生——同一个 topic 的多个 worker 同组自动分摊分区；
// 想让另一套消费者各消费一份全量（广播），用 queue:worker --group=xxx 指定别的组
function _kafka_default_group($topic_key)
{
    return _kafka_queue_config('consumer.group_prefix', 'queue').'-'.$topic_key;
}

// topic_key → 真实 topic 名：业务侧统一写 topic_key，
// 映射见 config/queue.php 的 topics（各环境覆盖即可换真实 topic，业务代码不改）
function queue_topic($topic_key)
{
    $topics = _kafka_queue_config('topics', []);

    otherwise(
        isset($topics[$topic_key]),
        'queue 配置缺少 topic 映射，检查 config/queue.php 的 topics：'.$topic_key,
        'exception',
        'QUEUE_TOPIC_NOT_FOUND');

    return $topics[$topic_key];
}

// 死信 topic：失败重试超限的消息落到 <真实 topic><后缀>，与主 topic 同集群
function queue_dead_letter_topic($topic_key)
{
    return queue_topic($topic_key)._kafka_queue_config('dead_letter_suffix', '_dead');
}

// ---------- 连接（进程内复用） ----------

// 生产者与消费者进程内复用：生产者每次 new 都要重新拉元数据（短请求里代价明显），
// 消费者更要靠实例本身维持消费组会籍（每轮重建会不停触发再均衡）
function _kafka_container($container = null)
{
    static $store = ['producer' => null, 'consumers' => []];

    if (! is_null($container)) {
        $store = $container;
    }

    return $store;
}

function _kafka_producer()
{
    $store = _kafka_container();

    if (is_null($store['producer'])) {

        $store['producer'] = new RdKafka\Producer(_kafka_conf('producer'));

        _kafka_container($store);
    }

    return $store['producer'];
}

function _kafka_consumer($group)
{
    $store = _kafka_container();

    if (! isset($store['consumers'][$group])) {

        $store['consumers'][$group] = new RdKafka\KafkaConsumer(_kafka_conf('consumer', $group));

        _kafka_container($store);
    }

    return $store['consumers'][$group];
}

// 关闭本进程打开的生产者与消费者（beanstalk 版对应 beanstalk_close）；
// queue_watch 退出时已经 close 过消费者，这里重复调用不报错（已关闭的实例跳过）
function queue_close()
{
    $store = _kafka_container();

    foreach ($store['consumers'] as $consumer) {

        try {
            $consumer->close();
        } catch (throwable $exception) {
            // 已经关过的消费者再关会抛异常，这里只记不抛——queue_close 允许重复调用
            log_module('queue', 'kafka 消费者关闭时出错（可忽略）：'.$exception->getMessage());
        }
    }

    if (! is_null($store['producer'])) {
        $store['producer']->flush(_kafka_flush_timeout_ms());
    }

    _kafka_container(['producer' => null, 'consumers' => []]);
}

function _kafka_conf($role, $group = null)
{
    $config = _kafka_config();

    $conf = new RdKafka\Conf();

    $conf->set('metadata.broker.list', $config['brokers']);
    $conf->set('socket.timeout.ms', (string) _kafka_timeout_ms());

    if ($role === 'producer') {

        // 投递超时：broker 不可达时不能让消息无限堆在本地队列里，到点以回执失败暴露出来
        $conf->set('message.timeout.ms', (string) _kafka_second_to_ms(array_get($config, 'message_timeout', 10)));

        // 投递回执：produce 是异步的，成败只有回调知道，queue_raw_push 靠这里记下的回执判断
        $conf->setDrMsgCb(function (RdKafka\Producer $producer, RdKafka\Message $message) {
            _kafka_delivery_report([
                'err' => $message->err,
                'error' => $message->errstr(),
                'topic' => $message->topic_name,
                'partition' => $message->partition,
                'offset' => $message->offset,
            ]);
        });

        return $conf;
    }

    $consumer_config = _kafka_queue_config('consumer', []);

    $conf->set('group.id', $group);
    // offset 由框架在任务处理成功后显式提交：没提交的消息在 worker 崩溃后会重投（至少一次）
    $conf->set('enable.auto.commit', 'false');
    $conf->set('auto.offset.reset', array_get($consumer_config, 'auto_offset_reset', 'earliest'));
    // 单条消息的处理上限（同时是再均衡的等待上限，见 config/queue.php 里的说明）
    $conf->set('max.poll.interval.ms', (string) array_get($consumer_config, 'max_poll_interval_ms', 300000));
    $conf->set('session.timeout.ms', (string) array_get($consumer_config, 'session_timeout_ms', 45000));
    // topic 名写错时要立刻报错，而不是（broker 允许时）静默建一个空 topic 空转
    $conf->set('allow.auto.create.topics', 'false');

    $conf->setRebalanceCb(function (RdKafka\KafkaConsumer $consumer, $err, $partitions = null) {
        switch ($err) {
            case RD_KAFKA_RESP_ERR__ASSIGN_PARTITIONS:
                log_module('queue', 'kafka 分区分配：'.json(['partitions' => _kafka_partitions_text($partitions)]));
                $consumer->assign($partitions);
                break;
            case RD_KAFKA_RESP_ERR__REVOKE_PARTITIONS:
                log_module('queue', 'kafka 分区回收：'.json(['partitions' => _kafka_partitions_text($partitions)]));
                $consumer->assign(null);
                break;
            default:
                _kafka_error('kafka 再均衡失败：'.$err);
        }
    });

    return $conf;
}

function _kafka_error($error)
{
    throw new Exception($error);
}

function _kafka_flush_timeout_ms()
{
    return _kafka_second_to_ms(array_get(_kafka_config(), 'flush_timeout', 15));
}

// 分区列表转成 "topic-分区号" 文本，仅用于日志
function _kafka_partitions_text($partitions)
{
    if (empty($partitions)) {
        return [];
    }

    $res = [];

    foreach ($partitions as $partition) {
        $res[] = $partition->getTopic().'-'.$partition->getPartition();
    }

    return $res;
}

// ---------- 生产者 ----------

// 投递回执容器：每次投递前清空、flush 后读取，判断这一条是否送达
function _kafka_delivery_report($report = null)
{
    static $container = [];

    if (! is_null($report)) {
        return $container = $report;
    }

    return $container;
}

function _kafka_json_encode(array $payload)
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

    otherwise(
        $json !== false,
        '队列消息 JSON 编码失败：'.json_last_error_msg(),
        'exception',
        'QUEUE_MESSAGE_ENCODE_FAILED');

    return $json;
}

// 整包投递到真实 topic。produce 是异步的，这里 poll + flush 等回执，
// 投递失败一律抛异常——push 的失败必须让调用方看得见，不能静默丢
function _kafka_produce($topic, array $payload, $key = '')
{
    $producer = _kafka_producer();

    // 上一次投递的回执不能拿来判断这一次
    _kafka_delivery_report([]);

    try {

        $topic_handle = $producer->newTopic($topic);

        $topic_handle->produce(
            RD_KAFKA_PARTITION_UA,
            0,
            _kafka_json_encode($payload),
            '' === $key ? null : (string) $key
        );

        $producer->poll(0);

        $outstanding = $producer->flush(_kafka_flush_timeout_ms());

    } catch (RdKafka\Exception $exception) {
        otherwise(false, '投递 '.$topic.' 失败：'.$exception->getMessage(), 'exception', 'QUEUE_PUSH_FAILED');
    }

    $report = _kafka_delivery_report();

    // 有回执先按回执判：回执里带具体原因（topic 不存在、消息超限等）
    if (! empty($report)) {

        otherwise(
            $report['err'] === RD_KAFKA_RESP_ERR_NO_ERROR,
            '投递 '.$topic.' 被拒：'.$report['error'],
            'exception',
            'QUEUE_PUSH_FAILED');

    } else {

        // 等不到回执即视为没送达。flush_timeout 配得比 message_timeout 长，正常不会走到这里——
        // 真走到了说明 flush 提前超时，此刻消息可能仍在重试投递，报失败给调用方是为了不静默丢
        otherwise(
            false,
            '投递 '.$topic.' 超时未送达（flush 后仍有 '.$outstanding.' 条在队列里，检查 broker 与 topic 是否存在）',
            'exception',
            'QUEUE_PUSH_FAILED');
    }

    return $report;
}

// 投递完整信封（死信与重投也走它）
function queue_raw_push($topic_key, array $payload, $key = '')
{
    return _kafka_produce(queue_topic($topic_key), $payload, $key);
}

// 投递任务：$key 是 Kafka 消息 key（决定落到哪个分区，同一个 key 的消息保序），空则由 broker 轮询分区
function queue_push($job_name, array $data = [], $key = '')
{
    $job = queue_job_pickup($job_name);

    otherwise(
        ! is_null($job),
        'queue 未注册的任务名（先在 command/queue/queue_job/ 里 queue_job 注册再投递）：'.$job_name,
        'exception',
        'QUEUE_JOB_NOT_FOUND');

    return queue_raw_push($job['topic_key'], [
        'job_name' => $job_name,
        'data' => $data,
        'trace' => trace_all(),
    ], $key);
}

// ---------- 任务注册（与 beanstalk 版同名同形，参数按 Kafka 语义裁剪） ----------

function queue_job_pickup($job_name)
{
    $jobs = queue_jobs();

    return $jobs[$job_name] ?? null;
}

function queue_jobs(?array $jobs = null)
{
    static $container = [];

    if (! is_null($jobs)) {
        return $container = $jobs;
    }

    return $container;
}

// retry 为延时秒数数组，按失败次数匹配进程内重试前的等待秒数，超出则投递死信 topic
function queue_job($job_name, closure $closure, $retry = [], $topic_key = 'default')
{
    $jobs = queue_jobs();

    $jobs[$job_name] = [
        'closure' => $closure,
        'retry' => $retry,
        'topic_key' => $topic_key,
    ];

    queue_jobs($jobs);
}

// 每次 worker 循环消费前触发，通常用于连接资源回收（如 cache_close、db_close）
function queue_finish_action(?closure $action = null)
{
    static $container = null;

    if (! empty($action)) {
        return $container = $action;
    }

    return $container;
}

function queue_finish_action_trigger()
{
    $finished_action = queue_finish_action();

    if ($finished_action instanceof closure) {
        call_user_func($finished_action);
    }
}

// ---------- 消费者 ----------

// 取 topic 元数据：只查这一个 topic（名字写错时 getTopic 拿不到它）
function _kafka_topic_metadata(RdKafka\KafkaConsumer $consumer, $topic)
{
    $metadata = $consumer->getMetadata(false, $consumer->newTopic($topic), _kafka_timeout_ms());

    foreach ($metadata->getTopics() as $topic_metadata) {

        if ($topic_metadata->getTopic() === $topic) {
            return $topic_metadata;
        }
    }

    return null;
}

function _kafka_topic_partitions(RdKafka\KafkaConsumer $consumer, $topic)
{
    $topic_metadata = _kafka_topic_metadata($consumer, $topic);

    // 不存在的 topic 也会返回一条元数据（err 为 UNKNOWN_TOPIC_OR_PART、分区数为 0），
    // 这里一并拦下：否则 worker 会订阅一个不存在的 topic 后静默空转
    $err = is_null($topic_metadata) ? null : $topic_metadata->getErr();

    otherwise(
        ! is_null($topic_metadata)
            && $err === RD_KAFKA_RESP_ERR_NO_ERROR
            && not_empty($topic_metadata->getPartitions()),
        'kafka 里不存在或不可用的 topic：'.$topic
        .(is_null($err) ? '' : '（'.rd_kafka_err2str($err).'）')
        .'，检查 config/queue.php 的 topics 映射与集群里的 topic',
        'exception',
        'QUEUE_TOPIC_NOT_FOUND');

    $partition_ids = [];

    foreach ($topic_metadata->getPartitions() as $partition) {
        $partition_ids[] = $partition->getId();
    }

    sort($partition_ids);

    return $partition_ids;
}

// 传给任务闭包的第二个参数：beanstalk 版这里是 job_id，kafka 版是消息元信息
function _kafka_message_meta(RdKafka\Message $message)
{
    return [
        'topic' => $message->topic_name,
        'partition' => $message->partition,
        'offset' => $message->offset,
        'key' => $message->key,
        'timestamp' => $message->timestamp,
    ];
}

// 死信投递：原始信封 + 失败现场打包投到死信 topic。
// 死信投递失败不能吞（吞了这条消息随 offset 提交永远消失），终止 worker 由 supervisor 拉起后重新消费
function _kafka_dead_letter($topic_key, RdKafka\Message $message, array $body, $reason)
{
    $dead_letter_topic = queue_dead_letter_topic($topic_key);

    try {

        _kafka_produce($dead_letter_topic, [
            'job_name' => array_get($body, 'job_name'),
            'data' => array_get($body, 'data'),
            'trace' => array_get($body, 'trace'),
            'fail' => [
                'reason' => $reason,
                'topic' => $message->topic_name,
                'partition' => $message->partition,
                'offset' => $message->offset,
                'key' => $message->key,
                'time' => datetime(),
            ],
        ], $message->key ?: '');

    } catch (throwable $exception) {

        log_exception($exception);

        _kafka_error('死信投递失败，worker 退出等 supervisor 拉起后重新消费：'.$dead_letter_topic);
    }

    log_module('queue', '任务进入死信：'.json([
        'job_name' => array_get($body, 'job_name'),
        'topic' => $message->topic_name,
        'partition' => $message->partition,
        'offset' => $message->offset,
        'dead_letter_topic' => $dead_letter_topic,
        'reason' => $reason,
    ]));
}

// 单条消息：解析信封 → 恢复投递方 trace → 执行任务闭包 → 成功提交 offset，失败按 retry 重试，超限落死信
function _kafka_handle_message(RdKafka\KafkaConsumer $consumer, RdKafka\Message $message, $topic_key)
{
    $body = json_decode($message->payload, true);
    $body = is_array($body) ? $body : [];

    // 恢复投递方的 trace 上下文（job 的 parent span = 投递方 span）；解不出来的 payload 则新起一个
    trace_init(array_get($body, 'trace.trace_id'), array_get($body, 'trace.span_id'));

    $job_name = array_get($body, 'job_name');
    $data = array_get($body, 'data', []);
    $job = is_null($job_name) ? null : queue_job_pickup($job_name);

    // 信封解析不了 / 任务名没注册：编程错误，落死信后提交——既不静默丢，也不卡住整个分区
    if (is_null($job)) {

        $reason = is_array(json_decode($message->payload, true))
            ? '未注册的任务名：'.var_export($job_name, true)
            : '消息不是合法的 JSON 信封：'.$message->payload;

        _kafka_dead_letter($topic_key, $message, $body, $reason);

        $consumer->commit($message);

        return;
    }

    $attempt = 0;

    for (;;) {

        $fail_reason = null;

        try {

            $res = call_user_func_array($job['closure'], [$data, _kafka_message_meta($message)]);

        } catch (throwable $exception) {

            log_exception($exception);

            $res = false;
            $fail_reason = $exception->getMessage();
        }

        if ($res) {

            $consumer->commit($message);

            return;
        }

        $fail_reason = $fail_reason ?: '任务返回失败';

        // 重试用尽 → 死信 + 提交，继续消费后面的消息（一条毒消息不该卡住整个分区）
        if (! isset($job['retry'][$attempt])) {

            _kafka_dead_letter($topic_key, $message, $body, $fail_reason);

            $consumer->commit($message);

            return;
        }

        // 按 retry 数组退避重试（与 beanstalk 版语义一致：第 n 次失败等 retry[n] 秒，第 0 次即首次失败）；
        // 重试是在本进程 sleep 后重跑，期间占住分区，不适合长间隔——要长延时就该用死信 + 定时重放
        sleep($job['retry'][$attempt]);

        $attempt++;
    }
}

// 消费主循环：订阅 topic_key 对应的 topic（同组多 worker 自动分摊分区），逐条处理、处理成功即提交 offset。
// 支持 SIGTERM 优雅退出（退出前离组，分区立刻交给同组其他 worker）与内存上限保护
function queue_watch($topic_key = 'default', $memory_limit = 1048576, $group = null)
{
    $topic = queue_topic($topic_key);

    $consumer = _kafka_consumer($group ?: _kafka_default_group($topic_key));

    // 订阅前先校验 topic 存在：订阅一个不存在的 topic 会一直空转，名字写错的现场应该是立刻报错
    _kafka_topic_partitions($consumer, $topic);

    $consumer->subscribe([$topic]);

    declare(ticks=1);
    $received_signal = false;
    pcntl_signal(SIGTERM, function () use (&$received_signal) {
        $received_signal = true;
    });

    $consume_timeout_ms = _kafka_second_to_ms(array_get(_kafka_queue_config('consumer', []), 'consume_timeout', 5));

    for (;;) {

        if (memory_get_usage(true) > $memory_limit) {
            throw new Exception('queue_watch out of memory');
        }

        if ($received_signal) {
            break;
        }

        queue_finish_action_trigger();

        $message = $consumer->consume($consume_timeout_ms);

        // 无消息（超时）与消费到分区末尾都不是错误，继续下一轮（顺便响应信号与内存检查）
        if (is_null($message) || in_array($message->err, [RD_KAFKA_RESP_ERR__TIMED_OUT, RD_KAFKA_RESP_ERR__PARTITION_EOF], true)) {
            continue;
        }

        if ($message->err !== RD_KAFKA_RESP_ERR_NO_ERROR) {

            log_exception(new Exception('kafka 消费出错：'.$message->errstr()));

            continue;
        }

        _kafka_handle_message($consumer, $message, $topic_key);

        // 处理完清掉上下文：下一轮在消费到新消息前不带上一条的 trace
        trace_reset();
    }

    $consumer->close();
}

// ---------- 管理 ----------

// 消费进度：每个分区的起始 / 末尾 / 已提交 offset 与堆积量（lag），替代 beanstalk 的 stats-tube。
// 消费组成员列表 rdkafka 拿不到（没有 DescribeGroups 接口），要看成员用 kafka-consumer-groups.sh
function queue_status($topic_key = 'default', $group = null)
{
    $topic = queue_topic($topic_key);

    $group = $group ?: _kafka_default_group($topic_key);

    $consumer = _kafka_consumer($group);

    $res = [];

    foreach (_kafka_topic_partitions($consumer, $topic) as $partition_id) {

        $low = 0;
        $high = 0;

        $consumer->queryWatermarkOffsets($topic, $partition_id, $low, $high, _kafka_timeout_ms());

        $committed = $consumer->getCommittedOffsets(
            [new RdKafka\TopicPartition($topic, $partition_id)],
            _kafka_timeout_ms()
        )[0]->getOffset();

        $committed = $committed === QUEUE_KAFKA_OFFSET_INVALID ? null : $committed;

        $res[] = [
            'partition' => $partition_id,
            'group' => $group,
            'low' => $low,
            'high' => $high,
            'committed' => $committed,
            // 没提交过时按消费者接手的起点（auto.offset.reset）到末尾估算
            'lag' => $high - (is_null($committed) ? $low : max($committed, $low)),
        ];
    }

    return $res;
}

// 重置消费位点（回溯重放）：earliest / latest 先解析成实际 offset 再提交，OffsetCommit 只接受具体数值。
// 注意：同组有 worker 在跑时，重置方的提交不带成员身份会被 broker 拒绝——先停 worker 再重置
function queue_reset_offset($topic_key = 'default', $offset = 'earliest', $partition = null, $group = null)
{
    $topic = queue_topic($topic_key);

    $group = $group ?: _kafka_default_group($topic_key);

    $consumer = _kafka_consumer($group);

    $targets = [];

    foreach (_kafka_topic_partitions($consumer, $topic) as $partition_id) {

        if (! is_null($partition) && (int) $partition !== $partition_id) {
            continue;
        }

        $low = 0;
        $high = 0;

        $consumer->queryWatermarkOffsets($topic, $partition_id, $low, $high, _kafka_timeout_ms());

        if ('earliest' === $offset) {
            $value = $low;
        } elseif ('latest' === $offset) {
            $value = $high;
        } else {
            $value = (int) $offset;
        }

        $targets[] = new RdKafka\TopicPartition($topic, $partition_id, $value);
    }

    otherwise(
        not_empty($targets),
        '没有匹配到要重置的分区：partition='.var_export($partition, true),
        'exception',
        'QUEUE_PARTITION_NOT_FOUND');

    $consumer->commit($targets);

    return $targets;
}

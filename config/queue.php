<?php

// tube 映射：业务侧（queue_job / queue_watch / queue:* 命令）统一写左边的 tube_key，
// 实际收发的 Beanstalkd tube 是右边的真实名称；各环境覆盖本文件即可让同一个 tube_key
// 落到不同真实 tube（业务代码不用改）。未映射的 tube_key 会直接报错，
// 避免名字打错时任务静默投进没人监听的 tube
//
// 环境覆盖示例（config/development/queue.php）：
// return ['tubes' => ['default' => 'dev_default']];

return [

    'tubes' => [
        'default' => 'default',
    ],
];

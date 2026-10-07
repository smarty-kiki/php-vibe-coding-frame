<?php

return [

    // 贴近生产（生产开、开发关）：测试环境顺带验证模板在编译缓存模式下的行为
    // 改了模板后要让改动生效，清一次 view/blade/*.php（部署脚本 after_push.sh 里已带）
    'compiled_cache' => true,

];

<?php

if_get('/', function ()
{
    return render('index/index', [
        'title' => 'hello world',
        'url_infos' => [
            ['href' => '/', 'name' => '首页'],
        ],
    ]);
});

if_get('/health_check', function ()
{
    return 'ok';
});

<?php

if_get('/api/error_code_maps', function ()
{
    return config('error_code');
});
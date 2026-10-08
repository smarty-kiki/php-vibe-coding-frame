<?php

// -x 为布尔 true，--key=value 为字符串值
function _command_prepare_arguments()
{
    static $file_name = '';
    static $command = '';
    static $arguments = [];

    if (! $file_name) {
        global $argv;
        $file_name = array_shift($argv);
        $command = array_shift($argv);

        foreach ($argv as $num => $argument) {

            switch (true) {

            case preg_match('/^-([a-zA-Z_]+)$/', $argument, $res):
                $arguments[$res[1]] = true;
                break;

            case preg_match('/^--([a-zA-Z_]+)=(.*)$/', $argument, $res):
                $arguments[$res[1]] = $res[2];
                break;
            }
        }
    }

    return [$file_name, $command, $arguments];
}

function command_paramater($key, $default = null)
{
    list($file_name, $command, $arguments) = _command_prepare_arguments();

    if (! isset($arguments[$key])) {
        if (is_null($default)) {
            echo "\033[31m需要加 --$key=xxx 或者 -$key\033[0m\n";
            exit(1);
        } else {
            return $default;
        }
    }

    return $arguments[$key];
}

function command($rule, $description, closure $action)
{
    list($file_name, $command, $arguments) = _command_prepare_arguments();

    if ($command === $rule) {
        exit($action());
    } else {
        command_not_found($rule, $description);
    }
}

function if_command_not_found(?closure $action = null)
{
    static $container = null;

    if (!empty($action)) {
        return $container = $action;
    }

    return $container;
}

// 无参调用时触发已注册的 if_command_not_found 回调；有参时收集规则
function command_not_found(?string $rule = null, ?string $description = null)
{
    static $rules = [];
    static $descriptions = [];

    if (is_null($rule) && is_null($description)) {
        call_user_func(if_command_not_found(), $rules, $descriptions);
        exit;
    } else {
        $rules[] = $rule;
        $descriptions[] = $description;
    }
}

function command_read_completions(?closure $closure = null)
{
    static $container = null;

    if (! is_null($closure)) {

        $container = $closure;
    }

    return $container;
}

// 带 Tab 补全的 readline 交互输入
function _command_readline($prompt)
{
    $prompt_infos = explode("\n", $prompt);
    $last_prompt_line = array_pop($prompt_infos);

    if (! empty($prompt_infos)) {

        echo implode("\n", $prompt_infos)."\n";
    }

    // 非终端输入（管道 / 重定向 / cron 下的 /dev/null）不走 readline：输入流结束时回调不会触发，
    // 而结束的流在 stream_select 里永远可读，原实现会 100% CPU 忙等不退出；这里改为 fgets 逐行读
    if (! stream_isatty(STDIN)) {

        echo $last_prompt_line;

        $line = fgets(STDIN);

        return $line === false ? '' : rtrim($line, "\r\n");
    }

    readline_completion_function(function ($block_buffer, $block_start, $point) {

        $buffer_info = readline_info();
        $buffer_info['block_buffer'] = $block_buffer;
        $buffer_info['block_start'] = $block_start;

        $closure = command_read_completions();

        if (is_null($closure)) {

            return [];
        }

        $result = call_user_func($closure, $buffer_info);

        if ($block_buffer === '') {
            return $result;
        }

        return array_filter($result, function ($val) use ($block_buffer) {
            return starts_with($val, $block_buffer);
        });
    });

    $prompting = true;
    $result = '';

    readline_callback_handler_install($last_prompt_line, function ($line) use (&$prompting, &$result) {
        readline_add_history($line);
        $result = $line;
        $prompting = false;
        readline_callback_handler_remove();
    });

    while ($prompting) {
        $w = NULL;
        $e = NULL;
        $r = [STDIN];
        $n = stream_select($r, $w, $e, null);
        if ($n && in_array(STDIN, $r)) {
            readline_callback_read_char();
        }
    }

    return $result;
}

// 传 options 时显示编号菜单并要求选择
function command_read($prompt, $default = true, array $options = [])
{
    if ($options) {
        $prompt = "$prompt (Default: $default)\n\n";
        foreach ($options as $key => $option) {
            $prompt .= "  $key) $option\n";
        }

        $prompt .= "\n> ";

        do {
            $result = _command_readline($prompt);
            $result = trim($result);
            $result = ($result === '')? $default: $result;
        } while (! array_key_exists($result, $options));

        return $options[$result];
    } else {
        $prompt = "$prompt (Default: $default)\n> ";
        $result = _command_readline($prompt);
        $result = trim($result);
        return ($result === '')? $default: $result;
    }
}

function command_read_bool($prompt, $default = 'n')
{
    $map = [
        'y' => true,
        'n' => false,
    ];

    do {

        $res = command_read("$prompt [y/n]?", $default);

    } while (! array_key_exists($res, $map));

    return $map[$res];
}

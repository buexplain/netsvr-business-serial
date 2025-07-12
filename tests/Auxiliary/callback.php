<?php
/**
 * Copyright 2023 buexplain@qq.com
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

declare(strict_types=1);

use NetsvrProtocol\ConnClose;
use NetsvrProtocol\ConnOpen;
use NetsvrProtocol\ConnOpenResp;

ini_set('display_errors', 'on');
ini_set('display_startup_errors', 'on');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Shanghai');
require __DIR__ . '/../../vendor/autoload.php';

//php -S 0.0.0.0:6636 callback.php
try {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    switch ($path) {
        case '/':
            echo 'hello world';
            break;
        case '/onopen':
            onopen();
            break;
        case '/onclose':
            onclose();
            break;
        default:
            http_response_code(404);
            echo '404';
            break;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo $e->getTraceAsString();
}

/**
 * websocket连接打开的回调
 * @return void
 * @throws Exception
 */
function onopen(): void
{
    $protobuf = file_get_contents('php://input');
    $cp = new ConnOpen();
    $cp->mergeFromString($protobuf);
    logger('conn open: ' . $cp->serializeToJsonString());
    $cpResp = new ConnOpenResp();
    $cpResp->setAllow(true);
    $cpResp->setData($cp->getUniqId());
    header('Content-Type: application/x-protobuf');
    http_response_code(200);
    echo $cpResp->serializeToString();
}

/**
 * websocket连接关闭的回调
 * @return void
 * @throws Exception
 */
function onclose(): void
{
    $protobuf = file_get_contents('php://input');
    $cp = new ConnClose();
    $cp->mergeFromString($protobuf);
    logger('conn close: ' . $cp->serializeToJsonString());
    header('Content-Type: application/x-protobuf');
    http_response_code(204);
}

/**
 * 日志
 * @param string $message
 * @return void
 */
function logger(string $message): void
{
    file_put_contents(
        "php://stdout",
        date('Y-m-d H:i:s') . ' --> ' . $message . PHP_EOL,
        FILE_APPEND
    );
}
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

namespace NetsvrBusiness;

use Exception;
use Netsvr\Constant;
use Netsvr\Router;
use NetsvrBusiness\Contract\TaskSocketInterface;
use Throwable;
use NetsvrBusiness\Exception\ConnectException;
use Socket;

/**
 * 与网关连接的任务socket，用于：
 * 1. business请求网关，需要网关响应指令，具体移步：https://github.com/buexplain/netsvr-protocol#业务进程请求网关网关处理完毕再响应给业务进程的指令
 * 2. business请求网关，不需要网关响应指令，具体移步：https://github.com/buexplain/netsvr-protocol#业务进程单向请求网关的指令
 */
class TaskSocket implements TaskSocketInterface
{
    /**
     * netsver网关的worker服务器监听的主机
     * @var string
     */
    protected string $host;
    /**
     * netsver网关的worker服务器监听的端口
     * @var int
     */
    protected int $port;
    /***
     * 发送数据超时，单位秒
     * @var int
     */
    protected int $sendTimeout;
    /**
     * 接收数据超时，单位秒
     * @var int
     */
    protected int $receiveTimeout;
    /**
     * socket对象
     * @var Socket|null
     */
    protected ?Socket $socket = null;

    public function __construct(
        string $host,
        int    $port,
        int    $sendTimeout,
        int    $receiveTimeout,
    )
    {
        $this->host = $host;
        $this->port = $port;
        $this->sendTimeout = $sendTimeout;
        $this->receiveTimeout = $receiveTimeout;
        $this->connect();
    }

    /**
     * @param bool $throwErr
     * @return void
     */
    protected function connect(bool $throwErr = true): void
    {
        //构造对象
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            if ($throwErr) {
                $code = socket_last_error();
                throw new ConnectException(socket_strerror($code), $code);
            } else {
                return;
            }
        }
        //设置接收超时
        if (socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, array('sec' => $this->receiveTimeout, 'usec' => 0)) === false) {
            if ($throwErr) {
                $code = socket_last_error();
                throw new ConnectException(socket_strerror($code), $code);
            } else {
                return;
            }
        }
        //设置发送超时
        if (socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, array('sec' => $this->sendTimeout, 'usec' => 0)) === false) {
            if ($throwErr) {
                $code = socket_last_error();
                throw new ConnectException(socket_strerror($code), $code);
            } else {
                return;
            }
        }
        //发起连接
        if (socket_connect($socket, $this->host, $this->port) === false) {
            if ($throwErr) {
                $code = socket_last_error();
                throw new ConnectException(socket_strerror($code), $code);
            } else {
                return;
            }
        }
        //存储到本对象的属性
        $this->socket = $socket;
    }

    /**
     * @param string $data
     * @return void
     */
    public function send(string $data): void
    {
        //打包数据
        $message = pack('N', strlen($data)) . $data;
        $retry = 0;
        loop:
        $ret = socket_write($this->socket, $message, strlen($message));
        if ($ret === false) {
            if ($retry > 0) {
                usleep(50);
            }
            $retry += 1;
            $this->connect($retry == 3);
            goto loop;
        }
    }

    public function __destruct()
    {
        try {
            if ($this->socket instanceof Socket) {
                socket_close($this->socket);
                $this->socket = null;
            }
        } catch (Throwable) {
        }
    }

    /**
     * @return Router|false
     * @throws Exception
     */
    public function receive(): Router|false
    {
        //先读取包头长度，4个字节
        $packageLength = socket_read($this->socket, 4);
        if ($packageLength === false || $packageLength === '') {
            return false;
        }
        $ret = unpack('N', $packageLength);
        if (!is_array($ret) || !isset($ret[1]) || !is_int($ret[1])) {
            return false;
        } else {
            $packageLength = $ret[1];
        }
        //再读取包体数据
        $packageBody = socket_read($this->socket, $packageLength);
        //读取失败了
        if ($packageBody === false || $packageBody === '') {
            return false;
        }
        //读取到了心跳
        if ($packageBody === Constant::PONG_MESSAGE) {
            return false;
        }
        $router = new Router();
        $router->mergeFromString($packageBody);
        return $router;
    }
}

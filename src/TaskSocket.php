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
use NetsvrBusiness\Exception\TaskSocketSendException;
use Throwable;
use NetsvrBusiness\Exception\TaskSocketConnectException;
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
     * 最大闲置时间，单位秒，建议比netsvr网关的worker服务器的ReadDeadline配置小3秒
     * 这段时间内，连接没有被使用过，则会认为连接已经被网关的worker服务器关闭
     * 此后再使用连接，会丢弃当前连接，创建新的连接
     * @var int
     */
    protected int $maxIdleTime;
    /**
     * 连接最后被使用的时间
     * @var int
     */
    protected int $lastUseTime = 0;
    /**
     * socket对象
     * @var Socket|null
     */
    protected ?Socket $socket = null;

    /**
     * @param string $host netsver网关的worker服务器监听的主机
     * @param int $port netsver网关的worker服务器监听的端口
     * @param int $sendTimeout 发送数据超时，单位秒
     * @param int $receiveTimeout 接收数据超时，单位秒
     * @param int $maxIdleTime 最大闲置时间，单位秒，建议比netsvr网关的worker服务器的ReadDeadline配置小3秒
     * @throws TaskSocketConnectException
     */
    public function __construct(
        string $host,
        int    $port,
        int    $sendTimeout,
        int    $receiveTimeout,
        int    $maxIdleTime,
    )
    {
        $this->host = $host;
        $this->port = $port;
        $this->sendTimeout = $sendTimeout;
        $this->receiveTimeout = $receiveTimeout;
        $this->maxIdleTime = $maxIdleTime;
        $this->connect();
    }

    /**
     * @return void
     * @throws TaskSocketConnectException
     */
    protected function connect(): void
    {
        //构造对象
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            $code = socket_last_error();
            throw new TaskSocketConnectException(socket_strerror($code), $code);
        }
        //设置为阻塞模式，这样接下来的读取和发送都可以简单操作，否则得循环的操作socket，并判断每次写入或发送了多少，很麻烦
        if (socket_set_block($socket) === false) {
            $code = socket_last_error($socket);
            throw new TaskSocketConnectException(socket_strerror($code), $code);
        }
        //设置接收超时
        if (socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, array('sec' => $this->receiveTimeout, 'usec' => 0)) === false) {
            $code = socket_last_error($socket);
            throw new TaskSocketConnectException(socket_strerror($code), $code);
        }
        //设置发送超时
        if (socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, array('sec' => $this->sendTimeout, 'usec' => 0)) === false) {
            $code = socket_last_error($socket);
            throw new TaskSocketConnectException(socket_strerror($code), $code);
        }
        //发起连接
        try {
            if (socket_connect($socket, $this->host, $this->port) === false) {
                $code = socket_last_error($socket);
                throw new TaskSocketConnectException(socket_strerror($code), $code);
            }
        } catch (Throwable $throwable) {
            throw new TaskSocketConnectException($throwable->getMessage(), $throwable->getCode());
        }
        //存储到本对象的属性
        $this->socket = $socket;
        //初始化socket对象的最后使用时间
        $this->lastUseTime = time();
    }

    /**
     * @return void
     * @throws TaskSocketConnectException
     * @throws Throwable
     */
    protected function reconnect(): void
    {
        for ($i = 0; $i < 3; $i++) {
            try {
                $this->connect();
                break;
            } catch (Throwable $throwable) {
                if ($i == 2) {
                    //三次后还是失败，抛出异常
                    throw $throwable;
                }
            }
        }
    }

    /**
     * @param string $data
     * @return void
     * @throws TaskSocketSendException
     * @throws Throwable
     */
    public function send(string $data): void
    {
        //判断连接是否失效，失效则重连一下
        if (time() - $this->lastUseTime > $this->maxIdleTime) {
            //之所以设计这个空闲机制是因为：
            //连接被对端杀掉后，我方无感知，继续往socket写入数据，不会报错，这样就会导致待发送的数据丢失
            //其实对端是有返回TCP的RST标志的，我方收到该标志后应该发起重连，但是php的socket扩展不返回这个TCP的RST标志
            $this->reconnect();
        }
        //打包数据
        $message = pack('N', strlen($data)) . $data;
        //开始发送数据
        try {
            $ret = socket_send($this->socket, $message, strlen($message), 0);
        } catch (Throwable) {
            $ret = false;
        }
        //判断发送结果
        if ($ret !== false) {
            //发送成功，更新socket对象的最后使用时间
            $this->lastUseTime = time();
            return;
        }
        //发送失败，重连一下
        $this->reconnect();
        //再次发送
        try {
            $length = strlen($message);
            while (true) {
                $ret = socket_write($this->socket, $message, $length);
                //发送失败，退出循环
                if ($ret === false) {
                    break;
                }
                //发送成功，检查是否发送完毕
                if ($ret === $length) {
                    break;
                }
                //没有发送完毕，遇到tcp短写，继续发送
                $message = substr($message, $ret);
                $length -= $ret;
            }
        } catch (Throwable) {
            $ret = false;
        }
        //判断发送结果
        if ($ret !== false) {
            //发送成功，更新socket对象的最后使用时间
            $this->lastUseTime = time();
            return;
        }
        //发送失败，抛出异常
        $code = socket_last_error($this->socket);
        throw new TaskSocketSendException(socket_strerror($code), $code);
    }

    public function __destruct()
    {
        if ($this->socket instanceof Socket) {
            try {
                socket_close($this->socket);
            } catch (Throwable) {
            }
            $this->socket = null;
        }
    }

    /**
     * @return Router|false
     * @throws Exception
     */
    public function receive(): Router|false
    {
        //先读取包头长度，4个字节
        $packageLength = '';
        $receiveRet = socket_recv($this->socket, $packageLength, 4, MSG_WAITALL);
        if ($receiveRet === false || ($receiveRet === 0 && $packageLength === null) || $packageLength === '') {
            return false;
        }
        $ret = unpack('N', $packageLength);
        if (!is_array($ret) || !isset($ret[1]) || !is_int($ret[1])) {
            return false;
        } else {
            $packageLength = $ret[1];
        }
        //再读取包体数据
        $packageBody = '';
        $receiveRet = socket_recv($this->socket, $packageBody, $packageLength, MSG_WAITALL);
        //读取失败
        if ($receiveRet === false || ($receiveRet === 0 && $packageBody === null) || $packageBody === '') {
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

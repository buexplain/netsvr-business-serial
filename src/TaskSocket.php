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

use NetsvrBusiness\Contract\TaskSocketInterface;
use Psr\Log\LoggerInterface;
use Workerman\Timer as WorkermanTimer;
use Swoole\Timer as SwooleTimer;

/**
 * 与网关连接的任务socket，用于：
 * 1. business请求网关，需要网关响应指令，具体移步：https://github.com/buexplain/netsvr-protocol#业务进程请求网关网关处理完毕再响应给业务进程的指令
 * 2. business请求网关，不需要网关响应指令，具体移步：https://github.com/buexplain/netsvr-protocol#业务进程单向请求网关的指令
 */
class TaskSocket extends Socket implements TaskSocketInterface
{
    /**
     * @var int|bool 心跳定时器的id
     */
    protected int|bool|null $timerId = false;

    /**
     * @var int 与网关维持心跳的间隔毫秒数
     */
    protected int $heartbeatIntervalMillisecond;

    /**
     * @var string business进程向网关的worker服务器发送的心跳消息
     */
    protected string $workerHeartbeatMessage;

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
     * @param string $logPrefix 日志前缀
     * @param LoggerInterface $logger
     * @param string $workerAddr netsvr网关的worker服务器监听的tcp地址
     * @param int $sendReceiveTimeout 读写数据超时，单位秒
     * @param int $connectTimeout 连接到服务端超时，单位秒
     * @param int $maxIdleTime 最大闲置时间，单位秒，建议比netsvr网关的worker服务器的ReadDeadline配置小3秒
     * @param string $workerHeartbeatMessage business进程向网关的worker服务器发送的心跳消息
     * @param int $heartbeatIntervalMillisecond 与网关维持心跳的间隔毫秒数
     */
    public function __construct(
        string          $logPrefix,
        LoggerInterface $logger,
        string          $workerAddr,
        int             $sendReceiveTimeout,
        int             $connectTimeout,
        int             $maxIdleTime,
        string          $workerHeartbeatMessage,
        int             $heartbeatIntervalMillisecond,
    )
    {
        parent::__construct($logPrefix, $logger, $workerAddr, $sendReceiveTimeout, $connectTimeout);
        $this->maxIdleTime = $maxIdleTime;
        $this->workerHeartbeatMessage = $workerHeartbeatMessage;
        $this->heartbeatIntervalMillisecond = $heartbeatIntervalMillisecond;
    }

    public function send(string $data): bool
    {
        //判断连接是否失效，失效则重连一下
        if (time() - $this->lastUseTime > $this->maxIdleTime) {
            //之所以设计这个空闲机制是因为：
            //连接被对端杀掉后，我方无感知，继续往socket写入数据，不会报错，这样就会导致待发送的数据丢失
            //其实对端是有返回TCP的RST标志的，我方收到该标志后应该发起重连，但是php的socket扩展不返回这个TCP的RST标志
            $this->connect();
        }
        $ret = parent::send($data);
        if ($ret) {
            $this->lastUseTime = time();
        } else {
            $this->lastUseTime = 0;
        }
        return $ret;
    }

    protected function loopHeartbeat(): void
    {
        if (is_int($this->timerId)) {
            return;
        }
        //优先兼容workerman，因为workerman的定时器可能来自于swoole，所以优先兼容workerman
        if (class_exists('\Workerman\Timer')) {
            $this->timerId = WorkermanTimer::add($this->heartbeatIntervalMillisecond / 1000, function () {
                if ($this->isConnected()) {
                    if (!$this->send($this->workerHeartbeatMessage)) {
                        $this->connect();
                    }
                }
            });
        } elseif (class_exists('\Swoole\Timer')) {
            //兼容laravel-octane的swoole驱动
            $this->timerId = SwooleTimer::tick($this->heartbeatIntervalMillisecond, function () {
                if ($this->isConnected()) {
                    if (!$this->send($this->workerHeartbeatMessage)) {
                        $this->connect();
                    }
                }
            });
        }
    }

    /**
     * @return bool
     */
    public function connect(): bool
    {
        $ret = parent::connect();
        if ($ret) {
            $this->lastUseTime = time();
            $this->loopHeartbeat();
        } else {
            $this->lastUseTime = 0;
        }
        return $ret;
    }

    public function close(): void
    {
        try {
            //先关闭心跳定时器
            if (class_exists('\Workerman\Timer')) {
                if (is_int($this->timerId)) {
                    WorkermanTimer::del($this->timerId);
                    $this->timerId = false;
                }
            } elseif (class_exists('\Swoole\Timer')) {
                if (is_int($this->timerId)) {
                    SwooleTimer::clear($this->timerId);
                    $this->timerId = false;
                }
            }
        } finally {
            //关闭连接
            parent::close();
        }
    }
}

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

namespace NetsvrBusiness\Workerman;

use Psr\Log\LoggerInterface;
use Workerman\Timer;

class TaskSocket extends \NetsvrBusiness\TaskSocket
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
        parent::__construct($logPrefix, $logger, $workerAddr, $sendReceiveTimeout, $connectTimeout, $maxIdleTime);
        $this->workerHeartbeatMessage = $workerHeartbeatMessage;
        $this->heartbeatIntervalMillisecond = $heartbeatIntervalMillisecond;
    }

    protected function loopHeartbeat(): void
    {
        if (is_int($this->timerId)) {
            return;
        }
        $this->timerId = Timer::add($this->heartbeatIntervalMillisecond / 1000, function () {
            if ($this->isConnected()) {
                if (!$this->send($this->workerHeartbeatMessage)) {
                    $this->connect();
                }
            }
        });
    }

    public function connect(): bool
    {
        $ret = parent::connect();
        if ($ret) {
            $this->loopHeartbeat();
        }
        return $ret;
    }

    public function close(): void
    {
        //先关闭心跳定时器
        if (is_int($this->timerId)) {
            Timer::del($this->timerId);
            $this->timerId = false;
        }
        //关闭连接
        parent::close();
    }
}
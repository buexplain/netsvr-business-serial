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

use NetsvrBusiness\Contract\MainSocketInterface;
use NetsvrBusiness\Contract\MainSocketManagerInterface;

/**
 *
 */
class MainSocketManager implements MainSocketManagerInterface
{
    /**
     * 持有所有与网关进行连接的mainSocket对象
     * @var array|MainSocketInterface[]
     */
    protected array $pool = [];

    /**
     * @var bool 是否已经调用过start方法
     */
    protected bool $status = false;

    /**
     * 返回所有与网关服务器连接的mainSocket对象
     * @return array|MainSocketInterface[]
     */
    public function getSockets(): array
    {
        return $this->status ? $this->pool : [];
    }

    /**
     * 根据网关的workerAddr获取具体网关的连接，注意这个地址是16进制字符串
     * @param string $workerAddrAsHex
     * @return MainSocketInterface|null
     */
    public function getSocket(string $workerAddrAsHex): ?MainSocketInterface
    {
        return $this->status ? ($this->pool[$workerAddrAsHex] ?? null) : null;
    }

    /**
     * @param MainSocketInterface $socket
     * @return void
     */
    public function addSocket(MainSocketInterface $socket): void
    {
        $this->pool[workerAddrConvertToHex($socket->getWorkerAddr())] = $socket;
    }

    /**
     * 让所有的mainSocket开始与网关进行交互
     * @return bool
     */
    public function start(): bool
    {
        if ($this->status) {
            return true;
        }
        //先连接
        $connectOk = [];
        foreach ($this->pool as $socket) {
            if ($socket->connect()) {
                $connectOk[] = $connectOk;
            } else {
                //关闭所有已经连接成功的socket
                foreach ($connectOk as $ok) {
                    $ok->close();
                }
                return false;
            }
        }
        //再注册
        $registerOk = [];
        foreach ($this->pool as $socket) {
            if ($socket->register()) {
                $registerOk[] = $registerOk;
            } else {
                //关闭所有已经注册成功的socket
                foreach ($registerOk as $ok) {
                    $ok->unregister();
                }
                //关闭所有已经连接成功的socket
                foreach ($this->pool as $sk) {
                    $sk->close();
                }
                return false;
            }
        }
        //最后开始心跳
        foreach ($this->pool as $socket) {
            $socket->loopHeartbeat();
        }
        $this->status = true;
        return $this->status;
    }

    /**
     * @return void
     */
    public function close(): void
    {
        if (!$this->status) {
            return;
        }
        $this->status = false;
        //先取消注册，这样netsvr就不再转发websocket事件过来
        foreach ($this->pool as $socket) {
            $socket->unregister();
        }
        //再关闭socket
        foreach ($this->pool as $socket) {
            $socket->close();
        }
    }
}
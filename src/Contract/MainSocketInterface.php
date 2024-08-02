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

namespace NetsvrBusiness\Contract;

/**
 * 主socket，用于：
 * 1. 接收网关单向发给business的指令，具体移步：https://github.com/buexplain/netsvr-protocol#网关单向转发给业务进程的指令
 * 2. business请求网关，无需网关响应的指令，具体移步：https://github.com/buexplain/netsvr-protocol#业务进程单向请求网关的指令
 */
interface MainSocketInterface
{
    /**
     * 连接到网关
     * @return bool
     */
    public function connect(): bool;

    /**
     * 注册自己，注册后，网关会转发用户的信息到本socket
     * @return bool
     */
    public function register(): bool;

    /**
     * 取消注册，取消后，网关会停止转发用户的信息到本socket
     * @return bool
     */
    public function unregister(): bool;

    /**
     * 定时心跳，保持连接的活跃
     * @return void
     */
    public function loopHeartbeat(): void;

    /**
     * 发送数据到网关
     * @param string $data
     * @return void
     */
    public function send(string $data): void;

    /**
     * 关闭连接
     * @return void
     */
    public function close(): void;

    /**
     * 返回当前连接的netsvr网关的worker服务器监听的tcp地址
     * @return string
     */
    public function getWorkerAddr(): string;
}
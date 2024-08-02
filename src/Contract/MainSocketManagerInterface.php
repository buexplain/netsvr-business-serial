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
 *
 */
interface MainSocketManagerInterface
{
    /**
     * 返回所有与网关服务器连接的mainSocket对象
     * @return array|MainSocketInterface[]
     */
    public function getSockets(): array;

    /**
     * 根据网关的workerAddr获取具体网关的连接，注意这个地址是16进制字符串
     * @param string $workerAddrAsHex
     * @return MainSocketInterface|null
     */
    public function getSocket(string $workerAddrAsHex): ?MainSocketInterface;

    /**
     * 添加一个与网关服务器连接的mainSocket对象
     * @param MainSocketInterface $socket
     * @return void
     */
    public function addSocket(MainSocketInterface $socket): void;

    /**
     * 让所有的mainSocket开始与网关进行交互
     * @return bool
     */
    public function start(): bool;

    /**
     * 关闭所有的mainSocket，不再与网关进行任何交互
     * @return void
     */
    public function close(): void;
}
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
use NetsvrBusiness\Contract\TaskSocketMangerInterface;

/**
 * 集中管理所有网关的socket连接对象
 */
class TaskSocketManger implements TaskSocketMangerInterface
{
    /**
     * @var array | TaskSocketInterface[]
     */
    protected array $sockets = [];

    /**
     * @param TaskSocketInterface $socket
     * @return void
     */
    public function addSocket(TaskSocketInterface $socket): void
    {
        $this->sockets[workerAddrConvertToHex($socket->getWorkerAddr())] = $socket;
    }

    /**
     * 返回连接的数量
     * @return int
     */
    public function count(): int
    {
        return count($this->sockets);
    }

    /**
     * @param string $workerAddrAsHex
     * @return TaskSocketInterface|null
     */
    public function getSocket(string $workerAddrAsHex): ?TaskSocketInterface
    {
        return $this->sockets[$workerAddrAsHex] ?? null;
    }

    /**
     * @return array|TaskSocketInterface[]
     */
    public function getSockets(): array
    {
        return $this->sockets;
    }

    /**
     * @return void
     */
    public function close(): void
    {
        foreach ($this->getSockets() as $socket) {
            $socket->close();
        }
    }
}
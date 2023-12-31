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
     * @param int $serverId
     * @param TaskSocketInterface $socket
     * @return void
     */
    public function addSocket(int $serverId, TaskSocketInterface $socket): void
    {
        $this->sockets[$serverId] = $socket;
    }

    /**
     * @param int $serverId
     * @return TaskSocketInterface|null
     */
    public function getSocket(int $serverId): ?TaskSocketInterface
    {
        return $this->sockets[$serverId] ?? null;
    }

    /**
     * @return array|TaskSocketInterface[]
     */
    public function getSockets(): array
    {
        return $this->sockets;
    }
}
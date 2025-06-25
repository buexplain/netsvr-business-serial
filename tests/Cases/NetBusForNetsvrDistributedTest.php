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

namespace NetsvrBusinessTest\Cases;

/**
 * 测试网关分布式部署的情况
 */
final class NetBusForNetsvrDistributedTest extends NetBusTestAbstract
{
    protected static function getNetsvrConfig(): array
    {
        return [
            'netsvr' => [
                [
                    'workerAddr' => '127.0.0.1:6071',
                    'ws' => 'ws://127.0.0.1:6070/netsvr',

                ],
                [
                    'workerAddr' => '127.0.0.1:6081',
                    'ws' => 'ws://127.0.0.1:6080/netsvr',
                ],
            ],
            'maxIdleTime' => 117,
            'workerHeartbeatMessage' => self::WORKER_HEARTBEAT_MESSAGE,
            'sendReceiveTimeout' => 5,
            'connectTimeout' => 5,
            'heartbeatIntervalMillisecond' => 45 * 1000,
        ];
    }
}
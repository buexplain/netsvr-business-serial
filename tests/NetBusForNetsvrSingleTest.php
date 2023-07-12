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

namespace NetsvrBusinessTest;

/**
 * 测试网关单机部署的情况
 */
final class NetBusForNetsvrSingleTest extends NetBusTestAbstract
{
    protected static array $netsvrConfig = [
        [
            'serverId' => 0,
            'host' => '127.0.0.1',
            'port' => 6061,
            'receiveTimeout' => 30,
            'sendTimeout' => 30,
            //网关服务器必须支持自定义uniqId连接
            'ws' => 'ws://127.0.0.1:6060/netsvr?uniqId=',
        ],
    ];
}
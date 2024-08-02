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

use Workerman\Connection\ConnectionInterface;
use Workerman\Protocols\ProtocolInterface;

/**
 * tcp包协议
 */
class LengthProtocol implements ProtocolInterface
{
    public static function input($recv_buffer, ConnectionInterface $connection)
    {
        if (strlen($recv_buffer) < 4) {
            return 0;
        }
        $prefix = unpack('N', $recv_buffer);
        return $prefix[1] + 4;
    }

    /**
     * @param $recv_buffer
     * @param ConnectionInterface $connection
     * @return array
     */
    public static function decode($recv_buffer, ConnectionInterface $connection): array
    {
        $cmd = unpack('N', substr($recv_buffer, 4, 4));
        $protobuf = substr($recv_buffer, 8);
        return ['cmd' => $cmd[1], 'protobuf' => $protobuf];
    }

    public static function encode($data, ConnectionInterface $connection): string
    {
        return pack('N', strlen($data)) . $data;
    }
}
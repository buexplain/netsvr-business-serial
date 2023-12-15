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

use Netsvr\Constant;
use NetsvrBusiness\Contract\SocketInterface;
use NetsvrBusiness\Socket;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SocketTest extends TestCase
{
    protected static function getSocket(): SocketInterface
    {
        return new Socket(new NullLogger(), '127.0.0.1', 6061, 1, 1);
    }

    /**
     * composer test -- --filter=testConnect
     * @return void
     */
    public function testConnect()
    {
        $socket = self::getSocket();
        $this->assertTrue($socket->connect());
        $socket->close();
    }

    /**
     * composer test -- --filter=testSend
     * @return void
     */
    public function testSend()
    {
        $socket = $this->getSocket();
        $this->assertFalse($socket->send(Constant::PING_MESSAGE));
        $socket->connect();
        $this->assertTrue($socket->send(Constant::PING_MESSAGE));
    }

    /**
     * composer test -- --filter=testReceive
     * @return void
     */
    public function testReceive()
    {
        $socket = $this->getSocket();
        $socket->connect();
        $socket->send(Constant::PING_MESSAGE);
        $this->assertTrue($socket->receive() === Constant::PONG_MESSAGE);
    }

    /**
     * composer test -- --filter=testClose
     * @return void
     */
    public function testClose()
    {
        $socket = $this->getSocket();
        $socket->connect();
        $this->assertTrue($socket->isConnected());
        $socket->close();
        $this->assertFalse($socket->isConnected());
    }

    /**
     * composer test -- --filter=testIsConnected
     * @return void
     */
    public function testIsConnected()
    {
        $socket = $this->getSocket();
        $this->assertFalse($socket->isConnected());
        $socket->connect();
        $this->assertTrue($socket->isConnected());
        $socket->close();
        $this->assertFalse($socket->isConnected());
    }
}
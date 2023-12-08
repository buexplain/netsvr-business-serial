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

use NetsvrBusiness\Contract\SocketInterface;
use NetsvrBusiness\Exception\SocketConnectException;
use NetsvrBusiness\Exception\SocketReceiveException;
use NetsvrBusiness\Exception\SocketSendException;
use Socket as BaseSocket;
use Throwable;
use Psr\Log\LoggerInterface;

class Socket implements SocketInterface
{
    /**
     * @var LoggerInterface 日志对象
     */
    protected LoggerInterface $logger;

    /**
     * netsvr网关的worker服务器监听的主机
     * @var string
     */
    protected string $host;

    /**
     * netsvr网关的worker服务器监听的端口
     * @var int
     */
    protected int $port;

    /***
     * 发送数据超时，单位秒
     * @var int
     */
    protected int $sendTimeout;

    /**
     * 接收数据超时，单位秒
     * @var int
     */
    protected int $receiveTimeout;

    /**
     * socket对象
     * @var BaseSocket|null
     */
    protected ?BaseSocket $socket = null;

    /**
     * @var bool 连接状态
     */
    protected bool $connected = false;

    /**
     * @param string $host netsvr网关的worker服务器监听的主机
     * @param int $port netsvr网关的worker服务器监听的端口
     * @param int $sendTimeout 发送数据超时，单位秒
     * @param int $receiveTimeout 接收数据超时，单位秒
     */
    public function __construct(
        LoggerInterface $logger,
        string          $host,
        int             $port,
        int             $sendTimeout,
        int             $receiveTimeout,
    )
    {
        $this->logger = $logger;
        $this->host = $host;
        $this->port = $port;
        $this->sendTimeout = $sendTimeout;
        $this->receiveTimeout = $receiveTimeout;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function close(): void
    {
        if ($this->socket instanceof BaseSocket) {
            $this->connected = false;
            $this->__destruct();
            $this->logger->info(sprintf('close connection %s:%s ok.',
                $this->host,
                $this->port,
            ));
        }
    }

    /**
     *
     */
    public function __destruct()
    {
        if ($this->socket instanceof BaseSocket) {
            try {
                socket_close($this->socket);
            } catch (Throwable) {
            }
            $this->socket = null;
        }
    }

    public function connect(): bool
    {
        try {
            //销毁旧的连接
            $this->__destruct();
            //开始新的连接
            //构造对象
            $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if ($socket === false) {
                $code = socket_last_error();
                socket_clear_error();
                throw new SocketConnectException(socket_strerror($code), $code);
            }
            //设置为阻塞模式
            if (socket_set_block($socket) === false) {
                $code = socket_last_error($socket);
                socket_clear_error($socket);
                throw new SocketConnectException(socket_strerror($code), $code);
            }
            //设置接收超时
            if (socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, array('sec' => $this->receiveTimeout, 'usec' => 0)) === false) {
                $code = socket_last_error($socket);
                socket_clear_error($socket);
                throw new SocketConnectException(socket_strerror($code), $code);
            }
            //设置发送超时
            if (socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, array('sec' => $this->sendTimeout, 'usec' => 0)) === false) {
                $code = socket_last_error($socket);
                throw new SocketConnectException(socket_strerror($code), $code);
            }
            //发起连接
            try {
                if (socket_connect($socket, $this->host, $this->port) === false) {
                    $code = socket_last_error($socket);
                    socket_clear_error($socket);
                    throw new SocketConnectException(socket_strerror($code), $code);
                }
            } catch (Throwable $throwable) {
                throw new SocketConnectException($throwable->getMessage(), $throwable->getCode());
            }
            //存储到本对象的属性
            $this->socket = $socket;
            $this->connected = true;
            return true;
        } catch (Throwable $throwable) {
            $this->connected = false;
            $this->logger->error(sprintf('connect to %s:%s failed.%s%s',
                $this->host,
                $this->port,
                "\n",
                self::formatExp($throwable)
            ));
            return false;
        }
    }

    /**
     * 发送一个包，失败返回false，成功返回true
     * @param string $data
     * @return bool
     */
    public function send(string $data): bool
    {
        try {
            $data = pack('N', strlen($data)) . $data;
            $length = strlen($data);
            while (true) {
                $ret = socket_write($this->socket, $data, $length);
                //发送失败，退出循环
                if ($ret === false) {
                    $code = socket_last_error($this->socket);
                    socket_clear_error($this->socket);
                    throw new SocketSendException(socket_strerror($code), $code);
                }
                //发送成功，检查是否发送完毕
                if ($ret === $length) {
                    return true;
                }
                //没有发送完毕，遇到tcp短写，继续发送
                $data = substr($data, $ret);
                $length -= $ret;
            }
        } catch (Throwable $throwable) {
            $this->connected = false;
            $this->logger->error(sprintf('send to %s:%s failed.%s%s',
                $this->host,
                $this->port,
                "\n",
                self::formatExp($throwable)
            ));
            return false;
        }
    }

    /**
     * 从socket中读取一定长度的数据
     * @param int $length
     * @return string
     */
    protected function _receive(int $length): string
    {
        $bufferLength = socket_recv($this->socket, $buffer, $length, MSG_WAITALL);
        if ($bufferLength === false || $buffer === null) {
            $code = socket_last_error($this->socket);
            socket_clear_error($this->socket);
            if ($code === SOCKET_ETIMEDOUT) {
                //Operation timed out
                //读取数据超时
                return '';
            }
            //其它错误
            throw new SocketReceiveException(socket_strerror($code), $code);
        }
        //对端直接关闭了连接
        if ($bufferLength === 0 && $buffer === '') {
            throw new SocketReceiveException('netsvr server closed the connection', 0);
        }
        return $buffer;
    }

    /**
     * 读取成功返回一个包，失败返回false，超时返回空字符串
     * @return string|false
     */
    public function receive(): string|false
    {
        try {
            //先读取包头长度，4个字节
            $buffer = $this->_receive(4);
            if ($buffer === '') {
                //读取超时，返回空字符串
                return '';
            }
            //解析出包体长度
            $prefix = unpack('N', $buffer);
            if (!is_array($prefix) || !isset($prefix[1]) || !is_int($prefix[1])) {
                throw new SocketReceiveException('unpack netsvr package length failed.', 0);
            }
            $packageLength = $prefix[1];
            //再读取包体数据
            $packageBody = '';
            $readBytes = $packageLength;
            while ($readBytes > 0) {
                $buffer = $this->_receive(min($readBytes, 65536));
                if ($buffer === '') {
                    //读取超时，因为包头读取成功了，此时tcp流中的包体读取超时，则算读取失败，否则无法解析出一个完整的业务包
                    $this->connected = false;
                    return false;
                }
                $packageBody .= $buffer;
                $readBytes -= strlen($buffer);
            }
            return $packageBody;
        } catch (Throwable $throwable) {
            $this->connected = false;
            $this->logger->error(sprintf('receive from %s:%s failed.%s%s',
                $this->host,
                $this->port,
                PHP_EOL,
                self::formatExp($throwable)
            ));
            return false;
        }
    }

    /**
     * @param Throwable $throwable
     * @return string
     */
    protected static function formatExp(Throwable $throwable): string
    {
        $message = $throwable->getMessage();
        $message = trim($message);
        if (strlen($message) == 0) {
            $message = get_class($throwable);
        }
        return sprintf(
            "%d --> %s in %s on line %d\nThrowable: %s\nStack trace:\n%s",
            $throwable->getCode(),
            $message,
            $throwable->getFile(),
            $throwable->getLine(),
            get_class($throwable),
            $throwable->getTraceAsString()
        );
    }
}
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
use Psr\Log\LoggerInterface;
use Throwable;

/**
 *
 */
class Socket implements SocketInterface
{
    /**
     * @var string 日志前缀
     */
    protected string $logPrefix = '';

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
     * 读写数据超时，单位秒
     * @var int
     */
    protected int $sendReceiveTimeout;
    /**
     * 连接到服务端超时，单位秒
     * @var int
     */
    protected int $connectTimeout;

    /**
     * socket对象
     * @var resource|null
     */
    protected mixed $socket = null;

    /**
     * @var bool 连接状态
     */
    protected bool $connected = false;

    /**
     * @param string $logPrefix
     * @param LoggerInterface $logger
     * @param string $host netsvr网关的worker服务器监听的主机
     * @param int $port netsvr网关的worker服务器监听的端口
     * @param int $sendReceiveTimeout 读写数据超时，单位秒
     * @param int $connectTimeout 连接到服务端超时，单位秒
     */
    public function __construct(
        string          $logPrefix,
        LoggerInterface $logger,
        string          $host,
        int             $port,
        int             $sendReceiveTimeout,
        int             $connectTimeout,
    )
    {
        $this->logPrefix = strlen($logPrefix) > 0 ? trim($logPrefix) . ' ' : '';
        $this->logger = $logger;
        $this->host = $host;
        $this->port = $port;
        $this->sendReceiveTimeout = $sendReceiveTimeout;
        $this->connectTimeout = $connectTimeout;
    }

    /**
     *
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * @return string
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * @return int
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * 判断连接是否正常
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * 关闭连接
     * @return void
     */
    public function close(): void
    {
        if (!$this->connected) {
            return;
        }
        $this->connected = false;
        if (is_resource($this->socket)) {
            try {
                fclose($this->socket);
            } catch (Throwable) {
            } finally {
                $this->socket = null;
                $this->logger->info(sprintf($this->logPrefix . 'close connection %s:%s ok.',
                    $this->host,
                    $this->port,
                ));
            }
        }
    }

    /**
     * 发起连接
     * @return bool
     */
    public function connect(): bool
    {
        try {
            $socket = @stream_socket_client(sprintf('tcp://%s:%d', $this->host, $this->port), $errno, $errMsg, $this->connectTimeout);
            if ($socket === false) {
                throw new SocketConnectException($errMsg, $errno);
            }
            if (!stream_set_timeout($socket, $this->sendReceiveTimeout)) {
                throw new SocketConnectException('stream set timeout error', 0);
            }
            if (!stream_set_blocking($socket, true)) {
                throw new SocketConnectException('stream set blocking error', 0);
            }
            //先关闭旧的socket对象
            $this->close();
            //存储到本对象的属性
            $this->socket = $socket;
            $this->connected = true;
            $this->logger->info(sprintf($this->logPrefix . 'connect to %s:%s ok.',
                $this->host,
                $this->port,
            ));
            return true;
        } catch (Throwable $throwable) {
            $this->logger->error(sprintf($this->logPrefix . 'connect to %s:%s failed.%s%s',
                $this->host,
                $this->port,
                "\n",
                self::formatExp($throwable)
            ));
            return false;
        }
    }

    /**
     * 发送数据
     * @param string $data
     * @return bool
     */
    public function send(string $data): bool
    {
        try {
            $data = pack('N', strlen($data)) . $data;
            $length = strlen($data);
            while (true) {
                $ret = fwrite($this->socket, $data, $length);
                //发送失败，退出循环
                if ($ret === false) {
                    $error = error_get_last();
                    if ($error) {
                        $message = $error['message'];
                        error_clear_last();
                    } else {
                        $message = 'netsvr server closed the connection';
                    }
                    throw new SocketSendException($message);
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
            $this->logger->error(sprintf($this->logPrefix . 'send to %s:%s failed.%s%s',
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
        $buffer = fread($this->socket, $length);
        if ($buffer === false || $buffer === '') {
            $info = stream_get_meta_data($this->socket);
            if ($info['timed_out']) {
                //读取数据超时
                return '';
            }
            $error = error_get_last();
            if ($error) {
                $message = $error['message'];
                error_clear_last();
            } else {
                $message = 'netsvr server closed the connection';
            }
            throw new SocketReceiveException($message);
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
            $this->logger->error(sprintf($this->logPrefix . 'receive from %s:%s failed.%s%s',
                $this->host,
                $this->port,
                "\n",
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

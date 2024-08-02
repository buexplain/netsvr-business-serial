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

use Exception;
use Netsvr\Cmd;
use Netsvr\ConnClose;
use Netsvr\ConnOpen;
use Netsvr\RegisterReq;
use Netsvr\RegisterResp;
use Netsvr\Transfer;
use Netsvr\UnRegisterReq;
use Netsvr\UnRegisterResp;
use NetsvrBusiness\Container;
use NetsvrBusiness\Contract\EventInterface;
use NetsvrBusiness\Contract\MainSocketInterface;
use NetsvrBusiness\Contract\SocketInterface;
use NetsvrBusiness\Contract\TaskSocketMangerInterface;
use NetsvrBusiness\Exception\RegisterMainSocketException;
use Psr\Log\LoggerInterface;
use Throwable;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;
use function NetsvrBusiness\workerAddrConvertToHex;

/**
 * 基于Workerman实现的MainSocket，可用于webman框架
 */
class MainSocket implements MainSocketInterface
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
     * @var EventInterface 处理网关发送过来的事件的回调对象
     */
    protected EventInterface $event;
    /**
     * @var SocketInterface 与网关进行连接的socket对象，该对象只在注册过程中使用，注册成功后，会将该对象导出成TcpConnection对象
     */
    protected SocketInterface $socket;
    /**
     * @var TcpConnection|null 与网关进行连接的socket对象，该对象在注册后使用
     */
    protected TcpConnection|null $connection = null;

    /**
     * @var string business进程向网关的worker服务器发送的心跳消息
     */
    protected string $workerHeartbeatMessage;
    /**
     * @var int 业务进程允许网关服务器转发的事件集合，具体的枚举值参考：Netsvr\Event类
     */
    protected int $events;
    /**
     * @var int 希望网关服务开启多少条协程来处理本连接的数据
     */
    protected int $processCmdGoroutineNum;
    /**
     * @var string 业务进程向网关发起注册后，网关返回的唯一id，取消注册的时候需要用到
     */
    protected string $connId = '';
    /**
     * @var int 与网关维持心跳的间隔毫秒数
     */
    protected int $heartbeatIntervalMillisecond;
    /**
     * @var bool 判断是否已经关闭自己
     */
    protected bool $closed = false;

    /**
     * @var int|bool 心跳定时器的id
     */
    protected int|bool $timerId = false;

    /**
     * @throws Exception
     */
    public function __construct(
        string          $logPrefix,
        LoggerInterface $logger,
        EventInterface  $event,
        SocketInterface $socket,
        string          $workerHeartbeatMessage,
        int             $events,
        int             $processCmdGoroutineNum,
        int             $heartbeatIntervalMillisecond,
    )
    {
        $this->logPrefix = strlen($logPrefix) > 0 ? trim($logPrefix) . ' ' : '';
        $this->logger = $logger;
        $this->event = $event;
        $this->socket = $socket;
        $this->workerHeartbeatMessage = $workerHeartbeatMessage;
        $this->events = $events;
        $this->processCmdGoroutineNum = $processCmdGoroutineNum;
        $this->heartbeatIntervalMillisecond = $heartbeatIntervalMillisecond;
    }

    /**
     * 该方法，必须在注册成功后才能调用
     * @param string $data
     * @return void
     */
    public function send(string $data): void
    {
        $this->connection->send($data);
    }

    public function getWorkerAddr(): string
    {
        return $this->socket->getWorkerAddr();
    }

    public function connect(): bool
    {
        return $this->socket->connect();
    }

    /**
     * 同步模式注册自己
     * @return bool
     */
    public function register(): bool
    {
        try {
            $req = new RegisterReq();
            $req->setEvents($this->events);
            $req->setProcessCmdGoroutineNum($this->processCmdGoroutineNum);
            if (!$this->socket->send(pack('N', Cmd::Register) . $req->serializeToString())) {
                return false;
            }
            $data = $this->socket->receive();
            if (!$data) {
                return false;
            }
            $resp = new RegisterResp();
            $resp->mergeFromString(substr($data, 4));
            if ($resp->getCode() == 0) {
                $this->connId = $resp->getConnId();
                //将socket转为TcpConnection对象
                $this->convertSocketToTcpConnection();
                $this->logger->info(sprintf($this->logPrefix . 'register to %s ok.', $this->socket->getWorkerAddr()));
                return true;
            }
            throw new RegisterMainSocketException($resp->getMessage(), $resp->getCode());
        } catch (Throwable $throwable) {
            $this->logger->error(sprintf($this->logPrefix . 'register to %s failed.%s%s',
                $this->socket->getWorkerAddr(),
                PHP_EOL,
                self::formatExp($throwable)
            ));
            return false;
        }
    }

    public function loopHeartbeat(): void
    {
        if ($this->connection instanceof TcpConnection) {
            if (is_int($this->timerId)) {
                return;
            }
            $this->timerId = Timer::add($this->heartbeatIntervalMillisecond / 1000, function () {
                if ($this->connection instanceof TcpConnection && $this->connection->getStatus() === TcpConnection::STATUS_ESTABLISHED) {
                    $this->connection->send($this->workerHeartbeatMessage);
                }
            });
        }
    }

    /**
     * 同步模式取消注册
     * @return bool
     */
    public function unregister(): bool
    {
        if ($this->connId === '') {
            return true;
        }
        try {
            $req = new UnRegisterReq();
            $req->setConnId($this->connId);
            /**
             * @var $taskSocketManger TaskSocketMangerInterface
             */
            $taskSocketManger = Container::getInstance()->get(TaskSocketMangerInterface::class);
            $socket = $taskSocketManger->getSocket(workerAddrConvertToHex($this->socket->getWorkerAddr()));
            if (!$socket) {
                return false;
            }
            $data = pack('N', Cmd::Unregister) . $req->serializeToString();
            if (!$socket->send($data)) {
                return false;
            }
            for ($i = 0; $i < 3; $i++) {
                $ret = $socket->receive();
                if ($ret === false) {
                    return false;
                }
                if ($ret === '') {
                    continue;
                }
                break;
            }
            if ($ret === '') {
                return false;
            }
            $resp = new UnRegisterResp();
            $resp->mergeFromString(substr($ret, 4));
            $this->connId = '';
            $this->logger->info(sprintf($this->logPrefix . 'unregister to %s ok.', $this->socket->getWorkerAddr()));
            return true;
        } catch (Throwable $throwable) {
            $this->logger->error(sprintf($this->logPrefix . 'unregister to %s failed.%s%s',
                $this->socket->getWorkerAddr(),
                PHP_EOL,
                self::formatExp($throwable)
            ));
            return false;
        }
    }

    public function close(): void
    {
        //判断是否已经执行过关闭函数
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        //先关闭心跳定时器
        if (is_int($this->timerId)) {
            Timer::del($this->timerId);
            $this->timerId = false;
        }
        //关闭底层的socket对象
        $this->closeTcpConnection();
        $this->socket->close();
    }

    /**
     * 将socket对象转换为TcpConnection对象
     * @return void
     */
    protected function convertSocketToTcpConnection(): void
    {
        $this->closeTcpConnection();
        $this->connection = new TcpConnection($this->socket->getSocket(), $this->socket->getWorkerAddr());
        $this->connection->protocol = LengthProtocol::class;
        $this->connection->onMessage = function (TcpConnection $connection, $data) {
            $this->onMessage($data);
        };
        $this->connection->onClose = function () {
            $this->onClose();
        };
    }

    protected function onMessage(array $data): void
    {
        try {
            $cmd = $data['cmd'];
            $protobuf = $data['protobuf'];
            switch ($cmd) {
                case Cmd::Transfer:
                    $tf = new Transfer();
                    $tf->mergeFromString($protobuf);
                    $this->event->onMessage($tf);
                    break;
                case Cmd::ConnOpen:
                    $cp = new ConnOpen();
                    $cp->mergeFromString($protobuf);
                    $this->event->onOpen($cp);
                    break;
                case Cmd::ConnClose:
                    $cc = new ConnClose();
                    $cc->mergeFromString($protobuf);
                    $this->event->onClose($cc);
                    break;
            }
        } catch (Throwable $throwable) {
            $this->logger->error(sprintf($this->logPrefix . 'process netsvr event failed.%s%s', PHP_EOL, self::formatExp($throwable)));
        }
    }

    protected function onClose(): void
    {
        //判断是否已经关闭自己
        if ($this->closed) {
            return;
        }
        // 定时器，每隔3秒，尝试重新注册连接
        $timerId = false;
        $timerId = Timer::add(3, function () use (&$timerId) {
            //定时器到期，先判断是否已经关闭自己
            if ($this->closed) {
                is_int($timerId) && Timer::del($timerId);
                return;
            }
            $this->socket->close();
            if ($this->connect() && $this->register()) {
                is_int($timerId) && Timer::del($timerId);
            }
        });
    }

    protected function closeTcpConnection(): void
    {
        if ($this->connection instanceof TcpConnection) {
            $connection = $this->connection;
            //不再持有该对象，让gc回收，触发对象的析构函数，减少workerman对于connection_count的计数，避免进程无法退出的问题
            $this->connection = null;
            //移除onClose回调，避免触发重连逻辑
            $connection->onClose = null;
            $connection->close();
            $connection->destroy();
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
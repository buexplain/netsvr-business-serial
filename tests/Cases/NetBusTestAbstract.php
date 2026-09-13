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

use ErrorException;
use NetsvrProtocol\ConnInfoDelete;
use NetsvrProtocol\ConnInfoUpdate;
use NetsvrProtocol\SingleCastBulkByCustomerIdItem;
use NetsvrProtocol\SingleCastBulkItem;
use NetsvrProtocol\TopicPublishBulkItem;
use NetsvrBusiness\Container;
use NetsvrBusiness\Contract\TaskSocketInterface;
use NetsvrBusiness\Contract\TaskSocketMangerInterface;
use NetsvrBusiness\NetBus;
use NetsvrBusiness\TaskSocket;
use NetsvrBusiness\TaskSocketManger;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\NullLogger;
use Throwable;
use WebSocket\Client;
use WebSocket\Configuration;
use WebSocket\Exception\ConnectionTimeoutException;
use WebSocket\Middleware\CloseHandler;
use function NetsvrBusiness\milliSleep;
use function NetsvrBusiness\repeatedFieldToArray;

abstract class NetBusTestAbstract extends TestCase
{
    public const TASK_HEARTBEAT_MESSAGE = '~6YOt5rW35piO~';

    /**
     * 返回网关配置
     * @return array
     */
    abstract protected static function getNetsvrConfig(): array;

    /**
     * @var array | Client[]
     */
    protected static array $wsClients = [];
    /**
     * @var array
     */
    protected static array $wsClientUniqIds = [];

    /**
     * 每个网关的连接数量
     */
    const NETSVR_ONLINE_NUM = 3;

    /**
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        static::initNetBus();
    }

    protected static function initNetBus(): void
    {
        /**
         * @var $container ContainerInterface|Container
         */
        $container = Container::getInstance();
        $taskSocketManger = new TaskSocketManger();
        $logPrefix = sprintf('TaskSocket#%d', getmypid());
        foreach (static::getNetsvrConfig()['netsvr'] as $item) {
            try {
                $item = array_merge(static::getNetsvrConfig(), $item);
                $taskSocket = new TaskSocket(
                    $logPrefix,
                    new NullLogger(),
                    $item['addr'],
                    $item['sendReceiveTimeout'],
                    $item['connectTimeout'],
                    $item['maxIdleTime'],
                    $item['heartbeatMessage'],
                    $item['heartbeatIntervalMillisecond'],
                );
                $taskSocketManger->addSocket($taskSocket);
                $container->bind(TaskSocketInterface::class, $taskSocket);
            } catch (Throwable $throwable) {
                echo '连接到网关的task服务器失败：' . $throwable->getMessage() . PHP_EOL;
                exit(1);
            }
        }
        $container->bind(TaskSocketMangerInterface::class, $taskSocketManger);
    }

    /**
     * 向每一个网关都初始化一个websocket连接上去
     * @param float|int|null $timeout 连接的读超时（秒），null 表示用库的默认值。
     *        需要「断言收不到数据」的用例应传一个较小值：库只在建立连接时读取一次超时，
     *        连接建立后没有非弃用的接口可以修改它。
     * @return void
     */
    protected function resetWsClient(float|int|null $timeout = null): void
    {
        foreach (static::$wsClients as $client) {
            try {
                //发送关闭帧
                $client->close();
                //等待关闭
                $client->receive();
                //断开连接
                $client->disconnect();
            } catch (Throwable) {
            }
        }
        static::$wsClients = [];
        static::$wsClientUniqIds = [];
        //需要指定读超时时，用 Configuration 传入（Client 的 setTimeout 已弃用）
        $configuration = null;
        if ($timeout !== null) {
            $configuration = new Configuration();
            $configuration->setTimeout($timeout);
        }
        foreach (static::getNetsvrConfig()['netsvr'] as $config) {
            for ($i = 0; $i < static::NETSVR_ONLINE_NUM; $i++) {
                $client = $configuration === null ? new Client($config["ws"]) : new Client($config["ws"], $configuration);
                $client->addMiddleware(new CloseHandler());
                $uniqId = $client->receive()->getContent();
                static::$wsClientUniqIds[$config['addr']][] = $uniqId;
                static::$wsClients[$uniqId] = $client;
            }
        }
    }

    /**
     * @return array
     */
    protected function getDefaultUniqIds(): array
    {
        $ret = [];
        foreach (static::$wsClientUniqIds as $uniqIds) {
            array_push($ret, ...$uniqIds);
        }
        return $ret;
    }

    /**
     * @param string $addr
     * @return array
     */
    protected function getDefaultUniqIdsByAddr(string $addr): array
    {
        return static::$wsClientUniqIds[$addr] ?? [];
    }

    /**
     * 格式化异常
     * @param Throwable $exception
     * @return string
     */
    protected static function formatExp(Throwable $exception): string
    {
        $message = $exception->getMessage();
        $message = trim($message);
        if (strlen($message) == 0) {
            $message = get_class($exception);
        }
        return sprintf(
            "%d --> %s in %s on line %d",
            $exception->getCode(),
            $message,
            $exception->getFile(),
            $exception->getLine()
        );
    }

    /**
     * 给每个连接设置一下session、customerId、topic
     * @param array $uniqIds
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    protected function connInfoUpdate(array $uniqIds): void
    {
        //给每个连接设置一下session、customerId、topic
        foreach ($uniqIds as $uniqId) {
            $up = new ConnInfoUpdate();
            $up->setUniqId($uniqId);
            //将其session设置为uniqId，方便接下来的校验
            $up->setNewSession($uniqId . 'Session');
            //将其订阅设置为uniqId，方便接下来的校验
            $up->setNewTopics([$uniqId . 'Topic']);
            //将其customerId设置为uniqId，方便接下来的校验
            $up->setNewCustomerId($uniqId . 'CustomerId');
            NetBus::connInfoUpdate($up);
        }
    }

    /**
     * 按 uniqId 取测试连接
     * @param string $uniqId
     * @return Client
     */
    protected function clientOf(string $uniqId): Client
    {
        if (!isset(static::$wsClients[$uniqId])) {
            $this->fail("找不到 uniqId=$uniqId 的测试连接");
        }
        return static::$wsClients[$uniqId];
    }

    /**
     * 断言连接收不到数据：等待时长由连接建立时的读超时决定，
     * 因此做这种断言的用例要用 resetWsClient($timeout) 建一个较小读超时的连接
     * @param Client $client
     * @return void
     */
    protected function assertNoMessage(Client $client): void
    {
        $message = null;
        try {
            $message = $client->receive();
        } catch (ConnectionTimeoutException) {
            //预期：读超时，没有数据
        } catch (Throwable $throwable) {
            $this->fail('读取数据失败：' . $throwable->getMessage());
        }
        if ($message !== null) {
            $this->fail('连接不应收到数据，实际收到：' . $message->getContent());
        }
    }

    /**
     * 等待这些连接从网关下线；强制关闭是异步的，网关写完关闭帧后还要清理连接，因此必须轮询而不能固定 sleep
     * @param array $uniqIds
     * @param float $timeout 秒
     * @param string $message
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    protected function waitOffline(array $uniqIds, float $timeout = 3.0, string $message = '等待连接下线超时'): void
    {
        $deadline = microtime(true) + $timeout;
        while (true) {
            $online = NetBus::checkOnline($uniqIds)->getUniqIds();
            if (empty($online)) {
                return;
            }
            if (microtime(true) > $deadline) {
                $this->fail($message . '，仍在线：' . implode(',', $online));
            }
            milliSleep(20);
        }
    }

    /**
     * 给每个连接设置 customerId，用 uniqId 充当以保证唯一
     * @param array $uniqIds
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    protected function setUniqueCustomerId(array $uniqIds): void
    {
        foreach ($uniqIds as $uniqId) {
            $up = new ConnInfoUpdate();
            $up->setUniqId($uniqId);
            $up->setNewCustomerId($uniqId);
            NetBus::connInfoUpdate($up);
        }
    }

    /**
     * 让每个网关各挑一个连接共享同一个 customerId，返回共享的 customerId 与这些连接
     * @return array [customerId, uniqIds]
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    protected function shareCustomerId(): array
    {
        $sharedCustomerId = uniqid('sharedCustomerId');
        $sharedUniqIds = [];
        foreach (static::getNetsvrConfig()['netsvr'] as $config) {
            $addrUniqIds = $this->getDefaultUniqIdsByAddr($config['addr']);
            if (!isset($addrUniqIds[0])) {
                continue;
            }
            $sharedUniqIds[] = $addrUniqIds[0];
            $up = new ConnInfoUpdate();
            $up->setUniqId($addrUniqIds[0]);
            $up->setNewCustomerId($sharedCustomerId);
            NetBus::connInfoUpdate($up);
        }
        return [$sharedCustomerId, $sharedUniqIds];
    }

    /**
     * composer test -- --filter=testConnInfoUpdate
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public function testConnInfoUpdate(): void
    {
        //连接到网关
        $this->resetWsClient();
        $uniqIds = $this->getDefaultUniqIds();
        $this->connInfoUpdate($uniqIds);
        //检查连接的信息否设置成功
        $ret = NetBus::connInfo($uniqIds)->getItems();
        $this->assertCount(count($uniqIds), $ret, "返回的连接数量不符合预期");
        foreach ($ret as $uniqId => $value) {
            //校验session是否设置成功
            $this->assertEquals($uniqId . 'Session', $value->getSession());
            //检查主题是否设置成功
            $this->assertEquals($uniqId . 'Topic', $value->getTopics()->offsetGet(0));
            //检查customerId是否设置成功
            $this->assertEquals($uniqId . 'CustomerId', $value->getCustomerId());
        }
    }

    /**
     * composer test -- --filter=testConnInfoDelete
     * @return void
     * @throws ContainerExceptionInterface
     * @throws Throwable
     */
    public function testConnInfoDelete(): void
    {
        //连接到网关
        $this->resetWsClient();
        $uniqIds = $this->getDefaultUniqIds();
        $this->connInfoUpdate($uniqIds);
        //移除每个连接的信息
        foreach ($uniqIds as $uniqId) {
            $up = new ConnInfoDelete();
            $up->setUniqId($uniqId);
            $up->setDelSession(true);
            $up->setDelCustomerId(true);
            $up->setDelTopic(true);
            NetBus::connInfoDelete($up);
        }
        //检查连接的信息是否删除成功
        $ret = NetBus::connInfo($uniqIds)->getItems();
        $this->assertCount(count($uniqIds), $ret, "返回的连接数量不符合预期");
        foreach ($ret as $value) {
            $this->assertTrue('' === $value->getSession());
            $this->assertTrue('' === $value->getCustomerId());
            $this->assertEmpty($value->getTopics()->count());
        }
    }

    /**
     * composer test -- --filter=testBroadcast
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function testBroadcast(): void
    {
        //连接到网关
        $this->resetWsClient();
        $message = uniqid() . str_repeat('a', 10);
        NetBus::broadcast($message);
        foreach (static::$wsClients as $client) {
            //接收每个连接的数据，并判断是否与之前发送的一致
            $this->assertTrue($message === $client->receive()->getContent());
        }
        //批量广播，每个连接都会按顺序收到全部数据
        $dataList = [uniqid(), uniqid()];
        NetBus::broadcastBulk($dataList);
        foreach (static::$wsClients as $client) {
            foreach ($dataList as $datum) {
                $this->assertTrue($datum === $client->receive()->getContent(), "返回的批量广播消息不符合预期");
            }
        }
    }

    /**
     * composer test -- --filter=testSendToUniqIds
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function testSendToUniqIds(): void
    {
        //连接到网关
        $this->resetWsClient();
        $uniqIds = $this->getDefaultUniqIds();
        $message = uniqid() . str_repeat('a', 10);
        NetBus::sendToUniqIds($uniqIds, $message);
        foreach (static::$wsClients as $client) {
            //接收每个连接的数据，并判断是否与之前发送的一致
            $this->assertTrue($message === $client->receive()->getContent());
        }
    }

    /**
     * composer test -- --filter=testSendToCustomerIds
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function testSendToCustomerIds(): void
    {
        //连接到网关
        $this->resetWsClient();
        $uniqIds = $this->getDefaultUniqIds();
        //设置每个连接的customerId
        $customerIds = [];
        $customerIdIncrement = 0;
        foreach ($uniqIds as $uniqId) {
            $up = new ConnInfoUpdate();
            $up->setUniqId($uniqId);
            $customerIdIncrement++;
            $up->setNewCustomerId($customerIdIncrement);
            NetBus::connInfoUpdate($up);
            $customerIds[$uniqId] = $customerIdIncrement;
        }
        $message = uniqid() . str_repeat('a', 10);
        NetBus::sendToCustomerIds(array_values($customerIds), $message);
        foreach (static::$wsClients as $client) {
            //接收每个连接的数据，并判断是否与之前发送的一致
            $this->assertTrue($message === $client->receive()->getContent());
        }
    }

    /**
     * composer test -- --filter=testSendToUniqId
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function testSendToUniqId(): void
    {
        //连接到网关
        $this->resetWsClient();
        $uniqIds = $this->getDefaultUniqIds();
        $message = [];
        foreach ($uniqIds as $uniqId) {
            $message[$uniqId] = uniqid() . str_repeat('a', (int)(65536 * 3.5));
            //给每个连接单播数据过去
            NetBus::sendToUniqId($uniqId, $message[$uniqId]);
        }
        foreach (static::$wsClients as $uniqId => $client) {
            //接收每个连接的单播数据，并判断是否与之前发送的一致
            $this->assertTrue($message[$uniqId] === $client->receive()->getContent());
        }
    }

    /**
     * composer test -- --filter=testSendToCustomerId
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function testSendToCustomerId(): void
    {
        //连接到网关
        $this->resetWsClient();
        $uniqIds = $this->getDefaultUniqIds();
        //设置每个连接的customerId
        $customerIds = [];
        foreach ($uniqIds as $uniqId) {
            $up = new ConnInfoUpdate();
            $up->setUniqId($uniqId);
            $customerId = $uniqId . 'CustomerId';
            $up->setNewCustomerId($customerId);
            NetBus::connInfoUpdate($up);
            $customerIds[$uniqId] = $customerId;
        }
        //给每个customerId发送数据
        $message = [];
        foreach ($customerIds as $uniqId => $customerId) {
            //记录每个uniqId的数据
            $message[$uniqId] = uniqid() . str_repeat('a', (int)(65536 * 3.5));
            //给每个连接单播数据过去
            NetBus::sendToCustomerId($customerId, $message[$uniqId]);
        }
        //接收每个连接的单播数据
        foreach (static::$wsClients as $uniqId => $client) {
            //接收每个连接的单播数据，并判断是否与之前发送的一致
            $this->assertTrue($message[$uniqId] === $client->receive()->getContent());
        }
    }

    /**
     * composer test -- --filter=testSingleCastBulk
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function testSingleCastBulk(): void
    {
        //连接到网关
        $this->resetWsClient();
        $uniqIds = $this->getDefaultUniqIds();
        //每一项一个目标、一条数据，等价于给每个连接各发一条不同的数据
        $items = [];
        $validData = [];
        foreach ($uniqIds as $uniqId) {
            $datum = uniqid();
            $validData[$uniqId][] = $datum;
            $items[] = (new SingleCastBulkItem())->setUniqIds([$uniqId])->setData([$datum]);
        }
        NetBus::singleCastBulk($items);
        foreach (static::$wsClients as $uniqId => $client) {
            foreach ($validData[$uniqId] as $datum) {
                $this->assertTrue($datum === $client->receive()->getContent(), "返回的批量单播消息不符合预期");
            }
        }
        //一项内多个目标共享同一条数据
        $datum = uniqid();
        $items = [(new SingleCastBulkItem())->setUniqIds($uniqIds)->setData([$datum])];
        NetBus::singleCastBulk($items);
        foreach (static::$wsClients as $client) {
            $this->assertTrue($datum === $client->receive()->getContent(), "返回的批量单播消息不符合预期");
        }
        //一项内一个目标接收多条数据
        $targetUniqId = $uniqIds[0];
        $dataList = [uniqid(), uniqid()];
        $items = [(new SingleCastBulkItem())->setUniqIds([$targetUniqId])->setData($dataList)];
        NetBus::singleCastBulk($items);
        foreach (static::$wsClients as $uniqId => $client) {
            if ($uniqId !== $targetUniqId) {
                continue;
            }
            foreach ($dataList as $datum) {
                $this->assertTrue($datum === $client->receive()->getContent(), "返回的批量单播消息不符合预期");
            }
        }
        //一项内多个目标 × 多条数据：每个目标都会按顺序收到全部数据
        $dataList = [uniqid(), uniqid()];
        $items = [(new SingleCastBulkItem())->setUniqIds($uniqIds)->setData($dataList)];
        NetBus::singleCastBulk($items);
        foreach (static::$wsClients as $client) {
            foreach ($dataList as $datum) {
                $this->assertTrue($datum === $client->receive()->getContent(), "返回的批量单播消息不符合预期");
            }
        }
    }

    /**
     * composer test -- --filter=testSingleCastBulkByCustomerId
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function testSingleCastBulkByCustomerId(): void
    {
        //连接到网关
        $this->resetWsClient();
        $uniqIds = $this->getDefaultUniqIds();
        //设置每个连接的customerId
        $customerIds = [];
        $customerIdIncrement = 0;
        foreach ($uniqIds as $uniqId) {
            $up = new ConnInfoUpdate();
            $up->setUniqId($uniqId);
            $customerIdIncrement++;
            $up->setNewCustomerId($customerIdIncrement);
            NetBus::connInfoUpdate($up);
            $customerIds[$uniqId] = (string)$customerIdIncrement;
        }
        //每一项一个客户、一条数据，等价于给每个客户各发一条不同的数据
        $items = [];
        $validData = [];
        foreach ($customerIds as $uniqId => $customerId) {
            $datum = uniqid();
            $validData[$uniqId][] = $datum;
            $items[] = (new SingleCastBulkByCustomerIdItem())->setCustomerIds([$customerId])->setData([$datum]);
        }
        NetBus::singleCastBulkByCustomerId($items);
        foreach (static::$wsClients as $uniqId => $client) {
            foreach ($validData[$uniqId] as $datum) {
                $this->assertTrue($datum === $client->receive()->getContent(), "返回的批量单播消息不符合预期");
            }
        }
        //一项内多个客户共享同一条数据
        $datum = uniqid();
        $items = [(new SingleCastBulkByCustomerIdItem())->setCustomerIds(array_values($customerIds))->setData([$datum])];
        NetBus::singleCastBulkByCustomerId($items);
        foreach (static::$wsClients as $client) {
            $this->assertTrue($datum === $client->receive()->getContent(), "返回的批量单播消息不符合预期");
        }
        //一项内一个客户接收多条数据
        $targetUniqId = array_key_last($customerIds);
        $dataList = [uniqid(), uniqid()];
        $items = [(new SingleCastBulkByCustomerIdItem())->setCustomerIds([$customerIds[$targetUniqId]])->setData($dataList)];
        NetBus::singleCastBulkByCustomerId($items);
        foreach (static::$wsClients as $uniqId => $client) {
            if ($uniqId !== $targetUniqId) {
                continue;
            }
            foreach ($dataList as $datum) {
                $this->assertTrue($datum === $client->receive()->getContent(), "返回的批量单播消息不符合预期");
            }
        }
        //一项内多个客户 × 多条数据：每个客户都会按顺序收到全部数据
        $dataList = [uniqid(), uniqid()];
        $items = [(new SingleCastBulkByCustomerIdItem())->setCustomerIds(array_values($customerIds))->setData($dataList)];
        NetBus::singleCastBulkByCustomerId($items);
        foreach (static::$wsClients as $client) {
            foreach ($dataList as $datum) {
                $this->assertTrue($datum === $client->receive()->getContent(), "返回的批量单播消息不符合预期");
            }
        }
    }

    /**
     * composer test -- --filter=testTopicSubscribe
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public function testTopicSubscribe()
    {
        //连接到网关
        $this->resetWsClient();
        //先订阅
        $uniqIds = $this->getDefaultUniqIds();
        $topics = array(uniqid(), uniqid());
        foreach ($uniqIds as $uniqId) {
            //每个连接都订阅两个主题
            NetBus::topicSubscribe($uniqId, $topics);
        }
        //返回连接的信息
        $ret = NetBus::connInfo($uniqIds)->toArray();
        $this->assertTrue(count($ret) == count($uniqIds), "返回的连接数量不符合预期");
        //判断每个连接订阅的主题是否正确
        sort($topics);
        foreach ($ret as $value) {
            sort($value['topics']);
            $this->assertEquals($value['topics'], $topics, "返回的连接的主题不符合预期");
        }
    }

    /**
     * composer test -- --filter=testTopicUnsubscribe
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public function testTopicUnsubscribe()
    {
        //连接到网关
        $this->resetWsClient();
        //先订阅
        $uniqIds = $this->getDefaultUniqIds();
        $topics = array(uniqid(), uniqid());
        foreach ($uniqIds as $uniqId) {
            //每个连接都订阅两个主题
            NetBus::topicSubscribe($uniqId, $topics);
        }
        //返回连接的信息
        $ret = NetBus::connInfo($uniqIds)->toArray();
        $this->assertTrue(count($ret) == count($uniqIds), "返回的连接数量不符合预期");
        //判断每个连接订阅的主题是否正确
        sort($topics);
        foreach ($ret as $value) {
            sort($value['topics']);
            $this->assertTrue($value['topics'] == $topics, "返回的连接的主题不符合预期");
        }
        //再取消订阅
        foreach ($uniqIds as $uniqId) {
            //每个连接都取消订阅之前订阅的两个主题
            NetBus::topicUnsubscribe($uniqId, $topics);
        }
        //返回连接的信息
        $ret = NetBus::connInfo($uniqIds)->toArray();
        $this->assertTrue(count($ret) == count($uniqIds), "返回的连接数量不符合预期");
        //判断每个连接是否取消订阅的主题成功
        foreach ($ret as $value) {
            $this->assertEmpty($value['topics'], "返回的连接的主题不符合预期");
        }
    }

    /**
     * composer test -- --filter=testTopicDelete
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public function testTopicDelete()
    {
        //连接到网关
        $this->resetWsClient();
        //先订阅
        $uniqIds = $this->getDefaultUniqIds();
        $topics = array(uniqid(), uniqid());
        foreach ($uniqIds as $uniqId) {
            //每个连接都订阅两个主题
            NetBus::topicSubscribe($uniqId, $topics);
        }
        //返回连接的信息
        $ret = NetBus::connInfo($uniqIds)->toArray();
        $this->assertTrue(count($ret) == count($uniqIds), "返回的连接数量不符合预期");
        //判断每个连接订阅的主题是否正确
        sort($topics);
        foreach ($ret as $value) {
            sort($value['topics']);
            $this->assertTrue($value['topics'] == $topics, "返回的连接的主题不符合预期");
        }
        //删除主题
        NetBus::topicDelete($topics);
        //返回连接的信息
        $ret = NetBus::connInfo($uniqIds)->toArray();
        $this->assertTrue(count($ret) == count($uniqIds), "返回的连接数量不符合预期");
        //判断每个连接订阅的主题是否被删除，因为删除主题的时候会删除主题关联的连接里面存储的主题信息
        foreach ($ret as $value) {
            $this->assertEmpty($value['topics'], "返回的连接的主题不符合预期");
        }
    }

    /**
     * composer test -- --filter=testPublishToTopics
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     * @throws ContainerExceptionInterface
     */
    public function testPublishToTopics()
    {
        //连接到网关
        $this->resetWsClient();
        //先订阅
        $uniqIds = $this->getDefaultUniqIds();
        $topics = array(uniqid(), uniqid());
        foreach ($uniqIds as $uniqId) {
            //每个连接都订阅两个主题
            NetBus::topicSubscribe($uniqId, $topics);
        }
        //待发布的内容
        $publish = uniqid();
        //同时向两个主题发送相同的消息
        NetBus::publishToTopics($topics, $publish);
        foreach (static::$wsClients as $client) {
            //每个连接都必须接收到主题次数的消息数量
            $error = null;
            for ($i = 0; $i < count($topics); $i++) {
                try {
                    $this->assertTrue($client->receive()->getContent() === $publish, "返回的发布消息不符合预期");
                } catch (Throwable $throwable) {
                    $error = self::formatExp($throwable);
                }
            }
            $this->assertNull($error, "接收的发布消息数量不符合预期: $error");
        }
    }

    /**
     * composer test -- --filter=testPublishToTopic
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     * @throws ContainerExceptionInterface
     */
    public function testPublishToTopic()
    {
        //连接到网关
        $this->resetWsClient();
        //先订阅
        $uniqIds = $this->getDefaultUniqIds();
        $topic = uniqid('topic');
        foreach ($uniqIds as $uniqId) {
            //每个连接都订阅该主题
            NetBus::topicSubscribe($uniqId, [$topic]);
        }
        //向该主题发布一条数据
        $publish = uniqid('data');
        NetBus::publishToTopic($topic, $publish);
        foreach (static::$wsClients as $client) {
            $this->assertTrue($publish === $client->receive()->getContent(), "返回的发布消息不符合预期");
        }
    }

    /**
     * composer test -- --filter=testTopicPublishBulk
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     * @throws ContainerExceptionInterface
     */
    public function testTopicPublishBulk()
    {
        //连接到网关
        $this->resetWsClient();
        //先订阅
        $uniqIds = $this->getDefaultUniqIds();
        $topics = array(uniqid('topic'), uniqid('topic'));
        foreach ($uniqIds as $uniqId) {
            //每个连接都订阅两个主题
            NetBus::topicSubscribe($uniqId, $topics);
        }
        //每一项一个主题、一条数据
        $items = [];
        $dataList = [];
        foreach ($topics as $topic) {
            $publish = uniqid('data');
            $dataList[] = $publish;
            $items[] = (new TopicPublishBulkItem())->setTopics([$topic])->setData([$publish]);
        }
        //发送到网关
        NetBus::topicPublishBulk($items);
        foreach (static::$wsClients as $client) {
            //每个连接都必须接收到主题次数的消息数量
            $error = null;
            foreach ($dataList as $publish) {
                try {
                    $this->assertTrue($client->receive()->getContent() === $publish, "返回的批量发布消息不符合预期");
                } catch (Throwable $throwable) {
                    $error = self::formatExp($throwable);
                }
            }
            $this->assertNull($error, "接收的批量发布消息数量不符合预期: $error");
        }
        //一项内一个主题接收多条数据
        $dataList = [uniqid('data'), uniqid('data')];
        $items = [(new TopicPublishBulkItem())->setTopics([$topics[0]])->setData($dataList)];
        NetBus::topicPublishBulk($items);
        foreach (static::$wsClients as $client) {
            $error = null;
            foreach ($dataList as $publish) {
                try {
                    $this->assertTrue($client->receive()->getContent() === $publish, "返回的批量发布消息不符合预期");
                } catch (Throwable $throwable) {
                    $error = self::formatExp($throwable);
                }
            }
            $this->assertNull($error, "接收的批量发布消息数量不符合预期: $error");
        }
    }

    /**
     * composer test -- --filter=testForceOfflineByUniqId
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     * @throws ContainerExceptionInterface
     * @throws ErrorException
     */
    public function testForceOfflineByUniqId()
    {
        //连接到网关
        $this->resetWsClient();
        //强制下线连接
        $uniqIds = $this->getDefaultUniqIds();
        NetBus::forceOffline($uniqIds);
        //服务端写入了关闭帧
        foreach (static::$wsClients as $client) {
            $msg = $client->receive();
            $this->assertEquals('close', $msg->getOpcode());
            $payload = $msg->getPayload();
            //解码status
            $status = unpack('n', substr($payload, 0, 2));
            $this->assertEquals(1008, $status[1]);
        }
        //等待网关执行完连接的关闭逻辑（清理连接是异步的，用轮询等待）
        $this->waitOffline($uniqIds, 3.0, '强制关闭某几个连接的结果与预期不符');
    }

    /**
     * composer test -- --filter=testForceOfflineByCustomerId
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     * @throws ContainerExceptionInterface
     * @throws ErrorException
     */
    public function testForceOfflineByCustomerId()
    {
        //连接到网关
        $this->resetWsClient();
        $uniqIds = $this->getDefaultUniqIds();
        //设置每个连接的customerId
        $customerIds = [];
        $customerIdIncrement = 0;
        foreach ($uniqIds as $uniqId) {
            $up = new ConnInfoUpdate();
            $up->setUniqId($uniqId);
            $customerIdIncrement++;
            $up->setNewCustomerId($customerIdIncrement);
            NetBus::connInfoUpdate($up);
            $customerIds[$uniqId] = $customerIdIncrement;
        }
        NetBus::forceOfflineByCustomerId(array_values($customerIds));
        //服务端写入了关闭帧
        foreach (static::$wsClients as $client) {
            $msg = $client->receive();
            $this->assertEquals('close', $msg->getOpcode());
            $payload = $msg->getPayload();
            //解码status
            $status = unpack('n', substr($payload, 0, 2));
            $this->assertEquals(1008, $status[1]);
        }
        //等待网关执行完连接的关闭逻辑（清理连接是异步的，用轮询等待）
        $this->waitOffline($uniqIds, 3.0, '强制关闭某几个customerId的结果与预期不符');
    }

    /**
     * composer test -- --filter=testForceOfflineGuest
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     * @throws ContainerExceptionInterface
     * @throws ErrorException
     */
    public function testForceOfflineGuest()
    {
        //先测试关闭成功的情况
        //连接到网关
        $this->resetWsClient();
        //强制下线连接
        $uniqIds = $this->getDefaultUniqIds();
        NetBus::forceOfflineGuest($uniqIds);
        //服务端写入了关闭帧
        foreach (static::$wsClients as $client) {
            $msg = $client->receive();
            $this->assertEquals('close', $msg->getOpcode());
            $payload = $msg->getPayload();
            //解码status
            $status = unpack('n', substr($payload, 0, 2));
            $this->assertEquals(1008, $status[1]);
        }
        //等待网关执行完连接的关闭逻辑（清理连接是异步的，用轮询等待）
        $this->waitOffline($uniqIds, 3.0, '强制关闭某几个空session值的连接的结果与预期不符');
        //再测试因为存在session值而关闭失败的情况
        $this->resetWsClient();
        $uniqIds = $this->getDefaultUniqIds();
        //给每个连接设置一下session
        foreach ($uniqIds as $uniqId) {
            $up = new ConnInfoUpdate();
            $up->setUniqId($uniqId);
            $up->setNewSession($uniqId);
            NetBus::connInfoUpdate($up);
        }
        //再强制下线
        NetBus::forceOfflineGuest($uniqIds);
        //因为有 session 的存在，这些连接不会被下线（不需要等待：网关本就不该关闭它们）
        $ret = NetBus::checkOnline($uniqIds)->getUniqIds();
        sort($uniqIds);
        sort($ret);
        $this->assertTrue($ret === $uniqIds, "返回的uniqId不符合预期");
    }

    /**
     * composer test -- --filter=testCheckOnline
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     * @throws ContainerExceptionInterface
     * @throws ErrorException
     */
    public function testCheckOnline()
    {
        //连接到网关
        $this->resetWsClient();
        //获取网关的连接
        $uniqIds = $this->getDefaultUniqIds();
        $ret = NetBus::checkOnline($uniqIds)->getUniqIds();
        sort($ret);
        sort($uniqIds);
        $this->assertTrue($ret === $uniqIds);
    }

    /**
     * composer test -- --filter=testUniqIdList
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     * @throws ContainerExceptionInterface
     */
    public function testUniqIdList()
    {
        //连接到网关
        $this->resetWsClient();
        //获取网关的连接
        $ret = NetBus::uniqIdList()->toArray();
        $this->assertSameSize(static::getNetsvrConfig()['netsvr'], $ret, "返回的网关行数不符合预期");
        foreach ($ret as $value) {
            $uniqIds = $this->getDefaultUniqIdsByAddr($value['addr']);
            sort($uniqIds);
            sort($value['uniqIds']);
            $this->assertEquals($uniqIds, $value['uniqIds'], "返回的连接不符合预期");
        }
    }

    /**
     * composer test -- --filter=testUniqIdCount
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     * @throws ContainerExceptionInterface
     * @throws ErrorException
     */
    public function testUniqIdCount()
    {
        //连接到网关
        $this->resetWsClient();
        //获取网关的连接数量
        $ret = NetBus::uniqIdCount();
        $this->assertEquals(count($this->getDefaultUniqIds()), $ret->getCount(), "返回的连接数量不符合预期");
        foreach ($ret->toArray() as $value) {
            $expected = count($this->getDefaultUniqIdsByAddr($value['addr']));
            $this->assertEquals($expected, $value['count'], "返回的连接数量不符合预期");
        }
    }

    /**
     * composer test -- --filter=testTopicCount
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     * @throws ContainerExceptionInterface
     * @throws ErrorException
     */
    public function testTopicCount()
    {
        //连接到网关
        $this->resetWsClient();
        $topics = [uniqid(), uniqid(), uniqid(), uniqid()];
        $uniqIds = $this->getDefaultUniqIds();
        //每个连接都订阅两个主题
        foreach ($uniqIds as $uniqId) {
            NetBus::topicSubscribe($uniqId, $topics);
        }
        //获取网关的主题数量
        $topicCountRet = NetBus::topicCount();
        foreach ($topicCountRet->toArray() as $value) {
            $this->assertTrue(count($topics) == $value['count'], "返回的topic数量不符合预期");
        }
        //多网关部署时，不同网关之间的同名主题会被重复统计，所以总数是：主题数 × 网关数
        $this->assertEquals(count($topics) * count(static::getNetsvrConfig()['netsvr']), $topicCountRet->getCount(), "TopicCountRet::getCount 不符合预期");
    }

    /**
     * composer test -- --filter=testTopicList
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     * @throws ContainerExceptionInterface
     * @throws ErrorException
     */
    public function testTopicList()
    {
        //连接到网关
        $this->resetWsClient();
        //订阅的总主题数量，之所以是这个主题数量，是因为我想顺手测试一下底层的socket对象在读取网关发来的超过65536大小的数据时是否正确
        $totalTopic = (int)(65536 * 3.5 / 13);
        $topics = [];
        for ($i = 0; $i < $totalTopic; $i++) {
            $topics[] = uniqid();
        }
        $uniqIds = $this->getDefaultUniqIds();
        //每个连接都订阅一波主题
        foreach ($uniqIds as $uniqId) {
            NetBus::topicSubscribe($uniqId, $topics);
        }
        //获取网关的主题列表
        $ret = NetBus::topicList()->toArray();
        $this->assertSameSize(static::getNetsvrConfig()['netsvr'], $ret, "返回的网关行数不符合预期");
        sort($topics);
        foreach ($ret as $value) {
            sort($value['topics']);
            $this->assertEquals($topics, $value['topics'], "返回的topics不符合预期");
        }
    }

    /**
     * composer test -- --filter=testTopicUniqIdList
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     * @throws ContainerExceptionInterface
     * @throws ErrorException
     */
    public function testTopicUniqIdList()
    {
        //连接到网关
        $this->resetWsClient();
        $topics = [uniqid(), uniqid(), uniqid(), uniqid()];
        $uniqIds = $this->getDefaultUniqIds();
        //每个连接都订阅全部主题
        foreach ($uniqIds as $uniqId) {
            NetBus::topicSubscribe($uniqId, $topics);
        }
        //获取每个主题的连接
        $ret = NetBus::topicUniqIdList($topics)->toArray();
        $this->assertCount(count($topics) * count(static::getNetsvrConfig()['netsvr']), $ret, "返回的「网关×主题」行数不符合预期");
        foreach ($ret as $value) {
            $uniqIds = $this->getDefaultUniqIdsByAddr($value['addr']);
            sort($uniqIds);
            $this->assertTrue(in_array($value['topic'], $topics), "返回的topic不符合预期");
            sort($value['uniqIds']);
            $this->assertEquals($uniqIds, $value['uniqIds'], "topic的uniqId不符合预期");
        }
    }

    /**
     * composer test -- --filter=testTopicUniqIdCount
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     * @throws ContainerExceptionInterface
     * @throws ErrorException
     */
    public function testTopicUniqIdCount()
    {
        //连接到网关
        $this->resetWsClient();
        $topics = [uniqid(), uniqid(), uniqid(), uniqid()];
        $uniqIds = $this->getDefaultUniqIds();
        //每个连接都订阅两个主题
        foreach ($uniqIds as $uniqId) {
            NetBus::topicSubscribe($uniqId, $topics);
        }
        //获取每个主题的连接数量
        $ret = NetBus::topicUniqIdCount($topics)->toArray();
        $this->assertCount(count($topics) * count(static::getNetsvrConfig()['netsvr']), $ret, "返回的「网关×主题」行数不符合预期");
        foreach ($ret as $value) {
            $uniqIds = $this->getDefaultUniqIdsByAddr($value['addr']);
            $this->assertTrue(in_array($value['topic'], $topics), "返回的topic不符合预期");
            $this->assertEquals(count($uniqIds), $value['count'], "topic的uniqId数量不符合预期");
        }
    }

    /**
     * composer test -- --filter=testTopicCustomerIdList
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     * @throws ContainerExceptionInterface
     * @throws ErrorException
     */
    public function testTopicCustomerIdList()
    {
        //连接到网关
        $this->resetWsClient();
        $uniqIds = $this->getDefaultUniqIds();
        $topics = [uniqid(), uniqid(), uniqid(), uniqid()];
        //每个连接都订阅主题、设定客户端id
        foreach ($uniqIds as $uniqId) {
            $up = new ConnInfoUpdate();
            $up->setUniqId($uniqId);
            $up->setNewTopics($topics);
            $up->setNewCustomerId($uniqId);
            NetBus::connInfoUpdate($up);
        }
        //获取主题的客户端id
        $ret = NetBus::topicCustomerIdList($topics)->toArray();
        $this->assertCount(count($topics) * count(static::getNetsvrConfig()['netsvr']), $ret, "返回的「网关×主题」行数不符合预期");
        foreach ($ret as $value) {
            $uniqIds = $this->getDefaultUniqIdsByAddr($value['addr']);
            sort($uniqIds);
            $this->assertTrue(in_array($value['topic'], $topics), "返回的topic不符合预期");
            sort($value['customerIds']);
            $this->assertEquals($uniqIds, $value['customerIds'], "topic的customerId不符合预期");
        }
    }

    /**
     * composer test -- --filter=testTopicCustomerIdToUniqIdsList
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     * @throws ContainerExceptionInterface
     * @throws ErrorException
     */
    public function testTopicCustomerIdToUniqIdsList()
    {
        //连接到网关
        $this->resetWsClient();
        $uniqIds = $this->getDefaultUniqIds();
        $uniqIdsArr = array_chunk($uniqIds, 2);
        $topics = [uniqid(), uniqid(), uniqid(), uniqid()];
        //每个连接都订阅主题、设定客户端id
        $validData = [];
        foreach ($uniqIdsArr as $k => $uniqIds) {
            //客户端id，被多个连接持有，相当于一个用户账号登录了两台设备
            $customerId = "customerId$k";
            sort($uniqIds);
            $validData[$customerId] = $uniqIds;
            foreach ($uniqIds as $uniqId) {
                $up = new ConnInfoUpdate();
                $up->setUniqId($uniqId);
                $up->setNewTopics($topics);
                $up->setNewCustomerId($customerId);
                NetBus::connInfoUpdate($up);
            }
        }
        //获取主题的客户端id以及其对应的uniqId列表
        $ret = NetBus::topicCustomerIdToUniqIdsList($topics)->toArray();
        $retTopic = array_unique(array_column($ret, 'topic'));
        sort($retTopic);
        sort($topics);
        $this->assertEquals($topics, $retTopic, "返回的topic不符合预期");
        $retData = [];
        foreach ($ret as $value) {
            $customerId = $value['customerId'];
            $uniqIds = $value['uniqIds'];
            if (isset($retData[$customerId])) {
                $retData[$customerId] = array_merge($retData[$customerId], $uniqIds);
            } else {
                $retData[$customerId] = $uniqIds;
            }
        }
        foreach ($validData as $customerId => $uniqIds) {
            $this->assertTrue(isset($retData[$customerId]), "返回的customerId不存在");
            $tmp = array_unique($retData[$customerId]);
            sort($tmp);
            $this->assertEquals($uniqIds, $tmp, "返回的uniqId列表不符合预期");
        }
    }

    /**
     * composer test -- --filter=testTopicCustomerIdCount
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     * @throws ContainerExceptionInterface
     * @throws ErrorException
     */
    public function testTopicCustomerIdCount()
    {
        //连接到网关
        $this->resetWsClient();
        $uniqIds = $this->getDefaultUniqIds();
        $topics = [uniqid(), uniqid(), uniqid(), uniqid()];
        //每个连接都订阅主题、设定客户端id
        foreach ($uniqIds as $uniqId) {
            $up = new ConnInfoUpdate();
            $up->setUniqId($uniqId);
            $up->setNewTopics($topics);
            $up->setNewCustomerId($uniqId);
            NetBus::connInfoUpdate($up);
        }
        //获取主题的客户端id
        $ret = NetBus::topicCustomerIdCount($topics)->toArray();
        $this->assertCount(count($topics) * count(static::getNetsvrConfig()['netsvr']), $ret, "返回的「网关×主题」行数不符合预期");
        foreach ($ret as $value) {
            $uniqIds = $this->getDefaultUniqIdsByAddr($value['addr']);
            sort($uniqIds);
            $this->assertTrue(in_array($value['topic'], $topics), "返回的topic不符合预期");
            $this->assertEquals(count($uniqIds), $value['count'], "topic的customerId不符合预期");
        }
    }

    /**
     * composer test -- --filter=testConnInfoGet
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     * @throws ContainerExceptionInterface
     */
    public function testConnInfoGet()
    {
        $this->resetWsClient();
        $uniqIds = $this->getDefaultUniqIds();
        //先测试没有数据的情况
        $ret = NetBus::connInfo($uniqIds)->getItems();
        $this->assertCount(count($uniqIds), $ret, "返回的连接数量不符合预期");
        foreach ($ret as $uniqId => $item) {
            $this->assertTrue(in_array($uniqId, $uniqIds), "网关返回的用户信息不符合预期");
            $this->assertEmpty($item->getCustomerId(), "网关返回的用户customerId不符合预期");
            $this->assertEmpty($item->getSession(), "网关返回的用户session不符合预期");
            $this->assertEmpty($item->getTopics()->count(), "网关返回的用户topics不符合预期");
        }
        //更新uniqId的数据
        foreach ($uniqIds as $uniqId) {
            $up = new ConnInfoUpdate();
            $up->setUniqId($uniqId);
            //将其session设置为uniqId，方便接下来的校验
            $up->setNewSession($uniqId . 'Session');
            //将其订阅设置为uniqId，方便接下来的校验
            $up->setNewTopics([$uniqId . 'Topic']);
            //将其customerId设置为uniqId，方便接下来的校验
            $up->setNewCustomerId($uniqId . 'CustomerId');
            NetBus::connInfoUpdate($up);
        }
        //测试有数据的情况下，获取全部数据
        $ret = NetBus::connInfo($uniqIds)->getItems();
        $this->assertCount(count($uniqIds), $ret, "返回的连接数量不符合预期");
        foreach ($ret as $uniqId => $item) {
            $this->assertTrue(in_array($uniqId, $uniqIds), "网关返回的用户信息不符合预期");
            $this->assertEquals($item->getCustomerId(), $uniqId . 'CustomerId', "网关返回的用户customerId不符合预期");
            $this->assertEquals($item->getSession(), $uniqId . 'Session', "网关返回的用户session不符合预期");
            $this->assertEquals($item->getTopics()->offsetGet(0), $uniqId . 'Topic', "网关返回的用户topics不符合预期");
        }
        //测试有数据的情况下，，只获取customerId
        $ret = NetBus::connInfo($uniqIds, false, true, false)->getItems();
        $this->assertCount(count($uniqIds), $ret, "返回的连接数量不符合预期");
        foreach ($ret as $uniqId => $item) {
            $this->assertEquals($item->getCustomerId(), $uniqId . 'CustomerId', "网关返回的用户customerId不符合预期");
            $this->assertEmpty($item->getSession(), "网关返回的用户session不符合预期");
            $this->assertEmpty(repeatedFieldToArray($item->getTopics()), "网关返回的用户topics不符合预期");
        }
        //测试有数据的情况下，只获取session
        $ret = NetBus::connInfo($uniqIds, true, false, false)->getItems();
        $this->assertCount(count($uniqIds), $ret, "返回的连接数量不符合预期");
        foreach ($ret as $uniqId => $item) {
            $this->assertEmpty($item->getCustomerId(), "网关返回的用户customerId不符合预期");
            $this->assertEquals($item->getSession(), $uniqId . 'Session', "网关返回的用户session不符合预期");
            $this->assertEmpty($item->getTopics()->count(), "网关返回的用户topics不符合预期");
        }
        //测试有数据的情况下，只获取topic
        $ret = NetBus::connInfo($uniqIds, false, false)->getItems();
        $this->assertCount(count($uniqIds), $ret, "返回的连接数量不符合预期");
        foreach ($ret as $uniqId => $item) {
            $this->assertEmpty($item->getCustomerId(), "网关返回的用户customerId不符合预期");
            $this->assertEmpty($item->getSession(), "网关返回的用户session不符合预期");
            $this->assertEquals($item->getTopics()->offsetGet(0), $uniqId . 'Topic', "网关返回的用户topics不符合预期");
        }
    }

    /**
     * composer test -- --filter=testConnInfoByCustomerId
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     * @throws ContainerExceptionInterface
     */
    public function testConnInfoByCustomerId()
    {
        $this->resetWsClient();
        $uniqIds = $this->getDefaultUniqIds();
        foreach ($uniqIds as $uniqId) {
            $up = new ConnInfoUpdate();
            $up->setUniqId($uniqId);
            $up->setNewCustomerId($uniqId); //用uniqId模拟客户端id，方便后续断言
            NetBus::connInfoUpdate($up);
        }
        $ret = NetBus::connInfoByCustomerId($uniqIds);
        $retUniqIds = $ret->getUniqIds();
        sort($retUniqIds);
        sort($uniqIds);
        //因为用uniqId模拟客户端id，所以断言uniqId
        $this->assertEquals($uniqIds, $retUniqIds, "网关返回的用户信息不符合预期");
        foreach ($ret->getItems() as $customerId => $items) {
            //因为一个客户id有可能对应多个uniqId，所以这里返回的是二维的结构
            foreach ($items as $item) {
                $this->assertEquals($customerId, $item->getUniqId(), "网关返回的用户信息不符合预期");
                $this->assertEmpty($item->getSession(), "网关返回的用户信息不符合预期");
                $this->assertEmpty(repeatedFieldToArray($item->getTopics()), "网关返回的用户信息不符合预期");
            }
        }
    }

    /**
     * composer test -- --filter=testMetrics
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public function testMetrics(): void
    {
        $ret = NetBus::metrics()->toArray();
        $serverIds = array_unique(array_column($ret, 'addr'));
        $configServerIds = array_column(static::getNetsvrConfig()['netsvr'], 'addr');
        sort($serverIds);
        sort($configServerIds);
        $this->assertTrue($configServerIds == $serverIds);
    }

    /**
     * composer test -- --filter=testLimit
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public function testLimit(): void
    {
        $config = NetBus::limit(null)->toArray();
        $this->assertSameSize(static::getNetsvrConfig()['netsvr'], $config, "返回的addr数量不符合预期");
        //不断言具体的限流值：协议里 0 表示不启用限流，具体值取决于网关的启动配置
        foreach ($config as $item) {
            $this->assertNotEmpty($item['addr'], "限流配置addr不能为空");
            //指定网关读取到的配置应与全量读取中该网关的配置一致
            $one = NetBus::limit(null, $item['addr'])->toArray();
            $this->assertCount(1, $one, "指定网关读取限流配置应只返回一条");
            $this->assertEquals($item['onMessage'], $one[0]['onMessage'], "指定网关与全量读取的 onMessage 不一致");
            $this->assertEquals($item['onOpen'], $one[0]['onOpen'], "指定网关与全量读取的 onOpen 不一致");
        }
    }

    /**
     * composer test -- --filter=testCustomerIdList
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     * @throws ContainerExceptionInterface
     */
    public function testCustomerIdList()
    {
        $this->resetWsClient();
        //获取网关中的客户端连接的客户端id
        $ret = NetBus::customerIdList()->toArray();
        $this->assertSameSize(static::getNetsvrConfig()['netsvr'], $ret, "返回的addr数量不符合预期");
        foreach ($ret as $value) {
            $this->assertEmpty($value['customerIds'], "返回的customerIds数量不符合预期");
        }
        //模拟客户端连接设置客户端id
        $uniqIds = $this->getDefaultUniqIds();
        $this->connInfoUpdate($uniqIds);
        //再次获取网关中的客户端连接的客户端id
        $ret = NetBus::customerIdList()->toArray();
        $this->assertSameSize(static::getNetsvrConfig()['netsvr'], $ret, "返回的addr数量不符合预期");
        foreach ($ret as $value) {
            $uniqIds = $this->getDefaultUniqIdsByAddr($value['addr']);
            $this->assertSameSize($uniqIds, $value['customerIds'], "返回的customerIds数量不符合预期");
        }
    }

    /**
     * composer test -- --filter=testCustomerIdCount
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     * @throws ContainerExceptionInterface
     * @throws ErrorException
     */
    public function testCustomerIdCount()
    {
        $this->resetWsClient();
        $uniqIds = $this->getDefaultUniqIds();
        $this->connInfoUpdate($uniqIds);
        $ret = NetBus::customerIdCount()->toArray();
        $this->assertSameSize(static::getNetsvrConfig()['netsvr'], $ret, "返回的addr数量不符合预期");
        foreach ($ret as $value) {
            $expected = count($this->getDefaultUniqIdsByAddr($value['addr']));
            $this->assertEquals($expected, $value['count'], "返回的customerId数量不符合预期");
        }
    }

    /**
     * 验证 uniqId 维度的 Ret 辅助方法：一个连接只属于一个网关，跨网关直接合并、不去重
     * composer test -- --filter=testRetForUniqIdDimension
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public function testRetForUniqIdDimension(): void
    {
        //连接到网关
        $this->resetWsClient();
        $gatewayNum = count(static::getNetsvrConfig()['netsvr']);
        $uniqIds = $this->getDefaultUniqIds();
        sort($uniqIds);
        //检查是否在线
        $checkOnlineRet = NetBus::checkOnline($uniqIds);
        $retUniqIds = $checkOnlineRet->getUniqIds();
        sort($retUniqIds);
        $this->assertEquals($uniqIds, $retUniqIds, "CheckOnlineRet::getUniqIds 不符合预期");
        $this->assertEquals(count($uniqIds), $checkOnlineRet->getLen(), "CheckOnlineRet::getLen 不符合预期");
        $this->assertTrue($checkOnlineRet->has($uniqIds[0]), "CheckOnlineRet::has 命中失败");
        $this->assertFalse($checkOnlineRet->has('notExistUniqId'), "CheckOnlineRet::has 未命中判断失败");
        $this->assertCount($gatewayNum, $checkOnlineRet->toArray(), "CheckOnlineRet::toArray 不符合预期");
        //获取网关中的全部连接
        $uniqIdListRet = NetBus::uniqIdList();
        $retUniqIds = $uniqIdListRet->getUniqIds();
        sort($retUniqIds);
        $this->assertEquals($uniqIds, $retUniqIds, "UniqIdListRet::getUniqIds 不符合预期");
        $this->assertEquals(count($uniqIds), $uniqIdListRet->getLen(), "UniqIdListRet::getLen 不符合预期");
        $this->assertTrue($uniqIdListRet->has($uniqIds[0]), "UniqIdListRet::has 命中失败");
        $this->assertFalse($uniqIdListRet->has('notExistUniqId'), "UniqIdListRet::has 未命中判断失败");
        //获取连接的详情
        $connInfoRet = NetBus::connInfo($uniqIds);
        $this->assertNull($connInfoRet->get('notExistUniqId'), "ConnInfoRet::get 未命中应返回 null");
        foreach ($uniqIds as $uniqId) {
            $item = $connInfoRet->get($uniqId);
            $this->assertNotNull($item, "ConnInfoRet::get 命中失败");
            $this->assertSame('', $item->getSession(), "ConnInfoRet::get 返回的 session 不符合预期");
        }
    }

    /**
     * 验证 customerId 维度的 Ret 辅助方法：同一个客户可能连接到多个网关，跨网关需要去重
     * composer test -- --filter=testRetForCustomerIdDimension
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public function testRetForCustomerIdDimension(): void
    {
        //连接到网关
        $this->resetWsClient();
        $uniqIds = $this->getDefaultUniqIds();
        //先给每个连接设置一个唯一的 customerId
        foreach ($uniqIds as $uniqId) {
            $up = new ConnInfoUpdate();
            $up->setUniqId($uniqId);
            $up->setNewCustomerId($uniqId . 'CustomerId');
            NetBus::connInfoUpdate($up);
        }
        //再让每个网关各有一个连接共享同一个 customerId，用于验证跨网关去重
        [$sharedCustomerId, $sharedUniqIds] = $this->shareCustomerId();
        //期望的客户列表：共享的客户只算一个，其余连接各自一个
        $expectCustomerIds = [$sharedCustomerId];
        foreach ($uniqIds as $uniqId) {
            if (in_array($uniqId, $sharedUniqIds, true)) {
                continue;
            }
            $expectCustomerIds[] = $uniqId . 'CustomerId';
        }
        sort($expectCustomerIds);
        $customerIdListRet = NetBus::customerIdList();
        $retCustomerIds = $customerIdListRet->getCustomerIds();
        sort($retCustomerIds);
        $this->assertEquals($expectCustomerIds, $retCustomerIds, "CustomerIdListRet::getCustomerIds 不符合预期（跨网关去重后应只算一个）");
        $this->assertEquals(count($expectCustomerIds), $customerIdListRet->getLen(), "CustomerIdListRet::getLen 不符合预期");
        $this->assertTrue($customerIdListRet->has($sharedCustomerId), "CustomerIdListRet::has 命中失败");
        $this->assertFalse($customerIdListRet->has('notExistCustomerId'), "CustomerIdListRet::has 未命中判断失败");
        //获取共享客户的全部连接，应该每个网关一条
        $connInfoByCustomerIdRet = NetBus::connInfoByCustomerId([$sharedCustomerId]);
        $items = $connInfoByCustomerIdRet->get($sharedCustomerId);
        $retUniqIds = array_map(function ($item) {
            return $item->getUniqId();
        }, $items);
        sort($retUniqIds);
        $expectUniqIds = $sharedUniqIds;
        sort($expectUniqIds);
        $this->assertEquals($expectUniqIds, $retUniqIds, "ConnInfoByCustomerIdRet::get 不符合预期");
        $this->assertSame([], $connInfoByCustomerIdRet->get('notExistCustomerId'), "ConnInfoByCustomerIdRet::get 未命中应返回空数组");
        //转为数组后，一行是一个连接
        $this->assertCount(count($expectUniqIds), $connInfoByCustomerIdRet->toArray(), "ConnInfoByCustomerIdRet::toArray 不符合预期");
    }

    /**
     * 验证 topic 维度的 Ret 辅助方法：同名主题可能分布在多个网关，跨网关需要去重
     * composer test -- --filter=testRetForTopicDimension
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public function testRetForTopicDimension(): void
    {
        //连接到网关
        $this->resetWsClient();
        $uniqIds = $this->getDefaultUniqIds();
        sort($uniqIds);
        $topics = [uniqid('topic'), uniqid('topic')];
        //每个连接都订阅全部主题，并用 uniqId 作为 customerId，方便断言
        foreach ($uniqIds as $uniqId) {
            $up = new ConnInfoUpdate();
            $up->setUniqId($uniqId);
            $up->setNewTopics($topics);
            $up->setNewCustomerId($uniqId);
            NetBus::connInfoUpdate($up);
        }
        //主题列表
        $topicListRet = NetBus::topicList();
        $retTopics = $topicListRet->getTopics();
        sort($retTopics);
        $expectTopics = $topics;
        sort($expectTopics);
        $this->assertEquals($expectTopics, $retTopics, "TopicListRet::getTopics 不符合预期");
        $this->assertTrue($topicListRet->has($topics[0]), "TopicListRet::has 命中失败");
        $this->assertFalse($topicListRet->has('notExistTopic'), "TopicListRet::has 未命中判断失败");
        //主题包含的连接
        $topicUniqIdListRet = NetBus::topicUniqIdList($topics);
        foreach ($topics as $topic) {
            $retUniqIds = $topicUniqIdListRet->getTopicUniqIds($topic);
            sort($retUniqIds);
            $this->assertEquals($uniqIds, $retUniqIds, "TopicUniqIdListRet::getTopicUniqIds($topic) 不符合预期");
        }
        $this->assertSame([], $topicUniqIdListRet->getTopicUniqIds('notExistTopic'), "TopicUniqIdListRet::getTopicUniqIds 主题不存在应返回空数组");
        $topicUniqIds = $topicUniqIdListRet->getUniqIds();
        $this->assertCount(count($topics), $topicUniqIds, "TopicUniqIdListRet::getUniqIds 不符合预期");
        foreach ($topics as $topic) {
            $retUniqIds = $topicUniqIds[$topic];
            sort($retUniqIds);
            $this->assertEquals($uniqIds, $retUniqIds, "TopicUniqIdListRet::getUniqIds[$topic] 不符合预期");
        }
        //主题包含的客户
        $topicCustomerIdListRet = NetBus::topicCustomerIdList($topics);
        $topicCustomerIdToUniqIdsListRet = NetBus::topicCustomerIdToUniqIdsList($topics);
        $topicCustomerIds = $topicCustomerIdListRet->getCustomerIds();
        foreach ($topics as $topic) {
            $retCustomerIds = $topicCustomerIdListRet->getTopicCustomerIds($topic);
            sort($retCustomerIds);
            $this->assertEquals($uniqIds, $retCustomerIds, "TopicCustomerIdListRet::getTopicCustomerIds($topic) 不符合预期");
            $retCustomerIds = $topicCustomerIds[$topic];
            sort($retCustomerIds);
            $this->assertEquals($uniqIds, $retCustomerIds, "TopicCustomerIdListRet::getCustomerIds[$topic] 不符合预期");
            //主题包含的客户，以及这些客户的全部连接
            $retCustomerIds = $topicCustomerIdToUniqIdsListRet->getTopicCustomerIds($topic);
            sort($retCustomerIds);
            $this->assertEquals($uniqIds, $retCustomerIds, "TopicCustomerIdToUniqIdsListRet::getTopicCustomerIds($topic) 不符合预期");
            foreach ($uniqIds as $uniqId) {
                //每个客户只有一个连接
                $this->assertEquals([$uniqId], $topicCustomerIdToUniqIdsListRet->getCustomerUniqIds($topic, $uniqId), "TopicCustomerIdToUniqIdsListRet::getCustomerUniqIds($topic, $uniqId) 不符合预期");
            }
        }
        $this->assertSame([], $topicCustomerIdListRet->getTopicCustomerIds('notExistTopic'), "TopicCustomerIdListRet::getTopicCustomerIds 主题不存在应返回空数组");
        $this->assertSame([], $topicCustomerIdToUniqIdsListRet->getTopicCustomerIds('notExistTopic'), "TopicCustomerIdToUniqIdsListRet::getTopicCustomerIds 主题不存在应返回空数组");
        $this->assertSame([], $topicCustomerIdToUniqIdsListRet->getCustomerUniqIds('notExistTopic', 'notExistCustomerId'), "TopicCustomerIdToUniqIdsListRet::getCustomerUniqIds 目标不存在应返回空数组");
    }

    /**
     * 目标不存在、目标为空、数据为空时网关会跳过，且不影响同项内的其它目标
     * composer test -- --filter=testSingleCastBulkSkipInvalidTarget
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public function testSingleCastBulkSkipInvalidTarget(): void
    {
        //连接到网关。本用例要用 assertNoMessage 断言「没收到数据」，读超时取小值以缩短等待
        $this->resetWsClient(0.5);
        $uniqIds = $this->getDefaultUniqIds();
        //uniqId 的前 12 个十六进制字符是网关地址，尾部换成不存在的自增 id，
        //构造一个「落在同一个网关、但连接不存在」的目标
        $notExistUniqId = substr($uniqIds[0], 0, 12) . 'ffffffffffffffff';
        $validFirst = $uniqIds[0];
        $validSecond = $uniqIds[1];
        $validThird = $uniqIds[2];
        $dataFirst = uniqid('skipInvalidTarget');
        $dataSecond = uniqid('skipInvalidTarget');
        NetBus::singleCastBulk([
            //不存在的目标与真实目标混在同一项：真实目标照常收到数据
            (new SingleCastBulkItem())->setUniqIds([$notExistUniqId, $validFirst])->setData([$dataFirst]),
            //同一项内混入空数据：空数据被跳过，真实数据照常投递
            (new SingleCastBulkItem())->setUniqIds([$validSecond])->setData([$dataSecond, '']),
            //目标为空：整项不处理
            (new SingleCastBulkItem())->setUniqIds([])->setData([uniqid('skipInvalidTarget')]),
            //数据为空：整项不处理
            (new SingleCastBulkItem())->setUniqIds([$validThird])->setData([]),
        ]);
        //有效的数据被投递
        $this->assertTrue($dataFirst === $this->clientOf($validFirst)->receive()->getContent(), "不存在的目标影响了同项内的真实目标");
        $this->assertTrue($dataSecond === $this->clientOf($validSecond)->receive()->getContent(), "空数据影响了同项内的真实数据");
        //上面的预期数据都已收到，此后所有连接都不应再有任何数据：
        //既证明空目标、空数据的项没有投递，也证明不存在的目标没有影响任何其它连接
        foreach (static::$wsClients as $client) {
            $this->assertNoMessage($client);
        }
    }

    /**
     * 客户端收到关闭帧后既不回关闭帧、也不断开连接，网关仍会在兜底时间到达后关闭连接
     * composer test -- --filter=testForceOfflineUncooperativeClient
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public function testForceOfflineUncooperativeClient(): void
    {
        //直接建立连接，不加 CloseHandler 中间件：收到关闭帧时不回关闭帧，也不主动断开 TCP
        $clients = [];
        $uniqIds = [];
        foreach (static::getNetsvrConfig()['netsvr'] as $config) {
            for ($i = 0; $i < static::NETSVR_ONLINE_NUM; $i++) {
                $client = new Client($config['ws']);
                $uniqIds[] = $client->receive()->getContent();
                $clients[] = $client;
            }
        }
        try {
            NetBus::forceOffline($uniqIds);
            foreach ($clients as $client) {
                $message = $client->receive();
                $this->assertEquals('close', $message->getOpcode(), '服务端没有写入关闭帧');
                $status = unpack('n', substr($message->getPayload(), 0, 2));
                $this->assertEquals(1008, $status[1], '关闭码不符合预期');
            }
            //兜底时间未到时连接仍应在网关的在线列表里：说明网关是在等兜底时间，而不是立刻关闭
            milliSleep(500);
            $this->assertNotEmpty(NetBus::checkOnline($uniqIds)->getUniqIds(), '写出关闭帧后不应立即关闭连接（网关应等 2 秒兜底后再强制关闭）');
            //兜底关闭到达后，连接会从网关的在线列表里消失
            $this->waitOffline($uniqIds, 5.0);
        } finally {
            foreach ($clients as $client) {
                try {
                    $client->close();
                    $client->disconnect();
                } catch (Throwable) {
                }
            }
        }
    }

    /**
     * 同一个客户连接到多个网关时，给该客户发数据，各网关上的连接都能收到
     * composer test -- --filter=testSendToSharedCustomerId
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public function testSendToSharedCustomerId(): void
    {
        //连接到网关。本用例要用 assertNoMessage 断言「没收到数据」，读超时取小值以缩短等待
        $this->resetWsClient(0.5);
        $uniqIds = $this->getDefaultUniqIds();
        $this->setUniqueCustomerId($uniqIds);
        [$sharedCustomerId, $sharedUniqIds] = $this->shareCustomerId();
        //组播：该客户的全部连接（含跨网关）都收到
        $message = uniqid('sendToSharedCustomerId');
        NetBus::sendToCustomerIds([$sharedCustomerId], $message);
        foreach ($sharedUniqIds as $uniqId) {
            $this->assertTrue($message === $this->clientOf($uniqId)->receive()->getContent(), "连接 $uniqId 收到的数据不符合预期");
        }
        //批量单播：同上
        $message = uniqid('singleCastBulkBySharedCustomerId');
        NetBus::singleCastBulkByCustomerId([
            (new SingleCastBulkByCustomerIdItem())->setCustomerIds([$sharedCustomerId])->setData([$message]),
        ]);
        foreach ($sharedUniqIds as $uniqId) {
            $this->assertTrue($message === $this->clientOf($uniqId)->receive()->getContent(), "连接 $uniqId 收到的数据不符合预期");
        }
        //其它客户不应收到数据
        foreach (static::$wsClients as $uniqId => $client) {
            if (in_array($uniqId, $sharedUniqIds, true)) {
                continue;
            }
            $this->assertNoMessage($client);
        }
    }
}
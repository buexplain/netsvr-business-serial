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
use Google\Protobuf\Internal\RepeatedField;
use Netsvr\ConnInfoDelete;
use Netsvr\ConnInfoUpdate;
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
use function NetsvrBusiness\milliSleep;

abstract class NetBusTestAbstract extends TestCase
{
    public const WORKER_HEARTBEAT_MESSAGE = '~6YOt5rW35piO~';

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
        foreach (static::getNetsvrConfig()['netsvr'] as $config) {
            try {
                $taskSocket = new TaskSocket(
                    $logPrefix,
                    new NullLogger(),
                    $config['workerAddr'],
                    static::getNetsvrConfig()['sendReceiveTimeout'],
                    static::getNetsvrConfig()['connectTimeout'],
                    $config['maxIdleTime']
                );
                $taskSocketManger->addSocket($taskSocket);
                $container->bind(TaskSocketInterface::class, $taskSocket);
            } catch (Throwable $throwable) {
                echo '连接到网关的worker服务器失败：' . $throwable->getMessage() . PHP_EOL;
                exit(1);
            }
        }
        $container->bind(TaskSocketMangerInterface::class, $taskSocketManger);
    }

    /**
     * 向每一个网关都初始化一个websocket连接上去
     * @return void
     */
    protected function resetWsClient(): void
    {
        foreach (static::$wsClients as $client) {
            try {
                $client->close();
                $client->disconnect();
                //等待关闭，否则测试用例跑不通过
                milliSleep(50);
            } catch (Throwable) {
            }
        }
        static::$wsClients = [];
        static::$wsClientUniqIds = [];
        foreach (static::getNetsvrConfig()['netsvr'] as $config) {
            for ($i = 0; $i < static::NETSVR_ONLINE_NUM; $i++) {
                $client = new Client($config["ws"]);
                $uniqId = $client->receive()->getContent();
                static::$wsClientUniqIds[$config['workerAddr']][] = $uniqId;
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
     * @param string $workerAddr
     * @return array
     */
    protected function getDefaultUniqIdsByWorkerAddr(string $workerAddr): array
    {
        return static::$wsClientUniqIds[$workerAddr] ?? [];
    }

    protected static function repeatedFieldToArray(RepeatedField $repeatedField): array
    {
        $ret = [];
        foreach ($repeatedField as $item) {
            $ret[] = $item;
        }
        return $ret;
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
        $ret = NetBus::connInfo($uniqIds);
        foreach ($ret as $uniqId => $value) {
            //校验session是否设置成功
            $this->assertEquals($uniqId . 'Session', $value['session']);
            //检查主题是否设置成功
            $this->assertEquals($uniqId . 'Topic', $value['topics'][0]);
            //检查customerId是否设置成功
            $this->assertEquals($uniqId . 'CustomerId', $value['customerId']);
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
        $ret = NetBus::connInfo($uniqIds);
        foreach ($ret as $value) {
            $this->assertTrue('' === $value['session']);
            $this->assertTrue('' === $value['customerId']);
            $this->assertEmpty($value['topics']);
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
        $message = uniqid() . str_repeat('a', (int)(65536 * 3.5)); //这个数字随便定义的
        NetBus::broadcast($message);
        foreach (static::$wsClients as $client) {
            //接收每个连接的数据，并判断是否与之前发送的一致
            $this->assertTrue($message === $client->receive()->getContent());
        }
    }

    /**
     * composer test -- --filter=testMulticastByUniqId
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function testMulticastByUniqId(): void
    {
        //连接到网关
        $this->resetWsClient();
        $uniqIds = $this->getDefaultUniqIds();
        $message = uniqid() . str_repeat('a', (int)(65536 * 3.5));
        NetBus::multicast($uniqIds, $message);
        foreach (static::$wsClients as $client) {
            //接收每个连接的数据，并判断是否与之前发送的一致
            $this->assertTrue($message === $client->receive()->getContent());
        }
    }

    /**
     * composer test -- --filter=testMulticastByCustomerId
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function testMulticastByCustomerId(): void
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
        $message = uniqid() . str_repeat('a', (int)(65536 * 3.5));
        NetBus::multicastByCustomerId($customerIds, $message);
        foreach (static::$wsClients as $client) {
            //接收每个连接的数据，并判断是否与之前发送的一致
            $this->assertTrue($message === $client->receive()->getContent());
        }
    }

    /**
     * composer test -- --filter=testSingleCastByUniqId
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function testSingleCastByUniqId(): void
    {
        //连接到网关
        $this->resetWsClient();
        $uniqIds = $this->getDefaultUniqIds();
        $message = [];
        foreach ($uniqIds as $uniqId) {
            $message[$uniqId] = uniqid() . str_repeat('a', (int)(65536 * 3.5));
            //给每个连接单播数据过去
            NetBus::singleCast($uniqId, $message[$uniqId]);
        }
        foreach (static::$wsClients as $uniqId => $client) {
            //接收每个连接的单播数据，并判断是否与之前发送的一致
            $this->assertTrue($message[$uniqId] === $client->receive()->getContent());
        }
    }

    /**
     * composer test -- --filter=testSingleCastByCustomerId
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function testSingleCastByCustomerId(): void
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
            NetBus::singleCastByCustomerId($customerId, $message[$uniqId]);
        }
        //接收每个连接的单播数据
        foreach (static::$wsClients as $uniqId => $client) {
            //接收每个连接的单播数据，并判断是否与之前发送的一致
            $this->assertTrue($message[$uniqId] === $client->receive()->getContent());
        }
    }

    /**
     * composer test -- --filter=testSingleCastBulkByUniqId
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function testSingleCastBulkByUniqId(): void
    {
        //连接到网关
        $this->resetWsClient();
        $uniqIds = $this->getDefaultUniqIds();
        //测试这种入参结构：['目标uniqId1'=>'数据1', '目标uniqId2'=>'数据2']
        $params = [];
        foreach ($uniqIds as $uniqId) {
            $params[$uniqId] = uniqid();
        }
        //将数据批量的单播出去
        NetBus::singleCastBulk($params);
        //接收每个连接的数据，并判断是否为刚刚批量单播出去的数据
        foreach (static::$wsClients as $uniqId => $client) {
            $this->assertTrue($params[$uniqId] === $client->receive()->getContent());
        }
        //测试这种入参结构：['目标uniqId1'=>'数据1']
        $params = [];
        foreach ($uniqIds as $uniqId) {
            $params[$uniqId] = uniqid();
            break;
        }
        //将数据批量的单播出去
        NetBus::singleCastBulk($params);
        //接收每个连接的数据，并判断是否为刚刚批量单播出去的数据
        foreach (static::$wsClients as $uniqId => $client) {
            if (!isset($params[$uniqId])) {
                break;
            }
            $this->assertTrue($params[$uniqId] === $client->receive()->getContent());
        }
        //测试这种入参结构：['uniqIds'=>['目标uniqId1', '目标uniqId2'], 'data'=>['数据1', '数据2']]
        $params = [];
        foreach ($uniqIds as $uniqId) {
            $params['uniqIds'][] = $uniqId;
            $params['data'][] = uniqid();
        }
        //将数据批量的单播出去
        NetBus::singleCastBulk($params);
        //接收每个连接的数据，并判断是否为刚刚批量单播出去的数据
        foreach (static::$wsClients as $uniqId => $client) {
            $index = intval(array_search($uniqId, $params['uniqIds']));
            $this->assertTrue($params['data'][$index] === $client->receive()->getContent());
        }
        //测试这种入参结构：['uniqIds'=>'目标uniqId1', 'data'=>['数据1', '数据2']]
        $params = [
            'uniqIds' => $uniqIds[0],
            'data' => [uniqid(), uniqid()],
        ];
        //将数据批量的单播出去
        NetBus::singleCastBulk($params);
        //接收每个连接的数据，并判断是否为刚刚批量单播出去的数据
        foreach (static::$wsClients as $uniqId => $client) {
            if ($uniqId !== $params['uniqIds']) {
                continue;
            }
            foreach ($params['data'] as $datum) {
                $this->assertTrue($datum === $client->receive()->getContent());
            }
        }
        //测试这种入参结构：['uniqIds'=>['目标uniqId1'], 'data'=>['数据1', '数据2']]
        $params = [
            'uniqIds' => [$uniqIds[0]],
            'data' => [uniqid(), uniqid()],
        ];
        //将数据批量的单播出去
        NetBus::singleCastBulk($params);
        //接收每个连接的数据，并判断是否为刚刚批量单播出去的数据
        foreach (static::$wsClients as $uniqId => $client) {
            if ($uniqId !== $params['uniqIds'][0]) {
                continue;
            }
            foreach ($params['data'] as $datum) {
                $this->assertTrue($datum === $client->receive()->getContent());
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
            $customerIds[$uniqId] = $customerIdIncrement;
        }
        //测试这种入参结构：['目标customerId1'=>'数据1', '目标customerId2'=>'数据2']
        $params = [];
        foreach ($customerIds as $customerId) {
            $params[$customerId] = uniqid();
        }
        //将数据批量的单播出去
        NetBus::singleCastBulkByCustomerId($params);
        //接收每个连接的数据，并判断是否为刚刚批量单播出去的数据
        foreach (static::$wsClients as $uniqId => $client) {
            $customerId = $customerIds[$uniqId];
            $this->assertTrue($params[$customerId] === $client->receive()->getContent());
        }
        //测试这种入参结构：['目标customerId1'=>'数据1']
        $params = [];
        foreach ($customerIds as $customerId) {
            $params[$customerId] = uniqid();
            break;
        }
        //将数据批量的单播出去
        NetBus::singleCastBulkByCustomerId($params);
        //接收每个连接的数据，并判断是否为刚刚批量单播出去的数据
        foreach (static::$wsClients as $uniqId => $client) {
            $customerId = $customerIds[$uniqId];
            if (!isset($params[$customerId])) {
                break;
            }
            $this->assertTrue($params[$customerId] === $client->receive()->getContent());
        }
        //测试这种入参结构：['customerIds'=>['目标customerId1', '目标customerId2'], 'data'=>['数据1', '数据2']]
        $params = [];
        foreach ($customerIds as $customerId) {
            $params['customerIds'][] = $customerId;
            $params['data'][] = uniqid();
        }
        //将数据批量的单播出去
        NetBus::singleCastBulkByCustomerId($params);
        //接收每个连接的数据，并判断是否为刚刚批量单播出去的数据
        foreach (static::$wsClients as $uniqId => $client) {
            $customerId = $customerIds[$uniqId];
            $index = intval(array_search($customerId, $params['customerIds']));
            $this->assertTrue($params['data'][$index] === $client->receive()->getContent());
        }
        //测试这种入参结构：['customerIds'=>'目标customerId1', 'data'=>['数据1', '数据2']]
        $targetUniqId = array_key_last($customerIds);
        $params = [
            'customerIds' => $customerIds[$targetUniqId],
            'data' => [uniqid(), uniqid()],
        ];
        //将数据批量的单播出去
        NetBus::singleCastBulkByCustomerId($params);
        //接收每个连接的数据，并判断是否为刚刚批量单播出去的数据
        foreach (static::$wsClients as $uniqId => $client) {
            if ($uniqId !== $targetUniqId) {
                continue;
            }
            foreach ($params['data'] as $datum) {
                $this->assertTrue($datum === $client->receive()->getContent());
            }
        }
        //测试这种入参结构：['uniqIds'=>['目标uniqId1'], 'data'=>['数据1', '数据2']]
        $targetUniqId = array_key_last($customerIds);
        $params = [
            'customerIds' => [
                $customerIds[$targetUniqId],
            ],
            'data' => [uniqid(), uniqid()],
        ];
        //将数据批量的单播出去
        NetBus::singleCastBulkByCustomerId($params);
        //接收每个连接的数据，并判断是否为刚刚批量单播出去的数据
        foreach (static::$wsClients as $uniqId => $client) {
            if ($uniqId !== $targetUniqId) {
                continue;
            }
            foreach ($params['data'] as $datum) {
                $this->assertTrue($datum === $client->receive()->getContent());
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
        $ret = NetBus::connInfo($uniqIds);
        $this->assertTrue(count($ret) == count($uniqIds), "返回的连接数量不符合预期");
        //判断每个连接订阅的主题是否正确
        sort($topics);
        foreach ($ret as $value) {
            sort($value['topics']);
            $this->assertTrue($value['topics'] == $topics, "返回的连接的主题不符合预期");
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
        $ret = NetBus::connInfo($uniqIds);
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
        $ret = NetBus::connInfo($uniqIds);
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
        $ret = NetBus::connInfo($uniqIds);
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
        $ret = NetBus::connInfo($uniqIds);
        $this->assertTrue(count($ret) == count($uniqIds), "返回的连接数量不符合预期");
        //判断每个连接订阅的主题是否被删除，因为删除主题的时候会删除主题关联的连接里面存储的主题信息
        foreach ($ret as $value) {
            $this->assertEmpty($value['topics'], "返回的连接的主题不符合预期");
        }
    }

    /**
     * composer test -- --filter=testTopicPublishGeneral
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     * @throws ContainerExceptionInterface
     */
    public function testTopicPublishGeneral()
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
        NetBus::topicPublish($topics, $publish);
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
        //测试一个topic对应一个内容的情况
        $params = [];
        foreach ($topics as $topic) {
            $params[$topic] = uniqid('data');
        }
        //发送到网关
        NetBus::topicPublishBulk($params);
        foreach (static::$wsClients as $client) {
            //每个连接都必须接收到主题次数的消息数量
            $error = null;
            foreach ($params as $publish) {
                try {
                    $this->assertTrue($client->receive()->getContent() === $publish, "返回的批量发布消息不符合预期");
                } catch (Throwable $throwable) {
                    $error = self::formatExp($throwable);
                }
            }
            $this->assertNull($error, "接收的批量发布消息数量不符合预期: $error");
        }
        //测试一个topic对应多个内容的情况
        $params = [
            'topics' => $topics[0],
            'data' => [uniqid('data'), uniqid('data')],
        ];
        NetBus::topicPublishBulk($params);
        foreach (static::$wsClients as $client) {
            $error = null;
            foreach ($params['data'] as $publish) {
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
        //等待网关执行完连接的关闭逻辑
        milliSleep(50);
        //检查是否在线
        $ret = NetBus::checkOnline($uniqIds);
        $this->assertEmpty($ret, '强制关闭某几个连接的结果与预期不符');
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
        NetBus::forceOfflineByCustomerId($customerIds);
        //等待网关执行完连接的关闭逻辑
        milliSleep(50);
        //检查是否在线
        $ret = NetBus::checkOnline($uniqIds);
        $this->assertEmpty($ret, '强制关闭某几个customerId的结果与预期不符');
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
        //等待网关执行完连接的关闭逻辑
        milliSleep(50);
        //检查是否在线
        $ret = NetBus::checkOnline($uniqIds);
        $this->assertEmpty($ret, '强制关闭某几个空session值的连接的结果与预期不符');
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
        //等待网关执行完连接的关闭逻辑
        milliSleep(50);
        //检查是否在线
        $ret = NetBus::checkOnline($uniqIds);
        sort($uniqIds);
        sort($ret);
        //因为有session的存在，所以不会被下线，反而依然在线
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
        $ret = NetBus::checkOnline($uniqIds);
        sort($ret);
        sort($uniqIds);
        $this->assertTrue($ret === $uniqIds);
    }

    /**
     * composer test -- --filter=testUniqIdList
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     * @throws ContainerExceptionInterface
     * @throws ErrorException
     */
    public function testUniqIdList()
    {
        //连接到网关
        $this->resetWsClient();
        //获取网关的连接
        $ret = NetBus::uniqIdList();
        foreach ($ret as $value) {
            $uniqIds = $this->getDefaultUniqIdsByWorkerAddr($value['workerAddr']);
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
        foreach ($ret as $value) {
            $expected = count($this->getDefaultUniqIdsByWorkerAddr($value['workerAddr']));
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
        $ret = NetBus::topicCount();
        foreach ($ret as $value) {
            $this->assertTrue(count($topics) == $value['count'], "返回的topic数量不符合预期");
        }
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
        $ret = NetBus::topicList();
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
        $ret = NetBus::topicUniqIdList($topics);
        foreach ($ret as $value) {
            $uniqIds = $this->getDefaultUniqIdsByWorkerAddr($value['workerAddr']);
            sort($uniqIds);
            foreach ($value['items'] as $item) {
                $this->assertTrue(in_array($item['topic'], $topics), "返回的topic不符合预期");
                sort($item['uniqIds']);
                $this->assertEquals($uniqIds, $item['uniqIds'], "topic的uniqId不符合预期");
            }
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
        $ret = NetBus::topicUniqIdCount($topics);
        foreach ($ret as $value) {
            $uniqIds = $this->getDefaultUniqIdsByWorkerAddr($value['workerAddr']);
            foreach ($value['items'] as $item) {
                $this->assertTrue(in_array($item['topic'], $topics), "返回的topic不符合预期");
                $this->assertEquals(count($uniqIds), $item['count'], "topic的uniqId数量不符合预期");
            }
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
        //每个连接都定义主题、设定客户端id
        foreach ($uniqIds as $uniqId) {
            $up = new ConnInfoUpdate();
            $up->setUniqId($uniqId);
            $up->setNewTopics($topics);
            $up->setNewCustomerId($uniqId);
            NetBus::connInfoUpdate($up);
        }
        //获取主题的客户端id
        $ret = NetBus::topicCustomerIdList($topics);
        foreach ($ret as $value) {
            $uniqIds = $this->getDefaultUniqIdsByWorkerAddr($value['workerAddr']);
            sort($uniqIds);
            foreach ($value['items'] as $item) {
                $this->assertTrue(in_array($item['topic'], $topics), "返回的topic不符合预期");
                sort($item['customerIds']);
                $this->assertEquals($uniqIds, $item['customerIds'], "topic的customerId不符合预期");
            }
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
        //每个连接都定义主题、设定客户端id
        foreach ($uniqIds as $uniqId) {
            $up = new ConnInfoUpdate();
            $up->setUniqId($uniqId);
            $up->setNewTopics($topics);
            $up->setNewCustomerId($uniqId);
            NetBus::connInfoUpdate($up);
        }
        //获取主题的客户端id
        $ret = NetBus::topicCustomerIdCount($topics);
        foreach ($ret as $value) {
            $uniqIds = $this->getDefaultUniqIdsByWorkerAddr($value['workerAddr']);
            foreach ($value['items'] as $item) {
                $this->assertTrue(in_array($item['topic'], $topics), "返回的topic不符合预期");
                $this->assertEquals(count($uniqIds), $item['count'], "topic的customerId数量不符合预期");
            }
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
        $ret = NetBus::connInfo($uniqIds);
        foreach ($ret as $uniqId => $item) {
            $this->assertTrue(in_array($uniqId, $uniqIds), "网关返回的用户信息不符合预期");
            $this->assertEmpty($item['customerId'], "网关返回的用户customerId不符合预期");
            $this->assertEmpty($item['session'], "网关返回的用户session不符合预期");
            $this->assertEmpty($item['topics'], "网关返回的用户topics不符合预期");
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
        $ret = NetBus::connInfo($uniqIds);
        foreach ($ret as $uniqId => $item) {
            $this->assertTrue(in_array($uniqId, $uniqIds), "网关返回的用户信息不符合预期");
            $this->assertEquals($item['customerId'], $uniqId . 'CustomerId', "网关返回的用户customerId不符合预期");
            $this->assertEquals($item['session'], $uniqId . 'Session', "网关返回的用户session不符合预期");
            $this->assertEquals($item['topics'][0], $uniqId . 'Topic', "网关返回的用户topics不符合预期");
        }
        //测试有数据的情况下，，只获取customerId
        $ret = NetBus::connInfo($uniqIds, true, false, false);
        foreach ($ret as $uniqId => $item) {
            $this->assertEquals($item['customerId'], $uniqId . 'CustomerId', "网关返回的用户customerId不符合预期");
            $this->assertEmpty($item['session'], "网关返回的用户session不符合预期");
            $this->assertEmpty($item['topics'], "网关返回的用户topics不符合预期");
        }
        //测试有数据的情况下，只获取session
        $ret = NetBus::connInfo($uniqIds, false, true, false);
        foreach ($ret as $uniqId => $item) {
            $this->assertEmpty($item['customerId'], "网关返回的用户customerId不符合预期");
            $this->assertEquals($item['session'], $uniqId . 'Session', "网关返回的用户session不符合预期");
            $this->assertEmpty($item['topics'], "网关返回的用户topics不符合预期");
        }
        //测试有数据的情况下，只获取topic
        $ret = NetBus::connInfo($uniqIds, false, false);
        foreach ($ret as $uniqId => $item) {
            $this->assertEmpty($item['customerId'], "网关返回的用户customerId不符合预期");
            $this->assertEmpty($item['session'], "网关返回的用户session不符合预期");
            $this->assertEquals($item['topics'][0], $uniqId . 'Topic', "网关返回的用户topics不符合预期");
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
        sort($uniqIds);
        ksort($ret);
        //因为用uniqId模拟客户端id，所以断言uniqId
        $this->assertEquals($uniqIds, array_keys($ret), "网关返回的用户信息不符合预期");
        foreach ($ret as $customerId => $items) {
            //因为一个客户id有可能对应多个uniqId，所以这里返回的是二维的结构
            foreach ($items as $item) {
                $this->assertEquals($customerId, $item['uniqId'], "网关返回的用户信息不符合预期");
                $this->assertEmpty($item['session'], "网关返回的用户信息不符合预期");
                $this->assertEmpty($item['topics'], "网关返回的用户信息不符合预期");
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
        $ret = NetBus::metrics();
        $serverIds = array_unique(array_column($ret, 'serverId'));
        $configServerIds = array_column(static::getNetsvrConfig()['netsvr'], 'serverId');
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
        $config = NetBus::limit();
        $this->assertNotEmpty($config);
        foreach ($config as $item) {
            $this->assertNotEmpty($item['workerAddr'], "限流配置workerAddr不能为空");
            $this->assertNotEmpty($item['onMessage'], "限流配置onMessage不能为空");
            $this->assertNotEmpty($item['onOpen'], "限流配置onOpen不能为空");
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
        $ret = NetBus::customerIdList();
        $this->assertSameSize(static::getNetsvrConfig()['netsvr'], $ret, "返回的workerAddr数量不符合预期");
        foreach ($ret as $value) {
            $this->assertEmpty($value['customerIds'], "返回的customerIds数量不符合预期");
        }
        //模拟客户端连接设置客户端id
        $uniqIds = $this->getDefaultUniqIds();
        $this->connInfoUpdate($uniqIds);
        //再次获取网关中的客户端连接的客户端id
        $ret = NetBus::customerIdList();
        foreach ($ret as $value) {
            $uniqIds = $this->getDefaultUniqIdsByWorkerAddr($value['workerAddr']);
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
        $ret = NetBus::customerIdCount();
        foreach ($ret as $value) {
            $expected = count($this->getDefaultUniqIdsByWorkerAddr($value['workerAddr']));
            $this->assertEquals($expected, $value['count'], "返回的customerId数量不符合预期");
        }
    }
}
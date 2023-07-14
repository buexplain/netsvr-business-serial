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

use ErrorException;
use Netsvr\ConnInfoDelete;
use Netsvr\ConnInfoUpdate;
use NetsvrBusiness\Container;
use NetsvrBusiness\Contract\ServerIdConvertInterface;
use NetsvrBusiness\Contract\TaskSocketInterface;
use NetsvrBusiness\Contract\TaskSocketMangerInterface;
use NetsvrBusiness\NetBus;
use NetsvrBusiness\ServerIdConvert;
use NetsvrBusiness\TaskSocket;
use NetsvrBusiness\TaskSocketManger;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Throwable;
use WebSocket\Client;

abstract class NetBusTestAbstract extends TestCase
{
    /**
     * @var array | Client[]
     */
    protected static array $wsClients = [];
    /**
     * @var array
     */
    protected static array $wsClientUniqIds = [];

    /**
     * 网关配置
     * @var array
     */
    protected static array $netsvrConfig = [];

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

    protected static function initNetBus()
    {
        /**
         * @var $container ContainerInterface|Container
         */
        $container = Container::getInstance();
        $container->bind(ServerIdConvertInterface::class, new ServerIdConvert());
        $taskSocketManger = new TaskSocketManger();
        foreach (static::$netsvrConfig as $config) {
            try {
                $taskSocket = new TaskSocket($config['host'], $config['port'], $config['sendTimeout'], $config['receiveTimeout'],117);
                $taskSocketManger->addSocket($config['serverId'], $taskSocket);
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
            } catch (Throwable) {
            }
        }
        static::$wsClients = [];
        static::$wsClientUniqIds = [];
        foreach (static::$netsvrConfig as $config) {
            for ($i = 0; $i < static::NETSVR_ONLINE_NUM; $i++) {
                //将每个网关的serverId转成16进制
                $hex = ($config['serverId'] < 15 ? '0' . dechex($config['serverId']) : dechex($config['serverId']));
                //将网关的serverId的16进制格式拼接到随机的uniqId前面
                $uniqId = $hex . uniqid();
                $client = new Client($config["ws"] . $uniqId, ['timeout' => 1]);
                try {
                    //这里必须先接收一下，否则接下来的测试会失败，我也搞不懂什么原因
                    $client->receive();
                } catch (Throwable) {
                }
                static::$wsClientUniqIds[$config['serverId']][] = $uniqId;
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
     * @param int $serverId
     * @return array
     */
    protected function getDefaultUniqIdsByServerId(int $serverId): array
    {
        return static::$wsClientUniqIds[$serverId] ?? [];
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
        //给每个连接设置一下session
        foreach ($uniqIds as $uniqId) {
            $up = new ConnInfoUpdate();
            $up->setUniqId($uniqId);
            //将其session设置为uniqId，方便接下来的校验
            $up->setNewSession($uniqId);
            //将其订阅设置为uniqId，方便接下来的校验
            $up->setNewTopics([$uniqId]);
            NetBus::connInfoUpdate($up);
        }
        //检查连接的的信息否设置成功
        $ret = NetBus::connInfo($uniqIds);
        foreach ($ret as $value) {
            //校验session是否设置成功
            $this->assertTrue($value['uniqId'] === $value['session']);
            //检查主题是否设置成功
            $this->assertTrue([$value['uniqId']] === $value['topics']);
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
        //给每个连接设置一下session
        foreach ($uniqIds as $uniqId) {
            $up = new ConnInfoUpdate();
            $up->setUniqId($uniqId);
            //将其session设置为uniqId，方便接下来的校验
            $up->setNewSession($uniqId);
            //将其订阅设置为uniqId，方便接下来的校验
            $up->setNewTopics([$uniqId]);
            NetBus::connInfoUpdate($up);
        }
        //检查连接的的信息否设置成功
        $ret = NetBus::connInfo($uniqIds);
        foreach ($ret as $value) {
            //校验session是否设置成功
            $this->assertTrue($value['uniqId'] === $value['session']);
            //检查主题是否设置成功
            $this->assertTrue([$value['uniqId']] === $value['topics']);
        }
        //移除每个连接的信息
        foreach ($uniqIds as $uniqId) {
            $up = new ConnInfoDelete();
            $up->setUniqId($uniqId);
            $up->setDelSession(true);
            $up->setDelTopic(true);
            NetBus::connInfoDelete($up);
        }
        //检查连接的信息是否删除成功
        $ret = NetBus::connInfo($uniqIds);
        foreach ($ret as $value) {
            $this->assertTrue('' === $value['session']);
            $this->assertTrue([] === $value['topics']);
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
        $message = uniqid();
        NetBus::broadcast($message);
        foreach (static::$wsClients as $client) {
            //接收每个连接的数据，并判断是否与之前发送的一致
            $this->assertTrue($message === $client->receive());
        }
    }

    /**
     * composer test -- --filter=testMulticast
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function testMulticast(): void
    {
        //连接到网关
        $this->resetWsClient();
        $uniqIds = $this->getDefaultUniqIds();
        $message = uniqid();
        NetBus::multicast($uniqIds, $message);
        foreach (static::$wsClients as $client) {
            //接收每个连接的数据，并判断是否与之前发送的一致
            $this->assertTrue($message === $client->receive());
        }
    }

    /**
     * composer test -- --filter=testSingleCastUnit
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function testSingleCastUnit(): void
    {
        //连接到网关
        $this->resetWsClient();
        $uniqIds = $this->getDefaultUniqIds();
        $message = [];
        foreach ($uniqIds as $uniqId) {
            $message[$uniqId] = uniqid();
            //给每个连接单播数据过去
            NetBus::singleCast($uniqId, $message[$uniqId]);
        }
        foreach (static::$wsClients as $uniqId => $client) {
            //接收每个连接的单播数据，并判断是否与之前发送的一致
            $this->assertTrue($message[$uniqId] === $client->receive());
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
        //构造批量单播所需的数据
        $message = [];
        foreach ($uniqIds as $uniqId) {
            $message[$uniqId] = uniqid();
        }
        //将数据批量的单播出去
        NetBus::singleCastBulk($message);
        //接收每个连接的数据，并判断是否为刚刚批量单播出去的数据
        foreach (static::$wsClients as $uniqId => $client) {
            $this->assertTrue($message[$uniqId] === $client->receive());
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
     * composer test -- --filter=testTopicPublish
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     * @throws ContainerExceptionInterface
     */
    public function testTopicPublish()
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
            $ok = true;
            for ($i = 0; $i < count($topics); $i++) {
                try {
                    $this->assertTrue($client->receive() === $publish, "返回的发布消息不符合预期");
                } catch (Throwable) {
                    $ok = false;
                }
            }
            $this->assertTrue($ok, "接收的发布消息数量不符合预期");
        }
    }

    /**
     * composer test -- --filter=testForceOfflineForAnyway
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     * @throws ContainerExceptionInterface
     * @throws ErrorException
     */
    public function testForceOfflineForAnyway()
    {
        //连接到网关
        $this->resetWsClient();
        //强制下线连接
        $uniqIds = $this->getDefaultUniqIds();
        NetBus::forceOffline($uniqIds);
        //等待网关执行完连接的关闭逻辑
        usleep(100);
        //检查是否在线
        $ret = NetBus::checkOnline($uniqIds);
        $this->assertEmpty($ret);
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
        usleep(100);
        //检查是否在线
        $ret = NetBus::checkOnline($uniqIds);
        $this->assertEmpty($ret);
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
        usleep(100);
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
            $uniqIds = $this->getDefaultUniqIdsByServerId($value['serverId']);
            sort($uniqIds);
            sort($value['uniqIds']);
            $this->assertTrue($uniqIds == $value['uniqIds'], "返回的连接不符合预期");
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
            $this->assertTrue(static::NETSVR_ONLINE_NUM == $value['count'], "返回的连接数量不符合预期");
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
        $topics = [uniqid(), uniqid(), uniqid(), uniqid()];
        $uniqIds = $this->getDefaultUniqIds();
        //每个连接都订阅两个主题
        foreach ($uniqIds as $uniqId) {
            NetBus::topicSubscribe($uniqId, $topics);
        }
        //获取网关的主题列表
        $ret = NetBus::topicList();
        sort($topics);
        foreach ($ret as $value) {
            sort($value['topics']);
            $this->assertTrue($topics == $value['topics'], "返回的topics不符合预期");
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
        //每个连接都订阅两个主题
        foreach ($uniqIds as $uniqId) {
            NetBus::topicSubscribe($uniqId, $topics);
        }
        //获取每个主题的连接
        $ret = NetBus::topicUniqIdList($topics);
        sort($uniqIds);
        foreach ($ret as $value) {
            $this->assertTrue(in_array($value['topic'], $topics), "返回的topic不符合预期");
            sort($value['uniqIds']);
            $this->assertTrue($uniqIds == $value['uniqIds'], "topic的uniqId不符合预期");
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
            $this->assertTrue(in_array($value['topic'], $topics), "返回的topic不符合预期");
            $this->assertTrue($value['count'] == count($uniqIds), "topic的uniqId数量不符合预期");
        }
    }

    /**
     * composer test -- --filter=testConnInfo
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     * @throws ContainerExceptionInterface
     * @throws ErrorException
     */
    public function testConnInfo()
    {
        $this->resetWsClient();
        $uniqIds = $this->getDefaultUniqIds();
        $ret = NetBus::connInfo($uniqIds);
        $ret = array_column($ret, 'uniqId');
        sort($uniqIds);
        sort($ret);
        $this->assertTrue($uniqIds == $ret, "网关返回的用户信息不符合预期");
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
        $configServerIds = array_column(static::$netsvrConfig, 'serverId');
        sort($serverIds);
        sort($configServerIds);
        $this->assertTrue($configServerIds == $serverIds);
    }

    /**
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public function testLimit(): void
    {
        $this->assertNotEmpty(NetBus::limit());
    }
}
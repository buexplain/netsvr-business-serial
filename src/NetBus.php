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

use Exception;
use Google\Protobuf\Internal\RepeatedField;
use Netsvr\Broadcast;
use Netsvr\CheckOnlineReq;
use Netsvr\CheckOnlineResp;
use Netsvr\Cmd;
use Netsvr\ConnInfoByCustomerIdReq;
use Netsvr\ConnInfoByCustomerIdResp;
use Netsvr\ConnInfoByCustomerIdRespItem;
use Netsvr\ConnInfoByCustomerIdRespItems;
use Netsvr\ConnInfoDelete;
use Netsvr\ConnInfoReq;
use Netsvr\ConnInfoResp;
use Netsvr\ConnInfoRespItem;
use Netsvr\ConnInfoUpdate;
use Netsvr\CustomerIdCountResp;
use Netsvr\CustomerIdListResp;
use Netsvr\ForceOffline;
use Netsvr\ForceOfflineByCustomerId;
use Netsvr\ForceOfflineGuest;
use Netsvr\LimitReq;
use Netsvr\LimitResp;
use Netsvr\MetricsResp;
use Netsvr\MetricsRespItem;
use Netsvr\Multicast;
use Netsvr\MulticastByCustomerId;
use Netsvr\SingleCast;
use Netsvr\SingleCastBulk;
use Netsvr\SingleCastBulkByCustomerId;
use Netsvr\SingleCastByCustomerId;
use Netsvr\TopicCountResp;
use Netsvr\TopicCustomerIdCountReq;
use Netsvr\TopicCustomerIdCountResp;
use Netsvr\TopicCustomerIdListReq;
use Netsvr\TopicCustomerIdListResp;
use Netsvr\TopicCustomerIdListRespItem;
use Netsvr\TopicDelete;
use Netsvr\TopicListResp;
use Netsvr\TopicPublish;
use Netsvr\TopicPublishBulk;
use Netsvr\TopicSubscribe;
use Netsvr\TopicUniqIdCountReq;
use Netsvr\TopicUniqIdCountResp;
use Netsvr\TopicUniqIdListReq;
use Netsvr\TopicUniqIdListResp;
use Netsvr\TopicUniqIdListRespItem;
use Netsvr\TopicUnsubscribe;
use Netsvr\UniqIdCountResp;
use Netsvr\UniqIdListResp;
use NetsvrBusiness\Contract\TaskSocketInterface;
use NetsvrBusiness\Contract\TaskSocketMangerInterface;
use NetsvrBusiness\Exception\SocketReceiveException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Throwable;

/**
 * 网络总线类，主打的就是与网关服务交互
 */
class NetBus
{
    /**
     * 更新客户在网关存储的信息
     * @param ConnInfoUpdate $connInfoUpdate
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function connInfoUpdate(ConnInfoUpdate $connInfoUpdate): void
    {
        self::sendToSocketByUniqId($connInfoUpdate->getUniqId(), self::pack(Cmd::ConnInfoUpdate, $connInfoUpdate->serializeToString()));
    }

    /**
     * 删除客户在网关存储的信息
     * @param ConnInfoDelete $connInfoDelete
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function connInfoDelete(ConnInfoDelete $connInfoDelete): void
    {
        self::sendToSocketByUniqId($connInfoDelete->getUniqId(), self::pack(Cmd::ConnInfoDelete, $connInfoDelete->serializeToString()));
    }

    /**
     * 广播
     * @param string $data 需要发送的数据
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function broadcast(string $data): void
    {
        $broadcast = new Broadcast();
        $broadcast->setData($data);
        self::sendToSockets(self::pack(Cmd::Broadcast, $broadcast->serializeToString()));
    }

    /**
     * 按uniqId组播
     * @param array|string|string[] $uniqIds 目标客户的网关uniqId
     * @param string $data 需要发送的数据
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function multicast(array|string $uniqIds, string $data): void
    {
        $uniqIds = (array)$uniqIds;
        //网关是单点部署或者是只有一个待发送的uniqId，则直接发送
        if (self::isSinglePoint() || count($uniqIds) == 1) {
            $multicast = new Multicast();
            $multicast->setUniqIds($uniqIds);
            $multicast->setData($data);
            self::sendToSocketByUniqId($uniqIds[array_key_last($uniqIds)], self::pack(Cmd::Multicast, $multicast->serializeToString()));
            return;
        }
        //对uniqId按所属网关进行分组
        $group = self::getUniqIdsGroupByWorkerAddrAsHex($uniqIds);
        //循环发送给各个网关
        foreach ($group as $workerAddrAsHex => $currentUniqIds) {
            $multicast = (new Multicast())->setData($data)->setUniqIds($currentUniqIds);
            self::sendToSocketByWorkerAddrAsHex($workerAddrAsHex, self::pack(Cmd::Multicast, $multicast->serializeToString()));
        }
    }

    /**
     * 按customerId组播
     * @param array|string|string[] $customerIds 目标客户的customerId
     * @param string $data 需要发送的数据
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function multicastByCustomerId(array|string|int $customerIds, string $data): void
    {
        $multicastByCustomerId = new MulticastByCustomerId();
        $multicastByCustomerId->setCustomerIds((array)$customerIds);
        $multicastByCustomerId->setData($data);
        //因为不知道客户id在哪个网关，所以给所有网关发送
        self::sendToSockets(self::pack(Cmd::MulticastByCustomerId, $multicastByCustomerId->serializeToString()));
    }

    /**
     * 按uniqId单播
     * @param string $uniqId 目标客户的网关uniqId
     * @param string $data 需要发送的数据
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function singleCast(string $uniqId, string $data): void
    {
        $singleCast = new SingleCast();
        $singleCast->setUniqId($uniqId);
        $singleCast->setData($data);
        self::sendToSocketByUniqId($uniqId, self::pack(Cmd::SingleCast, $singleCast->serializeToString()));
    }

    /**
     * 按customerId单播
     * @param string|int $customerId 目标客户的customerId
     * @param string $data 需要发送的数据
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function singleCastByCustomerId(string|int $customerId, string $data): void
    {
        $singleCastByCustomerId = new SingleCastByCustomerId();
        $singleCastByCustomerId->setCustomerId((string)$customerId);
        $singleCastByCustomerId->setData($data);
        //因为不知道客户id在哪个网关，所以给所有网关发送
        self::sendToSockets(self::pack(Cmd::SingleCastByCustomerId, $singleCastByCustomerId->serializeToString()));
    }

    /**
     * 按uniqId批量单播，一次性给多个用户发送不同的消息，或给一个用户发送多条消息
     * @param array $params 入参示例如下：
     * ['目标uniqId1'=>'数据1', '目标uniqId2'=>'数据2']
     * ['uniqIds'=>['目标uniqId1', '目标uniqId2'], 'data'=>['数据1', '数据2']]
     * ['uniqIds'=>'目标uniqId1', 'data'=>['数据1', '数据2']]
     * ['uniqIds'=>['目标uniqId1'], 'data'=>['数据1', '数据2']]
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function singleCastBulk(array $params): void
    {
        //网关是单机部署或者是只给一个用户发消息，则直接构造批量单播对象发送
        if (self::isSinglePoint() ||
            //这种格式的入参：['目标uniqId1'=>'数据1']
            count($params) === 1 ||
            //这种格式的入参：['uniqIds'=>'目标uniqId1', 'data'=>['数据1', '数据2']]
            //或者是这种格式的入参：['uniqIds'=>['目标uniqId1'], 'data'=>['数据1', '数据2']]
            (isset($params['data']) && isset($params['uniqIds']) && (!is_array($params['uniqIds']) || count($params['uniqIds']) === 1))
        ) {
            $singleCastBulk = new SingleCastBulk();
            if (isset($params['uniqIds']) && isset($params['data'])) {
                //这种格式的入参：['uniqIds'=>'目标uniqId1', 'data'=>['数据1', '数据2']]
                //或者是这种格式的入参：['uniqIds'=>['目标uniqId1'], 'data'=>['数据1', '数据2']]
                $uniqIds = (array)$params['uniqIds'];
                $singleCastBulk->setUniqIds($uniqIds);
                $singleCastBulk->setData((array)$params['data']);
            } else {
                //这种格式的入参：['目标uniqId1'=>'数据1']
                $uniqIds = array_keys($params);
                $singleCastBulk->setUniqIds($uniqIds);
                $singleCastBulk->setData(array_values($params));
            }
            self::sendToSocketByUniqId($uniqIds[array_key_last($uniqIds)], self::pack(Cmd::SingleCastBulk, $singleCastBulk->serializeToString()));
            return;
        }
        //网关是多机器部署，需要迭代每一个uniqId，并根据所在网关进行分组，然后再迭代每一个组，将数据发送到对应网关
        $bulks = [];
        if (isset($params['uniqIds']) && isset($params['data'])) {
            //这种结构的入参：['uniqIds'=>['目标uniqId1', '目标uniqId2'], 'data'=>['数据1', '数据2']]
            $params['data'] = (array)$params['data'];
            foreach ($params['uniqIds'] as $index => $uniqId) {
                $workerAddrAsHex = uniqIdConvertToWorkerAddrAsHex($uniqId);
                $bulks[$workerAddrAsHex]['uniqIds'][] = $uniqId;
                $bulks[$workerAddrAsHex]['data'][] = $params['data'][$index];
            }
        } else {
            //这种结构的入参：['目标uniqId1'=>'数据1', '目标uniqId2'=>'数据2']
            foreach ($params as $uniqId => $data) {
                $workerAddrAsHex = uniqIdConvertToWorkerAddrAsHex($uniqId);
                $bulks[$workerAddrAsHex]['uniqIds'][] = $uniqId;
                $bulks[$workerAddrAsHex]['data'][] = $data;
            }
        }
        //分组完毕，循环发送到各个网关
        foreach ($bulks as $workerAddrAsHex => $bulk) {
            $singleCastBulk = new SingleCastBulk();
            $singleCastBulk->setUniqIds($bulk['uniqIds']);
            $singleCastBulk->setData($bulk['data']);
            self::sendToSocketByWorkerAddrAsHex($workerAddrAsHex, self::pack(Cmd::SingleCastBulk, $singleCastBulk->serializeToString()));
        }
    }

    /**
     * 按customerId批量单播，一次性给多个用户发送不同的消息，或给一个用户发送多条消息
     * @param array $params 入参示例如下：
     * ['customerIds'=>'目标customerId1', 'data'=>['数据1', '数据2']]
     * ['customerIds'=>['目标customerId1'], 'data'=>['数据1', '数据2']]
     * ['customerIds'=>['目标customerId1', '目标customerId2'], 'data'=>['数据1', '数据2']]
     * ['目标customerId1'=>'数据1', '目标customerId2'=>'数据2']
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function singleCastBulkByCustomerId(array $params): void
    {
        $f = function (array $customerIds, array $data) {
            $singleCastBulkByCustomerId = new SingleCastBulkByCustomerId();
            $singleCastBulkByCustomerId->setCustomerIds($customerIds);
            $singleCastBulkByCustomerId->setData($data);
            //因为不知道客户id在哪个网关，所以给所有网关发送
            self::sendToSockets(self::pack(Cmd::SingleCastBulkByCustomerId, $singleCastBulkByCustomerId->serializeToString()));
        };
        //入参格式1：['customerIds'=>'目标customerId1', 'data'=>['数据1', '数据2']]
        //入参格式2：['customerIds'=>['目标customerId1'], 'data'=>['数据1', '数据2']]
        //入参格式3：['customerIds'=>['目标customerId1', '目标customerId2'], 'data'=>['数据1', '数据2']]
        if (isset($params['customerIds']) && isset($params['data'])) {
            $f((array)$params['customerIds'], (array)$params['data']);
            return;
        }
        //入参格式4：['目标customerId1'=>'数据1', '目标customerId2'=>'数据2']
        $f(array_keys($params), array_values($params));
    }

    /**
     * 订阅若干个主题
     * @param string $uniqId 需要订阅主题的客户的uniqId
     * @param array|string|string[] $topics 需要订阅的主题，包含具体主题的索引数组，主题必须是string类型
     * @param string $data 订阅成功后需要发给客户的数据
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function topicSubscribe(string $uniqId, array|string $topics, string $data = ''): void
    {
        $topicSubscribe = new TopicSubscribe();
        $topicSubscribe->setUniqId($uniqId);
        $topicSubscribe->setTopics((array)$topics);
        $topicSubscribe->setData($data);
        self::sendToSocketByUniqId($topicSubscribe->getUniqId(), self::pack(Cmd::TopicSubscribe, $topicSubscribe->serializeToString()));
    }

    /**
     * 取消若干个已订阅的主题
     * @param string $uniqId 需要取消已订阅主的题的客户的uniqId
     * @param array|string|string[] $topics 取消订阅的主题，包含具体主题的索引数组，主题必须是string类型
     * @param string $data 取消订阅成功后需要发给客户的数据
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function topicUnsubscribe(string $uniqId, array|string $topics, string $data = ''): void
    {
        $topicUnsubscribe = new TopicUnsubscribe();
        $topicUnsubscribe->setUniqId($uniqId);
        $topicUnsubscribe->setTopics((array)$topics);
        $topicUnsubscribe->setData($data);
        self::sendToSocketByUniqId($topicUnsubscribe->getUniqId(), self::pack(Cmd::TopicUnsubscribe, $topicUnsubscribe->serializeToString()));
    }

    /**
     * 删除主题
     * @param array|string|string[] $topics 需要删除的主题
     * @param string $data 删除主题后，需要发送给订阅过该主题的客户的数据
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function topicDelete(array|string $topics, string $data = ''): void
    {
        $topicDelete = new TopicDelete();
        $topicDelete->setTopics((array)$topics);
        $topicDelete->setData($data);
        self::sendToSockets(self::pack(Cmd::TopicDelete, $topicDelete->serializeToString()));
    }

    /**
     * 发布
     * @param array|string|string[] $topics 需要发布数据的主题
     * @param string $data 需要发给客户的数据
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function topicPublish(array|string $topics, string $data): void
    {
        $topicPublish = new TopicPublish();
        $topicPublish->setTopics((array)$topics);
        $topicPublish->setData($data);
        self::sendToSockets(self::pack(Cmd::TopicPublish, $topicPublish->serializeToString()));
    }

    /**
     * 批量发布，一次性给多个主题发送不同的消息，或给一个主题发送多条消息
     * @param array $params 入参示例如下：
     * ['目标主题1'=>'数据1', '目标主题2'=>'数据2']
     * ['topics'=>['目标主题1', '目标主题2'], 'data'=>['数据1', '数据2']]
     * ['topics'=>'目标主题1', 'data'=>['数据1', '数据2']]
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function topicPublishBulk(array $params): void
    {
        $topicPublishBulk = new TopicPublishBulk();
        if (isset($params['topics']) && isset($params['data'])) {
            //topics的值可以是只有一个，或者是与data的数量一致
            //['topics'=>'目标主题1', 'data'=>['数据1', '数据2']]
            //['topics'=>['目标主题1', '目标主题2'], 'data'=>['数据1', '数据2']]
            $topicPublishBulk->setTopics((array)$params['topics']);
            $topicPublishBulk->setData((array)$params['data']);
        } else {
            //key是topic，value是发给topic的数据，一一对应关系的
            //['目标主题1'=>'数据1', '目标主题2'=>'数据2']
            $topicPublishBulk->setTopics(array_keys($params));
            $topicPublishBulk->setData(array_values($params));
        }
        self::sendToSockets(self::pack(Cmd::TopicPublishBulk, $topicPublishBulk->serializeToString()));
    }

    /**
     * 强制关闭某几个连接
     * @param array|string|string[] $uniqIds 需要强制下线的客户的uniqId
     * @param string $data 下线操作前需要发给客户的信息
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function forceOffline(array|string $uniqIds, string $data = ''): void
    {
        $uniqIds = (array)$uniqIds;
        if (self::isSinglePoint() || count($uniqIds) == 1) {
            $forceOffline = new ForceOffline();
            $forceOffline->setUniqIds($uniqIds);
            $forceOffline->setData($data);
            self::sendToSocketByUniqId($uniqIds[array_key_last($uniqIds)], self::pack(Cmd::ForceOffline, $forceOffline->serializeToString()));
            return;
        }
        $group = self::getUniqIdsGroupByWorkerAddrAsHex($uniqIds);
        foreach ($group as $workerAddrAsHex => $currentUniqIds) {
            $forceOffline = new ForceOffline();
            $forceOffline->setUniqIds($currentUniqIds);
            $forceOffline->setData($data);
            self::sendToSocketByWorkerAddrAsHex($workerAddrAsHex, self::pack(Cmd::ForceOffline, $forceOffline->serializeToString()));
        }
    }

    /**
     * 强制关闭某几个customerId
     * @param array|string|int|string[]|int[] $customerIds 需要强制下线的customerId
     * @param string $data 下线操作前需要发给客户的信息
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function forceOfflineByCustomerId(array|string|int $customerIds, string $data = ''): void
    {
        $forceOfflineByCustomerId = new ForceOfflineByCustomerId();
        $forceOfflineByCustomerId->setCustomerIds($customerIds);
        $forceOfflineByCustomerId->setData($data);
        //因为不知道客户id在哪个网关，所以给所有网关发送
        self::sendToSockets(self::pack(Cmd::ForceOfflineByCustomerId, $forceOfflineByCustomerId->serializeToString()));
    }

    /**
     * 强制关闭某几个空session值的连接
     * @param array|string|string[] $uniqIds 需要强制下线的客户的uniqId，这个客户在网关存储的session必须是空字符串，如果不是，则不予处理
     * @param string $data 需要发给客户的数据，有这个数据，则转发给该连接，并在3秒倒计时后强制关闭连接，反之，立马关闭连接
     * @param int $delay 延迟多少秒执行，如果是0，立刻执行，否则就等待该秒数后，再根据uniqId获取连接，并判断连接是否存在session，没有就关闭连接，有就忽略
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function forceOfflineGuest(array|string $uniqIds, string $data = '', int $delay = 0): void
    {
        $uniqIds = (array)$uniqIds;
        if (self::isSinglePoint() || count($uniqIds) == 1) {
            $forceOfflineGuest = new ForceOfflineGuest();
            $forceOfflineGuest->setUniqIds($uniqIds);
            $forceOfflineGuest->setData($data);
            $forceOfflineGuest->setDelay($delay);
            self::sendToSocketByUniqId($uniqIds[array_key_last($uniqIds)], self::pack(Cmd::ForceOfflineGuest, $forceOfflineGuest->serializeToString()));
            return;
        }
        $group = self::getUniqIdsGroupByWorkerAddrAsHex($uniqIds);
        foreach ($group as $workerAddrAsHex => $currentUniqIds) {
            $forceOfflineGuest = new ForceOfflineGuest();
            $forceOfflineGuest->setUniqIds($currentUniqIds);
            $forceOfflineGuest->setData($data);
            $forceOfflineGuest->setDelay($delay);
            self::sendToSocketByWorkerAddrAsHex($workerAddrAsHex, self::pack(Cmd::ForceOfflineGuest, $forceOfflineGuest->serializeToString()));
        }
    }

    /**
     * 检查是否在线
     * @param array|string|string[] $uniqIds 包含uniqId的索引数组，或者是单个uniqId
     * @return array 包含当前在线的uniqId的索引数组
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public static function checkOnline(array|string $uniqIds): array
    {
        $uniqIds = (array)$uniqIds;
        //网关单点部署，或者是只有一个待检查的uniqId，则直接获取与网关的socket连接进行操作
        if (self::isSinglePoint() || count($uniqIds) == 1) {
            $socket = self::getSocketByUniqId($uniqIds[array_key_last($uniqIds)]);
            if (!$socket instanceof TaskSocketInterface) {
                return [];
            }
            $checkOnlineReq = (new CheckOnlineReq())->setUniqIds($uniqIds);
            $socket->send(self::pack(Cmd::CheckOnline, $checkOnlineReq->serializeToString()));
            $respData = $socket->receive();
            if ($respData === '' || $respData === false) {
                throw new SocketReceiveException('call Cmd::CheckOnline failed because the connection to the netsvr was disconnected');
            }
            $resp = new CheckOnlineResp();
            $resp->mergeFromString(self::unpack($respData));
            return self::repeatedFieldToArray($resp->getUniqIds());
        }
        //网关是多机器部署的，先对uniqId按所属网关进行分组
        $group = self::getUniqIdsGroupByWorkerAddrAsHex($uniqIds);
        if (empty($group)) {
            return [];
        }
        //再的向每个网关请求
        $ret = [];
        foreach ($group as $workerAddrAsHex => $currentUniqIds) {
            $socket = self::getTaskSocketManger()->getSocket($workerAddrAsHex);
            if ($socket instanceof TaskSocketInterface) {
                //构造请求参数
                $checkOnlineReq = (new CheckOnlineReq())->setUniqIds($currentUniqIds)->serializeToString();
                //发送请求
                $socket->send(self::pack(Cmd::CheckOnline, $checkOnlineReq));
                //接收响应
                $respData = $socket->receive();
                if ($respData === '' || $respData === false) {
                    throw new SocketReceiveException('call Cmd::CheckOnline failed because the connection to the netsvr was disconnected');
                }
                //解析响应
                $resp = new CheckOnlineResp();
                $resp->mergeFromString(self::unpack($respData));
                array_push($ret, ...self::repeatedFieldToArray($resp->getUniqIds()));
            }
        }
        return $ret;
    }

    /**
     * 获取网关中的uniqId
     * @return array[]
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public static function uniqIdList(): array
    {
        $sockets = self::getTaskSocketManger()->getSockets();
        $req = self::pack(Cmd::UniqIdList, '');
        $ret = [];
        foreach ($sockets as $socket) {
            $socket->send($req);
            $respData = $socket->receive();
            if ($respData === '' || $respData === false) {
                throw new SocketReceiveException('call Cmd::UniqIdList failed because the connection to the netsvr was disconnected');
            }
            $resp = new UniqIdListResp();
            $resp->mergeFromString(self::unpack($respData));
            $ret[] = [
                'workerAddr' => $socket->getWorkerAddr(),
                'uniqIds' => self::repeatedFieldToArray($resp->getUniqIds()),
            ];
        }
        return $ret;
    }

    /**
     * 统计网关的在线连接数
     * @return array[]
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public static function uniqIdCount(): array
    {
        $sockets = self::getTaskSocketManger()->getSockets();
        $req = self::pack(Cmd::UniqIdCount, '');
        $ret = [];
        foreach ($sockets as $socket) {
            $socket->send($req);
            $respData = $socket->receive();
            if ($respData === '' || $respData === false) {
                throw new SocketReceiveException('call Cmd::UniqIdCount failed because the connection to the netsvr was disconnected');
            }
            $resp = new UniqIdCountResp();
            $resp->mergeFromString(self::unpack($respData));
            $ret[] = [
                'workerAddr' => $socket->getWorkerAddr(),
                'count' => $resp->getCount(),
            ];
        }
        return $ret;
    }

    /**
     * 统计网关的主题数量
     * @return array[]
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public static function topicCount(): array
    {
        $sockets = self::getTaskSocketManger()->getSockets();
        $req = self::pack(Cmd::TopicCount, '');
        $ret = [];
        foreach ($sockets as $socket) {
            $socket->send($req);
            $respData = $socket->receive();
            if ($respData === '' || $respData === false) {
                throw new SocketReceiveException('call Cmd::TopicCount failed because the connection to the netsvr was disconnected');
            }
            $resp = new TopicCountResp();
            $resp->mergeFromString(self::unpack($respData));
            $ret[] = [
                'workerAddr' => $socket->getWorkerAddr(),
                'count' => $resp->getCount(),
            ];
        }
        return $ret;
    }

    /**
     * 获取网关的全部主题
     * @return array[]
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public static function topicList(): array
    {
        $sockets = self::getTaskSocketManger()->getSockets();
        $req = self::pack(Cmd::TopicList, '');
        $ret = [];
        foreach ($sockets as $socket) {
            $socket->send($req);
            $respData = $socket->receive();
            if ($respData === '' || $respData === false) {
                throw new SocketReceiveException('call Cmd::TopicList failed because the connection to the netsvr was disconnected');
            }
            $resp = new TopicListResp();
            $resp->mergeFromString(self::unpack($respData));
            $ret[] = [
                'workerAddr' => $socket->getWorkerAddr(),
                'topics' => self::repeatedFieldToArray($resp->getTopics()),
            ];
        }
        return $ret;
    }

    /**
     * 获取网关中某几个主题包含的uniqId
     * @param array|string|string[] $topics
     * @return array[] 注意一个uniqId可能订阅了多个主题
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public static function topicUniqIdList(array|string $topics): array
    {
        $sockets = self::getTaskSocketManger()->getSockets();
        $req = self::pack(Cmd::TopicUniqIdList, (new TopicUniqIdListReq())->setTopics((array)$topics)->serializeToString());
        $ret = [];
        foreach ($sockets as $socket) {
            $socket->send($req);
            $respData = $socket->receive();
            if ($respData === '' || $respData === false) {
                throw new SocketReceiveException('call Cmd::TopicUniqIdList failed because the connection to the netsvr was disconnected');
            }
            $resp = new TopicUniqIdListResp();
            $resp->mergeFromString(self::unpack($respData));
            $items = [
                'workerAddr' => $socket->getWorkerAddr(),
                'items' => [],
            ];
            foreach ($resp->getItems() as $topic => $item) {
                /**
                 * @var $item TopicUniqIdListRespItem
                 */
                $items['items'][] = [
                    'topic' => $topic,
                    'uniqIds' => self::repeatedFieldToArray($item->getUniqIds()),
                ];
            }
            $ret[] = $items;
        }
        return $ret;
    }

    /**
     * 统计网关中某几个主题包含的连接数
     * @param array|string|string[] $topics 需要统计连接数的主题
     * @param bool $allTopic 是否统计全部主题的连接数
     * @return array[]
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public static function topicUniqIdCount(array|string $topics, bool $allTopic = false): array
    {
        $sockets = self::getTaskSocketManger()->getSockets();
        $req = self::pack(Cmd::TopicUniqIdCount, (new TopicUniqIdCountReq())->setTopics((array)$topics)->setCountAll($allTopic)->serializeToString());
        $ret = [];
        foreach ($sockets as $socket) {
            $socket->send($req);
            $respData = $socket->receive();
            if ($respData === '' || $respData === false) {
                throw new SocketReceiveException('call Cmd::TopicUniqIdCount failed because the connection to the netsvr was disconnected');
            }
            $resp = new TopicUniqIdCountResp();
            $resp->mergeFromString(self::unpack($respData));
            $items = [
                'workerAddr' => $socket->getWorkerAddr(),
                'items' => [],
            ];
            foreach ($resp->getItems() as $topic => $count) {
                $items['items'][] = [
                    'topic' => $topic,
                    'count' => $count,
                ];
            }
            $ret[] = $items;
        }
        return $ret;
    }

    /**
     * 获取网关中某几个主题包含的customerId
     * @param array|string|string[] $topics
     * @return array[] 注意一个uniqId可能订阅了多个主题
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public static function topicCustomerIdList(array|string $topics): array
    {
        $sockets = self::getTaskSocketManger()->getSockets();
        $req = self::pack(Cmd::TopicCustomerIdList, (new TopicCustomerIdListReq())->setTopics((array)$topics)->serializeToString());
        $ret = [];
        foreach ($sockets as $socket) {
            $socket->send($req);
            $respData = $socket->receive();
            if ($respData === '' || $respData === false) {
                throw new SocketReceiveException('call Cmd::TopicCustomerIdList failed because the connection to the netsvr was disconnected');
            }
            $resp = new TopicCustomerIdListResp();
            $resp->mergeFromString(self::unpack($respData));
            $items = [
                'workerAddr' => $socket->getWorkerAddr(),
                'items' => [],
            ];
            foreach ($resp->getItems() as $topic => $item) {
                /**
                 * @var $item TopicCustomerIdListRespItem
                 */
                $items['items'][] = [
                    'topic' => $topic,
                    'customerIds' => self::repeatedFieldToArray($item->getCustomerIds()),
                ];
            }
            $ret[] = $items;
        }
        return $ret;
    }

    /**
     * 统计网关中某几个主题包含的customerId数量
     * @param array|string|string[] $topics 需要统计连接数的主题
     * @param bool $allTopic 是否统计全部主题的customerId数量
     * @return array[]
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public static function topicCustomerIdCount(array|string $topics, bool $allTopic = false): array
    {
        $sockets = self::getTaskSocketManger()->getSockets();
        $req = self::pack(Cmd::TopicCustomerIdCount, (new TopicCustomerIdCountReq())->setTopics((array)$topics)->setCountAll($allTopic)->serializeToString());
        $ret = [];
        foreach ($sockets as $socket) {
            $socket->send($req);
            $respData = $socket->receive();
            if ($respData === '' || $respData === false) {
                throw new SocketReceiveException('call Cmd::TopicCustomerIdCount failed because the connection to the netsvr was disconnected');
            }
            $resp = new TopicCustomerIdCountResp();
            $resp->mergeFromString(self::unpack($respData));
            $items = [
                'workerAddr' => $socket->getWorkerAddr(),
                'items' => [],
            ];
            foreach ($resp->getItems() as $topic => $count) {
                $items['items'][] = [
                    'topic' => $topic,
                    'count' => $count,
                ];
            }
            $ret[] = $items;
        }
        return $ret;
    }

    /**
     * 获取目标uniqId在网关中存储的信息
     * @param array|string $uniqIds
     * @param bool $reqCustomerId 是否请求customerId
     * @param bool $reqSession 是否请求session
     * @param bool $reqTopic 是否请求topic
     * @return array[] key是uniqId，value是uniqId对应的连接信息
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    public static function connInfo(array|string $uniqIds, bool $reqCustomerId = true, bool $reqSession = true, bool $reqTopic = true): array
    {
        $uniqIds = (array)$uniqIds;
        $f = function ($uniqIds) use ($reqCustomerId, $reqSession, $reqTopic): string {
            $connInfoReq = (new ConnInfoReq())->setUniqIds($uniqIds);
            $connInfoReq->setReqCustomerId($reqCustomerId);
            $connInfoReq->setReqSession($reqSession);
            $connInfoReq->setReqTopic($reqTopic);
            return $connInfoReq->serializeToString();
        };
        if (self::isSinglePoint() || count($uniqIds) == 1) {
            $socket = self::getSocketByUniqId($uniqIds[array_key_last($uniqIds)]);
            if (!$socket instanceof TaskSocketInterface) {
                return [];
            }
            $req = self::pack(Cmd::ConnInfo, $f($uniqIds));
            $socket->send($req);
            $respData = $socket->receive();
            if ($respData === '' || $respData === false) {
                throw new SocketReceiveException('call Cmd::ConnInfo failed because the connection to the netsvr was disconnected');
            }
            $resp = new ConnInfoResp();
            $resp->mergeFromString(self::unpack($respData));
            $ret = [];
            foreach ($resp->getItems() as $uniqId => $item) {
                /**
                 * @var $item ConnInfoRespItem
                 */
                $ret[$uniqId] = [
                    'customerId' => $item->getCustomerId(),
                    'session' => $item->getSession(),
                    'topics' => self::repeatedFieldToArray($item->getTopics()),
                ];
            }
            return $ret;
        }
        $group = self::getUniqIdsGroupByWorkerAddrAsHex($uniqIds);
        if (empty($group)) {
            return [];
        }
        $ret = [];
        foreach ($group as $workerAddrAsHex => $currentUniqIds) {
            /**
             * @var $socket TaskSocketInterface
             */
            $socket = self::getTaskSocketManger()->getSocket($workerAddrAsHex);
            if ($socket instanceof TaskSocketInterface) {
                $req = self::pack(Cmd::ConnInfo, $f($currentUniqIds));
                $socket->send($req);
                $respData = $socket->receive();
                if ($respData === '' || $respData === false) {
                    throw new SocketReceiveException('call Cmd::ConnInfo failed because the connection to the netsvr was disconnected');
                }
                $resp = new ConnInfoResp();
                $resp->mergeFromString(self::unpack($respData));
                foreach ($resp->getItems() as $uniqId => $item) {
                    /**
                     * @var $item ConnInfoRespItem
                     */
                    $ret[$uniqId] = [
                        'customerId' => $item->getCustomerId(),
                        'session' => $item->getSession(),
                        'topics' => self::repeatedFieldToArray($item->getTopics()),
                    ];
                }
            }
        }
        return $ret;
    }

    /**
     * 获取目标customerId在网关中存储的信息
     * @param array|string $customerIds
     * @param bool $reqUniqId 是否请求uniqId
     * @param bool $reqSession 是否请求session
     * @param bool $reqTopic 是否请求topic
     * @return array[] key是customerId，value是customerId对应的多个连接信息
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    public static function connInfoByCustomerId(array|string $customerIds, bool $reqUniqId = true, bool $reqSession = true, bool $reqTopic = true): array
    {
        $sockets = self::getTaskSocketManger()->getSockets();
        if (empty($sockets)) {
            return [];
        }
        $connInfoByCustomerIdReq = (new ConnInfoByCustomerIdReq())->setCustomerIds((array)$customerIds);
        $connInfoByCustomerIdReq->setReqUniqId($reqUniqId);
        $connInfoByCustomerIdReq->setReqSession($reqSession);
        $connInfoByCustomerIdReq->setReqTopic($reqTopic);
        $req = self::pack(Cmd::ConnInfoByCustomerId, $connInfoByCustomerIdReq->serializeToString());
        $ret = [];
        //因为不知道客户id在哪个网关，所以给所有网关发送
        foreach ($sockets as $socket) {
            $socket->send($req);
            $respData = $socket->receive();
            if ($respData === '' || $respData === false) {
                throw new SocketReceiveException('call Cmd::ConnInfoByCustomerId failed because the connection to the netsvr was disconnected');
            }
            $resp = new connInfoByCustomerIdResp();
            $resp->mergeFromString(self::unpack($respData));
            foreach ($resp->getItems() as $customerId => $info) {
                /**
                 * @var $info ConnInfoByCustomerIdRespItems
                 */
                $items = [];
                foreach ($info->getItems() as $item) {
                    /**
                     * @var $item ConnInfoByCustomerIdRespItem
                     */
                    $items[] = [
                        'uniqId' => $item->getUniqId(),
                        'session' => $item->getSession(),
                        'topics' => self::repeatedFieldToArray($item->getTopics()),
                    ];
                }
                $ret[$customerId] = $items;
            }
        }
        return $ret;
    }

    /**
     * @param int $precision 统计值的保留小数位
     * @return array
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public static function metrics(int $precision = 3): array
    {
        $sockets = self::getTaskSocketManger()->getSockets();
        if (empty($sockets)) {
            return [];
        }
        $req = self::pack(Cmd::Metrics, '');
        $ret = [];
        foreach ($sockets as $socket) {
            $socket->send($req);
            $respData = $socket->receive();
            if ($respData === '' || $respData === false) {
                throw new SocketReceiveException('call Cmd::Metrics failed because the connection to the netsvr was disconnected');
            }
            $resp = new MetricsResp();
            $resp->mergeFromString(self::unpack($respData));
            foreach ($resp->getItems() as $metricsValue) {
                /**
                 * @var $metricsValue MetricsRespItem
                 */
                $ret[] = [
                    //网关服务的severId
                    'workerAddr' => $socket->getWorkerAddr(),
                    //统计的服务状态项，具体含义请移步：https://github.com/buexplain/netsvr/blob/main/internal/metrics/metrics.go
                    'item' => $metricsValue->getItem(),
                    //总数
                    'count' => $metricsValue->getCount(),
                    //每秒速率
                    'meanRate' => round($metricsValue->getMeanRate(), $precision),
                    //每秒速率的最大值
                    'meanRateMax' => round($metricsValue->getMeanRateMax(), $precision),
                    //每1分钟速率
                    'rate1' => round($metricsValue->getRate1(), $precision),
                    //每1分钟速率的最大值
                    'rate1Max' => round($metricsValue->getRate1Max(), $precision),
                    //每5分钟速率
                    'rate5' => round($metricsValue->getRate5(), $precision),
                    //每5分钟速率的最大值
                    'rate5Max' => round($metricsValue->getRate5Max(), $precision),
                    //每15分钟速率
                    'rate15' => round($metricsValue->getRate15(), $precision),
                    //每15分钟速率的最大值
                    'rate15Max' => round($metricsValue->getRate15Max(), $precision),
                ];
            }
        }
        return $ret;
    }

    /**
     * 设置或读取网关针对business的每秒转发数量的限制的配置
     * @param LimitReq|null $limitReq
     * @param string $workerAddr
     * @return array[]
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    public static function limit(LimitReq $limitReq = null, string $workerAddr = ''): array
    {
        if ($workerAddr === '') {
            $workerAddrAsHex = '';
        } else {
            $workerAddrAsHex = workerAddrConvertToHex($workerAddr);
        }
        if ($workerAddrAsHex === '') {
            $sockets = self::getTaskSocketManger()->getSockets();
        } else {
            $socket = self::getTaskSocketManger()->getSocket($workerAddrAsHex);
            if (!empty($socket)) {
                $sockets = [$socket];
            } else {
                $sockets = [];
            }
        }
        if (empty($sockets)) {
            return [];
        }
        if ($limitReq === null) {
            $limitReq = new LimitReq();
        }
        $req = self::pack(Cmd::Limit, $limitReq->serializeToString());
        $ret = [];
        foreach ($sockets as $socket) {
            $socket->send($req);
            $respData = $socket->receive();
            if ($respData === '' || $respData === false) {
                throw new SocketReceiveException('call Cmd::Limit failed because the connection to the netsvr was disconnected');
            }
            $resp = new LimitResp();
            $resp->mergeFromString(self::unpack($respData));
            $ret[] = [
                'workerAddr' => $socket->getWorkerAddr(),
                'onMessage' => $resp->getOnMessage(),
                'onOpen' => $resp->getOnOpen(),
            ];
        }
        return $ret;
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    public static function customerIdList(): array
    {
        $sockets = self::getTaskSocketManger()->getSockets();
        $req = self::pack(Cmd::CustomerIdList, '');
        $ret = [];
        foreach ($sockets as $socket) {
            $socket->send($req);
            $respData = $socket->receive();
            if ($respData === '' || $respData === false) {
                throw new SocketReceiveException('call Cmd::CustomerIdList failed because the connection to the netsvr was disconnected');
            }
            $resp = new CustomerIdListResp();
            $resp->mergeFromString(self::unpack($respData));
            $ret[] = [
                'workerAddr' => $socket->getWorkerAddr(),
                'customerIds' => self::repeatedFieldToArray($resp->getCustomerIds()),
            ];
        }
        return $ret;
    }

    /**
     * 统计网关的在线客户数
     * 注意各个网关的客户数之和不一定等于总在线客户数，因为可能一个客户有多个设备连接到不同网关
     * @return array[]
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public static function customerIdCount(): array
    {
        $sockets = self::getTaskSocketManger()->getSockets();
        $req = self::pack(Cmd::CustomerIdCount, '');
        $ret = [];
        foreach ($sockets as $socket) {
            $socket->send($req);
            $respData = $socket->receive();
            if ($respData === '' || $respData === false) {
                throw new SocketReceiveException('call Cmd::CustomerIdCount failed because the connection to the netsvr was disconnected');
            }
            $resp = new CustomerIdCountResp();
            $resp->mergeFromString(self::unpack($respData));
            $ret[] = [
                'workerAddr' => $socket->getWorkerAddr(),
                'count' => $resp->getCount(),
            ];
        }
        return $ret;
    }

    /**
     * 给所有网关服务发送数据
     * @param string $data
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    protected static function sendToSockets(string $data): void
    {
        $sockets = self::getTaskSocketManger()->getSockets();
        if (!empty($sockets)) {
            foreach ($sockets as $socket) {
                $socket->send($data);
            }
        }
    }

    /**
     * 向uniqId对应的网关服务发送数据
     * @param string $uniqId
     * @param string $data
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    protected static function sendToSocketByUniqId(string $uniqId, string $data): void
    {
        $socket = self::getSocketByUniqId($uniqId);
        if ($socket instanceof TaskSocketInterface) {
            $socket->send($data);
        }
    }

    /**
     * 向workerAddr对应的网关服务发送数据
     * @param string $workerAddrAsHex
     * @param string $data
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    protected static function sendToSocketByWorkerAddrAsHex(string $workerAddrAsHex, string $data): void
    {
        /**
         * @var $socket TaskSocketInterface
         */
        $socket = self::getTaskSocketManger()->getSocket($workerAddrAsHex);
        if (!empty($socket)) {
            $socket->send($data);
        }
    }

    /**
     * 根据uniqId获取其所在的网关的socket
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    protected static function getSocketByUniqId(string $uniqId): ?TaskSocketInterface
    {
        return self::getTaskSocketManger()->getSocket(uniqIdConvertToWorkerAddrAsHex($uniqId));
    }

    /**
     * 判断网关是否为单点部署
     * @return bool
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    protected static function isSinglePoint(): bool
    {
        return count(self::getTaskSocketManger()->getSockets()) == 1;
    }

    /**
     * @return TaskSocketMangerInterface
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    protected static function getTaskSocketManger(): TaskSocketMangerInterface
    {
        return Container::getInstance()->get(TaskSocketMangerInterface::class);
    }

    /**
     * 将uniqId进行分组，同一网关的归到一组
     * @param array $uniqIds 包含uniqId的数组
     * @return array key是网关的worker服务器监听地址的16进制表示，value是包含uniqId的数组
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    protected static function getUniqIdsGroupByWorkerAddrAsHex(array $uniqIds): array
    {
        $ret = [];
        // 将uniqId按照worker服务器的监听地址进行分组
        foreach ($uniqIds as $uniqId) {
            $ret[uniqIdConvertToWorkerAddrAsHex($uniqId)][] = $uniqId;
        }
        return $ret;
    }

    /**
     * 将repeatedField转换为数组
     * @param RepeatedField $repeatedField
     * @return array
     */
    protected static function repeatedFieldToArray(RepeatedField $repeatedField): array
    {
        $ret = [];
        foreach ($repeatedField as $item) {
            $ret[] = $item;
        }
        return $ret;
    }

    /**
     * 打包
     * @param int $cmd
     * @param string $data
     * @return string
     */
    protected static function pack(int $cmd, string $data): string
    {
        return pack('N', $cmd) . $data;
    }

    /**
     * 解包
     * @param string $data
     * @return string
     */
    protected static function unpack(string $data): string
    {
        return substr($data, 4);
    }
}

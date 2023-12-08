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
use Netsvr\ConnInfoDelete;
use Netsvr\ConnInfoReq;
use Netsvr\ConnInfoResp;
use Netsvr\ConnInfoRespItem;
use Netsvr\ConnInfoUpdate;
use Netsvr\ConnOpenCustomUniqIdTokenResp;
use Netsvr\ForceOffline;
use Netsvr\ForceOfflineGuest;
use Netsvr\LimitReq;
use Netsvr\LimitResp;
use Netsvr\LimitRespItem;
use Netsvr\MetricsResp;
use Netsvr\MetricsRespItem;
use Netsvr\Multicast;
use Netsvr\SingleCast;
use Netsvr\SingleCastBulk;
use Netsvr\TopicCountResp;
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
use NetsvrBusiness\Contract\ServerIdConvertInterface;
use NetsvrBusiness\Contract\TaskSocketInterface;
use NetsvrBusiness\Contract\TaskSocketMangerInterface;
use NetsvrBusiness\Exception\SocketReceiveException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Throwable;
use ErrorException;

/**
 * 网络总线类，主打的就是与网关服务交互
 */
class NetBus
{
    /**
     * 获取客户端连接网关时，传递自定义uniqId所需的token
     * 返回的uniqId与token并无绑定关系，你可以采用返回的uniqId，也可以自己构造一个uniqId
     * @param int $serverId
     * @return array
     * @throws ContainerExceptionInterface
     * @throws ErrorException
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    public static function connOpenCustomUniqIdToken(int $serverId): array
    {
        /**
         * @var $socket TaskSocketInterface
         */
        $socket = Container::getInstance()->get(TaskSocketMangerInterface::class)->getSocket($serverId);
        $socket->send(self::pack(Cmd::ConnOpenCustomUniqIdToken, ''));
        $respData = $socket->receive();
        if ($respData === '' || $respData === false) {
            throw new SocketReceiveException('call Cmd::ConnOpenCustomUniqIdToken failed because the connection to the netsvr was disconnected');
        }
        $resp = new ConnOpenCustomUniqIdTokenResp();
        $resp->mergeFromString(self::unpack($respData));
        return [
            'uniqId' => $resp->getUniqId(),
            'token' => $resp->getToken(),
        ];
    }

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
     * 组播
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
            self::sendToSocketByUniqId($uniqIds[0], self::pack(Cmd::Multicast, $multicast->serializeToString()));
            return;
        }
        //对uniqId按所属网关进行分组
        $serverGroup = self::uniqIdsGroupByServerId($uniqIds);
        //循环发送给各个网关
        foreach ($serverGroup as $serverId => $currentUniqIds) {
            $multicast = (new Multicast())->setData($data)->setUniqIds($currentUniqIds);
            self::sendToSocketByServerId($serverId, self::pack(Cmd::Multicast, $multicast->serializeToString()));
        }
    }

    /**
     * 单播
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
     * 批量单播，一次性给多个用户发送不同的消息，或给一个用户发送多条消息
     * @param array $params 入参示例如下：
     * ['目标uniqId1'=>'数据1', '目标uniqId2'=>'数据2']
     * ['uniqIds'=>['目标uniqId1', '目标uniqId2'], 'data'=>['数据1', '数据2']]
     * ['uniqIds'=>'目标uniqId1', 'data'=>['数据1', '数据2']]
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
            //这种格式的入参：['uniqIds'=>'目标uniqId1', 'data'=>['数据1', '数据2']]，['uniqIds'=>['目标uniqId1'], 'data'=>['数据1', '数据2']]
            (isset($params['data']) && isset($params['uniqIds']) && (!is_array($params['uniqIds']) || count($params['uniqIds']) === 1))
        ) {
            $singleCastBulk = new SingleCastBulk();
            if (isset($params['uniqIds']) && isset($params['data'])) {
                $uniqIds = (array)$params['uniqIds'];
                $singleCastBulk->setUniqIds($uniqIds);
                $singleCastBulk->setData((array)$params['data']);
            } else {
                $uniqIds = array_keys($params);
                $singleCastBulk->setUniqIds($uniqIds);
                $singleCastBulk->setData(array_values($params));
            }
            self::sendToSocketByUniqId($uniqIds[0], self::pack(Cmd::SingleCastBulk, $singleCastBulk->serializeToString()));
            return;
        }
        //网关是多机器部署，需要迭代每一个uniqId，并根据所在网关进行分组，然后再迭代每一个组，并把数据发送到对应网关
        /**
         * @var $serverIdConvert ServerIdConvertInterface
         */
        $serverIdConvert = Container::getInstance()->get(ServerIdConvertInterface::class);
        $bulks = [];
        if (isset($params['uniqIds']) && isset($params['data'])) {
            //这种结构的入参：['uniqIds'=>['目标uniqId1', '目标uniqId2'], 'data'=>['数据1', '数据2']]
            $params['data'] = (array)$params['data'];
            $serverIds = $serverIdConvert->bulk($params['uniqIds']);
            foreach ($params['uniqIds'] as $index => $uniqId) {
                $serverId = $serverIds[$uniqId];
                $bulks[$serverId]['uniqIds'][] = $uniqId;
                $bulks[$serverId]['data'][] = $params['data'][$index];
            }
        } else {
            //这种结构的入参：['目标uniqId1'=>'数据1', '目标uniqId2'=>'数据2']
            $serverIds = $serverIdConvert->bulk(array_keys($params));
            foreach ($params as $uniqId => $data) {
                $serverId = $serverIds[$uniqId];
                $bulks[$serverId]['uniqIds'][] = $uniqId;
                $bulks[$serverId]['data'][] = $data;
            }
        }
        //分组完毕，循环发送到各个网关
        foreach ($bulks as $serverId => $bulk) {
            $singleCastBulk = new SingleCastBulk();
            $singleCastBulk->setUniqIds($bulk['uniqIds']);
            $singleCastBulk->setData($bulk['data']);
            self::sendToSocketByServerId($serverId, self::pack(Cmd::SingleCastBulk, $singleCastBulk->serializeToString()));
        }
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
            self::sendToSocketByUniqId($uniqIds[0], self::pack(Cmd::ForceOffline, $forceOffline->serializeToString()));
            return;
        }
        $serverGroup = self::uniqIdsGroupByServerId($uniqIds);
        foreach ($serverGroup as $serverId => $currentUniqIds) {
            $forceOffline = new ForceOffline();
            $forceOffline->setUniqIds($currentUniqIds);
            $forceOffline->setData($data);
            self::sendToSocketByServerId($serverId, self::pack(Cmd::ForceOffline, $forceOffline->serializeToString()));
        }
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
            self::sendToSocketByUniqId($uniqIds[0], self::pack(Cmd::ForceOfflineGuest, $forceOfflineGuest->serializeToString()));
            return;
        }
        $serverGroup = self::uniqIdsGroupByServerId($uniqIds);
        foreach ($serverGroup as $serverId => $currentUniqIds) {
            $forceOfflineGuest = new ForceOfflineGuest();
            $forceOfflineGuest->setUniqIds($currentUniqIds);
            $forceOfflineGuest->setData($data);
            $forceOfflineGuest->setDelay($delay);
            self::sendToSocketByServerId($serverId, self::pack(Cmd::ForceOfflineGuest, $forceOfflineGuest->serializeToString()));
        }
    }

    /**
     * 检查是否在线
     * @param array|string|string[] $uniqIds 包含uniqId的索引数组，或者是单个uniqId
     * @return array 包含当前在线的uniqId的索引数组
     * @throws ContainerExceptionInterface
     * @throws ErrorException
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public static function checkOnline(array|string $uniqIds): array
    {
        $uniqIds = (array)$uniqIds;
        //网关单点部署，或者是只有一个待检查的uniqId，则直接获取与网关的socket连接进行操作
        if (self::isSinglePoint() || count($uniqIds) == 1) {
            $socket = self::getSocketByUniqId($uniqIds[0]);
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
        $serverGroup = self::uniqIdsGroupByServerId($uniqIds);
        if (empty($serverGroup)) {
            return [];
        }
        //再的向每个网关请求
        $ret = [];
        foreach ($serverGroup as $serverId => $currentUniqIds) {
            /**
             * @var $socket TaskSocketInterface
             */
            $socket = Container::getInstance()->get(TaskSocketMangerInterface::class)->getSocket($serverId);
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
     * @throws ErrorException
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public static function uniqIdList(): array
    {
        $sockets = Container::getInstance()->get(TaskSocketMangerInterface::class)->getSockets();
        if (empty($sockets)) {
            return [];
        }
        $req = self::pack(Cmd::UniqIdList, '');
        //单机部署的网关，直接请求
        if (count($sockets) === 1) {
            $socket = $sockets[0];
            $socket->send($req);
            $respData = $socket->receive();
            if ($respData === '' || $respData === false) {
                throw new SocketReceiveException('call Cmd::UniqIdList failed because the connection to the netsvr was disconnected');
            }
            $resp = new UniqIdListResp();
            $resp->mergeFromString(self::unpack($respData));
            return [[
                'serverId' => $resp->getServerId(),
                'uniqIds' => self::repeatedFieldToArray($resp->getUniqIds()),
            ]];
        }
        //多机器部署的网关，请求
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
                'serverId' => $resp->getServerId(),
                'uniqIds' => self::repeatedFieldToArray($resp->getUniqIds()),
            ];
        }
        return $ret;
    }

    /**
     * 统计网关的在线连接数
     * @return array[]
     * @throws ContainerExceptionInterface
     * @throws ErrorException
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public static function uniqIdCount(): array
    {
        $sockets = Container::getInstance()->get(TaskSocketMangerInterface::class)->getSockets();
        if (empty($sockets)) {
            return [];
        }
        $req = self::pack(Cmd::UniqIdCount, '');
        //网关是单体架构部署的，直接请求
        if (count($sockets) === 1) {
            $socket = $sockets[0];
            $socket->send($req);
            $respData = $socket->receive();
            if ($respData === '' || $respData === false) {
                throw new SocketReceiveException('call Cmd::UniqIdCount failed because the connection to the netsvr was disconnected');
            }
            $resp = new UniqIdCountResp();
            $resp->mergeFromString(self::unpack($respData));
            return [[
                'serverId' => $resp->getServerId(),
                'count' => $resp->getCount(),
            ]];
        }
        //网关是多机器部署的，请求
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
                'serverId' => $resp->getServerId(),
                'count' => $resp->getCount(),
            ];
        }
        return $ret;
    }

    /**
     * 统计网关的主题数量
     * @return array[]
     * @throws ContainerExceptionInterface
     * @throws ErrorException
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public static function topicCount(): array
    {
        $sockets = Container::getInstance()->get(TaskSocketMangerInterface::class)->getSockets();
        if (empty($sockets)) {
            return [];
        }
        $req = self::pack(Cmd::TopicCount, '');
        //直接请求单个网关
        if (count($sockets) === 1) {
            $socket = $sockets[0];
            $socket->send($req);
            $respData = $socket->receive();
            if ($respData === '' || $respData === false) {
                throw new SocketReceiveException('call Cmd::TopicCount failed because the connection to the netsvr was disconnected');
            }
            $resp = new TopicCountResp();
            $resp->mergeFromString(self::unpack($respData));
            return [[
                'serverId' => $resp->getServerId(),
                'count' => $resp->getCount(),
            ]];
        }
        //请求多个网关
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
                'serverId' => $resp->getServerId(),
                'count' => $resp->getCount(),
            ];
        }
        return $ret;
    }

    /**
     * 获取网关的全部主题
     * @return array[]
     * @throws ContainerExceptionInterface
     * @throws ErrorException
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public static function topicList(): array
    {
        $sockets = Container::getInstance()->get(TaskSocketMangerInterface::class)->getSockets();
        if (empty($sockets)) {
            return [];
        }
        $req = self::pack(Cmd::TopicList, '');
        //直接请求单个网关
        if (count($sockets) === 1) {
            $socket = $sockets[0];
            $socket->send($req);
            $respData = $socket->receive();
            if ($respData === '' || $respData === false) {
                throw new SocketReceiveException('call Cmd::TopicList failed because the connection to the netsvr was disconnected');
            }
            $resp = new TopicListResp();
            $resp->mergeFromString(self::unpack($respData));
            return [[
                'serverId' => $resp->getServerId(),
                'topics' => self::repeatedFieldToArray($resp->getTopics()),
            ]];
        }
        //请求多个网关
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
                'serverId' => $resp->getServerId(),
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
        $sockets = Container::getInstance()->get(TaskSocketMangerInterface::class)->getSockets();
        if (empty($sockets)) {
            return [];
        }
        $topicUniqIdListReq = (new TopicUniqIdListReq())->setTopics((array)$topics)->serializeToString();
        $req = self::pack(Cmd::TopicUniqIdList, $topicUniqIdListReq);
        //网关是单机部署的，直接请求
        if (count($sockets) === 1) {
            $socket = $sockets[0];
            $socket->send($req);
            $respData = $socket->receive();
            if ($respData === '' || $respData === false) {
                throw new SocketReceiveException('call Cmd::TopicUniqIdList failed because the connection to the netsvr was disconnected');
            }
            $resp = new TopicUniqIdListResp();
            $resp->mergeFromString(self::unpack($respData));
            $ret = [];
            foreach ($resp->getItems() as $topic => $uniqIds) {
                /**
                 * @var $uniqIds TopicUniqIdListRespItem
                 */
                $ret[] = [
                    'topic' => $topic,
                    'uniqIds' => self::repeatedFieldToArray($uniqIds->getUniqIds()),
                ];
            }
            return $ret;
        }
        //网关是多机器部署的，请求每个网关机器
        $ret = [];
        foreach ($sockets as $socket) {
            $socket->send($req);
            $respData = $socket->receive();
            if ($respData === '' || $respData === false) {
                throw new SocketReceiveException('call Cmd::TopicUniqIdList failed because the connection to the netsvr was disconnected');
            }
            $resp = new TopicUniqIdListResp();
            $resp->mergeFromString(self::unpack($respData));
            foreach ($resp->getItems() as $topic => $uniqIds) {
                /**
                 * @var $uniqIds TopicUniqIdListRespItem
                 */
                if (isset($ret[$topic])) {
                    array_push($ret[$topic]['uniqIds'], ...self::repeatedFieldToArray($uniqIds->getUniqIds()));
                } else {
                    $ret[$topic] = [
                        'topic' => $topic,
                        'uniqIds' => self::repeatedFieldToArray($uniqIds->getUniqIds()),
                    ];
                }
            }
        }
        return array_values($ret);
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
        $sockets = Container::getInstance()->get(TaskSocketMangerInterface::class)->getSockets();
        if (empty($sockets)) {
            return [];
        }
        $topicUniqIdCountReq = (new TopicUniqIdCountReq())->setTopics((array)$topics)->setCountAll($allTopic);
        $req = self::pack(Cmd::TopicUniqIdCount, $topicUniqIdCountReq->serializeToString());
        if (count($sockets) === 1) {
            $socket = $sockets[0];
            $socket->send($req);
            $respData = $socket->receive();
            if ($respData === '' || $respData === false) {
                throw new SocketReceiveException('call Cmd::TopicUniqIdCount failed because the connection to the netsvr was disconnected');
            }
            $resp = new TopicUniqIdCountResp();
            $resp->mergeFromString(self::unpack($respData));
            $ret = [];
            foreach ($resp->getItems() as $currentTopic => $count) {
                $ret[] = [
                    'topic' => $currentTopic,
                    'count' => $count,
                ];
            }
            return $ret;
        }
        $ret = [];
        foreach ($sockets as $socket) {
            $socket->send($req);
            $respData = $socket->receive();
            if ($respData === '' || $respData === false) {
                throw new SocketReceiveException('call Cmd::TopicUniqIdCount failed because the connection to the netsvr was disconnected');
            }
            $resp = new TopicUniqIdCountResp();
            $resp->mergeFromString(self::unpack($respData));
            foreach ($resp->getItems() as $currentTopic => $count) {
                if (!isset($ret[$currentTopic])) {
                    $ret[$currentTopic] = [
                        'topic' => $currentTopic,
                        'count' => $count,
                    ];
                    continue;
                }
                $ret[$currentTopic]['count'] += $count;
            }
        }
        return array_values($ret);
    }

    /**
     * 获取目标uniqId在网关中存储的信息
     * @param array|string|string[] $uniqIds
     * @return array[]
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public static function connInfo(array|string $uniqIds): array
    {
        $uniqIds = (array)$uniqIds;
        if (self::isSinglePoint() || count($uniqIds) == 1) {
            $socket = self::getSocketByUniqId($uniqIds[0]);
            if (!$socket instanceof TaskSocketInterface) {
                return [];
            }
            $connInfoReq = (new ConnInfoReq())->setUniqIds($uniqIds);
            $req = self::pack(Cmd::ConnInfo, $connInfoReq->serializeToString());
            $socket->send($req);
            $respData = $socket->receive();
            if ($respData === '' || $respData === false) {
                throw new SocketReceiveException('call Cmd::ConnInfo failed because the connection to the netsvr was disconnected');
            }
            $resp = new ConnInfoResp();
            $resp->mergeFromString(self::unpack($respData));
            $ret = [];
            foreach ($resp->getItems() as $currentUniqId => $info) {
                /**
                 * @var $info ConnInfoRespItem
                 */
                $ret[] = [
                    'uniqId' => $currentUniqId,
                    'topics' => self::repeatedFieldToArray($info->getTopics()),
                    'session' => $info->getSession(),
                ];
            }
            return $ret;
        }
        $serverGroup = self::uniqIdsGroupByServerId($uniqIds);
        if (empty($serverGroup)) {
            return [];
        }
        $ret = [];
        foreach ($serverGroup as $serverId => $currentUniqIds) {
            /**
             * @var $socket TaskSocketInterface
             */
            $socket = Container::getInstance()->get(TaskSocketMangerInterface::class)->getSocket($serverId);
            if ($socket instanceof TaskSocketInterface) {
                $connInfoReq = (new ConnInfoReq())->setUniqIds($currentUniqIds)->serializeToString();
                $req = self::pack(Cmd::ConnInfo, $connInfoReq);
                $socket->send($req);
                $respData = $socket->receive();
                if ($respData === '' || $respData === false) {
                    throw new SocketReceiveException('call Cmd::ConnInfo failed because the connection to the netsvr was disconnected');
                }
                $resp = new ConnInfoResp();
                $resp->mergeFromString(self::unpack($respData));
                foreach ($resp->getItems() as $currentUniqId => $info) {
                    /**
                     * @var $info ConnInfoRespItem
                     */
                    $ret[] = [
                        'uniqId' => $currentUniqId,
                        'topics' => self::repeatedFieldToArray($info->getTopics()),
                        'session' => $info->getSession(),
                    ];
                }
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
        $sockets = Container::getInstance()->get(TaskSocketMangerInterface::class)->getSockets();
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
            foreach ($resp->getItems() as $metricsItem => $metricsValue) {
                /**
                 * @var $metricsValue MetricsRespItem
                 */
                $ret[] = [
                    //网关服务的severId
                    'serverId' => $resp->getServerId(),
                    //统计的服务状态项，具体含义请移步：https://github.com/buexplain/netsvr/blob/main/internal/metrics/metrics.go
                    'item' => $metricsItem,
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
     * @param int $serverId 需要变更的目标网关，如果是-1则表示与所有网关进行交互
     * @return array[]
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public static function limit(LimitReq $limitReq = null, int $serverId = -1): array
    {
        if ($serverId === -1) {
            $sockets = Container::getInstance()->get(TaskSocketMangerInterface::class)->getSockets();
        } else {
            $resp = Container::getInstance()->get(TaskSocketMangerInterface::class)->getSocket($serverId);
            if (!empty($resp)) {
                $sockets = [$resp];
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
            foreach ($resp->getItems() as $item) {
                /**
                 * @var $item LimitRespItem
                 */
                $ret[] = [
                    //网关服务的severId
                    'serverId' => $resp->getServerId(),
                    //限流器的名字
                    'name' => $item->getName(),
                    //使用该限流器的workerId，就是business向网关发起注册的时候填写的workerId
                    'workerIds' => self::repeatedFieldToArray($item->getWorkerIds()),
                    //当前这些workerId每秒最多接收到的网关转发数据次数，注意是这些workerId的每秒接收次数，不是单个workerId的每秒接收次数
                    'concurrency' => $item->getConcurrency(),
                ];
            }
        }
        return $ret;
    }

    /**
     * @param string $data
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    protected static function sendToSockets(string $data): void
    {
        $sockets = Container::getInstance()->get(TaskSocketMangerInterface::class)->getSockets();
        if (!empty($sockets)) {
            foreach ($sockets as $socket) {
                $socket->send($data);
            }
        }
    }

    /**
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
     * @param int $serverId
     * @param string $data
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    protected static function sendToSocketByServerId(int $serverId, string $data): void
    {
        /**
         * @var $socket TaskSocketInterface
         */
        $socket = Container::getInstance()->get(TaskSocketMangerInterface::class)->getSocket($serverId);
        if (!empty($socket)) {
            $socket->send($data);
        }
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    protected static function getSocketByUniqId(string $uniqId): ?TaskSocketInterface
    {
        $serverId = Container::getInstance()->get(ServerIdConvertInterface::class)->single($uniqId);
        return Container::getInstance()->get(TaskSocketMangerInterface::class)->getSocket($serverId);
    }

    /**
     * 判断网关是否为单点部署
     * @return bool
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    protected static function isSinglePoint(): bool
    {
        return count(Container::getInstance()->get(TaskSocketMangerInterface::class)->getSockets()) == 1;
    }

    /**
     * 将uniqId进行分组，同一网关的归到一组
     * @param array $uniqIds 包含uniqId的数组
     * @return array key是serverId，value是包含uniqId的数组
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    protected static function uniqIdsGroupByServerId(array $uniqIds): array
    {
        /**
         * @var $serverIdConvert ServerIdConvertInterface
         */
        $serverIdConvert = Container::getInstance()->get(ServerIdConvertInterface::class);
        $convert = $serverIdConvert->bulk($uniqIds);
        $serverIdUniqIds = [];
        foreach ($convert as $uniqId => $serverId) {
            $serverIdUniqIds[$serverId][] = $uniqId;
        }
        return $serverIdUniqIds;
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
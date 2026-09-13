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
use NetsvrBusiness\Ret\CheckOnlineRet;
use NetsvrBusiness\Ret\ConnInfoByCustomerIdRet;
use NetsvrBusiness\Ret\ConnInfoRet;
use NetsvrBusiness\Ret\CustomerIdCountRet;
use NetsvrBusiness\Ret\CustomerIdListRet;
use NetsvrBusiness\Ret\LimitRet;
use NetsvrBusiness\Ret\MetricsRet;
use NetsvrBusiness\Ret\TopicCountRet;
use NetsvrBusiness\Ret\TopicCustomerIdCountRet;
use NetsvrBusiness\Ret\TopicCustomerIdListRet;
use NetsvrBusiness\Ret\TopicCustomerIdToUniqIdsListRet;
use NetsvrBusiness\Ret\TopicListRet;
use NetsvrProtocol\BroadcastBulk;
use NetsvrProtocol\CheckOnlineReq;
use NetsvrProtocol\CheckOnlineResp;
use NetsvrProtocol\Cmd;
use NetsvrProtocol\ConnInfoByCustomerIdReq;
use NetsvrProtocol\ConnInfoByCustomerIdResp;
use NetsvrProtocol\ConnInfoDelete;
use NetsvrProtocol\ConnInfoReq;
use NetsvrProtocol\ConnInfoResp;
use NetsvrProtocol\ConnInfoUpdate;
use NetsvrProtocol\CustomerIdCountResp;
use NetsvrProtocol\CustomerIdListResp;
use NetsvrProtocol\ForceOffline;
use NetsvrProtocol\ForceOfflineByCustomerId;
use NetsvrProtocol\ForceOfflineGuest;
use NetsvrProtocol\LimitReq;
use NetsvrProtocol\LimitResp;
use NetsvrProtocol\MetricsResp;
use NetsvrProtocol\SingleCastBulk;
use NetsvrProtocol\SingleCastBulkByCustomerId;
use NetsvrProtocol\SingleCastBulkByCustomerIdItem;
use NetsvrProtocol\SingleCastBulkItem;
use NetsvrProtocol\TopicCountResp;
use NetsvrProtocol\TopicCustomerIdCountReq;
use NetsvrProtocol\TopicCustomerIdCountResp;
use NetsvrProtocol\TopicCustomerIdListReq;
use NetsvrProtocol\TopicCustomerIdListResp;
use NetsvrProtocol\TopicCustomerIdToUniqIdsListReq;
use NetsvrProtocol\TopicCustomerIdToUniqIdsListResp;
use NetsvrProtocol\TopicDelete;
use NetsvrProtocol\TopicListResp;
use NetsvrProtocol\TopicPublishBulk;
use NetsvrProtocol\TopicPublishBulkItem;
use NetsvrProtocol\TopicSubscribe;
use NetsvrProtocol\TopicUniqIdCountReq;
use NetsvrProtocol\TopicUniqIdCountResp;
use NetsvrBusiness\Ret\TopicUniqIdCountRet;
use NetsvrProtocol\TopicUniqIdListReq;
use NetsvrProtocol\TopicUniqIdListResp;
use NetsvrBusiness\Ret\TopicUniqIdListRet;
use NetsvrProtocol\TopicUnsubscribe;
use NetsvrProtocol\UniqIdCountResp;
use NetsvrBusiness\Ret\UniqIdCountRet;
use NetsvrProtocol\UniqIdListResp;
use NetsvrBusiness\Ret\UniqIdListRet;
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
     * 批量广播，网关按顺序把每一条数据广播给全部连接
     * @param array|string[] $data 需要广播的数据列表
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function broadcastBulk(array $data): void
    {
        if (empty($data)) {
            return;
        }
        $broadcastBulk = new BroadcastBulk();
        $broadcastBulk->setData($data);
        self::sendToSockets(self::pack(Cmd::BroadcastBulk, $broadcastBulk->serializeToString()));
    }

    /**
     * 广播一条数据给全部连接，等价于 broadcastBulk 只传一条数据
     * @param string $data 需要发送的数据
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function broadcast(string $data): void
    {
        self::broadcastBulk([$data]);
    }

    /**
     * 给一个连接发送一条数据
     * @param string $uniqId 目标连接的网关uniqId
     * @param string $data 需要发送的数据
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function sendToUniqId(string $uniqId, string $data): void
    {
        $item = new SingleCastBulkItem();
        $item->setUniqIds([$uniqId]);
        $item->setData([$data]);
        self::singleCastBulk([$item]);
    }

    /**
     * 给一组连接发送同一条数据
     * @param array|string|string[] $uniqIds 目标连接的网关uniqId
     * @param string $data 需要发送的数据
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function sendToUniqIds(array|string $uniqIds, string $data): void
    {
        $item = new SingleCastBulkItem();
        $item->setUniqIds((array)$uniqIds);
        $item->setData([$data]);
        self::singleCastBulk([$item]);
    }

    /**
     * 按uniqId批量单播，每一项是一组uniqId与其数据，
     * 网关会把本项内每一条数据按顺序发给本项内的每一个uniqId
     * @param SingleCastBulkItem[] $items 目标与数据的配对列表
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function singleCastBulk(array $items): void
    {
        if (empty($items)) {
            return;
        }
        //网关是单点部署，则直接发送
        if (self::isSinglePoint()) {
            $singleCastBulk = new SingleCastBulk();
            $singleCastBulk->setItems($items);
            self::sendToSockets(self::pack(Cmd::SingleCastBulk, $singleCastBulk->serializeToString()));
            return;
        }
        //网关是多机器部署，需要按每个uniqId所在网关拆分，再分别发送到对应网关
        $bulks = [];
        foreach ($items as $item) {
            foreach ($item->getUniqIds() as $uniqId) {
                $bulkItem = new SingleCastBulkItem();
                $bulkItem->setUniqIds([$uniqId]);
                $bulkItem->setData(repeatedFieldToArray($item->getData()));
                $bulks[uniqIdConvertToAddrAsHex($uniqId)][] = $bulkItem;
            }
        }
        foreach ($bulks as $addrAsHex => $currentItems) {
            $singleCastBulk = new SingleCastBulk();
            $singleCastBulk->setItems($currentItems);
            self::sendToSocketByAddrAsHex($addrAsHex, self::pack(Cmd::SingleCastBulk, $singleCastBulk->serializeToString()));
        }
    }

    /**
     * 按customerId批量单播，每一项是一组customerId与其数据，
     * 网关会把本项内每一条数据按顺序发给本项内每一个customerId对应的所有连接
     * @param SingleCastBulkByCustomerIdItem[] $items 目标与数据的配对列表
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function singleCastBulkByCustomerId(array $items): void
    {
        if (empty($items)) {
            return;
        }
        $singleCastBulkByCustomerId = new SingleCastBulkByCustomerId();
        $singleCastBulkByCustomerId->setItems($items);
        //因为不知道客户id在哪个网关，所以给所有网关发送
        self::sendToSockets(self::pack(Cmd::SingleCastBulkByCustomerId, $singleCastBulkByCustomerId->serializeToString()));
    }

    /**
     * 给一个客户的所有连接发送一条数据
     * @param string|int $customerId 目标客户的customerId
     * @param string $data 需要发送的数据
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function sendToCustomerId(string|int $customerId, string $data): void
    {
        self::sendToCustomerIds([$customerId], $data);
    }

    /**
     * 给一组客户的所有连接发送同一条数据
     * @param array|string|int|string[]|int[] $customerIds 目标客户的customerId
     * @param string $data 需要发送的数据
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function sendToCustomerIds(array|string|int $customerIds, string $data): void
    {
        $item = new SingleCastBulkByCustomerIdItem();
        $item->setCustomerIds(array_map('strval', (array)$customerIds));
        $item->setData([$data]);
        self::singleCastBulkByCustomerId([$item]);
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
     * 批量发布，每一项是一组主题与其数据，
     * 网关会把本项内每一条数据按顺序发布给本项内每一个主题的所有订阅连接
     * @param TopicPublishBulkItem[] $items 主题与数据的配对列表
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function topicPublishBulk(array $items): void
    {
        if (empty($items)) {
            return;
        }
        $topicPublishBulk = new TopicPublishBulk();
        $topicPublishBulk->setItems($items);
        self::sendToSockets(self::pack(Cmd::TopicPublishBulk, $topicPublishBulk->serializeToString()));
    }

    /**
     * 给一个主题发布一条数据
     * @param string $topic 目标主题
     * @param string $data 需要发给客户的数据
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function publishToTopic(string $topic, string $data): void
    {
        $item = new TopicPublishBulkItem();
        $item->setTopics([$topic]);
        $item->setData([$data]);
        self::topicPublishBulk([$item]);
    }

    /**
     * 给一组主题发布同一条数据
     * @param array|string|string[] $topics 目标主题
     * @param string $data 需要发给客户的数据
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function publishToTopics(array|string $topics, string $data): void
    {
        $item = new TopicPublishBulkItem();
        $item->setTopics((array)$topics);
        $item->setData([$data]);
        self::topicPublishBulk([$item]);
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
        $group = self::getUniqIdsGroupByAddrAsHex($uniqIds);
        foreach ($group as $addrAsHex => $currentUniqIds) {
            $forceOffline = new ForceOffline();
            $forceOffline->setUniqIds($currentUniqIds);
            $forceOffline->setData($data);
            self::sendToSocketByAddrAsHex($addrAsHex, self::pack(Cmd::ForceOffline, $forceOffline->serializeToString()));
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
        $group = self::getUniqIdsGroupByAddrAsHex($uniqIds);
        foreach ($group as $addrAsHex => $currentUniqIds) {
            $forceOfflineGuest = new ForceOfflineGuest();
            $forceOfflineGuest->setUniqIds($currentUniqIds);
            $forceOfflineGuest->setData($data);
            $forceOfflineGuest->setDelay($delay);
            self::sendToSocketByAddrAsHex($addrAsHex, self::pack(Cmd::ForceOfflineGuest, $forceOfflineGuest->serializeToString()));
        }
    }

    /**
     * 检查是否在线
     * @param array|string|string[] $uniqIds 包含uniqId的索引数组，或者是单个uniqId
     * @return CheckOnlineRet
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public static function checkOnline(array|string $uniqIds): CheckOnlineRet
    {
        $uniqIds = (array)$uniqIds;
        $ret = new CheckOnlineRet();
        //网关单点部署，或者是只有一个待检查的uniqId，则直接获取与网关的socket连接进行操作
        if (self::isSinglePoint() || count($uniqIds) == 1) {
            $socket = self::getSocketByUniqId($uniqIds[array_key_last($uniqIds)]);
            if (!$socket instanceof TaskSocketInterface) {
                return $ret;
            }
            $checkOnlineReq = (new CheckOnlineReq())->setUniqIds($uniqIds);
            $socket->send(self::pack(Cmd::CheckOnline, $checkOnlineReq->serializeToString()));
            $respData = $socket->receive();
            if ($respData === '' || $respData === false) {
                throw new SocketReceiveException('call Cmd::CheckOnline failed because the connection to the netsvr was disconnected');
            }
            $resp = new CheckOnlineResp();
            $resp->mergeFromString(self::unpack($respData));
            $ret->data[$socket->getAddr()] = $resp;
            return $ret;
        }
        //网关是多机器部署的，先对uniqId按所属网关进行分组
        $group = self::getUniqIdsGroupByAddrAsHex($uniqIds);
        if (empty($group)) {
            return $ret;
        }
        //再的向每个网关请求
        foreach ($group as $addrAsHex => $currentUniqIds) {
            $socket = self::getTaskSocketManger()->getSocket($addrAsHex);
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
                $ret->data[$socket->getAddr()] = $resp;
            }
        }
        return $ret;
    }

    /**
     * 获取网关中的uniqId
     * @return UniqIdListRet
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    public static function uniqIdList(): UniqIdListRet
    {
        $sockets = self::getTaskSocketManger()->getSockets();
        $req = self::pack(Cmd::UniqIdList, '');
        $ret = new UniqIdListRet();
        foreach ($sockets as $socket) {
            $socket->send($req);
            $respData = $socket->receive();
            if ($respData === '' || $respData === false) {
                throw new SocketReceiveException('call Cmd::UniqIdList failed because the connection to the netsvr was disconnected');
            }
            $resp = new UniqIdListResp();
            $resp->mergeFromString(self::unpack($respData));
            $ret->data[$socket->getAddr()] = $resp;
        }
        return $ret;
    }

    /**
     * 统计网关的在线连接数
     * @return UniqIdCountRet
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public static function uniqIdCount(): UniqIdCountRet
    {
        $sockets = self::getTaskSocketManger()->getSockets();
        $req = self::pack(Cmd::UniqIdCount, '');
        $ret = new UniqIdCountRet();
        foreach ($sockets as $socket) {
            $socket->send($req);
            $respData = $socket->receive();
            if ($respData === '' || $respData === false) {
                throw new SocketReceiveException('call Cmd::UniqIdCount failed because the connection to the netsvr was disconnected');
            }
            $resp = new UniqIdCountResp();
            $resp->mergeFromString(self::unpack($respData));
            $ret->data[$socket->getAddr()] = $resp;
        }
        return $ret;
    }

    /**
     * 统计网关的主题数量
     * @return TopicCountRet
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public static function topicCount(): TopicCountRet
    {
        $sockets = self::getTaskSocketManger()->getSockets();
        $req = self::pack(Cmd::TopicCount, '');
        $ret = new TopicCountRet();
        foreach ($sockets as $socket) {
            $socket->send($req);
            $respData = $socket->receive();
            if ($respData === '' || $respData === false) {
                throw new SocketReceiveException('call Cmd::TopicCount failed because the connection to the netsvr was disconnected');
            }
            $resp = new TopicCountResp();
            $resp->mergeFromString(self::unpack($respData));
            $ret->data[$socket->getAddr()] = $resp;
        }
        return $ret;
    }

    /**
     * 获取网关的全部主题
     * @return TopicListRet
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public static function topicList(): TopicListRet
    {
        $sockets = self::getTaskSocketManger()->getSockets();
        $req = self::pack(Cmd::TopicList, '');
        $ret = new TopicListRet();
        foreach ($sockets as $socket) {
            $socket->send($req);
            $respData = $socket->receive();
            if ($respData === '' || $respData === false) {
                throw new SocketReceiveException('call Cmd::TopicList failed because the connection to the netsvr was disconnected');
            }
            $resp = new TopicListResp();
            $resp->mergeFromString(self::unpack($respData));
            $ret->data[$socket->getAddr()] = $resp;
        }
        return $ret;
    }

    /**
     * 获取网关中某几个主题包含的uniqId
     * @param array|string|string[] $topics
     * @return TopicUniqIdListRet
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public static function topicUniqIdList(array|string $topics): TopicUniqIdListRet
    {
        $sockets = self::getTaskSocketManger()->getSockets();
        $req = self::pack(Cmd::TopicUniqIdList, (new TopicUniqIdListReq())->setTopics((array)$topics)->serializeToString());
        $ret = new TopicUniqIdListRet();
        foreach ($sockets as $socket) {
            $socket->send($req);
            $respData = $socket->receive();
            if ($respData === '' || $respData === false) {
                throw new SocketReceiveException('call Cmd::TopicUniqIdList failed because the connection to the netsvr was disconnected');
            }
            $resp = new TopicUniqIdListResp();
            $resp->mergeFromString(self::unpack($respData));
            $ret->data[$socket->getAddr()] = $resp;
        }
        return $ret;
    }

    /**
     * 统计网关中某几个主题包含的连接数，topics为空即统计全部主题，统计结果是去重的
     * @param array|string|string[] $topics 需要统计连接数的主题
     * @return TopicUniqIdCountRet
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public static function topicUniqIdCount(array|string $topics): TopicUniqIdCountRet
    {
        $sockets = self::getTaskSocketManger()->getSockets();
        $req = self::pack(Cmd::TopicUniqIdCount, (new TopicUniqIdCountReq())->setTopics((array)$topics)->serializeToString());
        $ret = new TopicUniqIdCountRet();
        foreach ($sockets as $socket) {
            $socket->send($req);
            $respData = $socket->receive();
            if ($respData === '' || $respData === false) {
                throw new SocketReceiveException('call Cmd::TopicUniqIdCount failed because the connection to the netsvr was disconnected');
            }
            $resp = new TopicUniqIdCountResp();
            $resp->mergeFromString(self::unpack($respData));
            $ret->data[$socket->getAddr()] = $resp;
        }
        return $ret;
    }

    /**
     * 获取网关中某几个主题包含的customerId
     * @param array|string|string[] $topics
     * @return TopicCustomerIdListRet
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public static function topicCustomerIdList(array|string $topics): TopicCustomerIdListRet
    {
        $sockets = self::getTaskSocketManger()->getSockets();
        $req = self::pack(Cmd::TopicCustomerIdList, (new TopicCustomerIdListReq())->setTopics((array)$topics)->serializeToString());
        $ret = new TopicCustomerIdListRet();
        foreach ($sockets as $socket) {
            $socket->send($req);
            $respData = $socket->receive();
            if ($respData === '' || $respData === false) {
                throw new SocketReceiveException('call Cmd::TopicCustomerIdList failed because the connection to the netsvr was disconnected');
            }
            $resp = new TopicCustomerIdListResp();
            $resp->mergeFromString(self::unpack($respData));
            $ret->data[$socket->getAddr()] = $resp;
        }
        return $ret;
    }

    /**
     * 获取网关中目标topic的customerId以及对应的uniqId列表
     * @param array|string|string[] $topics
     * @return TopicCustomerIdToUniqIdsListRet
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public static function topicCustomerIdToUniqIdsList(array|string $topics): TopicCustomerIdToUniqIdsListRet
    {
        $sockets = self::getTaskSocketManger()->getSockets();
        $req = self::pack(Cmd::TopicCustomerIdToUniqIdsList, (new TopicCustomerIdToUniqIdsListReq())->setTopics((array)$topics)->serializeToString());
        $ret = new TopicCustomerIdToUniqIdsListRet();
        foreach ($sockets as $socket) {
            $socket->send($req);
            $respData = $socket->receive();
            if ($respData === '' || $respData === false) {
                throw new SocketReceiveException('call Cmd::TopicCustomerIdToUniqIdsList failed because the connection to the netsvr was disconnected');
            }
            $resp = new TopicCustomerIdToUniqIdsListResp();
            $resp->mergeFromString(self::unpack($respData));
            $ret->data[$socket->getAddr()] = $resp;
        }
        return $ret;
    }

    /**
     * 统计网关中某几个主题包含的customerId数量，topics为空即统计全部主题，统计结果是去重的
     * @param array|string|string[] $topics 需要统计的主题
     * @return TopicCustomerIdCountRet
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public static function topicCustomerIdCount(array|string $topics): TopicCustomerIdCountRet
    {
        $sockets = self::getTaskSocketManger()->getSockets();
        $req = self::pack(Cmd::TopicCustomerIdCount, (new TopicCustomerIdCountReq())->setTopics((array)$topics)->serializeToString());
        $ret = new TopicCustomerIdCountRet();
        foreach ($sockets as $socket) {
            $socket->send($req);
            $respData = $socket->receive();
            if ($respData === '' || $respData === false) {
                throw new SocketReceiveException('call Cmd::TopicCustomerIdCount failed because the connection to the netsvr was disconnected');
            }
            $resp = new TopicCustomerIdCountResp();
            $resp->mergeFromString(self::unpack($respData));
            $ret->data[$socket->getAddr()] = $resp;
        }
        return $ret;
    }

    /**
     * 获取目标uniqId在网关中存储的信息
     * @param array|string $uniqIds
     * @param bool $reqSession 是否请求session
     * @param bool $reqCustomerId 是否请求customerId
     * @param bool $reqTopic 是否请求topic
     * @return ConnInfoRet
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    public static function connInfo(array|string $uniqIds, bool $reqSession = true, bool $reqCustomerId = true, bool $reqTopic = true): ConnInfoRet
    {
        $uniqIds = (array)$uniqIds;
        $f = function ($uniqIds) use ($reqSession, $reqCustomerId, $reqTopic): string {
            $connInfoReq = (new ConnInfoReq())->setUniqIds($uniqIds);
            $connInfoReq->setReqSession($reqSession);
            $connInfoReq->setReqCustomerId($reqCustomerId);
            $connInfoReq->setReqTopic($reqTopic);
            return $connInfoReq->serializeToString();
        };
        $ret = new ConnInfoRet();
        if (self::isSinglePoint() || count($uniqIds) == 1) {
            $socket = self::getSocketByUniqId($uniqIds[array_key_last($uniqIds)]);
            if (!$socket instanceof TaskSocketInterface) {
                return $ret;
            }
            $req = self::pack(Cmd::ConnInfo, $f($uniqIds));
            $socket->send($req);
            $respData = $socket->receive();
            if ($respData === '' || $respData === false) {
                throw new SocketReceiveException('call Cmd::ConnInfo failed because the connection to the netsvr was disconnected');
            }
            $resp = new ConnInfoResp();
            $resp->mergeFromString(self::unpack($respData));
            $ret->data[$socket->getAddr()] = $resp;
            return $ret;
        }
        $group = self::getUniqIdsGroupByAddrAsHex($uniqIds);
        if (empty($group)) {
            return $ret;
        }
        foreach ($group as $addrAsHex => $currentUniqIds) {
            /**
             * @var $socket TaskSocketInterface
             */
            $socket = self::getTaskSocketManger()->getSocket($addrAsHex);
            if ($socket instanceof TaskSocketInterface) {
                $req = self::pack(Cmd::ConnInfo, $f($currentUniqIds));
                $socket->send($req);
                $respData = $socket->receive();
                if ($respData === '' || $respData === false) {
                    throw new SocketReceiveException('call Cmd::ConnInfo failed because the connection to the netsvr was disconnected');
                }
                $resp = new ConnInfoResp();
                $resp->mergeFromString(self::unpack($respData));
                $ret->data[$socket->getAddr()] = $resp;
            }
        }
        return $ret;
    }

    /**
     * 获取目标customerId在网关中存储的信息
     * @param array|string $customerIds
     * @param bool $reqSession 是否请求session
     * @param bool $reqUniqId 是否请求uniqId
     * @param bool $reqTopic 是否请求topic
     * @return ConnInfoByCustomerIdRet
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    public static function connInfoByCustomerId(array|string $customerIds, bool $reqSession = true, bool $reqUniqId = true, bool $reqTopic = true): ConnInfoByCustomerIdRet
    {
        $sockets = self::getTaskSocketManger()->getSockets();
        if (empty($sockets)) {
            return new ConnInfoByCustomerIdRet();
        }
        $connInfoByCustomerIdReq = (new ConnInfoByCustomerIdReq())->setCustomerIds((array)$customerIds);
        $connInfoByCustomerIdReq->setReqSession($reqSession);
        $connInfoByCustomerIdReq->setReqUniqId($reqUniqId);
        $connInfoByCustomerIdReq->setReqTopic($reqTopic);
        $req = self::pack(Cmd::ConnInfoByCustomerId, $connInfoByCustomerIdReq->serializeToString());
        $ret = new ConnInfoByCustomerIdRet();
        //因为不知道客户id在哪个网关，所以给所有网关发送
        foreach ($sockets as $socket) {
            $socket->send($req);
            $respData = $socket->receive();
            if ($respData === '' || $respData === false) {
                throw new SocketReceiveException('call Cmd::ConnInfoByCustomerId failed because the connection to the netsvr was disconnected');
            }
            $resp = new ConnInfoByCustomerIdResp();
            $resp->mergeFromString(self::unpack($respData));
            $ret->data[$socket->getAddr()] = $resp;
        }
        return $ret;
    }

    /**
     * @return MetricsRet
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public static function metrics(): MetricsRet
    {
        $sockets = self::getTaskSocketManger()->getSockets();
        if (empty($sockets)) {
            return new MetricsRet();
        }
        $req = self::pack(Cmd::Metrics, '');
        $ret = new MetricsRet();
        foreach ($sockets as $socket) {
            $socket->send($req);
            $respData = $socket->receive();
            if ($respData === '' || $respData === false) {
                throw new SocketReceiveException('call Cmd::Metrics failed because the connection to the netsvr was disconnected');
            }
            $resp = new MetricsResp();
            $resp->mergeFromString(self::unpack($respData));
            $ret->data[$socket->getAddr()] = $resp;
        }
        return $ret;
    }

    /**
     * 设置或读取网关针对business的每秒转发数量的限制的配置
     * @param LimitReq|null $limitReq
     * @param string $addr
     * @return LimitRet
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Exception
     */
    public static function limit(LimitReq|null $limitReq, string $addr = ''): LimitRet
    {
        if ($addr === '') {
            $addrAsHex = '';
        } else {
            $addrAsHex = addrConvertToHex($addr);
        }
        if ($addrAsHex === '') {
            $sockets = self::getTaskSocketManger()->getSockets();
        } else {
            $socket = self::getTaskSocketManger()->getSocket($addrAsHex);
            if (!empty($socket)) {
                $sockets = [$socket];
            } else {
                $sockets = [];
            }
        }
        if (empty($sockets)) {
            return new LimitRet();
        }
        if ($limitReq === null) {
            $limitReq = new LimitReq();
        }
        $req = self::pack(Cmd::Limit, $limitReq->serializeToString());
        $ret = new LimitRet();
        foreach ($sockets as $socket) {
            $socket->send($req);
            $respData = $socket->receive();
            if ($respData === '' || $respData === false) {
                throw new SocketReceiveException('call Cmd::Limit failed because the connection to the netsvr was disconnected');
            }
            $resp = new LimitResp();
            $resp->mergeFromString(self::unpack($respData));
            $ret->data[$socket->getAddr()] = $resp;
        }
        return $ret;
    }

    /**
     * @return CustomerIdListRet
     * @throws NotFoundExceptionInterface
     * @throws Exception
     * @throws ContainerExceptionInterface
     */
    public static function customerIdList(): CustomerIdListRet
    {
        $sockets = self::getTaskSocketManger()->getSockets();
        $req = self::pack(Cmd::CustomerIdList, '');
        $ret = new CustomerIdListRet();
        foreach ($sockets as $socket) {
            $socket->send($req);
            $respData = $socket->receive();
            if ($respData === '' || $respData === false) {
                throw new SocketReceiveException('call Cmd::CustomerIdList failed because the connection to the netsvr was disconnected');
            }
            $resp = new CustomerIdListResp();
            $resp->mergeFromString(self::unpack($respData));
            $ret->data[$socket->getAddr()] = $resp;
        }
        return $ret;
    }

    /**
     * 统计网关的在线客户数
     * 注意各个网关的客户数之和不一定等于总在线客户数，因为可能一个客户有多个设备连接到不同网关
     * @return CustomerIdCountRet
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Throwable
     */
    public static function customerIdCount(): CustomerIdCountRet
    {
        $sockets = self::getTaskSocketManger()->getSockets();
        $req = self::pack(Cmd::CustomerIdCount, '');
        $ret = new CustomerIdCountRet();
        foreach ($sockets as $socket) {
            $socket->send($req);
            $respData = $socket->receive();
            if ($respData === '' || $respData === false) {
                throw new SocketReceiveException('call Cmd::CustomerIdCount failed because the connection to the netsvr was disconnected');
            }
            $resp = new CustomerIdCountResp();
            $resp->mergeFromString(self::unpack($respData));
            $ret->data[$socket->getAddr()] = $resp;
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
     * 向taskAddr对应的网关服务发送数据
     * @param string $addrAsHex
     * @param string $data
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    protected static function sendToSocketByAddrAsHex(string $addrAsHex, string $data): void
    {
        /**
         * @var $socket TaskSocketInterface
         */
        $socket = self::getTaskSocketManger()->getSocket($addrAsHex);
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
        return self::getTaskSocketManger()->getSocket(uniqIdConvertToAddrAsHex($uniqId));
    }

    /**
     * 判断网关是否为单点部署
     * @return bool
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    protected static function isSinglePoint(): bool
    {
        return self::getTaskSocketManger()->count() == 1;
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
     * 根据uniqId列表分组，返回每个addrAsHex对应的uniqId列表
     * @param array $uniqIds 包含uniqId的数组
     * @return array key是网关的task服务器监听ip地址的16进制表示，value是包含uniqId的数组
     */
    protected static function getUniqIdsGroupByAddrAsHex(array $uniqIds): array
    {
        $ret = [];
        // 将uniqId按照worker服务器的监听地址进行分组
        foreach ($uniqIds as $uniqId) {
            $ret[uniqIdConvertToAddrAsHex($uniqId)][] = $uniqId;
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

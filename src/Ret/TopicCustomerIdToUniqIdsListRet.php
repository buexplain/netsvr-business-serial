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

namespace NetsvrBusiness\Ret;

use NetsvrProtocol\CustomerIdToUniqIdsRespItem;
use NetsvrProtocol\TopicCustomerIdToUniqIdsListResp;
use NetsvrProtocol\TopicCustomerIdToUniqIdsListRespItem;
use function NetsvrBusiness\repeatedFieldToArray;

class TopicCustomerIdToUniqIdsListRet
{
    /**
     * key为网关worker服务器地址，value为 TopicCustomerIdToUniqIdsListResp
     * @var array|array<string,TopicCustomerIdToUniqIdsListResp>|TopicCustomerIdToUniqIdsListResp[]
     */
    public array $data = array();

    /**
     * 获取该主题下出现过的customerId，跨网关合并去重（顺序不保证）；主题不存在时返回空数组
     * @param string $topic
     * @return array|string[]
     */
    public function getTopicCustomerIds(string $topic): array
    {
        $seen = array();
        foreach ($this->data as $value) {
            $topicItem = $value->getItems()[$topic] ?? null;
            if ($topicItem === null) {
                continue;
            }
            foreach ($topicItem->getItems() as $customerId => $item) {
                $seen[$customerId] = true;
            }
        }
        return array_keys($seen);
    }

    /**
     * 获取「该主题下该客户」的全部连接；一个连接只属于一个网关，跨网关直接合并即可。
     * 注意：按协议，该列表是该客户在当前网关内的全部连接，不限于该主题。
     * 主题或客户不存在时返回空数组
     * @param string $topic
     * @param string $customerId
     * @return array|string[]
     */
    public function getCustomerUniqIds(string $topic, string $customerId): array
    {
        $ret = array();
        foreach ($this->data as $value) {
            $topicItem = $value->getItems()[$topic] ?? null;
            if ($topicItem === null) {
                continue;
            }
            $item = $topicItem->getItems()[$customerId] ?? null;
            if ($item === null) {
                continue;
            }
            array_push($ret, ...repeatedFieldToArray($item->getUniqIds()));
        }
        return $ret;
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        $ret = array();
        foreach ($this->data as $addr => $value) {
            foreach ($value->getItems() as $topic => $customerIdToUniqIdsList) {
                /**
                 * @var $customerIdToUniqIdsList TopicCustomerIdToUniqIdsListRespItem
                 */
                foreach ($customerIdToUniqIdsList->getItems() as $customerId => $uniqIds) {
                    /**
                     * @var $uniqIds CustomerIdToUniqIdsRespItem
                     */
                    $ret[] = [
                        'addr' => $addr,
                        'topic' => $topic,
                        'customerId' => $customerId,
                        'uniqIds' => repeatedFieldToArray($uniqIds->getUniqIds()),
                    ];
                }
            }
        }
        return $ret;
    }
}
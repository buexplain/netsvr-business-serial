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

use NetsvrProtocol\TopicCustomerIdListResp;
use NetsvrProtocol\TopicCustomerIdListRespItem;
use function NetsvrBusiness\repeatedFieldToArray;

class TopicCustomerIdListRet
{
    /**
     * key为网关worker服务器地址，value为 TopicCustomerIdListResp
     * @var array|array<string,TopicCustomerIdListResp>|TopicCustomerIdListResp[]
     */
    public array $data = array();

    /**
     * 获取该主题包含的customerId；同一个客户可能连接到多个网关，跨网关合并后去重。
     * 主题不存在时返回空数组
     * @param string $topic
     * @return array|string[]
     */
    public function getTopicCustomerIds(string $topic): array
    {
        $ret = array();
        foreach ($this->data as $value) {
            $item = $value->getItems()[$topic] ?? null;
            if ($item === null) {
                continue;
            }
            array_push($ret, ...repeatedFieldToArray($item->getCustomerIds()));
        }
        return array_values(array_unique($ret));
    }

    /**
     * 获取所有主题及其customerId；同一个客户可能连接到多个网关，每个主题的列表跨网关合并后去重
     * @return array|array<string,array<int,string>>
     */
    public function getCustomerIds(): array
    {
        $ret = array();
        foreach ($this->data as $value) {
            foreach ($value->getItems() as $topic => $item) {
                /**
                 * @var $item TopicCustomerIdListRespItem
                 */
                if (!isset($ret[$topic])) {
                    $ret[$topic] = array();
                }
                array_push($ret[$topic], ...repeatedFieldToArray($item->getCustomerIds()));
            }
        }
        foreach ($ret as $topic => $customerIds) {
            $ret[$topic] = array_values(array_unique($customerIds));
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
            foreach ($value->getItems() as $topic => $item) {
                /**
                 * @var $item TopicCustomerIdListRespItem
                 */
                $ret[] = [
                    'addr' => $addr,
                    'topic' => $topic,
                    'customerIds' => repeatedFieldToArray($item->getCustomerIds()),
                ];
            }
        }
        return $ret;
    }
}
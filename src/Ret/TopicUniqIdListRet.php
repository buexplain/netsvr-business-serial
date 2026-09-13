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

use NetsvrProtocol\TopicUniqIdListResp;
use NetsvrProtocol\TopicUniqIdListRespItem;
use function NetsvrBusiness\repeatedFieldToArray;

class TopicUniqIdListRet
{
    /**
     * key为网关worker服务器地址，value为 TopicUniqIdListResp
     * @var array|array<string,TopicUniqIdListResp>|TopicUniqIdListResp[]
     */
    public array $data = array();

    /**
     * 获取该主题包含的uniqId；一个连接只属于一个网关，各网关的uniqId不会重复，直接合并即可。
     * 主题不存在时返回空数组
     * @param string $topic
     * @return array|string[]
     */
    public function getTopicUniqIds(string $topic): array
    {
        $ret = [];
        foreach ($this->data as $value) {
            $item = $value->getItems()[$topic] ?? null;
            if ($item === null) {
                continue;
            }
            array_push($ret, ...repeatedFieldToArray($item->getUniqIds()));
        }
        return $ret;
    }

    /**
     * 获取所有主题及其uniqId；一个连接只属于一个网关，同一个主题的uniqId跨网关直接合并即可
     * @return array|array<string,array<int,string>>
     */
    public function getUniqIds(): array
    {
        $ret = [];
        foreach ($this->data as $value) {
            foreach ($value->getItems() as $topic => $item) {
                /**
                 * @var $item TopicUniqIdListRespItem
                 */
                if (!isset($ret[$topic])) {
                    $ret[$topic] = [];
                }
                array_push($ret[$topic], ...repeatedFieldToArray($item->getUniqIds()));
            }
        }
        return $ret;
    }

    public function toArray(): array
    {
        $ret = [];
        foreach ($this->data as $addr => $value) {
            foreach ($value->getItems() as $topic => $item) {
                /**
                 * @var $item TopicUniqIdListRespItem
                 */
                $ret[] = [
                    'addr' => $addr,
                    'topic' => $topic,
                    'uniqIds' => repeatedFieldToArray($item->getUniqIds()),
                ];
            }
        }
        return $ret;
    }
}
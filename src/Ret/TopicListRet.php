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

use NetsvrProtocol\TopicListResp;
use function NetsvrBusiness\repeatedFieldToArray;

class TopicListRet
{
    /**
     * key为网关worker服务器地址，value为 TopicListResp
     * @var array|array<string,TopicListResp>|TopicListResp[]
     */
    public array $data = array();

    /**
     * 获取所有主题；同名主题可能分布在多个网关，跨网关合并后去重
     * @return array|string[]
     */
    public function getTopics(): array
    {
        $ret = array();
        foreach ($this->data as $value) {
            array_push($ret, ...repeatedFieldToArray($value->getTopics()));
        }
        return array_values(array_unique($ret));
    }

    /**
     * 判断网关中是否存在该主题
     * @param string $topic
     * @return bool
     */
    public function has(string $topic): bool
    {
        foreach ($this->data as $value) {
            foreach ($value->getTopics() as $topicValue) {
                if ($topicValue === $topic) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        $ret = array();
        foreach ($this->data as $addr => $value) {
            $ret[] = [
                'addr' => $addr,
                'topics' => repeatedFieldToArray($value->getTopics()),
            ];
        }
        return $ret;
    }
}
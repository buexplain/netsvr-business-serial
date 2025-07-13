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
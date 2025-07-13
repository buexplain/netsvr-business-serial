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

use NetsvrProtocol\ConnInfoResp;
use NetsvrProtocol\ConnInfoRespItem;
use function NetsvrBusiness\repeatedFieldToArray;

class ConnInfoRet
{
    /**
     * key为网关worker服务器地址，value为 ConnInfoResp
     * @var array|array<string,ConnInfoResp>|ConnInfoResp[]
     */
    public array $data = array();

    /**
     * 获取所有ConnInfoRespItem
     * @return array|ConnInfoRespItem[]
     */
    public function getItems(): array
    {
        $items = [];
        foreach ($this->data as $resp) {
            foreach ($resp->getItems() as $uniqId => $item) {
                /**
                 * @var $item ConnInfoRespItem
                 */
                $items[$uniqId] = $item;
            }
        }
        return $items;
    }

    /**
     * 转为数组
     * @return array
     */
    public function toArray(): array
    {
        $ret = array();
        foreach ($this->data as $addr => $resp) {
            foreach ($resp->getItems() as $uniqId => $item) {
                /**
                 * @var $item ConnInfoRespItem
                 */
                $ret[] = [
                    'addr' => $addr,
                    'uniqId' => $uniqId,
                    'customerId' => $item->getCustomerId(),
                    'session' => $item->getSession(),
                    'topics' => repeatedFieldToArray($item->getTopics()),
                ];
            }
        }
        return $ret;
    }
}
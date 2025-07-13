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

use NetsvrProtocol\ConnInfoByCustomerIdResp;
use NetsvrProtocol\ConnInfoByCustomerIdRespItem;
use NetsvrProtocol\ConnInfoByCustomerIdRespItems;
use function NetsvrBusiness\repeatedFieldToArray;

class ConnInfoByCustomerIdRet
{
    /**
     * key为网关worker服务器地址，value为 ConnInfoByCustomerIdResp
     * @var array|array<string,ConnInfoByCustomerIdResp>|ConnInfoByCustomerIdResp[]
     */
    public array $data = array();

    /**
     * 获取所有连接信息
     * @return array|array<string,array<ConnInfoByCustomerIdRespItem>>
     */
    public function getItems(): array
    {
        $ret = array();
        foreach ($this->data as $value) {
            foreach ($value->getItems() as $customerId => $info) {
                /**
                 * @var $info ConnInfoByCustomerIdRespItems
                 */
                foreach ($info->getItems() as $item) {
                    /**
                     * @var $item ConnInfoByCustomerIdRespItem
                     */
                    $ret[$customerId][] = $item;
                }
            }
        }
        return $ret;
    }

    /**
     * 获取所有连接的uniqId
     * @return array
     */
    public function getUniqIds(): array
    {
        $ret = array();
        foreach ($this->data as $value) {
            foreach ($value->getItems() as $info) {
                /**
                 * @var $info ConnInfoByCustomerIdRespItems
                 */
                foreach ($info->getItems() as $item) {
                    /**
                     * @var $item ConnInfoByCustomerIdRespItem
                     */
                    $ret[] = $item->getUniqId();
                }
            }
        }
        return $ret;
    }

    /**
     * 转为数组
     * @return array
     */
    public function toArray(): array
    {
        $ret = array();
        foreach ($this->data as $addr => $value) {
            foreach ($value->getItems() as $customerId => $info) {
                /**
                 * @var $info ConnInfoByCustomerIdRespItems
                 */
                foreach ($info->getItems() as $item) {
                    /**
                     * @var $item ConnInfoByCustomerIdRespItem
                     */
                    $ret[] = [
                        'addr' => $addr,
                        'customerId' => $customerId,
                        'uniqId' => $item->getUniqId(),
                        'session' => $item->getSession(),
                        'topics' => repeatedFieldToArray($item->getTopics()),
                    ];
                }
            }
        }
        return $ret;
    }
}
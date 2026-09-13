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

use NetsvrProtocol\CustomerIdListResp;
use function NetsvrBusiness\repeatedFieldToArray;

class CustomerIdListRet
{
    /**
     * key为网关worker服务器地址，value为 CustomerIdListResp
     * @var array|array<string,CustomerIdListResp>|CustomerIdListResp[]
     */
    public array $data = array();

    /**
     * 获取所有customerId；同一个客户可能连接到多个网关，跨网关合并后去重
     * @return array|string[]
     */
    public function getCustomerIds(): array
    {
        $ret = array();
        foreach ($this->data as $value) {
            array_push($ret, ...repeatedFieldToArray($value->getCustomerIds()));
        }
        return array_values(array_unique($ret));
    }

    /**
     * 判断某个customerId是否在线
     * @param string $customerId
     * @return bool
     */
    public function has(string $customerId): bool
    {
        foreach ($this->data as $value) {
            foreach ($value->getCustomerIds() as $customerIdValue) {
                if ($customerIdValue === $customerId) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * 获取去重后的在线客户数；同一个客户可能连接到多个网关
     * @return int
     */
    public function getLen(): int
    {
        return count($this->getCustomerIds());
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
                'customerIds' => repeatedFieldToArray($value->getCustomerIds()),
            ];
        }
        return $ret;
    }
}
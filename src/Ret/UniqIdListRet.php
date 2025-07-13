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

use NetsvrProtocol\UniqIdListResp;
use function NetsvrBusiness\repeatedFieldToArray;

class UniqIdListRet
{
    /**
     * key为网关worker服务器地址，value为 UniqIdListResp
     * @var array|array<string,UniqIdListResp>|UniqIdListResp[]
     */
    public array $data = array();

    /**
     * 获取所有uniqId
     * @return array|array<int,string>|string[]
     */
    public function getUniqIds(): array
    {
        $ret = [];
        foreach ($this->data as $item) {
            array_push($ret, ...repeatedFieldToArray($item->getUniqIds()));
        }
        return $ret;
    }

    /**
     * 转为数组
     * @return array
     */
    public function toArray(): array
    {
        $ret = [];
        foreach ($this->data as $addr => $item) {
            $ret[] = [
                'addr' => $addr,
                'uniqIds' => repeatedFieldToArray($item->getUniqIds()),
            ];
        }
        return $ret;
    }
}
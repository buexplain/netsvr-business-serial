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

use NetsvrProtocol\CheckOnlineResp;
use function NetsvrBusiness\repeatedFieldToArray;

class CheckOnlineRet
{
    /**
     * key为网关worker服务器地址，value为 CheckOnlineResp
     * @var array|array<string,CheckOnlineResp>|CheckOnlineResp[]
     */
    public array $data = array();
    protected array|null $cache = null;

    protected function initCache(): void
    {
        if (is_null($this->cache)) {
            $this->cache = array();
            foreach ($this->data as $item) {
                foreach ($item->getUniqIds() as $uniqId) {
                    $this->cache[$uniqId] = true;
                }
            }
        }
    }

    /**
     * 获取所有在线的uniqId
     * @return array|array<int,string>|string[]
     */
    public function getUniqIds(): array
    {
        $this->initCache();
        return array_keys($this->cache);
    }

    /**
     * 判断某个uniqId是否在线
     * @param string $uniqId
     * @return bool
     */
    public function has(string $uniqId): bool
    {
        $this->initCache();
        return isset($this->cache[$uniqId]);
    }

    /**
     * 转为数组
     * @return array
     */
    public function toArray(): array
    {
        $ret = array();
        foreach ($this->data as $addr => $value) {
            $ret[] = [
                'addr' => $addr,
                'uniqIds' => repeatedFieldToArray($value->getUniqIds()),
            ];
        }
        return $ret;
    }
}
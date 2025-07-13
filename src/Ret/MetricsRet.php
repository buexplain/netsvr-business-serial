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

use NetsvrProtocol\MetricsResp;
use NetsvrProtocol\MetricsRespItem;

class MetricsRet
{
    /**
     * key为网关worker服务器地址，value为 MetricsResp
     * @var array|array<string,MetricsResp>|MetricsResp[]
     */
    public array $data = array();

    /**
     * @param int $precision 统计值的保留小数位
     * @return array
     */
    public function toArray(int $precision = 3): array
    {
        $ret = array();
        foreach ($this->data as $addr => $value) {
            foreach ($value->getItems() as $metricsValue) {
                /**
                 * @var $metricsValue MetricsRespItem
                 */
                $ret[] = [
                    'addr' => $addr,
                    //统计的服务状态项，具体含义请移步：https://github.com/buexplain/netsvr/blob/main/internal/metrics/metrics.go
                    'item' => $metricsValue->getItem(),
                    //总数
                    'count' => $metricsValue->getCount(),
                    //每秒速率
                    'meanRate' => round($metricsValue->getMeanRate(), $precision),
                    //每1分钟速率
                    'rate1' => round($metricsValue->getRate1(), $precision),
                    //每5分钟速率
                    'rate5' => round($metricsValue->getRate5(), $precision),
                    //每15分钟速率
                    'rate15' => round($metricsValue->getRate15(), $precision),
                ];
            }
        }
        return $ret;
    }
}
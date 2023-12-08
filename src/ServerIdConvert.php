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

namespace NetsvrBusiness;

use NetsvrBusiness\Contract\ServerIdConvertInterface;

/**
 * 这个类主要是解决网关分配给客户的uniqId与网关的serverId之间的映射关系
 * 目前的实现是uniqId的前两个字符就是serverId的16进制表示，所以截取uniqId的前两个字符、转int即可得到serverId
 * 如果业务侧对网关下发给客户的uniqId进行了变更，导致上面的逻辑失效，则业务侧必须重写这个类的方法，正确的处理uniqId与serverId的转换
 * 不建议业务侧将客户的uniqId与网关的serverId之间的映射关系存储到redis这种需要io查询的存储器上，最好是通过特定的uniqId格式，本进程内cpu计算即可得到，避免io开销
 * 另外需要注意serverId小于16时，转16进制必须补足两位字符串，示例：$hex = ($serverId < 16 ? '0' . dechex($serverId) : dechex($serverId));
 */
class ServerIdConvert implements ServerIdConvertInterface
{
    /**
     * 将客户的uniqId转换为所在网关的serverId
     * @param string $uniqId
     * @return int serverId
     */
    public function single(string $uniqId): int
    {
        return (int)hexdec(substr($uniqId, 0, 2));
    }

    /**
     * 批量的将客户的uniqId转换为所在网关的serverId
     * @param array $uniqIds
     * @return array key是uniqId，value是serverId
     */
    public function bulk(array $uniqIds): array
    {
        $ret = [];
        foreach ($uniqIds as $uniqId) {
            $ret[$uniqId] = (int)hexdec(substr($uniqId, 0, 2));
        }
        return $ret;
    }
}
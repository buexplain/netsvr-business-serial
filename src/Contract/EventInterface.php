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

namespace NetsvrBusiness\Contract;

use Netsvr\ConnClose;
use Netsvr\ConnOpen;
use Netsvr\Transfer;

/**
 * 处理客户数据的接口，应用层必须实现该接口
 * @link https://github.com/buexplain/netsvr-protocol
 */
interface EventInterface
{
    /**
     * 客户连接打开
     * @param ConnOpen $connOpen
     * @return void
     */
    public function onOpen(ConnOpen $connOpen): void;

    /**
     * 透传客户数据
     * @param Transfer $transfer
     * @return void
     */
    public function onMessage(Transfer $transfer): void;

    /**
     * 客户连接关闭
     * @param ConnClose $connClose
     * @return void
     */
    public function onClose(ConnClose $connClose): void;
}
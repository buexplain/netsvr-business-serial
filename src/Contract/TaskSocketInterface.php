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

interface TaskSocketInterface
{
    /**
     * 发送数据
     * @param string $data
     * @return void
     */
    public function send(string $data): void;

    /**
     * 接收数据
     * @return string|false
     */
    public function receive(): string|false;

    /**
     * 做一次心跳检查，看看连接是否正常
     * @return bool
     */
    public function heartbeat(): bool;
}
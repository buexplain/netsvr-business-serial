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

use NetsvrBusiness\Exception\ClassNotFoundException;
use Psr\Container\ContainerInterface;
use Closure;

class Container implements ContainerInterface
{
    /**
     * 容器对象实例
     * @var ContainerInterface|null
     */
    protected static ContainerInterface|null $instance = null;

    /**
     * 容器中的对象实例
     * @var array
     */
    protected array $instances = [];

    /**
     * 获取当前容器的实例（单例）
     * @access public
     * @return ContainerInterface
     */
    public static function getInstance(): ContainerInterface
    {
        if (is_null(static::$instance)) {
            static::$instance = new static;
        }
        return static::$instance;
    }

    /**
     * 设置当前容器的实例
     * @param ContainerInterface $instance
     * @return void
     */
    public static function setInstance(ContainerInterface $instance): void
    {
        static::$instance = $instance;
    }

    /**
     * 获取容器中的对象实例
     * @param string $id
     * @return mixed
     */
    public function get(string $id): mixed
    {
        if (isset($this->instances[$id])) {
            if ($this->instances[$id] instanceof Closure) {
                //如果是闭包，则执行一下得到真正的对象
                $this->instances[$id] = $this->instances[$id]();
            }
            return $this->instances[$id];
        }
        //如果报这个异常，则说明你没有在框架初始化阶段，初始化当前类
        //具体的初始化方式请参考本包的README.md文件
        throw new ClassNotFoundException('class not exists: ' . $id, $id);
    }

    /**
     * 绑定对象到容器
     * @param string $id
     * @param object $concrete
     * @return void
     */
    public function bind(string $id, object $concrete): void
    {
        $this->instances[$id] = $concrete;
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]);
    }
}
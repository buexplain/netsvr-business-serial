# netsvr-business-serial

这是一个可以快速开发websocket单工通信业务的包，它必须在串行的php程序中工作，不能在协程中工作，它基于[https://github.com/buexplain/netsvr](https://github.com/buexplain/netsvr)
进行工作。

ps：如果你的项目是hyperf框架的，则可以使用这个包：[https://github.com/buexplain/netsvr-business-coroutine](https://github.com/buexplain/netsvr-business-coroutine)

### 名词解释，并不准确，请见谅：

* 单工通信：服务器主动下发数据给客户端，但是客户端不能主动上传数据给服务端
* 串行的php程序：顺序执行代码的php程序，比如php-fpm内运行的项目、Laravel Octane类的常驻内存但是只能顺序执行的项目

## 使用步骤

1. 下载并启动网关服务：[https://github.com/buexplain/netsvr/releases](https://github.com/buexplain/netsvr/releases)
   ，该服务会启动：websocket服务器、worker服务器
2. 在你的项目里面安装本包以及protobuf包：
   > composer require buexplain/netsvr-business-serial
   >
   > composer require google/protobuf
3. 在框架初始化阶段，初始化本包，步骤如下：
   * 设置`\NetsvrBusiness\Container::class`的`\Psr\Container\ContainerInterface::class`接口的实例
   * 绑定`\NetsvrBusiness\Contract\TaskSocketMangerInterface::class`的单例实现
4. 完成以上步骤后，即可在你的业务代码中使用`\NetsvrBusiness\NetBus::class`
   的静态方法与网关交互，示例：`\NetsvrBusiness\NetBus::broadcast("将消息通过广播的方式给到全体在线人员");`

## 如何在框架初始化阶段，初始化本包

### Laravel框架

只要在你的laravel项目安装下面这个服务提供者即可。
其它框架初始化方式大同小异，不再赘述。

```php
<?php
/**
 * Copyright 2023 buexplain@qq.com
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * https://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use NetsvrBusiness\Container;
use NetsvrBusiness\Contract\TaskSocketMangerInterface;
use NetsvrBusiness\TaskSocket;
use NetsvrBusiness\TaskSocketManger;

/**
 * composer包 buexplain/netsvr-business-serial 的初始化代码
 */
class NetBusServiceProvider extends ServiceProvider
{
    protected array $netsvrConfig = [];

    public function __construct($app)
    {
        parent::__construct($app);
        //配置信息可以放到config文件夹下，我写这里是方便阅读
        //buexplain/netsvr-business-serial包支持网关分布式部署在多台机器上，并与之交互
        $this->netsvrConfig = [
            'netsvr' => [
                //第一台机器的网关的worker服务器的信息
                [
                    //网关的worker服务器地址
                    'workerAddr' => '127.0.0.1:6071',
                    //最大闲置时间，单位秒，建议比netsvr网关的worker服务器的ReadDeadline配置小3秒
                    'maxIdleTime' => 117,
                ],
                //第二台机器的网关的worker服务器的信息
                [
                    'workerAddr' => '127.0.0.1:6081',
                    'maxIdleTime' => 117,
                ],
            ],
            //向网关的worker服务器发送的心跳消息，这个字符串与网关的worker服务器的配置要一致
            'workerHeartbeatMessage' => '~6YOt5rW35piO~',
            //读写数据超时，单位秒
            'sendReceiveTimeout' => 5,
            //连接到服务端超时，单位秒
            'connectTimeout' => 5,
        ];
    }

    /**
     * Register services.
     */
    public function register(): void
    {
        //替换默认的容器
        Container::setInstance($this->app);
        //这个是管理与网关的worker服务器连接的socket的类
        $this->app->singleton(TaskSocketMangerInterface::class, function () {
            $taskSocketManger = new TaskSocketManger();
            $logPrefix = sprintf('TaskSocket#%d', getmypid());
            foreach ($this->netsvrConfig['netsvr'] as $config) {
                $taskSocket = new TaskSocket(
                    $logPrefix,
                    Log::getFacadeRoot(),
                    $config['workerAddr'],
                    $this->netsvrConfig['workerHeartbeatMessage'],
                    $this->netsvrConfig['sendReceiveTimeout'],
                    $this->netsvrConfig['connectTimeout'],
                    $config['maxIdleTime']);
                $taskSocketManger->addSocket($taskSocket);
            }
            return $taskSocketManger;
        });
    }

    /**
     * Bootstrap services.
     * @return void
     */
    public function boot(): void
    {
    }
}
```
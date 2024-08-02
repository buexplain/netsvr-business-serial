# netsvr-business-serial

可以快速开发websocket全双工通信业务的包，它必须在串行的php程序中工作，不能在协程中工作，它基于[https://github.com/buexplain/netsvr](https://github.com/buexplain/netsvr)
进行工作。

ps：如果你的项目是hyperf框架的，则可以使用这个包：[https://github.com/buexplain/netsvr-business-coroutine](https://github.com/buexplain/netsvr-business-coroutine)

## 安装步骤

### 安装netsvr

点击链接：[https://github.com/buexplain/netsvr/releases](https://github.com/buexplain/netsvr/releases)
，进去后下载网关程序，下载后启动网关服务，网关服务会启动：websocket服务器、worker服务器，请仔细阅读`netsvr.toml`文件。

### 在你的php项目里面安装本包以及protobuf包

1. composer require buexplain/netsvr-business-serial
2. composer require google/protobuf

### 在框架初始化阶段，初始化本包，步骤如下

#### Laravel框架

只要在你的laravel项目安装下面这个服务提供者即可，代码细节请自行修改。
其它的fpm容器下运行的框架初始化方式大同小异，不再赘述。

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
                    'workerAddr' => '127.0.0.1:6061',
                    //最大闲置时间，单位秒，建议比netsvr网关的worker服务器的ReadDeadline配置小3秒
                    'maxIdleTime' => 117,
                ]
            ],
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
        //这个是管理与网关的worker服务器连接的socket的类，仅绑定该类，只能实现服务器推送消息给客户端，客户端无法主动向网关发送消息
        $this->app->singleton(TaskSocketMangerInterface::class, function () {
            $taskSocketManger = new TaskSocketManger();
            $logPrefix = sprintf('TaskSocket#%d', getmypid());
            foreach ($this->netsvrConfig['netsvr'] as $config) {
                //创建连接对象，并添加到管理器，如果不用这个对象，则不会与netsvr网关进行连接
                $taskSocket = new TaskSocket(
                    $logPrefix,
                    Log::getFacadeRoot(),
                    $config['workerAddr'],
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

#### Webman框架

```php
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

namespace app;

use Exception;
use Netsvr\ConnClose;
use Netsvr\ConnOpen;
use Netsvr\Transfer;
use NetsvrBusiness\Container;
use NetsvrBusiness\Contract\EventInterface;
use NetsvrBusiness\Contract\MainSocketManagerInterface;
use NetsvrBusiness\Contract\TaskSocketMangerInterface;
use NetsvrBusiness\Workerman\MainSocket;
use NetsvrBusiness\MainSocketManager;
use NetsvrBusiness\NetBus;
use NetsvrBusiness\Socket;
use NetsvrBusiness\Workerman\TaskSocket;
use NetsvrBusiness\TaskSocketManger;
use Psr\Container\ContainerInterface;
use Webman\Bootstrap;
use Workerman\Worker;
use support\Log;
use Netsvr\Event;

class NetsvrBootstrap implements Bootstrap
{
    /**
     * 初始化配置信息，配置信息最好放在框架规定的目录，我写在这里只是方便演示
     * @return array
     */
    protected static function getConfig(): array
    {
        return [
            //如果一台网关服务机器承载不了业务的websocket连接数，可以再部署一台网关服务机器，这里支持配置多个网关服务，处理多个网关服务的websocket消息
            'netsvr' => [
                [
                    //netsvr网关的worker服务器监听的tcp地址
                    'workerAddr' => '127.0.0.1:6061',
                    //该参数表示接下来，需要网关服务的worker服务器开启多少协程来处理mainSocket连接的请求
                    'processCmdGoroutineNum' => 25,
                    //该参数表示接下来，需要网关服务的worker服务器转发如下事件给到business进程的mainSocket连接
                    'events' => Event::OnOpen | Event::OnClose | Event::OnMessage,
                ],
            ],
            //taskSocket的最大闲置时间，单位秒，建议比netsvr网关的worker服务器的ReadDeadline配置小3秒
            'maxIdleTime' => 117,
            //socket读写网关数据的超时时间，单位秒
            'sendReceiveTimeout' => 5,
            //连接到网关的超时时间，单位秒
            'connectTimeout' => 5,
            //business进程向网关的worker服务器发送的心跳消息，这个字符串与网关的worker服务器的配置要一致，如果错误，网关的worker服务器是会强制关闭连接的
            'workerHeartbeatMessage' => '~6YOt5rW35piO~',
            //维持心跳的间隔时间，单位毫秒
            'heartbeatIntervalMillisecond' => 25 * 1000,
        ];
    }

    /**
     * @param Worker|null $worker
     * @return void
     * @throws Exception
     */
    public static function start(?Worker $worker): void
    {
        //初始化容器
        /**
         * @var $container Container
         */
        $container = Container::getInstance();
        //初始化taskSocketManger，在此之后，webman进程、命令行进程、定时任务进程、自定义进程，都可以用NetBus类与netsvr网关交互
        self::initTaskSocketMangerInterface($container);
        //跟随webman启动，换句话说就是，在webman进程中，启动了n条tcp客户端连接到n个netsvr网关，并接收到n个netsvr网关转发过来的websocket事件
        //如果想在自定义进程中启动，则把name判断等于'自定义进程名字'即可
        if ($worker && $worker->name === 'webman') {
            self::initMainSocketManagerInterface($container);
        }
        //注册worker停止的回调，在worker停止时，先关闭mainSocket，再关闭taskSocket
        if ($worker) {
            $oldCallback = $worker->onWorkerStop;
            $worker->onWorkerStop = function () use ($oldCallback, $container) {
                //先关闭mainSocket
                $container->has(MainSocketManagerInterface::class) && $container->get(MainSocketManagerInterface::class)->close();
                //再关闭taskSocket
                $container->has(TaskSocketMangerInterface::class) && $container->get(TaskSocketMangerInterface::class)->close();
                //最后再执行原来的回调
                if ($oldCallback) {
                    $oldCallback();
                }
            };
        }
    }

    /**
     * 初始化taskSocketManger
     * 这个是管理与网关的worker服务器连接的socket的类，仅绑定该类，只能实现服务器推送消息给客户端，客户端无法主动向网关发送消息
     * @param Container $container
     * @return void
     */
    protected static function initTaskSocketMangerInterface(ContainerInterface $container): void
    {
        //这里只是绑定一个闭包，实际上并不会与netsvr网关进行连接，后续使用到了，才会进行连接
        $container->bind(TaskSocketMangerInterface::class, function () {
            $taskSocketManger = new TaskSocketManger();
            $logPrefix = sprintf('TaskSocket#%d', getmypid());
            foreach (self::getConfig()['netsvr'] as $item) {
                //将网关的特定参数与公共参数进行合并，网关的特定参数覆盖公共参数
                $item = array_merge(self::getConfig(), $item);
                //创建连接对象，并添加到管理器，如果不用这个对象，则不会与netsvr网关进行连接
                $taskSocket = new TaskSocket(
                    $logPrefix,
                    Log::channel(),
                    $item['workerAddr'],
                    $item['sendReceiveTimeout'],
                    $item['connectTimeout'],
                    $item['maxIdleTime'],
                    $item['workerHeartbeatMessage'],
                    $item['heartbeatIntervalMillisecond'],
                );
                $taskSocketManger->addSocket($taskSocket);
            }
            return $taskSocketManger;
        });
    }

    /**
     * 初始化mainSocket，初始化成功后，会接收到来自netsvr网关转发过来的websocket事件，可以实现服务端、客户端双向互发消息
     * @param Container $container
     * @return void
     * @throws Exception
     */
    public static function initMainSocketManagerInterface(ContainerInterface $container): void
    {
        $mainSocketManager = new MainSocketManager();
        $logPrefix = sprintf('MainSocket#%d', getmypid());
        $event = self::getEvent();
        foreach (self::getConfig()['netsvr'] as $item) {
            //将网关的特定参数与公共参数进行合并，网关的特定参数覆盖公共参数
            $item = array_merge(self::getConfig(), $item);
            //创建socket
            $socket = new Socket(
                $logPrefix,
                Log::channel(),
                $item['workerAddr'],
                $item['sendReceiveTimeout'],
                $item['connectTimeout']);
            //创建MainSocket连接
            $mainSocket = new MainSocket(
                $logPrefix,
                Log::channel(),
                $event,
                $socket,
                $item['workerHeartbeatMessage'],
                $item['events'],
                $item['processCmdGoroutineNum'],
                $item['heartbeatIntervalMillisecond']);
            //添加到管理器
            $mainSocketManager->addSocket($mainSocket);
        }
        //启动成功后，将mainSocketManager绑定到容器中，提供给NetBus类使用
        if ($mainSocketManager->start()) {
            $container->bind(MainSocketManagerInterface::class, $mainSocketManager);
        }
    }

    /**
     * 获取事件对象，这个类应该创建一个文件，实现EventInterface接口，我写在这里是为了演示方便
     * @return EventInterface
     */
    protected static function getEvent(): EventInterface
    {
        return new class implements EventInterface {
            /**
             * 处理连接打开事件
             * @param ConnOpen $connOpen
             * @return void
             */
            public function onOpen(ConnOpen $connOpen): void
            {
//                Log::channel()->info('onOpen ' . $connOpen->serializeToJsonString());
            }

            /**
             * 处理消息事件
             * @param Transfer $transfer
             * @return void
             */
            public function onMessage(Transfer $transfer): void
            {
                //将消息转发给NetBus，NetBus会根据uniqId将消息转发给对应的客户端
                NetBus::singleCast($transfer->getUniqId(), $transfer->getData());
            }

            /**
             * 处理连接关闭事件
             * @param ConnClose $connClose
             * @return void
             */
            public function onClose(ConnClose $connClose): void
            {
//                Log::channel()->info('onClose ' . $connClose->serializeToJsonString());
            }
        };
    }
}
```

### 完成以上步骤后

在你的业务代码中使用`\NetsvrBusiness\NetBus::class`
的静态方法与网关交互，示例：`\NetsvrBusiness\NetBus::broadcast("将消息通过广播的方式给到全体在线人员");`

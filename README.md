# netsvr-business-serial

这是一个可以快速开发websocket单工通信业务的包，它必须在串行的php程序中工作，不能在协程中工作，它基于[https://github.com/buexplain/netsvr](https://github.com/buexplain/netsvr)
进行工作。

ps：如果你的项目是hyperf框架的，则可以使用这个包：[https://github.com/buexplain/netsvr-business](https://github.com/buexplain/netsvr-business)

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
3. 在框架初始化阶段，初始化本包
    * 给容器`\NetsvrBusiness\Container::class`设置`\Psr\Container\ContainerInterface::class`接口的实例
    * 给`Psr\Container\ContainerInterface::class`
      接口的实例单例方式绑定接口：`\NetsvrBusiness\Contract\ServerIdConvertInterface::class`、`\NetsvrBusiness\Contract\TaskSocketMangerInterface::class`
      的实例
4. 完成以上步骤后，即可在你的业务代码中使用`\NetsvrBusiness\NetBus::class`
   的静态方法与网关交互，示例：`\NetsvrBusiness\NetBus::broadcast("广播给所有的网关的在线用户一条消息");`

## 如何在框架初始化阶段，初始化本包

### Laravel框架

只要在你的laravel项目安装下面这个服务提供者即可，另外代码里面的具体的配置信息需要你修改一下。

```php
<?php
declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Netsvr\Constant;
use NetsvrBusiness\Container;
use NetsvrBusiness\Contract\ServerIdConvertInterface;
use NetsvrBusiness\Contract\TaskSocketMangerInterface;
use NetsvrBusiness\ServerIdConvert;
use NetsvrBusiness\TaskSocket;
use NetsvrBusiness\TaskSocketManger;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

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
        //buexplain/netsvr-business-serial支持网关分布式部署在多台机器上
        $this->netsvrConfig = [
            //第一个网关机器的信息
            [
                //网关的唯一id
                'serverId' => 0,
                //网关的worker服务器地址
                'host' => '127.0.0.1',
                //网关的worker服务器监听的端口
                'port' => 6061,
                //读取网关的worker服务器信息的超时时间，单位秒
                'receiveTimeout' => 30,
                //写入网关的worker服务器信息的超时时间，单位秒
                'sendTimeout' => 30,
                //最大闲置时间，单位秒，建议比netsvr网关的worker服务器的ReadDeadline配置小3秒
                'maxIdleTime' => 117,
            ],
            //第二个网关机器信息
            [
                'serverId' => 1,
                'host' => '127.0.0.1',
                'port' => 6071,
                'receiveTimeout' => 30,
                'sendTimeout' => 30,
                'maxIdleTime' => 117,
            ],
        ];
    }

    /**
     * Register services.
     */
    public function register(): void
    {
        //替换默认的容器
        Container::setInstance($this->app);
        //这个是将网关下发的客户唯一id转为网关唯一id的类
        //目前有默认实现是ServerIdConvert，具体实现的逻辑可以点击该类，进去看看注释，如果不符合业务需求，则需要自己实现接口ServerIdConvertInterface
        $this->app->singleton(ServerIdConvertInterface::class, function () {
            return new ServerIdConvert();
        });
        //这个是管理与网关的worker服务器连接的socket的类
        $this->app->singleton(TaskSocketMangerInterface::class, function () {
            $taskSocketManger = new TaskSocketManger();
            foreach ($this->netsvrConfig as $config) {
                $taskSocket = new TaskSocket($config['host'], $config['port'], $config['sendTimeout'], $config['receiveTimeout'], $config['maxIdleTime']);
                $taskSocketManger->addSocket($config['serverId'], $taskSocket);
            }
            return $taskSocketManger;
        });
    }

    /**
     * Bootstrap services.
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function boot(): void
    {
        //如果是swoole驱动的Octane，则可以定时心跳一下，保持连接的活跃
        if ($this->app->has(TaskSocketMangerInterface::class) && class_exists('\Laravel\Octane\Facades\Octane') && class_exists('\Swoole\Http\Server')) {
            //获取所有网关配置里面最小的maxIdleTime值
            $maxIdleTime = min(array_column($this->netsvrConfig, 'maxIdleTime'));
            $seconds = 0;
            //计算出定时的间隔时间，最好是比空闲时间小三秒就立刻进行心跳操作
            for ($i = 3; $i >= 1; $i--) {
                if ($maxIdleTime - $i > 0) {
                    $seconds = $maxIdleTime - $i;
                    break;
                }
            }
            //时间太短，不做心跳处理
            if ($seconds <= 0) {
                return;
            }
            //开启心跳间隔
            \Laravel\Octane\Facades\Octane::tick('keepLiveForNetsvr', function () {
                //获取所有的网关socket连接
                $sockets = $this->app->get(TaskSocketMangerInterface::class)->getSockets();
                //循环每个连接并发送心跳与接收心跳返回
                foreach ($sockets as $socket) {
                    $socket->heartbeat();
                }
            })->seconds($seconds)->immediate();
        }
    }
}
```

### Thinkphp框架

只要在你的thinkphp项目安装下面这个服务即可，另外代码里面的具体的配置信息需要你修改一下。

```php
<?php
declare (strict_types=1);

namespace app\service;

use NetsvrBusiness\Container;
use NetsvrBusiness\Contract\ServerIdConvertInterface;
use NetsvrBusiness\Contract\TaskSocketMangerInterface;
use NetsvrBusiness\ServerIdConvert;
use NetsvrBusiness\TaskSocket;
use NetsvrBusiness\TaskSocketManger;
use think\App;
use think\Service;

/**
 * composer包 buexplain/netsvr-business-serial 的初始化代码
 */
class NetBusService extends Service
{
    protected array $netsvrConfig = [];

    public function __construct(App $app)
    {
        parent::__construct($app);
        //配置信息可以放到config文件夹下，我写这里是方便阅读
        //buexplain/netsvr-business-serial支持网关分布式部署在多台机器上
        $this->netsvrConfig = [
            //第一个网关机器的信息
            [
                //网关的唯一id
                'serverId' => 0,
                //网关的worker服务器地址
                'host' => '127.0.0.1',
                //网关的worker服务器监听的端口
                'port' => 6061,
                //读取网关的worker服务器信息的超时时间，单位秒
                'receiveTimeout' => 30,
                //写入网关的worker服务器信息的超时时间，单位秒
                'sendTimeout' => 30,
                //最大闲置时间，单位秒，建议比netsvr网关的worker服务器的ReadDeadline配置小3秒
                'maxIdleTime' => 117,
            ],
            //第二个网关机器信息
            [
                'serverId' => 1,
                'host' => '127.0.0.1',
                'port' => 6071,
                'receiveTimeout' => 30,
                'sendTimeout' => 30,
                'maxIdleTime' => 117,
            ],
        ];
    }

    /**
     * 注册服务
     *
     * @return void
     */
    public function register(): void
    {
        //替换默认的容器
        Container::setInstance($this->app);
        //这个是将网关下发的客户唯一id转为网关唯一id的类
        //目前有默认实现是ServerIdConvert，具体实现的逻辑可以点击该类，进去看看注释，如果不符合业务需求，则需要自己实现接口ServerIdConvertInterface
        $this->app->bind(ServerIdConvertInterface::class, function () {
            return new ServerIdConvert();
        });
        //这个是管理与网关的worker服务器连接的socket的类
        $this->app->bind(TaskSocketMangerInterface::class, function () {
            $taskSocketManger = new TaskSocketManger();
            foreach ($this->netsvrConfig as $config) {
                $taskSocket = new TaskSocket($config['host'], $config['port'], $config['sendTimeout'], $config['receiveTimeout'], $config['maxIdleTime']);
                $taskSocketManger->addSocket($config['serverId'], $taskSocket);
            }
            return $taskSocketManger;
        });
    }

    /**
     * 执行服务
     *
     * @return void
     */
    public function boot(): void
    {
        //
    }
}
```

### 无`\Psr\Container\ContainerInterface`容器的框架

其实任意框架都可以用下面的代码，在框架初始化阶段，对本包进行初始化，只是有`\Psr\Container\ContainerInterface`
容器的框架，我们就尽量使用框架本身的容器来初始化。
比如yii2，它就是一个无`\Psr\Container\ContainerInterface`容器的框架，则可以在它的配置文件的`bootstrap`项上加入一个闭包，闭包的具体代码就是：

```php
/**
 * 因为框架没有容器，所以我们直接使用本包提供的容器
 * @var $container \NetsvrBusiness\Container
 */
$container = \NetsvrBusiness\Container::getInstance();
//这个是将网关下发的客户唯一id转为网关唯一id的类
//目前有默认实现是ServerIdConvert，具体实现的逻辑可以点击该类，进去看看注释，如果不符合业务需求，则需要自己实现接口ServerIdConvertInterface
$container->bind(\NetsvrBusiness\Contract\ServerIdConvertInterface::class, function () {
    return new \NetsvrBusiness\ServerIdConvert();
});
//这个是管理与网关的worker服务器连接的socket的类
$container->bind(\NetsvrBusiness\Contract\TaskSocketMangerInterface::class, function () {
    //配置信息可以放到config文件夹下，我写这里是方便阅读
    //buexplain/netsvr-business-serial支持网关分布式部署在多台机器上
    $netsvrConfig = [
        //第一个网关机器的信息
        [
            //网关的唯一id
            'serverId' => 0,
            //网关的worker服务器地址
            'host' => '127.0.0.1',
            //网关的worker服务器监听的端口
            'port' => 6061,
            //读取网关的worker服务器信息的超时时间，单位秒
            'receiveTimeout' => 30,
            //写入网关的worker服务器信息的超时时间，单位秒
            'sendTimeout' => 30,
            //最大闲置时间，单位秒，建议比netsvr网关的worker服务器的ReadDeadline配置小3秒
            'maxIdleTime' => 117,
        ],
        //第二个网关机器信息
        [
            'serverId' => 1,
            'host' => '127.0.0.1',
            'port' => 6071,
            'receiveTimeout' => 30,
            'sendTimeout' => 30,
            'maxIdleTime' => 117,
        ],
    ];
    $taskSocketManger = new \NetsvrBusiness\TaskSocketManger();
    foreach ($netsvrConfig as $config) {
        $taskSocket = new \NetsvrBusiness\TaskSocket($config['host'], $config['port'], $config['sendTimeout'], $config['receiveTimeout'], $config['maxIdleTime']);
        $taskSocketManger->addSocket($config['serverId'], $taskSocket);
    }
    return $taskSocketManger;
});
```

## 如何跑本包的测试用例

1. 下载[网关服务](https://github.com/buexplain/netsvr/releases)的`v2.1.0`版本及以上的程序包
2. 修改配置文件`netsvr.toml`
   - `ConnOpenCustomUniqIdKey`改为`ConnOpenCustomUniqIdKey = "uniqId"`
   - `ServerId`改为`ServerId=0`
   - `ConnOpenWorkerId`改为`ConnOpenWorkerId=0`
   - `ConnCloseWorkerId`改为`ConnCloseWorkerId=0`
3. 执行命令：`netsvr-windows-amd64.bin -config configs/netsvr.toml`启动网关服务，注意我这个命令是windows系统的，其它系统的，自己替换成对应的网关服务程序包即可
4. 完成以上步骤后，就启动好一个网关服务了，接下来再启动一个网关服务，目的是测试本包在网关服务多机部署下的正确性
5. 复制一份`netsvr.toml`为`netsvr-607.toml`，并改动里面的`606`系列端口的为`607`系列端口，避免端口冲突；`ServerId`
   项改为`ServerId=1`，避免网关唯一id冲突
6. 执行命令：`netsvr-windows-amd64.bin -config configs/netsvr-607.toml`
   启动第二个网关服务，注意我这个命令是windows系统的，其它系统的，自己替换成对应的网关服务程序包即可
7. 完成以上步骤后，两个网关服务启动完毕，算是准备好了网关这块的环境
8. 执行本包的测试命令：`composer test`，等待一段时间，即可看到测试结果
# netsvr-business-serial

这是一个可以快速开发websocket单工通信业务的包，它必须在串行的php程序中工作，不能在协程中工作，它基于[https://github.com/buexplain/netsvr](https://github.com/buexplain/netsvr)
进行工作。

### 名词解释，并不准确，请见谅：
* 单工通信：服务器主动下发数据给客户端，但是客户端不能主动上传数据给服务端
* 串行的php程序：顺序执行逻辑的php程序，比如php-fpm内运行的项目、Laravel Octane类的常驻内存但是只能顺序执行的项目

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
      接口的实例单例方式绑定接口：`\NetsvrBusiness\Contract\ServerIdConvertInterface::class`、`\NetsvrBusiness\Contract\TaskSocketInterface::class`、`\NetsvrBusiness\Contract\TaskSocketMangerInterface::class`
      的实例
4. 完成以上步骤后，即可在你的业务代码中使用`\NetsvrBusiness\NetBus::class`的静态方法与网关交互

## 如何在框架初始化阶段，初始化本包

### Laravel框架

### Thinkphp框架

## 如何跑本包的测试用例

1. 下载[网关服务](https://github.com/buexplain/netsvr/releases)的`v1.1.0`版本及以上的程序包
2. 修改配置文件`netsvr.toml`的`ConnOpenCustomUniqIdKey`项为`ConnOpenCustomUniqIdKey = "uniqId"`、`ServerId`项为`ServerId=0`
3. 执行命令：`netsvr-windows-amd64.bin -config configs/netsvr.toml`启动网关服务，注意我这个命令是windows系统的，其它系统的，自己替换成对应的网关服务程序包即可
4. 完成以上步骤后，就启动好一个网关服务了，接下来再启动一个网关服务，目的是测试本包在网关服务多机部署下的正确性
5. 复制一份`netsvr.toml`为`netsvr-607.toml`，并改动里面的`606`系列端口的为`607`系列端口，避免端口冲突；`ServerId`项改为`ServerId=1`，避免网关唯一id冲突
6. 执行命令：`netsvr-windows-amd64.bin -config configs/netsvr-607.toml`启动第二个网关服务，注意我这个命令是windows系统的，其它系统的，自己替换成对应的网关服务程序包即可
7. 完成以上步骤后，两个网关服务启动完毕，算是准备好了网关这块的环境
8. 执行本包的测试命令：`composer test`，等待一段时间，即可看到测试结果
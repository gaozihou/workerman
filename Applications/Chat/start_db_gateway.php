# COMP4521
# ZHOU Xutong    20091184    xzhouaf@connect.ust.hk
# GAO Zihou          20090130    zgao@connect.ust.hk

<?php 

use \Workerman\Worker;
use \Workerman\WebServer;
use \GatewayWorker\Gateway;
use \GatewayWorker\BusinessWorker;
use \Workerman\Autoloader;


// 自动加载类
require_once __DIR__ . '/../../Workerman/Autoloader.php';
Autoloader::setRootPath(__DIR__);

// gateway 进程
$gateway = new Gateway("Websocket://0.0.0.0:7274");
// 设置名称，方便status时查看
$gateway->name = 'DbGateway';
// 设置进程数，gateway进程数建议与cpu核数相同
$gateway->count = 1;
// 分布式部署时请设置成内网ip（非127.0.0.1）
$gateway->lanIp = '127.0.0.1';
// 内部通讯起始端口，假如$gateway->count=4，起始端口为4000
// 则一般会使用4001 4002 4003 4004 4个端口作为内部通讯端口 
$gateway->startPort = 5000;
// 心跳间隔
$gateway->pingInterval = 0;

$gateway->onWorkerStart = function($gateway)
{
	Event::onDbGatewayStart();
	$time_interval = 2;
	\Workerman\Lib\Timer::add($time_interval, function()
	{
		Event::onDbTimerCount();
	});
};

// 当客户端连接上来时，设置连接的onWebSocketConnect，即在websocket握手时的回调
$gateway->onConnect = function($connection)
{
    $connection->onWebSocketConnect = function($connection , $http_header)
    {
    	//$connection->disconnect();
    };
};

// 如果不是在根目录启动，则运行runAll方法
if(!defined('GLOBAL_START'))
{
    Worker::runAll();
}


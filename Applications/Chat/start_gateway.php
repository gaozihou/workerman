<?php 
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link http://www.workerman.net/
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */
use \Workerman\Worker;
use \Workerman\WebServer;
use \GatewayWorker\Gateway;
use \GatewayWorker\BusinessWorker;
use \Workerman\Autoloader;

require_once '/var/www/task_manager/include/DbHandler.php';

// 自动加载类
require_once __DIR__ . '/../../Workerman/Autoloader.php';
Autoloader::setRootPath(__DIR__);

// gateway 进程
$gateway = new Gateway("Websocket://0.0.0.0:7272");
// 设置名称，方便status时查看
$gateway->name = 'ChatGateway';
// 设置进程数，gateway进程数建议与cpu核数相同
$gateway->count = 4;
// 分布式部署时请设置成内网ip（非127.0.0.1）
$gateway->lanIp = '127.0.0.1';
// 内部通讯起始端口，假如$gateway->count=4，起始端口为4000
// 则一般会使用4001 4002 4003 4004 4个端口作为内部通讯端口 
$gateway->startPort = 3000;
// 心跳间隔
$gateway->pingInterval = 3;
// 心跳超时次数
$gateway->pingNotResponseLimit = 2;
// 心跳数据
$gateway->pingData = '{"type":"ping"}';

$gateway->onWorkerStart = function($gateway)
{
	$time_interval = 1;
	\Workerman\Lib\Timer::add($time_interval, function()
	{
		$data = array(
        'type' => 'time',
        'time' => date('Y-m-d H:i:s'),
   		 );
    	\GatewayWorker\Lib\Gateway::sendToAll(json_encode($data));
	});
};

// 当客户端连接上来时，设置连接的onWebSocketConnect，即在websocket握手时的回调
$gateway->onConnect = function($connection)
{
    $connection->onWebSocketConnect = function($connection , $http_header)
    {
    	$db = new DbHandler();
    	$header = http_parse_headers($http_header);
    	$api_key = $header['Authorization'];
        if(!$db->isValidApiKey($api_key))
        {
            $connection->close();
        }
    };
};

// 如果不是在根目录启动，则运行runAll方法
if(!defined('GLOBAL_START'))
{
    Worker::runAll();
}

function http_parse_headers($raw_headers)
{
	$headers = array();
	$key = ''; // [+]

	foreach(explode("\n", $raw_headers) as $i => $h)
	{
		$h = explode(':', $h, 2);

		if (isset($h[1]))
		{
			if (!isset($headers[$h[0]]))
				$headers[$h[0]] = trim($h[1]);
			elseif (is_array($headers[$h[0]]))
			{
				// $tmp = array_merge($headers[$h[0]], array(trim($h[1]))); // [-]
				// $headers[$h[0]] = $tmp; // [-]
				$headers[$h[0]] = array_merge($headers[$h[0]], array(trim($h[1]))); // [+]
			}
			else
			{
				// $tmp = array_merge(array($headers[$h[0]]), array(trim($h[1]))); // [-]
				// $headers[$h[0]] = $tmp; // [-]
				$headers[$h[0]] = array_merge(array($headers[$h[0]]), array(trim($h[1]))); // [+]
			}

			$key = $h[0]; // [+]
		}
		else // [+]
		{ // [+]
			if (substr($h[0], 0, 1) == "\t") // [+]
				$headers[$key] .= "\r\n\t".trim($h[0]); // [+]
			elseif (!$key) // [+]
			$headers[0] = trim($h[0]);trim($h[0]); // [+]
		} // [+]
	}

	return $headers;
}


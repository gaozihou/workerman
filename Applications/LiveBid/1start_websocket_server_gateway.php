<?php 

use \GatewayWorker\Gateway;
use \Workerman\Autoloader;

// autoload
require_once __DIR__ . '/../../Workerman/Autoloader.php';
Autoloader::setRootPath(__DIR__);

// create Websocket gateway
$ws_server = new Gateway('Websocket://0.0.0.0:3838');

$ws_server->name = 'SimpleChatWebSocket';

$ws_server->count = 1;

$current_price = 0;

$ws_server->onWorkerStart = function($ws_server)
{
    $time_interval = 1;
    \Workerman\Lib\Timer::add($time_interval, function()
    {
        $data = array(
        'type' => 'time',
        'time' => date('Y-m-d H:i:s'),
    );
    broad_cast(json_encode($data));
    });
};

$ws_server->onConnect = function($connection)
{
    // on WebSocket handshake 
    $connection->onWebSocketConnect = function($connection)
    {
        $data = array(
                'type' => 'login',
                'time' => date('Y-m-d H:i:s'),
                // @see http://doc3.workerman.net/worker-development/id.html
                'from_id' => $connection->id,
		'price' => $current_price,
        );
        broad_cast(json_encode($data));
    };
};

// @see http://doc3.workerman.net/worker-development/on-message.html
$ws_server->onMessage = function($connection, $data)use($ws_server)
{
	$current_price = $current_price + $data;
    $data = array(
        'type' => 'say',
        'content' => $data,
        'time' => date('Y-m-d H:i:s'),
        // @see http://doc3.workerman.net/worker-development/id.html
        'from_id' => $connection->id,
	'price' => $current_price,
    );
    broad_cast(json_encode($data));
};

// @see http://doc3.workerman.net/worker-development/connection-on-close.html
$ws_server->onClose = function($connection)
{
    $data = array(
                'type' => 'logout',
                'time' => date('Y-m-d H:i:s'),
                // @see http://doc3.workerman.net/worker-development/id.html
                'from_id' => $connection->id,
        );
        broad_cast(json_encode($data));
};

/**
 * broadcast
 * @param string $msg
 * @return void
 */
function broad_cast($msg)
{
    global $ws_server;
    //@see http://doc3.workerman.net/worker-development/connections.html
    foreach($ws_server->connections as $connection)
    {
        // @see http://doc3.workerman.net/worker-development/send.html
        $connection->send($msg);
    }
}


// 如果不是在根目录启动，则运行runAll方法
if(!defined('GLOBAL_START'))
{
    Worker::runAll();
}

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
use \Workerman\Autoloader;

// autoload
require_once __DIR__ . '/../../Workerman/Autoloader.php';
require_once '/var/www/task_manager/include/DbHandler.php';
Autoloader::setRootPath(__DIR__);

// create Websocket worker
$ws_server = new Worker('Websocket://0.0.0.0:3636');

$ws_server->name = 'LiveBidWebSocket';

$ws_server->count = 1;

$total_connection_count = 0;

$_SESSION['items'] = array(
	1 => array(
			'item_name' => "Paul",
			'curr_price' => 10,
			'user_include' => array(),
			),		
);

$_SESSION['ID_userID_map'] = Array();

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

// @see http://doc3.workerman.net/worker-development/on-connect.html
$ws_server->onConnect = function($connection)
{
    // on WebSocket handshake 
    $connection->onWebSocketConnect = function($connection)
    {/*
        $data = array(
                'type' => 'login',
                'time' => date('Y-m-d H:i:s'),
                // @see http://doc3.workerman.net/worker-development/id.html
                'from_id' => $connection->id,
		'price' => $_SESSION['items'][1]['curr_price'],
        );
        broad_cast(json_encode($data));
        */
    	global $total_connection_count;
    	$total_connection_count++;
    };
};

// @see http://doc3.workerman.net/worker-development/on-message.html
$ws_server->onMessage = function($connection, $data)use($ws_server)
{
	global $total_connection_count;
	$obj = json_decode($data);
	
	if($obj->{'type'} == 'bid'){
		$_SESSION['items'][1]['curr_price'] = $_SESSION['items'][1]['curr_price'] + $obj->{'message'};
    	$data = array(
        	'type' => 'say',
        	'content' => $data." ".$total_connection_count,
        	'time' => date('Y-m-d H:i:s'),
        	// @see http://doc3.workerman.net/worker-development/id.html
        	'from_id' => $connection->id,
			'price' => $_SESSION['items'][1]['curr_price'],
    	);
    	broad_cast(json_encode($data));
    	
	}else if($obj->{'type'} == 'authenticate'){
		
		$response = authenticate($obj->{'api_key'});
		if($response['error'] == false){
			$_SESSION['ID_userID_map'][$connection->id] = $response['user_id'];
			$_SESSION['items'][$obj->{'item_id'}]['user_include'][] = $connection->id;
			$data = array(
					'type' => 'authenticate',
					'content' => 'User '.$connection->id.' passes the authentication, original user ID is '.$response['user_id'],
			);
			broad_cast(json_encode($data));
		}else{
			$data = array(
					'type' => 'authenticate',
					'content' =>'User '.$connection->id.' failed the authentication',
			);
			broad_cast(json_encode($data));
		}
	}
};

// @see http://doc3.workerman.net/worker-development/connection-on-close.html
$ws_server->onClose = function($connection)
{
	global $total_connection_count;
	$total_connection_count--;
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

function authenticate($api_key) {
	
	$db = new DbHandler();	
	if (!$db->isValidApiKey($api_key)) {
		$response['error'] = true;
		$response['user_id'] = -1;
	} else {
		$user_id = $db->getUserId($api_key);
		$response['error'] = false;
		$response['user_id'] = $user_id;
	}
	return $response;
}






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
use \GatewayWorker\Lib\Store;

// 自动加载类
require_once __DIR__ . '/../../Workerman/Autoloader.php';
Autoloader::setRootPath(__DIR__);

// bussinessWorker 进程
$worker = new BusinessWorker();
// worker名称
$worker->name = 'ChatBusinessWorker';
// bussinessWorker进程数量
$worker->count = 4;

$worker->onWorkerStart = function($worker)
{
	//addRoom("none", -1, "Ubuntu", 200, 1, "This is dummy description", 1, "Paul", 9, "1429706230067.bmp");
};

// This function is for the initialization of the room
function addRoom($curr_uname, $curr_uid, $name, $initial_price, $room_id, $description, $status, $seller_name, $seller_id, $image){
		$key = "CURR_ROOM_LIST";
    	$store = Store::instance('room');
    	$handler = fopen(__FILE__, 'r');
    	flock($handler,  LOCK_EX);
    	$room_list = $store->get($key);
    	if(!isset($room_list['curr_assign'])){
    		$room_list['curr_assign'] = 1;
    	}
    	$curr_room = $room_list['curr_assign'];
    	$room_list['rooms'][$curr_room] = true;
    	$room_list['curr_assign'] = $room_list['curr_assign'] + 1;
    	$store->set($key, $room_list);
    	
   		$key = "ROOM_CLIENT_LIST-$curr_room";
   		$task_info['name'] = $name;
   		$task_info['curr_price'] = $initial_price;
   		$task_info['curr_uname'] = $curr_uname;
   		$task_info['curr_uid'] = $curr_uid;
   		$task_info['description'] = $description;
   		$task_info['status'] = $status;
   		$task_info['seller_name'] = $seller_name;
   		$task_info['seller_id'] = $seller_id;
   		$task_info['image'] = $image;
   		$store->set($key, $task_info);
}

// 如果不是在根目录启动，则运行runAll方法
if(!defined('GLOBAL_START'))
{
    Worker::runAll();
}


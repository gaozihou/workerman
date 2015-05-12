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

use \GatewayWorker\Lib\Gateway;
use \GatewayWorker\Lib\Store;
require_once '/var/www/task_manager/include/DbHandler.php';

class Event
{
	public static $NOT_STARTED = 1;
	public static $BIDDING = 2;
	public static $FINISHED = 3;
	private static $TOTAL_TIME = 60;
	
	public static function onTimeCount(){
		
		$room_list = self::getRoomList();
		$store = Store::instance('room');
		if($room_list)
		{
			foreach($room_list as $tmp_room_id=>$tmp_condition)
			{
				$key = "ROOM_CLIENT_LIST-$tmp_room_id";
				$task_info = $store->get($key);
				
				switch($task_info['status']){
					
					case self::$NOT_STARTED:
						if($task_info['start_time'] <= time()){
							$task_info['status'] = self::$BIDDING;
							$store->set($key, $task_info);
							$client_id_array = array_keys($task_info['client_list']);
							$new_message = array(
									'type'=>'start',
							);
							Gateway::sendToAll(json_encode($new_message), $client_id_array);
						}
						break;
						
					case self::$BIDDING:
						if($task_info['time_left']>0){
							$task_info['time_left']--;
							$store->set($key, $task_info);
						}else{
							$task_info['status'] = self::$FINISHED;
							$store->set($key, $task_info);
							$client_id_array = array_keys($task_info['client_list']);
							$new_message = array(
									'type'=>'end',
									'curr_uname'=>$task_info['curr_uname'],
									'curr_uid'=>$task_info['curr_uid'],
									'initial_price'=>$task_info['initial_price'],
							);
							Gateway::sendToAll(json_encode($new_message), $client_id_array);
							
							$key = "DB-QUEUE";
							$db_queue = $store->get($key);
							$db_queue[] = $task_info;
							$store->set($key, $db_queue);
						}
						break;
						
					case self::$FINISHED:
						if((time() - $task_info['start_time'] > 86400) || !isset($task_info['bid_list'])){
							self::deleteRoom($tmp_room_id);
						}
						break;
						
					default:
						break;
				}
			}
		}	
	}
	
	public static function onDbGatewayStart(){
		$store = Store::instance('room');
		$key = "DB-QUEUE";
		$store->set($key, array());
	}
	
	public static function onDbTimerCount(){
		$store = Store::instance('room');
		$key = "DB-QUEUE";
		$db_queue = $store->get($key);
		$store->set($key, array());
		
		foreach($db_queue as $tmp_queue_id=>$task_info)
		{
			$db = new DbHandler();
			$result = ($db->postItem($task_info['seller_id'], $task_info['name'], $task_info['description'], "new",
					"Other", 60, 0, $task_info['initial_price'], $task_info['image'], $task_info['seller_name'], 1));
			if($task_info['curr_uid'] != -1){
				$db->updatePrice($task_info['curr_uid'],$task_info['curr_price'], $result);
			}
		}
	}
   
   /**
    * 有消息时
    * @param int $client_id
    * @param string $message
    */
   public static function onMessage($client_id, $message)
   {
        // debug
        echo "client:{$_SERVER['REMOTE_ADDR']}:{$_SERVER['REMOTE_PORT']} gateway:{$_SERVER['GATEWAY_ADDR']}:{$_SERVER['GATEWAY_PORT']}  client_id:$client_id session:".json_encode($_SESSION)." onMessage:".$message."\n";
        
        // 客户端传递的是json数据
        $message_data = json_decode($message, true);
        if(!$message_data)
        {
            return ;
        }
        
        // 根据类型执行不同的业务
        switch($message_data['type'])
        {
            // 客户端回应服务端的心跳
            case 'pong':
                return;
            // 客户端登录 message格式: {type:login, name:xx, room_id:1} ，添加到客户端，广播给所有客户端xx进入聊天室
            case 'login':
            case 're_login':
            	$db = new DbHandler();
            	$api_key = $message_data['Authorization'];
            	if(!$db->isValidApiKey($api_key))
            	{
            		Gateway::closeClient($client_id);
            		return;
            	}
                // 判断是否有房间号
                if(!isset($message_data['room_id']))
                {
                    throw new \Exception("\$message_data['room_id'] not set. client_ip:{$_SERVER['REMOTE_ADDR']} \$message:$message");
                }
                
                // 把房间号昵称放到session中
                $room_id = $message_data['room_id'];
                $client_name = htmlspecialchars($message_data['client_name']);
                $user_id = $message_data['user_id'];
                $_SESSION['room_id'] = $room_id;
                $_SESSION['client_name'] = $client_name;
                
                // 存储到当前房间的客户端列表
                $all_clients = self::addClientToRoom($room_id, $client_id, $client_name, $user_id);
                
                // 整理客户端列表以便显示
                $client_list = self::formatClientsData($all_clients);
                
                // 转播给当前房间的所有客户端，xx进入聊天室 message {type:login, client_id:xx, name:xx} 
                $task_info = self::getRoom($_SESSION['room_id']);
                $new_message = array(
                		'type'=>$message_data['type'], 
                		'client_id'=>$client_id,
                		'client_name'=>htmlspecialchars($client_name), 
                		'client_list'=>$client_list, 
                		'time'=>date('Y-m-d H:i:s'),
                		'price'=>$task_info['curr_price'],
                		'description'=>$task_info['description'],
                		'seller_name'=>$task_info['seller_name'],
                		'seller_id'=>$task_info['seller_id'],
                		'status'=>$task_info['status'],
                		'name'=>$task_info['name'],
                		'curr_uname'=>$task_info['curr_uname'],
                		'curr_uid'=>$task_info['curr_uid'],
                		'initial_price'=>$task_info['initial_price'],
                		'start_time'=>date('Y-m-d H:i:s', $task_info['start_time']),
                		'time_left'=>$task_info['time_left'],
                		'user_id'=>$user_id,
                		'bid_list'=>$task_info['bid_list'],
                );
                $client_id_array = array_keys($all_clients);
                Gateway::sendToAll(json_encode($new_message), $client_id_array);
                echo $task_info['time_left']."\n";
                return;
                
            // 客户端发言 message: {type:say, to_client_id:xx, content:xx}
            case 'say':
                // 非法请求
                if(!isset($_SESSION['room_id']))
                {
                    throw new \Exception("\$_SESSION['room_id'] not set. client_ip:{$_SERVER['REMOTE_ADDR']}");
                }
                $room_id = $_SESSION['room_id'];
                $client_name = $_SESSION['client_name'];
                
                $task_info = self::getRoom($_SESSION['room_id']);
                $task_info['curr_price'] = $message_data['content'];
                $task_info['curr_uid'] = $task_info['client_list'][$client_id]['user_id'];
                $task_info['curr_uname'] = $task_info['client_list'][$client_id]['user_name'];
                $task_info['bid_list'][] = array(
                		'user_id'=> $task_info['curr_uid'],
                		'user_name'=>$task_info['curr_uname'],
                		'price'=>$task_info['curr_price'],
                		'time'=>date('Y-m-d H:i:s'),
                );
                self::setRoom($_SESSION['room_id'], $task_info);
                
                // 向大家说
                $all_clients = self::getClientListFromRoom($_SESSION['room_id']);
                $client_id_array = array_keys($all_clients);
                $new_message = array(
                    'type'=>'say', 
                    'from_client_id'=>$client_id,
                    'from_client_name' =>$client_name,
                    'to_client_id'=>'all',
                    'content'=>nl2br(htmlspecialchars($message_data['content'])),
                    'time'=>date('Y-m-d H:i:s'),
                	'price'=>$task_info['curr_price'],
                );
                return Gateway::sendToAll(json_encode($new_message), $client_id_array);
                
                // Client side add new room
            case 'add':
            	$db = new DbHandler();
            	$api_key = $message_data['Authorization'];
            	if(!$db->isValidApiKey($api_key)){
            		Gateway::closeClient($client_id);
            		return;
            	}
            	$seller_id = $db->getUserId($api_key);
            	$seller_name = $message_data['seller_name'];
            	$image =  $message_data['image'];
            	$description = $message_data['description'];
            	$room_id = $message_data['room_id'];
            	$name = $message_data['name'];
            	$initial_price = $message_data['initial_price'];
            	$start_time = $message_data['later'];
            	self::addRoom("none", -1, $name, $initial_price, $room_id, $description, self::$NOT_STARTED, 
            			$seller_name, $seller_id, $image, $start_time);
            	Gateway::closeClient($client_id);
            	return;
            case 'request_rooms':
            	$room_info = self::formatRoomsInfo(self::getRoomList());
            	$new_message = array(
            			'type'=>'say',
            			'list'=>$room_info,
            	);
            	Gateway::sendToCurrentClient(json_encode($new_message));
            	return;
         	case 'close_client':
            	return  Gateway::closeClient($client_id);
        }
   }
   
   /**
    * 当客户端断开连接时
    * @param integer $client_id 客户端id
    */
   public static function onClose($client_id)
   {
       // debug
       echo "client:{$_SERVER['REMOTE_ADDR']}:{$_SERVER['REMOTE_PORT']} gateway:{$_SERVER['GATEWAY_ADDR']}:{$_SERVER['GATEWAY_PORT']}  client_id:$client_id onClose:''\n";
       
       // 从房间的客户端列表中删除
       if(isset($_SESSION['room_id']))
       {
           $room_id = $_SESSION['room_id'];
           self::delClientFromRoom($room_id, $client_id);
           // 广播 xxx 退出了
           if($all_clients = self::getClientListFromRoom($room_id))
           {
               $client_list = self::formatClientsData($all_clients);
               $new_message = array('type'=>'logout', 'from_client_id'=>$client_id, 'from_client_name'=>$_SESSION['client_name'], 'client_list'=>$client_list, 'time'=>date('Y-m-d H:i:s'));
               $client_id_array = array_keys($all_clients);
               Gateway::sendToAll(json_encode($new_message), $client_id_array);
           }
       }
   }
   
  
   /**
    * 格式化客户端列表数据
    * @param array $all_clients
    */
   public static function formatClientsData($all_clients)
   {
       $client_list = array();
       if($all_clients)
       {
           foreach($all_clients as $tmp_client_id=>$tmp_single_client)
           {
               $client_list[] = array('client_id'=>$tmp_client_id, 'client_name'=>$tmp_single_client['user_name']);
           }
       }
       return $client_list;
   }
   
   public static function formatRoomsInfo($room_list){
   		$room_info = array();
   		$store = Store::instance('room');
   		if($room_list)
   		{
   			foreach($room_list as $tmp_room_id=>$tmp_condition)
   			{
   				$key = "ROOM_CLIENT_LIST-$tmp_room_id";
   				$task_info = $store->get($key);
   				$room_info[] = array('id'=>$tmp_room_id, 'name'=>$task_info['name'], 'curr_price'=>$task_info['curr_price'], 'status'=>$task_info['status'], 'image'=>$task_info['image'], 'seller_name'=>$task_info['seller_name']);
   			}
   		}
   		return $room_info;
   }
   
   public static function getCurrentPriceFromRoom($room_id){
   		$key = "ROOM_CLIENT_LIST-$room_id";
   		$store = Store::instance('room');
   		$task_info = $store->get($key);
   		if(false === $task_info['curr_price']){
   			return 0;
   		}
   		return $task_info['curr_price'];
   }
   
   public static function getRoom($room_id){
   	$key = "ROOM_CLIENT_LIST-$room_id";
   	$store = Store::instance('room');
   	$task_info = $store->get($key);
    return $task_info;
   }
   
   public static function setRoom($room_id, $task_info){
   	$key = "ROOM_CLIENT_LIST-$room_id";
   	$store = Store::instance('room');
   	$store->set($key, $task_info);
   }
   
   public static function setCurrentRoomPrice($room_id, $price){
   		$key = "ROOM_CLIENT_LIST-$room_id";
   		$store = Store::instance('room');
   		$handler = fopen(__FILE__, 'r');
   		flock($handler,  LOCK_EX);
   		$task_info = $store->get($key);
   		if(false === $task_info['curr_price']){
   			$task_info['curr_price'] = 0;
   		}else{
   			$task_info['curr_price'] = $price;
   		}
   		$store->set($key, $task_info);
   		flock($handler, LOCK_UN);
   }
   
   /**
    * 获得客户端列表
    * @todo 保存有限个
    */
   public static function getClientListFromRoom($room_id)
   {
       $key = "ROOM_CLIENT_LIST-$room_id";
       $store = Store::instance('room');
       $task_info = $store->get($key);
       if(false === $task_info['client_list'])
       {
           return array();
       }
       return $task_info['client_list'];
   }
   
   public static function getRoomList(){
   		$key = "CURR_ROOM_LIST";
   		$store = Store::instance('room');
   		$room_list = $store->get($key);
   		if(false === $room_list['rooms']){
   			return array();
   			echo hehe;
   		}
   		return $room_list['rooms'];
   }
   
   public static function deleteRoom($room_id){
   		$key = "CURR_ROOM_LIST";
   		$store = Store::instance('room');
   		$handler = fopen(__FILE__, 'r');
   		flock($handler,  LOCK_EX);
   		$room_list = $store->get($key);
   		if(isset($room_list['rooms'][$room_id])){
   			unset($room_list['rooms'][$room_id]);
   		}
   		$store->set($key, $room_list);
   		flock($handler, LOCK_UN);
   }
   
   /**
    * 从客户端列表中删除一个客户端
    * @param int $client_id
    */
   public static function delClientFromRoom($room_id, $client_id)
   {
       $key = "ROOM_CLIENT_LIST-$room_id";
       $store = Store::instance('room');
       // 存储驱动是memcache或者file
           $handler = fopen(__FILE__, 'r');
           flock($handler,  LOCK_EX);
           $task_info = $store->get($key);
           if(isset($task_info['client_list'][$client_id]))
           {
               unset($task_info['client_list'][$client_id]);
               $ret = $store->set($key, $task_info);
               flock($handler, LOCK_UN);
               return $task_info['client_list'];
           }
           flock($handler, LOCK_UN);
       return $task_info['client_list'];
   }
   
   /**
    * 添加到客户端列表中
    * @param int $client_id
    * @param string $client_name
    */
   public static function addClientToRoom($room_id, $client_id, $client_name, $user_id)
   {
       $key = "ROOM_CLIENT_LIST-$room_id";
       $store = Store::instance('room');
       // 获取所有所有房间的实际在线客户端列表，以便将存储中不在线用户删除
       $all_online_client_id = Gateway::getOnlineStatus();
       // 存储驱动是memcache或者file
           $handler = fopen(__FILE__, 'r');
           flock($handler,  LOCK_EX);
           $task_info = $store->get($key);
           if(!isset($task_info['client_list'][$client_id]))
           {
               // 将存储中不在线用户删除
               if($all_online_client_id && $task_info['client_list'])
               {
                   $all_online_client_id = array_flip($all_online_client_id);
                   $task_info['client_list'] = array_intersect_key($task_info['client_list'], $all_online_client_id);
               }
               // 添加在线客户端
               $task_info['client_list'][$client_id]['user_name'] = $client_name;
               $task_info['client_list'][$client_id]['user_id'] = $user_id;
               $ret = $store->set($key, $task_info);
               flock($handler, LOCK_UN);
               return $task_info['client_list'];
           }
           flock($handler, LOCK_UN);
       return $task_info['client_list'];
   }
   
   // This function is for the initialization of the room
    public static function addRoom($curr_uname, $curr_uid, $name, $initial_price, $room_id, 
    		$description, $status, $seller_name, $seller_id, $image, $start_time){
    	
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
   		$task_info['start_time'] = time() + $start_time;
   		$task_info['time_left'] = self::$TOTAL_TIME ;
   		$task_info['bid_list'] = array();
   		$task_info['initial_price'] = $initial_price;
   		$store->set($key, $task_info);
   		flock($handler, LOCK_UN);
   		echo $task_info['time_left']."\n";
   	}
   
}









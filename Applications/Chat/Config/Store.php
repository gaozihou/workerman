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
namespace Config;

/**
 * 存储配置
 * 注意生产环境使用$driver = self::DRIVER_MC，具体参考applications/Demo/README.md
 * @author walkor
 */
class Store
{
    // 使用文件存储，注意使用文件存储无法支持workerman分布式部署
    const DRIVER_FILE = 1;
    // 使用memcache存储，支持workerman分布式部署
    const DRIVER_MC = 2;
    // 使用redis存储，支持workerman分布式部署
    const DRIVER_REDIS = 3;
    
    /* 使用哪种存储驱动 文件存储DRIVER_FILE 或者 memcache存储DRIVER_MC，为了更好的性能请使用DRIVER_MC
     * 注意： DRIVER_FILE只适合开发环境，生产环境或者压测请使用DRIVER_MC，需要php cli 安装memcache扩展
     */
    public static $driver = self::DRIVER_MC;
    
    // 如果是memcache存储，则在这里设置memcache的ip端口，注意确保你安装了memcache扩展
    public static $gateway = array(
        //'127.0.0.1:22301',
        'localhost:11211',
    );
    
    public static $room = array(
        'localhost:11211',
    );
    
    /*
     *  如果使用文件存储，默认系统临时目录下
     */
    public static $storePath = '';
}

// 默认系统临时目录下
Store::$storePath = sys_get_temp_dir().'/workerman-chat/';

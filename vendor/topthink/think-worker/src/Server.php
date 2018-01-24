<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2014 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

namespace think\worker;

use Workerman\Worker;
use Workerman\Lib\Timer;
  // 心跳间隔25秒
define('HEARTBEAT_TIME', 25);
/**
 * Worker控制器扩展类
 */
abstract class Server
{
    protected $worker;
    protected $socket    = '';
    protected $protocol  = 'text';
    protected $host      = '0.0.0.0';
    protected $port      = '2346';
    protected $processes = 1;
    public $global_uid = 0;

    /**
     * 架构函数
     * @access public
     */
    public function __construct()
    {
       //          // 实例化 Websocket 服务
       // // $this->worker = new Worker($this->socket ?: $this->protocol . '://' . $this->host . ':' . $this->port);
       //  $this->worker = new Worker("text://0.0.0.0:2346");
       //  // 设置进程数
       //  $this->worker->count = $this->processes;
       //  $this->worker->reusePort = true;
       //  // 初始化
       //  $this->init();

       //  // 设置回调
       //  foreach (['onWorkerStart', 'onConnect', 'onMessage', 'onClose', 'onError', 'onBufferFull', 'onBufferDrain', 'onWorkerStop', 'onWorkerReload'] as $event) {
       //      if (method_exists($this, $event)) {
       //          $this->worker->$event = [$this, $event];
       //      }
       //  }
        
$worker = new Worker('websocket://0.0.0.0:1234');

/*
 * 注意这里进程数必须设置为1，否则会报端口占用错误
 * (php 7可以设置进程数大于1，前提是$inner_text_worker->reusePort=true)
 */
$worker->count = 1;
// worker进程启动后创建一个text Worker以便打开一个内部通讯端口
$worker->onWorkerStart = function($worker)
{
   Timer::add(1, function()use($worker){
        $time_now = time();
        foreach($worker->connections as $connection) {
            // 有可能该connection还没收到过消息，则lastMessageTime设置为当前时间
            if (empty($connection->lastMessageTime)) {
                $connection->lastMessageTime = $time_now;
                continue;
            }
            // 上次通讯时间间隔大于心跳间隔，则认为客户端已经下线，关闭连接
            if ($time_now - $connection->lastMessageTime > HEARTBEAT_TIME) {
                $connection->close();
            }
        }
    });
    // 开启一个内部端口，方便内部系统推送数据，Text协议格式 文本+换行符
    $inner_text_worker = new Worker('Text://0.0.0.0:5678');
    $inner_text_worker->listen();
    $inner_text_worker->onMessage = function($connection, $buffer)
    {
        global $worker;
        // $data数组格式，里面有uid，表示向那个uid的页面推送数据
        $data = json_decode($buffer, true);
        $uid = $data['uid'];
          $ip=$connection->getRemoteIp() ;
        // 通过workerman，向uid的页面推送数据
         // $worker->uidConnections[$uid] = $connection;
        $ret = sendMessageByUid($uid, $buffer);
        // 返回推送结果
        $connection->send($ret ? 'ok' : 'fail');
    };
};
// 新增加一个属性，用来保存uid到connection的映射
$worker->uidConnections = array();
// 当有客户端发来消息时执行的回调函数
$worker->onMessage = function($connection, $data)
{
    global $worker;

       // 给connection临时设置一个lastMessageTime属性，用来记录上次收到消息的时间
    $connection->lastMessageTime = time();
    // 判断当前客户端是否已经验证,既是否设置了uid
    if(!isset($connection->uid))
    {
       // 没验证的话把第一个包当做uid（这里为了方便演示，没做真正的验证）
       $message_data = json_decode($data, true);

       $connection->uid = $message_data['uid'];
    //   \Think\log::write('$worker->onMessage'.$data);
       /* 保存uid到connection的映射，这样可以方便的通过uid查找connection，
        * 实现针对特定uid推送数据
        */
       if($message_data['type']=='login'){
        $msgstr=$message_data['uid'].'登录了';
       }
       $worker->uidConnections[$connection->uid] = $connection;
        $ret = broadcast($message_data['uid']);
       return;
    }
};

// 当有客户端连接断开时
$worker->onClose = function($connection)
{
    global $worker;
    if(isset($connection->uid))
    {
        // 连接断开时删除映射
        unset($worker->uidConnections[$connection->uid]);
    }
};

// 向所有验证的用户推送数据
function broadcast($message)
{
   global $worker;
   foreach($worker->uidConnections as $connection)
   {
        $connection->send($message);
   }
}

// 针对uid推送数据
function sendMessageByUid($uid, $message)
{
    global $worker;
    if(isset($worker->uidConnections[$uid]))
    {
        $connection = $worker->uidConnections[$uid];
        $connection->send($message);
        echo 'yes';
        return true;
    }
    echo 'nooo';
    return false;
}

// 运行所有的worker
Worker::runAll();
    }

    protected function init()
    {
    }

}

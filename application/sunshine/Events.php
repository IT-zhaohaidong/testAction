<?php
/**
 * 芯夏M205DTU 4G
 * 用于检测业务代码死循环或者长时间阻塞等问题
 * 如果发现业务卡死，可以将下面declare打开（去掉//注释），并执行php start.php reload
 * 然后观察一段时间workerman.log看是否有process_timeout异常
 */

//declare(ticks=1);

use GatewayWorker\BusinessWorker;
use \GatewayWorker\Lib\Gateway;
use Workerman\Worker;

/**
 * 主逻辑
 * 主要是处理 onConnect onMessage onClose 三个方法
 * onConnect 和 onClose 如果不需要可以不用实现并删除
 */
class Events
{
//    public function __construct()
//    {
//        parent::__construct();
//        // 构造函数中注册事件回调函数
//        $this->onWorkerStart = [$this, 'onWorkerStart'];
//        $this->onConnect = [$this, 'onConnect'];
//        $this->onMessage = [$this, 'onMessage'];
//        $this->onClose = [$this, 'onClose'];
//    }
    // Worker 启动时触发的事件回调函数
//    public function onWorkerStart() {
//
//    }
    /**
     * 新建一个类的静态成员，用来保存redis实例
     */
    public static $redis = null;

    /**
     * 进程启动后初始化redis连接
     */
    public static function onWorkerStart($worker)
    {
        self::$redis = new \think\cache\driver\Redis();
    }


    /**
     * 当客户端连接时触发
     * 如果业务不需此回调可以删除onConnect
     *
     * @param int $client_id 连接id
     */
    public static function onConnect($client_id)
    {
//        trace($client_id,'庆祝吧!!!!!!!!');
//        Gateway::bindUid($client_id, '1122');
//        // 向当前client_id发送数据
        var_dump('连接上了' . $client_id);
        Gateway::sendToClient($client_id, "Hello $client_id\r\n");
//        // 向所有人发送
//        Gateway::sendToAll("$client_id login\r\n");
    }

    /**
     * 当客户端发来消息时触发
     * @param int $client_id 连接id
     * @param mixed $message 具体消息
     */
    public static function onMessage($client_id, $message)
    {
        // 向所有人发送
//        Gateway::sendToAll("$client_id said $message\r\n");
//        var_dump($client_id . 'said ' . $message);
//        Gateway::sendToClient($client_id, 'server send' . $message);
//        if ($message){
//            $arr=$this->dealMessage($message);
//        }
        $data = self::dealMessage($message);
        var_dump('action=>' . $data['Action']);
        if ($data['Action'] == 'CheckIn') {
            // 向设备回复确认收到签到信息
            $msg = "S=0&Action=@CheckIn&MsgId={$data['MsgId']}&Timer=0&Imei={$data['Imei']}&E=0";
            $res = Gateway::sendToClient($client_id, $msg);
            $imeiStr = $data['Imei'] . 'client';
            self::$redis->set($imeiStr, $client_id);
//            (new \app\index\model\FinanceOrder())->find(1);
            $res = (new \app\applet\controller\Text())->testh(1);
            var_dump($res);
//            $imei = self::$redis->get($imeiStr);
        }
//        Gateway::sendToAll("$client_id said $message\r\n");
    }

    /**
     * 当用户断开连接时触发
     * @param int $client_id 连接id
     */
    public static function onClose($client_id)
    {
        // 向所有人发送
//       GateWay::sendToAll("$client_id logout\r\n");
    }
//
//    public function sendFirst()
//    {
//        $client_id = '7f0000010b5400000001';
//        $res = Gateway::sendToClient($client_id, 'client translate');
//        return $res;
//    }

    public static function dealMessage($res)
    {
        if (!$res) {
            return false;
        }
        $arr = explode('&', $res);
        $data = [];
        foreach ($arr as $k => $v) {
            $params = explode('=', $v);
            $data[$params[0]] = $params[1];
        }
        var_dump($data);
        return $data;
    }
}

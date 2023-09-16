<?php

namespace app\test\controller;

use app\index\model\MachineDeviceErrorModel;
use app\index\model\MachineGoods;
use app\index\model\MachineOutLogModel;
use app\index\model\MachineSignalLogModel;
use Pheanstalk\Pheanstalk;
use think\Cache;
use think\Controller;
use think\Db;
use function AlibabaCloud\Client\value;

//芯夏
class Queque extends Controller
{
    public function index()
    {
        $pda = Pheanstalk::create('127.0.0.1');
        var_dump($pda);
    }
}

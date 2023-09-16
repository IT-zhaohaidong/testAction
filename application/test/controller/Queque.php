<?php

namespace app\test\controller;

use Pheanstalk\Pheanstalk;
use think\Controller;

//队列
class Queque extends Controller
{
    public function index()
    {
        $pda = Pheanstalk::create('127.0.0.1');
        $data = [
            'id' => rand(10000, 99999),
            'price' => 1.00,
            'name' => '测试商品'
        ];
        $pda->useTube('order')->put(json_encode($data));
        var_dump($data);
    }

    public function order()
    {
        $pda = Pheanstalk::create('127.0.0.1');
        while (true) {
            //获取管道并消费
            $job = $pda->watch('order')->ignore('default')->reserve();
            //获取任务id
//            $id = $job->getId();
            //获取任务数据
            $data = $job->getData();
            trace($data, '获取消费任务');
            //处理完任务后就删除掉
            $pda->delete($job);
            sleep(1);
        }

    }
}

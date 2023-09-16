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

    }
}

<?php

namespace app\box\controller;

use think\Cache;
use think\Controller;

class Test extends Controller
{

    public function index()
    {
//        $order=time().rand(1000,9999);
        $order = $this->request->get('order');
        $res = $this->shijian($order, 1);
        if ($res) {
            var_dump('成功');
        } else {
            var_dump('没有反馈');
        }
    }

    public function shijian($order, $num)
    {
        if ($num <= 6) {
            $str = $order;
            $str = Cache::store('redis')->get($str);
            if ($str == 2) {
                return true;
            } else {
                if ($num > 1) {
                    sleep(1);
                }
                $res = $this->shijian($order, $num + 1);
                return $res;
            }
        } else {
            return false;
        }
    }

    public function str()
    {
        $str = request()->get('order');
        Cache::store('redis')->set($str, 2, 30);
    }

    public function chuhuo()
    {
        $data = [
            'imei' => '866833058935765',
            'deviceNumber' => '7663556954',
            'laneNumber' => 1,
            'laneType' => 1,
            'paymentType' => 1,
            'orderNo' => '16635717405299order1',
            'timestamp' => 1663571750,
        ];
        $result = https_request('http://47.96.15.3:8899/api/vending/goodsOut', $data);
        $result = json_decode($result, true);
        var_dump($result);
        die();
    }


}

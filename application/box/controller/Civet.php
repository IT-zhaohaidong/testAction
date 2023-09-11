<?php

namespace app\box\controller;

use app\index\model\FinanceOrder;
use app\index\model\MachineCardModel;
use app\index\model\MachineGoods;
use app\index\model\MachineOutLogModel;
use app\index\model\OrderGoods;
use think\Cache;
use think\Controller;
use think\Db;

//灵猫
class Civet extends Controller
{
    //灵猫获取出货货道
    public function getOutNum()
    {
        $imei = $this->request->post("imei", "");
        $idcard = $this->request->post("idcard", "");
        if (!$imei) {
            return json(["code" => 100, "msg" => "imei号不能为空"]);
        }
        if (!$idcard) {
            return json(["code" => 100, "msg" => "卡号丢失"]);
        }

        $device = Db::name('machine_device')->where(['imei' => $imei])->find();
        if (!$device) {
            return json(["code" => 100, "msg" => '设备不存在']);
        }
        if ($device['is_lock'] == 0) {
            return json(['code' => 100, 'msg' => '设备备禁用']);
        }
        if ($device['supply_id'] != 3) {
            if ($device['status'] != 1) {
                return json(['code' => 100, 'msg' => '设备不在线,请联系客服处理']);
            }
        }

        $cardModel = new MachineCardModel();
        $card = $cardModel
            ->where('idcard', $idcard)
            ->where('status', 0)
            ->find();
        if (!$card) {
            return json(['code' => 100, 'msg' => '无效卡,请联系管理员处理']);
        }
        if ($card['num'] < 1) {
            return json(["code" => 100, "msg" => "卡次数不足"]);
        }
        $goods = Db::name('machine_goods')
            ->where(['device_sn' => $device['device_sn']])
            ->where('is_lock',0)
            ->where('stock', '>', 0)
            ->where('goods_id', $card['goods_id'])
            ->where('num', '<=', $device['num'])
            ->find();
        if (!$goods) {
            return json(["code" => 100, "msg" => "库存不足"]);
        }
        return json(['code' => 200, 'data' => ['num' => $goods['num']]]);
    }

    //刷卡领取
    public function createOrder()
    {
        $imei = $this->request->post("imei", "");
        $num = $this->request->post("num", "");
        $idcard = $this->request->post("idcard", "");
        $order_sn = $this->request->post("order_sn", "");
        if ($order_sn) {
            $arr = explode('test', $order_sn);
            if ($arr[0] == '100') {
                $data = [
                    'order_id' => -1,
                    'order_sn' => $order_sn,
                    'num' => $num
                ];
                return json(['code' => 200, 'data' => $data]);
            }
        }
        if (!$imei) {
            return json(["code" => 100, "msg" => "imei号不能为空"]);
        }
        if (!$idcard && !$order_sn) {
            return json(["code" => 100, "msg" => "缺少参数"]);
        }

        $device = Db::name('machine_device')->where(['imei' => $imei])->find();
        if (!$device) {
            return json(["code" => 100, "msg" => '设备不存在']);
        }
        if ($device['is_lock'] == 0) {
            return json(['code' => 100, 'msg' => '设备备禁用']);
        }
        if ($device['supply_id'] != 3) {
            if ($device['status'] != 1) {
                return json(['code' => 100, 'msg' => '设备不在线,请联系客服处理']);
            }
        }
        $good = Db::name('machine_goods')->where(['num' => $num, 'device_sn' => $device['device_sn']])->find();
        if (!$good || $good['stock'] < 1) {
            return json(["code" => 100, "msg" => "库存不足"]);
        }
        if ($idcard) {
            $cardModel = new MachineCardModel();
            $card = $cardModel
                ->where('idcard', $idcard)
                ->where('status', 0)
                ->find();
            if (!$card) {
                return json(['code' => 100, 'msg' => '无效卡,请联系管理员处理']);
            }
            if ($card['num'] < 1) {
                return json(["code" => 100, "msg" => "卡次数不足"]);
            }
            $cartGet = 'cartGet' . $idcard;
            $res = Cache::store('redis')->get($cartGet);
            trace($res, '刷卡开始');
            if ($res) {
                return json(['code' => 100, 'msg' => '请勿重复刷卡']);
            } else {
                Cache::store('redis')->set($cartGet, 1, 30);
            }
        }
        $goods = Db::name('machine_goods')
            ->alias('num')
            ->join('mall_goods goods', 'num.goods_id=goods.id', 'left')
            ->where(['num.device_sn' => $device['device_sn']])
            ->where(['num.num' => $num])
            ->field('num.num,num.stock,num.price,goods.id,goods.title,goods.image,goods.description')
            ->find();
        $order_sn = $order_sn ?? time() . mt_rand(1000, 9999);
        $data = [
            'order_sn' => $order_sn,
            'device_sn' => $device['device_sn'],
            'uid' => $device['uid'],
            'idcard' => $idcard,
            'price' => 0,
            'count' => 1,
            'pay_type' => 5,
            'status' => 4,
            'create_time' => time(),
        ];
        $order_id = Db::name('finance_order')->insertGetId($data);
        //添加订单商品
        $goods_data = [
            'order_id' => $order_id,
            'device_sn' => $device['device_sn'],
            'order_sn' => $order_sn . 'order1',
            'num' => $num,
            'goods_id' => $goods['id'],
            'price' => $goods['price'],
            'count' => 1,
            'total_price' => $goods['price'],
        ];
        (new OrderGoods())->save($goods_data);
        $data = [
            'order_id' => $order_id,
            'order_sn' => $order_sn . 'order1',
            'num' => $num
        ];
        return json(['code' => 200, 'data' => $data, 'msg' => '验证成功']);
    }

    //通知刷卡出货结果  status 1:出货成功  2:出货失败  order_id(订单id) reason(出货失败原因) goods_code(灵猫 商品码)
    public function payResult()
    {
        $params = request()->post();
        trace($params, '灵猫刷卡出货出货通知');
//        $redeem_code = (new FinanceOrder())->where('id', $params['order_id'])->value('redeem_code');
//        if ($params['status'] == 1) {
//            (new RedeemCodeModel())->where('code', $redeem_code)->update(['status' => 1, 'use_time' => time()]);
//        }
//        Cache::store('redis')->rm($redeem_code);
        if ($params['order_id'] == -1) {
            return json(['code' => 200, 'msg' => '成功']);
        }
        $data = [
            'price' => 0,
            'status' => $params['status'] == 1 ? 1 : 3,
            'pay_time' => time(),
        ];
        (new FinanceOrder())->where('id', $params['order_id'])->update($data);
        //更新库存 不管出货成功还是失败
        $order = (new OrderGoods())->where('order_id', $params['order_id'])->field('device_sn')->find();
        $idcard = (new FinanceOrder())->where('id', $params['order_id'])->value('idcard');
        $device_sn = $order['device_sn'];
        $order_goods = (new OrderGoods())->where('order_id', $params['order_id'])->find();
        trace($order_goods, '订单商品');
        if (!empty($params['goods_code'])) {
            (new OrderGoods())->where('id', $order_goods['id'])->update(['goods_count' => $params['goods_code']]);
        }
        if ($params['status'] == 1) {
            $machineGoodsModel = new MachineGoods();
            $machineGoods = $machineGoodsModel->where(['device_sn' => $device_sn, 'num' => $order_goods['num']])->find();
            $data = ['stock' => $machineGoods['stock'] - 1];
            $machineGoodsModel->where(['device_sn' => $device_sn, 'num' => $order_goods['num']])->update($data);
            if ($idcard) {
                $num = (new MachineCardModel())->where('idcard', $idcard)->value('num');
                (new MachineCardModel())->where('idcard', $idcard)->update(['num' => $num - 1]);
            }
        }

        $out_log = [
            'device_sn' => $device_sn,
            'num' => $order_goods['num'],
            'order_sn' => $order_goods['order_sn'],
            'status' => $params['status'] == 1 ? 0 : 3,
            'remark' => empty($params['reason']) ? '' : $params['reason'],
        ];
        (new MachineOutLogModel())->save($out_log);
        return json(['code' => 200, 'msg' => '成功']);
    }
}

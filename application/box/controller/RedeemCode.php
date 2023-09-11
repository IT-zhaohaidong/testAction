<?php

namespace app\box\controller;

use app\index\model\FinanceOrder;
use app\index\model\MachineDevice;
use app\index\model\MachineGoods;
use app\index\model\MachineOutLogModel;
use app\index\model\OrderGoods;
use app\index\model\RedeemCodeModel;
use think\Cache;
use think\Controller;
use think\Db;

//兑换码兑换
class RedeemCode extends Controller
{
    //商品列表  1货道1商品
    public function goodsList()
    {
        $imei = $this->request->get("imei", "");
        if (!$imei) {
            return json(["code" => 100, "msg" => "imei号不能为空"]);
        }
        $device = Db::name('machine_device')->where(['imei' => $imei])->find();
        if (!$device) {
            return json(['code' => 100, 'msg' => '设备不存在,稍后重试']);
        }
        $goods = Db::name('machine_goods')
            ->alias('num')
            ->join('mall_goods goods', 'num.goods_id=goods.id', 'left')
            ->where('num.device_sn', $device['device_sn'])
            ->field('num.num,num.stock amount,num.price good_price,goods.id,goods.title,goods.image images,goods.description')
            ->order('num.num asc')
            ->select();
        $data = [];
        foreach ($goods as $k => $v) {
            if ($v['id']) {
                $data[] = $v;
            }
        }
        return json(['code' => 200, 'data' => $data]);
    }

    //兑换码兑换
    public function createOrder()
    {
        $imei = $this->request->post("imei", "");
        $num = $this->request->post("num", "");
        $redeem_code = $this->request->post("redeem_code", "");
        if (!$imei) {
            return json(["code" => 100, "msg" => "The imei number cannot be empty"]);
        }
        if (!$redeem_code) {
            return json(["code" => 100, "msg" => "Please enter the redemption code"]);
        }

        $device = Db::name('machine_device')->where(['imei' => $imei])->find();
        if (!$device) {
            return json(["code" => 100, "msg" => 'Device does not exist']);
        }
        if ($device['is_lock'] == 0) {
            return json(['code' => 100, 'msg' => 'The device has been disabled']);
        }
        if ($device['supply_id'] != 3) {
            if ($device['status'] != 1) {
                return json(['code' => 100, 'msg' => 'Device is not online, please contact customer service!']);
            }
        }
        $good = Db::name('machine_goods')->where(['num' => $num, 'device_sn' => $device['device_sn']])->find();
        if (!$good || $good['stock'] < 1) {
            return json(["code" => 100, "msg" => "The goods are sold out"]);
        }
        $redeemCodeModel = new RedeemCodeModel();
        $code = $redeemCodeModel->where('code', $redeem_code)->find();
        if (!$code) {
            return json(["code" => 100, "msg" => "The redemption code does not exist"]);
        }
        if ($code['status'] == 1) {
            return json(["code" => 100, "msg" => "The conversion code has been used"]);
        }
        $res = Cache::store('redis')->get($redeem_code);
        if ($res) {
            return json(["code" => 100, "msg" => "Exchange, please try again later"]);
        } else {
            Cache::store('redis')->set($redeem_code, 1, 120);
        }
        $goods = Db::name('machine_goods')
            ->alias('num')
            ->join('mall_goods goods', 'num.goods_id=goods.id', 'left')
            ->where(['num.device_sn' => $device['device_sn']])
            ->where(['num.num' => $num])
            ->field('num.num,num.stock,num.price,goods.id,goods.title,goods.image,goods.description')
            ->find();
        $order_sn = time() . mt_rand(1000, 9999);
        $data = [
            'order_sn' => $order_sn,
            'device_sn' => $device['device_sn'],
            'uid' => $device['uid'],
            'redeem_code' => $redeem_code,
            'price' => 0,
            'count' => 1,
            'pay_type' => 8,
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
            'order_id' => $order_id
        ];
        return json(['code' => 200, 'data' => $data, 'msg' => 'Successful verification']);
    }

    //通知兑换码结果  status 1:出货成功  2:出货失败  order_id(订单id) reason(出货失败原因)
    public function payResult()
    {
        $params = request()->post();
        trace($params, '兑换码出货通知');
        $redeem_code = (new FinanceOrder())->where('id', $params['order_id'])->value('redeem_code');
        if ($params['status'] == 1) {
            (new RedeemCodeModel())->where('code', $redeem_code)->update(['status' => 1, 'use_time' => time()]);
        }
        Cache::store('redis')->rm($redeem_code);

        $data = [
            'price' => 0,
            'status' => $params['status'] == 1 ? 1 : 3,
            'pay_time' => time(),
        ];
        (new FinanceOrder())->where('id', $params['order_id'])->update($data);
        //更新库存 不管出货成功还是失败
        $device_sn = (new OrderGoods())->where('order_id', $params['order_id'])->value('device_sn');
        $order_goods = (new OrderGoods())->where('order_id', $params['order_id'])->find();
        trace($order_goods, '订单商品');

        $machineGoodsModel = new MachineGoods();
        $machineGoods = $machineGoodsModel->where(['device_sn' => $device_sn, 'num' => $order_goods['num']])->find();
        $data = ['stock' => $machineGoods['stock'] - 1];
        $machineGoodsModel->where(['device_sn' => $device_sn, 'num' => $order_goods['num']])->update($data);
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

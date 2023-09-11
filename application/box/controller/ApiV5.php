<?php

namespace app\box\controller;

use app\index\model\FinanceOrder;
use app\index\model\MachineDevice;
use app\index\model\MachineGoods;
use app\index\model\MallGoodsModel;
use app\index\model\OrderGoods;
use think\Cache;
use think\Controller;
use think\Db;

//纹身机H5+安卓
class ApiV5 extends Controller
{
    //H5获取商品列表  1货道1商品
    public function goodsList()
    {
        $device_sn = $this->request->get("device_sn", "");
        if (!$device_sn) {
            return json(["code" => 100, "msg" => "设备号不能为空"]);
        }
        $model = new  \app\index\model\MachineDevice();
        $device = $model->where(['device_sn' => $device_sn])->find();
        $goods = Db::name('machine_goods')
            ->alias('num')
            ->join('mall_goods goods', 'num.goods_id=goods.id', 'left')
            ->where('num.device_sn', $device['device_sn'])
            ->field('num.num,num.is_lock,num.stock amount,num.price good_price,goods.id,goods.title,goods.image images,goods.description')
            ->order('num.num asc')
            ->select();
        $data = [];
        foreach ($goods as $k => $v) {
            if ($v['id']) {
                $data[] = $v;
            }
        }
        $data = array_values($data);
        return json(['code' => 200, 'data' => $data]);
    }

    //H5选择商品  type:1-信用卡支付 2-现金支付
    public function chooseGoods()
    {
        $params = request()->post();
        if (empty($params['device_sn']) || empty($params['num']) || empty($params['type'])) {
            return json(['code' => 100, 'msg' => '缺少参数']);
        }
        $model = new  \app\index\model\MachineDevice();
        $device = $model->where(['device_sn' => $params['device_sn']])->find();
        if (!$device) {
            return json(['code' => 100, 'msg' => '设备不存在']);
        }
        $str = $params['device_sn'] . '_pay';
        $res = Cache::store('redis')->get($str);
        if ($res) {
            return json(['code' => 100, 'msg' => '设备正在使用中,请稍后再试']);
        }
        $data = [
            "imei" => $device['imei'],
            "deviceNumber" => $params['device_sn'],
            "laneNumber" => $params['num'],
            "laneType" => 1,
            "paymentType" => $params['type'] == 1 ? 3 : 2,//1在线支付，2纸币支付，3刷信用卡卡支付
            "orderNo" => time() . rand(1000, 9999),
            "timestamp" => time()
        ];
        $url = 'http://feishi.feishi.vip:9100/api/vending/goodsOut';
        $result = https_request($url, $data);
        $result = json_decode($result, true);
        trace($result, '出货指令结果');
        if ($result['code'] == 200) {
            return json(['code' => 200, 'msg' => '成功']);
        } else {
            return json(['code' => 100, 'msg' => $result['msg']]);
        }
    }

    //安卓创建订单
    public function createOrder()
    {
        $params = request()->post();
        if (empty($params['imei']) || empty($params['num'])) {
            return json(['code' => 100, 'msg' => '缺少参数']);
        }

        $model = new  \app\index\model\MachineDevice();
        $device = $model->where(['imei' => $params['imei']])->find();
        if (!$device) {
            return json(['code' => 100, 'msg' => '设备不存在']);
        }
        if ($device['is_lock'] == 0) {
            return json(['code' => 100, 'msg' => 'The device has been disabled!']);
        }
        if ($device['expire_time'] < time()) {
            return json(['code' => 100, 'msg' => 'The equipment has expired, please contact customer service for processing!']);
        }
        if ($device['supply_id'] == 1 || $device['supply_id'] == 5) {
            if ($device['status'] != 1) {
                return json(['code' => 100, 'msg' => 'The device is not online, please contact customer service!']);
            }
        }
        $str = $device['device_sn'] . '_pay';
        $res = Cache::store('redis')->get($str);
        if ($res) {
            return json(['code' => 100, 'msg' => '设备正在使用中,请稍后再试']);
        } else {
            Cache::store('redis')->set($str, 1, 30);
        }

        $goods = (new MachineGoods())->where(['device_sn' => $device['device_sn'], 'num' => $params['num']])->find();
        $order_sn = time() . $device['uid'] . rand(1000, 9999);
        $order_data = [
            'device_sn' => $device['device_sn'],
            'uid' => $device['uid'],
            'price' => $goods['price'],
//            'pay_type' => $params['type'] == 1 ? 7 : 9,
            'order_sn' => $order_sn,
            'status' => 0,
            'count' => 1,
            'create_time' => time()
        ];
        $mallGoodsModel = new MallGoodsModel();
        $mall_goods = $mallGoodsModel->where('id', $goods['goods_id'])->find();
        $profit = 0;
        if ($mall_goods['cost_price'] > 0) {
            $profit = round(($goods['price'] - $mall_goods['cost_price']) * 100) / 100;
        }
        $order_data['profit'] = $profit;
        $order_data['cost_price'] = $mall_goods['cost_price'] > 0 ? $mall_goods['cost_price'] : 0;
//        $order_data['other_cost_price'] = $mall_goods['other_cost_price'] > 0 ? $mall_goods['other_cost_price'] : 0;
        $orderModel = new FinanceOrder();
        $order_id = $orderModel->insertGetId($order_data);
        $goods_data = [
            'order_id' => $order_id,
            'device_sn' => $device['device_sn'],
            'order_sn' => $order_sn,
            'num' => $params['num'],
            'goods_id' => $goods['goods_id'],
            'price' => $goods['price'],
            'count' => 1,
            'total_price' => $goods['price'],
        ];
        (new OrderGoods())->save($goods_data);
        $data = [
            'order_sn' => $order_sn,
            'price' => $goods['price'],
            'num' => $params['num'],
            'count' => 1,
        ];
        return json(['code' => 200, 'data' => $data]);
    }

    //安卓端取消支付
    public function cancelPay()
    {
        $imei = request()->get('imei', '');
        if (!$imei) {
            return json(['code' => 100, 'msg' => '缺少参数']);
        }
        $device_sn = (new MachineDevice())->where('imei', $imei)->value('device_sn');
        if (!$device_sn) {
            return json(['code' => 100, 'msg' => '设备不存在']);
        }
        $str = $device_sn . '_pay';
        $res = Cache::store('redis')->rm($str);
        return json(['code' => 200, 'data' => '取消成功']);
    }

    //通知付款结果  status 1:付款成功  2:付款失败 3:已退款  order_sn(订单号) type:1-信用卡支付 2-现金支付
    public function payResult()
    {
        $params = request()->post();
        trace($params, '纹身机付款通知');
        $order = (new FinanceOrder())->where('order_sn', $params['order_sn'])->find();
        $str = $order['device_sn'] . '_pay';
        Cache::store('redis')->rm($str);
        if ($params['status'] == 1 || $params['status'] == 3) {
            $data = [
                'status' => $params['status'] == 1 ? 1 : 2,
                'pay_time' => time(),
                'pay_type' => $params['type'] == 1 ? 7 : 9,
            ];
            (new FinanceOrder())->where('order_sn', $params['order_sn'])->update($data);

            //更新库存 不管出货成功还是失败
            $device_sn = $order['device_sn'];
            $order_goods = (new OrderGoods())->where('order_id', $order['id'])->group('num')->column('sum(count) total_count,goods_id,device_sn', 'num');
            trace($order_goods, '订单商品');
            $nums = [];
            foreach ($order_goods as $k => $v) {
                $nums[] = $k;
            }
            $machineGoodsModel = new MachineGoods();
            $machine_goods = $machineGoodsModel->where('device_sn', $device_sn)->whereIn('num', $nums)->select();
            foreach ($machine_goods as $k => $v) {
                if (isset($order_goods[$v['num']])) {
                    $data = [
                        'stock' => $v['stock'] - $order_goods[$v['num']]['total_count']
                    ];
                    $machineGoodsModel->where('id', $v['id'])->update($data);
                }
            }
//            $orderGoods = (new OrderGoods())->where('order_id', $order['id'])->find();

        }
        return json(['code' => 200, 'msg' => '成功']);
    }
}

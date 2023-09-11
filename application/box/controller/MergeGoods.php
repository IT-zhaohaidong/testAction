<?php

namespace app\box\controller;

use app\index\common\Oss;
use app\index\model\AppLogModel;
use app\index\model\FinanceOrder;
use app\index\model\MachineDevice;
use app\index\model\MachineGoods;
use app\index\model\MallGoodsModel;
use app\index\model\OrderGoods;
use app\index\model\OutVideoModel;
use think\Controller;
use think\Db;

//信用卡购物车模式  1商品多货道(合并商品) 美妆机(国外)
class MergeGoods extends Controller
{
    //合并货道 购物车模式创建订单
    public function carCreateOrder()
    {
        $post = request()->post();
        $imei = isset($post['imei']) ? $post['imei'] : '';
        $data = isset($post['data']) ? $post['data'] : [];
//        $data = [
//            [
//                'goods_id' => 1,//商品id
//                'count' => 2,//购买数量
//            ], [
//                'goods_id' => 3,//商品id
//                'count' => 2,//购买数量
//            ]
//        ];
        if (empty($imei)) {
            return json(['code' => 100, 'msg' => '缺少参数']);
        }
        if (empty($data)) {
            return json(['code' => 100, 'msg' => 'Please select goods']);
        }
        $device = (new MachineDevice())->where('imei', $imei)->find();
        if ($device['is_lock'] == 0) {
            return json(['code' => 100, 'msg' => 'The device has been disabled!']);
        }
        if ($device['expire_time'] < time()) {
            return json(['code' => 100, 'msg' => 'The equipment has expired, please contact customer service for processing!']);
        }
//        if (in_array($device['supply_id'], [1,2,5,6])) {
//            if ($device['status'] != 1) {
//                return json(['code' => 100, 'msg' => 'The device is not online, please contact customer service!']);
//            }
//        }
        $device_sn = $device['device_sn'];
        $machineGoodsModel = new MachineGoods();
        $orderGoods = [];
        $is_stock = 0;
        $goods_id = 0;
        $total_price = 0;
        $total_count = 0;
        $goods_ids = [];
        foreach ($data as $k => $v) {
            $totalStock = $machineGoodsModel->where('is_lock', 0)->where('device_sn', $device_sn)->where('goods_id', $v['goods_id'])->sum('stock') ?? 0;
            if ($totalStock < $v['count']) {
                $is_stock = 1;
                $goods_id = $v['goods_id'];
                break;
            }
            $goods_ids[] = $v['goods_id'];
            $total_count += $v['count'];
            $machineGoods = $machineGoodsModel
                ->where('device_sn', $device_sn)
                ->where('goods_id', $v['goods_id'])
                ->field('price,active_price,num')
                ->find();
            $price = $machineGoods['active_price'] > 0 ? $machineGoods['active_price'] : $machineGoods['price'];
            $total_price = ($total_price * 100 + $price * 100 * $v['count']) / 100;
            $orderSingleGoods = $this->getOrderGoods($device_sn, $v['goods_id'], $price, $v['count'], []);
            $orderGoods = array_merge($orderGoods, $orderSingleGoods);
        }
        if ($is_stock) {
            $title = (new MallGoodsModel())->where('id', $goods_id)->value('title');
            return json(['code' => 100, 'msg' => $title . " inventory shortage"]);
        }
        if ($total_price < 0.01) {
            return json(['code' => 100, 'msg' => 'The payment must be greater than 0']);
        }
        $order_sn = time() . mt_rand(1000, 9999);
        $data = [
            'order_sn' => $order_sn,
            'device_sn' => $device_sn,
            'uid' => $device['uid'],
            'price' => $total_price,
            'count' => $total_count,
            'create_time' => time(),
        ];
        $data['pay_type'] = 7;
        $order_id = Db::name('finance_order')->insertGetId($data);
        $goods = (new MallGoodsModel())
            ->whereIn('id', $goods_ids)
            ->column('id,title,image,cost_price,other_cost_price,profit', 'id');
        $deal_goods = [];
        $key = 0;
        $total_profit = 0;//总利润
        $total_cost_price = 0;//总成本价
        $total_other_cost_price = 0;//总其他成本价
        foreach ($orderGoods as $k => $v) {
            $orderGoods[$k]['order_id'] = $order_id;
            $out_data[] = ['num' => $v['num'], 'count' => $v['count']];
            for ($i = 1; $i <= $v['count']; $i++) {
                $key++;
                $order_children = $order_sn . 'order' . $key;
                $deal_goods[] = [
                    'device_sn' => $v['device_sn'],
                    'num' => $v['num'],
                    'count' => 1,
                    'order_sn' => $order_children,
                    'order_id' => $order_id,
                    'goods_id' => $v['goods_id'],
                    'price' => $v['price'],
                    'total_price' => $v['price'],
                ];
            }
            if (isset($goods[$v['goods_id']]['cost_price']) && $goods[$v['goods_id']]['cost_price'] > 0) {
                $total_profit += $v['price'] - $goods[$v['goods_id']]['cost_price'] * $v['count'];
                $total_cost_price += $goods[$v['goods_id']]['cost_price'] * $v['count'];
                $total_other_cost_price += $goods[$v['goods_id']]['other_cost_price'] * $v['count'];
            }
        }
        $total_profit = round($total_profit, 2);
        $total_cost_price = round($total_cost_price, 2);
        $total_other_cost_price = round($total_other_cost_price, 2);
        Db::name('finance_order')->where('id', $order_id)->update(['profit' => $total_profit, 'cost_price' => $total_cost_price, 'other_cost_price' => $total_other_cost_price]);
        (new OrderGoods())->saveAll($deal_goods);

        foreach ($deal_goods as $k => $v) {
            if (isset($goods[$v['goods_id']])) {
                $deal_goods[$k]['title'] = $goods[$v['goods_id']]['title'];
            } else {
                $deal_goods[$k]['title'] = '';
            }
        }
        $data = [
            'order_id' => $order_id,
            'data' => $deal_goods
        ];
        return json(['code' => 200, 'data' => $data]);
    }


    //$where不能用的id   $count剩余所需数量  $type_where商品端口 0:全部  1:微信 2:支付宝
    public function getOrderGoods($device_sn, $goods_id, $price, $count, $where = [], $port = 0)
    {
        $item = [];
        $model = new MachineGoods();
        $port_where = [];
        if ($port > 0) {
            $port_where['port'] = ['in', [0, $port]];
        }
        $goods = $model
            ->where('device_sn', $device_sn)
            ->where('goods_id', $goods_id)
            ->where('is_lock', 0)
            ->whereNotIn('id', $where)
            ->where('stock', '>', 0)
            ->field('id,stock,num,goods_id')
            ->find();
        $goods_id = $goods['goods_id'];
        if ($count > $goods['stock']) {
            $where[] = $goods['id'];
            $count = $count - $goods['stock'];
            $item[] = [
                'device_sn' => $device_sn,
                'num' => $goods['num'],
                'goods_id' => $goods_id,
                'price' => $price,
                'count' => 1,
                'total_price' => $price * $goods['stock'],
            ];
            $data = $this->getOrderGoods($device_sn, $goods_id, $price, $count, $where);
            $item = array_merge($item, $data);
            return $item;
        } else {
            $item[] = [
                'device_sn' => $device_sn,
                'num' => $goods['num'],
                'goods_id' => $goods_id,
                'price' => $price,
                'count' => $count,
                'total_price' => $price * $count,
            ];
            return $item;
        }
    }
}

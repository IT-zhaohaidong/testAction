<?php

namespace app\applet\controller;

use app\index\model\FinanceOrder;
use app\index\model\MachineDevice;
use app\index\model\MachineDeviceErrorModel;
use app\index\model\MachineGoods;
use app\index\model\MachineStockLogModel;
use app\index\model\MachineVisionGoodsModel;
use app\index\model\OrderGoods;
use app\index\model\SystemVisionGoodsModel;
use app\index\model\VisionGoodsModel;
use app\index\model\VisionOrderGoodsModel;
use app\index\model\VisionOrderModel;
use think\Cache;
use think\Controller;
use think\Db;

//视觉柜sku回调
class VisualCabinetNotify extends Controller
{
    //sku审核回调
    public function index()
    {
        $params = request()->post();
        trace($params, '视觉柜回调');
        $systemVisionGoodsModel = new SystemVisionGoodsModel();
        $row = $systemVisionGoodsModel->where('sku_id', $params['ks_sku_id'])->find();
        if ($row) {
            if ($params['result']['code'] == 2) {
                $data = [
                    'status' => 1,
                    'reason' => ''
                ];
            } else {
                $data = [
                    'status' => 2,
                    'reason' => $params['comments']
                ];
            }
            $systemVisionGoodsModel->where('sku_id', $params['ks_sku_id'])->update($data);
        } else {
            $visionGoodsModel = new VisionGoodsModel();
            $res = $visionGoodsModel->where('sku_id', $params['ks_sku_id'])->find();
            if ($res) {
                if ($params['result']['code'] == 2) {
                    $data = [
                        'status' => 1,
                        'reason' => ''
                    ];
                    //将代理商提交的sku,审核通过的,加入商品库
                    $system_data = [
                        'uid' => ',' . $res['uid'] . ',',
                        'user_id' => $res['uid'],
                        'code' => $res['code'],
                        'sku_id' => $res['sku_id'],
                        'title' => $res['title'],
                        'image' => $res['image'],
                        'img_urls' => $res['img_urls'],
                        'price' => $res['price'],
                        'add_type' => $res['add_type'],
                        'status' => 1,
                        'create_time' => time()
                    ];
                    $id = $systemVisionGoodsModel->where('sku_id', $params['ks_sku_id'])->insertGetId($system_data);
                    $data['goods_id'] = $id;
                } else {
                    $data = [
                        'status' => 2,
                        'reason' => $params['comments']
                    ];
                }
                $visionGoodsModel->where('sku_id', $params['ks_sku_id'])->update($data);
            }
        }
        return json(['code' => "10000", 'msg' => '请求成功', 'data' => json([])]);
    }

    //订单 SKU识别结果回调通知
    public function orderNotify()
    {
        $params = request()->post();
        trace($params, '视觉柜订单SKU识别结果回调通知');
        $orderModel = new VisionOrderModel();
        $order = $orderModel->where('ks_recog_id', $params['ks_recog_id'])->find();
        if ($order) {
            if ($params['result']['status']['code'] == 2) {
                $goodsModel = new MachineVisionGoodsModel();
                $goods = $goodsModel->alias('mg')
                    ->join('vision_goods g', 'g.id=mg.goods_id', 'left')
                    ->where('mg.device_sn', $order['device_sn'])
                    ->column('mg.price,mg.goods_id,mg.stock,mg.id', 'g.sku_id');
                $order_goods = [];
                $order_price = 0;
                foreach ($params['result']['result'] as $k => $v) {
                    $price = $v['sku_num'] * $goods[$v['ks_sku_id']]['price'];
                    $order_goods[] = [
                        'order_id' => $order['id'],
                        'goods_id' => $goods[$v['ks_sku_id']]['goods_id'],
                        'num' => $v['sku_num'],
                        'money' => $price,
                    ];
                    $order_price += $price;
                    //减库存
                    $goodsModel->where('id',$goods[$v['ks_sku_id']]['id'])->update([$goods[$v['ks_sku_id']]['id']-$v['sku_num']]);
                }
                //添加订单商品
                $orderGoodsModel=new VisionOrderGoodsModel();
                $orderGoodsModel->saveAll($order_goods);
                //修改订单状态
                $order_data = [
                    'status' => 2,
                    'code' => 0,
                    'price' => $order_price,
                ];
                $orderModel->where('id', $order['id'])->update($order_data);
                // todo 结束支付分订单

                //todo 添加余额记录 修改代理商余额

            } elseif ($params['result']['status']['code'] == 3) {
                //todo 取消或结束支付分订单

                //修改订单状态
                $order_data = [
                    'status' => 2,
                    'code' => 0,
                    'price' => 0,
                ];
                $orderModel->where('id', $order['id'])->update($order_data);
            } else {
                //修改订单为异常状态
                $order_data = [
                    'status' => 3,
                    'code' => $params['result']['status']['code'],
                ];
                $orderModel->where('id', $order['id'])->update($order_data);
            }
        }
        return json(['code' => "10000", 'msg' => '请求成功', 'data' => json([])]);
    }
}

<?php

namespace app\box\controller;

use app\index\common\Email;
use app\index\common\Oss;
use app\index\model\AppLogModel;
use app\index\model\FinanceOrder;
use app\index\model\MachineDevice;
use app\index\model\MachineGoods;
use app\index\model\MallGoodsModel;
use app\index\model\OrderGoods;
use app\index\model\OutVideoModel;
use app\index\model\SystemAdmin;
use think\Controller;
use think\Db;

//信用卡购物车模式  1商品多货道(合并商品) 美妆机(国外)
class ApiV3 extends Controller
{
    //一商品一货道 购物车模式创建订单
    public function carCreateOrder()
    {
        $post = request()->post();
        $imei = isset($post['imei']) ? $post['imei'] : '';
        $data = isset($post['data']) ? $post['data'] : [];
//        $data = [
//            [
//                'goods_id' => 1,//商品id
//                'count' => 2,//购买数量
//                'num' => 2,//货道号
//            ], [
//                'goods_id' => 3,//商品id
//                'count' => 2,//购买数量
//                'num' => 2,//货道号
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
            $totalStock = $machineGoodsModel->where('device_sn', $device_sn)->where('num', $v['num'])->where('is_lock',0)->value('stock');
            if ($totalStock < $v['count']) {
                $is_stock = 1;
                $goods_id = $v['goods_id'];
                break;
            }
            $goods_ids[] = $v['goods_id'];
            $total_count += $v['count'];
            $machineGoods = $machineGoodsModel
                ->where('device_sn', $device_sn)
                ->where('num', $v['num'])
                ->where('is_lock',0)
                ->field('price,active_price,num')
                ->find();
            $price = $machineGoods['active_price'] > 0 ? $machineGoods['active_price'] : $machineGoods['price'];
            $total_price = ($total_price * 100 + $price * 100 * $v['count']) / 100;
            $orderSingleGoods = $this->getOrderGoods($device_sn, $v['num'], $price, $v['count'], []);
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
//            'goods_id' => $goods['id'],
//            'route_number' => $goods['num'],
            'price' => $total_price,
            'count' => $total_count,
            'create_time' => time(),
        ];
        $data['pay_type'] = 7;
        $order_id = Db::name('finance_order')->insertGetId($data);
        $mall_goods = (new MallGoodsModel())
            ->whereIn('id', $goods_ids)
            ->column('cost_price,other_cost_price,profit', 'id');
        $deal_goods = [];
        $goods_ids = [];
        $key = 0;
        $total_profit = 0;//总利润
        $total_cost_price = 0;//总成本价
        $total_other_cost_price = 0;//总其他成本价
        foreach ($orderGoods as $k => $v) {
            $orderGoods[$k]['order_id'] = $order_id;
            $out_data[] = ['num' => $v['num'], 'count' => $v['count']];
            $goods_ids[] = $v['goods_id'];
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
            if (isset($mall_goods[$v['goods_id']]['cost_price']) && $mall_goods[$v['goods_id']]['cost_price'] > 0) {
                $total_profit += $v['total_price'] -
                    $mall_goods[$v['goods_id']]['cost_price'] * $v['count'];
                $total_cost_price += $mall_goods[$v['goods_id']]['cost_price'] * $v['count'];
//                $total_other_cost_price += $mall_goods[$v['goods_id']]['other_cost_price'] * $v['count'];
            }
        }
        $total_profit = round($total_profit, 2);
        $total_cost_price = round($total_cost_price, 2);
//        $total_other_cost_price = round($total_other_cost_price, 2);
        Db::name('finance_order')->where('id', $order_id)->update(['profit' => $total_profit, 'cost_price' => $total_cost_price, 'other_cost_price' => $total_other_cost_price]);
        $goods = (new MallGoodsModel())->whereIn('id', $goods_ids)->column('id,title,image', 'id');
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
    public function getOrderGoods($device_sn, $num, $price, $count, $where = [], $port = 0)
    {
        $item = [];
        $model = new MachineGoods();
        $port_where = [];
        if ($port > 0) {
            $port_where['port'] = ['in', [0, $port]];
        }
        $goods = $model
            ->where('device_sn', $device_sn)
            ->where('num', $num)
            ->where('is_lock',1)
            ->field('id,stock,num,goods_id')
            ->find();
        $goods_id = $goods['goods_id'];
        if ($count > $goods['stock']) {
            $where[] = $goods['id'];
//            $count = $count - $goods['stock'];
            $item[] = [
                'device_sn' => $device_sn,
                'num' => $goods['num'],
                'goods_id' => $goods_id,
                'price' => $price,
                'count' => 1,
                'total_price' => $price * $goods['stock'],
            ];
//            $data = $this->getOrderGoods($device_sn, $goods_id, $price, $count, $where);
//            $item = array_merge($item, $data);
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

    //通知付款结果 美国美妆机专用  status 1:付款成功  2:付款失败  price: 支付金额(付款金额-退款金额)  order_id(订单id)
    public function payResult()
    {
        $params = request()->get();
        trace($params, '信用卡付款通知');
        if ($params['status'] == 1) {
            $data = [
                'price' => $params['price'],
                'status' => 1,
                'pay_time' => time(),
            ];
            (new FinanceOrder())->where('id', $params['order_id'])->update($data);
            //更新库存 不管出货成功还是失败
            $device = (new OrderGoods())->where('order_id', $params['order_id'])->field('device_sn')->find();
            $order_goods = (new OrderGoods())->where('order_id', $params['order_id'])->group('num')->column('sum(count) total_count,goods_id,device_sn', 'num');
            trace($order_goods, '订单商品');
            $nums = [];
            foreach ($order_goods as $k => $v) {
                $nums[] = $k;
            }
            $machineGoodsModel = new MachineGoods();
            $machine_goods = $machineGoodsModel->where('device_sn', $device['device_sn'])->whereIn('num', $nums)->select();
            foreach ($machine_goods as $k => $v) {
                if (isset($order_goods[$v['num']])) {
                    $data = [
                        'stock' => $v['stock'] - $order_goods[$v['num']]['total_count']
                    ];
                    $machineGoodsModel->where('id', $v['id'])->update($data);
                }
            }
            $uid = (new FinanceOrder())->where('id', $params['order_id'])->value('uid');
            if ($uid == 188) {
                $email = (new SystemAdmin())->where('id', $uid)->value('email');
                if ($email) {
                    $order_sn = (new FinanceOrder())->where('id', $params['order_id'])->value('order_sn');
                    $device = (new MachineDevice())->alias('d')
                        ->join('system_admin a', 'a.id=d.uid', 'left')
                        ->where('d.device_sn', $device['device_sn'])
                        ->field('d.device_name,a.username')
                        ->find();

                    $title = "New order alert";
//                    $body = "You have a transaction of $" . $params['price'] . ", the order number is " . $order_sn . ", please check";
                    $body = "<div style='text-align: center;width: 100%;'><img src='https://fs-manghe.oss-cn-hangzhou.aliyuncs.com/goods_image/7d12056b0c05c706b776d700b9a10e1.png' alt='' style='width: 30%;object-fit: contain'></div><br>
<div style=\"border: 1px solid #000000;height: 100px;\"><div style=\"width: 100%; height: 50px; display: flex;justify-content: center;align-items: center;border-bottom: 1px solid #000000;\"><div style=\"width: 50%;border-right: 1px solid #000000;text-align: center;line-height: 50px;font-weight: bold;\">Order number</div><div style=\"width: 50%;text-align: center;line-height: 50px;font-weight: bold;\">Money</div></div><div style=\"width: 100%;height: 50px; display: flex;justify-content: center;align-items: center;\"><div style=\"width: 50%;border-right: 1px solid #000000;text-align: center;line-height: 50px;\">{$order_sn}</div><div style=\"width: 50%;text-align: center;line-height: 50px;\"><span>$</span>{$params['price']}</div></div></div><div style=\"border: 1px solid #000000;height: 100px;\"><div style=\"width: 100%; height: 50px; display: flex;justify-content: center;align-items: center;border-bottom: 1px solid #000000;\"><div style=\"width: 50%;border-right: 1px solid #000000;text-align: center;line-height: 50px;font-weight: bold;\">Device name</div><div style=\"width: 50%;text-align: center;line-height: 50px;font-weight: bold;\">Username</div></div><div style=\"width: 100%;height: 50px; display: flex;justify-content: center;align-items: center;\"><div style=\"width: 50%;border-right: 1px solid #000000;text-align: center;line-height: 50px;\">{$device['device_name']}</div><div style=\"width: 50%;text-align: center;line-height: 50px;\">{$device['username']}</div></div></div><br>
<div>Please check the trading order email, thank you</div>";
                    $res = (new Email())->send_email($title, $body, $email);
                    trace($res, '邮件发送结果');
                }
            }
        }
        return json(['code' => 200, 'msg' => '成功']);
    }

    //上传app日志
    public function uploadLog()
    {
        $post = request()->post();
        $file = $_FILES['file'];
        $file_name = $file['name'];//获取缓存区图片,格式不能变
        $type = array("txt");//允许选择的文件类型
        $ext = explode(".", $file_name);//拆分获取图片名
        $ext = $ext[count($ext) - 1];//取图片的后缀名
        $path = dirname(dirname(dirname(dirname(__FILE__))));
        if (in_array($ext, $type)) {
//            if ($_FILES["file"]["size"] / 1024 / 1024 > 20) {
//                return json(['code' => 100, 'msg' => '上传文件不可大于5M!']);
//            }
            $name = "/public/upload/applog/" . date('Ymd') . '/';
            $dirpath = $path . $name;
            if (!is_dir($dirpath)) {
                mkdir($dirpath, 0777, true);
            }
            $time = time() . rand(1000, 9999);
            $filename = $dirpath . $time . '.' . $ext;
            move_uploaded_file($file["tmp_name"], $filename);
            $ossFile = "log/" . $time . '.' . $ext;
            $url = (new Oss())->uploadToOss($ossFile, $filename);
//            $filename = Env::get('server.servername', 'http://api.feishi.vip') . '/upload/applog/' . date('Ymd') . '/' . $time . '.' . $ext;
            $data = [
                'imei' => isset($post['imei']) ? $post['imei'] : '',
                'url' => $url
            ];
            (new AppLogModel())->save($data);
            $data = ['code' => 200, 'data' => ['filename' => $url]];
            return json($data);
        } else {
            return json(['code' => 100, 'msg' => '文件类型错误!']);
        }
    }

    //上传app日志 安卓直传oss
    public function addLog()
    {
        $post = request()->post();
        if (empty($post['url']) || empty($post['imei'])) {
            return json(['code' => 100, 'msg' => '缺少参数']);
        }
        $data = [
            'imei' => isset($post['imei']) ? $post['imei'] : '',
            'url' => $post['url']
        ];
        (new AppLogModel())->save($data);
        $data = ['code' => 200, 'msg' => '上传成功'];
        return json($data);
    }

    //上传出货视频
    public function uploadVideo()
    {
        $post = request()->post();
        $file = $_FILES['file'];
        $file_name = $file['name'];//获取缓存区图片,格式不能变
        $type = array("mp4");//允许选择的文件类型
        $ext = explode(".", $file_name);//拆分获取图片名
        $ext = $ext[count($ext) - 1];//取图片的后缀名
        $path = dirname(dirname(dirname(dirname(__FILE__))));
        if (in_array($ext, $type)) {
            if ($_FILES["file"]["size"] / 1024 / 1024 > 15) {
                return json(['code' => 100, 'msg' => '上传文件不可大于15M!']);
            }
            $name = "/public/upload/out-video/" . date('Ymd') . '/';
            $dirpath = $path . $name;
            if (!is_dir($dirpath)) {
                mkdir($dirpath, 0777, true);
            }
            $time = time() . rand(1000, 9999);
            $filename = $dirpath . $time . '.' . $ext;
            move_uploaded_file($file["tmp_name"], $filename);
            $ossFile = "out-video/" . $time . '.' . $ext;
            $url = (new Oss())->uploadToOss($ossFile, $filename);
            $device_sn = (new MachineDevice())->where('imei', $post['imei'])->value('device_sn');
            $data = [
                'imei' => isset($post['imei']) ? $post['imei'] : '',
                'device_sn' => $device_sn,
                'url' => $url,
                'order_sn' => $post['order_sn'],
                'num' => $post['num'],
            ];
            (new OutVideoModel())->save($data);
            $data = ['code' => 200, 'data' => ['filename' => $url]];
            return json($data);
        } else {
            return json(['code' => 100, 'msg' => '文件类型错误!']);
        }
    }
}

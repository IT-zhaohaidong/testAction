<?php

namespace app\box\controller;


use app\index\model\CompanyQrcodeModel;
use app\index\model\FinanceOrder;
use app\index\model\MachineDevice;
use app\index\model\MachineDeviceErrorModel;
use app\index\model\MachineGoods;
use app\index\model\MallGoodsModel;
use app\index\model\OrderGoods;
use app\index\model\SystemAdmin;
use think\Cache;
use think\Controller;
use think\Db;

//前端控制出货设备版本
class ApiV2 extends Controller
{
    public function getDeviceSn()
    {
        $str = 'FS' . date('Ymd');
        $device = Db::name('machine_device')->where("device_sn", 'like', '%' . $str . '%')->order('device_sn desc')->field('device_sn')->find();
        if ($device) {
            $num = substr($device['device_sn'], strlen($str)) + 1;
        } else {
            $num = 1000;
        }
        $device_sn = $str . $num;
        return $device_sn;
    }

    //获取广告
    public function getAdver()
    {
        $imei = $this->request->get("imei", "");
        if (!$imei) {
            return json(["code" => 100, "msg" => "imei号不能为空"]);
        }
        $device = Db::name('machine_device')->where(['imei' => $imei])->where('delete_time', null)->find();
        if (!$device) {
//            $device_sn = $this->getDeviceSn();
//            $data = [
//                'imei' => $imei,
//                'device_sn' => $device_sn,
//                'device_name' => $imei,
//                'qr_code' => qrcode($device_sn),
//                'official_code' => "",
//                'uid' => 1,
//                'supply_id' => 2
//            ];
//            $id = Db::name('machine_device')->insertGetId($data);
//            $device['id'] = $id;
            return json(['code' => 100, 'msg' => '设备不存在,稍后重试']);
        }

        $video = Db::name('machine_video')
            ->where(['device_id' => $device['id']])
            ->value('video_id');
        $videos = [];
        if ($video) {
            $videos = Db::name('adver_material')
                ->where('id', 'in', $video)
                ->where('start_time', '<=', time())
                ->where('end_time', '>=', time() - 24 * 3600)->column('url');
            $videos = array_values($videos);
        }
        $model = new  \app\index\model\MachineDevice();
        $image = $model->alias('d')
            ->join('machine_banner b', 'd.banner_id=b.id', 'left')
            ->where('d.device_sn', $device['device_sn'])
            ->value('b.material_image');
        $images = $image ? explode(',', $image) : [];
        $wx_qr_code = (new CompanyQrcodeModel())->where('device_sn', $device['device_sn'])->value('qr_code');
        $data = ['video' => $videos, 'images' => $images, 'qr_code' => $device['qr_code'], 'wx_qr_code' => $wx_qr_code];
        return json(["code" => 200, "data" => $data]);
    }

    //韩国 获取设备H5二维码
    public function getQrCode()
    {
        $imei = $this->request->get("imei", "");
        if (!$imei) {
            return json(["code" => 100, "msg" => "imei号不能为空"]);
        }
        $device = Db::name('machine_device')->where(['imei' => $imei])->field('id,h_qrcode,device_sn')->find();
        if (!$device) {
            return json(['code' => 100, 'msg' => '设备不存在,稍后重试']);
        }
        if (empty($device['h_qrcode'])) {
            $qrcode = $this->qrcode($device['device_sn']);
            Db::name('machine_device')->where(['id' => $device['id']])->update(['h_qrcode' => $qrcode]);
        } else {
            $qrcode = $device['h_qrcode'];
        }
        return json(['code' => 200, 'data' => ['qrcode' => $qrcode]]);
    }

    function qrcode($msg)
    {
        $res_msg = "device_sn=" . $msg;

        $url = 'http://korea.feishi.vip/#/';
        // 1. 生成原始的二维码(生成图片文件)=
        require_once $_SERVER['DOCUMENT_ROOT'] . '/static/phpqrcode.php';
        $value = $url . "?" . $res_msg;;         //二维码内容
        $errorCorrectionLevel = 'L';  //容错级别
        $matrixPointSize = 10;      //生成图片大小
        //生成二维码图片
        $filename = $_SERVER['DOCUMENT_ROOT'] . '/upload/device_code/' . time() . '.png';
        $time = time() . rand(0, 9);
        $filename1 = $_SERVER['DOCUMENT_ROOT'] . '/upload/device_code/' . $time . '.png';
        \QRcode::png($value, $filename, $errorCorrectionLevel, $matrixPointSize, 4);

        $QR = $filename;        //已经生成的原始二维码图片文件
        $QR = imagecreatefromstring(file_get_contents($QR));
        //输出图片
        imagepng($QR, $filename);
        imagedestroy($QR);

        $fontPath = $_SERVER['DOCUMENT_ROOT'] . "/static/plugs/font-awesome-4.7.0/fonts/simkai.ttf";
        $obj = addFontToPic($filename, $fontPath, 18, $msg, 360, $filename1);
        return 'http://' . $_SERVER['SERVER_NAME'] . '/upload/device_code/' . $time . '.png';
    }

    //商品列表  1货道1商品
    public function goodsList()
    {
        $imei = $this->request->get("imei", "");
        if (!$imei) {
            return json(["code" => 100, "msg" => "imei号不能为空"]);
        }
        $device = Db::name('machine_device')->where(['imei' => $imei])->find();
        $device_sn = $device['device_sn'];
        if (!$device) {
//            $device_sn = $this->getDeviceSn();
//            $data = [
//                'imei' => $imei,
//                'device_sn' => $device_sn,
//                'device_name' => $imei,
//                'qr_code' => qrcode($device_sn),
//                'official_code' => "",
//                'uid' => 1,
//                'supply_id' => 2
//            ];
//            $id = Db::name('machine_device')->insertGetId($data);
//            $device['id'] = $id;
            return json(['code' => 100, 'msg' => '设备不存在,稍后重试']);
        }

        $model = new  \app\index\model\MachineDevice();
        $image = $model->alias('d')
            ->join('machine_banner b', 'd.banner_id=b.id', 'left')
            ->where('d.device_sn', $device_sn)
            ->value('b.material_image');
        $images = $image ? explode(',', $image) : [];
        $goods = Db::name('machine_goods')
            ->alias('num')
            ->join('mall_goods goods', 'num.goods_id=goods.id', 'left')
            ->where('num.device_sn', $device['device_sn'])
            ->field('num.num,num.stock amount,num.price good_price,goods.id,goods.title,goods.image images,goods.detail,goods.goods_images,goods.description')
            ->order('num.num asc')
            ->select();
        $data = [];
        foreach ($goods as $k => $v) {
            if ($v['id']) {
                $goods_images = $v['goods_images'] ? explode(',', $v['goods_images']) : [];
                $v['goods_images'] = $goods_images;
                $v['detail'] = $v['detail'] ? explode(',', $v['detail']) : [];
                $data[] = $v;
            }

        }
        return json(['code' => 200, 'data' => $data, 'images' => $images]);
    }

    //一商品多货道
    public function mergeGoodsList()
    {
        $imei = $this->request->get("imei", "");
        if (!$imei) {
            return json(["code" => 100, "msg" => "imei号不能为空"]);
        }
        $device = Db::name('machine_device')->where(['imei' => $imei])->find();
        if (!$device) {
            return json(['code' => 100, 'msg' => '设备不存在,稍后重试']);
        }

        $data = (new \app\index\model\MachineGoods())->alias("g")
            ->join("mall_goods s", "g.goods_id=s.id", "LEFT")
            ->field("g.goods_id,g.price good_price,s.image,s.detail,s.description,s.goods_images,s.title,sum(g.stock) amount")
            ->where("g.device_sn", $device['device_sn'])
            ->where('g.goods_id', '>', 0)
            ->where('g.num', '<=', $device['num'])
            ->order('g.num asc')
            ->group('g.goods_id')
            ->select();
        foreach ($data as $k => $v) {
            $goods_images = $v['goods_images'] ? explode(',', $v['goods_images']) : [];
            $data[$k]['goods_images'] = $goods_images;
            $data[$k]['detail'] = $v['detail'] ? explode(',', $v['detail']) : [];
        }
        $model = new  \app\index\model\MachineDevice();
        $image = $model->alias('d')
            ->join('machine_banner b', 'd.banner_id=b.id', 'left')
            ->where('d.device_sn', $device['device_sn'])
            ->value('b.material_image');
        $images = $image ? explode(',', $image) : [];
        return json(['code' => 200, 'data' => $data, 'images' => $images]);
    }

    //单商品生成订单
    public function createOrder()
    {
        $imei = $this->request->post("imei", "");
        $num = $this->request->post("num", "");
        $goods_id = $this->request->post("goods_id", "");
        if (!$imei) {
            return json(["code" => 100, "msg" => "imei号不能为空"]);
        }
        $device = (new MachineDevice())->where('imei', $imei)->find();
        if ($device['expire_time'] < time()) {
            return json(['code' => 100, 'msg' => '设备已过期,请联系客服处理!']);
        }
        $mallGoodsModel = new MallGoodsModel();
        if (isset($goods_id) && $goods_id > 0) {
            //一商品多货道
            $good = Db::name('machine_goods')
                ->where(['goods_id' => $goods_id, 'device_sn' => $device['device_sn']])
                ->where('stock', '>', 0)
                ->where('is_lock', 0)
                ->order('num asc')
                ->find();
            if (!$good) {
                return json(["code" => 100, "msg" => "该商品已售空"]);
            }
            $num = $good['num'];
            $mall_goods = $mallGoodsModel->where('id', $good['goods_id'])->find();
        } else {
            //一商品一货道
            $good = Db::name('machine_goods')->where(['num' => $num, 'device_sn' => $device['device_sn']])->find();
            if (!$good || $good['stock'] < 1) {
                return json(["code" => 100, "msg" => "该商品已售空"]);
            }
            if ($good['is_stock'] == 1) {
                return json(["code" => 100, "msg" => "该货道已被锁定,暂不能购买"]);
            }
            $mall_goods = $good;
        }
        if ($device['is_lock'] == 0) {
            return json(['code' => 100, 'msg' => '该设备已被禁用']);
        }
        if ($device['supply_id'] != 3) {
            if ($device['status'] != 1) {
                return json(['code' => 100, 'msg' => '设备不在线,请联系客服处理!']);
            }
        }
        if ($device['expire_time'] < time()) {
            return json(['code' => 100, 'msg' => '设备已过期,请联系客服处理!']);
        }
        if ($good['price'] < 0.01) {
            return json(["code" => 100, "msg" => "付款金额不能少于0.01"]);
        }
        $goods = Db::name('machine_goods')
            ->alias('num')
            ->join('mall_goods goods', 'num.goods_id=goods.id', 'left')
            ->where(['num.device_sn' => $device['device_sn']])
            ->where(['num.num' => $num])
            ->field('num.num,num.stock,num.price,goods.id,goods.title,goods.image,goods.description')
            ->find();
        $profit = 0;
        if ($mall_goods['cost_price'] > 0) {
            $profit = round(($goods['price'] - $mall_goods['cost_price']) * 100) / 100;
        }
        $order_sn = time() . mt_rand(1000, 9999);
        $pay = new Wxpay();
        $total_fee = $goods['price'];
        $uid = (new MachineDevice())->where("device_sn", $device['device_sn'])->value("uid");
        $user = Db::name('system_admin')->where("id", $uid)->find();
        $data = [
            'order_sn' => $order_sn,
            'device_sn' => $device['device_sn'],
            'uid' => $device['uid'],
//            'goods_id' => $goods['id'],
//            'route_number' => $goods['num'],
            'price' => $goods['price'],
            'count' => 1,
            'profit' => $profit,
            'cost_price' => $mall_goods['cost_price'] ?? 0,
//            'other_cost_price' => $mall_goods['other_cost_price'] ?? 0,
            'create_time' => time(),
        ];

        //当设备所属人未开通商户号时,判断父亲代理商是否开通,若未开通,用系统支付,若开通,用父亲代理商商户号进行支付
        $parentId = [];
        $parentUser = (new SystemAdmin())->getParents($uid, 1);
        foreach ($parentUser as $k => $v) {
            $parentId[] = $v['id'];
        }
        $userList = (new SystemAdmin())->whereIn('id', $parentId)->select();
        $is_set_mchid = false;
        $wx_mchid_id = 0;
        foreach ($userList as $k => $v) {
            if ($v['is_wx_mchid'] == 1 && $v['wx_mchid_id']) {
                $is_set_mchid = true;
                $wx_mchid_id = $v['wx_mchid_id'];
                break;
            }
        }
        if ($is_set_mchid && $wx_mchid_id) {
            $data['pay_type'] = 3;
        } else {
            $data['pay_type'] = 1;
        }
        $order_id = Db::name('finance_order')->insertGetId($data);
        //添加订单商品
        $goods_data = [
            'order_id' => $order_id,
            'order_sn' => $order_sn . 'order1',
            'device_sn' => $device['device_sn'],
            'num' => $num,
            'goods_id' => $goods['id'],
            'price' => $goods['price'],
            'count' => 1,
            'total_price' => $goods['price'],
        ];
        (new OrderGoods())->save($goods_data);
        if ($is_set_mchid && $wx_mchid_id) {
            //代理商
            $notify_url = 'https://api.feishi.vip/box/Wxpay/agentNotify';
            $result = $pay->prepay('', $order_sn, $total_fee, $user, $notify_url);
        } else {
            //系统
            $notify_url = 'https://api.feishi.vip/box/Wxpay/payNotify';
//            var_dump($total_fee);die();
            $result = $pay->prepay('', $order_sn, $total_fee, $user, $notify_url);
        }
        if ($result['return_code'] == 'SUCCESS') {
            $data = [
                'order_id' => $order_id,
                'url' => $result['code_url'],
                'num' => $num
            ];
            return json(['code' => 200, 'data' => $data]);
        } else {
            return json(['code' => 100, 'msg' => $result['return_msg']]);
        }
    }


    //一商品多货道 购物车模式创建订单
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
            return json(['code' => 100, 'msg' => '请选择商品']);
        }
        $device = (new MachineDevice())->where('imei', $imei)->find();
        if ($device['is_lock'] == 0) {
            return json(['code' => 100, 'msg' => '该设备已被禁用']);
        }
        if ($device['expire_time'] < time()) {
            return json(['code' => 100, 'msg' => '设备已过期,请联系客服处理!']);
        }
        if ($device['supply_id'] != 3) {
            if ($device['status'] != 1) {
                return json(['code' => 100, 'msg' => '设备不在线,请联系客服处理!']);
            }
        }
        $device_sn = $device['device_sn'];
        $machineGoodsModel = new MachineGoods();
        $orderGoods = [];
        $is_stock = 0;
        $goods_id = 0;
        $total_price = 0;
        $total_count = 0;
        $goods_ids = [];
        foreach ($data as $k => $v) {
            $totalStock = $machineGoodsModel->where('device_sn', $device_sn)->where('goods_id', $v['goods_id'])->sum('stock');
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
                ->where('is_lock',0)
                ->field('price,num')
                ->order('num asc')
                ->find();
            $total_price = ($total_price * 100 + $machineGoods['price'] * 100 * $v['count']) / 100;
            $orderSingleGoods = $this->getOrderGoods($device_sn, $v['goods_id'], $machineGoods['price'], $v['count'], []);
            $orderGoods = array_merge($orderGoods, $orderSingleGoods);
        }
        if ($is_stock) {
            $title = (new MallGoodsModel())->where('id', $goods_id)->value('title');
            return json(['code' => 100, 'msg' => $title . " 库存不足"]);
        }
        if ($total_price < 0.01) {
            return json(['code' => 100, 'msg' => '付款金额必须大于0']);
        }
        $order_sn = time() . mt_rand(1000, 9999);
        $pay = new Wxpay();
        $uid = (new MachineDevice())->where("device_sn", $device['device_sn'])->value("uid");
        $user = Db::name('system_admin')->where("id", $uid)->find();
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
        //当设备所属人未开通商户号时,判断父亲代理商是否开通,若未开通,用系统支付,若开通,用父亲代理商商户号进行支付
        $parentId = [];
        $parentUser = (new SystemAdmin())->getParents($user['id'], 1);
        foreach ($parentUser as $k => $v) {
            $parentId[] = $v['id'];
        }
        $userList = (new SystemAdmin())->whereIn('id', $parentId)->select();
        $is_set_mchid = false;
        $wx_mchid_id = 0;
        foreach ($userList as $k => $v) {
            if ($v['is_wx_mchid'] == 1 && $v['wx_mchid_id']) {
                $is_set_mchid = true;
                $wx_mchid_id = $v['wx_mchid_id'];
                break;
            }
        }
        if ($is_set_mchid && $wx_mchid_id) {
            $data['pay_type'] = 3;
        } else {
            $data['pay_type'] = 1;
        }
        $order_id = Db::name('finance_order')->insertGetId($data);
        $mall_goods = (new MallGoodsModel())
            ->whereIn('id', $goods_ids)
            ->column('cost_price,other_cost_price,profit', 'id');
        $out_data = [];
        foreach ($orderGoods as $k => $v) {
            $orderGoods[$k]['order_id'] = $order_id;
            $out_data[] = ['num' => $v['num'], 'count' => $v['count']];
        }
        $deal_goods = [];
        $key = 0;
        $total_profit = 0;//总利润
        $total_cost_price = 0;//总成本价
        $total_other_cost_price = 0;//总其他成本价
        foreach ($orderGoods as $k => $v) {
            $orderGoods[$k]['order_id'] = $order_id;
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
                    'total_price' => $v['total_price'],
                ];
                $out_data[] = ['num' => $v['num'], 'count' => 1, 'order_sn' => $order_children,];
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
        (new OrderGoods())->saveAll($deal_goods);
        if ($is_set_mchid && $wx_mchid_id) {
            //代理商
            $notify_url = 'https://api.feishi.vip/box/Wxpay/agentNotify';
            $result = $pay->prepay('', $order_sn, $total_price, $user, $notify_url);
        } else {
            //系统
            $notify_url = 'https://api.feishi.vip/box/Wxpay/payNotify';
//            var_dump($total_fee);die();
            $result = $pay->prepay('', $order_sn, $total_price, $user, $notify_url);
        }
        if ($result['return_code'] == 'SUCCESS') {
            $data = [
                'order_id' => $order_id,
                'url' => $result['code_url'],
                'data' => $out_data
            ];
            return json(['code' => 200, 'data' => $data]);
        } else {
            return json(['code' => 100, 'msg' => $result['return_msg']]);
        }
    }

    //$where不能用的id   $count剩余所需数量  $type_where商品端口 0:全部  1:微信 2:支付宝
    public function getOrderGoods($device_sn, $goods_id, $price, $count, $where, $port = 0)
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
            ->whereNotIn('id', $where)
            ->where('is_lock',0)
            ->where('stock', '>', 0)
            ->where($port_where)
            ->order('num asc')
            ->field('id,stock,num')
            ->find();

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

    //获取支付结果
    public function getProgress()
    {
        $order_id = request()->get('order_id', '');
        if (!$order_id) {
            return json(['code' => 100, 'msg' => '缺少参数']);
        }
        $model = new FinanceOrder();
        $order = $model->where('id', $order_id)->field('status,device_sn')->find();
        $status = $order['status'];
        $uid = Db::name('machine_device')->where('device_sn', $order['device_sn'])->value('uid');
        $phone = Db::name('system_admin')->where('id', $uid)->value('phone');
        if ($status == 4) {
            $data = [
                'status' => 2,
                'remark' => '',
                'phone' => $phone ?? '',
                'qrcode' => 'https://tanhuang.feishikeji.cloud/static/common/images/qrcode_for_gh_2e7d350d9ff5_430.jpg'
            ];

        } else {
            $data = [
                'status' => 1,
                'remark' => '',
                'phone' => $phone ?? '',
                'qrcode' => "https://tanhuang.feishikeji.cloud/static/common/images/qrcode_for_gh_2e7d350d9ff5_430.jpg"
            ];
        }
        return json(['code' => 200, 'data' => $data]);
    }

    //通知后台是否出货 status=>1:出货成功 2:出货失败
    public function isChuhuo()
    {
        $order_id = request()->get('order_id', '');
        $status = request()->get('status', '');
        $errCode = request()->get('errCode', '');
        $remark = request()->get('remark', '');
        if (!$order_id) {
            return json(['code' => 100, 'msg' => '订单号不能为空']);
        }
        $row = Db::name('finance_order')->where(['id' => $order_id, 'status' => 4])->find();
        if ($row) {
            $goods = Db::name('order_goods')->where(['order_id' => $order_id])->find();
            if ($status == 1) {
                $where = [
                    'device_sn' => $goods['device_sn'],
                    'num' => $goods['num']
                ];
                $cukun = Db::name('machine_goods')->where($where)->value('stock');
                $jiankucun = Db::name('machine_goods')->where($where)->update(['stock' => $cukun - 1]);
                Db::name('finance_order')->where(['id' => $order_id])->update(['status' => 1]);
            } else {
//                Db::name('order_goods')->where(['order_id' => $order_id])->update(['status' => 3]);
                $imei = (new MachineDevice())->where('device_sn', $goods['device_sn'])->value('imei');
                $data = [
                    'device_sn' => $goods['device_sn'],
                    'imei' => $imei,
                    'num' => $goods['num'],
                    'order_sn' => $row['order_sn'],
                    'status' => $errCode,
                    'remark' => $remark,
                    'create_time' => time(),
                ];
                Db::name('machine_device_error')->insert($data);
            }
            return json(['code' => 200, 'msg' => '操作成功']);
        } else {
            return json(['code' => 100, 'msg' => '无效订单']);
        }
    }

    //获取设备信息
    public function getDevice()
    {
        $imei = request()->get('imei', '');
        if (!$imei) {
            return json(['code' => 100, 'msg' => 'imei号缺失']);
        }
        $model = new MachineDevice();
        $data = $model->alias('d')
            ->join('system_admin a', 'd.uid=a.id')
            ->join('machine_position p', 'd.position_id=p.id')
            ->where('d.imei', $imei)
            ->field('d.device_sn,d.device_name,d.imei,d.num,d.screen_orientation,a.username,p.position')
            ->find();
        if ($data){
            $data['screen_orientation'] = $data['screen_orientation'] ?? 0;
        }
        return json(['code' => 200, 'data' => $data]);
    }

    //设备登录验证
    public function deviceLogin()
    {
        $imei = request()->get('imei', '');
        $code = request()->get('code', '');
        if (!$imei) {
            return json(['code' => 100, 'msg' => 'imei号缺失']);
        }
        $device_sn = (new MachineDevice())->where('imei', $imei)->value('device_sn');
        $str = $device_sn . '_loginCode';
        $res = Cache::store('redis')->get($str);
        if (!$res) {
            return json(['code' => 100, 'msg' => '请先获取登录码']);
        }
        if ($code != $res) {
            return json(['code' => 100, 'msg' => '登录码错误']);
        }
        return json(['code' => 200, 'msg' => '验证成功']);
    }


    //设备与中转断联,验证网络
    public function verifyNetwork()
    {
        $imei = request()->get('imei', '');
        $data = [
            'imei' => $imei,
            'create_time' => time()
        ];
        Db::name('device_verify_network')->insert($data);
        return json(['code' => 200, 'msg' => '成功']);
    }
}

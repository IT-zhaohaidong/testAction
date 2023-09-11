<?php

namespace app\box\controller;


use app\index\model\MachineDevice;
use app\index\model\MachineDeviceErrorModel;
use app\index\model\OrderGoods;
use app\index\model\SystemAdmin;
use think\Cache;
use think\Controller;
use think\Db;

//绑定设备版本
class Api extends Controller
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

    /**
     * 设备列表 [get]
     * keywords 关键字
     */
    public function deviceList()
    {
        $get = $this->request->get();
        $page = $get['page'] ?? 1;
        if (!empty($get['username'])) {
            $device = Db::name('machine_device')->alias('device')
                ->join('system_admin admin', 'admin.id=device.uid', 'left')
                ->where('admin.username', $get['username'])
                ->limit(20)->page($page)
                ->field('device.id,device.device_sn,device.device_name,admin.username')
                ->order('device.id desc')
                ->select();
        } else {
            $device = [];
        }
        $data = [
            'code' => 200,
            'data' => $device,
            'params' => $get
        ];
        return json($data);
    }

    public function bindDevice()
    {
        $post = $this->request->post();
        $device_sn = $post['device_sn'] ?? '';
        $imei = $post['imei'] ?? '';
        if (!$device_sn || !$imei) {
            return json(["code" => 100, "msg" => "参数错误"]);
        }
        $id = Db::name('machine_android')->where('imei', $imei)->value('id');
        if ($id) {
            Db::name('machine_android')->where('id', $id)->update(['device_sn' => $device_sn]);
        } else {
            Db::name('machine_android')->insert(['device_sn' => $device_sn, 'imei' => $imei]);
        }
        return json(["code" => 200, "msg" => "绑定成功"]);

    }

//    //获取广告
//    public function getAdver()
//    {
//        $imei = $this->request->get("imei", "");
//        if (!$imei) {
//            return json(["code" => 100, "msg" => "imei号不能为空"]);
//        }
//        $device = Db::name('machine_device')->where(['imei' => $imei])->find();
//        if (!$device) {
//            $device_sn = $this->getDeviceSn();
//            $data = [
//                'imei' => $imei,
//                'device_sn' => $device_sn,
//                'device_name' => $imei,
//                'device_qrcode_image' => qrcode($device_sn),
//                'official_qrcode_image' => "",
//                'uid' => 1
//            ];
//            Db::name('machine_device')->insert($data);
//            $device['device_sn'] = $device;
//        }
//
//        $video = Db::name('machine_video')
//            ->where(['device_sn' => $device['device_sn']])
//            ->column('video_id');
//        $videos = [];
//        if ($video) {
//            $videos = Db::name('operate_video')
//                ->where('id', 'in', $video)
//                ->where('start_time', '<=', time())
//                ->where('end_time', '>=', time() - 24 * 3600)->column('video_url');
//            $videos = array_values($videos);
//        }
//        $data = ['video' => $videos];
//        return json(["code" => 200, "data" => $data]);
//    }

    //获取广告
    public function getAdver()
    {
        $imei = $this->request->get("imei", "");
        if (!$imei) {
            return json(["code" => 100, "msg" => "imei号不能为空"]);
        }
        $device = Db::name('machine_android')->where(['imei' => $imei])->find();
        if (!$device) {
//            $device_sn = $this->getDeviceSn();
//            $data = [
//                'imei' => $imei,
//                'device_sn' => $device_sn,
//                'device_name' => $imei,
//                'device_qrcode_image' => qrcode($device_sn),
//                'official_qrcode_image' => "",
//                'uid' => 1
//            ];
//            Db::name('machine_device')->insert($data);
//            $device['device_sn'] = $device;
            return json(["code" => 400, "msg" => '未绑定设备']);
        }
        $id = (new MachineDevice())->where('device_sn', $device['device_sn'])->value('id');
        $video = Db::name('machine_video')
            ->where(['device_id' => $id])
            ->value('video_id');
        $videos = [];
        if ($video) {
            $videos = Db::name('adver_material')
                ->where('id', 'in', $video)
                ->where('start_time', '<=', time())
                ->where('end_time', '>=', time() - 24 * 3600)->column('url');
            $videos = array_values($videos);
        }
        $data = ['video' => $videos];
        return json(["code" => 200, "data" => $data]);
    }


    //商品列表
    public function goodsList()
    {
        $imei = $this->request->get("imei", "");
        if (!$imei) {
            return json(["code" => 100, "msg" => "imei号不能为空"]);
        }
        $device = Db::name('machine_android')->where(['imei' => $imei])->find();
        if (!$device) {
            return json(["code" => 400, "msg" => '未绑定设备']);
        }
        $device = Db::name('machine_android')->where(['imei' => $imei])->find();
        $device_sn = $device['device_sn'];
        $model = new  \app\index\model\MachineDevice();
        $image = $model->alias('d')
            ->join('machine_banner b', 'd.banner_id=b.id')
            ->where('d.device_sn', $device_sn)
            ->value('b.material_image');
        $images = $image ? explode(',', $image) : [];
        $goods = Db::name('machine_goods')
            ->alias('num')
            ->join('mall_goods goods', 'num.goods_id=goods.id', 'left')
            ->where(['num.device_sn' => $device['device_sn']])
            ->field('num.num,num.stock amount,num.price good_price,goods.id,goods.title,goods.image images,goods.description')
            ->select();
        return json(['code' => 200, 'data' => $goods, 'images' => $images]);
    }

    //生成订单
    public function createOrder()
    {

        $imei = $this->request->post("imei", "");
        $num = $this->request->post("num", "");
        if (!$imei) {
            return json(["code" => 100, "msg" => "imei号不能为空"]);
        }
        $device = Db::name('machine_android')->where(['imei' => $imei])->find();
        if (!$device) {
            return json(["code" => 100, "msg" => $imei]);
        }
        $device = (new MachineDevice())->where('device_sn', $device['device_sn'])->find();
        if ($device['is_lock'] == 0) {
            return json(['code' => 100, 'msg' => '该设备已被禁用']);
        }
        if ($device['supply_id'] != 3) {
            if ($device['status'] != 1) {
                return json(['code' => 100, 'msg' => '设备不在线,请联系客服处理!']);
            }
        }
        $good = Db::name('machine_goods')->where(['num' => $num, 'device_sn' => $device['device_sn']])->find();
        if (!$good || $good['stock'] < 1) {
            return json(["code" => 100, "msg" => "该商品已售空"]);
        }
        if ($good['price'] < 0.01) {
            return json(["code" => 100, "msg" => "付款金额不能少于0.01"]);
        }
        $goods = Db::name('machine_goods')
            ->alias('num')
            ->join('mall_goods goods', 'num.goods_id=goods.id', 'left')
            ->where(['num.device_sn' => $device['device_sn']])
            ->where(['num.num' => $num])
            ->field('num.num,num.stock,num.price,goods.id,goods.title,goods.image,goods.description,goods.cost_price,goods.other_cost_price')
            ->find();
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
            'create_time' => time(),
        ];
        $profit = 0;
        if ($goods['cost_price'] > 0) {
            $profit = round(($goods['price'] - $goods['cost_price'] ) * 100) / 100;
        }
        $data['profit'] = $profit;
        $data['cost_price'] = $goods['cost_price'] ?? 0;
//        $data['other_cost_price'] = $goods['other_cost_price'] ?? 0;
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
        //添加订单商品
        $goods_data = [
            'order_id' => $order_id,
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
            $result = $pay->prepay('', $order_sn, $total_fee, $user, $notify_url);
        }
        if ($result['return_code'] == 'SUCCESS') {
            $data = [
                'order_id' => $order_id,
                'url' => $result['code_url']
            ];
            return json(['code' => 200, 'data' => $data]);
        } else {
            return json(['code' => 100, 'msg' => $result['return_msg']]);
        }
    }

    public function getProgress()
    {
        $order_id = $this->request->get('order_id', '');
        if (!$order_id) {
            return json(['code' => 100, 'msg' => '订单号不能为空']);
        }

        $order = Db::name('finance_order')->where(['id' => $order_id])->find();
        $str = 'chuohuoresult_' . $order['order_sn'];
        $res = Cache::store('redis')->get($str);
        trace($res, '出货');
        $error = (new MachineDeviceErrorModel())->where('order_sn', $order['order_sn'])->find();
        if ($order) {
            //status  1:待付款 2:付款成功 3:出货成功 4:出货失败
            if ($order['status'] == 0) {
                $status = 1;
            } elseif ($res) {
                $status = 3;
            } elseif ($error) {
                $status = 4;
            } else {
                $status = 2;
            }
            $uid = Db::name('machine_device')->where('device_sn', $order['device_sn'])->value('uid');
            $phone = Db::name('system_admin')->where('id', $uid)->value('phone');
            $arr = [1 => '电机正常,未检测到出货', 2 => '无效货道', 3 => '设备未开机', 4 => '货道超时'];
            $data = [
                'status' => $status,
                'remark' => $error ? $arr[$error['status']] : '',
                'device_sn' => $order['device_sn'],
//                'route_number' => $order['route_number'],
                'phone' => $phone,
                'qrcode' => "https://tanhuang.feishikeji.cloud/static/common/images/qrcode_for_gh_2e7d350d9ff5_430.jpg"
            ];
            return json(['code' => 200, 'msg' => '', 'data' => $data]);
        } else {
            return json(['code' => 201, 'msg' => '订单不存在']);
        }
    }

//    //通知后台是否出货 status=>3:出货成功 4:出货失败
//    public function isChuhuo($order_id, $status, $remark = '')
//    {
//        if (!$order_id) {
//            return json(['code' => 100, 'msg' => '订单号不能为空']);
//        }
//        $row = Db::name('pay_progress')->where(['order_id' => $order_id])->find();
//        if ($row) {
//            //status  1:待付款 2:付款成功 3:出货成功 4:出货失败
//            $data = [
//                'status' => $status,
//                'remark' => $remark
//            ];
//            Db::name('pay_progress')->where(['order_id' => $order_id])->update($data);
//            $order = Db::name('finance_order')->where(['id' => $order_id])->find();
//            $where = [
//                'device_sn' => $order['device_sn'],
//                'num' => $order['route_number']
//            ];
//            $cukun = Db::name('machine_goods')->where($where)->value('amount');
//            $jiankucun = Db::name('machine_goods')->where($where)->update(['amount' => $cukun - 1]);
//
////            $signCode = 'orderId=' . $order_id . '192006250b4c09247ec02edce69f6a2d';
////            $data = [
////                'orderId' => $order_id,
////                'shippingResult' => $status == 3 ? 1 : 0,
////                'reason' => $remark,
////                'timestamp' => time(),
////                'nonce' => rand(1000, 9999),
//////                'signature' => md5($signCode),
////            ];
////            $sign = $this->asc_sort($data);
////            $signCode = $sign . "192006250b4c09247ec02edce69f6a2d";
////            $data['signature'] = md5($signCode);
////            https_request('https://wx.shenghuojia.com/mercury/whisper-pome/fangyuan/shippingResult', $data);
//            return json(['code' => 200, 'msg' => '操作成功']);
//        } else {
//            return json(['code' => 100, 'msg' => '无效订单号']);
//        }
//    }
}

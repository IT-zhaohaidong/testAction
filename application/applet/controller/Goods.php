<?php

namespace app\applet\controller;

use app\box\controller\ApiV2;
use app\index\common\Wxpay;
use app\index\common\Yuepai;
use app\index\model\CommissionPlanModel;
use app\index\model\DeviceConfigModel;
use app\index\model\FinanceCash;
use app\index\model\FinanceOrder;
use app\index\model\MachineCardModel;
use app\index\model\MachineCart;
use app\index\model\MachineDevice;
use app\index\model\MachineDeviceErrorModel;
use app\index\model\MachineGoods;
use app\index\model\MachineOutLogModel;
use app\index\model\MachineStockLogModel;
use app\index\model\MallGoodsModel;
use app\index\model\MchidModel;
use app\index\model\OperateUserModel;
use app\index\model\OrderGoods;
use app\index\model\SystemAdmin;
use think\Cache;
use think\Controller;
use think\Db;

class Goods extends Controller
{
    public function getBanner()
    {
        $device_sn = request()->get('device_sn', '');
        $model = new  \app\index\model\MachineDevice();
        $image = $model->alias('d')
            ->join('machine_banner b', 'd.banner_id=b.id')
            ->where('d.device_sn', $device_sn)
            ->value('b.material_image');
        $images = $image ? explode(',', $image) : [];
        $device = $model->alias('d')
            ->join('operate_about b', 'd.uid=b.uid', 'left')
            ->where('d.device_sn', $device_sn)
            ->field('d.*,b.phone')->find();
        return json(['code' => 200, 'data' => $images, 'device' => $device]);
    }

    public function getDevice()
    {
        $device_sn = request()->get('device_sn', '');
        $model = new  \app\index\model\MachineDevice();
        $device = $model->alias('d')
            ->where('d.device_sn', $device_sn)
            ->field('d.imei,d.device_sn,d.id,d.supply_id')->find();
        return json(['code' => 200, 'data' => $device]);
    }

    //获取商品列表
    public function getList()
    {
        $params = request()->get();
        $device_sn = request()->get('device_sn', '');
        if (empty($device_sn)) {
            return json(['code' => 100, 'msg' => '缺少参数']);
        }
        $device = (new \app\index\model\MachineDevice())->where('device_sn', $device_sn)->find();
        if ($device['uid'] == 72 && isset($params['is_wx'])) {
            //校园派样机 拦截微信端口  留存企业微信&支付宝
            if ($params['is_wx'] == 2) {
                return json(['code' => 100, 'msg' => '请用支付宝扫描此二维码']);
            }
        }
        $where = [];
        if (!empty($params['port'])) {
            $port = [0, $params['port']];
            $where['g.port'] = ['in', $port];
        }
        $config = (new DeviceConfigModel())->getDeviceConfig($device['id']);
        if ($config['merge_goods'] != 1) {
            //一商品一货道
            $data = (new \app\index\model\MachineGoods())->alias("g")
                ->join("mall_goods s", "g.goods_id=s.id", "LEFT")
                ->field("g.id,g.num,g.goods_id,g.stock,g.price,g.active_price,s.image,s.goods_images,s.detail,s.description,s.title,s.goods_code")
                ->where("g.device_sn", $device_sn)
                ->where($where)
                ->where('num', '<=', $device['num'])
                ->where('g.goods_id', '>', 0)
                ->order('g.num asc')
                ->select();
            $arr = [];
            foreach ($data as $k => $v) {
                if (!$v['goods_id']) {
                    unset($data[$k]);
                    continue;
                }
                if ($v['num'] > $device['num']) {
                    unset($data[$k]);
                    continue;
                }
                $v['active_price'] = $v['active_price'] ? $v['active_price'] : 0;
                $arr[$v['num']] = $v;
            }
            return json(['code' => 200, 'data' => $arr, 'config' => $config]);
        } else {
            //合并商品
            $data = (new \app\index\model\MachineGoods())->alias("g")
                ->join("mall_goods s", "g.goods_id=s.id", "LEFT")
                ->field("g.id,g.goods_id,sum(g.stock) stock,g.price,g.active_price,s.image,s.goods_images,s.detail,s.description,s.title,s.goods_code")
                ->where("g.device_sn", $device_sn)
                ->where($where)
                ->where('num', '<=', $device['num'])
                ->where('g.goods_id', '>', 0)
                ->order('g.num asc')
                ->group('g.goods_id')
                ->select();
            foreach ($data as $k => $v) {
                if (!$v['goods_id']) {
                    unset($data[$k]);
                    continue;
                }
                $data[$k]['active_price'] = $v['active_price'] ? $v['active_price'] : 0;
            }
            return json(['code' => 200, 'data' => $data, 'config' => $config]);
        }
    }

    /**
     * 微信小程序端支付接口  一商品一货道
     */
    public function createOrder()
    {
        $post = $this->request->post();
        trace($post, '预支付参数');
//        $post['order_time'] = time();
        $post['status'] = 0;
        $order_sn = time() . mt_rand(1000, 9999);
        $post['order_sn'] = $order_sn;
        $device = (new MachineDevice())->where("device_sn", $post['device_sn'])->field("imei,supply_id,uid,status,expire_time,is_lock")->find();
        (new OperateUserModel())->where('openid', $post['openid'])->update(['uid' => $device['uid']]);
//        $post['device_sn'] = "ILJXJI";
////        $post['order_sn']       = "213123123132";
//        $post['money'] = '0.01';
//        $post['route_number'] = 9999;
//        $post['openid'] = 'oF1bN5WreAhaNJ5YDklopY0vDA3s';
        if (in_array($device['uid'], [72, 90, 104])) {
            $row = (new FinanceOrder())
                ->where(['openid' => $post['openid'], 'device_sn' => $post['device_sn'], 'status' => 1])
                ->find();
            if ($row) {
                return json(['code' => 100, 'msg' => '您的资格已用尽']);
            }
        }

        if ($device['supply_id'] == 3) {
            $bool = $this->device_status($post['device_sn']);
            if (!$bool) {
                return json(["code" => 100, "msg" => "设备不在线"]);
            }
        } else {
            if ($device['status'] != 1 && $device['supply_id'] != 4) {
                return json(['code' => 100, 'msg' => '设备不在线,请联系客服处理!']);
            }
        }
        if ($device['expire_time'] < time()) {
            return json(['code' => 100, 'msg' => '设备已过期,请联系客服处理!']);
        }
        if ($device['is_lock'] < 1) {
            return json(['code' => 100, 'msg' => '设备已禁用']);
        }

        $mallGoodsModel = new MallGoodsModel();
        if (isset($post['num']) && $post['num'] > 0) {
            //一货道一商品
            $goods = (new MachineGoods())
                ->where(['device_sn' => $post['device_sn'], 'num' => $post['num']])
                ->find();
            if ($goods['is_lock'] == 1) {
                return json(['code' => 100, 'msg' => '该货道已被锁定,不可购买']);
            }
            if ($goods['stock'] < 1) {
                return json(['code' => 100, 'msg' => '库存不足']);
            }
            $mall_goods = $mallGoodsModel->where('id', $goods['goods_id'])->find();
        } else {
            //合并商品
            if (!isset($post['goods_id']) || $post['goods_id'] < 1) {
                return json(['code' => 100, 'msg' => '缺少参数']);
            }
            $amount = (new MachineGoods())
                ->where(['device_sn' => $post['device_sn'], 'goods_id' => $post['goods_id']])
                ->where('is_lock', 0)
                ->group('goods_id')
                ->field('sum(stock) total_stock')
                ->find();
            if ($amount['total_stock'] < 1) {
                return json(['code' => 100, 'msg' => '库存不足']);
            }
            $goods = (new MachineGoods())
                ->where(['device_sn' => $post['device_sn'], 'goods_id' => $post['goods_id']])
                ->where('is_lock', 0)
                ->where('stock', '>', 0)
                ->find();
            $post['num'] = $goods['num'];
            $mall_goods = $mallGoodsModel->where('id', $post['goods_id'])->find();
            unset($post['goods_id']);
        }
        $post['price'] = $goods['active_price'] > 0 ? $goods['active_price'] : $goods['price'];
        if ($post['price'] <= 0) {
            return json(['code' => 100, 'msg' => '订单金额不能小于0']);
        }
        $profit = 0;
        if ($mall_goods['cost_price'] > 0) {
            $profit = round(($post['price'] - $mall_goods['cost_price']) * 100) / 100;
            $post['cost_price'] = $mall_goods['cost_price'] > 0 ? $mall_goods['cost_price'] : 0;
//            $post['other_cost_price'] = $mall_goods['other_cost_price'] > 0 ? $mall_goods['other_cost_price'] : 0;
        }
        $post['profit'] = $profit;
        //判断是否有用户在购买
        $str = 'buying' . $post['device_sn'];
        $res = Cache::store('redis')->get($str);
        trace($res, '用户正在购买');
        if ($res == 1) {
            return json(['code' => 100, 'msg' => '有其他用户正在购买,请稍后重试']);
        } else {
            Cache::store('redis')->set($str, 1, 120);
        }
        $uid = (new MachineDevice())
            ->where("device_sn", $post['device_sn'])
            ->value("uid");
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

        $user = Db::name('system_admin')->where("id", $uid)->find();
        $post['uid'] = $uid;
        $post['count'] = 1;
        $post['create_time'] = time();
        if ($is_set_mchid) {
            $mchid = (new MchidModel())->where('id', $wx_mchid_id)->field('mchid,key')->find();
            $pay = new \app\applet\controller\Wxpay();
            $notify_url = 'https://api.feishi.vip/applet/goods/user_notify';
            $user['mchid'] = $mchid;
            $user['is_wx_mchid'] = 1;
            $user['wx_mchid_id'] = true;
            $prepay_id = $pay->prepay($post['openid'], $order_sn, $post['price'], $user, $notify_url);
            trace($prepay_id, '预支付');
            $order_obj = new FinanceOrder();

            $num = $post['num'];
            unset($post['num']);
            $post['pay_type'] = 3;
            $order_id = $order_obj->insertGetId($post);
            //添加订单商品
            $goods_data = [
                'order_id' => $order_id,
                'order_sn' => $order_sn . 'order1',
                'device_sn' => $goods['device_sn'],
                'num' => $num,
                'goods_id' => $goods['goods_id'],
                'price' => $goods['price'],
                'count' => 1,
                'total_price' => $goods['price'],
            ];
            (new OrderGoods())->save($goods_data);
            //小程序调用微信支付配置
            $data['appId'] = 'wx6fd3c40b45928f43';
            $data['timeStamp'] = strval(time());
            $data['nonceStr'] = $pay->getNonceStr();
            $data['signType'] = "MD5";
            $data['package'] = "prepay_id=" . $prepay_id['prepay_id'];
            $data['paySign'] = $pay->makeSign($data, $mchid['key']);
            $data['order_sn'] = $order_sn;
            echo json_encode($data, 256);
        } else {
            $pay = new \app\applet\controller\Wxpay();
            $notify_url = 'https://api.feishi.vip/applet/goods/system_notify';
            $prepay_id = $pay->prepay($post['openid'], $order_sn, $post['price'], $user, $notify_url);
            $order_obj = new FinanceOrder();
            $num = $post['num'];
            unset($post['num']);
            $post['pay_type'] = 1;
            $order_id = $order_obj->insertGetId($post);
            //添加订单商品
            $goods_data = [
                'order_id' => $order_id,
                'order_sn' => $order_sn . 'order1',
                'device_sn' => $goods['device_sn'],
                'num' => $num,
                'goods_id' => $goods['goods_id'],
                'price' => $goods['price'],
                'count' => 1,
                'total_price' => $goods['price']
            ];
            (new OrderGoods())->save($goods_data);
            //小程序调用微信支付配置
            $data['appId'] = "wx6fd3c40b45928f43";
            $data['timeStamp'] = strval(time());
            $data['nonceStr'] = $pay->getNonceStr();
            $data['signType'] = "MD5";
            $data['package'] = "prepay_id=" . $prepay_id['prepay_id'];
            $data['paySign'] = $pay->makeSign($data, 'wgduhzmxasi8ogjetftyio111imljs2j');
            $data['order_sn'] = $order_sn;
            echo json_encode($data, 256);
        }
    }

    //购物车购买
    public function createOrderByCart()
    {
        $params = request()->get();
        if (!$params['device_sn'] || !$params['openid']) {
            return json(['code' => 100, 'msg' => '缺少参数']);
        }
        $cartModel = new MachineCart();
        $cart = $cartModel
            ->where(['device_sn' => $params['device_sn'], 'openid' => $params['openid']])
            ->select();
        if (!$cart) {
            return json(['code' => 100, 'msg' => '请选购商品']);
        }

        $device = (new MachineDevice())
            ->where('device_sn', $params['device_sn'])
            ->field('id,num,uid,status,expire_time,is_lock,supply_id')
            ->find();
        (new OperateUserModel())->where('openid', $params['openid'])->update(['uid' => $device['uid']]);
        if ($device['supply_id'] == 3) {
            $bool = $this->device_status($params['device_sn']);
            if (!$bool) {
                return json(["code" => 100, "msg" => "设备不在线"]);
            }
        } else {
            if ($device['status'] != 1) {
                return json(['code' => 100, 'msg' => '设备不在线,请联系客服处理!']);
            }
        }
        if ($device['expire_time'] < time()) {
            return json(['code' => 100, 'msg' => '设备已过期,请联系客服处理!']);
        }
        if ($device['is_lock'] < 0) {
            return json(['code' => 100, 'msg' => '设备已禁用']);
        }
        $port = [0, 1];
        $where['g.port'] = ['in', $port];
        $data = (new \app\index\model\MachineGoods())->alias("g")
            ->join("mall_goods s", "g.goods_id=s.id", "LEFT")
            ->where("g.device_sn", $params['device_sn'])
            ->where($where)
            ->where('num', '<=', $device['num'])
            ->where('g.goods_id', '>', 0)
            ->where('g.is_stock', 0)
            ->order('g.num asc')
            ->group('g.goods_id')
            ->column('g.goods_id,sum(g.stock) stock,g.price,g.active_price,s.title', 'g.goods_id');
        $code = 0;//0:无异常  1:商品已从货道清除或更改  2:库存不足
        $bad_goods_id = 0;
        $total_price = 0;
        $total_count = 0;
        $orderGoods = [];
        $goods_ids = [];
        foreach ($cart as $k => $v) {
            if (!isset($data[$v['goods_id']])) {
                $code = 1;
                $bad_goods_id = $v['goods_id'];
                break;
            }
            if ($data[$v['goods_id']]['stock'] < $v['count']) {
                $code = 2;
                $bad_goods_id = $v['goods_id'];
                break;
            }
            $goods_ids[] = $v['goods_id'];
            $price = $data[$v['goods_id']]['active_price'] > 0 ? $data[$v['goods_id']]['active_price'] : $data[$v['goods_id']]['price'];
            $total_price = ($total_price * 100 + $price * 100 * $v['count']) / 100;
            $total_count += $v['count'];
            $orderSingleGoods = (new ApiV2())->getOrderGoods($params['device_sn'], $v['goods_id'], $price, $v['count'], [], 1);
            $orderGoods = array_merge($orderGoods, $orderSingleGoods);
        }
        if ($code > 0) {
            $res = $code == 1 ? '已下架' : '库存不足';
            $title = (new MallGoodsModel())->where('id', $bad_goods_id)->value('title');
            $msg = '商品' . "\"" . $title . "\"" . $res;
            return json(['code' => 100, 'msg' => $msg]);
        }
        if ($total_price <= 0) {
            return json(['code' => 100, 'msg' => '付款金额必须大于0']);
        }
        $str = 'buying' . $params['device_sn'];
        $res = Cache::store('redis')->get($str);
        if ($res == 1) {
            return json(['code' => 100, 'msg' => '有其他用户正在购买,请稍后重试']);
        } else {
            Cache::store('redis')->set($str, 1, 120);
        }
        $order_sn = time() . mt_rand(1000, 9999);
        $pay = $pay = new \app\applet\controller\Wxpay();
        $user = Db::name('system_admin')->where("id", $device['uid'])->find();
        $data = [
            'order_sn' => $order_sn,
            'device_sn' => $params['device_sn'],
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
        $orderGoodsList = [];
        $order_index = 0;
        $total_profit = 0;//总利润
        $total_cost_price = 0;//总成本价
        $total_other_cost_price = 0;//总其他成本价
        foreach ($orderGoods as $k => $v) {
            for ($i = 1; $i <= $v['count']; $i++) {
                $order_index++;
                $orderGoodsList[] = [
                    'device_sn' => $v['device_sn'],
                    'num' => $v['num'],
                    'goods_id' => $v['goods_id'],
                    'price' => $v['price'],
                    'count' => 1,
                    'total_price' => $v['price'],
                    'order_sn' => $order_sn . 'order' . $order_index,
                    'order_id' => $order_id,
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
        (new OrderGoods())->saveAll($orderGoodsList);
        if ($is_set_mchid && $wx_mchid_id) {
            //代理商
            $notify_url = 'https://api.feishi.vip/box/Wxpay/agentNotify';
            $result = $pay->prepay($params['openid'], $order_sn, $total_price, $user, $notify_url);
        } else {
            //系统
            $notify_url = 'https://api.feishi.vip/box/Wxpay/payNotify';
//            var_dump($total_fee);die();
            $result = $pay->prepay($params['openid'], $order_sn, $total_price, $user, $notify_url);
        }
        if ($result['return_code'] == 'SUCCESS') {
            $data = [];
            //小程序调用微信支付配置
            $data['appId'] = "wx6fd3c40b45928f43";
            $data['timeStamp'] = strval(time());
            $data['nonceStr'] = $pay->getNonceStr();
            $data['signType'] = "MD5";
            $data['package'] = "prepay_id=" . $result['prepay_id'];
            $data['paySign'] = $pay->makeSign($data, 'wgduhzmxasi8ogjetftyio111imljs2j');
            $data['order_sn'] = $order_sn;
            return json(['code' => 200, 'data' => $data]);
        } else {
            return json(['code' => 100, 'msg' => $result['return_msg']]);
        }

    }

    //取消支付
    public function cancelPay()
    {
        $device_sn = request()->get('device_sn', '');
        if (!$device_sn) {
            return json(['code' => 100, 'msg' => '缺少参数']);
        }
        $str = 'buying' . $device_sn;
        $res = Cache::store('redis')->get($str);
        if ($res == 1) {
            Cache::store('redis')->rm($str);
        }
        return json(['code' => 200, 'msg' => '取消成功']);
    }

    //获取付款进度(蓝牙格子柜)
    public function getOrderStatus()
    {
        $order_sn = request()->post('order_sn', '');
        if (empty($order_sn)) {
            return json(['code' => 100, 'msg' => '缺少参数']);
        }
        $model = new FinanceOrder();
        $order = $model->where('order_sn', $order_sn)->find();
        if ($order['status'] > 0) {
            return json(['code' => 200, 'msg' => '付款成功']);
        } else {
//            $result = (new Wxpay())->orderInfo($order['order_sn']);
//            trace($result, '商户号申请支付结果');
//            if (!empty($result['trade_state']) && $result['trade_state'] == 'SUCCESS') {
//                $model->where('id', $order['id'])->update(['status' => 4, 'transaction_id' => $result['transaction_id']]);
//                return json(['code' => 200, 'msg' => '付款成功']);
//            }
            return json(['code' => 100, 'msg' => '暂未付款']);
        }
    }

    //通知出货结果(蓝牙格子柜) status(0:出货失败  1:出货成功)
    public function outResult()
    {
        $order_sn = request()->post('order_sn', '');
        $status = request()->post('status', 0);
        $remark = request()->post('remark', '');
        if (empty($order_sn)) {
            return json(['code' => 100, 'msg' => '缺少参数']);
        }
        $orderModel = new FinanceOrder();
        $order = $orderModel->where('order_sn', $order_sn)->find();
        $orderGoods = (new OrderGoods())->where('order_id', $order['id'])->find();
        if ($status == 0) {
            //出货失败
            $orderModel->where('id', $order['id'])->update(['status' => 3]);
            //添加出货记录
            $outLog = [
                'device_sn' => $orderGoods['device_sn'],
                'num' => $orderGoods['num'],
                'order_sn' => $orderGoods['order_sn'],
                'status' => 3,
                'remark' => $remark
            ];
        } else {
            //出货成功
            $orderModel->where('id', $order['id'])->update(['status' => 1]);

            $old_stock = (new MachineGoods())
                ->where(['device_sn' => $orderGoods['device_sn'], 'num' => $orderGoods['num']])->value('stock');
            //扣除库存
            (new MachineGoods())
                ->where(['device_sn' => $orderGoods['device_sn'], 'num' => $orderGoods['num']])
                ->update(['stock' => 0]);
            //添加出货记录
            $outLog = [
                'device_sn' => $orderGoods['device_sn'],
                'num' => $orderGoods['num'],
                'order_sn' => $orderGoods['order_sn'],
                'status' => 0,
            ];
            //添加库存记录
            $data = [
                'device_sn' => $orderGoods['device_sn'],
                'num' => $orderGoods['num'],
                'goods_id' => $orderGoods['goods_id'],
                'old_stock' => $old_stock,
                'new_stock' => 0,
                'change_detail' => '用户下单,库存减少;订单号:' . $order_sn,

            ];
            (new MachineStockLogModel())->save($data);
        }
        (new MachineOutLogModel())->save($outLog);
        return json(['code' => 200, 'msg' => '成功']);
    }

    /**
     * 安卓端微信支付
     * @param 'order_sn'
     * @param 'device_sn'
     * @param 'openid'
     */
    public function getPay()
    {
        $post = $this->request->post();
        if (empty($post['device_sn']) || empty($post['order_sn'])) {
            return json(['code' => 100, 'msg' => '缺少参数']);
        }
        trace($post, '预支付参数');
//        $post['order_time'] = time();
        $order = (new FinanceOrder())->where('order_sn', $post['order_sn'])->field('uid,price')->find();
        $uid = $order['uid'];
        $user = Db::name('system_admin')->where("id", $uid)->find();
        //当设备所属人未开通商户号时,判断父亲代理商是否开通,若未开通,用系统支付,若开通,用父亲代理商商户号进行支付
        $parentId = [];
        $parentUser = (new SystemAdmin())->getParents($uid, 1);
        foreach ($parentUser as $k => $v) {
            $parentId[] = $v['id'];
        }
        $userList = (new SystemAdmin())->whereIn('id', $parentId)->select();
        $is_set_mchid = false;
        $mchid_uid = 0;
        $wx_mchid_id = 0;
        foreach ($userList as $k => $v) {
            if ($v['is_wx_mchid'] == 1 && $v['wx_mchid_id']) {
                $is_set_mchid = true;
                $mchid_uid = $v['id'];
                $wx_mchid_id = $v['wx_mchid_id'];
                break;
            }
        }
        $pay = new Wxpay();
        if ($is_set_mchid) {
            $pay_type = 3;
            $notify_url = 'https://api.feishi.vip/applet/goods/user_notify;';
            $mchid = (new MchidModel())->where('id', $wx_mchid_id)->field('mchid,key')->find();
        } else {
            $pay_type = 1;
            $notify_url = 'https://api.feishi.vip/applet/goods/system_notify';
            $mchid['key'] = 'wgduhzmxasi8ogjetftyio111imljs2j';
        }
        (new FinanceOrder())->where('order_sn', $post['order_sn'])->update(['pay_type' => $pay_type]);
        $prepay_id = $pay->prepay($post['openid'], $post['order_sn'], $order['price'], $user, $notify_url);
        //小程序调用微信支付配置
        $data['appId'] = 'wx6fd3c40b45928f43';
        $data['timeStamp'] = strval(time());
        $data['nonceStr'] = $pay->getNonceStr();
        $data['signType'] = "MD5";
        $data['package'] = "prepay_id=" . $prepay_id['prepay_id'];
        $data['paySign'] = $pay->makeSign($data, $mchid['key']);
        $data['order_sn'] = $post['order_sn'];
        echo json_encode($data, 256);
    }

    //系统支付回调
    public function system_notify()
    {
        $xml = request()->getContent();
        trace($xml, '微信支付毁掉');
        //将服务器返回的XML数据转化为数组
        $data = (new Wxpay())->xml2array($xml);
        // 保存微信服务器返回的签名sign
        $data_sign = $data['sign'];
        // sign不参与签名算法
        unset($data['sign']);
        $result = $data;
        //获取服务器返回的数据
        $out_trade_no = $data['out_trade_no'];        //订单单号
        $openid = $data['openid'];                    //付款人openID
        $total_fee = $data['total_fee'];            //付款金额
        $transaction_id = $data['transaction_id'];    //微信支付流水号

        // 返回状态给微信服务器
        if ($result) {
            $str = '<xml><return_code><![CDATA[SUCCESS]]></return_code><return_msg><![CDATA[OK]]></return_msg></xml>';
        } else {
            $str = '<xml><return_code><![CDATA[FAIL]]></return_code><return_msg><![CDATA[签名失败]]></return_msg></xml>';
        }
        echo $str;
        //TODO 此时可以根据自己的业务逻辑 进行数据库更新操作
        $order_str = 'wx_return' . $out_trade_no;
        $res = Cache::store('redis')->get($order_str);
        //判断是否接收到回调,避免重复执行
        if (!$res) {
            Cache::store('redis')->set($order_str, 1, 100);
            $this->orderDeal($result, 1);
        }
        return $result;
    }

    //代理商支付回调
    public function user_notify()
    {
        $xml = request()->getContent();
        //将服务器返回的XML数据转化为数组
        $data = (new Wxpay())->xml2array($xml);
        // 保存微信服务器返回的签名sign
        $data_sign = $data['sign'];
        // sign不参与签名算法
        unset($data['sign']);
//        $sign = (new Wxpay())->makeSign($data);

        // 判断签名是否正确  判断支付状态
//        if (($sign === $data_sign) && ($data['return_code'] == 'SUCCESS')) {
        $result = $data;
        //获取服务器返回的数据
        $out_trade_no = $data['out_trade_no'];        //订单单号
        $openid = $data['openid'];                    //付款人openID
        $total_fee = $data['total_fee'];            //付款金额
        $transaction_id = $data['transaction_id'];    //微信支付流水号
        //TODO 此时可以根据自己的业务逻辑 进行数据库更新操作

//        } else {
//            $result = false;
//        }
        // 返回状态给微信服务器
        if ($result) {
            $str = '<xml><return_code><![CDATA[SUCCESS]]></return_code><return_msg><![CDATA[OK]]></return_msg></xml>';
        } else {
            $str = '<xml><return_code><![CDATA[FAIL]]></return_code><return_msg><![CDATA[签名失败]]></return_msg></xml>';
        }
        echo $str;
        $order_str = 'wx_return' . $out_trade_no;
        $res = Cache::store('redis')->get($order_str);
        //判断是否接收到回调,避免重复执行
        if (!$res) {
            Cache::store('redis')->set($order_str, 1, 100);
            $this->orderDeal($result, 3);
        }
        return $result;
    }

    public function orderDeal($data, $type, $status = 1)
    {
        $orderModel = new FinanceOrder();
        $update_data = [
            'pay_time' => time(),
            'pay_type' => $type,
            'transaction_id' => $data['transaction_id'],
            'status' => $status
        ];
        $orderModel->where('order_sn', $data['out_trade_no'])->update($update_data);
        $order = $orderModel->where('order_sn', $data['out_trade_no'])->field('id,uid,device_sn,price,count,pay_type,order_type,openid,create_time,order_sn')->find();
        if ($order['order_type'] == 1) {
            //todo 雀客
            $goods_code = (new OrderGoods())->alias('og')
                ->join('mall_goods g', 'og.goods_id=g.id', 'left')->value('g.goods_code');
            $point = (new MachineDevice())->alias('d')
                ->join('machine_position p', 'd.position_id=p.id', 'left')
                ->value('p.position');
            $order_data = [
                'payTime' => date('Y-m-d H:i:s'),
                'orderTime' => $order['create_time'],
                'outOrderId' => $order['id'],
                'goodsCode' => $goods_code,
                'orderAmount' => $order['price'],
                'userId' => $order['openid'],
                'point' => $point ?? '无点位',
                'order_sn' => $order['order_sn']
            ];
            (new Yuepai())->callBack($order_data);
        }
        $device = (new MachineDevice())->where('device_sn', $order['device_sn'])->field('id,supply_id')->find();
        $device_id = $device['id'];
//        $ratio = Db::name('machine_commission')
//            ->where(['device_id' => $device_id])
//            ->select();
        if ($order['price'] > 0) {
            $adminModel = new SystemAdmin();
            $uid = [];
            $userCommission = (new CommissionPlanModel())->getMoney($order['price'], $device_id);
            foreach ($userCommission as $k => $v) {
                $uid[] = $v['uid'];
            }
            switch ($type) {
                case 1:
                    //系统微信
                    $admin_balance = $adminModel->whereIn('id', $uid)->column('system_balance', 'id');
                    break;
                case 2:
                    //系统支付宝
                    $admin_balance = $adminModel->whereIn('id', $uid)->column('system_balance', 'id');
                    break;
                case 3:
                    //用户微信
                    $admin_balance = $adminModel->whereIn('id', $uid)->column('agent_wx_balance', 'id');
                    break;
                case 4:
                    //用户支付宝
                    $admin_balance = $adminModel->whereIn('id', $uid)->column('agent_ali_balance', 'id');
                    break;
            }
            $total_price = 0;
            $cash_data = [];
            $userMoneyList = array_column($userCommission, 'money', 'uid');
            foreach ($userCommission as $k => $v) {
//            $price = floor(($order['price'] * $v['ratio'] / 100) * 100) / 100;
                //补货员系统余额更新
                if ($userMoneyList[$v['uid']] > 0) {
                    $balance = $userMoneyList[$v['uid']] + $admin_balance[$v['uid']];
                    $update = [];
                    switch ($type) {
                        case 1:
                            //系统微信
                            $update['system_balance'] = $balance;
                            break;
                        case 2:
                            //系统支付宝
                            $update['system_balance'] = $balance;
                            break;
                        case 3:
                            //用户微信
                            $update['agent_wx_balance'] = $balance;
                            break;
                        case 4:
                            //用户支付宝
                            $update['agent_ali_balance'] = $balance;
                            break;
                    }
                    trace($update, '错误更新数据');
                    $adminModel->where('id', $v['uid'])->update($update);
                }

                //统计用户订单收益数据
                $cash_data[] = [
                    'uid' => $v['uid'],
                    'order_sn' => $data['out_trade_no'],
                    'price' => $userMoneyList[$v['uid']],
                    'type' => 1,
                ];
//            //下级人员总收益
//            $total_price += $price;
            }
//        if ($type == 1 || $type == 2) {
//            //代理商余额更新
//            $balance = $order['price'] - $total_price + $admin_balance[$order['uid']];
//            $adminModel->where('id', $order['uid'])->update(['system_balance' => $balance]);
//        }
//        $cash_data[] = [
//            'uid' => $order['uid'],
//            'order_sn' => $data['out_trade_no'],
//            'price' => $order['price'] - $total_price,
//            'type' => 1
//        ];
            (new FinanceCash())->saveAll($cash_data);
        }
        //出货
        $order_goods = (new OrderGoods())->where('order_id', $order['id'])->select();
        if ($device['supply_id'] == 2 || $device['supply_id'] == 4 || $status == 4) {
            $orderModel->where('order_sn', $data['out_trade_no'])->update(['status' => 4]);
        } else {
            $index = 1;
            $machineGoodsModel = new MachineGoods();
            foreach ($order_goods as $k => $v) {
                $goods = (new MachineGoods())
                    ->where(['device_sn' => $v['device_sn'], 'num' => $v['num']])
                    ->field('stock,goods_id')->find();
                if ($device['supply_id'] == 1 || $device['supply_id'] == 5 || $device['supply_id'] == 6) {
                    //中转板子出货
//                    for ($i = 0; $i < $v['count']; $i++) {
//                        $order_no = $data['out_trade_no'] . 'order' . $index;
                    $res = $this->goodsOut($v['device_sn'], $v['num'], $v['order_sn'], $v['goods_id']);
                    if ($res['code'] == 1) {
                        (new FinanceOrder())->where('order_sn', $data['out_trade_no'])->update(['status' => 3]);
                        //锁货道
                        (new MachineGoods())
                            ->where(["device_sn" => $v['device_sn'], "num" => $v['num']])
                            ->update(['is_lock' => 1]);
                    } else {
                        if ($order['pay_type'] == 6) {
                            (new OperateUserModel())->where('openid', $order['openid'])->update(['is_free_by_company' => 1]);
                        }

                    }
                    $this->addStockLog($v['device_sn'], $v['num'], $v['order_sn'], 1, $goods);
                    $index++;
//                    }
                } elseif ($device['supply_id'] == 3) {
                    $out_log = [];
                    //蜜连出货
//                    for ($i = 0; $i < $v['count']; $i++) {
//                        $order_no = $data['out_trade_no'] . 'order' . $index;
                    $res = $this->shipment($v['device_sn'], $v['num'], $v['order_sn']);
                    if ($res['errorCode'] != 0) {
                        $status = $res['errorCode'] == 65020 ? 2 : 3;
                        $out_log[] = ["device_sn" => $v['device_sn'], "num" => $v['num'], "order_sn" => $v['order_sn'], 'status' => $status];
                        (new FinanceOrder())->where('order_sn', $data['out_trade_no'])->update(['status' => 3]);
                        //锁货道
                        (new MachineGoods())
                            ->where(["device_sn" => $v['device_sn'], "num" => $v['num']])
                            ->update(['is_lock' => 1]);
                    } else {
                        $row = $machineGoodsModel->where(["device_sn" => $v['device_sn'], "num" => $v['num']])->field('id,stock')->find();
                        $machineGoodsModel->where('id', $row['id'])->update(['stock' => $row['stock'] - 1]);
                        if ($order['pay_type'] == 6) {
                            (new OperateUserModel())->where('openid', $order['openid'])->update(['is_free_by_company' => 1]);
                        }
                        $out_log[] = ["device_sn" => $v['device_sn'], "num" => $v['num'], "order_sn" => $v['order_sn'], 'status' => 0];
                        $this->addStockLog($v['device_sn'], $v['num'], $v['order_sn'], 1, $goods);
                    }
//                        $index++;
//                    }
                    (new MachineOutLogModel())->saveAll($out_log);
                } else {

                }
//                if (isset($res) && $res['code'] == 1) {
//                    continue;
//                }
            }
        }


        if (!empty($data['openid'])) {
            $user = (new OperateUserModel())->where('openid', $data['openid'])->field('id,buy_num')->find();
            if ($user) {
                (new OperateUserModel())->where('id', $user['id'])->update(['buy_num' => $user['buy_num'] + 1]);
            } else {
                (new OperateUserModel())->save(['openid' => $data['openid'], 'buy_num' => 1]);
            }
        }
        //购买结束
        $str = 'buying' . $order['device_sn'];
        Cache::store('redis')->rm($str);
    }

    public function out($device, $order_sn)
    {
        $data['out_trade_no'] = $order_sn;
        $str = 'out_' . $order_sn;
        $order = (new FinanceOrder())->where('order_sn', $order_sn)->field('id,pay_type,idcard,device_sn')->find();
        if ($device['supply_id'] == 1 || $device['supply_id'] == 3) {
//            $order_no = $data['out_trade_no'] . 'order' . 0;
//            $outingStr = 'outing_' . $device['device_sn'];
//            Cache::store('redis')->set($outingStr, 1, 30);
//            $result = $this->goodsOut($device['device_sn'], $num, $order_no);
//            trace($result, '最终出货结果');
//            if ($result['code'] == 1) {
//                Cache::store('redis')->set($str, 2);
//                (new FinanceOrder())->where('order_sn', $data['out_trade_no'])->update(['status' => 3]);
//            } else {
//                $this->addStockLog($device['device_sn'], $num, $data['out_trade_no'], 1, $goods);
//            }
            $order_id = $order['id'];
            $order_goods = (new OrderGoods())->where('order_id', $order_id)->select();
            $index = 1;
            foreach ($order_goods as $k => $v) {
                $goods = (new MachineGoods())
                    ->where(['device_sn' => $v['device_sn'], 'num' => $v['num']])
                    ->field('stock,goods_id')->find();
                if ($device['supply_id'] == 1) {
                    //中转板子出货
                    for ($i = 0; $i < $v['count']; $i++) {
                        $order_no = $data['out_trade_no'] . 'order' . $index;
                        $res = $this->goodsOut($v['device_sn'], $v['num'], $order_no, $v['goods_id']);

                        if ($res['code'] == 1) {
                            (new FinanceOrder())->where('order_sn', $data['out_trade_no'])->update(['status' => 3]);
                            //锁货道
                            (new MachineGoods())
                                ->where(["device_sn" => $v['device_sn'], "num" => $v['num']])
                                ->update(['is_lock' => 1]);
                        } else {
                            if ($order['pay_type'] == 5) {
                                (new MachineCardModel())->where('idcard', $order['idcard'])->dec('num', 1)->update();
                            }
                        }
                        if ($order['pay_type'] == 5) {
                            $num = (new MachineCardModel())->where('idcard', $order['idcard'])->value('num');
                            (new FinanceOrder())->where('order_sn', $data['out_trade_no'])->update(['card_num' => $num]);
                        }
                        $this->addStockLog($v['device_sn'], $v['num'], $order_no, 1, $goods);
                        $index++;
                    }
                } elseif ($device['supply_id'] == 3) {
                    $out_log = [];
                    $machineGoodsModel = new MachineGoods();
                    //蜜连出货
                    for ($i = 0; $i < $v['count']; $i++) {
                        $order_no = $data['out_trade_no'] . 'order' . $index;
                        $res = $this->shipment($v['device_sn'], $v['num'], $order_no);
                        if ($res['errorCode'] != 0) {
                            $status = $res['errorCode'] == 65020 ? 2 : 3;
                            $out_log[] = ["device_sn" => $v['device_sn'], "num" => $v['num'], "order_sn" => $order_no, 'status' => $status];
                            (new FinanceOrder())->where('order_sn', $data['out_trade_no'])->update(['status' => 3]);
                            //锁货道
                            (new MachineGoods())
                                ->where(["device_sn" => $v['device_sn'], "num" => $v['num']])
                                ->update(['is_lock' => 1]);
                        } else {
                            $row = $machineGoodsModel->where(["device_sn" => $v['device_sn'], "num" => $v['num']])->field('id,stock')->find();
                            $machineGoodsModel->where('id', $row['id'])->update(['stock' => $row['stock'] - 1]);
                            $out_log[] = ["device_sn" => $v['device_sn'], "num" => $v['num'], "order_sn" => $order_no, 'status' => 0];
                            $this->addStockLog($v['device_sn'], $v['num'], $order_no, 1, $goods);
                        }
                        $index++;
                    }
                    (new MachineOutLogModel())->saveAll($out_log);
                }
//                if (isset($res) && $res['code'] == 1) {
//                    continue;
//                }
            }
        } elseif ($device['supply_id'] == 2) {

        } else {
            //其他供应商出货
        }
        //购买结束
        $str = 'buying' . $order['device_sn'];
        Cache::store('redis')->rm($str);
    }

    /**
     * 出货
     */
    public function Shipment($device_sn = "", $huodao = "", $order_sn = "")
    {
        $rand_str = rand(10000, 99999);
        $data = '{"cmd": 1000, "data": {"digital": ' . $huodao . ', "msg": "run", "count": 1, "quantity": 1, "done": 1}, "sn": "' . $device_sn . '", "nonceStr": "' . $rand_str . '"}';


        $post_data = array(
            'data' => $data
        );
        $res = $this->send_pos('http://mqtt.ibeelink.com/api/ext/tissue/pub-cmd', $post_data, md5($data . 'D902530082e570917F645F755AE17183'));
        if ($res['errorCode'] == 0) {
            return ['errorCode' => 0, 'msg' => '操作成功'];
        } else {
            $imei = (new MachineDevice())->where('device_sn', $device_sn)->value('imei');
            $data = [
                "imei" => $imei,
                "device_sn" => $device_sn,
                "num" => $huodao,
                "order_sn" => explode('order', $order_sn)[0],
                "status" => 1,
            ];
            (new MachineDeviceErrorModel())->save($data);
        }
        return $res;
    }

    public function send_pos($url, $post_data, $token)
    {
        $postdata = http_build_query($post_data);
        $options = array(
            'http' =>
                array(
                    'method' => 'POST',
                    'header' => array("token:" . $token, "chan:bee-CSQYUS", "Content-type:application/x-www-form-urlencoded"),
                    'content' => $postdata,
                    'timeout' => 15 * 60
                )
        );
        $context = stream_context_create($options);
        $result = file_get_contents($url, false, $context);
        $info = json_decode($result, true);
        trace($info, '出货信息');
        return $info;
    }

    public function addStockLog($device_sn, $num, $order_sn, $count, $goods)
    {
        $data = [
            'device_sn' => $device_sn,
            'num' => $num,
            'goods_id' => $goods['goods_id'],
            'old_stock' => $goods['stock'],
            'new_stock' => $goods['stock'] - $count,
            'change_detail' => '用户下单,库存减少' . $count . '件;订单号:' . $order_sn,
        ];
        (new MachineStockLogModel())->save($data);
    }

    public function goodsOut($device_sn, $num, $order_sn, $goods_id)
    {
        $device = (new MachineDevice())->where('device_sn', $device_sn)->field('imei,supply_id,transfer')->find();
        $imei = $device['imei'];
        $data = [
            "imei" => $imei,
            "deviceNumber" => $device_sn,
            "laneNumber" => $num,
            "laneType" => 1,
            "paymentType" => 1,//1在线支付，2纸币支付，3刷信用卡卡支付
            "orderNo" => $order_sn,
            "timestamp" => time()
        ];
        $str = $device_sn . '_ip';
        $ip = Cache::store('redis')->get($str);
        trace($ip, '板子ip');
        if ($device['transfer'] == 3) {
            $url = 'http://119.45.161.95:9100/api/sinShine/goodsOut';
        } elseif ($device['transfer'] == 4) {
            $url = 'http://121.40.60.106:9100/api/sinShine/goodsOut';
        } else {
            $url = 'http://feishi.feishi.vip:9100/api/vending/goodsOut';
        }
        $result = https_request($url, $data);
        $result = json_decode($result, true);
        $result['order_sn'] = $order_sn;
        trace($data, '出货参数');
        trace($result, '出货指令结果');
        if ($result['code'] == 200) {
            $str = $data['deviceNumber'] . '_heartBeat';
            Cache::store('redis')->set($str, 1, 180);
            $res = $this->isBack($order_sn, 1, $device['supply_id']);
            if (!$res) {
                //没有反馈业务处理
                $str = 'out_' . $order_sn;
                trace($str, '查询订单号');
                $res_a = Cache::store('redis')->get($str);
                if ($res_a == 2 || !$res) {
                    $outingStr = 'outing_' . $device_sn;
                    Cache::store('redis')->rm($outingStr);
                    $status = $res_a == 2 ? 1 : 5;
                    trace(1111, '没有出货反馈');
                    if ($status == 5) {
                        $data = [
                            "imei" => $imei,
                            "device_sn" => $device_sn,
                            "num" => $num,
                            "order_sn" => explode('order', $order_sn)[0],
                            'goods_id' => $goods_id,
                            "status" => $status,
                        ];
                        (new MachineDeviceErrorModel())->save($data);
                        $log = ["device_sn" => $device_sn, "num" => $num, "order_sn" => $data['order_sn'], 'status' => 5];
                        (new MachineOutLogModel())->save($log);
                    }
                    //由于没有反馈不扣库存,实际出货成功;会使用户购买实际货道为空,系统有库存的商品;使点击空转;故出货失败也扣库存
                    if (strstr($order_sn, "mt_")) {
                        Db::name('mt_device_goods')->where("num", $num)->where("device_sn", $device_sn)->dec("stock")->update();
                    } else {
                        Db::name('machine_goods')->where("num", $num)->where("device_sn", $device_sn)->dec("stock")->update();
                    }
                    return ['code' => 1, 'msg' => '失败'];
                }
            }
            if (strstr($order_sn, "mt_")) {
                Db::name('mt_device_goods')->where("num", $num)->where("device_sn", $device_sn)->dec("stock")->update();
            } else {
                Db::name('machine_goods')->where("num", $num)->where("device_sn", $device_sn)->dec("stock")->update();
            }
            return ['code' => 0, 'msg' => '成功'];

        } else {
            $save_data = [
                'device_sn' => $device_sn,
                'imei' => $imei,
                'num' => $num,
                'order_sn' => explode('order', $order_sn)[0],
                'goods_id' => $goods_id,
                'status' => 3,
            ];
            (new MachineDeviceErrorModel())->save($save_data);
            $log = ["device_sn" => $device_sn, "num" => $num, "order_sn" => $save_data['order_sn'], 'status' => 2];
            (new MachineOutLogModel())->save($log);
            return ['code' => 1, 'msg' => '失败'];
        }
    }

    public function isBack($order, $num, $supply_id)
    {
        $total_num = $supply_id == 5 ? 120 : 50;
        if ($num <= $total_num) {
            $str = 'out_' . $order;
            trace($str, '查询订单号');
            $res = Cache::store('redis')->get($str);
            trace($res, '查询结果');
            if ($res == 1) {
                return true;
            } else {
                if ($res == 2) {
                    return false;
                }
                if ($num > 1) {
                    usleep(500000);
                }
                $res = $this->isBack($order, $num + 1, $supply_id);
                return $res;
            }
        } else {
            return false;
        }
    }

    /**
     * 查看设备状态
     */
    public function device_status($sn = '')
    {

//        $sn="ILJXJI";
        $nonceStr = time();
        $url = "https://mqtt.ibeelink.com/api/ext/tissue/device/info";
        $data = '{
                "sn":"' . $sn . '","nonceStr": "' . $nonceStr . '"}';
        function send_post($url, $post_data, $token)
        {
            $postdata = http_build_query($post_data);
            $options = array(
                'http' =>
                    array(
                        'method' => 'GET',
                        'header' => array('token:' . $token, 'chan:bee-CSQYUS', 'Content-type:application/x-www-form-urlencoded'),
                        'content' => $postdata,
                        'timeout' => 15 * 60
                    )
            );
            $context = stream_context_create($options);
            $result = file_get_contents($url, false, $context);
            return $result;
        }

        $post_data = array(
            'data' => $data
        );
        $res = send_post($url, $post_data, md5($data . 'D902530082e570917F645F755AE17183'));
        $res_arr = json_decode($res, true);
        return $res_arr['data']['online'];

    }

    public function refund_notify()
    {
        $xml = request()->getContent();
        trace($xml, '微信支付毁掉');
        //将服务器返回的XML数据转化为数组
        $pay = new Wxpay();
        $data = $pay->xml2array($xml);
//        // 保存微信服务器返回的签名sign
//        $data_sign = $data['req_info'];
//        // sign不参与签名算法
//        unset($data['req_info']);
        $mchid_key = Cache::store('redis')->get('mchid_key');
        $key = $mchid_key ? $mchid_key : 'wgduhzmxasi8ogjetftyio111imljs2j';
        trace($key, '退款回调秘钥');
        $key = MD5($key);

        $res = $this->refund_decrypt($data['req_info'], $key);
        $res = $pay->xml2array($res);
        trace($res, '退款解密后数据');
//        $sign = $pay->makeSign($data);
//
//        // 判断签名是否正确  判断支付状态
//        if (($sign === $data_sign) && ($data['return_code'] == 'SUCCESS')) {
        //获取服务器返回的数据
//            $out_trade_no = $res['out_trade_no'];        //订单单号
//            $out_refund_no = $res['out_refund_no'];        //退款单号
//            $total_fee = $res['total_fee'];            //订单金额
//            $refund_fee = $res['refund_fee'];            //退款金额
//            $transaction_id = $res['transaction_id'];    //微信支付流水号
//        } else {
//            $result = false;
//        }
        // 返回状态给微信服务器
        $str = ' < xml><return_code ><![CDATA[SUCCESS]] ></return_code ><return_msg ><![CDATA[OK]] ></return_msg ></xml > ';

        echo $str;
        $this->refundDeal($res);
        Cache::store('redis')->rm('mchid_key');
    }

    public function refund_decrypt($str, $key)
    {

        $str = base64_decode($str);

        $str = openssl_decrypt($str, 'AES-256-ECB', $key, OPENSSL_RAW_DATA);

        return $str;
    }

    public function refundDeal($data)
    {
        if (!$data) {
            return false;
        }
        $orderModel = new \app\index\model\FinanceOrder();
        $order = $orderModel->where('order_sn', $data['out_trade_no'])->field('id,status,pay_type')->find();
        if ($order['status'] == 2) {
            return false;
        }
        $order_id = $order['id'];
        //修改订单状态
        $update_data = [
            'status' => 2,
            'refund_time' => time()
        ];
//        if ($reason) {
//            $update_data['refund_reason'] = $reason;
//        }
        $orderModel->where('id', $order_id)->update($update_data);
        $order_sn = $orderModel->where('id', $order_id)->value('order_sn');
        //修改代理商和分润人员余额
        $cashModel = new FinanceCash();
        $cash = $cashModel->where('order_sn', $order_sn)->select();
        $adminModel = new SystemAdmin();
        $uid = [];
        foreach ($cash as $k => $v) {
            $uid[] = $v['uid'];
        }
        if ($order['pay_type'] == 1 || $order['pay_type'] == 2) {
            $admin = $adminModel->whereIn('id', $uid)->column('system_balance', 'id');
        } elseif ($order['pay_type'] == 3) {
            $admin = $adminModel->whereIn('id', $uid)->column('agent_wx_balance', 'id');
        }
        $cash_data = [];
        foreach ($cash as $k => $v) {
            $money = $admin[$v['uid']] - $v['price'];
            if ($order['pay_type'] == 1 || $order['pay_type'] == 2) {
                $adminModel->where('id', $v['uid'])->update(['system_balance' => $money]);
            } elseif ($order['pay_type'] == 3) {
                $adminModel->where('id', $v['uid'])->update(['agent_wx_balance' => $money]);
            }
            $cash_data[] = [
                'uid' => $v['uid'],
                'order_sn' => $order_sn,
                'price' => 0 - $v['price'],
                'type' => 2
            ];
        }
        //添加余额修改记录
        $cashModel->saveAll($cash_data);
    }
}

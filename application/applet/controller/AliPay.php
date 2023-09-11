<?php

namespace app\applet\controller;

use app\box\controller\ApiV2;
use app\index\model\FinanceOrder;
use app\index\model\MachineCart;
use app\index\model\MachineDevice;
use app\index\model\MachineGoods;
use app\index\model\MallGoodsModel;
use app\index\model\MchidModel;
use app\index\model\OperateUserModel;
use app\index\model\OrderGoods;
use app\index\model\SystemAdmin;
use think\Cache;
use think\Controller;
use think\Db;
use think\Exception;

class AliPay extends Controller
{
    /**
     * 支付宝小程序支付接口
     */
    public function createOrder()
    {
        $post = $this->request->post();
        trace($post, '预支付参数');
//        $post['order_time'] = time();
        $post['status'] = 0;
        $order_sn = time() . mt_rand(1000, 9999);
        $post['order_sn'] = $order_sn;
        $device = (new MachineDevice())->where("device_sn", $post['device_sn'])->field("id,num,imei,uid,status,expire_time,is_lock,supply_id")->find();
        (new OperateUserModel())->where('openid', $post['openid'])->update(['uid' => $device['uid']]);
        if ($device['supply_id'] == 3) {
            $bool = (new Goods())->device_status($post['device_sn']);
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
        if ($device['is_lock'] < 0) {
            return json(['code' => 100, 'msg' => '设备已禁用']);
        }

        $mallGoodsModel = new MallGoodsModel();
        if (isset($post['num']) && $post['num'] > 0) {
            $goods = (new MachineGoods())->where(['device_sn' => $post['device_sn'], 'num' => $post['num']])->find();
            $post['price'] = $goods['active_price'] > 0 ? $goods['active_price'] : $goods['price'];
            trace($post['price'], '支付宝价格');
            if ($post['price'] <= 0) {
                return json(['code' => 100, 'msg' => '订单金额不能小于0']);
            }
            //一货道一商品
            $goods = (new MachineGoods())->where(['device_sn' => $post['device_sn'], 'num' => $post['num']])->find();
            $amount = (new MachineGoods())->where(['device_sn' => $post['device_sn'], 'num' => $post['num']])->value('stock');
            if ($amount < 1) {
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
                ->group('goods_id')
                ->field('sum(stock) total_stock')->find();
            if ($amount['total_stock'] < 1) {
                return json(['code' => 100, 'msg' => '库存不足']);
            }
            $goods = (new MachineGoods())
                ->where(['device_sn' => $post['device_sn'], 'goods_id' => $post['goods_id']])
                ->where('stock', '>', 0)
                ->find();
            if (!$goods) {
                return json(['code' => 100, 'msg' => '库存不足']);
            }
            $post['num'] = $goods['num'];
            $post['price'] = $goods['active_price'] > 0 ? $goods['active_price'] : $goods['price'];
            trace($post['price'], '支付宝价格');
            if ($post['price'] <= 0) {
                return json(['code' => 100, 'msg' => '订单金额不能小于0']);
            }
            $mall_goods = $mallGoodsModel->where('id', $post['goods_id'])->find();
            unset($post['goods_id']);
        }
        //判断是否有用户在购买
        $str = 'buying' . $post['device_sn'];
        $res = Cache::store('redis')->get($str);
        if ($res == 1) {
            return json(['code' => 100, 'msg' => '有其他用户正在购买,请稍后重试']);
        } else {
            Cache::store('redis')->set($str, 1, 120);
        }
        $profit = 0;
        if ($mall_goods['cost_price'] > 0) {
            $profit = round(($post['price'] - $mall_goods['cost_price']) * 100) / 100;
            $post['cost_price'] = $mall_goods['cost_price'] > 0 ? $mall_goods['cost_price'] : 0;
//            $post['other_cost_price'] = $mall_goods['other_cost_price'] > 0 ? $mall_goods['other_cost_price'] : 0;
        }
        $post['profit'] = $profit;

        include_once dirname(dirname(dirname(dirname(__FILE__)))) . '/vendor/alipay/aop/AopCertClient.php';
        include_once dirname(dirname(dirname(dirname(__FILE__)))) . '/vendor/alipay/aop/request/AlipayTradeCreateRequest.php';
        $uid = (new MachineDevice())->where("device_sn", $post['device_sn'])->value("uid");
        $user = Db::name('system_admin')->where("id", $uid)->find();
        $post['uid'] = $uid;
        $post['create_time'] = time();
        $post['count'] = 1;
        if ($user['is_ali_mchid'] == 1 && $user['ali_mchid_id']) {
            //代理商支付宝支付
            //todo 代理商支付宝支付,待配置
            return false;
        } else {
            //系统支付宝支付
            $order_obj = new FinanceOrder();
            $num = $post['num'];
            unset($post['num']);
            $post['pay_type'] = 2;
            $order_id = $order_obj->insertGetId($post);
            //添加订单商品
            $goods_data = [
                'order_id' => $order_id,
                'order_sn' => $order_sn . 'order1',
                'device_sn' => $goods['device_sn'],
                'num' => $num,
                'goods_id' => $goods['goods_id'],
                'price' => $post['price'],
                'count' => 1,
                'total_price' => $post['price'],
            ];
            (new OrderGoods())->save($goods_data);
//            $goods_detail = (new MallGoodsModel())->where('id', $goods['goods_id'])->find();
//            $goods_detail = [
//                'goods_id' => $goods['goods_id'],
//                'goods_name' => $goods_detail['title'],
//                'quantity' => 1,
//                'price' => $post['price']
//            ];

            $app_id = '2021003143688161';
            //应用私钥
            $privateKeyPath = dirname(dirname(dirname(dirname(__FILE__)))) . '/public/cert/api.feishi.vip_私钥.txt';
            $privateKey = file_get_contents($privateKeyPath);

            $aop = new \AopCertClient ();
            $aop->gatewayUrl = 'https://openapi.alipay.com/gateway.do';
            $aop->appId = $app_id;
            $aop->rsaPrivateKey = $privateKey;
            //支付宝公钥证书
            $aop->alipayPublicKey = dirname(dirname(dirname(dirname(__FILE__)))) . '/public/cert/alipayCertPublicKey_RSA2.crt';

            $aop->apiVersion = '1.0';
            $aop->signType = 'RSA2';
            $aop->postCharset = 'UTF-8';
            $aop->format = 'json';
            //调用getCertSN获取证书序列号
            $appPublicKey = dirname(dirname(dirname(dirname(__FILE__)))) . "/public/cert/appCertPublicKey_2021003143688161.crt";
            $aop->appCertSN = $aop->getCertSN($appPublicKey);
            //支付宝公钥证书地址
            $aliPublicKey = dirname(dirname(dirname(dirname(__FILE__)))) . "/public/cert/alipayRootCert.crt";;
            $aop->alipayCertSN = $aop->getCertSN($aliPublicKey);
            //调用getRootCertSN获取支付宝根证书序列号
            $rootCert = dirname(dirname(dirname(dirname(__FILE__)))) . "/public/cert/alipayRootCert.crt";
            $aop->alipayRootCertSN = $aop->getRootCertSN($rootCert);

            $object = new \stdClass();
            $object->out_trade_no = $order_sn;
            $object->total_amount = $post['price'];
            $object->subject = '智能云小店订单';
            $object->buyer_id = $post['openid'];
            $object->timeout_express = '10m';

            $json = json_encode($object);
            $request = new \AlipayTradeCreateRequest();
            $request->setNotifyUrl('http://api.feishi.vip/applet/ali_pay/systemNotify');
            $request->setBizContent($json);

            $result = $aop->execute($request);

            $responseNode = str_replace(".", "_", $request->getApiMethodName()) . "_response";
            $resultCode = $result->$responseNode->code;
            trace($result, '支付宝预支付结果');
            if (!empty($resultCode) && $resultCode == 10000) {
                $data = [
                    'order_sn' => $order_sn,
                    'orderno' => $result->$responseNode->trade_no,
                    'total_amount' => $post['price']
                ];
                return json(['code' => 200, 'data' => $data]);
            } else {
                return json(['code' => 100, 'msg' => $result->$responseNode->sub_msg]);
            }
        }
    }

    //购物车购买
    public function createOrderByCart()
    {
        $params = request()->get();
        if (!$params['device_sn'] || !$params['openid']) {
            return json(['code' => 100, 'msg' => '缺少参数']);
        }
        $device = (new MachineDevice())->where("device_sn", $params['device_sn'])->field("id,num,imei,uid,status,expire_time,is_lock,supply_id")->find();
        (new OperateUserModel())->where('openid', $params['openid'])->update(['uid' => $device['uid']]);
        if ($device['supply_id'] == 3) {
            $bool = (new Goods())->device_status($params['device_sn']);
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
        if ($device['is_lock'] < 1) {
            return json(['code' => 100, 'msg' => '设备已禁用']);
        }
        $cartModel = new MachineCart();
        $cart = $cartModel
            ->where(['device_sn' => $params['device_sn'], 'openid' => $params['openid']])
            ->select();
        if (!$cart) {
            return json(['code' => 100, 'msg' => '请选购商品']);
        }
        $user = Db::name('system_admin')->where("id", $device['uid'])->find();
        if ($user['is_ali_mchid'] == 1 && $user['ali_mchid_id']) {
            //代理商支付宝支付
            //todo 代理商支付宝支付,待配置
            return json(['code' => 100, 'msg' => '暂未开通']);
        } else {
            //判断是否有用户在购买
            $str = 'buying' . $params['device_sn'];
            $res = Cache::store('redis')->get($str);
            if ($res == 1) {
                return json(['code' => 100, 'msg' => '有其他用户正在购买,请稍后重试']);
            } else {
                Cache::store('redis')->set($str, 1, 120);
            }
            $port = [0, 2];
            $where['g.port'] = ['in', $port];
            $data = (new \app\index\model\MachineGoods())->alias("g")
                ->join("mall_goods s", "g.goods_id=s.id", "LEFT")
                ->where("g.device_sn", $params['device_sn'])
                ->where($where)
                ->where('num', '<=', $device['num'])
                ->where('g.goods_id', '>', 0)
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
                $orderSingleGoods = (new ApiV2())->getOrderGoods($params['device_sn'], $v['goods_id'], $price, $v['count'], [], 2);
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
            $order_sn = time() . mt_rand(1000, 9999);
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
//            //当设备所属人未开通商户号时,判断父亲代理商是否开通,若未开通,用系统支付,若开通,用父亲代理商商户号进行支付
//            $parentId = [];
//            $parentUser = (new SystemAdmin())->getParents($user['id'], 1);
//            foreach ($parentUser as $k => $v) {
//                $parentId[] = $v['id'];
//            }
//            $userList = (new SystemAdmin())->whereIn('id', $parentId)->select();
//            $is_set_mchid = false;
//            $wx_mchid_id = 0;
//            foreach ($userList as $k => $v) {
//                if ($v['is_wx_mchid'] == 1 && $v['wx_mchid_id']) {
//                    $is_set_mchid = true;
//                    $wx_mchid_id = $v['wx_mchid_id'];
//                    break;
//                }
//            }
//            if ($is_set_mchid && $wx_mchid_id) {
            $data['pay_type'] = 2;
//            } else {
//                $data['pay_type'] = 1;
//            }
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
                        'order_id' => $order_id
                    ];
                }
                if (isset($mall_goods[$v['goods_id']]['cost_price']) && $mall_goods[$v['goods_id']]['cost_price'] > 0) {
                    $total_profit += $v['total_price'] -
                        $mall_goods[$v['goods_id']]['cost_price'] * $v['count'];
                    $total_cost_price += $mall_goods[$v['goods_id']]['cost_price'] * $v['count'];
//                    $total_other_cost_price += $mall_goods[$v['goods_id']]['other_cost_price'] * $v['count'];
                }
            }
            $total_profit = round($total_profit, 2);
            $total_cost_price = round($total_cost_price, 2);
//            $total_other_cost_price = round($total_other_cost_price, 2);
            Db::name('finance_order')->where('id', $order_id)->update(['profit' => $total_profit, 'cost_price' => $total_cost_price, 'other_cost_price' => $total_other_cost_price]);
            (new OrderGoods())->saveAll($orderGoodsList);
            include_once dirname(dirname(dirname(dirname(__FILE__)))) . '/vendor/alipay/aop/AopCertClient.php';
            include_once dirname(dirname(dirname(dirname(__FILE__)))) . '/vendor/alipay/aop/request/AlipayTradeCreateRequest.php';
            $app_id = '2021003143688161';
            //应用私钥
            $privateKeyPath = dirname(dirname(dirname(dirname(__FILE__)))) . '/public/cert/api.feishi.vip_私钥.txt';
            $privateKey = file_get_contents($privateKeyPath);

            $aop = new \AopCertClient ();
            $aop->gatewayUrl = 'https://openapi.alipay.com/gateway.do';
            $aop->appId = $app_id;
            $aop->rsaPrivateKey = $privateKey;
            //支付宝公钥证书
            $aop->alipayPublicKey = dirname(dirname(dirname(dirname(__FILE__)))) . '/public/cert/alipayCertPublicKey_RSA2.crt';

            $aop->apiVersion = '1.0';
            $aop->signType = 'RSA2';
            $aop->postCharset = 'UTF-8';
            $aop->format = 'json';
            //调用getCertSN获取证书序列号
            $appPublicKey = dirname(dirname(dirname(dirname(__FILE__)))) . "/public/cert/appCertPublicKey_2021003143688161.crt";
            $aop->appCertSN = $aop->getCertSN($appPublicKey);
            //支付宝公钥证书地址
            $aliPublicKey = dirname(dirname(dirname(dirname(__FILE__)))) . "/public/cert/alipayRootCert.crt";;
            $aop->alipayCertSN = $aop->getCertSN($aliPublicKey);
            //调用getRootCertSN获取支付宝根证书序列号
            $rootCert = dirname(dirname(dirname(dirname(__FILE__)))) . "/public/cert/alipayRootCert.crt";
            $aop->alipayRootCertSN = $aop->getRootCertSN($rootCert);

            $object = new \stdClass();
            $object->out_trade_no = $order_sn;
            $object->total_amount = $total_price;
            $object->subject = '智能云小店订单';
            $object->buyer_id = $params['openid'];
            $object->timeout_express = '10m';

            $json = json_encode($object);
            $request = new \AlipayTradeCreateRequest();
            $request->setNotifyUrl('http://api.feishi.vip/applet/ali_pay/systemNotify');
            $request->setBizContent($json);

            $result = $aop->execute($request);

            $responseNode = str_replace(".", "_", $request->getApiMethodName()) . "_response";
            $resultCode = $result->$responseNode->code;
            trace($result, '支付宝预支付结果');
            if (!empty($resultCode) && $resultCode == 10000) {
                $data = [
                    'order_sn' => $order_sn,
                    'orderno' => $result->$responseNode->trade_no,
                    'total_amount' => $total_price
                ];
                return json(['code' => 200, 'data' => $data]);
            } else {
                return json(['code' => 100, 'msg' => $result->$responseNode->sub_msg]);
            }
        }
    }


    //安卓支付宝支付
    public function getPAy()
    {
        $post = request()->post();
        if (empty($post['device_sn']) || empty($post['order_sn'])) {
            return json(['code' => 100, 'msg' => '缺少参数']);
        }
        $order_sn = $post['order_sn'];
        $order = (new FinanceOrder())->where("order_sn", $post['order_sn'])->field("uid,price")->find();
        $uid = $order['uid'];
        $user = Db::name('system_admin')->where("id", $uid)->find();
        $post['uid'] = $uid;
        include_once dirname(dirname(dirname(dirname(__FILE__)))) . '/vendor/alipay/aop/AopCertClient.php';
        include_once dirname(dirname(dirname(dirname(__FILE__)))) . '/vendor/alipay/aop/request/AlipayTradeCreateRequest.php';
        if ($user['is_ali_mchid'] == 1 && $user['ali_mchid_id']) {
            //代理商支付宝支付
            //todo 代理商支付宝支付,待配置
            return false;
        } else {
            $app_id = '2021003143688161';
            //应用私钥
            $privateKeyPath = dirname(dirname(dirname(dirname(__FILE__)))) . '/public/cert/api.feishi.vip_私钥.txt';
            $privateKey = file_get_contents($privateKeyPath);

            $aop = new \AopCertClient ();
            $aop->gatewayUrl = 'https://openapi.alipay.com/gateway.do';
            $aop->appId = $app_id;
            $aop->rsaPrivateKey = $privateKey;
            //支付宝公钥证书
            $aop->alipayPublicKey = dirname(dirname(dirname(dirname(__FILE__)))) . '/public/cert/alipayCertPublicKey_RSA2.crt';

            $aop->apiVersion = '1.0';
            $aop->signType = 'RSA2';
            $aop->postCharset = 'UTF-8';
            $aop->format = 'json';
            //调用getCertSN获取证书序列号
            $appPublicKey = dirname(dirname(dirname(dirname(__FILE__)))) . "/public/cert/appCertPublicKey_2021003143688161.crt";
            $aop->appCertSN = $aop->getCertSN($appPublicKey);
            //支付宝公钥证书地址
            $aliPublicKey = dirname(dirname(dirname(dirname(__FILE__)))) . "/public/cert/alipayRootCert.crt";;
            $aop->alipayCertSN = $aop->getCertSN($aliPublicKey);
            //调用getRootCertSN获取支付宝根证书序列号
            $rootCert = dirname(dirname(dirname(dirname(__FILE__)))) . "/public/cert/alipayRootCert.crt";
            $aop->alipayRootCertSN = $aop->getRootCertSN($rootCert);

            $object = new \stdClass();
            $object->out_trade_no = $order_sn;
            $object->total_amount = $order['price'];
            $object->subject = '智能云小店订单';
            $object->buyer_id = $post['openid'];
            $object->timeout_express = '10m';

            $json = json_encode($object);
            $request = new \AlipayTradeCreateRequest();
            $request->setNotifyUrl('http://api.feishi.vip/applet/ali_pay/systemNotify');
            $request->setBizContent($json);

            $result = $aop->execute($request);

            $responseNode = str_replace(".", "_", $request->getApiMethodName()) . "_response";
            $resultCode = $result->$responseNode->code;
            if (!empty($resultCode) && $resultCode == 10000) {
                $data = [
                    'order_sn' => $order_sn,
                    'orderno' => $result->$responseNode->trade_no,
                    'total_amount' => $order['price']
                ];
                return json(['code' => 200, 'data' => $data]);
            } else {
                return json(['code' => 100, 'msg' => $result->$responseNode->sub_msg]);
            }
        }
    }

    public function systemNotify()
    {
        $params = request()->post();
        if (empty($params)) {
            echo 'error';
            exit;
        }
        trace($params, '支付宝支付回调');
        $data = [
            'out_trade_no' => $params['out_trade_no'],
            'total_fee' => $params['total_amount'],
            'transaction_id' => $params['trade_no'],
            'openid' => $params['buyer_id']
        ];
        $res = Cache::store('redis')->get('notify_' . $params['out_trade_no']);
        if (!$res) {
            Cache::store('redis')->set('notify_' . $params['out_trade_no'], 1, 300);
        } else {
            echo 'success';
            exit;
        }

        echo 'success';
        (new Goods())->orderDeal($data, 2);
        exit;
    }
}

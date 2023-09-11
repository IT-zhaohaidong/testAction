<?php

namespace app\box\controller;

use app\applet\controller\Goods;
use app\index\common\Oss;
use app\index\model\AppLogModel;
use app\index\model\FinanceOrder;
use app\index\model\MachineDevice;
use app\index\model\MachineGoods;
use app\index\model\MallGoodsModel;
use app\index\model\OrderGoods;
use app\index\model\OutVideoModel;
use app\index\model\SystemAdmin;
use think\Cache;
use think\Controller;
use think\Db;

//小程序端由后端控制出货,,安卓端由安卓控制出货
class ApiV4 extends Controller
{
    //一货道一商品,按货道创建购物车
    public function createOrder()
    {
        ini_set('memory_limit', '128M');
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
            return json(['code' => 100, 'msg' => '请选择商品']);
        }
        $device = (new MachineDevice())->where('imei', $imei)->find();
        if ($device['is_lock'] == 0) {
            return json(['code' => 100, 'msg' => '该设备被禁用!']);
        }
        if ($device['expire_time'] < time()) {
            return json(['code' => 100, 'msg' => '该设备已过期,请联系客服处理']);
        }
        if ($device['supply_id'] == 1) {
            if ($device['status'] != 1) {
                return json(['code' => 100, 'msg' => '设备不在线,请联系客服处理']);
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
            $totalStock = $machineGoodsModel->where('device_sn', $device_sn)->where('num', $v['num'])->where('is_lock', 0)->value('stock') ?? 0;
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
                ->where('is_lock', 0)
                ->field('price,active_price,num')
                ->find();
            $price = $machineGoods['active_price'] > 0 ? $machineGoods['active_price'] : $machineGoods['price'];
            $total_price = ($total_price * 100 + $price * 100 * $v['count']) / 100;
            $orderSingleGoods = $this->getOrderGoods($device_sn, $v['num'], $price, $v['count'], []);
            $orderGoods = array_merge($orderGoods, $orderSingleGoods);
        }
        if ($is_stock) {
            $title = (new MallGoodsModel())->where('id', $goods_id)->value('title');
            return json(['code' => 100, 'msg' => $title . " 库存不足"]);
        }
        if ($total_price < 0.01) {
            return json(['code' => 100, 'msg' => '支付金额必须大于0']);
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
        //当设备所属人未开通商户号时,判断父亲代理商是否开通,若未开通,用系统支付,若开通,用父亲代理商商户号进行支付
        $parentId = [];
        $parentUser = (new SystemAdmin())->getParents($device['uid'], 1);
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
        $user = Db::name('system_admin')->where("id", $device['uid'])->find();
        $pay = new Wxpay();
        if ($is_set_mchid && $wx_mchid_id) {
            //代理商
            $notify_url = 'https://api.feishi.vip/box/api_v4/agentNotify';
            $result = $pay->prepay('', $order_sn, $total_price, $user, $notify_url);
        } else {
            //系统
            $notify_url = 'https://api.feishi.vip/box/api_v4/payNotify';
//            var_dump($total_fee);die();
            $result = $pay->prepay('', $order_sn, $total_price, $user, $notify_url);
        }
        if ($result['return_code'] == 'SUCCESS') {
            $data = [
                'order_id' => $order_id,
                'url' => $result['code_url'],
                'data' => $deal_goods
            ];
            return json(['code' => 200, 'data' => $data]);
        } else {
            return json(['code' => 100, 'msg' => $result['return_msg']]);
        }

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
            ->where('is_lock',0)
            ->field('id,stock,num,goods_id')
            ->find();
        $goods_id = $goods['goods_id'];
        if ($count > $goods['stock']) {
            $where[] = $goods['id'];
            $item[] = [
                'device_sn' => $device_sn,
                'num' => $goods['num'],
                'goods_id' => $goods_id,
                'price' => $price,
                'count' => $goods['stock'],
                'total_price' => $price * $goods['stock'],
            ];
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

    public function outResult()
    {
        $order_id = request()->post('order_id');
        $status = request()->post('status', '');//1:成功 2:失败
        if (!$order_id) {
            return json(['code' => 100, 'msg' => '订单id不能为空']);
        }
        $row = Db::name('finance_order')->where(['id' => $order_id, 'status' => 4])->find();
        if ($row) {
//            无论成功与失败都减库存,避免货道空转
//            $orderGoods = Db::name('order_goods')->where(['order_id' => $order_id])->select();
//            $where = [
//                'device_sn' => $row['device_sn'],
//            ];
//            $goods = Db::name('machine_goods')->where($where)->column('stock', 'num');
            Db::name('finance_order')->where(['id' => $order_id])->update(['status' => $status == 1 ? 1 : 3]);
//            foreach ($orderGoods as $k => $v) {
//                Db::name('machine_goods')
//                    ->where('device_sn', $row['device_sn'])
//                    ->where('num', $v['num'])
//                    ->update(['stock' => $goods[$v['num']] - $v['count']]);
//            }
            return json(['code' => 200, 'msg' => '操作成功']);
        } else {
            return json(['code' => 100, 'msg' => '无效订单']);
        }
    }

    /**
     * 微信支付回调验证
     * @return array|bool
     */
    public function payNotify()
    {
        $xml = file_get_contents("php://input");
        //将服务器返回的XML数据转化为数组
        $data = self::xml2array($xml);
        trace($data, '女生盒子支付回调');
        // 保存微信服务器返回的签名sign
        $data_sign = $data['sign'];
        // sign不参与签名算法
        unset($data['sign']);
//        $sign = self::makeSign($data);

        // 判断签名是否正确  判断支付状态
//        if (($sign === $data_sign) && ($data['return_code'] == 'SUCCESS')) {
        $result = $data;
        //获取服务器返回的数据
        $out_trade_no = $data['out_trade_no'];        //订单单号
        $openid = $data['openid'];                    //付款人openID
        $total_fee = $data['total_fee'];            //付款金额
        $transaction_id = $data['transaction_id'];    //微信支付流水号
        $res = Cache::store('redis')->get('notify_' . $out_trade_no);
        if (!$res) {
            Cache::store('redis')->set('notify_' . $out_trade_no, 1, 300);
        } else {
            $str = '<xml><return_code><![CDATA[SUCCESS]]></return_code><return_msg><![CDATA[OK]]></return_msg></xml>';
            echo $str;
            return $result;
        }

        //支付成功的业务逻辑
        (new FinanceOrder())->where('order_sn', $out_trade_no)->update(['openid' => $openid]);
        (new Goods)->orderDeal($result, 1, 4);


        //直接扣库存,避免已支付未出货(无反馈,出货不扣库存,笔辩使下次购买电机空转)
        $row = Db::name('finance_order')->where(['order_sn' => $out_trade_no])->find();
        if ($row) {
            //无论成功与失败都减库存,避免货道空转
            $orderGoods = Db::name('order_goods')->where(['order_id' => $row['id']])->select();
            $where = [
                'device_sn' => $row['device_sn'],
            ];
            $goods = Db::name('machine_goods')->where($where)->column('stock', 'num');
            foreach ($orderGoods as $k => $v) {
                Db::name('machine_goods')
                    ->where('device_sn', $row['device_sn'])
                    ->where('num', $v['num'])
                    ->update(['stock' => $goods[$v['num']] - $v['count']]);
            }
        }
        $str = '<xml><return_code><![CDATA[SUCCESS]]></return_code><return_msg><![CDATA[OK]]></return_msg></xml>';
        echo $str;
        return $result;
    }

    /**
     * 微信支付回调验证
     * @return array|bool
     */
    public function agentNotify()
    {
        $xml = file_get_contents("php://input");
        //将服务器返回的XML数据转化为数组
        $data = self::xml2array($xml);
        trace($data, '女生盒子支付回调');
        // 保存微信服务器返回的签名sign
        $data_sign = $data['sign'];
        // sign不参与签名算法
        unset($data['sign']);
//        $sign = self::makeSign($data);

        // 判断签名是否正确  判断支付状态
//        if (($sign === $data_sign) && ($data['return_code'] == 'SUCCESS')) {
        $result = $data;
        //获取服务器返回的数据
        $out_trade_no = $data['out_trade_no'];        //订单单号
        $openid = $data['openid'];                    //付款人openID
        $total_fee = $data['total_fee'];            //付款金额
        $transaction_id = $data['transaction_id'];    //微信支付流水号
        //支付成功的业务逻辑

        $res = Cache::store('redis')->get('notify_' . $out_trade_no);
        if (!$res) {
            Cache::store('redis')->set('notify_' . $out_trade_no, 1, 300);
        } else {
            $str = '<xml><return_code><![CDATA[SUCCESS]]></return_code><return_msg><![CDATA[OK]]></return_msg></xml>';
            echo $str;
            return $result;
        }
        (new FinanceOrder())->where('order_sn', $out_trade_no)->update(['openid' => $openid]);
        (new Goods)->orderDeal($result, 3, 4);

        //直接扣库存,避免已支付未出货(无反馈,出货不扣库存,笔辩使下次购买电机空转)
        $row = Db::name('finance_order')->where(['order_sn' => $out_trade_no])->find();
        if ($row) {
            //无论成功与失败都减库存,避免货道空转
            $orderGoods = Db::name('order_goods')->where(['order_id' => $row['id']])->select();
            $where = [
                'device_sn' => $row['device_sn'],
            ];
            $goods = Db::name('machine_goods')->where($where)->column('stock', 'num');
            foreach ($orderGoods as $k => $v) {
                Db::name('machine_goods')
                    ->where('device_sn', $row['device_sn'])
                    ->where('num', $v['num'])
                    ->update(['stock' => $goods[$v['num']] - $v['count']]);
            }
        }
//        } else {
//            $result = false;
//        }
//        // 返回状态给微信服务器
//        if ($result) {
        $str = '<xml><return_code><![CDATA[SUCCESS]]></return_code><return_msg><![CDATA[OK]]></return_msg></xml>';
        echo $str;
//        } else {
//            $str = '<xml><return_code><![CDATA[FAIL]]></return_code><return_msg><![CDATA[签名失败]]></return_msg></xml>';
//        }

        return $result;
    }

    //---------------------------------------------------------------用到的函数------------------------------------------------------------

    /**
     * 错误返回提示
     * @param string $errMsg 错误信息
     * @param string $status 错误码
     * @return  json的数据
     */
    protected function return_err($errMsg = 'error', $status = 0)
    {
        exit(json_encode(array('status' => $status, 'result' => 'fail', 'errmsg' => $errMsg)));
    }


    /**
     * 正确返回
     * @param array $data 要返回的数组
     * @return  json的数据
     */
    protected function return_data($data = array())
    {
        exit(json_encode(array('status' => 1, 'result' => 'success', 'data' => $data)));
    }

    /**
     * 将一个数组转换为 XML 结构的字符串
     * @param array $arr 要转换的数组
     * @param int $level 节点层级, 1 为 Root.
     * @return string XML 结构的字符串
     */
    protected function array2xml($arr, $level = 1)
    {
        $s = $level == 1 ? "<xml>" : '';
        foreach ($arr as $tagname => $value) {
            if (is_numeric($tagname)) {
                $tagname = $value['TagName'];
                unset($value['TagName']);
            }
            if (!is_array($value)) {
                $s .= "<{$tagname}>" . (!is_numeric($value) ? '<![CDATA[' : '') . $value . (!is_numeric($value) ? ']]>' : '') . "</{$tagname}>";
            } else {
                $s .= "<{$tagname}>" . $this->array2xml($value, $level + 1) . "</{$tagname}>";
            }
        }
        $s = preg_replace("/([\x01-\x08\x0b-\x0c\x0e-\x1f])+/", ' ', $s);
        return $level == 1 ? $s . "</xml>" : $s;
    }

    /**
     * 将xml转为array
     * @param string $xml xml字符串
     * @return array    转换得到的数组
     */
    protected function xml2array($xml)
    {
        //禁止引用外部xml实体
        libxml_disable_entity_loader(true);
        $result = json_decode(json_encode(simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
        return $result;
    }

    /**
     *
     * 产生随机字符串，不长于32位
     * @param int $length
     * @return 产生的随机字符串
     */
    public function getNonceStr($length = 32)
    {
        $chars = "abcdefghijklmnopqrstuvwxyz0123456789";
        $str = "";
        for ($i = 0; $i < $length; $i++) {
            $str .= substr($chars, mt_rand(0, strlen($chars) - 1), 1);
        }
        return $str;
    }

    /**
     * 生成签名
     * @return 签名
     */
    public function makeSign($data, $key)
    {
        // 去空
        $data = array_filter($data);
        //签名步骤一：按字典序排序参数
        ksort($data);
        $string_a = http_build_query($data);
        $string_a = urldecode($string_a);
        //签名步骤二：在string后加入KEY
        //$config=$this->config;
        $string_sign_temp = $string_a . "&key=" . $key;
        //签名步骤三：MD5加密
        $sign = md5($string_sign_temp);
        // 签名步骤四：所有字符转为大写
        $result = strtoupper($sign);
        return $result;
    }


    function https_request($url, $data = null, $header = NULL)
    {
        // 1. 初始化一个 cURL 对象
        $curl = curl_init();
        // 2.设置你需要抓取的URL
        curl_setopt($curl, CURLOPT_URL, $url);
        // (可选)设置头 阿里云的许多接口需要在头上传输秘钥
        if (!empty($header)) {
            curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
        }
        // 3.https必须加这个，不加不好使（不多加解释，东西太多了）
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); //对认证证书进行检验
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        // 4.设置post数据
        if (!empty($data)) {//post方式，否则是get方式
            //设置模拟post方式
            curl_setopt($curl, CURLOPT_POST, 1);
            //传数据，get方式是直接在地址栏传的，这是post传参的解决方式
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);//$data可以是数组，json
        }
        // 设置cURL 参数，要求结果保存到字符串中还是输出到屏幕上。1是保存，0是输出
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        // 让curl跟随页面重定向
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
        // 5. 运行cURL，请求网页
        $output = curl_exec($curl);
        // 6. 关闭CURL请求
        curl_close($curl);
        return $output;
    }
}

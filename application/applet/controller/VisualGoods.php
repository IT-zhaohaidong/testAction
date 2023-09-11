<?php

namespace app\applet\controller;

use app\index\common\Decrypt;
use app\index\model\MachineDevice;
use app\index\model\MachineVisionGoodsModel;
use app\index\model\OperateUserModel;
use app\index\model\VisionOrderModel;
use think\Controller;
use WxPayPoint\Request\WxPayPointRequest;

class VisualGoods extends Controller
{

    protected $config = [];

    public function __construct()
    {
        parent::__construct();
        $this->config = [
            'appid' => 'wx6fd3c40b45928f43',//公众账号ID
            'mch_id' => '1628468078',   //支付商户号
            'service_id' => '00004000000000168975706235446797',//服务ID
            'key' => 'wgduhzmxasi8ogjetftyio111imljs2j',//支付key 1
            'v3key' => '68Api3sahjdjk45sdhisainnashl874k',//支付v3key  1
            'serial_no' => '167DCE6BA851318A63DF3CB22266B858F85B4032',//证书序号 1
            'private_key' => $_SERVER['DOCUMENT_ROOT'] . '/cert/apiclient_key.pem',//证书 1
            'public_key' => $_SERVER['DOCUMENT_ROOT'] . '/cert/apiclient_cert.pem',
        ];
    }

    public function getList()
    {
        $device_sn = request()->get('device_sn', '');
        if (!$device_sn) {
            return json(['code' => 100, 'msg' => '请扫描设备二维码']);
        }
        $model = new MachineVisionGoodsModel();
        $goodsList = $model->alias('mg')
            ->join('vision_goods g', 'mg.goods_id=g.id', 'left')
            ->field('g.title,g.image,mg.price')
            ->select();
        $model = new  \app\index\model\MachineDevice();
        $image = $model->alias('d')
            ->join('machine_banner b', 'd.banner_id=b.id')
            ->where('d.device_sn', $device_sn)
            ->value('b.material_image');
        $images = $image ? explode(',', $image) : [];
        return json(['code' => 200, 'data' => ['goodsList' => $goodsList, 'banner' => $images]]);
    }

    public function createOrder()
    {
        $params = request()->get();
        if (empty($params['openid']) || empty($params['device_sn'])) {
            return json(['code' => 100, 'msg' => '缺少参数']);
        }
        $user = (new OperateUserModel())->where('openid', $params['openid'])->find();
        if (!$user) {
            return json(['code' => 400, 'msg' => '未授权']);
        }
        $device = (new MachineDevice())->where('device_sn', $params['device_sn'])->find();
        if ($device['status'] != 1) {
            return json(['code' => 101, 'msg' => '暂停服务 设备离线']);
        }
        if ($device['expire_time'] < time()) {
            //设备已过期
            return json(['code' => 101, 'msg' => '设备已过期,请联系客服处理!']);
        }
        if ($device['is_lock'] < 1) {
            return json(['code' => 101, 'msg' => '设备已禁用']);
        }
        $order_sn = time() . rand(100, 999) . $user['id'];
        $app = WxPayPointRequest::getInstance($this->config);
        trace($app, '支付分配置');
        $data = [
            "out_order_no" => $order_sn,
            "appid" => $this->config['appid'],
            "service_id" => "00004000000000168975706235446797",
            "service_introduction" => "",
            "time_range" => [
                "start_time" => 'OnAccept'
            ],
            "location" => [
                "start_location" => $device['position_describe'] ?? '暂无地点'
            ],
            "notify_url" => "https://api.feishi.vip/applet/visual_goods/notify",
        ];
        $result = $app->createBill($data);
        $result['insert_data'] = $data;
        if (isset($result['code']) && $result['code']) {
            return json(['code' => 100, 'msg' => $result['message']]);
        }
        trace($result, '支付分订单创建');
        $extraData = [
            'mch_id' => $result['mch_id'],
            'package' => $result['package'],
            'timestamp' => $result['timestamp'],
            'nonce_str' => $result['nonce_str'],
            'sign_type' => $result['sign_type'],
            'sign' => $result['sign'],
            'out_order_no' => $result['out_order_no'],
            'service_id' => $result['service_id']
        ];
        $order = [
            'order_sn' => $result['out_order_no'],
            'openid' => $params['openid'],
            'uid' => $device['uid'],
            'device_sn' => $params['device_sn'],
            'status' => 0
        ];
        $result = (new VisionOrderModel())->save($order);
        if ($result) {
            return json(['code' => 200, 'msg' => '订单创建成功', 'extraData' => $extraData]);
        } else {
            return json(['code' => 100, 'msg' => '订单创建失败']);
        }
    }

    /**
     * 用户确认订单回调
     */
    public function notify()
    {
        $post = $this->request->post();
        trace($post, "用户确认订单回调");
        $orderModel = new VisionOrderModel();
        if ($post['event_type'] == "PAYSCORE.USER_CONFIRM") {
            $obj = new Decrypt('68Api3sahjdjk45sdhisainnashl874k');//todo 更改V3Key
            $json = $obj->decryptToString($post['resource']['original_type'], $post['resource']['nonce'], $post['resource']['ciphertext']);
            $arr = json_decode($json, true);
            $order_sn = $arr['out_order_no'];
            $device = $orderModel->where("order_sn", $order_sn)->field("device_sn,imei,status")->find();
//            $use_str = 'use' . $borrow_device;
//            $res = Cache::store('redis')->get($use_str);
//            if (!$res) {
//                $app = WxPayPointRequest::getInstance($this->config);
//                $data = [
//                    "out_order_no" => $order_sn,
//                    "appid" => $this->config['appid'],
//                    "service_id" => "00002002000000164575695389413680",
//                    "reason" => "操作超时",
//                ];
//                $result = $app->cancelBill($data);
//                trace($result, '取消订单');
//                (new VisionOrderModel())->where("order_sn", $order_sn)->update(['order_status' => 5]);
//                return json(['code' => 200, 'msg' => '成功']);
//            }
            $data = [
                "device_sn" => $device['device_sn'],
                "imei" => $device['imei'],
                'timestamp' => $order_sn
            ];
            $result = https_request('https://tanhuang.feishikeji.cloud/api/device/out', $data);
            trace('开锁结果', $result);
            if (!strpos($result, '200')) {
//                $return_area = Db::name('machine_device')->where('device_sn', $borrow_device)->value('position_describe');
                $app = WxPayPointRequest::getInstance($this->config);
                $data = [
                    "out_order_no" => $order_sn,
                    "appid" => $this->config['appid'],
                    "service_id" => "00002002000000164575695389413680",
                    "reason" => "开锁失败",
                ];
                $result = $app->cancelBill($data);
                trace($result, '取消订单');
                $orderModel->where("order_sn", $order_sn)->update(['status' => 7]);
                return json(['code' => 200, 'msg' => '成功']);
//                (new FinanceOrder())->where("order_sn", $order_sn)->save(['order_status' => 4]);
            } else {
                $data = ['status' => 5];
                $orderModel->where("order_sn", $order_sn)->save($data);
            }
            return json(['code' => 200, 'msg' => '成功']);
        }
    }
}

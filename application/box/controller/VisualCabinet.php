<?php

namespace app\box\controller;

use app\index\common\Oss;
use app\index\common\OssToken;
use app\index\model\MachineVisionGoodsModel;
use app\index\model\VisionOrderModel;
use think\Cache;
use think\Controller;

//视觉柜
class VisualCabinet extends Controller
{
    //上传出货视频
//    public function uploadVideo()
//    {
//        $file = $_FILES['file'];
//        $file_name = $file['name'];//获取缓存区图片,格式不能变
//        $type = array("mp4");//允许选择的文件类型
//        $ext = explode(".", $file_name);//拆分获取图片名
//        $ext = $ext[count($ext) - 1];//取图片的后缀名
//        $path = dirname(dirname(dirname(dirname(__FILE__))));
//        if (in_array($ext, $type)) {
////            if ($_FILES["file"]["size"] / 1024 / 1024 > 15) {
////                return json(['code' => 100, 'msg' => '上传文件不可大于15M!']);
////            }
//            $name = "/public/upload/vision-video/" . date('Ymd') . '/';
//            $dirpath = $path . $name;
//            if (!is_dir($dirpath)) {
//                mkdir($dirpath, 0777, true);
//            }
//            $time = time() . rand(1000, 9999);
//            $filename = $dirpath . $time . '.' . $ext;
//            move_uploaded_file($file["tmp_name"], $filename);
//            $ossFile = "vision_video/" . $time . '.' . $ext;
//            $url = (new Oss())->uploadToOss($ossFile, $filename);
////            $device_sn = (new MachineDevice())->where('imei', $post['imei'])->value('device_sn');
////            $data = [
////                'imei' => isset($post['imei']) ? $post['imei'] : '',
////                'device_sn' => $device_sn,
////                'url' => $url,
////                'order_sn' => $post['order_sn'],
////                'num' => $post['num'],
////            ];
////            (new OutVideoModel())->save($data);
//            $data = ['code' => 200, 'data' => ['filename' => $url]];
//            return json($data);
//        } else {
//            return json(['code' => 100, 'msg' => '文件类型错误!']);
//        }
//    }

    //通知订单取货结果
    public function getGoodsResult()
    {
        $params = request()->post();
        if (empty($params['order_sn'])) {
            return json(['code' => 100, 'msg' => '缺少参数']);
        }
//        $data = [
//            'main' => $params['main'],
//            'subsidiary' => $params['subsidiary'],
//            'status' => 1
//        ];
        $model = new VisionOrderModel();
        $order = $model->where('order_sn', $params['order_sn'])->find();
        if (!$order) {
            return json(['code' => 100, 'msg' => '订单不存在']);
        }
//        $model->where('id', $order['id'])->update($order);
        $goods = (new MachineVisionGoodsModel())->alias('mg')
            ->join('vision_goods g', 'g.id=mg.goods_id', 'left')
            ->where('mg.device_sn', $order['device_sn'])
            ->field('g.sku_id')
            ->select();
        $data = [
            'recog_id' => $params['order_sn'],
            'origin_time' => strtotime($order['create_time']),
            'sku_scope' => $goods,
            'container_id' => $order['device_sn'],
            'resource_urls' => [
                $params['main'],
                $params['subsidiary']
            ],
            'notify_url' => 'https://api.feishi.vip/applet/visual_cabinet_notify/orderNotify'
        ];
        $res = (new \app\index\common\VisualCabinet())->recognition($data);
        $order_data = [
            'main' => $params['main'],
            'subsidiary' => $params['subsidiary'],
        ];
        if (empty($res['data'])) {
            $order_data['status'] = 6;//取货视频上传失败
            $order_data['fail_reason'] = $res['msg'];
        } else {
            $order_data['status'] = 1;//上传成功,识别中
            $order_data['ks_recog_id'] = $res['data']['ks_recog_id'];
        }
        $model->where('id', $order['id'])->update($order_data);
        return json(['code' => 200, 'msg' => '成功']);
    }

    //抓拍图片
    public function orderImage()
    {
        $file = $_FILES['file'];
        $order_sn = request()->post('order_sn', '');
        if (!$order_sn) {
            return json(['code' => 100, 'msg' => '缺少参数']);
        }
        $orderModel = new VisionOrderModel();
        $order = $orderModel->where('order_sn', $order_sn)->find();
        if (!$order) {
            return json(['code' => 100, 'msg' => '订单不存在']);
        }
        $file_name = $file['name'];//获取缓存区图片,格式不能变
        $type = array("png",'jpg','jpeg');//允许选择的文件类型
        $ext = explode(".", $file_name);//拆分获取图片名
        $ext = $ext[count($ext) - 1];//取图片的后缀名
        $path = dirname(dirname(dirname(dirname(__FILE__))));
        if (in_array($ext, $type)) {
//            if ($_FILES["file"]["size"] / 1024 / 1024 > 15) {
//                return json(['code' => 100, 'msg' => '上传文件不可大于15M!']);
//            }
            $name = "/public/upload/vision-video/" . date('Ymd') . '/';
            $dirpath = $path . $name;
            if (!is_dir($dirpath)) {
                mkdir($dirpath, 0777, true);
            }
            $time = time() . rand(1000, 9999);
            $filename = $dirpath . $time . '.' . $ext;
            move_uploaded_file($file["tmp_name"], $filename);
            $ossFile = "vision_video/" . $time . '.' . $ext;
            $url = (new Oss())->uploadToOss($ossFile, $filename);
            $order_image = $order['order_image'] ? $order['order_image'] . ',' . $url : $url;
            $orderModel->where('id', $order['id'])->update(['order_image' => $order_image]);
            $data = ['code' => 200, 'msg' => '上传成功'];
            return json($data);
        } else {
            return json(['code' => 100, 'msg' => '文件类型错误!']);
        }
    }

    //获取ossToken
    public function getToken()
    {
        $str = 'ossToken';
        $token = Cache::store('redis')->get($str);
        if ($token) {
            return json(['code' => 200, 'data' => $token]);
        } else {
            $result = (new OssToken())->getStsToken();
            Cache::store('redis')->set($str, $result['data'], 3000);
            return json($result);
        }
    }


}

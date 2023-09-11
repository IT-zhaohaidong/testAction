<?php

namespace app\index\controller;

use app\index\common\Oss;
use app\index\common\VisualCabinet;
use app\index\model\SystemVisionGoodsModel;
use app\index\model\VisionGoodsModel;

//视觉柜商品库
class SystemVisionGoods extends BaseController
{
    public function getList()
    {
        $params = request()->get();
        $user = $this->user;
        $page = request()->get('page', 1);
        $limit = request()->get('limit', 15);
        $where = [];
        if (!empty($params['title'])) {
            $where['title'] = ['like', '%' . $params['title'] . '%'];
        }

        if (!empty($params['code'])) {
            $where['code'] = ['like', '%' . $params['code'] . '%'];
        }
        $model = new SystemVisionGoodsModel();
        $count = $model
            ->where($where)
            ->count();

        $list = $model->alias('g')
            ->where($where)
            ->page($page)
            ->limit($limit)
            ->order('id desc')
            ->select();

        foreach ($list as $k => $v) {
            if (in_array($user['id'], explode(',', $v['uid']))) {
                //已加入我的商品库
                $list[$k]['is_join'] = 1;
            } else {
                //未加入我的商品库
                $list[$k]['is_join'] = 0;
            }
        }
        return json(['code' => 200, 'data' => $list, 'params' => $params, 'count' => $count]);

    }

    //通过69码搜索三方商品库商品
    public function searchGoodsByCode()
    {
        $code = request()->get('code', '');
        if (!$code) {
            return json(['code' => 100, 'msg' => '缺少参数']);
        }
        $list = (new VisualCabinet())->getGoodsByCode($code);
        if (empty($list['data'])) {
            return json(['code' => 200, 'data' => [], 'msg' => '暂无商品']);
        }
        $data = $list['data'];
        $model = new SystemVisionGoodsModel();
        $goods = $model->where('code', $code)->column('sku_id');
        foreach ($data as $k => $v) {
            //判断商品是否在商品库存在
            if (in_array($v['ks_sku_id'], $goods)) {
                $data[$k]['is_join'] = 1;
            } else {
                $data[$k]['is_join'] = 0;
            }
        }
        return json(['code' => 200, 'data' => $data, 'msg' => '成功']);
    }

    //加入系统商品库
    public function joinSystemGoods()
    {
        $params = request()->post('data/a');
        $user = $this->user;
        $data = [
            'code' => $params['barcode'],
            'image' => $params['cover'],
            'sku_id' => $params['ks_sku_id'],
            'title' => $params['sku_name'],
            'img_urls' => implode(',', $params['img_urls']),
            'uid' => $user['id'],
            'status' => 1,
            'add_type' => 2
        ];
        $model = new SystemVisionGoodsModel();
        $row = $model->where(['sku_id' => $params['ks_sku_id']])->find();
        if ($row) {
            return json(['code' => 100, 'msg' => '该商品已加入商品库']);
        }
        $model->save($data);
        return json(['code' => 200, 'msg' => '加入成功']);
    }

    //提交新商品到三方商品库进行审核
    public function addToThirdGoods()
    {
        $params = request()->post();
        if (!$params['code'] || !$params['image'] || !$params['title'] || !$params['img_urls']) {
            return json(['code' => 100, 'msg' => '缺少参数']);
        }
        $user = $this->user;
        $data = [
            'code' => $params['code'],
            'image' => $params['image'],
            'title' => $params['title'],
            'img_urls' => implode(',', array_unique($params['img_urls'])),
            'status' => 0,
            'price' => $params['price'],
//            'cate_ids' => implode(',', $params['cate_ids']),
            'user_id' => $user['id'],
            'create_time' => time()
        ];
        $model = new SystemVisionGoodsModel();
        $id = $model->insertGetId($data);
        $third_data = [
            'sku_id' => $id,
            'sku_name' => $params['title'],
            'barcode' => $params['code'],
            'img_urls' => $params['img_urls'],
            'notify_url' => 'http://api.feishi.vip/applet/visual_cabinet_notify/index'
        ];
        $visualCabinet = new VisualCabinet();
        $res = $visualCabinet->putSku($third_data);
        if (!empty($res['data'])) {
            $model->where('id', $id)->update(['sku_id' => $res['data']['ks_sku_id']]);
            return json(['code' => 200, 'msg' => '提交成功,等待审核']);
        } else {
            $model->where('id', $id)->delete();
            return json(['code' => 100, 'msg' => $res['msg']]);
        }
    }

    public function joinMyGoods()
    {
        $user = $this->user;
        $ids = request()->post('ids/a');
        if (!$ids) {
            return json(['code' => 100, 'msg' => '请选择商品!']);
        }
        $model = new SystemVisionGoodsModel();
        $list = $model->whereIn('id', $ids)->select();
        $insert_arr = [];
        foreach ($list as $k => $v) {
            $item = [
                'uid' => $user['id'],
                'title' => $v['title'],
                'goods_id' => $v['id'],
                'code' => $v['code'],
                'sku_id' => $v['sku_id'],
                'image' => $v['image'],
                'img_urls' => $v['img_urls'],
                'add_type' => $v['add_type'],
                'status' => $v['status'],
                'price' => $v['price'],
            ];
            $insert_arr[] = $item;
            $uid = $v['uid'] ? $v['uid'] . $user['id'] . ',' : ',' . $user['id'] . ',';
            $model->where('id', $v['id'])->update(['uid' => $uid]);
        }
        (new VisionGoodsModel())->saveAll($insert_arr);
        return json(['code' => 200, 'msg' => '加入成功']);
    }

    public function uploadImage()
    {
        $file = $_FILES['file'];
        $file_name = $file['name'];//获取缓存区图片,格式不能变
        $type = array("jpg", "jpeg", 'png', 'bmp');//允许选择的文件类型
        $ext = explode(".", $file_name);//拆分获取图片名
        $ext = $ext[count($ext) - 1];//取图片的后缀名
        $path = dirname(dirname(dirname(dirname(__FILE__))));
        if (in_array($ext, $type)) {
            if ($_FILES["file"]["size"] / 1024 / 1024 > 10) {
                return json(['code' => 100, 'msg' => '上传文件不可大于10M!']);
            }
            $name = "/public/upload/" . date('Ymd') . '/';
            $dirpath = $path . $name;
            if (!is_dir($dirpath)) {
                mkdir($dirpath, 0777, true);
            }
            $time = time();
            $filename = $dirpath . $time . '.' . $ext;
            move_uploaded_file($file["tmp_name"], $filename);
            $ossFile = "visionGoods/" . $time . rand(1000, 9999) . '.' . $ext;
            $url = (new Oss())->uploadToOss($ossFile, $filename);
//            $filename = Env::get('server.servername', 'http://api.feishi.vip') . '/upload/' . date('Ymd') . '/' . $time . '.' . $ext;
            $data = ['code' => 200, 'data' => ['filename' => $url]];
            return json($data);
        } else {
            return json(['code' => 100, 'msg' => '文件类型错误!']);
        }
    }

    //关闭页面,删除上传图片
    public function delImage()
    {
        $images = request()->post('images/a', []);
        foreach ($images as $k => $v) {
            delMaterial($v);
        }
        return json(['code' => 200, 'msg' => '成功']);
    }
}

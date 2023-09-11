<?php

namespace app\index\controller;

use app\index\common\VisualCabinet;
use app\index\model\SystemVisionGoodsModel;
use app\index\model\VisionGoodsModel;

//视觉柜我的商品
class VisionGoods extends BaseController
{
    public function getList()
    {
        $params = request()->get();
        $user = $this->user;
        $page = request()->get('page', 1);
        $limit = request()->get('limit', 15);
        $where = [];
        if ($user['role_id'] != 1) {
            if ($user['role_id'] > 5) {
                $user['id'] = $user['parent_id'];
            }
            $where['g.uid'] = $user['id'];
        }
        if (!empty($params['title'])) {
            $where['g.title'] = ['like', '%' . $params['title'] . '%'];
        }

        if (!empty($params['code'])) {
            $where['g.code'] = ['like', '%' . $params['code'] . '%'];
        }
        if (!empty($params['username'])) {
            $where['a.username'] = ['like', '%' . $params['username'] . '%'];
        }
        $model = new VisionGoodsModel();
        $count = $model->alias('g')
            ->join('system_admin a', 'a.id=g.uid', 'left')
            ->where($where)
            ->count();

        $list = $model->alias('g')
            ->join('system_admin a', 'a.id=g.uid', 'left')
            ->where($where)
            ->field('g.*,a.username')
            ->page($page)
            ->limit($limit)
            ->order('id desc')
            ->select();
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
        if (!$list['data']) {
            return json(['code' => 200, 'data' => [], 'msg' => '暂无商品']);
        }
        $data = $list['data'];
        $model = new VisionGoodsModel();
        $goods = $model->where('code', $code)->column('sku_id');
        foreach ($data as $k => $v) {
            //判断商品是否在我的商品存在
            if (in_array($v['ks_sku_id'], $goods)) {
                $data[$k]['is_join'] = 1;
            } else {
                $data[$k]['is_join'] = 0;
            }
        }
        return json(['code' => 200, 'data' => $data, 'msg' => '成功']);
    }

    //加入我的商品
    public function joinMyGoods()
    {
        $params = request()->post('data/a');
        $user = $this->user;
        $data = [
            'code' => $params['barcode'],
            'image' => $params['cover'],
            'sku_id' => $params['ks_sku_id'],
            'title' => $params['sku_name'],
            'img_urls' => implode(',', $params['img_urls']),
            'uid' => ',' . $user['id'] . ',',
            'user_id' => $user['id'],
            'status' => 1,
            'add_type' => 2,
            'create_time' => time()
        ];
        $goodsModel = new VisionGoodsModel();
        $goods = $goodsModel->where('sku_id', $params['ks_sku_id'])->find();
        if ($goods) {
            return json(['code' => 101, 'msg' => '该商品已加入我的商品']);
        }
        $systemModel = new SystemVisionGoodsModel();
        $system = $systemModel->where('sku_id', $params['ks_sku_id'])->find();
        $goods_data = [
            'code' => $params['barcode'],
            'sku_id' => $params['ks_sku_id'],
            'title' => $params['sku_name'],
            'image' => $params['cover'],
            'img_urls' => implode(',', $params['img_urls']),
            'uid' => $user['id'],
            'status' => 1,
            'add_type' => 2,
        ];
        if (!$system) {
            $id = $systemModel->insertGetId($data);
            $goods_data['goods_id'] = $id;
            $goodsModel->save($goods_data);
        } else {
            $goods_data['goods_id'] = $system['id'];
            $goodsModel->save($goods_data);
        }
        return json(['code' => 200, 'msg' => '加入成功']);
    }

    //提交新商品到三方商品库进行审核
    public function addToThirdGoods()
    {
        $params = request()->post();
        $user = $this->user;
        $data = [
            'code' => $params['code'],
            'image' => $params['image'],
            'title' => $params['title'],
            'img_urls' => implode(',', array_unique($params['img_urls'])),
            'status' => 0,
            'price' => $params['price'],
            'uid' => $user['id'],
            'create_time' => time()
        ];
        $model = new VisionGoodsModel();
        $id = $model->insertGetId($data);
        $third_data = [
            'sku_id' => $id,
            'sku_name' => $params['title'],
            'barcode' => $params['code'],
            'img_urls' => $params['img_urls'],
            'notify_url' => 'http://api.feishi.vip/applet/visual_cabinet_notify/index',
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
}

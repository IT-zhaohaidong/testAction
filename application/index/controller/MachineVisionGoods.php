<?php

namespace app\index\controller;

use app\index\model\MachineVisionGoodsModel;
use app\index\model\VisionGoodsModel;

class MachineVisionGoods extends BaseController
{
    //设备商品列表
    public function getGoodsList()
    {
        $id = request()->get('id');
        if (!$id) {
            return json(['code' => 100, 'msg' => '缺少参数']);
        }
        $device = (new \app\index\model\MachineDevice())->find($id);
        $model = new MachineVisionGoodsModel();
        $list = $model->alias('d')
            ->join('vision_goods g', 'd.goods_id=g.id', 'left')
            ->where('d.device_sn', $device['device_sn'])
            ->field('d.*,g.image,g.title')
            ->order('id asc')
            ->select();
        return json(['code' => 200, 'data' => $list]);
    }

    //获取商品列表
    public function getGoods()
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
            ->where('g.status', 1)
            ->count();

        $list = $model->alias('g')
            ->join('system_admin a', 'a.id=g.uid', 'left')
            ->where($where)
            ->where('g.status', 1)
            ->field('g.*,a.username')
            ->page($page)
            ->limit($limit)
            ->order('id desc')
            ->select();
        return json(['code' => 200, 'data' => $list, 'params' => $params, 'count' => $count]);
    }

    //保存设备商品信息
    public function save()
    {
        $data = request()->post('data/a');
        $device_sn = request()->post('device_sn');
        if (!$device_sn) {
            return json(['code' => 100, 'msg' => '缺少参数']);
        }
        $model = new MachineVisionGoodsModel();

        if (!$data) {
            $model->where('device_sn', $device_sn)->delete();
            return json(['code' => 200, 'msg' => '成功']);
        }
        $ids = [];
        $insert_data = [];
        foreach ($data as $k => $v) {
            if (!($v['id'])) {
                $ids[] = $v['id'];
            }
            $insert_data[] = [
                'device_sn' => $device_sn,
                'goods_id' => $v['goods_id'],
                'price' => $v['price'],
                'stock' => $v['stock'],
                'id' => $v['id'],
            ];
        }
        if ($ids) {
            $model->whereNotIn('id', $ids)->where('device_sn', $device_sn)->delete();
        }
//        $stock = $model->whereIn('id', $ids)->column('device_sn,num,goods_id,stock', 'id');
//        $stockModel = new MachineStockLogModel();
//        $stock_log = [];
        $newGoods = [];
        foreach ($insert_data as $k => $v) {
            if ($v['id']) {
//                if ($v['stock'] != $stock[$v['id']]['stock'] || $v['goods_id'] != $stock[$v['id']]['goods_id']) {
//                    $v['update_time'] = time();
//                } else {
//                    unset($v['update_time']);
//                }
                $model->where('id', $v['id'])->update($v);
//                if ($v['goods_id'] != $stock[$v['id']]['goods_id']) {
//                    $log = [
//                        [
//                            'uid' => $user['id'],
//                            'device_sn' => $device_sn,
//                            'num' => $v['num'],
//                            'old_stock' => $stock[$v['id']]['stock'],
//                            'goods_id' => $stock[$v['id']]['goods_id'],
//                            'new_stock' => 0,
//                            'change_detail' => '更改货道商品,清空当前库存'
//                        ], [
//                            'uid' => $user['id'],
//                            'device_sn' => $device_sn,
//                            'num' => $v['num'],
//                            'goods_id' => $v['goods_id'],
//                            'old_stock' => 0,
//                            'new_stock' => $v['stock'],
//                            'change_detail' => '更改货道商品,库存增加' . $v['stock'] . '件'
//                        ]
//                    ];
//                    $stock_log = array_merge($stock_log, $log);
//                } else {
//                    if ($stock[$v['id']]['stock'] == $v['stock']) {
//                        continue;
//                    }
//                    if ($stock[$v['id']]['stock'] > $v['stock']) {
//                        $change = intval($stock[$v['id']]['stock']) - intval($v['stock']);
//                        $change_detail = '补货,库存减少' . $change . '件';
//                    } else {
//                        $change = intval($v['stock']) - intval($stock[$v['id']]['stock']);
//                        $change_detail = '补货,库存增加' . $change . '件';
//                    }
//                    $stock_log[] = [
//                        'uid' => $user['id'],
//                        'device_sn' => $device_sn,
//                        'num' => $v['num'],
//                        'old_stock' => $stock[$v['id']]['stock'],
//                        'goods_id' => $stock[$v['id']]['goods_id'],
//                        'new_stock' => $v['stock'],
//                        'change_detail' => $change_detail
//                    ];
//                }
            } else {
                unset($v['id']);
                $newGoods[] = $v;
//                $stock_log[] = [
//                    'uid' => $user['id'],
//                    'device_sn' => $device_sn,
//                    'num' => $v['num'],
//                    'old_stock' => 0,
//                    'goods_id' => $v['goods_id'],
//                    'new_stock' => $v['stock'],
//                    'change_detail' => '货道添加商品,库存增加' . $v['stock'] . '件'
//                ];
            }
        }
        $model->saveAll($newGoods);
//        $stockModel->saveAll($stock_log);
        return json(['code' => 200, 'msg' => '成功']);
    }

    //清理商品
    public function clearGoods()
    {
        $id = request()->get('id');
        if (!$id) {
            return json(['code' => 100, 'msg' => '缺少参数']);
        }
        $device = (new \app\index\model\MachineDevice())->find($id);
        $model = new MachineVisionGoodsModel();
        $model->where('device_sn', $device['device_sn'])->delete();
        return json(['code' => 200, 'msg' => '清除成功']);
    }
}

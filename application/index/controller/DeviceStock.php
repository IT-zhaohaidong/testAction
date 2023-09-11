<?php

namespace app\index\controller;

use app\index\model\MachineStockLogModel;
use app\index\model\OrderGoods;
use think\Db;

class DeviceStock extends BaseController
{
    public function getList()
    {
        $params = request()->get();
        $page = request()->get('page', 1);
        $limit = request()->get('limit', 15);
        if (empty($params['device_sn'])) {
            return json(['code' => 100, 'msg' => '缺少参数']);
        }
        $where['log.device_sn'] = ['=', $params['device_sn']];
        if (!empty($params['num'])) {
            $where['log.num'] = ['=', $params['num']];
        }

        if (!empty($params['username'])) {
            $where['a.username'] = ['like', '%' . $params['username'] . '%'];
        }

        if (!empty($params['title'])) {
            $where['g.title'] = ['like', '%' . $params['title'] . '%'];
        }
        if (!empty($params['keywords'])) {
            $where['g.title|a.username|log.num'] = ['like', '%' . $params['keywords'] . '%'];
        }
        $model = new MachineStockLogModel();
        $count = $model->alias('log')
            ->join('system_admin a', 'a.id=log.uid', 'left')
            ->join('mall_goods g', 'g.id=log.goods_id', 'left')
            ->where($where)
            ->count();
        $list = $model->alias('log')
            ->join('system_admin a', 'a.id=log.uid', 'left')
            ->join('mall_goods g', 'g.id=log.goods_id', 'left')
            ->where($where)
            ->field('log.*,a.username,g.title,g.image')
            ->page($page)
            ->limit($limit)
            ->order('log.id desc')
            ->select();
        return json(['code' => 200, 'data' => $list, 'count' => $count, 'params' => $params]);
    }
}
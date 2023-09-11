<?php

namespace app\index\controller;

use app\index\model\VisionOrderModel;

//视觉柜我的商品
class VisionOrder extends BaseController
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
            $where['o.uid'] = $user['id'];
        }
        if (!empty($params['order_sn'])) {
            $where['o.order_sn'] = ['like', '%' . $params['order_sn'] . '%'];
        }
//        if (!empty($params['code'])) {
//            $where['g.code'] = ['like', '%' . $params['code'] . '%'];
//        }
        if (!empty($params['username'])) {
            $where['a.username'] = ['like', '%' . $params['username'] . '%'];
        }
        $model = new VisionOrderModel();
        $count = $model->alias('o')
            ->join('system_admin a', 'a.id=o.uid', 'left')
            ->where($where)
            ->count();

        $list = $model->alias('o')
            ->join('system_admin a', 'a.id=o.uid', 'left')
            ->where($where)
            ->field('o.*,a.username')
            ->page($page)
            ->limit($limit)
            ->order('o.id desc')
            ->select();
        return json(['code' => 200, 'data' => $list, 'params' => $params, 'count' => $count]);

    }
}

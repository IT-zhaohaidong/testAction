<?php

namespace app\index\controller;

use app\index\model\TransferDeviceModel;
use app\index\model\TransferDeviceSystemModel;

//中转分流系统
class DeviceSystem extends BaseController
{
    public function getList()
    {
        $params = request()->get();
        $page = request()->get('page', 1);
        $limit = request()->get('limit', 15);
        $model = new TransferDeviceSystemModel();
        $count = $model->count();
        $list = $model
            ->page($page)
            ->limit($limit)
            ->order('id desc')
            ->select();
        return json(['code' => 200, 'data' => $list, 'count' => $count, 'params' => $params]);
    }

    public function save()
    {
        $params = request()->post();
        if (empty($params['username']) || empty($params['url'])) {
            return json(['code' => 100, 'msg' => '缺少参数']);
        }
        $model = new TransferDeviceSystemModel();
        $data = [
            'username' => $params['username'],
            'url' => $params['url'],
            'remark' => $params['remark'],
        ];
        if (empty($params['id'])) {
            $row = $model->where('username', $data['username'])->find();
            if ($row) {
                return json(['code' => 100, 'msg' => '该系统已存在']);
            }
            $model->save($data);
        } else {
            $row = $model->where('id', '<>', $params['id'])->where('username', $data['username'])->find();
            if ($row) {
                return json(['code' => 100, 'msg' => '该系统已存在']);
            }
            $model->where('id', $params['id'])->update($data);
        }
        return json(['code' => 200, 'msg' => '保存成功']);
    }

    public function del()
    {
        $id = request()->get('id', '');
        if (!$id) {
            return json(['code' => 100, 'msg' => '缺少参数']);
        }
        $model = new TransferDeviceSystemModel();
        $row = (new TransferDeviceModel())->where('uid', $id)->find();
        if ($row) {
            return json(['code' => 100, 'msg' => '该系统下存在设备,不可删除']);
        }
        $model->where('id', $id)->delete();
        return json(['code' => 200, 'msg' => '删除成功']);
    }
}

<?php

namespace app\index\controller;

use app\index\model\TransferDeviceModel;
use app\index\model\TransferDeviceSystemModel;

//中转设备
class TransferDevice extends BaseController
{
    public function getList()
    {
        $params = request()->get();
        $model = new TransferDeviceModel();
        $where = [];
        if (!empty($params['device_sn'])) {
            $where['d.device_sn|d.imei'] = ['like', '%' . $params['device_sn'] . '%'];
        }
        $count = $model->alias('d')
            ->join('device_system s', 'd.uid=s.id', 'left')
            ->where($where)
            ->field('d.*,s.username')
            ->count();
        $list = $model->alias('d')
            ->join('device_system s', 'd.uid=s.id', 'left')
            ->where($where)
            ->field('d.*,s.username')
            ->page($params['page'])
            ->limit($params['limit'])
            ->order('d.id desc')
            ->select();
        $arr = [1 => '国内新中转', 2 => '国外中转', 3 => '国内芯夏', 4 => '芯夏测试中转'];
        foreach ($list as $k => $v) {
            $list[$k]['transfer_name'] = isset($arr[$v['transfer']]) ? $arr[$v['transfer']] : '';
        }
        return json(['code' => 200, 'data' => $list, 'count' => $count, 'params' => $params]);
    }

    public function getSystemList()
    {
        $model = new TransferDeviceSystemModel();
        $list = $model
            ->order('id desc')
            ->field('id,username')
            ->select();
        return json(['code' => 200, 'data' => $list]);
    }

    //保存,分流
    public function saveDevice()
    {
        $params = request()->post();
        $row = (new TransferDeviceModel())->where('id', $params['id'])->find();
        $url = (new TransferDeviceSystemModel())->where('id', $params['uid'])->value('url');
        $data = [
            'imei' => $row['imei'],
            'url' => $url,
            'deviceNumber' => $row['device_sn']
        ];
        //售卖机
        if ($row['transfer'] == 1) {
            $url = 'http://feishi.feishi.vip:9100/api/vending/resetUrl';
        } elseif ($row['transfer'] == 2) {
            $url = 'http://transfer.feishi.vip:9100/api/vending/resetUrl';
        } elseif ($row['transfer'] == 3) {
            $url = "http://feishi.feishi.vip:9100/api/sinShine/goodsOut";
        } elseif ($row['transfer'] == 4) {
            $url = "http://121.40.60.106:9100/api/sinShine/goodsOut";
        } else {
            return json(['code' => 100, 'msg' => '该中转未被记录']);
        }
        https_request($url, $data);
        (new TransferDeviceModel())->where('id', $params['id'])->update(['uid' => $params['uid']]);
        return json(['code' => 200, 'msg' => '分流成功']);
    }
}

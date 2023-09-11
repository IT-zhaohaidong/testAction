<?php

namespace app\index\controller;


use app\index\model\MachineSignalLogModel;

class MachineSignal extends BaseController
{
    //获取设备信号日志
    public function getList()
    {
        $params = request()->get();
        $device_sn = request()->get('device_sn', '');
        if (!$device_sn) {
            return json(['code' => 100, 'msg' => '缺少参数']);
        }
        $page = empty($params['page']) ? 1 : $params['page'];
        $limit = empty($params['limit']) ? 15 : $params['limit'];
        $model = new MachineSignalLogModel();
        $count = $model
            ->where('device_sn', $device_sn)
            ->count();
        $list = $model
            ->where('device_sn', $device_sn)
            ->page($page)->limit($limit)
            ->order('id desc')
            ->select();
        foreach ($list as $k => $v) {

            if ($v['signal'] == 0) {
                $bin = "未检测到SIM卡";
            } else if ($v['signal'] > -75) {
                $bin = "很好";
            } else if ($v['signal'] > -85) {
                $bin = "正常";
            } else if ($v['signal'] > -95) {
                $bin = "一般";
            } else if ($v['signal'] > -100) {
                $bin = "很差";
            } else {
                $bin = "错误";
            }
            $list[$k]['signal'] = $v['signal'] . '(' . $bin . ')';
        }
        return json(['code' => 200, 'data' => $list, 'count' => $count, 'params' => $params]);
    }
}

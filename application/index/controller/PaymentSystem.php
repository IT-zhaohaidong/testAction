<?php


namespace app\index\controller;


use app\index\model\PaymentSystemModel;
use function AlibabaCloud\Client\value;

class PaymentSystem extends BaseController
{
    public static $system = [
        ['label' => '硬币', 'value' => 1],
        ['label' => '纸币', 'value' => 2],
        ['label' => '百富', 'value' => 3],
        ['label' => 'NaYax', 'value' => 4],
    ];

    public function getList()
    {
        $params = request()->get();
        $limit = request()->get('limit', 15);
        $page = request()->get('page', 1);
        $user = $this->user;
        $where = [];
        if ($user['role_id'] != 1) {
            if ($user['role_id'] > 5) {
                $user['id'] = $user['parent_id'];
            }
            $where['d.uid'] = ['=', $user['id']];
        }
        if (!empty($params['username'])) {
            $where['a.username'] = ['like', '%' . $params['username'] . '%'];
        }
        if (!empty($params['device'])) {
            $where['d.device_sn|d.device_name|d.imei'] = ['like', '%' . $params['device'] . '%'];
        }
        $model = new PaymentSystemModel();
        $count = $model->alias('p')
            ->join('machine_device d', 'p.device_id=d.id', 'left')
            ->join('system_admin a', 'a.id=d.uid', 'left')
            ->where($where)
            ->field('p.*,a.username,d.device_sn,d.device_name,d.imei')
            ->count();
        $list = $model->alias('p')
            ->join('machine_device d', 'p.device_id=d.id', 'left')
            ->join('system_admin a', 'a.id=d.uid', 'left')
            ->field('p.*,a.username,d.device_sn,d.device_name,d.imei')
            ->where($where)
            ->order('p.id desc')
            ->page($page)
            ->limit($limit)
            ->select();
        foreach ($list as $k => $v) {
            $list[$k]['pay_system_ids'] = $v['pay_system_ids'] ? explode(',', $v['pay_system_ids']) : [];
        }
        return json(['code' => 200, 'data' => $list, 'count' => $count, 'params' => $params]);
    }

    public function getDevice()
    {
        $device = (new \app\index\model\MachineDevice())
            ->order('id desc')
            ->field('id,device_sn,device_name')
            ->select();
        foreach ($device as $k => $v) {
            $device[$k]['device_sn'] = $v['device_sn'] . '(' . $v['device_name'] . ')';
        }
        $system = self::$system;
        $data = compact('device', 'system');
        return json(['code' => 200, 'data' => $data]);
    }

    public function savePayment()
    {
        $params = request()->post();
        $pay_system = '';
        $system = array_column(self::$system, 'label', 'value');
        for ($i = 1; $i <= count($params['pay_system_ids']); $i++) {
            $pay_system .= isset($system[$params['pay_system_ids'][$i - 1]]) ? $system[$params['pay_system_ids'][$i - 1]] : '';
        }
        $data = [
            'device_id' => $params['device_id'],
            'pay_system_ids' => implode(',', $params['pay_system_ids']),
            'pay_system' => $pay_system,
            'system_sn' => $params['system_sn'],
        ];
        $model = new PaymentSystemModel();
        if (!empty($params['id'])) {
            $model->where('id', $params['id'])->update($data);
        } else {
            $model->save($data);
        }
        return json(['code' => 200, 'msg' => '保存成功']);
    }

}

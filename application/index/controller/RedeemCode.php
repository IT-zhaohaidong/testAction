<?php


namespace app\index\controller;


use app\index\common\Email;
use app\index\model\RedeemCodeModel;

class RedeemCode extends BaseController
{
    public function getList()
    {
        $params = request()->get();
        $page = empty($params['page']) ? 1 : $params['page'];
        $limit = empty($params['limit']) ? 15 : $params['limit'];
        $user = $this->user;
        $where = [];
        if ($user['role_id'] != 1) {
            $where['uid'] = ['=', $user['id']];
        }
        if (!empty($params['phone'])) {
            $where['phone'] = ['like', "%" . $user['phone'] . "%"];
        }
        if (!empty($params['name'])) {
            $where['name'] = ['like', "%" . $user['name'] . "%"];
        }
        if (!empty($params['code'])) {
            $where['code'] = ['like', "%" . $user['code'] . "%"];
        }
        if (isset($params['status']) && $params['status'] !== '') {
            $where['status'] = ['=', $params['status']];
        }
        $model = new RedeemCodeModel();
        $count = $model
            ->where($where)
            ->count();
        $list = $model
            ->where($where)
            ->page($page)
            ->limit($limit)
            ->order('status asc')
            ->order('id desc')
            ->select();
        return json(['code' => 200, 'data' => $list, 'count' => $count, 'params' => $params]);
    }

    public function addCode()
    {
        $params = request()->post();
        if (empty($params['phone']) || empty($params['email'])) {
            return json(['code' => 100, 'msg' => '缺少参数']);
        }
        $model = new RedeemCodeModel();
        $row = $model->where('phone', $params['phone'])->find();
        if ($row) {
            return json(['code' => 100, 'msg' => '手机号已存在']);
        }
        $user = $this->user;
        $data = [
            'name' => $params['name'],
            'area_code' => $params['area_code'],
            'phone' => $params['phone'],
            'email' => $params['email'],
            'uid' => $user['id'],
            'code' => $this->getCode()
        ];
        $model = new RedeemCodeModel();
        $model->save($data);
//        if ($params['email']) {
//            $title = 'Exchange code issuing notice';
//            $body = "Your exchange code is " . $data['code'] . ", please go to the facility to redeem goods";
//            $address = $params['email'];
//            (new Email())->send_email($title, $body, $address);
//        }
        return json(['code' => 200, 'msg' => '添加成功']);
    }

    //编辑
    public function editCode()
    {
        $params = request()->post();
        if (empty($params['id'])) {
            return json(['code' => 100, 'msg' => '手机号已存在']);
        }
        $model = new RedeemCodeModel();
        $code = $model->where('id', $params['id'])->find();
        $row = $model
            ->where('id', '<>', $params['id'])
            ->where('phone', $params['phone'])
            ->find();
        if ($row) {
            return json(['code' => 100, 'msg' => '手机号已存在']);
        }
        $data = [
            'name' => $params['name'],
            'area_code' => $params['area_code'],
            'phone' => $params['phone'],
            'email' => $params['email'],
        ];
        $model->where('id', $params['id'])->update($data);
//        if ($code['email'] != $params['email']) {
//            $title = 'Exchange code issuing notice';
//            $body = "Your exchange code is " . $row['code'] . ", please go to the facility to redeem goods";
//            $address = $params['email'];
//            (new Email())->send_email($title, $body, $address);
//        }
        return json(['code' => 200, 'msg' => '成功']);
    }

    //生成码
    public function createCode()
    {
        $user = $this->user;
        $data = [
            'uid' => $user['id'],
            'code' => $this->getCode()
        ];
        $model = new RedeemCodeModel();
        $model->save($data);
        return json(['code' => 200, 'msg' => '添加成功']);
    }

    //生成一个合法的code
    public function getCode()
    {
        $code = rand(100000, 999999);
        $model = new RedeemCodeModel();
        //半年前的时间
        $time = time() - 180 * 24 * 3600;
        $row = $model->where('code', $code)->find();
        if ($row) {
            if ($row['status'] == 0 || ($row['status'] == 1 && strtotime($row['create_time']) < $time)) {
                $this->getCode();
            }
        }
        return $code;
    }
}

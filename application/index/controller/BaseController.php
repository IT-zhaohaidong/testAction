<?php

namespace app\index\controller;

use think\App;
use think\Cache;
use think\Collection;
use think\Db;

class BaseController extends Collection
{
    public $token;
    public $user = [];

    public function __construct(App $app)
    {
        parent::__construct($app);
        if (empty($_SERVER['HTTP_TOKEN'])) {
            echo json_encode(["code" => 400, "msg" => "token不存在"]);
            exit();
        }
        $token = $_SERVER['HTTP_TOKEN'];
        $token_str = 'tokenInfo_' . $token;
        $info = Cache::store('redis')->get($token_str);
        if ($info) {
            $token_info = $info;
        } else {
            $token_info = Db::name("system_login")->where("token", $token)->where("expire_time", '>', time())->find();
            Cache::store('redis')->set($token_str, $token_info, 7200);
        }

        if (empty($token_info) || $token_info['expire_time'] < time()) {
            echo json_encode(["code" => 400, "msg" => "登录状态已失效"]);
            exit();
        }
        $str = 'loginUser_' . $token_info['uid'];
        $user = Cache::store('redis')->get($str);
        if ($user) {
            $this->user = $user;
        } else {
            $this->user = Db::name('system_admin')->where('id', $token_info['uid'])->find();
            Cache::store('redis')->set($str, $user, 7200);
        }
        if ($this->user['account_type'] == 1) {
            if ($this->user['expire_time']) {
                if (time() > $this->user['expire_time']) {
                    return json(['code' => 400, 'msg' => '该测试账号已过期']);
                }
            }
        }
        $this->token = $token;
    }

    public function getBuHuoWhere()
    {
        $user = $this->user;
        $device_ids = Db::name('machine_device_partner')->where('admin_id', $user['parent_id'])->where('uid', $user['id'])->column('device_id');
        $device_ids = $device_ids ? array_values($device_ids) : [];
        $device_sn = Db::name('machine_device')->whereIn('id', $device_ids)->value('device_sn');
        $device_sn = $device_sn ? array_values($device_sn) : [];

        return ['in', $device_sn];
    }

}

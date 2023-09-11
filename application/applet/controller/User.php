<?php

namespace app\applet\controller;

use app\index\model\MachineDevice;
use app\index\model\OperateUserModel;
use app\index\model\SystemAdmin;
use think\Controller;

class User extends Controller
{
    /**
     * 获取用户信息
     */
    public function getUserInfo()
    {
        $openid = request()->get("openid", "");
        $device_sn = request()->get("device_sn", "");
        if (empty($openid)) {
            return json(['code' => 400, 'msg' => '缺少参数']);
        }
        $userObj = new OperateUserModel();
        $info = $userObj
            ->field("id,nickname,sex,photo,openid,uid,phone")
            ->where("openid", $openid)->find();
        if ($info) {
            if (!empty($device_sn) && empty($info['uid'])) {
                $uid = (new MachineDevice())->where('device_sn', $device_sn)->value('uid');
                $userObj->where('id', $info['id'])->update(['uid' => $uid]);
            }
            $data = [
                "code" => 200,
                "msg" => "获取成功",
                "data" => $info
            ];
        } else {
            $data = [
                "code" => 400,
                "msg" => "用户不存在",
                "data" => $info
            ];
        }
        return json($data);
    }

    //代理商扫码绑定提现账户
    public function bindAdmin()
    {
        $post = request()->post();
        if (empty($post['openid']) || empty($post['uid'])) {
            return json(['code' => 100, 'msg' => '缺少参数']);
        }
        (new SystemAdmin())->where('id', $post['uid'])->update(['openid' => $post['openid']]);
        return json(['code' => 200, 'msg' => '绑定成功,可以提现啦!']);
    }

}

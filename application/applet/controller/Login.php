<?php

namespace app\applet\controller;

use app\index\model\MachineDevice;
use app\index\model\OperateUserModel;
use app\index\model\SystemAdmin;
use think\Controller;
use think\Db;


class Login extends Controller
{
    protected $appid = "wx6fd3c40b45928f43";
    protected $appsecret = "c874d23fbdd1b240b6e14753adb3c748";

    /**
     *  获取用户openid
     */
    public function getOpenid()
    {
        $code = request()->get("code", "");
        $url = "https://api.weixin.qq.com/sns/jscode2session?appid={$this->appid}&secret={$this->appsecret}&js_code={$code}&grant_type=authorization_code";
        $content = https_request($url);
        $content = json_decode($content, true);
        unset($content['session_key']);
        return json($content);
    }

    /**
     * 登录 保存用户信息
     */
    public function login()
    {
        $data = request()->post();
        trace($data, '用户信息');
        $user_obj = new OperateUserModel();
        if (strripos($data['openid'], 'SELECT')) {
            return json(['code' => 100, 'msg' => '非法攻击']);
        }

        $user_info = $user_obj->where("openid", $data['openid'])->find();
        $data['nickname'] = !empty($data['nickname']) ? $this->emoji2str($data['nickname']) : '支付宝用户';
        if ($user_info) {
            $user_obj->where("openid", $data['openid'])->update($data);
        } else {
            $user_obj->save($data);
        }
        return json(['code' => 200, 'msg' => '登录成功']);
    }

    /**
     * 获取用户手机号
     */
    public function getPhone()
    {
        $post = $this->request->post();
        $url = "https://api.weixin.qq.com/sns/jscode2session?appid={$this->appid}&secret={$this->appsecret}&js_code={$post['code']}&grant_type=authorization_code";
        $content = https_request($url);
        $content = json_decode($content, true);
        if (!isset($content['session_key'])) {
            return json($content);
        }
        $sessionKey = $content['session_key'];
        trace($content, '获取手机号的sessionKey');
        $encryptedData = $post['encryptedData'];
        $iv = $post['iv'];
        $data = "";
        $pc = new \app\applet\controller\WXBizDataCrypt($this->appid, $sessionKey);
        $errCode = $pc->decryptData($encryptedData, $iv, $data);
        $data = json_decode($data, true);
        $phone = $data['purePhoneNumber'];
        trace($errCode, '获取手机号状态码');
        trace($data, '获取手机号结果');
        if ($errCode == 0) {
            $arr = ["openid" => $content['openid'], 'phone' => $phone];
            $obj = new OperateUserModel();
            $info = $obj->where('openid', $arr['openid'])->find();
            if ($info) {
                $obj->where('id', $info['id'])->update($arr);
            } else {
                $obj->save($arr);
            }
            $data = [
                "code" => 200,
                "msg" => "获取成功",
                'phone' => $phone,
                'openid' => $content['openid']
            ];
            return json($data);
        } else {
            return json(['code' => 100, 'msg' => '获取失败']);
        }
    }

    function emoji2str($str)
    {
        $strEncode = '';
        $length = mb_strlen($str, 'utf-8');
        for ($i = 0; $i < $length; $i++) {
            $_tmpStr = mb_substr($str, $i, 1, 'utf-8');
            if (strlen($_tmpStr) >= 4) {
                $strEncode .= '??';
            } else {
                $strEncode .= $_tmpStr;
            }
        }
        return $strEncode;
    }


}

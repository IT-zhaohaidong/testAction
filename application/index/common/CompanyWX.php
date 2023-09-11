<?php

namespace app\index\common;

use think\Cache;
use think\Db;

/*
 * 1.从企业微信获取secret和corId
 * 2.在企业微信管理后台的“客户联系-客户”页面，点开“API”小按钮，再点击“接收事件服务器”配置 事件回调
 */

class CompanyWX
{
    public $secret;//开发秘钥
    public $corId;//企业id

    public function __construct($corId, $secret)
    {
        $this->secret = $secret;
        $this->corId = $corId;
    }

    //获取token
    public function getToken()
    {
        $token = Cache::store('redis')->get($this->corId);
        if ($token) {
            return $token;
        } else {
            $url = "https://qyapi.weixin.qq.com/cgi-bin/gettoken?corpid={$this->corId}&corpsecret={$this->secret}";
            $token = https_request($url);
            $token = json_decode($token, true);
            if ($token['errcode'] == 0) {
                Cache::store('redis')->set($this->corId, $token['access_token'], 7100);
                return $token['access_token'];
            } else {
                exit($token);
            }

        }
    }

    //创建用户二维码
    public function createCode($username, $device_sn)
    {
        $token = $this->getToken();
        $data = [
            "type" => 1,
            "scene" => 2,
            "remark" => "渠道客户",
            "state" => $device_sn,
            "user" => [$username],
            "is_temp" => false,
            "is_exclusive" => true,
        ];
        $url = "https://qyapi.weixin.qq.com/cgi-bin/externalcontact/add_contact_way?access_token=$token";
        $result = https_request($url, json_encode($data));
        trace($result, '创建企业微信二维码');
        $res = json_decode($result, true);
        if ($res['errcode'] == 0) {
            return ['code' => 200, 'data' => $res];
        } else {
            return ['code' => 100, 'msg' => '二维码创建失败'];
        }
    }

    //删除联系我二维码
    public function delCode($config_id)
    {
        $token = $this->getToken();
        $data = [
            "config_id" => $config_id,
        ];
        $url = "https://qyapi.weixin.qq.com/cgi-bin/externalcontact/del_contact_way?access_token=$token";
        $result = https_request($url, json_encode($data));
        trace($result, '删除联系我');
        $res = json_decode($result, true);
        if ($res['errcode'] == 0) {
            return ['code' => 200, 'data' => $res];
        } else {
            return ['code' => 100, 'msg' => '删除失败'];
        }
    }

    //获取企业用户
    public function getUser()
    {
        $token = $this->getToken();
        $url = "https://qyapi.weixin.qq.com/cgi-bin/externalcontact/get_follow_user_list?access_token=$token";
        $res = https_request($url);
        $result = json_decode($res, true);
        trace($result, '获取企业用户');
        if ($result['errcode'] == 0) {
            return ['code' => 200, 'list' => $result['follow_user']];
        } else {
            return ['code' => 100, 'msg' => $res];
        }
    }

    //发送欢迎语
    public function sendMsg($welcome_code, $page,$media_id)
    {
        $token = $this->getToken();
        $url = "https://qyapi.weixin.qq.com/cgi-bin/externalcontact/send_welcome_msg?access_token=$token";
        $data = [
            "welcome_code" => $welcome_code,
            "attachments" => [
                [
                    "msgtype" => "miniprogram",
                    "miniprogram" => [
                        "title" => "点击购买商品",//todo 小程序标题
                        "pic_media_id" => $media_id,
                        "appid" => "wx6fd3c40b45928f43",//todo 跳转小程序
                        "page" => $page//todo 跳转页面
                    ]
                ]
            ]
        ];
        $result = https_request($url, json_encode($data));
        trace($result, '发送欢迎语结果');
    }

    public function getClientDetail($external_userid)
    {
        $token = $this->getToken();
        $url = "https://qyapi.weixin.qq.com/cgi-bin/externalcontact/get?access_token=$token&external_userid=$external_userid";
        $res = https_request($url);
        $result = json_decode($res, true);
        trace($result, '获取外部用户');
        if ($result['errcode'] == 0) {
            return ['code' => 200, 'list' => $result];
        } else {
            return ['code' => 100, 'msg' => $res];
        }
    }

    public function uploadImg($file_path)
    {
        $token = $this->getToken();
        // 设置企业微信API的相关参数
        $api_url = "https://qyapi.weixin.qq.com/cgi-bin/media/upload?access_token=$token&type=image";
        // 执行上传操作
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, array('media' => new \CURLFile($file_path)));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);
        // // 解析响应并获取上传后的图片URL
        $response_data = json_decode($response, true);
        trace($response_data, '企业微信上传文件结果');
        if ($response_data['errcode'] != 0) {
            return '文件上传失败';
        } else {
            return $response_data['media_id'];
        }
    }


}
<?php

namespace app\applet\controller;

use app\index\common\CompanyWX;
use app\index\model\CompanyWxModel;

include_once dirname(dirname(dirname(dirname(__FILE__)))) . '/vendor/company/WXBizMsgCrypt.php';

/*
 * 企业微信事件回调
 */

class CompanyNotify
{
    public function index()
    {
        //广州圣伊卡罗化妆品有限公司 todo 当新企业注册时,更换以下三个数据
        $token = 'yw2sz0lPLiGExTPxWHUVJjrwmSqt5D';
        $encodingAesKey = 'sLwQJXCCrt64OcMojKLFSRcr5Ng9XgeRSDoD7jz64Hm';
        $corpId = 'ww72615244fdf045b0';
        $wxcpt = new \WXBizMsgCrypt($token, $encodingAesKey, $corpId);
        if (request()->isGet()) {
            $echostr = request()->get('echostr');
            $msg_signature = request()->get('msg_signature');
            $timestamp = request()->get('timestamp');
            $nonce = request()->get('nonce');
            $sEchoStr = "";
            $errCode = $wxcpt->VerifyURL($msg_signature, $timestamp, $nonce, $echostr, $sEchoStr);
            trace($errCode, '企业微信验证');
            if ($errCode == 0) {
                trace($sEchoStr, '验证返回值');
                (new CompanyWxModel())->where('corId', $corpId)->update(['is_notify'=> 1]);
                // 验证URL成功，将sEchoStr返回
//                 HttpUtils.SetResponce($sEchoStr);
                echo $sEchoStr;
            } else {
                trace($errCode, 'url验证失败');
            }
        } else {
            $data = file_get_contents("php://input");
            trace($data, '事件回调');
            $url_params = $_SERVER['QUERY_STRING'];
            $params = $this->getParams($url_params);
            $sMsg = "";  // 解析之后的明文
            $errCode = $wxcpt->DecryptMsg($params['msg_signature'], $params['timestamp'], $params['nonce'], $data, $sMsg);
            if ($errCode == 0) {
                // 解密成功，sMsg即为xml格式的明文
                trace($sMsg, '解密之后的xml');
                $res = $this->xml2array($sMsg);
                trace($res, 'xml转数组');
                if (isset($res['Event']) && $res['Event'] == 'change_external_contact') {
                    trace('成功啦');
                    $corId = $res['ToUserName'];
                    $userId = $res['UserID'];//企业服务人员id
                    $externalUserID = $res['ExternalUserID'];//外部联系人id
                    if (isset($res['WelcomeCode'])) {
                        //根据企业用户id获取设备号,构建小程序路径
                        $companyModel = new CompanyWxModel();
                        $row = $companyModel->where('corId', $corId)->find();
                        $companyWx = new CompanyWX($corId, $row['secret']);
                        $user = $companyWx->getClientDetail($externalUserID);
                        if ($user['code'] == 200) {
                            $is_company = $row['is_form'];
                            $device_sn = $this->getState($userId, $user['list']['follow_user']);
                            $page = "/pages/index/index?device={$device_sn}&type=1&is_company={$is_company}";
                            trace($page,'小程序链接');
                            $media_id = $row['media_id'];
                            $companyWx->sendMsg($res['WelcomeCode'], $page, $media_id);
                        }
                    }

                }
            } else {
                print("ERR: " . $errCode . "\n\n");
                trace($errCode, '事件回调解析失败');
            }
        }

    }

    public function getState($userId, $user)
    {
        $state = '';
        foreach ($user as $k => $v) {
            if ($v['userid'] == $userId) {
                $state = $v['state'];
                break;
            }
        }
        return $state;
    }

    //----------------公用函数----------------------
    public function getParams($url_params)
    {
        $url = urldecode($url_params);
        $params = explode('&', $url);
        unset($params[0]);
        $arr = [];
        foreach ($params as $k => $v) {
            $single_params = explode('=', $v);
            if ($single_params[0] == 'echostr') {
                $arr[$single_params[0]] = $single_params[1] . '==';
            } else {
                $arr[$single_params[0]] = $single_params[1];
            }

        }
        return $arr;
    }

    /**
     * 将xml转为array
     * @param string $xml xml字符串
     * @return array    转换得到的数组
     */
    public function xml2array($xml)
    {
        //禁止引用外部xml实体
        libxml_disable_entity_loader(true);
        $result = json_decode(json_encode(simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
        return $result;
    }
}

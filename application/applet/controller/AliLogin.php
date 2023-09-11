<?php

namespace app\applet\controller;

use think\Controller;
use think\Exception;

class AliLogin extends Controller
{
    public function getOpenid()
    {
        include_once dirname(dirname(dirname(dirname(__FILE__)))) . '/vendor/alipay/aop/AopCertClient.php';
        include_once dirname(dirname(dirname(dirname(__FILE__)))) . '/vendor/alipay/aop/request/AlipaySystemOauthTokenRequest.php';
        $code = request()->get('code', '');
        $app_id = '2021003143688161';
        //应用私钥
        $privateKeyPath = dirname(dirname(dirname(dirname(__FILE__)))) . '/public/cert/api.feishi.vip_私钥.txt';
        $privateKey = file_get_contents($privateKeyPath);

        $aop = new \AopCertClient ();
        $aop->gatewayUrl = 'https://openapi.alipay.com/gateway.do';
        $aop->appId = $app_id;
        $aop->rsaPrivateKey = $privateKey;
        //支付宝公钥证书
        $aop->alipayPublicKey = dirname(dirname(dirname(dirname(__FILE__)))) . '/public/cert/alipayCertPublicKey_RSA2.crt';

        $aop->apiVersion = '1.0';
        $aop->signType = 'RSA2';
        $aop->postCharset = 'UTF-8';
        $aop->format = 'json';
        //调用getCertSN获取证书序列号
        $appPublicKey = dirname(dirname(dirname(dirname(__FILE__)))) . "/public/cert/appCertPublicKey_2021003143688161.crt";
        $aop->appCertSN = $aop->getCertSN($appPublicKey);
        //支付宝公钥证书地址
        $aliPublicKey = dirname(dirname(dirname(dirname(__FILE__)))) . "/public/cert/alipayRootCert.crt";;
        $aop->alipayCertSN = $aop->getCertSN($aliPublicKey);
        //调用getRootCertSN获取支付宝根证书序列号
        $rootCert = dirname(dirname(dirname(dirname(__FILE__)))) . "/public/cert/alipayRootCert.crt";
        $aop->alipayRootCertSN = $aop->getRootCertSN($rootCert);

        $request = new \AlipaySystemOauthTokenRequest ();
        $request->setGrantType("authorization_code");
        $request->setCode($code);
        $result = $aop->execute($request);
        trace($result, '支付宝获取openid');
        $responseNode = str_replace(".", "_", $request->getApiMethodName()) . "_response";
        if (!empty($result->$responseNode->code) && $result->$responseNode->code != 10000) {
            return json(['code' => 200, 'msg' => '失败']);
        } else {
            return json(['code' => 200, 'data' => ['openid' => $result->alipay_system_oauth_token_response->user_id]]);
        }
    }

    public function getPublicKey()
    {
        include_once dirname(dirname(dirname(dirname(__FILE__)))) . '/vendor/alipay/aop/AopCertClient.php';
        $aop = new \AopCertClient();
        $alipayCertPath = dirname(dirname(dirname(dirname(__FILE__)))) . "/public/cert/appCertPublicKey_2021003143688161.crt";
        $alipayrsaPublicKey = $aop->getPublicKey($alipayCertPath);
        $oldchar = array("", "　", "\t", "\n", "\r");
        $newchar = array("", "", "", "", "");
        $alipayrsaPublicKey = str_replace($oldchar, $newchar, $alipayrsaPublicKey);
        echo '支付宝公钥证书值' . $alipayrsaPublicKey;
    }
}

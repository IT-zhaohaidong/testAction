<?php

namespace app\index\common;


class Yuepai
{
    private $openId = "1010035|2500628227943";
    private $appId = "483571710549550973";
    private $token = "f801517f-dc1c-4a54-82cc-0aca5b5e2ed7";
    private $key = "inmiwOFonuCBYdaJB3JV24MgyFPqDvMKixjGRhV/LPQw35X+KadVEbXYtlizCbqndwlOWHH4rdc08klL+sNCAA==";
    private $cpId = "nsrrh920230410102508391";

    public function check($order_sn, $userId, $goodsCode)
    {
//        $userId = 'xxx';//从支付宝获取
//        $traceId = 'xxx';//订单号
        $requestId = time() . rand(1000, 9999);
        $base_data = [
            'method' => 'py.qualified.check',
            'version' => '1.0',
            'reqTime' => getMillisecond(),
            'requestId' => $requestId,
            'openId' => $this->openId,
            'appId' => $this->appId,
            'token' => $this->token,
        ];
        $data = [
            'traceId' => $order_sn,
            'cpId' => $this->cpId,
            'userId' => $userId,
            'userType' => 'ALIPAY_OPEN_ID',
            'goodsCode' => $goodsCode
        ];
        $data_json = json_encode($data);
        $sign = $this->getSign($data_json, $requestId);
        $base_data['sign'] = $sign;
        $base_data['data'] = $data_json;
        $url = 'http://open.eguagua.cn/openapi';
        $header = array(
            'Content-Type: application/json',
        );
        $res = https_request($url, json_encode($base_data), $header);
        return json_decode($res, true);
    }

    //订单完成回调乐派
    public function callBack($order)
    {
        $requestId = time() . rand(1000, 9999);
        $base_data = [
            'method' => 'order.success.callback',
            'version' => '1.0.0',
            'reqTime' => getMillisecond(),
            'requestId' => $requestId,
            'openId' => $this->openId,
            'appId' => $this->appId,
            'token' => $this->token,
        ];
        $data = [
            'traceId' => $order['order_sn'],
            'cpId' => $this->cpId,
            'point' => $order['point'],
            'userId' => $order['userId'],
            'userType' => 'ALIPAY_OPEN_ID',
            'payTime' => $order['payTime'],
            'orderTime' => $order['orderTime'],
            'outOrderId' => $order['outOrderId'],
            'goodsCode' => $order['goodsCode'],
            'orderAmount' => $order['orderAmount'],
            'payType' => 'ALIPAY',
            'orderStatus' => '支付成功',
        ];
        $data_json = json_encode($data);
        $sign = $this->getSign($data_json, $requestId);
        $base_data['sign'] = $sign;
        $base_data['data'] = $data_json;
        $url = 'http://open.eguagua.cn/openapi';
        $header = array(
            'Content-Type: application/json',
        );
        $res = https_request($url, json_encode($base_data), $header);
        return json_decode($res, true);
    }

    protected function getSign($data, $requestId)
    {
        $str = $data . $this->openId . $this->appId . $this->token . $requestId;
        return base64_encode(hash_hmac("sha512", $str, base64_decode($this->key), true));
    }

    //曝光数据回传
    public function exposure()
    {
        $requestId = time() . rand(1000, 9999);
        $base_data = [
            'method' => 'py.exposure.callback',
            'version' => '1.0',
            'reqTime' => getMillisecond(),
            'requestId' => $requestId,
            'openId' => $this->openId,
            'appId' => $this->appId,
            'token' => $this->token,
        ];
        $data['list'][] = [
            'id' => 'cs123987564jsj',
            'date' => date('Y-m-d'),
            'cpId' => $this->cpId,
            'goodsCode' => 'PY0298771221896',
            'exposeCount' => 5,
            'point' => '浙江省杭州市萧山区息港，浙江省杭州市钱江世纪城地铁站',
            'pointType' => 'P2'
        ];
        $data_json = json_encode($data);
        $sign = $this->getSign($data_json, $requestId);
        $base_data['sign'] = $sign;
        $base_data['data'] = $data_json;
        $url = 'http://open.eguagua.cn/openapi';
        $header = array(
            'Content-Type: application/json',
        );
        $res = https_request($url, json_encode($base_data), $header);
        return json_decode($res, true);
    }

}
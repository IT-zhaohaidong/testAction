<?php


namespace app\index\common;

use think\Db;

/**
 * 视觉柜接口对接
 * @package app\index\common
 * 参考文档:https://www.yuque.com/u21115010/yt90ss/dnelt4?#
 */
class VisualCabinet
{
    private $secretKey = 'fUUftelQR8hKn9mE3IrEMYr0aaT5Kfbv';
    private $accessKey = '26aa2933b898428E8Ae38qxN';
    private $host = 'retail.bibt.cn';
    private $url = 'https://retail.bibt.cn';
    private $content_type = 'application/json';

    //根据六九码获取商品
    public function getGoodsByCode($code)
    {
        $uri = "/openapi/sc/v1/sku/all_sku/${code}";
        $method_type = 'GET';
        $time_millis = getMillisecond();
//        $query_string = "barcode=${code}";
        $signature = $this->makeSign($this->host, $uri, $this->content_type, $method_type, $time_millis, $this->accessKey, $this->secretKey, '');
        $res = $this->getResult($signature, $time_millis, $uri);
        trace($res, '根据六九码获取商品');
        return $res;
    }

    //根据超级收银台sku_id获取商品
    public function getGoodsBySkuId($sku_id)
    {
        $uri = "/openapi/sc/v1/sku/skus/${sku_id}";
        $method_type = 'GET';
        $time_millis = getMillisecond();
        $signature = $this->makeSign($this->host, $uri, $this->content_type, $method_type, $time_millis, $this->accessKey, $this->secretKey, '');
        $res = $this->getResult($signature, $time_millis, $uri);
        trace($res, '根据超级收银台sku_id获取商品');
        return $res;
    }

    //常规SKU提交审核
    public function putSku($data)
    {
//        $data = [
//            'sku_id' => '3',
//            'sku_name' => '清风原木纯品纸巾',
//            'barcode' => '6922266461712',
//            'img_urls' => ['https://fs-manghe.oss-cn-hangzhou.aliyuncs.com/material/16870659545103.jpg', 'https://fs-manghe.oss-cn-hangzhou.aliyuncs.com/material/16870658764728.jpg'],
//            'notify_url' => 'http://api.feishi.vip/applet/visual_cabinet_notify/index'
//        ];
        $uri = "/openapi/sc/v1/sku/";
//        var_dump('方法uri=>' . $uri);
        $method_type = 'POST';
        $time_millis = getMillisecond();
//        $time_millis = 1687655757320;
        ksort($data);
        $signature = $this->makeSign($this->host, $uri, $this->content_type, $method_type, $time_millis, $this->accessKey, $this->secretKey, '', $data);
        $res = $this->getResult($signature, $time_millis, $uri, $data);
        trace($res, '常规SKU提交审核');
        return $res;
    }

    //删除私有库sku
    public function delSku($ks_sku_id)
    {
        $uri = "/openapi/sc/v1/sku/${ks_sku_id}";
        $method_type = 'DELETE';
        $time_millis = getMillisecond();
        $signature = $this->makeSign($this->host, $uri, $this->content_type, $method_type, $time_millis, $this->accessKey, $this->secretKey, '');
        $header = array(
            'Content-Type:application/json',
            'Authorization:' . $signature,
            'X-Request-Date:' . $time_millis,
            'X-Request-id:' . $time_millis,
            'Referer:https://api.feishi.vip/'
        );
        $url = $this->url . $uri;
        $curl = curl_init();
        // 2.设置你需要抓取的URL
        curl_setopt($curl, CURLOPT_URL, $url);
        // (可选)设置头 阿里云的许多接口需要在头上传输秘钥
        if (!empty($header)) {
            curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
        }
        // 3.https必须加这个，不加不好使（不多加解释，东西太多了）
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false); //对认证证书进行检验
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        //设置请求方式
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "DELETE");
        // 设置cURL 参数，要求结果保存到字符串中还是输出到屏幕上。1是保存，0是输出
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        // 让curl跟随页面重定向
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
        // 5. 运行cURL，请求网页
        $output = curl_exec($curl);
        // 6. 关闭CURL请求
        curl_close($curl);
        trace($output, '删除常规SKU');
        return json_decode($output, true);
    }

    //sku提交识别
    public function recognition($data)
    {
        $uri = '/openapi/sc/v1/recognition';
        $data = [
            'recog_id' => $data['recog_id'],//订单号
            'extra_params' => [
                'origin_time' => $data['origin_time'],//下单时间
                'sku_scope' => $data['sku_scope'],//货柜所有三方sku

            ],
            'container_id' => $data['container_id'],//设备号
            'resource_urls' => $data['resource_urls'],//视频地址
            'notify_url' => $data['notify_url']
        ];
        $method_type = 'POST';
        $time_millis = getMillisecond();
        ksort($data);
        $signature = $this->makeSign($this->host, $uri, $this->content_type, $method_type, $time_millis, $this->accessKey, $this->secretKey, '', $data);
        $res = $this->getResult($signature, $time_millis, $uri, $data);
        trace($res, 'sku提交识别');
        return $res;
    }

    //主动获取sku识别结果
    public function getRecognitionResult($ks_recog_id)
    {
        $uri = "/openapi/sc/v1/recognition/${ks_recog_id}";
        $method_type = 'GET';
        $time_millis = getMillisecond();
        $signature = $this->makeSign($this->host, $uri, $this->content_type, $method_type, $time_millis, $this->accessKey, $this->secretKey, '');
        $res = $this->getResult($signature, $time_millis, $uri);
        trace($res, '主动获取sku识别结果');
        return $res;
    }


    public function getResult($signature, $time_millis, $uri, $data = [])
    {
        $header = array(
            'Content-Type:application/json',
            'Authorization:' . $signature,
            'X-Request-Date:' . $time_millis,
            'X-Request-id:' . $time_millis,
            'Referer:https://api.feishi.vip/'
        );
        $url = $this->url . $uri;
        if ($data) {
            $data = json_encode($data, JSON_UNESCAPED_UNICODE);
            $data = str_replace('\\', '', $data);
        }
//        var_dump('请求url=>' . $url);
//        var_dump('传递的参数=>' . $data);
//        var_dump($header);
        $res = https_request($url, $data, $header);
        return json_decode($res, true);
    }

    public function makeSign($host, $uri, $content_type, $method_type, $time_millis, $access_key, $secret_key, $query_string, $request_body = [])
    {
//        $request_body = [
//            'container_id' => 'xxx',
//            'extra_params' => [
//                'origin_time' => time() . substr(microtime(), 2, 3),
//                'sku_scope' => 'xxx'
//            ],
//            'notify_url' => 'xxx',
//            'recog_id' => 'xxx',
//            'resource_type' => 1,
//            'resource_urls' => 'http://api.feishi.vip'
//        ];
        $body_str = json_encode($request_body, JSON_UNESCAPED_UNICODE);
        $body = str_replace("\\", "", $body_str);
//        var_dump('body=>' . $body);
        $algorithm = "HMAC-SHA1";

        $signedHeaders = strtolower($host) . ";" . strtolower($content_type) . ";" . strtolower($time_millis);
//        var_dump('signedHeaders=>' . $signedHeaders);

//        $payloadHex = strtolower(md5($body));
        $payloadHex = $request_body ? strtolower(md5($body)) : 'd41d8cd98f00b204e9800998ecf8427e';
//        var_dump('payloadHex=>' . $payloadHex);
        $request = $method_type . "\n" . $uri . "\n" . $query_string . "\n" . $signedHeaders . "\n" . $payloadHex;
//        var_dump('request=>' . $request);
        $requestHex = strtolower(sha1($request));
//        var_dump('requestHex=>' . $requestHex);
        $stringToSign = $algorithm . "\n" . $requestHex;
//        var_dump('stringToSign=>' . $stringToSign);
        $signature = hash_hmac("sha1", $stringToSign, $secret_key, false);
//        var_dump('signature=>' . $signature);
        $sign = $algorithm . " " . $access_key . ":" . $signature;
//        var_dump('sign=>' . $sign);
        return $sign;
    }

}

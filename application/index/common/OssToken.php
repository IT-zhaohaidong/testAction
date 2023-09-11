<?php

namespace app\index\common;

use AlibabaCloud\Client\AlibabaCloud;
use AlibabaCloud\Client\Exception\ClientException;
use AlibabaCloud\Client\Exception\ServerException;


class OssToken
{
    private $accessKeyId = "LTAI5t8yWniWZ9Xjsdx1fFKF";//子用户id
    private $accessKeySecret = "wuH64KuPc15BG5opTlyJQRKepr5NYw";//子用户secret
    private $roleArn = "acs:ram::1746752566011263:role/aliyunosstokengeneratorrole";//RAM角色
    private $bucket = "fs-manghe";//bucket名称
    private $uploadDir = "vision_video/";//上传目录
    //
//    public function index()
//    {
//
//
//        if (is_file(__DIR__ . '/../autoload.php')) {
//            require_once __DIR__ . '/../autoload.php';
//        }
//        if (is_file(__DIR__ . '/../vendor/autoload.php')) {
//
//
//            require_once __DIR__ . '/../vendor/autoload.php';
//        }
//
//        // $url = "https://sts.aliyuncs.com";
//
//        // $accessKeyId="LTAI5tLZ6TxyUWVxYMTvMVaS";
//
//        // $accessKeySecret="Xq0TZFt9XcjnGtxjA43OXSazgUH62R";
//
//        // $endpoint="oss-cn-beijing.aliyuncs.com";
//
//        // $durationSeconds = '1800';
//
//        // $bucket="go-your-heart";
//
//        // $object="/project/abc/mp4/*.jpg";
//
//        // $roleArn="acs:ram::1964658688264468:role/aliyunicedefaultrole";
//
//        // $roleSessionName='client1';
//
//        // $this->sts($accessKeyId,$roleArn,$roleSessionName,$durationSeconds);
//
//
//        $config = [
//            "AccessKeyID" => "LTAI5tLZ6TxyUWVxYMTvMVaS",       // 子用户ID
//            "AccessKeySecret" => "Xq0TZFt9XcjnGtxjA43OXSazgUH62R",  //子用户Secret
//            "RoleArn" => "acs:ram::1964658688264468:role/hjm",    //  RAM角色
//            "BucketName" => "go-your-heart",                   // bucket名称
//            "Endpoint" => "oss-cn-beijing.aliyuncs.com",       //   Endpoint
//            "TokenExpireTime" => "900",
//            "PolicyFile" => "project/abc/mp4/bucket_write_policy.txt"
//        ];
//        //只有put的权限
//        $policy = '{
//            "Statement": [
//                {
//                    "Action": "sts:AssumeRole",
//                    "Effect": "Allow",
//                    "Resource": "*"
//                }
//            ],
//            "Version": "1"
//        }';
//        AlibabaCloud::accessKeyClient($config['AccessKeyID'], $config['AccessKeySecret'])->regionId('cn-beijing')->name('default');
//        $rst = Sts::v20150401()
//            ->assumeRole()
//            //指定角色ARN
//            ->withRoleArn($config['RoleArn'])
//            //RoleSessionName即临时身份的会话名称，用于区分不同的临时身份
//            ->withRoleSessionName('test_sts')
//            //设置权限策略以进一步限制角色的权限
//            ->withPolicy($policy)
//            ->timeout(30)
//            ->connectTimeout(30)
//            //口令有效期是少900，最大没限制
//            ->withDurationSeconds(900)
//            ->request();
//        $code = $rst->getStatusCode();
//        $json = $rst->jsonSerialize();
//        //这里获取body是得不到有用信息的要用上面的json
//        $body = $rst->getBody();
//
//        //返回从STS服务获取的临时访问密钥（AccessKey ID,AccessKey Secret）
//        var_dump($code);
//        var_dump($json);
//
//
//    }

    /**
     *  获取获临时访问凭证
     */
    public function getStsToken()
    {
        AlibabaCloud::accessKeyClient($this->accessKeyId, $this->accessKeySecret)
            ->regionId('cn-hangzhou')
            ->asDefaultClient();
        try {
            $result = AlibabaCloud::rpc()
                ->product('Sts')
                ->scheme('https') //只能是https，否则会报错
                ->version('2015-04-01')
                ->action('AssumeRole')
                ->method('POST')
                ->host('sts.aliyuncs.com')
                ->options([
                    'query' => [
                        'RegionId' => "cn-hangzhou",
                        'RoleArn' => $this->roleArn,
                        'RoleSessionName' => "UploadToken",
                    ],
                ])
                ->request();
            $data = $result->Credentials;
            trace($data, 'ossToken获取结果');
            return
                [
                    'code' => 200,
                    'data' =>
                        [
                            'keyid' => $data->AccessKeyId,
                            'secret' => $data->AccessKeySecret,
                            'token' => $data->SecurityToken,
                            'bucket' => $this->bucket,
                            'uploaddir' => $this->uploadDir //上传目录
                        ],
                    'msg' => 'ok'
                ];
        } catch (ClientException $e) {
            trace($e->getErrorMessage(), 'ossToken获取异常');
            return
                [
                    'code' => 100,
                    'data' => [],
                    'msg' => $e->getErrorMessage()
                ];
        } catch (ServerException $e) {
            trace($e->getErrorMessage(), 'ossToken获取异常');
            return
                [
                    'code' => 100,
                    'data' => [],
                    'msg' => $e->getErrorMessage()
                ];
        }
    }
}

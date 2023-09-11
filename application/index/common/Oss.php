<?php

namespace app\index\common;

use OSS\Core\OssException;
use OSS\Core\OssUtil;
use OSS\OssClient;
use think\Db;

class Oss
{
    private $accessKeyId = "LTAI5t5icQYB8e1fAuBsbZ1o";
    private $accessKeySecret = "UuUeAd6r1iCR53ODzRJdQxOfIOalV6";
    private $endpoint = "https://oss-cn-hangzhou.aliyuncs.com";
    // 填写Bucket名称，例如examplebucket。
    private $bucket = "fs-manghe";

    //上传文件到oss
    public function uploadToOss($object, $filePath)
    {
        try {
            $ossClient = new OssClient($this->accessKeyId, $this->accessKeySecret, $this->endpoint);
            $res = $ossClient->uploadFile($this->bucket, $object, $filePath);
            @unlink($filePath);
            return $res['info']['url'];
        } catch (OssException $e) {
            printf(__FUNCTION__ . ": FAILED\n");
            printf($e->getMessage() . "\n");
            trace($e->getMessage(), 'oss文件上传异常');
            return false;
        }
    }

    //下载文件
    public function downLoad($object, $localfile)
    {
        //$object = "testfolder/exampleobject.txt";
        // 下载Object到本地文件examplefile.txt，并保存到指定的本地路径中（D:\\localpath）。如果指定的本地文件存在会覆盖，不存在则新建。
        // 如果未指定本地路径，则下载后的文件默认保存到示例程序所属项目对应本地路径中。
        //$localfile = "D:\\localpath\\examplefile.txt";
        $options = array(
            OssClient::OSS_FILE_DOWNLOAD => $localfile
        );
        // 使用try catch捕获异常。如果捕获到异常，则说明下载失败；如果没有捕获到异常，则说明下载成功。
        try {
            $ossClient = new OssClient($this->accessKeyId, $this->accessKeySecret, $this->endpoint);
            $ossClient->getObject($this->bucket, $object, $options);
            return true;
        } catch (OssException $e) {
            printf(__FUNCTION__ . ": FAILED\n");
            printf($e->getMessage() . "\n");
            return false;
        }
    }

    /**
     * 删除文件
     * @param $path
     * @return bool
     */
    public function delete($path)
    {
        $ossClient = new OssClient($this->accessKeyId, $this->accessKeySecret, $this->endpoint);
        return $ossClient->deleteObject($this->bucket, $path) === null;
    }

    //-------------------------------分片上传------------------------------------------------------------------
    /**
     *  步骤1：初始化一个分片上传事件，并获取uploadId。
     */
    public function initMultipartUpload($object)
    {
        $initOptions = array(
            OssClient::OSS_HEADERS => array()
        );
        try {
            $ossClient = new OssClient($this->accessKeyId, $this->accessKeySecret, $this->endpoint);
            //返回uploadId。uploadId是分片上传事件的唯一标识，您可以根据uploadId发起相关的操作，如取消分片上传、查询分片上传等。
            $uploadId = $ossClient->initiateMultipartUpload($this->bucket, $object, $initOptions);
//            var_dump($uploadId);
            return $uploadId;
        } catch (OssException $e) {
            printf($e->getMessage() . "\n");
            return false;
        }
    }

    /**
     * 步骤2：上传分片。
     */
    public function multiuploadParts($object, $uploadFile, $uploadId, $start = 0)
    {
        $partSize = 1 * 1024 * 1024;
        $uploadFileSize = sprintf('%u', filesize($uploadFile));
        $ossClient = new OssClient($this->accessKeyId, $this->accessKeySecret, $this->endpoint);
        $pieces = $ossClient->generateMultiuploadParts($uploadFileSize, $partSize);
        $responseUploadPart = array();
        $uploadPosition = 0;
        $isCheckMd5 = true;
        foreach ($pieces as $i => $piece) {
            $fromPos = $uploadPosition + (integer)$piece[$ossClient::OSS_SEEK_TO];
            $toPos = (integer)$piece[$ossClient::OSS_LENGTH] + $fromPos - 1;
            $upOptions = array(
                // 上传文件。
                $ossClient::OSS_FILE_UPLOAD => $uploadFile,
                // 设置分片号。
                $ossClient::OSS_PART_NUM => ($start + 1),
                // 指定分片上传起始位置。
                $ossClient::OSS_SEEK_TO => $fromPos,
                // 指定文件长度。
                $ossClient::OSS_LENGTH => $toPos - $fromPos + 1,
                // 是否开启MD5校验，true为开启。
                $ossClient::OSS_CHECK_MD5 => $isCheckMd5,
            );
            // 开启MD5校验。
            if ($isCheckMd5) {
                $contentMd5 = OssUtil::getMd5SumForFile($uploadFile, $fromPos, $toPos);
                $upOptions[$ossClient::OSS_CONTENT_MD5] = $contentMd5;
            }
            try {
                // 上传分片
                $responseUploadPart[] = $ossClient->uploadPart($this->bucket, $object, $uploadId, $upOptions);
                printf("initiateMultipartUpload, uploadPart - part#{$start} OK\n");
                $start++;
            } catch (OssException $e) {
                printf("initiateMultipartUpload, uploadPart - part#{$start} FAILED\n");
                printf($e->getMessage() . "\n");
                return false;
            }

        }
        // $uploadParts是由每个分片的ETag和分片号（PartNumber）组成的数组。
        $uploadParts = array();
        foreach ($responseUploadPart as $i => $eTag) {
            $uploadParts[] = array(
                'PartNumber' => ($i + 1),
                'ETag' => $eTag,
            );
        }
        @unlink($uploadFile);
        return $uploadParts;
    }

    /**
     * 步骤3：完成上传。
     */
    public function completeUploadParts($object, $uploadParts, $uploadId)
    {
        $comOptions['headers'] = array(
            // 指定完成分片上传时是否覆盖同名Object。此处设置为true，表示禁止覆盖同名Object。
            'x-oss-forbid-overwrite' => 'true',
            // 如果指定了x-oss-complete-all:yes，则OSS会列举当前uploadId已上传的所有Part，然后按照PartNumber的序号排序并执行CompleteMultipartUpload操作。
            // 'x-oss-complete-all'=> 'yes'
        );
        $ossClient = new OssClient($this->accessKeyId, $this->accessKeySecret, $this->endpoint);
        try {
            // 执行completeMultipartUpload操作时，需要提供所有有效的$uploadParts。OSS收到提交的$uploadParts后，会逐一验证每个分片的有效性。当所有的数据分片验证通过后，OSS将把这些分片组合成一个完整的文件。
            $res = $ossClient->completeMultipartUpload($this->bucket, $object, $uploadId, $uploadParts, $comOptions);
            trace($res, '完成分片合并');
            return $res;
        } catch (OssException $e) {
            printf("Complete Multipart Upload FAILED\n");
            printf($e->getMessage() . "\n");
            return false;
        }
    }

    //---------------------------------------追加上传----------------------------------------------------------
    public function appendUpload($position, $object, $filePath)
    {
        $ossClient = new OssClient($this->accessKeyId, $this->accessKeySecret, $this->endpoint);
        try {
            $res = $ossClient->appendObject($this->bucket, $object, $filePath, $position);
            return $res;
        } catch (OssException $e) {
            echo "视频追加上传失败：" . $e->getMessage();
            return false;
        }
    }

}

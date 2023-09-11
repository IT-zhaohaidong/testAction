<?php

namespace app\transfer\controller;

use app\index\model\MachineDeviceErrorModel;
use app\index\model\MachineGoods;
use app\index\model\MachineOutLogModel;
use app\index\model\MachineSignalLogModel;
use think\Cache;
use think\Controller;
use think\Db;

//芯夏
class Sunshine extends Controller
{
    /**
     * 心跳包信息
     */
    public function checkIn()
    {
        $post = $this->request->post();
        $str = $post['Imei'] . '_heartBeat';
        Cache::store('redis')->set($str, 1, 180);
        $ip = $this->request->ip();
        $str = $post['Imei'] . '_ip';
        Cache::store('redis')->set($str, $ip);
        trace($post, "售卖机心跳包信息");
        $transfer = $ip == '119.45.161.95' ? 3 : 4;
        //将设备信息录入库
        $row = Db::name('machine_device')->where('imei', $post['Imei'])->find();
        if ($row && ($row['iccid'] != $post['ICCID'] || $row['device_sn'] != $post['Imei'] || $row['transfer'] != $transfer || $row['app_version'] != $post['Version'])) {
            $data = [
                'iccid' => $post['ICCID'],
                'card_type' => 1,
                'supplier_type' => 1,
                'supply_id' => 6,
                'device_sn' => $post['Imei'],
                'transfer' => $transfer,
                'app_version' => $post['Version'],
            ];
            if ($row['device_sn'] != $post['Imei']) {
                $data['qr_code'] = qrcode($post['Imei'], 1);
            }
            Db::name('machine_device')->where('id', $row['id'])->update($data);
        }
        if (!$row) {
            $params = [
                'device_sn' => $post['Imei'],
                'device_name' => $post['Imei'],
                'imei' => $post['Imei'],
                'num' => 1,
                'qr_code' => qrcode($post['Imei'], 1),
                'type_id' => 1,
                'transfer' => $transfer,
                'uid' => 1,
                'iccid' => $post['ICCID'],
                'app_version' => $post['Version'],
                'card_type' => 1,
                'supplier_type' => 0,
                'supply_id' => 6,
                'status' => 1,
                'expire_time' => mktime(date("H"), date("i"), date("s"), date("m"), date("d"), date("Y") + 1),
                'create_time' => time()
            ];
            Db::name('machine_device')->insert($params);
        }

        $row = Db::name('device')->where('imei', $post['Imei'])->find();

//        $transfer = 3;
        if (!$row) {
            Db::name('device')->insert(['imei' => $post['Imei'], 'transfer' => $transfer, 'device_sn' => $post['Imei'], 'uid' => 1, 'create_time' => time()]);
        } else {
            if ($transfer != $row['transfer']) {
                Db::name('device')->where('id', $row['id'])->update(['transfer' => $transfer]);
            }
            if ($row['uid'] != 1) {
                $domain_name = Db::name('device_system')->where(['id' => $row['uid']])->value('url');
                trace($domain_name, '1----------------');
                return json(['imei' => $post['Imei'], 'url' => $domain_name, 'deviceNumber' => $post['Imei']]);
            }
        }
        return "ok";
    }

    //设备上传4G信号值
    public function csq()
    {
        $post = $this->request->post();
        $str = $post['Imei'] . '_heartBeat';
        Cache::store('redis')->set($str, 1, 180);
        $ip = $this->request->ip();
        $str = $post['Imei'] . '_ip';
        Cache::store('redis')->set($str, $ip);
        trace($post, "售卖机心跳包信息");
        $data = [
            'device_sn' => $post['Imei'],
            'signal' => hexdec((string)$post['CsqLevel'])
        ];
        (new MachineSignalLogModel())->save($data);
        return "ok";
    }

    /**
     * 设备回复出货结果
     */
    public function deliver()
    {
        $post = $this->request->post();
        $post['ChannelIndex'] = hexdec((string)$post['ChannelIndex']) + 1;
        $post['AlarmCode'] = hexdec((string)$post['AlarmCode']);
        if ($post['AlarmCode'] == 10) {
            $post['AlarmCode'] = 0;
            $post['Result'] = 1;
        }
        trace($post, "售卖机设备回复出货结果");
        $str = $post['Imei'] . '_heartBeat';

        Cache::store('redis')->set($str, 1, 180);
        $arr = explode('test', $post['SaleId']);
        if ($arr[0] == '100') {
            $arr = [0 => '正常出货', 1 => '电机正常,未检测到出货', 2 => '无效货道', 4 => '货道超时', 6 => '过流', 7 => '欠流', 8 => '超时', 9 => '光幕自检失败', 10 => '反馈锁未弹开'];
            $str = $post['Imei'] . '_testResult';
            $res = Cache::store('redis')->get($str) ?? [];
            if ($post['Result'] == 0) {
                $status = $post['AlarmCode'] == 6 ? 1 : $post['AlarmCode'] + 5;
            } else {
                $status = 0;
            }
            $data = [
                'num' => $post['ChannelIndex'],
                'status' => $arr[$status],
                'orderNo' => $post['SaleId'],
                'time' => date('H:i:s')
            ];
            $res[] = $data;
            Cache::store('redis')->set($str, $res, 300);

            $outStr = 'out_' . $post['SaleId'];
            if ($post['Result'] == 0) {
                Cache::store('redis')->set($outStr, 2);
                $log = ["device_sn" => $post['Imei'], "num" => $post['ChannelIndex'], "order_sn" => $post['SaleId'], 'status' => $status];
                (new MachineOutLogModel())->save($log);
            } else {
                Cache::store('redis')->set($outStr, 1);
                $log = ["device_sn" => $post['Imei'], "num" => $post['ChannelIndex'], "order_sn" => $post['SaleId'], 'status' => 0];
                (new MachineOutLogModel())->save($log);
            }
            return "ok";
        }
        Cache::store('redis')->set($post['SaleId'], 2, 30);
        $post['orderNo'] = explode('test', $post['SaleId'])[0];
        //美团订单号
        $order_sn = strstr($post['SaleId'], "mt_");
//        $old_order_sn = explode('order', $post['orderNo'])[0];
        $outStr = 'out_' . $post['SaleId'];
        if ($post['Result'] == 0 && $post['AlarmCode'] != 3) {
            $status = $post['AlarmCode'] == 6 ? 1 : $post['AlarmCode'] + 5;
            $data = [
                'order_sn' => $post['SaleId'],
                'device_sn' => $post['Imei'],
                'num' => $post['ChannelIndex'],
                'status' => $status,
            ];
            (new MachineDeviceErrorModel())->save($data);
            Cache::store('redis')->set($outStr, 2);
            $log = ["device_sn" => $post['Imei'], "num" => $post['ChannelIndex'], "order_sn" => $post['SaleId'], 'status' => $status];
            (new MachineOutLogModel())->save($log);
            //锁货道
            (new MachineGoods())
                ->where(["device_sn" => $post['Imei'], "num" => $post['ChannelIndex']])
                ->update(['is_lock' => 1]);
        } else {
            //获取成功反馈
            if (!$order_sn) {
                $order_sn = explode('order', $post['SaleId'])[0];
            }
            $str = 'chuohuoresult_' . $order_sn;
            Cache::store('redis')->set($str, 1, 3000);
            Cache::store('redis')->set($outStr, 1);
            $log = ["device_sn" => $post['Imei'], "num" => $post['ChannelIndex'], "order_sn" => $post['SaleId'], 'status' => 0];
            (new MachineOutLogModel())->save($log);
        }
        return "ok";
    }
}

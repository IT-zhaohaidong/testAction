<?php

namespace app\crontab\controller;

use app\index\common\Email;
use think\Cache;
use think\Db;

class Device
{
    //设备开关机状态
    public function deviceStatus()
    {
        $time = time();
        $rows = Db::name('machine_device')->whereIn('supply_id', [1, 2, 5,6])->where('delete_time', null)->where('status', 1)->field('id,device_sn')->select();
        $ids = [];
        $online_log = [];
        foreach ($rows as $k => $v) {
            $str = $v['device_sn'] . '_heartBeat';
            $res = Cache::store('redis')->get($str);
            if (!$res) {
                $ids[] = $v['id'];
                $online_log[] = ['device_sn' => $v['device_sn'], 'status' => 0, 'create_time' => $time];
            }
        }
        Db::name('machine_device')->whereIn('id', $ids)->update(['status' => 0]);

        $rows = Db::name('machine_device')->whereIn('supply_id', [1, 2, 5,6])->where('delete_time', null)->where('status', 0)->field('id,device_sn')->select();
        $ids = [];
        foreach ($rows as $k => $v) {
            $str = $v['device_sn'] . '_heartBeat';
            $res = Cache::store('redis')->get($str);
            if ($res) {
                $ids[] = $v['id'];
                $online_log[] = ['device_sn' => $v['device_sn'], 'status' => 1, 'create_time' => $time];
            }
        }
        Db::name('machine_device')->whereIn('id', $ids)->update(['status' => 1]);
        Db::name('machine_online_log')->insertAll($online_log);
    }

    //蜜连设备开关机状态
    public function milianStatus()
    {
        $time = time();
        $rows = Db::name('machine_device')->where('supply_id', 3)->where('delete_time', null)->field('id,device_sn,status')->select();
        $ids = [];
        $online_log = [];
        $outline_ids = [];
        foreach ($rows as $k => $v) {
            $res = $this->device_status($v['device_sn']);
            if (!$res) {
                if ($v['status'] != 0) {
                    $outline_ids[] = $v['id'];
                    $online_log[] = ['device_sn' => $v['device_sn'], 'status' => 0, 'create_time' => $time];
                }
            } else {
                if ($v['status'] != 1) {
                    $ids[] = $v['id'];
                    $online_log[] = ['device_sn' => $v['device_sn'], 'status' => 1, 'create_time' => $time];
                }
            }
            usleep(20000);
        }
        Db::name('machine_device')->whereIn('id', $ids)->update(['status' => 1]);
        Db::name('machine_device')->whereIn('id', $outline_ids)->update(['status' => 0]);
        Db::name('machine_online_log')->insertAll($online_log);
    }

    /**
     * 查看设备状态
     */
    public function device_status($sn = '')
    {

//        $sn="ILJXJI";
        $nonceStr = time();
        $url = "https://mqtt.ibeelink.com/api/ext/tissue/device/info";
        $data = '{
                "sn":"' . $sn . '","nonceStr": "' . $nonceStr . '"}';
        $post_data = array(
            'data' => $data
        );
        $res = $this->milian_post($url, $post_data, md5($data . 'D902530082e570917F645F755AE17183'));
        $res_arr = json_decode($res, true);
        return $res_arr['data']['online'];

    }

    public function milian_post($url, $post_data, $token)
    {
        $postdata = http_build_query($post_data);
        $options = array(
            'http' =>
                array(
                    'method' => 'GET',
                    'header' => array('token:' . $token, 'chan:bee-CSQYUS', 'Content-type:application/x-www-form-urlencoded'),
                    'content' => $postdata,
                    'timeout' => 15 * 60
                )
        );
        $context = stream_context_create($options);
        $result = file_get_contents($url, false, $context);
        return $result;
    }


    //设备到期提醒
    public function deviceExpire()
    {
        $admin = Db::name('system_admin')
            ->where('delete_time', null)
            ->where('email', '<>', '')
            ->where('email', 'not null')
            ->column('username,email', 'id');
        $ids = array_keys($admin);
        $time = time() - 3600 * 24 * 3;
        $device = Db::name('machine_device')
            ->whereIn('uid', $ids)
            ->where('delete_time', null)
            ->where('expire_time', '<', $time)
            ->field('count(id) device_count,uid')
            ->group('uid')
            ->select();
        foreach ($device as $k => $v) {
            if ($v['device_count'] > 0 && isset($admin[$v['uid']]) && $admin[$v['uid']]['email'] != '') {
                $title = '设备到期提醒';
                $body = "尊敬的" . $admin[$v['uid']]['username'] . ":<br>" . "您有" . $v['device_count'] . '台设备已到期或即将到期,为避免影响使用,请及时续费';
                $res = (new Email())->send_email($title, $body, $admin[$v['uid']]['email']);
                trace($res, '到期提醒');
            }
        }
    }
}

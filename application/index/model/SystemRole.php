<?php

namespace app\index\model;

use app\index\common\TimeModel;

class SystemRole extends TimeModel
{

    public function getList()
    {
        $rows = self::where('delete_time', null)->select();
        return $rows;
    }

    public function getOne($id)
    {
        $rows = self::where('delete_time', null)->where('id', $id)->find();
        return $rows;
    }

    public function tree($list, $pid = 0, $role_id = '')
    {
        $item = [];
        foreach ($list as $k => $v) {
            if ($v['pid'] == $pid || $role_id == $v['value']) {
                $children = $this->tree($list, $v['value']);
                if ($children) {
                    $v['children'] = $children;
                }
                $item[] = $v;
            }
        }
        return $item;
    }


}

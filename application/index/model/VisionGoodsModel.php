<?php

// +----------------------------------------------------------------------
// | EasyAdmin
// +----------------------------------------------------------------------
// | PHP交流群: 763822524
// +----------------------------------------------------------------------
// | 开源协议  https://mit-license.org
// +----------------------------------------------------------------------
// | github开源项目：https://github.com/zhongshaofa/EasyAdmin
// +----------------------------------------------------------------------

namespace app\index\model;


use app\index\common\TimeModel;

class VisionGoodsModel extends TimeModel
{
    protected $table = 'fs_vision_goods';
    protected $deleteTime = 'delete_time';
}

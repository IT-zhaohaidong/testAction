<?php

namespace app\index\model;

use app\index\common\TimeModel;

class VisionOrderModel extends TimeModel
{
    protected $table = 'fs_vision_order';
    protected $deleteTime = 'delete_time';
}

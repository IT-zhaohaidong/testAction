<?php
namespace app\queque;

use Pheanstalk\Pheanstalk;
use think\console\Command;
use think\console\Input;
use think\console\Output;

class Order extends Command
{
    protected function configure()
    {
        $this->setName('order')->setDescription('Here is the remark ');
    }

    protected function execute(Input $input, Output $output)
    {
        $pda = Pheanstalk::create('127.0.0.1');
        while (true) {
            //获取管道并消费
            $job = $pda->watch('order')->ignore('default')->reserve();
            //获取任务id
//            $id = $job->getId();
            //获取任务数据
            $data = $job->getData();
            trace($data, '获取消费任务');
            //处理完任务后就删除掉
            $pda->delete($job);
            sleep(1);
        }

    }
}

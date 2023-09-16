<?php
namespace app\queque;

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
        $output->writeln("TestCommand:");
    }
}

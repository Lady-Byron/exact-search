<?php

use Flarum\Extend;
use LadyByron\ExactSearch\ExactScoutServiceProvider;

return [
    // 必须传“类名字符串”，不能传闭包
    (new Extend\ServiceProvider())->register(ExactScoutServiceProvider::class),
];

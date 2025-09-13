<?php

use Flarum\Extend;
use LadyByron\ExactSearch\ExactScoutServiceProvider;

return [
    (new Extend\ServiceProvider())->register(ExactScoutServiceProvider::class),
];

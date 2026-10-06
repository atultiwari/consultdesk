<?php

declare(strict_types=1);

use ConsultDesk\Http\AppFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

AppFactory::create(getenv('APP_DEBUG') === '1')->run();

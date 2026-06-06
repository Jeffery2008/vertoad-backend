<?php

declare(strict_types=1);

use VertoAD\AppFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

AppFactory::create()->run();

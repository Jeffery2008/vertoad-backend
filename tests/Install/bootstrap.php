<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/InstallNativeFunctions.php';

// Load native-call boundaries after the test namespace functions are registered.
require dirname(__DIR__, 2) . '/src/Install/InstallFilesystem.php';
require dirname(__DIR__, 2) . '/src/Install/InstallSecretGenerator.php';
require dirname(__DIR__, 2) . '/src/Install/InstallState.php';

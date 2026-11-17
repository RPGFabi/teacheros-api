<?php

declare(strict_types=1);

use Hoelzle\Core\Application;
use Hoelzle\Core\Config\Config;
use Hoelzle\Core\Setup\SetupRunner;
use Hoelzle\Core\Setup\SetupRunnerConfig;

require_once dirname(__DIR__) . '/vendor/autoload.php';

Application::initialize(dirname(__DIR__));
Config::load();

$runner = new SetupRunner(new SetupRunnerConfig(
    setupDir: __DIR__,
    logFile: dirname(__DIR__) . '/debug/setup.log',
    allowedModes: ['migrate'],
    defaultMode: 'migrate',
    ensureDatabase: true,
));

exit($runner->run($argv ?? []));

#!/usr/bin/env php
<?php

declare(strict_types=1);

namespace FaktorE\CLI\Sniffy;

use Symfony\Component\Console\Application;

require __DIR__ . '/../../../autoload.php';
require __DIR__ . '/SniffyCommand.php';
require __DIR__ . '/FixerCommand.php';

$application = new Application('Sniffy');
$application->add(new SniffyCommand('check-sniffer'));
$application->add(new FixerCommand('check-fixer'));
$application->run();

#!/usr/bin/env php
<?php

require_once __DIR__ . '/../../../core/php/core.inc.php';
require_once __DIR__ . '/../core/class/localthings.class.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "LocalThings daemon requires PHP CLI\n");
    exit(1);
}

$daemon = new LocalThingsDaemon(
    localthings::daemonRuntimePath(),
    function ($operation, $payload) {
        localthings::enterDaemonMode();
        return localthings::daemonHandle($operation, $payload);
    },
    function () {
        localthings::enterDaemonMode();
        localthings::daemonTick();
    },
    function () {
        localthings::leaveDaemonMode();
    },
    function ($level, $message) {
        log::add('localthings_daemon', $level, $message);
    }
);

try {
    $status = $daemon->run();
} catch (Throwable $error) {
    log::add('localthings_daemon', 'error', '[Daemon] Arrêt fatal : ' . $error->getMessage());
    localthings::leaveDaemonMode();
    $status = 1;
}
exit($status);

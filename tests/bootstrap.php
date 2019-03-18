<?php

namespace FireSessions {

    function is_ajax_request()
    {

    }
}

namespace {
    require_once __DIR__ . '/../vendor/autoload.php';

    if (getenv('CI') !== false) {
        require_once __DIR__ . '/env.travis.php';
    } else {
        require_once __DIR__ . '/env.dev.php';
    }
}

<?php

use FireSessions\Session;

return array(
    'driver' => Session::FILES_DRIVER,
    'cookie_name' => 'fsessions',
    'save_path' => __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'sessions',
    'expiration' => 7200,
    'regenerate_time' => 300,
    'destroy_on_regenerate' => false
);

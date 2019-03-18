<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use FireSessions\SessionFactory;

$config = require_once __DIR__ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . $_GET['driver'] . '.php';

$session = SessionFactory::getInstance($config);

echo json_encode([
    'allUserdata' => $session->userdata(),
    'allFlashdata' => $session->flashdata(),
    'allTempdata' => $session->tempdata(),
    'myUdata2' => $session->userdata('myUdata2'),
    'myFdata2' => $session->flashdata('myFdata2'),
    'myTdata2' => $session->tempdata('myTdata2'),
]);

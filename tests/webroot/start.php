<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use FireSessions\SessionFactory;

if (!in_array($_GET['driver'], array('redis', 'files', 'memcached'))) {
    die ('Not allowed.');
}

$config = require_once __DIR__ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . $_GET['driver'] . '.php';

$session = SessionFactory::getInstance($config);

$session->setUserdata('myUdata1', 'myUdataValue1');
$session->setUserdata(['myUdata2' => 'myUdataValue2']);
$session->setFlashdata('myFdata1', 'myFdataValue1');
$session->setFlashdata(['myFdata2' => 'myFdataValue2']);
$session->setTempdata('myTdata1', 'myTdataValue1', 10);
$session->setTempdata(['myTdata2' => 'myTdataValue2'], ['myTdata2' => 100]);

echo 'OK';

<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
if (str_starts_with($_SERVER['REQUEST_URI'], '/zapi/smartbt/prgs')) {
    $body=file_get_contents('php://input');
    $head=$_SERVER['REQUEST_METHOD'].' '.$_SERVER['REQUEST_URI'].' '.$_SERVER['SERVER_PROTOCOL']."\r\n";
    foreach(getallheaders() as $name=>$value)$head.=$name.': '.$value."\r\n";
    echo json_encode(['requestBytes'=>strlen($head."\r\n")+strlen($body)]);
    return;
}
$d=new TestDevice();
$d->attrs['SmartButtonAdminToken']='fixture-admin';
$d->attrs['SmartButtonToken']='fixture-callback';
$d->attrs['SmartButtonScenes']='[{"id":"existing","name":"Test","targets":[]}]';
invoke($d,'ProcessHookData');

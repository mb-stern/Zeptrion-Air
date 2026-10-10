<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
$host=$argv[1];$checks=0;
function expect(bool $condition,string $message): void {global $checks;if(!$condition)throw new RuntimeException($message);$checks++;}
function request(string $path,?array $body=null,string $token=''): array {
    global $host;
    $c=curl_init('http://'.$host.$path);
    $options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5,CURLOPT_PROXY=>''];
    if($body!==null){$options[CURLOPT_POSTFIELDS]=json_encode($body);$options[CURLOPT_HTTPHEADER]=['Content-Type: application/json'];}
    if($token!=='')$options[CURLOPT_HTTPHEADER][]='X-Zeptrion-Admin-Token: '.$token;
    curl_setopt_array($c,$options);$data=curl_exec($c);if($data===false)throw new RuntimeException(curl_error($c));
    return [(int)curl_getinfo($c,CURLINFO_HTTP_CODE),$data];
}
expect(request('/hook/zeptrionair-99')[0]===403,'UI rejects anonymous GET');
expect(request('/hook/zeptrionair-99?admin=incorrect')[0]===403,'UI rejects wrong token');
[$status,$html]=request('/hook/zeptrionair-99?admin=fixture-admin');
expect($status===200 && str_contains($html,"'X-Zeptrion-Admin-Token':\"fixture-admin\""),'Authorized UI supplies token for its POST requests');
$forget=['op'=>'forget','scene'=>'existing'];
expect(request('/hook/zeptrionair-99',$forget)[0]===403,'Anonymous mutation is forbidden');
expect(request('/hook/zeptrionair-99?admin=fixture-admin',$forget)[0]===403,'POST requires custom header, not ambient URL/cookie');
expect(request('/hook/zeptrionair-99',$forget,'fixture-callback')[0]===403,'Callback token cannot administer UI');
[$status,$body]=request('/hook/zeptrionair-99',$forget,'fixture-admin');expect($status===200 && json_decode($body,true)['scenes']===[],'Authorized mutation works');
expect(request('/hook/zeptrionair-99?action=run&scene=existing&token=fixture-admin')[0]===403,'Admin token cannot invoke callback');
[$status,$body]=request('/hook/zeptrionair-99?action=run&scene=existing&token=fixture-callback');expect($status===200 && $body==='OK','Existing device callback still works');

$d=new TestDevice();$payload=['req'=>'POST','loc'=>'192.0.2.1','pth'=>'/zrap/chctrl','bdy'=>'cmd1=recall_s1'];
$body=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$estimate=invoke($d,'SmartButtonRequestSize',$host,'POST','/zapi/smartbt/prgs',$body);
$r=invoke($d,'SmartButtonRequest',$host,'POST','/zapi/smartbt/prgs',$payload,5000);expect($r['success'] && json_decode($r['raw'],true)['requestBytes']===$estimate,'Measured HTTP request matches complete size calculation');
for($bytes=730;$bytes<=731;$bytes++){
    $payload['bdy']='cmd1='.str_repeat('x',450);
    do{$encoded=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$size=invoke($d,'SmartButtonRequestSize',$host,'POST','/zapi/smartbt/prgs',$encoded);if($size<$bytes)$payload['bdy'].='x';}while($size<$bytes);
    expect($size===$bytes,'Exact boundary generated');
    $r=invoke($d,'SmartButtonRequest',$host,'POST','/zapi/smartbt/prgs',$payload,5000);
    expect($bytes===730 ? ($r['success'] && json_decode($r['raw'],true)['requestBytes']===730) : (!$r['success'] && $r['httpCode']===0),'730 bytes accepted; 731 bytes blocked before sending');
}
echo "$checks HTTP regression checks passed.\n";

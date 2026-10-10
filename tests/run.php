<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';
$checks=0;
function check(bool $condition,string $message): void {global $checks;if(!$condition)throw new RuntimeException($message);$checks++;}
$d=new TestDevice();$GLOBALS['values']=['Ch1Lamella'=>50];
$d->RequestAction('Ch1Command',1);
$own=json_decode($d->attrs['OwnMotorState'],true)[1];
check($d->commands===[[1,'on']], 'Lamella up starts in the correct direction');
check($own['stopCommand']==='off' && $own['type']==='lamella', 'Lamella up has a planned stop');
check($d->timers['OwnMotorTimer']===100 && $GLOBALS['values']['Ch1Lamella']===83, 'Lamella step uses timer and target');
$d->attrs['OwnMotorState']=json_encode(['1'=>array_merge($own,['until'=>1])]);$d->OwnMotorTick();
check($d->commands===[[1,'on'],[1,'off']], 'Lamella stop is sent');
$d=new TestDevice();$GLOBALS['values']['Ch1Lamella']=50;$d->RequestAction('Ch1Command',3);
check($d->commands===[[1,'off']] && json_decode($d->attrs['OwnMotorState'],true)[1]['stopCommand']==='on', 'Lamella down also plans stop');
$d=new TestDevice();$d->attrs['OwnMotorState']=json_encode(['1'=>['type'=>'position','until'=>(int)(microtime(true)*1000)+5000,'direction'=>'down']]);$d->RequestAction('Ch1Command',1);
check($d->commands===[] && isset(json_decode($d->attrs['OwnMotorState'],true)[1]['pendingLamella']), 'Lamella step is queued during travel');

$d=new TestDevice();$d->commandResult=false;
$d->attrs['OwnMotorState']=json_encode(['1'=>['type'=>'position','until'=>1,'target'=>50,'stopCommand'=>'on']]);$d->OwnMotorTick();
$own=json_decode($d->attrs['OwnMotorState'],true)[1];
check($own['stopCommand']==='stop' && $own['stopAttempts']===1, 'Failed stop remains pending and retries idempotently');
$own['until']=1;$d->attrs['OwnMotorState']=json_encode(['1'=>$own]);$d->commandResult=true;$d->OwnMotorTick();
check($d->commands===[[1,'on'],[1,'stop']] && json_decode($d->attrs['OwnMotorState'],true)===[], 'Successful retry clears pending stop');
$d=new TestDevice();$d->commandResult=false;$d->attrs['OwnMotorState']=json_encode(['1'=>['type'=>'lamella','until'=>1,'stopCommand'=>'off']]);
for($i=0;$i<3;$i++){ $all=json_decode($d->attrs['OwnMotorState'],true);$all[1]['until']=1;$d->attrs['OwnMotorState']=json_encode($all);$d->OwnMotorTick(); }
check(json_decode($d->attrs['OwnMotorState'],true)[1]['stopFailed'] && $d->status===202 && count($d->logs)===1, 'Three failed stops retain failure and report error');
invoke($d,'SetCommunicationReady');check($d->status===202,'Successful info queries do not hide failed motor stop');
$d->OwnMotorTick();check(count($d->commands)===3 && $d->timers['OwnMotorTimer']===0,'Stop retries are bounded');
$d->ProcessNotifyData('<chnotify><ch1><val>0</val></ch1></chnotify>');check(isset(json_decode($d->attrs['OwnMotorState'],true)[1]['stopFailed']),'Notify cannot discard unresolved stop');
$d->commandResult=true;check($d->Stop(1) && json_decode($d->attrs['OwnMotorState'],true)===[],'Explicit successful stop clears retained failure');
invoke($d,'SetCommunicationReady');check($d->status===102,'Communication can become ready after manual stop');

$d=new TestDevice();$d->props['Channel1Type']='light';$GLOBALS['values']['Ch1Switch']=true;
$d->ProcessNotifyData('<chnotify><ch1><val>-1</val></ch1></chnotify>');check($GLOBALS['values']['Ch1Switch']===true,'Unknown notification preserves switch');
invoke($d,'ApplyChannelStates',['ch1'=>['val'=>'-1']],'chscan');check($GLOBALS['values']['Ch1Switch']===true,'Unknown scan preserves switch');
$d->props['ShowChannelActualValues']=true;invoke($d,'ApplyChannelStates',['ch1'=>['val'=>'-1']],'chscan');check($GLOBALS['values']['Ch1ActualValue']===-1.0,'Diagnostic raw status exposes unknown scan');
$d->ProcessNotifyData('<chnotify><ch1><val>-1</val></ch1></chnotify>');check($GLOBALS['values']['Ch1ActualValue']===-1,'Diagnostic raw status exposes unknown notify');
check(invoke($d,'StateToBool',-1)===null,'Unknown value does not become false');
$d->ProcessNotifyData('<chnotify><ch1><val>0</val></ch1></chnotify>');check($GLOBALS['values']['Ch1Switch']===false,'Known off notification still applies');
$d->props['Channel1Type']='dimmer';$GLOBALS['values']['Ch1DimmerSwitch']=true;$d->ProcessNotifyData('<chnotify><ch1><val>-1</val></ch1></chnotify>');check($GLOBALS['values']['Ch1DimmerSwitch']===true,'Unknown dimmer notification preserves switch');

$d=new TestDevice();$d->props['Channel1Type']='light';$d->StartNotifyListener();check($d->timers['RecoveryTimer']>0 && (float)$d->buffers['NotifyDeadline']>microtime(true),'Scan has watchdog and deadline');
$xml='<chscan><ch1><val>0</val></ch1></chscan>';$response="HTTP/1.1 200 OK\r\nContent-Length: ".strlen($xml)."\r\n\r\n".$xml;
$d->ReceiveData(json_encode(['Buffer'=>bin2hex(substr($response,0,20))]));check(count($d->sent)===1,'Fragmented scan is not completed early');
$d->ReceiveData(json_encode(['Buffer'=>bin2hex(substr($response,20))]));check($d->buffers['NotifyPending']==='notify' && (float)$d->buffers['NotifyDeadline']>microtime(true)+30,'Scan transitions to long-poll with adequate deadline');
$d->RecoveryTick();check(count($d->sent)===2,'Watchdog leaves unexpired long-poll alone');
$d->buffers['NotifyDeadline']='1';$d->RecoveryTick();check($d->buffers['NotifyPending']==='' && $GLOBALS['parentChanges']===[[11,'Open',false],[11,'Open',true]],'Expired stream is closed before recovery');
$d->ReceiveData(json_encode(['Buffer'=>bin2hex($response)]));check($d->buffers['NotifyPending']==='' && count($d->sent)===2,'Unsolicited stale data cannot restart notify during recovery');
$d->RecoveryTick();check($d->buffers['NotifyPending']==='scan' && count($d->sent)===3,'Recovery sends fresh scan');
$d->buffers['NotifyParent']='11';$d->active=false;$d->MessageSink(time(),11,IM_CHANGESTATUS,[202]);check($d->buffers['NotifyPending']==='' && $d->timers['RecoveryTimer']>0,'Parent status change enters recovery');
$d->active=true;$d->RecoveryTick();check($d->buffers['NotifyPending']==='scan','Reconnected parent resumes scan');

// Discovery retains the original service-name host behavior.
$discovery=new ZeptrionAirDiscovery();$found=[];
$GLOBALS['browseServices']['_zapp._tcp']=[
 ['Name'=>'zapp-19370098','Host'=>'different.local.','IPv4'=>['192.0.2.1'],'Port'=>8080],
 ['Name'=>'zapp-19370099.local.'],
 ['Name'=>'Living room'],
 ['Name'=>'zapp-19370098'],
 ['Name'=>'']
];
(new ReflectionMethod($discovery,'CollectServices'))->invokeArgs($discovery,[1,'_zapp._tcp',false,&$found]);
check(isset($found['zapp-19370098']),'Bare service name is used directly as host');
check(isset($found['zapp-19370099.local']),'Only trailing root dot is removed from service name');
check(isset($found['Living room']),'Primary discovery keeps service name without extra resolution');
check($found['zapp-19370098']['ip']==='' && !isset($found['different.local']),'Host and IP details do not replace service name');
check(count($found)===3,'Duplicate service names are merged and empty names ignored');
check(($GLOBALS['serviceQueries']??[])===[],'Discovery does not issue extra address queries');
$GLOBALS['browseErrors']=['_zapp._tcp'];$before=$found;
(new ReflectionMethod($discovery,'CollectServices'))->invokeArgs($discovery,[1,'_zapp._tcp',false,&$found]);
check($found===$before,'Browse error preserves already discovered services');
$GLOBALS['browseServices']['_http._tcp']=[['Name'=>'zapp-19370100'],['Name'=>'Other web service']];
(new ReflectionMethod($discovery,'CollectServices'))->invokeArgs($discovery,[1,'_http._tcp',true,&$found]);
check(isset($found['zapp-19370100']) && !isset($found['Other web service']),'Legacy discovery retains original Feller-name filter');
$GLOBALS['browseErrors']=[];$GLOBALS['browseServices']=[];

$d=new TestDevice();$d->attrs['MotorRuntimeState']=json_encode(['1'=>['moving'=>true,'moveStartMs'=>1000,'direction'=>'down']]);invoke($d,'ProcessMotorNotify',1,100);check(json_decode($d->attrs['MotorRuntimeState'],true)[1]['moveStartMs']===1000,'Repeated running status preserves start time');
$d=new TestDevice();invoke($d,'ProcessMotorNotify',1,100);check(json_decode($d->attrs['MotorRuntimeState'],true)[1]['moving'],'New running status starts travel');

$d=new TestDevice();$d->props['Channel1Type']='dimmer';$GLOBALS['values']['Ch1Level']=60;$GLOBALS['values']['Ch1DimmerSwitch']=false;invoke($d,'CaptureSceneReference',1,1);$ref=json_decode($d->GetSceneReferenceData(1,1),true);check($ref['level']===60 && $ref['on']===false,'Off scene keeps remembered level and separate switch');invoke($d,'ApplySceneReferenceEnd',$ref);check($GLOBALS['values']['Ch1DimmerSwitch']===false,'Off reference stays off');
invoke($d,'ApplySceneReferenceEnd',['channel'=>1,'type'=>'dimmer','level'=>60]);check($GLOBALS['values']['Ch1DimmerSwitch']===true,'Legacy references remain compatible');

foreach(['stop','on','off','toggle','dim_up','dim_down','close','open','move_close','move_open','recall_s1','recall_s4','store_s1','delete_s4','dim_up_100','dim_down_32000','move_open_100','move_close_32000'] as $cmd){invoke($d,'ValidateCommand',$cmd);$checks++;}
foreach(['recall_s0','recall_s5','move_open_99','move_close_32001','invalid'] as $cmd){try{invoke($d,'ValidateCommand',$cmd);throw new RuntimeException('Accepted '.$cmd);}catch(InvalidArgumentException $e){$checks++;}}
foreach(array_merge(glob(dirname(__DIR__).'/*/module.json'),[dirname(__DIR__).'/library.json']) as $file){json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);$checks++;}
echo "$checks regression checks passed.\n";

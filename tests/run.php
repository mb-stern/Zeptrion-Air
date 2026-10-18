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
$d->buffers['NotifyParent']='11';$d->active=false;$d->MessageSink(time(),11,IM_CHANGESTATUS,[202]);check($d->buffers['NotifyPending']==='' && $d->timers['RecoveryTimer']===0,'Inactive parent stops recovery timer and waits for status event');
$d->active=true;$d->RecoveryTick();check($d->buffers['NotifyPending']==='scan','Reconnected parent resumes scan');

// Reproduce a timer callback interleaved with processing a valid HTTP response.
foreach([false,true] as $notifyResponse){
 $d=new TestDevice();$d->props['Channel1Type']='light';$d->buffers['NotifyParent']='11';$d->StartNotifyListener();
 if($notifyResponse)$d->ReceiveData(json_encode(['Buffer'=>bin2hex($response)]));
 $before=count($d->sent);$parentChangesBefore=$GLOBALS['parentChanges']??[];$interleaved=false;
 $d->debugHook=function($label)use($d,&$interleaved){
  if($label!=='CHNOTIFY EVENT' || $interleaved)return;
  $interleaved=true;$sent=count($d->sent);
  $d->RecoveryTick();$d->MessageSink(time(),11,IM_CHANGESTATUS,[102]);invoke($d,'CheckNotifySocket');
  check(count($d->sent)===$sent,'Timer, ready event and info check cannot send while an HTTP response is being processed');
 };
 $d->buffers['NotifyDeadline']='1';$d->ReceiveData(json_encode(['Buffer'=>bin2hex($response)]));
 check($interleaved && count($d->sent)===$before+1 && str_starts_with(hex2bin(end($d->sent)['Buffer']),'GET /zrap/chnotify '),'Processed response sends exactly one next chnotify, without an extra chscan');
 check($d->buffers['NotifyPending']==='notify' && (float)$d->buffers['NotifyDeadline']>microtime(true)+30,'Response processing transfers directly to the next long-poll with fresh deadline');
 check(($GLOBALS['parentChanges']??[])===$parentChangesBefore,'Completed response is not mistaken for a socket timeout during processing');
}
$d=new TestDevice();$d->props['Channel1Type']='light';$d->StartNotifyListener();
$d->debugHook=function($label){if($label==='CHNOTIFY EVENT')throw new RuntimeException('Simulated processing failure');};
try{$d->ReceiveData(json_encode(['Buffer'=>bin2hex($response)]));throw new LogicException('Expected processing failure');}catch(RuntimeException $e){check($e->getMessage()==='Simulated processing failure','Processing failure propagates');}
$d->debugHook=null;
check($d->buffers['NotifyPending']==='' && $d->buffers['NotifyDeadline']==='0','Processing failure releases the response state');
$d->RecoveryTick();check($d->buffers['NotifyPending']==='scan' && count($d->sent)===2,'Watchdog can recover after a processing exception');
foreach([false,true] as $reconnected){
 $d=new TestDevice();$d->props['Channel1Type']='light';$d->buffers['NotifyParent']='11';$d->StartNotifyListener();
 $d->debugHook=function($label)use($d,$reconnected){
  if($label!=='CHNOTIFY EVENT')return;
  $d->debugHook=null;$d->active=false;$d->MessageSink(time(),11,IM_CHANGESTATUS,[202]);
  if($reconnected){$d->active=true;$d->MessageSink(time(),11,IM_CHANGESTATUS,[102]);}
 };
 $d->ReceiveData(json_encode(['Buffer'=>bin2hex($response)]));
 check($reconnected ? ($d->buffers['NotifyPending']==='scan' && count($d->sent)===2) : ($d->buffers['NotifyPending']==='' && count($d->sent)===1),'Response completion preserves a concurrent disconnect or a new reconnect scan');
 check($d->timers['RecoveryTimer']===($reconnected?5000:0),'Cancelled response cannot rearm the watchdog while socket is disconnected');
}

// A newly configured device waits neutrally until its socket and first response are ready.
$d=new TestDevice();$d->props['Channel1Type']='light';$d->props['ShowOnline']=true;$d->buffers['NotifyParent']='11';$d->active=false;
check(!$d->StartNotifyListener() && $d->status===104 && $d->sent===[] && $d->timers['RecoveryTimer']===0,'Initial socket startup is inactive/waiting, without an immediate error or send');
$d->MessageSink(time(),11,IM_CHANGESTATUS,[200]);$d->RecoveryTick();
check($d->status===104 && $d->sent===[],'Initial parent errors keep waiting until a first actual request is possible');
invoke($d,'SetCommunicationReady');
check($d->status===104,'Successful metadata/RSSI cannot mark an unconfirmed listener active');
$d->active=true;$d->MessageSink(time(),11,IM_CHANGESTATUS,[102]);
check($d->status===104 && $d->buffers['NotifyPending']==='scan' && count($d->sent)===1,'Ready socket immediately sends initial scan while awaiting first response');
$d->ReceiveData(json_encode(['Buffer'=>bin2hex($response)]));
check($d->status===102 && $d->buffers['NotifyPending']==='notify','First successful response activates device and starts chnotify');
$d->active=false;$d->MessageSink(time(),11,IM_CHANGESTATUS,[200]);
check($d->status===202,'Loss of an established listener is still a real communication error');
invoke($d,'SetCommunicationReady');
check($d->status===202,'Separate HTTP success cannot hide a failed established listener');
$d=new TestDevice();$d->props['Channel1Type']='light';$d->StartNotifyListener();
$d->ReceiveData(json_encode(['Buffer'=>bin2hex("HTTP/1.1 500 Error\r\nContent-Length: 0\r\n\r\n")]));
check($d->status===202,'An actual initial HTTP failure is not hidden as normal startup');
$d->active=false;$d->RecoveryTick();invoke($d,'SetCommunicationReady');
check($d->status===202,'A failed initial request retains its error while waiting for reconnect');
$d=new TestDevice();$d->sendResult=false;
check(!$d->StartNotifyListener() && $d->status===202,'Rejected initial socket send remains a genuine error');
$d=new TestDevice();$d->StartNotifyListener();$d->buffers['NotifyDeadline']='1';$d->RecoveryTick();
check($d->status===202,'Timeout of the first real request remains a genuine error');
$d=new TestDevice();$d->StartNotifyListener();$d->active=false;$d->RecoveryTick();
check($d->status===202,'Disconnect while the first actual scan is pending is not treated as mere startup');
$d=new TestDevice();$d->attrs['OwnMotorState']=json_encode(['1'=>['stopFailed'=>true]]);$d->active=false;$d->StartNotifyListener();
check($d->status===202,'Neutral startup cannot hide an unresolved failed motor stop');

// Reconnection is driven by ready events, with no polling of an inactive socket.
$d=new TestDevice();$d->buffers['NotifyParent']='11';$d->active=false;$d->StartNotifyListener();
$d->active=true;$d->MessageSink(time(),11,IM_CHANGESTATUS,[102]);
check(count($d->sent)===1 && $d->buffers['NotifyPending']==='scan','Ready status immediately starts scan without waiting for recovery timer');
$deadline=$d->buffers['NotifyDeadline'];$d->MessageSink(time(),11,IM_CHANGESTATUS,[102]);
check(count($d->sent)===1 && $d->buffers['NotifyDeadline']===$deadline,'Repeated ready status preserves pending scan and its deadline');
$d->ReceiveData(json_encode(['Buffer'=>bin2hex($response)]));$deadline=$d->buffers['NotifyDeadline'];
$d->MessageSink(time(),11,IM_CHANGESTATUS,[102]);
check(count($d->sent)===2 && $d->buffers['NotifyPending']==='notify' && $d->buffers['NotifyDeadline']===$deadline,'Ready status does not interrupt running chnotify');
$d->MessageSink(time(),12,IM_CHANGESTATUS,[202]);
check($d->buffers['NotifyPending']==='notify','Status of another socket does not affect listener');
$d->active=false;$d->MessageSink(time(),11,IM_CHANGESTATUS,[202]);
check($d->buffers['NotifyPending']==='' && $d->timers['RecoveryTimer']===0,'Inactive socket error waits for native reconnect without recovery polling');
$d->MessageSink(time(),11,IM_CHANGESTATUS,[102]);
check(count($d->sent)===2 && $d->timers['RecoveryTimer']===0,'Ready event cannot send or poll while physical parent chain is inactive');
$d->buffers['NotifyListening']='0';$d->active=true;$d->MessageSink(time(),11,IM_CHANGESTATUS,[102]);
check(count($d->sent)===2,'Disabled listener is not restarted by ready event');

// The existing info tick recovers a missed ready event, without dialing a closed socket.
$d=new TestDevice();$d->props['Host']='127.0.0.1:1';$d->props['Channel1Type']='light';$d->active=false;
$GLOBALS['instanceData'][11]=['InstanceStatus'=>202];
$GLOBALS['socketConfig'][11]=['Host'=>'zapp-19370093','Port'=>80,'Open'=>true];
$d->StartNotifyListener();$d->RefreshRuntimeInfo();
check($d->sent===[] && $d->timers['RecoveryTimer']===0,'Info tick leaves disconnected socket waiting without requests');
$d->active=true;$GLOBALS['instanceData'][11]['InstanceStatus']=102;
$d->RefreshRuntimeInfo();
check(count($d->sent)===1 && $d->buffers['NotifyPending']==='scan','Info tick starts scan when ready event was missed, even if independent RSSI lookup fails');
$snapshots=array_values(array_filter($d->debug,fn($entry)=>$entry[0]==='Socketstatus'));
$snapshot=json_decode(end($snapshots)[1],true,512,JSON_THROW_ON_ERROR);
check($snapshot['InstanzID']===11 && $snapshot['Status']===102 && $snapshot['Host']==='zapp-19370093' && $snapshot['Port']===80 && $snapshot['Open']===true && $snapshot['ParentAktiv']===true,'Device debug exposes actual socket ID, state and selected configuration');
$deadline=$d->buffers['NotifyDeadline'];$d->RefreshRuntimeInfo();
check(count($d->sent)===1 && $d->buffers['NotifyDeadline']===$deadline,'Info tick does not duplicate or reset pending scan');
$d->ReceiveData(json_encode(['Buffer'=>bin2hex($response)]));$d->RefreshRuntimeInfo();
check(count($d->sent)===2 && $d->buffers['NotifyPending']==='notify','Info fallback resumes chnotify and preserves running long-poll');
$d->ReceiveData(json_encode(['Buffer'=>bin2hex("HTTP/1.1 500 Error\r\nContent-Length: 0\r\n\r\n")]));
$d->RefreshRuntimeInfo();
check(count($d->sent)===2 && $d->timers['RecoveryTimer']===10000,'Info fallback respects scheduled recovery after active HTTP error');
$d->timers['RecoveryTimer']=0;$d->buffers['NotifyListening']='0';$d->RefreshRuntimeInfo();
check(count($d->sent)===2,'Info fallback cannot restart a disabled listener');
$d->buffers['NotifyListening']='1';$GLOBALS['instanceData'][99]=['ConnectionID'=>0];$d->RefreshRuntimeInfo();
check(count($d->sent)===2,'Missing parent is reported without starting a request');
unset($GLOBALS['instanceData'][99]);$GLOBALS['socketConfig'][11]['Open']=false;
$d->RefreshRuntimeInfo();
check(count($d->sent)===2 && $d->timers['RecoveryTimer']===0,'Info fallback respects disabled Open setting even with a stale active status');
$GLOBALS['socketConfig'][11]['Open']=true;$GLOBALS['instanceData'][11]['InstanceStatus']=202;
$d->RefreshRuntimeInfo();
check(count($d->sent)===2 && $d->timers['RecoveryTimer']===0,'Info fallback requires actual socket status 102 as well as an active parent chain');
unset($GLOBALS['instanceData'],$GLOBALS['socketConfig']);

// A responding notify socket must win over independent RSSI/DNS failures.
$d=new TestDevice();$d->props['Host']='127.0.0.1:1';$d->props['ShowOnline']=true;$d->buffers['NotifyParent']='11';
$d->attrs['CommunicationFailures']=8;$d->status=202;$GLOBALS['values']['Online']=false;
$d->StartNotifyListener();$d->ReceiveData(json_encode(['Buffer'=>bin2hex($response)]));
check($d->attrs['CommunicationFailures']===0 && $d->status===102 && $GLOBALS['values']['Online']===true,'Successful socket response clears accumulated RSSI failures and restores online status');
check($d->buffers['NotifyPending']==='notify' && count($d->sent)===2,'Recovered scan immediately starts chnotify');
for($i=0;$i<3;$i++)$d->RefreshRuntimeInfo();
check($d->attrs['CommunicationFailures']===0 && $d->status===102 && $GLOBALS['values']['Online']===true,'Repeated failed RSSI requests cannot mark a responding socket offline');
check($d->buffers['NotifyPending']==='notify' && count($d->sent)===2,'RSSI failure does not cancel or duplicate pending chnotify');
$d->ReceiveData(json_encode(['Buffer'=>bin2hex($response)]));
check($d->buffers['NotifyPending']==='notify' && count($d->sent)===3,'Next socket response continues long-poll after RSSI failures');
$d->active=false;$d->RecoveryTick();
check($d->timers['RecoveryTimer']===0 && $d->status===202 && $GLOBALS['values']['Online']===false,'Socket outage stops polling and reports offline');
$d->RecoveryTick();
check($d->timers['RecoveryTimer']===0 && count($d->sent)===3,'Even a manual recovery tick leaves an inactive socket waiting without requests');
$d->active=true;$d->MessageSink(time(),11,IM_CHANGESTATUS,[102]);
check($d->buffers['NotifyPending']==='scan' && count($d->sent)===4,'Ready status restarts scan after timer-free reconnect wait');
$d->ReceiveData(json_encode(['Buffer'=>bin2hex($response)]));
check($d->status===102 && $GLOBALS['values']['Online']===true && $d->buffers['NotifyPending']==='notify' && count($d->sent)===5,'Recovered socket clears outage and resumes chnotify');
$d->buffers['NotifyDeadline']='1';
for($i=0;$i<3;$i++)$d->RefreshRuntimeInfo();
check($d->attrs['CommunicationFailures']===3 && $d->status===202,'Expired socket response cannot mask a genuine HTTP communication failure');
$d->RecoveryTick();
check($d->timers['RecoveryTimer']===10000 && $d->buffers['NotifyOnline']==='0' && $d->buffers['NotifyPending']==='','Watchdog enters recovery for an unresponsive socket');
$d->RecoveryTick();$d->ReceiveData(json_encode(['Buffer'=>bin2hex("HTTP/1.1 500 Error\r\nContent-Length: 0\r\n\r\n")]));
check($d->attrs['CommunicationFailures']===3 && $d->status===202 && $d->buffers['NotifyOnline']==='0' && $d->timers['RecoveryTimer']===10000,'HTTP error cannot report connection recovered or stop retries');
$d->RecoveryTick();$d->attrs['OwnMotorState']=json_encode(['1'=>['stopFailed'=>true]]);
$d->ReceiveData(json_encode(['Buffer'=>bin2hex($response)]));
check($d->status===202 && $d->attrs['CommunicationFailures']===0 && $GLOBALS['values']['Online']===true,'Socket recovery preserves a separate failed motor stop');

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

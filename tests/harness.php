<?php
declare(strict_types=1);
// Isolated review harness. These doubles do not simulate a full Symcon kernel.
class IPSModuleStrict {
 public int $InstanceID=99;
 public array $props=['Host'=>'fixture','Channels'=>2,'Channel1Type'=>'shutter','Channel1LamellaTimeMs'=>1000,'RecoveryInterval'=>10];
 public array $attrs=['OwnMotorState'=>'{}','MotorRuntimeState'=>'{}','MotorLearnedTimes'=>'{}','PendingSceneReferences'=>'[]','SceneReferences'=>'{}','SmartButtonScenes'=>'[]'];
 public array $buffers=[],$timers=[],$sent=[],$logs=[]; public int $status=102;
 public bool $active=true;
 protected function ReadPropertyString($k): string {return (string)($this->props[$k]??'');}
 protected function ReadPropertyInteger($k): int {return (int)($this->props[$k]??27000);}
 protected function ReadPropertyBoolean($k): bool {return (bool)($this->props[$k]??false);}
 protected function ReadAttributeString($k): string {return (string)($this->attrs[$k]??'');}
 protected function WriteAttributeString($k,$v): void {$this->attrs[$k]=$v;}
 protected function ReadAttributeInteger($k): int {return (int)($this->attrs[$k]??0);}
 protected function WriteAttributeInteger($k,$v): void {$this->attrs[$k]=$v;}
 protected function SetBuffer($k,$v): void {$this->buffers[$k]=$v;}
 protected function GetBuffer($k): string {return (string)($this->buffers[$k]??'');}
 protected function SetTimerInterval($k,$v): void {$this->timers[$k]=$v;}
 protected function HasActiveParent(): bool {return $this->active;}
 protected function SendDataToParent($json): bool {$this->sent[]=json_decode($json,true); return true;}
 protected function SendDebug(...$args): void {}
 protected function SetStatus(int $status): void {$this->status=$status;}
 protected function LogMessage(string $message,int $severity): void {$this->logs[]=$message;}
 protected function GetIDForIdent(string $ident): int {return IPS_GetObjectIDByIdent($ident,$this->InstanceID);}
 protected function SetValue($ident,$v): void {$GLOBALS['values'][$ident]=$v;}
}
function IPS_GetObjectIDByIdent($ident,$parent) {$ids=&$GLOBALS["ids"];if(!is_array($ids))$ids=[];if(!isset($ids[$ident]))$ids[$ident]=count($ids)+1;return $ids[$ident];}
function IPS_VariableExists($id) {return true;}
function IPS_GetObject($id) {return ['ParentID'=>99,'ObjectIdent'=>array_search($id,$GLOBALS['ids'])];}
function GetValue($id) {return $GLOBALS['values'][array_search($id,$GLOBALS['ids'])]??0;}
function ZC_QueryServiceType($id,$type,$domain) {if(in_array($type,$GLOBALS['browseErrors']??[],true))throw new RuntimeException('Fetching addresses for services: error 87');return $GLOBALS['browseServices'][$type]??[['Name'=>'Living room','Type'=>$type.'.','Domain'=>'local.']];}
function ZC_QueryService($id,$name,$type,$domain) {$GLOBALS['serviceQueries'][]=[$name,$type,$domain];if($GLOBALS['resolveError']??false)throw new RuntimeException('Address lookup error 87');return [['Host'=>'zapp-19370098.local.','IPv4'=>['192.0.2.1'],'Port'=>$GLOBALS['servicePort']??80]];}
function IPS_GetInstance($id) {return ['ConnectionID'=>11];}
function IPS_SetProperty($id,$key,$value) {$GLOBALS['parentChanges'][]=[$id,$key,$value];}
function IPS_ApplyChanges($id) {}
function IPS_SemaphoreEnter($name,$timeout) {return true;}
function IPS_SemaphoreLeave($name) {}
define('IM_CHANGESTATUS',10505);
define('KL_ERROR',102);
require dirname(__DIR__) . '/ZeptrionAir/module.php';
require dirname(__DIR__) . '/ZeptrionAirDiscovery/module.php';
class TestDevice extends ZeptrionAir {
 public array $commands=[];
 public bool $commandResult=true;
 public function SendCommand(int $Channel,string $Command): bool {$this->commands[]=[$Channel,$Command];return $this->commandResult;}
}
function invoke($object,$method,...$args) {return (new ReflectionMethod($object,$method))->invokeArgs($object,$args);}

function IPS_GetInstanceListByModuleID($id) {return [];}

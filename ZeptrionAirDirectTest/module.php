<?php
declare(strict_types=1);

class ZeptrionAirDirectTest extends IPSModule
{
    private const TX = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';
    private const CS = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';

    public function Create()
    {
        parent::Create();
        $this->RequireParent(self::TX);

        $this->RegisterPropertyInteger('RecoveryInterval', 10);
        $this->RegisterPropertyInteger('LearnChannel', 1);
        $this->RegisterAttributeInteger('LearnedUpMs', 0);
        $this->RegisterAttributeInteger('LearnedDownMs', 0);
        $this->RegisterAttributeInteger('RolloPosition', -1);
        $this->RegisterAttributeString('LastDirection', '');
        $this->RegisterTimer('RecoveryTimer', 0, 'ZEPADT_RecoveryTick($_IPS["TARGET"]);');
        $this->RegisterTimer('LearnKickTimer', 0, 'ZEPADT_LearnKickTick($_IPS["TARGET"]);');

        $this->SetBuffer('Buffer', '');
        $this->SetBuffer('Listening', '0');
        $this->SetBuffer('Pending', '');
        $this->SetBuffer('Online', '0');

        $this->SetBuffer('LearnState', 'idle');
        $this->SetBuffer('LearnStartMs', '0');
        $this->SetBuffer('LearnDownMs', '0');
        $this->SetBuffer('LearnUpMs', '0');
        $this->SetBuffer('MoveStartMs', '0');
        $this->SetBuffer('MoveDirection', '');
        $this->SetBuffer('CommandDirection', '');
        $this->SetBuffer('EndpointTarget', '');
        $this->SetBuffer('NextAction', '');
    }

    public function GetCompatibleParents()
    {
        return json_encode(['type'=>'require','moduleIDs'=>[self::CS]]);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetBuffer('Buffer', '');
        $this->SetBuffer('Pending', '');
        $this->SetBuffer('Online', '0');
        $this->SetTimerInterval('RecoveryTimer', 0);
    }

    public function GetConfigurationForm()
    {
        $down=$this->ReadAttributeInteger('LearnedDownMs');
        $up=$this->ReadAttributeInteger('LearnedUpMs');
        $pos=$this->ReadAttributeInteger('RolloPosition');
        return json_encode([
            'elements'=>[
                ['type'=>'Label','caption'=>'V12 TEST: Best-Match + sichere 0/100%-Synchronpunkte.'],
                ['type'=>'NumberSpinner','name'=>'RecoveryInterval','caption'=>'Recovery-Intervall (Sekunden)','minimum'=>5,'maximum'=>300],
                ['type'=>'NumberSpinner','name'=>'LearnChannel','caption'=>'Rollo-Kanal (ch1 = 1, ch2 = 2)','minimum'=>1,'maximum'=>4],
                ['type'=>'Label','caption'=>'Lernstatus: '.$this->GetBuffer('LearnState')],
                ['type'=>'Label','caption'=>'Gelernte Fahrzeit HOCH: '.($up>0?number_format($up/1000,3,'.','').' s':'noch nicht gelernt')],
                ['type'=>'Label','caption'=>'Gelernte Fahrzeit RUNTER: '.($down>0?number_format($down/1000,3,'.','').' s':'noch nicht gelernt')],
                ['type'=>'Label','caption'=>'Berechnete Position: '.($pos>=0?$pos.' %':'noch unbekannt')],
                ['type'=>'Label','caption'=>'Laufzeit RUNTER: '.($down>0?number_format($down/1000,3,',','').' s':'-')],
                ['type'=>'Label','caption'=>'Laufzeit HOCH: '.($up>0?number_format($up/1000,3,',','').' s':'-')],
                ['type'=>'Label','caption'=>'ACHTUNG: Einlernen bewegt den Behang automatisch. Fahrweg frei halten.']
            ],
            'actions'=>[
                ['type'=>'Button','caption'=>'Listener starten / neu starten','onClick'=>'ZEPADT_StartListener($id);'],
                ['type'=>'Button','caption'=>'Listener stoppen','onClick'=>'ZEPADT_StopListener($id);'],
                ['type'=>'Button','caption'=>'Recovery jetzt testen','onClick'=>'ZEPADT_ForceRecovery($id);'],
                ['type'=>'Button','caption'=>'Laufzeiten einlernen','onClick'=>'ZEPADT_StartLearning($id);'],
                ['type'=>'Button','caption'=>'Einlernen abbrechen','onClick'=>'ZEPADT_AbortLearning($id);']
            ]
        ]);
    }

    public function StartListener()
    {
        $this->SetBuffer('Listening','1');
        $this->SetBuffer('Buffer','');
        $this->SetBuffer('Pending','');
        if (!$this->HasActiveParent()) {
            $this->EnterRecovery('Parent nicht aktiv');
            return false;
        }
        $this->SetTimerInterval('RecoveryTimer',0);
        return $this->SendRequest('/zrap/chscan','scan');
    }

    public function StopListener()
    {
        $this->AbortLearning(false);
        $this->SetBuffer('Listening','0');
        $this->SetBuffer('Pending','');
        $this->SetBuffer('Buffer','');
        $this->SetBuffer('Online','0');
        $this->SetTimerInterval('RecoveryTimer',0);
        $this->SendDebug('STATE','Listener gestoppt',0);
        return true;
    }

    public function ForceRecovery()
    {
        if ($this->GetBuffer('Listening')!=='1') $this->SetBuffer('Listening','1');
        $this->EnterRecovery('Recovery manuell ausgeloest');
    }

    public function MoveToEndpoint(int $target)
    {
        if ($target!==0 && $target!==100) return false;
        if ($this->GetBuffer('LearnState')!=='idle') return false;
        if ($this->GetBuffer('Pending')!=='') {
            $this->SendDebug('ROLLO TARGET','noch HTTP Pending='.$this->GetBuffer('Pending').' -> Zielbefehl momentan nicht gesendet',0);
            return false;
        }

        $dir=$target===0 ? 'up' : 'down';
        $this->SetBuffer('CommandDirection',$dir);
        $this->SetBuffer('EndpointTarget',(string)$target);
        $this->SendDebug('ROLLO TARGET','Variable/Ziel '.$target.'% -> '.strtoupper($dir),0);
        return $this->SendCommand($target===0 ? 'open' : 'close','endpoint_'.$target);
    }

    public function StartLearning()
    {
        if ($this->GetBuffer('Listening')!=='1') {
            $this->SendDebug('LEARN','Bitte zuerst Listener starten.',0);
            return false;
        }
        if (!$this->HasActiveParent()) {
            $this->SendDebug('LEARN','Client Socket nicht aktiv.',0);
            return false;
        }
        if ($this->GetBuffer('LearnState')!=='idle') {
            $this->SendDebug('LEARN','Einlernen laeuft bereits.',0);
            return false;
        }

        $this->SetBuffer('LearnDownMs','0');
        $this->SetBuffer('LearnUpMs','0');
        $this->SetBuffer('CommandDirection','');
        $this->SetBuffer('LearnStartMs','0');
        $this->SetBuffer('LearnState','reference_pending');
        $this->SetBuffer('NextAction','open_reference');
        $this->SendDebug('LEARN','START: zuerst HOCH bis Endanschlag. Diese Fahrt wird NICHT gemessen.',0);

        if ($this->GetBuffer('Pending')==='notify') {
            $socketID=$this->GetSocketID();
            if ($socketID>0 && IPS_InstanceExists($socketID) && (bool)IPS_GetProperty($socketID,'Open')) {
                $this->SendDebug('LEARN','laufendes chnotify abbrechen -> Socket kurz neu starten',0);
                $this->SetBuffer('Pending','');
                $this->SetBuffer('Buffer','');
                IPS_SetProperty($socketID,'Open',false);
                IPS_ApplyChanges($socketID);
                $this->SetTimerInterval('LearnKickTimer',500);
                return true;
            }
        }

        $this->RunNextActionIfPossible();
        return true;
    }

    public function LearnKickTick()
    {
        $this->SetTimerInterval('LearnKickTimer',0);
        if ($this->GetBuffer('LearnState')==='idle') return false;

        $socketID=$this->GetSocketID();
        if ($socketID<=0 || !IPS_InstanceExists($socketID)) {
            $this->SendDebug('LEARN','Socket fuer Lernstart nicht gefunden -> Abbruch',0);
            $this->AbortLearning(false);
            return false;
        }

        if (!(bool)IPS_GetProperty($socketID,'Open')) {
            $this->SendDebug('LEARN','Socket wieder oeffnen',0);
            IPS_SetProperty($socketID,'Open',true);
            IPS_ApplyChanges($socketID);
            $this->SetTimerInterval('LearnKickTimer',250);
            return true;
        }

        if (!$this->HasActiveParent()) {
            $this->SetTimerInterval('LearnKickTimer',250);
            return true;
        }

        $this->SendDebug('LEARN','Socket ACTIVE -> Referenzfahrt jetzt starten',0);
        return $this->RunNextActionIfPossible();
    }

    private function GetSocketID(): int
    {
        $instance=IPS_GetInstance($this->InstanceID);
        return (int)($instance['ConnectionID'] ?? 0);
    }

    public function AbortLearning(bool $sendStop = true)
    {
        $wasLearning=$this->GetBuffer('LearnState')!=='idle';
        $this->SetTimerInterval('LearnKickTimer',0);
        $this->SetBuffer('LearnState','idle');
        $this->SetBuffer('LearnStartMs','0');
        $this->SetBuffer('NextAction','');
        $this->SetBuffer('CommandDirection','');
        if ($wasLearning) $this->SendDebug('LEARN','ABGEBROCHEN',0);

        if ($sendStop && $this->GetBuffer('Pending')==='' && $this->HasActiveParent()) {
            $this->SendCommand('stop','abort_stop');
        }
        return true;
    }

    public function RecoveryTick()
    {
        if ($this->GetBuffer('LearnState')!=='idle') {
            $this->SendDebug('RECOVERY','gesperrt: Einlernen aktiv',0);
            return;
        }

        if ($this->GetBuffer('Listening')!=='1') {
            $this->SetTimerInterval('RecoveryTimer',0);
            return;
        }

        if (!$this->HasActiveParent()) {
            $this->SendDebug('RECOVERY','Client Socket noch nicht aktiv -> auf Symcon-Reconnect warten',0);
            return;
        }

        if ($this->GetBuffer('Pending')!=='') {
            $this->SetBuffer('Pending','');
            $this->SetBuffer('Buffer','');
        }

        $this->SendDebug('RECOVERY','Probe mit chscan',0);
        $this->SendRequest('/zrap/chscan','scan');
    }

    public function ReceiveData($JSONString)
    {
        $d=json_decode($JSONString,true);
        if (!is_array($d) || !isset($d['Buffer']) || $d['Buffer']==='') return;

        $buffer=$this->GetBuffer('Buffer').(string)$d['Buffer'];

        while (true) {
            $r=$this->Extract($buffer);
            if ($r===null) break;
            $buffer=$r['rest'];

            $kind=$this->GetBuffer('Pending');
            $this->SetBuffer('Pending','');
            $this->SetBuffer('Online','1');

            $this->SendDebug('HTTP',(string)$r['status'].' '.$kind,0);

            if ($r['status']!==200 && $r['status']!==302) {
                $this->EnterRecovery('HTTP Status '.$r['status']);
                continue;
            }

            if ($kind==='scan') {
                $this->ProcessScan($r['body']);
                $this->SetTimerInterval('RecoveryTimer',0);
                $this->SendDebug('RECOVERY','chscan OK -> Recovery AUS',0);
                if ($this->GetBuffer('Listening')==='1') {
                    $this->SendDebug('STATE','chscan OK -> chnotify aktivieren',0);
                }
            } elseif ($kind==='notify') {
                $this->ProcessNotify($r['body']);
            } elseif (str_starts_with($kind,'cmd:')) {
                $this->SendDebug('COMMAND','HTTP bestaetigt: '.substr($kind,4),0);
            }

            if ($this->GetBuffer('Listening')==='1' && $this->GetBuffer('Pending')==='') {
                if (!$this->RunNextActionIfPossible()) {
                    $this->SendRequest('/zrap/chnotify','notify');
                }
            }
        }

        $this->SetBuffer('Buffer',$buffer);
    }

    private function EnterRecovery(string $reason): void
    {
        $this->SetBuffer('Online','0');
        $this->SetBuffer('Pending','');
        $this->SetBuffer('Buffer','');

        if ($this->GetBuffer('LearnState')!=='idle') {
            $this->SetBuffer('LearnState','idle');
            $this->SetBuffer('NextAction','');
            $this->SetBuffer('LearnStartMs','0');
            $this->SendDebug('LEARN','Einlernen wegen Verbindungsproblem abgebrochen.',0);
        }

        $sec=max(5,$this->ReadPropertyInteger('RecoveryInterval'));
        $this->SetTimerInterval('RecoveryTimer',$sec*1000);
        $this->SendDebug('RECOVERY',$reason.' -> alle '.$sec.' s chscan versuchen',0);
    }

    private function RunNextActionIfPossible(): bool
    {
        if ($this->GetBuffer('Pending')!=='') return false;

        $action=$this->GetBuffer('NextAction');
        if ($action==='') return false;
        $this->SetBuffer('NextAction','');

        if ($action==='open_reference') {
            $this->SetBuffer('LearnState','reference_wait_start');
            $this->SendDebug('LEARN','1/3: HOCH Referenzfahrt senden',0);
            $this->SetBuffer('CommandDirection','up');
            return $this->SendCommand('open','open_reference');
        }
        if ($action==='close_measure') {
            $this->SetBuffer('LearnState','down_wait_start');
            $this->SendDebug('LEARN','2/3: RUNTER Messfahrt senden',0);
            $this->SetBuffer('CommandDirection','down');
            return $this->SendCommand('close','close_measure');
        }
        if ($action==='open_measure') {
            $this->SetBuffer('LearnState','up_wait_start');
            $this->SendDebug('LEARN','3/3: HOCH Messfahrt senden',0);
            $this->SetBuffer('CommandDirection','up');
            return $this->SendCommand('open','open_measure');
        }
        return false;
    }

    private function SendCommand(string $cmd,string $tag): bool
    {
        if ($this->GetBuffer('Listening')!=='1' || !$this->HasActiveParent()) {
            $this->EnterRecovery('Befehl senden nicht moeglich');
            return false;
        }
        if ($this->GetBuffer('Pending')!=='') return false;

        $ch=max(1,$this->ReadPropertyInteger('LearnChannel'));
        $body='cmd='.rawurlencode($cmd);
        $q="POST /zrap/chctrl/ch".$ch." HTTP/1.1\r\n".
           "Host: zeptrion\r\n".
           "Content-Type: application/x-www-form-urlencoded\r\n".
           "Content-Length: ".strlen($body)."\r\n".
           "Connection: keep-alive\r\n\r\n".$body;

        $this->SetBuffer('Pending','cmd:'.$tag);
        $this->SendDebug('TX','ch'.$ch.' cmd='.$cmd.' ('.$tag.')',0);
        $ok=$this->SendDataToParent(json_encode(['DataID'=>self::TX,'Buffer'=>$q]));

        if ($ok===false) {
            $this->SetBuffer('Pending','');
            $this->EnterRecovery('SendDataToParent fuer Befehl fehlgeschlagen');
            return false;
        }
        return true;
    }

    private function SendRequest(string $path,string $kind): bool
    {
        if ($this->GetBuffer('Listening')!=='1' || !$this->HasActiveParent()) {
            $this->EnterRecovery('Senden nicht moeglich');
            return false;
        }

        if ($this->GetBuffer('Pending')!=='') return false;

        $q="GET ".$path." HTTP/1.1\r\n".
           "Host: zeptrion\r\n".
           "Accept: application/xml,text/xml,*/*\r\n".
           "Cache-Control: no-cache\r\n".
           "Connection: keep-alive\r\n\r\n";

        $this->SetBuffer('Pending',$kind);
        $this->SendDebug('TX',$kind.' '.$path,0);
        $ok=$this->SendDataToParent(json_encode(['DataID'=>self::TX,'Buffer'=>$q]));

        if ($ok===false) {
            $this->SetBuffer('Pending','');
            $this->EnterRecovery('SendDataToParent fehlgeschlagen');
            return false;
        }
        return true;
    }

    private function ProcessScan(string $body): void
    {
        $this->SendDebug('CHSCAN RAW',trim($body),0);
        $x=@simplexml_load_string(trim($body));
        if ($x===false) return;
        foreach ($x->children() as $c=>$n) {
            $v=isset($n->val)?(string)$n->val:'';
            $this->SendDebug('SYNC',(string)$c.' = '.$v,0);
        }
    }

    private function ProcessNotify(string $body): void
    {
        $this->SendDebug('NOTIFY RAW',trim($body),0);
        $x=@simplexml_load_string(trim($body));
        if ($x===false) return;

        foreach ($x->children() as $c=>$n) {
            $v=isset($n->val)?(int)$n->val:-1;
            $msg=(string)$c.' = '.$v;
            $this->SendDebug('EVENT',$msg,0);
            $this->LogMessage('chnotify '.$msg,KL_MESSAGE);
            $this->ProcessRolloEvent((string)$c,$v);
            $this->ProcessLearningEvent((string)$c,$v);
        }
    }

    private function ProcessRolloEvent(string $channel,int $value): void
    {
        $target='ch'.max(1,$this->ReadPropertyInteger('LearnChannel'));
        if ($channel!==$target) return;
        if ($this->GetBuffer('LearnState')!=='idle') return;

        $now=(int)round(microtime(true)*1000);

        if ($value===100) {
            $pos=$this->ReadAttributeInteger('RolloPosition');
            $dir=$this->GetBuffer('CommandDirection');
            $this->SetBuffer('CommandDirection','');

            if ($dir==='') {
                if ($pos===0) $dir='down';
                elseif ($pos===100) $dir='up';
                else $dir='unknown';
            }

            $this->SetBuffer('MoveStartMs',(string)$now);
            $this->SetBuffer('MoveDirection',$dir);
            $this->SendDebug('ROLLO','Fahrt START | Position='.($pos>=0?$pos.'%':'unbekannt').' | Richtung='.$dir,0);
            return;
        }

        if ($value!==0) return;

        $start=(int)$this->GetBuffer('MoveStartMs');
        if ($start<=0) return;
        $elapsed=max(0,$now-$start);
        $this->SetBuffer('MoveStartMs','0');

        $dir=$this->GetBuffer('MoveDirection');
        $this->SetBuffer('MoveDirection','');
        $endpointTarget=$this->GetBuffer('EndpointTarget');
        $this->SetBuffer('EndpointTarget','');

        $pos=$this->ReadAttributeInteger('RolloPosition');
        $up=$this->ReadAttributeInteger('LearnedUpMs');
        $down=$this->ReadAttributeInteger('LearnedDownMs');

        if ($pos<0 || $up<=0 || $down<=0) {
            $this->SendDebug('ROLLO','Fahrt ENDE nach '.$elapsed.' ms | Position unbekannt: Lernwerte/Synchronpunkt fehlen',0);
            return;
        }

        $expectUp=(int)round($pos*$up/100);
        $expectDown=(int)round((100-$pos)*$down/100);
        $errUp=abs($elapsed-$expectUp);
        $errDown=abs($elapsed-$expectDown);

        if ($dir==='unknown') {
            if ($errUp < $errDown) {
                $dir='up';
            } elseif ($errDown < $errUp) {
                $dir='down';
            } else {
                $last=$this->ReadAttributeString('LastDirection');
                $dir=($last==='up') ? 'down' : (($last==='down') ? 'up' : 'unknown');
            }

            $this->SendDebug(
                'ROLLO MATCH',
                'gemessen='.number_format($elapsed/1000,3,'.','').'s | UP bis 0='.number_format($expectUp/1000,3,'.','').'s (Fehler '.number_format($errUp/1000,3,'.','').'s) | DOWN bis 100='.number_format($expectDown/1000,3,'.','').'s (Fehler '.number_format($errDown/1000,3,'.','').'s) -> '.$dir,
                0
            );
        }

        if ($dir==='up') {
            $delta=(int)round($elapsed*100/$up);
            $new=max(0,$pos-$delta);
            $tol=max(750,(int)round($expectUp*0.08));
            if ($elapsed >= $expectUp-$tol) $new=0;
            $this->SetRolloPosition($new);
            $this->WriteAttributeString('LastDirection','up');
        } elseif ($dir==='down') {
            $delta=(int)round($elapsed*100/$down);
            $new=min(100,$pos+$delta);
            $tol=max(750,(int)round($expectDown*0.08));
            if ($elapsed >= $expectDown-$tol) $new=100;
            $this->SetRolloPosition($new);
            $this->WriteAttributeString('LastDirection','down');
        } else {
            $this->SendDebug('ROLLO','Richtung nicht bestimmbar -> Position bleibt '.$pos.'%',0);
            return;
        }

        if ($endpointTarget==='0' || $endpointTarget==='100') {
            $forced=(int)$endpointTarget;
            $this->SetRolloPosition($forced);
            $this->WriteAttributeString('LastDirection',$forced===0 ? 'up' : 'down');
            $this->SendDebug('ROLLO SYNC','explizites Variablen-Ziel erreicht -> Position hart auf '.$forced.'%',0);
        }
        $this->SendDebug('ROLLO','Fahrt ENDE | '.$elapsed.' ms | Richtung='.$dir.' | Position='.$this->ReadAttributeInteger('RolloPosition').'%',0);
    }

    private function SetRolloPosition(int $position): void
    {
        $position=max(0,min(100,$position));
        $this->WriteAttributeInteger('RolloPosition',$position);
        $id=@$this->GetIDForIdent('RolloPosition');
        if ($id>0 && (int)GetValue($id)!==$position) $this->SetValue('RolloPosition',$position);
    }

    private function ProcessLearningEvent(string $channel,int $value): void
    {
        $target='ch'.max(1,$this->ReadPropertyInteger('LearnChannel'));
        if ($channel!==$target) return;

        $state=$this->GetBuffer('LearnState');
        if ($state==='idle') return;

        $now=(int)round(microtime(true)*1000);

        if ($value===100) {
            if ($state==='reference_wait_start') {
                $this->SetBuffer('LearnState','reference_run');
                $this->SendDebug('LEARN','1/3: Referenzfahrt HOCH laeuft',0);
            } elseif ($state==='down_wait_start') {
                $this->SetBuffer('LearnStartMs',(string)$now);
                $this->SetBuffer('LearnState','down_run');
                $this->SendDebug('LEARN','2/3: RUNTER Zeitmessung gestartet',0);
            } elseif ($state==='up_wait_start') {
                $this->SetBuffer('LearnStartMs',(string)$now);
                $this->SetBuffer('LearnState','up_run');
                $this->SendDebug('LEARN','3/3: HOCH Zeitmessung gestartet',0);
            }
            return;
        }

        if ($value!==0) return;

        if ($state==='reference_run') {
            $this->SetBuffer('LearnState','between');
            $this->SetBuffer('NextAction','close_measure');
            $this->SetRolloPosition(0);
            $this->SendDebug('LEARN','1/3: oberer Anschlag erreicht -> Position 0%. Referenzzeit verworfen.',0);
        } elseif ($state==='down_run') {
            $ms=$now-(int)$this->GetBuffer('LearnStartMs');
            if ($ms<500) {
                $this->SendDebug('LEARN','FEHLER: RUNTER-Laufzeit unplausibel kurz.',0);
                $this->AbortLearning(false);
                return;
            }
            $this->SetBuffer('LearnDownMs',(string)$ms);
            $this->WriteAttributeInteger('LearnedDownMs',$ms);
            $this->SetRolloPosition(100);
            $this->SetBuffer('LearnState','between');
            $this->SetBuffer('NextAction','open_measure');
            $this->SendDebug('LEARN','2/3: RUNTER = '.number_format($ms/1000,3,'.','').' s -> Position 100%.',0);
        } elseif ($state==='up_run') {
            $ms=$now-(int)$this->GetBuffer('LearnStartMs');
            if ($ms<500) {
                $this->SendDebug('LEARN','FEHLER: HOCH-Laufzeit unplausibel kurz.',0);
                $this->AbortLearning(false);
                return;
            }
            $this->SetBuffer('LearnUpMs',(string)$ms);
            $this->WriteAttributeInteger('LearnedUpMs',$ms);
            $this->SetRolloPosition(0);
            $this->WriteAttributeString('LastDirection','up');
            $this->SetBuffer('LearnState','idle');
            $this->SetBuffer('LearnStartMs','0');
            $this->SetBuffer('CommandDirection','');
            $this->SendDebug(
                'LEARN',
                'FERTIG | RUNTER '.number_format(((int)$this->GetBuffer('LearnDownMs'))/1000,3,'.','').' s | HOCH '.number_format($ms/1000,3,'.','').' s | Position = 0%',
                0
            );
        }
    }

    private function Extract(string $b): ?array
    {
        $he=strpos($b,"\r\n\r\n");
        if ($he===false) return null;
        $h=substr($b,0,$he); $bs=$he+4;
        $ls=explode("\r\n",$h); $sl=array_shift($ls); $st=0;
        if (preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})/',(string)$sl,$m)) $st=(int)$m[1];

        $hs=[];
        foreach($ls as $l){
            $p=strpos($l,':');
            if($p!==false)$hs[strtolower(trim(substr($l,0,$p)))]=trim(substr($l,$p+1));
        }

        if(isset($hs['content-length'])){
            $n=(int)$hs['content-length'];
            if(strlen($b)<$bs+$n)return null;
            return ['status'=>$st,'body'=>substr($b,$bs,$n),'rest'=>substr($b,$bs+$n)];
        }

        if($st===302 || $st===204) {
            return ['status'=>$st,'body'=>'','rest'=>substr($b,$bs)];
        }

        foreach(['</chnotify>','</chscan>'] as $tag){
            $p=strpos($b,$tag,$bs);
            if($p!==false){
                $e=$p+strlen($tag);
                return ['status'=>$st,'body'=>substr($b,$bs,$e-$bs),'rest'=>substr($b,$e)];
            }
        }
        return null;
    }
}

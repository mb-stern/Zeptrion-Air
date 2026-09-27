<?php
declare(strict_types=1);

class ZeptrionAirSplitter extends IPSModuleStrict
{
    private const DEVICE_MODULE_ID = '{75F3D2A4-9D4E-4E5C-A07E-8EFA49D824C1}';

    public function Create(): void { parent::Create(); $this->RegisterAttributeString('SmartButtonScenes','[]'); $this->RegisterAttributeString('SmartButtonToken',''); $this->RegisterHook('zeptrionair'); }
    public function ApplyChanges(): void { parent::ApplyChanges(); if($this->ReadAttributeString('SmartButtonToken')==='')$this->WriteAttributeString('SmartButtonToken',bin2hex(random_bytes(16))); $this->RegisterHook('zeptrionair'); $this->SetStatus(102); }
    public function GetConfigurationForm(): string { return json_encode(['elements'=>[['type'=>'Label','caption'=>'Zentraler Dienst für zeptrionAIR Smart-Taster.'],['type'=>'Label','caption'=>'WebHook: /hook/zeptrionair']],'status'=>[['code'=>102,'icon'=>'active','caption'=>'Aktiv']]],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); }

    protected function ProcessHookData(): void
    {
        if((string)($_GET['action']??'')==='run'){ $this->RunScene((string)($_GET['scene']??''),(string)($_GET['token']??'')); return; }
        if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))==='POST'){
            header('Content-Type: application/json; charset=utf-8'); $in=json_decode((string)file_get_contents('php://input'),true);
            if(!is_array($in)){echo json_encode(['ok'=>false,'message'=>'Ungültige Anfrage']);return;}
            switch((string)($in['op']??'')){
                case 'program': echo json_encode($this->ProgramScene($in),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);return;
                case 'forget': echo json_encode($this->ForgetScene((string)($in['scene']??'')),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);return;
                case 'select-delete': echo json_encode($this->SelectDelete(),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);return;
            }
            echo json_encode(['ok'=>false,'message'=>'Unbekannte Aktion']);return;
        }
        header('Content-Type: text/html; charset=utf-8'); echo $this->BuildInterface();
    }

    private function ProgramScene(array $in): array
    {
        $name=trim((string)($in['name']??''))?:'Szene'; $targets=$this->NormalizeTargets(is_array($in['targets']??null)?$in['targets']:[]);
        if($targets===[])return ['ok'=>false,'message'=>'Bitte mindestens ein Ziel auswählen.'];
        $id=preg_replace('/[^a-zA-Z0-9_-]/','',(string)($in['scene']??''))?:bin2hex(random_bytes(6)); $sel=$this->SelectSmartButton();
        if(!$sel['success'])return ['ok'=>false,'message'=>$sel['message']];
        $hh=(string)($_SERVER['HTTP_HOST']??''); if($hh==='')return ['ok'=>false,'message'=>'Symcon-Adresse konnte nicht ermittelt werden.'];
        $p=explode(':',$hh,2); $token=$this->ReadAttributeString('SmartButtonToken');
        $payload=['req'=>'GET','typ'=>'application/x-www-form-urlencoded','loc'=>$p[0],'prt'=>(string)(isset($p[1])?(int)$p[1]:3777),'pth'=>'/hook/zeptrionair?action=run&scene='.rawurlencode($id).'&token='.rawurlencode($token),'bdy'=>''];
        $r=$this->SmartButtonRequest((string)$sel['host'],'POST','/zapi/smartbt/prgs',$payload,5000); if(!$r['success'])return ['ok'=>false,'message'=>'Programmierung fehlgeschlagen: '.$r['message']];
        $scenes=$this->ReadScenes(); $entry=['id'=>$id,'name'=>$name,'targets'=>$targets,'smartButtonHost'=>$sel['host'],'smartButtonName'=>$sel['name'],'smartButtonInstance'=>$sel['instance']]; $found=false;
        foreach($scenes as &$s)if((string)($s['id']??'')===$id){$s=$entry;$found=true;break;} unset($s); if(!$found)$scenes[]=$entry; $this->WriteScenes($scenes);
        return ['ok'=>true,'message'=>'Smart-Taste wurde an „'.$sel['name'].'“ erkannt und mit „'.$name.'“ programmiert.','scenes'=>$scenes];
    }

    private function SelectDelete(): array { $s=$this->SelectSmartButton(); if(!$s['success'])return ['ok'=>false,'message'=>$s['message']]; return ['ok'=>true,'message'=>'Smart-Taste wurde an „'.$s['name'].'“ erkannt.']; }

    private function SelectSmartButton(): array
    {
        $devices=$this->GetDeviceHosts(); if($devices===[])return ['success'=>false,'message'=>'Keine zeptrionAIR-Geräte mit Host gefunden.']; $active=[];
        foreach($devices as $host=>$d){$r=$this->SmartButtonRequest($host,'POST','/zapi/smartbt/prgm',['on'=>true,'ntm'=>60],4000);if($r['success'])$active[$host]=$d;}
        if($active===[])return ['success'=>false,'message'=>'Programmiermodus konnte auf keinem zApp gestartet werden.'];
        $this->SendDebug('Smart-Taster','Warte auf Tastendruck auf '.count($active).' zApp(s): '.implode(', ',array_keys($active)),0); $multi=curl_multi_init();$handles=[];
        foreach($active as $host=>$d){$ch=curl_init();curl_setopt_array($ch,[CURLOPT_URL=>'http://'.$host.'/zapi/smartbt/prgn',CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT_MS=>2500,CURLOPT_TIMEOUT_MS=>65000,CURLOPT_HTTPHEADER=>['Connection: close']]);curl_multi_add_handle($multi,$ch);$handles[$host]=$ch;}
        $selected='';$deadline=microtime(true)+66;
        do{do{$st=curl_multi_exec($multi,$running);}while($st===CURLM_CALL_MULTI_PERFORM);foreach($handles as $host=>$ch){if((int)(curl_getinfo($ch)['http_code']??0)===200){$body=(string)curl_multi_getcontent($ch);if($body!==''){$this->SendDebug('Smart-Taster prgn RAW',$host.' / HTTP 200 / Antwort: '.$body,0);$j=json_decode($body,true);if(is_array($j)&&($j['prg']??false)===true){$selected=$host;break 2;}}}}if($running>0)curl_multi_select($multi,.2);}while($running>0&&microtime(true)<$deadline);
        foreach($handles as $ch){curl_multi_remove_handle($multi,$ch);curl_close($ch);}curl_multi_close($multi); if($selected==='')return ['success'=>false,'message'=>'Keine Smart-Taste erkannt.'];
        $d=$active[$selected];$this->SendDebug('Smart-Taster erkannt','zApp: '.$selected.' / Gerät: '.$d['name'].' / Instanz: '.$d['instance'],0);return ['success'=>true,'host'=>$selected,'name'=>$d['name'],'instance'=>$d['instance']];
    }

    private function SmartButtonRequest(string $host,string $method,string $path,?array $payload=null,int $timeoutMs=4000): array
    {
        $url='http://'.$host.$path;$ch=curl_init();$o=[CURLOPT_URL=>$url,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT_MS=>2000,CURLOPT_TIMEOUT_MS=>$timeoutMs,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>['Connection: close']];
        if($payload!==null){$body=json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$o[CURLOPT_POSTFIELDS]=$body;$o[CURLOPT_HTTPHEADER]=['Content-Type: application/json','Content-Length: '.strlen((string)$body),'Connection: close'];}
        curl_setopt_array($ch,$o);$resp=curl_exec($ch);$err=curl_error($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$raw=is_string($resp)?$resp:'';$this->SendDebug('Smart-Taster RAW',$method.' '.$url.' / HTTP '.$code.' / Antwort: '.$raw.($err!==''?' / Fehler: '.$err:''),0);
        return ['success'=>$err===''&&$code>=200&&$code<300,'message'=>$err!==''?$err:'HTTP '.$code.($raw!==''?' / '.$raw:''),'raw'=>$raw,'httpCode'=>$code];
    }

    private function ForgetScene(string $id): array { $s=array_values(array_filter($this->ReadScenes(),static fn(array $x):bool=>(string)($x['id']??'')!==$id));$this->WriteScenes($s);return ['ok'=>true,'message'=>'Eintrag wurde aus dem zeptrionAIR-Splitter entfernt.','scenes'=>$s]; }

    private function RunScene(string $id,string $token): void
    {
        if($token===''||!hash_equals($this->ReadAttributeString('SmartButtonToken'),$token)){http_response_code(403);echo 'Forbidden';return;}
        foreach($this->ReadScenes() as $s){if((string)($s['id']??'')!==$id)continue;foreach(($s['targets']??[]) as $t){try{$type=(string)($t['type']??'zeptrion');if($type==='zeptrion'){$i=(int)($t['instance']??0);if($this->IsDeviceInstance($i))ZEPA_RecallScene($i,(int)$t['channel'],(int)$t['memory']);}elseif($type==='symcon'){$oid=(int)($t['object']??0);if(IPS_VariableExists($oid))RequestAction($oid,$t['value']??null);elseif(IPS_ScriptExists($oid))IPS_RunScript($oid);}elseif($type==='variable'){ $oid=(int)($t['variable']??0);if(IPS_VariableExists($oid))RequestAction($oid,$t['value']??null);}elseif($type==='script'){ $oid=(int)($t['script']??0);if(IPS_ScriptExists($oid))IPS_RunScript($oid);}}catch(Throwable $e){$this->SendDebug('Smart-Taster Aktion',$e->getMessage(),0);}}echo 'OK';return;}http_response_code(404);echo 'Scene not found';
    }

    private function BuildInterface(): string
    {
        $data=json_encode(['scenes'=>$this->ReadScenes(),'zeptrionTargets'=>$this->GetTargets(),'objects'=>$this->GetSymconObjects()],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Smart-Taster</title><style>body{font-family:system-ui,sans-serif;max-width:1000px;margin:28px auto;padding:0 16px;background:#f5f5f5;color:#222}h1{font-size:24px}.card{background:#fff;border-radius:10px;padding:16px;margin:12px 0;box-shadow:0 1px 4px #0002}.row{display:flex;gap:8px;align-items:center;margin:8px 0;flex-wrap:wrap}input,select,button{font:inherit;padding:8px;border:1px solid #bbb;border-radius:6px}input{min-width:150px}select{min-width:180px}button{cursor:pointer}.name{flex:1}.danger{margin-left:auto}.status{padding:12px 0;min-height:24px;font-weight:600}.target{padding-left:12px;border-left:3px solid #ddd}.busy{opacity:.55;pointer-events:none}.hint,.source{color:#666;font-size:14px}.objfield{min-width:330px;text-align:left}.modal{position:fixed;inset:0;background:#0008;display:flex;align-items:center;justify-content:center;z-index:99}.modalbox{background:#fff;width:min(760px,92vw);max-height:80vh;border-radius:10px;padding:14px;overflow:auto}.treeitem{display:block;width:100%;text-align:left;border:0;border-radius:3px;background:transparent;padding:5px 8px}.treeitem:hover{background:#eee}.treeitem.disabled{color:#777;cursor:default}.search{width:calc(100% - 20px);margin:5px}.close{float:right}</style></head><body><h1>Smart-Taster konfigurieren</h1><p class="hint">Szene benennen, Ziele hinzufügen und danach direkt programmieren.</p><div id="scenes"></div><button id="addScene">+ Szene hinzufügen</button> <button id="clearButton">Smart-Taster löschen</button><div class="status" id="status"></div><script>const D='.$data.';let scenes=Array.isArray(D.scenes)?D.scenes:[];const Z=D.zeptrionTargets||[],O=D.objects||[];const el=id=>document.getElementById(id),mk=(t,x)=>{const e=document.createElement(t);if(x!==undefined)e.textContent=x;return e};function msg(x){el("status").textContent=x||""}function busy(v){document.body.classList.toggle("busy",!!v)}function opts(s,l,v){s.replaceChildren();l.forEach(x=>{const o=mk("option",x.caption);o.value=x.value;if(String(x.value)===String(v))o.selected=true;s.append(o)})}function obj(id){return O.find(x=>+x.id===+id)}function migrate(t){if(t.type==="variable"){t={type:"symcon",object:+t.variable,value:t.value}}else if(t.type==="script"){t={type:"symcon",object:+t.script}}return t}function pick(t,done){const m=mk("div");m.className="modal";const b=mk("div");b.className="modalbox";const x=mk("button","Schliessen");x.className="close";x.onclick=()=>m.remove();const h=mk("h3","Symcon-Objekt auswählen");const q=mk("input");q.className="search";q.placeholder="Suchen …";const list=mk("div");function fill(){const f=q.value.toLowerCase();list.replaceChildren();O.filter(o=>!f||o.path.toLowerCase().includes(f)).forEach(o=>{const e=mk("button",(o.type==="variable"?"◉ ":o.type==="script"?"▶ ":"▸ ")+o.path);e.className="treeitem"+(o.selectable?"":" disabled");if(o.selectable)e.onclick=()=>{t.object=+o.id;t.value=o.defaultValue??"";m.remove();done()};list.append(e)})}q.oninput=fill;b.append(x,h,q,list);m.append(b);document.body.append(m);fill()}function valueEditor(t,r){const o=obj(t.object);if(!o||o.type!=="variable")return;if(Array.isArray(o.associations)&&o.associations.length){const s=mk("select");opts(s,o.associations.map(a=>({value:a.value,caption:a.name})),t.value);s.onchange=()=>t.value=s.value;r.append(s)}else if(o.varType===0){const s=mk("select");opts(s,[{value:"false",caption:"Aus / False"},{value:"true",caption:"Ein / True"}],String(t.value));s.onchange=()=>t.value=s.value;r.append(s)}else{const v=mk("input");v.placeholder="Wert";v.value=t.value??"";v.oninput=()=>t.value=v.value;r.append(v)}}function render(){const root=el("scenes");root.replaceChildren();scenes.forEach((s,i)=>{s.targets=(s.targets||[]).map(migrate);const c=mk("div");c.className="card";const top=mk("div");top.className="row";const n=mk("input");n.className="name";n.value=s.name||"";n.oninput=()=>s.name=n.value;const f=mk("button","Aus Splitter entfernen");f.className="danger";f.onclick=()=>forget(s.id);top.append(n,f);c.append(top);if(s.smartButtonName){const src=mk("div","Smart-Taster: "+s.smartButtonName+(s.smartButtonHost?" ("+s.smartButtonHost+")":""));src.className="source";c.append(src)}s.targets.forEach((t,j)=>{const r=mk("div");r.className="row target";const typ=mk("select");opts(typ,[{value:"zeptrion",caption:"zeptrionAIR intern"},{value:"symcon",caption:"Symcon-Objekt"}],t.type||"zeptrion");typ.onchange=()=>{t.type=typ.value;if(t.type==="symcon"){delete t.instance;delete t.channel;delete t.memory}else delete t.object;render()};r.append(typ);if(t.type==="symcon"){const o=obj(t.object);const p=mk("button",o?o.path:"Objekt auswählen …");p.className="objfield";p.onclick=()=>pick(t,render);r.append(p);if(o){const tag=mk("span",o.type==="script"?"Script":"Variable");tag.className="source";r.append(tag);valueEditor(t,r)}}else{const q=mk("select");opts(q,Z,String(t.instance||0)+":"+String(t.channel||0));q.onchange=()=>{const a=q.value.split(":");t.instance=+a[0];t.channel=+a[1]};const mem=mk("select");opts(mem,[1,2,3,4].map(x=>({value:x,caption:"S"+x})),t.memory||1);mem.onchange=()=>t.memory=+mem.value;r.append(q,mem)}const d=mk("button","Entfernen");d.onclick=()=>{s.targets.splice(j,1);render()};r.append(d);c.append(r)});const a=mk("div");a.className="row";const add=mk("button","+ Ziel hinzufügen");add.onclick=()=>{const z=Z[0];s.targets.push(z?{type:"zeptrion",instance:z.instance,channel:z.channel,memory:1}:{type:"symcon",object:0});render()};const p=mk("button","Smart-Taste programmieren");p.onclick=()=>program(s);a.append(add,p);c.append(a);root.append(c)})}function addScene(){scenes.push({id:Math.random().toString(36).slice(2),name:"Neue Szene",targets:[]});render()}async function api(x){try{const r=await fetch("/hook/zeptrionair",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(x)});return await r.json()}catch(e){return{ok:false,message:e.message}}}async function program(s){if(!confirm("Die Smart-Tasten beginnen jetzt zu blinken. Bitte danach die gewünschte blinkende Smart-Taste am Schalter drücken."))return;msg("Smart-Tasten werden aktiviert. Bitte gewünschte blinkende Smart-Taste drücken …");busy(true);const r=await api({op:"program",scene:s.id,name:s.name,targets:s.targets});busy(false);if(r.ok&&r.scenes)scenes=r.scenes;render();msg(r.message)}async function forget(id){const r=await api({op:"forget",scene:id});if(r.ok&&r.scenes)scenes=r.scenes;render();msg(r.message)}async function clearButton(){if(!confirm("Die Smart-Tasten beginnen jetzt zu blinken. Bitte danach die Smart-Taste drücken, deren Programmierung gelöscht werden soll."))return;msg("Smart-Tasten werden aktiviert. Bitte die zu löschende Smart-Taste drücken …");busy(true);const r=await api({op:"select-delete"});busy(false);msg(r.message)}el("addScene").onclick=addScene;el("clearButton").onclick=clearButton;render();</script></body></html>';
    }

    private function GetDeviceHosts(): array { $r=[];foreach(IPS_GetInstanceListByModuleID(self::DEVICE_MODULE_ID) as $id){$h=trim((string)IPS_GetProperty($id,'Host'));if($h==='')continue;$n=trim(IPS_GetName($id));$r[$h]=['instance'=>$id,'name'=>$n!==''?$n:$h];}ksort($r,SORT_NATURAL);return $r; }
    private function GetTargets(): array { $r=[];foreach(IPS_GetInstanceListByModuleID(self::DEVICE_MODULE_ID) as $id){$cnt=max(1,min(4,(int)IPS_GetProperty($id,'Channels')));for($c=1;$c<=$cnt;$c++){if(strtolower((string)IPS_GetProperty($id,'Channel'.$c.'Type'))==='unused')continue;$n=trim((string)IPS_GetProperty($id,'Channel'.$c.'Name'));if($n==='')$n=IPS_GetName($id).' / Kanal '.$c;$r[]=['value'=>$id.':'.$c,'caption'=>$n,'instance'=>$id,'channel'=>$c];}}usort($r,static fn($a,$b)=>strnatcasecmp($a['caption'],$b['caption']));return $r; }

    private function GetSymconObjects(): array
    {
        $out=[];foreach(IPS_GetObjectList() as $id){$o=IPS_GetObject($id);$type=(int)$o['ObjectType'];$kind='other';$select=false;$varType=null;$assoc=[];$def='';
            if($type===2&&IPS_VariableExists($id)){$kind='variable';$select=true;$v=IPS_GetVariable($id);$varType=(int)$v['VariableType'];$profile=(string)($v['VariableCustomProfile']?:$v['VariableProfile']);if($profile!==''&&IPS_VariableProfileExists($profile)){$pr=IPS_GetVariableProfile($profile);foreach(($pr['Associations']??[]) as $a)$assoc[]=['value'=>$a['Value'],'name'=>$a['Name']];}if($varType===0)$def='false';}
            elseif($type===3&&IPS_ScriptExists($id)){$kind='script';$select=true;}
            $out[]=['id'=>$id,'path'=>$this->ObjectPath($id),'type'=>$kind,'selectable'=>$select,'varType'=>$varType,'associations'=>$assoc,'defaultValue'=>$def];
        }usort($out,static fn($a,$b)=>strnatcasecmp($a['path'],$b['path']));return $out;
    }
    private function ObjectPath(int $id): string { $p=[];$c=$id;for($i=0;$i<12&&$c>0;$i++){$p[]=IPS_GetName($c);$c=IPS_GetParent($c);}return implode(' / ',array_reverse($p)); }

    private function NormalizeTargets(array $targets): array
    {
        $out=[];foreach($targets as $t){if(!is_array($t))continue;$type=(string)($t['type']??'zeptrion');
            if($type==='zeptrion'){$i=(int)($t['instance']??0);$c=(int)($t['channel']??0);$m=(int)($t['memory']??0);if($this->IsDeviceInstance($i)&&$c>=1&&$c<=4&&$m>=1&&$m<=4)$out[]=['type'=>'zeptrion','instance'=>$i,'channel'=>$c,'memory'=>$m];}
            elseif($type==='symcon'){$id=(int)($t['object']??0);if(IPS_VariableExists($id)){$v=IPS_GetVariable($id);$value=$t['value']??'';switch((int)$v['VariableType']){case 0:$value=filter_var($value,FILTER_VALIDATE_BOOLEAN);break;case 1:$value=(int)$value;break;case 2:$value=(float)$value;break;default:$value=(string)$value;}$out[]=['type'=>'symcon','object'=>$id,'value'=>$value];}elseif(IPS_ScriptExists($id))$out[]=['type'=>'symcon','object'=>$id];}
            elseif($type==='variable'){$id=(int)($t['variable']??0);if(IPS_VariableExists($id))$out[]=['type'=>'symcon','object'=>$id,'value'=>$t['value']??''];}
            elseif($type==='script'){$id=(int)($t['script']??0);if(IPS_ScriptExists($id))$out[]=['type'=>'symcon','object'=>$id];}
        }return $out;
    }
    private function IsDeviceInstance(int $id): bool { if($id<=0||!IPS_InstanceExists($id))return false;$i=IPS_GetInstance($id);return(string)($i['ModuleInfo']['ModuleID']??'')===self::DEVICE_MODULE_ID; }
    private function ReadScenes(): array { $x=json_decode($this->ReadAttributeString('SmartButtonScenes'),true);return is_array($x)?$x:[]; }
    private function WriteScenes(array $x): void { $this->WriteAttributeString('SmartButtonScenes',json_encode(array_values($x),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)); }
}

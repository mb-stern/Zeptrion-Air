<?php
declare(strict_types=1);

class ZeptrionAirSplitter extends IPSModuleStrict
{
    private const DEVICE_MODULE_ID = '{75F3D2A4-9D4E-4E5C-A07E-8EFA49D824C1}';

    public function Create(): void
    {
        parent::Create();
        $this->RegisterAttributeString('SmartButtonScenes', '[]');
        $this->RegisterAttributeString('SmartButtonToken', '');
        $this->RegisterHook('zeptrionair');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        if ($this->ReadAttributeString('SmartButtonToken') === '') $this->WriteAttributeString('SmartButtonToken', bin2hex(random_bytes(16)));
        $this->RegisterHook('zeptrionair');
        $this->SetStatus(102);
    }

    public function GetConfigurationForm(): string
    {
        return json_encode(['elements' => [
            ['type' => 'Label', 'caption' => 'Zentraler Dienst für zeptrionAIR Smart-Taster.'],
            ['type' => 'Label', 'caption' => 'WebHook: /hook/zeptrionair']
        ], 'status' => [['code' => 102, 'icon' => 'active', 'caption' => 'Aktiv']]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected function ProcessHookData(): void
    {
        if ((string)($_GET['action'] ?? '') === 'run') {
            $this->RunScene((string)($_GET['scene'] ?? ''), (string)($_GET['token'] ?? ''));
            return;
        }
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
            header('Content-Type: application/json; charset=utf-8');
            $input = json_decode((string)file_get_contents('php://input'), true);
            if (!is_array($input)) { echo json_encode(['ok' => false, 'message' => 'Ungültige Anfrage']); return; }
            switch ((string)($input['op'] ?? '')) {
                case 'program': echo json_encode($this->ProgramScene($input), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); return;
                case 'forget': echo json_encode($this->ForgetScene((string)($input['scene'] ?? '')), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); return;
                case 'select-delete': echo json_encode($this->SelectDelete(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); return;
            }
            echo json_encode(['ok' => false, 'message' => 'Unbekannte Aktion']); return;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo $this->BuildInterface();
    }

    private function ProgramScene(array $input): array
    {
        $name = trim((string)($input['name'] ?? '')) ?: 'Szene';
        $targets = $this->NormalizeTargets(is_array($input['targets'] ?? null) ? $input['targets'] : []);
        if ($targets === []) return ['ok' => false, 'message' => 'Bitte mindestens ein Ziel auswählen.'];
        $sceneID = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($input['scene'] ?? '')) ?: bin2hex(random_bytes(6));
        $selected = $this->SelectSmartButton();
        if (!$selected['success']) return ['ok' => false, 'message' => $selected['message']];

        $hostHeader = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($hostHeader === '') return ['ok' => false, 'message' => 'Symcon-Adresse konnte nicht ermittelt werden.'];
        $parts = explode(':', $hostHeader, 2);
        $token = $this->ReadAttributeString('SmartButtonToken');
        $payload = ['req' => 'GET', 'typ' => 'application/x-www-form-urlencoded', 'loc' => $parts[0], 'prt' => (string)(isset($parts[1]) ? (int)$parts[1] : 3777), 'pth' => '/hook/zeptrionair?action=run&scene=' . rawurlencode($sceneID) . '&token=' . rawurlencode($token), 'bdy' => ''];
        $result = $this->SmartButtonRequest((string)$selected['host'], 'POST', '/zapi/smartbt/prgs', $payload, 5000);
        if (!$result['success']) return ['ok' => false, 'message' => 'Programmierung fehlgeschlagen: ' . $result['message']];

        $scenes = $this->ReadScenes();
        $entry = ['id' => $sceneID, 'name' => $name, 'targets' => $targets, 'smartButtonHost' => $selected['host'], 'smartButtonName' => $selected['name'], 'smartButtonInstance' => $selected['instance']];
        $found = false;
        foreach ($scenes as &$scene) if ((string)($scene['id'] ?? '') === $sceneID) { $scene = $entry; $found = true; break; }
        unset($scene);
        if (!$found) $scenes[] = $entry;
        $this->WriteScenes($scenes);
        return ['ok' => true, 'message' => 'Smart-Taste wurde an „' . $selected['name'] . '“ erkannt und mit „' . $name . '“ programmiert.', 'scenes' => $scenes];
    }

    private function SelectDelete(): array
    {
        $selected = $this->SelectSmartButton();
        if (!$selected['success']) return ['ok' => false, 'message' => $selected['message']];
        return ['ok' => true, 'message' => 'Smart-Taste wurde an „' . $selected['name'] . '“ erkannt.', 'smartButtonHost' => $selected['host'], 'smartButtonName' => $selected['name']];
    }

    private function SelectSmartButton(): array
    {
        $devices = $this->GetDeviceHosts();
        if ($devices === []) return ['success' => false, 'message' => 'Keine zeptrionAIR-Geräte mit Host gefunden.'];
        $active = [];
        foreach ($devices as $host => $device) {
            $r = $this->SmartButtonRequest($host, 'POST', '/zapi/smartbt/prgm', ['on' => true, 'ntm' => 60], 4000);
            if ($r['success']) $active[$host] = $device;
        }
        if ($active === []) return ['success' => false, 'message' => 'Programmiermodus konnte auf keinem zApp gestartet werden.'];
        $this->SendDebug('Smart-Taster', 'Warte auf Tastendruck auf ' . count($active) . ' zApp(s): ' . implode(', ', array_keys($active)), 0);
        $multi = curl_multi_init(); $handles = [];
        foreach ($active as $host => $device) {
            $ch = curl_init();
            curl_setopt_array($ch, [CURLOPT_URL => 'http://' . $host . '/zapi/smartbt/prgn', CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT_MS => 2500, CURLOPT_TIMEOUT_MS => 65000, CURLOPT_HTTPHEADER => ['Connection: close']]);
            curl_multi_add_handle($multi, $ch); $handles[$host] = $ch;
        }
        $selectedHost = ''; $deadline = microtime(true) + 66.0;
        do {
            do { $status = curl_multi_exec($multi, $running); } while ($status === CURLM_CALL_MULTI_PERFORM);
            foreach ($handles as $host => $ch) {
                if ((int)(curl_getinfo($ch)['http_code'] ?? 0) === 200) {
                    $body = (string)curl_multi_getcontent($ch);
                    if ($body !== '') {
                        $this->SendDebug('Smart-Taster prgn RAW', $host . ' / HTTP 200 / Antwort: ' . $body, 0);
                        $json = json_decode($body, true);
                        if (is_array($json) && ($json['prg'] ?? false) === true) { $selectedHost = $host; break 2; }
                    }
                }
            }
            if ($running > 0) curl_multi_select($multi, 0.20);
        } while ($running > 0 && microtime(true) < $deadline);
        foreach ($handles as $host => $ch) { curl_multi_remove_handle($multi, $ch); curl_close($ch); }
        curl_multi_close($multi);
        if ($selectedHost === '') return ['success' => false, 'message' => 'Keine Smart-Taste erkannt.'];
        $device = $active[$selectedHost];
        $this->SendDebug('Smart-Taster erkannt', 'zApp: ' . $selectedHost . ' / Gerät: ' . $device['name'] . ' / Instanz: ' . $device['instance'], 0);
        return ['success' => true, 'host' => $selectedHost, 'name' => $device['name'], 'instance' => $device['instance'], 'message' => 'Smart-Taste erkannt'];
    }

    private function SmartButtonRequest(string $host, string $method, string $path, ?array $payload = null, int $timeoutMs = 4000): array
    {
        $url = 'http://' . $host . $path; $ch = curl_init();
        $options = [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT_MS => 2000, CURLOPT_TIMEOUT_MS => $timeoutMs, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => ['Connection: close']];
        if ($payload !== null) { $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); $options[CURLOPT_POSTFIELDS] = $body; $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/json', 'Content-Length: ' . strlen((string)$body), 'Connection: close']; }
        curl_setopt_array($ch, $options); $response = curl_exec($ch); $error = curl_error($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $raw = is_string($response) ? $response : '';
        $this->SendDebug('Smart-Taster RAW', $method . ' ' . $url . ' / HTTP ' . $code . ' / Antwort: ' . $raw . ($error !== '' ? ' / Fehler: ' . $error : ''), 0);
        return ['success' => $error === '' && $code >= 200 && $code < 300, 'message' => $error !== '' ? $error : ('HTTP ' . $code . ($raw !== '' ? ' / ' . $raw : '')), 'raw' => $raw, 'httpCode' => $code];
    }

    private function ForgetScene(string $sceneID): array
    {
        $scenes = array_values(array_filter($this->ReadScenes(), static fn(array $scene): bool => (string)($scene['id'] ?? '') !== $sceneID)); $this->WriteScenes($scenes);
        return ['ok' => true, 'message' => 'Eintrag wurde aus dem zeptrionAIR-Splitter entfernt.', 'scenes' => $scenes];
    }

    private function RunScene(string $sceneID, string $token): void
    {
        if ($token === '' || !hash_equals($this->ReadAttributeString('SmartButtonToken'), $token)) { http_response_code(403); echo 'Forbidden'; return; }
        foreach ($this->ReadScenes() as $scene) {
            if ((string)($scene['id'] ?? '') !== $sceneID) continue;
            foreach (($scene['targets'] ?? []) as $target) {
                $type = (string)($target['type'] ?? 'zeptrion');
                try {
                    if ($type === 'zeptrion') {
                        $instance = (int)($target['instance'] ?? 0); $channel = (int)($target['channel'] ?? 0); $memory = (int)($target['memory'] ?? 0);
                        if ($this->IsDeviceInstance($instance)) ZEPA_RecallScene($instance, $channel, $memory);
                    } elseif ($type === 'variable') {
                        $id = (int)($target['variable'] ?? 0); if (IPS_VariableExists($id)) RequestAction($id, $target['value'] ?? null);
                    } elseif ($type === 'script') {
                        $id = (int)($target['script'] ?? 0); if (IPS_ScriptExists($id)) IPS_RunScript($id);
                    }
                } catch (Throwable $e) { $this->SendDebug('Smart-Taster Aktion', $e->getMessage(), 0); }
            }
            echo 'OK'; return;
        }
        http_response_code(404); echo 'Scene not found';
    }

    private function BuildInterface(): string
    {
        $data = json_encode(['scenes' => $this->ReadScenes(), 'zeptrionTargets' => $this->GetTargets(), 'variables' => $this->GetVariables(), 'scripts' => $this->GetScripts()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Smart-Taster</title><style>body{font-family:system-ui,sans-serif;max-width:1000px;margin:28px auto;padding:0 16px;background:#f5f5f5;color:#222}h1{font-size:24px}.card{background:#fff;border-radius:10px;padding:16px;margin:12px 0;box-shadow:0 1px 4px #0002}.row{display:flex;gap:8px;align-items:center;margin:8px 0;flex-wrap:wrap}input,select,button{font:inherit;padding:8px;border:1px solid #bbb;border-radius:6px}input{min-width:150px}select{min-width:180px}button{cursor:pointer}.name{flex:1}.danger{margin-left:auto}.status{padding:12px 0;min-height:24px;font-weight:600}.target{padding-left:12px;border-left:3px solid #ddd}.busy{opacity:.55;pointer-events:none}.hint,.source{color:#666;font-size:14px}</style></head><body><h1>Smart-Taster konfigurieren</h1><p class="hint">Szene benennen, Ziele hinzufügen und danach direkt programmieren.</p><div id="scenes"></div><button id="addScene">+ Szene hinzufügen</button> <button id="clearButton">Smart-Taster löschen</button><div class="status" id="status"></div><script>const D=' . $data . ';let scenes=Array.isArray(D.scenes)?D.scenes:[];const Z=D.zeptrionTargets||[],V=D.variables||[],S=D.scripts||[];const el=id=>document.getElementById(id),mk=(t,x)=>{const e=document.createElement(t);if(x!==undefined)e.textContent=x;return e};function msg(x){el("status").textContent=x||""}function busy(v){document.body.classList.toggle("busy",!!v)}function opts(sel,list,val){sel.replaceChildren();list.forEach(x=>{const o=mk("option",x.caption);o.value=x.value;if(String(x.value)===String(val))o.selected=true;sel.append(o)})}function render(){const root=el("scenes");root.replaceChildren();scenes.forEach((s,i)=>{s.targets=s.targets||[];const c=mk("div");c.className="card";const top=mk("div");top.className="row";const n=mk("input");n.className="name";n.value=s.name||"";n.oninput=()=>s.name=n.value;const f=mk("button","Aus Splitter entfernen");f.className="danger";f.onclick=()=>forget(s.id);top.append(n,f);c.append(top);if(s.smartButtonName){const src=mk("div","Smart-Taster: "+s.smartButtonName+(s.smartButtonHost?" ("+s.smartButtonHost+")":""));src.className="source";c.append(src)}s.targets.forEach((t,j)=>{if(!t.type)t.type="zeptrion";const r=mk("div");r.className="row target";const typ=mk("select");opts(typ,[{value:"zeptrion",caption:"zeptrionAIR S1–S4"},{value:"variable",caption:"Symcon-Variable"},{value:"script",caption:"Symcon-Script"}],t.type);typ.onchange=()=>{t.type=typ.value;render()};r.append(typ);if(t.type==="zeptrion"){const q=mk("select");opts(q,Z,String(t.instance||0)+":"+String(t.channel||0));q.onchange=()=>{const p=q.value.split(":");t.instance=+p[0];t.channel=+p[1]};const m=mk("select");opts(m,[1,2,3,4].map(x=>({value:x,caption:"S"+x})),t.memory||1);m.onchange=()=>t.memory=+m.value;r.append(q,m)}else if(t.type==="variable"){const q=mk("select");opts(q,V,t.variable||0);q.onchange=()=>t.variable=+q.value;const val=mk("input");val.placeholder="Wert";val.value=t.value??"";val.oninput=()=>t.value=val.value;r.append(q,val)}else{const q=mk("select");opts(q,S,t.script||0);q.onchange=()=>t.script=+q.value;r.append(q)}const d=mk("button","Entfernen");d.onclick=()=>{s.targets.splice(j,1);render()};r.append(d);c.append(r)});const a=mk("div");a.className="row";const add=mk("button","+ Ziel hinzufügen");add.onclick=()=>{const z=Z[0];s.targets.push(z?{type:"zeptrion",instance:z.instance,channel:z.channel,memory:1}:{type:"script",script:S[0]?.value||0});render()};const p=mk("button","Smart-Taste programmieren");p.onclick=()=>program(s);a.append(add,p);c.append(a);root.append(c)})}function addScene(){scenes.push({id:Math.random().toString(36).slice(2),name:"Neue Szene",targets:[]});render()}async function api(x){try{const r=await fetch("/hook/zeptrionair",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(x)});return await r.json()}catch(e){return{ok:false,message:e.message}}}async function program(s){if(!confirm("Die Smart-Tasten beginnen jetzt zu blinken. Bitte danach die gewünschte blinkende Smart-Taste am Schalter drücken."))return;msg("Smart-Tasten werden aktiviert. Bitte gewünschte blinkende Smart-Taste drücken …");busy(true);const r=await api({op:"program",scene:s.id,name:s.name,targets:s.targets});busy(false);if(r.ok&&r.scenes)scenes=r.scenes;render();msg(r.message)}async function forget(id){const r=await api({op:"forget",scene:id});if(r.ok&&r.scenes)scenes=r.scenes;render();msg(r.message)}async function clearButton(){if(!confirm("Die Smart-Tasten beginnen jetzt zu blinken. Bitte danach die Smart-Taste drücken, deren Programmierung gelöscht werden soll."))return;msg("Smart-Tasten werden aktiviert. Bitte die zu löschende Smart-Taste drücken …");busy(true);const r=await api({op:"select-delete"});busy(false);msg(r.message)}el("addScene").onclick=addScene;el("clearButton").onclick=clearButton;render();</script></body></html>';
    }

    private function GetDeviceHosts(): array
    {
        $result = [];
        foreach (IPS_GetInstanceListByModuleID(self::DEVICE_MODULE_ID) as $id) {
            $host = trim((string)IPS_GetProperty($id, 'Host')); if ($host === '') continue;
            $name = trim(IPS_GetName($id)); if ($name === '') $name = $host;
            $result[$host] = ['instance' => $id, 'name' => $name];
        }
        ksort($result, SORT_NATURAL); return $result;
    }

    private function GetTargets(): array
    {
        $targets = [];
        foreach (IPS_GetInstanceListByModuleID(self::DEVICE_MODULE_ID) as $id) {
            $count = max(1, min(4, (int)IPS_GetProperty($id, 'Channels')));
            for ($ch=1;$ch<=$count;$ch++) {
                if (strtolower((string)IPS_GetProperty($id, 'Channel'.$ch.'Type')) === 'unused') continue;
                $name=trim((string)IPS_GetProperty($id,'Channel'.$ch.'Name')); if($name==='')$name=IPS_GetName($id).' / Kanal '.$ch;
                $targets[]=['value'=>$id.':'.$ch,'caption'=>$name,'instance'=>$id,'channel'=>$ch];
            }
        }
        usort($targets,static fn($a,$b)=>strnatcasecmp($a['caption'],$b['caption'])); return $targets;
    }

    private function GetVariables(): array
    {
        $out=[]; foreach(IPS_GetVariableList() as $id){$name=$this->ObjectPath($id);$out[]=['value'=>$id,'caption'=>$name];} usort($out,static fn($a,$b)=>strnatcasecmp($a['caption'],$b['caption'])); return $out;
    }

    private function GetScripts(): array
    {
        $out=[]; foreach(IPS_GetScriptList() as $id){$out[]=['value'=>$id,'caption'=>$this->ObjectPath($id)];} usort($out,static fn($a,$b)=>strnatcasecmp($a['caption'],$b['caption'])); return $out;
    }

    private function ObjectPath(int $id): string
    {
        $parts=[];$cur=$id;for($i=0;$i<8&&$cur>0;$i++){$parts[]=IPS_GetName($cur);$cur=IPS_GetParent($cur);}return implode(' / ',array_reverse($parts));
    }

    private function NormalizeTargets(array $targets): array
    {
        $out=[]; foreach($targets as $t){if(!is_array($t))continue;$type=(string)($t['type']??'zeptrion');if($type==='zeptrion'){$i=(int)($t['instance']??0);$c=(int)($t['channel']??0);$m=(int)($t['memory']??0);if($this->IsDeviceInstance($i)&&$c>=1&&$c<=4&&$m>=1&&$m<=4)$out[]=['type'=>'zeptrion','instance'=>$i,'channel'=>$c,'memory'=>$m];}elseif($type==='variable'){$id=(int)($t['variable']??0);if(IPS_VariableExists($id)){$v=IPS_GetVariable($id);$value=$t['value']??'';switch((int)$v['VariableType']){case 0:$value=filter_var($value,FILTER_VALIDATE_BOOLEAN);break;case 1:$value=(int)$value;break;case 2:$value=(float)$value;break;default:$value=(string)$value;}$out[]=['type'=>'variable','variable'=>$id,'value'=>$value];}}elseif($type==='script'){$id=(int)($t['script']??0);if(IPS_ScriptExists($id))$out[]=['type'=>'script','script'=>$id];}}return $out;
    }

    private function IsDeviceInstance(int $id): bool
    {
        if($id<=0||!IPS_InstanceExists($id))return false;$i=IPS_GetInstance($id);return(string)($i['ModuleInfo']['ModuleID']??'')===self::DEVICE_MODULE_ID;
    }
    private function ReadScenes(): array {$x=json_decode($this->ReadAttributeString('SmartButtonScenes'),true);return is_array($x)?$x:[];}
    private function WriteScenes(array $x): void {$this->WriteAttributeString('SmartButtonScenes',json_encode(array_values($x),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));}
}

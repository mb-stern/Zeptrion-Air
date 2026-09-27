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
        if ($this->ReadAttributeString('SmartButtonToken') === '') {
            $this->WriteAttributeString('SmartButtonToken', bin2hex(random_bytes(16)));
        }
        $this->RegisterHook('zeptrionair');
        $this->SetStatus(102);
    }

    public function GetConfigurationForm(): string
    {
        return json_encode([
            'elements' => [
                ['type' => 'Label', 'caption' => 'Zentraler Dienst für zeptrionAIR Smart-Taster.'],
                ['type' => 'Label', 'caption' => 'WebHook: /hook/zeptrionair']
            ],
            'status' => [['code' => 102, 'icon' => 'active', 'caption' => 'Aktiv']]
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected function ProcessHookData(): void
    {
        $action = (string)($_GET['action'] ?? '');
        if ($action === 'run') {
            $this->RunScene((string)($_GET['scene'] ?? ''), (string)($_GET['token'] ?? ''));
            return;
        }
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
            header('Content-Type: application/json; charset=utf-8');
            $input = json_decode((string)file_get_contents('php://input'), true);
            if (!is_array($input)) {
                echo json_encode(['ok' => false, 'message' => 'Ungültige Anfrage']);
                return;
            }
            $op = (string)($input['op'] ?? '');
            if ($op === 'program') {
                echo json_encode($this->ProgramScene($input), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                return;
            }
            if ($op === 'forget') {
                echo json_encode($this->ForgetScene((string)($input['scene'] ?? '')), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                return;
            }
            if ($op === 'select-delete') {
                echo json_encode($this->SelectDelete(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                return;
            }
            echo json_encode(['ok' => false, 'message' => 'Unbekannte Aktion']);
            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo $this->BuildInterface();
    }

    private function ProgramScene(array $input): array
    {
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') $name = 'Szene';
        $targets = $this->NormalizeTargets(is_array($input['targets'] ?? null) ? $input['targets'] : []);
        if ($targets === []) return ['ok' => false, 'message' => 'Bitte mindestens ein Ziel auswählen.'];
        $sceneID = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($input['scene'] ?? ''));
        if ($sceneID === '') $sceneID = bin2hex(random_bytes(6));

        $selected = $this->SelectSmartButton();
        if (!$selected['success']) return ['ok' => false, 'message' => $selected['message']];

        $hostHeader = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($hostHeader === '') return ['ok' => false, 'message' => 'Symcon-Adresse konnte nicht ermittelt werden.'];
        $parts = explode(':', $hostHeader, 2);
        $location = $parts[0];
        $port = isset($parts[1]) ? (int)$parts[1] : 3777;
        $token = $this->ReadAttributeString('SmartButtonToken');
        $payload = [
            'req' => 'GET',
            'typ' => 'application/x-www-form-urlencoded',
            'loc' => $location,
            'prt' => (string)$port,
            'pth' => '/hook/zeptrionair?action=run&scene=' . rawurlencode($sceneID) . '&token=' . rawurlencode($token),
            'bdy' => ''
        ];
        $result = $this->SmartButtonRequest((string)$selected['host'], 'POST', '/zapi/smartbt/prgs', $payload, 5000);
        if (!$result['success']) return ['ok' => false, 'message' => 'Programmierung fehlgeschlagen: ' . $result['message']];

        $scenes = $this->ReadScenes();
        $entry = ['id' => $sceneID, 'name' => $name, 'targets' => $targets, 'smartButtonHost' => (string)$selected['host']];
        $found = false;
        foreach ($scenes as &$scene) {
            if ((string)($scene['id'] ?? '') === $sceneID) { $scene = $entry; $found = true; break; }
        }
        unset($scene);
        if (!$found) $scenes[] = $entry;
        $this->WriteScenes($scenes);
        return ['ok' => true, 'message' => 'Smart-Taste wurde erkannt und mit „' . $name . '“ programmiert. Quelle: ' . $selected['host'], 'scenes' => $scenes];
    }

    private function SelectDelete(): array
    {
        $selected = $this->SelectSmartButton();
        if (!$selected['success']) return ['ok' => false, 'message' => $selected['message']];
        return ['ok' => false, 'message' => 'Smart-Taste wurde an ' . $selected['host'] . ' erkannt. Der eigentliche Feller-Löschbefehl ist noch nicht eindeutig dokumentiert; es wurde nichts überschrieben. Bitte den Splitter-Debug von prgn prüfen.'];
    }

    private function SelectSmartButton(): array
    {
        $hosts = $this->GetDeviceHosts();
        if ($hosts === []) return ['success' => false, 'message' => 'Keine zeptrionAIR-Geräte mit Host gefunden.'];

        $active = [];
        foreach ($hosts as $host) {
            $r = $this->SmartButtonRequest($host, 'POST', '/zapi/smartbt/prgm', ['on' => true, 'ntm' => 60], 4000);
            if ($r['success']) $active[] = $host;
        }
        if ($active === []) return ['success' => false, 'message' => 'Programmiermodus konnte auf keinem zApp gestartet werden.'];

        $this->SendDebug('Smart-Taster', 'Warte auf Tastendruck auf ' . count($active) . ' zApp(s): ' . implode(', ', $active), 0);
        $multi = curl_multi_init();
        $handles = [];
        foreach ($active as $host) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => 'http://' . $host . '/zapi/smartbt/prgn',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => 2500,
                CURLOPT_TIMEOUT_MS => 65000,
                CURLOPT_HTTPHEADER => ['Connection: close']
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[$host] = $ch;
        }
        $selectedHost = '';
        $deadline = microtime(true) + 66.0;
        do {
            do { $status = curl_multi_exec($multi, $running); } while ($status === CURLM_CALL_MULTI_PERFORM);
            foreach ($handles as $host => $ch) {
                $info = curl_getinfo($ch);
                if ((int)($info['http_code'] ?? 0) === 200) {
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

        foreach ($handles as $host => $ch) {
            if ($selectedHost === '' || $host !== $selectedHost) {
                $body = (string)curl_multi_getcontent($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($body !== '' || $code !== 0) $this->SendDebug('Smart-Taster prgn RAW', $host . ' / HTTP ' . $code . ' / Antwort: ' . $body, 0);
            }
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi);

        if ($selectedHost === '') return ['success' => false, 'message' => 'Keine Smart-Taste erkannt.'];
        $this->SendDebug('Smart-Taster erkannt', 'zApp: ' . $selectedHost, 0);
        return ['success' => true, 'host' => $selectedHost, 'message' => 'Smart-Taste erkannt'];
    }

    private function SmartButtonRequest(string $host, string $method, string $path, ?array $payload = null, int $timeoutMs = 4000): array
    {
        $url = 'http://' . $host . $path;
        $ch = curl_init();
        $options = [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT_MS => 2000, CURLOPT_TIMEOUT_MS => $timeoutMs, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => ['Connection: close']];
        if ($payload !== null) {
            $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $options[CURLOPT_POSTFIELDS] = $body;
            $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/json', 'Content-Length: ' . strlen((string)$body), 'Connection: close'];
        }
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $raw = is_string($response) ? $response : '';
        $this->SendDebug('Smart-Taster RAW', $method . ' ' . $url . ' / HTTP ' . $code . ' / Antwort: ' . $raw . ($error !== '' ? ' / Fehler: ' . $error : ''), 0);
        return ['success' => $error === '' && $code >= 200 && $code < 300, 'message' => $error !== '' ? $error : ('HTTP ' . $code . ($raw !== '' ? ' / ' . $raw : '')), 'raw' => $raw, 'httpCode' => $code];
    }

    private function ForgetScene(string $sceneID): array
    {
        $scenes = array_values(array_filter($this->ReadScenes(), static fn(array $scene): bool => (string)($scene['id'] ?? '') !== $sceneID));
        $this->WriteScenes($scenes);
        return ['ok' => true, 'message' => 'Eintrag wurde aus dem zeptrionAIR-Splitter entfernt.', 'scenes' => $scenes];
    }

    private function RunScene(string $sceneID, string $token): void
    {
        if ($token === '' || !hash_equals($this->ReadAttributeString('SmartButtonToken'), $token)) { http_response_code(403); echo 'Forbidden'; return; }
        foreach ($this->ReadScenes() as $scene) {
            if ((string)($scene['id'] ?? '') !== $sceneID) continue;
            foreach (($scene['targets'] ?? []) as $target) {
                $instance = (int)($target['instance'] ?? 0); $channel = (int)($target['channel'] ?? 0); $memory = (int)($target['memory'] ?? 0);
                if ($this->IsDeviceInstance($instance)) @ZEPA_RecallScene($instance, $channel, $memory);
            }
            echo 'OK'; return;
        }
        http_response_code(404); echo 'Scene not found';
    }

    private function BuildInterface(): string
    {
        $data = json_encode(['scenes' => $this->ReadScenes(), 'targets' => $this->GetTargets()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Smart-Taster</title><style>body{font-family:system-ui,sans-serif;max-width:900px;margin:28px auto;padding:0 16px;background:#f5f5f5;color:#222}h1{font-size:24px}.card{background:#fff;border-radius:10px;padding:16px;margin:12px 0;box-shadow:0 1px 4px #0002}.row{display:flex;gap:8px;align-items:center;margin:8px 0;flex-wrap:wrap}input,select,button{font:inherit;padding:8px;border:1px solid #bbb;border-radius:6px}input{flex:1;min-width:180px}select{min-width:220px}button{cursor:pointer}.danger{margin-left:auto}.status{padding:12px 0;min-height:24px;font-weight:600}.target{padding-left:12px;border-left:3px solid #ddd}.busy{opacity:.55;pointer-events:none}.hint{color:#666}</style></head><body><h1>Smart-Taster konfigurieren</h1><p class="hint">Szene benennen, Ziele hinzufügen und danach direkt programmieren.</p><div id="scenes"></div><button id="addScene">+ Szene hinzufügen</button> <button id="clearButton">Smart-Taster löschen</button><div class="status" id="status"></div><script>const D=' . $data . ';let scenes=Array.isArray(D.scenes)?D.scenes:[];const targets=Array.isArray(D.targets)?D.targets:[];const el=id=>document.getElementById(id);function msg(s){el("status").textContent=s||""}function make(t,x){const e=document.createElement(t);if(x!==undefined)e.textContent=x;return e}function busy(v){document.body.classList.toggle("busy",!!v)}function render(){const root=el("scenes");root.replaceChildren();scenes.forEach((s,i)=>{const c=make("div");c.className="card";const top=make("div");top.className="row";const n=make("input");n.placeholder="Szenenname";n.value=s.name||"";n.oninput=()=>s.name=n.value;const f=make("button","Aus Splitter entfernen");f.className="danger";f.onclick=()=>forget(s.id);top.append(n,f);c.append(top);(s.targets||[]).forEach((t,j)=>{const r=make("div");r.className="row target";const q=make("select");targets.forEach(x=>{const o=make("option",x.caption);o.value=x.value;if(x.value===String(t.instance)+":"+String(t.channel))o.selected=true;q.append(o)});q.onchange=()=>{const p=q.value.split(":");t.instance=Number(p[0]);t.channel=Number(p[1])};const m=make("select");for(let z=1;z<=4;z++){const o=make("option","S"+z);o.value=z;if(Number(t.memory)===z)o.selected=true;m.append(o)}m.onchange=()=>t.memory=Number(m.value);const d=make("button","Entfernen");d.onclick=()=>{s.targets.splice(j,1);render()};r.append(q,m,d);c.append(r)});const a=make("div");a.className="row";const add=make("button","+ Gerät hinzufügen");add.onclick=()=>addTarget(i);const p=make("button","Smart-Taste programmieren");p.onclick=()=>program(s);a.append(add,p);c.append(a);root.append(c)})}function addScene(){scenes.push({id:Math.random().toString(36).slice(2),name:"Neue Szene",targets:[]});render()}function addTarget(i){if(!targets.length){msg("Keine Ziele vorhanden.");return}const p=targets[0].value.split(":");scenes[i].targets.push({instance:Number(p[0]),channel:Number(p[1]),memory:1});render()}async function api(x){try{const r=await fetch("/hook/zeptrionair",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(x)});return await r.json()}catch(e){return{ok:false,message:e.message}}}async function program(s){if(!confirm("Die Smart-Tasten beginnen jetzt zu blinken. Bitte danach die gewünschte blinkende Smart-Taste am Schalter drücken."))return;msg("Smart-Tasten werden aktiviert. Bitte gewünschte blinkende Smart-Taste drücken …");busy(true);const r=await api({op:"program",scene:s.id,name:s.name,targets:s.targets});busy(false);if(r.ok&&r.scenes)scenes=r.scenes;render();msg(r.message)}async function forget(id){const r=await api({op:"forget",scene:id});if(r.ok&&r.scenes)scenes=r.scenes;render();msg(r.message)}async function clearButton(){if(!confirm("Die Smart-Tasten beginnen jetzt zu blinken. Bitte danach die Smart-Taste drücken, deren Programmierung gelöscht werden soll."))return;msg("Smart-Tasten werden aktiviert. Bitte die zu löschende Smart-Taste drücken …");busy(true);const r=await api({op:"select-delete"});busy(false);msg(r.message)}el("addScene").onclick=addScene;el("clearButton").onclick=clearButton;render();</script></body></html>';
    }

    private function GetDeviceHosts(): array
    {
        $hosts = [];
        foreach (IPS_GetInstanceListByModuleID(self::DEVICE_MODULE_ID) as $instanceID) {
            $host = trim((string)IPS_GetProperty($instanceID, 'Host'));
            if ($host !== '') $hosts[$host] = $host;
        }
        $hosts = array_values($hosts); sort($hosts, SORT_NATURAL | SORT_FLAG_CASE); return $hosts;
    }

    private function GetTargets(): array
    {
        $targets = [];
        foreach (IPS_GetInstanceListByModuleID(self::DEVICE_MODULE_ID) as $instanceID) {
            $count = max(1, min(4, (int)IPS_GetProperty($instanceID, 'Channels')));
            for ($channel = 1; $channel <= $count; $channel++) {
                if (strtolower((string)IPS_GetProperty($instanceID, 'Channel' . $channel . 'Type')) === 'unused') continue;
                $name = trim((string)IPS_GetProperty($instanceID, 'Channel' . $channel . 'Name'));
                if ($name === '') $name = IPS_GetName($instanceID);
                $targets[] = ['value' => $instanceID . ':' . $channel, 'caption' => $name, 'instance' => $instanceID, 'channel' => $channel];
            }
        }
        usort($targets, static fn(array $a, array $b): int => strnatcasecmp($a['caption'], $b['caption'])); return $targets;
    }

    private function NormalizeTargets(array $targets): array
    {
        $result = [];
        foreach ($targets as $target) {
            if (!is_array($target)) continue;
            $instance = (int)($target['instance'] ?? 0); $channel = (int)($target['channel'] ?? 0); $memory = (int)($target['memory'] ?? 0);
            if ($this->IsDeviceInstance($instance) && $channel >= 1 && $channel <= 4 && $memory >= 1 && $memory <= 4) $result[] = ['instance' => $instance, 'channel' => $channel, 'memory' => $memory];
        }
        return $result;
    }

    private function IsDeviceInstance(int $instanceID): bool
    {
        if ($instanceID <= 0 || !IPS_InstanceExists($instanceID)) return false;
        $instance = IPS_GetInstance($instanceID);
        return (string)($instance['ModuleInfo']['ModuleID'] ?? '') === self::DEVICE_MODULE_ID;
    }

    private function ReadScenes(): array
    {
        $scenes = json_decode($this->ReadAttributeString('SmartButtonScenes'), true); return is_array($scenes) ? $scenes : [];
    }

    private function WriteScenes(array $scenes): void
    {
        $this->WriteAttributeString('SmartButtonScenes', json_encode(array_values($scenes), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}

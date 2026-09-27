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
            'status' => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Aktiv']
            ]
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected function ProcessHookData(): void
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $action = (string)($_GET['action'] ?? '');
        if ($action === 'run') {
            $this->RunScene((string)($_GET['scene'] ?? ''), (string)($_GET['token'] ?? ''));
            return;
        }
        $source = (int)($_GET['source'] ?? 0);
        if ($method === 'POST') {
            header('Content-Type: application/json; charset=utf-8');
            $input = json_decode((string)file_get_contents('php://input'), true);
            if (!is_array($input)) {
                echo json_encode(['ok' => false, 'message' => 'Ungültige Anfrage']);
                return;
            }
            $op = (string)($input['op'] ?? '');
            if ($op === 'program') {
                echo json_encode($this->ProgramScene($source, $input), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                return;
            }
            if ($op === 'forget') {
                echo json_encode($this->ForgetScene((string)($input['scene'] ?? '')), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                return;
            }
            if ($op === 'select-delete') {
                echo json_encode($this->SelectDelete($source), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                return;
            }
            echo json_encode(['ok' => false, 'message' => 'Unbekannte Aktion']);
            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo $this->BuildInterface($source);
    }

    private function ProgramScene(int $source, array $input): array
    {
        if (!$this->IsDeviceInstance($source)) return ['ok' => false, 'message' => 'Quellgerät nicht gefunden.'];
        $name = trim((string)($input['name'] ?? ''));
        if ($name === '') $name = 'Szene';
        $targets = $this->NormalizeTargets(is_array($input['targets'] ?? null) ? $input['targets'] : []);
        if ($targets === []) return ['ok' => false, 'message' => 'Bitte mindestens ein Ziel auswählen.'];
        $sceneID = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($input['scene'] ?? ''));
        if ($sceneID === '') $sceneID = bin2hex(random_bytes(6));
        $token = $this->ReadAttributeString('SmartButtonToken');
        $hostHeader = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($hostHeader === '') return ['ok' => false, 'message' => 'Symcon-Adresse konnte nicht ermittelt werden.'];
        $parts = explode(':', $hostHeader, 2);
        $location = $parts[0];
        $port = isset($parts[1]) ? (int)$parts[1] : 3777;
        $path = '/hook/zeptrionair?action=run&scene=' . rawurlencode($sceneID) . '&token=' . rawurlencode($token);
        try {
            if (!ZEPA_ProgramSmartButtonWebHook($source, $location, $port, $path)) {
                return ['ok' => false, 'message' => 'Programmierung wurde nicht bestätigt.'];
            }
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
        $scenes = $this->ReadScenes();
        $found = false;
        foreach ($scenes as &$scene) {
            if (($scene['id'] ?? '') === $sceneID) {
                $scene = ['id' => $sceneID, 'name' => $name, 'targets' => $targets];
                $found = true;
                break;
            }
        }
        unset($scene);
        if (!$found) $scenes[] = ['id' => $sceneID, 'name' => $name, 'targets' => $targets];
        $this->WriteScenes($scenes);
        return ['ok' => true, 'message' => 'Smart-Taste wurde mit „' . $name . '“ programmiert.', 'scenes' => $scenes];
    }

    private function ForgetScene(string $sceneID): array
    {
        $scenes = array_values(array_filter($this->ReadScenes(), static fn(array $scene): bool => (string)($scene['id'] ?? '') !== $sceneID));
        $this->WriteScenes($scenes);
        return ['ok' => true, 'message' => 'Eintrag wurde aus dem zeptrionAIR-Splitter entfernt.', 'scenes' => $scenes];
    }

    private function SelectDelete(int $source): array
    {
        if (!$this->IsDeviceInstance($source)) return ['ok' => false, 'message' => 'Quellgerät nicht gefunden.'];
        try {
            ZEPA_SelectSmartButtonForDelete($source);
            return ['ok' => true, 'message' => 'Smart-Taste wurde erkannt. Der eigentliche Feller-Löschbefehl bleibt noch unverändert aus dem bisherigen Teststand.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    private function RunScene(string $sceneID, string $token): void
    {
        if ($token === '' || !hash_equals($this->ReadAttributeString('SmartButtonToken'), $token)) {
            http_response_code(403); echo 'Forbidden'; return;
        }
        foreach ($this->ReadScenes() as $scene) {
            if ((string)($scene['id'] ?? '') !== $sceneID) continue;
            foreach (($scene['targets'] ?? []) as $target) {
                $instance = (int)($target['instance'] ?? 0);
                $channel = (int)($target['channel'] ?? 0);
                $memory = (int)($target['memory'] ?? 0);
                if ($this->IsDeviceInstance($instance)) @ZEPA_RecallScene($instance, $channel, $memory);
            }
            echo 'OK'; return;
        }
        http_response_code(404); echo 'Scene not found';
    }

    private function BuildInterface(int $source): string
    {
        if (!$this->IsDeviceInstance($source)) {
            return '<!doctype html><html><body style="font-family:system-ui;padding:24px"><h2>zeptrionAIR Smart-Taster</h2><p>Bitte die Smart-Taster-Konfiguration über eine passende zeptrionAIR-Geräteinstanz öffnen.</p></body></html>';
        }
        $data = json_encode(['scenes' => $this->ReadScenes(), 'targets' => $this->GetTargets()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Smart-Taster</title><style>body{font-family:system-ui,sans-serif;max-width:900px;margin:28px auto;padding:0 16px;background:#f5f5f5;color:#222}h1{font-size:24px}.card{background:#fff;border-radius:10px;padding:16px;margin:12px 0;box-shadow:0 1px 4px #0002}.row{display:flex;gap:8px;align-items:center;margin:8px 0;flex-wrap:wrap}input,select,button{font:inherit;padding:8px;border:1px solid #bbb;border-radius:6px}input{flex:1;min-width:180px}select{min-width:220px}button{cursor:pointer}.danger{margin-left:auto}.status{padding:12px 0;min-height:24px;font-weight:600}.target{padding-left:12px;border-left:3px solid #ddd}.busy{opacity:.55;pointer-events:none}</style></head><body><h1>Smart-Taster konfigurieren</h1><div id="scenes"></div><button id="addScene">+ Szene hinzufügen</button> <button id="clearButton">Smart-Taster löschen</button><div class="status" id="status"></div><script>const D=' . $data . ';let scenes=Array.isArray(D.scenes)?D.scenes:[];const targets=Array.isArray(D.targets)?D.targets:[];const el=id=>document.getElementById(id);function msg(s){el("status").textContent=s||""}function make(t,x){const e=document.createElement(t);if(x!==undefined)e.textContent=x;return e}function busy(v){document.body.classList.toggle("busy",!!v)}function render(){const root=el("scenes");root.replaceChildren();scenes.forEach((s,i)=>{const c=make("div");c.className="card";const top=make("div");top.className="row";const n=make("input");n.placeholder="Szenenname";n.value=s.name||"";n.oninput=()=>s.name=n.value;const f=make("button","Aus Splitter entfernen");f.className="danger";f.onclick=()=>forget(s.id);top.append(n,f);c.append(top);(s.targets||[]).forEach((t,j)=>{const r=make("div");r.className="row target";const q=make("select");targets.forEach(x=>{const o=make("option",x.caption);o.value=x.value;if(x.value===String(t.instance)+":"+String(t.channel))o.selected=true;q.append(o)});q.onchange=()=>{const p=q.value.split(":");t.instance=Number(p[0]);t.channel=Number(p[1])};const m=make("select");for(let z=1;z<=4;z++){const o=make("option","S"+z);o.value=z;if(Number(t.memory)===z)o.selected=true;m.append(o)}m.onchange=()=>t.memory=Number(m.value);const d=make("button","Entfernen");d.onclick=()=>{s.targets.splice(j,1);render()};r.append(q,m,d);c.append(r)});const a=make("div");a.className="row";const add=make("button","+ Gerät hinzufügen");add.onclick=()=>addTarget(i);const p=make("button","Smart-Taste programmieren");p.onclick=()=>program(s);a.append(add,p);c.append(a);root.append(c)})}function addScene(){scenes.push({id:Math.random().toString(36).slice(2),name:"Neue Szene",targets:[]});render()}function addTarget(i){if(!targets.length){msg("Keine Ziele vorhanden.");return}const p=targets[0].value.split(":");scenes[i].targets.push({instance:Number(p[0]),channel:Number(p[1]),memory:1});render()}async function api(x){try{const r=await fetch(location.href,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(x)});return await r.json()}catch(e){return{ok:false,message:e.message}}}async function program(s){msg("Bitte gewünschte blinkende Smart-Taste drücken …");busy(true);const r=await api({op:"program",scene:s.id,name:s.name,targets:s.targets});busy(false);if(r.ok&&r.scenes)scenes=r.scenes;render();msg(r.message)}async function forget(id){const r=await api({op:"forget",scene:id});if(r.ok&&r.scenes)scenes=r.scenes;render();msg(r.message)}async function clearButton(){msg("Bitte die zu löschende Smart-Taste drücken …");busy(true);const r=await api({op:"select-delete"});busy(false);msg(r.message)}el("addScene").onclick=addScene;el("clearButton").onclick=clearButton;render();</script></body></html>';
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
        usort($targets, static fn(array $a, array $b): int => strnatcasecmp($a['caption'], $b['caption']));
        return $targets;
    }

    private function NormalizeTargets(array $targets): array
    {
        $result = [];
        foreach ($targets as $target) {
            if (!is_array($target)) continue;
            $instance = (int)($target['instance'] ?? 0);
            $channel = (int)($target['channel'] ?? 0);
            $memory = (int)($target['memory'] ?? 0);
            if ($this->IsDeviceInstance($instance) && $channel >= 1 && $channel <= 4 && $memory >= 1 && $memory <= 4) {
                $result[] = ['instance' => $instance, 'channel' => $channel, 'memory' => $memory];
            }
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
        $scenes = json_decode($this->ReadAttributeString('SmartButtonScenes'), true);
        return is_array($scenes) ? $scenes : [];
    }

    private function WriteScenes(array $scenes): void
    {
        $this->WriteAttributeString('SmartButtonScenes', json_encode(array_values($scenes), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}

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

    public function GetSmartButtonAssignment(int $deviceInstance): string
    {
        $names = [];
        foreach ($this->ReadScenes() as $scene) {
            if ((int)($scene['smartButtonInstance'] ?? 0) !== $deviceInstance) {
                continue;
            }
            $name = trim((string)($scene['name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }
        return implode(', ', array_values(array_unique($names)));
    }

    protected function ProcessHookData(): void
    {
        if ((string)($_GET['action'] ?? '') === 'run') {
            $this->RunScene((string)($_GET['scene'] ?? ''), (string)($_GET['token'] ?? ''));
            return;
        }
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
            header('Content-Type: application/json; charset=utf-8');
            $in = json_decode((string)file_get_contents('php://input'), true);
            if (!is_array($in)) {
                echo json_encode(['ok' => false, 'message' => 'Ungültige Anfrage']);
                return;
            }
            switch ((string)($in['op'] ?? '')) {
                case 'program':
                    echo json_encode($this->ProgramScene($in), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    return;
                case 'forget':
                    echo json_encode($this->ForgetScene((string)($in['scene'] ?? '')), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    return;
                case 'select-delete':
                    echo json_encode($this->SelectDelete(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    return;
                case 'tree-children':
                    echo json_encode(['ok' => true, 'items' => $this->GetObjectTreeChildren((int)($in['parent'] ?? 0))], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    return;
                case 'tree-search':
                    echo json_encode(['ok' => true, 'items' => $this->SearchObjectTree((string)($in['query'] ?? ''))], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    return;
                case 'object-info':
                    echo json_encode(['ok' => true, 'object' => $this->GetSelectableObjectInfo((int)($in['id'] ?? 0))], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    return;
            }
            echo json_encode(['ok' => false, 'message' => 'Unbekannte Aktion']);
            return;
        }
        header('Content-Type: text/html; charset=utf-8');
        echo $this->BuildInterface();
    }

    private function ProgramScene(array $in): array
    {
        $name = trim((string)($in['name'] ?? '')) ?: 'Szene';
        $targets = $this->NormalizeTargets(is_array($in['targets'] ?? null) ? $in['targets'] : []);
        if ($targets === []) {
            return ['ok' => false, 'message' => 'Bitte mindestens ein Ziel auswählen.'];
        }
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($in['scene'] ?? '')) ?: bin2hex(random_bytes(6));
        $sel = $this->SelectSmartButton();
        if (!$sel['success']) {
            return ['ok' => false, 'message' => $sel['message']];
        }
        $hh = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($hh === '') {
            return ['ok' => false, 'message' => 'Symcon-Adresse konnte nicht ermittelt werden.'];
        }
        $p = explode(':', $hh, 2);
        $token = $this->ReadAttributeString('SmartButtonToken');
        $payload = [
            'req' => 'GET',
            'typ' => 'application/x-www-form-urlencoded',
            'loc' => $p[0],
            'prt' => (string)(isset($p[1]) ? (int)$p[1] : 3777),
            'pth' => '/hook/zeptrionair?action=run&scene=' . rawurlencode($id) . '&token=' . rawurlencode($token),
            'bdy' => ''
        ];
        $r = $this->SmartButtonRequest((string)$sel['host'], 'POST', '/zapi/smartbt/prgs', $payload, 5000);
        if (!$r['success']) {
            return ['ok' => false, 'message' => 'Programmierung fehlgeschlagen: ' . $r['message']];
        }
        $scenes = $this->ReadScenes();
        $entry = [
            'id' => $id,
            'name' => $name,
            'targets' => $targets,
            'smartButtonHost' => $sel['host'],
            'smartButtonName' => $sel['name'],
            'smartButtonInstance' => $sel['instance']
        ];
        $found = false;
        foreach ($scenes as &$scene) {
            if ((string)($scene['id'] ?? '') === $id) {
                $scene = $entry;
                $found = true;
                break;
            }
        }
        unset($scene);
        if (!$found) {
            $scenes[] = $entry;
        }
        $this->WriteScenes($scenes);
        return ['ok' => true, 'message' => 'Smart-Taste wurde an „' . $sel['name'] . '“ erkannt und mit „' . $name . '“ programmiert.', 'scenes' => $scenes];
    }

    private function SelectDelete(): array
    {
        $s = $this->SelectSmartButton();
        if (!$s['success']) {
            return ['ok' => false, 'message' => $s['message']];
        }
        return ['ok' => true, 'message' => 'Smart-Taste auf „' . $s['name'] . '“ wurde erkannt und zum Löschen ausgewählt.'];
    }

    private function SelectSmartButton(): array
    {
        $devices = $this->GetDeviceHosts();
        if ($devices === []) {
            return ['success' => false, 'message' => 'Keine zeptrionAIR-Geräte mit Host gefunden.'];
        }
        $active = [];
        foreach ($devices as $host => $device) {
            $r = $this->SmartButtonRequest($host, 'POST', '/zapi/smartbt/prgm', ['on' => true, 'ntm' => 60], 4000);
            if ($r['success']) {
                $active[$host] = $device;
            }
        }
        if ($active === []) {
            return ['success' => false, 'message' => 'Programmiermodus konnte auf keinem zApp gestartet werden.'];
        }
        $this->SendDebug('Smart-Taster', 'Warte auf Tastendruck auf ' . count($active) . ' zApp(s): ' . implode(', ', array_keys($active)), 0);
        $multi = curl_multi_init();
        $handles = [];
        foreach ($active as $host => $device) {
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
        $selected = '';
        $deadline = microtime(true) + 66;
        do {
            do {
                $status = curl_multi_exec($multi, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);
            foreach ($handles as $host => $ch) {
                if ((int)(curl_getinfo($ch)['http_code'] ?? 0) === 200) {
                    $body = (string)curl_multi_getcontent($ch);
                    if ($body !== '') {
                        $this->SendDebug('Smart-Taster prgn RAW', $host . ' / HTTP 200 / Antwort: ' . $body, 0);
                        $json = json_decode($body, true);
                        if (is_array($json) && ($json['prg'] ?? false) === true) {
                            $selected = $host;
                            break 2;
                        }
                    }
                }
            }
            if ($running > 0) {
                curl_multi_select($multi, .2);
            }
        } while ($running > 0 && microtime(true) < $deadline);
        foreach ($handles as $ch) {
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi);
        if ($selected === '') {
            return ['success' => false, 'message' => 'Keine Smart-Taste erkannt.'];
        }
        $device = $active[$selected];
        $this->SendDebug('Smart-Taster erkannt', 'zApp: ' . $selected . ' / Gerät: ' . $device['name'] . ' / Instanz: ' . $device['instance'], 0);
        return ['success' => true, 'host' => $selected, 'name' => $device['name'], 'instance' => $device['instance']];
    }

    private function SmartButtonRequest(string $host, string $method, string $path, ?array $payload = null, int $timeoutMs = 4000): array
    {
        $url = 'http://' . $host . $path;
        $ch = curl_init();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => 2000,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Connection: close']
        ];
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
        return ['success' => $error === '' && $code >= 200 && $code < 300, 'message' => $error !== '' ? $error : 'HTTP ' . $code . ($raw !== '' ? ' / ' . $raw : ''), 'raw' => $raw, 'httpCode' => $code];
    }

    private function ForgetScene(string $id): array
    {
        $scenes = array_values(array_filter($this->ReadScenes(), static fn(array $scene): bool => (string)($scene['id'] ?? '') !== $id));
        $this->WriteScenes($scenes);
        return ['ok' => true, 'message' => 'Eintrag wurde aus dem zeptrionAIR-Splitter entfernt.', 'scenes' => $scenes];
    }

    private function RunScene(string $id, string $token): void
    {
        if ($token === '' || !hash_equals($this->ReadAttributeString('SmartButtonToken'), $token)) {
            http_response_code(403);
            echo 'Forbidden';
            return;
        }
        foreach ($this->ReadScenes() as $scene) {
            if ((string)($scene['id'] ?? '') !== $id) {
                continue;
            }
            foreach (($scene['targets'] ?? []) as $target) {
                try {
                    $type = (string)($target['type'] ?? 'zeptrion');
                    if ($type === 'zeptrion') {
                        $instance = (int)($target['instance'] ?? 0);
                        if ($this->IsDeviceInstance($instance)) {
                            ZEPA_RecallScene($instance, (int)$target['channel'], (int)$target['memory']);
                        }
                    } elseif ($type === 'symcon') {
                        $objectID = (int)($target['object'] ?? 0);
                        if (IPS_VariableExists($objectID)) {
                            RequestAction($objectID, $target['value'] ?? null);
                        } elseif (IPS_ScriptExists($objectID)) {
                            IPS_RunScript($objectID);
                        }
                    } elseif ($type === 'variable') {
                        $objectID = (int)($target['variable'] ?? 0);
                        if (IPS_VariableExists($objectID)) {
                            RequestAction($objectID, $target['value'] ?? null);
                        }
                    } elseif ($type === 'script') {
                        $objectID = (int)($target['script'] ?? 0);
                        if (IPS_ScriptExists($objectID)) {
                            IPS_RunScript($objectID);
                        }
                    }
                } catch (Throwable $e) {
                    $this->SendDebug('Smart-Taster Aktion', $e->getMessage(), 0);
                }
            }
            echo 'OK';
            return;
        }
        http_response_code(404);
        echo 'Scene not found';
    }

    private function BuildInterface(): string
    {
        $data = json_encode([
            'scenes' => $this->ReadScenes(),
            'zeptrionTargets' => $this->GetTargets()
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $html = <<<'HTML'
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Smart-Taster</title>
<style>
body{font-family:system-ui,sans-serif;max-width:1000px;margin:28px auto;padding:0 16px;background:#f5f5f5;color:#222}h1{font-size:24px}.card{background:#fff;border-radius:10px;padding:16px;margin:12px 0;box-shadow:0 1px 4px #0002}.row{display:flex;gap:8px;align-items:center;margin:8px 0;flex-wrap:wrap}input,select,button{font:inherit;padding:8px;border:1px solid #bbb;border-radius:6px}input{min-width:150px}select{min-width:180px}button{cursor:pointer}.name{flex:1}.danger{margin-left:auto}.status{padding:12px 0;min-height:24px;font-weight:600}.target{padding-left:12px;border-left:3px solid #ddd}.busy{opacity:.55;pointer-events:none}.hint,.source{color:#666;font-size:14px}.objfield{min-width:330px;text-align:left}.modal{position:fixed;inset:0;background:#0008;display:flex;align-items:center;justify-content:center;z-index:99}.modalbox{background:#fff;width:min(760px,92vw);height:min(650px,84vh);border-radius:10px;padding:14px;display:flex;flex-direction:column}.tree{overflow:auto;flex:1;border:1px solid #ddd;border-radius:6px;padding:6px}.node{margin:1px 0}.nodeRow{display:flex;align-items:center;min-height:30px;border-radius:4px}.nodeRow:hover{background:#eee}.twisty{width:28px;border:0;background:transparent;padding:4px}.nodeLabel{border:0;background:transparent;text-align:left;flex:1;padding:5px}.nodeLabel.selectable{font-weight:500}.children{margin-left:22px}.search{box-sizing:border-box;width:100%;margin:8px 0}.close{margin-left:auto}.modalHead{display:flex;align-items:center;gap:10px}.typeTag{font-size:12px;color:#777;margin-left:8px}.searchResult{display:block;width:100%;text-align:left;border:0;background:transparent;padding:7px;border-radius:4px}.searchResult:hover{background:#eee}
</style></head><body><h1>Smart-Taster konfigurieren</h1><p class="hint">Szene benennen, Ziele hinzufügen und danach direkt programmieren.</p><div id="scenes"></div><button id="addScene">+ Szene hinzufügen</button> <button id="clearButton">Smart-Taster löschen</button><div class="status" id="status"></div><script>
const D=__DATA__;let scenes=Array.isArray(D.scenes)?D.scenes:[];const Z=D.zeptrionTargets||[];const cache=new Map();
const el=id=>document.getElementById(id),mk=(t,x)=>{const e=document.createElement(t);if(x!==undefined)e.textContent=x;return e};
function msg(x){el('status').textContent=x||''}function busy(v){document.body.classList.toggle('busy',!!v)}
function opts(s,l,v){s.replaceChildren();l.forEach(x=>{const o=mk('option',x.caption);o.value=x.value;if(String(x.value)===String(v))o.selected=true;s.append(o)})}
async function api(x){try{const r=await fetch('/hook/zeptrionair',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(x)});return await r.json()}catch(e){return{ok:false,message:e.message}}}
async function objectInfo(id){if(!id)return null;if(cache.has(+id))return cache.get(+id);const r=await api({op:'object-info',id:+id});if(r.ok&&r.object){cache.set(+id,r.object);return r.object}return null}
function migrate(t){if(t.type==='variable')return{type:'symcon',object:+t.variable,value:t.value};if(t.type==='script')return{type:'symcon',object:+t.script};return t}
async function pick(t,done){const m=mk('div');m.className='modal';const b=mk('div');b.className='modalbox';const head=mk('div');head.className='modalHead';head.append(mk('h3','Symcon-Objekt auswählen'));const x=mk('button','Schliessen');x.className='close';x.onclick=()=>m.remove();head.append(x);const q=mk('input');q.className='search';q.placeholder='Objekt suchen …';const tree=mk('div');tree.className='tree';b.append(head,q,tree);m.append(b);document.body.append(m);
async function choose(item){if(!item.selectable)return;t.object=+item.id;t.objectInfo=item;t.value=item.defaultValue??'';cache.set(+item.id,item);m.remove();done()}
async function load(parent,container){container.textContent='Lade …';const r=await api({op:'tree-children',parent});container.replaceChildren();if(!r.ok)return;(r.items||[]).forEach(item=>{const wrap=mk('div');wrap.className='node';const row=mk('div');row.className='nodeRow';const twist=mk('button',item.hasChildren?'▶':'');twist.className='twisty';const label=mk('button',(item.icon||'')+' '+item.name);label.className='nodeLabel'+(item.selectable?' selectable':'');const tag=mk('span',item.type==='variable'?'Variable':item.type==='script'?'Script':'');tag.className='typeTag';row.append(twist,label,tag);wrap.append(row);const children=mk('div');children.className='children';wrap.append(children);let open=false;twist.onclick=async()=>{if(!item.hasChildren)return;open=!open;twist.textContent=open?'▼':'▶';if(open&&children.childNodes.length===0)await load(item.id,children);children.style.display=open?'block':'none'};label.onclick=()=>item.selectable?choose(item):twist.click();container.append(wrap)})}
let timer=0;q.oninput=()=>{clearTimeout(timer);timer=setTimeout(async()=>{const text=q.value.trim();if(text===''){await load(0,tree);return}tree.textContent='Suche …';const r=await api({op:'tree-search',query:text});tree.replaceChildren();(r.items||[]).forEach(item=>{const e=mk('button',(item.type==='variable'?'● ':'▶ ')+item.path);e.className='searchResult';e.onclick=()=>choose(item);tree.append(e)})},180)};await load(0,tree)}
async function valueEditor(t,r){const o=t.objectInfo||await objectInfo(t.object);if(!o||o.type!=='variable')return;if(Array.isArray(o.associations)&&o.associations.length){const s=mk('select');opts(s,o.associations.map(a=>({value:a.value,caption:a.name})),t.value);s.onchange=()=>t.value=o.varType===1?+s.value:o.varType===2?+s.value:s.value;r.append(s);return}if(o.varType===0){const s=mk('select');opts(s,[{value:'false',caption:'Aus / False'},{value:'true',caption:'Ein / True'}],String(t.value));s.onchange=()=>t.value=s.value;r.append(s);return}if((o.varType===1||o.varType===2)&&o.profileMin!==null&&o.profileMax!==null){const min=Number(o.profileMin),max=Number(o.profileMax),rawStep=Number(o.profileStep),step=rawStep>0?rawStep:(o.varType===1?1:0.1),suffix=o.profileSuffix||'';const count=Math.floor((max-min)/step+0.0000001)+1;if(count>0&&count<=500){const s=mk('select');const values=[];for(let i=0;i<count;i++){let v=min+i*step;if(o.varType===1)v=Math.round(v);else v=Math.round(v*1000000)/1000000;values.push({value:v,caption:String(v)+(suffix?' '+suffix.trim():'')})}if(!values.some(x=>Number(x.value)===Number(t.value))&&t.value!==''&&t.value!==undefined)values.push({value:Number(t.value),caption:String(t.value)+(suffix?' '+suffix.trim():'')});values.sort((a,b)=>Number(a.value)-Number(b.value));opts(s,values,t.value===''||t.value===undefined?min:t.value);s.onchange=()=>t.value=o.varType===1?parseInt(s.value,10):parseFloat(s.value);r.append(s);return}const n=mk('input');n.type='number';n.min=String(min);n.max=String(max);n.step=String(step);n.value=t.value===''||t.value===undefined?String(min):String(t.value);n.onchange=()=>t.value=o.varType===1?parseInt(n.value,10):parseFloat(n.value);r.append(n);if(suffix){const u=mk('span',suffix);u.className='source';r.append(u)}return}const v=mk('input');v.placeholder='Wert';v.value=t.value??'';v.oninput=()=>t.value=v.value;r.append(v)}
async function render(){const root=el('scenes');root.replaceChildren();for(const s of scenes){s.targets=(s.targets||[]).map(migrate);const c=mk('div');c.className='card';const top=mk('div');top.className='row';const n=mk('input');n.className='name';n.value=s.name||'';n.oninput=()=>s.name=n.value;const f=mk('button','Aus Splitter entfernen');f.className='danger';f.onclick=()=>forget(s.id);top.append(n,f);c.append(top);if(s.smartButtonName){const src=mk('div','Smart-Taster: '+s.smartButtonName+(s.smartButtonHost?' ('+s.smartButtonHost+')':''));src.className='source';c.append(src)}for(let j=0;j<s.targets.length;j++){const t=s.targets[j];const r=mk('div');r.className='row target';const typ=mk('select');opts(typ,[{value:'zeptrion',caption:'zeptrionAIR'},{value:'symcon',caption:'Symcon-Objekt'}],t.type||'zeptrion');typ.onchange=()=>{t.type=typ.value;if(t.type==='symcon'){delete t.instance;delete t.channel;delete t.memory}else{delete t.object;delete t.objectInfo}render()};r.append(typ);if(t.type==='symcon'){const o=t.objectInfo||await objectInfo(t.object);if(o)t.objectInfo=o;const p=mk('button',o?o.path:'Objekt auswählen …');p.className='objfield';p.onclick=()=>pick(t,render);r.append(p);if(o){const tag=mk('span',o.type==='script'?'Script':'Variable');tag.className='source';r.append(tag);await valueEditor(t,r)}}else{const q=mk('select');opts(q,Z,String(t.instance||0)+':'+String(t.channel||0));q.onchange=()=>{const a=q.value.split(':');t.instance=+a[0];t.channel=+a[1]};const mem=mk('select');opts(mem,[1,2,3,4].map(x=>({value:x,caption:'S'+x})),t.memory||1);mem.onchange=()=>t.memory=+mem.value;r.append(q,mem)}const d=mk('button','Entfernen');d.onclick=()=>{s.targets.splice(j,1);render()};r.append(d);c.append(r)}const a=mk('div');a.className='row';const add=mk('button','+ Ziel hinzufügen');add.onclick=()=>{const z=Z[0];s.targets.push(z?{type:'zeptrion',instance:z.instance,channel:z.channel,memory:1}:{type:'symcon',object:0});render()};const p=mk('button','Smart-Taste programmieren');p.onclick=()=>program(s);a.append(add,p);c.append(a);root.append(c)}}
function addScene(){scenes.push({id:Math.random().toString(36).slice(2),name:'Neue Szene',targets:[]});render()}
async function program(s){if(!confirm('Die Smart-Tasten beginnen jetzt zu blinken. Bitte danach die gewünschte blinkende Smart-Taste am Schalter drücken.'))return;msg('Smart-Tasten werden aktiviert. Bitte gewünschte blinkende Smart-Taste drücken …');busy(true);const clean=(s.targets||[]).map(t=>{const x={...t};delete x.objectInfo;return x});const r=await api({op:'program',scene:s.id,name:s.name,targets:clean});busy(false);if(r.ok&&r.scenes)scenes=r.scenes;await render();msg(r.message)}
async function forget(id){const r=await api({op:'forget',scene:id});if(r.ok&&r.scenes)scenes=r.scenes;await render();msg(r.message)}
async function clearButton(){if(!confirm('Die Smart-Tasten beginnen jetzt zu blinken. Bitte danach die Smart-Taste drücken, deren Programmierung gelöscht werden soll.'))return;msg('Smart-Tasten werden aktiviert. Bitte die zu löschende Smart-Taste drücken …');busy(true);const r=await api({op:'select-delete'});busy(false);msg(r.message)}
el('addScene').onclick=addScene;el('clearButton').onclick=clearButton;render();
</script></body></html>
HTML;
        return str_replace('__DATA__', $data, $html);
    }

    private function GetDeviceHosts(): array
    {
        $result = [];
        foreach (IPS_GetInstanceListByModuleID(self::DEVICE_MODULE_ID) as $id) {
            $host = trim((string)IPS_GetProperty($id, 'Host'));
            if ($host === '') {
                continue;
            }
            $name = trim(IPS_GetName($id));
            $result[$host] = ['instance' => $id, 'name' => $name !== '' ? $name : $host];
        }
        ksort($result, SORT_NATURAL);
        return $result;
    }

    private function GetTargets(): array
    {
        $result = [];
        foreach (IPS_GetInstanceListByModuleID(self::DEVICE_MODULE_ID) as $id) {
            $count = max(1, min(4, (int)IPS_GetProperty($id, 'Channels')));
            for ($channel = 1; $channel <= $count; $channel++) {
                if (strtolower((string)IPS_GetProperty($id, 'Channel' . $channel . 'Type')) === 'unused') {
                    continue;
                }
                $name = trim((string)IPS_GetProperty($id, 'Channel' . $channel . 'Name'));
                if ($name === '') {
                    $name = IPS_GetName($id) . ' / Kanal ' . $channel;
                }
                $result[] = ['value' => $id . ':' . $channel, 'caption' => $name, 'instance' => $id, 'channel' => $channel];
            }
        }
        usort($result, static fn($a, $b) => strnatcasecmp($a['caption'], $b['caption']));
        return $result;
    }

    private function GetObjectTreeChildren(int $parentID): array
    {
        $ids = $parentID === 0 ? IPS_GetChildrenIDs(0) : (IPS_ObjectExists($parentID) ? IPS_GetChildrenIDs($parentID) : []);
        $items = [];
        foreach ($ids as $id) {
            $info = $this->GetTreeObjectInfo((int)$id);
            if ($info !== null) {
                $items[] = $info;
            }
        }
        usort($items, static function (array $a, array $b): int {
            $rank = static function (array $item): int {
                $id = (int)($item['id'] ?? 0);
                if ($id > 0 && IPS_ObjectExists($id)) {
                    $object = IPS_GetObject($id);
                    $objectType = (int)($object['ObjectType'] ?? -1);
                    if ($objectType === 0) {
                        return 0; // Kategorien / Ordner
                    }
                    if ($objectType === 1) {
                        return 1; // Instanzen
                    }
                }
                return 2; // Variablen, Skripte und übrige Objekte
            };
            $ra = $rank($a);
            $rb = $rank($b);
            return $ra === $rb
                ? strnatcasecmp((string)$a['name'], (string)$b['name'])
                : ($ra <=> $rb);
        });
        return $items;
    }

    private function SearchObjectTree(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $needle = mb_strtolower($query);
        $items = [];
        foreach (IPS_GetObjectList() as $id) {
            if (!IPS_VariableExists($id) && !IPS_ScriptExists($id)) {
                continue;
            }
            $path = $this->ObjectPath($id);
            if (mb_strpos(mb_strtolower($path), $needle) === false) {
                continue;
            }
            $info = $this->GetSelectableObjectInfo($id);
            if ($info !== null) {
                $items[] = $info;
            }
            if (count($items) >= 100) {
                break;
            }
        }
        usort($items, static fn(array $a, array $b): int => strnatcasecmp((string)$a['path'], (string)$b['path']));
        return $items;
    }

    private function GetTreeObjectInfo(int $id): ?array
    {
        if (!IPS_ObjectExists($id)) {
            return null;
        }
        $object = IPS_GetObject($id);
        $selectable = IPS_VariableExists($id) || IPS_ScriptExists($id);
        $type = IPS_VariableExists($id) ? 'variable' : (IPS_ScriptExists($id) ? 'script' : 'container');
        return [
            'id' => $id,
            'name' => IPS_GetName($id),
            'path' => $this->ObjectPath($id),
            'type' => $type,
            'selectable' => $selectable,
            'hasChildren' => count(IPS_GetChildrenIDs($id)) > 0,
            'icon' => $type === 'variable' ? '●' : ($type === 'script' ? '▶' : '▸')
        ];
    }

    private function GetSelectableObjectInfo(int $id): ?array
    {
        if (IPS_VariableExists($id)) {
            $variable = IPS_GetVariable($id);
            $profileName = (string)($variable['VariableCustomProfile'] ?: $variable['VariableProfile']);
            $associations = [];
            $profileMin = null;
            $profileMax = null;
            $profileStep = null;
            $profileSuffix = '';
            if ($profileName !== '' && IPS_VariableProfileExists($profileName)) {
                $profile = IPS_GetVariableProfile($profileName);
                foreach (($profile['Associations'] ?? []) as $association) {
                    $associations[] = ['value' => $association['Value'], 'name' => $association['Name']];
                }
                $profileMin = $profile['MinValue'] ?? null;
                $profileMax = $profile['MaxValue'] ?? null;
                $profileStep = $profile['StepSize'] ?? null;
                $profileSuffix = (string)($profile['Suffix'] ?? '');
            }
            return [
                'id' => $id,
                'name' => IPS_GetName($id),
                'path' => $this->ObjectPath($id),
                'type' => 'variable',
                'selectable' => true,
                'varType' => (int)$variable['VariableType'],
                'associations' => $associations,
                'profileMin' => $profileMin,
                'profileMax' => $profileMax,
                'profileStep' => $profileStep,
                'profileSuffix' => $profileSuffix,
                'defaultValue' => (int)$variable['VariableType'] === 0 ? 'false' : ($profileMin ?? '')
            ];
        }
        if (IPS_ScriptExists($id)) {
            return [
                'id' => $id,
                'name' => IPS_GetName($id),
                'path' => $this->ObjectPath($id),
                'type' => 'script',
                'selectable' => true
            ];
        }
        return null;
    }

    private function ObjectPath(int $id): string
    {
        $parts = [];
        $current = $id;
        for ($i = 0; $i < 20 && $current > 0; $i++) {
            $parts[] = IPS_GetName($current);
            $current = IPS_GetParent($current);
        }
        return implode(' / ', array_reverse($parts));
    }

    private function NormalizeTargets(array $targets): array
    {
        $out = [];
        foreach ($targets as $target) {
            if (!is_array($target)) {
                continue;
            }
            $type = (string)($target['type'] ?? 'zeptrion');
            if ($type === 'zeptrion') {
                $instance = (int)($target['instance'] ?? 0);
                $channel = (int)($target['channel'] ?? 0);
                $memory = (int)($target['memory'] ?? 0);
                if ($this->IsDeviceInstance($instance) && $channel >= 1 && $channel <= 4 && $memory >= 1 && $memory <= 4) {
                    $out[] = ['type' => 'zeptrion', 'instance' => $instance, 'channel' => $channel, 'memory' => $memory];
                }
            } elseif ($type === 'symcon') {
                $id = (int)($target['object'] ?? 0);
                if (IPS_VariableExists($id)) {
                    $variable = IPS_GetVariable($id);
                    $value = $target['value'] ?? '';
                    switch ((int)$variable['VariableType']) {
                        case 0: $value = filter_var($value, FILTER_VALIDATE_BOOLEAN); break;
                        case 1: $value = (int)$value; break;
                        case 2: $value = (float)$value; break;
                        default: $value = (string)$value;
                    }
                    $out[] = ['type' => 'symcon', 'object' => $id, 'value' => $value];
                } elseif (IPS_ScriptExists($id)) {
                    $out[] = ['type' => 'symcon', 'object' => $id];
                }
            } elseif ($type === 'variable') {
                $id = (int)($target['variable'] ?? 0);
                if (IPS_VariableExists($id)) {
                    $out[] = ['type' => 'symcon', 'object' => $id, 'value' => $target['value'] ?? ''];
                }
            } elseif ($type === 'script') {
                $id = (int)($target['script'] ?? 0);
                if (IPS_ScriptExists($id)) {
                    $out[] = ['type' => 'symcon', 'object' => $id];
                }
            }
        }
        return $out;
    }

    private function IsDeviceInstance(int $id): bool
    {
        if ($id <= 0 || !IPS_InstanceExists($id)) {
            return false;
        }
        $instance = IPS_GetInstance($id);
        return (string)($instance['ModuleInfo']['ModuleID'] ?? '') === self::DEVICE_MODULE_ID;
    }

    private function ReadScenes(): array
    {
        $data = json_decode($this->ReadAttributeString('SmartButtonScenes'), true);
        return is_array($data) ? $data : [];
    }

    private function WriteScenes(array $scenes): void
    {
        $this->WriteAttributeString('SmartButtonScenes', json_encode(array_values($scenes), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}

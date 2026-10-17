<?php
declare(strict_types=1);

// Exercise real channel provisioning against the documented Symcon enumeration
// contract. This fixture is not a full Symcon kernel.
// https://www.symcon.de/de/llms/developer/sdk-tools/sdk-php/presentations.md
class PresentationFixture
{
    public static array $objects = [], $presentations = [], $actions = [], $calls = [];
    public static int $nextID = 100;

    public static function reset(): void
    {
        self::$objects = [99 => ['ParentID' => 0, 'ObjectIdent' => '', 'ObjectName' => 'Device', 'ObjectType' => 1]];
        self::$presentations = self::$actions = self::$calls = [];
        self::$nextID = 100;
    }

    public static function create(int $type, int $parent = 0, string $ident = '', string $name = ''): int
    {
        $id = self::$nextID++;
        self::$objects[$id] = ['ParentID' => $parent, 'ObjectIdent' => $ident, 'ObjectName' => $name, 'ObjectType' => $type];
        return $id;
    }

    public static function validate(array $presentation, string $api): void
    {
        if (($presentation['PRESENTATION'] ?? '') !== VARIABLE_PRESENTATION_ENUMERATION) return;
        $options = json_decode($presentation['OPTIONS'], true, 512, JSON_THROW_ON_ERROR);
        foreach ($options as $option) {
            $unknown = array_diff(array_keys($option), ['Value', 'Caption', 'IconActive', 'IconValue', 'Color']);
            if ($unknown !== []) {
                throw new RuntimeException($api . ': OPTIONS includes unknown sub-parameters: ' . implode(', ', $unknown));
            }
            if (!is_int($option['Value']) || !is_string($option['Caption']) || !is_bool($option['IconActive'])
                || !is_string($option['IconValue']) || !is_int($option['Color'])) {
                throw new RuntimeException($api . ': invalid enumeration option types');
            }
        }
        self::$calls[$api] = (self::$calls[$api] ?? 0) + 1;
    }
}

class IPSModuleStrict
{
    public int $InstanceID = 99;
    public array $props = ['Channels' => 4];
    protected function ReadPropertyInteger(string $key): int { return (int)($this->props[$key] ?? 0); }
    protected function ReadPropertyString(string $key): string { return (string)($this->props[$key] ?? ''); }
    protected function ReadPropertyBoolean(string $key): bool { return (bool)($this->props[$key] ?? false); }
    protected function GetIDForIdent(string $ident): int { return (int)IPS_GetObjectIDByIdent($ident, $this->InstanceID); }
    protected function EnableAction(string $ident): void { PresentationFixture::$actions[$ident] = true; }
    protected function RegisterVariableInteger(string $ident, string $name, array|string $presentation, int $position): void
    {
        $this->register($ident, $name, $presentation, $position);
    }
    protected function RegisterVariableBoolean(string $ident, string $name, array|string $presentation, int $position): void
    {
        $this->register($ident, $name, $presentation, $position);
    }
    private function register(string $ident, string $name, array|string $presentation, int $position): void
    {
        if (is_array($presentation)) PresentationFixture::validate($presentation, 'RegisterVariable');
        $id = IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($id === false) $id = PresentationFixture::create(2, $this->InstanceID, $ident, $name);
        IPS_SetPosition($id, $position);
        if (is_array($presentation)) PresentationFixture::$presentations[$id] = $presentation;
    }
    protected function UnregisterVariable(string $ident): void
    {
        foreach (PresentationFixture::$objects as $id => $object) {
            if ($object['ObjectIdent'] === $ident && $object['ObjectType'] === 2) unset(PresentationFixture::$objects[$id]);
        }
    }
}

function IPS_GetObjectIDByIdent(string $ident, int $parent): int|false {
    foreach (PresentationFixture::$objects as $id => $object) {
        if ($object['ObjectIdent'] === $ident && $object['ParentID'] === $parent) return $id;
    }
    return false;
}
function IPS_GetChildrenIDs(int $parent): array { return array_keys(array_filter(PresentationFixture::$objects, fn($o) => $o['ParentID'] === $parent)); }
function IPS_GetObject(int $id): array { return PresentationFixture::$objects[$id]; }
function IPS_VariableExists(int $id): bool { return (PresentationFixture::$objects[$id]['ObjectType'] ?? -1) === 2; }
function IPS_InstanceExists(int $id): bool { return (PresentationFixture::$objects[$id]['ObjectType'] ?? -1) === 1; }
function IPS_CreateInstance(string $moduleID): int { return PresentationFixture::create(1); }
function IPS_DeleteInstance(int $id): void { unset(PresentationFixture::$objects[$id]); }
function IPS_ApplyChanges(int $id): void {}
function IPS_SetParent(int $id, int $parent): void { PresentationFixture::$objects[$id]['ParentID'] = $parent; }
function IPS_SetIdent(int $id, string $ident): void { PresentationFixture::$objects[$id]['ObjectIdent'] = $ident; }
function IPS_SetName(int $id, string $name): void { PresentationFixture::$objects[$id]['ObjectName'] = $name; }
function IPS_GetName(int $id): string { return PresentationFixture::$objects[$id]['ObjectName']; }
function IPS_SetPosition(int $id, int $position): void { PresentationFixture::$objects[$id]['ObjectPosition'] = $position; }
function IPS_SetVariableCustomPresentation(int $id, array $presentation): void {
    PresentationFixture::validate($presentation, 'IPS_SetVariableCustomPresentation');
    PresentationFixture::$presentations[$id] = $presentation;
}
foreach (['ENUMERATION', 'SWITCH', 'SLIDER', 'SHUTTER'] as $type) define('VARIABLE_PRESENTATION_' . $type, $type);
define('VARIABLETYPE_BOOLEAN', 0);
define('VARIABLETYPE_INTEGER', 1);
require dirname(__DIR__) . '/ZeptrionAir/module.php';

$checks = 0;
function expect(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function options(int $id): array {
    return json_decode(PresentationFixture::$presentations[$id]['OPTIONS'], true, 512, JSON_THROW_ON_ERROR);
}
$apply = new ReflectionMethod(ZeptrionAir::class, 'ApplyChannelVariables');
foreach ([false, true] as $withScenes) {
    PresentationFixture::reset();
    $device = new ZeptrionAir();
    foreach ([1 => 'light', 2 => 'dimmer', 3 => 'shutter', 4 => 'awning'] as $channel => $type) {
        $device->props['Channel' . $channel . 'Type'] = $type;
        for ($scene = 1; $scene <= 4; $scene++) {
            $device->props['Channel' . $channel . 'Scene' . $scene . 'Visible'] = $withScenes;
            $device->props['Channel' . $channel . 'Scene' . $scene . 'Name'] = 'Szene ' . $scene;
        }
    }
    $apply->invoke($device);
    $commands = [];
    foreach ([2 => [0, 2, 4], 3 => [0, 1, 2, 3, 4], 4 => [0, 2, 4]] as $channel => $values) {
        $id = IPS_GetObjectIDByIdent('Ch' . $channel . 'Command', 99);
        $commands[$channel] = $id;
        expect($id !== false && PresentationFixture::$actions['Ch' . $channel . 'Command'], 'Motor/dimmer control is created with action');
        expect(array_column(options($id), 'Value') === array_merge($values, $withScenes ? [11, 12, 13, 14] : []), 'Command values and enabled scene commands are preserved');
    }
    $sceneID = IPS_GetObjectIDByIdent('Ch1Scene', 99);
    expect($withScenes ? ($sceneID !== false && array_column(options($sceneID), 'Value') === [1, 2, 3, 4]) : $sceneID === false, 'Separate light scene selector follows scene visibility');
    expect((PresentationFixture::$calls['RegisterVariable'] ?? 0) > 0 && (PresentationFixture::$calls['IPS_SetVariableCustomPresentation'] ?? 0) > 0, 'Both Symcon presentation APIs validate enumeration options');
    // Reapply with changed scene names and visibility; existing command IDs must survive.
    for ($channel = 1; $channel <= 4; $channel++) {
        $device->props['Channel' . $channel . 'Scene1Visible'] = true;
        $device->props['Channel' . $channel . 'Scene1Name'] = 'Abend';
        $device->props['Channel' . $channel . 'Scene4Visible'] = false;
    }
    $apply->invoke($device);
    foreach ($commands as $channel => $id) {
        expect(IPS_GetObjectIDByIdent('Ch' . $channel . 'Command', 99) === $id, 'Reapply preserves existing command variable ID');
        $byValue = array_column(options($id), null, 'Value');
        expect($byValue[11]['Caption'] === 'Abend' && !isset($byValue[14]), 'Reapply updates scene caption and removes hidden scene');
    }
}
echo "$checks presentation provisioning checks passed.\n";

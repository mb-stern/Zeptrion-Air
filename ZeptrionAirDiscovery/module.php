<?php
declare(strict_types=1);

class ZeptrionAirDiscovery extends IPSModuleStrict
{
    private const DEVICE_MODULE_ID = '{75F3D2A4-9D4E-4E5C-A07E-8EFA49D824C1}';
    private const SPLITTER_MODULE_ID = '{C7B836D4-9DA7-4C88-9AA0-0E8D4A5B52A1}';
    private const ZEROCONF_MODULE_ID = '{780B2D48-916C-4D59-AD35-5A429B2355A5}';

    public function Create(): void
    {
        parent::Create();
    }

    public function GetCompatibleParents(): string
    {
        return json_encode
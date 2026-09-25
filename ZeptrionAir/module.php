<?php

declare(strict_types=1);

class ZeptrionAir extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyString('DeviceName', '');
        $this->RegisterPropertyString('DeviceType', '');
        $this->RegisterPropertyString('SerialNumber', '');
        $this->RegisterPropertyInteger('Channels', 2);
        $this->RegisterPropertyInteger('PollInterval', 30);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if (trim($this->ReadPropertyString('Host')) === '') {
            $this->SetStatus(201);
            return;
        }

        $this->SetStatus(102);
    }
}

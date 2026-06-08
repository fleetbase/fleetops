<?php

namespace Fleetbase\FleetOps\Support\FuelProviders;

class FuelProviderDescriptor
{
    public string $key;
    public string $label;
    public string $type;
    public ?string $driverClass;
    public ?string $description;
    public ?string $docsUrl;
    public array $requiredFields;
    public array $capabilities;
    public array $metadata;

    public function __construct(array $config)
    {
        $this->key            = $config['key'];
        $this->label          = $config['label'] ?? $config['key'];
        $this->type           = $config['type'] ?? 'native';
        $this->driverClass    = $config['driver_class'] ?? null;
        $this->description    = $config['description'] ?? null;
        $this->docsUrl        = $config['docs_url'] ?? null;
        $this->requiredFields = $config['required_fields'] ?? [];
        $this->capabilities   = $config['capabilities'] ?? [];
        $this->metadata       = $config['metadata'] ?? [];
    }

    public function toArray(): array
    {
        return [
            'key'             => $this->key,
            'label'           => $this->label,
            'type'            => $this->type,
            'description'     => $this->description,
            'docs_url'        => $this->docsUrl,
            'required_fields' => $this->requiredFields,
            'capabilities'    => $this->capabilities,
            'metadata'        => $this->metadata,
        ];
    }
}

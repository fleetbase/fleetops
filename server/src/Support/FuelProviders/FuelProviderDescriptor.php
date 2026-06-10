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
    public ?string $category;
    public ?string $icon;
    public array $requiredFields;
    public array $capabilities;
    public array $syncDefaults;
    public array $setupInstructions;
    public array $metadata;

    public function __construct(array $config)
    {
        $this->key               = $config['key'];
        $this->label             = $config['label'] ?? $config['key'];
        $this->type              = $config['type'] ?? 'native';
        $this->driverClass       = $config['driver_class'] ?? null;
        $this->description       = $config['description'] ?? null;
        $this->docsUrl           = $config['docs_url'] ?? null;
        $this->category          = $config['category'] ?? null;
        $this->icon              = $config['icon'] ?? 'gas-pump';
        $this->requiredFields    = $config['required_fields'] ?? [];
        $this->capabilities      = $config['capabilities'] ?? [];
        $this->syncDefaults      = $config['sync_defaults'] ?? [];
        $this->setupInstructions = $config['setup_instructions'] ?? [];
        $this->metadata          = $config['metadata'] ?? [];
    }

    public function toArray(): array
    {
        return [
            'key'                => $this->key,
            'label'              => $this->label,
            'type'               => $this->type,
            'description'        => $this->description,
            'docs_url'           => $this->docsUrl,
            'category'           => $this->category,
            'icon'               => $this->icon,
            'required_fields'    => $this->requiredFields,
            'capabilities'       => $this->capabilities,
            'sync_defaults'      => $this->syncDefaults,
            'setup_instructions' => $this->setupInstructions,
            'metadata'           => $this->metadata,
        ];
    }
}

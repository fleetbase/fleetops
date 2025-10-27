<?php

namespace Fleetbase\FleetOps\Contracts;

use Fleetbase\FleetOps\Support\Utils;

/**
 * Class TelematicProviderDescriptor.
 *
 * Data Transfer Object for provider metadata.
 * Used by the ProviderRegistry to describe available providers.
 */
class TelematicProviderDescriptor
{
    public string $key;
    public string $label;
    public string $type; // 'native' or 'custom'
    public ?string $driverClass;
    public ?string $icon;
    public ?string $description;
    public ?string $docsUrl;
    public array $requiredFields;
    public bool $supportsWebhooks;
    public bool $supportsDiscovery;
    public array $metadata;

    /**
     * Create a new ProviderDescriptor instance.
     */
    public function __construct(array $data)
    {
        $this->key               = $data['key'];
        $this->label             = $data['label'];
        $this->type              = $data['type'] ?? 'native';
        $this->driverClass       = $data['driver_class'] ?? null;
        $this->icon              = $data['icon'] ?? null;
        $this->description       = $data['description'] ?? null;
        $this->docsUrl           = $data['docs_url'] ?? null;
        $this->requiredFields    = $data['required_fields'] ?? [];
        $this->supportsWebhooks  = $data['supports_webhooks'] ?? false;
        $this->supportsDiscovery = $data['supports_discovery'] ?? false;
        $this->metadata          = $data['metadata'] ?? [];
    }

    /**
     * Convert to array for JSON serialization.
     */
    public function toArray(): array
    {
        return [
            'key'                => $this->key,
            'label'              => $this->label,
            'type'               => $this->type,
            'icon'               => $this->icon,
            'description'        => $this->description,
            'docs_url'           => $this->docsUrl,
            'required_fields'    => $this->requiredFields,
            'supports_webhooks'  => $this->supportsWebhooks,
            'supports_discovery' => $this->supportsDiscovery,
            'metadata'           => $this->metadata,
            'webhook_url'        => Utils::apiUrl('webhooks/telematics/' . $this->key),
        ];
    }

    /**
     * Get JSON representation.
     */
    public function toJson(): string
    {
        return json_encode($this->toArray());
    }
}

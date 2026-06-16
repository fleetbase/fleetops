<?php

namespace Fleetbase\FleetOps\Http\Filter;

use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Filter\Filter;

class DeviceFilter extends Filter
{
    public function queryForInternal()
    {
        $this->builder->where('company_uuid', $this->session->get('company'));
    }

    public function queryForPublic()
    {
        $this->queryForInternal();
    }

    public function query(?string $searchQuery)
    {
        $this->builder->search($searchQuery);
    }

    public function status(string|array $status)
    {
        $status = Utils::arrayFrom($status);

        if ($status) {
            $this->builder->whereIn('status', $status);
        }
    }

    public function telematic(?string $telematic)
    {
        $this->builder->where('telematic_uuid', $telematic);
    }

    public function telematicUuid(?string $telematic)
    {
        $this->telematic($telematic);
    }

    public function provider(?string $provider)
    {
        $this->builder->where('provider', $provider);
    }

    public function warrantyUuid(?string $warranty)
    {
        $this->builder->where('warranty_uuid', $warranty);
    }

    public function attachableType(?string $attachableType)
    {
        $this->builder->where('attachable_type', $attachableType);
    }

    public function attachableUuid(?string $attachable)
    {
        $this->builder->where('attachable_uuid', $attachable);
    }

    public function attachmentState(?string $attachmentState)
    {
        if ($attachmentState === 'attached') {
            $this->builder->whereNotNull('attachable_uuid');
        }

        if ($attachmentState === 'unattached') {
            $this->builder->whereNull('attachable_uuid');
        }
    }
}

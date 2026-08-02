<?php

namespace Fleetbase\FleetOps\Casts;

use Fleetbase\Casts\Json;
use Fleetbase\Models\File;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

class OrderConfigEntities implements CastsAttributes
{
    /**
     * Cast the given value.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @param string                              $key
     * @param array                               $attributes
     *
     * @return array
     */
    public function get($model, $key, $value, $attributes)
    {
        $entities = Json::decode($value);
        if (is_array($entities)) {
            $entities = array_map(function ($entity) {
                if (isset($entity['photo_uuid'])) {
                    $photoUrl = $this->photoUrlFor($entity['photo_uuid']);
                    if ($photoUrl) {
                        $entity['photo_url'] = $photoUrl;
                    }
                }

                return $entity;
            }, $entities);
        }

        return $entities;
    }

    /**
     * Prepare the given value for storage.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @param string                              $key
     * @param array                               $value
     * @param array                               $attributes
     *
     * @return string
     */
    public function set($model, $key, $value, $attributes)
    {
        return json_encode($value);
    }

    protected function photoUrlFor(string $photoUuid): ?string
    {
        $file = File::where('uuid', $photoUuid)->first();

        return $file?->url;
    }
}

<?php

namespace Fleetbase\FleetOps\Seeders\Testing\Concerns;

use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\FleetOps\Models\Zone;
use Fleetbase\LaravelMysqlSpatial\Types\LineString;
use Fleetbase\LaravelMysqlSpatial\Types\MultiPolygon;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\LaravelMysqlSpatial\Types\Polygon;
use Fleetbase\Models\Company;
use Fleetbase\Seeders\Concerns\ResolvesSeedCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

trait SeedsTestingData
{
    use ResolvesSeedCompany;

    protected const SEED_NAME = 'fleetops-testing';

    protected function resolveCompany(): ?Company
    {
        return $this->resolveSeedCompany();
    }

    protected function prepareCompany(): ?Company
    {
        $company = $this->resolveCompany();
        if (!$company) {
            $this->command?->error('No company found. Create a Fleetbase company before running FleetOps testing seeders.');

            return null;
        }

        session(['company' => $company->uuid]);

        return $company;
    }

    protected function fixtureKey(string $seedId): string
    {
        return static::SEED_NAME . ':' . $seedId;
    }

    protected function meta(string $seedId, array $extra = []): array
    {
        return array_merge([
            'seed'    => static::SEED_NAME,
            'seed_id' => $seedId,
        ], $extra);
    }

    protected function timestamp(int $hoursOffset = 0): Carbon
    {
        return Carbon::parse('2026-01-15 08:00:00', 'Asia/Singapore')->addHours($hoursOffset);
    }

    protected function point(float $lat, float $lng): Point
    {
        return new Point($lat, $lng);
    }

    protected function polygon(array $coordinates): Polygon
    {
        $points = array_map(fn (array $coordinate) => $this->point($coordinate[0], $coordinate[1]), $coordinates);
        if ($points[0]->getLat() !== end($points)->getLat() || $points[0]->getLng() !== end($points)->getLng()) {
            $points[] = $points[0];
        }

        return new Polygon([new LineString($points)]);
    }

    protected function multiPolygon(array $coordinates): MultiPolygon
    {
        return new MultiPolygon([$this->polygon($coordinates)]);
    }

    protected function createRecord(string $modelClass, array $attributes, bool $withoutEvents = false): Model
    {
        /** @var Model $model */
        $model      = new $modelClass();
        $attributes = $this->filterColumns($model, array_merge([
            'uuid'       => (string) Str::uuid(),
            'created_at' => $this->timestamp(),
            'updated_at' => $this->timestamp(),
        ], $attributes));

        $model->forceFill($attributes);

        if ($withoutEvents) {
            $modelClass::withoutEvents(fn () => $model->save());
        } else {
            $model->save();
        }

        return $model;
    }

    protected function filterColumns(Model $model, array $attributes): array
    {
        $table = $model->getTable();

        if (!Schema::hasTable($table)) {
            return $attributes;
        }

        return array_filter(
            $attributes,
            fn (string $column) => Schema::hasColumn($table, $column),
            ARRAY_FILTER_USE_KEY
        );
    }

    protected function seededQuery(string $modelClass)
    {
        /** @var Model $model */
        $model = new $modelClass();
        $table = $model->getTable();
        $query = $modelClass::query();

        if (Schema::hasColumn($table, 'meta')) {
            return $query->where('meta->seed', static::SEED_NAME);
        }

        if (Schema::hasColumn($table, '_key')) {
            return $query->where('_key', 'like', static::SEED_NAME . ':%');
        }

        return $query->whereRaw('1 = 0');
    }

    protected function purgeModel(string $modelClass): void
    {
        /** @var Model $model */
        $model = new $modelClass();
        $query = $this->seededQuery($modelClass);

        if (in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
            $query->forceDelete();

            return;
        }

        $query->delete();
    }

    protected function seededModel(string $modelClass, string $seedId): ?Model
    {
        /** @var Model $model */
        $model = new $modelClass();
        $table = $model->getTable();

        if (Schema::hasColumn($table, 'meta')) {
            return $modelClass::where('meta->seed', static::SEED_NAME)->where('meta->seed_id', $seedId)->first();
        }

        if (Schema::hasColumn($table, '_key')) {
            return $modelClass::where('_key', $this->fixtureKey($seedId))->first();
        }

        return null;
    }

    protected function seededServiceArea(string $seedId): ?ServiceArea
    {
        /** @var ServiceArea|null $serviceArea */
        $serviceArea = $this->seededModel(ServiceArea::class, $seedId);

        return $serviceArea;
    }

    protected function seededZone(string $seedId): ?Zone
    {
        /** @var Zone|null $zone */
        $zone = $this->seededModel(Zone::class, $seedId);

        return $zone;
    }
}

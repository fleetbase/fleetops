<?php

namespace Fleetbase\FleetOps\Support\Ai\Capabilities;

use Fleetbase\Ai\Contracts\AIContextCapabilityInterface;
use Fleetbase\Ai\Models\AiTask;
use Fleetbase\Ai\Support\Capabilities\AbstractAICapability;
use Fleetbase\Support\Auth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

abstract class AbstractFleetOpsAICapability extends AbstractAICapability implements AIContextCapabilityInterface
{
    public function module(): string
    {
        return 'fleet-ops';
    }

    public function shouldResolve(AiTask $task): bool
    {
        return $this->matchesPrompt($this->prompt($task));
    }

    abstract protected function matchesPrompt(string $prompt): bool;

    protected function prompt(AiTask $task): string
    {
        return Str::lower(trim((string) $task->prompt));
    }

    protected function containsAny(string $prompt, array $terms): bool
    {
        foreach ($terms as $term) {
            if (str_contains($prompt, Str::lower($term))) {
                return true;
            }
        }

        return false;
    }

    protected function can(string $permission): bool
    {
        $user = Auth::getUserFromSession();

        if ($user?->isAdmin()) {
            return true;
        }

        return Auth::can($permission);
    }

    protected function canAll(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (!$this->can($permission)) {
                return false;
            }
        }

        return true;
    }

    protected function searchTerms(string $prompt): array
    {
        preg_match_all('/[A-Z]{2,}[-_][A-Z0-9-_]+|[A-Za-z0-9][A-Za-z0-9-_]{2,}/', (string) $prompt, $matches);

        $terms = collect($matches[0] ?? [])
            ->reject(fn ($term) => in_array(Str::lower($term), ['find', 'show', 'open', 'order', 'orders', 'vehicle', 'vehicles', 'driver', 'drivers', 'work', 'status', 'about', 'fleet', 'ops'], true))
            ->unique()
            ->take(6)
            ->values()
            ->all();

        return empty($terms) ? [trim((string) $prompt)] : $terms;
    }

    protected function whereLikeAny(Builder $builder, array $columns, array $terms): void
    {
        $builder->where(function (Builder $query) use ($columns, $terms) {
            foreach ($columns as $column) {
                foreach ($terms as $term) {
                    $query->orWhere($column, 'like', '%' . $term . '%');
                }
            }
        });
    }
}

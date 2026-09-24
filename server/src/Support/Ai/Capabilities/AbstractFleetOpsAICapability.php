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
    /**
     * Words that never identify a record and must not be used as search terms.
     */
    protected const SEARCH_STOPWORDS = [
        'the', 'and', 'for', 'with', 'from', 'this', 'that', 'what', 'where', 'when', 'which', 'who', 'how', 'many', 'much',
        'can', 'could', 'would', 'should', 'please', 'help', 'want', 'need', 'show', 'find', 'open', 'look', 'tell', 'about',
        'status', 'order', 'orders', 'vehicle', 'vehicles', 'driver', 'drivers', 'device', 'devices', 'sensor', 'sensors',
        'telematic', 'telematics', 'maintenance', 'maintenances', 'work', 'fleet', 'fleets', 'ops', 'create', 'new', 'all',
        'today', 'yesterday', 'tomorrow', 'week', 'month', 'fleetbase', 'import', 'template', 'download', 'dummy', 'test',
    ];

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

    /**
     * Applies the current user's IAM directives (record-level scoping) for a permission to a query.
     */
    protected function scopeToPermission($query, string $permission)
    {
        if ($query instanceof Builder && Builder::hasGlobalMacro('applyDirectivesForPermissions')) {
            return $query->applyDirectivesForPermissions($permission);
        }

        return $query;
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

    /**
     * Extracts record-reference-like search terms from a prompt.
     *
     * Only terms that plausibly identify a record are kept: quoted phrases, tokens containing a digit
     * (public ids, plates, tracking numbers), emails and other delimited identifiers, and capitalized
     * names that are not the first word. Ordinary words are never used as LIKE terms, and an empty
     * array is returned when the prompt does not reference a specific record.
     */
    protected function searchTerms(string $prompt): array
    {
        $prompt = trim($prompt);
        $terms  = [];

        preg_match_all('/"([^"]{2,64})"|\'([^\']{2,64})\'/u', $prompt, $quoted);
        foreach (array_merge($quoted[1] ?? [], $quoted[2] ?? []) as $phrase) {
            if (trim($phrase) !== '') {
                $terms[] = trim($phrase);
            }
        }

        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}@._\-]*[\p{L}\p{N}]/u', $prompt, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] ?? [] as [$token, $offset]) {
            if ($this->isReferenceTerm($token, $offset === 0)) {
                $terms[] = $token;
            }
        }

        return collect($terms)
            ->unique(fn ($term) => Str::lower($term))
            ->take(6)
            ->values()
            ->all();
    }

    protected function isReferenceTerm(string $token, bool $isFirstWord = false): bool
    {
        if (mb_strlen($token) < 3 || in_array(Str::lower($token), static::SEARCH_STOPWORDS, true)) {
            return false;
        }

        // Public ids, plates, tracking numbers, emails, and upper-case codes such as ORDER-ABC.
        if (preg_match('/\p{N}|[@_]/u', $token) || preg_match('/^[\p{Lu}\p{N}]+(?:-[\p{Lu}\p{N}]+)+$/u', $token)) {
            return true;
        }

        return !$isFirstWord && preg_match('/^\p{Lu}\p{Ll}+$/u', $token) === 1;
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

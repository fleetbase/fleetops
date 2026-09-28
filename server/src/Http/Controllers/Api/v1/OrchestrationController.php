<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Http\Controllers\Internal\v1\OrchestrationController as InternalOrchestrationController;
use Fleetbase\FleetOps\Orchestration\OrchestrationEngineRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public consumable API for FleetOps orchestration.
 *
 * This controller delegates orchestration behavior to the internal workbench
 * controller and only changes the public API serialization boundary.
 */
class OrchestrationController extends InternalOrchestrationController
{
    public function __construct(OrchestrationEngineRegistry $registry)
    {
        // The fleetbase.api route group authenticates API credentials and establishes
        // their company scope. Console IAM middleware belongs to the internal
        // workbench, not to these organization-credential endpoints.
        $this->registry = $registry;
    }

    public function run(Request $request): JsonResponse
    {
        return $this->publicResponse(parent::run($request));
    }

    public function commit(Request $request): JsonResponse
    {
        return $this->publicResponse(parent::commit($request));
    }

    protected function publicResponse(JsonResponse $response): JsonResponse
    {
        return response()->json(
            $this->sanitizePublicPayload($response->getData(true)),
            $response->getStatusCode()
        );
    }

    /**
     * Recursively remove internal identifiers from public orchestrator payloads.
     */
    protected function sanitizePublicPayload(mixed $payload): mixed
    {
        if (!is_array($payload)) {
            return $payload;
        }

        $sanitized = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && $this->isInternalIdentifierKey($key)) {
                continue;
            }

            $sanitized[$key] = $this->sanitizePublicPayload($value);
        }

        return $sanitized;
    }

    protected function isInternalIdentifierKey(string $key): bool
    {
        return $key === 'uuid'
            || str_ends_with($key, '_uuid')
            || $key === 'internal_id'
            || $key === 'company_uuid';
    }
}

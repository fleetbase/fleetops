<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Http\Requests\CreateIssueRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateIssueRequest;
use Fleetbase\FleetOps\Http\Resources\v1\Issue as DeletedIssue;
use Fleetbase\FleetOps\Http\Resources\v1\Issue as IssueResource;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Http\Controllers\Internal\v1\IssueController as InternalIssueController;
use Fleetbase\FleetOps\Models\Issue;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class IssueController extends Controller
{
    /**
     * Creates a new Fleetbase Issue resource.
     *
     * @param \Fleetbase\Http\Requests\CreateIssueRequest $request
     *
     * @return \Fleetbase\Http\Resources\Entity
     */
    public function create(CreateIssueRequest $request)
    {
        // get request input
        $input = $request->only([
            'driver',
            'location',
            'category',
            'type',
            'report',
            'priority',
            'tags',
            'status',
        ]);

        // Find driver who is reporting
        try {
            $driver = $this->findDriverRecord($request->input('driver'));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'Driver reporting issue not found.',
                ],
                404
            );
        }

        // get the user uuid
        $input['company_uuid']      = $driver->company_uuid;
        $input['driver_uuid']       = $driver->uuid;
        $input['reported_by_uuid']  = $driver->user_uuid;
        $input['vehicle_uuid']      = $driver->vehicle_uuid;

        // The order the issue is about. The request only checks that such an
        // order exists somewhere; this looks for it in the driver's own
        // organization, so an id from another one links nothing.
        if ($request->filled('order')) {
            $orderUuid = $this->findOrderUuid($request->input('order'), $driver->company_uuid);
            if (!$orderUuid) {
                return $this->jsonResponse(
                    [
                        'error' => 'Order the issue is about not found.',
                    ],
                    404
                );
            }

            $input['order_uuid'] = $orderUuid;
        }

        // create the issue
        $issue = $this->createIssue($input);

        // response the driver resource
        return $this->issueResource($this->withPublicRelations($issue));
    }

    /**
     * Updates new Fleetbase Issue resource.
     *
     * @param string                                      $id
     * @param \Fleetbase\Http\Requests\UpdateIssueRequest $request
     *
     * @return \Fleetbase\Http\Resources\Issue
     */
    public function update($id, UpdateIssueRequest $request)
    {
        // find for the issue
        try {
            $issue = $this->findIssueRecord($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'Issue resource not found.',
                ],
                404
            );
        }

        $input = $request->only([
            'category',
            'type',
            'report',
            'priority',
            'tags',
            'status',
        ]);

        // `order` relinks the issue; sent empty it unlinks it.
        if ($request->exists('order')) {
            $orderUuid = $request->filled('order') ? $this->findOrderUuid($request->input('order'), $issue->company_uuid) : null;
            if ($request->filled('order') && !$orderUuid) {
                return $this->jsonResponse(
                    [
                        'error' => 'Order the issue is about not found.',
                    ],
                    404
                );
            }

            $input['order_uuid'] = $orderUuid;
        }

        // update the issue
        $issue->update($input);

        // response the issue resource
        return $this->issueResource($this->withPublicRelations($issue));
    }

    /**
     * Query for Fleetbase Issue resources.
     *
     * @return \Fleetbase\Http\Resources\FleetCollection
     */
    public function query(Request $request)
    {
        $results = $this->queryIssues($request);

        // The order each issue is about, in two queries for the whole list
        // rather than two per row.
        if ($results instanceof \Illuminate\Database\Eloquent\Collection) {
            $results->load(['order.trackingNumber']);
        }

        return $this->issueResourceCollection($results);
    }

    /**
     * Finds a single Fleetbase Issue resources.
     *
     * @return \Fleetbase\Http\Resources\ContactCollection
     */
    public function find($id)
    {
        // find for the issue
        try {
            $issue = $this->findIssueRecord($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'Issue resource not found.',
                ],
                404
            );
        }

        // response the issue resource
        return $this->issueResource($this->withPublicRelations($issue));
    }

    /**
     * GET /v1/issues/{id}/timeline — what has happened to an issue since it was filed.
     *
     * The same events the console draws, built by the same code, for an issue
     * in the caller's organization. What goes out is narrower than what the
     * console gets: the console's events carry record uuids as ids and print
     * them into the text when a linked record changes, and the public API does
     * not hand out uuids.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function timeline($id)
    {
        try {
            $issue = $this->findIssueRecord($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'Issue resource not found.',
                ],
                404
            );
        }

        $events = collect($this->timelineEventsFor($issue))->map(fn ($event) => $this->publicTimelineEvent((array) $event))->values()->all();

        return $this->jsonResponse(['events' => $events], 200);
    }

    /**
     * Deletes a Fleetbase Issue resources.
     *
     * @return \Fleetbase\Http\Resources\FleetCollection
     */
    public function delete($id)
    {
        // find for the driver
        try {
            $issue = $this->findIssueRecord($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return $this->jsonResponse(
                [
                    'error' => 'Issue resource not found.',
                ],
                404
            );
        }

        // delete the issue
        $issue->delete();

        // response the issue resource
        return $this->deletedIssueResource($issue);
    }

    /**
     * The console's timeline for the issue, as plain arrays. The console's
     * handler checks the issue against the session's organization itself.
     */
    protected function timelineEventsFor(Issue $issue): array
    {
        $response = app(InternalIssueController::class)->timeline($issue->uuid);
        if (!method_exists($response, 'getData') || $response->getStatusCode() !== 200) {
            return [];
        }

        return (array) data_get($response->getData(true), 'events', []);
    }

    /**
     * One timeline event without anything that identifies a record by uuid.
     *
     * The id becomes a short hash of the console's (still stable, so a list
     * can key on it). A change to a linked record (`assigned_to_uuid`,
     * `order_uuid`…) says only that it changed: its old and new values are
     * uuids. The icon and tone are the console's own drawing hints and stay
     * behind.
     */
    protected function publicTimelineEvent(array $event): array
    {
        $field       = data_get($event, 'meta.field');
        $linksRecord = is_string($field) && Str::endsWith($field, '_uuid');
        $meta        = [];

        if (is_string($field)) {
            $meta['field'] = $linksRecord ? Str::beforeLast($field, '_uuid') : $field;
            if (!$linksRecord) {
                $meta['from'] = data_get($event, 'meta.from');
                $meta['to']   = data_get($event, 'meta.to');
            }
        }

        foreach (['file_name', 'file_url'] as $key) {
            if (data_get($event, "meta.$key") !== null) {
                $meta[$key] = data_get($event, "meta.$key");
            }
        }

        return [
            'id'          => 'event_' . substr(sha1((string) data_get($event, 'id')), 0, 16),
            'type'        => data_get($event, 'type'),
            'label'       => data_get($event, 'label'),
            'description' => $linksRecord ? Str::headline($meta['field']) . ' changed.' : data_get($event, 'description'),
            'actor_name'  => data_get($event, 'actor_name'),
            'created_at'  => data_get($event, 'created_at'),
            'meta'        => $meta ?: new \stdClass(),
        ];
    }

    /**
     * What a public caller reads with an issue: its photos, and the order it
     * is about. Neither is loaded with the model by default.
     */
    protected function withPublicRelations(Issue $issue): Issue
    {
        return $issue->load(['files', 'order.trackingNumber']);
    }

    protected function findOrderUuid(string $id, ?string $companyUuid): ?string
    {
        return Order::where('public_id', $id)->where('company_uuid', $companyUuid)->value('uuid');
    }

    protected function findDriverRecord(?string $id): Driver
    {
        return Driver::findRecordOrFail($id);
    }

    protected function createIssue(array $input): Issue
    {
        return Issue::create($input);
    }

    protected function findIssueRecord(string $id): Issue
    {
        return Issue::findRecordOrFail($id);
    }

    protected function queryIssues(Request $request)
    {
        return Issue::queryWithRequest($request);
    }

    protected function issueResource(Issue $issue)
    {
        return new IssueResource($issue);
    }

    protected function issueResourceCollection($results)
    {
        return IssueResource::collection($results);
    }

    protected function deletedIssueResource(Issue $issue)
    {
        return new DeletedIssue($issue);
    }

    protected function jsonResponse(array $payload, int $status)
    {
        return response()->json($payload, $status);
    }
}

<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Http\Requests\CreateIssueRequest;
use Fleetbase\FleetOps\Http\Requests\UpdateIssueRequest;
use Fleetbase\FleetOps\Http\Resources\v1\Issue as DeletedIssue;
use Fleetbase\FleetOps\Http\Resources\v1\Issue as IssueResource;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\Issue;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\Request;

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

        // create the issue
        $issue = $this->createIssue($input);

        // response the driver resource
        return $this->issueResource($issue);
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

        // update the issue
        $issue->update($input);

        // response the issue resource
        return $this->issueResource($issue);
    }

    /**
     * Query for Fleetbase Issue resources.
     *
     * @return \Fleetbase\Http\Resources\FleetCollection
     */
    public function query(Request $request)
    {
        $results = $this->queryIssues($request);

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
        return $this->issueResource($issue);
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

    protected function findDriverRecord(string $id): Driver
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

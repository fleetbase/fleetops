<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Http\Resources\v1\InspectionForm as InspectionFormResource;
use Fleetbase\FleetOps\Http\Resources\v1\InspectionSubmission as InspectionSubmissionResource;
use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Models\InspectionForm;
use Fleetbase\FleetOps\Models\InspectionSubmission;
use Fleetbase\FleetOps\Models\Vehicle;
use Fleetbase\FleetOps\Support\InspectionSubmitter;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Driver-facing inspections.
 *
 * A DVIR is filled in by the driver, on a handset, often before the vehicle
 * has left the yard and sometimes with no signal. Until now the only way in
 * was a tokenised public link minted from the console, one per inspection —
 * which is fine for a contractor with a phone number and no app, and useless
 * for a driver who inspects the same truck every morning.
 *
 * These endpoints let the app read the forms its company has published, file
 * a submission against one, and read back what was filed. Authoring forms,
 * reviewing and resolving submissions stay on the internal namespace: that is
 * fleet management, not driving.
 */
class InspectionController extends Controller
{
    /**
     * The relations a submission is answered with, so the app never has to
     * make a second request to learn what its own submission produced.
     */
    protected const SUBMISSION_RELATIONS = ['form', 'vehicle', 'driver', 'itemResults', 'issue', 'workOrder'];

    /**
     * GET /v1/inspection-forms — the published forms a driver may fill in.
     *
     * `vehicle` narrows to forms bound to that vehicle *and* forms bound to
     * nothing: an organisation-wide pre-trip form applies to every truck, and
     * a form written for one truck's lift gate applies to that truck only.
     */
    public function queryForms(Request $request)
    {
        $query = static::publishedForms();

        if ($request->filled('type')) {
            $query->whereIn('type', Utils::arrayFrom($request->input('type')));
        }

        if ($request->filled('vehicle')) {
            $vehicle = static::findVehicleRecord($request->input('vehicle'));
            if (!$vehicle) {
                return response()->apiError('Vehicle resource not found.', 404);
            }

            $query->where(function ($query) use ($vehicle) {
                $query->whereNull('subject_uuid')->orWhere(function ($query) use ($vehicle) {
                    $query->where('subject_type', Vehicle::class)->where('subject_uuid', $vehicle->uuid);
                });
            });
        }

        return InspectionFormResource::collection($query->limit(static::limit($request))->get());
    }

    /**
     * GET /v1/inspection-forms/{id} — one published form, items and settings included.
     *
     * A draft or archived form answers 404 rather than 403: to a driver a form
     * that cannot be filled in does not exist, and the distinction would only
     * tell them something about the console they cannot act on.
     */
    public function findForm(string $id)
    {
        $form = static::findPublishedForm($id);
        if (!$form) {
            return response()->apiError('Inspection form resource not found.', 404);
        }

        return new InspectionFormResource($form);
    }

    /**
     * POST /v1/inspections — file an inspection against a published form.
     *
     * The body is exactly what the public link accepts, plus the three things
     * the link already knew: which form, which vehicle, which driver. The app
     * queues a submit when it is offline and replays it later, so a replay
     * that carries the same `Idempotency-Key` header answers with the
     * submission the first attempt created rather than filing a second one.
     */
    public function submit(Request $request)
    {
        $validated = $request->validate(array_merge(InspectionSubmitter::rules(), [
            'inspection_form' => 'required|string',
            'driver'          => 'required|string',
            'vehicle'         => 'nullable|string',
            'started_at'      => 'nullable|date',
        ]));

        $driver = static::findDriverRecord($request->input('driver'));
        if (!$driver) {
            return response()->apiError('Driver resource not found.', 404);
        }

        $form = static::findPublishedForm($request->input('inspection_form'));
        if (!$form) {
            return response()->apiError('Inspection form resource not found.', 404);
        }

        $vehicle = null;
        if ($request->filled('vehicle')) {
            $vehicle = static::findVehicleRecord($request->input('vehicle'));
            if (!$vehicle) {
                return response()->apiError('Vehicle resource not found.', 404);
            }
        }

        $idempotencyKey = trim((string) $request->header('Idempotency-Key'));
        if ($idempotencyKey !== '') {
            $replayed = static::findSubmissionByIdempotencyKey($driver, $idempotencyKey);
            if ($replayed) {
                return new InspectionSubmissionResource($replayed->load(static::SUBMISSION_RELATIONS));
            }
        }

        $attributes = [
            // Without an explicit vehicle the inspection is of the truck the
            // driver is assigned to, which is what a pre-trip almost always is.
            'vehicle_uuid'      => $vehicle?->uuid ?? $driver->vehicle_uuid,
            'driver_uuid'       => $driver->uuid,
            'submitted_by_uuid' => $driver->user_uuid,
            'source'            => 'navigator',
            'meta'              => $idempotencyKey !== '' ? ['idempotency_key' => $idempotencyKey] : [],
        ];

        if ($request->filled('started_at')) {
            $attributes['started_at'] = Carbon::parse($request->input('started_at'));
        }

        $submission = InspectionSubmitter::submit($form, $validated, $attributes);

        return new InspectionSubmissionResource($submission->fresh(static::SUBMISSION_RELATIONS));
    }

    /**
     * GET /v1/inspections — submissions, newest first.
     */
    public function query(Request $request)
    {
        $query = static::submissions();

        if ($request->filled('driver')) {
            $driver = static::findDriverRecord($request->input('driver'));
            if (!$driver) {
                return response()->apiError('Driver resource not found.', 404);
            }

            $query->where('driver_uuid', $driver->uuid);
        }

        if ($request->filled('vehicle')) {
            $vehicle = static::findVehicleRecord($request->input('vehicle'));
            if (!$vehicle) {
                return response()->apiError('Vehicle resource not found.', 404);
            }

            $query->where('vehicle_uuid', $vehicle->uuid);
        }

        static::applySubmissionFilters($request, $query);

        return InspectionSubmissionResource::collection($query->limit(static::limit($request))->get());
    }

    /**
     * GET /v1/inspections/{id} — one submission with its item results and follow-up.
     */
    public function find(string $id)
    {
        $submission = static::findSubmission($id);
        if (!$submission) {
            return response()->apiError('Inspection resource not found.', 404);
        }

        return new InspectionSubmissionResource($submission->load(static::SUBMISSION_RELATIONS));
    }

    /**
     * GET /v1/vehicles/{id}/inspections — a vehicle's inspection history.
     *
     * The same rows `query` answers with `vehicle=`, addressed the way the app
     * holds them: it is on a vehicle's screen, and wants that vehicle's history.
     */
    public function forVehicle(Request $request, string $id)
    {
        $vehicle = static::findVehicleRecord($id);
        if (!$vehicle) {
            return response()->apiError('Vehicle resource not found.', 404);
        }

        $query = static::submissions()->where('vehicle_uuid', $vehicle->uuid);
        static::applySubmissionFilters($request, $query);

        return InspectionSubmissionResource::collection($query->limit(static::limit($request))->get());
    }

    /** The column filters shared by the two listings. Each accepts one value or a comma separated list. */
    protected static function applySubmissionFilters(Request $request, $query): void
    {
        foreach (['type', 'result', 'status'] as $column) {
            if ($request->filled($column)) {
                $query->whereIn($column, Utils::arrayFrom($request->input($column)));
            }
        }
    }

    /**
     * How many rows a listing answers with. Defaults to what fits on a screen;
     * a driver's whole history is thousands of rows nobody asked for.
     */
    protected static function limit(Request $request): int
    {
        $limit = (int) $request->input('limit', 30);

        return $limit > 0 ? $limit : 30;
    }

    /**
     * Every lookup is scoped to the company the API key belongs to. The
     * consumable API serves one company per credential, and a public id from
     * another company must be indistinguishable from one that does not exist.
     */
    protected static function publishedForms()
    {
        return InspectionForm::where('company_uuid', session('company'))
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->orderBy('published_at', 'desc');
    }

    protected static function submissions()
    {
        return InspectionSubmission::where('company_uuid', session('company'))
            ->with(['form', 'vehicle', 'driver'])
            ->orderBy('submitted_at', 'desc')
            ->orderBy('created_at', 'desc');
    }

    protected static function findPublishedForm(string $id): ?InspectionForm
    {
        return static::publishedForms()
            ->where(function ($query) use ($id) {
                $query->where('public_id', $id)->orWhere('uuid', $id);
            })
            ->first();
    }

    protected static function findSubmission(string $id): ?InspectionSubmission
    {
        return InspectionSubmission::where('company_uuid', session('company'))
            ->where(function ($query) use ($id) {
                $query->where('public_id', $id)->orWhere('uuid', $id);
            })
            ->first();
    }

    protected static function findSubmissionByIdempotencyKey(Driver $driver, string $key): ?InspectionSubmission
    {
        return InspectionSubmission::where('company_uuid', $driver->company_uuid)
            ->where('driver_uuid', $driver->uuid)
            ->where('meta->idempotency_key', $key)
            ->first();
    }

    protected static function findDriverRecord(string $id): ?Driver
    {
        return Driver::where('company_uuid', session('company'))
            ->where(function ($query) use ($id) {
                $query->where('public_id', $id)->orWhere('uuid', $id);
            })
            ->first();
    }

    protected static function findVehicleRecord(string $id): ?Vehicle
    {
        return Vehicle::where('company_uuid', session('company'))
            ->where(function ($query) use ($id) {
                $query->where('public_id', $id)->orWhere('uuid', $id);
            })
            ->first();
    }
}

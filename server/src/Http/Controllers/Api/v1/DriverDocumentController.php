<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Models\Driver;
use Fleetbase\FleetOps\Support\DriverDocuments;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * The documents an organization asks of a driver, read and sent from the Navigator app.
 *
 *   GET  /v1/drivers/{id}/documents  — each document asked for and where the driver's copy stands
 *   POST /v1/drivers/{id}/documents  — send (or send again) the file for one document
 *
 * A driver's own token reaches only that driver's documents. An organization's
 * API key reaches any of its drivers, as it does for the rest of the driver API.
 */
class DriverDocumentController extends Controller
{
    public function index(string $id)
    {
        $driver = $this->driver($id);
        if (!$driver) {
            return response()->apiError('Driver resource not found.', 404);
        }

        return response()->json($this->payload($driver));
    }

    public function store(string $id, Request $request)
    {
        $driver = $this->driver($id);
        if (!$driver) {
            return response()->apiError('Driver resource not found.', 404);
        }

        $document = $request->input('document');
        $data     = $request->input('data');
        if (!is_string($document) || trim($document) === '') {
            return response()->apiError('Say which document this is.', 422);
        }
        if (!is_string($data) || $data === '') {
            return response()->apiError('No file was sent.', 422);
        }

        try {
            DriverDocuments::submit($driver, $document, $data, $request->input('content_type'), $request->input('file_name'));
        } catch (\InvalidArgumentException $e) {
            return response()->apiError($e->getMessage(), 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->apiError('The document could not be stored. Try again.', 500);
        }

        return response()->json($this->payload($driver), 201);
    }

    protected function payload(Driver $driver): array
    {
        $documents = DriverDocuments::forDriver($driver);

        return ['documents' => $documents, 'summary' => DriverDocuments::summary($driver, $documents)];
    }

    /**
     * The driver, inside the caller's organization; and when the caller is a
     * driver signed in with their own token, only themselves.
     */
    protected function driver(string $id): ?Driver
    {
        try {
            $driver = Driver::findRecordOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return null;
        }

        if (session('company') && $driver->company_uuid !== session('company')) {
            return null;
        }
        if (session('is_sanctum_token') && $driver->user_uuid !== session('user')) {
            return null;
        }

        return $driver;
    }
}

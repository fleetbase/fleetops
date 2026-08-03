<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Waypoint;
use Fleetbase\Http\Controllers\Controller;
use Illuminate\Http\Request;

class LabelController extends Controller
{
    /**
     * Undocumented function.
     *
     * @return void
     */
    public function getLabel(string $publicId, Request $request)
    {
        $format  = $request->input('format', 'stream');
        $type    = $request->input('type', strtok($publicId, '_'));
        $subject = $this->findLabelSubject($type, $publicId);

        if (!$subject) {
            return $this->apiError('Unable to render label.');
        }

        switch ($format) {
            case 'pdf':
            case 'stream':
            default:
                return $subject->pdfLabelStream();

            case 'text':
                $text = $subject->pdfLabel()->output();

                return $this->makeResponse($text);

            case 'base64':
                $base64 = base64_encode($subject->pdfLabel()->output());

                return $this->jsonResponse(['data' => mb_convert_encoding($base64, 'UTF-8', 'UTF-8')]);
        }

        return $this->apiError('Unable to render label.');
    }

    protected function findLabelSubject(?string $type, string $publicId): mixed
    {
        switch ($type) {
            case 'order':
                return $this->findLabelSubjectFor(Order::class, $publicId);

            case 'waypoint':
                return $this->findLabelSubjectFor(Waypoint::class, $publicId);

            case 'entity':
                return $this->findLabelSubjectFor(Entity::class, $publicId);
        }

        return null;
    }

    /**
     * Resolves a label subject by public id or uuid, constrained to the current company.
     *
     * The identifier match is grouped so the company constraint applies to both arms —
     * without the closure it would read as `public_id = ? OR (uuid = ? AND company_uuid = ?)`
     * and leak labels across organizations.
     */
    protected function findLabelSubjectFor(string $model, string $publicId): mixed
    {
        $companyUuid = $this->sessionCompany();
        if (!$companyUuid) {
            return null;
        }

        return $model::where(function ($query) use ($publicId) {
            $query->where('public_id', $publicId)->orWhere('uuid', $publicId);
        })->where('company_uuid', $companyUuid)->withoutGlobalScopes()->first();
    }

    protected function sessionCompany(): ?string
    {
        return session('company');
    }

    protected function apiError(string $message)
    {
        return response()->apiError($message);
    }

    protected function makeResponse(string $text)
    {
        return response()->make($text);
    }

    protected function jsonResponse(array $payload)
    {
        return response()->json($payload);
    }
}

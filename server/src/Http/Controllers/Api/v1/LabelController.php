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
                return Order::where('public_id', $publicId)->orWhere('uuid', $publicId)->withoutGlobalScopes()->first();

            case 'waypoint':
                return Waypoint::where('public_id', $publicId)->orWhere('uuid', $publicId)->withoutGlobalScopes()->first();

            case 'entity':
                return Entity::where('public_id', $publicId)->orWhere('uuid', $publicId)->withoutGlobalScopes()->first();
        }

        return null;
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

<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\RecurringOrderSchedule;
use Fleetbase\FleetOps\Models\RecurringOrderScheduleOccurrence;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\FleetOps\Models\ServiceQuoteItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecurringOrderMaterializationService
{
    public const DEFAULT_HORIZON_DAYS = 60;

    public function materializeAll(?int $horizonDays = null): array
    {
        $horizonDays = $horizonDays ?: (int) config('fleetops.recurring_orders.horizon_days', static::DEFAULT_HORIZON_DAYS);
        $horizon = now()->addDays($horizonDays);
        $stats = ['materialized' => 0, 'skipped' => 0, 'errors' => 0];

        RecurringOrderSchedule::needsMaterialization($horizon)
            ->chunk(100, function ($schedules) use ($horizon, &$stats) {
                foreach ($schedules as $schedule) {
                    try {
                        $created = $this->materializeSchedule($schedule, $horizon);
                        if ($created > 0) {
                            $stats['materialized']++;
                        } else {
                            $stats['skipped']++;
                        }
                    } catch (\Throwable $exception) {
                        $stats['errors']++;
                        \Log::error('[RecurringOrderMaterializationService] Failed to materialize schedule', [
                            'schedule_uuid' => $schedule->uuid,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
            });

        return $stats;
    }

    public function materializeSchedule(RecurringOrderSchedule $schedule, ?Carbon $horizon = null): int
    {
        if ($schedule->status !== 'active') {
            return 0;
        }

        $timezone = $schedule->timezone ?: 'UTC';
        $from = ($schedule->last_materialized_at?->copy()->setTimezone($timezone) ?? now($timezone))->startOfDay();
        $horizon = ($horizon ?: now()->addDays((int) config('fleetops.recurring_orders.horizon_days', static::DEFAULT_HORIZON_DAYS)))->copy();
        $occurrences = $schedule->previewOccurrences($from, $horizon->copy()->setTimezone($timezone), 500);

        if ($occurrences->isEmpty()) {
            $schedule->update([
                'last_materialized_at' => now(),
                'materialization_horizon' => $horizon,
            ]);

            return 0;
        }

        $existingStates = $schedule->occurrences()
            ->whereBetween('occurrence_at', [$from->copy()->setTimezone('UTC'), $horizon->copy()->setTimezone('UTC')])
            ->get()
            ->keyBy(fn (RecurringOrderScheduleOccurrence $occurrence) => $occurrence->occurrence_at->toISOString());

        $created = 0;

        foreach ($occurrences as $occurrenceLocal) {
            $occurrenceUtc = $occurrenceLocal->copy()->setTimezone('UTC');
            $stateKey = $occurrenceUtc->toISOString();
            $state = $existingStates->get($stateKey);

            if ($state && in_array($state->status, ['generated', 'skipped', 'canceled'], true)) {
                continue;
            }

            $order = $this->generateOrderForOccurrence($schedule, $occurrenceUtc);

            RecurringOrderScheduleOccurrence::updateOrCreate(
                [
                    'recurring_order_schedule_uuid' => $schedule->uuid,
                    'occurrence_at' => $occurrenceUtc,
                ],
                [
                    'company_uuid' => $schedule->company_uuid,
                    'order_uuid' => $order->uuid,
                    'status' => 'generated',
                ]
            );

            $created++;
        }

        $schedule->update([
            'last_materialized_at' => now(),
            'materialization_horizon' => $horizon,
        ]);

        return $created;
    }

    public function generateOrderForOccurrence(RecurringOrderSchedule $schedule, Carbon $occurrenceUtc): Order
    {
        return DB::transaction(function () use ($schedule, $occurrenceUtc) {
            $orderMeta = (array) ($schedule->template_order_meta ?? []);
            $orderType = data_get($orderMeta, 'type') ?: data_get($schedule->orderConfig, 'key') ?: 'transport';

            $order = Order::create([
                'company_uuid' => $schedule->company_uuid,
                'internal_id' => data_get($orderMeta, 'internal_id'),
                'customer_uuid' => $schedule->customer_uuid,
                'customer_type' => $schedule->customer_type,
                'facilitator_uuid' => $schedule->facilitator_uuid,
                'facilitator_type' => $schedule->facilitator_type,
                'order_config_uuid' => $schedule->order_config_uuid,
                'driver_assigned_uuid' => $schedule->driver_assigned_uuid,
                'vehicle_assigned_uuid' => $schedule->vehicle_assigned_uuid,
                'scheduled_at' => $occurrenceUtc,
                'pod_method' => data_get($orderMeta, 'pod_method'),
                'pod_required' => (bool) data_get($orderMeta, 'pod_required', false),
                'adhoc' => (bool) data_get($orderMeta, 'adhoc', false),
                'adhoc_distance' => data_get($orderMeta, 'adhoc_distance'),
                'notes' => data_get($orderMeta, 'notes'),
                'type' => $orderType,
                'status' => 'created',
                'meta' => array_merge(
                    (array) data_get($orderMeta, 'meta', []),
                    [
                        'recurring_order_schedule_uuid' => $schedule->uuid,
                        'recurring_order_schedule_public_id' => $schedule->public_id,
                        'recurring_occurrence_at' => $occurrenceUtc->toISOString(),
                        'is_recurring_generated' => true,
                    ]
                ),
                'time_window_start' => data_get($orderMeta, 'time_window_start'),
                'time_window_end' => data_get($orderMeta, 'time_window_end'),
                'required_skills' => data_get($orderMeta, 'required_skills'),
                'orchestrator_priority' => data_get($orderMeta, 'orchestrator_priority', 50),
                'recurring_order_schedule_uuid' => $schedule->uuid,
                'recurring_occurrence_at' => $occurrenceUtc,
                'created_by_uuid' => $schedule->created_by_uuid,
                'updated_by_uuid' => $schedule->updated_by_uuid,
            ]);

            $payload = $this->buildPayloadFromBlueprint($schedule, $orderType);
            $payload->save();
            $payload->setWaypoints((array) ($schedule->template_payload['waypoints'] ?? []));
            $payload->setEntities((array) ($schedule->template_entities ?? []));
            $payload->setCurrentWaypoint($payload->getPickupOrFirstWaypoint(), false);
            $payload->save();

            $order->setPayload($payload);
            $order->setPreliminaryDistanceAndTime();

            if ($schedule->service_rate_uuid) {
                $this->attachFreshQuoteFromServiceRate($order, $schedule);
            }

            return $order->fresh(['payload', 'trackingNumber']);
        });
    }

    public function skipOccurrence(RecurringOrderSchedule $schedule, Carbon $occurrenceAt, ?string $reason = null, bool $cancelGeneratedOrder = true): RecurringOrderScheduleOccurrence
    {
        $occurrenceUtc = $occurrenceAt->copy()->setTimezone('UTC');
        $existing = $schedule->occurrences()->where('occurrence_at', $occurrenceUtc)->first();

        if ($existing?->order && $cancelGeneratedOrder && $existing->order->status !== 'canceled') {
            $existing->order->cancel();
            $existing->order->save();
        }

        return RecurringOrderScheduleOccurrence::updateOrCreate(
            [
                'recurring_order_schedule_uuid' => $schedule->uuid,
                'occurrence_at' => $occurrenceUtc,
            ],
            [
                'company_uuid' => $schedule->company_uuid,
                'order_uuid' => $existing?->order_uuid,
                'status' => 'canceled',
                'reason' => $reason,
            ]
        );
    }

    protected function buildPayloadFromBlueprint(RecurringOrderSchedule $schedule, string $orderType): Payload
    {
        $blueprint = (array) ($schedule->template_payload ?? []);
        $payload = new Payload([
            'company_uuid' => $schedule->company_uuid,
            'type' => data_get($blueprint, 'type', $orderType),
            'meta' => data_get($blueprint, 'meta', []),
            'payment_method' => data_get($blueprint, 'payment_method'),
            'cod_amount' => data_get($blueprint, 'cod_amount'),
            'cod_currency' => data_get($blueprint, 'cod_currency'),
            'cod_payment_method' => data_get($blueprint, 'cod_payment_method'),
        ]);

        if ($pickup = data_get($blueprint, 'pickup')) {
            $payload->setPickup($this->normalizePlaceAttributes($pickup, $schedule->company_uuid), [
                'callback' => function ($pickupPlace, Payload $targetPayload) {
                    $targetPayload->setCurrentWaypoint($pickupPlace, false);
                },
            ]);
        }

        if ($dropoff = data_get($blueprint, 'dropoff')) {
            $payload->setDropoff($this->normalizePlaceAttributes($dropoff, $schedule->company_uuid));
        }

        if ($return = data_get($blueprint, 'return')) {
            $payload->setReturn($this->normalizePlaceAttributes($return, $schedule->company_uuid));
        }

        return $payload;
    }

    protected function normalizePlaceAttributes(array $place, string $companyUuid): array
    {
        unset($place['id'], $place['public_id'], $place['created_at'], $place['updated_at'], $place['deleted_at']);
        $place['company_uuid'] = $place['company_uuid'] ?? $companyUuid;

        return $place;
    }

    protected function attachFreshQuoteFromServiceRate(Order $order, RecurringOrderSchedule $schedule): void
    {
        $serviceRate = $schedule->serviceRate;
        $payload = $order->payload;
        $waypoints = collect([$payload->pickup, ...$payload->waypoints()->get()->all(), $payload->dropoff])->filter();
        $entities = $payload->entities()->get();

        if (!$serviceRate || $waypoints->count() < 2) {
            return;
        }

        try {
            [$amount, $lines] = $serviceRate->quoteFromPreliminaryData($entities, $waypoints, $order->distance ?? 0, $order->time ?? 0, false);

            $quote = ServiceQuote::create([
                'request_id' => ServiceQuote::generatePublicId('request'),
                'company_uuid' => $serviceRate->company_uuid,
                'service_rate_uuid' => $serviceRate->uuid,
                'payload_uuid' => $payload->uuid,
                'amount' => $amount,
                'currency' => $serviceRate->currency,
            ]);

            foreach ($lines as $line) {
                ServiceQuoteItem::create([
                    'service_quote_uuid' => $quote->uuid,
                    'amount' => $line['amount'],
                    'currency' => $line['currency'],
                    'details' => $line['details'],
                    'code' => $line['code'],
                ]);
            }

            $quote->updateMeta('preliminary_data', [
                'pickup' => $payload->pickup?->toArray(),
                'dropoff' => $payload->dropoff?->toArray(),
                'return' => $payload->return?->toArray(),
                'waypoints' => $payload->waypointMarkers()->with('place')->get()->map(fn ($waypoint) => array_merge($waypoint->toArray(), ['place' => $waypoint->place?->toArray()]))->toArray(),
                'entities' => $entities->map(fn (Entity $entity) => $entity->toArray())->toArray(),
            ]);

            $order->purchaseServiceQuote($quote);
        } catch (\Throwable $exception) {
            $meta = (array) ($order->meta ?? []);
            $meta['recurring_billing_status'] = 'quote_failed';
            $meta['recurring_billing_error'] = $exception->getMessage();
            $meta['recurring_service_rate_uuid'] = $schedule->service_rate_uuid;
            $order->updateQuietly(['meta' => $meta]);
        }
    }
}

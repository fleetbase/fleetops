<?php

namespace Illuminate\Foundation\Auth\Access {
    if (!trait_exists(AuthorizesRequests::class)) {
        trait AuthorizesRequests
        {
        }
    }
}

namespace Illuminate\Foundation\Bus {
    if (!trait_exists(DispatchesJobs::class)) {
        trait DispatchesJobs
        {
        }
    }
}

namespace Illuminate\Foundation\Validation {
    if (!trait_exists(ValidatesRequests::class)) {
        trait ValidatesRequests
        {
        }
    }
}

namespace Illuminate\Foundation\Auth {
    if (!class_exists(User::class)) {
        class User extends \Illuminate\Database\Eloquent\Model
        {
        }
    }
}

namespace Illuminate\Routing {
    if (!class_exists(Controller::class)) {
        class Controller
        {
        }
    }
}

namespace {
    if (!function_exists('Fleetbase\Traits\config')) {
        eval('namespace Fleetbase\Traits; function config($key = null, $default = null) { return $key === "api.cache.enabled" ? false : $default; }');
    }

    if (!function_exists('Fleetbase\Models\config')) {
        eval('namespace Fleetbase\Models; function config($key = null, $default = null) { return $default; }');
    }

    if (!function_exists('Fleetbase\Models\env')) {
        eval('namespace Fleetbase\Models; function env($key = null, $default = null) { return $default; }');
    }

    if (!function_exists('Spatie\Activitylog\Models\config')) {
        eval('namespace Spatie\Activitylog\Models; function config($key = null, $default = null) { return $default; }');
    }

    use Fleetbase\FleetOps\Http\Controllers\Internal\v1\IssueController;
    use Fleetbase\FleetOps\Models\Issue;
    use Fleetbase\FleetOps\Models\Order;
    use Fleetbase\Models\Activity;
    use Fleetbase\Models\Comment;
    use Fleetbase\Models\File;
    use Fleetbase\Models\User;
    use Illuminate\Http\Request;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Collection;

    class FleetOpsInternalIssueControllerSessionState
    {
        public static ?string $company = null;
    }

    if (!function_exists('Fleetbase\FleetOps\Http\Controllers\Internal\v1\session')) {
        eval('namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1; function session($key = null, $default = null) { return $key === "company" ? \FleetOpsInternalIssueControllerSessionState::$company : $default; }');
    }

    class FleetOpsInternalIssueControllerProbe extends IssueController
    {
        public ?User $assignedUser = null;
        public Collection $uploadFiles;
        public ?Order $metaOrder     = null;
        public ?Issue $timelineIssue = null;
        public Collection $activities;
        public Collection $comments;
        public Collection $files;
        public Collection $fileActivities;

        public function __construct()
        {
            $this->uploadFiles    = collect();
            $this->activities     = collect();
            $this->comments       = collect();
            $this->files          = collect();
            $this->fileActivities = collect();
        }

        protected function findAssignedUser(string $uuid): ?User
        {
            $this->assignedUser?->setAttribute('lookup_uuid', $uuid);

            return $this->assignedUser;
        }

        protected function filesForUploads(array $uploads): Collection
        {
            $this->uploadFiles->each(fn (File $file) => $file->setAttribute('lookup_uploads', $uploads));

            return $this->uploadFiles;
        }

        protected function findOrderForIssueMeta(string $orderUuid, string $companyUuid): ?Order
        {
            $this->metaOrder?->setAttribute('lookup_order_uuid', $orderUuid);
            $this->metaOrder?->setAttribute('lookup_company_uuid', $companyUuid);

            return $this->metaOrder;
        }

        protected function findIssueForTimeline(string $id): ?Issue
        {
            $this->timelineIssue?->setAttribute('lookup_id', $id);

            return $this->timelineIssue;
        }

        protected function activitiesForIssue(Issue $issue): Collection
        {
            return $this->activities;
        }

        protected function commentsForIssue(Issue $issue): Collection
        {
            return $this->comments;
        }

        protected function filesForIssue(Issue $issue): Collection
        {
            return $this->files;
        }

        protected function fileActivitiesForIssue(Issue $issue): Collection
        {
            return $this->fileActivities;
        }

        protected function jsonResponse(array $payload)
        {
            return ['json' => $payload];
        }

        protected function errorResponse(string $message, int $status = 400)
        {
            return ['error' => $message, 'status' => $status];
        }
    }

    class FleetOpsInternalIssueFake extends Issue
    {
        public array $syncedCustomFields = [];
        public int $quietSaves           = 0;

        public function syncCustomFieldValues(array $payload, array $options = []): array
        {
            $this->syncedCustomFields[] = [$payload, $options];

            return $payload;
        }

        public function saveQuietly(array $options = [])
        {
            $this->quietSaves++;

            return true;
        }
    }

    class FleetOpsInternalIssueFileFake extends File
    {
        public mixed $keyedSubject = null;

        public function getUrlAttribute(): ?string
        {
            return $this->attributes['url'] ?? null;
        }

        public function setKey($model, $type = null): File
        {
            $this->keyedSubject = $model;

            return $this;
        }
    }

    class FleetOpsInternalIssueRequestFake extends Request
    {
        public function array($key = null, $default = []): array
        {
            $value = data_get($this->all(), $key, $default);

            return is_array($value) ? $value : $default;
        }
    }

    function fleetopsInternalIssue(array $attributes = []): FleetOpsInternalIssueFake
    {
        $issue = new FleetOpsInternalIssueFake();
        $issue->setRawAttributes(array_merge([
            'uuid'          => 'issue-uuid',
            'public_id'     => 'issue_public',
            'company_uuid'  => 'company-uuid',
            'reporter_name' => 'Reporter Name',
            'created_at'    => Carbon::parse('2026-07-27 08:00:00'),
        ], $attributes), true);
        $issue->setRelation('reporter', (object) [
            'avatar_url' => 'https://cdn.test/reporter.png',
        ]);

        return $issue;
    }

    function fleetopsInternalIssueActivity(array $attributes = []): Activity
    {
        $activity   = new Activity();
        $attributes = array_merge([
            'uuid'        => 'activity-uuid',
            'event'       => 'updated',
            'description' => 'Issue was updated.',
            'properties'  => [],
            'created_at'  => Carbon::parse('2026-07-27 09:00:00'),
        ], $attributes);

        if (is_array($attributes['properties'])) {
            $attributes['properties'] = json_encode($attributes['properties']);
        }

        $activity->setRawAttributes($attributes, true);
        $activity->setRelation('causer', (object) [
            'name'       => 'Ops Manager',
            'avatar_url' => 'https://cdn.test/manager.png',
        ]);

        return $activity;
    }

    test('internal issue controller after save clears customer assignees syncs custom fields uploads and meta orders', function () {
        $controller = new FleetOpsInternalIssueControllerProbe();
        $assignee   = new User();
        $assignee->setRawAttributes(['uuid' => 'customer-user-uuid', 'type' => 'customer'], true);
        $file = new FleetOpsInternalIssueFileFake();
        $file->setRawAttributes(['uuid' => 'file-uuid'], true);
        $order = new Order();
        $order->setRawAttributes(['uuid' => 'order-uuid'], true);

        $controller->assignedUser = $assignee;
        $controller->uploadFiles  = collect([$file]);
        $controller->metaOrder    = $order;

        $issue = fleetopsInternalIssue([
            'assigned_to_uuid' => 'customer-user-uuid',
            'company_uuid'     => 'company-uuid',
            'meta'             => ['order_uuid' => 'order-uuid'],
        ]);

        $request = FleetOpsInternalIssueRequestFake::create('/int/v1/issues', 'POST', [
            'issue' => [
                'custom_field_values' => ['temperature' => 'cold'],
                'files'               => ['file-uuid'],
            ],
        ]);

        $controller->afterSave($request, $issue);

        expect($assignee->lookup_uuid)->toBe('customer-user-uuid')
            ->and($issue->assigned_to_uuid)->toBeNull()
            ->and($issue->order_uuid)->toBe('order-uuid')
            ->and($issue->quietSaves)->toBe(2)
            ->and($issue->syncedCustomFields)->toBe([[['temperature' => 'cold'], []]])
            ->and($file->keyedSubject)->toBe($issue)
            ->and($file->lookup_uploads)->toBe(['file-uuid'])
            ->and($order->lookup_order_uuid)->toBe('order-uuid')
            ->and($order->lookup_company_uuid)->toBe('company-uuid');
    });

    test('internal issue controller timeline aggregates issue comments files and activities', function () {
        FleetOpsInternalIssueControllerSessionState::$company = 'company-uuid';

        $controller                = new FleetOpsInternalIssueControllerProbe();
        $controller->timelineIssue = fleetopsInternalIssue();

        $fieldActivity = fleetopsInternalIssueActivity([
            'uuid'       => 'field-activity-uuid',
            'properties' => [
                'old'        => ['status' => 'open'],
                'attributes' => ['status' => 'resolved'],
            ],
            'created_at' => Carbon::parse('2026-07-27 10:00:00'),
        ]);
        $genericActivity = fleetopsInternalIssueActivity([
            'uuid'        => 'generic-activity-uuid',
            'description' => 'Priority note changed.',
            'created_at'  => Carbon::parse('2026-07-27 09:30:00'),
        ]);
        $createdActivity = fleetopsInternalIssueActivity([
            'uuid'       => 'created-activity-uuid',
            'event'      => 'created',
            'created_at' => Carbon::parse('2026-07-27 11:00:00'),
        ]);

        $comment = new Comment();
        $comment->setRawAttributes([
            'uuid'       => 'comment-uuid',
            'public_id'  => 'comment_public',
            'content'    => '<p>Driver reported a damaged package near the hub.</p>',
            'created_at' => Carbon::parse('2026-07-27 12:00:00'),
        ], true);
        $comment->setRelation('author', (object) ['name' => 'Dispatcher', 'avatar_url' => 'https://cdn.test/dispatcher.png']);

        $file = new FleetOpsInternalIssueFileFake();
        $file->setRawAttributes([
            'uuid'              => 'file-uuid',
            'public_id'         => 'file_public',
            'original_filename' => 'damage-photo.jpg',
            'url'               => 'https://cdn.test/damage-photo.jpg',
            'created_at'        => Carbon::parse('2026-07-27 13:00:00'),
        ], true);
        $file->setRelation('uploader', (object) ['name' => 'Warehouse', 'avatar_url' => 'https://cdn.test/warehouse.png']);

        $removedFileActivity = fleetopsInternalIssueActivity([
            'uuid'       => 'removed-file-activity-uuid',
            'event'      => 'deleted',
            'properties' => ['old' => ['original_filename' => 'old-damage.pdf']],
            'created_at' => Carbon::parse('2026-07-27 14:00:00'),
        ]);

        $controller->activities     = collect([$fieldActivity, $genericActivity, $createdActivity]);
        $controller->comments       = collect([$comment]);
        $controller->files          = collect([$file]);
        $controller->fileActivities = collect([$removedFileActivity]);

        $response = $controller->timeline('issue_public');
        $events   = $response['json']['events'];

        expect($controller->timelineIssue->lookup_id)->toBe('issue_public')
            ->and($events)->toHaveCount(6)
            ->and($events->pluck('type')->all())->toBe([
                'document_removed',
                'document_uploaded',
                'correspondence_added',
                'issue_closed',
                'issue_updated',
                'issue_opened',
            ])
            ->and($events[0])->toMatchArray([
                'label'       => 'Document removed',
                'description' => 'old-damage.pdf',
                'meta'        => ['file_name' => 'old-damage.pdf'],
            ])
            ->and($events[1])->toMatchArray([
                'label'       => 'Document uploaded',
                'description' => 'damage-photo.jpg',
                'actor_name'  => 'Warehouse',
                'meta'        => [
                    'file_id'   => 'file_public',
                    'file_name' => 'damage-photo.jpg',
                    'file_url'  => 'https://cdn.test/damage-photo.jpg',
                ],
            ])
            ->and($events[2])->toMatchArray([
                'label'       => 'Correspondence added',
                'description' => 'Driver reported a damaged package near the hub.',
                'actor_name'  => 'Dispatcher',
                'meta'        => ['comment_id' => 'comment_public'],
            ])
            ->and($events[3])->toMatchArray([
                'label'       => 'Issue closed',
                'description' => 'Status changed from Open to Resolved.',
            ])
            ->and($events[4])->toMatchArray([
                'label'       => 'Issue updated',
                'description' => 'Priority note changed.',
            ]);
    });

    test('internal issue controller timeline rejects missing or cross-company issues', function () {
        FleetOpsInternalIssueControllerSessionState::$company = 'company-uuid';

        $controller = new FleetOpsInternalIssueControllerProbe();

        expect($controller->timeline('missing'))->toBe([
            'error'  => 'Issue not found for this organization.',
            'status' => 404,
        ]);

        $controller->timelineIssue = fleetopsInternalIssue(['company_uuid' => 'other-company']);

        expect($controller->timeline('issue_public'))->toBe([
            'error'  => 'Issue not found for this organization.',
            'status' => 404,
        ]);
    });
}

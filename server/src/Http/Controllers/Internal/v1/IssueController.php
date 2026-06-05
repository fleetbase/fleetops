<?php

namespace Fleetbase\FleetOps\Http\Controllers\Internal\v1;

use Fleetbase\FleetOps\Exports\IssueExport;
use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Imports\IssueImport;
use Fleetbase\FleetOps\Models\Issue;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Http\Requests\ExportRequest;
use Fleetbase\Http\Requests\ImportRequest;
use Fleetbase\Models\Activity;
use Fleetbase\Models\Comment;
use Fleetbase\Models\File;
use Fleetbase\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class IssueController extends FleetOpsController
{
    /**
     * The resource to query.
     *
     * @var string
     */
    public $resource = 'issue';

    /**
     * Handle post save transactions.
     */
    public function afterSave(Request $request, Issue $issue)
    {
        if ($issue->assigned_to_uuid) {
            $assignee = User::where('uuid', $issue->assigned_to_uuid)->first();

            if ($assignee && $assignee->type === 'customer') {
                $issue->assigned_to_uuid = null;
                $issue->saveQuietly();
            }
        }

        $customFieldValues = $request->array('issue.custom_field_values');
        if ($customFieldValues) {
            $issue->syncCustomFieldValues($customFieldValues);
        }

        $uploads = $request->array('issue.files');
        if ($uploads) {
            File::whereIn('uuid', $uploads)->get()->each(function (File $file) use ($issue) {
                $file->setKey($issue);
            });
        }

        if (!$issue->order_uuid && data_get($issue, 'meta.order_uuid')) {
            $order = Order::where('uuid', data_get($issue, 'meta.order_uuid'))->where('company_uuid', $issue->company_uuid)->first();
            if ($order) {
                $issue->order_uuid = $order->uuid;
                $issue->saveQuietly();
            }
        }
    }

    /**
     * Return curated activity timeline entries for an issue.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function timeline($id)
    {
        $issue = Issue::findById($id, ['reporter', 'assignee']);

        if (!$issue || $issue->company_uuid !== session('company')) {
            return response()->error('Issue not found for this organization.', 404);
        }

        $events = collect([$this->makeIssueOpenedEvent($issue)])
            ->merge($this->issueActivityEvents($issue))
            ->merge($this->commentEvents($issue))
            ->merge($this->fileEvents($issue))
            ->sortByDesc('created_at')
            ->values();

        return response()->json(['events' => $events]);
    }

    protected function issueActivityEvents(Issue $issue): Collection
    {
        return Activity::with(['causer'])
            ->where('subject_type', Issue::class)
            ->where('subject_id', $issue->uuid)
            ->latest()
            ->get()
            ->flatMap(function (Activity $activity) use ($issue) {
                if ($activity->event === 'created') {
                    return [];
                }

                $events = [];
                foreach (['status', 'priority', 'assigned_to_uuid', 'vehicle_uuid', 'driver_uuid', 'order_uuid', 'type', 'category', 'tags', 'resolved_at'] as $field) {
                    $from = data_get($activity->properties, "old.{$field}");
                    $to   = data_get($activity->properties, "attributes.{$field}");

                    if ($from === $to || $to === null) {
                        continue;
                    }

                    $events[] = $this->makeFieldChangedEvent($activity, $issue, $field, $from, $to);
                }

                return $events ?: [$this->makeGenericActivityEvent($activity, $issue)];
            });
    }

    protected function commentEvents(Issue $issue): Collection
    {
        return Comment::with(['author'])
            ->where('subject_type', Issue::class)
            ->where('subject_uuid', $issue->uuid)
            ->latest()
            ->get()
            ->map(function (Comment $comment) {
                return [
                    'id'               => $comment->uuid,
                    'type'             => 'correspondence_added',
                    'label'            => 'Correspondence added',
                    'description'      => Str::limit(strip_tags($comment->content ?? ''), 120),
                    'actor_name'       => data_get($comment, 'author.name', 'Someone'),
                    'actor_avatar_url' => data_get($comment, 'author.avatar_url'),
                    'created_at'       => $comment->created_at,
                    'icon'             => 'comment',
                    'tone'             => 'blue',
                    'meta'             => [
                        'comment_id' => $comment->public_id ?? $comment->uuid,
                    ],
                ];
            });
    }

    protected function fileEvents(Issue $issue): Collection
    {
        $currentFiles = File::with(['uploader'])
            ->where('subject_type', Issue::class)
            ->where('subject_uuid', $issue->uuid)
            ->latest()
            ->get()
            ->map(function (File $file) {
                return [
                    'id'               => $file->uuid,
                    'type'             => 'document_uploaded',
                    'label'            => 'Document uploaded',
                    'description'      => $file->original_filename,
                    'actor_name'       => data_get($file, 'uploader.name', 'Someone'),
                    'actor_avatar_url' => data_get($file, 'uploader.avatar_url'),
                    'created_at'       => $file->created_at,
                    'icon'             => 'file-arrow-up',
                    'tone'             => 'purple',
                    'meta'             => [
                        'file_id'   => $file->public_id ?? $file->uuid,
                        'file_name' => $file->original_filename,
                        'file_url'  => $file->url,
                    ],
                ];
            });

        $fileActivities = Activity::with(['causer'])
            ->where('subject_type', File::class)
            ->where(function ($query) use ($issue) {
                $query->where('properties->attributes->subject_uuid', $issue->uuid)
                    ->orWhere('properties->old->subject_uuid', $issue->uuid);
            })
            ->latest()
            ->get()
            ->filter(fn (Activity $activity) => $activity->event === 'deleted')
            ->map(function (Activity $activity) {
                $fileName = data_get($activity->properties, 'old.original_filename')
                    ?? data_get($activity->properties, 'attributes.original_filename')
                    ?? 'Document';

                return [
                    'id'               => $activity->uuid ?? $activity->id,
                    'type'             => 'document_removed',
                    'label'            => 'Document removed',
                    'description'      => $fileName,
                    'actor_name'       => data_get($activity, 'causer.name', 'Someone'),
                    'actor_avatar_url' => data_get($activity, 'causer.avatar_url'),
                    'created_at'       => $activity->created_at,
                    'icon'             => 'file-circle-xmark',
                    'tone'             => 'red',
                    'meta'             => [
                        'file_name' => $fileName,
                    ],
                ];
            });

        return $currentFiles->merge($fileActivities);
    }

    protected function makeIssueOpenedEvent(Issue $issue): array
    {
        return [
            'id'               => $issue->uuid . '-opened',
            'type'             => 'issue_opened',
            'label'            => 'Issue opened',
            'description'      => 'Reported by ' . ($issue->reporter_name ?: 'Unknown reporter'),
            'actor_name'       => $issue->reporter_name ?: 'Unknown reporter',
            'actor_avatar_url' => data_get($issue, 'reporter.avatar_url'),
            'created_at'       => $issue->created_at,
            'icon'             => 'circle-plus',
            'tone'             => 'green',
            'meta'             => [
                'issue_id' => $issue->public_id,
            ],
        ];
    }

    protected function makeFieldChangedEvent(Activity $activity, Issue $issue, string $field, $from, $to): array
    {
        $eventMap = [
            'status'           => ['status_changed', 'Status changed', 'arrows-rotate', 'blue'],
            'priority'         => ['priority_changed', 'Priority changed', 'flag', 'orange'],
            'assigned_to_uuid' => ['assignee_changed', 'Assignee changed', 'user-check', 'indigo'],
            'vehicle_uuid'     => ['vehicle_changed', 'Vehicle linked', 'truck', 'slate'],
            'driver_uuid'      => ['driver_changed', 'Driver linked', 'id-card', 'slate'],
            'order_uuid'       => ['order_changed', 'Order linked', 'box', 'slate'],
            'resolved_at'      => [$to ? 'issue_closed' : 'issue_reopened', $to ? 'Issue closed' : 'Issue re-opened', $to ? 'circle-check' : 'rotate-left', $to ? 'green' : 'orange'],
        ];

        [$type, $label, $icon, $tone] = $eventMap[$field] ?? ['issue_updated', Str::headline(str_replace('_uuid', '', $field)) . ' changed', 'pen', 'slate'];

        if ($field === 'status' && in_array($to, ['closed', 'resolved', 'completed'])) {
            [$type, $label, $icon, $tone] = ['issue_closed', 'Issue closed', 'circle-check', 'green'];
        }

        if ($field === 'status' && $to === 're_opened') {
            [$type, $label, $icon, $tone] = ['issue_reopened', 'Issue re-opened', 'rotate-left', 'orange'];
        }

        return [
            'id'               => ($activity->uuid ?? $activity->id) . '-' . $field,
            'type'             => $type,
            'label'            => $label,
            'description'      => $this->formatChangeDescription($field, $from, $to),
            'actor_name'       => data_get($activity, 'causer.name', 'Someone'),
            'actor_avatar_url' => data_get($activity, 'causer.avatar_url'),
            'created_at'       => $activity->created_at,
            'icon'             => $icon,
            'tone'             => $tone,
            'meta'             => [
                'field'    => $field,
                'from'     => $from,
                'to'       => $to,
                'issue_id' => $issue->public_id,
            ],
        ];
    }

    protected function makeGenericActivityEvent(Activity $activity, Issue $issue): array
    {
        return [
            'id'               => $activity->uuid ?? $activity->id,
            'type'             => 'issue_updated',
            'label'            => 'Issue updated',
            'description'      => $activity->description,
            'actor_name'       => data_get($activity, 'causer.name', 'Someone'),
            'actor_avatar_url' => data_get($activity, 'causer.avatar_url'),
            'created_at'       => $activity->created_at,
            'icon'             => 'pen',
            'tone'             => 'slate',
            'meta'             => [
                'issue_id' => $issue->public_id,
            ],
        ];
    }

    protected function formatChangeDescription(string $field, $from, $to): string
    {
        $fieldLabel = Str::headline(str_replace('_uuid', '', $field));
        $fromLabel  = blank($from) ? 'none' : Str::headline((string) $from);
        $toLabel    = blank($to) ? 'none' : Str::headline((string) $to);

        return "{$fieldLabel} changed from {$fromLabel} to {$toLabel}.";
    }

    /**
     * Export the issue to excel or csv.
     *
     * @return \Illuminate\Http\Response
     */
    public function export(ExportRequest $request)
    {
        $format       = $request->input('format', 'xlsx');
        $selections   = $request->array('selections');
        $fileName     = trim(Str::slug('issue-' . date('Y-m-d-H:i')) . '.' . $format);

        return Excel::download(new IssueExport($selections), $fileName);
    }

    /**
     * Process import files (excel,csv) into Fleetbase order data.
     *
     * @return \Illuminate\Http\Response
     */
    public function import(ImportRequest $request)
    {
        $disk           = $request->input('disk', config('filesystems.default'));
        $files          = $request->resolveFilesFromIds();
        $importedCount  = 0;

        foreach ($files as $file) {
            try {
                $import = new IssueImport();
                Excel::import($import, $file->path, $disk);
                $importedCount += $import->imported;
            } catch (\Throwable $e) {
                return response()->error('Invalid file, unable to proccess.');
            }
        }

        return response()->json(['status' => 'ok', 'message' => 'Import completed', 'imported' => $importedCount]);
    }
}

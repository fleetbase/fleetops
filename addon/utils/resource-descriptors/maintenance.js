import { get } from '@ember/object';
import { first, present, relation, icon, fact, relatedFact, money, join, panelOpener, polymorphicType } from './helpers';

/**
 * Issues, maintenance, schedules, work orders and inspections.
 */
export default function buildMaintenanceDescriptors(owner) {
    return [
        {
            key: 'issue',
            labelKey: 'resource.issue',
            icon: 'triangle-exclamation',
            modelNames: ['issue'],
            polymorphicTypes: ['fleet-ops:issue', 'Fleetbase\\FleetOps\\Models\\Issue'],
            permission: 'fleet-ops view issue',
            statusTones: {
                low: 'text-gray-400',
                medium: 'text-yellow-500',
                high: 'text-red-500',
                critical: 'text-red-500',
                scheduled_maintenance: 'text-yellow-500',
                resolved: 'text-green-500',
                completed: 'text-green-500',
                pending: 'text-yellow-500',
                in_progress: 'text-yellow-500',
            },
            title: (issue) => first(issue, 'title', 'issue_id', 'public_id'),
            identifier: (issue) => first(issue, 'issue_id', 'public_id'),
            image: () => icon('triangle-exclamation'),
            status: (issue) => first(issue, 'priority', 'status'),
            selectDetails: (issue) => [first(issue, 'type', 'category'), first(issue, 'status')],
            facts: (issue) => [
                fact('type', join([first(issue, 'type'), first(issue, 'category')], ' · '), { format: 'humanize' }),
                fact('priority', first(issue, 'priority'), { format: 'humanize' }),
                fact('status', first(issue, 'status'), { format: 'humanize' }),
                relatedFact('vehicle', relation(owner, issue, 'vehicle'), 'vehicle', first(issue, 'vehicle_name')),
                relatedFact('driver', relation(owner, issue, 'driver'), 'driver', first(issue, 'driver_name')),
                relatedFact('assignee', relation(owner, issue, 'assignee'), 'user', first(issue, 'assignee_name')),
            ],
            open: panelOpener(owner, 'issue-actions'),
        },
        {
            key: 'maintenance',
            labelKey: 'resource.maintenance',
            icon: 'screwdriver-wrench',
            modelNames: ['maintenance'],
            polymorphicTypes: ['fleet-ops:maintenance', 'Fleetbase\\FleetOps\\Models\\Maintenance'],
            permission: 'fleet-ops view maintenance',
            title: (maintenance) => first(maintenance, 'summary', 'type', 'public_id'),
            identifier: (maintenance) => first(maintenance, 'public_id'),
            image: () => icon('screwdriver-wrench'),
            status: (maintenance) => (get(maintenance, 'is_overdue') ? 'overdue' : first(maintenance, 'status')),
            selectDetails: (maintenance) => [first(maintenance, 'type'), first(maintenance, 'maintainable_name')],
            facts: (maintenance) => [
                fact('type', first(maintenance, 'type'), { format: 'humanize' }),
                relatedFact('maintainable', relation(owner, maintenance, 'maintainable'), polymorphicType(maintenance, 'maintainable'), first(maintenance, 'maintainable_name')),
                relatedFact('performed-by', relation(owner, maintenance, 'performed_by'), polymorphicType(maintenance, 'performed_by'), first(maintenance, 'performed_by_name')),
                fact('scheduled', get(maintenance, 'completed_at') ?? get(maintenance, 'scheduled_at'), { format: 'date' }),
                fact('total-cost', money(get(maintenance, 'total_cost'), get(maintenance, 'currency'))),
                relatedFact('work-order', relation(owner, maintenance, 'work_order'), 'work-order', first(maintenance, 'work_order_subject')),
            ],
            open: panelOpener(owner, 'maintenance-actions'),
        },
        {
            key: 'maintenance-schedule',
            labelKey: 'resource.maintenance-schedule',
            icon: 'calendar-check',
            modelNames: ['maintenance-schedule'],
            polymorphicTypes: ['fleet-ops:maintenance-schedule', 'Fleetbase\\FleetOps\\Models\\MaintenanceSchedule'],
            permission: 'fleet-ops view maintenance-schedule',
            statusTones: { active: 'text-green-500', paused: 'text-yellow-500', archived: 'text-gray-400' },
            title: (schedule) => first(schedule, 'name', 'title', 'code', 'public_id'),
            identifier: (schedule) => first(schedule, 'code') ?? join([get(schedule, 'interval_value'), first(schedule, 'interval_unit')]),
            image: () => icon('calendar-check'),
            status: (schedule) => first(schedule, 'status'),
            selectDetails: (schedule) => [first(schedule, 'subject_name'), join([get(schedule, 'interval_value'), first(schedule, 'interval_unit')])],
            facts: (schedule) => [
                relatedFact('subject', relation(owner, schedule, 'subject'), polymorphicType(schedule, 'subject', 'subject_type'), first(schedule, 'subject_name')),
                fact('interval', join([first(schedule, 'interval_method'), join([get(schedule, 'interval_value'), first(schedule, 'interval_unit')])], ' · '), { format: 'humanize' }),
                fact('next-due', get(schedule, 'next_due_date') ?? get(schedule, 'nextDueAt'), { format: 'date' }),
                relatedFact(
                    'assignee',
                    relation(owner, schedule, 'default_assignee'),
                    polymorphicType(schedule, 'default_assignee', 'default_assignee_type'),
                    first(schedule, 'default_assignee_name')
                ),
                fact('priority', first(schedule, 'default_priority'), { format: 'humanize' }),
            ],
            open: panelOpener(owner, 'maintenance-schedule-actions'),
        },
        {
            key: 'work-order',
            labelKey: 'resource.work-order',
            icon: 'clipboard-list',
            modelNames: ['work-order'],
            polymorphicTypes: ['fleet-ops:work-order', 'Fleetbase\\FleetOps\\Models\\WorkOrder'],
            permission: 'fleet-ops view work-order',
            title: (workOrder) => first(workOrder, 'subject', 'code', 'public_id'),
            identifier: (workOrder) => first(workOrder, 'code'),
            image: () => icon('clipboard-list'),
            status: (workOrder) => (get(workOrder, 'is_overdue') ? 'overdue' : first(workOrder, 'status')),
            selectDetails: (workOrder) => [first(workOrder, 'code'), first(workOrder, 'status')],
            facts: (workOrder) => [
                relatedFact('target', relation(owner, workOrder, 'target'), polymorphicType(workOrder, 'target'), first(workOrder, 'target_name')),
                relatedFact('assignee', relation(owner, workOrder, 'assignee'), polymorphicType(workOrder, 'assignee'), first(workOrder, 'assignee_name')),
                fact('priority', first(workOrder, 'priority'), { format: 'humanize' }),
                fact('due', get(workOrder, 'due_at'), { format: 'date' }),
                fact('completion', present(get(workOrder, 'completion_percentage')) ? `${get(workOrder, 'completion_percentage')}%` : null),
                relatedFact('schedule', workOrder.__schedule ?? null, 'maintenance-schedule', null),
            ],
            hydrate: async (workOrder) => {
                const id = get(workOrder, 'schedule_uuid');
                const store = owner.lookup('service:store');

                if (!id || !store || workOrder.__schedule !== undefined) {
                    return null;
                }

                try {
                    workOrder.__schedule = store.peekRecord('maintenance-schedule', id) ?? (await store.findRecord('maintenance-schedule', id));
                } catch {
                    workOrder.__schedule = null;
                }

                return workOrder;
            },
            open: panelOpener(owner, 'work-order-actions'),
        },
        {
            key: 'inspection-form',
            labelKey: 'resource.inspection-form',
            icon: 'list-check',
            modelNames: ['inspection-form'],
            polymorphicTypes: ['fleet-ops:inspection-form', 'Fleetbase\\FleetOps\\Models\\InspectionForm'],
            permission: 'fleet-ops view inspection-form',
            statusTones: { published: 'text-green-500', draft: 'text-yellow-500', archived: 'text-gray-400' },
            title: (form) => first(form, 'name', 'displayName', 'public_id'),
            identifier: (form) => join([first(form, 'type'), present(get(form, 'item_count')) ? `${get(form, 'item_count')} items` : null], ' · '),
            image: () => icon('list-check'),
            status: (form) => (get(form, 'is_published') ? 'published' : first(form, 'status')),
            selectDetails: (form) => [first(form, 'type'), first(form, 'status')],
            facts: (form) => [
                fact('type', first(form, 'type'), { format: 'humanize' }),
                fact('status', get(form, 'is_published') ? 'published' : first(form, 'status'), { format: 'humanize' }),
                fact('items', get(form, 'item_count')),
                relatedFact('subject', relation(owner, form, 'subject'), polymorphicType(form, 'subject', 'subject_type'), first(form, 'subject.displayName', 'subject.name')),
                fact('published', get(form, 'published_at'), { format: 'date' }),
            ],
            open: panelOpener(owner, 'inspection-form-actions', { mode: 'transition' }),
        },
        {
            key: 'inspection-submission',
            labelKey: 'resource.inspection-submission',
            icon: 'clipboard-check',
            modelNames: ['inspection-submission'],
            polymorphicTypes: ['fleet-ops:inspection-submission', 'Fleetbase\\FleetOps\\Models\\InspectionSubmission'],
            permission: 'fleet-ops view inspection-submission',
            statusTones: { passed: 'text-green-500', failed: 'text-red-500', submitted: 'text-green-500', draft: 'text-yellow-500' },
            title: (submission) => first(submission, 'form.name', 'form_name', 'displayName', 'public_id'),
            identifier: (submission) => first(submission, 'submittedAt', 'submitted_at'),
            image: () => icon('clipboard-check'),
            status: (submission) => first(submission, 'result', 'status'),
            selectDetails: (submission) => [first(submission, 'vehicle_name', 'driver_name'), first(submission, 'result', 'status')],
            facts: (submission) => [
                relatedFact('form', relation(owner, submission, 'form'), 'inspection-form', first(submission, 'form_name')),
                relatedFact('vehicle', relation(owner, submission, 'vehicle'), 'vehicle', first(submission, 'vehicle_name')),
                relatedFact('driver', relation(owner, submission, 'driver'), 'driver', first(submission, 'driver_name')),
                relatedFact('submitted-by', relation(owner, submission, 'submitted_by'), 'user', first(submission, 'submitted_by.name')),
                fact('failed-items', present(get(submission, 'total_items')) ? `${get(submission, 'failed_items') ?? 0} of ${get(submission, 'total_items')}` : null),
                fact('submitted', get(submission, 'submitted_at'), { format: 'date' }),
            ],
            open: panelOpener(owner, 'inspection-submission-actions', { mode: 'transition' }),
        },
    ];
}

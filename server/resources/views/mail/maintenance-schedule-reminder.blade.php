<x-mail-layout>
<h2 style="font-size: 18px; font-weight: 600;">
    Maintenance Due Reminder: {{ $schedule->name }}
</h2>
@if($assignee && $assignee->name)
<p>Dear {{ $assignee->name }},</p>
@endif
<p>
    This is a reminder that the following maintenance is due
    @if($offsetDays === 1)
        <strong>tomorrow</strong>.
    @else
        in <strong>{{ $offsetDays }} days</strong>.
    @endif
</p>
<table style="width: 100%; border-collapse: collapse; margin-top: 16px; margin-bottom: 16px;">
    <tr>
        <td style="padding: 8px 0; font-weight: 600; width: 40%; vertical-align: top;">Schedule</td>
        <td style="padding: 8px 0;">{{ $schedule->name }}</td>
    </tr>
    @if($subject)
    <tr>
        <td style="padding: 8px 0; font-weight: 600; vertical-align: top;">Asset</td>
        <td style="padding: 8px 0;">{{ $subject->name ?? $subject->display_name ?? $subject->public_id }}</td>
    </tr>
    @endif
    <tr>
        <td style="padding: 8px 0; font-weight: 600; vertical-align: top;">Type</td>
        <td style="padding: 8px 0;">{{ ucfirst(str_replace('_', ' ', $schedule->type)) }}</td>
    </tr>
    <tr>
        <td style="padding: 8px 0; font-weight: 600; vertical-align: top;">Due Date</td>
        <td style="padding: 8px 0;">{{ $schedule->next_due_date?->format('d M Y') }}</td>
    </tr>
    <tr>
        <td style="padding: 8px 0; font-weight: 600; vertical-align: top;">Interval</td>
        <td style="padding: 8px 0;">
            @if($schedule->interval_method === 'time')
                Every {{ $schedule->interval_value }} {{ $schedule->interval_unit }}
            @elseif($schedule->interval_method === 'odometer')
                Every {{ number_format($schedule->interval_distance) }} km / miles
            @elseif($schedule->interval_method === 'engine_hours')
                Every {{ number_format($schedule->interval_engine_hours) }} engine hours
            @endif
        </td>
    </tr>
    <tr>
        <td style="padding: 8px 0; font-weight: 600; vertical-align: top;">Priority</td>
        <td style="padding: 8px 0;">{{ ucfirst($schedule->default_priority ?? 'normal') }}</td>
    </tr>
</table>
@if($schedule->instructions)
<p style="font-weight: 600; margin-bottom: 4px;">Instructions:</p>
<p style="white-space: pre-line;">{{ $schedule->instructions }}</p>
@endif
<p style="margin-top: 24px; color: #6b7280; font-size: 13px;">
    Please do not reply directly to this email. Contact the fleet manager if you have any questions.
</p>
</x-mail-layout>

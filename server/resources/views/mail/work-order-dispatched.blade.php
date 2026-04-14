<x-mail-layout>
<h2 style="font-size: 18px; font-weight: 600;">
    Work Order Dispatched: #{{ $workOrder->public_id }}
</h2>

@if($assignee && $assignee->name)
<p>Dear {{ $assignee->name }},</p>
@endif

<p>
    A work order has been assigned to you. Please review the details below and take the necessary action.
</p>

<table style="width: 100%; border-collapse: collapse; margin-top: 16px; margin-bottom: 16px;">
    <tr>
        <td style="padding: 8px 0; font-weight: 600; width: 40%; vertical-align: top;">Work Order #</td>
        <td style="padding: 8px 0;">{{ $workOrder->public_id }}</td>
    </tr>
    @if($workOrder->subject)
    <tr>
        <td style="padding: 8px 0; font-weight: 600; vertical-align: top;">Subject</td>
        <td style="padding: 8px 0;">{{ $workOrder->subject }}</td>
    </tr>
    @endif
    <tr>
        <td style="padding: 8px 0; font-weight: 600; vertical-align: top;">Status</td>
        <td style="padding: 8px 0;">{{ ucfirst(str_replace('_', ' ', $workOrder->status)) }}</td>
    </tr>
    <tr>
        <td style="padding: 8px 0; font-weight: 600; vertical-align: top;">Priority</td>
        <td style="padding: 8px 0;">{{ ucfirst($workOrder->priority) }}</td>
    </tr>
    @if($target)
    <tr>
        <td style="padding: 8px 0; font-weight: 600; vertical-align: top;">Asset / Vehicle</td>
        <td style="padding: 8px 0;">{{ $target->name ?? $target->display_name ?? $target->public_id }}</td>
    </tr>
    @endif
    @if($workOrder->due_at)
    <tr>
        <td style="padding: 8px 0; font-weight: 600; vertical-align: top;">Due Date</td>
        <td style="padding: 8px 0;">{{ $workOrder->due_at->format('d M Y') }}</td>
    </tr>
    @endif
    @if($workOrder->estimated_cost)
    <tr>
        <td style="padding: 8px 0; font-weight: 600; vertical-align: top;">Estimated Cost</td>
        <td style="padding: 8px 0;">{{ number_format($workOrder->estimated_cost / 100, 2) }} {{ strtoupper($workOrder->currency ?? 'USD') }}</td>
    </tr>
    @endif
    @if($workOrder->approved_budget)
    <tr>
        <td style="padding: 8px 0; font-weight: 600; vertical-align: top;">Approved Budget</td>
        <td style="padding: 8px 0;">{{ number_format($workOrder->approved_budget / 100, 2) }} {{ strtoupper($workOrder->currency ?? 'USD') }}</td>
    </tr>
    @endif
</table>

@if($workOrder->instructions)
<p style="font-weight: 600; margin-bottom: 4px;">Instructions:</p>
<p style="white-space: pre-line;">{{ $workOrder->instructions }}</p>
@endif

<p style="margin-top: 24px; color: #6b7280; font-size: 13px;">
    Please do not reply directly to this email. Contact the fleet manager if you have any questions.
</p>
</x-mail-layout>

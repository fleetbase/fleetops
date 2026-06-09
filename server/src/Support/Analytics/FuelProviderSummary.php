<?php

namespace Fleetbase\FleetOps\Support\Analytics;

use Fleetbase\FleetOps\Models\FuelProviderConnection;
use Fleetbase\FleetOps\Models\FuelProviderTransaction;

class FuelProviderSummary extends AbstractAnalytics
{
    public function get(): array
    {
        $transactions = FuelProviderTransaction::where('company_uuid', $this->company->uuid)
            ->whereBetween('transaction_at', [$this->start, $this->end])
            ->get();

        $connections = FuelProviderConnection::where('company_uuid', $this->company->uuid)->get();

        $byProvider = $transactions
            ->groupBy('provider')
            ->map(fn ($rows, $provider) => [
                'provider'     => $provider,
                'transactions' => $rows->count(),
                'spend'        => (int) $rows->sum('amount'),
                'volume'       => (float) $rows->sum('volume'),
                'unmatched'    => $rows->where('sync_status', 'unmatched')->count(),
            ])
            ->values();

        return [
            'summary' => [
                'connections'        => $connections->count(),
                'active_connections' => $connections->whereIn('status', ['connected', 'active'])->count(),
                'transactions'       => $transactions->count(),
                'unmatched'          => $transactions->where('sync_status', 'unmatched')->count(),
                'spend'              => (int) $transactions->sum('amount'),
                'volume'             => (float) $transactions->sum('volume'),
                'currency'           => $transactions->first()?->currency ?? $this->companyCurrency(),
            ],
            'providers' => $byProvider,
        ];
    }
}

<?php

namespace Fleetbase\FleetOps\Support\Analytics;

use Fleetbase\FleetOps\Models\FuelProviderConnection;
use Fleetbase\FleetOps\Models\FuelProviderTransaction;

class FuelProviderSummary extends AbstractAnalytics
{
    public function get(): array
    {
        $transactions = $this->transactions($this->company->uuid);
        $connections  = $this->connections($this->company->uuid);

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

    protected function transactions(string $companyUuid)
    {
        return FuelProviderTransaction::where('company_uuid', $companyUuid)
            ->whereBetween('transaction_at', [$this->start, $this->end])
            ->get();
    }

    protected function connections(string $companyUuid)
    {
        return FuelProviderConnection::where('company_uuid', $companyUuid)->get();
    }
}

<?php

namespace Fleetbase\FleetOps\Observers;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\PurchaseRate;
use Fleetbase\FleetOps\Support\Utils;
use Fleetbase\Models\Company;
use Fleetbase\Models\Transaction;
use Fleetbase\Models\TransactionItem;

class PurchaseRateObserver
{
    /**
     * Handle the PurchaseRate "creating" event.
     * Create transactions accordingly.
     *
     * @return void
     */
    public function creating(PurchaseRate $purchaseRate)
    {
        if (!$purchaseRate->uuid) {
            $purchaseRate->uuid = $this->generateUuid();
        }

        $this->loadRelations($purchaseRate);
        $order = $this->resolveOrder($purchaseRate);

        // get company
        $company = $this->findCompany(session('company', $purchaseRate->company_uuid));

        // get currency to use
        $currency = $this->getServiceQuoteCurrency($purchaseRate)
            ?: $this->getCompanyTransactionCurrency($company ?? $purchaseRate->company_uuid);

        // create transaction and transaction items
        $transaction = $this->createTransaction([
            'company_uuid'           => session('company', $purchaseRate->company_uuid),
            'customer_uuid'          => $purchaseRate->customer_uuid,
            'customer_type'          => $purchaseRate->customer_type,
            'subject_uuid'           => $order?->uuid,
            'subject_type'           => $order ? Order::class : null,
            'context_uuid'           => $purchaseRate->uuid,
            'context_type'           => PurchaseRate::class,
            'gateway_transaction_id' => $this->getTransactionId($purchaseRate),
            'gateway'                => 'internal',
            'amount'                 => $this->getServiceQuoteAmount($purchaseRate),
            'currency'               => $currency,
            'description'            => 'Dispatch order',
            'type'                   => 'dispatch',
            'direction'              => Transaction::DIRECTION_CREDIT,
            'status'                 => Transaction::STATUS_SUCCESS,
            'settlement_status'      => Transaction::SETTLEMENT_STATUS_UNPAID,
        ]);

        if ($this->hasServiceQuote($purchaseRate)) {
            $this->getServiceQuoteItems($purchaseRate)->each(function ($serviceQuoteItem) use ($transaction, $currency) {
                $this->createTransactionItem([
                    'transaction_uuid' => $transaction->uuid,
                    'amount'           => $serviceQuoteItem->amount ?? 0,
                    'currency'         => $currency,
                    'details'          => data_get($serviceQuoteItem, 'details', 'Internal dispatch'),
                    'code'             => data_get($serviceQuoteItem, 'code', 'internal'),
                ]);
            });
        }

        $purchaseRate->transaction_uuid = $transaction->uuid;
        $purchaseRate->status           = $purchaseRate->status ?: Transaction::STATUS_SUCCESS;
    }

    protected function generateUuid(): string
    {
        return PurchaseRate::generateUuid();
    }

    protected function loadRelations(PurchaseRate $purchaseRate): void
    {
        $purchaseRate->load(['serviceQuote.items', 'serviceQuote.serviceRate']);
    }

    protected function findCompany(?string $uuid): ?Company
    {
        return Company::where('uuid', $uuid)->first();
    }

    protected function getCompanyTransactionCurrency(mixed $company): string
    {
        return Utils::getCompanyTransactionCurrency($company);
    }

    protected function getServiceQuoteCurrency(PurchaseRate $purchaseRate): ?string
    {
        return data_get($purchaseRate, 'serviceQuote.currency');
    }

    protected function getServiceQuoteAmount(PurchaseRate $purchaseRate): int|float
    {
        return data_get($purchaseRate, 'serviceQuote.amount', 0);
    }

    protected function getTransactionId(PurchaseRate $purchaseRate): string
    {
        return $purchaseRate->getMeta('transaction_id', Transaction::generateNumber());
    }

    protected function hasServiceQuote(PurchaseRate $purchaseRate): bool
    {
        return isset($purchaseRate->serviceQuote);
    }

    protected function getServiceQuoteItems(PurchaseRate $purchaseRate): \Illuminate\Support\Collection
    {
        return $purchaseRate->serviceQuote->items;
    }

    protected function createTransaction(array $attributes): Transaction
    {
        return Transaction::create($attributes);
    }

    protected function createTransactionItem(array $attributes): TransactionItem
    {
        return TransactionItem::create($attributes);
    }

    protected function resolveOrder(PurchaseRate $purchaseRate): ?Order
    {
        if (!$purchaseRate->payload_uuid) {
            return null;
        }

        return Order::where('payload_uuid', $purchaseRate->payload_uuid)->first();
    }
}

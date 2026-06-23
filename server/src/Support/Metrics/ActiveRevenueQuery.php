<?php

namespace Fleetbase\FleetOps\Support\Metrics;

use Fleetbase\FleetOps\Models\Order;
use Fleetbase\Models\Company;
use Fleetbase\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class ActiveRevenueQuery
{
    public const ACTIVE_STATUSES               = [Transaction::STATUS_SUCCESS];
    public const INACTIVE_TRANSACTION_STATUSES = ['pending', 'failed', 'cancelled', 'canceled', 'void', 'voided', 'expired', 'reversed', 'refunded', 'ignored'];
    public const INACTIVE_ORDER_STATUSES       = ['canceled', 'cancelled', 'order_canceled'];
    public const INACTIVE_INVOICE_STATUSES     = ['void', 'voided', 'cancelled', 'canceled'];

    public static function forCompany(Company $company, string $currency, ?\DateTimeInterface $start = null, ?\DateTimeInterface $end = null): Builder
    {
        $query = Transaction::query()
            ->where('company_uuid', $company->uuid)
            ->where('currency', $currency)
            ->where('direction', Transaction::DIRECTION_CREDIT)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->whereNotIn('status', self::INACTIVE_TRANSACTION_STATUSES)
            ->whereNull('deleted_at')
            ->whereNull('voided_at')
            ->whereNull('reversed_at')
            ->whereNull('parent_transaction_uuid');

        if ($start && $end) {
            $query->whereBetween('created_at', [$start, $end]);
        }

        static::excludeInactiveOrders($query);
        static::excludeInactiveInvoices($query);

        return $query;
    }

    protected static function excludeInactiveOrders(Builder $query): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        foreach (['subject_uuid' => 'subject_type', 'context_uuid' => 'context_type'] as $uuidColumn => $typeColumn) {
            $query->whereNotExists(function ($subQuery) use ($uuidColumn, $typeColumn) {
                $subQuery->selectRaw('1')
                    ->from('orders')
                    ->whereColumn('orders.uuid', "transactions.{$uuidColumn}")
                    ->where("transactions.{$typeColumn}", Order::class)
                    ->where(function ($orderQuery) {
                        static::inactiveOrderConstraint($orderQuery);
                    });
            });
        }

        $query->whereNotExists(function ($subQuery) {
            $subQuery->selectRaw('1')
                ->from('orders')
                ->whereColumn('orders.transaction_uuid', 'transactions.uuid')
                ->where(function ($orderQuery) {
                    static::inactiveOrderConstraint($orderQuery);
                });
        });
    }

    protected static function excludeInactiveInvoices(Builder $query): void
    {
        $invoiceClass = 'Fleetbase\\Ledger\\Models\\Invoice';

        if (!class_exists($invoiceClass) || !Schema::hasTable('ledger_invoices')) {
            return;
        }

        foreach (['subject_uuid' => 'subject_type', 'context_uuid' => 'context_type'] as $uuidColumn => $typeColumn) {
            $query->whereNotExists(function ($subQuery) use ($uuidColumn, $typeColumn, $invoiceClass) {
                $subQuery->selectRaw('1')
                    ->from('ledger_invoices')
                    ->whereColumn('ledger_invoices.uuid', "transactions.{$uuidColumn}")
                    ->where("transactions.{$typeColumn}", $invoiceClass)
                    ->where(function ($invoiceQuery) {
                        static::inactiveInvoiceConstraint($invoiceQuery);
                    });
            });
        }

        $query->whereNotExists(function ($subQuery) {
            $subQuery->selectRaw('1')
                ->from('ledger_invoices')
                ->whereColumn('ledger_invoices.transaction_uuid', 'transactions.uuid')
                ->where(function ($invoiceQuery) {
                    static::inactiveInvoiceConstraint($invoiceQuery);
                });
        });
    }

    protected static function inactiveOrderConstraint($query): void
    {
        $query->whereNotNull('orders.deleted_at')
            ->orWhereIn('orders.status', self::INACTIVE_ORDER_STATUSES);
    }

    protected static function inactiveInvoiceConstraint($query): void
    {
        $query->whereNotNull('ledger_invoices.deleted_at')
            ->orWhereIn('ledger_invoices.status', self::INACTIVE_INVOICE_STATUSES);

        if (Schema::hasTable('orders')) {
            $query->orWhereExists(function ($orderQuery) {
                $orderQuery->selectRaw('1')
                    ->from('orders')
                    ->whereColumn('orders.uuid', 'ledger_invoices.order_uuid')
                    ->where(function ($inactiveOrderQuery) {
                        static::inactiveOrderConstraint($inactiveOrderQuery);
                    });
            });
        }
    }
}

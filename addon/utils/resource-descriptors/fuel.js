import { get } from '@ember/object';
import { first, present, relation, icon, fact, relatedFact, money, join, panelOpener, routeOpener } from './helpers';

/**
 * Fuel reports, fuel provider connections and their transactions.
 */
export default function buildFuelDescriptors(owner) {
    return [
        {
            key: 'fuel-report',
            labelKey: 'resource.fuel-report',
            icon: 'gas-pump',
            modelNames: ['fuel-report'],
            polymorphicTypes: ['fleet-ops:fuel-report', 'Fleetbase\\FleetOps\\Models\\FuelReport'],
            permission: 'fleet-ops view fuel-report',
            title: (report) => first(report, 'public_id'),
            identifier: (report) => join([get(report, 'volume'), first(report, 'metric_unit')]) ?? money(get(report, 'amount'), get(report, 'currency')),
            image: () => icon('gas-pump'),
            status: (report) => first(report, 'status'),
            selectDetails: (report) => [first(report, 'vehicle_name'), join([get(report, 'volume'), first(report, 'metric_unit')])],
            facts: (report) => [
                relatedFact('vehicle', relation(owner, report, 'vehicle'), 'vehicle', first(report, 'vehicle_name')),
                relatedFact('driver', relation(owner, report, 'driver'), 'driver', first(report, 'driver_name')),
                relatedFact('reporter', relation(owner, report, 'reporter'), 'user', first(report, 'reporter_name')),
                fact('volume', join([get(report, 'volume'), first(report, 'metric_unit')])),
                fact('amount', money(get(report, 'amount'), get(report, 'currency'))),
                fact('odometer', join([get(report, 'odometer'), first(report, 'source', 'provider')], ' · ')),
            ],
            open: panelOpener(owner, 'fuel-report-actions'),
        },
        {
            key: 'fuel-provider-connection',
            labelKey: 'resource.fuel-provider-connection',
            icon: 'plug',
            modelNames: ['fuel-provider-connection'],
            polymorphicTypes: ['fleet-ops:fuel-provider-connection', 'Fleetbase\\FleetOps\\Models\\FuelProviderConnection'],
            statusTones: { connected: 'text-green-500', configured: 'text-yellow-500', error: 'text-red-500', disabled: 'text-gray-400' },
            title: (connection) => first(connection, 'displayName', 'name', 'provider', 'public_id'),
            identifier: (connection) => first(connection, 'provider'),
            image: () => icon('plug'),
            status: (connection) => (present(get(connection, 'last_error')) ? 'error' : first(connection, 'status')),
            selectDetails: (connection) => [first(connection, 'provider'), first(connection, 'environment')],
            facts: (connection) => [
                fact('provider', first(connection, 'provider')),
                fact('environment', first(connection, 'environment'), { format: 'humanize' }),
                fact('status', first(connection, 'status'), { format: 'humanize' }),
                fact('last-synced', get(connection, 'last_synced_at'), { format: 'date' }),
                fact('last-error', first(connection, 'last_error')),
            ],
            open: panelOpener(owner, 'fuel-integration-actions', { mode: 'transition' }),
        },
        {
            key: 'fuel-provider-transaction',
            labelKey: 'resource.fuel-provider-transaction',
            icon: 'receipt',
            modelNames: ['fuel-provider-transaction'],
            polymorphicTypes: ['fleet-ops:fuel-provider-transaction', 'Fleetbase\\FleetOps\\Models\\FuelProviderTransaction'],
            statusTones: { matched: 'text-green-500', unmatched: 'text-yellow-500', pending: 'text-yellow-500', failed: 'text-red-500' },
            title: (transaction) => first(transaction, 'provider_transaction_id', 'public_id'),
            identifier: (transaction) => first(transaction, 'station_name'),
            image: () => icon('receipt'),
            status: (transaction) => first(transaction, 'sync_status'),
            selectDetails: (transaction) => [first(transaction, 'station_name'), join([get(transaction, 'volume'), first(transaction, 'metric_unit')])],
            facts: (transaction) => [
                fact('transaction-at', get(transaction, 'transaction_at'), { format: 'date' }),
                fact('volume', join([join([get(transaction, 'volume'), first(transaction, 'metric_unit')]), money(get(transaction, 'amount'), get(transaction, 'currency'))], ' · ')),
                fact('station', first(transaction, 'station_name')),
                relatedFact('vehicle', relation(owner, transaction, 'vehicle'), 'vehicle', first(transaction, 'vehicle_name', 'plate_number')),
                relatedFact('driver', relation(owner, transaction, 'driver'), 'driver', first(transaction, 'driver_name')),
                relatedFact('fuel-report', relation(owner, transaction, 'fuel_report'), 'fuel-report', first(transaction, 'fuel_report_id')),
            ],
            open: routeOpener(owner, 'management.fuel-transactions.index.details'),
        },
    ];
}

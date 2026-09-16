import { fuelDate, fuelMoney } from './fuel-integration-format';

export const transactionStatuses = {
    imported: { label: 'Imported', tone: 'info' },
    matched: { label: 'Matched', tone: 'success' },
    unmatched: { label: 'Unmatched', tone: 'warning' },
    reviewed: { label: 'Reviewed', tone: 'success' },
    ignored: { label: 'Ignored', tone: 'default' },
    duplicate: { label: 'Duplicate', tone: 'warning' },
    error: { label: 'Error', tone: 'error' },
};

export const transactionActions = {
    vehicle: {
        title: 'Match to Vehicle',
        description: 'Choose the vehicle for this purchase. Confirming replaces the current vehicle match and may create a Fuel Report according to the integration settings.',
    },
    order: {
        title: 'Match to Order',
        description: 'Choose the order or trip for this purchase. Confirming replaces the current order link. The vehicle match and review status are unchanged.',
    },
    reprocess: {
        title: 'Reprocess / Rematch',
        description:
            'Run the integration’s matching rules again for missing matches. Existing vehicle and order links are kept. Reviewed or ignored status will be replaced with Matched or Unmatched. A Fuel Report may be created if enabled in the integration settings.',
    },
    ignored: {
        title: 'Ignore Transaction',
        description:
            'Exclude this purchase from automatic matching while keeping it in the ledger. Existing matches and Fuel Reports are kept. You can reprocess it later to resume matching.',
    },
    reviewed: { title: 'Mark Reviewed', description: 'Confirm that you have checked this purchase. This updates its review status without changing its vehicle, order, or Fuel Report.' },
};

export function openTransactionAction(modalsManager, mode, transactions, onSaved) {
    const records = Array.isArray(transactions) ? transactions : [transactions];
    if (!records.length || !transactionActions[mode]) return;
    return modalsManager.show('modals/fuel-transaction-action', {
        title: transactionActions[mode].title,
        acceptButtonText: transactionActions[mode].title,
        acceptButtonType: mode === 'ignored' ? 'danger' : 'primary',
        declineButtonText: 'Cancel',
        keepOpen: true,
        mode,
        transactions: records,
        onSaved,
    });
}

const present = (value) => ['string', 'number'].includes(typeof value) && String(value).trim() !== '';
const text = (value) => (present(value) ? String(value).trim() : null);

// Only named purchase fields are presented. Unknown payload objects, provider codes,
// credentials and other integration internals never become operator-facing content.
export function purchaseGroups(transaction) {
    const normalized = transaction.normalized_payload ?? {};
    const raw = transaction.raw_payload ?? {};
    const value = (...keys) => keys.flatMap((key) => [normalized[key], raw[key]]).find(present);
    const group = (title, rows) => ({ title, rows: rows.filter(([, value]) => present(value)).map(([label, value]) => ({ label, value: text(value) })) });
    const petroapp = transaction.provider === 'petroapp';
    const groups = [
        group('Purchase', [
            ['Bill number', transaction.provider_transaction_id],
            ['Purchased at', transaction.transaction_at ? fuelDate(transaction.transaction_at, 'Unavailable') : null],
            ['Amount', present(transaction.amount) ? fuelMoney(transaction.amount, transaction.currency) : null],
            ['Quantity', present(transaction.volume) ? `${transaction.volume} ${transaction.metric_unit || 'L'}` : null],
            ['Fuel type', value('fuel_type', 'product_name', 'fuel_grade')],
            ['Payment method', value('payment_method_text', 'payment_method_name')],
            ['Odometer', transaction.odometer],
            ['Invoice number', value('invoice_number')],
            [
                'Amount before VAT',
                petroapp && present(raw.cost_before_vat) ? `${transaction.currency || 'SAR'} ${Number(raw.cost_before_vat).toLocaleString('en-US', { maximumFractionDigits: 3 })}` : null,
            ],
            ['VAT rate', petroapp && present(raw.vat_percent) ? `${Number(raw.vat_percent) * 100}%` : null],
        ]),
        group('Station & account', [
            ['Station', transaction.station_name],
            ['City', value('city')],
            ['District', value('district')],
            ['Account branch', value('branch_name')],
            ['Representative', value('delegate_name')],
            ['Representative phone', petroapp ? value('delegate_mobile') : null],
        ]),
        group('Vehicle & trip references', [
            ['Plate number', transaction.plate_number],
            ['Fuel card', transaction.vehicle_card_id],
            ['Internal number', transaction.internal_number],
            ['Structure number', transaction.structure_number],
            ['Trip number', transaction.trip_number],
            ['VIN', value('vin', 'vin_number')],
            ['Serial number', value('serial_number')],
            ['Call sign', value('call_sign')],
        ]),
    ];
    return groups.filter((group) => group.rows.length);
}

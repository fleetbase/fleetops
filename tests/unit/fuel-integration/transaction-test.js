import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import Component from '@glimmer/component';
import { setComponentTemplate } from '@ember/component';
import Resolver from 'ember-resolver';
import TransactionAction from '@fleetbase/fleetops-engine/components/modals/fuel-transaction-action';
import { purchaseGroups, openTransactionAction, transactionStatuses } from '@fleetbase/fleetops-engine/utils/fuel-transaction';
import TransactionsController from '@fleetbase/fleetops-engine/controllers/management/fuel-transactions/index';

module('Unit | fuel integration | transaction presentation', function () {
    test('purchase fields are readable, provider aware, and retain zero values', function (assert) {
        const groups = purchaseGroups({
            provider: 'petroapp',
            amount: 300,
            currency: 'SAR',
            volume: 4,
            odometer: 0,
            normalized_payload: { payment_method_text: 'Company balance', city: ' Al Baha' },
            raw_payload: { fuel_type: 'Diesel', vat_percent: 0.15, cost_before_vat: 2.609, delegate_mobile: '012345', token: 'secret', nested: { private: true } },
        });
        const rows = Object.fromEntries(groups.flatMap((group) => group.rows.map((row) => [row.label, row.value])));
        assert.strictEqual(rows.Amount, 'SAR 3');
        assert.strictEqual(rows['VAT rate'], '15%');
        assert.strictEqual(rows['Amount before VAT'], 'SAR 2.609');
        assert.strictEqual(rows.Odometer, '0');
        assert.strictEqual(rows['Fuel type'], 'Diesel');
        assert.strictEqual(rows.City, 'Al Baha');
        assert.false(JSON.stringify(groups).includes('secret'));
        assert.false(JSON.stringify(groups).includes('nested'));
        const other = purchaseGroups({ provider: 'other', raw_payload: { fuel_type: 'Petrol', vat_percent: 15, token: 'secret' } });
        assert.deepEqual(other[0].rows, [{ label: 'Fuel type', value: 'Petrol' }], 'unknown provider units are not guessed');
        const sasco = purchaseGroups({ provider: 'sasco', station_name: 'Station', normalized_payload: { driver_name: 'Missy Champerlen', driver_phone: '517444245' } });
        assert.deepEqual(sasco[0].rows, [
            { label: 'Station', value: 'Station' },
            { label: 'Driver', value: 'Missy Champerlen' },
            { label: 'Driver phone', value: '517444245' },
        ]);
        assert.deepEqual(purchaseGroups({}), []);
    });
    test('all supported states have a semantic badge style', function (assert) {
        assert.strictEqual(transactionStatuses.unmatched.tone, 'warning');
        assert.strictEqual(transactionStatuses.error.tone, 'error');
        assert.deepEqual(Object.keys(transactionStatuses), ['imported', 'matched', 'unmatched', 'reviewed', 'ignored', 'duplicate', 'error']);
    });
    test('opening an action passes a reviewable modal without executing the request', function (assert) {
        const transaction = { id: 'bill' };
        const manager = {
            show(name, options) {
                assert.strictEqual(name, 'modals/fuel-transaction-action');
                assert.strictEqual(options.mode, 'ignored');
                assert.deepEqual(options.transactions, [transaction]);
                assert.true(options.keepOpen);
            },
        };
        openTransactionAction(manager, 'ignored', transaction);
    });
});

module('Unit | fuel integration | transaction actions', function (hooks) {
    setupRenderingTest(hooks, { resolver: Resolver.create({ namespace: { modulePrefix: 'dummy' } }) });
    hooks.beforeEach(function () {
        this.requests = [];
        this.owner.register(
            'service:notifications',
            class extends Service {
                success() {}
                warning() {}
            }
        );
        this.owner.register('component:modal/default', setComponentTemplate(hbs`{{yield}}`, class extends Component {}));
        this.owner.register('component:input-group', setComponentTemplate(hbs`{{yield}}`, class extends Component {}));
        for (const name of ['fuel-transaction-status', 'model-select']) this.owner.register(`component:${name}`, setComponentTemplate(hbs`<span></span>`, class extends Component {}));
        const context = this;
        this.owner.register(
            'service:fetch',
            class extends Service {
                async post(path, body) {
                    context.requests.push({ path, body });
                }
            }
        );
        this.Dialog = class extends TransactionAction {
            constructor() {
                super(...arguments);
                context.dialog = this;
            }
        };
        this.record = { id: 'bill-1', provider_transaction_id: '123', sync_status: 'unmatched', amount: 300, async reload() {} };
        this.options = { mode: 'vehicle', transactions: [this.record] };
    });
    test('matching waits for target selection and explicit confirmation, then reloads and closes', async function (assert) {
        await render(hbs`<this.Dialog @options={{this.options}} />`);
        assert.strictEqual(this.requests.length, 0, 'opening or canceling does not send a request');
        assert.true(this.dialog.modalOptions.acceptButtonDisabled);
        await this.options.confirm();
        assert.strictEqual(this.requests.length, 0, 'empty target cannot be submitted');
        this.dialog.select({ id: 'vehicle-1', displayName: 'Truck 1' });
        assert.false(this.dialog.modalOptions.acceptButtonDisabled);
        let closed = false;
        await this.options.confirm(null, () => {
            closed = true;
        });
        assert.deepEqual(this.requests, [{ path: 'fuel-provider-transactions/bill-1/match-vehicle', body: { vehicle: 'vehicle-1' } }]);
        assert.true(closed);
    });
    test('order matching uses its own endpoint and target', async function (assert) {
        this.options.mode = 'order';
        await render(hbs`<this.Dialog @options={{this.options}} />`);
        this.dialog.select({ id: 'order-1', public_id: 'Order 1' });
        await this.options.confirm();
        assert.deepEqual(this.requests[0], { path: 'fuel-provider-transactions/bill-1/match-order', body: { order: 'order-1' } });
    });
    for (const mode of ['ignored', 'reviewed', 'reprocess']) {
        test(`${mode} waits for confirmation and submits only the intended change`, async function (assert) {
            this.options.mode = mode;
            await render(hbs`<this.Dialog @options={{this.options}} />`);
            assert.strictEqual(this.requests.length, 0);
            await this.options.confirm();
            assert.deepEqual(this.requests, [
                { path: `fuel-provider-transactions/bill-1/${mode === 'reprocess' ? 'reprocess' : 'review'}`, body: mode === 'reprocess' ? {} : { status: mode } },
            ]);
        });
    }
    test('bulk failure stays open and retry skips completed purchases', async function (assert) {
        const requests = this.requests;
        let fail = true;
        this.owner.register(
            'service:fetch',
            class extends Service {
                async post(path) {
                    requests.push(path);
                    if (path.includes('bill-2') && fail) throw new Error('Unavailable');
                }
            }
        );
        this.options = { mode: 'reprocess', transactions: [this.record, { ...this.record, id: 'bill-2' }] };
        await render(hbs`<this.Dialog @options={{this.options}} />`);
        let closed = false;
        await this.options.confirm(null, () => {
            closed = true;
        });
        assert.false(closed);
        assert.ok(this.dialog.error.includes('1 already updated'));
        fail = false;
        await this.options.confirm(null, () => {
            closed = true;
        });
        assert.true(closed);
        assert.strictEqual(requests.length, 3);
        assert.strictEqual(requests.filter((path) => path.includes('bill-1')).length, 1);
    });
    test('table hides report action until linked and routes matching into dialogs', function (assert) {
        this.owner.register(
            'service:modals-manager',
            class extends Service {
                show(name, options) {
                    assert.strictEqual(options.mode, 'order');
                }
            }
        );
        this.owner.register('service:host-router', class extends Service {});
        this.owner.register('service:table-context', class extends Service {});
        this.owner.register('controller:fuel-test-transactions', TransactionsController);
        const controller = this.owner.lookup('controller:fuel-test-transactions');
        const actions = controller.columns.at(-1).actions;
        const report = actions.find((action) => action.label === 'Open Fuel Report');
        assert.false(report.isVisible(this.record));
        assert.true(report.isVisible({ fuel_report_id: 'report-1' }));
        actions.find((action) => action.label === 'Match to Order').fn(this.record);
        assert.strictEqual(this.requests.length, 0);
    });
});

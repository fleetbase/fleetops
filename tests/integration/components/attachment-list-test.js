import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render, click } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | attachment-list', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders a header, one row per item and per-row actions', async function (assert) {
        this.set('items', [
            { id: 'a', name: 'Liftgate LG-1' },
            { id: 'b', name: 'Reefer Unit RU-7' },
        ]);
        this.set('detached', []);
        this.set('detach', (item) => this.detached.push(item.id));

        await render(hbs`
            <AttachmentList @title="Equipment" @description="Equipment on this trailer." @items={{this.items}}>
                <:item as |item|>{{item.name}}</:item>
                <:itemActions as |item|>
                    <button type="button" data-test-detach={{item.id}} {{on "click" (fn this.detach item)}}>Detach</button>
                </:itemActions>
            </AttachmentList>
        `);

        assert.dom('.fleetops-attachment-list-header h3').hasText('Equipment');
        assert.dom('.fleetops-attachment-list-header p').hasText('Equipment on this trailer.');
        assert.dom('.fleetops-attachment-list-item').exists({ count: 2 });
        assert.dom('.fleetops-attachment-list-item:first-child').includesText('Liftgate LG-1');
        assert.dom('.fleetops-attachment-list-empty').doesNotExist();

        await click('[data-test-detach="b"]');

        assert.deepEqual(this.detached, ['b'], 'row actions receive the row item');
    });

    test('it renders the empty state with the primary action and a spinner while loading', async function (assert) {
        this.set('items', []);
        this.set('clicks', 0);
        this.set('attach', () => this.set('clicks', this.clicks + 1));
        this.set('isLoading', false);

        await render(hbs`
            <AttachmentList
                @title="Devices"
                @items={{this.items}}
                @isLoading={{this.isLoading}}
                @actionText="Attach device"
                @onAction={{this.attach}}
                @emptyTitle="No devices attached"
                @emptyDescription="Attach a device to start receiving telemetry."
            >
                <:item as |item|>{{item.name}}</:item>
            </AttachmentList>
        `);

        assert.dom('.fleetops-attachment-list-empty').exists();
        assert.dom('.fleetops-attachment-list-empty h3').hasText('No devices attached');
        assert.dom('.fleetops-attachment-list-empty p').hasText('Attach a device to start receiving telemetry.');
        assert.dom('.fleetops-attachment-list-empty button').exists('the empty state repeats the primary action');

        await click('.fleetops-attachment-list-empty button');
        assert.strictEqual(this.clicks, 1);

        this.set('isLoading', true);
        assert.dom('.fleetops-attachment-list-loading').exists();
        assert.dom('.fleetops-attachment-list-empty').doesNotExist();
    });
});

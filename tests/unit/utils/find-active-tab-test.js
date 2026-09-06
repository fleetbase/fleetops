import { module, test } from 'qunit';
import findActiveTab from '@fleetbase/fleetops-engine/utils/find-active-tab';

module('Unit | Utility | find-active-tab', function () {
    const tabs = [
        { id: 'tab_1', slug: 'details' },
        { id: 'tab_2', slug: 'activity' },
    ];

    test('it finds a tab by slug or by id', function (assert) {
        assert.strictEqual(findActiveTab(tabs, 'activity'), tabs[1]);
        assert.strictEqual(findActiveTab(tabs, 'tab_2'), tabs[1]);
        assert.strictEqual(findActiveTab(tabs, 'missing'), undefined);
    });

    test('without an identifier the first tab is active', function (assert) {
        assert.strictEqual(findActiveTab(tabs), tabs[0]);
        assert.strictEqual(findActiveTab([]), undefined);
        assert.strictEqual(findActiveTab(), undefined, 'no tabs at all is not an error');
    });
});

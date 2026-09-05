import { module, test } from 'qunit';
import { setupWindowMock } from 'ember-window-mock/test-support';
import window from 'ember-window-mock';
import calculateMapDrawerDropdownPosition from '@fleetbase/fleetops-engine/utils/map-drawer-dropdown-position';

module('Unit | Utility | map-drawer-dropdown-position', function (hooks) {
    setupWindowMock(hooks);

    test('positions the dropdown to the left of the trigger, inside the drawer', function (assert) {
        const result = calculateMapDrawerDropdownPosition(mockTrigger({ left: 500, top: 300, right: 532, bottom: 332 }), mockContent({ width: 220, height: 160 }));

        assert.deepEqual(result.style, { position: 'absolute', left: 274, top: 300, marginTop: '0px', zIndex: '10000' });
    });

    test('clamps inside the drawer when there is not enough room on the left', function (assert) {
        const result = calculateMapDrawerDropdownPosition(mockTrigger({ left: 80, top: 300, right: 112, bottom: 332 }), mockContent({ width: 220, height: 160 }));

        assert.strictEqual(result.style.left, 6, 'left edge is clamped to the drawer boundary');
        assert.strictEqual(result.style.top, 300, 'top still aligns to trigger top');
    });

    test('clamps vertically without flipping above the trigger', function (assert) {
        const result = calculateMapDrawerDropdownPosition(mockTrigger({ left: 500, top: 560, right: 532, bottom: 592 }), mockContent({ width: 220, height: 160 }));

        assert.strictEqual(result.style.left, 274, 'left edge remains to the left of the trigger');
        assert.strictEqual(result.style.top, 448, 'top is clamped inside the drawer instead of flipped above the trigger');
    });

    test('falls back to the viewport and default menu size without a drawer or measured content', function (assert) {
        window.innerWidth = 1000;
        window.innerHeight = 500;

        const detached = calculateMapDrawerDropdownPosition({ getBoundingClientRect: () => ({ left: 900, top: 400, right: 932, bottom: 432 }) });
        assert.strictEqual(detached.style.left, 670, 'the default 224px width is used when the content is not measurable');
        assert.strictEqual(detached.style.top, 268, 'the default 240px height is clamped to the viewport');

        const outsideDrawer = calculateMapDrawerDropdownPosition(mockTrigger({ left: 100, top: 20, right: 132, bottom: 52 }, { drawer: null }), mockContent({ width: 220, height: 160 }));
        assert.strictEqual(outsideDrawer.style.left, 6, 'the viewport left edge bounds the menu');
        assert.strictEqual(outsideDrawer.style.top, 20);
    });

    test('returns an empty style without a trigger', function (assert) {
        assert.deepEqual(calculateMapDrawerDropdownPosition(null), { style: {} });
    });
});

function mockTrigger(rect, { drawer = mockElement({ left: 0, top: 100, right: 800, bottom: 600 }) } = {}) {
    return {
        getBoundingClientRect: () => rect,
        closest(selector) {
            return selector === '.next-drawer-panel' ? drawer : null;
        },
    };
}

function mockContent({ width, height }) {
    return mockElement({ left: 0, top: 0, right: width, bottom: height, width, height });
}

function mockElement(rect) {
    return {
        getBoundingClientRect: () => rect,
    };
}

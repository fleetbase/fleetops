import { module, test } from 'qunit';
import calculateMapDrawerDropdownPosition from '@fleetbase/fleetops-engine/utils/map-drawer-dropdown-position';

module('Unit | Utility | map-drawer-dropdown-position', function () {
    test('positions the dropdown to the left of the trigger', function (assert) {
        const result = calculateMapDrawerDropdownPosition(mockTrigger({ left: 500, top: 300, right: 532, bottom: 332 }), mockContent({ width: 220, height: 160 }));

        assert.strictEqual(result.style.left, '274px', 'left edge is trigger left minus menu width and gap');
        assert.strictEqual(result.style.top, '300px', 'top aligns to trigger top');
        assert.strictEqual(result.style.zIndex, '10000', 'z-index is returned as a string for the style modifier');
        assert.strictEqual(typeof result.style.zIndex, 'string', 'z-index value type is compatible with the style modifier');
    });

    test('clamps inside the drawer when there is not enough room on the left', function (assert) {
        const result = calculateMapDrawerDropdownPosition(mockTrigger({ left: 80, top: 300, right: 112, bottom: 332 }), mockContent({ width: 220, height: 160 }));

        assert.strictEqual(result.style.left, '6px', 'left edge is clamped to the drawer boundary');
        assert.strictEqual(result.style.top, '300px', 'top still aligns to trigger top');
    });

    test('clamps vertically without flipping above the trigger', function (assert) {
        const result = calculateMapDrawerDropdownPosition(mockTrigger({ left: 500, top: 560, right: 532, bottom: 592 }), mockContent({ width: 220, height: 160 }));

        assert.strictEqual(result.style.left, '274px', 'left edge remains to the left of the trigger');
        assert.strictEqual(result.style.top, '434px', 'top is clamped inside the drawer instead of flipped above the trigger');
    });
});

function mockTrigger(rect) {
    const root = mockElement({ left: 0, top: 0, right: 0, bottom: 0 });
    const drawer = mockElement({ left: 0, top: 100, right: 800, bottom: 600 });

    return {
        getBoundingClientRect: () => rect,
        closest(selector) {
            if (selector === '.ember-basic-dropdown') {
                return root;
            }

            if (selector === '.next-drawer-panel') {
                return drawer;
            }

            return null;
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

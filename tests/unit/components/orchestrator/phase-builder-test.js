import { module, test } from 'qunit';
import PhaseBuilderComponent from 'dummy/components/orchestrator/phase-builder';

function makeBuilder(args = {}) {
    return Object.create(PhaseBuilderComponent.prototype, {
        args: { value: args },
        intl: { value: { t: (key) => key } },
    });
}

module('Unit | Component | orchestrator/phase-builder', function () {
    test('new phases use the engine selected in orchestrator settings', function (assert) {
        const phase = makeBuilder({ defaultEngine: 'vroom' })._defaultPhase('optimize_routes');

        assert.strictEqual(phase.engine, 'vroom');
        assert.strictEqual(phase.mode, 'optimize_routes');
    });

    test('new phases fall back to the built-in greedy engine before settings load', function (assert) {
        assert.strictEqual(makeBuilder()._defaultPhase().engine, 'greedy');
    });
});

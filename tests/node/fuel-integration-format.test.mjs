import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fuelDate, fuelMoney, syncRunView } from '../../addon/utils/fuel-integration-format.js';

test('fuel dates use Latin digits and stable formatting, including invalid and missing values', () => {
    assert.match(fuelDate('2026-09-15T12:30:00Z'), /^2026-09-15 \d{2}:30$/);
    assert.equal(fuelDate(null), 'Not yet');
    assert.equal(fuelDate('invalid'), 'Not yet');
    assert.equal(fuelMoney(12345, 'SAR'), 'SAR 123.45');
});
test('sync outcomes distinguish empty, updated, failed and queued runs', () => {
    assert.match(syncRunView({ status: 'completed', imported: 0 }).result, /No transactions returned/);
    assert.match(syncRunView({ status: 'completed', imported: 0, summary: { received: 2, updated: 2 } }).result, /0 new · 2 updated/);
    assert.equal(syncRunView({ status: 'error', error: 'Provider unavailable' }).result, 'Provider unavailable');
    assert.match(syncRunView({ status: 'queued', created_at: '2020-01-01' }).result, /background worker/);
    assert.equal(syncRunView({ status: 'running' }).pending, true);
    assert.match(syncRunView({ status: 'completed' }).window, /unavailable/);
    assert.equal(syncRunView({ from: '2026-06-23T00:00:00Z', to: '2026-06-23T23:59:59Z' }).window, '2026-06-23 – 2026-06-23');
});

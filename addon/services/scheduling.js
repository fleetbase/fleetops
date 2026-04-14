import Service from '@ember/service';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { isNone } from '@ember/utils';
import { addMinutes, areIntervalsOverlapping } from 'date-fns';

/**
 * SchedulingService
 *
 * Central domain-logic service for the unified order scheduler.
 * Encapsulates conflict detection, best-fit insertion, single/bulk order
 * assignment, and a client-side undo/redo history stack.
 *
 * Usage in a controller:
 *   @service scheduling;
 *
 *   const result = await this.scheduling.assignOrder(order, driverId, scheduledAt);
 *   if (result.hasConflict) { ... }
 */
export default class SchedulingService extends Service {
    @service store;
    @service fetch;
    @service notifications;

    // -------------------------------------------------------------------------
    // Undo / Redo History Stack
    // -------------------------------------------------------------------------

    /** @type {Array<{orderId, previousDate, newDate, previousDriverId, newDriverId}>} */
    @tracked _history = [];

    /** @type {Array<{orderId, previousDate, newDate, previousDriverId, newDriverId}>} */
    @tracked _future = [];

    /** True when there is at least one action to undo. */
    get canUndo() {
        return this._history.length > 0;
    }

    /** True when there is at least one action to redo. */
    get canRedo() {
        return this._future.length > 0;
    }

    /**
     * Push a new action onto the history stack.
     * Clears the redo stack — a new action invalidates any future states.
     * Capped at 20 entries to prevent unbounded memory growth.
     *
     * @param {Object} entry
     */
    _pushHistory(entry) {
        const capped = [...this._history, entry].slice(-20);
        this._history = capped;
        this._future = [];
    }

    /**
     * Revert the most recent scheduling action.
     */
    @action async undo() {
        if (!this.canUndo) return;
        const history = [...this._history];
        const entry = history.pop();
        this._history = history;
        this._future = [entry, ...this._future];
        await this._applyHistoryEntry(entry, true);
    }

    /**
     * Re-apply the most recently undone action.
     */
    @action async redo() {
        if (!this.canRedo) return;
        const [entry, ...rest] = this._future;
        this._future = rest;
        this._history = [...this._history, entry];
        await this._applyHistoryEntry(entry, false);
    }

    /**
     * Apply a history entry in either the undo (reverse) or redo (forward) direction.
     *
     * @param {Object}  entry
     * @param {boolean} isUndo  true = restore previous state, false = re-apply new state
     */
    async _applyHistoryEntry(entry, isUndo) {
        const order = this.store.peekRecord('order', entry.orderId);
        if (!order) return;
        const targetDate = isUndo ? entry.previousDate : entry.newDate;
        const targetDriver = isUndo ? entry.previousDriverId : entry.newDriverId;
        const payload = {
            order: order.id,
            scheduled_at: targetDate instanceof Date ? targetDate.toISOString() : targetDate,
        };
        if (targetDriver !== undefined) {
            payload.driver_id = targetDriver;
        }
        try {
            await this.fetch.patch('orders/schedule', payload);
            order.set('scheduled_at', targetDate);
            if (targetDriver !== undefined) {
                order.set('driver_assigned_uuid', targetDriver);
            }
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    // -------------------------------------------------------------------------
    // Conflict Detection
    // -------------------------------------------------------------------------

    /**
     * Returns all orders assigned to `driverId` whose time window overlaps
     * with the proposed [start, end] interval.
     *
     * The `excludeOrderId` parameter allows the caller to exclude the order
     * being rescheduled from its own conflict check.
     *
     * @param {string}  driverId
     * @param {Date}    start
     * @param {Date}    end
     * @param {string}  [excludeOrderId]
     * @returns {Array<Model>}  Conflicting order records
     */
    checkConflicts(driverId, start, end, excludeOrderId = null) {
        return this.store.peekAll('order').filter((o) => {
            if (o.id === excludeOrderId) return false;
            if (o.driver_assigned_uuid !== driverId) return false;
            if (isNone(o.scheduled_at)) return false;
            const oStart = new Date(o.scheduled_at);
            const oEnd = addMinutes(oStart, o.estimated_duration ?? 60);
            return areIntervalsOverlapping({ start, end }, { start: oStart, end: oEnd }, { inclusive: false });
        });
    }

    // -------------------------------------------------------------------------
    // Best-Fit Insertion
    // -------------------------------------------------------------------------

    /**
     * Calculates the optimal `scheduled_at` time for `order` when dropped
     * onto a driver's row without a specific time target.
     *
     * Primary strategy: POST to the Fleetbase routing API for a travel-time-
     * aware recommendation.
     * Fallback: Append the order after the last existing order for that driver.
     *
     * @param {string}  driverId
     * @param {Model}   order
     * @returns {Promise<Date>}
     */
    async findBestFit(driverId, order) {
        // Try the server-side routing API first
        try {
            const response = await this.fetch.post('orders/best-fit', {
                driver_id: driverId,
                order_id: order.id,
            });
            if (response?.recommended_scheduled_at) {
                return new Date(response.recommended_scheduled_at);
            }
        } catch {
            // Non-critical — fall through to heuristic
        }

        // Heuristic fallback: find the end time of the last order for this driver
        const driverOrders = this.store
            .peekAll('order')
            .filter((o) => o.driver_assigned_uuid === driverId && !isNone(o.scheduled_at))
            .sortBy('scheduled_at');

        if (driverOrders.length === 0) {
            // No existing orders — use now as the starting point
            return new Date();
        }

        const lastOrder = driverOrders[driverOrders.length - 1];
        return addMinutes(new Date(lastOrder.scheduled_at), lastOrder.estimated_duration ?? 60);
    }

    // -------------------------------------------------------------------------
    // Single Order Assignment
    // -------------------------------------------------------------------------

    /**
     * Assigns a single order to a driver at a given datetime.
     *
     * Flow:
     *   1. Conflict check (unless `options.skipConflictCheck` is true)
     *   2. Persist via a targeted PATCH to the schedule endpoint
     *      (does NOT trigger dispatch or change the order status)
     *   3. Reflect the change in the local Ember Data store
     *   4. On failure: notify and return error
     *
     * @param {Model}   order
     * @param {string}  driverId
     * @param {Date}    scheduledAt
     * @param {Object}  [options]
     * @param {boolean} [options.skipConflictCheck=false]
     * @returns {Promise<{hasConflict: boolean, conflicts?: Array, error?: Error}>}
     */
    async assignOrder(order, driverId, scheduledAt, options = {}) {
        const duration = order.estimated_duration ?? 60;
        const end = addMinutes(scheduledAt, duration);
        const previousDate = order.scheduled_at;
        const previousDriverId = order.driver_assigned_uuid;

        if (!options.skipConflictCheck && driverId) {
            const conflicts = this.checkConflicts(driverId, scheduledAt, end, order.id);
            if (conflicts.length > 0) {
                return { hasConflict: true, conflicts };
            }
        }

        const payload = {
            order: order.id,
            scheduled_at: scheduledAt instanceof Date ? scheduledAt.toISOString() : scheduledAt,
        };
        if (driverId) {
            payload.driver_id = driverId;
        }

        try {
            // Use the dedicated schedule endpoint so the backend only sets
            // scheduled_at / driver_assigned_uuid without triggering dispatch.
            await this.fetch.patch('orders/schedule', payload);

            // Reflect the change locally without triggering another save
            order.set('scheduled_at', scheduledAt);
            if (driverId) {
                order.set('driver_assigned_uuid', driverId);
            }

            this._pushHistory({
                orderId: order.id,
                previousDate,
                newDate: scheduledAt,
                previousDriverId,
                newDriverId: driverId,
            });
            return { hasConflict: false };
        } catch (error) {
            this.notifications.serverError(error);
            return { hasConflict: false, error };
        }
    }

    /**
     * Removes the schedule assignment from an order (sets `scheduled_at` to null).
     *
     * @param {Model} order
     */
    async unscheduleOrder(order) {
        const previousDate = order.scheduled_at;
        const previousDriverId = order.driver_assigned_uuid;
        try {
            await this.fetch.patch('orders/schedule', { order: order.id, scheduled_at: null });
            order.set('scheduled_at', null);
            this._pushHistory({
                orderId: order.id,
                previousDate,
                newDate: null,
                previousDriverId,
                newDriverId: null,
            });
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    // -------------------------------------------------------------------------
    // Bulk Assignment
    // -------------------------------------------------------------------------

    /**
     * Assigns multiple orders to a driver on a given date in a single operation.
     *
     * Attempts a batch API call first. On failure, falls back to sequential saves.
     *
     * @param {Array<Model>} orders
     * @param {string}       driverId
     * @param {Date}         date
     */
    async bulkAssign(orders, driverId, date) {
        const orderIds = orders.map((o) => o.id);
        try {
            await this.fetch.post('orders/bulk-schedule', {
                order_ids: orderIds,
                driver_id: driverId,
                scheduled_at: date instanceof Date ? date.toISOString() : date,
            });
        } catch {
            // Fallback: sequential saves
            for (const order of orders) {
                try {
                    order.set('scheduled_at', date);
                    order.set('driver_assigned_uuid', driverId);
                    await order.save();
                } catch (error) {
                    order.rollbackAttributes();
                    this.notifications.serverError(error);
                }
            }
            return;
        }

        // Reflect the bulk change in the local store
        orders.forEach((order) => {
            order.set('scheduled_at', date);
            order.set('driver_assigned_uuid', driverId);
        });
    }
}

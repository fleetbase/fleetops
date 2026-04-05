import Component from '@glimmer/component';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

/**
 * Modals::SchedulingConflict
 *
 * Surfaces a scheduling conflict when an order is dragged onto a driver row
 * that already has an overlapping event. Provides three resolution paths:
 *
 *   1. Cancel         — close the modal, leave the order unscheduled
 *   2. Auto-Adjust    — call @options.autoAdjust to find the next available slot
 *   3. Assign Anyway  — call @options.assignAnyway to force the assignment
 *
 * Options accepted (set via modalsManager.show):
 *   - order        {Order}    The order being scheduled
 *   - driver       {Driver}   The target driver
 *   - conflicts    {Array}    Existing events that overlap the requested slot
 *   - scheduledAt  {Date}     The originally requested start time
 *   - autoAdjust   {Function} (modalsManager, done) => Promise
 *   - assignAnyway {Function} (modalsManager, done) => Promise
 */
export default class ModalsSchedulingConflictComponent extends Component {
    @service modalsManager;

    /**
     * Trigger the auto-adjust resolution path.
     * Calls @options.autoAdjust(modalsManager, done) so the controller can
     * start loading, find the best-fit slot, assign, and close the modal.
     */
    @action
    autoAdjust() {
        const { autoAdjust } = this.args.options;
        if (typeof autoAdjust === 'function') {
            const done = () => this.modalsManager.done();
            autoAdjust(this.modalsManager, done);
        }
    }

    /**
     * Trigger the assign-anyway resolution path.
     * Calls @options.assignAnyway(modalsManager, done) so the controller can
     * skip the conflict check and force the assignment.
     */
    @action
    assignAnyway() {
        const { assignAnyway } = this.args.options;
        if (typeof assignAnyway === 'function') {
            const done = () => this.modalsManager.done();
            assignAnyway(this.modalsManager, done);
        }
    }
}

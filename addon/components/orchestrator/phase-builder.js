import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

/**
 * Orchestrator::PhaseBuilder
 *
 * Allows dispatchers to compose a multi-phase orchestration run.
 * Each phase is a discrete step: a mode (Assign Vehicles, Assign Drivers,
 * Optimize Routes) with its own constraint options and resource/order filters.
 *
 * Phases are executed in sequence. The dispatcher can run all phases at once
 * or step through them manually.
 *
 * @arg phases              - Array of phase objects (managed externally)
 * @arg availableEngines    - Array of { id, name } engine options
 * @arg onPhasesChange      - Action(phases) — called when phases array changes
 * @arg onRunPhases         - Action(phases) — called when "Run" is clicked
 */
export default class OrchestratorPhaseBuilderComponent extends Component {
    @service intl;

    /** Index of the phase currently being edited in the editor panel. */
    @tracked editingIndex = null;

    /** Draft copy of the phase being edited. */
    @tracked draftPhase = null;

    get modeOptions() {
        return [
            { value: 'assign_vehicles', label: this.intl.t('orchestrator.mode-assign-vehicles') },
            { value: 'assign_drivers', label: this.intl.t('orchestrator.mode-assign-drivers') },
            { value: 'optimize_routes', label: this.intl.t('orchestrator.mode-optimize-routes') },
            { value: 'allocate', label: this.intl.t('orchestrator.mode-allocate') },
        ];
    }

    get orderStatusOptions() {
        return [
            { value: 'created', label: this.intl.t('orchestrator.status-created') },
            { value: 'dispatched', label: this.intl.t('orchestrator.status-dispatched') },
            { value: 'started', label: this.intl.t('orchestrator.status-started') },
        ];
    }

    _defaultPhase(mode = 'assign_vehicles') {
        return {
            id: crypto.randomUUID(),
            mode,
            label: this.intl.t(`orchestrator.mode-${mode.replace(/_/g, '-')}`),
            engine: 'greedy',
            orderStatuses: ['created'],
            balanceWorkload: false,
            respectSkills: true,
            respectCapacity: true,
            returnToDepot: false,
            autoCommit: false,
        };
    }

    @action addPhase() {
        const newPhase = this._defaultPhase('assign_vehicles');
        const phases = [...(this.args.phases ?? []), newPhase];
        this.args.onPhasesChange?.(phases);
        this.editingIndex = phases.length - 1;
        this.draftPhase = { ...newPhase };
    }

    @action removePhase(index) {
        const phases = (this.args.phases ?? []).filter((_, i) => i !== index);
        this.args.onPhasesChange?.(phases);
        if (this.editingIndex === index) {
            this.editingIndex = null;
            this.draftPhase = null;
        } else if (this.editingIndex > index) {
            this.editingIndex = this.editingIndex - 1;
        }
    }

    @action movePhaseUp(index) {
        if (index === 0) return;
        const phases = [...(this.args.phases ?? [])];
        [phases[index - 1], phases[index]] = [phases[index], phases[index - 1]];
        this.args.onPhasesChange?.(phases);
        if (this.editingIndex === index) this.editingIndex = index - 1;
        else if (this.editingIndex === index - 1) this.editingIndex = index;
    }

    @action movePhaseDown(index) {
        const phases = this.args.phases ?? [];
        if (index >= phases.length - 1) return;
        const updated = [...phases];
        [updated[index], updated[index + 1]] = [updated[index + 1], updated[index]];
        this.args.onPhasesChange?.(updated);
        if (this.editingIndex === index) this.editingIndex = index + 1;
        else if (this.editingIndex === index + 1) this.editingIndex = index;
    }

    @action editPhase(index) {
        this.editingIndex = index;
        this.draftPhase = { ...(this.args.phases ?? [])[index] };
    }

    @action closePhasEditor() {
        this.editingIndex = null;
        this.draftPhase = null;
    }

    @action saveDraftPhase() {
        if (this.editingIndex === null || !this.draftPhase) return;
        const phases = [...(this.args.phases ?? [])];
        phases[this.editingIndex] = { ...this.draftPhase };
        this.args.onPhasesChange?.(phases);
        this.editingIndex = null;
        this.draftPhase = null;
    }

    @action setDraftMode(mode) {
        this.draftPhase = {
            ...this.draftPhase,
            mode,
            label: this.intl.t(`orchestrator.mode-${mode.replace(/_/g, '-')}`),
        };
    }

    @action setDraftEngine(engine) {
        this.draftPhase = { ...this.draftPhase, engine };
    }

    @action toggleDraftOrderStatus(status) {
        const current = this.draftPhase.orderStatuses ?? [];
        const updated = current.includes(status) ? current.filter((s) => s !== status) : [...current, status];
        this.draftPhase = { ...this.draftPhase, orderStatuses: updated };
    }

    @action setDraftOption(key, value) {
        this.draftPhase = { ...this.draftPhase, [key]: value };
    }

    @action setDraftLabel(event) {
        this.draftPhase = { ...this.draftPhase, label: event.target.value };
    }

    get isEditing() {
        return this.editingIndex !== null && this.draftPhase !== null;
    }

    modeLabel(mode) {
        return this.modeOptions.find((m) => m.value === mode)?.label ?? mode;
    }

    modeIcon(mode) {
        const icons = {
            assign_vehicles: 'truck',
            assign_drivers: 'user',
            optimize_routes: 'route',
            allocate: 'bolt',
        };
        return icons[mode] ?? 'cog';
    }
}

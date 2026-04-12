import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';

/**
 * Orchestrator Import Modal
 *
 * Three-step import flow:
 *   1. Upload  — drag-and-drop or browse for CSV / Excel file
 *   2. Map     — map file columns to Fleetbase order fields
 *   3. Preview — validate rows, review errors, confirm import
 */

/** Required and optional target fields the user can map to. */
const TARGET_FIELDS = [
    { key: 'dropoff_address',  label: 'Dropoff Address',   required: true  },
    { key: 'pickup_address',   label: 'Pickup Address',    required: false },
    { key: 'scheduled_at',     label: 'Scheduled At',      required: false },
    { key: 'customer_name',    label: 'Customer Name',     required: false },
    { key: 'customer_phone',   label: 'Customer Phone',    required: false },
    { key: 'notes',            label: 'Notes / Reference', required: false },
    { key: 'weight_kg',        label: 'Weight (kg)',       required: false },
    { key: 'volume_m3',        label: 'Volume (m³)',       required: false },
    { key: 'required_skills',  label: 'Required Skills',   required: false },
    { key: 'priority',         label: 'Priority (0-100)',  required: false },
    { key: 'time_window_start',label: 'Time Window Start', required: false },
    { key: 'time_window_end',  label: 'Time Window End',   required: false },
    { key: 'service_time_min', label: 'Service Time (min)',required: false },
];

export default class OrchestratorImportComponent extends Component {
    @service fetch;
    @service notifications;
    @service intl;

    // ── Step state ────────────────────────────────────────────────────────────

    @tracked step = 'upload'; // 'upload' | 'map' | 'preview'

    // ── Upload step ───────────────────────────────────────────────────────────

    @tracked selectedFile     = null;
    @tracked isDraggingFile   = false;
    @tracked parseError       = null;
    @tracked fileColumns      = [];
    @tracked rawRows          = [];

    // ── Mapping step ──────────────────────────────────────────────────────────

    /** Array of { key, label, required, mappedColumn } objects. */
    @tracked columnMappings = TARGET_FIELDS.map((f) => ({ ...f, mappedColumn: null }));

    // ── Preview step ──────────────────────────────────────────────────────────

    @tracked mappedRows       = [];
    @tracked validationErrors = [];

    // ── Upload handlers ───────────────────────────────────────────────────────

    /**
     * Called by ember-file-upload's FileDropzone / UploadButton when a file is
     * added to the queue. The `file` object is an ember-file-upload UploadFile
     * wrapper — we extract the underlying native File from `file.file`.
     */
    @action onFileQueued(file) {
        // ember-file-upload wraps the native File in an UploadFile object.
        // We only need the native File for our client-side CSV parser.
        const nativeFile = file?.file ?? file;
        if (nativeFile instanceof File) {
            this._setFile(nativeFile);
        }
        // Remove from the upload queue — we handle the file ourselves (no server upload).
        file?.queue?.remove(file);
    }

    @action onFileDragOver(event) {
        event.preventDefault();
        this.isDraggingFile = true;
    }

    @action onFileDragLeave() {
        this.isDraggingFile = false;
    }

    @action onFileDrop(event) {
        event.preventDefault();
        this.isDraggingFile = false;
        const file = event.dataTransfer?.files?.[0];
        if (file) this._setFile(file);
    }

    @action onFileSelected(event) {
        const file = event.target?.files?.[0];
        if (file) this._setFile(file);
    }

    @action clearFile() {
        this.selectedFile  = null;
        this.parseError    = null;
        this.fileColumns   = [];
        this.rawRows       = [];
    }

    _setFile(file) {
        const allowed = ['text/csv', 'application/vnd.ms-excel',
                         'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        const ext     = file.name.split('.').pop()?.toLowerCase();
        if (!allowed.includes(file.type) && !['csv', 'xlsx', 'xls'].includes(ext)) {
            this.parseError = this.intl.t('orchestrator.invalid-file-type');
            return;
        }
        this.selectedFile = file;
        this.parseError   = null;
    }

    get formattedFileSize() {
        if (!this.selectedFile) return '';
        const bytes = this.selectedFile.size;
        if (bytes < 1024) return `${bytes} B`;
        if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
        return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    }

    // ── Parse file ────────────────────────────────────────────────────────────

    @task *parseFile() {
        this.parseError = null;
        try {
            const text = yield this._readFileAsText(this.selectedFile);
            const { columns, rows } = this._parseCsv(text);
            this.fileColumns = columns;
            this.rawRows     = rows;

            // Auto-map columns by fuzzy name match
            this._autoMapColumns(columns);

            this.step = 'map';
        } catch (error) {
            this.parseError = error.message ?? this.intl.t('orchestrator.parse-error');
        }
    }

    _readFileAsText(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload  = (e) => resolve(e.target.result);
            reader.onerror = () => reject(new Error(this.intl.t('orchestrator.read-error')));
            reader.readAsText(file);
        });
    }

    _parseCsv(text) {
        const lines   = text.split(/\r?\n/).filter((l) => l.trim());
        if (!lines.length) throw new Error(this.intl.t('orchestrator.empty-file'));

        const columns = lines[0].split(',').map((c) => c.trim().replace(/^"|"$/g, ''));
        const rows    = lines.slice(1, 4).map((line) => {
            const vals = line.split(',').map((v) => v.trim().replace(/^"|"$/g, ''));
            return Object.fromEntries(columns.map((col, i) => [col, vals[i] ?? '']));
        });
        return { columns, rows };
    }

    _autoMapColumns(columns) {
        const ALIASES = {
            dropoff_address:   ['dropoff', 'delivery address', 'destination', 'to address', 'drop'],
            pickup_address:    ['pickup', 'collection address', 'from address', 'origin', 'pick'],
            scheduled_at:      ['scheduled', 'delivery date', 'date', 'delivery time', 'time'],
            customer_name:     ['customer', 'name', 'recipient', 'consignee'],
            customer_phone:    ['phone', 'mobile', 'contact', 'tel'],
            notes:             ['notes', 'reference', 'ref', 'instructions', 'remarks'],
            weight_kg:         ['weight', 'kg', 'weight kg', 'gross weight'],
            volume_m3:         ['volume', 'm3', 'cbm', 'cubic'],
            required_skills:   ['skills', 'requirements', 'required skills'],
            priority:          ['priority', 'urgency'],
            time_window_start: ['time window start', 'tw start', 'earliest'],
            time_window_end:   ['time window end', 'tw end', 'latest'],
            service_time_min:  ['service time', 'dwell time', 'stop time'],
        };

        this.columnMappings = this.columnMappings.map((mapping) => {
            const aliases = ALIASES[mapping.key] ?? [];
            const matched = columns.find((col) =>
                aliases.some((alias) => col.toLowerCase().includes(alias))
            );
            return { ...mapping, mappedColumn: matched ?? null };
        });
    }

    // ── Column mapping helpers ────────────────────────────────────────────────

    get fileColumnOptions() {
        return [
            { label: this.intl.t('orchestrator.skip-column'), value: null },
            ...this.fileColumns.map((col) => ({ label: col, value: col })),
        ];
    }

    get previewRows() {
        return this.rawRows;
    }

    @action setColumnMapping(mapping, value) {
        this.columnMappings = this.columnMappings.map((m) =>
            m.key === mapping.key ? { ...m, mappedColumn: value } : m
        );
    }

    get mappingIsValid() {
        return this.columnMappings
            .filter((m) => m.required)
            .every((m) => m.mappedColumn);
    }

    // ── Build preview ─────────────────────────────────────────────────────────

    @task *buildPreview() {
        // For the preview we re-read the full file and map all rows
        try {
            const text = yield this._readFileAsText(this.selectedFile);
            const { columns, rows: allRows } = this._parseFullCsv(text);

            const mapped = allRows.map((row, idx) => {
                const mapped = { _rowIndex: idx + 2 };
                for (const m of this.columnMappings) {
                    if (m.mappedColumn) {
                        mapped[m.key] = row[m.mappedColumn] ?? '';
                    }
                }
                // Validate required fields
                if (!mapped.dropoff_address) {
                    mapped._error = this.intl.t('orchestrator.missing-dropoff');
                }
                return mapped;
            });

            this.mappedRows       = mapped;
            this.validationErrors = mapped.filter((r) => r._error);
            this.step             = 'preview';
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    _parseFullCsv(text) {
        const lines   = text.split(/\r?\n/).filter((l) => l.trim());
        const columns = lines[0].split(',').map((c) => c.trim().replace(/^"|"$/g, ''));
        const rows    = lines.slice(1).map((line) => {
            const vals = line.split(',').map((v) => v.trim().replace(/^"|"$/g, ''));
            return Object.fromEntries(columns.map((col, i) => [col, vals[i] ?? '']));
        });
        return { columns, rows };
    }

    // ── Submit import ─────────────────────────────────────────────────────────

    @task *submitImport() {
        const validRows = this.mappedRows.filter((r) => !r._error);
        if (!validRows.length) {
            this.notifications.warning(this.intl.t('orchestrator.no-valid-rows'));
            return;
        }

        try {
            yield this.fetch.post('fleet-ops/orchestrator/import-orders', {
                rows:    validRows,
                options: { mark_imported: true },
            });

            this.notifications.success(
                this.intl.t('orchestrator.import-success', { count: validRows.length })
            );

            // Notify the workbench to reload
            if (typeof this.args.options?.onImportComplete === 'function') {
                this.args.options.onImportComplete();
            }

            // Close the modal
            if (typeof this.args.onConfirm === 'function') {
                this.args.onConfirm();
            }
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    // ── Navigation ────────────────────────────────────────────────────────────

    @action goToUpload() {
        this.step = 'upload';
    }

    @action goToMapping() {
        this.step = 'map';
    }

    // ── Template download ─────────────────────────────────────────────────────

    @action downloadTemplate() {
        const header = TARGET_FIELDS.map((f) => f.label).join(',');
        const sample = [
            '"123 Main St, London"',
            '"456 High St, London"',
            '"2026-04-10 09:00"',
            '"John Doe"',
            '"+44 7700 900000"',
            '"REF-001"',
            '"5.2"',
            '"0.04"',
            '"refrigerated"',
            '"50"',
            '"08:00"',
            '"12:00"',
            '"10"',
        ].join(',');

        const csv  = `${header}\n${sample}\n`;
        const blob = new Blob([csv], { type: 'text/csv' });
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href     = url;
        a.download = 'orchestrator-import-template.csv';
        a.click();
        URL.revokeObjectURL(url);
    }
}

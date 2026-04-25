import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import * as XLSX from 'xlsx';

/**
 * Orchestrator Import Modal
 *
 * Three-step import flow:
 *   1. Upload  — drag-and-drop or browse for CSV / Excel file
 *   2. Map     — map file columns to Fleetbase order fields (grouped by section)
 *   3. Preview — validate rows, review errors, confirm import
 *
 * The import template supports two order types:
 *   - pickup_dropoff  (default) — a single pickup and a single dropoff address
 *   - multi_waypoint            — multiple waypoints identified by a shared order_ref
 *
 * Entity resolution (customer, facilitator, vehicle, driver) is performed
 * server-side: existing records are found by email/phone/plate before creating new ones.
 */

// ── Field sections ─────────────────────────────────────────────────────────────
const FIELD_SECTIONS = [
    {
        key: 'order',
        label: 'Order Details',
        fields: [
            { key: 'order_type', label: 'Order Type', required: false, hint: 'pickup_dropoff or multi_waypoint' },
            { key: 'order_ref', label: 'Order Reference', required: false, hint: 'Groups rows into one multi-waypoint order' },
            { key: 'internal_id', label: 'Internal ID', required: false },
            { key: 'type', label: 'Order Config Slug', required: false, hint: 'e.g. default, delivery, transfer' },
            { key: 'status', label: 'Status', required: false },
            { key: 'scheduled_at', label: 'Scheduled At', required: false, hint: 'YYYY-MM-DD HH:mm' },
            { key: 'notes', label: 'Notes', required: false },
            { key: 'priority', label: 'Priority (0-100)', required: false },
            { key: 'time_window_start', label: 'Time Window Start', required: false, hint: 'HH:mm' },
            { key: 'time_window_end', label: 'Time Window End', required: false, hint: 'HH:mm' },
            { key: 'service_time_min', label: 'Service Time (min)', required: false },
            { key: 'required_skills', label: 'Required Skills', required: false, hint: 'Comma-separated' },
        ],
    },
    {
        key: 'pickup',
        label: 'Pickup / Origin',
        fields: [
            { key: 'pickup_name', label: 'Pickup Name', required: false },
            { key: 'pickup_street1', label: 'Pickup Street 1', required: false },
            { key: 'pickup_street2', label: 'Pickup Street 2', required: false },
            { key: 'pickup_city', label: 'Pickup City', required: false },
            { key: 'pickup_state', label: 'Pickup State', required: false },
            { key: 'pickup_postal_code', label: 'Pickup Postal Code', required: false },
            { key: 'pickup_country', label: 'Pickup Country', required: false, hint: 'ISO 3166-1 alpha-2' },
            { key: 'pickup_phone', label: 'Pickup Phone', required: false },
            { key: 'pickup_lat', label: 'Pickup Latitude', required: false },
            { key: 'pickup_lng', label: 'Pickup Longitude', required: false },
        ],
    },
    {
        key: 'dropoff',
        label: 'Dropoff / Destination',
        fields: [
            { key: 'dropoff_name', label: 'Dropoff Name', required: false },
            { key: 'dropoff_street1', label: 'Dropoff Street 1', required: true },
            { key: 'dropoff_street2', label: 'Dropoff Street 2', required: false },
            { key: 'dropoff_city', label: 'Dropoff City', required: false },
            { key: 'dropoff_state', label: 'Dropoff State', required: false },
            { key: 'dropoff_postal_code', label: 'Dropoff Postal Code', required: false },
            { key: 'dropoff_country', label: 'Dropoff Country', required: false, hint: 'ISO 3166-1 alpha-2' },
            { key: 'dropoff_phone', label: 'Dropoff Phone', required: false },
            { key: 'dropoff_lat', label: 'Dropoff Latitude', required: false },
            { key: 'dropoff_lng', label: 'Dropoff Longitude', required: false },
        ],
    },
    {
        key: 'payload',
        label: 'Payload',
        fields: [
            { key: 'weight_kg', label: 'Weight (kg)', required: false },
            { key: 'volume_m3', label: 'Volume (m3)', required: false },
            { key: 'parcels', label: 'Parcels', required: false },
            { key: 'cod_amount', label: 'COD Amount', required: false },
            { key: 'cod_currency', label: 'COD Currency', required: false, hint: 'ISO 4217, e.g. USD' },
        ],
    },
    {
        key: 'customer',
        label: 'Customer',
        fields: [
            { key: 'customer_name', label: 'Customer Name', required: false },
            { key: 'customer_email', label: 'Customer Email', required: false, hint: 'Used to look up or create a contact' },
            { key: 'customer_phone', label: 'Customer Phone', required: false },
            { key: 'customer_type', label: 'Customer Type', required: false, hint: 'contact (default) or vendor' },
        ],
    },
    {
        key: 'facilitator',
        label: 'Facilitator',
        fields: [
            { key: 'facilitator_name', label: 'Facilitator Name', required: false },
            { key: 'facilitator_email', label: 'Facilitator Email', required: false, hint: 'Used to look up or create a vendor' },
            { key: 'facilitator_phone', label: 'Facilitator Phone', required: false },
            { key: 'facilitator_type', label: 'Facilitator Type', required: false, hint: 'contact or vendor (default)' },
        ],
    },
    {
        key: 'vehicle',
        label: 'Vehicle',
        fields: [{ key: 'vehicle_plate', label: 'Vehicle Plate', required: false, hint: 'Looks up vehicle by plate number' }],
    },
    {
        key: 'driver',
        label: 'Driver',
        fields: [
            { key: 'driver_name', label: 'Driver Name', required: false },
            { key: 'driver_phone', label: 'Driver Phone', required: false, hint: 'Used to look up driver' },
            { key: 'driver_email', label: 'Driver Email', required: false },
        ],
    },
    {
        key: 'entity',
        label: 'Entity (Item / Parcel / Passenger)',
        fields: [
            { key: 'entity_name', label: 'Entity Name', required: false, hint: 'Name of the item, parcel or passenger' },
            { key: 'entity_type', label: 'Entity Type', required: false, hint: 'e.g. parcel, passenger, pallet' },
            { key: 'entity_description', label: 'Entity Description', required: false },
            { key: 'entity_sku', label: 'Entity SKU', required: false },
            { key: 'entity_barcode', label: 'Entity Barcode', required: false },
            { key: 'entity_internal_id', label: 'Entity Internal ID', required: false },
            { key: 'entity_declared_value', label: 'Declared Value', required: false },
            { key: 'entity_currency', label: 'Entity Currency', required: false, hint: 'ISO 4217, e.g. USD' },
            { key: 'entity_price', label: 'Entity Price', required: false },
            { key: 'entity_sale_price', label: 'Entity Sale Price', required: false },
            { key: 'entity_weight', label: 'Entity Weight', required: false },
            { key: 'entity_weight_unit', label: 'Weight Unit', required: false, hint: 'kg, lb, g, oz' },
            { key: 'entity_length', label: 'Entity Length', required: false },
            { key: 'entity_width', label: 'Entity Width', required: false },
            { key: 'entity_height', label: 'Entity Height', required: false },
            { key: 'entity_dimensions_unit', label: 'Dimensions Unit', required: false, hint: 'cm, m, in, ft' },
            {
                key: 'entity_destination',
                label: 'Entity Destination',
                required: false,
                hint: 'pickup, dropoff, or waypoint index (0,1,2…). Add extra rows with the same order_ref to attach multiple entities to one order.',
            },
        ],
    },
];

/** Flat list of all target fields (used for column-mapping state). */
const TARGET_FIELDS = FIELD_SECTIONS.flatMap((s) => s.fields);

/** Auto-mapping aliases: key => array of substrings to match against column names. */
const ALIASES = {
    order_type: ['order type', 'order_type', 'type of order'],
    order_ref: ['order ref', 'order_ref', 'ref', 'group', 'batch'],
    internal_id: ['internal id', 'internal_id', 'internal ref'],
    type: ['order config', 'config slug', 'order slug'],
    status: ['status'],
    scheduled_at: ['scheduled', 'delivery date', 'date', 'delivery time'],
    notes: ['notes', 'instructions', 'remarks', 'reference'],
    priority: ['priority', 'urgency'],
    time_window_start: ['time window start', 'tw start', 'earliest', 'from time'],
    time_window_end: ['time window end', 'tw end', 'latest', 'to time'],
    service_time_min: ['service time', 'dwell time', 'stop time'],
    required_skills: ['skills', 'requirements', 'required skills'],
    pickup_name: ['pickup name', 'origin name', 'from name'],
    pickup_street1: ['pickup street', 'pickup address', 'from address', 'origin address', 'collection address', 'pick up'],
    pickup_street2: ['pickup street 2', 'pickup address 2'],
    pickup_city: ['pickup city', 'origin city', 'from city'],
    pickup_state: ['pickup state', 'origin state', 'from state'],
    pickup_postal_code: ['pickup postal', 'pickup zip', 'origin postal'],
    pickup_country: ['pickup country', 'origin country'],
    pickup_phone: ['pickup phone', 'origin phone'],
    pickup_lat: ['pickup lat', 'origin lat', 'from lat'],
    pickup_lng: ['pickup lng', 'pickup lon', 'origin lng', 'from lng'],

    dropoff_name: ['dropoff name', 'destination name', 'to name', 'delivery name'],
    dropoff_street1: ['dropoff street', 'dropoff address', 'to address', 'destination address', 'delivery address', 'drop off'],
    dropoff_street2: ['dropoff street 2', 'dropoff address 2'],
    dropoff_city: ['dropoff city', 'destination city', 'to city'],
    dropoff_state: ['dropoff state', 'destination state'],
    dropoff_postal_code: ['dropoff postal', 'dropoff zip', 'destination postal'],
    dropoff_country: ['dropoff country', 'destination country'],
    dropoff_phone: ['dropoff phone', 'recipient phone'],
    dropoff_lat: ['dropoff lat', 'destination lat', 'to lat'],
    dropoff_lng: ['dropoff lng', 'dropoff lon', 'destination lng', 'to lng'],

    weight_kg: ['weight', 'kg', 'weight kg', 'gross weight'],
    volume_m3: ['volume', 'm3', 'cbm', 'cubic'],
    parcels: ['parcels', 'packages', 'pieces'],
    cod_amount: ['cod amount', 'cash on delivery', 'cod'],
    cod_currency: ['cod currency', 'currency'],
    customer_name: ['customer name', 'customer', 'recipient', 'consignee'],
    customer_email: ['customer email', 'recipient email'],
    customer_phone: ['customer phone', 'recipient phone', 'mobile'],
    customer_type: ['customer type'],
    facilitator_name: ['facilitator name', 'facilitator', 'vendor name', 'partner'],
    facilitator_email: ['facilitator email', 'vendor email'],
    facilitator_phone: ['facilitator phone', 'vendor phone'],
    facilitator_type: ['facilitator type', 'vendor type'],
    vehicle_plate: ['vehicle plate', 'plate number', 'plate', 'registration'],
    driver_name: ['driver name', 'driver'],
    driver_phone: ['driver phone', 'driver mobile'],
    driver_email: ['driver email'],
    entity_name: ['entity name', 'item name', 'parcel name', 'package name', 'passenger name', 'item', 'parcel'],
    entity_type: ['entity type', 'item type', 'parcel type', 'package type'],
    entity_description: ['entity description', 'item description', 'parcel description', 'description'],
    entity_sku: ['sku', 'entity sku', 'item sku', 'product code'],
    entity_barcode: ['barcode', 'entity barcode', 'item barcode'],
    entity_internal_id: ['entity internal id', 'item id', 'parcel id'],
    entity_declared_value: ['declared value', 'entity value', 'item value'],
    entity_currency: ['entity currency', 'item currency'],
    entity_price: ['entity price', 'item price', 'price'],
    entity_sale_price: ['sale price', 'entity sale price'],
    entity_weight: ['entity weight', 'item weight', 'parcel weight'],
    entity_weight_unit: ['entity weight unit', 'weight unit'],
    entity_length: ['entity length', 'item length', 'length'],
    entity_width: ['entity width', 'item width', 'width'],
    entity_height: ['entity height', 'item height', 'height'],
    entity_dimensions_unit: ['dimensions unit', 'dim unit', 'entity dim unit'],
    entity_destination: ['entity destination', 'item destination', 'deliver to', 'waypoint'],
};

export default class OrchestratorImportComponent extends Component {
    @service fetch;
    @service notifications;
    @service modalsManager;
    @service intl;

    // ── Step state ────────────────────────────────────────────────────────────
    @tracked step = 'upload'; // 'upload' | 'map' | 'preview'

    // ── Upload step ───────────────────────────────────────────────────────────
    @tracked selectedFile = null;
    @tracked isDraggingFile = false;
    @tracked parseError = null;
    @tracked fileColumns = [];
    @tracked rawRows = [];

    // ── Mapping step ──────────────────────────────────────────────────────────
    /** Array of { key, label, required, mappedColumn, hint? } objects. */
    @tracked columnMappings = TARGET_FIELDS.map((f) => ({ ...f, mappedColumn: null }));

    // ── Preview step ──────────────────────────────────────────────────────────
    @tracked mappedRows = [];
    @tracked validationErrors = [];

    // ── Sections (for grouped column-mapping UI) ──────────────────────────────
    get fieldSections() {
        return FIELD_SECTIONS.map((section) => ({
            ...section,
            fields: this.columnMappings.filter((m) => section.fields.some((f) => f.key === m.key)),
        }));
    }

    // ── Preview groups (grouped by order_ref for the preview table) ──────────
    /**
     * Collapses mappedRows into one entry per logical order (grouped by order_ref).
     * Each group carries summary fields used by the preview table.
     */
    get previewGroups() {
        const rows = this.mappedRows ?? [];
        const groups = new Map();

        rows.forEach((row) => {
            const key = row.order_ref || row._rowIndex || String(Math.random());
            if (!groups.has(key)) {
                groups.set(key, {
                    // identity
                    order_ref: row.order_ref || null,
                    internal_id: row.internal_id || null,
                    order_type: row.order_type || 'pickup_dropoff',
                    // addresses
                    pickup_street1: row.pickup_street1 || null,
                    pickup_city: row.pickup_city || null,
                    dropoff_street1: row.dropoff_street1 || null,
                    dropoff_city: row.dropoff_city || null,
                    // parties
                    customer_name: row.customer_name || null,
                    customer_email: row.customer_email || null,
                    facilitator_name: row.facilitator_name || null,
                    facilitator_email: row.facilitator_email || null,
                    vehicle_plate: row.vehicle_plate || null,
                    driver_name: row.driver_name || null,
                    driver_email: row.driver_email || null,
                    // schedule
                    scheduled_at: row.scheduled_at || null,
                    // counters
                    waypointCount: 0,
                    entityCount: 0,
                    // validation
                    hasError: false,
                    errorMessage: null,
                });
            }
            const g = groups.get(key);

            // Count waypoint stops (rows with a dropoff address for multi_waypoint)
            if (row.order_type === 'multi_waypoint' && row.dropoff_street1) {
                g.waypointCount += 1;
            }

            // Count entities (rows with entity_name)
            if (row.entity_name) {
                g.entityCount += 1;
            }

            // Propagate errors
            if (row._error) {
                g.hasError = true;
                g.errorMessage = row._error;
            }

            // Fill in missing address/party fields from later rows in the group
            if (!g.pickup_street1 && row.pickup_street1) g.pickup_street1 = row.pickup_street1;
            if (!g.pickup_city && row.pickup_city) g.pickup_city = row.pickup_city;
            if (!g.dropoff_street1 && row.dropoff_street1) g.dropoff_street1 = row.dropoff_street1;
            if (!g.dropoff_city && row.dropoff_city) g.dropoff_city = row.dropoff_city;
            if (!g.customer_name && row.customer_name) g.customer_name = row.customer_name;
            if (!g.customer_email && row.customer_email) g.customer_email = row.customer_email;
            if (!g.facilitator_name && row.facilitator_name) g.facilitator_name = row.facilitator_name;
            if (!g.facilitator_email && row.facilitator_email) g.facilitator_email = row.facilitator_email;
            if (!g.vehicle_plate && row.vehicle_plate) g.vehicle_plate = row.vehicle_plate;
            if (!g.driver_name && row.driver_name) g.driver_name = row.driver_name;
            if (!g.driver_email && row.driver_email) g.driver_email = row.driver_email;
            if (!g.scheduled_at && row.scheduled_at) g.scheduled_at = row.scheduled_at;
        });

        return Array.from(groups.values());
    }

    // ── Footer action buttons (injected into modal footer via modalsManager) ──
    /**
     * Returns the correct footer button array for the current step.
     * Called whenever the step changes via _setStep().
     */
    get _footerButtons() {
        const t = (key) => this.intl.t('orchestrator.' + key);

        if (this.step === 'upload') {
            return [
                {
                    type: 'primary',
                    icon: 'arrow-right',
                    iconPosition: 'right',
                    wrapperClass: 'btn-icon-right',
                    text: t('next'),
                    disabled: !this.selectedFile,
                    isLoading: this.parseFile.isRunning,
                    perform: this.parseFile,
                },
            ];
        }

        if (this.step === 'map') {
            return [
                {
                    type: 'default',
                    icon: 'arrow-left',
                    iconPosition: 'left',
                    text: t('back'),
                    onClick: () => this._setStep('upload'),
                },
                {
                    type: 'primary',
                    icon: 'arrow-right',
                    iconPosition: 'right',
                    wrapperClass: 'btn-icon-right',
                    text: t('next'),
                    disabled: !this.mappingIsValid,
                    isLoading: this.buildPreview.isRunning,
                    perform: this.buildPreview,
                },
            ];
        }

        if (this.step === 'preview') {
            const isRunning = this.submitImport.isRunning;
            return [
                {
                    type: 'default',
                    icon: 'arrow-left',
                    iconPosition: 'left',
                    text: t('back'),
                    disabled: isRunning,
                    onClick: () => this._setStep('map'),
                },
                {
                    type: 'success',
                    icon: isRunning ? 'spinner' : 'file-import',
                    text: isRunning ? t('importing') : t('import-confirm'),
                    disabled: isRunning,
                    isLoading: isRunning,
                    perform: this.submitImport,
                },
            ];
        }

        return [];
    }

    /** Update the modal footer buttons whenever the step changes. */
    _syncFooterButtons() {
        this.modalsManager.setOption('actionButtons', this._footerButtons);
    }

    /** Change step and sync footer buttons. */
    _setStep(step) {
        this.step = step;
        this._syncFooterButtons();
    }

    // ── Upload handlers ───────────────────────────────────────────────────────
    /**
     * Called by ember-file-upload's FileDropzone / UploadButton when a file is
     * added to the queue. The `file` object is an ember-file-upload UploadFile
     * wrapper — we extract the underlying native File from `file.file`.
     */
    @action onFileQueued(file) {
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
        this.selectedFile = null;
        this.parseError = null;
        this.fileColumns = [];
        this.rawRows = [];
        this._syncFooterButtons();
    }

    _setFile(file) {
        const allowed = ['text/csv', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        const ext = file.name.split('.').pop()?.toLowerCase();
        if (!allowed.includes(file.type) && !['csv', 'xlsx', 'xls'].includes(ext)) {
            this.parseError = this.intl.t('orchestrator.invalid-file-type');
            return;
        }
        this.selectedFile = file;
        this.parseError = null;
        this._syncFooterButtons();
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
            const ext = this.selectedFile.name.split('.').pop()?.toLowerCase();
            let columns, rows;
            if (ext === 'xlsx' || ext === 'xls') {
                ({ columns, rows } = yield this._parseXlsx(this.selectedFile));
            } else {
                const text = yield this._readFileAsText(this.selectedFile);
                ({ columns, rows } = this._parseCsv(text));
            }
            this.fileColumns = columns;
            this.rawRows = rows;
            this._autoMapColumns(columns);
            this._setStep('map');
        } catch (error) {
            this.parseError = error.message ?? this.intl.t('orchestrator.parse-error');
        }
    }

    _readFileAsText(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = (e) => resolve(e.target.result);
            reader.onerror = () => reject(new Error(this.intl.t('orchestrator.read-error')));
            reader.readAsText(file);
        });
    }

    _readFileAsArrayBuffer(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = (e) => resolve(e.target.result);
            reader.onerror = () => reject(new Error(this.intl.t('orchestrator.read-error')));
            reader.readAsArrayBuffer(file);
        });
    }

    async _parseXlsx(file) {
        const buffer = await this._readFileAsArrayBuffer(file);
        const workbook = XLSX.read(buffer, { type: 'array' });
        // Use the first sheet
        const sheetName = workbook.SheetNames[0];
        const sheet = workbook.Sheets[sheetName];
        // Convert to array of arrays (header + data rows)
        const aoa = XLSX.utils.sheet_to_json(sheet, { header: 1, defval: '' });
        if (!aoa.length) throw new Error(this.intl.t('orchestrator.empty-file'));
        // First row is the header — strip leading/trailing spaces and any trailing asterisks
        const rawHeaders = aoa[0];
        const columns = rawHeaders.map((h) =>
            String(h)
                .trim()
                .replace(/\s*\*$/, '')
        );
        // Take up to 3 sample data rows
        const rows = aoa.slice(1, 4).map((rowArr) => {
            return Object.fromEntries(columns.map((col, i) => [col, rowArr[i] ?? '']));
        });
        return { columns, rows };
    }

    _parseCsv(text) {
        const lines = text.split(/\r?\n/).filter((l) => l.trim());
        if (!lines.length) throw new Error(this.intl.t('orchestrator.empty-file'));
        const columns = this._parseCsvLine(lines[0]);
        const rows = lines.slice(1, 4).map((line) => {
            const vals = this._parseCsvLine(line);
            return Object.fromEntries(columns.map((col, i) => [col, vals[i] ?? '']));
        });
        return { columns, rows };
    }

    /** Parse a single CSV line, respecting quoted fields. */
    _parseCsvLine(line) {
        const result = [];
        let current = '';
        let inQuotes = false;
        for (let i = 0; i < line.length; i++) {
            const ch = line[i];
            if (ch === '"') {
                if (inQuotes && line[i + 1] === '"') {
                    current += '"';
                    i++;
                } else {
                    inQuotes = !inQuotes;
                }
            } else if (ch === ',' && !inQuotes) {
                result.push(current.trim());
                current = '';
            } else {
                current += ch;
            }
        }
        result.push(current.trim());
        return result;
    }

    _autoMapColumns(columns) {
        this.columnMappings = this.columnMappings.map((mapping) => {
            const aliases = ALIASES[mapping.key] ?? [];
            const matched = columns.find((col) => aliases.some((alias) => col.toLowerCase().includes(alias)));
            return { ...mapping, mappedColumn: matched ?? null };
        });
    }

    // ── Column mapping helpers ────────────────────────────────────────────────

    /**
     * Plain string options for the column-mapping selects.
     * The empty string '' is used as the "skip" sentinel — the <Select>
     * component renders it as the placeholder / skip option.
     */
    get fileColumnOptions() {
        // Return plain strings; '' means "skip / not mapped"
        return ['', ...this.fileColumns];
    }

    get previewRows() {
        return this.rawRows;
    }

    /**
     * Handle a column-mapping select change.
     *
     * Called via {{on "change" (fn this.setColumnMapping fieldKey)}} on a
     * native <select> element, so the second argument is a DOM Event.
     * We extract event.target.value to get the selected column string.
     * An empty string means "skip / not mapped".
     */
    @action setColumnMapping(fieldKey, event) {
        const value = typeof event === 'string' ? event : (event?.target?.value ?? '');
        const mapped = value === '' ? null : value;
        this.columnMappings = this.columnMappings.map((m) => (m.key === fieldKey ? { ...m, mappedColumn: mapped } : m));
        // Re-sync footer so "Next" disabled state is updated
        this._syncFooterButtons();
    }

    /** Number of fields that have been mapped to a spreadsheet column. */
    get mappedCount() {
        return this.columnMappings.filter((m) => m.mappedColumn).length;
    }

    /** Total number of target fields. */
    get totalFieldCount() {
        return this.columnMappings.length;
    }

    get mappingIsValid() {
        // At minimum, dropoff_street1 must be mapped
        return this.columnMappings.filter((m) => m.required).every((m) => m.mappedColumn);
    }

    // ── Build preview ─────────────────────────────────────────────────────────
    @task *buildPreview() {
        try {
            const ext = this.selectedFile.name.split('.').pop()?.toLowerCase();
            let allRows;

            if (ext === 'xlsx' || ext === 'xls') {
                // Re-parse the full xlsx file (all rows, not just the 3-row sample)
                const buffer = yield this._readFileAsArrayBuffer(this.selectedFile);
                const workbook = XLSX.read(buffer, { type: 'array' });
                const sheet = workbook.Sheets[workbook.SheetNames[0]];
                const aoa = XLSX.utils.sheet_to_json(sheet, { header: 1, defval: '' });
                const rawHeaders = aoa[0];
                const columns = rawHeaders.map((h) =>
                    String(h)
                        .trim()
                        .replace(/\s*\*$/, '')
                );
                allRows = aoa.slice(1).map((rowArr) => Object.fromEntries(columns.map((col, i) => [col, rowArr[i] ?? ''])));
            } else {
                const text = yield this._readFileAsText(this.selectedFile);
                const lines = text.split(/\r?\n/).filter((l) => l.trim());
                const columns = this._parseCsvLine(lines[0]);
                allRows = lines.slice(1).map((line) => {
                    const vals = this._parseCsvLine(line);
                    return Object.fromEntries(columns.map((col, i) => [col, vals[i] ?? '']));
                });
            }

            const mapped = allRows.map((row, idx) => {
                const result = { _rowIndex: idx + 2 };
                for (const m of this.columnMappings) {
                    if (m.mappedColumn) {
                        result[m.key] = row[m.mappedColumn] ?? '';
                    }
                }
                // Validate: need at least a dropoff street address
                if (!result.dropoff_street1) {
                    result._error = this.intl.t('orchestrator.missing-dropoff');
                }
                return result;
            });

            this.mappedRows = mapped;
            this.validationErrors = mapped.filter((r) => r._error);
            this._setStep('preview');
        } catch (error) {
            this.notifications.serverError(error);
        }
    }

    // ── Submit import ────────────────────────────────────────────────
    @task *submitImport() {
        const validRows = this.mappedRows.filter((r) => !r._error);
        if (!validRows.length) {
            this.notifications.warning(this.intl.t('orchestrator.no-valid-rows'));
            return;
        }
        // Immediately push loading state to the footer button so the user
        // gets instant visual feedback and cannot double-click.
        this._syncFooterButtons();
        try {
            yield this.fetch.post('fleet-ops/orchestrator/import-orders', {
                rows: validRows,
                options: { mark_imported: true },
            });
            this.notifications.success(this.intl.t('orchestrator.import-success', { count: validRows.length }));
            if (typeof this.args.options?.onImportComplete === 'function') {
                this.args.options.onImportComplete();
            }
            if (typeof this.args.onConfirm === 'function') {
                this.args.onConfirm();
            }
        } catch (error) {
            this.notifications.serverError(error);
            // Restore the button to its normal state on error so the user
            // can retry without having to navigate away.
            this._syncFooterButtons();
        }
    }

    // ── Navigation ────────────────────────────────────────────────────────────
    @action goToUpload() {
        this._setStep('upload');
    }

    @action goToMapping() {
        this._setStep('map');
    }

    // ── Template download ─────────────────────────────────────────────────────
    @action downloadTemplate() {
        if (typeof this.args.options?.onImportTemplate === 'function') {
            return this.args.options.onImportTemplate();
        }

        // Open the import template URL for download
        window.open('https://flb-assets.s3.ap-southeast-1.amazonaws.com/import-templates/Fleetbase_Order_Import_Template.xlsx');
    }
}

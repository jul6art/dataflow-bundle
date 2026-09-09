import { Controller } from '@hotwired/stimulus';
import { t } from '@jul6art/core-bundle/i18n/registry';

/*
 * Report builder — a four-step wizard over the bundle's report engine.
 *
 *   1. entity   — pick the root class
 *   2. columns  — toggle fields on the left, order and rename the selection on the right
 *   3. filters  — add AND-combined filters
 *   4. export   — preview, download CSV / XLSX / JSON, save the definition
 *
 * ## What is a VALUE and why
 *
 * ⚠️ Every endpoint is a Stimulus value. The implementation this is extracted from hard-coded
 * `/organization/reports/builder/fields` and three siblings, so the controller could only ever
 * live in one application, mounted under one prefix, behind one firewall. A route is the
 * application's, always — and `loadUrl` now carries an explicit `{id}` placeholder instead of the
 * `String.replace(/\/0(?=…)/)` trick the original used to smuggle an id into a URL generated with
 * `id: 0`.
 *
 * ## Labels
 *
 * ⚠️ Read through `@jul6art/core-bundle/i18n/registry`, never from an HTML attribute. A controller
 * shipped inside `vendor/` cannot import the application's `assets/translator.js` — the relative
 * path out of `vendor/` does not exist — and posting a translation tree into a `data-` attribute
 * is what this ecosystem measured at 8.7 kB of escaped HTML per page before removing it.
 *
 * ⚠️ A ternary goes OUTSIDE the lookup, never inside it. A condition in the argument position
 * hides BOTH keys from the scanner, so the translation guard reports neither as used and a
 * catalogue clean-up deletes them; one lookup per arm is the same expression and stays readable to
 * a static pass. (Written in prose rather than shown as code on purpose: a scanner reads comments
 * too, and an example would register two keys nobody translates.)
 *
 * ⚠️ Every key starts with `dataflow.`, which is a rule and not a habit: a project cannot be asked
 * to translate `filters.op.eq`. The extracted version read exactly that key — twelve operator
 * labels outside any namespace — and `AssetTranslationKeysTest` is what now refuses it.
 *
 * ## Busy state
 *
 * ⚠️ The controller dispatches `dataflow:busy` / `dataflow:idle` instead of calling a loader
 * mixin. The version this replaces used `useBlockable` from `datatable-bundle`, which would have
 * made a report builder depend on a datatable library for a spinner. The application decides what
 * busy LOOKS like; the controller only says when it is.
 *
 * ```js
 * // assets/app.js — three lines to keep the overlay you already have
 * document.addEventListener('dataflow:busy', (e) => { e.detail.element.dataset.blockId = block(e.detail.element); });
 * document.addEventListener('dataflow:idle', (e) => unblock(e.detail.element.dataset.blockId));
 * ```
 *
 * ## CSS
 *
 * The markup this emits uses the utility classes of `jul6art/ui-bundle` — `form-panel`,
 * `form-control`, `btn-primary`, `btn-secondary` — plus `.step-tab` and `.step-panel` from
 * `assets/styles/dataflow.css`. ⚠️ Add this bundle's `assets/` to Tailwind's `content`: a class
 * used only here is otherwise purged from the PRODUCTION stylesheet, and only from that one.
 */

const STEPS = ['entity', 'columns', 'filters', 'export'];

/** How many rows the preview asks for. A preview is a shape check, not a report. */
const PREVIEW_ROWS = 50;

export default class extends Controller {
    static targets = [
        'entityRadio', 'columnsList', 'selectedColumnsList', 'filtersList',
        'previewPanel', 'previewHeader', 'previewBody',
        'reportName', 'shareToggle',
        'stepEntity', 'stepColumns', 'stepFilters', 'stepExport',
        'stepTabs', 'prevBtn', 'nextBtn', 'stepIndicator',
    ];

    static values = {
        fieldsUrl: { type: String, default: '' },
        runUrl: { type: String, default: '' },
        exportUrl: { type: String, default: '' },
        saveUrl: { type: String, default: '' },
        // ⚠️ Must contain `{id}`. Without the placeholder every load hits the same URL, which is
        // how the original's regex hack came about.
        loadUrl: { type: String, default: '' },
        previewRows: { type: Number, default: PREVIEW_ROWS },
    };

    /**
     * Operator codes with their value arity. Labels come from `dataflow.filter.op.<code>`.
     *
     * ⚠️ The codes mirror `Jul6Art\DataflowBundle\Report\Spec\FilterOperator`. A code this list
     * carries and the enum does not is refused by the interpreter, in silence — the filter is
     * simply dropped — so the two are kept in step by `ReportSpecInterpreter`'s own test rather
     * than by hope.
     */
    static OPERATORS = [
        { code: 'eq', arity: 1 },
        { code: 'neq', arity: 1 },
        { code: 'gt', arity: 1 },
        { code: 'gte', arity: 1 },
        { code: 'lt', arity: 1 },
        { code: 'lte', arity: 1 },
        { code: 'like', arity: 1 },
        { code: 'in', arity: 'list' },
        { code: 'nin', arity: 'list' },
        { code: 'isNull', arity: 0 },
        { code: 'isNotNull', arity: 0 },
        { code: 'between', arity: 2 },
    ];

    connect() {
        this._entity = '';
        this._columns = [];
        this._availableFields = [];
        this._filters = [];
        this._currentStep = 0;
        this._loadedId = null;
        this._shareScope = 'private';
        this._renderStep();

        // Auto-load via `?load=<id>`, which is how a saved-reports table hands one over.
        const loadId = new URLSearchParams(window.location.search).get('load');

        if (loadId && /^\d+$/.test(loadId)) {
            this._loadReportById(parseInt(loadId, 10));
        }
    }

    // ── Busy state ──────────────────────────────────────────────────────────────────────────────

    _busy(element) {
        this.dispatch('busy', { detail: { element: element || this.element }, prefix: 'dataflow' });
    }

    _idle(element) {
        this.dispatch('idle', { detail: { element: element || this.element }, prefix: 'dataflow' });
    }

    /** The element a long operation should cover: the export step when it exists, else the whole. */
    get _workArea() {
        return this.hasStepExportTarget ? this.stepExportTarget : this.element;
    }

    // ── Stepper ─────────────────────────────────────────────────────────────────────────────────

    _renderStep() {
        STEPS.forEach((step, i) => {
            const panel = this[`step${step.charAt(0).toUpperCase()}${step.slice(1)}Target`];

            if (panel) {
                panel.hidden = i !== this._currentStep;
            }
        });

        this._renderStepTabs();

        if (this.hasStepIndicatorTarget) {
            this.stepIndicatorTarget.textContent = t('dataflow.builder.step.indicator', {
                '%current%': this._currentStep + 1,
                '%total%': STEPS.length,
            });
        }

        if (this.hasPrevBtnTarget) {
            const atStart = 0 === this._currentStep;
            this.prevBtnTarget.disabled = atStart;
            this.prevBtnTarget.classList.toggle('opacity-50', atStart);
            this.prevBtnTarget.classList.toggle('cursor-not-allowed', atStart);
        }

        if (this.hasNextBtnTarget) {
            const atEnd = this._currentStep === STEPS.length - 1;
            this.nextBtnTarget.disabled = atEnd;
            this.nextBtnTarget.classList.toggle('opacity-50', atEnd);
            this.nextBtnTarget.classList.toggle('cursor-not-allowed', atEnd);
            this.nextBtnTarget.hidden = atEnd;
        }
    }

    /**
     * ⚠️ The state lives in two classes — `is-current` and `is-done` — rather than in the eleven
     * Tailwind utilities the original toggled by hand. Eleven strings repeated across three
     * branches is a palette change nobody can make safely, and it put colour decisions inside a
     * controller shipped in `vendor/`.
     */
    _renderStepTabs() {
        if (!this.hasStepTabsTarget) {
            return;
        }

        this.stepTabsTarget.querySelectorAll('.step-tab').forEach((tab, i) => {
            tab.classList.toggle('is-current', i === this._currentStep);
            tab.classList.toggle('is-done', i < this._currentStep);
        });
    }

    nextStep() {
        if (!this._canAdvance()) {
            return;
        }

        if (this._currentStep < STEPS.length - 1) {
            this._currentStep += 1;
            this._renderStep();
        }
    }

    prevStep() {
        if (this._currentStep > 0) {
            this._currentStep -= 1;
            this._renderStep();
        }
    }

    gotoStep(event) {
        const index = parseInt(event.currentTarget.dataset.stepIndex, 10);

        // No entity means no fields, so every later step would be empty.
        if (Number.isNaN(index) || (index > 0 && !this._entity)) {
            return;
        }

        this._currentStep = index;
        this._renderStep();
    }

    _canAdvance() {
        if (0 === this._currentStep && !this._entity) {
            this._toast(t('dataflow.builder.error.need_entity'), 'error');

            return false;
        }

        if (1 === this._currentStep && 0 === this._columns.length) {
            this._toast(t('dataflow.builder.error.need_column'), 'error');

            return false;
        }

        return true;
    }

    // ── Step 1 — the root entity ────────────────────────────────────────────────────────────────

    toggleShare(event) {
        this._shareScope = event.target.checked ? 'organization' : 'private';
    }

    newReport() {
        this._entity = '';
        this._columns = [];
        this._filters = [];
        this._availableFields = [];
        this._loadedId = null;
        this._shareScope = 'private';

        if (this.hasShareToggleTarget) {
            this.shareToggleTarget.checked = false;
        }

        this.entityRadioTargets.forEach((radio) => { radio.checked = false; });

        if (this.hasReportNameTarget) {
            this.reportNameTarget.value = '';
        }

        ['columnsList', 'selectedColumnsList', 'filtersList'].forEach((name) => {
            if (this[`has${name.charAt(0).toUpperCase()}${name.slice(1)}Target`]) {
                this[`${name}Target`].innerHTML = '';
            }
        });

        if (this.hasPreviewPanelTarget) {
            this.previewPanelTarget.hidden = true;
        }

        this._currentStep = 0;
        this._renderStep();
    }

    async entityChanged(event) {
        this._entity = event.target.value;

        if (!this._entity) {
            return;
        }

        const fields = await this._fetchFields();

        if (null === fields) {
            return;
        }

        this._availableFields = fields;

        // An empty selection starts on the identifier: a report of nothing is not a useful blank
        // state, and `id` is the one column every entity has.
        if (0 === this._columns.length) {
            this._columns = this._availableFields
                .filter((field) => 'id' === field.path)
                .map((field) => ({ path: field.path, label: field.label, sort: null }));
        }

        this._renderColumns();
        this._renderSelectedColumns();
    }

    async _fetchFields() {
        if (!this.fieldsUrlValue) {
            this._toast(t('dataflow.builder.error.not_configured'), 'error');

            return null;
        }

        const url = `${this.fieldsUrlValue}${this.fieldsUrlValue.includes('?') ? '&' : '?'}entity=${encodeURIComponent(this._entity)}`;

        this._busy(this.element);

        try {
            const response = await fetch(url, {
                credentials: 'include',
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                this._toast(t('dataflow.builder.error.fields_failed'), 'error');

                return null;
            }

            const data = await response.json();

            return this._sortFields(data.fields || []);
        } finally {
            this._idle(this.element);
        }
    }

    /**
     * Identifier first, then plain columns, then the ones behind a relation.
     *
     * ⚠️ `traversed`, which is what `ReportField::toArray()` produces. This read `relation` in
     * v1.0.0 — a name inherited from the application the controller came from — so the comparison
     * was `undefined !== undefined`, always false, and every relation column sorted in among the
     * plain ones. A missing property is not an error in JavaScript, so nothing said a word.
     */
    _sortFields(fields) {
        return [...fields].sort((a, b) => {
            if ('id' === a.path) return -1;
            if ('id' === b.path) return 1;
            if (a.traversed !== b.traversed) return a.traversed ? 1 : -1;

            return a.path.localeCompare(b.path);
        });
    }

    // ── Step 2 — columns ────────────────────────────────────────────────────────────────────────

    _renderColumns() {
        const selected = new Set(this._columns.map((column) => column.path));
        this.columnsListTarget.innerHTML = '';

        const { direct, byRelation } = this._groupByRelation(this._availableFields);

        this._renderColumnGroup(t('dataflow.builder.columns.group.direct'), direct, selected);
        [...byRelation.keys()].sort().forEach((relation) => {
            this._renderColumnGroup(relation, byRelation.get(relation), selected);
        });
    }

    /**
     * ⚠️ Grouped by the relation PREFIX rather than shown flat. A catalogue two hops deep offers
     * a hundred and forty paths, and a flat list of `customer.account.label` strings is unusable —
     * which is what made the original screen's column picker the part users complained about.
     */
    _groupByRelation(fields) {
        const direct = [];
        const byRelation = new Map();

        fields.forEach((field) => {
            const lastDot = field.path.lastIndexOf('.');

            if (-1 === lastDot) {
                direct.push(field);

                return;
            }

            const relation = field.path.substring(0, lastDot);

            if (!byRelation.has(relation)) {
                byRelation.set(relation, []);
            }

            byRelation.get(relation).push(field);
        });

        return { direct, byRelation };
    }

    _renderColumnGroup(title, items, selected) {
        if (0 === items.length) {
            return;
        }

        const sorted = this._sortWithIdFirst(items);
        const group = document.createElement('div');
        group.className = 'dataflow-field-group';

        const heading = document.createElement('div');
        heading.className = 'dataflow-field-group-title';
        heading.textContent = title;
        group.appendChild(heading);

        sorted.forEach((field) => {
            const row = document.createElement('label');
            row.className = 'dataflow-field-row';
            row.innerHTML = `
                <input type="checkbox" ${selected.has(field.path) ? 'checked' : ''} class="rounded">
                <span class="dataflow-field-name" title="${this._esc(field.path)}">${this._esc(this._tail(field.path))}</span>
                ${'id' === field.path ? `<span class="dataflow-field-badge">${this._esc(t('dataflow.builder.columns.default'))}</span>` : ''}
            `;
            row.querySelector('input').addEventListener('change', (event) => {
                this._toggleColumn(field, event.target.checked);
            });
            group.appendChild(row);
        });

        this.columnsListTarget.appendChild(group);
    }

    _sortWithIdFirst(items) {
        return [...items].sort((a, b) => {
            if ('id' === this._tail(a.path)) return -1;
            if ('id' === this._tail(b.path)) return 1;

            return a.path.localeCompare(b.path);
        });
    }

    _toggleColumn(field, checked) {
        if (checked) {
            this._columns.push({ path: field.path, label: field.label, sort: null });
        } else {
            this._columns = this._columns.filter((column) => column.path !== field.path);
        }

        this._renderSelectedColumns();
    }

    _renderSelectedColumns() {
        if (!this.hasSelectedColumnsListTarget) {
            return;
        }

        const list = this.selectedColumnsListTarget;
        list.innerHTML = '';

        if (0 === this._columns.length) {
            list.innerHTML = `<p class="dataflow-empty">${this._esc(t('dataflow.builder.columns.none'))}</p>`;

            return;
        }

        this._columns.forEach((column, index) => {
            list.appendChild(this._selectedColumnRow(column, index));
        });
    }

    _selectedColumnRow(column, index) {
        const item = document.createElement('div');
        item.className = 'dataflow-selected-column';

        const prefix = column.path.includes('.') ? column.path.substring(0, column.path.lastIndexOf('.')) : '';
        const source = (prefix ? `${prefix} › ` : '') + this._tail(column.path);
        const isFirst = 0 === index;
        const isLast = index === this._columns.length - 1;

        item.innerHTML = `
            <div class="flex items-center gap-2">
                <span class="dataflow-selected-column-rank">${index + 1}.</span>
                <input type="text" class="form-control text-xs flex-1 !py-0.5"
                       value="${this._esc(column.label ?? '')}" placeholder="${this._esc(this._tail(column.path))}"
                       data-act="label" title="${this._esc(t('dataflow.builder.columns.rename'))}">
                <button type="button" class="dataflow-icon-button" data-act="up"
                        title="${this._esc(t('dataflow.builder.columns.move_up'))}" ${isFirst ? 'disabled' : ''}>↑</button>
                <button type="button" class="dataflow-icon-button" data-act="down"
                        title="${this._esc(t('dataflow.builder.columns.move_down'))}" ${isLast ? 'disabled' : ''}>↓</button>
                <button type="button" class="dataflow-icon-button is-danger" data-act="remove"
                        title="${this._esc(t('dataflow.builder.columns.remove'))}">✕</button>
            </div>
            <span class="dataflow-selected-column-source" title="${this._esc(column.path)}">${this._esc(t('dataflow.builder.columns.source_label'))} : ${this._esc(source)}</span>
        `;

        // ⚠️ Renamed in place, without re-rendering: a re-render on every keystroke steals the
        // focus mid-word, and the user's caret lands back at position zero.
        item.querySelector('input[data-act="label"]').addEventListener('input', (event) => {
            this._columns[index].label = event.target.value;
        });

        item.querySelectorAll('button[data-act]').forEach((button) => {
            button.addEventListener('click', () => this._reorderColumn(button.dataset.act, index));
        });

        return item;
    }

    _reorderColumn(action, index) {
        if ('up' === action && index > 0) {
            [this._columns[index - 1], this._columns[index]] = [this._columns[index], this._columns[index - 1]];
        } else if ('down' === action && index < this._columns.length - 1) {
            [this._columns[index], this._columns[index + 1]] = [this._columns[index + 1], this._columns[index]];
        } else if ('remove' === action) {
            this._columns.splice(index, 1);
            this._renderColumns();
        }

        this._renderSelectedColumns();
    }

    // ── Step 3 — filters ────────────────────────────────────────────────────────────────────────

    addFilter() {
        this._filters.push({ path: '', op: 'eq', value: '' });
        this._renderFilters();
    }

    _renderFilters() {
        this.filtersListTarget.innerHTML = '';

        this._filters.forEach((filter, index) => {
            this.filtersListTarget.appendChild(this._filterCard(filter, index));
        });
    }

    _filterCard(filter, index) {
        const card = document.createElement('div');
        card.className = 'relative';

        // ⚠️ Filters are combined with AND, and the screen says so. An implicit conjunction is
        // read as a disjunction by roughly half the people who see it.
        if (index > 0) {
            const conjunction = document.createElement('div');
            conjunction.className = 'dataflow-filter-conjunction';
            conjunction.textContent = t('dataflow.builder.filters.and');
            card.appendChild(conjunction);
        }

        const inner = document.createElement('div');
        inner.className = 'dataflow-filter';
        inner.innerHTML = `
            <div class="flex flex-col gap-1 min-w-[140px] flex-1">
                <label class="dataflow-filter-label">${this._esc(t('dataflow.builder.filters.label.field'))}</label>
                <select class="form-control text-xs" data-k="path">${this._pathOptions(filter.path)}</select>
            </div>
            <div class="flex flex-col gap-1 min-w-[140px]">
                <label class="dataflow-filter-label">${this._esc(t('dataflow.builder.filters.label.operator'))}</label>
                <select class="form-control text-xs" data-k="op">${this._operatorOptions(filter.op)}</select>
            </div>
            <div class="flex flex-col gap-1 flex-1 min-w-[180px]">
                <label class="dataflow-filter-label">${this._esc(t('dataflow.builder.filters.label.value'))}</label>
                <div class="flex items-center gap-2">${this._valueInputs(filter)}</div>
            </div>
            <button type="button" class="btn-secondary text-xs !text-red-500" data-k="remove"
                    title="${this._esc(t('dataflow.builder.filters.remove'))}">
                <i class="fa-solid fa-trash"></i>
            </button>
        `;

        inner.querySelectorAll('[data-k]').forEach((element) => {
            if ('remove' === element.dataset.k) {
                element.addEventListener('click', () => {
                    this._filters.splice(index, 1);
                    this._renderFilters();
                });

                return;
            }

            element.addEventListener('change', (event) => {
                this._filters[index][element.dataset.k] = event.target.value;

                // Changing the operator changes how many values it takes, so the inputs are rebuilt.
                if ('op' === element.dataset.k) {
                    this._renderFilters();
                }
            });
        });

        card.appendChild(inner);

        return card;
    }

    _pathOptions(selectedPath) {
        const { direct, byRelation } = this._groupByRelation(this._availableFields);
        const option = (field) => `<option value="${this._esc(field.path)}" ${field.path === selectedPath ? 'selected' : ''}>${this._esc(this._tail(field.path))}</option>`;

        let html = `<option value="">${this._esc(t('dataflow.builder.filters.placeholder.field'))}</option>`;

        if (direct.length > 0) {
            html += `<optgroup label="${this._esc(t('dataflow.builder.columns.group.direct'))}">${this._sortWithIdFirst(direct).map(option).join('')}</optgroup>`;
        }

        [...byRelation.keys()].sort().forEach((relation) => {
            html += `<optgroup label="${this._esc(relation)}">${this._sortWithIdFirst(byRelation.get(relation)).map(option).join('')}</optgroup>`;
        });

        return html;
    }

    _operatorOptions(selected) {
        return this.constructor.OPERATORS
            .map((operator) => `<option value="${operator.code}" ${operator.code === selected ? 'selected' : ''}>${this._esc(t(`dataflow.filter.op.${operator.code}`))}</option>`)
            .join('');
    }

    _valueInputs(filter) {
        const operator = this.constructor.OPERATORS.find((candidate) => candidate.code === filter.op);
        const arity = operator ? operator.arity : 1;
        const input = (key, placeholder) => `<input type="text" class="form-control text-xs flex-1" placeholder="${this._esc(placeholder)}" value="${this._esc(filter[key] ?? '')}" data-k="${key}">`;

        if (0 === arity) {
            return `<span class="dataflow-empty flex-1">${this._esc(t('dataflow.builder.filters.no_value'))}</span>`;
        }

        if (2 === arity) {
            return [
                input('value', t('dataflow.builder.filters.placeholder.between_min')),
                `<span class="text-xs text-slate-400">${this._esc(t('dataflow.builder.filters.between_separator'))}</span>`,
                input('value2', t('dataflow.builder.filters.placeholder.between_max')),
            ].join('');
        }

        return input('value', 'list' === arity
            ? t('dataflow.builder.filters.placeholder.list')
            : t('dataflow.builder.filters.placeholder.value'));
    }

    // ── Step 4 — preview, export, save ──────────────────────────────────────────────────────────

    _definition() {
        const form = new FormData();
        form.append('entity', this._entity);
        form.append('columns', JSON.stringify(this._columns));
        form.append('filters', JSON.stringify(this._filters));

        return form;
    }

    async preview() {
        if (!this.runUrlValue) {
            this._toast(t('dataflow.builder.error.not_configured'), 'error');

            return;
        }

        const form = this._definition();
        form.append('limit', String(this.previewRowsValue));

        this._busy(this._workArea);

        try {
            const response = await fetch(this.runUrlValue, {
                method: 'POST',
                body: form,
                credentials: 'include',
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                this._toast(t('dataflow.builder.error.preview_failed'), 'error');

                return;
            }

            const data = await response.json();
            this._renderPreview(data.columns || [], data.rows || []);
        } finally {
            this._idle(this._workArea);
        }
    }

    _renderPreview(columns, rows) {
        this.previewPanelTarget.hidden = false;
        this.previewHeaderTarget.innerHTML = columns
            .map((column) => `<th class="dataflow-preview-th">${this._esc(column.label)}</th>`)
            .join('');

        if (0 === rows.length) {
            this.previewBodyTarget.innerHTML = `<tr><td class="dataflow-empty px-2 py-2">${this._esc(t('dataflow.builder.export.preview_empty'))}</td></tr>`;

            return;
        }

        this.previewBodyTarget.innerHTML = rows
            .map((row) => `<tr>${columns.map((column) => `<td class="px-2 py-1">${this._esc(this._format(row[column.path]))}</td>`).join('')}</tr>`)
            .join('');
    }

    exportCsv() { this._export('csv'); }

    exportXlsx() { this._export('xlsx'); }

    exportJson() { this._export('json'); }

    /**
     * ⚠️ Fetched into a Blob rather than navigated to. A plain link cannot show that the server is
     * working, and an XLSX of fifty thousand rows takes long enough that a user presses the button
     * again — which starts a second export of the same fifty thousand rows.
     *
     * ⚠️ And the filename comes from `Content-Disposition`, not from the client. The server has
     * already sanitised it; re-deriving it here would let a report NAME decide a filename.
     */
    async _export(format) {
        if (!this.exportUrlValue) {
            this._toast(t('dataflow.builder.error.not_configured'), 'error');

            return;
        }

        const form = this._definition();
        form.append('format', format);

        this._busy(this._workArea);

        try {
            const response = await fetch(this.exportUrlValue, {
                method: 'POST',
                body: form,
                credentials: 'include',
            });

            if (!response.ok) {
                this._toast(t('dataflow.builder.error.export_failed'), 'error');

                return;
            }

            this._download(await response.blob(), this._filenameFrom(response, format));
        } finally {
            this._idle(this._workArea);
        }
    }

    _filenameFrom(response, format) {
        const disposition = response.headers.get('Content-Disposition') || '';
        const match = disposition.match(/filename="?([^";]+)"?/);

        return match ? match[1] : `report.${format}`;
    }

    _download(blob, filename) {
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();

        // ⚠️ Revoked on a delay, not immediately: revoking before the browser has started reading
        // the object cancels the download in WebKit, and only there.
        setTimeout(() => URL.revokeObjectURL(url), 5000);
    }

    async save() {
        if (!this.saveUrlValue) {
            this._toast(t('dataflow.builder.error.not_configured'), 'error');

            return;
        }

        const isUpdate = Boolean(this._loadedId);
        const name = (this.reportNameTarget?.value || '').trim() || t('dataflow.report.default_name');

        // ⚠️ Confirmed BEFORE persisting. The version this replaces raised a native `alert()`
        // afterwards, which freezes the browser — and the wording differs between creating and
        // updating because overwriting someone's saved report is not the same act as making one.
        const confirmed = await this._confirm({
            title: isUpdate
                ? t('dataflow.builder.confirm.save_update_title')
                : t('dataflow.builder.confirm.save_new_title'),
            message: isUpdate
                ? t('dataflow.builder.confirm.save_update_message', { '%name%': name })
                : t('dataflow.builder.confirm.save_new_message'),
            confirmLabel: isUpdate
                ? t('dataflow.builder.confirm.update_confirm')
                : t('dataflow.builder.confirm.save_confirm'),
            cancelLabel: t('dataflow.builder.confirm.cancel'),
        });

        if (!confirmed) {
            return;
        }

        const form = this._definition();

        if (isUpdate) {
            form.append('id', String(this._loadedId));
        }

        form.append('name', name);
        form.append('shareScope', this._shareScope || 'private');

        this._busy(this._workArea);

        try {
            const response = await fetch(this.saveUrlValue, {
                method: 'POST',
                body: form,
                credentials: 'include',
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                this._toast(t('dataflow.builder.error.save_failed'), 'error');

                return;
            }

            const data = await response.json().catch(() => ({}));

            // ⚠️ The identifier is kept, so a second save updates instead of creating a twin.
            if (data.id) {
                this._loadedId = data.id;
            }

            this._toast(isUpdate ? t('dataflow.builder.flash.updated') : t('dataflow.builder.flash.saved'));
        } finally {
            this._idle(this._workArea);
        }
    }

    async _loadReportById(id) {
        if (!this.loadUrlValue.includes('{id}')) {
            this._toast(t('dataflow.builder.error.not_configured'), 'error');

            return;
        }

        this._loadedId = id;
        this._busy(this.element);

        try {
            const response = await fetch(this.loadUrlValue.replace('{id}', String(id)), {
                credentials: 'include',
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                this._toast(t('dataflow.builder.error.load_failed'), 'error');

                return;
            }

            this._applyDefinition(await response.json());
        } finally {
            this._idle(this.element);
        }

        // Outside the busy block: `_fetchFields` raises its own.
        const fields = await this._fetchFields();

        if (null !== fields) {
            this._availableFields = fields;
        }

        this._renderColumns();
        this._renderSelectedColumns();
        this._renderFilters();
        this._currentStep = STEPS.length - 1;
        this._renderStep();
    }

    /**
     * ⚠️ A stored definition is re-read defensively — a column whose label is gone falls back to
     * its path, a filter's second value to null. The server re-interprets the payload against
     * today's catalogue, so a definition saved last year legitimately arrives with less in it than
     * it was saved with, and the screen has to render what survived rather than fail.
     */
    _applyDefinition(data) {
        this._entity = data.entity;
        this._columns = (data.columns || []).map((column) => ({
            path: column.path,
            label: column.label || column.path,
            sort: column.sort || null,
        }));
        this._filters = (data.filters || []).map((filter) => ({
            path: filter.path,
            op: filter.op,
            value: filter.value ?? '',
            value2: filter.value2 ?? null,
        }));

        if (this.hasReportNameTarget) {
            this.reportNameTarget.value = data.name || '';
        }

        this._shareScope = data.shareScope || 'private';

        if (this.hasShareToggleTarget) {
            this.shareToggleTarget.checked = 'organization' === this._shareScope;
        }

        this.entityRadioTargets.forEach((radio) => { radio.checked = radio.value === this._entity; });
    }

    // ── Chrome ──────────────────────────────────────────────────────────────────────────────────

    /**
     * A confirmation that returns a Promise, so it composes with `await`.
     *
     * ⚠️ Not a native `confirm()`, which blocks the whole browser and makes any automated pass
     * impossible — a rule this ecosystem applies without exception.
     */
    _confirm({ title, message, confirmLabel, cancelLabel }) {
        return new Promise((resolve) => {
            const overlay = document.createElement('div');
            overlay.className = 'dataflow-modal';
            overlay.innerHTML = `
                <div class="dataflow-modal-backdrop" data-backdrop></div>
                <div class="dataflow-modal-panel">
                    <div class="flex items-start gap-4">
                        <div class="dataflow-modal-icon"><i class="fa-solid fa-circle-question"></i></div>
                        <div class="flex-1 min-w-0">
                            ${title ? `<h3 class="dataflow-modal-title">${this._esc(title)}</h3>` : ''}
                            ${message ? `<p class="dataflow-modal-message">${this._esc(message)}</p>` : ''}
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end gap-3">
                        <button type="button" data-cancel class="btn-secondary">${this._esc(cancelLabel || '')}</button>
                        <button type="button" data-confirm class="btn-primary">${this._esc(confirmLabel || '')}</button>
                    </div>
                </div>
            `;

            const close = (answer) => { overlay.remove(); resolve(answer); };

            overlay.querySelector('[data-backdrop]').addEventListener('click', () => close(false));
            overlay.querySelector('[data-cancel]').addEventListener('click', () => close(false));
            overlay.querySelector('[data-confirm]').addEventListener('click', () => close(true));
            document.body.appendChild(overlay);
        });
    }

    /**
     * ⚠️ A toast and never `alert()`, for errors included. A modal dialog freezes the browser, so
     * one raised on a failed export leaves the page unusable until someone clicks it.
     */
    _toast(message, variant = 'success') {
        const toast = document.createElement('div');
        toast.className = `dataflow-toast is-${'error' === variant ? 'error' : 'success'}`;
        toast.innerHTML = `<i class="fa-solid fa-${'error' === variant ? 'circle-exclamation' : 'circle-check'}"></i> ${this._esc(message)}`;
        document.body.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 200);
        }, 2500);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────────────────────

    /** `customer.account.label` → `label`. The prefix is shown separately. */
    _tail(path) {
        return path.includes('.') ? path.substring(path.lastIndexOf('.') + 1) : path;
    }

    _format(value) {
        if (null === value || undefined === value) {
            return '';
        }

        return 'object' === typeof value ? JSON.stringify(value) : String(value);
    }

    /**
     * ⚠️ Every interpolated value goes through here. Column labels, report names and filter values
     * are all typed by a user, and this controller builds its rows with `innerHTML` — so one
     * unescaped label is stored XSS on a screen its author shares with colleagues.
     */
    _esc(value) {
        return String(value).replace(/[&<>"']/g, (character) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[character]));
    }
}

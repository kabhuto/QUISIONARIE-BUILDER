(() => {
    const config = window.builderConfig;
    const form = document.querySelector('#builder-form');
    const paper = document.querySelector('#question-canvas');
    const blockList = document.querySelector('#block-list');
    const hint = document.querySelector('#drop-hint');
    const payload = document.querySelector('#blocks-payload');
    const blockCount = document.querySelector('#block-count');
    const questionCount = document.querySelector('#question-count');
    const selectionPanel = document.querySelector('#selection-panel');
    const activityInput = document.querySelector('#activity');
    const previousSelect = document.querySelector('#previous-activity');
    const activityModes = document.querySelectorAll('[name="activity-source"]');
    const paperSizeInputs = form.querySelectorAll('[name="settings[paper_size]"]');
    const orientationInputs = form.querySelectorAll('[name="settings[orientation]"]');
    const periodTypeInputs = form.querySelectorAll('[name="settings[period_type]"]');
    const numberingInputs = form.querySelectorAll('[name="settings[numbering]"]');
    const periodValueSelect = document.querySelector('#period-value');
    const periodLabel = document.querySelector('#period-label');
    const choiceTypes = config.choiceTypes;
    const nonLogicTypes = config.nonLogicTypes;
    const noCodeTypes = config.noCodeTypes;
    const rangeTypes = Object.keys(config.rangeDefaults);
    const valueless = config.valuelessOperators;

    const blocks = restoreBlocks() ?? [makeBlock('KETERANGAN UMUM')];
    let selected = blocks[0] ? { kind: 'block', id: blocks[0].id } : null;
    let dragState = null;
    let numbers = new Map();
    let fieldTab = 'options';
    let mentionAt = null;

    // ── Model ──────────────────────────────────────────────────────────────

    function makeId(prefix) {
        return `${prefix}-${Math.random().toString(36).slice(2, 10)}`;
    }

    function clamp(value, min, max, fallback) {
        const number = Number.parseInt(value, 10);
        return Number.isFinite(number) ? Math.min(max, Math.max(min, number)) : fallback;
    }

    function makeColumn(label = '', type = 'text') {
        return { id: makeId('c'), label, type, options: type === 'choice' ? ['Ya', 'Tidak'] : [] };
    }

    function defaultRegion() {
        return { fields: Object.entries(config.regionFields).map(([key, label]) => ({ key, label, enabled: true, mode: 'input', code: '', name: '' })) };
    }

    function defaultTable() {
        return { mode: 'dynamic', max_rows: 20, initial_rows: 3, rows: [], columns: [makeColumn('Kolom 1'), makeColumn('Kolom 2')] };
    }

    function defaultMatrix() {
        return { rows: ['Pernyataan 1', 'Pernyataan 2'], columns: ['Tidak setuju', 'Netral', 'Setuju'] };
    }

    function emptyValidation() {
        return { rules: [], message: '', checks: [] };
    }

    function makeQuestion(type, overrides = {}) {
        return {
            id: makeId('q'),
            type,
            label: '',
            description: '',
            required: false,
            hidden: false,
            disabled: false,
            hide_label: false,
            placeholder: '',
            prefill: '',
            width: 'full',
            help_position: 'below',
            max_length: null,
            code_length: type === 'code' ? 2 : (choiceTypes.includes(type) ? 1 : 0),
            options: choiceTypes.includes(type) ? ['Pilihan 1', 'Pilihan 2'] : [],
            logic: null,
            validation: emptyValidation(),
            ...(type === 'region' ? { region: defaultRegion(), label: 'Identitas wilayah' } : {}),
            ...(type === 'table' ? { table: defaultTable() } : {}),
            ...(rangeTypes.includes(type) ? { range: { ...config.rangeDefaults[type] } } : {}),
            ...(type === 'matrix' ? { matrix: defaultMatrix() } : {}),
            ...overrides,
        };
    }

    function makeBlock(title = '', questions = []) {
        return { id: makeId('b'), title, layout: 'full', orientation: '', questions, condition: null };
    }

    function normalizeRegion(region) {
        const existing = new Map((Array.isArray(region?.fields) ? region.fields : []).map(field => [field?.key, field]));
        return {
            fields: Object.entries(config.regionFields).map(([key, label]) => {
                const field = existing.get(key) ?? {};
                return {
                    key,
                    label: String(field.label || label),
                    enabled: region ? Boolean(field.enabled) : true,
                    mode: field.mode === 'fixed' ? 'fixed' : 'input',
                    code: String(field.code ?? ''),
                    name: String(field.name ?? ''),
                };
            }),
        };
    }

    function normalizeTable(table) {
        const columns = (Array.isArray(table?.columns) ? table.columns : []).map(column => ({
            id: String(column?.id || makeId('c')),
            label: String(column?.label ?? ''),
            type: config.tableColumnTypes[column?.type] ? column.type : 'text',
            options: Array.isArray(column?.options) ? column.options.map(String) : [],
        }));
        const maxRows = clamp(table?.max_rows, 1, config.maxTableRows, 20);
        return {
            mode: table?.mode === 'fixed' ? 'fixed' : 'dynamic',
            max_rows: maxRows,
            initial_rows: Math.min(clamp(table?.initial_rows, 1, config.maxTableRows, 3), maxRows),
            rows: Array.isArray(table?.rows) ? table.rows.map(String) : [],
            columns: columns.length ? columns : defaultTable().columns,
        };
    }

    function normalizeRule(rule) {
        return {
            question_id: String(rule?.question_id ?? ''),
            operator: config.operators[rule?.operator] ? rule.operator : 'equals',
            value: String(rule?.value ?? ''),
        };
    }

    // An answer tag in a rule value, {{question-id}} or {{question-id|fallback}}: compare with another answer.
    const MENTION = /\{\{([A-Za-z0-9_-]+)(?:\|([^}]*))?\}\}/g;

    function mentionsIn(value) {
        return [...String(value ?? '').matchAll(MENTION)].map(match => ({ id: match[1], fallback: match[2] ?? '' }));
    }

    function wholeMention(value) {
        const match = /^\{\{([A-Za-z0-9_-]+)(?:\|([^}]*))?\}\}$/.exec(String(value ?? '').trim());
        return match ? { id: match[1], fallback: match[2] ?? '' } : null;
    }

    // A group is {operator: "and" | "or", rules: [...]}; rules are conditions or (one level of) nested groups.
    function normalizeGroup(group, depth = 0) {
        return {
            operator: group?.operator === 'or' ? 'or' : 'and',
            rules: (Array.isArray(group?.rules) ? group.rules : [])
                .filter(rule => rule && typeof rule === 'object')
                .map(rule => (Array.isArray(rule.rules) && depth < 1 ? normalizeGroup(rule, depth + 1) : normalizeRule(rule))),
        };
    }

    function normalizeLogic(logic) {
        if (!logic?.conditions) return null;
        return {
            conditions: normalizeGroup(logic.conditions),
            actions: (Array.isArray(logic.actions) ? logic.actions : []).filter(action => config.logicActions[action]),
        };
    }

    function normalizeValidation(validation) {
        return {
            rules: (Array.isArray(validation?.rules) ? validation.rules : [])
                .filter(rule => config.validationRules[rule?.type])
                .map(rule => ({ type: rule.type, value: String(rule.value ?? '') })),
            message: String(validation?.message ?? ''),
            checks: (Array.isArray(validation?.checks) ? validation.checks : []).map(check => ({
                id: String(check?.id || makeId('k')),
                level: check?.level === 'warning' ? 'warning' : 'error',
                conditions: normalizeGroup(check?.conditions),
                message: String(check?.message ?? ''),
            })),
        };
    }

    // Ratings always count from one star.
    function normalizeRange(type, range) {
        const defaults = config.rangeDefaults[type];
        const isRating = type === 'rating';
        return {
            min: isRating ? 1 : clamp(range?.min, -config.rangeLimit, config.rangeLimit, defaults.min),
            max: clamp(range?.max, -config.rangeLimit, config.rangeLimit, defaults.max),
            step: isRating ? 1 : clamp(range?.step, 1, config.rangeLimit, defaults.step),
        };
    }

    function normalizeMatrix(matrix) {
        const list = values => (Array.isArray(values) ? values.map(String) : []);
        return matrix ? { rows: list(matrix.rows), columns: list(matrix.columns) } : defaultMatrix();
    }

    function normalizeQuestion(question) {
        // Older questions had a single "show when" condition: that is "hidden" plus logic that shows it.
        const legacy = question.condition?.question_id && !question.logic;
        const normalized = {
            id: String(question.id || makeId('q')),
            type: question.type,
            label: String(question.label ?? ''),
            description: String(question.description ?? ''),
            required: Boolean(question.required),
            hidden: legacy || Boolean(question.hidden),
            disabled: Boolean(question.disabled),
            hide_label: Boolean(question.hide_label),
            placeholder: String(question.placeholder ?? ''),
            prefill: String(question.prefill ?? ''),
            width: config.fieldWidths[question.width] ? question.width : 'full',
            help_position: question.help_position === 'above' ? 'above' : 'below',
            max_length: question.max_length ? clamp(question.max_length, 1, 5000, null) : null,
            code_length: clamp(question.code_length, 0, config.maxCodeLength, 0),
            options: Array.isArray(question.options) ? question.options.map(String) : [],
            logic: legacy
                ? { conditions: { operator: 'and', rules: [normalizeRule(question.condition)] }, actions: ['show'] }
                : normalizeLogic(question.logic),
            validation: normalizeValidation(question.validation),
        };
        if (question.type === 'region') normalized.region = normalizeRegion(question.region);
        if (question.type === 'table') normalized.table = normalizeTable(question.table);
        if (rangeTypes.includes(question.type)) normalized.range = normalizeRange(question.type, question.range);
        if (question.type === 'matrix') normalized.matrix = normalizeMatrix(question.matrix);
        return normalized;
    }

    function restoreBlocks() {
        try {
            const restored = JSON.parse(payload.value || 'null');
            if (!Array.isArray(restored) || !restored.length) return null;
            return restored.map(block => ({
                id: String(block?.id || makeId('b')),
                title: String(block?.title ?? ''),
                layout: block?.layout === 'half' ? 'half' : 'full',
                orientation: ['portrait', 'landscape'].includes(block?.orientation) ? block.orientation : '',
                condition: normalizeCondition(block?.condition),
                questions: (Array.isArray(block?.questions) ? block.questions : [])
                    .filter(question => config.questionTypes[question?.type])
                    .map(normalizeQuestion),
            }));
        } catch {
            return null;
        }
    }

    function normalizeCondition(condition) {
        if (!condition || typeof condition !== 'object' || !condition.question_id) return null;
        return normalizeRule(condition);
    }

    function findBlock(id) {
        const blockIndex = blocks.findIndex(block => block.id === id);
        return blockIndex < 0 ? null : { block: blocks[blockIndex], blockIndex };
    }

    function findQuestion(id) {
        for (const [blockIndex, block] of blocks.entries()) {
            const questionIndex = block.questions.findIndex(question => question.id === id);
            if (questionIndex >= 0) return { block, blockIndex, question: block.questions[questionIndex], questionIndex };
        }
        return null;
    }

    function checkedValue(inputs) {
        return [...inputs].find(input => input.checked)?.value;
    }

    // Mirrors Survey::numberQuestions(): information items are not numbered.
    function computeNumbers() {
        const style = checkedValue(numberingInputs) ?? 'block';
        const result = new Map();
        let sequence = 0;
        blocks.forEach((block, blockIndex) => {
            let withinBlock = 0;
            block.questions.forEach(question => {
                if (question.type === 'info') return;
                withinBlock++;
                sequence++;
                result.set(question.id, style === 'sequential' ? String(sequence) : `${blockIndex + 1}${String(withinBlock).padStart(2, '0')}`);
            });
        });
        return result;
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, character => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        })[character]);
    }

    function truncate(text, length) {
        return text.length > length ? `${text.slice(0, length - 1)}…` : text;
    }

    function toRoman(number) {
        const numerals = [[1000, 'M'], [900, 'CM'], [500, 'D'], [400, 'CD'], [100, 'C'], [90, 'XC'], [50, 'L'], [40, 'XL'], [10, 'X'], [9, 'IX'], [5, 'V'], [4, 'IV'], [1, 'I']];
        let result = '';
        for (const [value, numeral] of numerals) {
            while (number >= value) {
                result += numeral;
                number -= value;
            }
        }
        return result;
    }

    function isSelected(kind, id) {
        return selected?.kind === kind && selected.id === id;
    }

    // ── Logic ──────────────────────────────────────────────────────────────

    // Questions a rule may refer to: earlier, logic-capable ones (and the question itself for consistency checks).
    function earlierQuestions(kind, id, includeSelf = false) {
        const earlier = [];
        for (const block of blocks) {
            if (kind === 'block' && block.id === id) return earlier;
            for (const question of block.questions) {
                const usable = !nonLogicTypes.includes(question.type);
                if (kind === 'question' && question.id === id) {
                    if (includeSelf && usable) earlier.push({ question, number: numbers.get(question.id) });
                    return earlier;
                }
                if (usable) earlier.push({ question, number: numbers.get(question.id) });
            }
        }
        return earlier;
    }

    function questionLabel(item) {
        return `${item.number} ${truncate(item.question.label || '(tanpa teks)', 30)}`;
    }

    function groupProblem(group, available) {
        if (!group?.rules?.length) return 'belum memiliki aturan';
        for (const rule of group.rules) {
            if (rule.rules) {
                const problem = groupProblem(rule, available);
                if (problem) return problem;
                continue;
            }
            if (!available.some(item => item.question.id === rule.question_id)) return 'merujuk pertanyaan yang tidak ada sebelumnya';
            if (!valueless.includes(rule.operator) && !rule.value.trim()) return 'belum memiliki nilai jawaban';
            const mentions = valueless.includes(rule.operator) ? [] : mentionsIn(rule.value);
            if (mentions.some(mention => !available.some(item => item.question.id === mention.id))) return 'memakai tag jawaban dari pertanyaan yang tidak tersedia';
            if (!mentions.length && ['greater_than', 'less_than'].includes(rule.operator) && Number.isNaN(Number(rule.value.replace(',', '.')))) return 'harus memakai angka untuk pembanding lebih/kurang dari';
        }
        return '';
    }

    function allowedActions(question) {
        return [question.hidden ? 'show' : 'hide', question.disabled ? 'enable' : 'disable', question.required ? 'optional' : 'require'];
    }

    function logicProblem(question) {
        if (!question.logic) return '';
        const problem = groupProblem(question.logic.conditions, earlierQuestions('question', question.id));
        if (problem) return `Logika ${problem}.`;
        if (!question.logic.actions.length) return 'Logika belum memiliki tindakan.';
        if (question.logic.actions.some(action => !allowedActions(question).includes(action))) return 'Logika memiliki tindakan yang tidak sesuai dengan pengaturan isian.';
        return '';
    }

    function validationProblemOf(question) {
        for (const rule of question.validation.rules) {
            const definition = config.validationRules[rule.type];
            const value = rule.value.trim();
            if (!value) return `Aturan "${definition.label}" belum memiliki nilai.`;
            if (definition.input === 'number' && Number.isNaN(Number(value))) return `Aturan "${definition.label}" harus berupa angka.`;
            if (definition.input === 'date' && !/^\d{4}-\d{2}-\d{2}$/.test(value)) return `Aturan "${definition.label}" harus berupa tanggal.`;
            if (rule.type === 'pattern') {
                try {
                    new RegExp(value, 'u');
                } catch {
                    return 'Pola (regex) pada aturan isian tidak valid.';
                }
            }
        }
        for (const check of question.validation.checks) {
            if (!check.message.trim()) return 'Pemeriksaan konsistensi belum memiliki pesan.';
            const problem = groupProblem(check.conditions, earlierQuestions('question', question.id, true));
            if (problem) return `Pemeriksaan konsistensi ${problem}.`;
        }
        return '';
    }

    function describeGroup(group, available) {
        return group.rules.map(rule => {
            if (rule.rules) return `(${describeGroup(rule, available)})`;
            const source = available.find(item => item.question.id === rule.question_id);
            const tagged = rule.value.replace(MENTION, (match, id) => `@${available.find(item => item.question.id === id)?.number ?? '?'}`);
            const value = valueless.includes(rule.operator) ? '' : ` ${tagged}`;
            return `${source?.number ?? '?'} ${config.operators[rule.operator]}${value}`;
        }).join(group.operator === 'or' ? ' atau ' : ' dan ');
    }

    // Block conditions keep the simple "show this block when …" form.
    function conditionProblem(kind, id) {
        const condition = findBlock(id)?.block.condition;
        if (kind !== 'block' || !condition) return '';
        if (!earlierQuestions('block', id).some(item => item.question.id === condition.question_id)) return 'Logika blok merujuk pertanyaan yang tidak ada sebelumnya.';
        if (!valueless.includes(condition.operator) && !condition.value.trim()) return 'Logika blok belum memiliki nilai jawaban.';
        return '';
    }

    function chip(text, className = '') {
        return `<span class="logic-badge ${className}" title="${escapeHtml(text)}">${escapeHtml(truncate(text, 46))}</span>`;
    }

    function logicBadge(kind, id) {
        if (kind === 'block') {
            const condition = findBlock(id)?.block.condition;
            if (!condition) return '';
            if (conditionProblem(kind, id)) return chip('⚠ Logika perlu diperbaiki', 'is-invalid');
            return chip(`⤷ Jika ${describeGroup({ operator: 'and', rules: [condition] }, earlierQuestions('block', id))}`);
        }

        const question = findQuestion(id)?.question;
        if (!question) return '';
        const chips = [];
        if (question.hidden) chips.push(chip('Tersembunyi', 'is-flag'));
        if (question.disabled) chips.push(chip('Nonaktif', 'is-flag'));
        if (question.width !== 'full') chips.push(chip(`Lebar ${question.width}`, 'is-flag'));
        if (question.logic) {
            chips.push(logicProblem(question)
                ? chip('⚠ Logika perlu diperbaiki', 'is-invalid')
                : chip(`⤷ Jika ${describeGroup(question.logic.conditions, earlierQuestions('question', id))} → ${question.logic.actions.map(action => config.logicActions[action]).join(', ')}`));
        }
        const validationCount = question.validation.rules.length + question.validation.checks.length;
        if (validationCount) {
            chips.push(validationProblemOf(question)
                ? chip('⚠ Validasi perlu diperbaiki', 'is-invalid')
                : chip(`✓ ${validationCount} validasi`, 'is-check'));
        }
        return chips.join('');
    }

    function refreshLogicSlot(kind, id) {
        const slot = kind === 'block'
            ? blockList.querySelector(`[data-block-id="${CSS.escape(id)}"] > .block-head .logic-slot`)
            : blockList.querySelector(`[data-question-id="${CSS.escape(id)}"] .logic-slot`);
        if (slot) slot.innerHTML = logicBadge(kind, id);
    }

    function refreshAllBadges() {
        blocks.forEach(block => {
            refreshLogicSlot('block', block.id);
            block.questions.forEach(question => refreshLogicSlot('question', question.id));
        });
    }

    function pruneGroup(group, removed) {
        group.rules = group.rules
            .map(rule => {
                if (rule.rules) return pruneGroup(rule, removed);
                if (mentionsIn(rule.value).some(mention => removed.has(mention.id))) rule.value = '';
                return rule;
            })
            .filter(rule => (rule.rules ? rule.rules.length > 0 : !removed.has(rule.question_id)));
        return group;
    }

    // Removes rules that point at deleted (or no longer logic-capable) questions.
    function clearConditionsOn(questionIds) {
        const removed = new Set(questionIds);
        blocks.forEach(block => {
            if (removed.has(block.condition?.question_id)) block.condition = null;
            block.questions.forEach(question => {
                if (question.logic && !pruneGroup(question.logic.conditions, removed).rules.length) question.logic = null;
                question.validation.checks = question.validation.checks.filter(check => pruneGroup(check.conditions, removed).rules.length > 0);
            });
        });
    }

    // Block-level "show this block when" editor (one condition).
    function renderLogicEditor() {
        const container = selectionPanel.querySelector('#logic-editor');
        const target = selected?.kind === 'block' && findBlock(selected.id)?.block;
        if (!container || !target) return;

        const earlier = earlierQuestions('block', selected.id);
        const condition = target.condition;
        const source = condition && earlier.find(item => item.question.id === condition.question_id);
        let html = `<div class="setting-label"><span>Logika tampil blok</span><span class="setting-value">${condition ? 'BERSYARAT' : 'SELALU'}</span></div>`;

        if (!earlier.length) {
            container.innerHTML = `${html}<p class="setting-note">Belum ada pertanyaan sebelumnya yang bisa dijadikan syarat.</p>`;
            return;
        }

        html += `<label class="setting-field"><span>Tampilkan blok ini</span>
            <select class="setting-select" data-logic-field="question_id">
                <option value="">Selalu tampil</option>
                ${earlier.map(item => `<option value="${escapeHtml(item.question.id)}" ${item.question.id === condition?.question_id ? 'selected' : ''}>Jika ${escapeHtml(questionLabel(item))}</option>`).join('')}
            </select></label>`;

        if (source) {
            html += `<label class="setting-field"><span>Jawabannya</span>
                <select class="setting-select" data-logic-field="operator">
                    ${Object.entries(config.operators).map(([operator, label]) => `<option value="${operator}" ${operator === condition.operator ? 'selected' : ''}>${escapeHtml(label)}</option>`).join('')}
                </select></label>`;
            if (!valueless.includes(condition.operator)) {
                const options = choiceTypes.includes(source.question.type) ? source.question.options : [];
                html += options.length
                    ? `<label class="setting-field"><span>Nilai</span><select class="setting-select" data-logic-field="value">
                        <option value="">Pilih jawaban…</option>
                        ${options.map(option => `<option value="${escapeHtml(option)}" ${option === condition.value ? 'selected' : ''}>${escapeHtml(option)}</option>`).join('')}
                       </select></label>`
                    : `<label class="setting-field"><span>Nilai</span><input class="setting-select" type="text" maxlength="255" value="${escapeHtml(condition.value)}" data-logic-field="value" placeholder="Jawaban yang dicocokkan"></label>`;
            }
        }

        container.innerHTML = html;
    }

    function updateCondition(field, value) {
        const target = selected?.kind === 'block' && findBlock(selected.id)?.block;
        if (!target) return;
        if (field === 'question_id') {
            const source = value && findQuestion(value)?.question;
            target.condition = value ? { question_id: value, operator: target.condition?.operator ?? 'equals', value: source && choiceTypes.includes(source.type) ? source.options[0] ?? '' : '' } : null;
            renderLogicEditor();
        } else if (field === 'operator' && target.condition) {
            target.condition.operator = value;
            renderLogicEditor();
        } else if (field === 'value' && target.condition) {
            target.condition.value = value;
        }
        refreshLogicSlot('block', selected.id);
    }

    // ── Rule editor (field logic and consistency checks) ───────────────────

    function newRule(available) {
        const source = available[0]?.question;
        return { question_id: source?.id ?? '', operator: 'equals', value: source && choiceTypes.includes(source.type) ? source.options[0] ?? '' : '' };
    }

    function ruleValueControl(rule, source, available, isOpen) {
        if (valueless.includes(rule.operator)) return '';
        const mention = wholeMention(rule.value);
        if (mention) {
            const reference = available.find(item => item.question.id === mention.id);
            return `<span class="mention-chip ${reference ? '' : 'is-invalid'}" title="Dibandingkan dengan jawaban pertanyaan ini">
                <b>@</b><span>${escapeHtml(reference ? questionLabel(reference) : 'pertanyaan tidak tersedia')}${mention.fallback ? ` <small>· jika kosong: ${escapeHtml(mention.fallback)}</small>` : ''}</span>
                <button type="button" data-mention-action="clear" aria-label="Hapus tag jawaban">×</button>
            </span>`;
        }
        return `<span class="value-wrap">${ruleValueInput(rule, source)}<button class="mention-button" type="button" data-mention-action="open" aria-expanded="${isOpen}" title="Bandingkan dengan jawaban pertanyaan lain" aria-label="Sisipkan tag jawaban">@</button></span>`;
    }

    function mentionPicker(rule, available) {
        const candidates = available.filter(item => item.question.id !== rule.question_id);
        return `
            <div class="mention-picker">
                <div class="mention-picker-head"><strong>Sisipkan tag jawaban</strong><input class="setting-select" type="text" maxlength="100" placeholder="Nilai jika kosong" data-mention-fallback aria-label="Nilai cadangan jika pertanyaan belum dijawab"></div>
                <p class="setting-note">${candidates.length ? 'Pilih pertanyaan. Nilai pembanding diambil dari jawaban responden.' : 'Belum ada pertanyaan lain yang bisa dijadikan tag.'}</p>
                <div class="mention-list">
                    ${candidates.map(item => `<button type="button" data-mention-action="insert" data-mention-id="${escapeHtml(item.question.id)}"><b>@</b>${escapeHtml(questionLabel(item))}</button>`).join('')}
                </div>
                <button class="mini-button" type="button" data-mention-action="cancel">Batal</button>
            </div>`;
    }

    function ruleValueInput(rule, source) {
        const options = source && choiceTypes.includes(source.question.type) ? source.question.options : [];
        if (options.length && !['greater_than', 'less_than'].includes(rule.operator)) {
            return `<select class="setting-select" data-rule-field="value" aria-label="Nilai">
                <option value="">Pilih jawaban…</option>
                ${options.map(option => `<option value="${escapeHtml(option)}" ${option === rule.value ? 'selected' : ''}>${escapeHtml(option)}</option>`).join('')}
            </select>`;
        }
        const numeric = ['greater_than', 'less_than'].includes(rule.operator) || ['number', 'currency'].includes(source?.question.type);
        return `<input class="setting-select" type="${numeric ? 'number' : 'text'}" step="any" maxlength="255" value="${escapeHtml(rule.value)}" placeholder="Nilai" data-rule-field="value" aria-label="Nilai">`;
    }

    function ruleEditor(rule, available, scope, path) {
        const source = available.find(item => item.question.id === rule.question_id);
        const isOpen = mentionAt === `${scope}|${path.join('.')}` && !valueless.includes(rule.operator) && !wholeMention(rule.value);
        return `
            <div class="rule-row ${source ? '' : 'is-invalid'}" data-scope="${scope}" data-path="${path.join('.')}">
                <select class="setting-select" data-rule-field="question_id" aria-label="Pertanyaan">
                    ${source ? '' : '<option value="" selected>Pilih pertanyaan…</option>'}
                    ${available.map(item => `<option value="${escapeHtml(item.question.id)}" ${item.question.id === rule.question_id ? 'selected' : ''}>${escapeHtml(questionLabel(item))}</option>`).join('')}
                </select>
                <div class="rule-row-line">
                    <select class="setting-select" data-rule-field="operator" aria-label="Pembanding">
                        ${Object.entries(config.operators).map(([operator, label]) => `<option value="${operator}" ${operator === rule.operator ? 'selected' : ''}>${escapeHtml(label)}</option>`).join('')}
                    </select>
                    ${ruleValueControl(rule, source, available, isOpen)}
                    <button class="question-remove" type="button" data-group-action="remove-rule" aria-label="Hapus aturan">×</button>
                </div>
                ${isOpen ? mentionPicker(rule, available) : ''}
            </div>`;
    }

    function groupEditor(group, available, scope, path = []) {
        const isRoot = path.length === 0;
        return `
            <div class="rule-group ${isRoot ? 'is-root' : 'is-nested'}" data-scope="${scope}" data-path="${path.join('.')}">
                <div class="rule-group-head">
                    <select class="setting-select" data-group-field="operator" aria-label="Cara menggabungkan aturan">
                        <option value="and" ${group.operator === 'and' ? 'selected' : ''}>Semua aturan terpenuhi (DAN)</option>
                        <option value="or" ${group.operator === 'or' ? 'selected' : ''}>Salah satu terpenuhi (ATAU)</option>
                    </select>
                    ${isRoot ? '' : '<button class="question-remove" type="button" data-group-action="remove-group" aria-label="Hapus grup aturan">×</button>'}
                </div>
                ${group.rules.map((rule, index) => (rule.rules ? groupEditor(rule, available, scope, [...path, index]) : ruleEditor(rule, available, scope, [...path, index]))).join('')}
                <div class="rule-group-add">
                    <button class="mini-button" type="button" data-group-action="add-rule">+ Aturan</button>
                    ${isRoot ? '<button class="mini-button" type="button" data-group-action="add-group">+ Grup aturan</button>' : ''}
                </div>
            </div>`;
    }

    function scopeRoot(question, scope) {
        if (scope === 'logic') return question.logic?.conditions;
        return question.validation.checks[Number(scope.split(':')[1])]?.conditions;
    }

    function parsePath(value) {
        return value ? value.split('.').map(Number) : [];
    }

    function nodeAt(root, path) {
        return path.reduce((node, index) => node?.rules[index], root);
    }

    function scopeAvailable(question, scope) {
        return earlierQuestions('question', question.id, scope !== 'logic');
    }

    // ── Canvas rendering ───────────────────────────────────────────────────

    function regionPreview(question) {
        const fields = question.region.fields.filter(field => field.enabled);
        if (!fields.length) return '<p class="block-empty">Aktifkan minimal satu isian wilayah di panel kanan.</p>';
        return `<div class="region-preview">${fields.map(field => `
            <div class="region-cell ${field.mode === 'fixed' ? 'is-fixed' : ''}">
                <small>${escapeHtml(field.label)}</small>
                <span>${field.mode === 'fixed'
                    ? escapeHtml(`${field.code ? `[${field.code}] ` : ''}${field.name}` || '(belum ditetapkan)')
                    : '<em>diisi responden</em>'}</span>
            </div>`).join('')}</div>`;
    }

    function tableEditor(question) {
        const table = question.table;
        const columnCells = () => table.columns.map(() => '<td></td>').join('') + '<td class="col-add-cell"></td>';
        const header = table.columns.map(column => `
            <th class="table-col" data-col-id="${escapeHtml(column.id)}">
                <div class="col-head">
                    <span class="drag-handle" data-col-drag title="Seret untuk memindahkan kolom">⠿</span>
                    <button class="question-remove" type="button" data-action="remove-col" aria-label="Hapus kolom">×</button>
                </div>
                <input class="col-label" type="text" maxlength="120" value="${escapeHtml(column.label)}" placeholder="Judul kolom" data-field="col-label">
                <select class="col-type" data-field="col-type" aria-label="Jenis kolom">
                    ${Object.entries(config.tableColumnTypes).map(([type, label]) => `<option value="${type}" ${type === column.type ? 'selected' : ''}>${escapeHtml(label)}</option>`).join('')}
                </select>
                ${column.type === 'choice' ? `<input class="col-options" type="text" value="${escapeHtml(column.options.join('; '))}" placeholder="Opsi; pisahkan ;" data-field="col-options">` : ''}
            </th>`).join('');
        const body = table.mode === 'fixed'
            ? table.rows.map((row, index) => `
                <tr>
                    <th class="row-head"><div class="row-label"><input type="text" maxlength="120" value="${escapeHtml(row)}" placeholder="Nama baris" data-field="row-label" data-row-index="${index}"><button class="question-remove" type="button" data-action="remove-row" data-row-index="${index}" aria-label="Hapus baris">×</button></div></th>
                    ${columnCells()}
                </tr>`).join('') + `<tr><th class="row-head"><button class="mini-button" type="button" data-action="add-row">+ Baris</button></th>${columnCells()}</tr>`
            : Array.from({ length: table.initial_rows }, (_, index) => `
                <tr>
                    <th class="row-head"><div class="row-label"><span>${index + 1}</span>${table.initial_rows > 1 ? `<button class="question-remove" type="button" data-action="remove-row" aria-label="Hapus baris ${index + 1}">×</button>` : ''}</div></th>
                    ${columnCells()}
                </tr>`).join('')
              + `<tr><th class="row-head"><button class="mini-button" type="button" data-action="add-row" ${table.initial_rows >= table.max_rows ? 'disabled' : ''}>+ Baris</button></th>${columnCells()}</tr>`
              + `<tr><td class="table-note" colspan="${table.columns.length + 2}">Responden mulai dengan ${table.initial_rows} baris dan dapat menambah hingga ${table.max_rows} baris</td></tr>`;

        return `
            <div class="table-editor">
                <table class="table-preview">
                    <thead>
                        <tr><th class="row-head">${table.mode === 'fixed' ? 'Uraian' : 'No'}</th>${header}<th class="col-add-cell"><button class="mini-button" type="button" data-action="add-col" ${table.columns.length >= config.maxTableColumns ? 'disabled' : ''}>+ Kolom</button></th></tr>
                        <tr class="col-numbers"><td></td>${table.columns.map((column, index) => `<td>(${index + 1})</td>`).join('')}<td></td></tr>
                    </thead>
                    <tbody>${body}</tbody>
                </table>
            </div>`;
    }

    // "If the answer is X, ask …": one button per option adds a hidden follow-up that shows on that answer.
    function branchActions(question) {
        if (!question.options.length) return '';
        return `
            <div class="branch-actions">
                <span>Pertanyaan lanjutan:</span>
                ${question.options.map(option => `<button class="mini-button" type="button" data-action="add-branch" data-option="${escapeHtml(option)}" title="Tambah pertanyaan yang hanya muncul jika jawabannya “${escapeHtml(option)}”">+ jika “${escapeHtml(truncate(option, 18))}”</button>`).join('')}
            </div>`;
    }

    function rangeValues(question) {
        const { min, max, step } = question.range;
        const values = [];
        for (let value = min; value <= max && values.length < config.maxRangePoints; value += step) values.push(value);
        return values;
    }

    function matrixPreview(question) {
        const { rows, columns } = question.matrix;
        if (!rows.length || columns.length < 2) return '<p class="block-empty">Tulis minimal satu baris dan dua kolom pilihan.</p>';
        return `<table class="matrix-preview">
            <thead><tr><td></td>${columns.map(column => `<th>${escapeHtml(column)}</th>`).join('')}</tr></thead>
            <tbody>${rows.map(row => `<tr><th>${escapeHtml(row)}</th>${columns.map(() => '<td><i></i></td>').join('')}</tr>`).join('')}</tbody>
        </table>`;
    }

    function previewFor(question) {
        if (choiceTypes.includes(question.type)) {
            const options = question.options.length ? question.options : ['Pilihan 1', 'Pilihan 2'];
            const branches = branchActions(question);
            if (question.type === 'dropdown') return `<div class="answer-line answer-select">${escapeHtml(question.placeholder || options[0])}<span>▾</span></div>${branches}`;
            return `<div class="choice-list">${options.map((option, index) => `<span class="choice-option ${question.type === 'multiple' ? 'multiple' : ''}"><i></i><b>${index + 1}.</b> ${escapeHtml(option)}</span>`).join('')}</div>${branches}`;
        }
        const hint = question.prefill || question.placeholder;
        switch (question.type) {
            case 'region': return regionPreview(question);
            case 'table': return tableEditor(question);
            case 'info':
            case 'code': return '';
            case 'long': return `<div class="answer-area">${escapeHtml(hint || 'Jawaban...')}</div>`;
            case 'date': return `<div class="answer-line">${escapeHtml(question.prefill || 'Tgl ___ / Bln ___ / Thn _____')}</div>`;
            case 'number': return `<div class="answer-line">${escapeHtml(hint || 'Masukkan angka')}</div>`;
            case 'currency': return `<div class="answer-line"><b>Rp</b> ${escapeHtml(hint || '........................')}</div>`;
            case 'email': return `<div class="answer-line">${escapeHtml(hint || 'nama@contoh.go.id')}</div>`;
            case 'phone': return `<div class="answer-line">${escapeHtml(hint || '08xx-xxxx-xxxx')}</div>`;
            case 'url': return `<div class="answer-line">${escapeHtml(hint || 'https://')}</div>`;
            case 'checkbox': return '<span class="check-preview"><i></i> Ya</span>';
            case 'rating': return `<div class="range-preview is-rating">${'<i>★</i>'.repeat(rangeValues(question).length)}</div>`;
            case 'scale': return `<div class="range-preview">${rangeValues(question).map(value => `<i>${value}</i>`).join('')}</div>`;
            case 'slider': return `<div class="range-preview is-slider">${question.range.min}<b></b>${question.range.max}</div>`;
            case 'matrix': return matrixPreview(question);
            case 'signature': return '<div class="sign-preview">Kotak tanda tangan responden</div>';
            default: return `<div class="answer-line">${escapeHtml(hint || 'Jawaban...')}</div>`;
        }
    }

    // Code boxes on the right of a question, with − / + to change how many there are.
    function codeBoxes(question) {
        if (noCodeTypes.includes(question.type)) return '<span class="code-field"></span>';
        const length = question.code_length;
        return `
            <span class="code-field ${length ? '' : 'is-empty'}">
                <button class="code-step" type="button" data-action="code-less" aria-label="Kurangi kotak kode" ${length === 0 ? 'disabled' : ''}>−</button>
                <span class="code-boxes" aria-label="${length} kotak kode">${'<i></i>'.repeat(length)}</span>
                <button class="code-step" type="button" data-action="code-more" aria-label="Tambah kotak kode" ${length >= config.maxCodeLength ? 'disabled' : ''}>${length ? '+' : '+ kode'}</button>
            </span>`;
    }

    const ICONS = {
        plus: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>',
        gear: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/></svg>',
        trash: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 11v6M14 11v6"/></svg>',
    };

    function questionRow(question) {
        const number = numbers.get(question.id);
        const isInfo = question.type === 'info';
        const isBranch = question.hidden && question.logic?.actions.includes('show');
        const classes = ['question-row', `is-${question.type}`, isBranch && 'is-branch', question.hidden && !isBranch && 'is-hidden-field', isSelected('question', question.id) && 'is-selected', question.required && 'is-required'].filter(Boolean).join(' ');
        const subject = isInfo ? 'keterangan' : `pertanyaan ${number}`;
        const description = isInfo
            ? `<textarea class="question-desc" rows="2" maxlength="1000" placeholder="Isi keterangan untuk responden" data-field="description">${escapeHtml(question.description)}</textarea>`
            : `<input class="question-desc" type="text" maxlength="1000" value="${escapeHtml(question.description)}" placeholder="Petunjuk pengisian (opsional)" data-field="description">`;
        return `
            <article class="${classes}" data-question-id="${escapeHtml(question.id)}">
                <span class="drag-handle" data-drag-handle title="Seret untuk memindahkan">⠿</span>
                <span class="question-number">${isInfo ? 'i' : number}</span>
                <div class="question-main">
                    <input class="question-label" type="text" maxlength="255" value="${escapeHtml(question.label)}" placeholder="${isInfo ? 'Judul keterangan' : 'Tulis pertanyaan...'}" data-field="label" aria-label="Teks ${subject}">
                    ${description}
                    <span class="logic-slot">${logicBadge('question', question.id)}</span>
                    <div class="question-preview">${previewFor(question)}</div>
                    ${choiceTypes.includes(question.type) ? `<label class="option-editor"><span>PILIHAN</span><textarea data-field="options" aria-label="Pilihan jawaban, satu per baris" placeholder="Satu pilihan per baris">${escapeHtml(question.options.join('\n'))}</textarea></label>` : ''}
                    ${question.type === 'matrix' ? `<div class="matrix-editor">
                        <label class="option-editor"><span>BARIS</span><textarea data-field="matrix-rows" aria-label="Baris matriks, satu per baris" placeholder="Satu pernyataan per baris">${escapeHtml(question.matrix.rows.join('\n'))}</textarea></label>
                        <label class="option-editor"><span>KOLOM</span><textarea data-field="matrix-columns" aria-label="Kolom pilihan matriks, satu per baris" placeholder="Satu pilihan per baris">${escapeHtml(question.matrix.columns.join('\n'))}</textarea></label>
                    </div>` : ''}
                </div>
                ${codeBoxes(question)}
                <div class="question-tools">
                    <button type="button" data-action="insert-after" title="Tambah pertanyaan di bawahnya" aria-label="Tambah pertanyaan di bawah ${subject}">${ICONS.plus}</button>
                    <button type="button" data-action="open-settings" title="Pengaturan isian: opsi, logika, validasi" aria-label="Pengaturan ${subject}">${ICONS.gear}</button>
                    <button type="button" class="is-danger" data-action="remove-question" title="Hapus" aria-label="Hapus ${subject}">${ICONS.trash}</button>
                </div>
            </article>`;
    }

    function blockSection(block, blockIndex) {
        const roman = toRoman(blockIndex + 1);
        const classes = ['form-block', block.layout === 'half' && 'is-half', isSelected('block', block.id) && 'is-selected'].filter(Boolean).join(' ');
        return `
            <section class="${classes}" data-block-id="${escapeHtml(block.id)}">
                <header class="block-head">
                    <span class="drag-handle" data-drag-handle title="Seret untuk memindahkan blok">⠿</span>
                    <span class="block-prefix">BLOK ${roman}.</span>
                    <input class="block-title" type="text" maxlength="160" value="${escapeHtml(block.title)}" placeholder="JUDUL BLOK" data-field="block-title" aria-label="Judul blok ${roman}">
                    ${block.orientation ? `<span class="orientation-chip" title="Blok ini dicetak pada halaman ${block.orientation === 'landscape' ? 'lanskap' : 'potret'}">${block.orientation === 'landscape' ? '▭ Lanskap' : '▯ Potret'}</span>` : ''}
                    <span class="logic-slot">${logicBadge('block', block.id)}</span>
                    <button class="question-remove" type="button" data-action="remove-block" aria-label="Hapus blok ${roman}">×</button>
                </header>
                <div class="block-body">
                    ${block.questions.length
                        ? block.questions.map(questionRow).join('')
                        : '<div class="block-empty">Tarik jenis pertanyaan ke blok ini</div>'}
                </div>
            </section>`;
    }

    function render() {
        numbers = computeNumbers();
        blockList.innerHTML = blocks.map(blockSection).join('');
        blockCount.textContent = blocks.length;
        questionCount.textContent = numbers.size;
        hint.hidden = blocks.length > 0;
        renderSelectionPanel();
    }

    function refreshPreview(question) {
        const row = blockList.querySelector(`[data-question-id="${CSS.escape(question.id)}"]`);
        if (row) row.querySelector('.question-preview').innerHTML = previewFor(question);
    }

    // ── Settings panel ─────────────────────────────────────────────────────

    function showPane(name) {
        document.querySelectorAll('[data-panel-tab]').forEach(tab => tab.setAttribute('aria-selected', String(tab.dataset.panelTab === name)));
        document.querySelectorAll('[data-panel-pane]').forEach(pane => (pane.hidden = pane.dataset.panelPane !== name));
    }

    function regionSettings(question) {
        return `
            <div class="setting-label"><span>Isian wilayah</span><span class="setting-value">ATUR</span></div>
            <p class="setting-note">“Ditetapkan” tampil sebagai ringkasan (mis. [35] JAWA TIMUR); “Diisi responden” menjadi kolom isian.</p>
            <div class="region-config">
                ${question.region.fields.map(field => `
                    <div class="region-field ${field.enabled ? '' : 'is-off'}" data-region-key="${field.key}">
                        <label class="region-enable"><input type="checkbox" data-region-field="enabled" ${field.enabled ? 'checked' : ''}> ${escapeHtml(field.label)}</label>
                        ${field.enabled ? `
                            <select class="setting-select" data-region-field="mode" aria-label="Cara pengisian ${escapeHtml(field.label)}">
                                <option value="input" ${field.mode === 'input' ? 'selected' : ''}>Diisi responden</option>
                                <option value="fixed" ${field.mode === 'fixed' ? 'selected' : ''}>Ditetapkan</option>
                            </select>
                            ${field.mode === 'fixed' ? (field.key === 'klasifikasi'
                                ? `<select class="setting-select" data-region-field="name" aria-label="Klasifikasi">${['', 'PERKOTAAN', 'PERDESAAN'].map(value => `<option value="${value}" ${value === field.name ? 'selected' : ''}>${value || 'Pilih…'}</option>`).join('')}</select>`
                                : `<div class="region-fixed"><input class="setting-select" type="text" maxlength="20" value="${escapeHtml(field.code)}" placeholder="Kode" data-region-field="code"><input class="setting-select" type="text" maxlength="120" value="${escapeHtml(field.name)}" placeholder="Nama" data-region-field="name"></div>`) : ''}
                        ` : ''}
                    </div>`).join('')}
            </div>`;
    }

    function tableSettings(question) {
        const table = question.table;
        return `
            <div class="setting-label"><span>Tabel</span><span class="setting-value">${table.columns.length} KOLOM</span></div>
            <div class="segmented">
                <label><input class="sr-only" type="radio" name="table-mode-${escapeHtml(question.id)}" value="dynamic" data-setting="table-mode" ${table.mode === 'dynamic' ? 'checked' : ''}><span>Baris diisi responden</span></label>
                <label><input class="sr-only" type="radio" name="table-mode-${escapeHtml(question.id)}" value="fixed" data-setting="table-mode" ${table.mode === 'fixed' ? 'checked' : ''}><span>Baris tetap</span></label>
            </div>
            ${table.mode === 'dynamic'
                ? `<label class="setting-field"><span>Maksimal baris</span><input class="setting-select" type="number" min="1" max="${config.maxTableRows}" value="${table.max_rows}" data-setting="max_rows"></label><label class="setting-field"><span>Baris awal <small>(tampil ke responden dan saat dicetak)</small></span><input class="setting-select" type="number" min="1" max="${table.max_rows}" value="${table.initial_rows}" data-setting="initial_rows"></label>`
                : '<p class="setting-note">Tulis nama baris langsung di tabel, mis. Dibayar / Tidak dibayar / Total.</p>'}
            <p class="setting-note">Judul, jenis, dan urutan kolom diatur langsung di tabel pada lembar.</p>`;
    }

    function rangeSettings(question) {
        const { min, max, step } = question.range;
        const field = (key, label, value, lowest) => `<label class="setting-field"><span>${label}</span><input class="setting-select" type="number" min="${lowest}" max="${config.rangeLimit}" value="${value}" data-setting="range-${key}"></label>`;
        if (question.type === 'rating') {
            return `<div class="setting-label"><span>Rating</span><span class="setting-value">${max} BINTANG</span></div>${field('max', `Jumlah bintang <small>(2–${config.maxRangePoints})</small>`, max, 2)}`;
        }
        return `
            <div class="setting-label"><span>Rentang nilai</span><span class="setting-value">${min}–${max}</span></div>
            <div class="range-settings">${field('min', 'Terkecil', min, -config.rangeLimit)}${field('max', 'Terbesar', max, -config.rangeLimit)}${field('step', 'Kelipatan', step, 1)}</div>
            ${question.type === 'scale' ? `<p class="setting-note">Skala menampilkan satu tombol per nilai, maksimal ${config.maxRangePoints} nilai.</p>` : ''}`;
    }

    function flagButton(question, flag, label, icon) {
        return `<button class="flag-toggle" type="button" data-flag="${flag}" aria-pressed="${question[flag] ? 'true' : 'false'}"><span aria-hidden="true">${icon}</span>${label}</button>`;
    }

    function optionsTab(question) {
        const isInfo = question.type === 'info';
        const hasCodeBoxes = !noCodeTypes.includes(question.type);
        const prefill = config.prefillTypes.includes(question.type);
        const placeholder = config.placeholderTypes.includes(question.type);
        const prefillControl = ['single', 'dropdown'].includes(question.type)
            ? `<select class="setting-select" data-option="prefill"><option value="">(tanpa nilai awal)</option>${question.options.map(option => `<option value="${escapeHtml(option)}" ${option === question.prefill ? 'selected' : ''}>${escapeHtml(option)}</option>`).join('')}</select>`
            : `<input class="setting-select" type="${question.type === 'date' ? 'date' : (['number', 'currency'].includes(question.type) ? 'number' : 'text')}" maxlength="255" value="${escapeHtml(question.prefill)}" data-option="prefill">`;
        return `
            <label class="setting-field"><span>Nama isian</span><input class="setting-select" type="text" maxlength="255" value="${escapeHtml(question.label)}" data-option="label"></label>
            <div class="flag-toggles">
                ${isInfo ? '' : flagButton(question, 'required', 'Wajib', '✱')}
                ${flagButton(question, 'hidden', 'Tersembunyi', '◌')}
                ${isInfo ? '' : flagButton(question, 'disabled', 'Nonaktif', '⊘')}
            </div>
            ${question.hidden ? '<p class="setting-note">Isian tersembunyi tidak tampil dan tidak wajib diisi, kecuali dimunculkan lewat tab Logika.</p>' : ''}
            ${question.disabled ? '<p class="setting-note">Isian nonaktif tampil tetapi tidak bisa diubah responden; nilainya diambil dari nilai awal.</p>' : ''}
            <label class="setting-field"><span>Jenis isian</span>
                <select class="setting-select" data-setting="type">
                    ${Object.entries(config.questionTypes).map(([type, label]) => `<option value="${type}" ${type === question.type ? 'selected' : ''}>${escapeHtml(label)}</option>`).join('')}
                </select>
            </label>
            ${hasCodeBoxes ? `<label class="setting-field"><span>Jumlah kotak kode <small>(0–${config.maxCodeLength})</small></span>
                <input class="setting-select" type="number" min="0" max="${config.maxCodeLength}" value="${question.code_length}" data-setting="code_length"></label>` : ''}
            ${question.type === 'region' ? `<div class="type-settings">${regionSettings(question)}</div>` : ''}
            ${question.type === 'table' ? `<div class="type-settings">${tableSettings(question)}</div>` : ''}
            ${rangeTypes.includes(question.type) ? `<div class="type-settings">${rangeSettings(question)}</div>` : ''}
            <div class="option-section">
                <div class="setting-label"><span>Kustomisasi</span></div>
                ${isInfo ? '' : `<label class="required-toggle"><input type="checkbox" data-option="hide_label" ${question.hide_label ? 'checked' : ''}><span class="toggle-track"></span><span>Sembunyikan nama isian</span></label>`}
                ${prefill ? `<label class="setting-field"><span>Nilai awal</span>${prefillControl}</label>` : ''}
                ${placeholder ? `<label class="setting-field"><span>Teks contoh (placeholder)</span><input class="setting-select" type="text" maxlength="255" value="${escapeHtml(question.placeholder)}" data-option="placeholder"></label>` : ''}
                <div class="setting-field"><span>Lebar isian (formulir responden)</span>
                    <div class="width-options">
                        ${Object.entries(config.fieldWidths).map(([width, label]) => `<label><input class="sr-only" type="radio" name="width-${escapeHtml(question.id)}" value="${width}" data-option="width" ${question.width === width ? 'checked' : ''}><span>${escapeHtml(label)}</span></label>`).join('')}
                    </div>
                </div>
                <label class="setting-field"><span>${isInfo ? 'Isi keterangan' : 'Teks bantuan'}</span><textarea class="setting-select setting-textarea" rows="3" maxlength="1000" data-option="description">${escapeHtml(question.description)}</textarea></label>
                ${isInfo || ['region', 'table', 'matrix'].includes(question.type) ? '' : `<div class="setting-field"><span>Posisi teks bantuan</span>
                    <div class="segmented">
                        <label><input class="sr-only" type="radio" name="help-${escapeHtml(question.id)}" value="below" data-option="help_position" ${question.help_position === 'below' ? 'checked' : ''}><span>Di bawah isian</span></label>
                        <label><input class="sr-only" type="radio" name="help-${escapeHtml(question.id)}" value="above" data-option="help_position" ${question.help_position === 'above' ? 'checked' : ''}><span>Di atas isian</span></label>
                    </div></div>`}
                ${['short', 'long'].includes(question.type) ? `<label class="setting-field"><span>Batas karakter <small>(kosong = bawaan)</small></span><input class="setting-select" type="number" min="1" max="5000" value="${question.max_length ?? ''}" data-option="max_length"></label>` : ''}
            </div>`;
    }

    function logicTab(question) {
        const available = earlierQuestions('question', question.id);
        const others = blocks.flatMap(block => block.questions).filter(item => item.id !== question.id && item.type !== 'info');
        const withLogic = others.filter(item => item.logic);
        const otherLabel = item => `${numbers.get(item.id) ?? 'i'} ${truncate(item.label || '(tanpa teks)', 28)}`;
        const tools = `
            <div class="logic-tools">
                <select class="setting-select" data-logic-tool="copy-from" aria-label="Salin logika dari" ${withLogic.length ? '' : 'disabled'}>
                    <option value="">Salin dari…</option>
                    ${withLogic.map(item => `<option value="${escapeHtml(item.id)}">${escapeHtml(otherLabel(item))}</option>`).join('')}
                </select>
                <select class="setting-select" data-logic-tool="copy-to" aria-label="Salin logika ke" ${question.logic && others.length ? '' : 'disabled'}>
                    <option value="">Salin ke…</option>
                    ${others.map(item => `<option value="${escapeHtml(item.id)}">${escapeHtml(otherLabel(item))}</option>`).join('')}
                </select>
                <button class="mini-button" type="button" data-logic-tool="clear" ${question.logic ? '' : 'disabled'}>Hapus</button>
            </div>`;

        if (!available.length) return `${tools}<p class="setting-note">Belum ada pertanyaan sebelumnya yang bisa dijadikan syarat.</p>`;

        const conditions = question.logic
            ? groupEditor(question.logic.conditions, available, 'logic')
            : '<button class="mini-button is-wide" type="button" data-logic-tool="start">+ Tambah aturan</button>';
        const actions = allowedActions(question).map(action => `
            <label class="action-option"><input type="checkbox" data-logic-action="${action}" ${question.logic?.actions.includes(action) ? 'checked' : ''} ${question.logic ? '' : 'disabled'}><span>${escapeHtml(config.logicActions[action][0].toUpperCase() + config.logicActions[action].slice(1))} isian ini</span></label>`).join('');
        const problem = logicProblem(question);
        return `
            ${tools}
            <div class="logic-step"><span>Jika kondisi berikut terpenuhi</span></div>
            ${conditions}
            <div class="logic-then">maka</div>
            <div class="logic-step"><span>Lakukan tindakan berikut</span></div>
            <div class="action-list">${actions}</div>
            ${problem ? `<p class="setting-note is-warning">${escapeHtml(problem)}</p>` : ''}
            <p class="setting-note">Isian tersembunyi tidak pernah wajib diisi. Aturan hanya bisa merujuk pertanyaan sebelumnya.</p>`;
    }

    function validationTab(question) {
        const available = earlierQuestions('question', question.id, true);
        const ruleTypes = Object.entries(config.validationRules).filter(([, rule]) => rule.types.includes(question.type));
        const checks = question.validation.checks.map((check, index) => `
            <div class="check-card is-${check.level}" data-check-index="${index}">
                <div class="check-card-head">
                    <select class="setting-select" data-check-field="level" aria-label="Tingkat pemeriksaan">
                        ${Object.entries(config.checkLevels).map(([level, label]) => `<option value="${level}" ${level === check.level ? 'selected' : ''}>${escapeHtml(label)}</option>`).join('')}
                    </select>
                    <button class="question-remove" type="button" data-check-action="remove" aria-label="Hapus pemeriksaan">×</button>
                </div>
                <p class="setting-note">Tampilkan pesan jika:</p>
                ${groupEditor(check.conditions, available, `check:${index}`)}
                <label class="setting-field"><span>Pesan</span><input class="setting-select" type="text" maxlength="255" value="${escapeHtml(check.message)}" placeholder="mis. Apakah benar responden laki-laki sedang hamil?" data-check-field="message"></label>
            </div>`).join('');
        const rules = question.validation.rules.map((rule, index) => {
            const input = config.validationRules[rule.type]?.input ?? 'text';
            return `
                <div class="vrule" data-rule-index="${index}">
                    <select class="setting-select" data-vrule-field="type" aria-label="Jenis aturan">
                        ${ruleTypes.map(([type, definition]) => `<option value="${type}" ${type === rule.type ? 'selected' : ''}>${escapeHtml(definition.label)}</option>`).join('')}
                    </select>
                    <div class="rule-row-line">
                        <input class="setting-select" type="${input}" value="${escapeHtml(rule.value)}" placeholder="Nilai" data-vrule-field="value" aria-label="Nilai aturan">
                        <button class="question-remove" type="button" data-vrule-action="remove" aria-label="Hapus aturan">×</button>
                    </div>
                </div>`;
        }).join('');
        const problem = validationProblemOf(question);

        return `
            <div class="logic-step"><span>Pemeriksaan konsistensi</span></div>
            <p class="setting-note">Munculkan galat atau peringatan saat jawaban tidak masuk akal, mis. 103 Jenis kelamin = Laki-laki <b>dan</b> pertanyaan ini = Ya (hamil). Galat harus diperbaiki; peringatan boleh dikonfirmasi responden.</p>
            ${available.length ? checks : '<p class="setting-note">Jenis isian ini belum bisa dijadikan syarat.</p>'}
            ${available.length && question.validation.checks.length < config.maxChecks ? '<button class="mini-button is-wide" type="button" data-check-action="add">+ Tambah pemeriksaan</button>' : ''}
            ${ruleTypes.length ? `
                <div class="logic-step"><span>Aturan isian</span></div>
                ${rules}
                ${question.validation.rules.length < 10 ? '<button class="mini-button is-wide" type="button" data-vrule-action="add">+ Tambah aturan isian</button>' : ''}
                <label class="setting-field"><span>Pesan bila aturan isian tidak terpenuhi <small>(opsional)</small></span><input class="setting-select" type="text" maxlength="255" value="${escapeHtml(question.validation.message)}" data-vrule-field="message"></label>` : ''}
            ${problem ? `<p class="setting-note is-warning">${escapeHtml(problem)}</p>` : ''}`;
    }

    function renderSelectionPanel() {
        const foundQuestion = selected?.kind === 'question' ? findQuestion(selected.id) : null;
        const foundBlock = selected?.kind === 'block' ? findBlock(selected.id) : null;

        if (foundQuestion) {
            const { question, blockIndex } = foundQuestion;
            const isInfo = question.type === 'info';
            const logicCount = question.logic ? 1 : 0;
            const validationCount = question.validation.rules.length + question.validation.checks.length;
            const body = fieldTab === 'logic' ? logicTab(question) : (fieldTab === 'validation' ? validationTab(question) : optionsTab(question));
            selectionPanel.innerHTML = `
                <div class="field-head">
                    <span class="field-type">${escapeHtml(config.questionTypes[question.type])}</span>
                    <strong>${isInfo ? 'Keterangan' : `Pertanyaan ${numbers.get(question.id)}`}</strong>
                    <span class="setting-value">BLOK ${toRoman(blockIndex + 1)}</span>
                </div>
                <div class="field-tabs" role="tablist">
                    <button type="button" role="tab" data-field-tab="options" aria-selected="${fieldTab === 'options'}">Opsi</button>
                    <button type="button" role="tab" data-field-tab="logic" aria-selected="${fieldTab === 'logic'}">Logika${logicCount ? ' <b>•</b>' : ''}</button>
                    <button type="button" role="tab" data-field-tab="validation" aria-selected="${fieldTab === 'validation'}" ${isInfo ? 'disabled' : ''}>Validasi${validationCount ? ` <b>${validationCount}</b>` : ''}</button>
                </div>
                <div class="field-tab-body">${body}</div>
                <button class="danger-link" type="button" data-setting-action="remove-question">Hapus ${isInfo ? 'keterangan' : 'pertanyaan'}</button>`;
            return;
        }

        if (foundBlock) {
            const { block, blockIndex } = foundBlock;
            selectionPanel.innerHTML = `
                <div class="setting-label"><span>Blok ${toRoman(blockIndex + 1)}</span><span class="setting-value">${block.questions.length} ISIAN</span></div>
                <p class="setting-note">Ubah judul langsung di lembar. Tarik ⠿ pada kepala blok untuk mengubah urutan.</p>
                <div class="setting-field"><span>Lebar blok (lembar cetak)</span>
                    <div class="segmented">
                        <label><input class="sr-only" type="radio" name="block-layout" value="full" data-setting="layout" ${block.layout !== 'half' ? 'checked' : ''}><span>Penuh</span></label>
                        <label><input class="sr-only" type="radio" name="block-layout" value="half" data-setting="layout" ${block.layout === 'half' ? 'checked' : ''}><span>Setengah</span></label>
                    </div>
                </div>
                <div class="setting-field"><span>Orientasi halaman (lembar cetak)</span>
                    <div class="width-options">
                        ${[['', 'Ikut formulir'], ['portrait', 'Potret'], ['landscape', 'Lanskap']].map(([value, label]) => `<label><input class="sr-only" type="radio" name="block-orientation" value="${value}" data-setting="orientation" ${block.orientation === value ? 'checked' : ''}><span>${label}</span></label>`).join('')}
                    </div>
                </div>
                <p class="setting-note">Blok dengan orientasi berbeda dicetak mulai halaman baru, jadi satu kuesioner bisa berisi halaman potret dan lanskap.</p>
                <label class="setting-field"><span>Tambah isian ke blok ini</span>
                    <select class="setting-select" data-setting="add-question">
                        <option value="">Pilih jenis…</option>
                        ${Object.entries(config.questionTypes).map(([type, label]) => `<option value="${type}">${escapeHtml(label)}</option>`).join('')}
                    </select>
                </label>
                <div class="logic-editor" id="logic-editor"></div>
                <button class="danger-link" type="button" data-setting-action="remove-block">Hapus blok</button>`;
            renderLogicEditor();
            return;
        }

        selectionPanel.innerHTML = `
            <div class="setting-label"><span>Elemen dipilih</span><span class="setting-value">EDIT</span></div>
            <div class="selected-placeholder"><span class="cursor-icon">↖</span><span>Pilih blok atau pertanyaan di lembar, atau klik ⚙ pada pertanyaan untuk mengatur opsi, logika, dan validasinya.</span></div>`;
    }

    function select(kind, id) {
        showPane('item');
        if (isSelected(kind, id)) return;
        selected = { kind, id };
        mentionAt = null;
        blockList.querySelectorAll('.is-selected').forEach(element => element.classList.remove('is-selected'));
        const attribute = kind === 'block' ? 'data-block-id' : 'data-question-id';
        blockList.querySelector(`[${attribute}="${CSS.escape(id)}"]`)?.classList.add('is-selected');
        renderSelectionPanel();
    }

    function focusField(selector) {
        const field = blockList.querySelector(selector);
        field?.focus();
        field?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function selectionContext() {
        if (selected?.kind === 'question') {
            const found = findQuestion(selected.id);
            if (found) return { block: found.block, blockIndex: found.blockIndex, questionIndex: found.questionIndex };
        }
        if (selected?.kind === 'block') {
            const found = findBlock(selected.id);
            if (found) return { block: found.block, blockIndex: found.blockIndex, questionIndex: null };
        }
        return null;
    }

    // ── Editing ────────────────────────────────────────────────────────────

    function addBlock() {
        const block = makeBlock();
        const context = selectionContext();
        if (context) blocks.splice(context.blockIndex + 1, 0, block);
        else blocks.push(block);
        selected = { kind: 'block', id: block.id };
        render();
        focusField(`[data-block-id="${CSS.escape(block.id)}"] .block-title`);
    }

    function addQuestion(type, targetBlock = null) {
        const question = makeQuestion(type);
        const context = targetBlock ? null : selectionContext();
        let block = targetBlock ?? context?.block ?? blocks.at(-1);
        if (!block) {
            block = makeBlock('KETERANGAN UMUM');
            blocks.push(block);
        }
        if (context?.questionIndex !== null && context?.questionIndex !== undefined) block.questions.splice(context.questionIndex + 1, 0, question);
        else block.questions.push(question);
        selected = { kind: 'question', id: question.id };
        fieldTab = 'options';
        render();
        focusField(`[data-question-id="${CSS.escape(question.id)}"] .question-label`);
    }

    function removeQuestion(id) {
        const found = findQuestion(id);
        if (!found) return;
        found.block.questions.splice(found.questionIndex, 1);
        clearConditionsOn([id]);
        const neighbour = found.block.questions[Math.max(0, found.questionIndex - 1)];
        selected = neighbour ? { kind: 'question', id: neighbour.id } : { kind: 'block', id: found.block.id };
        render();
    }

    function removeBlock(id) {
        const found = findBlock(id);
        if (!found) return;
        const questionTotal = found.block.questions.length;
        if (questionTotal && !window.confirm(`Hapus Blok ${toRoman(found.blockIndex + 1)} beserta ${questionTotal} isiannya?`)) return;
        blocks.splice(found.blockIndex, 1);
        clearConditionsOn(found.block.questions.map(question => question.id));
        const neighbour = blocks[Math.max(0, found.blockIndex - 1)];
        selected = neighbour ? { kind: 'block', id: neighbour.id } : null;
        render();
    }

    function changeType(question, type) {
        question.type = type;
        if (choiceTypes.includes(type) && !question.options.length) question.options = ['Pilihan 1', 'Pilihan 2'];
        if (type === 'region' && !question.region) question.region = defaultRegion();
        if (type === 'table' && !question.table) question.table = defaultTable();
        if (rangeTypes.includes(type)) question.range = { ...config.rangeDefaults[type] };
        if (type === 'matrix' && !question.matrix) question.matrix = defaultMatrix();
        if (noCodeTypes.includes(type)) question.code_length = 0;
        if (nonLogicTypes.includes(type)) clearConditionsOn([question.id]);
        if (!config.prefillTypes.includes(type)) question.prefill = '';
        if (type === 'info') {
            question.required = false;
            question.disabled = false;
        }
        question.validation.rules = question.validation.rules.filter(rule => config.validationRules[rule.type].types.includes(type));
    }

    // Toggling a base flag flips its logic action, e.g. a newly hidden field's "hide" becomes "show".
    function toggleFlag(question, flag) {
        question[flag] = !question[flag];
        const pairs = { hidden: ['hide', 'show'], disabled: ['disable', 'enable'], required: ['require', 'optional'] };
        const [whenOff, whenOn] = pairs[flag];
        if (question.logic) {
            question.logic.actions = question.logic.actions.map(action => {
                if (action === whenOff && question[flag]) return whenOn;
                if (action === whenOn && !question[flag]) return whenOff;
                return action;
            });
        }
    }

    function refreshCodeBoxes(question) {
        const field = blockList.querySelector(`[data-question-id="${CSS.escape(question.id)}"] .code-field`);
        if (field) field.outerHTML = codeBoxes(question);
        const input = selectionPanel.querySelector('[data-setting="code_length"]');
        if (input && isSelected('question', question.id)) input.value = question.code_length;
    }

    function tableContext(element) {
        const row = element.closest('.question-row');
        const found = row && findQuestion(row.dataset.questionId);
        if (!found?.question.table || found.question.type !== 'table') return null;
        const columnId = element.closest('[data-col-id]')?.dataset.colId;
        return { question: found.question, table: found.question.table, column: found.question.table.columns.find(column => column.id === columnId) };
    }

    function selectFromEvent(event) {
        const row = event.target.closest('.question-row');
        const blockElement = event.target.closest('.form-block');
        if (row) select('question', row.dataset.questionId);
        else if (blockElement) select('block', blockElement.dataset.blockId);
    }

    function openSettings(questionId, tab = 'options') {
        fieldTab = tab;
        selected = null;
        select('question', questionId);
        document.querySelector('.settings-panel')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    blockList.addEventListener('click', event => {
        const actionElement = event.target.closest('[data-action]');
        const action = actionElement?.dataset.action;
        const row = event.target.closest('.question-row');
        if (action === 'remove-question') return removeQuestion(row.dataset.questionId);
        if (action === 'remove-block') return removeBlock(event.target.closest('.form-block').dataset.blockId);
        if (action === 'open-settings') return openSettings(row.dataset.questionId);
        if (action === 'insert-after') {
            const found = findQuestion(row.dataset.questionId);
            const question = makeQuestion('short');
            found.block.questions.splice(found.questionIndex + 1, 0, question);
            selected = { kind: 'question', id: question.id };
            fieldTab = 'options';
            render();
            focusField(`[data-question-id="${CSS.escape(question.id)}"] .question-label`);
            return;
        }
        if (action === 'add-branch') {
            const parent = findQuestion(row.dataset.questionId);
            const followUp = makeQuestion('short', {
                hidden: true,
                logic: { conditions: { operator: 'and', rules: [{ question_id: parent.question.id, operator: 'equals', value: actionElement.dataset.option }] }, actions: ['show'] },
            });
            // Keep follow-ups grouped directly under their parent question.
            const followsParent = question => question?.logic?.conditions.rules.some(rule => rule.question_id === parent.question.id);
            let insertAt = parent.questionIndex + 1;
            while (followsParent(parent.block.questions[insertAt])) insertAt++;
            parent.block.questions.splice(insertAt, 0, followUp);
            selected = { kind: 'question', id: followUp.id };
            fieldTab = 'options';
            render();
            focusField(`[data-question-id="${CSS.escape(followUp.id)}"] .question-label`);
            return;
        }
        if (action === 'code-more' || action === 'code-less') {
            const question = findQuestion(row.dataset.questionId).question;
            question.code_length = clamp(question.code_length + (action === 'code-more' ? 1 : -1), 0, config.maxCodeLength, 0);
            select('question', question.id);
            refreshCodeBoxes(question);
            return;
        }

        const context = action && tableContext(actionElement);
        if (context) {
            const { table, column } = context;
            if (action === 'add-col' && table.columns.length < config.maxTableColumns) table.columns.push(makeColumn(`Kolom ${table.columns.length + 1}`));
            if (action === 'remove-col' && column && table.columns.length > 1) table.columns.splice(table.columns.indexOf(column), 1);
            if (action === 'add-row' && table.mode === 'fixed') table.rows.push(`Baris ${table.rows.length + 1}`);
            if (action === 'remove-row' && table.mode === 'fixed') table.rows.splice(Number(actionElement.dataset.rowIndex), 1);
            if (action === 'add-row' && table.mode === 'dynamic') table.initial_rows = Math.min(table.max_rows, table.initial_rows + 1);
            if (action === 'remove-row' && table.mode === 'dynamic') table.initial_rows = Math.max(1, table.initial_rows - 1);
            render();
            return;
        }
        selectFromEvent(event);
    });
    blockList.addEventListener('focusin', selectFromEvent);

    blockList.addEventListener('input', event => {
        const field = event.target.dataset.field;
        if (field === 'block-title') {
            const found = findBlock(event.target.closest('.form-block').dataset.blockId);
            if (found) found.block.title = event.target.value;
            return;
        }

        const context = tableContext(event.target);
        if (context && field?.startsWith('col-') && context.column) {
            if (field === 'col-label') context.column.label = event.target.value;
            if (field === 'col-options') context.column.options = event.target.value.split(';').map(option => option.trim()).filter(Boolean);
            if (field === 'col-type') {
                context.column.type = event.target.value;
                if (context.column.type === 'choice' && !context.column.options.length) context.column.options = ['Ya', 'Tidak'];
                render();
            }
            return;
        }
        if (context && field === 'row-label') {
            context.table.rows[Number(event.target.dataset.rowIndex)] = event.target.value;
            return;
        }

        const row = event.target.closest('.question-row');
        const found = row && findQuestion(row.dataset.questionId);
        if (!found) return;
        const panelField = name => (isSelected('question', found.question.id) ? selectionPanel.querySelector(`[data-option="${name}"]`) : null);
        if (field === 'label') {
            found.question.label = event.target.value;
            const mirror = panelField('label');
            if (mirror) mirror.value = event.target.value;
        }
        if (field === 'description') {
            found.question.description = event.target.value;
            const mirror = panelField('description');
            if (mirror) mirror.value = event.target.value;
        }
        if (field === 'matrix-rows' || field === 'matrix-columns') {
            found.question.matrix[field === 'matrix-rows' ? 'rows' : 'columns'] = event.target.value.split('\n').map(line => line.trim()).filter(Boolean);
            refreshPreview(found.question);
        }
        if (field === 'options') {
            found.question.options = event.target.value.split('\n').map(option => option.trim()).filter(Boolean);
            refreshPreview(found.question);
        }
    });

    // ── Settings panel events ──────────────────────────────────────────────

    function selectedQuestion() {
        return selected?.kind === 'question' ? findQuestion(selected.id)?.question ?? null : null;
    }

    function afterPanelChange(question, { rerender = false, preview = false } = {}) {
        if (preview) refreshPreview(question);
        refreshAllBadges();
        if (rerender) renderSelectionPanel();
    }

    selectionPanel.addEventListener('input', event => {
        const target = event.target;
        if (target.dataset.logicField === 'value') return updateCondition('value', target.value);
        const question = selectedQuestion();
        if (!question) return;

        // Options tab: typed fields update the model without re-rendering, so focus stays put.
        const option = target.dataset.option;
        if (option && target.matches('input[type="text"], input[type="number"], input[type="date"], textarea')) {
            if (option === 'max_length') question.max_length = target.value ? clamp(target.value, 1, 5000, null) : null;
            else question[option] = target.value;
            if (option === 'label' || option === 'description') {
                const mirror = blockList.querySelector(`[data-question-id="${CSS.escape(question.id)}"] [data-field="${option}"]`);
                if (mirror) mirror.value = target.value;
            }
            return afterPanelChange(question, { preview: ['prefill', 'placeholder'].includes(option) });
        }

        if (target.dataset.ruleField === 'value' && target.tagName === 'INPUT') {
            const row = target.closest('[data-path]');
            nodeAt(scopeRoot(question, row.dataset.scope), parsePath(row.dataset.path)).value = target.value;
            return afterPanelChange(question);
        }
        if (target.dataset.checkField === 'message') {
            question.validation.checks[Number(target.closest('[data-check-index]').dataset.checkIndex)].message = target.value;
            return afterPanelChange(question);
        }
        if (target.dataset.vruleField === 'value') {
            question.validation.rules[Number(target.closest('[data-rule-index]').dataset.ruleIndex)].value = target.value;
            return afterPanelChange(question);
        }
        if (target.dataset.vruleField === 'message') {
            question.validation.message = target.value;
            return;
        }

        const regionField = target.dataset.regionField;
        if ((regionField === 'code' || regionField === 'name') && target.tagName === 'INPUT') {
            const field = question.region.fields.find(item => item.key === target.closest('[data-region-key]').dataset.regionKey);
            field[regionField] = target.value;
            refreshPreview(question);
            return;
        }
        if (target.dataset.setting?.startsWith('range-')) {
            const key = target.dataset.setting.slice('range-'.length);
            question.range[key] = clamp(target.value, key === 'step' ? 1 : -config.rangeLimit, config.rangeLimit, question.range[key]);
            refreshPreview(question);
            return;
        }
        if (target.dataset.setting === 'code_length') {
            question.code_length = clamp(target.value, 0, config.maxCodeLength, 0);
            refreshCodeBoxes(question);
        }
        if (target.dataset.setting === 'initial_rows') {
            question.table.initial_rows = clamp(target.value, 1, question.table.max_rows, 1);
            refreshPreview(question);
        }
        if (target.dataset.setting === 'max_rows') {
            question.table.max_rows = clamp(target.value, 1, config.maxTableRows, 20);
            question.table.initial_rows = Math.min(question.table.initial_rows, question.table.max_rows);
            refreshPreview(question);
        }
    });

    selectionPanel.addEventListener('change', event => {
        const target = event.target;
        if (target.dataset.logicField) return updateCondition(target.dataset.logicField, target.value);
        const setting = target.dataset.setting;

        if (setting === 'add-question') {
            const found = selected?.kind === 'block' && findBlock(selected.id);
            if (found && target.value) addQuestion(target.value, found.block);
            return;
        }
        if (setting === 'orientation') {
            const found = selected?.kind === 'block' && findBlock(selected.id);
            if (found) found.block.orientation = target.value;
            render();
            return;
        }
        if (setting === 'layout') {
            const found = selected?.kind === 'block' && findBlock(selected.id);
            if (found) found.block.layout = target.value;
            render();
            return;
        }

        const question = selectedQuestion();
        if (!question) return;

        // Options tab: choices that change what the panel shows.
        const option = target.dataset.option;
        if (option === 'hide_label') {
            question.hide_label = target.checked;
            return afterPanelChange(question);
        }
        if (option === 'width' || option === 'help_position' || (option === 'prefill' && target.tagName === 'SELECT')) {
            question[option] = target.value;
            return afterPanelChange(question, { preview: true });
        }
        if (option === 'max_length') {
            target.value = question.max_length ?? '';
            return;
        }

        // Logic tab.
        const tool = target.dataset.logicTool;
        if (tool === 'copy-from' && target.value) {
            const source = findQuestion(target.value)?.question;
            if (source?.logic) {
                question.logic = structuredClone(source.logic);
                question.logic.actions = question.logic.actions.filter(action => allowedActions(question).includes(action));
                if (!question.logic.actions.length) question.logic.actions = [allowedActions(question)[0]];
            }
            return afterPanelChange(question, { rerender: true });
        }
        if (tool === 'copy-to' && target.value) {
            const destination = findQuestion(target.value)?.question;
            if (destination && question.logic) {
                destination.logic = structuredClone(question.logic);
                destination.logic.actions = destination.logic.actions.filter(action => allowedActions(destination).includes(action));
                if (!destination.logic.actions.length) destination.logic.actions = [allowedActions(destination)[0]];
                showNotice(`Logika disalin ke pertanyaan ${numbers.get(destination.id) ?? ''}.`, 'toast-info');
            }
            return afterPanelChange(question, { rerender: true });
        }
        if (target.dataset.logicAction) {
            const actions = new Set(question.logic.actions);
            if (target.checked) actions.add(target.dataset.logicAction);
            else actions.delete(target.dataset.logicAction);
            question.logic.actions = [...actions];
            return afterPanelChange(question);
        }
        if (target.dataset.groupField === 'operator') {
            const group = target.closest('[data-path]');
            nodeAt(scopeRoot(question, group.dataset.scope), parsePath(group.dataset.path)).operator = target.value;
            return afterPanelChange(question);
        }
        const ruleField = target.dataset.ruleField;
        if (ruleField) {
            const row = target.closest('[data-path]');
            const rule = nodeAt(scopeRoot(question, row.dataset.scope), parsePath(row.dataset.path));
            rule[ruleField] = target.value;
            if (ruleField === 'question_id') {
                const source = findQuestion(target.value)?.question;
                rule.value = source && choiceTypes.includes(source.type) ? source.options[0] ?? '' : '';
            }
            return afterPanelChange(question, { rerender: ruleField !== 'value' });
        }

        // Validation tab.
        if (target.dataset.checkField === 'level') {
            question.validation.checks[Number(target.closest('[data-check-index]').dataset.checkIndex)].level = target.value;
            return afterPanelChange(question, { rerender: true });
        }
        if (target.dataset.vruleField === 'type') {
            question.validation.rules[Number(target.closest('[data-rule-index]').dataset.ruleIndex)] = { type: target.value, value: '' };
            return afterPanelChange(question, { rerender: true });
        }

        const regionField = target.dataset.regionField;
        if (regionField === 'enabled' || regionField === 'mode' || (regionField === 'name' && target.tagName === 'SELECT')) {
            const field = question.region.fields.find(item => item.key === target.closest('[data-region-key]').dataset.regionKey);
            field[regionField] = regionField === 'enabled' ? target.checked : target.value;
            refreshPreview(question);
            renderSelectionPanel();
            return;
        }

        if (setting?.startsWith('range-')) return renderSelectionPanel();
        if (setting === 'code_length') target.value = question.code_length;
        if (setting === 'max_rows') {
            target.value = question.table.max_rows;
            renderSelectionPanel();
        }
        if (setting === 'initial_rows') target.value = question.table.initial_rows;
        if (setting === 'type') {
            changeType(question, target.value);
            render();
        }
        if (setting === 'table-mode') {
            question.table.mode = target.value;
            if (question.table.mode === 'fixed' && !question.table.rows.length) question.table.rows = ['Baris 1', 'Baris 2'];
            render();
        }
    });

    selectionPanel.addEventListener('click', event => {
        const settingAction = event.target.closest('[data-setting-action]')?.dataset.settingAction;
        if (settingAction === 'remove-question' && selected) return removeQuestion(selected.id);
        if (settingAction === 'remove-block' && selected) return removeBlock(selected.id);

        const tab = event.target.closest('[data-field-tab]')?.dataset.fieldTab;
        if (tab) {
            fieldTab = tab;
            mentionAt = null;
            return renderSelectionPanel();
        }

        const question = selectedQuestion();
        if (!question) return;

        const flag = event.target.closest('[data-flag]')?.dataset.flag;
        if (flag) {
            toggleFlag(question, flag);
            return render();
        }

        const tool = event.target.closest('button[data-logic-tool]')?.dataset.logicTool;
        if (tool === 'start') {
            question.logic = { conditions: { operator: 'and', rules: [newRule(earlierQuestions('question', question.id))] }, actions: [allowedActions(question)[0]] };
            return afterPanelChange(question, { rerender: true });
        }
        if (tool === 'clear') {
            question.logic = null;
            return afterPanelChange(question, { rerender: true });
        }

        const mentionAction = event.target.closest('[data-mention-action]');
        if (mentionAction) {
            const row = mentionAction.closest('.rule-row');
            const key = `${row.dataset.scope}|${row.dataset.path}`;
            const rule = nodeAt(scopeRoot(question, row.dataset.scope), parsePath(row.dataset.path));
            const action = mentionAction.dataset.mentionAction;
            if (action === 'open') mentionAt = mentionAt === key ? null : key;
            if (action === 'cancel') mentionAt = null;
            if (action === 'clear') rule.value = '';
            if (action === 'insert') {
                const fallback = row.querySelector('[data-mention-fallback]').value.replace(/[{}|]/g, '').trim();
                rule.value = `{{${mentionAction.dataset.mentionId}${fallback ? `|${fallback}` : ''}}}`;
                mentionAt = null;
            }
            return afterPanelChange(question, { rerender: true });
        }

        const groupAction = event.target.closest('[data-group-action]');
        if (groupAction) {
            mentionAt = null;
            const node = groupAction.closest('[data-path]');
            const scope = node.dataset.scope;
            const root = scopeRoot(question, scope);
            const path = parsePath(node.dataset.path);
            const available = scopeAvailable(question, scope);
            const action = groupAction.dataset.groupAction;
            if (action === 'add-rule') nodeAt(root, path).rules.push(newRule(available));
            if (action === 'add-group') nodeAt(root, path).rules.push({ operator: 'and', rules: [newRule(available)] });
            if (action === 'remove-rule' || action === 'remove-group') {
                nodeAt(root, path.slice(0, -1)).rules.splice(path.at(-1), 1);
                if (scope === 'logic' && !root.rules.length) question.logic = null;
            }
            return afterPanelChange(question, { rerender: true });
        }

        const checkAction = event.target.closest('[data-check-action]');
        if (checkAction?.dataset.checkAction === 'add') {
            const available = earlierQuestions('question', question.id, true);
            const self = available.find(item => item.question.id === question.id) ?? available[0];
            question.validation.checks.push({
                id: makeId('k'),
                level: 'warning',
                conditions: { operator: 'and', rules: [newRule(self ? [self] : [])] },
                message: '',
            });
            return afterPanelChange(question, { rerender: true });
        }
        if (checkAction?.dataset.checkAction === 'remove') {
            question.validation.checks.splice(Number(checkAction.closest('[data-check-index]').dataset.checkIndex), 1);
            return afterPanelChange(question, { rerender: true });
        }

        const vruleAction = event.target.closest('[data-vrule-action]');
        if (vruleAction?.dataset.vruleAction === 'add') {
            const [firstType] = Object.entries(config.validationRules).find(([, rule]) => rule.types.includes(question.type)) ?? [];
            if (firstType) question.validation.rules.push({ type: firstType, value: '' });
            return afterPanelChange(question, { rerender: true });
        }
        if (vruleAction?.dataset.vruleAction === 'remove') {
            question.validation.rules.splice(Number(vruleAction.closest('[data-rule-index]').dataset.ruleIndex), 1);
            return afterPanelChange(question, { rerender: true });
        }
    });

    document.querySelectorAll('[data-panel-tab]').forEach(tab => tab.addEventListener('click', () => showPane(tab.dataset.panelTab)));

    // ── Drag and drop ──────────────────────────────────────────────────────
    // Blocks, questions and table columns only become draggable while their ⠿ handle is held,
    // so text inside their inputs can still be selected normally.

    function resetDraggables() {
        blockList.querySelectorAll('[draggable="true"]').forEach(element => element.removeAttribute('draggable'));
    }

    function clearDropMarks() {
        paper.querySelectorAll('.drop-before, .drop-after, .drop-into').forEach(element => element.classList.remove('drop-before', 'drop-after', 'drop-into'));
        blockList.classList.remove('drop-end');
        hint.classList.remove('is-over');
    }

    function isLowerHalf(event, element) {
        const bounds = element.getBoundingClientRect();
        return event.clientY > bounds.top + bounds.height / 2;
    }

    function locateDrop(event) {
        if (dragState.kind === 'column') {
            const header = event.target.closest('th.table-col');
            if (!header || header.closest('.question-row')?.dataset.questionId !== dragState.questionId) return null;
            const bounds = header.getBoundingClientRect();
            return { element: header, mode: event.clientX > bounds.left + bounds.width / 2 ? 'after' : 'before', columnId: header.dataset.colId };
        }
        if (dragState.kind === 'block' || dragState.kind === 'new-block') {
            const blockElement = event.target.closest('.form-block');
            if (!blockElement) return { mode: 'end' };
            return { element: blockElement, mode: isLowerHalf(event, blockElement) ? 'after' : 'before', blockId: blockElement.dataset.blockId };
        }
        const row = event.target.closest('.question-row');
        if (row) return { element: row, mode: isLowerHalf(event, row) ? 'after' : 'before', questionId: row.dataset.questionId };
        const blockElement = event.target.closest('.form-block');
        if (blockElement) return { element: blockElement, mode: 'into', blockId: blockElement.dataset.blockId };
        return { mode: 'end' };
    }

    function applyDrop(state, target) {
        if (state.kind === 'column') {
            if (target.columnId === state.columnId) return;
            const columns = findQuestion(state.questionId).question.table.columns;
            const [column] = columns.splice(columns.findIndex(item => item.id === state.columnId), 1);
            columns.splice(columns.findIndex(item => item.id === target.columnId) + (target.mode === 'after' ? 1 : 0), 0, column);
            render();
            return;
        }

        if (state.kind === 'block' || state.kind === 'new-block') {
            if (state.kind === 'block' && target.blockId === state.id) return;
            const block = state.kind === 'block' ? blocks.splice(findBlock(state.id).blockIndex, 1)[0] : makeBlock();
            const targetIndex = target.blockId ? blocks.findIndex(item => item.id === target.blockId) : -1;
            if (targetIndex < 0) blocks.push(block);
            else blocks.splice(targetIndex + (target.mode === 'after' ? 1 : 0), 0, block);
            selected = { kind: 'block', id: block.id };
            render();
            if (state.kind === 'new-block') focusField(`[data-block-id="${CSS.escape(block.id)}"] .block-title`);
            return;
        }

        if (state.kind === 'question' && target.questionId === state.id) return;
        let question;
        if (state.kind === 'question') {
            const found = findQuestion(state.id);
            question = found.block.questions.splice(found.questionIndex, 1)[0];
        } else {
            question = makeQuestion(state.type);
        }

        const targetQuestion = target.questionId && findQuestion(target.questionId);
        if (targetQuestion) {
            targetQuestion.block.questions.splice(targetQuestion.questionIndex + (target.mode === 'after' ? 1 : 0), 0, question);
        } else {
            let block = (target.blockId && findBlock(target.blockId)?.block) || blocks.at(-1);
            if (!block) {
                block = makeBlock('KETERANGAN UMUM');
                blocks.push(block);
            }
            block.questions.push(question);
        }
        selected = { kind: 'question', id: question.id };
        render();
        if (state.kind === 'new-question') focusField(`[data-question-id="${CSS.escape(question.id)}"] .question-label`);
    }

    document.querySelectorAll('[data-tool]').forEach(tool => {
        const isBlockTool = tool.dataset.tool === 'block';
        tool.addEventListener('click', () => (isBlockTool ? addBlock() : addQuestion(tool.dataset.tool)));
        tool.addEventListener('dragstart', event => {
            dragState = isBlockTool ? { kind: 'new-block' } : { kind: 'new-question', type: tool.dataset.tool };
            event.dataTransfer.setData('text/plain', tool.dataset.tool);
            event.dataTransfer.effectAllowed = 'copy';
        });
    });

    blockList.addEventListener('pointerdown', event => {
        if (event.target.closest('[data-col-drag]')) {
            event.target.closest('th.table-col').setAttribute('draggable', 'true');
            return;
        }
        const handle = event.target.closest('[data-drag-handle]');
        if (handle) (handle.closest('.question-row') ?? handle.closest('.form-block')).setAttribute('draggable', 'true');
    });
    document.addEventListener('pointerup', () => {
        if (!dragState) resetDraggables();
    });

    blockList.addEventListener('dragstart', event => {
        const element = event.target;
        if (!(element instanceof HTMLElement) || element.getAttribute('draggable') !== 'true') return;
        if (element.matches('th.table-col')) dragState = { kind: 'column', questionId: element.closest('.question-row').dataset.questionId, columnId: element.dataset.colId };
        else if (element.matches('.question-row')) dragState = { kind: 'question', id: element.dataset.questionId };
        else if (element.matches('.form-block')) dragState = { kind: 'block', id: element.dataset.blockId };
        else return;
        event.dataTransfer.setData('text/plain', dragState.id ?? dragState.columnId);
        event.dataTransfer.effectAllowed = 'move';
        requestAnimationFrame(() => element.classList.add('is-dragging'));
    });

    document.addEventListener('dragend', () => {
        dragState = null;
        clearDropMarks();
        blockList.querySelectorAll('.is-dragging').forEach(element => element.classList.remove('is-dragging'));
        resetDraggables();
    });

    paper.addEventListener('dragover', event => {
        if (!dragState) return;
        const target = locateDrop(event);
        clearDropMarks();
        if (!target) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = dragState.kind.startsWith('new') ? 'copy' : 'move';
        if (target.element) target.element.classList.add(`drop-${target.mode}`);
        else if (blocks.length) blockList.classList.add('drop-end');
        else hint.classList.add('is-over');
    });

    paper.addEventListener('dragleave', event => {
        if (!paper.contains(event.relatedTarget)) clearDropMarks();
    });

    paper.addEventListener('drop', event => {
        if (!dragState) return;
        event.preventDefault();
        const state = dragState;
        const target = locateDrop(event);
        dragState = null;
        clearDropMarks();
        if (target) applyDrop(state, target);
    });

    // ── Form properties ────────────────────────────────────────────────────

    // The chosen paper size sets the sheet's minimum height; longer content simply extends it.
    function fitPaperHeight() {
        paper.style.minHeight = `${Math.round(paper.offsetWidth * Number(paper.dataset.ratio || 1.414))}px`;
    }
    window.addEventListener('resize', fitPaperHeight);

    function applyPaperSettings() {
        const size = checkedValue(paperSizeInputs) ?? 'A4';
        const orientation = checkedValue(orientationInputs) ?? 'portrait';
        const [portraitWidth, portraitHeight] = config.paperSizes[size];
        const [width, height] = orientation === 'landscape' ? [portraitHeight, portraitWidth] : [portraitWidth, portraitHeight];
        const orientationLabel = orientation === 'landscape' ? 'Lanskap' : 'Potret';

        paper.dataset.ratio = String(height / width);
        paper.dataset.orientation = orientation;
        fitPaperHeight();
        document.querySelector('#paper-dimension').textContent = `${width} × ${height} MM`;
        document.querySelector('#orientation-value').textContent = orientationLabel.toUpperCase();
        document.querySelector('#format-summary').textContent = `Kertas ${size} · ${orientationLabel}`;
        document.querySelectorAll('.paper-size-icon').forEach(icon => icon.classList.toggle('is-landscape', orientation === 'landscape'));
    }

    function populatePeriodValues(preferredValue) {
        const period = config.periodTypes[checkedValue(periodTypeInputs) ?? 'triwulan'];
        const current = Number(preferredValue ?? periodValueSelect.value);
        periodValueSelect.innerHTML = period.values.map((label, index) => `<option value="${index + 1}">${period.label} ${escapeHtml(label)}</option>`).join('');
        periodValueSelect.hidden = !period.values.length;
        periodValueSelect.disabled = !period.values.length;
        if (period.values.length) periodValueSelect.value = String(current >= 1 && current <= period.values.length ? current : 1);
        applyPeriodLabel();
    }

    function applyPeriodLabel() {
        const period = config.periodTypes[checkedValue(periodTypeInputs) ?? 'triwulan'];
        periodLabel.hidden = !period.values.length;
        document.querySelector('#period-name').textContent = period.label;
        document.querySelector('#period-display').textContent = period.values[Number(periodValueSelect.value) - 1] ?? '';
    }

    paperSizeInputs.forEach(input => input.addEventListener('change', applyPaperSettings));
    orientationInputs.forEach(input => input.addEventListener('change', applyPaperSettings));
    periodTypeInputs.forEach(input => input.addEventListener('change', () => populatePeriodValues()));
    periodValueSelect.addEventListener('change', applyPeriodLabel);
    numberingInputs.forEach(input => input.addEventListener('change', render));

    // Activity source: type a new activity or reuse a previous one.
    activityModes.forEach(mode => mode.addEventListener('change', () => {
        const usePrevious = mode.value === 'previous' && mode.checked;
        previousSelect.hidden = !usePrevious;
        activityInput.readOnly = usePrevious;
        activityInput.classList.toggle('is-readonly', usePrevious);
        if (!usePrevious) {
            activityInput.value = activityInput.dataset.manualValue || activityInput.value;
            return;
        }
        if (previousSelect.value) activityInput.value = previousSelect.value;
    }));
    activityInput.dataset.manualValue = activityInput.value;
    activityInput.addEventListener('input', () => {
        if (!activityInput.readOnly) activityInput.dataset.manualValue = activityInput.value;
    });
    previousSelect.addEventListener('change', () => {
        if (previousSelect.value) activityInput.value = previousSelect.value;
    });

    // ── Submission ─────────────────────────────────────────────────────────

    function showNotice(message, className = 'toast-error') {
        document.querySelectorAll('.toast').forEach(toast => toast.remove());
        const toast = document.createElement('div');
        toast.className = `toast ${className}`;
        toast.setAttribute('role', 'alert');
        toast.textContent = message;
        document.body.append(toast);
        setTimeout(() => toast.remove(), 4000);
    }

    function questionProblem(question) {
        if (!question.label.trim()) return question.type === 'info' ? 'Judul keterangan belum diisi.' : 'Teks pertanyaan belum diisi.';
        if (question.type === 'region' && !question.region.fields.some(field => field.enabled)) return 'Identitas wilayah membutuhkan minimal satu isian.';
        if (question.type === 'table') {
            if (question.table.columns.some(column => !column.label.trim())) return 'Judul kolom tabel belum diisi.';
            if (question.table.columns.some(column => column.type === 'choice' && !column.options.length)) return 'Kolom pilihan pada tabel belum memiliki opsi.';
            if (question.table.mode === 'fixed' && !question.table.rows.some(row => row.trim())) return 'Tabel baris tetap membutuhkan nama baris.';
        }
        if (rangeTypes.includes(question.type)) {
            const { min, max, step } = question.range;
            if (max <= min) return 'Nilai terbesar harus lebih dari nilai terkecil.';
            if (step > max - min) return 'Kelipatan melebihi rentang nilai.';
            if (question.type !== 'slider' && Math.floor((max - min) / step) + 1 > config.maxRangePoints) return `Maksimal ${config.maxRangePoints} pilihan nilai.`;
        }
        if (question.type === 'matrix') {
            const { rows, columns } = question.matrix;
            if (!rows.length || columns.length < 2) return 'Matriks membutuhkan minimal satu baris dan dua kolom pilihan.';
            if (new Set(rows).size < rows.length || new Set(columns).size < columns.length) return 'Baris dan kolom matriks tidak boleh kembar.';
            if (rows.length > config.maxMatrixRows || columns.length > config.maxMatrixColumns) return `Matriks maksimal ${config.maxMatrixRows} baris dan ${config.maxMatrixColumns} kolom.`;
        }
        return logicProblem(question) || validationProblemOf(question);
    }

    function findProblem() {
        if (!blocks.length) return { message: 'Tambahkan minimal satu blok.' };
        const untitledBlock = blocks.find(block => !block.title.trim());
        if (untitledBlock) return { message: 'Judul blok belum diisi.', kind: 'block', id: untitledBlock.id, selector: `[data-block-id="${CSS.escape(untitledBlock.id)}"] .block-title` };
        if (!numbers.size) return { message: 'Tambahkan minimal satu pertanyaan.' };
        for (const block of blocks) {
            const blockProblem = conditionProblem('block', block.id);
            if (blockProblem) return { message: blockProblem, kind: 'block', id: block.id, selector: `[data-block-id="${CSS.escape(block.id)}"] .block-title` };
            for (const question of block.questions) {
                const problem = questionProblem(question);
                if (problem) return { message: problem, kind: 'question', id: question.id, selector: `[data-question-id="${CSS.escape(question.id)}"] .question-label` };
            }
        }
        return null;
    }

    form.addEventListener('submit', event => {
        const problem = findProblem();
        if (problem) {
            event.preventDefault();
            showNotice(problem.message);
            if (problem.selector) {
                select(problem.kind, problem.id);
                focusField(problem.selector);
            } else {
                hint.hidden = false;
                hint.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            return;
        }
        payload.value = JSON.stringify(blocks);
    });

    // Rows along the paper (the top line with "FORMULIR PENDATAAN" and the form code, and the
    // RAHASIA rule): each text chip can be edited, cleared, or dragged left, centre or right.
    function setupChipRule(rule) {
        const chips = [...rule.querySelectorAll('[data-rule-chip]')];
        let draggedChip = null;

        function place(chip, position) {
            const slot = rule.querySelector(`[data-rule-slot="${position}"]`) ?? rule.querySelector('[data-rule-slot="left"]');
            // Fixed items (e.g. the period label) stay last in their slot.
            slot.insertBefore(chip, slot.querySelector('[data-rule-fixed]'));
            document.querySelector(chip.dataset.positionInput).value = slot.dataset.ruleSlot;
        }

        function clearMarks() {
            rule.querySelectorAll('.is-drop-target').forEach(slot => slot.classList.remove('is-drop-target'));
        }

        chips.forEach(chip => {
            place(chip, document.querySelector(chip.dataset.positionInput).value);

            chip.querySelector('[data-rule-clear]').addEventListener('click', () => {
                const input = chip.querySelector('input');
                input.value = '';
                input.focus();
            });
            chip.addEventListener('pointerdown', event => {
                if (event.target.closest('[data-rule-drag]')) chip.setAttribute('draggable', 'true');
            });
            chip.addEventListener('dragstart', event => {
                if (chip.getAttribute('draggable') !== 'true') return;
                event.stopPropagation();
                draggedChip = chip;
                event.dataTransfer.setData('text/plain', 'rule-chip');
                event.dataTransfer.effectAllowed = 'move';
                requestAnimationFrame(() => chip.classList.add('is-dragging'));
            });
            chip.addEventListener('dragend', () => {
                draggedChip = null;
                chip.classList.remove('is-dragging');
                chip.removeAttribute('draggable');
                clearMarks();
            });
        });

        document.addEventListener('pointerup', () => {
            if (!draggedChip) chips.forEach(chip => chip.removeAttribute('draggable'));
        });
        rule.addEventListener('dragover', event => {
            const slot = event.target.closest('[data-rule-slot]');
            if (!draggedChip || !slot) return;
            event.preventDefault();
            event.stopPropagation();
            event.dataTransfer.dropEffect = 'move';
            clearMarks();
            slot.classList.add('is-drop-target');
        });
        rule.addEventListener('drop', event => {
            const slot = event.target.closest('[data-rule-slot]');
            if (!draggedChip || !slot) return;
            event.preventDefault();
            event.stopPropagation();
            clearMarks();
            place(draggedChip, slot.dataset.ruleSlot);
        });
    }

    document.querySelectorAll('[data-rule]').forEach(setupChipRule);
    document.querySelectorAll('[data-copy]').forEach(button => button.addEventListener('click', async () => {
        const field = document.querySelector(button.dataset.copy);
        try {
            await navigator.clipboard.writeText(field.value);
        } catch {
            field.select();
            document.execCommand('copy');
        }
        const label = button.textContent;
        button.textContent = 'Tersalin';
        setTimeout(() => (button.textContent = label), 1500);
    }));

    applyPaperSettings();
    populatePeriodValues(periodValueSelect.dataset.selected);
    render();
})();

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
    const numericOperators = config.numericOperators;
    const rangeOperators = config.rangeOperators;

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
            options_from: '',
            other_option: false,
            other_label: '',
            mask: '',
            lookup: null,
            ...(type === 'calc' ? { formula: '', format: 'number', decimals: 0 } : {}),
            ...(type === 'file' ? { file: { accept: 'image', max_mb: 5 } } : {}),
            ...(type === 'list' ? { list: { max: 10 } } : {}),
            ...(type === 'region' ? { region: defaultRegion(), label: 'Identitas wilayah' } : {}),
            ...(type === 'table' ? { table: defaultTable() } : {}),
            ...(rangeTypes.includes(type) ? { range: { ...config.rangeDefaults[type] } } : {}),
            ...(type === 'matrix' ? { matrix: defaultMatrix() } : {}),
            ...overrides,
        };
    }

    function makeBlock(title = '', questions = []) {
        return { id: makeId('b'), title, layout: 'full', orientation: '', repeat: null, questions, condition: null };
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

    // A range value is "from;to"; null unless both ends are numbers in order.
    function rangeBounds(value) {
        const parts = String(value ?? '').split(';').map(part => part.trim().replace(',', '.'));
        if (parts.length !== 2 || parts.some(part => part === '' || Number.isNaN(Number(part)))) return null;
        return Number(parts[0]) <= Number(parts[1]) ? parts.map(Number) : null;
    }

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
            options_from: choiceTypes.includes(question.type) ? String(question.options_from ?? '') : '',
            other_option: Boolean(question.other_option),
            other_label: String(question.other_label ?? ''),
            mask: question.type === 'short' ? String(question.mask ?? '') : '',
            lookup: question.type === 'dropdown' && config.lookupSources[question.lookup?.source]
                ? Object.fromEntries(['source', 'reference', 'value_column', 'label_column', 'filter_column', 'url', 'data_path', 'value_key', 'label_key', 'param', 'depends_on'].map(key => [key, String(question.lookup[key] ?? '')]))
                : null,
        };
        if (question.type === 'calc') {
            normalized.formula = String(question.formula ?? '');
            normalized.format = ['number', 'currency', 'percent'].includes(question.format) ? question.format : 'number';
            normalized.decimals = clamp(question.decimals, 0, 6, 0);
        }
        if (question.type === 'file') {
            normalized.file = {
                accept: config.fileKinds[question.file?.accept] ? question.file.accept : 'image',
                max_mb: clamp(question.file?.max_mb, 1, config.maxFileMb, 5),
            };
        }
        if (question.type === 'list') normalized.list = { max: clamp(question.list?.max, 1, config.maxListItems, 10) };
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
                repeat: block?.repeat?.source ? {
                    source: String(block.repeat.source),
                    max: clamp(block.repeat.max, 1, config.maxRosterRows, 20),
                    print_rows: clamp(block.repeat.print_rows, 1, 30, 3),
                } : null,
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


    // ── Answer tags and formulas ───────────────────────────────────────────
    // Tags are stored as {{question-id}} and shown as {{101}} (the question's number) while editing.

    const TAG = /\{\{([A-Za-z0-9_-]+)(\|[^}]*)?\}\}/g;

    function tagsToNumbers(text) {
        return String(text ?? '').replace(TAG, (match, id, rest = '') => (numbers.has(id) ? `{{${numbers.get(id)}${rest}}}` : match));
    }

    function tagsToIds(text) {
        const byNumber = new Map([...numbers].map(([id, number]) => [number, id]));
        return String(text ?? '').replace(TAG, (match, key, rest = '') => (byNumber.has(key) ? `{{${byNumber.get(key)}${rest}}}` : match));
    }

    function tagIds(text) {
        return [...String(text ?? '').matchAll(TAG)].map(match => match[1]);
    }

    const FORMULA_FUNCTIONS = { sum: [1, Infinity], avg: [1, Infinity], min: [1, Infinity], max: [1, Infinity], round: [1, 2], floor: [1, 1], ceil: [1, 1], abs: [1, 1], count: [1, 1], len: [1, 1], years: [1, 2], days: [2, 2], if: [3, 3] };

    // Mirrors App\Support\Formula::parse(): a readable reason, or '' when the formula is fine.
    function formulaSyntaxProblem(formula) {
        if (!formula.trim()) return 'belum diisi';
        const pattern = /\s*(?:\{\{([A-Za-z0-9_-]+)\}\}|(\d+(?:\.\d+)?)|([A-Za-z_]+)|(>=|<=|==|!=|<>|[-+*/%(),<>=])|"([^"]*)"|'([^']*)')/y;
        const tokens = [];
        let offset = 0;
        while (offset < formula.length && formula.slice(offset).trim() !== '') {
            pattern.lastIndex = offset;
            const match = pattern.exec(formula);
            if (!match) return `memuat karakter yang tidak dikenal: "${formula.slice(offset).trim().slice(0, 8)}"`;
            offset = pattern.lastIndex;
            if (match[1] !== undefined) tokens.push(['tag', match[1]]);
            else if (match[2] !== undefined) tokens.push(['num', match[2]]);
            else if (match[3] !== undefined) tokens.push(['name', match[3].toLowerCase()]);
            else if (match[4] !== undefined) tokens.push(['op', match[4]]);
            else tokens.push(['str', '']);
        }
        let position = 0;
        const peek = () => tokens[position] ?? ['end', ''];
        const expect = symbol => {
            if (peek()[1] !== symbol) throw new Error(`kurang tanda "${symbol}"`);
            position++;
        };
        const factor = () => {
            const [kind, value] = tokens[position++] ?? ['end', ''];
            if (kind === 'op' && (value === '-' || value === '+')) return factor();
            if (['num', 'tag', 'str'].includes(kind)) return;
            if (kind === 'op' && value === '(') {
                comparison();
                return expect(')');
            }
            if (kind === 'name') {
                if (!FORMULA_FUNCTIONS[value]) throw new Error(`memakai fungsi "${value}" yang tidak dikenal`);
                expect('(');
                let count = 0;
                if (peek()[1] !== ')') {
                    do {
                        comparison();
                        count++;
                    } while (peek()[1] === ',' && ++position);
                }
                expect(')');
                if (count < FORMULA_FUNCTIONS[value][0] || count > FORMULA_FUNCTIONS[value][1]) throw new Error(`memanggil ${value}() dengan jumlah isian yang salah`);
                return;
            }
            throw new Error(kind === 'end' ? 'berakhir sebelum selesai' : `memuat "${value}" di tempat yang salah`);
        };
        const loop = (next, operators) => () => {
            next();
            while (peek()[0] === 'op' && operators.includes(peek()[1])) {
                position++;
                next();
            }
        };
        const term = loop(factor, ['*', '/', '%']);
        const additive = loop(term, ['+', '-']);
        const comparison = loop(additive, ['>', '<', '>=', '<=', '==', '!=', '<>', '=']);
        try {
            comparison();
            if (position < tokens.length) return `memuat "${tokens[position][1]}" di tempat yang salah`;
        } catch (error) {
            return error.message;
        }
        return '';
    }

    // Formulas, answer tags and options taken from another question may only use earlier questions.
    function dynamicProblem(question) {
        const earlierIds = earlierQuestions('question', question.id).map(item => item.question.id);
        if (question.type === 'calc') {
            const problem = formulaSyntaxProblem(question.formula);
            if (problem) return `Rumus ${problem}.`;
            const formulaIds = earlierQuestions('question', question.id, false, true).map(item => item.question.id);
            if (tagIds(question.formula).some(id => !formulaIds.includes(id))) return 'Rumus memakai tag jawaban dari pertanyaan yang tidak ada sebelumnya.';
        }
        if (inRoster(question) && !config.rosterTypes.includes(question.type)) return 'Jenis isian ini tidak dapat dipakai di blok berulang.';
        if ([...tagIds(question.label), ...tagIds(question.description)].some(id => !earlierIds.includes(id))) return 'Teks memakai tag jawaban dari pertanyaan yang tidak ada sebelumnya.';
        if (question.options_from) {
            const source = findQuestion(question.options_from)?.question;
            if (!source || source.type !== 'multiple' || !earlierIds.includes(source.id)) return 'Sumber pilihan harus pertanyaan pilihan ganda sebelumnya.';
        }
        if (question.other_option && question.options.includes('Lainnya')) return 'Hapus pilihan "Lainnya" dari daftar; opsi "Lainnya (sebutkan)" sudah otomatis.';
        if (question.lookup) {
            const lookup = question.lookup;
            if (lookup.depends_on && !earlierQuestions('question', question.id).some(item => item.question.id === lookup.depends_on)) return 'Pilihan bertingkat harus bergantung pada pertanyaan sebelumnya.';
            if (lookup.source === 'api') return lookup.url.trim() ? '' : 'Alamat API belum diisi.';
            const list = config.referenceLists.find(item => item.slug === lookup.reference);
            if (!list) return 'Pilih data referensi.';
            if (!list.columns.includes(lookup.value_column)) return 'Pilih kolom nilai (kode) data referensi.';
            if (lookup.depends_on && !list.columns.includes(lookup.filter_column)) return 'Pilihan bertingkat membutuhkan kolom penyaring.';
        }
        return '';
    }

    function lookupSettings(question) {
        const lookup = question.lookup;
        const parents = earlierQuestions('question', question.id);
        const parentSelect = `<label class="setting-field"><span>Bergantung pada jawaban <small>(pilihan bertingkat)</small></span>
            <select class="setting-select" data-lookup-field="depends_on">
                <option value="">Tidak bergantung</option>
                ${parents.map(item => `<option value="${escapeHtml(item.question.id)}" ${item.question.id === lookup.depends_on ? 'selected' : ''}>${escapeHtml(questionLabel(item))}</option>`).join('')}
            </select></label>`;
        if (lookup.source === 'api') {
            const field = (key, label, placeholder) => `<label class="setting-field"><span>${label}</span><input class="setting-select mono-input" type="text" maxlength="500" value="${escapeHtml(lookup[key])}" placeholder="${escapeHtml(placeholder)}" data-lookup-field="${key}"></label>`;
            return `${field('url', 'Alamat API (GET, JSON)', 'https://contoh.go.id/api/desa')}
                ${field('data_path', 'Letak daftar di JSON <small>(kosong = akar)</small>', 'data')}
                <div class="setting-pair">${field('value_key', 'Kunci nilai', 'id')}${field('label_key', 'Kunci label', 'nama')}</div>
                ${parentSelect}
                ${lookup.depends_on ? field('param', 'Nama parameter penyaring <small>(atau tulis {value} di alamat)</small>', 'kecamatan') : ''}
                <p class="setting-note">Seperti sourceAPI FormGear. Jawaban pertanyaan induk dikirim sebagai parameter; daftar disimpan sementara 10 menit.</p>`;
        }
        const list = config.referenceLists.find(item => item.slug === lookup.reference);
        const columnSelect = (key, label, optional) => `<label class="setting-field"><span>${label}</span><select class="setting-select" data-lookup-field="${key}">
            <option value="">${optional ? '(sama dengan nilai)' : 'Pilih kolom…'}</option>
            ${(list?.columns ?? []).map(column => `<option value="${escapeHtml(column)}" ${column === lookup[key] ? 'selected' : ''}>${escapeHtml(column)}</option>`).join('')}
        </select></label>`;
        return `<label class="setting-field"><span>Data referensi</span>
                <select class="setting-select" data-lookup-field="reference">
                    <option value="">Pilih data…</option>
                    ${config.referenceLists.map(item => `<option value="${escapeHtml(item.slug)}" ${item.slug === lookup.reference ? 'selected' : ''}>${escapeHtml(item.name)}</option>`).join('')}
                </select></label>
            ${config.referenceLists.length ? '' : `<p class="setting-note">Belum ada data referensi. <a href="${escapeHtml(config.referencesUrl)}" target="_blank" rel="noopener">Unggah CSV</a> terlebih dahulu.</p>`}
            ${list ? `<div class="setting-pair">${columnSelect('value_column', 'Kolom nilai (kode)', false)}${columnSelect('label_column', 'Kolom label', true)}</div>
            ${parentSelect}
            ${lookup.depends_on ? columnSelect('filter_column', 'Kolom penyaring (berisi jawaban induk)', false) : ''}` : ''}`;
    }

    function tagPicker(target, question, label = 'Sisipkan jawaban…') {
        const available = earlierQuestions('question', question.id, false, target === 'formula');
        const rowToken = target !== 'formula' && inRoster(question);
        if (!available.length && !rowToken) return '';
        return `<select class="setting-select tag-picker" data-insert-tag="${target}" aria-label="Sisipkan tag jawaban">
            <option value="">${escapeHtml(label)}</option>
            ${rowToken ? '<option value="@baris">↻ Nama/nomor rincian (baris)</option>' : ''}
            ${available.map(item => `<option value="${escapeHtml(item.number)}">@${escapeHtml(questionLabel(item))}</option>`).join('')}
        </select>`;
    }

    // ── Logic ──────────────────────────────────────────────────────────────

    // Questions a rule may refer to: earlier, logic-capable ones (and the question itself for consistency checks).
    // Rules see earlier questions outside repeating blocks, plus earlier ones in their own repeating
    // block (answered per row). Formulas may also total another repeating block's rows, or count a list.
    function earlierQuestions(kind, id, includeSelf = false, forFormula = false) {
        const ownBlock = kind === 'block' ? null : findQuestion(id)?.block;
        const earlier = [];
        for (const block of blocks) {
            if (kind === 'block' && block.id === id) return earlier;
            const otherRoster = Boolean(block.repeat?.source) && block !== ownBlock;
            for (const question of block.questions) {
                const usable = forFormula ? !['region', 'table', 'matrix', 'info'].includes(question.type) : !nonLogicTypes.includes(question.type);
                if (kind === 'question' && question.id === id) {
                    if (includeSelf && usable) earlier.push({ question, number: numbers.get(question.id) });
                    return earlier;
                }
                if (usable && (!otherRoster || forFormula)) earlier.push({ question, number: numbers.get(question.id) });
            }
        }
        return earlier;
    }

    function inRoster(question) {
        return Boolean(findQuestion(question.id)?.block.repeat?.source);
    }

    // Questions a block can repeat for: earlier numbers, multiple choices and lists outside repeating blocks.
    function rosterSources(block) {
        const sources = [];
        for (const candidate of blocks) {
            if (candidate === block) break;
            if (candidate.repeat?.source) continue;
            candidate.questions.filter(question => config.rosterSourceTypes.includes(question.type)).forEach(question => sources.push({ question, number: numbers.get(question.id) }));
        }
        return sources;
    }

    function rosterProblem(block) {
        if (!block.repeat?.source) return '';
        if (!rosterSources(block).some(item => item.question.id === block.repeat.source)) return 'Blok berulang harus diulang per pertanyaan Angka, Pilihan lebih dari satu, atau Daftar nama sebelumnya.';
        return '';
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
            if (!mentions.length && numericOperators.includes(rule.operator) && Number.isNaN(Number(rule.value.replace(',', '.')))) return 'harus memakai angka untuk pembanding angka';
            if (!mentions.length && rangeOperators.includes(rule.operator) && !rangeBounds(rule.value)) return 'harus memakai rentang angka yang benar (dari ≤ sampai)';
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
            const tagged = (rangeOperators.includes(rule.operator) ? rule.value.replace(';', '–') : rule.value).replace(MENTION, (match, id) => `@${available.find(item => item.question.id === id)?.number ?? '?'}`);
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
            if (removed.has(block.repeat?.source)) block.repeat = null;
            block.questions.forEach(question => {
                if (question.lookup && removed.has(question.lookup.depends_on)) question.lookup.depends_on = '';
                if (removed.has(question.options_from)) question.options_from = '';
            });
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
                    ${Object.entries(config.operators).filter(([operator]) => !rangeOperators.includes(operator)).map(([operator, label]) => `<option value="${operator}" ${operator === condition.operator ? 'selected' : ''}>${escapeHtml(label)}</option>`).join('')}
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
        if (rangeOperators.includes(rule.operator)) {
            const [from = '', to = ''] = rule.value.split(';');
            return `<span class="range-wrap">
                <input class="setting-select" type="number" step="any" value="${escapeHtml(from)}" placeholder="Dari" data-range-part="0" aria-label="Batas bawah">
                <span aria-hidden="true">–</span>
                <input class="setting-select" type="number" step="any" value="${escapeHtml(to)}" placeholder="Sampai" data-range-part="1" aria-label="Batas atas">
            </span>`;
        }
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
        if (options.length && !numericOperators.includes(rule.operator)) {
            return `<select class="setting-select" data-rule-field="value" aria-label="Nilai">
                <option value="">Pilih jawaban…</option>
                ${options.map(option => `<option value="${escapeHtml(option)}" ${option === rule.value ? 'selected' : ''}>${escapeHtml(option)}</option>`).join('')}
            </select>`;
        }
        const numeric = numericOperators.includes(rule.operator) || ['number', 'currency', 'calc'].includes(source?.question.type);
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
        if (question.lookup) {
            const list = config.referenceLists.find(item => item.slug === question.lookup.reference);
            const from = question.lookup.source === 'api' ? `API ${(question.lookup.url.match(/^https?:\/\/([^/]+)/) ?? [])[1] ?? ''}` : `data referensi “${list?.name ?? '?'}”`;
            const parent = question.lookup.depends_on ? `, menurut jawaban ${numbers.get(question.lookup.depends_on) ?? '?'}` : '';
            return `<div class="dynamic-preview">⇣ Daftar pilihan dari ${escapeHtml(from)}${escapeHtml(parent)}</div>`;
        }
        if (choiceTypes.includes(question.type) && question.options_from) {
            return `<div class="dynamic-preview">⇠ Pilihan diambil dari jawaban pertanyaan ${escapeHtml(numbers.get(question.options_from) ?? '?')}</div>`;
        }
        if (choiceTypes.includes(question.type)) {
            const options = [...(question.options.length ? question.options : ['Pilihan 1', 'Pilihan 2']), ...(question.other_option ? [question.other_label || 'Lainnya (sebutkan)'] : [])];
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
            case 'list': return '<div class="list-preview"><span>1. ____________</span><span>2. ____________</span><b>+ Tambah nama</b></div>';
            case 'time': return `<div class="answer-line">${escapeHtml(question.prefill || 'Jam __ : __')}</div>`;
            case 'calc': return `<div class="dynamic-preview is-calc"><b>ƒx</b> ${escapeHtml(tagsToNumbers(question.formula) || 'Tulis rumus di panel kanan, mis. {{101}} + {{102}}')}</div>`;
            case 'location': return '<div class="answer-line">📍 lintang, bujur — tombol “Ambil lokasi saat ini”</div>';
            case 'file': return `<div class="sign-preview">⇪ Unggah ${escapeHtml((config.fileKinds[question.file?.accept]?.label ?? 'berkas').toLowerCase())} · maks. ${question.file?.max_mb ?? 5} MB</div>`;
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
            ? `<textarea class="question-desc" rows="2" maxlength="1000" placeholder="Isi keterangan untuk responden" data-field="description">${escapeHtml(tagsToNumbers(question.description))}</textarea>`
            : `<input class="question-desc" type="text" maxlength="1000" value="${escapeHtml(tagsToNumbers(question.description))}" placeholder="Petunjuk pengisian (opsional)" data-field="description">`;
        return `
            <article class="${classes}" data-question-id="${escapeHtml(question.id)}">
                <span class="drag-handle" data-drag-handle title="Seret untuk memindahkan">⠿</span>
                <span class="question-number">${isInfo ? 'i' : number}</span>
                <div class="question-main">
                    <input class="question-label" type="text" maxlength="255" value="${escapeHtml(tagsToNumbers(question.label))}" placeholder="${isInfo ? 'Judul keterangan' : 'Tulis pertanyaan... (sisipkan jawaban dengan {{101}})'}" data-field="label" aria-label="Teks ${subject}">
                    ${description}
                    <span class="logic-slot">${logicBadge('question', question.id)}</span>
                    <div class="question-preview">${previewFor(question)}</div>
                    ${choiceTypes.includes(question.type) && !question.options_from && !question.lookup ? `<label class="option-editor"><span>PILIHAN</span><textarea data-field="options" aria-label="Pilihan jawaban, satu per baris" placeholder="Satu pilihan per baris">${escapeHtml(question.options.join('\n'))}</textarea></label>` : ''}
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
        const classes = ['form-block', block.layout === 'half' && 'is-half', block.repeat && 'is-roster', isSelected('block', block.id) && 'is-selected'].filter(Boolean).join(' ');
        return `
            <section class="${classes}" data-block-id="${escapeHtml(block.id)}">
                <header class="block-head">
                    <span class="drag-handle" data-drag-handle title="Seret untuk memindahkan blok">⠿</span>
                    <span class="block-prefix">BLOK ${roman}.</span>
                    <input class="block-title" type="text" maxlength="160" value="${escapeHtml(block.title)}" placeholder="JUDUL BLOK" data-field="block-title" aria-label="Judul blok ${roman}">
                    ${block.repeat ? `<span class="roster-chip" title="Blok diulang untuk setiap jawaban pertanyaan ${escapeHtml(numbers.get(block.repeat.source) ?? '?')}">↻ per ${escapeHtml(numbers.get(block.repeat.source) ?? '?')}</span>` : ''}
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
        const multipleSources = earlierQuestions('question', question.id).filter(item => item.question.type === 'multiple');
        const sourceValue = question.lookup ? `lookup:${question.lookup.source}` : question.options_from;
        const dynamicSettings = [
            choiceTypes.includes(question.type) ? `
                <label class="setting-field"><span>Sumber pilihan</span>
                    <select class="setting-select" data-option="options_from">
                        <option value="">Ditulis di lembar</option>
                        ${multipleSources.map(item => `<option value="${escapeHtml(item.question.id)}" ${item.question.id === sourceValue ? 'selected' : ''}>Jawaban ${escapeHtml(questionLabel(item))}</option>`).join('')}
                        ${question.type === 'dropdown' ? Object.entries(config.lookupSources).map(([source, label]) => `<option value="lookup:${source}" ${sourceValue === `lookup:${source}` ? 'selected' : ''}>${escapeHtml(label)}</option>`).join('') : ''}
                    </select>
                </label>
                ${question.lookup ? lookupSettings(question) : ''}
                ${multipleSources.length || question.lookup ? '' : `<p class="setting-note">Pilihan bisa diambil dari jawaban pertanyaan “Pilihan lebih dari satu” sebelumnya${question.type === 'dropdown' ? ', dari Data referensi (mis. daftar desa, bisa berjenjang), atau dari API' : ''}.</p>`}
                ${question.options_from || question.lookup ? '' : `<label class="required-toggle"><input type="checkbox" data-option="other_option" ${question.other_option ? 'checked' : ''}><span class="toggle-track"></span><span>Tambah opsi “Lainnya (sebutkan)”</span></label>
                ${question.other_option ? `<label class="setting-field"><span>Teks opsi lainnya</span><input class="setting-select" type="text" maxlength="60" value="${escapeHtml(question.other_label)}" placeholder="Lainnya (sebutkan)" data-option="other_label"></label>` : ''}`}` : '',
            question.type === 'short' ? `
                <label class="setting-field"><span>Format isian (masking) <small>9 angka · a huruf · * huruf/angka</small></span>
                    <input class="setting-select mono-input" type="text" maxlength="${config.maxMaskLength}" value="${escapeHtml(question.mask)}" placeholder="mis. 99.99.99.9999 atau aa-9999" list="mask-presets" data-option="mask">
                    <datalist id="mask-presets"><option value="9999999999999999">NIK (16 digit)</option><option value="99999">KBLI / kode pos (5 digit)</option><option value="99.99.99.9999">Kode wilayah desa</option><option value="aa-9999">Huruf-angka</option></datalist>
                </label>` : '',
            question.type === 'calc' ? `
                <label class="setting-field"><span>Rumus</span>
                    <textarea class="setting-select setting-textarea mono-input" rows="3" maxlength="500" placeholder="mis. {{101}} + {{102}}" data-option="formula">${escapeHtml(tagsToNumbers(question.formula))}</textarea>
                </label>
                ${tagPicker('formula', question, 'Sisipkan jawaban ke rumus…')}
                <p class="setting-note">Operasi + − × (*) ÷ (/) % dan perbandingan &gt; &lt; = (hasil 1/0). Fungsi: sum, avg, min, max, round(x, desimal), floor, ceil, abs, count (jumlah pilihan), len, years(tgl) untuk umur, days(tgl1, tgl2), if(syarat, ya, tidak). Jawaban kosong dihitung 0.</p>
                <div class="setting-pair">
                    <label class="setting-field"><span>Tampilan</span><select class="setting-select" data-option="format">${Object.entries(config.formulaFormats).map(([value, label]) => `<option value="${value}" ${question.format === value ? 'selected' : ''}>${escapeHtml(label)}</option>`).join('')}</select></label>
                    <label class="setting-field"><span>Desimal</span><input class="setting-select" type="number" min="0" max="6" value="${question.decimals}" data-option="decimals"></label>
                </div>` : '',
            question.type === 'file' ? `
                <div class="setting-pair">
                    <label class="setting-field"><span>Jenis berkas</span><select class="setting-select" data-option="file_accept">${Object.entries(config.fileKinds).map(([value, kind]) => `<option value="${value}" ${question.file.accept === value ? 'selected' : ''}>${escapeHtml(kind.label)}</option>`).join('')}</select></label>
                    <label class="setting-field"><span>Maks. MB</span><input class="setting-select" type="number" min="1" max="${config.maxFileMb}" value="${question.file.max_mb}" data-option="file_max_mb"></label>
                </div>` : '',
        ].join('');
        const problem = dynamicProblem(question);
        return `
            <label class="setting-field"><span>Nama isian</span><input class="setting-select" type="text" maxlength="255" value="${escapeHtml(tagsToNumbers(question.label))}" data-option="label"></label>
            ${isInfo ? '' : tagPicker('label', question, 'Sisipkan jawaban ke teks pertanyaan…')}
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
            ${dynamicSettings ? `<div class="type-settings"><div class="setting-label"><span>Isian dinamis</span></div>${dynamicSettings}</div>` : ''}
            <p class="setting-note is-warning" data-dynamic-problem ${problem ? '' : 'hidden'}>${escapeHtml(problem)}</p>
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
                <label class="setting-field"><span>${isInfo ? 'Isi keterangan' : 'Teks bantuan'}</span><textarea class="setting-select setting-textarea" rows="3" maxlength="1000" data-option="description">${escapeHtml(tagsToNumbers(question.description))}</textarea></label>
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
                <div class="type-settings">
                    <div class="setting-label"><span>Rincian berulang (roster)</span><span class="setting-value">${block.repeat ? 'AKTIF' : 'TIDAK'}</span></div>
                    <label class="setting-field"><span>Ulangi blok ini</span>
                        <select class="setting-select" data-setting="repeat-source">
                            <option value="">Tidak diulang</option>
                            ${rosterSources(block).map(item => `<option value="${escapeHtml(item.question.id)}" ${item.question.id === block.repeat?.source ? 'selected' : ''}>Per jawaban ${escapeHtml(questionLabel(item))}</option>`).join('')}
                        </select>
                    </label>
                    ${block.repeat ? `<div class="setting-pair">
                        <label class="setting-field"><span>Maks. rincian</span><input class="setting-select" type="number" min="1" max="${config.maxRosterRows}" value="${block.repeat.max}" data-setting="repeat-max"></label>
                        <label class="setting-field"><span>Baris cetak</span><input class="setting-select" type="number" min="1" max="30" value="${block.repeat.print_rows}" data-setting="repeat-print"></label>
                    </div>` : ''}
                    <p class="setting-note">${rosterSources(block).length ? 'Pertanyaan blok ini diisi sekali untuk setiap jawaban: per angka (mis. jumlah ART = 3 → 3 rincian), per pilihan yang dicentang, atau per nama di Daftar nama. Sisipkan {{@baris}} di teks untuk menampilkan nama rinciannya.' : 'Tambahkan pertanyaan Angka, Pilihan lebih dari satu, atau Daftar nama di blok sebelumnya untuk menjadikan blok ini berulang.'}</p>
                    ${rosterProblem(block) ? `<p class="setting-note is-warning">${escapeHtml(rosterProblem(block))}</p>` : ''}
                </div>
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
        if (type === 'info' || type === 'calc') {
            question.required = false;
            question.disabled = false;
        }
        if (type === 'calc' && question.formula === undefined) Object.assign(question, { formula: '', format: 'number', decimals: 0 });
        if (type === 'file' && !question.file) question.file = { accept: 'image', max_mb: 5 };
        if (!choiceTypes.includes(type)) Object.assign(question, { options_from: '', other_option: false });
        if (type !== 'dropdown') question.lookup = null;
        if (type !== 'short') question.mask = '';
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
            found.question.label = tagsToIds(event.target.value);
            const mirror = panelField('label');
            if (mirror) mirror.value = event.target.value;
        }
        if (field === 'description') {
            found.question.description = tagsToIds(event.target.value);
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
            else if (option === 'decimals') question.decimals = clamp(target.value, 0, 6, 0);
            else if (option === 'file_max_mb') question.file.max_mb = clamp(target.value, 1, config.maxFileMb, 5);
            else if (['label', 'description', 'formula'].includes(option)) question[option] = tagsToIds(target.value);
            else question[option] = target.value;
            if (['formula', 'mask', 'other_label', 'file_max_mb'].includes(option)) {
                refreshPreview(question);
                const note = selectionPanel.querySelector('[data-dynamic-problem]');
                if (note) {
                    note.textContent = dynamicProblem(question);
                    note.hidden = !note.textContent;
                }
            }
            if (option === 'label' || option === 'description') {
                const mirror = blockList.querySelector(`[data-question-id="${CSS.escape(question.id)}"] [data-field="${option}"]`);
                if (mirror) mirror.value = target.value;
            }
            return afterPanelChange(question, { preview: ['prefill', 'placeholder'].includes(option) });
        }

        if (target.dataset.lookupField && target.tagName === 'INPUT' && question.lookup) {
            question.lookup[target.dataset.lookupField] = target.value;
            return afterPanelChange(question, { preview: true });
        }
        if (target.dataset.rangePart) {
            const row = target.closest('[data-path]');
            const rule = nodeAt(scopeRoot(question, row.dataset.scope), parsePath(row.dataset.path));
            const parts = rule.value.split(';');
            parts[Number(target.dataset.rangePart)] = target.value;
            rule.value = `${parts[0] ?? ''};${parts[1] ?? ''}`;
            return afterPanelChange(question);
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
        if (setting === 'repeat-source' || setting === 'repeat-max' || setting === 'repeat-print') {
            const found = selected?.kind === 'block' && findBlock(selected.id);
            if (!found) return;
            const { block } = found;
            if (setting === 'repeat-source') block.repeat = target.value ? { source: target.value, max: block.repeat?.max ?? 20, print_rows: block.repeat?.print_rows ?? 3 } : null;
            if (setting === 'repeat-max' && block.repeat) block.repeat.max = clamp(target.value, 1, config.maxRosterRows, 20);
            if (setting === 'repeat-print' && block.repeat) block.repeat.print_rows = clamp(target.value, 1, 30, 3);
            // Questions of other blocks may no longer use this block's questions in their rules.
            if (setting === 'repeat-source') clearConditionsOn([]);
            render();
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
        if (option === 'options_from' || option === 'other_option') {
            if (option === 'options_from') {
                const lookupSource = target.value.startsWith('lookup:') ? target.value.slice(7) : '';
                question.options_from = lookupSource ? '' : target.value;
                question.lookup = lookupSource ? { source: lookupSource, reference: '', value_column: '', label_column: '', filter_column: '', url: '', data_path: '', value_key: '', label_key: '', param: '', depends_on: '' } : null;
            } else {
                question.other_option = target.checked;
            }
            if (question.options_from || question.lookup) question.other_option = false;
            render();
            return;
        }
        const lookupField = target.dataset.lookupField;
        if (lookupField && question.lookup) {
            question.lookup[lookupField] = target.value;
            if (lookupField === 'reference') Object.assign(question.lookup, { value_column: '', label_column: '', filter_column: '' });
            return afterPanelChange(question, { rerender: target.tagName === 'SELECT', preview: true });
        }
        if (option === 'format' || option === 'file_accept') {
            if (option === 'format') question.format = target.value;
            else question.file.accept = target.value;
            return afterPanelChange(question, { preview: true });
        }
        const tagTarget = target.dataset.insertTag;
        if (tagTarget && target.value) {
            const field = selectionPanel.querySelector(`[data-option="${tagTarget}"]`);
            const tag = `{{${target.value}}}`;
            const start = field.selectionStart ?? field.value.length;
            const end = field.selectionEnd ?? field.value.length;
            field.value = `${field.value.slice(0, start)}${tag}${field.value.slice(end)}`;
            target.value = '';
            field.focus();
            field.setSelectionRange(start + tag.length, start + tag.length);
            field.dispatchEvent(new Event('input', { bubbles: true }));
            return;
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
            const wasRange = rangeOperators.includes(rule.operator);
            rule[ruleField] = target.value;
            // A single value and a "from;to" range are not interchangeable.
            if (ruleField === 'operator' && wasRange !== rangeOperators.includes(rule.operator)) rule.value = '';
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
        return dynamicProblem(question) || logicProblem(question) || validationProblemOf(question);
    }

    function findProblem() {
        if (!blocks.length) return { message: 'Tambahkan minimal satu blok.' };
        const untitledBlock = blocks.find(block => !block.title.trim());
        if (untitledBlock) return { message: 'Judul blok belum diisi.', kind: 'block', id: untitledBlock.id, selector: `[data-block-id="${CSS.escape(untitledBlock.id)}"] .block-title` };
        if (!numbers.size) return { message: 'Tambahkan minimal satu pertanyaan.' };
        for (const block of blocks) {
            const blockProblem = conditionProblem('block', block.id) || rosterProblem(block);
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

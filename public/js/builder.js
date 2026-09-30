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

    const blocks = restoreBlocks() ?? [makeBlock('KETERANGAN UMUM')];
    let selected = blocks[0] ? { kind: 'block', id: blocks[0].id } : null;
    let dragState = null;
    let numbers = new Map();

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

    function makeQuestion(type, overrides = {}) {
        return {
            id: makeId('q'),
            type,
            label: '',
            description: '',
            required: false,
            code_length: type === 'code' ? 2 : (choiceTypes.includes(type) ? 1 : 0),
            options: choiceTypes.includes(type) ? ['Pilihan 1', 'Pilihan 2'] : [],
            condition: null,
            ...(type === 'region' ? { region: defaultRegion(), label: 'Identitas wilayah' } : {}),
            ...(type === 'table' ? { table: defaultTable() } : {}),
            ...overrides,
        };
    }

    function makeBlock(title = '', questions = []) {
        return { id: makeId('b'), title, layout: 'full', questions, condition: null };
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
        return {
            mode: table?.mode === 'fixed' ? 'fixed' : 'dynamic',
            max_rows: clamp(table?.max_rows, 1, config.maxTableRows, 20),
            initial_rows: Math.min(clamp(table?.initial_rows, 1, config.maxTableRows, 3), clamp(table?.max_rows, 1, config.maxTableRows, 20)),
            rows: Array.isArray(table?.rows) ? table.rows.map(String) : [],
            columns: columns.length ? columns : defaultTable().columns,
        };
    }

    function normalizeQuestion(question) {
        const normalized = {
            id: String(question.id || makeId('q')),
            type: question.type,
            label: String(question.label ?? ''),
            description: String(question.description ?? ''),
            required: Boolean(question.required),
            code_length: clamp(question.code_length, 0, config.maxCodeLength, 0),
            options: Array.isArray(question.options) ? question.options.map(String) : [],
            condition: normalizeCondition(question.condition),
        };
        if (question.type === 'region') normalized.region = normalizeRegion(question.region);
        if (question.type === 'table') normalized.table = normalizeTable(question.table);
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
        return {
            question_id: String(condition.question_id),
            operator: config.operators[condition.operator] ? condition.operator : 'equals',
            value: String(condition.value ?? ''),
        };
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

    // ── Display logic ──────────────────────────────────────────────────────

    function earlierQuestions(kind, id) {
        const earlier = [];
        for (const block of blocks) {
            if (kind === 'block' && block.id === id) return earlier;
            for (const question of block.questions) {
                if (kind === 'question' && question.id === id) return earlier;
                if (!nonLogicTypes.includes(question.type)) earlier.push({ question, number: numbers.get(question.id) });
            }
        }
        return earlier;
    }

    function targetOf(kind, id) {
        return kind === 'block' ? findBlock(id)?.block : findQuestion(id)?.question;
    }

    function conditionProblem(kind, id) {
        const condition = targetOf(kind, id)?.condition;
        if (!condition) return '';
        const subject = kind === 'block' ? 'blok' : 'pertanyaan';
        if (!earlierQuestions(kind, id).some(item => item.question.id === condition.question_id)) return `Logika ${subject} merujuk pertanyaan yang tidak ada sebelumnya.`;
        if (condition.operator !== 'answered' && !condition.value.trim()) return `Logika ${subject} belum memiliki nilai jawaban.`;
        return '';
    }

    function logicBadge(kind, id) {
        const condition = targetOf(kind, id)?.condition;
        if (!condition) return '';
        const problem = conditionProblem(kind, id);
        if (problem) return `<span class="logic-badge is-invalid" title="${escapeHtml(problem)}">⚠ Logika perlu diperbaiki</span>`;
        const source = earlierQuestions(kind, id).find(item => item.question.id === condition.question_id);
        const text = condition.operator === 'answered'
            ? `${source.number} sudah diisi`
            : `${source.number} ${condition.operator === 'equals' ? '=' : '≠'} ${condition.value}`;
        return `<span class="logic-badge" title="Hanya tampil jika ${escapeHtml(text)}">⤷ Jika ${escapeHtml(truncate(text, 34))}</span>`;
    }

    function refreshLogicSlot(kind, id) {
        const slot = kind === 'block'
            ? blockList.querySelector(`[data-block-id="${CSS.escape(id)}"] > .block-head .logic-slot`)
            : blockList.querySelector(`[data-question-id="${CSS.escape(id)}"] .logic-slot`);
        if (slot) slot.innerHTML = logicBadge(kind, id);
    }

    function clearConditionsOn(questionIds) {
        const removed = new Set(questionIds);
        blocks.forEach(block => {
            if (removed.has(block.condition?.question_id)) block.condition = null;
            block.questions.forEach(question => {
                if (removed.has(question.condition?.question_id)) question.condition = null;
            });
        });
    }

    function renderLogicEditor() {
        const container = selectionPanel.querySelector('#logic-editor');
        const target = selected && targetOf(selected.kind, selected.id);
        if (!container || !target) return;

        const earlier = earlierQuestions(selected.kind, selected.id);
        const condition = target.condition;
        const source = condition && earlier.find(item => item.question.id === condition.question_id);
        const subject = selected.kind === 'block' ? 'blok' : 'isian';
        let html = `<div class="setting-label"><span>Logika tampil</span><span class="setting-value">${condition ? 'BERSYARAT' : 'SELALU'}</span></div>`;

        if (!earlier.length) {
            container.innerHTML = `${html}<p class="setting-note">Belum ada pertanyaan sebelumnya yang bisa dijadikan syarat.</p>`;
            return;
        }

        html += `<label class="setting-field"><span>Tampilkan ${subject} ini</span>
            <select class="setting-select" data-logic-field="question_id">
                <option value="">Selalu tampil</option>
                ${earlier.map(item => `<option value="${escapeHtml(item.question.id)}" ${item.question.id === condition?.question_id ? 'selected' : ''}>Jika ${item.number} ${escapeHtml(truncate(item.question.label || '(tanpa teks)', 34))}</option>`).join('')}
            </select></label>`;

        if (condition && !source) {
            html += '<p class="setting-note is-warning">Pertanyaan syarat tidak lagi berada sebelum elemen ini. Pilih ulang.</p>';
        }

        if (source) {
            html += `<label class="setting-field"><span>Jawabannya</span>
                <select class="setting-select" data-logic-field="operator">
                    ${Object.entries(config.operators).map(([operator, label]) => `<option value="${operator}" ${operator === condition.operator ? 'selected' : ''}>${escapeHtml(label)}</option>`).join('')}
                </select></label>`;

            if (condition.operator !== 'answered') {
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
        const target = selected && targetOf(selected.kind, selected.id);
        if (!target) return;

        if (field === 'question_id') {
            if (!value) {
                target.condition = null;
            } else {
                const source = findQuestion(value)?.question;
                const keepValue = target.condition?.question_id === value;
                target.condition = {
                    question_id: value,
                    operator: target.condition?.operator ?? 'equals',
                    value: keepValue ? target.condition.value : (source && choiceTypes.includes(source.type) ? source.options[0] ?? '' : ''),
                };
            }
            renderLogicEditor();
        } else if (field === 'operator' && target.condition) {
            target.condition.operator = value;
            renderLogicEditor();
        } else if (field === 'value' && target.condition) {
            target.condition.value = value;
        }

        refreshLogicSlot(selected.kind, selected.id);
        if (selected.kind === 'question') {
            blockList.querySelector(`[data-question-id="${CSS.escape(selected.id)}"]`)?.classList.toggle('is-branch', Boolean(target.condition));
        }
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

    // "If the answer is X, ask …": one button per option adds a follow-up question with that condition.
    function branchActions(question) {
        if (!question.options.length) return '';
        return `
            <div class="branch-actions">
                <span>Pertanyaan lanjutan:</span>
                ${question.options.map(option => `<button class="mini-button" type="button" data-action="add-branch" data-option="${escapeHtml(option)}" title="Tambah pertanyaan yang hanya muncul jika jawabannya “${escapeHtml(option)}”">+ jika “${escapeHtml(truncate(option, 18))}”</button>`).join('')}
            </div>`;
    }

    function previewFor(question) {
        if (choiceTypes.includes(question.type)) {
            const options = question.options.length ? question.options : ['Pilihan 1', 'Pilihan 2'];
            const branches = branchActions(question);
            if (question.type === 'dropdown') return `<div class="answer-line answer-select">${escapeHtml(options[0])}<span>▾</span></div>${branches}`;
            return `<div class="choice-list">${options.map((option, index) => `<span class="choice-option ${question.type === 'multiple' ? 'multiple' : ''}"><i></i><b>${index + 1}.</b> ${escapeHtml(option)}</span>`).join('')}</div>${branches}`;
        }
        switch (question.type) {
            case 'region': return regionPreview(question);
            case 'table': return tableEditor(question);
            case 'info':
            case 'code': return '';
            case 'long': return '<div class="answer-area">Jawaban...</div>';
            case 'date': return '<div class="answer-line">Tgl ___ / Bln ___ / Thn _____</div>';
            case 'number': return '<div class="answer-line">Masukkan angka</div>';
            case 'currency': return '<div class="answer-line"><b>Rp</b> ........................</div>';
            case 'email': return '<div class="answer-line">nama@contoh.go.id</div>';
            case 'phone': return '<div class="answer-line">08xx-xxxx-xxxx</div>';
            default: return '<div class="answer-line">Jawaban...</div>';
        }
    }

    // Code boxes on the right of a question, with − / + to change how many there are.
    function codeBoxes(question) {
        if (nonLogicTypes.includes(question.type)) return '<span class="code-field"></span>';
        const length = question.code_length;
        return `
            <span class="code-field ${length ? '' : 'is-empty'}">
                <button class="code-step" type="button" data-action="code-less" aria-label="Kurangi kotak kode" ${length === 0 ? 'disabled' : ''}>−</button>
                <span class="code-boxes" aria-label="${length} kotak kode">${'<i></i>'.repeat(length)}</span>
                <button class="code-step" type="button" data-action="code-more" aria-label="Tambah kotak kode" ${length >= config.maxCodeLength ? 'disabled' : ''}>${length ? '+' : '+ kode'}</button>
            </span>`;
    }

    function questionRow(question) {
        const number = numbers.get(question.id);
        const isInfo = question.type === 'info';
        const classes = ['question-row', `is-${question.type}`, question.condition && 'is-branch', isSelected('question', question.id) && 'is-selected', question.required && 'is-required'].filter(Boolean).join(' ');
        const description = isInfo
            ? `<textarea class="question-desc" rows="2" maxlength="1000" placeholder="Isi keterangan untuk responden" data-field="description">${escapeHtml(question.description)}</textarea>`
            : `<input class="question-desc" type="text" maxlength="1000" value="${escapeHtml(question.description)}" placeholder="Petunjuk pengisian (opsional)" data-field="description">`;
        return `
            <article class="${classes}" data-question-id="${escapeHtml(question.id)}">
                <span class="drag-handle" data-drag-handle title="Seret untuk memindahkan">⠿</span>
                <span class="question-number">${isInfo ? 'i' : number}</span>
                <div class="question-main">
                    <input class="question-label" type="text" maxlength="255" value="${escapeHtml(question.label)}" placeholder="${isInfo ? 'Judul keterangan' : 'Tulis pertanyaan...'}" data-field="label" aria-label="Teks ${isInfo ? 'keterangan' : `pertanyaan ${number}`}">
                    ${description}
                    <span class="logic-slot">${logicBadge('question', question.id)}</span>
                    <div class="question-preview">${previewFor(question)}</div>
                    ${choiceTypes.includes(question.type) ? `<label class="option-editor"><span>PILIHAN</span><textarea data-field="options" aria-label="Pilihan jawaban, satu per baris" placeholder="Satu pilihan per baris">${escapeHtml(question.options.join('\n'))}</textarea></label>` : ''}
                </div>
                ${codeBoxes(question)}
                <button class="question-remove" type="button" data-action="remove-question" aria-label="Hapus ${isInfo ? 'keterangan' : `pertanyaan ${number}`}">×</button>
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

    // ── Selection panel ────────────────────────────────────────────────────

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

    function renderSelectionPanel() {
        const foundQuestion = selected?.kind === 'question' ? findQuestion(selected.id) : null;
        const foundBlock = selected?.kind === 'block' ? findBlock(selected.id) : null;

        if (foundQuestion) {
            const { question, blockIndex } = foundQuestion;
            const isInfo = question.type === 'info';
            const hasCodeBoxes = !nonLogicTypes.includes(question.type);
            selectionPanel.innerHTML = `
                <div class="setting-label"><span>${isInfo ? 'Keterangan' : `Pertanyaan ${numbers.get(question.id)}`}</span><span class="setting-value">BLOK ${toRoman(blockIndex + 1)}</span></div>
                <label class="setting-field"><span>Jenis isian</span>
                    <select class="setting-select" data-setting="type">
                        ${Object.entries(config.questionTypes).map(([type, label]) => `<option value="${type}" ${type === question.type ? 'selected' : ''}>${escapeHtml(label)}</option>`).join('')}
                    </select>
                </label>
                ${hasCodeBoxes ? `<label class="setting-field"><span>Jumlah kotak kode <small>(0–${config.maxCodeLength})</small></span>
                    <input class="setting-select" type="number" min="0" max="${config.maxCodeLength}" value="${question.code_length}" data-setting="code_length">
                </label>` : ''}
                ${isInfo ? '' : `<label class="required-toggle"><input type="checkbox" data-setting="required" ${question.required ? 'checked' : ''}><span class="toggle-track"></span><span>Wajib diisi</span></label>`}
                ${question.type === 'region' ? `<div class="type-settings">${regionSettings(question)}</div>` : ''}
                ${question.type === 'table' ? `<div class="type-settings">${tableSettings(question)}</div>` : ''}
                <div class="logic-editor" id="logic-editor"></div>
                <button class="danger-link" type="button" data-setting-action="remove-question">Hapus ${isInfo ? 'keterangan' : 'pertanyaan'}</button>`;
            renderLogicEditor();
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
            <div class="selected-placeholder"><span class="cursor-icon">↖</span><span>Pilih blok atau pertanyaan di lembar untuk mengatur detailnya.</span></div>`;
    }

    function select(kind, id) {
        if (isSelected(kind, id)) return;
        selected = { kind, id };
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
        if (nonLogicTypes.includes(type)) {
            question.code_length = 0;
            clearConditionsOn([question.id]);
        }
        if (type === 'info') question.required = false;
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

    blockList.addEventListener('click', event => {
        const actionElement = event.target.closest('[data-action]');
        const action = actionElement?.dataset.action;
        if (action === 'remove-question') return removeQuestion(event.target.closest('.question-row').dataset.questionId);
        if (action === 'remove-block') return removeBlock(event.target.closest('.form-block').dataset.blockId);
        if (action === 'add-branch') {
            const parent = findQuestion(event.target.closest('.question-row').dataset.questionId);
            const followUp = makeQuestion('short', { condition: { question_id: parent.question.id, operator: 'equals', value: actionElement.dataset.option } });
            // Keep follow-ups grouped directly under their parent question.
            let insertAt = parent.questionIndex + 1;
            while (parent.block.questions[insertAt]?.condition?.question_id === parent.question.id) insertAt++;
            parent.block.questions.splice(insertAt, 0, followUp);
            selected = { kind: 'question', id: followUp.id };
            render();
            focusField(`[data-question-id="${CSS.escape(followUp.id)}"] .question-label`);
            return;
        }
        if (action === 'code-more' || action === 'code-less') {
            const question = findQuestion(event.target.closest('.question-row').dataset.questionId).question;
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
        if (field === 'label') found.question.label = event.target.value;
        if (field === 'description') found.question.description = event.target.value;
        if (field === 'options') {
            found.question.options = event.target.value.split('\n').map(option => option.trim()).filter(Boolean);
            refreshPreview(found.question);
        }
    });

    selectionPanel.addEventListener('input', event => {
        if (event.target.dataset.logicField === 'value') return updateCondition('value', event.target.value);
        const found = selected?.kind === 'question' && findQuestion(selected.id);
        if (!found) return;
        const { question } = found;

        const regionField = event.target.dataset.regionField;
        if ((regionField === 'code' || regionField === 'name') && event.target.tagName === 'INPUT') {
            const field = question.region.fields.find(item => item.key === event.target.closest('[data-region-key]').dataset.regionKey);
            field[regionField] = event.target.value;
            refreshPreview(question);
            return;
        }

        if (event.target.dataset.setting === 'code_length') {
            question.code_length = clamp(event.target.value, 0, config.maxCodeLength, 0);
            refreshCodeBoxes(question);
        }
        if (event.target.dataset.setting === 'initial_rows') {
            question.table.initial_rows = clamp(event.target.value, 1, question.table.max_rows, 1);
            refreshPreview(question);
        }
        if (event.target.dataset.setting === 'max_rows') {
            question.table.max_rows = clamp(event.target.value, 1, config.maxTableRows, 20);
            question.table.initial_rows = Math.min(question.table.initial_rows, question.table.max_rows);
            refreshPreview(question);
        }
    });

    selectionPanel.addEventListener('change', event => {
        if (event.target.dataset.logicField) return updateCondition(event.target.dataset.logicField, event.target.value);
        const setting = event.target.dataset.setting;

        if (setting === 'add-question') {
            const found = selected?.kind === 'block' && findBlock(selected.id);
            if (found && event.target.value) addQuestion(event.target.value, found.block);
            return;
        }
        if (setting === 'layout') {
            const found = selected?.kind === 'block' && findBlock(selected.id);
            if (found) found.block.layout = event.target.value;
            render();
            return;
        }

        const found = selected?.kind === 'question' && findQuestion(selected.id);
        if (!found) return;
        const { question } = found;

        const regionField = event.target.dataset.regionField;
        if (regionField === 'enabled' || regionField === 'mode' || (regionField === 'name' && event.target.tagName === 'SELECT')) {
            const field = question.region.fields.find(item => item.key === event.target.closest('[data-region-key]').dataset.regionKey);
            field[regionField] = regionField === 'enabled' ? event.target.checked : event.target.value;
            refreshPreview(question);
            renderSelectionPanel();
            return;
        }

        if (setting === 'code_length') event.target.value = question.code_length;
        if (setting === 'max_rows') {
            event.target.value = question.table.max_rows;
            renderSelectionPanel();
        }
        if (setting === 'initial_rows') event.target.value = question.table.initial_rows;
        if (setting === 'required') {
            question.required = event.target.checked;
            blockList.querySelector(`[data-question-id="${CSS.escape(question.id)}"]`)?.classList.toggle('is-required', event.target.checked);
        }
        if (setting === 'type') {
            changeType(question, event.target.value);
            render();
        }
        if (setting === 'table-mode') {
            question.table.mode = event.target.value;
            if (question.table.mode === 'fixed' && !question.table.rows.length) question.table.rows = ['Baris 1', 'Baris 2'];
            render();
        }
    });

    selectionPanel.addEventListener('click', event => {
        const action = event.target.closest('[data-setting-action]')?.dataset.settingAction;
        if (action === 'remove-question' && selected) removeQuestion(selected.id);
        if (action === 'remove-block' && selected) removeBlock(selected.id);
    });

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

    function showNotice(message) {
        document.querySelectorAll('.toast').forEach(toast => toast.remove());
        const toast = document.createElement('div');
        toast.className = 'toast toast-error';
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
        return conditionProblem('question', question.id);
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

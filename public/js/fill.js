(() => {
    const form = document.querySelector('#fill-form');
    if (!form) return;

    const config = window.fillConfig ?? {};
    const isPreview = form.hasAttribute('data-preview');
    const blocks = [...form.querySelectorAll('.fill-block')];
    const note = document.querySelector('#fill-note');
    const draftStatus = document.querySelector('[data-draft-status]');
    const progressBar = document.querySelector('[data-progress-bar]');
    const progressText = document.querySelector('[data-progress-text]');

    const OTHER_VALUE = 'Lainnya';
    const OTHER_SUFFIX = '__lainnya';

    function parseJson(value) {
        try {
            return JSON.parse(value || 'null');
        } catch {
            return null;
        }
    }

    function conditionOf(element) {
        return parseJson(element.dataset.condition);
    }

    function fieldValue(field) {
        return (field?.value ?? '').trim();
    }

    // The answer as the logic sees it; structured answers only count as "answered or not".
    function answerOf(question) {
        const type = question.dataset.type;
        if (type === 'multiple') return [...question.querySelectorAll('input[type="checkbox"]:checked')].map(input => input.value);
        if (type === 'single' || type === 'rating' || type === 'scale') return question.querySelector('input[type="radio"]:checked')?.value ?? '';
        if (type === 'checkbox') return question.querySelector('input[type="checkbox"]:checked')?.value ?? '';
        if (type === 'region' || type === 'table' || type === 'matrix') {
            const filled = [...question.querySelectorAll('input:not([type="radio"]):not([type="hidden"]), select, input[type="radio"]:checked')].some(field => fieldValue(field) !== '');
            return filled ? ['filled'] : [];
        }
        if (type === 'calc') return question.dataset.value ?? '';
        if (type === 'file') {
            const input = question.querySelector('input[type="file"]');
            return input?.files?.length ? input.files[0].name : '';
        }
        const value = fieldValue(question.querySelector('[name^="answers["]:not([data-other-text])'));
        return type === 'currency' ? value.replace(/\D/g, '') : value;
    }

    function isAnswered(answer) {
        return Array.isArray(answer) ? answer.length > 0 : answer !== '';
    }

    function toNumber(value) {
        if (Array.isArray(value) || value === null || value === undefined) return null;
        const text = String(value).trim().replace(',', '.');
        return text !== '' && !Number.isNaN(Number(text)) ? Number(text) : null;
    }

    // A range value is "from;to", both ends included; null when the answer or the range is not numeric.
    function inRange(number, range) {
        const parts = String(range).split(';');
        const from = toNumber(parts[0]);
        const to = toNumber(parts[1]);
        if (number === null || parts.length !== 2 || from === null || to === null || from > to) return null;
        return number >= from && number <= to;
    }

    // Answer tags in a rule value ({{id}} or {{id|fallback}}) become the respondent's answers.
    function resolveTags(value, known) {
        return String(value ?? '').replace(/\{\{([A-Za-z0-9_-]+)(?:\|([^}]*))?\}\}/g, (match, id, fallback) => {
            const answer = known[id];
            const text = Array.isArray(answer) ? answer.join(', ') : String(answer ?? '').trim();
            return text !== '' ? text : fallback ?? '';
        });
    }

    // Same rules as App\Support\SurveyLogic::passes(): case-insensitive; a hidden question counts as unanswered.
    function passes(condition, known) {
        if (!condition) return true;
        const answer = known[condition.question_id] ?? '';
        const resolved = resolveTags(condition.value, known);
        const value = resolved.trim().toLowerCase();
        const values = (Array.isArray(answer) ? answer : [answer]).map(item => String(item ?? '').trim().toLowerCase());
        const text = Array.isArray(answer) ? '' : String(answer).trim().toLowerCase();
        const equals = Array.isArray(answer) ? values.includes(value) : text === value;
        const contains = Array.isArray(answer) ? values.includes(value) : value !== '' && text.includes(value);
        const number = toNumber(answer);
        const target = toNumber(resolved);

        switch (condition.operator) {
            case 'answered': return isAnswered(answer);
            case 'empty': return !isAnswered(answer);
            case 'not_equals': return !equals;
            case 'contains': return contains;
            case 'not_contains': return !contains;
            case 'greater_than': return number !== null && target !== null && number > target;
            case 'less_than': return number !== null && target !== null && number < target;
            case 'greater_or_equal': return number !== null && target !== null && number >= target;
            case 'less_or_equal': return number !== null && target !== null && number <= target;
            case 'between': return inRange(number, resolved) === true;
            case 'not_between': return inRange(number, resolved) === false;
            default: return equals;
        }
    }


    // ── Formulas, mirroring App\Support\Formula ────────────────────────────

    const FUNCTIONS = { sum: [1, Infinity], avg: [1, Infinity], min: [1, Infinity], max: [1, Infinity], round: [1, 2], floor: [1, 1], ceil: [1, 1], abs: [1, 1], count: [1, 1], len: [1, 1], years: [1, 2], days: [2, 2], if: [3, 3] };
    const formulaCache = new Map();

    function parseFormula(formula) {
        if (formulaCache.has(formula)) return formulaCache.get(formula);
        const pattern = /\s*(?:\{\{([A-Za-z0-9_-]+)\}\}|(\d+(?:\.\d+)?)|([A-Za-z_]+)|(>=|<=|==|!=|<>|[-+*/%(),<>=])|"([^"]*)"|'([^']*)')/y;
        const tokens = [];
        let offset = 0;
        while (offset < formula.length && formula.slice(offset).trim() !== '') {
            pattern.lastIndex = offset;
            const match = pattern.exec(formula);
            if (!match) return formulaCache.set(formula, null).get(formula);
            offset = pattern.lastIndex;
            if (match[1] !== undefined) tokens.push(['tag', match[1]]);
            else if (match[2] !== undefined) tokens.push(['num', match[2]]);
            else if (match[3] !== undefined) tokens.push(['name', match[3].toLowerCase()]);
            else if (match[4] !== undefined) tokens.push(['op', match[4] === '<>' ? '!=' : (match[4] === '=' ? '==' : match[4])]);
            else tokens.push(['str', (match[5] ?? '') + (match[6] ?? '')]);
        }
        let position = 0;
        const peek = () => tokens[position] ?? ['end', ''];
        const expect = symbol => {
            if (peek()[1] !== symbol) throw new Error(symbol);
            position++;
        };
        const factor = () => {
            const [kind, value] = tokens[position++] ?? ['end', ''];
            if (kind === 'op' && (value === '-' || value === '+')) {
                const operand = factor();
                return value === '-' ? ['neg', operand] : operand;
            }
            if (kind === 'num') return ['num', Number(value)];
            if (kind === 'tag' || kind === 'str') return [kind, value];
            if (kind === 'op' && value === '(') {
                const inner = comparison();
                expect(')');
                return inner;
            }
            if (kind === 'name' && FUNCTIONS[value]) {
                expect('(');
                const args = [];
                if (peek()[1] !== ')') {
                    do {
                        args.push(comparison());
                    } while (peek()[1] === ',' && ++position);
                }
                expect(')');
                if (args.length < FUNCTIONS[value][0] || args.length > FUNCTIONS[value][1]) throw new Error(value);
                return ['call', value, args];
            }
            throw new Error(value);
        };
        const loop = (next, operators) => () => {
            let left = next();
            while (peek()[0] === 'op' && operators.includes(peek()[1])) {
                const operator = tokens[position++][1];
                left = ['bin', operator, left, next()];
            }
            return left;
        };
        const term = loop(factor, ['*', '/', '%']);
        const additive = loop(term, ['+', '-']);
        const comparison = loop(additive, ['>', '<', '>=', '<=', '==', '!=']);
        let tree = null;
        try {
            tree = tokens.length ? comparison() : null;
            if (position < tokens.length) tree = null;
        } catch {
            tree = null;
        }
        formulaCache.set(formula, tree);
        return tree;
    }

    function formulaNumber(value) {
        if (typeof value === 'number') return value;
        if (Array.isArray(value) || value === null || value === undefined) return 0;
        const text = String(value).trim().replace(',', '.');
        return text !== '' && !Number.isNaN(Number(text)) ? Number(text) : 0;
    }

    function formulaDate(value) {
        if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) throw new Error('date');
        return value;
    }

    function today() {
        const now = new Date();
        return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
    }

    function runFormula(node, answers) {
        switch (node[0]) {
            case 'num':
            case 'str': return node[1];
            case 'tag': return answers[node[1]] ?? null;
            case 'neg': return -formulaNumber(runFormula(node[1], answers));
            case 'bin': {
                const left = formulaNumber(runFormula(node[2], answers));
                const right = formulaNumber(runFormula(node[3], answers));
                if ((node[1] === '/' || node[1] === '%') && right === 0) throw new Error('zero');
                switch (node[1]) {
                    case '+': return left + right;
                    case '-': return left - right;
                    case '*': return left * right;
                    case '/': return left / right;
                    case '%': return left % right;
                    case '>': return Number(left > right);
                    case '<': return Number(left < right);
                    case '>=': return Number(left >= right);
                    case '<=': return Number(left <= right);
                    case '==': return Number(Math.abs(left - right) < 1e-9);
                    default: return Number(Math.abs(left - right) >= 1e-9);
                }
            }
            default: {
                const [, name, args] = node;
                if (name === 'if') return formulaNumber(runFormula(args[formulaNumber(runFormula(args[0], answers)) !== 0 ? 1 : 2], answers));
                const values = args.map(argument => runFormula(argument, answers));
                const numbers = () => values.map(formulaNumber);
                switch (name) {
                    case 'sum': return numbers().reduce((total, value) => total + value, 0);
                    case 'avg': return numbers().reduce((total, value) => total + value, 0) / values.length;
                    case 'min': return Math.min(...numbers());
                    case 'max': return Math.max(...numbers());
                    case 'round': {
                        const factor = 10 ** formulaNumber(values[1] ?? 0);
                        return Math.round(formulaNumber(values[0]) * factor) / factor;
                    }
                    case 'floor': return Math.floor(formulaNumber(values[0]));
                    case 'ceil': return Math.ceil(formulaNumber(values[0]));
                    case 'abs': return Math.abs(formulaNumber(values[0]));
                    case 'count': return Array.isArray(values[0]) ? values[0].filter(isAnswered).length : Number(isAnswered(values[0] ?? ''));
                    case 'len': return Array.isArray(values[0]) ? 0 : [...String(values[0] ?? '').trim()].length;
                    case 'years': {
                        const [from, to] = [formulaDate(values[0]), formulaDate(values[1] ?? today())];
                        return Number(to.slice(0, 4)) - Number(from.slice(0, 4)) - (to.slice(5) < from.slice(5) ? 1 : 0);
                    }
                    default: return Math.round((Date.parse(`${formulaDate(values[1])}T00:00:00Z`) - Date.parse(`${formulaDate(values[0])}T00:00:00Z`)) / 86400000);
                }
            }
        }
    }

    // Null when the formula's answers are all empty or it cannot be worked out.
    function evaluateFormula(formula, answers) {
        const tree = parseFormula(formula);
        if (!tree) return null;
        const references = [...formula.matchAll(/\{\{([A-Za-z0-9_-]+)\}\}/g)].map(match => match[1]);
        if (references.length && !references.some(id => isAnswered(answers[id] ?? ''))) return null;
        try {
            const value = formulaNumber(runFormula(tree, answers));
            return Number.isFinite(value) ? value : null;
        } catch {
            return null;
        }
    }

    function plainNumber(value, decimals) {
        const text = (Math.round(value * 10 ** decimals) / 10 ** decimals).toFixed(decimals);
        return /^-0(\.0+)?$/.test(text) ? text.slice(1) : text;
    }

    function displayNumber(value, format, decimals) {
        const number = Number(value).toLocaleString('id-ID', { minimumFractionDigits: format === 'currency' ? 0 : decimals, maximumFractionDigits: format === 'currency' ? 0 : decimals });
        if (format === 'currency') return `Rp ${number}`;
        return format === 'percent' ? `${number}%` : number;
    }

    // ── Answers quoted in question texts, options taken from other answers ─

    function answerText(question, answer, known) {
        if (!isAnswered(answer ?? '')) return '';
        const id = question?.dataset.questionId;
        const other = id ? String(known[`${id}${OTHER_SUFFIX}`] ?? '').trim() : '';
        const values = [answer].flat().map(value => (value === OTHER_VALUE && other ? other : value));
        if (question?.dataset.type === 'calc') return displayNumber(answer, question.dataset.format, Number(question.dataset.decimals || 0));
        if (question?.dataset.type === 'currency') return displayNumber(answer, 'currency', 0);
        if (question?.dataset.type === 'date' && /^\d{4}-\d{2}-\d{2}$/.test(answer)) return formatDate(answer);
        return values.join(', ');
    }

    function updatePipes(known) {
        form.closest('body').querySelectorAll('[data-pipe]').forEach(pipe => {
            const source = form.querySelector(`.fill-question[data-question-id="${CSS.escape(pipe.dataset.pipe)}"]`);
            const text = answerText(source, known[pipe.dataset.pipe], known);
            pipe.textContent = text || pipe.dataset.fallback || '…';
            pipe.classList.toggle('is-empty', !text);
        });
    }

    function sourceOptions(sourceId, known) {
        const other = String(known[`${sourceId}${OTHER_SUFFIX}`] ?? '').trim();
        const chosen = [known[sourceId] ?? []].flat().filter(value => typeof value === 'string' && value.trim() !== '');
        return [...new Set(chosen.map(value => (value === OTHER_VALUE && other ? other : value)))];
    }

    // Rebuilds a choice question's options from the answers chosen on its source question, keeping what still applies.
    function syncDynamicOptions(question, known) {
        const options = sourceOptions(question.dataset.optionsFrom, known);
        const key = JSON.stringify(options);
        if (question.dataset.optionsKey === key) return;
        question.dataset.optionsKey = key;
        const type = question.dataset.type;
        const container = question.querySelector('[data-options]');
        const name = `answers[${question.dataset.questionId}]`;
        const selected = [answerOf(question)].flat();
        if (type === 'dropdown') {
            const placeholder = container.querySelector('option[value=""]');
            container.replaceChildren(placeholder, ...options.map(option => new Option(option, option, false, selected.includes(option))));
        } else {
            container.replaceChildren(...options.map(option => {
                const label = document.createElement('label');
                label.className = `cawi-option${type === 'multiple' ? ' is-multiple' : ''}`;
                const input = document.createElement('input');
                input.type = type === 'single' ? 'radio' : 'checkbox';
                input.name = type === 'single' ? name : `${name}[]`;
                input.value = option;
                input.checked = selected.includes(option);
                const text = document.createElement('span');
                text.textContent = option;
                label.append(input, text);
                return label;
            }));
            container.classList.toggle('is-columns', options.length > 4 && question.classList.contains('w-full'));
        }
        const empty = question.querySelector('[data-options-empty]');
        if (empty) empty.hidden = options.length > 0;
    }

    function syncOtherText(question) {
        const text = question.querySelector('[data-other-text]');
        if (!text) return;
        const chosen = [answerOf(question)].flat().includes(OTHER_VALUE);
        text.hidden = !chosen;
        if (!chosen) text.value = '';
    }

    // A group is {operator: "and" | "or", rules: [...]} where each rule is a condition or a nested group.
    function evaluate(group, known) {
        const rules = group?.rules ?? [];
        if (!rules.length) return false;
        const results = rules.map(rule => (rule.rules ? evaluate(rule, known) : passes(rule, known)));
        return group.operator === 'or' ? results.includes(true) : !results.includes(false);
    }

    const ACTIONS = {
        hide: ['hidden', true], show: ['hidden', false],
        disable: ['disabled', true], enable: ['disabled', false],
        require: ['required', true], optional: ['required', false],
    };

    // Mirrors SurveyLogic::states(): base flags, then the field logic's actions when its rules hold.
    function stateOf(question, blockVisible, known) {
        const state = {
            hidden: !blockVisible || question.hasAttribute('data-static-hidden'),
            disabled: question.hasAttribute('data-static-disabled'),
            required: question.hasAttribute('data-static-required'),
        };
        const logic = parseJson(question.dataset.logic);
        if (blockVisible && logic && evaluate(logic.conditions, known)) {
            (logic.actions ?? []).forEach(action => {
                const [key, value] = ACTIONS[action] ?? [];
                if (key) state[key] = value;
            });
        }
        if (question.dataset.type === 'info' || question.dataset.type === 'calc' || state.hidden || state.disabled) state.required = false;
        return state;
    }

    // Questions the respondent can see and change.
    function editableQuestions() {
        return [...form.querySelectorAll('.fill-question:not([data-type="info"]):not([data-type="calc"])')]
            .filter(question => !question.hidden && !question.closest('.fill-block').hidden && !question.classList.contains('is-disabled'));
    }

    function applyLogic() {
        const known = {};
        blocks.forEach(block => {
            const blockVisible = passes(conditionOf(block), known);
            block.hidden = !blockVisible;
            document.querySelector(`[data-nav-block="${CSS.escape(block.dataset.blockId)}"]`)?.toggleAttribute('hidden', !blockVisible);
            block.querySelectorAll('.fill-question').forEach(question => {
                const state = stateOf(question, blockVisible, known);
                question.hidden = state.hidden;
                question.classList.toggle('is-disabled', state.disabled);
                question.toggleAttribute('data-required', state.required);
                question.querySelectorAll('input, select, textarea, button').forEach(field => (field.disabled = state.hidden || state.disabled));
                if (state.hidden || question.dataset.type === 'info') return;
                const id = question.dataset.questionId;
                if (question.dataset.optionsFrom) syncDynamicOptions(question, known);
                if (question.dataset.type === 'calc') {
                    const value = evaluateFormula(question.dataset.formula ?? '', known);
                    const decimals = Number(question.dataset.decimals || 0);
                    question.dataset.value = value === null ? '' : plainNumber(value, decimals);
                    question.querySelector('[data-calc-output]').textContent = value === null ? '—' : displayNumber(question.dataset.value, question.dataset.format, decimals);
                }
                syncOtherText(question);
                known[id] = answerOf(question);
                const other = question.querySelector('[data-other-text]');
                if (other) known[`${id}${OTHER_SUFFIX}`] = fieldValue(other);
            });
        });
        updatePipes(known);
        form.querySelectorAll('.cawi-table[data-mode="dynamic"]').forEach(table => {
            const question = table.closest('.fill-question');
            if (!question.hidden && !question.classList.contains('is-disabled')) updateAddButton(table);
        });
        updateChecks(known);
        updateCounters();
        updateProgress();
    }

    // Consistency checks appear as soon as their conditions hold (e.g. "Laki-laki" and "hamil").
    function updateChecks(known) {
        form.querySelectorAll('.fill-question[data-validation]').forEach(question => {
            const checks = parseJson(question.dataset.validation)?.checks ?? [];
            const active = !question.hidden && !question.closest('.fill-block').hidden;
            checks.forEach(check => {
                const element = question.querySelector(`[data-check-id="${CSS.escape(check.id)}"]`);
                if (!element) return;
                const failing = active && evaluate(check.conditions, known);
                element.hidden = !failing;
                element.querySelectorAll('input').forEach(input => (input.disabled = !failing));
                if (!failing) element.classList.remove('needs-attention');
            });
        });
    }

    // An error check must be fixed; a warning check must be confirmed before sending.
    function consistencyProblem(question) {
        for (const element of question.querySelectorAll('[data-check-id]:not([hidden])')) {
            if (!element.classList.contains('is-warning')) {
                element.classList.add('needs-attention');
                return 'Jawaban tidak konsisten. Periksa kembali.';
            }
            if (!element.querySelector('input')?.checked) {
                element.classList.add('needs-attention');
                return 'Centang konfirmasi jika jawaban ini sudah benar.';
            }
            element.classList.remove('needs-attention');
        }
        return '';
    }

    function updateCounters() {
        form.querySelectorAll('[data-max-length]').forEach(question => {
            const counter = question.querySelector('[data-counter]');
            if (!counter) return;
            const length = fieldValue(question.querySelector('[name^="answers["]')).length;
            const max = Number(question.dataset.maxLength);
            counter.textContent = `${length}/${max} karakter`;
            counter.classList.toggle('is-over', length > max);
        });
    }

    function updateProgress() {
        if (!progressText) return;
        const questions = editableQuestions();
        const answered = questions.filter(question => isAnswered(answerOf(question))).length;
        const percent = questions.length ? Math.round((answered / questions.length) * 100) : 0;
        progressBar.style.width = `${percent}%`;
        progressText.textContent = `${answered} dari ${questions.length} pertanyaan terisi`;
    }

    // ── "Validasi" rules, mirroring App\Support\FieldValidation ─────────────

    function isDate(value) {
        return /^\d{4}-\d{2}-\d{2}$/.test(value) && !Number.isNaN(Date.parse(value));
    }

    function formatDate(value) {
        return new Date(`${value}T00:00:00`).toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' });
    }

    function rulePasses(type, value, answer) {
        if (!isAnswered(answer)) return true;
        const text = Array.isArray(answer) ? '' : String(answer);
        const lower = text.toLowerCase();
        const target = String(value).toLowerCase();
        switch (type) {
            case 'min_length': return [...text].length >= Number(value);
            case 'max_length': return [...text].length <= Number(value);
            case 'contains': return lower.includes(target);
            case 'not_contains': return !lower.includes(target);
            case 'starts_with': return lower.startsWith(target);
            case 'pattern':
                try {
                    return new RegExp(value, 'u').test(text);
                } catch {
                    return true;
                }
            case 'min_value': return toNumber(text) !== null && toNumber(text) >= Number(value);
            case 'max_value': return toNumber(text) !== null && toNumber(text) <= Number(value);
            case 'after_date': return isDate(text) && text >= value;
            case 'before_date': return isDate(text) && text <= value;
            case 'min_selected': return [answer].flat().length >= Number(value);
            case 'max_selected': return [answer].flat().length <= Number(value);
            default: return true;
        }
    }

    function ruleMessage(type, value) {
        switch (type) {
            case 'min_length': return `Minimal ${value} karakter.`;
            case 'max_length': return `Maksimal ${value} karakter.`;
            case 'contains': return `Harus berisi "${value}".`;
            case 'not_contains': return `Tidak boleh berisi "${value}".`;
            case 'starts_with': return `Harus diawali dengan "${value}".`;
            case 'pattern': return 'Format isian tidak sesuai.';
            case 'min_value': return `Nilai minimal ${value}.`;
            case 'max_value': return `Nilai maksimal ${value}.`;
            case 'after_date': return `Tanggal paling awal ${formatDate(value)}.`;
            case 'before_date': return `Tanggal paling akhir ${formatDate(value)}.`;
            case 'min_selected': return `Pilih minimal ${value} jawaban.`;
            case 'max_selected': return `Pilih maksimal ${value} jawaban.`;
            default: return 'Isian tidak valid.';
        }
    }

    function validationProblem(question, answer) {
        const validation = parseJson(question.dataset.validation);
        const failed = (validation?.rules ?? []).find(rule => !rulePasses(rule.type, rule.value, answer));
        if (!failed) return '';
        return (validation.message ?? '').trim() || ruleMessage(failed.type, failed.value);
    }

    // ── Inputs ─────────────────────────────────────────────────────────────

    function formatRupiah(field) {
        const digits = field.value.replace(/\D/g, '').replace(/^0+(?=\d)/, '');
        field.value = digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    function syncCodeDigits(group) {
        const digits = [...group.querySelectorAll('.code-digit')];
        const value = group.querySelector('input[type="hidden"]').value;
        digits.forEach((digit, index) => (digit.value = value[index] ?? ''));
    }

    form.querySelectorAll('.code-input').forEach(group => {
        const digits = [...group.querySelectorAll('.code-digit')];
        const hidden = group.querySelector('input[type="hidden"]');
        const sync = () => {
            hidden.value = digits.map(digit => digit.value).join('');
        };

        digits.forEach((digit, index) => {
            digit.addEventListener('input', () => {
                digit.value = digit.value.replace(/\D/g, '').slice(-1);
                if (digit.value && digits[index + 1]) digits[index + 1].focus();
                sync();
            });
            digit.addEventListener('keydown', event => {
                if (event.key === 'Backspace' && !digit.value && digits[index - 1]) digits[index - 1].focus();
                if (event.key === 'ArrowLeft' && digits[index - 1]) digits[index - 1].focus();
                if (event.key === 'ArrowRight' && digits[index + 1]) digits[index + 1].focus();
            });
            digit.addEventListener('paste', event => {
                const pasted = (event.clipboardData?.getData('text') ?? '').replace(/\D/g, '');
                if (!pasted) return;
                event.preventDefault();
                digits.forEach((box, boxIndex) => {
                    if (boxIndex >= index && pasted[boxIndex - index] !== undefined) box.value = pasted[boxIndex - index];
                });
                digits[Math.min(digits.length - 1, index + pasted.length)].focus();
                sync();
                applyLogic();
            });
        });
    });

    form.querySelectorAll('[data-currency]').forEach(formatRupiah);

    // A slider only holds an answer once the respondent has touched it; until then it shows "—".
    function syncSlider(slider) {
        const value = slider.querySelector('input[type="hidden"]').value;
        if (value !== '') slider.querySelector('[data-slider-range]').value = value;
        slider.querySelector('[data-slider-output]').textContent = value === '' ? '—' : value;
        slider.querySelector('[data-slider-clear]').hidden = value === '';
    }

    function setSlider(slider, value) {
        slider.querySelector('input[type="hidden"]').value = value;
        syncSlider(slider);
    }

    // ── Signature pads ─────────────────────────────────────────────────────
    // The drawing is kept as a PNG data URL in the pad's hidden input.

    function drawSignature(pad) {
        const canvas = pad.querySelector('canvas');
        const context = canvas.getContext('2d');
        const value = pad.querySelector('input[type="hidden"]').value;
        context.clearRect(0, 0, canvas.width, canvas.height);
        if (!value.startsWith('data:image/png;base64,')) return;
        const image = new Image();
        image.onload = () => context.drawImage(image, 0, 0, canvas.width, canvas.height);
        image.src = value;
    }

    form.querySelectorAll('[data-signature]').forEach(pad => {
        const canvas = pad.querySelector('canvas');
        const hidden = pad.querySelector('input[type="hidden"]');
        const context = canvas.getContext('2d');
        let drawing = false;

        // The canvas is scaled by CSS, so pointer positions are mapped back to its own pixels.
        const point = event => {
            const bounds = canvas.getBoundingClientRect();
            return [(event.clientX - bounds.left) * canvas.width / bounds.width, (event.clientY - bounds.top) * canvas.height / bounds.height];
        };
        const finish = () => {
            if (!drawing) return;
            drawing = false;
            hidden.value = canvas.toDataURL('image/png');
            hidden.dispatchEvent(new Event('input', { bubbles: true }));
        };

        canvas.addEventListener('pointerdown', event => {
            if (hidden.disabled) return;
            event.preventDefault();
            drawing = true;
            canvas.setPointerCapture(event.pointerId);
            Object.assign(context, { lineWidth: 2.5, lineCap: 'round', lineJoin: 'round', strokeStyle: '#111' });
            const [x, y] = point(event);
            context.beginPath();
            context.moveTo(x, y);
            // A tap without movement still leaves a dot.
            context.lineTo(x + 0.01, y);
            context.stroke();
        });
        canvas.addEventListener('pointermove', event => {
            if (!drawing) return;
            context.lineTo(...point(event));
            context.stroke();
        });
        canvas.addEventListener('pointerup', finish);
        canvas.addEventListener('pointercancel', finish);
        pad.querySelector('[data-signature-clear]').addEventListener('click', () => {
            hidden.value = '';
            drawSignature(pad);
            hidden.dispatchEvent(new Event('input', { bubbles: true }));
        });

        drawSignature(pad);
    });

    // ── Tables with respondent-added rows ──────────────────────────────────

    function renumberRows(table) {
        table.querySelectorAll('[data-table-row]').forEach((row, index) => {
            row.querySelector('th').textContent = index + 1;
        });
    }

    function addRow(table) {
        const body = table.querySelector('[data-table-body]');
        const template = table.closest('.fill-question').querySelector('[data-row-template]');
        const next = Number(table.dataset.nextIndex ?? body.children.length);
        table.dataset.nextIndex = String(next + 1);
        body.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__ROW__', String(next)));
        renumberRows(table);
        updateAddButton(table);
        return body.lastElementChild;
    }

    function updateAddButton(table) {
        const button = table.closest('.fill-question').querySelector('[data-add-row]');
        if (button) button.disabled = table.querySelectorAll('[data-table-row]').length >= Number(table.dataset.maxRows);
    }

    form.querySelectorAll('.cawi-table[data-mode="dynamic"]').forEach(table => {
        table.dataset.nextIndex = String(table.querySelectorAll('[data-table-row]').length);
        updateAddButton(table);
    });

    form.addEventListener('click', event => {
        const addButton = event.target.closest('[data-add-row]');
        if (addButton) {
            const table = addButton.closest('.fill-question').querySelector('.cawi-table');
            addRow(table).querySelector('input, select')?.focus();
            scheduleDraft();
            return;
        }
        // Clicking a slider where its handle already sits is an answer too, though nothing moves.
        const sliderRange = event.target.closest('[data-slider-range]');
        const sliderClear = event.target.closest('[data-slider-clear]');
        if (sliderRange || sliderClear) {
            setSlider(event.target.closest('[data-slider]'), sliderRange ? sliderRange.value : '');
            applyLogic();
            scheduleDraft();
            return;
        }
        const removeButton = event.target.closest('[data-remove-row]');
        if (removeButton) {
            const table = removeButton.closest('.cawi-table');
            const rows = table.querySelectorAll('[data-table-row]');
            if (rows.length > 1) removeButton.closest('tr').remove();
            else removeButton.closest('tr').querySelectorAll('input, select').forEach(field => (field.value = ''));
            renumberRows(table);
            updateAddButton(table);
            applyLogic();
            scheduleDraft();
        }
    });

    // ── Validation ─────────────────────────────────────────────────────────

    function setError(question, message) {
        question.classList.toggle('has-error', Boolean(message));
        question.querySelector('[data-error]').textContent = message;
    }

    function problemWith(question) {
        const type = question.dataset.type;
        const required = question.hasAttribute('data-required');

        if (type === 'region') {
            if (!required) return '';
            const empty = [...question.querySelectorAll('[data-region-input]')].some(field => !fieldValue(field))
                || [...question.querySelectorAll('.cawi-options')].some(group => !group.querySelector('input:checked'));
            return empty ? 'Lengkapi semua isian wilayah.' : '';
        }
        if (type === 'table') {
            return required && !isAnswered(answerOf(question)) ? 'Isi minimal satu baris.' : '';
        }
        if (type === 'matrix') {
            const unanswered = [...question.querySelectorAll('[data-matrix-row]')].some(row => !row.querySelector('input:checked'));
            return required && unanswered ? 'Pilih jawaban pada setiap baris.' : '';
        }

        const answer = answerOf(question);
        if (!isAnswered(answer)) return required ? 'Pertanyaan ini wajib diisi.' : consistencyProblem(question);
        if ([answer].flat().includes(OTHER_VALUE) && question.querySelector('[data-other-text]') && !fieldValue(question.querySelector('[data-other-text]'))) return 'Sebutkan jawaban lainnya.';
        if (question.dataset.mask && !maskPattern(question.dataset.mask).test(answer)) return `Isi sesuai format ${question.dataset.mask}.`;
        if (type === 'time' && !/^\d{2}:\d{2}$/.test(answer)) return 'Isi jam dengan format JJ:MM.';
        if (type === 'location' && !locationPattern.test(answer)) return 'Isi koordinat "lintang, bujur", mis. -7.0321, 112.7464.';
        if (type === 'file') {
            const file = question.querySelector('input[type="file"]').files[0];
            const maxMb = Number(question.querySelector('input[type="file"]').dataset.maxMb || 5);
            if (file && file.size > maxMb * 1024 * 1024) return `Ukuran berkas maksimal ${maxMb} MB.`;
        }
        if (type === 'number' && Number.isNaN(Number(answer))) return 'Isi dengan angka.';
        if (type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(answer)) return 'Isi dengan alamat email yang valid.';
        if (type === 'phone' && !/^[0-9+\-\s().]{6,20}$/.test(answer)) return 'Isi dengan nomor telepon yang valid.';
        if (type === 'url' && !/^https?:\/\/\S+$/i.test(answer)) return 'Isi dengan tautan yang diawali http:// atau https://.';
        const codeDigits = question.querySelectorAll('.code-digit').length;
        if (codeDigits && !new RegExp(`^\\d{${codeDigits}}$`).test(answer)) return `Isi ${codeDigits} digit angka.`;
        const maxLength = Number(question.dataset.maxLength || 0);
        if (maxLength && [...String(answer)].length > maxLength) return `Maksimal ${maxLength} karakter.`;
        return validationProblem(question, answer) || consistencyProblem(question);
    }

    // ── Draft saved in this browser ────────────────────────────────────────

    let draftTimer = null;

    function storage() {
        try {
            return config.useDraft ? window.localStorage : null;
        } catch {
            return null;
        }
    }

    function saveDraft() {
        const store = storage();
        if (!store) return;
        // Chosen files cannot be kept in a draft; everything else can.
        const entries = [...new FormData(form).entries()].filter(([name, value]) => name.startsWith('answers[') && typeof value === 'string');
        try {
            store.setItem(config.draftKey, JSON.stringify({ savedAt: Date.now(), entries }));
            const time = new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
            if (draftStatus) draftStatus.textContent = `Tersimpan sementara pukul ${time}.`;
        } catch {
            if (draftStatus) draftStatus.textContent = 'Draf tidak dapat disimpan di perangkat ini.';
        }
    }

    function scheduleDraft() {
        if (!storage()) return;
        clearTimeout(draftTimer);
        draftTimer = setTimeout(saveDraft, 800);
    }

    function restoreDraft() {
        const store = storage();
        if (!store || !config.restoreDraft) return;
        let draft = null;
        try {
            draft = JSON.parse(store.getItem(config.draftKey) || 'null');
        } catch {
            return;
        }
        if (!Array.isArray(draft?.entries) || !draft.entries.length) return;

        // Dynamic table rows were saved with whatever indexes they had; recreate them as 0..n-1.
        const rowMaps = new Map();
        const entries = draft.entries.map(([name, value]) => {
            const match = name.match(/^answers\[([^\]]+)\]\[(\d+)\]\[([^\]]+)\]$/);
            const table = match && form.querySelector(`.cawi-table[data-table="${CSS.escape(match[1])}"][data-mode="dynamic"]`);
            if (!table) return [name, value];
            if (!rowMaps.has(table)) rowMaps.set(table, new Map());
            const map = rowMaps.get(table);
            if (!map.has(match[2])) map.set(match[2], map.size);
            return [`answers[${match[1]}][${map.get(match[2])}][${match[3]}]`, value];
        });
        rowMaps.forEach((map, table) => {
            table.querySelector('[data-table-body]').innerHTML = '';
            table.dataset.nextIndex = '0';
            for (let index = 0; index < Math.min(map.size, Number(table.dataset.maxRows)); index++) addRow(table);
        });

        entries.forEach(([name, value]) => {
            form.querySelectorAll(`[name="${name.replace(/["\\]/g, '\\$&')}"]`).forEach(field => {
                if (field.type === 'radio' || field.type === 'checkbox') {
                    if (field.value === value) field.checked = true;
                } else {
                    field.value = value;
                }
            });
        });
        form.querySelectorAll('.code-input').forEach(syncCodeDigits);
        form.querySelectorAll('[data-currency]').forEach(formatRupiah);
        form.querySelectorAll('[data-slider]').forEach(syncSlider);
        form.querySelectorAll('[data-signature]').forEach(drawSignature);

        const savedAt = new Date(draft.savedAt).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' });
        if (draftStatus) draftStatus.innerHTML = `Melanjutkan draf ${savedAt}. <button type="button" class="link-button" data-clear-draft>Mulai ulang</button>`;
    }

    document.querySelector('[data-save-draft]')?.addEventListener('click', saveDraft);
    form.addEventListener('click', event => {
        if (!event.target.closest('[data-clear-draft]')) return;
        storage()?.removeItem(config.draftKey);
        window.location.reload();
    });

    // ── Events ─────────────────────────────────────────────────────────────

    // ── Input masks, locations, uploads ────────────────────────────────────

    const locationPattern = /^(-?\d{1,2}(?:\.\d+)?),\s*(-?\d{1,3}(?:\.\d+)?)$/;

    function maskPattern(mask) {
        const pattern = [...mask].map(character => ({ 9: '[0-9]', a: '[A-Za-z]', '*': '[A-Za-z0-9]' })[character] ?? character.replace(/[.*+?^${}()|[\]\\/-]/g, '\\$&')).join('');
        return new RegExp(`^${pattern}$`);
    }

    // Types the mask's fixed characters (dots, dashes) automatically and drops what does not fit.
    function applyMask(field, mask) {
        const raw = field.value.replace(/[^A-Za-z0-9]/g, '');
        let result = '';
        let index = 0;
        for (const character of mask) {
            if (index >= raw.length) break;
            if (character === '9' || character === 'a' || character === '*') {
                const next = raw[index];
                const fits = character === '9' ? /\d/.test(next) : (character === 'a' ? /[A-Za-z]/.test(next) : true);
                if (!fits) break;
                result += character === 'a' ? next.toUpperCase() : next;
                index++;
            } else {
                result += character;
            }
        }
        field.value = result;
    }

    form.querySelectorAll('.fill-question[data-mask] input.fill-input').forEach(field => applyMask(field, field.closest('[data-mask]').dataset.mask));

    form.addEventListener('click', event => {
        const button = event.target.closest('[data-locate]');
        if (!button) return;
        const container = button.closest('[data-location]');
        const status = container.querySelector('[data-location-status]');
        if (!navigator.geolocation) {
            status.textContent = 'Perangkat ini tidak mendukung pengambilan lokasi. Isi koordinat secara manual.';
            return;
        }
        status.textContent = 'Mengambil lokasi…';
        navigator.geolocation.getCurrentPosition(position => {
            const field = container.querySelector('input');
            field.value = `${position.coords.latitude.toFixed(6)}, ${position.coords.longitude.toFixed(6)}`;
            status.textContent = `Akurasi sekitar ${Math.round(position.coords.accuracy)} m.`;
            field.dispatchEvent(new Event('input', { bubbles: true }));
        }, error => {
            status.textContent = error.code === 1 ? 'Izin lokasi ditolak. Izinkan akses lokasi atau isi koordinat secara manual.' : 'Lokasi tidak dapat diambil. Coba lagi atau isi secara manual.';
        }, { enableHighAccuracy: true, timeout: 15000 });
    });

    form.addEventListener('change', event => {
        if (!event.target.matches('[data-file] input[type="file"]')) return;
        const preview = event.target.closest('[data-file]').querySelector('[data-file-preview]');
        const file = event.target.files[0];
        if (preview.src) URL.revokeObjectURL(preview.src);
        preview.hidden = !file || !file.type.startsWith('image/');
        if (!preview.hidden) preview.src = URL.createObjectURL(file);
    });

    form.addEventListener('input', event => {
        if (event.target.matches('[data-currency]')) formatRupiah(event.target);
        if (event.target.closest('.fill-question[data-mask]') && event.target.matches('input.fill-input')) applyMask(event.target, event.target.closest('[data-mask]').dataset.mask);
        if (event.target.matches('[data-slider-range]')) setSlider(event.target.closest('[data-slider]'), event.target.value);
        const question = event.target.closest('.fill-question');
        if (question?.classList.contains('has-error')) setError(question, '');
        applyLogic();
        scheduleDraft();
    });
    form.addEventListener('change', () => {
        applyLogic();
        scheduleDraft();
    });

    form.addEventListener('submit', event => {
        applyLogic();
        let firstInvalid = null;
        editableQuestions().forEach(question => {
            const problem = problemWith(question);
            setError(question, problem);
            if (problem && !firstInvalid) firstInvalid = question;
        });

        if (firstInvalid) {
            event.preventDefault();
            firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
            firstInvalid.querySelector('input:not([type="hidden"]), select, textarea')?.focus({ preventScroll: true });
            note.textContent = 'Masih ada isian yang perlu diperbaiki.';
            return;
        }

        if (isPreview) {
            event.preventDefault();
            note.textContent = 'Pratinjau: semua isian valid. Pada formulir asli, jawaban akan terkirim.';
            return;
        }
        clearTimeout(draftTimer);
        saveDraft();
    });

    // Highlight the block currently on screen in the left-hand navigation.
    const navLinks = [...document.querySelectorAll('[data-nav-target]')];
    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver(entries => {
            entries.filter(entry => entry.isIntersecting).forEach(entry => {
                navLinks.forEach(link => link.classList.toggle('is-active', link.dataset.navTarget === entry.target.id));
            });
        }, { rootMargin: '-30% 0px -60% 0px' });
        navLinks.forEach(link => {
            const section = document.getElementById(link.dataset.navTarget);
            if (section) observer.observe(section);
        });
    }

    restoreDraft();
    applyLogic();
})();

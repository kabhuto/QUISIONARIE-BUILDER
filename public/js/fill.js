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
        const value = fieldValue(question.querySelector('[name^="answers["]'));
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

    // Same rules as App\Support\SurveyLogic::passes(): case-insensitive; a hidden question counts as unanswered.
    function passes(condition, known) {
        if (!condition) return true;
        const answer = known[condition.question_id] ?? '';
        const value = String(condition.value ?? '').trim().toLowerCase();
        const values = (Array.isArray(answer) ? answer : [answer]).map(item => String(item ?? '').trim().toLowerCase());
        const text = Array.isArray(answer) ? '' : String(answer).trim().toLowerCase();
        const equals = Array.isArray(answer) ? values.includes(value) : text === value;
        const contains = Array.isArray(answer) ? values.includes(value) : value !== '' && text.includes(value);
        const number = toNumber(answer);
        const target = toNumber(condition.value);

        switch (condition.operator) {
            case 'answered': return isAnswered(answer);
            case 'empty': return !isAnswered(answer);
            case 'not_equals': return !equals;
            case 'contains': return contains;
            case 'not_contains': return !contains;
            case 'greater_than': return number !== null && target !== null && number > target;
            case 'less_than': return number !== null && target !== null && number < target;
            default: return equals;
        }
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
        if (question.dataset.type === 'info' || state.hidden || state.disabled) state.required = false;
        return state;
    }

    // Questions the respondent can see and change.
    function editableQuestions() {
        return [...form.querySelectorAll('.fill-question:not([data-type="info"])')]
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
                if (!state.hidden && question.dataset.type !== 'info') known[question.dataset.questionId] = answerOf(question);
            });
        });
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
        const entries = [...new FormData(form).entries()].filter(([name]) => name.startsWith('answers['));
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

    form.addEventListener('input', event => {
        if (event.target.matches('[data-currency]')) formatRupiah(event.target);
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

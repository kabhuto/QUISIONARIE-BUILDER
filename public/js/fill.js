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

    function conditionOf(element) {
        try {
            return JSON.parse(element.dataset.condition || 'null');
        } catch {
            return null;
        }
    }

    function fieldValue(field) {
        return (field?.value ?? '').trim();
    }

    // The answer as the display logic sees it; structured answers only count as "answered or not".
    function answerOf(question) {
        const type = question.dataset.type;
        if (type === 'multiple') return [...question.querySelectorAll('input[type="checkbox"]:checked')].map(input => input.value);
        if (type === 'single') return question.querySelector('input[type="radio"]:checked')?.value ?? '';
        if (type === 'region' || type === 'table') {
            const filled = [...question.querySelectorAll('input:not([type="radio"]):not([type="hidden"]), select, input[type="radio"]:checked')].some(field => fieldValue(field) !== '');
            return filled ? ['filled'] : [];
        }
        return fieldValue(question.querySelector('[name^="answers["]'));
    }

    function isAnswered(answer) {
        return Array.isArray(answer) ? answer.length > 0 : answer !== '';
    }

    // Same rules as App\Support\SurveyLogic: a hidden question counts as unanswered.
    function passes(condition, visibleAnswers) {
        if (!condition) return true;
        const answer = visibleAnswers[condition.question_id] ?? '';
        const value = String(condition.value ?? '');
        const equals = Array.isArray(answer) ? answer.includes(value) : answer === value;
        if (condition.operator === 'answered') return isAnswered(answer);
        if (condition.operator === 'not_equals') return !equals;
        return equals;
    }

    function visibleQuestions() {
        return [...form.querySelectorAll('.fill-question:not([data-type="info"])')].filter(question => !question.hidden && !question.closest('.fill-block').hidden);
    }

    function applyLogic() {
        const visibleAnswers = {};
        blocks.forEach(block => {
            const blockVisible = passes(conditionOf(block), visibleAnswers);
            block.hidden = !blockVisible;
            document.querySelector(`[data-nav-block="${CSS.escape(block.dataset.blockId)}"]`)?.toggleAttribute('hidden', !blockVisible);
            block.querySelectorAll('.fill-question').forEach(question => {
                const visible = blockVisible && passes(conditionOf(question), visibleAnswers);
                question.hidden = !visible;
                question.querySelectorAll('input, select, textarea, button').forEach(field => (field.disabled = !visible));
                if (visible && question.dataset.type !== 'info') visibleAnswers[question.dataset.questionId] = answerOf(question);
            });
        });
        form.querySelectorAll('.cawi-table[data-mode="dynamic"]').forEach(table => {
            if (!table.closest('.fill-question').hidden) updateAddButton(table);
        });
        updateProgress();
    }

    function updateProgress() {
        if (!progressText) return;
        const questions = visibleQuestions();
        const answered = questions.filter(question => isAnswered(answerOf(question))).length;
        const percent = questions.length ? Math.round((answered / questions.length) * 100) : 0;
        progressBar.style.width = `${percent}%`;
        progressText.textContent = `${answered} dari ${questions.length} pertanyaan terisi`;
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

        const answer = answerOf(question);
        if (!isAnswered(answer)) return required ? 'Pertanyaan ini wajib diisi.' : '';
        if (type === 'number' && Number.isNaN(Number(answer))) return 'Isi dengan angka.';
        if (type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(answer)) return 'Isi dengan alamat email yang valid.';
        if (type === 'phone' && !/^[0-9+\-\s().]{6,20}$/.test(answer)) return 'Isi dengan nomor telepon yang valid.';
        const codeDigits = question.querySelectorAll('.code-digit').length;
        if (codeDigits && !new RegExp(`^\\d{${codeDigits}}$`).test(answer)) return `Isi ${codeDigits} digit angka.`;
        return '';
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
        visibleQuestions().forEach(question => {
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

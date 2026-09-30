(() => {
    const config = window.headerConfig;
    const payload = document.querySelector('#header-payload');
    const dialog = document.querySelector('#kop-dialog');
    const slotsEditor = document.querySelector('#kop-slots');
    const paperPreview = document.querySelector('#kop-preview');
    const dialogPreview = document.querySelector('#kop-dialog-preview');
    const introPreview = document.querySelector('#intro-preview');
    const statusLine = document.querySelector('#kop-status');
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

    const items = restoreItems();
    let draggedItemId = null;
    let logoLibrary = null;

    function makeId() {
        return `h-${Math.random().toString(36).slice(2, 10)}`;
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, character => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        })[character]);
    }

    function restoreItems() {
        try {
            const parsed = JSON.parse(payload.value || 'null');
            const list = Array.isArray(parsed?.items) ? parsed.items : config.items;
            return list
                .filter(item => config.itemTypes[item?.type] && config.slots[item?.slot])
                .map(item => ({
                    id: String(item.id || makeId()),
                    type: item.type,
                    slot: item.slot,
                    text: String(item.text ?? ''),
                    style: String(item.style ?? (item.type === 'badge' ? 'dark' : 'bold')),
                    path: item.path || null,
                    height: Number(item.height) || 64,
                }));
        } catch {
            return [...config.items];
        }
    }

    function save() {
        payload.value = JSON.stringify({ items });
        renderPreviews();
    }

    function logoUrl(path) {
        return `${config.storageUrl}/${path}`;
    }

    // Kop rendering shared by the paper and the dialog preview.
    function renderKop(target, { placeholders }) {
        target.innerHTML = `<div class="kop">${Object.keys(config.slots).map(slot => `
            <div class="kop-slot kop-${slot}">
                ${items.filter(item => item.slot === slot).map(item => {
                    if (item.type === 'logo') {
                        if (item.path) return `<img class="kop-logo" src="${escapeHtml(logoUrl(item.path))}" style="height:${item.height}px" alt="">`;
                        return placeholders ? `<span class="kop-logo-placeholder" style="height:${item.height}px;width:${item.height}px">LOGO</span>` : '';
                    }
                    if (!item.text) return '';
                    if (item.type === 'text') return `<div class="kop-text is-${escapeHtml(item.style)}">${escapeHtml(item.text)}</div>`;
                    return `<span class="kop-badge is-${escapeHtml(item.style)}">${escapeHtml(item.text)}</span>`;
                }).join('')}
            </div>`).join('')}</div>`;
    }

    function renderPreviews() {
        renderKop(paperPreview, { placeholders: true });
        renderKop(dialogPreview, { placeholders: true });
    }

    function itemCard(item) {
        const typeLabel = config.itemTypes[item.type];
        let fields = '';

        if (item.type === 'logo') {
            fields = `
                <div class="kop-logo-field">
                    ${item.path ? `<img src="${escapeHtml(logoUrl(item.path))}" alt="">` : '<span class="kop-logo-placeholder is-small">LOGO</span>'}
                    <div class="kop-logo-actions">
                        <label class="mini-button">Unggah<input class="sr-only" type="file" accept="image/png,image/jpeg,image/webp" data-kop-upload></label>
                        <button class="mini-button" type="button" data-kop-action="library">Pilih logo</button>
                        ${item.path ? '<button class="mini-button" type="button" data-kop-action="clear-logo">Kosongkan</button>' : ''}
                    </div>
                </div>
                <label class="kop-range"><span>Tinggi ${item.height}px</span><input type="range" min="24" max="140" step="2" value="${item.height}" data-kop-field="height"></label>
                <div class="kop-library" data-kop-library hidden></div>
                <p class="kop-error" data-kop-error></p>`;
        } else {
            const styles = item.type === 'badge'
                ? Object.entries(config.badgeStyles).map(([style, definition]) => [style, definition.label])
                : [['bold', 'Tebal'], ['normal', 'Biasa']];
            fields = `
                <input class="setting-select" type="text" maxlength="120" value="${escapeHtml(item.text)}" data-kop-field="text" placeholder="${item.type === 'badge' ? 'mis. RAHASIA / CAWI' : 'Teks kop'}">
                <select class="setting-select" data-kop-field="style" aria-label="Gaya">
                    ${styles.map(([style, label]) => `<option value="${style}" ${style === item.style ? 'selected' : ''}>${escapeHtml(label)}</option>`).join('')}
                </select>`;
        }

        return `
            <div class="kop-item" data-item-id="${escapeHtml(item.id)}">
                <div class="kop-item-head">
                    <span class="drag-handle" data-kop-drag title="Seret untuk memindahkan">⠿</span>
                    <strong>${escapeHtml(typeLabel)}</strong>
                    <button class="question-remove" type="button" data-kop-action="remove" aria-label="Hapus ${escapeHtml(typeLabel)}">×</button>
                </div>
                ${fields}
            </div>`;
    }

    function renderSlots() {
        slotsEditor.innerHTML = Object.entries(config.slots).map(([slot, label]) => `
            <div class="kop-slot-editor" data-slot="${slot}">
                <div class="kop-slot-title">${escapeHtml(label)}</div>
                <div class="kop-slot-items" data-slot-items="${slot}">
                    ${items.filter(item => item.slot === slot).map(itemCard).join('') || '<p class="kop-slot-empty">Tarik elemen atau file logo ke sini</p>'}
                </div>
                <div class="kop-slot-add">
                    ${Object.entries(config.itemTypes).map(([type, typeLabel]) => `<button class="mini-button" type="button" data-kop-add="${type}" data-slot="${slot}">+ ${escapeHtml(typeLabel)}</button>`).join('')}
                </div>
            </div>`).join('');
    }

    function render() {
        renderSlots();
        save();
    }

    function findItem(element) {
        const card = element.closest('[data-item-id]');
        return card ? items.find(item => item.id === card.dataset.itemId) : null;
    }

    slotsEditor.addEventListener('click', async event => {
        const add = event.target.closest('[data-kop-add]');
        if (add) {
            const type = add.dataset.kopAdd;
            items.push({ id: makeId(), type, slot: add.dataset.slot, text: '', style: type === 'badge' ? 'dark' : 'bold', path: null, height: 64 });
            render();
            slotsEditor.querySelector(`[data-item-id="${items.at(-1).id}"] input[type="text"]`)?.focus();
            return;
        }

        const action = event.target.closest('[data-kop-action]')?.dataset.kopAction;
        const item = findItem(event.target);
        if (!item || !action) return;

        if (action === 'remove') {
            items.splice(items.indexOf(item), 1);
            render();
        }
        if (action === 'clear-logo') {
            item.path = null;
            render();
        }
        if (action === 'library') {
            const library = event.target.closest('.kop-item').querySelector('[data-kop-library]');
            library.hidden = !library.hidden;
            if (!library.hidden) await showLibrary(library);
        }
        if (action === 'pick-logo') {
            item.path = event.target.closest('[data-path]').dataset.path;
            render();
        }
    });

    slotsEditor.addEventListener('input', event => {
        const field = event.target.dataset.kopField;
        const item = findItem(event.target);
        if (!field || !item) return;
        if (field === 'height') {
            item.height = Number(event.target.value);
            event.target.previousElementSibling.textContent = `Tinggi ${item.height}px`;
        } else {
            item[field] = event.target.value;
        }
        save();
    });

    slotsEditor.addEventListener('change', async event => {
        if (!event.target.matches('[data-kop-upload]')) return;
        const item = findItem(event.target);
        const file = event.target.files[0];
        if (!item || !file) return;

        const error = event.target.closest('.kop-item').querySelector('[data-kop-error]');
        error.textContent = 'Mengunggah…';

        try {
            item.path = await uploadLogo(file);
            render();
        } catch (failure) {
            error.textContent = failure.message;
        }
    });

    async function uploadLogo(file) {
        if (!/^image\/(png|jpeg|webp)$/.test(file.type)) throw new Error(`"${file.name}" bukan gambar PNG, JPG, atau WEBP.`);
        const body = new FormData();
        body.append('logo', file);

        let response;
        try {
            response = await fetch(config.logoStoreUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' },
                body,
            });
        } catch {
            throw new Error('Logo gagal diunggah. Periksa koneksi lalu coba lagi.');
        }
        const result = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(result.errors?.logo?.[0] ?? 'Logo gagal diunggah.');
        logoLibrary = null;
        return result.path;
    }

    function showStatus(message, isError = false) {
        statusLine.textContent = message;
        statusLine.classList.toggle('is-error', isError);
    }

    /**
     * Image files dropped from the computer become logos: onto a logo card they replace it,
     * elsewhere they are inserted where they were dropped (e.g. above the CAWI label).
     */
    async function dropLogoFiles(files, target) {
        const images = [...files].filter(file => file.type.startsWith('image/'));
        if (!images.length) return showStatus('Yang dijatuhkan bukan file gambar.', true);

        const replaced = target.card && items.find(item => item.id === target.card.dataset.itemId && item.type === 'logo');
        let insertAt;
        if (target.card && !replaced) {
            insertAt = items.findIndex(item => item.id === target.card.dataset.itemId) + (target.after ? 1 : 0);
        } else if (target.atStart) {
            const first = items.findIndex(item => item.slot === target.slot);
            insertAt = first < 0 ? items.length : first;
        } else {
            const last = items.map(item => item.slot).lastIndexOf(target.slot);
            insertAt = last < 0 ? items.length : last + 1;
        }

        showStatus(`Mengunggah ${images.length} logo…`);
        for (const [index, file] of images.entries()) {
            try {
                const path = await uploadLogo(file);
                if (replaced && index === 0) {
                    replaced.path = path;
                } else {
                    items.splice(insertAt++, 0, { id: makeId(), type: 'logo', slot: target.slot, text: '', style: 'bold', path, height: 64 });
                }
            } catch (failure) {
                render();
                return showStatus(failure.message, true);
            }
        }
        render();
        showStatus(`${images.length} logo ditambahkan ke kop ${config.slots[target.slot].toLowerCase()}.`);
    }

    function hasFiles(event) {
        return [...(event.dataTransfer?.types ?? [])].includes('Files');
    }

    async function showLibrary(container) {
        container.innerHTML = '<p class="kop-slot-empty">Memuat logo…</p>';
        try {
            logoLibrary ??= (await (await fetch(config.logoIndexUrl, { headers: { Accept: 'application/json' } })).json()).logos;
        } catch {
            container.innerHTML = '<p class="kop-error">Daftar logo gagal dimuat.</p>';
            return;
        }
        container.innerHTML = logoLibrary.length
            ? logoLibrary.map(logo => `<button class="kop-library-item" type="button" data-kop-action="pick-logo" data-path="${escapeHtml(logo.path)}"><img src="${escapeHtml(logo.url)}" alt=""></button>`).join('')
            : '<p class="kop-slot-empty">Belum ada logo. Klik “Unggah”.</p>';
    }

    // Drag items between the left, centre and right slots.
    slotsEditor.addEventListener('pointerdown', event => {
        if (event.target.closest('[data-kop-drag]')) event.target.closest('.kop-item').setAttribute('draggable', 'true');
    });
    document.addEventListener('pointerup', () => {
        if (!draggedItemId) slotsEditor.querySelectorAll('[draggable="true"]').forEach(card => card.removeAttribute('draggable'));
    });

    slotsEditor.addEventListener('dragstart', event => {
        const card = event.target.closest?.('.kop-item');
        if (!card || card.getAttribute('draggable') !== 'true') return;
        draggedItemId = card.dataset.itemId;
        event.dataTransfer.setData('text/plain', draggedItemId);
        event.dataTransfer.effectAllowed = 'move';
        requestAnimationFrame(() => card.classList.add('is-dragging'));
    });

    function clearMarks() {
        slotsEditor.querySelectorAll('.drop-before, .drop-after, .drop-into').forEach(element => element.classList.remove('drop-before', 'drop-after', 'drop-into'));
    }

    function dropTarget(event) {
        const card = event.target.closest('.kop-item');
        if (card && card.dataset.itemId !== draggedItemId) {
            const bounds = card.getBoundingClientRect();
            return { card, after: event.clientY > bounds.top + bounds.height / 2, slot: card.closest('[data-slot]').dataset.slot };
        }
        const slot = event.target.closest('[data-slot]');
        return slot ? { slot: slot.dataset.slot, container: slot } : null;
    }

    function isLogoCard(card) {
        return items.find(item => item.id === card?.dataset.itemId)?.type === 'logo';
    }

    slotsEditor.addEventListener('dragover', event => {
        const withFiles = hasFiles(event);
        if (!draggedItemId && !withFiles) return;
        const target = dropTarget(event);
        if (!target) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = withFiles ? 'copy' : 'move';
        clearMarks();
        if (withFiles && isLogoCard(target.card)) target.card.classList.add('drop-into');
        else if (target.card) target.card.classList.add(target.after ? 'drop-after' : 'drop-before');
        else target.container.classList.add('drop-into');
    });

    slotsEditor.addEventListener('dragleave', event => {
        if (!slotsEditor.contains(event.relatedTarget)) clearMarks();
    });

    slotsEditor.addEventListener('drop', event => {
        if (hasFiles(event) && !draggedItemId) {
            event.preventDefault();
            const target = dropTarget(event);
            clearMarks();
            if (target) dropLogoFiles(event.dataTransfer.files, target);
            return;
        }
        if (!draggedItemId) return;
        event.preventDefault();
        const target = dropTarget(event);
        const moved = items.find(item => item.id === draggedItemId);
        draggedItemId = null;
        clearMarks();
        if (!target || !moved) return;

        items.splice(items.indexOf(moved), 1);
        moved.slot = target.slot;
        if (target.card) {
            const anchor = items.findIndex(item => item.id === target.card.dataset.itemId);
            items.splice(anchor + (target.after ? 1 : 0), 0, moved);
        } else {
            items.push(moved);
        }
        render();
    });

    slotsEditor.addEventListener('dragend', () => {
        draggedItemId = null;
        clearMarks();
        slotsEditor.querySelectorAll('.is-dragging').forEach(card => card.classList.remove('is-dragging'));
    });

    // Logo files can also be dropped straight onto the kop on the paper; the drop position picks the slot.
    function paperSlotAt(event) {
        const bounds = paperPreview.getBoundingClientRect();
        const third = (event.clientX - bounds.left) / bounds.width;
        return third < 1 / 3 ? 'left' : (third < 2 / 3 ? 'center' : 'right');
    }

    paperPreview.addEventListener('dragover', event => {
        if (!hasFiles(event)) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = 'copy';
        paperPreview.dataset.fileOver = paperSlotAt(event);
    });
    paperPreview.addEventListener('dragleave', event => {
        if (!paperPreview.contains(event.relatedTarget)) delete paperPreview.dataset.fileOver;
    });
    paperPreview.addEventListener('drop', async event => {
        if (!hasFiles(event)) return;
        event.preventDefault();
        delete paperPreview.dataset.fileOver;
        const slot = paperSlotAt(event);
        await dropLogoFiles(event.dataTransfer.files, { slot, atStart: true });
        if (statusLine.classList.contains('is-error')) openDialog('kop');
    });

    // A file dropped anywhere else must not make the browser leave the unsaved builder.
    ['dragover', 'drop'].forEach(type => window.addEventListener(type, event => {
        if (hasFiles(event) && !event.defaultPrevented) {
            event.preventDefault();
            if (type === 'dragover') event.dataTransfer.dropEffect = 'none';
        }
    }));

    // Dialog, tabs and the intro summary on the paper.
    function showTab(tab) {
        dialog.querySelectorAll('[data-kop-tab]').forEach(button => button.setAttribute('aria-selected', String(button.dataset.kopTab === tab)));
        dialog.querySelectorAll('[data-kop-panel]').forEach(panel => (panel.hidden = panel.dataset.kopPanel !== tab));
    }

    function openDialog(tab = 'kop') {
        showTab(tab);
        dialog.showModal();
    }

    document.querySelectorAll('[data-open-kop]').forEach(button => button.addEventListener('click', () => openDialog(button.dataset.openKop || 'kop')));
    paperPreview.addEventListener('click', () => openDialog('kop'));
    paperPreview.addEventListener('keydown', event => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            openDialog('kop');
        }
    });
    dialog.querySelectorAll('[data-kop-tab]').forEach(button => button.addEventListener('click', () => showTab(button.dataset.kopTab)));
    dialog.querySelector('[data-close-kop]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('keydown', event => {
        // Enter in a single-line field would otherwise submit the whole builder form.
        if (event.key === 'Enter' && event.target.matches('input')) event.preventDefault();
    });

    function renderIntroPreview() {
        const value = name => dialog.querySelector(`[name="intro[${name}]"]`).value.trim();
        const firstLine = value('text').split('\n')[0];
        const chips = [
            value('help_title') || value('help_phone') || value('help_email') ? 'Kontak bantuan' : null,
            value('notice') ? 'Pemberitahuan' : null,
            value('guide') ? 'Petunjuk pengisian' : null,
        ].filter(Boolean);
        introPreview.innerHTML = `
            <span class="intro-preview-label">PEMBUKA</span>
            <span class="intro-preview-text">${escapeHtml(firstLine || 'Tambahkan teks sambutan untuk responden')}</span>
            ${chips.map(chip => `<span class="intro-chip">${escapeHtml(chip)}</span>`).join('')}`;
    }

    dialog.querySelector('[data-kop-panel="intro"]').addEventListener('input', renderIntroPreview);

    render();
    renderIntroPreview();
})();

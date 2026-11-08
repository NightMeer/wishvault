(() => {
    // Bestätigungsdialoge
    document.querySelectorAll('form[data-confirm]').forEach((f) =>
        f.addEventListener('submit', (e) => { if (!confirm(f.dataset.confirm)) e.preventDefault(); }));

    // Status-Auswahl: Speichern-Button + Mail-Option einblenden
    document.querySelectorAll('.status-form').forEach((f) => {
        const sel = f.querySelector('select');
        const initial = sel.value;
        const save = f.querySelector('.save-btn');
        const notify = f.querySelector('.notify-check');
        const reason = f.querySelector('.reason-input');
        const initialReason = reason ? reason.value : '';
        const update = () => {
            const changed = sel.value !== initial || (sel.value === 'rejected' && reason && reason.value !== initialReason);
            save.hidden = !changed;
            if (notify) notify.hidden = !(sel.value !== initial && sel.value === 'available');
            if (reason) reason.hidden = sel.value !== 'rejected';
            sel.className = 'status-select status-' + sel.value;
        };
        sel.addEventListener('change', () => { update(); if (reason && !reason.hidden) reason.focus(); });
        if (reason) reason.addEventListener('input', update);
    });

    // Notizen bei Verlassen des Feldes speichern
    document.querySelectorAll('.note-form input[name="note"]').forEach((i) => {
        const initial = i.value;
        i.addEventListener('change', () => { if (i.value !== initial) i.form.submit(); });
    });

    // AppID-Zuordnung per Enter/Verlassen speichern
    document.querySelectorAll('.input-appid').forEach((i) => {
        const initial = i.value;
        i.addEventListener('change', () => { if (i.value !== initial) i.form.submit(); });
    });

    // GSE: Live-Filter
    const filter = document.getElementById('gseFilter');
    if (filter) {
        const rows = document.querySelectorAll('#gseTable tbody tr[data-name]');
        const none = document.getElementById('gseNoMatch');
        filter.addEventListener('input', () => {
            const q = filter.value.trim().toLowerCase();
            let n = 0;
            rows.forEach((r) => { const hit = r.dataset.name.includes(q); r.classList.toggle('filtered-out', !hit); if (hit) n++; });
            none.hidden = !(q && n === 0);
        });
    }

    // GSE: Umbenennen-Dialog
    const dlg = document.getElementById('renameDialog');
    if (dlg) {
        document.querySelectorAll('[data-rename]').forEach((b) => b.addEventListener('click', () => {
            document.getElementById('renameId').value = b.dataset.rename;
            document.getElementById('renameInput').value = b.dataset.name;
            dlg.showModal();
        }));
        dlg.querySelector('[data-close]').addEventListener('click', () => dlg.close());
        dlg.addEventListener('click', (e) => { if (e.target === dlg) dlg.close(); });
    }

    // ---------- Sortierbare Tabellen ----------
    const cellValue = (row, idx) => {
        const c = row.children[idx];
        if (!c) return '';
        return c.dataset.value !== undefined ? c.dataset.value : c.textContent.trim().toLowerCase();
    };
    document.querySelectorAll('table[data-sortable]').forEach((table) => {
        const tbody = table.tBodies[0];
        table.querySelectorAll('thead th').forEach((th, idx) => {
            if (th.hasAttribute('data-nosort')) return;
            const type = th.dataset.sort || 'text';
            th.classList.add('sortable');
            th.addEventListener('click', () => {
                const dir = th.classList.contains('sort-asc') ? -1 : 1;
                table.querySelectorAll('thead th').forEach((x) => x.classList.remove('sort-asc', 'sort-desc'));
                th.classList.add(dir === 1 ? 'sort-asc' : 'sort-desc');
                const rows = [...tbody.rows].filter((r) => !r.hasAttribute('data-static'));
                rows.sort((a, b) => {
                    const va = cellValue(a, idx), vb = cellValue(b, idx);
                    const cmp = type === 'num'
                        ? (parseFloat(va) || 0) - (parseFloat(vb) || 0)
                        : String(va).localeCompare(String(vb), 'de', { numeric: true, sensitivity: 'base' });
                    return cmp * dir;
                });
                const statics = [...tbody.rows].filter((r) => r.hasAttribute('data-static'));
                rows.concat(statics).forEach((r) => tbody.appendChild(r));
            });
        });
    });

    // ---------- Tabellen-Filter (Volltext) ----------
    document.querySelectorAll('.table-filter').forEach((input) => {
        const table = document.querySelector(input.dataset.table);
        if (!table) return;
        input.addEventListener('input', () => {
            const q = input.value.trim().toLowerCase();
            [...table.tBodies[0].rows].forEach((r) => {
                if (r.hasAttribute('data-static')) return;
                const text = r.textContent.toLowerCase() + ' ' + [...r.querySelectorAll('input:not([type=hidden]), select')].map((i) => (i.value || '').toLowerCase()).join(' ');
                r.classList.toggle('filtered-out', q !== '' && !text.includes(q));
            });
        });
    });

    // ---------- Steam-Tags anzeigen & kopieren ----------
    const tagDlg = document.createElement('dialog');
    tagDlg.className = 'modal';
    tagDlg.innerHTML = `
        <h3>Steam-Tags</h3>
        <p class="muted small" data-title></p>
        <div class="tag-list" data-list></div>
        <textarea class="tag-text" rows="3" readonly data-text></textarea>
        <div class="btn-row end">
            <button type="button" class="btn btn-ghost" data-close>Schließen</button>
            <button type="button" class="btn btn-primary" data-copy>Alle kopieren</button>
        </div>`;
    document.body.appendChild(tagDlg);
    const tagText = tagDlg.querySelector('[data-text]');
    const tagList = tagDlg.querySelector('[data-list]');
    tagDlg.querySelector('[data-close]').addEventListener('click', () => tagDlg.close());
    tagDlg.addEventListener('click', (e) => { if (e.target === tagDlg) tagDlg.close(); });

    async function copyText(text, btn) {
        let ok = false;
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(text);
                ok = true;
            }
        } catch (e) { /* Fallback unten */ }
        if (!ok) {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            tagDlg.appendChild(ta);
            ta.select();
            ok = document.execCommand('copy');
            ta.remove();
        }
        if (btn) {
            const old = btn.textContent;
            btn.textContent = ok ? '✔ Kopiert' : 'Kopieren fehlgeschlagen';
            setTimeout(() => (btn.textContent = old), 1500);
        }
    }
    tagDlg.querySelector('[data-copy]').addEventListener('click', (e) => copyText(tagText.value, e.currentTarget));
    tagList.addEventListener('click', (e) => {
        const chip = e.target.closest('.tag-chip');
        if (chip) copyText(chip.dataset.tag, chip);
    });

    document.querySelectorAll('[data-tags]').forEach((btn) => btn.addEventListener('click', async () => {
        tagDlg.querySelector('[data-title]').textContent = btn.dataset.name + ' – Klick auf einen Tag kopiert ihn einzeln.';
        tagList.innerHTML = '<span class="muted">Lade Tags …</span>';
        tagText.value = '';
        tagDlg.showModal();
        try {
            const res = await fetch('/admin/api.php?action=tags&appid=' + encodeURIComponent(btn.dataset.tags));
            const d = await res.json();
            if (!res.ok) throw new Error(d.error || 'Fehler');
            const tags = d.tags || [];
            if (!tags.length) {
                tagList.innerHTML = '<span class="muted">Keine Tags gefunden.</span>';
                return;
            }
            tagList.innerHTML = '';
            tags.forEach((t) => {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'tag-chip';
                b.dataset.tag = t;
                b.textContent = t;
                tagList.appendChild(b);
            });
            tagText.value = tags.join(', ');
        } catch (e) {
            tagList.innerHTML = '<span class="text-danger">' + e.message.replace(/[<>&]/g, '') + '</span>';
        }
    }));
})();

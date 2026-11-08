(() => {
    const $ = (s) => document.querySelector(s);
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const EMAIL_KEY = 'wl_email';

    const searchInput = $('#searchInput');
    const results = $('#searchResults');
    const spinner = $('#searchSpinner');
    const emailInput = $('#emailInput');
    const emailClear = $('#emailClear');
    const grid = $('#gameGrid');
    const empty = $('#emptyState');
    const toast = $('#toast');

    let filter = 'open';
    let sort = 'votes';

    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    const steamIcon = document.getElementById('steamIconTpl').innerHTML;
    const listFilter = $('#listFilter');
    const statusFilter = $('#statusFilter');
    const listCount = $('#listCount');
    let allGames = [];

    // ---------- E-Mail im Browser merken (nur wenn Mailversand aktiv) ----------
    function loadEmail() {
        if (!emailInput) return;
        const saved = localStorage.getItem(EMAIL_KEY) || '';
        emailInput.value = saved;
        emailClear.hidden = !saved;
    }
    function currentEmail() {
        if (!emailInput) return '';
        const v = emailInput.value.trim();
        if (v && emailInput.checkValidity()) {
            localStorage.setItem(EMAIL_KEY, v);
            emailClear.hidden = false;
        }
        return v;
    }
    if (emailInput) emailClear.addEventListener('click', () => {
        localStorage.removeItem(EMAIL_KEY);
        emailInput.value = '';
        emailClear.hidden = true;
        showToast('Gespeicherte E-Mail-Adresse entfernt.');
    });
    if (emailInput) emailInput.addEventListener('change', currentEmail);
    loadEmail();

    function showToast(msg, type = 'info') {
        toast.textContent = msg;
        toast.className = 'toast toast-' + type;
        toast.hidden = false;
        clearTimeout(showToast.t);
        showToast.t = setTimeout(() => (toast.hidden = true), 4000);
    }

    async function post(action, data) {
        const email = currentEmail();
        if (emailInput && emailInput.value.trim() && !emailInput.checkValidity()) {
            showToast('Die E-Mail-Adresse ist ungültig.', 'error');
            return null;
        }
        const body = new URLSearchParams({ ...data, email, csrf });
        const res = await fetch('/api.php?action=' + action, { method: 'POST', body });
        return res.json();
    }

    function handleResult(r) {
        if (!r) return;
        const type = { created: 'success', upvoted: 'success', unvoted: 'info', removed: 'info', in_gamevault: 'info', already_voted: 'info' }[r.result] || 'error';
        showToast(r.message || 'Fertig.', type);
        loadList();
    }

    // ---------- Live-Suche ----------
    let timer, seq = 0;
    searchInput.addEventListener('input', () => {
        clearTimeout(timer);
        const q = searchInput.value.trim();
        if (q.length < 2) {
            results.hidden = true;
            return;
        }
        timer = setTimeout(() => search(q), 350);
    });

    async function search(q) {
        const my = ++seq;
        spinner.hidden = false;
        try {
            const res = await fetch('/api.php?action=search&q=' + encodeURIComponent(q));
            const data = await res.json();
            if (my !== seq) return;
            renderResults(data.results || []);
        } catch (e) {
            results.innerHTML = '<div class="result-empty">Suche fehlgeschlagen.</div>';
            results.hidden = false;
        } finally {
            if (my === seq) spinner.hidden = true;
        }
    }

    function resultAction(r) {
        if (r.in_gamevault) {
            return r.gv_url
                ? `<a class="btn btn-success btn-sm" href="${esc(r.gv_url)}" target="_blank" rel="noopener">✔ Bereits auf GameVault – öffnen</a>`
                : '<span class="badge badge-available">✔ Bereits auf GameVault</span>';
        }
        if (r.wish) {
            const w = r.wish;
            if (w.status === 'rejected') {
                return `<span class="badge badge-rejected">Abgelehnt</span>${w.reject_reason ? `<span class="muted small">${esc(w.reject_reason)}</span>` : ''}`;
            }
            const info = `<span class="badge badge-${w.status}">Schon gewünscht · ${w.votes} 👍</span>`;
            return w.voted
                ? info + `<button class="btn btn-ghost btn-sm" data-unvote="${w.id}" title="Deine Stimme zurückziehen">✕ Stimme zurückziehen</button>`
                : info + `<button class="btn btn-primary btn-sm" data-upvote="${w.id}">+1 Upvote</button>`;
        }
        return `<button class="btn btn-primary btn-sm" data-wish="${r.appid}">Wünschen</button>`;
    }

    function renderResults(list) {
        if (!list.length) {
            results.innerHTML = '<div class="result-empty">Keine Treffer auf Steam.</div>';
        } else {
            results.innerHTML = list.map((r) => `
                <div class="result">
                    <img src="${esc(r.image)}" alt="" loading="lazy" onerror="this.style.visibility='hidden'">
                    <div class="result-name">${esc(r.name)}</div>
                    <div class="result-action">${resultAction(r)}</div>
                </div>`).join('');
        }
        results.hidden = false;
    }

    results.addEventListener('click', async (e) => {
        const wish = e.target.closest('[data-wish]');
        const up = e.target.closest('[data-upvote]');
        const un = e.target.closest('[data-unvote]');
        if (!wish && !up && !un) return;
        const btn = wish || up || un;
        btn.disabled = true;
        const r = wish ? await post('wish', { appid: wish.dataset.wish })
            : up ? await post('upvote', { id: up.dataset.upvote })
            : await post('unvote', { id: un.dataset.unvote });
        handleResult(r);
        if (searchInput.value.trim().length >= 2) search(searchInput.value.trim());
    });

    document.addEventListener('click', (e) => {
        if (!e.target.closest('.wish-box')) results.hidden = true;
    });
    searchInput.addEventListener('focus', () => {
        if (results.innerHTML && searchInput.value.trim().length >= 2) results.hidden = false;
    });

    // ---------- Wunschliste ----------
    async function loadList() {
        const res = await fetch(`/api.php?action=list&sort=${sort}&filter=${filter}`);
        const data = await res.json();
        allGames = data.games || [];
        renderList();
    }

    function renderList() {
        const q = listFilter.value.trim().toLowerCase();
        const st = statusFilter.value;
        const games = allGames.filter((g) =>
            (!q || g.name.toLowerCase().includes(q)) &&
            (!st || (st === 'coming_soon' ? g.coming_soon : g.status === st)));
        empty.hidden = games.length > 0;
        empty.textContent = allGames.length ? 'Keine Treffer für diesen Filter.' : 'Noch keine Wünsche in dieser Ansicht.';
        listCount.textContent = games.length !== allGames.length ? `${games.length} von ${allGames.length}` : `${allGames.length} ${allGames.length === 1 ? 'Spiel' : 'Spiele'}`;
        grid.innerHTML = games.map(card).join('');
    }
    listFilter.addEventListener('input', renderList);
    statusFilter.addEventListener('change', renderList);

    function card(g) {
        const canVote = ['new', 'queued', 'paused'].includes(g.status);
        const voteBtn = canVote
            ? `<button class="vote-btn ${g.voted ? 'voted' : ''}" data-vote="${g.id}" data-voted="${g.voted ? 1 : 0}"
                   title="${g.voted ? 'Du hast abgestimmt – klicken, um die Stimme zurückzuziehen' : 'Upvote'}">
                   <span class="vote-arrow">${g.voted ? '✔' : '▲'}</span><span class="vote-count">${g.votes}</span></button>`
            : `<div class="vote-btn static"><span class="vote-arrow">▲</span><span class="vote-count">${g.votes}</span></div>`;
        return `
        <article class="game-card status-${esc(g.status)}">
            <a class="cover" href="${esc(g.gv_url || g.steam_url)}" target="_blank" rel="noopener" title="${g.gv_url ? 'Auf GameVault öffnen' : 'Auf Steam ansehen'}">
                <img src="${esc(g.cover)}" alt="${esc(g.name)}" loading="lazy" data-fallback="${esc(g.header)}"
                     onerror="if(this.dataset.fallback){this.src=this.dataset.fallback;this.dataset.fallback='';this.classList.add('wide')}">
                ${g.coming_soon ? '<span class="ribbon">Coming Soon</span>' : ''}
            </a>
            <div class="card-body">
                <h3 title="${esc(g.name)}">${esc(g.name)}</h3>
                <div class="card-meta">
                    <span class="badge badge-${esc(g.status)}">${esc(g.status_label)}</span>
                    ${g.coming_soon && g.release_date ? `<span class="muted small">${esc(g.release_date)}</span>` : ''}
                    ${g.reject_reason ? `<div class="reject-reason" title="${esc(g.reject_reason)}">Grund: ${esc(g.reject_reason)}</div>` : ''}
                    <a class="steam-link" href="${esc(g.steam_url)}" target="_blank" rel="noopener" title="Auf Steam ansehen">${steamIcon}</a>
                </div>
            </div>
            ${voteBtn}
        </article>`;
    }

    grid.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-vote]');
        if (!btn) return;
        btn.disabled = true;
        handleResult(await post(btn.dataset.voted === '1' ? 'unvote' : 'upvote', { id: btn.dataset.vote }));
    });

    function bindTabs(container, attr, setter) {
        container.addEventListener('click', (e) => {
            const t = e.target.closest('.tab');
            if (!t) return;
            container.querySelectorAll('.tab').forEach((x) => x.classList.toggle('active', x === t));
            setter(t.dataset[attr]);
            loadList();
        });
    }
    bindTabs($('#filterTabs'), 'filter', (v) => (filter = v));
    bindTabs($('#sortTabs'), 'sort', (v) => (sort = v));

    loadList();
})();

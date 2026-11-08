(() => {
    const input = document.getElementById('gseName');
    const dot = document.getElementById('gseDot');
    const hint = document.getElementById('gseHint');
    const form = document.getElementById('gseForm');
    const hidden = document.getElementById('gseUser');
    const sid = document.getElementById('gseSteamId');
    const LANG_KEY = 'wl_gse_lang', NAME_KEY = 'wl_gse_name';
    const lang = document.getElementById('gseLang');

    if (localStorage.getItem(LANG_KEY)) lang.value = localStorage.getItem(LANG_KEY);
    lang.addEventListener('change', () => localStorage.setItem(LANG_KEY, lang.value));

    let timer;
    function check() {
        clearTimeout(timer);
        const name = input.value.trim();
        if (!name) {
            dot.className = 'status-dot';
            hint.textContent = 'Name eingeben und kurz warten …';
            form.hidden = true;
            return;
        }
        timer = setTimeout(async () => {
            const res = await fetch('/gse.php?action=check&name=' + encodeURIComponent(name));
            const d = await res.json();
            if (d.found) {
                dot.className = 'status-dot ok';
                hint.textContent = 'Gefunden – SteamID ist verknüpft.';
                hidden.value = d.username;
                sid.textContent = d.steam_id;
                form.hidden = false;
                localStorage.setItem(NAME_KEY, d.username);
            } else {
                dot.className = 'status-dot err';
                hint.textContent = 'Spieler nicht gefunden. Bist du in GameVault aktiviert bzw. vom Admin eingetragen?';
                form.hidden = true;
            }
        }, 300);
    }
    input.addEventListener('input', check);
    if (localStorage.getItem(NAME_KEY)) {
        input.value = localStorage.getItem(NAME_KEY);
        check();
    }
})();

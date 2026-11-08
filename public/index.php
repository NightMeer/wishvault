<?php
declare(strict_types=1);
require '/var/www/app/bootstrap.php';

$mailEnabled = Mailer::isConfigured();
layout_header('Wunschliste', 'wishlist');
?>
<section class="hero">
    <h1>Spiel wünschen</h1>
    <p class="muted">Such ein Steam-Spiel oder füge einen Steam-Link ein. Ist es schon gewünscht, zählt deine Stimme als Upvote.</p>

    <div class="wish-box">
        <div class="search-field">
            <input type="search" id="searchInput" placeholder="Spielname oder https://store.steampowered.com/app/…" autocomplete="off">
            <span class="spinner" id="searchSpinner" hidden></span>
        </div>
        <div id="searchResults" class="search-results" hidden></div>

        <?php if ($mailEnabled): ?>
        <div class="email-row">
            <label for="emailInput">Benachrichtigung per E-Mail <span class="muted">(optional)</span></label>
            <div class="email-field">
                <input type="email" id="emailInput" placeholder="du@example.com" autocomplete="email">
                <button type="button" class="btn btn-ghost btn-sm" id="emailClear" hidden title="Gespeicherte Adresse aus diesem Browser entfernen">✕ Entfernen</button>
            </div>
            <small class="muted" id="emailHint">Wird in diesem Browser gespeichert und bei weiteren Wünschen automatisch verwendet.</small>
        </div>
        <?php endif; ?>
    </div>
</section>

<section>
    <div class="list-toolbar">
        <div class="tabs" id="filterTabs">
            <button class="tab active" data-filter="open">Offen</button>
            <button class="tab" data-filter="available">Verfügbar</button>
            <button class="tab" data-filter="rejected">Abgelehnt</button>
            <button class="tab" data-filter="all">Alle</button>
        </div>
        <div class="tabs" id="sortTabs">
            <button class="tab active" data-sort="votes">🔥 Meistgewünscht</button>
            <button class="tab" data-sort="new">🕒 Neueste</button>
        </div>
    </div>
    <div class="list-filter">
        <input type="search" id="listFilter" placeholder="Liste durchsuchen …" autocomplete="off">
        <select id="statusFilter">
            <option value="">Alle Status</option>
            <option value="new">Neu</option>
            <option value="queued">Warteschlange</option>
            <option value="paused">Pausiert / Delay</option>
            <option value="coming_soon">Coming Soon</option>
            <option value="available">Verfügbar</option>
            <option value="rejected">Abgelehnt</option>
        </select>
        <span class="muted small" id="listCount"></span>
    </div>
    <div id="gameGrid" class="game-grid"></div>
    <template id="steamIconTpl"><?= steam_icon() ?></template>
    <p id="emptyState" class="empty" hidden>Noch keine Wünsche in dieser Ansicht.</p>
</section>

<div id="toast" class="toast" hidden></div>
<?php
layout_footer(['/assets/wishlist.js']);

<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
check_auth();

global $PDOK_PDK;

$q  = req('q', '');
$qt = req('qt', ''); // Titel
$qa = req('qa', ''); // Abstract / Zusammenfassung
$qj = req('qj', ''); // Zeitschrift / Quelle
$qp = req('qp', ''); // Personen / Verfasser
$qy = req('qy', ''); // Erscheinungsjahr

// Bestandsinformationen ermitteln (je nach Datenquelle)
$lib = get_opac_library();
$totalEntries = $lib->countRecords();
$lastUpdate = null;
$isBibSource = $lib instanceof BibLibrary;
$stockInfo = $isBibSource ? 'BibTeX-Bestand (data/bib)' : 'MIDOS-Bestand (data/midos)';

render_header($HTML_TITLE);
render_app_header(ueb('Suchmaske'));
?>

<?php if ($totalEntries > 0): ?>
<div class="status-info" style="margin-bottom: 16px;">
    <p style="margin: 0;">
        <span class="muted"><?= htmlspecialchars(ueb('Bestandsinformationen:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        <?= htmlspecialchars(ueb('Einträge im Bestand:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        <?= number_format($totalEntries, 0, ',', '.') ?>
        (<?= htmlspecialchars($stockInfo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>)<?php if ($lastUpdate): ?>,
        <?= htmlspecialchars(ueb('Letzte Aktualisierung:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        <?= date('d.m.Y H:i', $lastUpdate) ?></span>
        <?php else: ?></span><?php endif; ?>
        <?php if ($isBibSource): // Import-Aktion selbst ist per Admin-Secret geschützt (mimport.php) ?>
        &nbsp;<a href="mimport.php" class="btn btn-sm">BibTeX-Import</a>
        <?php endif; ?>
    </p>
</div>
<?php endif; ?>

<p class="status-muted" style="margin-bottom: 16px;">
    Erweiterte Suchmaske mit Mehrfeld-Suche und einfacher Bool-Logik
    (AND / OR / NOT innerhalb der Felder).
</p>

<div class="search-box">
<form method="get" action="msuche.php">
    <div class="form-group">
        <label class="form-label" for="q"><?= htmlspecialchars(ueb('Freitext (alle Felder):'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
        <input class="form-input" type="text" name="q" id="q"
               placeholder="<?= htmlspecialchars(ueb('Begriff(e), werden auf den gesamten Datensatz angewendet'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
               value="<?= htmlspecialchars($q, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    </div>

    <div class="form-group">
        <label class="form-label" for="qt"><?= htmlspecialchars(ueb('Titel (Feld T/TI):'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
        <input class="form-input" type="text" name="qt" id="qt"
               placeholder="<?= htmlspecialchars(ueb('Beispiele: KI AND Forschung, NOT Rezension'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
               value="<?= htmlspecialchars($qt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    </div>

    <div class="form-group">
        <label class="form-label" for="qa"><?= htmlspecialchars(ueb('Zusammenfassung (Feld ABS/ZUS):'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
        <input class="form-input" type="text" name="qa" id="qa"
               value="<?= htmlspecialchars($qa, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    </div>

    <div class="form-group">
        <label class="form-label" for="qj"><?= htmlspecialchars(ueb('Zeitschrift / Quelle (Feld ZNA):'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
        <input class="form-input" type="text" name="qj" id="qj"
               value="<?= htmlspecialchars($qj, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    </div>

    <div class="form-group">
        <label class="form-label" for="qp"><?= htmlspecialchars(ueb('Person(en) / Verfasser (Feld VER/@VER):'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
        <input class="form-input" type="text" name="qp" id="qp"
               value="<?= htmlspecialchars($qp, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    </div>

    <div class="form-group">
        <label class="form-label" for="qy"><?= htmlspecialchars(ueb('Erscheinungsjahr:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
        <input class="form-input" type="text" name="qy" id="qy"
               placeholder="<?= htmlspecialchars(ueb('z. B. 2024'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
               value="<?= htmlspecialchars($qy, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    </div>

    <p class="status-muted" style="margin-top: 8px;">
        Bool-Syntax pro Feld: Begriffe können mit <code>AND</code>, <code>OR</code>, <code>NOT</code> kombiniert werden
        (keine Klammern, Auswertung von links nach rechts).
    </p>

    <div style="margin-top: 24px; display: flex; gap: 12px; flex-wrap: wrap;">
        <button type="submit" class="btn btn-primary">
            <?= htmlspecialchars(ueb('Suchen'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </button>
        <a class="btn" href="mlogin.php?action=logout"><?= htmlspecialchars(ueb('Logout'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
    </div>
</form>
</div>

<?php
render_footer();

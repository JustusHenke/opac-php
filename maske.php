<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
check_auth();

$q  = req('q', '');
$qt = req('qt', ''); // Titel
$qa = req('qa', ''); // Abstract / Zusammenfassung
$qj = req('qj', ''); // Zeitschrift / Quelle
$qp = req('qp', ''); // Personen / Verfasser

render_header($HTML_TITLE);
render_app_header(ueb('Suchmaske'));
?>

<p class="muted">
    Erweiterte PHP-Suchmaske mit Mehrfeld-Suche und einfacher Bool-Logik
    (AND / OR / NOT innerhalb der Felder, sequentielle Suche über <code>pdok.pdk</code>).
</p>

<form method="get" action="msuche.php">
    <div>
        <label class="field-label" for="q"><?= htmlspecialchars(ueb('Freitext (alle Felder):'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
        <input class="input-text" type="text" name="q" id="q"
               placeholder="<?= htmlspecialchars(ueb('Begriff(e), werden auf den gesamten Datensatz angewendet'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
               value="<?= htmlspecialchars($q, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    </div>

    <div style="margin-top: 12px;">
        <label class="field-label" for="qt"><?= htmlspecialchars(ueb('Titel (Feld T/TI):'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
        <input class="input-text" type="text" name="qt" id="qt"
               placeholder="<?= htmlspecialchars(ueb('Beispiele: KI AND Forschung, NOT Rezension'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
               value="<?= htmlspecialchars($qt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    </div>

    <div style="margin-top: 8px;">
        <label class="field-label" for="qa"><?= htmlspecialchars(ueb('Zusammenfassung (Feld ABS/ZUS):'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
        <input class="input-text" type="text" name="qa" id="qa"
               value="<?= htmlspecialchars($qa, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    </div>

    <div style="margin-top: 8px;">
        <label class="field-label" for="qj"><?= htmlspecialchars(ueb('Zeitschrift / Quelle (Feld ZNA):'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
        <input class="input-text" type="text" name="qj" id="qj"
               value="<?= htmlspecialchars($qj, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    </div>

    <div style="margin-top: 8px;">
        <label class="field-label" for="qp"><?= htmlspecialchars(ueb('Person(en) / Verfasser (Feld VER/@VER):'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
        <input class="input-text" type="text" name="qp" id="qp"
               value="<?= htmlspecialchars($qp, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    </div>

    <p class="muted" style="margin-top: 6px;">
        Bool-Syntax pro Feld: Begriffe können mit <code>AND</code>, <code>OR</code>, <code>NOT</code> kombiniert werden
        (keine Klammern, Auswertung von links nach rechts).
    </p>

    <div style="margin-top: 16px;">
        <button type="submit" class="btn btn-primary">
            <?= htmlspecialchars(ueb('Suchen'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </button>
        <a class="btn" href="mlogin.php?action=logout"><?= htmlspecialchars(ueb('Logout'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
    </div>
</form>

<?php
render_footer();


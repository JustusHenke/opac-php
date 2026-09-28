<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
check_auth();

global $PDOK_PDK, $DATA_DIR;

// Datenquelle (BibTeX-Bestand oder MIDOS-Rückfallposition)
require_once __DIR__ . '/MidosIndex.php';
$lib  = get_opac_library();
$isBib = $lib instanceof BibLibrary;

// Suchparameter
$q  = req('q', '');
$qt = req('qt', '');
$qa = req('qa', '');
$qj = req('qj', '');
$qp = req('qp', '');
$qs = req('qs', '');
$qy = req('qy', '');

$hasAny = ($q !== '' || $qt !== '' || $qa !== '' || $qj !== '' || $qp !== '' || $qs !== '' || $qy !== '');

render_header($HTML_TITLE);
render_app_header(ueb('Trefferliste'));

if (!$hasAny): ?>
    <p class="status-error">
        <?= htmlspecialchars(ueb('Es wurde kein Suchkriterium eingegeben.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
    </p>
<?php
    render_footer();
    exit;
endif;

if (!$isBib && !is_readable($PDOK_PDK)): ?>
    <p class="status-error">
        <?= htmlspecialchars(ueb('Die Datei mit den Dokumentdaten (pdok.pdk) konnte nicht gefunden oder gelesen werden.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
    </p>
<?php
    render_footer();
    exit;
endif;

// Feld-basierte Index-Suche
// BibLibrary: Feldnamen; MidosIndex: Legacy-Indexnummern (1=Person, 2=Titel, 4=Schlagwort, 6=Zeitschrift, 7=Jahr)
$fieldMap = $isBib
    ? ['qt' => $qt, 'qp' => $qp, 'qj' => $qj, 'qs' => $qs, 'qa' => $qa, 'qy' => $qy, 'q' => $q]
    : ['qt' => 2, 'qp' => 1, 'qj' => 6, 'qs' => 4, 'qy' => 7];

$candidateIds = null;
foreach ($fieldMap as $key => $val) {
    if ($val === '') {
        continue;
    }
    $ids = $lib->searchBoolean((string) $val, $key);

    // Feld gesetzt, aber keine Treffer -> Gesamtergebnis leer
    if (empty($ids)) {
        $candidateIds = [];
        break;
    }
    if ($candidateIds === null) {
        $candidateIds = $ids;
    } else {
        $candidateIds = array_intersect($candidateIds, $ids);
        if ($candidateIds === []) {
            break;
        }
    }
}

// MIDOS-Fallback: Wenn keine Felder gesetzt sind, aber Freitext 'q' -> sequenzieller Scan
$useSequential = (!$isBib && $candidateIds === null && $q !== '');

$results = [];
$maxResults = 1000;

if ($useSequential) {
    // Sequenzielle Suche (Legacy): Freitext + Feldprüfung über die Rohtexte
    $fp = fopen($PDOK_PDK, 'r');
    if ($fp) {
        $lineNumber = 0;
        while (($raw = fgets($fp)) !== false) {
            $lineNumber++;
            if (trim($raw) === '') continue;
            $utf8 = mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
            if (!matches_bool($utf8, $q)) {
                continue;
            }
            $fields = ($qt !== '' || $qa !== '' || $qj !== '' || $qp !== '')
                ? parse_pdok_fields($utf8)
                : [];
            $match = true;
            if ($match && $qt !== '') {
                $match = matches_bool(($fields['T'] ?? '') . ' ' . ($fields['TI'] ?? ''), $qt);
            }
            if ($match && $qa !== '') {
                $match = matches_bool(($fields['ABS'] ?? '') . ' ' . ($fields['ZUS'] ?? ''), $qa);
            }
            if ($match && $qj !== '') {
                $match = matches_bool($fields['ZNA'] ?? '', $qj);
            }
            if ($match && $qp !== '') {
                $match = matches_bool(($fields['VER'] ?? '') . ' ' . $utf8, $qp);
            }
            if ($match) {
                $rec = $lib->getRecord($lineNumber);
                if ($rec !== null) {
                    $results[] = ['line' => $lineNumber, 'title' => $rec['title'], 'html' => $rec['html']];
                }
                if (count($results) >= $maxResults) break;
            }
        }
        fclose($fp);
    }
} else {
    // Index-basierter Zugriff
    $candidateIds = $candidateIds ?? [];
    sort($candidateIds, SORT_NUMERIC);
    foreach ($candidateIds as $docId) {
        if (count($results) >= $maxResults) break;
        $rec = $lib->getRecord((int) $docId);
        if ($rec === null) continue;
        $results[] = ['line' => (int) $docId, 'title' => $rec['title'], 'html' => $rec['html']];
    }
}

$count = count($results);

// Statistik
log_search(trim($q . ' ' . $qt . ' ' . $qa . ' ' . $qj . ' ' . $qp . ' ' . $qy), $count);
?>

<div class="search-box" style="margin-bottom: 20px;">
    <p style="margin-bottom: 8px;">
        <?= htmlspecialchars(ueb('Suchkriterien:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><br>
        <?php if ($q !== ''): ?>
            <span class="muted"><?= htmlspecialchars(ueb('Freitext:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            <strong><?= htmlspecialchars($q, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong><br>
        <?php endif; ?>
        <?php if ($qt !== ''): ?>
            <span class="muted"><?= htmlspecialchars(ueb('Titel:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            <strong><?= htmlspecialchars($qt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong><br>
        <?php endif; ?>
        <?php if ($qa !== ''): ?>
            <span class="muted"><?= htmlspecialchars(ueb('Zusammenfassung:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            <strong><?= htmlspecialchars($qa, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong><br>
        <?php endif; ?>
        <?php if ($qj !== ''): ?>
            <span class="muted"><?= htmlspecialchars(ueb('Zeitschrift:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            <strong><?= htmlspecialchars($qj, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong><br>
        <?php endif; ?>
        <?php if ($qp !== ''): ?>
            <span class="muted"><?= htmlspecialchars(ueb('Person(en):'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            <strong><?= htmlspecialchars($qp, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong><br>
        <?php endif; ?>
        <?php if ($qs !== ''): ?>
            <span class="muted"><?= htmlspecialchars(ueb('Schlagwort:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            <strong><?= htmlspecialchars($qs, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong><br>
        <?php endif; ?>
        <?php if ($qy !== ''): ?>
            <span class="muted"><?= htmlspecialchars(ueb('Jahr:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            <strong><?= htmlspecialchars($qy, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong><br>
        <?php endif; ?>

        <?= htmlspecialchars(ueb('Trefferanzahl:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        <strong><?= (int)$count ?></strong>
    </p>
</div>

<?php if ($count === 0): ?>
    <p class="status-muted">
        <?= htmlspecialchars(ueb('Es wurden keine Dokumente zu dieser Anfrage gefunden.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
    </p>
<?php else: ?>
    <?php
    $cart = $_SESSION['cart'] ?? [];
    require_once __DIR__ . '/UserData.php';
    $userData = new UserData($DATA_DIR);
    $username = $_SESSION['username'] ?? 'guest';
    $isGuest = ($_SESSION['userid'] ?? '') === 'guest';
    ?>
    <div class="result-list">
    <?php foreach ($results as $idx => $res):
        $line = (int)$res['line'];
        $inCart = in_array($line, $cart, true);
        $hasNote = !$isGuest && $userData->getNote($username, $line) !== null;
        ?>
        <div class="result-card">
            <div class="result-title">
                <span class="result-number"><?= (int)($idx + 1) ?>.</span>
                <?= htmlspecialchars($res['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </div>
            <div class="result-meta">
                <span><?= htmlspecialchars(ueb('Datensatz-ID:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= $line ?></span>
                <?php if ($isBib): ?>
                    <span class="badge">BibTeX</span>
                <?php endif; ?>
            </div>
            <div class="result-actions">
                <?php if ($inCart): ?>
                    <a href="mtools.php?action=cart_remove&amp;line=<?= $line ?>"
                       onclick="event.preventDefault(); toggleCart(<?= $line ?>, 'cart_remove', this)"
                       class="btn btn-sm" style="color:var(--accent-red);">
                        <?= htmlspecialchars(ueb('aus Warenkorb entfernen'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </a>
                <?php else: ?>
                    <a href="mtools.php?action=cart_add&amp;line=<?= $line ?>"
                       onclick="event.preventDefault(); toggleCart(<?= $line ?>, 'cart_add', this)"
                       class="btn btn-sm">
                        <?= htmlspecialchars(ueb('in Warenkorb'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </a>
                <?php endif; ?>
                <?php if (!$isGuest): ?>
                    <a href="mnote.php?line=<?= $line ?>" class="btn btn-sm">
                        <?= htmlspecialchars(ueb('Notiz'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </a>
                    <span id="note-star-<?= $line ?>" style="color:var(--primary); <?= $hasNote ? '' : 'display:none;' ?>">★</span>
                <?php endif; ?>
            </div>
            <div>
                <?= $res['html'] ?>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
<?php endif; ?>

<p style="margin-top:24px; display: flex; gap: 12px; flex-wrap: wrap;">
    <a class="btn" href="maske.php"><?= htmlspecialchars(ueb('Zurück zur Suchmaske'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
    <a class="btn" href="mtools.php?action=cart_view"><?= htmlspecialchars(ueb('Warenkorb anzeigen'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
    <a class="btn" href="mlogin.php?action=logout"><?= htmlspecialchars(ueb('Logout'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
</p>

<?php
render_footer();
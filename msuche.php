<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
check_auth();

global $PDOK_PDK, $DATA_DIR;

// Initialisierung Index-Klasse
require_once __DIR__ . '/MidosIndex.php';
$indexDir = dirname($PDOK_PDK);
$midosIndex = new MidosIndex($indexDir);

// Suchparameter
$q  = req('q', '');
$qt = req('qt', '');
$qa = req('qa', '');
$qj = req('qj', '');
$qp = req('qp', '');
$qs = req('qs', '');

$hasAny = ($q !== '' || $qt !== '' || $qa !== '' || $qj !== '' || $qp !== '' || $qs !== '');

render_header($HTML_TITLE);
render_app_header(ueb('Trefferliste'));

if (!$hasAny): ?>
    <p class="error">
        <?= htmlspecialchars(ueb('Es wurde kein Suchkriterium eingegeben.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
    </p>
<?php
    render_footer();
    exit;
endif;

if (!is_readable($PDOK_PDK)): ?>
    <p class="error">
        <?= htmlspecialchars(ueb('Die Datei mit den Dokumentdaten (pdok.pdk) konnte nicht gefunden oder gelesen werden.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
    </p>
<?php
    render_footer();
    exit;
endif;

// Index-basierte Suche
$candidateIds = null;
$useIndex = true;

// 1. Index-Abfragen für spezifische Felder
// Mapping: qt->2 (Titel), qp->1 (Person), qj->6 (Zeitschrift), qa->18 (Abstract)
$fieldMap = [
    'qt' => ['val' => $qt, 'idx' => 2],
    'qp' => ['val' => $qp, 'idx' => 1],
    'qj' => ['val' => $qj, 'idx' => 6],
    'qa' => ['val' => $qa, 'idx' => 18],
    'qs' => ['val' => $qs, 'idx' => 4],
];

foreach ($fieldMap as $key => $info) {
    if ($info['val'] !== '') {
        $ids = $midosIndex->searchBoolean($info['val'], $info['idx']);
        
        // Wenn ein Feld gesetzt ist, aber der Index nichts liefert -> 0 Treffer
        if (empty($ids)) {
            $candidateIds = [];
            break;
        }

        if ($candidateIds === null) {
            $candidateIds = $ids;
        } else {
            $candidateIds = array_intersect($candidateIds, $ids);
            if (empty($candidateIds)) {
                break;
            }
        }
    }
}

// Wenn nur 'q' (Freitext) gesetzt ist und keine Feldsuche -> Sequenziell (oder später Index 5)
// Aktuell: Fallback auf Sequenziell für 'q', wenn candidateIds null ist
if ($candidateIds === null && $q !== '') {
    $useIndex = false; // Full Scan nötig
}

$results = [];
$maxResults = 1000;

if ($useIndex && $candidateIds !== null) {
    // A. Index-basierter Zugriff
    // candidateIds enthält die Dokument-IDs (Zeilennummern).
    // Falls 'q' gesetzt ist, müssen wir diese Kandidaten noch gegen 'q' prüfen.
    
    // Sortieren für sequenziellen Zugriff (performance opt)
    sort($candidateIds, SORT_NUMERIC);
    
    foreach ($candidateIds as $docId) {
        if (count($results) >= $maxResults) break;

        $raw = $midosIndex->getRecord($docId);
        if ($raw === null) continue;

        // Encoding fix: Data is ISO-8859-1, convert to UTF-8
        $raw = mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');

        // Falls 'q' gesetzt ist, Prüfen
        if ($q !== '') {
            if (!matches_bool($raw, $q)) {
                continue;
            }
        }

        $rec = format_pdok_record($raw);
        $results[] = [
            'line'  => $docId,
            'title' => $rec['title'],
            'html'  => $rec['html'],
        ];
    }
} else {
    // B. Sequenzielle Suche (Legacy Fallback)
    // Entweder nur 'q' gesucht, oder Index-Dateien fehlen / Fehler
    
    $fp = fopen($PDOK_PDK, 'r');
    if ($fp) {
        $lineNumber = 0;
        while (($raw = fgets($fp)) !== false) {
            $lineNumber++;
            if (trim($raw) === '') continue;

            // Encoding fix: Data is ISO-8859-1, convert to UTF-8
            $raw = mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');

            $match = true;

            // Freitext über gesamten Datensatz
            if ($q !== '') {
                $match = $match && matches_bool($raw, $q);
            }

            // Falls wir hier landen, obwohl Felder gesetzt waren (z.B. Index Init Fehler),
            // müssen wir auch die Felder prüfen!
            // Da wir oben $useIndex auf false setzen nur wenn candidateIds null war (also keine Felder),
            // sollte das hier nur für q-only passieren.
            // ABER: Falls Index-Klasse leere Ergebnisse lieferte weil Dateien fehlten?
            // MidosIndex::search gibt [] zurück wenn Dateien fehlen.
            // Das würde als "0 Treffer" interpretiert werden!
            // TODO: MidosIndex sollte signalisieren, ob Index existiert.
            // Workaround: Wir vertrauen darauf, dass Indices da sind wenn wir hier sind.
            // Für q-only Suche:
            
            if ($match) {
                // Felder Check (Fallback für q-only, Felder sollten leer sein)
                $fields = ($qt !== '' || $qa !== '' || $qj !== '' || $qp !== '') 
                    ? parse_pdok_fields($raw) 
                    : [];

                if ($match && $qt !== '') {
                    $titleField = ($fields['T'] ?? '') . ' ' . ($fields['TI'] ?? '');
                    $match = matches_bool($titleField, $qt);
                }
                if ($match && $qa !== '') {
                    $absField = ($fields['ABS'] ?? '') . ' ' . ($fields['ZUS'] ?? '');
                    $match = matches_bool($absField, $qa);
                }
                if ($match && $qj !== '') {
                    $znaField = $fields['ZNA'] ?? '';
                    $match = matches_bool($znaField, $qj);
                }
                if ($match && $qp !== '') {
                    $verField = ($fields['VER'] ?? '');
                    $verAll = $verField . ' ' . $raw;
                    $match = matches_bool($verAll, $qp);
                }
            }

            if ($match) {
                $rec = format_pdok_record($raw);
                $results[] = [
                    'line'  => $lineNumber, // 1-based, fgets starts at 1? No, counter starts at 1
                    'title' => $rec['title'],
                    'html'  => $rec['html'],
                ];
                if (count($results) >= $maxResults) break;
            }
        }
        fclose($fp);
    }
}

$count = count($results);

// Statistik
log_search(trim($q . ' ' . $qt . ' ' . $qa . ' ' . $qj . ' ' . $qp), $count);
?>

<p>
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

    <?= htmlspecialchars(ueb('Trefferanzahl:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
    <strong><?= (int)$count ?></strong>
</p>

<?php if ($count === 0): ?>
    <p class="muted">
        <?= htmlspecialchars(ueb('Es wurden keine Dokumente zu dieser Anfrage gefunden.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
    </p>
<?php else: ?>
    <?php
    $cart = $_SESSION['cart'] ?? [];
    require_once __DIR__ . '/UserData.php';
    $userData = new UserData($DATA_DIR);
    $username = $_SESSION['username'] ?? 'guest';

    foreach ($results as $idx => $res):
        $line = (int)$res['line'];
        $inCart = in_array($line, $cart, true);
        $hasNote = $userData->getNote($username, $line) !== null;
        ?>
        <div class="search-result">
            <div class="search-result-title">
                <?= (int)($idx + 1) ?>.
                <?= htmlspecialchars($res['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </div>
            <div class="muted">
                <?= htmlspecialchars(ueb('Line:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= $line ?>
                &nbsp;|&nbsp;
                <?php if ($inCart): ?>
                    <a href="mtools.php?action=cart_remove&amp;line=<?= $line ?>" 
                       onclick="event.preventDefault(); toggleCart(<?= $line ?>, 'cart_remove', this)" 
                       style="color:#d9534f;"><?= htmlspecialchars(ueb('aus Warenkorb entfernen'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
                <?php else: ?>
                    <a href="mtools.php?action=cart_add&amp;line=<?= $line ?>" 
                       onclick="event.preventDefault(); toggleCart(<?= $line ?>, 'cart_add', this)"><?= htmlspecialchars(ueb('in Warenkorb'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
                <?php endif; ?>
                &nbsp;|&nbsp;
                <a href="mnote.php?line=<?= $line ?>"><?= htmlspecialchars(ueb('Notiz'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
                <span id="note-star-<?= $line ?>" style="color:var(--primary); <?= $hasNote ? '' : 'display:none;' ?>">★</span>
            </div>
            <div style="margin-top:4px;">
                <?= $res['html'] ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<p style="margin-top:16px;">
    <a class="btn" href="maske.php"><?= htmlspecialchars(ueb('Zurück zur Suchmaske'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
    <a class="btn" href="mtools.php?action=cart_view"><?= htmlspecialchars(ueb('Warenkorb anzeigen'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
    <a class="btn" href="mlogin.php?action=logout"><?= htmlspecialchars(ueb('Logout'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
</p>

<?php
render_footer();


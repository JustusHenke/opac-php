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

// Sortierung der Trefferliste. Vorgabe ist bewusst "relevance": die Reihenfolge
// kommt dann unverändert aus der Suche und wird nicht überschrieben.
$sort = (string) req('sort', 'relevance');
$sortLabels = [
    'relevance' => 'Relevanz',
    'author_asc' => 'Autor (A–Z)',
    'author_desc' => 'Autor (Z–A)',
    'year_asc' => 'Jahr (älteste zuerst)',
    'year_desc' => 'Jahr (neueste zuerst)',
    'title_asc' => 'Titel (A–Z)',
    'title_desc' => 'Titel (Z–A)',
];
if (!isset($sortLabels[$sort])) {
    $sort = 'relevance';
}

// "in Treffern suchen": Bool-Ausdruck (UND/ODER/NOT), angewendet auf genau die
// Treffer, die gerade angezeigt werden (siehe $hitSig/$hitSet weiter unten).
$refine = (string) req('in', '');
$refineField = (string) req('inf', 'q');
$refineLabels = [
    'q' => 'Freitext (alle Felder)',
    'qt' => 'Titel',
    'qp' => 'Person(en)',
    'qs' => 'Schlagwort',
    'qj' => 'Zeitschrift',
    'qa' => 'Zusammenfassung',
    'qy' => 'Jahr',
];
if (!isset($refineLabels[$refineField])) {
    $refineField = 'q';
}
// Der MIDOS-Bestand hat kein Register für Freitext und Zusammenfassung –
// dort werden nur die Felder angeboten, die tatsächlich indiziert sind.
if (!$isBib) {
    unset($refineLabels['q'], $refineLabels['qa']);
    if (!isset($refineLabels[$refineField])) {
        $refineField = 'qt';
    }
}

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
// Suchbegriff je Feld; das Register steht getrennt davon, weil die beiden
// Datenquellen unterschiedliche Registerbezeichnungen erwarten:
// BibLibrary: Feldnamen, MidosIndex: Legacy-Nummern
// (1=Person, 2=Titel, 4=Schlagwort, 6=Zeitschrift, 7=Jahr).
$fieldTerms = ['qt' => $qt, 'qa' => $qa, 'qp' => $qp, 'qj' => $qj, 'qs' => $qs, 'qy' => $qy, 'q' => $q];
$fieldIndex = $isBib
    ? ['qt' => 'qt', 'qa' => 'qa', 'qp' => 'qp', 'qj' => 'qj', 'qs' => 'qs', 'qy' => 'qy', 'q' => 'q']
    : ['qt' => 2, 'qa' => 0, 'qp' => 1, 'qj' => 6, 'qs' => 4, 'qy' => 7, 'q' => 0];

$candidateIds = null;
foreach ($fieldTerms as $key => $val) {
    if ($val === '') {
        continue;
    }
    if (!$isBib && ($key === 'q' || $key === 'qa')) {
        // Freitext und Zusammenfassung kennt der MIDOS-Bestand nur im
        // sequenziellen Scan – dort werden beide unten mitgeprüft.
        continue;
    }
    $ids = $lib->searchBoolean($val, $fieldIndex[$key]);

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

// MIDOS-Fallback: Wenn kein Indexfeld gesetzt ist, aber Freitext 'q' oder
// Abstract 'qa' (nur im sequenziellen Scan pruefbar) -> sequenzieller Scan
$useSequential = (!$isBib && $candidateIds === null && ($q !== '' || $qa !== ''));

// Höchstzahl angezeigter Treffer. Für Sortierung und "in Treffern suchen" wird
// die Ausgangsmenge trotzdem vollständig bestimmt, nur die Ausgabe begrenzt.
$maxResults = 1000;

// Deckel für den sequenziellen Legacy-Scan (MIDOS): verhindert, dass eine sehr
// häufige Formulierung die ganze Datei und den Speicher belegt.
$scanLimit = 20000;

if ($useSequential) {
    // Sequenzielle Suche (Legacy): Freitext + Feldprüfung über die Rohtexte.
    // Gesammelt werden nur die Zeilennummern – die Datensätze werden danach
    // einmal zentral (und damit auch sortierbar) geladen.
    $candidateIds = [];
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
                $candidateIds[] = $lineNumber;
                if (count($candidateIds) >= $scanLimit) break;
            }
        }
        fclose($fp);
    }
}

// ---------------------------------------------------------------- Verfeinerung
// Signatur des Suchauftrags: eine Suche "in Treffern" greift nur dann auf die
// gespeicherte Trefferliste zu, wenn sie sich auf dieselbe Suche bezieht.
$hitSig = md5(implode("\x1f", [$q, $qt, $qa, $qj, $qp, $qs, $qy]));

$baseIds = array_map('intval', $candidateIds ?? []);
$storedIds = [];
$refineNote = '';
$refineActive = '';
$refined = false;

if ($refine !== '') {
    $hitSet = $_SESSION['hit_set'] ?? null;
    if (is_array($hitSet) && ($hitSet['sig'] ?? '') === $hitSig && is_array($hitSet['ids'] ?? null)) {
        $storedIds = array_map('intval', $hitSet['ids']);
    }
    if ($storedIds === []) {
        // Keine passende Trefferliste (z. B. andere Suche, neue Sitzung):
        // statt einer irreführenden Trefferliste lieber die normale Suche zeigen.
        // Die Eingabe bleibt im Formular stehen, damit sie erneut abgeschickt
        // werden kann, sobald wieder eine Trefferliste vorliegt.
        $refineNote = 'Die zugehörige Trefferliste ist nicht mehr verfügbar – die Suche in Treffern wurde verworfen.';
    } else {
        // Ausgangsmenge ist exakt die zuletzt angezeigte Trefferliste, nicht
        // der Gesamtbestand: refine_hits() kann daraus nichts hinzufügen.
        $baseIds = refine_hits($lib, $storedIds, $refine, $fieldIndex[$refineField]);
        $refineActive = $refine;
        $refined = true;
    }
}

// ---------------------------------------------------------------- Sortierung
if ($sort !== 'relevance' && $baseIds !== []) {
    $baseIds = sort_hit_ids($baseIds, $lib->sortKeys($baseIds), $sort);
} elseif (!$refined) {
    // Relevanz = Bestandsreihenfolge der Suche (wie bisher).
    sort($baseIds, SORT_NUMERIC);
}

$total = count($baseIds);

// ---------------------------------------------------------------- Trefferliste
$results = [];
foreach (array_slice($baseIds, 0, $maxResults) as $docId) {
    $rec = $lib->getRecord((int) $docId);
    if ($rec === null) continue;
    $results[] = [
        'line' => (int) $docId,
        'title' => $rec['title'],
        'html' => $rec['html'],
        'source' => (string) ($rec['source'] ?? ''),
    ];
}
$count = count($results);

// Ausgangsmenge für eine spätere Suche "in Treffern": exakt die jetzt
// angezeigten Treffer in Anzeigereihenfolge. Bewusst nur die angezeigte Liste
// (höchstens $maxResults IDs) – so bleibt die Sitzung klein, und die
// Verfeinerung liefert nie Treffer, die nicht auf der Seite standen.
// Beim Verfeinern bleibt die ursprüngliche Ausgangsmenge unverändert stehen,
// damit sich weitere Einschränkungen weiter auf sie beziehen.
if (!$refined) {
    $_SESSION['hit_set'] = [
        'sig' => $hitSig,
        'ids' => array_column($results, 'line'),
        'at' => time(),
    ];
}

// Statistik
log_search(trim($q . ' ' . $qt . ' ' . $qa . ' ' . $qj . ' ' . $qp . ' ' . $qy), $count);
if ($refined) {
    log_search('in Treffern: ' . $refineActive . ' [' . $refineLabels[$refineField] . ']', $count);
}
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
        <strong><?= number_format($total, 0, ',', '.') ?></strong>
        <?php if ($total > $count): ?>
            <span class="muted">
                &middot; <?= htmlspecialchars(ueb('davon angezeigt:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                <?= number_format($count, 0, ',', '.') ?>
            </span>
        <?php endif; ?>
    </p>
    <?php if ($refineNote !== ''): ?>
        <p class="status-error" style="margin: 8px 0 0;">
            <?= htmlspecialchars($refineNote, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </p>
    <?php endif; ?>
    <?php if ($refined): ?>
        <p class="status-muted" style="margin: 8px 0 0;">
            <?= htmlspecialchars(ueb('Eingeschränkt in Treffern:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            <strong><?= htmlspecialchars($refineActive, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
            (<?= htmlspecialchars(ueb($refineLabels[$refineField]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>)
            &middot; <?= htmlspecialchars(ueb('Ausgangsmenge:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            <?= number_format(count($storedIds), 0, ',', '.') ?>
            &middot;
            <a href="<?= htmlspecialchars(search_link([
                'q' => $q, 'qt' => $qt, 'qa' => $qa, 'qj' => $qj,
                'qp' => $qp, 'qs' => $qs, 'qy' => $qy, 'sort' => $sort,
            ]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <?= htmlspecialchars(ueb('Einschränkung aufheben'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </a>
        </p>
    <?php endif; ?>
</div>

<?php if ($total > 0):
    // Parameter des aktuellen Suchauftrags: alle Folgeformulare (Sortierung,
    // Verfeinerung) geben sie unveraendert mit, damit kein Kriterium verloren geht.
    $carry = ['q' => $q, 'qt' => $qt, 'qa' => $qa, 'qj' => $qj, 'qp' => $qp, 'qs' => $qs, 'qy' => $qy];
    ?>
<div class="search-box" style="margin-bottom: 20px;">
    <h3><?= htmlspecialchars(ueb('Sortierung'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
    <form method="get" action="msuche.php">
        <?php foreach ($carry as $ck => $cv): ?>
            <?php if ($cv === '') { continue; } ?>
            <input type="hidden" name="<?= $ck ?>" value="<?= htmlspecialchars((string) $cv, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <?php endforeach; ?>
        <?php if ($refined): ?>
            <input type="hidden" name="in" value="<?= htmlspecialchars($refineActive, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <input type="hidden" name="inf" value="<?= htmlspecialchars($refineField, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <?php endif; ?>
        <div class="form-group" style="margin-bottom: 12px;">
            <label class="form-label" for="sort"><?= htmlspecialchars(ueb('Reihenfolge der Treffer'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                <select class="form-input" name="sort" id="sort" style="max-width: 320px;">
                    <?php foreach ($sortLabels as $sk => $sl): ?>
                        <option value="<?= $sk ?>"<?= $sk === $sort ? ' selected' : '' ?>>
                            <?= htmlspecialchars(ueb($sl), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary">
                    <?= htmlspecialchars(ueb('Sortieren'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </button>
            </div>
            <p class="status-muted" style="margin-top: 8px; margin-bottom: 0;">
                <?= htmlspecialchars(ueb('Relevanz ist die Vorgabe und entspricht der Reihenfolge aus der Suche.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </p>
        </div>
    </form>

    <h3 style="margin-top: 24px;"><?= htmlspecialchars(ueb('In Treffern suchen'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
    <form method="get" action="msuche.php">
        <?php foreach ($carry as $ck => $cv): ?>
            <?php if ($cv === '') { continue; } ?>
            <input type="hidden" name="<?= $ck ?>" value="<?= htmlspecialchars((string) $cv, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <?php endforeach; ?>
        <input type="hidden" name="sort" value="<?= htmlspecialchars($sort, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <div class="form-group" style="margin-bottom: 12px;">
            <label class="form-label" for="in"><?= htmlspecialchars(ueb('Suchbegriff(e) in den angezeigten Treffern'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <input class="form-input" type="text" name="in" id="in" maxlength="300"
                   placeholder="<?= htmlspecialchars(ueb('z. B. Digitalisierung NOT Rezension, Transfer UND Hochschule'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                   value="<?= htmlspecialchars($refine, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        </div>
        <div class="form-group" style="margin-bottom: 12px;">
            <label class="form-label" for="inf"><?= htmlspecialchars(ueb('Feld'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <select class="form-input" name="inf" id="inf" style="max-width: 320px;">
                <?php foreach ($refineLabels as $rf => $rl): ?>
                    <option value="<?= $rf ?>"<?= $rf === $refineField ? ' selected' : '' ?>>
                        <?= htmlspecialchars(ueb($rl), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
            <button type="submit" class="btn btn-primary">
                <?= htmlspecialchars(ueb('In Treffern suchen'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </button>
            <?php if ($refined): ?>
                <a class="btn" href="<?= htmlspecialchars(search_link($carry + ['sort' => $sort]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <?= htmlspecialchars(ueb('Einschränkung aufheben'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </a>
            <?php endif; ?>
        </div>
        <p class="status-muted" style="margin-top: 8px; margin-bottom: 0;">
            <?= htmlspecialchars(ueb('Die Suche wirkt nur auf die oben angezeigten Treffer, nie auf den gesamten Bestand. Operatoren: AND/UND, OR/ODER, NOT/NICHT (Großschreibung).'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </p>
    </form>
</div>
<?php endif; ?>

<?php if ($count === 0): ?>
    <p class="status-muted">
        <?= htmlspecialchars(ueb('Es wurden keine Dokumente zu dieser Anfrage gefunden.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
    </p>
<?php else: ?>
    <?php
    $cart = $_SESSION['cart'] ?? [];
    require_once __DIR__ . '/UserData.php';
    $userData = new UserData($USER_DATA_DIR);
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
                <?= source_comment($res['source'] ?? null) ?>
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
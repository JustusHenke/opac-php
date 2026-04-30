<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
check_auth();
require_once __DIR__ . '/MidosIndex.php';

// Parameter
$idx   = (int)req('idx', '1');
$start = req('start', '');
$page  = (int)req('page', '1');
$limit = 50;

$indexes = [
    1 => 'Personen',
    2 => 'Titel (Wörter)',
    4 => 'Schlagwörter',
    6 => 'Zeitschriften',
    7 => 'Jahre'
];

if (!array_key_exists($idx, $indexes)) $idx = 1;

$midosIndex = new MidosIndex($DATA_DIR);

// Bei gesetztem Start-Parameter: Nur Terme mit diesem Präfix
if ($start !== '') {
    // Alle Terme mit diesem Präfix holen (für Paginierung)
    $allTermsWithPrefix = $midosIndex->getTerms($idx, $start, 999999);
    $totalTerms = count($allTermsWithPrefix);
    $totalPages = (int)ceil($totalTerms / $limit);
    
    // Nur die aktuelle Seite anzeigen
    $offset = ($page - 1) * $limit;
    $terms = array_slice($allTermsWithPrefix, $offset, $limit);
} else {
    // Ohne Start-Parameter: Alle Terme
    $totalTerms = $midosIndex->getTermCount($idx);
    $totalPages = (int)ceil($totalTerms / $limit);
    
    $offset = ($page - 1) * $limit;
    if ($offset < 0) $offset = 0;
    $terms = $midosIndex->getTermsByOffset($idx, $offset, $limit);
}

render_header($HTML_TITLE);
render_app_header(ueb('Index-Liste: ') . ueb($indexes[$idx]));
?>

<div class="index-bar">
    <?php foreach (range('A', 'Z') as $char): ?>
        <a href="mindex.php?idx=<?= $idx ?>&amp;start=<?= urlencode($char) ?>"
           class="index-char <?= ($start === $char) ? 'active' : '' ?>">
            <?= $char ?>
        </a>
    <?php endforeach; ?>
</div>

<div class="index-search">
    <form method="get" action="mindex.php" style="display:flex; align-items:center; gap: 10px; flex-wrap: wrap; width: 100%;">
        <input type="hidden" name="idx" value="<?= $idx ?>">
        
        <label for="start" style="font-weight:600; font-size: var(--size-sm);"><?= htmlspecialchars(ueb('Springe zu:')) ?></label>
        <input type="text" name="start" id="start" value="<?= htmlspecialchars($start) ?>" class="form-input" style="width:150px;">
        <button type="submit" class="btn btn-primary"><?= htmlspecialchars(ueb('Anzeigen')) ?></button>
        
        <div style="margin-left: auto; display: flex; gap: 8px; flex-wrap: wrap;">
            <?php foreach ($indexes as $i => $label): ?>
                <a href="mindex.php?idx=<?= $i ?>" 
                   class="nav-link <?= $i === $idx ? 'active' : '' ?>" 
                   style="margin-left: 0;"><?= htmlspecialchars($label) ?></a>
            <?php endforeach; ?>
        </div>
    </form>
</div>

<?php if (empty($terms)): ?>
    <p class="status-muted"><?= htmlspecialchars(ueb('Keine Einträge gefunden.')) ?></p>
<?php else: ?>
    <ul class="index-list" style="margin-top: 20px;">
        <?php
        $qParam = match($idx) {
            1 => 'qp',
            2 => 'qt',
            6 => 'qj',
            7 => 'qy',
            default => 'qs'
        };
        
        foreach ($terms as $t):
            $term = $t['term'];
            $searchVal = (strpos($term, ' ') !== false) ? '"' . $term . '"' : $term;
            $link = 'msuche.php?' . $qParam . '=' . urlencode($searchVal);
            ?>
            <li class="index-item">
                <a href="<?= $link ?>" style="text-decoration:none;">
                    <?= htmlspecialchars($term) ?>
                    <span class="index-item-count">(<?= $t['count'] ?>)</span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <!-- Pagination -->
    <div class="pagination">
        <?php 
        $startParam = $start !== '' ? '&amp;start=' . urlencode($start) : '';
        ?>
        <?php if ($page > 1): ?>
            <a href="mindex.php?idx=<?= $idx ?><?= $startParam ?>&amp;page=1" class="btn btn-sm">« Erste</a>
            <a href="mindex.php?idx=<?= $idx ?><?= $startParam ?>&amp;page=<?= $page - 1 ?>" class="btn btn-sm">‹ Vorherige</a>
        <?php endif; ?>

        <span class="page-info"><?= ueb('Seite') ?> <strong><?= $page ?></strong> <?= ueb('von') ?> <?= $totalPages ?></span>

        <?php if ($page < $totalPages): ?>
            <a href="mindex.php?idx=<?= $idx ?><?= $startParam ?>&amp;page=<?= $page + 1 ?>" class="btn btn-sm">N&auml;chste ›</a>
            <a href="mindex.php?idx=<?= $idx ?><?= $startParam ?>&amp;page=<?= $totalPages ?>" class="btn btn-sm">Letzte »</a>
        <?php endif; ?>
    </div>
<?php endif;

render_footer();

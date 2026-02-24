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

// Logic for A-Z Jumping
if ($start !== '') {
    // If start is set, we use the start term to find the offset? 
    // Actually, it's easier to just use the start term logic as before, 
    // but if we want numeric pagination, we stick to it.
    // If user typed something in "Start ab", we jump to that page or just show from there.
}

$totalTerms = $midosIndex->getTermCount($idx);
$totalPages = (int)ceil($totalTerms / $limit);

if ($start !== '') {
    $terms = $midosIndex->getTerms($idx, $start, $limit);
    // Find approximate offset for start term? (Optional, might be complex)
    $page = 1; // Reset to 1 for term-based browsing
} else {
    $offset = ($page - 1) * $limit;
    if ($offset < 0) $offset = 0;
    $terms = $midosIndex->getTermsByOffset($idx, $offset, $limit);
}

render_header($HTML_TITLE);
render_app_header(ueb('Index-Liste: ') . ueb($indexes[$idx]));

?>

<div class="search-box">
    <div style="margin-bottom: 15px; text-align: center; border-bottom: 1px solid var(--tertiary); padding-bottom: 10px;">
        <?php foreach (range('A', 'Z') as $char): ?>
            <a href="mindex.php?idx=<?= $idx ?>&amp;start=<?= urlencode($char) ?>" style="margin: 0 4px; font-weight: 600; font-size: 1.1em;"><?= $char ?></a>
        <?php endforeach; ?>
    </div>

    <form method="get" action="mindex.php" style="display:flex; align-items:center; justify-content: space-between;">
        <input type="hidden" name="idx" value="<?= $idx ?>">
        
        <div>
            <label for="start" style="margin-right:10px;"><?= htmlspecialchars(ueb('Springe zu:')) ?></label>
            <input type="text" name="start" id="start" value="<?= htmlspecialchars($start) ?>" class="input-text" style="width:150px;">
            <button type="submit" class="btn btn-primary" style="margin-left:5px;"><?= htmlspecialchars(ueb('Anzeigen')) ?></button>
        </div>

        <div style="font-size: var(--small);">
            <?= htmlspecialchars(ueb('Index:')) ?>
            <?php foreach ($indexes as $i => $label): ?>
                <a href="mindex.php?idx=<?= $i ?>" style="<?= $i === $idx ? 'font-weight:bold; color:var(--secondary);' : '' ?> margin-left: 5px;"><?= htmlspecialchars($label) ?></a>
            <?php endforeach; ?>
        </div>
    </form>
</div>

<?php if (empty($terms)): ?>
    <p class="muted"><?= htmlspecialchars(ueb('Keine Einträge gefunden.')) ?></p>
<?php else: ?>
    <ul style="list-style:none; padding:0; margin-top:20px; column-count: 2; column-gap: 40px;">
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
            <li style="margin-bottom:6px; break-inside: avoid;">
                <a href="<?= $link ?>" style="text-decoration:none;">
                    <?= htmlspecialchars($term) ?>
                    <span style="font-size: 0.8em; color: #888;">(<?= $t['count'] ?>)</span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <!-- Pagination UI -->
    <div style="margin-top: 30px; display: flex; justify-content: center; align-items: center; gap: 10px;">
        <?php if ($page > 1): ?>
            <a href="mindex.php?idx=<?= $idx ?>&amp;page=1" class="btn btn-small">« First</a>
            <a href="mindex.php?idx=<?= $idx ?>&amp;page=<?= $page - 1 ?>" class="btn btn-small">‹ Prev</a>
        <?php endif; ?>

        <span class="muted"><?= ueb('Seite') ?> <strong><?= $page ?></strong> <?= ueb('von') ?> <?= $totalPages ?></span>

        <?php if ($page < $totalPages): ?>
            <?php 
                $nextStart = ($terms && count($terms) >= $limit) ? $terms[count($terms)-1]['term'] : '';
            ?>
            <a href="mindex.php?idx=<?= $idx ?>&amp;page=<?= $page + 1 ?>" class="btn btn-small">Next ›</a>
            <a href="mindex.php?idx=<?= $idx ?>&amp;page=<?= $totalPages ?>" class="btn btn-small">Last »</a>
        <?php endif; ?>
    </div>
<?php endif;

render_footer();

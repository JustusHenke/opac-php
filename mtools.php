<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
check_auth();

global $PDOK_PDK;

$action = req('action', 'cart_view');
$line   = req('line');

// Einfache Warenkorb-Implementierung auf Basis der Zeilennummer in pdok.pdk
if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

if ($action === 'cart_add' && $line !== null) {
    $ln = (int)$line;
    if ($ln > 0 && !in_array($ln, $_SESSION['cart'], true)) {
        $_SESSION['cart'][] = $ln;
    }
    if (req('ajax')) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'in_cart' => true]);
        exit;
    }
    $referer = $_SERVER['HTTP_REFERER'] ?? 'msuche.php';
    redirect($referer);
}

if ($action === 'cart_remove' && $line !== null) {
    $ln = (int)$line;
    $_SESSION['cart'] = array_values(array_filter(
        $_SESSION['cart'],
        static fn (int $v): bool => $v !== $ln
    ));
    if (req('ajax')) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'in_cart' => false]);
        exit;
    }
    redirect('mtools.php');
}

if ($action === 'clear') {
    $_SESSION['cart'] = [];
    if (req('ajax')) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }
    redirect('mtools.php');
}

if ($action === 'export_bibtex') {
    require_once __DIR__ . '/MidosIndex.php';
    $indexDir = dirname($PDOK_PDK);
    $midosIndex = new MidosIndex($indexDir);
    
    $cart = $_SESSION['cart'] ?? [];
    if (empty($cart)) {
        redirect('mtools.php');
    }

    header('Content-Type: application/x-bibtex; charset=utf-8');
    header('Content-Disposition: attachment; filename="export.bib"');

    foreach ($cart as $id) {
        $raw = $midosIndex->getRecord((int)$id);
        if ($raw === null) continue;
        
        $raw = mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
        $fields = parse_pdok_fields($raw);
        
        // Determine type
        $dty = $fields['DTY'] ?? '';
        $type = 'misc';
        if ($dty === 'ZA') $type = 'article';
        elseif (stripos($dty, 'Druckwerk') !== false) $type = 'book';
        
        // ID
        $bibId = 'ref' . $id;
        
        echo "@" . $type . "{" . $bibId . ",\n";
        
        // Map fields
        $map = [
            'title'     => 'HST',
            'year'      => 'ERJ', 
            'journal'   => 'ZNA',
            'volume'    => 'ZJG',
            'number'    => 'ZHE',
            'pages'     => 'KOL',
            'publisher' => 'VER',
            'address'   => 'ORT',
            'isbn'      => 'ISBN',
            'issn'      => 'ISSN',
            'abstract'  => 'ABS'
        ];
        
        // Special: Author
        $ver = $fields['VER'] ?? '';
        if ($ver !== '') {
            $ver = str_replace('|', ' and ', $ver);
            echo "  author = {" . $ver . "},\n";
        }
        
        foreach ($map as $bibKey => $pdokKey) {
            $val = $fields[$pdokKey] ?? '';
            // Fallbacks
            if ($val === '' && $pdokKey === 'ERJ') $val = $fields['JA'] ?? '';
            if ($val === '' && $pdokKey === 'ISBN') $val = $fields['ISSN'] ?? '';
            
            if ($val !== '') {
                echo "  " . $bibKey . " = {" . $val . "},\n";
            }
        }
        
        echo "}\n\n";
    }
    exit;
}

render_header($HTML_TITLE);
render_app_header(ueb('Warenkorb'));

if (!is_readable($PDOK_PDK)): ?>
    <p class="error">
        <?= htmlspecialchars(ueb('Datenbank ist nicht lesbar – Warenkorb kann nicht angezeigt werden.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
    </p>
<?php
    render_footer();
    exit;
endif;

$cart = $_SESSION['cart'];
sort($cart);

if ($cart === []): ?>
    <p class="muted">
        <?= htmlspecialchars(ueb('Der Warenkorb ist leer.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
    </p>
<?php else: ?>
    <p class="muted">
        <?= htmlspecialchars(ueb('Dokumente im Warenkorb:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        <a href="mtools.php?action=export_bibtex" class="btn btn-small" style="float: right; margin-left: 10px; background-color: var(--secondary); color: white; text-decoration: none; padding: 4px 8px; border-radius: 4px;">BibTeX Export</a>
        <a href="mtools.php?action=clear" class="btn btn-small" style="float: right; background-color: #d9534f; color: white; text-decoration: none; padding: 4px 8px; border-radius: 4px;">Warenkorb leeren</a>
    </p>

    <?php
    require_once __DIR__ . '/MidosIndex.php';
    $indexDir = dirname($PDOK_PDK);
    $midosIndex = new MidosIndex($indexDir);

    foreach ($cart as $ln):
        $raw = $midosIndex->getRecord((int)$ln);
        if ($raw === null) {
            continue;
        }
        $raw = mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
        $rec = format_pdok_record($raw);
        ?>
        <div class="search-result">
            <div class="search-result-title">
                <?= (int)$ln ?>.
                <?= htmlspecialchars($rec['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </div>
            <div class="muted">
                <a href="mtools.php?action=cart_remove&amp;line=<?= (int)$ln ?>" onclick="event.preventDefault(); toggleCart(<?= (int)$ln ?>, 'cart_remove', this); this.closest('.search-result').style.display='none';">
                    <?= htmlspecialchars(ueb('aus Warenkorb entfernen'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </a>
                &nbsp;|&nbsp;
                <a href="mnote.php?line=<?= (int)$ln ?>"><?= htmlspecialchars(ueb('Notiz bearbeiten'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
                <?php
                    require_once __DIR__ . '/UserData.php';
                    $userData = new UserData($DATA_DIR);
                    if ($userData->getNote($_SESSION['username'], (int)$ln)) {
                        echo ' <span style="color:var(--primary);">★</span>';
                    }
                ?>
            </div>
            <div style="margin-top:4px;">
                <?= $rec['html'] ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<p style="margin-top:16px;">
    <a class="btn" href="maske.php"><?= htmlspecialchars(ueb('Zurück zur Suchmaske'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
    <a class="btn" href="mlogin.php?action=logout"><?= htmlspecialchars(ueb('Logout'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
</p>

<?php
render_footer();


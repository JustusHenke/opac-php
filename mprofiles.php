<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
check_auth();
require_once __DIR__ . '/UserData.php';
require_once __DIR__ . '/MidosIndex.php';

$username = $_SESSION['username'];
$userData = new UserData($DATA_DIR);
$midosIndex = new MidosIndex($DATA_DIR);

$action = req('action', 'list');
$profileId = (int)req('id', '0');

if ($action === 'create_from_cart') {
    if (!validate_csrf_token(req('csrf_token'))) {
        die('CSRF token mismatch');
    }
    $name = req('name', '');
    $cart = $_SESSION['cart'] ?? [];
    if ($name !== '' && !empty($cart)) {
        $userData->createProfile($username, $name, $cart);
        $_SESSION['cart'] = []; // Clear cart after saving?
    }
    redirect('mprofiles.php');
}

if ($action === 'delete' && $profileId > 0) {
    $userData->deleteProfile($profileId, $username);
    redirect('mprofiles.php');
}

render_header($HTML_TITLE . ' - Profile');
render_app_header(ueb('Meine Profile / Sammlungen'));

if ($action === 'view' && $profileId > 0): 
    $profiles = $userData->getProfiles($username);
    $current = null;
    foreach ($profiles as $p) { if ((int)$p['id'] === $profileId) { $current = $p; break; } }
    
    if ($current):
        $items = $userData->getProfileItems($profileId);
        ?>
        <h3><?= htmlspecialchars($current['profile_name']) ?></h3>
        <p class="muted"><?= count($items) ?> <?= ueb('Dokumente') ?></p>
        
        <?php foreach ($items as $ln): 
            $raw = $midosIndex->getRecord((int)$ln);
            if (!$raw) continue;
            $raw = mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
            $rec = format_pdok_record($raw);
            ?>
            <div class="search-result">
                <div class="search-result-title"><?= (int)$ln ?>. <?= htmlspecialchars($rec['title']) ?></div>
                <div class="muted">
                    <a href="mnote.php?line=<?= (int)$ln ?>"><?= ueb('Notiz') ?></a>
                </div>
                <?= $rec['html'] ?>
            </div>
        <?php endforeach; ?>
        
        <p style="margin-top:20px;">
            <a href="mprofiles.php" class="btn"><?= ueb('Zurück zur Übersicht') ?></a>
        </p>
    <?php endif;

else:
    $profiles = $userData->getProfiles($username);
    if (empty($profiles)): ?>
        <p class="muted"><?= ueb('Sie haben noch keine Sammlungen gespeichert.') ?></p>
    <?php else: ?>
        <table class="table" style="width:100%; border-collapse: collapse; margin-top:20px;">
            <thead>
                <tr style="border-bottom: 2px solid var(--border-color); text-align:left;">
                    <th style="padding:10px;"><?= ueb('Name') ?></th>
                    <th style="padding:10px;"><?= ueb('Erstellt am') ?></th>
                    <th style="padding:10px;"><?= ueb('Aktion') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($profiles as $p): ?>
                    <tr style="border-bottom: 1px solid var(--tertiary);">
                        <td style="padding:10px;">
                            <a href="mprofiles.php?action=view&amp;id=<?= $p['id'] ?>" style="font-weight:600;">
                                <?= htmlspecialchars($p['profile_name']) ?>
                            </a>
                        </td>
                        <td style="padding:10px;"><?= $p['created_at'] ?></td>
                        <td style="padding:10px;">
                            <a href="mprofiles.php?action=delete&amp;id=<?= $p['id'] ?>" style="color:#d9534f;" onclick="return confirm('Sicher?')"><?= ueb('Löschen') ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="search-box" style="margin-top:40px;">
        <h4><?= ueb('Neue Sammlung aus Warenkorb erstellen') ?></h4>
        <?php if (empty($_SESSION['cart'])): ?>
            <p class="muted"><?= ueb('Ihr Warenkorb ist leer.') ?></p>
        <?php else: ?>
            <form method="post" action="mprofiles.php?action=create_from_cart">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token()) ?>">
                <input type="text" name="name" placeholder="<?= ueb('Name der Sammlung...') ?>" class="input-text" style="width:250px;" required>
                <button type="submit" class="btn btn-primary"><?= ueb('Warenkorb speichern') ?></button>
            </form>
        <?php endif; ?>
    </div>
<?php endif;

render_footer();

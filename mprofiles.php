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
        <h3 style="font-size: var(--size-xl); font-weight: 600; margin-bottom: 8px; color: var(--secondary);"><?= htmlspecialchars($current['profile_name']) ?></h3>
        <p class="status-muted" style="margin-bottom: 20px;"><?= count($items) ?> <?= ueb('Dokumente') ?></p>
        
        <?php foreach ($items as $ln): 
            $raw = $midosIndex->getRecord((int)$ln);
            if (!$raw) continue;
            $raw = mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
            $rec = format_pdok_record($raw);
            ?>
            <div class="result-card">
                <div class="result-title"><?= (int)$ln ?>. <?= htmlspecialchars($rec['title']) ?></div>
                <div class="result-meta">
                    <a href="mnote.php?line=<?= (int)$ln ?>"><?= ueb('Notiz') ?></a>
                </div>
                <?= $rec['html'] ?>
            </div>
        <?php endforeach; ?>
        
        <p style="margin-top:24px;">
            <a href="mprofiles.php" class="btn"><?= ueb('Zurück zur Übersicht') ?></a>
        </p>
    <?php endif;

else:
    $profiles = $userData->getProfiles($username);
    if (empty($profiles)): ?>
        <p class="status-muted"><?= ueb('Sie haben noch keine Sammlungen gespeichert.') ?></p>
    <?php else: ?>
        <div class="profile-list">
            <?php foreach ($profiles as $p): ?>
                <div class="profile-card">
                    <div class="profile-info">
                        <a href="mprofiles.php?action=view&amp;id=<?= $p['id'] ?>" style="font-weight:600; color: var(--secondary);">
                            <div class="profile-name"><?= htmlspecialchars($p['profile_name']) ?></div>
                        </a>
                        <div class="profile-date"><?= $p['created_at'] ?></div>
                    </div>
                    <a href="mprofiles.php?action=delete&amp;id=<?= $p['id'] ?>" 
                       class="btn btn-sm btn-danger" 
                       onclick="return confirm('Sicher?')"><?= ueb('Löschen') ?></a>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="search-box" style="margin-top: 40px;">
        <h3><?= ueb('Neue Sammlung aus Warenkorb erstellen') ?></h3>
        <?php if (empty($_SESSION['cart'])): ?>
            <p class="status-muted"><?= ueb('Ihr Warenkorb ist leer.') ?></p>
        <?php else: ?>
            <form method="post" action="mprofiles.php?action=create_from_cart" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: flex-end;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token()) ?>">
                <div>
                    <label class="form-label" for="profile-name"><?= ueb('Name der Sammlung') ?></label>
                    <input type="text" name="name" id="profile-name" placeholder="<?= ueb('Name der Sammlung...') ?>" class="form-input" style="width:250px;" required>
                </div>
                <button type="submit" class="btn btn-primary"><?= ueb('Warenkorb speichern') ?></button>
            </form>
        <?php endif; ?>
    </div>
<?php endif;

render_footer();

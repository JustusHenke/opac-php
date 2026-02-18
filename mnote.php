<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
check_auth();
require_once __DIR__ . '/UserData.php';
require_once __DIR__ . '/MidosIndex.php';

$username = $_SESSION['username'];
$docId = (int)req('line', '0');
if ($docId <= 0) {
    redirect('msuche.php');
}

$userData = new UserData($DATA_DIR);
$midosIndex = new MidosIndex($DATA_DIR);

$action = req('action', 'view');
$message = '';

if ($action === 'save') {
    if (!validate_csrf_token(req('csrf_token'))) {
        die('CSRF token mismatch');
    }
    $content = req('content', '');
    $userData->saveNote($username, $docId, $content);
    $message = ueb('Notiz wurde gespeichert.');
}

$noteText = $userData->getNote($username, $docId) ?? '';
$record = $midosIndex->getRecord($docId);
$title = 'Unbekannt';
if ($record) {
    $parsed = parse_pdok_fields(mb_convert_encoding($record, 'UTF-8', 'ISO-8859-1'));
    $title = $parsed['HST'] ?? ($parsed['TI'] ?? 'Unbekannter Titel');
}

render_header($HTML_TITLE . ' - Notiz');
render_app_header(ueb('Notiz bearbeiten'));

?>

<div class="search-box">
    <h3><?= htmlspecialchars($title) ?></h3>
    <p class="muted">Dokument-ID: <?= $docId ?></p>

    <?php if ($message): ?>
        <p class="success"><?= htmlspecialchars($message) ?></p>
    <?php endif; ?>

    <form method="post" action="mnote.php?line=<?= $docId ?>&amp;action=save">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token()) ?>">
        <label for="content" class="field-label"><?= htmlspecialchars(ueb('Ihre persönliche Notiz:')) ?></label>
        <textarea name="content" id="content" class="input-text" style="height: 150px;"><?= htmlspecialchars($noteText) ?></textarea>
        
        <div style="margin-top: 20px;">
            <button type="submit" class="btn btn-primary"><?= htmlspecialchars(ueb('Speichern')) ?></button>
            <a href="msuche.php" class="btn"><?= htmlspecialchars(ueb('Abbrechen')) ?></a>
        </div>
    </form>
</div>

<?php
render_footer();

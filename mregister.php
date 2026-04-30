<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require_once __DIR__ . '/UserData.php';

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim(req('username', ''));
    $password = req('password', '');
    $first    = trim(req('first_name', ''));
    $last     = trim(req('last_name', ''));

    if (!validate_csrf_token(req('csrf_token'))) {
        $error = ueb('Sicherheits-Token ungültig. Bitte versuchen Sie es erneut.');
    } elseif ($username === '' || $password === '') {
        $error = ueb('Benutzername und Passwort sind erforderlich.');
    } elseif (strlen($password) < 8) {
        $error = ueb('Das Passwort muss mindestens 8 Zeichen lang sein.');
    } elseif (!preg_match('/^[a-zA-Z0-9_\-]+$/', $username)) {
        $error = ueb('Der Benutzername enthält ungültige Zeichen (nur Buchstaben, Zahlen, _ und - erlaubt).');
    } else {
        $userData = new UserData($DATA_DIR);
        if ($userData->userExists($username)) {
            $error = ueb('Dieser Benutzername ist bereits vergeben.');
        } else {
            if ($userData->registerUser($username, $password, $first, $last)) {
                $success = true;
            } else {
                $error = ueb('Registrierung fehlgeschlagen. Bitte versuchen Sie es später erneut.');
            }
        }
    }
}

render_header($HTML_TITLE . ' - Registrierung');
render_app_header(ueb('Registrierung'));
?>

<div class="search-box">
    <?php if ($success): ?>
        <p class="status-success"><?= htmlspecialchars(ueb('Registrierung erfolgreich! Sie können sich jetzt anmelden.')) ?></p>
        <p><a href="mlogin.php" class="btn btn-primary"><?= htmlspecialchars(ueb('Zum Login')) ?></a></p>
    <?php else: ?>
        <form method="post" action="mregister.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token()) ?>">
            <div class="form-group">
                <label class="form-label" for="username"><?= htmlspecialchars(ueb('Benutzername:')) ?></label>
                <input class="form-input" type="text" name="username" id="username" required>
            </div>
            <div class="form-group">
                <label class="form-label" for="password"><?= htmlspecialchars(ueb('Passwort:')) ?></label>
                <input class="form-input" type="password" name="password" id="password" required>
            </div>
            <div class="form-group">
                <label class="form-label" for="first_name"><?= htmlspecialchars(ueb('Vorname:')) ?></label>
                <input class="form-input" type="text" name="first_name" id="first_name">
            </div>
            <div class="form-group">
                <label class="form-label" for="last_name"><?= htmlspecialchars(ueb('Nachname:')) ?></label>
                <input class="form-input" type="text" name="last_name" id="last_name">
            </div>

            <?php if ($error !== ''): ?>
                <div class="status-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div style="margin-top: 24px; display: flex; gap: 12px; flex-wrap: wrap;">
                <button type="submit" class="btn btn-primary"><?= htmlspecialchars(ueb('Registrieren')) ?></button>
                <a href="mlogin.php" class="btn"><?= htmlspecialchars(ueb('Abbrechen')) ?></a>
            </div>
        </form>
    <?php endif; ?>
</div>

<?php
render_footer();

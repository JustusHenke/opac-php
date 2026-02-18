<?php
declare(strict_types=1);

require __DIR__ . '/config.php';

// Einfache Routing-Logik für Login / Logout
$action = req('action', '');

if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'] ?? false, $params['httponly'] ?? true
        );
    }
    session_destroy();
    redirect('mlogin.php');
}

$error    = '';
$username = req('username', '');
$password = req('password', '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF-Check (nur wenn kein Gastzugang)
    if (req('usergast') === null && !validate_csrf_token(req('csrf_token'))) {
        $error = ueb('Sicherheits-Token ungültig. Bitte versuchen Sie es erneut.');
    } elseif ($username === '' || $password === '') {
        // Gastzugang darf ohne Passwort
        if (req('usergast') !== null) {
            session_regenerate_id(true); // Fixation verhindern
            $_SESSION['username'] = ueb('Gast');
            $_SESSION['userid']   = 'guest';
            redirect('maske.php');
        }
        $error = ueb('Bitte Benutzername und Passwort eingeben.');
    } else {
        $user = authenticate($username, $password);
        if ($user === null) {
            $error = ueb('Ungültiger Benutzername oder Passwort.');
        } else {
            session_regenerate_id(true); // Fixation verhindern
            $useridField = $user[9] ?? '';
            $userid      = $useridField !== '' ? ('usr' . $useridField) : 'usr';

            $_SESSION['username'] = trim(($user[10] ?? '') . ' ' . ($user[11] ?? '') . ' ' . ($user[1] ?? '') . ' ' . ($user[0] ?? ''));
            if ($_SESSION['username'] === '') {
                $_SESSION['username'] = $username;
            }
            $_SESSION['userid'] = $userid;

            redirect('maske.php');
        }
    }
}

render_header($HTML_TITLE);
render_app_header(ueb('Login'));
?>

<p class="muted">
    MIDOS-Version des OPAC.
</p>

<?php if (!is_readable($USER_FILE)): ?>
    <p class="error">
        <?= htmlspecialchars(ueb('Hinweis: Benutzerdatenbank ist nicht lesbar.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
    </p>
<?php endif; ?>

<form method="post" action="mlogin.php">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token()) ?>">
    <div>
        <label class="field-label" for="username"><?= htmlspecialchars(ueb('Benutzername:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
        <input class="input-text" type="text" name="username" id="username"
               value="<?= htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    </div>

    <div>
        <label class="field-label" for="password"><?= htmlspecialchars(ueb('Passwort:'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
        <input class="input-text" type="password" name="password" id="password" value="">
    </div>

    <?php if ($error !== ''): ?>
        <div class="error"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endif; ?>

    <div style="margin-top: 16px;">
        <button type="submit" class="btn btn-primary"><?= htmlspecialchars(ueb('Login'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
        <button type="submit" name="usergast" value="1" class="btn">
            <?= htmlspecialchars(ueb('Gastzugang'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </button>
        <a href="mregister.php" class="btn" style="float: right;">
            <?= htmlspecialchars(ueb('Registrieren'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </a>
    </div>
</form>

<?php
render_footer();


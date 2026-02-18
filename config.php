<?php
// Gemeinsame Konfiguration und Hilfsfunktionen für die PHP-Version (Root-App).

declare(strict_types=1);

// Basispfade (relativ zu diesem Skript im Projekt-Root)
$BASE_DIR   = __DIR__;
$DATA_DIR   = $BASE_DIR . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'midos';

// Datenquellen (nur noch aus data/)
$USER_FILE = $DATA_DIR . DIRECTORY_SEPARATOR . 'user.dat';
$PDOK_PDK  = $DATA_DIR . DIRECTORY_SEPARATOR . 'pdok.pdk';
$PDOK_PD2  = $DATA_DIR . DIRECTORY_SEPARATOR . 'pdok.pd2';
/**
 * Hilfsfunktion: einfache Bool-Tokenisierung (AND/OR/NOT, keine Klammern).
 */
function matches_bool(string $haystack, string $expression): bool
{
    $expression = trim($expression);
    if ($expression === '') {
        return true;
    }

    $tokens = preg_split('/\s+/', $expression);
    if ($tokens === false) {
        return true;
    }

    $result    = null;
    $op        = 'AND';
    $negateNext = false;

    foreach ($tokens as $tok) {
        $upper = strtoupper($tok);
        if ($upper === 'AND' || $upper === 'OR') {
            $op = $upper;
            continue;
        }
        if ($upper === 'NOT') {
            $negateNext = true;
            continue;
        }

        $term = $tok;
        $termNorm = str_lower($term);
        $hayNorm  = str_lower($haystack);
        $has      = str_pos($hayNorm, $termNorm) !== false;
        if ($negateNext) {
            $has        = !$has;
            $negateNext = false;
        }

        if ($result === null) {
            $result = $has;
        } elseif ($op === 'AND') {
            $result = $result && $has;
        } else { // OR
            $result = $result || $has;
        }
    }

    return $result ?? true;
}


// Standard-HTML-Einstellungen
$HTML_TITLE = 'OPAC – MIDOS-WEB-Retrieval';
$CHARSET    = 'utf-8';

// Session-Sicherheitseinstellungen vor dem Start setzen
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_samesite', 'Lax');
    // Falls HTTPS verwendet wird, sollte auch secure gesetzt werden.
    // ini_set('session.cookie_secure', '1'); 

    session_start();
}

/**
 * Generiert einen CSRF-Token für Formulare.
 */
function get_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validiert einen CSRF-Token.
 */
function validate_csrf_token(?string $token): bool
{
    if ($token === null || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Prüft, ob ein Benutzer angemeldet ist, sonst Redirect zum Login.
 */
function check_auth(): void
{
    if (empty($_SESSION['username'])) {
        header('Location: mlogin.php');
        exit;
    }
}

/**
 * Liest GET/POST-Parameter bequem aus.
 */
function req(string $key, ?string $default = null): ?string
{
    if (isset($_POST[$key])) {
        return is_string($_POST[$key]) ? trim($_POST[$key]) : $default;
    }
    if (isset($_GET[$key])) {
        return is_string($_GET[$key]) ? trim($_GET[$key]) : $default;
    }
    return $default;
}

/**
 * Einfache Weiterleitung.
 */
function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function str_lower(string $s): string
{
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($s, 'UTF-8');
    }
    return strtolower($s);
}

function str_pos(string $haystack, string $needle): int|false
{
    if (function_exists('mb_strpos')) {
        return mb_strpos($haystack, $needle, 0, 'UTF-8');
    }
    return strpos($haystack, $needle);
}

/**
 * Einfacher HTML-Kopf.
 */
function render_header(string $title): void
{
    global $CHARSET;
    header('Content-Type: text/html; charset=' . $CHARSET);
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "<!DOCTYPE html>\n";
    echo "<html lang=\"de\">\n<head>\n";
    echo '<meta charset="' . htmlspecialchars($CHARSET, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\">\n";
    echo '<title>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</title>\n";
    echo "<style>
    :root {
      --primary: #0070c0; /* blue */
      --secondary: #003f7a; /* darkblue */
      --tertiary: #ededed; /* light grey */
      --quartary: #badbff; /* light blue */
      --xsmall: 12px;
      --small: 13px;
      --copy: 15px;
      --default: 16px;
      --larger: 17px;
      --large: 18px;
      --xlarge: 20px;
      --primary-color: #0070c0;
      --primary-dark: #003f7a;
      --text-color: #111;
      --light-bg: #ededed;
      --border-color: #d1d1d1;
      --menu-transition: 0.3s ease;
      --box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    }

    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: var(--default); color: var(--text-color); background-color: #fff; margin: 0; padding: 0; }
    
    a { color: var(--primary); text-decoration: none; transition: all 0.2s; }
    a:hover { color: var(--primary-dark); text-decoration: underline; }
    
    .container { max-width: 1000px; margin: 30px auto; padding: 25px; border: 1px solid var(--border-color); background: #fff; box-shadow: var(--box-shadow); border-radius: 4px; }
    
    .header { border-bottom: 2px solid var(--primary); margin-bottom: 25px; padding-bottom: 15px; display: flex; justify-content: space-between; align-items: center; }
    .header-title { font-size: var(--xlarge); font-weight: 600; color: var(--secondary); }
    .login-info { font-size: var(--small); color: #666; }
    
    .btn { padding: 8px 16px; border: 1px solid var(--border-color); background: var(--tertiary); color: #333; cursor: pointer; border-radius: 4px; font-size: var(--default); transition: background 0.2s; display: inline-block; }
    .btn:hover { background: #ddd; text-decoration: none; }
    
    .btn-primary { background: var(--primary); color: #fff; border-color: var(--primary-dark); }
    .btn-primary:hover { background: var(--primary-dark); color: #fff; }
    
    .field-label { font-weight: 600; margin-top: 12px; display: block; color: var(--secondary); font-size: var(--small); }
    .input-text { width: 100%; max-width: 600px; padding: 10px; border: 1px solid var(--border-color); border-radius: 4px; font-size: var(--default); }
    .input-text:focus { border-color: var(--primary); outline: none; }
    
    .search-result { border-bottom: 1px solid var(--tertiary); padding: 15px 0; }
    .search-result:last-child { border-bottom: none; }
    .search-result-title { font-weight: 600; font-size: var(--larger); color: var(--primary-dark); margin-bottom: 5px; }
    
    .muted { color: #666; font-size: var(--small); margin-bottom: 8px; }
    .muted a { color: var(--primary); }
    
    .error { color: #d32f2f; background: #ffebee; padding: 10px; border-radius: 4px; border: 1px solid #ffcdd2; }
    .success { color: #388e3c; background: #e8f5e9; padding: 10px; border-radius: 4px; border: 1px solid #c8e6c9; }
    </style>\n";
    echo <<<'EOD'
    <script>
    async function toggleCart(line, action, el) {
        try {
            const response = await fetch(`mtools.php?action=${action}&line=${line}&ajax=1`);
            const data = await response.json();
            if (data.success) {
                if (action === 'cart_add') {
                    el.innerText = 'aus Warenkorb entfernen';
                    el.href = `mtools.php?action=cart_remove&line=${line}`;
                    el.onclick = (e) => { e.preventDefault(); toggleCart(line, 'cart_remove', el); };
                    el.style.color = '#d9534f';
                } else {
                    el.innerText = 'in Warenkorb';
                    el.href = `mtools.php?action=cart_add&line=${line}`;
                    el.onclick = (e) => { e.preventDefault(); toggleCart(line, 'cart_add', el); };
                    el.style.color = '';
                }
            }
        } catch (e) {
            console.error('Cart update failed', e);
            window.location.href = el.href; 
        }
    }
    </script>
EOD;
    echo "</head>\n<body>\n";
}

/**
 * Einfacher HTML-Fuß.
 */
function render_footer(): void
{
    echo "</body>\n</html>";
}

/**
 * Kopfbereich mit Titel + Login-Anzeige.
 */
function render_app_header(string $pageTitle): void
{
    $username = $_SESSION['username'] ?? null;
    echo "<div class=\"container\">\n";
    echo "  <div class=\"header\">\n";
    echo '    <div class="header-title">' . htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</div>\n";
    echo '    <div class="login-info">';
    if ($username) {
        echo 'Angemeldet als <strong>' . htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong>'
            . ' &nbsp;|&nbsp; <a href="maske.php">Suche</a>'
            . ' &nbsp;|&nbsp; <a href="mindex.php">' . htmlspecialchars(ueb('Index')) . '</a>'
            . ' &nbsp;|&nbsp; <a href="mprofiles.php">' . htmlspecialchars(ueb('Sammlungen')) . '</a>'
            . ' &nbsp;|&nbsp; <a href="mtools.php?action=cart_view">' . htmlspecialchars(ueb('Warenkorb')) . '</a>'
            . ' &nbsp;|&nbsp; <a href="mlogin.php?action=logout">Logout</a>';
    } else {
        echo '<a class="btn btn-primary" href="mlogin.php" style="color: white; padding: 4px 10px;">Login</a>';
    }
    echo "</div>\n";
    echo "  </div>\n";
}

/**
 * Platzhalter für Übersetzungen. (Später: mwrlang.dat portieren)
 */
function ueb(string $text): string
{
    return $text;
}

/**
 * Liest Benutzer aus user.dat (bestehendes Format).
 *
 * Rückgabe: Array von Datensätzen, jeder Datensatz ist ein Array der per „¿“ getrennten Felder.
 */
function load_users(): array
{
    global $USER_FILE;
    if (!is_readable($USER_FILE)) {
        return [];
    }
    $lines = file($USER_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $users = [];
    foreach ($lines as $line) {
        $line = mb_convert_encoding($line, 'UTF-8', 'ISO-8859-1');
        $users[] = explode('¿', $line);
    }
    return $users;
}

/**
 * Sucht einen Benutzer anhand von Benutzername/Passwort.
 * Perl-Referenz: `mlogin.pl` nutzt Feld[7] (username) und Feld[8] (password).
 */
function authenticate(string $username, string $password): ?array
{
    require_once __DIR__ . '/UserData.php';
    global $DATA_DIR;
    $userData = new UserData($DATA_DIR);
    $user = $userData->authenticateUser($username, $password);
    
    if ($user) {
        // Return in a format compatible with existing mlogin.php expectations
        // Mapping SQLite fields to legacy-like array indexes for minimal disruption
        return [
            0 => $user['last_name'],
            1 => $user['first_name'],
            7 => $user['username'],
            9 => $user['username'], // Reuse username as ID for DB users
            'is_db' => true
        ];
    }

    // Legacy fallback (WARNUNG: Vergleicht Passwörter im Klartext!)
    // Es wird empfohlen, alle Benutzer in die SQLite-Datenbank zu migrieren.
    $users = load_users();
    foreach ($users as $fields) {
        if (($fields[7] ?? '') === $username && ($fields[8] ?? '') === $password) {
            return $fields;
        }
    }
    return null;
}

/**
 * Rudimentäres Parsen eines MIDOS-Datensatzes aus pdok.pdk
 * Format: "Feld:Wert¿Feld:Wert¿..."
 */
function parse_pdok_fields(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }

    $parts  = explode('¿', $raw);
    $fields = [];
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        $pos = strpos($part, ':');
        if ($pos === false) {
            continue;
        }
        $name  = substr($part, 0, $pos);
        $value = substr($part, $pos + 1);
        $fields[$name] = $value;
    }

    return $fields;
}

/**
 * Aufbereitung eines Datensatzes (Titel + HTML), auf Basis von parse_pdok_fields().
 */
function format_pdok_record(string $raw): array
{
    $fields = parse_pdok_fields($raw);
    if ($fields === []) {
        return ['title' => '(leer)', 'html' => '(leer)'];
    }

    // Haupttitel ermitteln (HST, TI, T)
    $title = $fields['HST'] ?? ($fields['TI'] ?? ($fields['T'] ?? '(ohne Titel)'));
    
    // Wichtige Felder extrahieren
    $author = $fields['VER'] ?? '';
    if ($author === '') {
         // Fallback auf Verfasser im Rohtext? Eher nicht, VER sollte da sein.
         // Manchmal steht es in anderen Feldern
    }
    
    $source  = $fields['ZNA'] ?? '';
    $year    = $fields['ERJ'] ?? ($fields['JA'] ?? '');
    $issue   = $fields['ZHE'] ?? '';
    $volume  = $fields['ZJG'] ?? '';
    $pages   = $fields['KOL'] ?? '';
    $place   = $fields['ORT'] ?? '';
    $publisher = $fields['VER'] ?? ''; // Naja, VER ist Person.
    
    $abstract = $fields['ABS'] ?? ($fields['ZUS'] ?? '');
    
    $sig     = $fields['SIG'] ?? '';
    $isbn    = $fields['ISSN'] ?? ($fields['ISBN'] ?? ''); 
    $url     = $fields['URL'] ?? '';
    if ($url !== '') {
        // Strip <a> tags if the field contains raw HTML: <a href="http...">link</a>
        if (preg_match('/href="([^"]+)"/i', $url, $m)) {
            $url = $m[1];
        } else {
            $url = strip_tags($url);
        }
        $url = trim($url);
    }

    // HTML Zusammenbauen
    $html  = '<div style="margin-bottom: 6px;">';
    
    // Volltext / PDF Link
    if ($url !== '') {
        $html .= '<div style="margin-bottom: 10px; background: #f9f9f9; padding: 6px; border-left: 4px solid var(--primary);">
                    <a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" target="_blank" style="font-weight:600; color:var(--primary);">
                        <span style="margin-right:5px;">📄</span>' . htmlspecialchars(ueb('Volltext anzeigen / PDF öffnen')) . '
                    </a>
                  </div>';
    }

    // Verfasser
    if ($author !== '') {
        // Multi-Value Feld? Oft mit | getrennt
        $authors = explode('|', $author);
        $authorLinks = [];
        foreach ($authors as $a) {
            $a = trim($a);
            if ($a === '') continue;
            // Link zur Personensuche (qp)
            $link = 'msuche.php?qp=' . urlencode('"' . $a . '"');
            $authorLinks[] = '<a href="' . $link . '" style="color: var(--secondary); text-decoration: none;">' 
                           . htmlspecialchars($a, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';
        }
        
        $html .= '<div style="font-size: var(--default); color: var(--text-color); margin-bottom: 2px;">' 
              . implode(' &nbsp;|&nbsp; ', $authorLinks) 
              . '</div>';
    }

    // Titel (wird im UI oft schon als Link angezeigt, aber hier nochmal als Text?)
    // In msuche.php wird 'title' für den Link genutzt.
    // Wir zeigen hier Zusatzinfos.
    
    // Quelle (Zeitschrift, Jahr, etc.)
    $sourceStr = '';
    if ($source !== '') {
        $sourceStr .= 'In: <em>' . htmlspecialchars($source, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</em>';
    }
    if ($volume !== '') {
        $sourceStr .= ', Jg. ' . htmlspecialchars($volume, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    if ($issue !== '') {
        $sourceStr .= ', Heft ' . htmlspecialchars($issue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    if ($year !== '') {
        $sourceStr .= ' (' . htmlspecialchars($year, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ')';
    }
    if ($pages !== '') {
        $sourceStr .= ', ' . htmlspecialchars($pages, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    
    if ($sourceStr !== '') {
        $html .= '<div style="color: #555; margin-bottom: 4px;">' . $sourceStr . '</div>';
    }

    // Signatur und ID
    $sigStr = '';
    if ($sig !== '') {
        $sigStr .= '<span style="background: var(--tertiary); padding: 2px 6px; border-radius: 3px; font-weight: 600; font-size: var(--small);">SIG: ' 
                . htmlspecialchars($sig, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span> ';
    }
    if ($isbn !== '') {
        $sigStr .= '<span style="color: #666; font-size: var(--small); margin-left: 8px;">ISBN/ISSN: ' 
                . htmlspecialchars($isbn, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
    }

    if ($sigStr !== '') {
        $html .= '<div style="margin-top: 6px; margin-bottom: 8px;">' . $sigStr . '</div>';
    }

    // Abstract (gekürzt?)
    if ($abstract !== '') {
        if (strlen($abstract) > 300) {
            $short = substr($abstract, 0, 300) . '...';
            // Einfacher Toggle wäre schön, aber JS ist hier minimal. Zeigen wir es ganz oder gekürzt.
            // Zeigen wir es ganz, es ist eine Detailansicht in der Liste.
        }
        $html .= '<div style="margin-top: 8px; font-size: var(--small); color: #333; line-height: 1.4;"><strong>Abstract:</strong> ' 
              . htmlspecialchars($abstract, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
    }
    
    $html .= '</div>';
    
    // Debug / Alle Felder (auskommentiert oder togglebar?)
    // Wir lassen die rohen Felder erstmal weg oder packen sie in einen <details> block
    $html .= '<details style="margin-top: 8px; font-size: var(--xsmall); color: #777;">';
    $html .= '<summary>Alle Felder anzeigen</summary>';
    foreach ($fields as $name => $value) {
        if (substr($name, 0, 1) === '@') continue; // Interne Felder ausblenden
        $html .= '<div><strong>' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ':</strong> ' 
              . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
    }
    $html .= '</details>';

    return [
        'title' => (string)$title,
        'html'  => $html,
    ];
}

/**
 * Einfache Such-Statistik in data/midos/stat.log.
 */
function log_search(string $query, int $hits): void
{
    global $DATA_DIR;
    $file = $DATA_DIR . DIRECTORY_SEPARATOR . 'stat.log';

    $user = $_SESSION['username'] ?? '';
    $time = date('Y-m-d H:i:s');
    $line = implode("\t", [$time, $user, $query, (string)$hits]) . "\n";

    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}



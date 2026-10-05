<?php
// Gemeinsame Konfiguration und Hilfsfunktionen für die PHP-Version (Root-App).

declare(strict_types=1);

// Basispfade (relativ zu diesem Skript im Projekt-Root)
$BASE_DIR = __DIR__;
$DATA_DIR =
    $BASE_DIR . DIRECTORY_SEPARATOR . "data" . DIRECTORY_SEPARATOR . "midos";

// Datenquellen (nur noch aus data/)
$PDOK_PDK = $DATA_DIR . DIRECTORY_SEPARATOR . "pdok.pdk";

// Neuer Bestand: BibTeX-Dateien unter data/bib/. Die NEUESTE .bib-Datei bildet
// den Vollbestand ab (Quelle der Wahrheit).
$BIB_DIR =
    $BASE_DIR . DIRECTORY_SEPARATOR . "data" . DIRECTORY_SEPARATOR . "bib";

// Nutzerdaten (Konten, Notizen, Profile) sind datenquellenunabhängig und
// liegen direkt unter data/ – nicht mehr im MIDOS-Ordner.
$USER_DATA_DIR = $BASE_DIR . DIRECTORY_SEPARATOR . "data";

// Einmalige Migration: alte Lage data/midos/user_data.db nach data/ verschieben
$legacyUserDb = $DATA_DIR . DIRECTORY_SEPARATOR . "user_data.db";
$userDb = $USER_DATA_DIR . DIRECTORY_SEPARATOR . "user_data.db";
if (is_file($legacyUserDb) && !is_file($userDb)) {
    @rename($legacyUserDb, $userDb);
}

// .env im Projekt-Root einlesen (Vorlage: .env.example). Format: KEY=VALUE je
// Zeile, optional in Anführungszeichen; # = Kommentar. Echte Umgebungsvariablen
// haben Vorrang (existierende Keys werden nicht überschrieben). Die Datei ist
// über die Root-.htaccess vor Web-Zugriff geschützt und gitignored.
$envFile = $BASE_DIR . DIRECTORY_SEPARATOR . ".env";
if (is_file($envFile)) {
    $envLines = @file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($envLines)) {
        foreach ($envLines as $envLine) {
            $envLine = trim($envLine);
            if ($envLine === "" || $envLine[0] === "#") {
                continue;
            }
            $eq = strpos($envLine, "=");
            if ($eq === false) {
                continue;
            }
            $envKey = trim(substr($envLine, 0, $eq));
            $envVal = trim(substr($envLine, $eq + 1));
            $len = strlen($envVal);
            if ($len >= 2
                && (($envVal[0] === '"' && $envVal[$len - 1] === '"')
                    || ($envVal[0] === "'" && $envVal[$len - 1] === "'"))) {
                $envVal = substr($envVal, 1, -1);
            }
            if ($envKey !== "" && getenv($envKey) === false) {
                if (function_exists("putenv")) {
                    @putenv($envKey . "=" . $envVal);
                }
                $_ENV[$envKey] = $envVal;
            }
        }
    }
    unset($envFile, $envLines, $envLine, $eq, $envKey, $envVal, $len);
}

/**
 * Aktive Datenquelle des OPAC:
 *  - 'bibtex': Bestand = neueste .bib-Datei unter data/bib (Standard, MIDOS wird
 *    nicht mehr weitergegeben)
 *  - 'midos' : Bestand = data/midos/pdok.pdk (Rückfallposition / Archivmodus)
 */
$DATA_SOURCE = "bibtex";

/**
 * Admin-Secret für Bestandsimport-Aktionen (mimport.php). Es gibt kein
 * Admin-Rollenkonzept – stattdessen muss bei jeder Import-Aktion das Secret
 * mitgeliefert werden (Formularfeld, nur POST).
 * Empfohlen: Datei .env im Projekt-Root (OPAC_ADMIN_SECRET=..., Vorlage
 * .env.example). Reihenfolge: Umgebungsvariable/.env -> Wert hier (Fallback).
 * Leeres Secret (alle Quellen) => Import-Aktionen deaktiviert (fail-closed).
 */
$OPAC_ADMIN_SECRET = "";
/**
 * Hilfsfunktion: einfache Bool-Tokenisierung (AND/OR/NOT, keine Klammern).
 */
function matches_bool(string $haystack, string $expression): bool
{
    $expression = trim($expression);
    if ($expression === "") {
        return true;
    }

    $tokens = preg_split("/\s+/", $expression);
    if ($tokens === false) {
        return true;
    }

    $result = null;
    $op = "AND";
    $negateNext = false;

    foreach ($tokens as $tok) {
        $upper = strtoupper($tok);
        if ($upper === "AND" || $upper === "OR") {
            $op = $upper;
            continue;
        }
        if ($upper === "NOT") {
            $negateNext = true;
            continue;
        }

        $term = $tok;
        $termNorm = str_lower($term);
        $hayNorm = str_lower($haystack);
        $has = str_pos($hayNorm, $termNorm) !== false;
        if ($negateNext) {
            $has = !$has;
            $negateNext = false;
        }

        if ($result === null) {
            $result = $has;
        } elseif ($op === "AND") {
            $result = $result && $has;
        } else {
            // OR
            $result = $result || $has;
        }
    }

    return $result ?? true;
}

// Standard-HTML-Einstellungen
$HTML_TITLE = "OPAC – MIDOS-WEB-Retrieval";
$CHARSET = "utf-8";

// Session-Sicherheitseinstellungen vor dem Start setzen
if (session_status() === PHP_SESSION_NONE) {
    ini_set("session.cookie_httponly", "1");
    ini_set("session.use_only_cookies", "1");
    ini_set("session.cookie_samesite", "Lax");
    // Falls HTTPS verwendet wird, sollte auch secure gesetzt werden.
    // ini_set('session.cookie_secure', '1');

    session_start();
}

/**
 * Generiert einen CSRF-Token für Formulare.
 */
function get_csrf_token(): string
{
    if (empty($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }
    return $_SESSION["csrf_token"];
}

/**
 * Validiert einen CSRF-Token.
 */
function validate_csrf_token(?string $token): bool
{
    if ($token === null || empty($_SESSION["csrf_token"])) {
        return false;
    }
    return hash_equals($_SESSION["csrf_token"], $token);
}

/**
 * Prüft, ob ein Benutzer angemeldet ist, sonst Redirect zum Login.
 */
function check_auth(): void
{
    if (empty($_SESSION["username"])) {
        header("Location: mlogin.php");
        exit();
    }
}

/**
 * Liefert das effektive Admin-Secret. Reihenfolge:
 * 1. Umgebungsvariable OPAC_ADMIN_SECRET (inkl. Werten aus .env, das beim
 *    Start von config.php eingelesen wird)
 * 2. Fallback: $OPAC_ADMIN_SECRET aus config.php
 */
function opac_admin_secret(): string
{
    global $OPAC_ADMIN_SECRET;
    $secret = (string) (getenv("OPAC_ADMIN_SECRET") ?: "");
    if ($secret === "" && isset($_ENV["OPAC_ADMIN_SECRET"])) {
        $secret = (string) $_ENV["OPAC_ADMIN_SECRET"];
    }
    if ($secret === "") {
        $secret = (string) ($OPAC_ADMIN_SECRET ?? "");
    }
    return $secret;
}

/**
 * Prüft das mitgelieferte Admin-Secret (timing-sicher via hash_equals,
 * fail-closed): leerer konfigurierter Secret-Wert => false (Import deaktiviert).
 */
function check_admin_secret(?string $input): bool
{
    $secret = opac_admin_secret();
    if ($secret === "" || $input === null || $input === "") {
        return false;
    }
    return hash_equals($secret, $input);
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
    header("Location: " . $url);
    exit();
}

function str_lower(string $s): string
{
    if (function_exists("mb_strtolower")) {
        return mb_strtolower($s, "UTF-8");
    }
    return strtolower($s);
}

function str_pos(string $haystack, string $needle): int|false
{
    if (function_exists("mb_strpos")) {
        return mb_strpos($haystack, $needle, 0, "UTF-8");
    }
    return strpos($haystack, $needle);
}

/**
 * Quellenangabe eines Datensatzes als HTML-Kommentar statt sichtbarem Badge.
 *
 * Die Herkunft (BibTeX-Bestand oder MIDOS-Bestand) bleibt maschinenlesbar –
 * für Vorlagen, Export und Auswertung –, ohne die Trefferliste optisch zu
 * belasten. Im öffentlichen Frontend wird die Datenquelle bewusst nicht
 * angezeigt.
 */
function source_comment(?string $source): string
{
    $key = match ($source) {
        'bib' => 'bibtex',
        'midos' => 'midos',
        default => 'unknown',
    };
    return '<!-- source: ' . $key . ' -->';
}

/**
 * Sortierschlüssel: lowercase, Diakritika gefaltet, nur Buchstaben/Ziffern.
 * Sorgt dafür, dass A–Z weder an Groß-/Kleinschreibung noch an Umlauten hängt.
 * Die Faltungen entsprechen BibLibrary::normText().
 */
function sort_key(?string $s): string
{
    $s = str_lower((string) $s);
    $translit = [
        'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'á' => 'a', 'à' => 'a', 'â' => 'a',
        'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ç' => 'c',
        'å' => 'a', 'ø' => 'o', 'æ' => 'ae', 'œ' => 'oe', 'ł' => 'l', 'š' => 's',
        'ž' => 'z', 'č' => 'c', 'ę' => 'e', 'ą' => 'a', 'ć' => 'c', 'ń' => 'n',
        'ő' => 'o', 'ű' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'đ' => 'd', 'ð' => 'd', 'þ' => 'th',
    ];
    $s = strtr($s, $translit);
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? '';
    return trim(preg_replace('/\s+/', ' ', $s) ?? '');
}

/**
 * Zerlegt einen Bool-Ausdruck (UND/ODER/NOT) in Einzelschritte.
 *
 * Es werden die englischen (AND/OR/NOT) wie die deutschen Operatoren
 * (UND/ODER/NICHT) erkannt – und nur in Großschreibung, damit das im Deutschen
 * häufige Wort "und" nicht als Operator verschluckt wird.
 *
 * @return list<array{op:string,term:string}> op = AND|OR|NOT
 */
function parse_boolean(string $expression): array
{
    preg_match_all('/"(?:\\\\.|[^\\\\"])*"|\S+/', trim($expression), $matches);
    $out = [];
    $op = 'AND';
    foreach ($matches[0] ?? [] as $tok) {
        if (strlen($tok) > 1 && $tok[0] === '"' && substr($tok, -1) === '"') {
            $tok = stripslashes(substr($tok, 1, -1));
        }
        $upper = strtoupper($tok);
        // AND/OR/NOT wie bisher ohne Rücksicht auf Groß-/Kleinschreibung;
        // die deutschen Entsprechungen zählen bewusst nur in Großschreibung,
        // damit "und" im Text ein Suchbegriff bleibt und kein Operator wird.
        if ($upper === 'AND' || $tok === 'UND') { $op = 'AND'; continue; }
        if ($upper === 'OR' || $tok === 'ODER') { $op = 'OR'; continue; }
        if ($upper === 'NOT' || $tok === 'NICHT') { $op = 'NOT'; continue; }
        if ($tok === '') { continue; }
        $out[] = ['op' => $op, 'term' => $tok];
        $op = 'AND';
    }
    return $out;
}

/**
 * Bool-Ausdruck auf eine bereits ermittelte Treffermenge anwenden.
 *
 * Bewusst auf Mengen statt auf den Gesamtbestand: das Ergebnis ist immer eine
 * Teilmenge von $base und behält dessen Reihenfolge (die der Anzeige
 * entspricht, bei Relevanz also die Trefferreihenfolge). NOT ohne vorherigen
 * Begriff schließt alles Ausgeschlossene aus der Ausgangsmenge aus.
 *
 * @param int[] $base Ausgangsmenge (bestimmt auch die Reihenfolge)
 * @param list<array{op:string,set:int[]}> $terms
 * @return int[] Teilmenge von $base
 */
function apply_boolean(array $base, array $terms): array
{
    $result = null;
    foreach ($terms as $term) {
        $set = $term['set'] ?? [];
        switch ($term['op'] ?? 'AND') {
            case 'NOT':
                $result = $result === null
                    ? array_diff($base, $set)
                    : array_diff($result, $set);
                break;
            case 'OR':
                $result = $result === null
                    ? $set
                    : array_unique(array_merge($result, $set));
                break;
            default: // AND
                $result = $result === null
                    ? $set
                    : array_intersect($result, $set);
        }
    }
    if ($result === null) {
        return [];
    }
    // Ausgabe in der Reihenfolge der Ausgangsmenge (array_* erhält sie).
    return array_values(array_intersect($base, $result));
}

/**
 * "in Treffern suchen": Bool-Ausdruck auf die angezeigte Trefferliste anwenden.
 * Der Ausdruck wird ausschließlich gegen $base ausgewertet – eine Suche in
 * Treffern kann nie versehentlich den gesamten Bestand durchsuchen.
 *
 * @param int[] $base Datensatz-IDs der angezeigten Treffer
 * @return int[] Teilmenge von $base
 */
function refine_hits(OpacLibrary $lib, array $base, string $expression, int|string $field): array
{
    $base = array_map('intval', $base);
    if ($base === [] || trim($expression) === '') {
        return [];
    }
    $terms = [];
    foreach (parse_boolean($expression) as $step) {
        // Der Gesamttreffer wird auf die Ausgangsmenge beschnitten; nur so
        // zählt der einzelne Begriff tatsächlich "innerhalb der Treffer".
        $terms[] = [
            'op' => $step['op'],
            'set' => array_intersect($base, $lib->searchBoolean($step['term'], $field)),
        ];
    }
    if ($terms === []) {
        return $base;
    }
    return apply_boolean($base, $terms);
}

/**
 * Treffer-IDs nach Autor, Jahr oder Titel sortieren.
 *
 * 'relevance' (Vorgabe) bleibt unangetastet: die Reihenfolge kommt dann aus
 * der Suche selbst und wird nicht überschrieben.
 *
 * @param int[] $ids
 * @param array<int,array{author:string,year:string,title:string}> $keys
 * @return int[]
 */
function sort_hit_ids(array $ids, array $keys, string $sort): array
{
    if ($sort === '' || $sort === 'relevance') {
        return $ids;
    }
    $field = match (true) {
        str_starts_with($sort, 'author') => 'author',
        str_starts_with($sort, 'year') => 'year',
        default => 'title',
    };
    $desc = str_ends_with($sort, '_desc');

    usort($ids, static function (int $a, int $b) use ($keys, $field, $desc): int {
        $ka = $keys[$a][$field] ?? '';
        $kb = $keys[$b][$field] ?? '';
        $kaMissing = ($ka === '');
        $kbMissing = ($kb === '');
        // Datensätze ohne Wert (z. B. ohne Verfasser) stehen immer am Ende –
        // in beide Richtungen, sonst eröffnen sie die Liste sinnlos.
        if ($kaMissing !== $kbMissing) {
            return $kaMissing ? 1 : -1;
        }
        if ($field === 'year' && !$kaMissing) {
            $cmp = ((int) $ka) <=> ((int) $kb);
        } else {
            $cmp = strcmp($ka, $kb);
        }
        if ($cmp !== 0) {
            return $desc ? -$cmp : $cmp;
        }
        return $a <=> $b; // stabil: gleiche Werte behalten die Bestandsreihenfolge
    });
    return $ids;
}

/**
 * Such-URL, die übergebene Parameter (leere werden weggelassen) mitnimmt.
 * Basis für Sortier- und Verfeinerungslinks, die den Suchauftrag nicht verlieren.
 *
 * @param array<string,string|null> $params
 */
function search_link(array $params): string
{
    $clean = [];
    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        $clean[$key] = $value;
    }
    return $clean === [] ? 'msuche.php' : 'msuche.php?' . http_build_query($clean);
}

/**
 * Einfacher HTML-Kopf.
 */
function render_header(string $title): void
{
    global $CHARSET;
    header("Content-Type: text/html; charset=" . $CHARSET);
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo "<!DOCTYPE html>\n";
    echo "<html lang=\"de\">\n<head>\n";
    echo '<meta charset="' .
        htmlspecialchars($CHARSET, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") .
        "\">\n";
    echo "<title>" .
        htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") .
        "</title>\n";
    echo "<style>
    :root {
      --primary: #0070c0;
      --secondary: #003f7a;
      --tertiary: #ededed;
      --quartary: #badbff;
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
    echo '<link rel="stylesheet" href="styles.css">' . "\n";
    echo <<<'EOD'
        <script>
        (function() {
          var saved = localStorage.getItem('opac-theme');
          if (saved) { document.documentElement.setAttribute('data-theme', saved); }
          else if (window.matchMedia('(prefers-color-scheme: dark)').matches) { document.documentElement.setAttribute('data-theme', 'dark'); }
        })();
        function toggleTheme() {
          var current = document.documentElement.getAttribute('data-theme');
          var next = current === 'dark' ? 'light' : 'dark';
          document.documentElement.setAttribute('data-theme', next);
          localStorage.setItem('opac-theme', next);
        }
        function toggleMobileNav() {
          var nav = document.getElementById('app-nav');
          if (nav) nav.classList.toggle('open');
        }
        </script>
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
    echo '<a class="skip-link" href="#main">Zum Inhalt springen</a>' . "\n";
}

/**
 * Einfacher HTML-Fuß.
 */
function render_footer(): void
{
    echo "</div>\n";
    echo "</body>\n</html>";
}

/**
 * Kopfbereich mit Titel + Login-Anzeige.
 */
function render_app_header(string $pageTitle): void
{
    $username = $_SESSION["username"] ?? null;
    echo '<div class="app-container">' . "\n";
    echo '<div class="app-header">' . "\n";
    echo '  <div class="app-title">' .
        htmlspecialchars($pageTitle, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") .
        "</div>\n";
    echo '  <div class="app-nav" id="app-nav">' . "\n";
    if ($username) {
        echo '    <a class="nav-link" href="maske.php">' .
            htmlspecialchars(ueb("Suche")) .
            "</a>" .
            "\n";
        echo '    <a class="nav-link" href="mindex.php">' .
            htmlspecialchars(ueb("Index")) .
            "</a>" .
            "\n";
        echo '    <a class="nav-link" href="mprofiles.php">' .
            htmlspecialchars(ueb("Sammlungen")) .
            "</a>" .
            "\n";
        echo '    <a class="nav-link" href="mtools.php?action=cart_view">' .
            htmlspecialchars(ueb("Warenkorb")) .
            "</a>" .
            "\n";
        echo '    <a class="nav-link" href="mlogin.php?action=logout">' .
            htmlspecialchars(ueb("Logout")) .
            "</a>" .
            "\n";
    } else {
        echo '    <a class="btn btn-primary" href="mlogin.php">' .
            htmlspecialchars(ueb("Login")) .
            "</a>" .
            "\n";
    }
    echo '    <button class="theme-toggle" onclick="toggleTheme()" aria-label="Design wechseln" title="Helles/Dunkles Design">🌓</button>' .
        "\n";
    if ($username) {
        echo '    <button class="mobile-nav-toggle" onclick="toggleMobileNav()" aria-label="Menü öffnen">☰</button>' .
            "\n";
    }
    echo "  </div>\n";
    echo "</div>\n";
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

/**
 * Sucht einen Benutzer anhand von Benutzername/Passwort.
 * Perl-Referenz: `mlogin.pl` nutzt Feld[7] (username) und Feld[8] (password).
 */
function authenticate(string $username, string $password): ?array
{
    require_once __DIR__ . "/UserData.php";
    global $DATA_DIR, $USER_DATA_DIR;
    $userData = new UserData($USER_DATA_DIR);
    $user = $userData->authenticateUser($username, $password);

    if ($user) {
        return [
            0 => $user["last_name"],
            1 => $user["first_name"],
            7 => $user["username"],
            9 => $user["username"],
            "is_db" => true,
        ];
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
    if ($raw === "") {
        return [];
    }

    $parts = explode("¿", $raw);
    $fields = [];
    foreach ($parts as $part) {
        if ($part === "") {
            continue;
        }
        $pos = strpos($part, ":");
        if ($pos === false) {
            continue;
        }
        $name = substr($part, 0, $pos);
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
        return ["title" => "(leer)", "html" => "(leer)"];
    }

    // Haupttitel ermitteln (HST, TI, T)
    $title =
        $fields["HST"] ?? ($fields["TI"] ?? ($fields["T"] ?? "(ohne Titel)"));

    // Wichtige Felder extrahieren
    $author = $fields["VER"] ?? "";
    if ($author === "") {
        // Fallback auf Verfasser im Rohtext? Eher nicht, VER sollte da sein.
        // Manchmal steht es in anderen Feldern
    }

    $source = $fields["ZNA"] ?? "";
    $year = $fields["ERJ"] ?? ($fields["JA"] ?? "");
    $issue = $fields["ZHE"] ?? "";
    $volume = $fields["ZJG"] ?? "";
    $pages = $fields["KOL"] ?? "";

    $abstract = $fields["ABS"] ?? ($fields["ZUS"] ?? "");

    $sig = $fields["SIG"] ?? "";
    $isbn = $fields["ISSN"] ?? ($fields["ISBN"] ?? "");
    $url = $fields["URL"] ?? "";
    if ($url !== "") {
        // Strip <a> tags if the field contains raw HTML: <a href="http...">link</a>
        if (preg_match('/href="([^"]+)"/i', $url, $m)) {
            $url = $m[1];
        } else {
            $url = strip_tags($url);
        }
        $url = trim($url);
    }

    // HTML Zusammenbauen
    $html = '<div class="record-content">';

    // Volltext / PDF Link
    if ($url !== "") {
        $html .=
            '<a class="fulltext-link" href="' .
            htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") .
            '" target="_blank">' .
            "<span>📄</span>" .
            htmlspecialchars(ueb("Volltext anzeigen / PDF öffnen")) .
            "</a>";
    }

    // Verfasser
    if ($author !== "") {
        // Multi-Value Feld? Oft mit | getrennt
        $authors = explode("|", $author);
        $authorLinks = [];
        foreach ($authors as $a) {
            $a = trim($a);
            if ($a === "") {
                continue;
            }
            // Link zur Personensuche (qp)
            $link = "msuche.php?qp=" . urlencode('"' . $a . '"');
            $authorLinks[] =
                '<a class="author-link" href="' .
                $link .
                '">' .
                htmlspecialchars($a, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") .
                "</a>";
        }

        $html .=
            '<div class="author-links">' .
            implode(" &nbsp;|&nbsp; ", $authorLinks) .
            "</div>";
    }

    // Titel (wird im UI oft schon als Link angezeigt, aber hier nochmal als Text?)
    // In msuche.php wird 'title' für den Link genutzt.
    // Wir zeigen hier Zusatzinfos.

    // Quelle (Zeitschrift, Jahr, etc.)
    $sourceStr = "";
    if ($source !== "") {
        $sourceStr .=
            "In: <em>" .
            htmlspecialchars($source, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") .
            "</em>";
    }
    if ($volume !== "") {
        $sourceStr .=
            ", Jg. " .
            htmlspecialchars($volume, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
    }
    if ($issue !== "") {
        $sourceStr .=
            ", Heft " .
            htmlspecialchars($issue, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
    }
    if ($year !== "") {
        $sourceStr .=
            " (" .
            htmlspecialchars($year, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") .
            ")";
    }
    if ($pages !== "") {
        $sourceStr .=
            ", " .
            htmlspecialchars($pages, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
    }

    if ($sourceStr !== "") {
        $html .= '<div class="source-info">' . $sourceStr . "</div>";
    }

    // Signatur und ID
    $sigStr = "";
    if ($sig !== "") {
        $sigStr .=
            '<span class="badge">SIG: ' .
            htmlspecialchars($sig, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") .
            "</span> ";
    }
    if ($isbn !== "") {
        $sigStr .=
            '<span class="badge" style="background: transparent; color: var(--text-muted);">ISBN/ISSN: ' .
            htmlspecialchars($isbn, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") .
            "</span>";
    }

    if ($sigStr !== "") {
        $html .=
            '<div style="margin-top: 6px; margin-bottom: 8px;">' .
            $sigStr .
            "</div>";
    }

    // Abstract
    if ($abstract !== "") {
        $html .=
            '<div class="abstract-text"><strong>Abstract:</strong> ' .
            htmlspecialchars($abstract, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") .
            "</div>";
    }

    $html .= "</div>";

    // Debug / Alle Felder (togglebar)
    $html .= '<details class="record-details">';
    $html .= "<summary>Alle Felder anzeigen</summary>";
    $html .= '<div class="details-content">';
    foreach ($fields as $name => $value) {
        if (substr($name, 0, 1) === "@") {
            continue;
        } // Interne Felder ausblenden
        $html .=
            '<div class="field-row"><span class="field-label">' .
            htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") .
            ":</span> " .
            htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") .
            "</div>";
    }
    $html .= "</div></details>";

    return [
        "title" => (string) $title,
        "html" => $html,
    ];
}

/**
 * Einfache Such-Statistik in data/midos/stat.log.
 */
function log_search(string $query, int $hits): void
{
    global $DATA_DIR;
    $file = $DATA_DIR . DIRECTORY_SEPARATOR . "stat.log";

    $user = $_SESSION["username"] ?? "";
    $time = date("Y-m-d H:i:s");
    $line = implode("\t", [$time, $user, $query, (string) $hits]) . "\n";

    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Vertrag für alle Bestands-Klassen (MidosIndex und BibLibrary).
 * Beide implementieren dieselbe API, sodass Suche/Browsing/Notizen
 * datenquellenunabhängig funktionieren.
 */
interface OpacLibrary
{
    /**
     * Bool-Suche (AND/OR/NOT) in einem Feld.
     * $index: Feldname ('qp','qt','qs','qj','qy','qa','q') oder Legacy-Nummer (1,2,4,6,7).
     * Rückgabe: Liste von doc-IDs.
     */
    public function searchBoolean(string $query, int|string $index): array;

    /** Terme eines Registers für A-Z-Browsing: [['term'=>..,'count'=>..], ...] */
    public function getTerms(int|string $index, string $prefix, int $limit = 100): array;

    public function getTermCount(int|string $index): int;

    public function getTermsByOffset(int|string $index, int $offset, int $limit = 50): array;

    /**
     * Normalisierter Datensatz: id, source, title, subtitle, authors, editors,
     * journal, year, volume, issue, pages, publisher, location, url, doi,
     * isbn_issn, abstract, keywords, fields, alltext, html.
     */
    public function getRecord(int $docId): ?array;

    /** Record ohne HTML-Erzeugung (für Filter): id, title, alltext, abstract, ... */
    public function getRecordLight(int $docId): ?array;

    /**
     * Sortierschlüssel (Autor, Jahr, Titel) für eine Menge von Datensatz-IDs.
     * Bewusst ohne HTML und ohne Datensatz-Objekte: eine Sortierung darf nicht
     * den ganzen Bestand laden, sonst wird sie bei großen Beständen zur
     * Belastung. Fehlende Werte kommen als leere Zeichenkette zurück.
     *
     * @param int[] $ids
     * @return array<int,array{author:string,year:string,title:string}> id => Schlüssel
     */
    public function sortKeys(array $ids): array;

    /** Leichte Iteration über den Bestand: ['id','title','alltext','abstract'] */
    public function iterateLight(): Traversable;

    public function countRecords(): int;

    /**
     * Bestands-Metadaten für die Anzeige (rein lesend, ohne Import-Aktion):
     *   last_sync  int|null  Unix-Zeitstempel des letzten Abgleichs, null = unbekannt
     *   file       string    Name der zuletzt importierten Quelldatei
     *   file_size  int       Größe dieser Datei in Bytes, 0 = unbekannt
     *   pending    array|null ausstehender Abgleich (siehe pendingImport())
     *
     * @return array{last_sync:int|null,file:string,file_size:int,pending:array|null}
     */
    public function stockInfo(): array;
}

/**
 * Factory für den aktiven Bestand ($DATA_SOURCE).
 */
function get_opac_library(): OpacLibrary
{
    static $lib = null;
    if ($lib !== null) {
        return $lib;
    }
    global $DATA_SOURCE, $DATA_DIR, $BIB_DIR;
    if (($DATA_SOURCE ?? "bibtex") === "bibtex") {
        require_once __DIR__ . "/BibLibrary.php";
        $lib = new BibLibrary($BIB_DIR);
    } else {
        require_once __DIR__ . "/MidosIndex.php";
        $lib = new MidosIndex($DATA_DIR);
    }
    return $lib;
}

/**
 * Datensatz anhand ID aus dem aktiven Bestand (mit HTML-Darstellung).
 */
function opac_record(int $id): ?array
{
    return get_opac_library()->getRecord($id);
}

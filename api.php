<?php
declare(strict_types=1);

/**
 * JSON-API des OPAC (api.php)
 * ===========================
 *
 * Fernabfragen des Bestands ohne das HTML-Frontend – für Skripte, R, Python,
 * cron-Jobs oder eigene Anwendungen. Alle Antworten sind UTF-8-JSON.
 *
 * Aufruf (Routen über ?action=…):
 *   GET api.php?action=search&q=Digitalisierung&limit=20
 *   GET api.php?action=search&qt=Hochschule&sort=year_desc&offset=40&limit=20
 *   GET api.php?action=record&id=123
 *   GET api.php?action=records&ids=12,15,99
 *   GET api.php?action=index&idx=qp&prefix=Sch&limit=50
 *   GET api.php?action=stats
 *
 * Authentifizierung
 * -----------------
 * Optional per API-Key. Ist OPAC_API_KEY konfiguriert (.env oder Umgebung),
 * muss er bei jeder Anfrage mitgeliefert werden – sonst antwortet die API mit
 * HTTP 401. Übergabe (eine der Varianten genügt):
 *   Header:   X-API-Key: <key>
 *   Header:   Authorization: Bearer <key>
 *   Query:    ?key=<key>
 * Ist kein Key konfiguriert, ist die API offen (nur lesend, fail-open).
 *
 * Antwortformat
 * -------------
 *   Erfolg:  {"ok":true,"data":…,"meta":{…}}
 *   Fehler:  {"ok":false,"error":{"code":…,"message":…}}
 * Die HTTP-Statuszeile trägt denselben Zustand (200/400/401/404/405/500).
 *
 * Diese Datei ist rein lesend: es werden keine Datensätze geändert und keine
 * Importe gestartet. Bestandsänderungen laufen weiterhin über mimport.php
 * bzw. import_bibtex.php (CLI).
 */

require __DIR__ . '/config.php';

// ------------------------------------------------------------------ Helfer
/** Beendet die Anfrage mit einer JSON-Fehlerantwort und passendem HTTP-Code. */
function api_fail(int $status, string $code, string $message): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        ['ok' => false, 'error' => ['code' => $code, 'message' => $message]],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

/** Beendet die Anfrage mit einer JSON-Erfolgsantwort. */
function api_ok(array $data, array $meta = []): never
{
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    $payload = ['ok' => true, 'data' => $data];
    if ($meta !== []) {
        $payload['meta'] = $meta;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Liest einen Integer-Parameter, optional mit Unter-/Obergrenze. */
function api_int(string $key, int $default, int $min, int $max): int
{
    $raw = req($key);
    if ($raw === null || $raw === '' || !is_numeric($raw)) {
        return $default;
    }
    return max($min, min($max, (int) $raw));
}

/** CORS für Lesezugriffe aus fremden Anwendungen (nur GET). */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: X-API-Key, Authorization, Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    api_fail(405, 'method_not_allowed', 'Nur GET-Anfragen sind erlaubt.');
}

// ------------------------------------------------------------- API-Key
// Reihenfolge: Header X-API-Key -> Authorization: Bearer -> Query ?key=.
$providedKey = null;
$headerKey = $_SERVER['HTTP_X_API_KEY'] ?? null;
if (is_string($headerKey) && $headerKey !== '') {
    $providedKey = $headerKey;
} else {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null);
    if (is_string($auth) && preg_match('/^Bearer\s+(.+)$/i', trim($auth), $m)) {
        $providedKey = trim($m[1]);
    } elseif (req('key') !== null && req('key') !== '') {
        $providedKey = req('key');
    }
}
if (!check_api_key($providedKey)) {
    api_fail(401, 'unauthorized', 'Ungültiger oder fehlender API-Key.');
}

// -------------------------------------------------------------- Routing
$action = (string) req('action', 'search');
$lib = get_opac_library();
$isBib = $lib instanceof BibLibrary;

switch ($action) {
    // --------------------------------------------------------------- search
    case 'search':
        $terms = [];
        foreach (opac_search_fields() as $f) {
            $terms[$f] = (string) req($f, '');
        }
        if (implode('', $terms) === '') {
            api_fail(400, 'no_criteria', 'Es wurde kein Suchkriterium angegeben (mindestens ein Feld q/qt/qa/qp/qj/qs/qy muss gesetzt sein).');
        }

        $sort = (string) req('sort', 'relevance');
        $validSorts = [
            'relevance', 'author_asc', 'author_desc',
            'year_asc', 'year_desc', 'title_asc', 'title_desc',
        ];
        if (!in_array($sort, $validSorts, true)) {
            api_fail(400, 'bad_sort', 'Ungültige Sortierung. Erlaubt: ' . implode(', ', $validSorts) . '.');
        }

        // MIDOS kennt Freitext/Abstract nicht als Register – früh und klar melden.
        if (!$isBib && (trim($terms['q']) !== '' || trim($terms['qa']) !== '')) {
            api_fail(400, 'field_not_supported', 'Die Felder q (Freitext) und qa (Zusammenfassung) stehen im MIDOS-Bestand nicht zur Verfügung.');
        }

        $opts = [
            'sort' => $sort,
            'limit' => api_int('limit', 20, 0, 1000),
            'offset' => api_int('offset', 0, 0, PHP_INT_MAX),
            'in' => (string) req('in', ''),
            'inf' => (string) req('inf', 'q'),
        ];

        $res = opac_search_result($terms, $opts);

        // Bestands-Metadaten für die Antwort (nur lesend, ohne Import).
        $stock = $lib->stockInfo();
        api_ok(
            [
                'total' => $res['total'],
                'count' => $res['count'],
                'offset' => $res['offset'],
                'limit' => $res['limit'],
                'ids' => $res['ids'],
                'records' => $res['records'],
            ],
            [
                'query' => array_filter($terms, static fn (string $v): bool => $v !== ''),
                'sort' => $sort,
                'refine' => $opts['in'] !== '' ? ['expression' => $opts['in'], 'field' => $opts['inf']] : null,
                'source' => $isBib ? 'bibtex' : 'midos',
                'stock_total' => $lib->countRecords(),
                'last_sync' => $stock['last_sync'],
                'stock_file' => $stock['file'],
            ]
        );
        // no break (api_ok beendet)

    // --------------------------------------------------------------- record
    case 'record':
        $id = api_int('id', 0, 0, PHP_INT_MAX);
        if ($id <= 0) {
            api_fail(400, 'bad_id', 'Parameter "id" fehlt oder ist ungültig.');
        }
        $rec = $lib->getRecord($id);
        if ($rec === null) {
            api_fail(404, 'not_found', 'Kein Datensatz mit dieser ID im Bestand.');
        }
        api_ok(opac_record_public($rec), ['source' => $isBib ? 'bibtex' : 'midos']);

    // -------------------------------------------------------------- records
    case 'records':
        $raw = (string) req('ids', '');
        if ($raw === '') {
            api_fail(400, 'bad_ids', 'Parameter "ids" fehlt (kommagetrennte Liste von Datensatz-IDs).');
        }
        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part)) {
                $ids[] = (int) $part;
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            api_fail(400, 'bad_ids', 'Keine gültigen Datensatz-IDs übergeben.');
        }
        if (count($ids) > 500) {
            api_fail(400, 'too_many_ids', 'Höchstens 500 IDs je Anfrage.');
        }
        $out = [];
        foreach ($ids as $id) {
            $rec = $lib->getRecord($id);
            if ($rec === null) {
                continue;
            }
            $out[] = opac_record_public($rec);
        }
        api_ok(
            ['requested' => count($ids), 'count' => count($out), 'records' => $out],
            ['source' => $isBib ? 'bibtex' : 'midos']
        );

    // ---------------------------------------------------------------- index
    case 'index':
        // Register: qp=Personen, qt=Titel(Wörter), qs=Schlagwörter,
        //           qj=Zeitschriften, qy=Jahre.
        $idx = (string) req('idx', 'qp');
        $valid = ['qp', 'qt', 'qs', 'qj', 'qy'];
        if (!in_array($idx, $valid, true)) {
            api_fail(400, 'bad_index', 'Ungültiges Register. Erlaubt: ' . implode(', ', $valid) . '.');
        }
        $prefix = (string) req('prefix', '');
        $limit = api_int('limit', 50, 1, 500);
        $offset = api_int('offset', 0, 0, PHP_INT_MAX);

        $terms = $lib->getTerms($idx, $prefix, 999999);
        $totalTerms = count($terms);
        $page = array_slice($terms, $offset, $limit);

        $out = [];
        foreach ($page as $t) {
            $out[] = [
                'term' => (string) ($t['term'] ?? ''),
                'count' => (int) ($t['count'] ?? 0),
            ];
        }
        api_ok(
            [
                'index' => $idx,
                'prefix' => $prefix,
                'offset' => $offset,
                'limit' => $limit,
                'total' => $totalTerms,
                'count' => count($out),
                'terms' => $out,
            ],
            ['source' => $isBib ? 'bibtex' : 'midos']
        );

    // ---------------------------------------------------------------- stats
    case 'stats':
        $stock = $lib->stockInfo();
        api_ok(
            [
                'source' => $isBib ? 'bibtex' : 'midos',
                'total' => $lib->countRecords(),
                'last_sync' => $stock['last_sync'],
                'last_sync_iso' => $stock['last_sync'] !== null ? date('c', (int) $stock['last_sync']) : null,
                'file' => $stock['file'],
                'file_size' => $stock['file_size'],
                'pending_import' => $stock['pending'],
            ]
        );

    default:
        api_fail(404, 'unknown_action', 'Unbekannte Aktion. Erlaubt: search, record, records, index, stats.');
}

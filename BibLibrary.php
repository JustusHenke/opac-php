<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/BibTeXParser.php';

/**
 * Class BibLibrary
 *
 * Neuer Bestand des OPAC: BibTeX-Dateien unter data/bib/.
 *
 * Prinzip (Reconcile): Die NEUESTE .bib-Datei bildet immer den Vollbestand ab
 * und ist die Quelle der Wahrheit. Ein Import (sync) stellt sicher, dass die
 * SQLite-Datenbank exakt dem Inhalt dieser Datei entspricht:
 *  - neue Einträge  -> INSERT
 *  - geänderte      -> UPDATE (ID bleibt stabil, Notizen/Warenkorb bleiben gültig)
 *  - entfernte      -> DELETE ("Datenbank bereinigen")
 * Das Delta wird nur als Statistik ermittelt (neu/aktualisiert/entfernt/gleich),
 * die Korrektheit hängt nie davon ab, ob ein Zuverlässiges Delta bildbar ist.
 * Für den Übergang MIDOS -> BibTeX kann per Vollimport (force) alles neu
 * importiert werden.
 */
class BibLibrary implements OpacLibrary
{
    /** Logdatei für Import-Läufe (Dubletten, Entfernungen, Kennzahlen). */
    private const IMPORT_LOG = 'import.log';

    /**
     * Obergrenze der Detailzeilen je Kategorie und Lauf. Verhindert, dass ein
     * pathologischer Bestand (z. B. dieselbe Datei 100.000-fach) das Log
     * unbrennbar groß macht; die Gesamtzahl wird immer korrekt geloggt.
     */
    private const IMPORT_LOG_MAX_DETAIL = 20000;

    /**
     * Version der Fingerprint-Formel (siehe fingerprint()). Ändert sich die
     * Dublettenprüfung, wird dieser Wert erhöht; der nächste Import rechnet
     * dann die gespeicherten Fingerprints des Bestands einmalig auf die neue
     * Formel um (migrateFingerprints()) – sonst würden sämtliche Bestände als
     * neu erkannt und doppelt eingefügt.
     */
    private const FP_VERSION = '2';

    private string $bibDir;
    private ?PDO $db = null;

    /** @var resource|null Datei-Handle des Sync-Locks (data/bib/sync.lock) */
    private $lockHandle = null;

    public function __construct(string $bibDir)
    {
        $this->bibDir = rtrim($bibDir, '/\\');
        if (!is_dir($this->bibDir)) {
            @mkdir($this->bibDir, 0775, true);
        }
        $this->ensureFresh();
    }

    // ================================================================ Bestand/Import

    /**
     * Große Bestände werden NICHT mehr automatisch beim Seitenaufruf importiert.
     *
     * Grund: der Abgleich ist ein schwerer, synchroner Vorgang. Bei einem
     * Bestand von mehreren 10.000 Einträgen läuft er in ein Request-Timeout
     * oder memory_limit und die Seite antwortet mit HTTP 500 – bisher trat das
     * genau deshalb beim Login (Gastzugang) auf, weil die erste Seite nach dem
     * Login den Bestand lädt. Stattdessen wird der Bedarf nur markiert
     * (meta 'sync_pending') und der Import manuell über mimport.php bzw.
     * `php import_bibtex.php` angestoßen.
     *
     * Kleine Bestände bleiben bequem: bis AUTO_SYNC_MAX_BYTES wird weiterhin
     * automatisch importiert (Obergrenze per OPAC_AUTO_SYNC_MAX_BYTES
     * überschreibbar, 0 = nie).
     */
    private const AUTO_SYNC_MAX_BYTES = 8388608; // 8 MB

    /** @return int Obergrenze für den Auto-Import in Bytes (0 = aus). */
    private function autoSyncLimit(): int
    {
        $env = getenv('OPAC_AUTO_SYNC_MAX_BYTES');
        if ($env !== false && $env !== '' && ctype_digit($env)) {
            return (int) $env;
        }
        return self::AUTO_SYNC_MAX_BYTES;
    }

    /**
     * Erzwingt beim ersten Zugriff einen Abgleich, wenn sich die neueste .bib geändert hat.
     * Kleine Dateien werden automatisch importiert, große nur markiert (siehe
     * AUTO_SYNC_MAX_BYTES) – ein Seitenaufruf darf nie einen Import starten,
     * der an Time-/Memory-Limits scheitert.
     */
    private function ensureFresh(): void
    {
        try {
            $newest = $this->findNewestBib();
            if ($newest === null) {
                return;
            }

            // Schnellpfad: Datei ist unangetastet (gleicher Name/mtime/Größe) –
            // dann genügt ein Meta-Vergleich, ohne die ganze Datei zu hashen.
            if ($this->getMeta('last_file') === $newest['name']
                && $this->getMeta('last_file_mtime') === (string) $newest['mtime']
                && $this->getMeta('last_file_size') === (string) $newest['size']) {
                return;
            }

            if (md5_file($newest['path']) === $this->getMeta('last_file_md5')) {
                return; // nur mtime/Name aktualisiert, Inhalt identisch
            }

            $limit = $this->autoSyncLimit();
            if ($limit > 0 && $newest['size'] > $limit) {
                // Zu groß für einen Auto-Import im Request: markieren, nicht blockieren.
                $this->setMeta(
                    'sync_pending',
                    json_encode([
                        'file' => $newest['name'],
                        'size' => $newest['size'],
                        'mtime' => $newest['mtime'],
                        'since' => date('c'),
                    ], JSON_UNESCAPED_UNICODE)
                );
                return;
            }

            $this->sync();
            $this->setMeta('sync_pending', '');
        } catch (Throwable $e) {
            $this->setMeta('last_error', date('c') . ' – ' . $e->getMessage());
        }
    }

    /** Liefert die neueste .bib-Datei (mtime, dann Name) oder null. */
    public function findNewestBib(): ?array
    {
        $files = glob($this->bibDir . DIRECTORY_SEPARATOR . '*.bib') ?: [];
        if ($files === []) {
            return null;
        }
        usort($files, function (string $a, string $b): int {
            $ma = filemtime($a) ?: 0;
            $mb = filemtime($b) ?: 0;
            return $mb === $ma ? strcmp($b, $a) : $mb - $ma; // neueste zuerst
        });
        $path = $files[0];
        return [
            'path' => $path,
            'name' => basename($path),
            'mtime' => filemtime($path) ?: 0,
            'size' => filesize($path) ?: 0,
        ];
    }

    /**
     * Führt den Abgleich (Reconcile) mit der neuesten .bib-Datei aus.
     *
     * @param bool $forceFull true = Vollimport (Tabelle leeren, alles neu)
     * @return array Statistik: status, file, parsed, unique, inserted, updated, unchanged, removed, duplicates, errors
     */
    public function sync(bool $forceFull = false): array
    {
        if (!$this->acquireLock()) {
            return ['status' => 'busy', 'message' => 'Es läuft bereits ein Import.'];
        }
        try {
            return $this->doSync($forceFull);
        } finally {
            $this->releaseLock();
        }
    }

    /** Setzt ein nicht-blockierendes Exklusiv-Lock; false, wenn bereits ein Import läuft. */
    private function acquireLock(): bool
    {
        $fh = @fopen($this->bibDir . DIRECTORY_SEPARATOR . 'sync.lock', 'c');
        if ($fh === false) {
            return true; // Sperrdatei nicht beschreibbar -> nicht blockieren
        }
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            return false;
        }
        $this->lockHandle = $fh;
        return true;
    }

    private function releaseLock(): void
    {
        if ($this->lockHandle !== null) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }

    /** Eigentlicher Abgleich; wird nur von sync() unter Lock ausgeführt. */
    private function doSync(bool $forceFull = false): array
    {
        $db = $this->getDb();
        $newest = $this->findNewestBib();
        if ($newest === null) {
            $this->writeImportLog([sprintf('[%s] Import nicht ausgeführt: Keine .bib-Datei unter data/bib gefunden.', date('Y-m-d H:i:s'))]);
            return ['status' => 'no_file', 'message' => 'Keine .bib-Datei unter data/bib gefunden.'];
        }

        $md5 = md5_file($newest['path']);
        if (!$forceFull && $md5 === $this->getMeta('last_file_md5')) {
            $this->writeImportLog([sprintf('[%s] Import übersprungen: %s ist unverändert (MD5 %s).', date('Y-m-d H:i:s'), $this->logSafe($newest['name']), $md5)]);
            return ['status' => 'up_to_date', 'file' => $newest['name']];
        }

        // Log-Kopf für diesen Lauf (Dubletten/Entfernungen folgen weiter unten).
        $logStarted = time();
        $dupLog = [];      // Dubletten innerhalb der Datei
        $removedLog = [];  // aus dem Bestand entfernte Datensätze
        $logLines = [
            str_repeat('=', 78),
            sprintf('[%s] Import gestartet', date('Y-m-d H:i:s', $logStarted)),
            sprintf('  Quelle   : %s (%s Bytes, geändert %s)',
                $this->logSafe($newest['name']),
                number_format($newest['size'], 0, ',', '.'),
                date('Y-m-d H:i', (int) $newest['mtime'])),
            sprintf('  MD5      : %s', $md5),
            sprintf('  Modus    : %s', $forceFull ? 'Vollimport (force)' : 'Abgleich mit neuester Datei'),
            sprintf('  Umgebung : PHP %s (%s), memory_limit=%s', PHP_VERSION, PHP_SAPI, (string) ini_get('memory_limit')),
            sprintf('  Bestand  : %s Datensätze vor dem Lauf', number_format($this->countRecords(), 0, ',', '.')),
            str_repeat('=', 78),
        ];

        // Ein Import kann bei großen Beständen Minuten dauern: Limit anheben,
        // soweit die Konfiguration es zulässt (CLI ist ohnehin unbegrenzt).
        if (PHP_SAPI !== 'cli') {
            @set_time_limit(0);
        }

        $parser = new BibTeXParser();
        // Streamend parsen: es ist immer nur ein Eintrag gleichzeitig im Speicher.
        // Vorher lag das komplette Parse-Ergebnis als Array im RAM – bei großen
        // Dateien (mehrfache Dateigröße im Speicher) scheiterte der Import an
        // memory_limit bzw. max_execution_time.
        $stream = $parser->stream((string) file_get_contents($newest['path']));

        $stats = [
            'status' => 'synced',
            'file' => $newest['name'],
            'parsed' => 0,
            'unique' => 0,
            'duplicates' => 0,
            'inserted' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'removed' => 0,
            'errors' => 0,
        ];

        $db->exec('PRAGMA busy_timeout = 30000');
        $db->beginTransaction();
        try {
            if ($forceFull) {
                $db->exec('DELETE FROM entries');
            }

            // Fingerprint-Formel geändert? Einmalig den Bestand umrechnen, BEVOR
            // die Vergleichstabelle geladen wird – sonst würde der Abgleich alle
            // Alt-Fingerprints als neue Einträge ansehen und den Bestand doppelt
            // einfügen.
            $this->migrateFingerprints($logLines);

            // Bestehende Fingerprints laden
            $byFp = [];
            foreach ($db->query('SELECT id, fingerprint, content_hash FROM entries', PDO::FETCH_ASSOC) as $row) {
                $byFp[$row['fingerprint']] = $row;
            }

            $insert = $db->prepare(
                'INSERT INTO entries (fingerprint, content_hash, citekey, entry_type, title, subtitle, year,
                    authors, authors_norm, journal, journal_norm, abstract, abstract_norm, keywords, keywords_norm,
                    doi, url, isbn_issn, language, alltext, alltext_norm, fields_json, source_file, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)'
            );
            $update = $db->prepare(
                'UPDATE entries SET content_hash=?, citekey=?, entry_type=?, title=?, subtitle=?, year=?,
                    authors=?, authors_norm=?, journal=?, journal_norm=?, abstract=?, abstract_norm=?, keywords=?,
                    keywords_norm=?, doi=?, url=?, isbn_issn=?, language=?, alltext=?, alltext_norm=?,
                    fields_json=?, source_file=?, updated_at=CURRENT_TIMESTAMP WHERE id=?'
            );

            // Duplikate innerhalb der Datei (anhand Fingerprint) überspringen:
            // der erste Treffer gewinnt – wie zuvor, nur ohne Zwischen-Array.
            $seenFps = [];
            foreach ($stream as $e) {
                $stats['parsed']++;
                $fp = $this->fingerprint($e);
                if (isset($seenFps[$fp])) {
                    $stats['duplicates']++;
                    if (count($dupLog) < self::IMPORT_LOG_MAX_DETAIL) {
                        // Erster Treffer behält den Datensatz, dieser wird verworfen.
                        $dupLog[] = sprintf('    %d. %s', $stats['duplicates'], $this->describeEntry($e, $fp));
                    }
                    continue;
                }
                $seenFps[$fp] = true;
                $stats['unique']++;

                $row = $this->toRow($e, $newest['name'], $fp);
                $ch = $this->contentHash($e);

                if (isset($byFp[$fp])) {
                    if ($byFp[$fp]['content_hash'] === $ch) {
                        $stats['unchanged']++;
                        continue;
                    }
                    $update->execute([
                        $ch, $row['citekey'], $row['entry_type'], $row['title'], $row['subtitle'], $row['year'],
                        $row['authors'], $row['authors_norm'], $row['journal'], $row['journal_norm'],
                        $row['abstract'], $row['abstract_norm'], $row['keywords'], $row['keywords_norm'],
                        $row['doi'], $row['url'], $row['isbn_issn'], $row['language'],
                        $row['alltext'], $row['alltext_norm'], $row['fields_json'], $row['source_file'],
                        $byFp[$fp]['id'],
                    ]);
                    $stats['updated']++;
                } else {
                    $insert->execute([
                        $fp, $ch, $row['citekey'], $row['entry_type'], $row['title'], $row['subtitle'], $row['year'],
                        $row['authors'], $row['authors_norm'], $row['journal'], $row['journal_norm'],
                        $row['abstract'], $row['abstract_norm'], $row['keywords'], $row['keywords_norm'],
                        $row['doi'], $row['url'], $row['isbn_issn'], $row['language'],
                        $row['alltext'], $row['alltext_norm'], $row['fields_json'], $row['source_file'],
                    ]);
                    $stats['inserted']++;
                }
            }
            $stats['errors'] = count($parser->errors);
            unset($stream);

            // Bereinigen: Einträge, die in der neuesten Datei nicht mehr vorkommen.
            // Die Quelldatei ist die Quelle der Wahrheit: was dort fehlt, fliegt raus.
            $db->exec('CREATE TEMP TABLE IF NOT EXISTS keep_fp (fp TEXT PRIMARY KEY)');
            $db->exec('DELETE FROM keep_fp');
            $ins = $db->prepare('INSERT OR IGNORE INTO keep_fp (fp) VALUES (?)');
            foreach (array_keys($seenFps) as $fp) {
                $ins->execute([$fp]);
            }
            // Vor dem DELETE werden die betroffenen Datensätze für das Log gelesen
            // (gestreamt, nicht gepuffert – sonst wächst der Speicherbedarf).
            $removedStmt = $db->query(
                'SELECT id, fingerprint, citekey, title, year, doi, source_file
                   FROM entries WHERE fingerprint NOT IN (SELECT fp FROM keep_fp)'
            );
            $stats['removed'] = 0;
            foreach ($removedStmt as $rm) {
                $stats['removed']++;
                if (count($removedLog) < self::IMPORT_LOG_MAX_DETAIL) {
                    $removedLog[] = sprintf('    %d. id=%d | Titel: %s | Jahr: %s | Citekey: %s | DOI: %s | Datei: %s',
                        $stats['removed'],
                        (int) $rm['id'],
                        $this->logSafe((string) ($rm['title'] ?? ''), 160) ?: '(ohne Titel)',
                        $this->logSafe((string) ($rm['year'] ?? ''), 20) ?: '-',
                        $this->logSafe((string) ($rm['citekey'] ?? ''), 80) ?: '-',
                        $this->logSafe((string) ($rm['doi'] ?? ''), 80) ?: '-',
                        $this->logSafe((string) ($rm['source_file'] ?? ''), 80) ?: '-'
                    );
                }
            }
            $removedStmt->closeCursor();
            $db->exec('DELETE FROM entries WHERE fingerprint NOT IN (SELECT fp FROM keep_fp)');

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            $this->setMeta('last_error', date('c') . ' – ' . $e->getMessage());
            $this->writeImportLog(array_merge($logLines, [
                sprintf('[%s] Import ABGEBROCHEN – Rollback ausgeführt', date('Y-m-d H:i:s')),
                sprintf('  Fehler  : %s: %s', get_class($e), $e->getMessage()),
                sprintf('  Dauer   : %s', $this->formatDuration(time() - $logStarted)),
            ]));
            throw $e;
        }

        $this->rebuildTitleIndex();

        $stats['total'] = $this->countRecords();
        $this->setMeta('last_file', $newest['name']);
        $this->setMeta('last_file_mtime', (string) $newest['mtime']);
        $this->setMeta('last_file_size', (string) $newest['size']);
        $this->setMeta('last_file_md5', $md5);
        $this->setMeta('last_sync', date('Y-m-d H:i:s'));
        $this->setMeta('last_stats', json_encode($stats, JSON_UNESCAPED_UNICODE));
        $this->setMeta('sync_pending', '');
        $this->setMeta('last_error', '');

        // Dubletten/Entfernungen und Kennzahlen protokollieren.
        $logLines[] = sprintf('[%s] Dubletten in der Quelldatei (übersprungen, erster Treffer behalten): %s',
            date('Y-m-d H:i:s'), number_format($stats['duplicates'], 0, ',', '.'));
        $logLines = array_merge($logLines, $dupLog);
        if ($stats['duplicates'] > count($dupLog)) {
            $logLines[] = sprintf('    … %s weitere Dubletten nicht einzeln aufgeführt (Log-Obergrenze).',
                number_format($stats['duplicates'] - count($dupLog), 0, ',', '.'));
        }
        if ($stats['duplicates'] === 0) {
            $logLines[] = '    (keine)';
        }

        $logLines[] = sprintf('[%s] Aus dem Bestand entfernt (nicht mehr in der Quelldatei enthalten): %s',
            date('Y-m-d H:i:s'), number_format($stats['removed'], 0, ',', '.'));
        $logLines = array_merge($logLines, $removedLog);
        if ($stats['removed'] > count($removedLog)) {
            $logLines[] = sprintf('    … %s weitere Datensätze nicht einzeln aufgeführt (Log-Obergrenze).',
                number_format($stats['removed'] - count($removedLog), 0, ',', '.'));
        }
        if ($stats['removed'] === 0) {
            $logLines[] = '    (keine)';
        }

        $logLines[] = sprintf('[%s] Zusammenfassung: parsed=%s unique=%s duplicates=%s inserted=%s updated=%s unchanged=%s removed=%s total=%s errors=%s | Dauer: %s',
            date('Y-m-d H:i:s'),
            number_format($stats['parsed'], 0, ',', '.'),
            number_format($stats['unique'], 0, ',', '.'),
            number_format($stats['duplicates'], 0, ',', '.'),
            number_format($stats['inserted'], 0, ',', '.'),
            number_format($stats['updated'], 0, ',', '.'),
            number_format($stats['unchanged'], 0, ',', '.'),
            number_format($stats['removed'], 0, ',', '.'),
            number_format($stats['total'], 0, ',', '.'),
            number_format($stats['errors'], 0, ',', '.'),
            $this->formatDuration(time() - $logStarted)
        );
        $this->writeImportLog($logLines);

        return $stats;
    }

    /** Pfad der Import-Logdatei (data/bib/import.log, webgeschützt über data/.htaccess). */
    public function importLogPath(): string
    {
        return $this->bibDir . DIRECTORY_SEPARATOR . self::IMPORT_LOG;
    }

    /**
     * Hängt einen Block an die Import-Logdatei an (Zeitstempel je Lauf in der
     * Kopfzeile). Fehler beim Schreiben dürfen den Import nie abbrechen.
     *
     * @param string[] $lines
     */
    private function writeImportLog(array $lines): void
    {
        if ($lines === []) {
            return;
        }
        $text = '';
        foreach ($lines as $line) {
            // Einrückung bleibt erhalten, erst der Text wird normalisiert.
            $body = ltrim($line, " \t");
            $indent = substr($line, 0, strlen($line) - strlen($body));
            $text .= $indent . $this->logSafe($body, 4000) . "\n";
        }
        if (@file_put_contents($this->importLogPath(), $text, FILE_APPEND | LOCK_EX) === false) {
            error_log('[BibLibrary] Import-Log nicht schreibbar: ' . $this->importLogPath());
        }
    }

    /**
     * Log-taugliche Einzeilermeldung: Steuerzeichen/Umbrüche entfernen,
     * Whitespace normalisieren, Länge begrenzen. Ticker und unmaskierte
     * LaTeX-Sonderzeichen bleiben möglichst erhalten.
     */
    private function logSafe(string $text, int $max = 240): string
    {
        $t = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);
        if ($t === null) { // ungültiges UTF-8 -> ohne /u erneut versuchen
            $t = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text) ?? '';
        }
        $w = preg_replace('/\s+/u', ' ', $t);
        if ($w === null) {
            $w = preg_replace('/\s+/', ' ', $t) ?? $t;
        }
        $w = trim($w);
        if ($max > 0 && mb_strlen($w) > $max) {
            $w = mb_substr($w, 0, $max) . '…';
        }
        return $w;
    }

    /** Kurzbeschreibung eines geparsten Eintrags für die Dubletten-Protokollierung. */
    private function describeEntry(array $e, string $fingerprint): string
    {
        $f = $e['fields'] ?? [];
        $authors = $this->splitNames((string) ($f['author'] ?? ($f['editor'] ?? '')));
        $doi = strtolower(trim(preg_replace('~^https?://(dx\.)?doi\.org/~i', '', $f['doi'] ?? '') ?? ''));
        return sprintf(
            'Titel: %s | Jahr: %s | Erstautor: %s | DOI: %s | Typ: %s | Citekey: %s | Fingerprint: %s',
            $this->logSafe((string) ($f['title'] ?? ''), 160) ?: '(ohne Titel)',
            $this->extractYear($f) ?: '-',
            $this->logSafe($authors[0] ?? '', 80) ?: '-',
            $doi !== '' ? $doi : '-',
            $this->logSafe((string) ($e['type'] ?? ''), 30) ?: '-',
            $this->logSafe((string) ($e['citekey'] ?? ''), 80) ?: '-',
            $fingerprint
        );
    }

    /** Sekunden als lesbare Dauer ("20.3 s", "1 min 04 s"). */
    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return number_format((float) $seconds, 1, ',', '.') . ' s';
        }
        return sprintf('%d min %02d s', intdiv($seconds, 60), $seconds % 60);
    }

    /** Erzeugt die Spaltenwerte für entries aus einem geparsten Eintrag. */
    private function toRow(array $e, string $file, string $fp): array
    {
        $f = $e['fields'];
        $title = trim($f['title'] ?? '');
        $subtitle = trim($f['subtitle'] ?? '');
        $authors = $this->splitNames($f['author'] ?? '');
        $editors = $this->splitNames($f['editor'] ?? '');
        $journal = trim($f['journaltitle'] ?? ($f['journal'] ?? ($f['booktitle'] ?? '')));
        $abstract = trim($f['abstract'] ?? '');
        $keywords = trim($f['keywords'] ?? '');
        $doi = strtolower(trim(preg_replace('~^https?://(dx\.)?doi\.org/~i', '', $f['doi'] ?? '') ?? ''));
        $url = trim($f['url'] ?? '');
        $isbnIssn = trim($f['isbn'] ?? ($f['issn'] ?? ''));
        $language = trim($f['language'] ?? ($f['langid'] ?? ''));
        $year = $this->extractYear($f);

        $allNames = array_merge($authors, $editors);
        $alltextParts = array_filter([
            $title, $subtitle, implode(' ', $allNames), $journal, $abstract, $keywords,
            $doi, $url, $isbnIssn, $language,
            trim($f['publisher'] ?? ''), trim($f['location'] ?? ($f['address'] ?? '')),
            trim($f['note'] ?? ''), trim($f['organization'] ?? ''),
        ], static fn (string $s): bool => $s !== '');

        return [
            'fingerprint' => $fp,
            'citekey' => $e['citekey'],
            'entry_type' => $e['type'],
            'title' => $title,
            'subtitle' => $subtitle,
            'year' => $year,
            'authors' => implode('|', $authors),
            'authors_norm' => implode('|', array_map(fn (string $n): string => $this->normalizeTerm($n), $allNames)),
            'journal' => $journal,
            'journal_norm' => $this->normalizeTerm($journal),
            'abstract' => $abstract,
            'abstract_norm' => $this->normText($abstract),
            'keywords' => $keywords,
            'keywords_norm' => $this->normText($keywords),
            'doi' => $doi,
            'url' => $url,
            'isbn_issn' => $isbnIssn,
            'language' => $language,
            'alltext' => implode(' ', $alltextParts),
            'alltext_norm' => $this->normText(implode(' ', $alltextParts)),
            'fields_json' => json_encode($e['fields'], JSON_UNESCAPED_UNICODE),
            'source_file' => $file,
        ];
    }

    /** Autor-String ("A, B and C, D") -> Anzeigenamen-Liste ("A, B", "C, D"). */
    private function splitNames(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }
        $raw = BibTeXParser::decodeLatex($raw);
        $out = [];
        foreach (preg_split('/\s+and\s+/i', $raw) ?: [] as $n) {
            $n = trim(str_replace(['{', '}'], '', $n));
            if ($n !== '') {
                $out[] = $n;
            }
        }
        return $out;
    }

    private function extractYear(array $f): string
    {
        $raw = trim($f['date'] ?? ($f['year'] ?? ''));
        if (preg_match('/(\d{4})/', $raw, $m)) {
            return $m[1];
        }
        return '';
    }

    /**
     * Einmalige Neuberechnung aller gespeicherten Fingerprints, wenn sich die
     * Fingerprint-Formel geändert hat (FP_VERSION). Läuft innerhalb der
     * Import-Transaktion, direkt vor dem Laden der Vergleichstabelle.
     *
     * Kollidieren dabei Bestandszeilen unter den neuen (schärferen) Kriterien,
     * handelt es sich um echte Dubletten: der ältere Datensatz (kleinste ID)
     * bleibt, die übrigen werden entfernt und protokolliert.
     *
     * @param string[] $logLines Import-Log, wird per Referenz ergänzt
     */
    private function migrateFingerprints(array &$logLines): void
    {
        if ($this->getMeta('fingerprint_version') === self::FP_VERSION) {
            return;
        }

        $db = $this->getDb();
        $upd = $db->prepare('UPDATE entries SET fingerprint = ? WHERE id = ?');
        $del = $db->prepare('DELETE FROM entries WHERE id = ?');
        $sel = $db->prepare('SELECT id, fingerprint, citekey, entry_type, title, year, fields_json
                               FROM entries WHERE id = ?');

        // IDs vorab lesen (klein): vermeidet fetch-while-write auf derselben
        // Verbindung – SQLite garantiert das nicht zuverlässig.
        $ids = array_map('intval', array_column(
            $db->query('SELECT id FROM entries ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            'id'
        ));

        $seen = [];
        $recomputed = 0;
        $collisions = [];
        foreach ($ids as $id) {
            $sel->execute([$id]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);
            $sel->closeCursor();
            if ($row === false) {
                continue;
            }
            $e = [
                'citekey' => (string) ($row['citekey'] ?? ''),
                'type' => (string) ($row['entry_type'] ?? ''),
                'fields' => json_decode((string) ($row['fields_json'] ?? ''), true) ?: [],
            ];
            $newFp = $this->fingerprint($e);
            if (isset($seen[$newFp])) {
                // Echte Dublette nach den neuen Kriterien -> älteren Datensatz behalten.
                $del->execute([$id]);
                if (count($collisions) < self::IMPORT_LOG_MAX_DETAIL) {
                    $collisions[] = sprintf(
                        '    %d. id=%d | Typ: %s | Titel: %s | Jahr: %s | Citekey: %s',
                        count($collisions) + 1,
                        $id,
                        $this->logSafe((string) ($row['entry_type'] ?? ''), 40) ?: '-',
                        $this->logSafe((string) ($row['title'] ?? ''), 160) ?: '(ohne Titel)',
                        $this->logSafe((string) ($row['year'] ?? ''), 20) ?: '-',
                        $this->logSafe((string) ($row['citekey'] ?? ''), 80) ?: '-'
                    );
                }
                continue;
            }
            $seen[$newFp] = true;
            if ($newFp !== $row['fingerprint']) {
                $upd->execute([$newFp, $id]);
                $recomputed++;
            }
        }

        $this->setMeta('fingerprint_version', self::FP_VERSION);
        $logLines[] = sprintf(
            '[%s] Dublettenformel aktualisiert (Version %s): Fingerprints neu berechnet für %s Datensätze, %s Bestands-Dubletten entfernt.',
            date('Y-m-d H:i:s'),
            self::FP_VERSION,
            number_format($recomputed, 0, ',', '.'),
            number_format(count($collisions), 0, ',', '.')
        );
        $logLines = array_merge($logLines, $collisions);
        if (count($collisions) >= self::IMPORT_LOG_MAX_DETAIL) {
            $logLines[] = '    … weitere Kollisionen nicht einzeln aufgeführt (Log-Obergrenze).';
        }
        if ($collisions === []) {
            $logLines[] = '    (keine Kollisionen – der Bestand enthielt keine Dubletten nach den neuen Kriterien)';
        }
    }

    /**
     * Wiedererkennung über Importläufe hinweg: DOI wenn vorhanden, sonst
     * Eintragstyp + Titel + Jahr + Erstautor + Container.
     *
     * Eintragstyp und Container (Zeitschrift, Sammelband) gehören zur Identität:
     * Ein Buch, ein Buchteil (@incollection/@inbook) und ein Zeitschriftenaufsatz
     * können denselben Titel, dasselbe Jahr und denselben Erstautor haben – das
     * sind dann verschiedene Werke und keine Dubletten.
     *
     * Formelversion: FP_VERSION. Bei Änderungen an dieser Methode die Version
     * erhöhen – der Bestand wird dann beim nächsten Import einmalig neu
     * gerechnet (migrateFingerprints()).
     */
    private function fingerprint(array $e): string
    {
        $f = $e['fields'];
        $doi = strtolower(trim(preg_replace('~^https?://(dx\.)?doi\.org/~i', '', $f['doi'] ?? '') ?? ''));
        if ($doi !== '') {
            return 'doi:' . sha1($doi);
        }
        $title = $this->normText($f['title'] ?? '');
        $year = $this->extractYear($f);
        $surname = '';
        $names = trim($f['author'] ?? ($f['editor'] ?? ''));
        if ($names !== '') {
            $first = (preg_split('/\s+and\s+/i', $names) ?: [''])[0];
            $first = str_replace(['{', '}'], '', $first);
            if (str_contains($first, ',')) {
                $surname = trim((explode(',', $first, 2))[0]);
            } else {
                $parts = preg_split('/\s+/', trim($first)) ?: [];
                $surname = $parts === [] ? '' : (string) end($parts);
            }
        }
        $container = '';
        foreach (['journaltitle', 'journal', 'booktitle'] as $cKey) {
            $cVal = trim($f[$cKey] ?? '');
            if ($cVal !== '') {
                $container = $cVal;
                break;
            }
        }
        return 't:' . sha1(implode('|', [
            $title,
            $year,
            $this->normalizeTerm($surname),
            strtolower(trim((string) ($e['type'] ?? ''))),
            $this->normText($container),
        ]));
    }

    private function contentHash(array $e): string
    {
        $canon = ['key' => $e['citekey'], 'type' => $e['type'], 'fields' => $e['fields']];
        ksort($canon['fields']);
        return sha1(json_encode($canon, JSON_UNESCAPED_UNICODE));
    }

    /** Titelwörter-Index (für A-Z-Browsing und Titel-Suche) neu aufbauen. */
    private function rebuildTitleIndex(): void
    {
        $db = $this->getDb();
        $db->exec('DELETE FROM search_index');
        $stmt = $db->prepare('INSERT INTO search_index (term, display_term, field, doc_id) VALUES (?, ?, ?, ?)');
        $db->beginTransaction();
        foreach ($db->query('SELECT id, title, subtitle FROM entries', PDO::FETCH_ASSOC) as $row) {
            $words = preg_split('/[^\p{L}\p{N}]+/u', ($row['title'] ?? '') . ' ' . ($row['subtitle'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach (array_unique($words) as $w) {
                if (mb_strlen($this->normalizeTerm($w), 'UTF-8') > 2) {
                    $stmt->execute([$this->normalizeTerm($w), $w, 'qt', (int) $row['id']]);
                }
            }
        }
        $db->commit();
        $db->exec('CREATE INDEX IF NOT EXISTS idx_si_field_term ON search_index (field, term)');
    }

    // ================================================================ Suche (OpacLibrary)

    public function searchBoolean(string $query, int|string $index): array
    {
        $field = is_int($index) ? $this->numToKey($index) : $index;

        preg_match_all('/"(?:\\\\.|[^\\\\"])*"|\S+/', trim($query), $matches);
        $tokens = $matches[0] ?? [];
        if ($tokens === []) {
            return [];
        }

        $result = null;
        $op = 'AND';
        $negateNext = false;

        foreach ($tokens as $tok) {
            if (substr($tok, 0, 1) === '"' && substr($tok, -1) === '"') {
                $tok = stripslashes(substr($tok, 1, -1));
            }
            $upper = strtoupper($tok);
            if ($upper === 'AND') { $op = 'AND'; continue; }
            if ($upper === 'OR') { $op = 'OR'; continue; }
            if ($upper === 'NOT') { $negateNext = true; continue; }

            $ids = $this->idsForTerm($tok, $field);

            if ($negateNext) {
                if ($result !== null) {
                    $result = array_diff($result, $ids);
                }
                $negateNext = false;
            } else {
                if ($result === null) {
                    $result = $ids;
                } elseif ($op === 'AND') {
                    $result = array_intersect($result, $ids);
                } else {
                    $result = array_unique(array_merge($result, $ids), SORT_NUMERIC);
                }
            }
            $op = 'AND';
        }
        return $result ?? [];
    }

    /** ID-Set für einen Suchbegriff in einem Feld. */
    private function idsForTerm(string $term, string $field): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }
        $db = $this->getDb();
        $norm = $this->normalizeTerm($term);
        switch ($field) {
            case 'qt':
                $stmt = $db->prepare("SELECT DISTINCT doc_id FROM search_index WHERE field = 'qt' AND term LIKE ?");
                $stmt->execute([$norm . '%']);
                return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            case 'qp':
                $stmt = $db->prepare('SELECT id FROM entries WHERE authors_norm LIKE ?');
                $stmt->execute(['%' . $norm . '%']);
                return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            case 'qj':
                $stmt = $db->prepare("SELECT id FROM entries WHERE journal_norm != '' AND journal_norm LIKE ?");
                $stmt->execute(['%' . $norm . '%']);
                return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            case 'qs':
                $stmt = $db->prepare("SELECT id FROM entries WHERE keywords_norm != '' AND keywords_norm LIKE ?");
                $stmt->execute(['%' . $norm . '%']);
                return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            case 'qy':
                $stmt = $db->prepare("SELECT id FROM entries WHERE year != '' AND year LIKE ?");
                $stmt->execute([$term . '%']);
                return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            case 'qa':
                $stmt = $db->prepare("SELECT id FROM entries WHERE abstract_norm != '' AND abstract_norm LIKE ?");
                $stmt->execute(['%' . $norm . '%']);
                return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            default: // Freitext 'q'
                $stmt = $db->prepare('SELECT id FROM entries WHERE alltext_norm LIKE ?');
                $stmt->execute(['%' . $norm . '%']);
                return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }
    }

    // ---- A-Z Browsing / Term-Listen ----

    public function getTerms(int|string $index, string $prefix, int $limit = 100): array
    {
        $field = is_int($index) ? $this->numToKey($index) : $index;
        $prefix = $this->normalizeTerm($prefix);
        if ($field === 'qt') {
            $db = $this->getDb();
            $stmt = $db->prepare(
                "SELECT MIN(display_term) AS term, COUNT(DISTINCT doc_id) AS count
                 FROM search_index WHERE field = 'qt' AND term LIKE ?
                 GROUP BY term ORDER BY term LIMIT ?"
            );
            $stmt->execute([$prefix . '%', $limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return $this->facetTerms($field, $prefix, null, $limit);
    }

    public function getTermCount(int|string $index): int
    {
        $field = is_int($index) ? $this->numToKey($index) : $index;
        if ($field === 'qt') {
            $db = $this->getDb();
            return (int) $db->query("SELECT COUNT(DISTINCT term) FROM search_index WHERE field = 'qt'")->fetchColumn();
        }
        return count($this->facetTerms($field, '', null, null));
    }

    public function getTermsByOffset(int|string $index, int $offset, int $limit = 50): array
    {
        $field = is_int($index) ? $this->numToKey($index) : $index;
        if ($field === 'qt') {
            $db = $this->getDb();
            $stmt = $db->prepare(
                "SELECT MIN(display_term) AS term, COUNT(DISTINCT doc_id) AS count
                 FROM search_index WHERE field = 'qt'
                 GROUP BY term ORDER BY term LIMIT ? OFFSET ?"
            );
            $stmt->execute([$limit, $offset]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return $this->facetTerms($field, '', $offset, $limit);
    }

    /**
     * Facetten (Personen/Quellen/Schlagwörter/Jahr) direkt aus den entries-Spalten –
     * ganze Begriffe statt Einzelwörter, daher kein search_index nötig.
     */
    private function facetTerms(string $field, string $prefix, ?int $offset, ?int $limit): array
    {
        $db = $this->getDb();
        $counts = [];
        foreach ($db->query('SELECT id, authors, journal, keywords, year FROM entries', PDO::FETCH_ASSOC) as $row) {
            $values = match ($field) {
                'qp' => explode('|', $row['authors'] ?? ''),
                'qj' => [$row['journal'] ?? ''],
                'qs' => preg_split('/[,;]+/', $row['keywords'] ?? '') ?: [],
                'qy' => [$row['year'] ?? ''],
                default => [],
            };
            foreach ($values as $v) {
                $v = trim($v);
                if ($v === '') {
                    continue;
                }
                $t = $this->normalizeTerm($v);
                if ($prefix !== '' && !str_starts_with($t, $prefix)) {
                    continue;
                }
                if (!isset($counts[$t])) {
                    $counts[$t] = ['term' => $v, 'count' => 0];
                }
                $counts[$t]['count']++;
            }
        }
        uasort($counts, fn (array $a, array $b): int => strcmp($a['term'], $b['term']));
        $out = array_values($counts);
        if ($offset !== null) {
            $out = array_slice($out, $offset, $limit ?? 50);
        } elseif ($limit !== null) {
            $out = array_slice($out, 0, $limit);
        }
        return $out;
    }

    // ================================================================ Records

    /** Vollständiger Opac-Record (inkl. HTML-Darstellung). */
    public function getRecord(int $docId): ?array
    {
        $row = $this->fetchRow($docId);
        if ($row === null) {
            return null;
        }
        return $this->toOpacRecord($row, true);
    }

    /** Record ohne HTML-Erzeugung (für Filterung). */
    public function getRecordLight(int $docId): ?array
    {
        $row = $this->fetchRow($docId);
        if ($row === null) {
            return null;
        }
        return $this->toOpacRecord($row, false);
    }

    /**
     * Sortierschlüssel für eine ID-Menge (Autor/Jahr/Titel).
     *
     * Nur die drei Sortierspalten werden gelesen – kein fields_json, kein
     * HTML-Aufbau. Die Schritte fassen je 500 IDs zusammen, damit die
     * Anzahl-der-Parameter auch bei sehr großen Treffermengen begrenzt bleibt.
     *
     * @param int[] $ids
     * @return array<int,array{author:string,year:string,title:string}>
     */
    public function sortKeys(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $db = $this->getDb();
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $db->prepare(
                "SELECT id, authors, year, title FROM entries WHERE id IN ($placeholders)"
            );
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out[(int) $row['id']] = [
                    // Autoren stehen in der Form "Nachname, Vorname" – die
                    // Sortierung trifft damit zuerst den Nachnamen.
                    'author' => sort_key((string) $row['authors']),
                    'year' => trim((string) $row['year']),
                    'title' => sort_key((string) $row['title']),
                ];
            }
        }
        return $out;
    }

    /** Leichte Iteration über alle Einträge (für Freitext-Fallback). */
    public function iterateLight(): Traversable
    {
        foreach ($this->getDb()->query('SELECT id, title, alltext, abstract FROM entries ORDER BY id', PDO::FETCH_ASSOC) as $row) {
            yield [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'alltext' => (string) $row['alltext'],
                'abstract' => (string) $row['abstract'],
            ];
        }
    }

    public function countRecords(): int
    {
        try {
            return (int) $this->getDb()->query('SELECT COUNT(*) FROM entries')->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    private function fetchRow(int $docId): ?array
    {
        if ($docId < 1) {
            return null;
        }
        $stmt = $this->getDb()->prepare('SELECT * FROM entries WHERE id = ?');
        $stmt->execute([$docId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** DB-Zeile -> normalisiertes Opac-Record (vertraglich identisch zu MidosIndex::getRecord). */
    private function toOpacRecord(array $row, bool $withHtml): array
    {
        $f = json_decode($row['fields_json'] ?? '{}', true) ?: [];
        $authors = $row['authors'] !== '' ? explode('|', $row['authors']) : [];
        $editors = ($row['authors'] === '' && ($f['editor'] ?? '') !== '')
            ? $this->splitNames((string) $f['editor'])
            : [];

        // Anzeigefelder werden für die Ausgabe entklammert und dekodiert: In der .bib
        // stehen die Werte als {geschuetzt} bzw. mit LaTeX-Makros (z. B.
        // {Hinterbühne}, \"{a}), weil BibTeX so die Großschreibung erhält. Roh
        // bleiben fields_json und alltext – die dienen Suche und Fingerprint.
        $disp = static fn (string $v): string => BibTeXParser::decodeLatex(trim($v));

        $rec = [
            'id' => (int) $row['id'],
            'source' => 'bib',
            'citekey' => (string) $row['citekey'],
            'entry_type' => (string) $row['entry_type'],
            'type_label' => $this->typeLabel((string) $row['entry_type']),
            'title' => $disp((string) $row['title']),
            'subtitle' => $disp((string) $row['subtitle']),
            'authors' => $authors,
            'editors' => $editors,
            'journal' => $disp((string) $row['journal']),
            'year' => (string) $row['year'],
            'volume' => $disp((string) ($f['volume'] ?? '')),
            'issue' => $disp((string) ($f['number'] ?? ($f['issue'] ?? ''))),
            'pages' => $disp((string) ($f['pages'] ?? '')),
            'publisher' => $disp((string) ($f['publisher'] ?? '')),
            'location' => $disp((string) ($f['location'] ?? ($f['address'] ?? ''))),
            'url' => (string) $row['url'],
            'doi' => (string) $row['doi'],
            'isbn_issn' => (string) $row['isbn_issn'],
            'language' => (string) $row['language'],
            'abstract' => $disp((string) $row['abstract']),
            'keywords' => array_values(array_filter(array_map($disp, preg_split('/[,;]+/', $row['keywords'] ?? '') ?: []))),
            'fields' => $f,
            'alltext' => (string) $row['alltext'],
        ];
        $rec['html'] = $withHtml ? $this->renderHtml($rec) : '';
        return $rec;
    }

    /** HTML-Darstellung, strukturell identisch zum MIDOS-Record (config.php). */
    private function renderHtml(array $rec): string
    {
        $h = fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<div class="record-content">';

        $linkUrl = $rec['url'] !== '' ? $rec['url'] : ($rec['doi'] !== '' ? 'https://doi.org/' . $rec['doi'] : '');
        if ($linkUrl !== '') {
            $html .= '<a class="fulltext-link" href="' . $h($linkUrl) . '" target="_blank"><span>📄</span>'
                . $h(ueb('Volltext anzeigen / PDF öffnen')) . '</a>';
        }

        $names = $rec['authors'] !== [] ? $rec['authors'] : $rec['editors'];
        if ($names !== []) {
            $links = [];
            foreach ($names as $n) {
                $q = 'msuche.php?qp=' . urlencode('"' . $n . '"');
                $links[] = '<a class="author-link" href="' . $h($q) . '">' . $h($n) . '</a>';
            }
            $prefix = $rec['authors'] === [] && $rec['editors'] !== [] ? '<em>' . $h(ueb('Hrsg.')) . ':</em> ' : '';
            $html .= '<div class="author-links">' . $prefix . implode(' &nbsp;|&nbsp; ', $links) . '</div>';
        }

        if ($rec['subtitle'] !== '') {
            $html .= '<div class="source-info">' . $h($rec['subtitle']) . '</div>';
        }

        $srcStr = '';
        if ($rec['journal'] !== '') {
            $srcStr .= 'In: <em>' . $h($rec['journal']) . '</em>';
        }
        if ($rec['volume'] !== '') {
            $srcStr .= ', Jg. ' . $h($rec['volume']);
        }
        if ($rec['issue'] !== '') {
            $srcStr .= ', Heft ' . $h($rec['issue']);
        }
        if ($rec['year'] !== '') {
            $srcStr .= ' (' . $h($rec['year']) . ')';
        }
        if ($rec['pages'] !== '') {
            $srcStr .= ', ' . $h($rec['pages']);
        }
        if ($rec['publisher'] !== '') {
            $srcStr .= ($srcStr === '' ? '' : '. ') . $h($rec['publisher']);
        }
        if ($rec['location'] !== '') {
            $srcStr .= ': ' . $h($rec['location']);
        }
        if ($srcStr !== '') {
            $html .= '<div class="source-info">' . $srcStr . '</div>';
        }

        $badges = '<span class="badge">' . $h($rec['type_label']) . '</span> ';
        if ($rec['doi'] !== '') {
            $badges .= '<span class="badge" style="background: transparent; color: var(--text-muted);">DOI: '
                . '<a href="https://doi.org/' . $h($rec['doi']) . '" target="_blank">' . $h($rec['doi']) . '</a></span> ';
        }
        if ($rec['isbn_issn'] !== '') {
            $badges .= '<span class="badge" style="background: transparent; color: var(--text-muted);">ISBN/ISSN: '
                . $h($rec['isbn_issn']) . '</span>';
        }
        $html .= '<div style="margin-top: 6px; margin-bottom: 8px;">' . $badges . '</div>';

        if ($rec['abstract'] !== '') {
            $html .= '<div class="abstract-text"><strong>Abstract:</strong> ' . $h($rec['abstract']) . '</div>';
        }

        if ($rec['keywords'] !== []) {
            $kwHtml = '';
            foreach ($rec['keywords'] as $kw) {
                $kwHtml .= '<span class="badge" style="background: transparent; color: var(--text-muted);">' . $h($kw) . '</span> ';
            }
            $html .= '<div style="margin-top: 4px;">' . $kwHtml . '</div>';
        }

        $html .= '</div>';
        $html .= '<details class="record-details"><summary>Alle Felder anzeigen</summary><div class="details-content">';
        foreach ($rec['fields'] as $name => $value) {
            if ($name === 'file') {
                continue; // lokale Zotero-Pfade sind im Web-OPAC ohne Bedeutung
            }
            $html .= '<div class="field-row"><span class="field-label">' . $h($name) . ':</span> '
                . $h(BibTeXParser::decodeLatex((string) $value)) . '</div>';
        }
        $html .= '</div></details>';
        return $html;
    }

    // ================================================================ Meta/Hilfsfunktionen

    public function getStats(): array
    {
        $stats = [
            'total' => $this->countRecords(),
            'file' => $this->getMeta('last_file') ?: '',
            'file_mtime' => $this->getMeta('last_file_mtime') ?: '',
            'last_sync' => $this->getMeta('last_sync') ?: '',
            'last_stats' => $this->getMeta('last_stats') ?: '',
            'last_error' => $this->getMeta('last_error') ?: '',
        ];
        if ($stats['last_stats'] !== '') {
            $stats['last_stats'] = json_decode($stats['last_stats'], true) ?: [];
        }
        $stats['sync_pending'] = $this->pendingImport();
        return $stats;
    }

    /**
     * Bestands-Metadaten für die Frontend-Anzeige: Zeitpunkt des letzten
     * Abgleichs sowie Name und Größe der zuletzt importierten .bib-Datei.
     * Liefert bewusst nur Angaben, keine Import-Aktion.
     *
     * @return array{last_sync:int|null,file:string,file_size:int,pending:array|null}
     */
    public function stockInfo(): array
    {
        $sync = $this->getMeta('last_sync') ?: '';
        $ts = ($sync !== '') ? strtotime($sync) : false;

        return [
            'last_sync' => ($ts === false) ? null : (int) $ts,
            'file' => $this->getMeta('last_file') ?: '',
            'file_size' => (int) ($this->getMeta('last_file_size') ?: '0'),
            'pending' => $this->pendingImport(),
        ];
    }

    /**
     * Ausstehender Abgleich oder null: die neueste .bib hat sich geändert, ist aber
     * zu groß für einen Auto-Import im Seitenaufruf (siehe ensureFresh()).
     *
     * @return array{file:string,size:int,mtime:int,since:string}|null
     */
    public function pendingImport(): ?array
    {
        $raw = $this->getMeta('sync_pending') ?: '';
        if ($raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : ['file' => $raw];
    }

    public function getMeta(string $key): ?string
    {
        try {
            $stmt = $this->getDb()->prepare('SELECT value FROM meta WHERE key = ?');
            $stmt->execute([$key]);
            $v = $stmt->fetchColumn();
            return $v === false ? null : (string) $v;
        } catch (Throwable) {
            return null;
        }
    }

    public function setMeta(string $key, string $value): void
    {
        $this->getDb()->prepare(
            'INSERT INTO meta (key, value) VALUES (?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value'
        )->execute([$key, $value]);
    }

    private function getDb(): PDO
    {
        if ($this->db === null) {
            $file = $this->bibDir . DIRECTORY_SEPARATOR . 'bib.db';
            $this->db = new PDO('sqlite:' . $file);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->db->exec(
                'CREATE TABLE IF NOT EXISTS entries (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    fingerprint TEXT UNIQUE NOT NULL,
                    content_hash TEXT NOT NULL,
                    citekey TEXT,
                    entry_type TEXT,
                    title TEXT DEFAULT \'\',
                    subtitle TEXT DEFAULT \'\',
                    year TEXT DEFAULT \'\',
                    authors TEXT DEFAULT \'\',
                    authors_norm TEXT DEFAULT \'\',
                    journal TEXT DEFAULT \'\',
                    journal_norm TEXT DEFAULT \'\',
                    abstract TEXT DEFAULT \'\',
                    abstract_norm TEXT DEFAULT \'\',
                    keywords TEXT DEFAULT \'\',
                    keywords_norm TEXT DEFAULT \'\',
                    doi TEXT DEFAULT \'\',
                    url TEXT DEFAULT \'\',
                    isbn_issn TEXT DEFAULT \'\',
                    language TEXT DEFAULT \'\',
                    alltext TEXT DEFAULT \'\',
                    alltext_norm TEXT DEFAULT \'\',
                    fields_json TEXT NOT NULL,
                    source_file TEXT,
                    updated_at TEXT
                )'
            );
            $this->db->exec('CREATE TABLE IF NOT EXISTS search_index (
                term TEXT, display_term TEXT, field TEXT, doc_id INTEGER)');
            $this->db->exec('CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT)');
            $this->db->exec('CREATE INDEX IF NOT EXISTS idx_entries_authors ON entries (authors_norm)');
            $this->db->exec('CREATE INDEX IF NOT EXISTS idx_entries_alltext ON entries (alltext_norm)');
        }
        return $this->db;
    }

    /** Normalisiert für Suche/Gruppierung: Großbuchstaben, Umlaute gefaltet (wie MidosIndex). */
    private function normalizeTerm(string $s): string
    {
        $s = mb_strtoupper($s, 'UTF-8');
        $map = [
            'Ä' => 'A', 'Ö' => 'O', 'Ü' => 'U', 'ß' => 'S',
            'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Å' => 'A', 'Ã' => 'A', 'Ā' => 'A', 'Ă' => 'A', 'Ą' => 'A',
            'Ç' => 'C', 'Ć' => 'C', 'Č' => 'C',
            'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Ę' => 'E', 'Ě' => 'E', 'Ē' => 'E', 'Ė' => 'E',
            'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Į' => 'I', 'İ' => 'I', 'Í' => 'I',
            'Ð' => 'D', 'Đ' => 'D', 'Ñ' => 'N', 'Ń' => 'N', 'Ň' => 'N',
            'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ø' => 'O', 'Ő' => 'O', 'Ō' => 'O',
            'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ű' => 'U', 'Ů' => 'U', 'Ū' => 'U',
            'Ý' => 'Y', 'Ÿ' => 'Y', 'Þ' => 'TH', 'Æ' => 'AE', 'Œ' => 'OE', 'Ł' => 'L', 'Ð' => 'D',
            'Ś' => 'S', 'Š' => 'S', 'Ż' => 'Z', 'Ž' => 'Z', 'Ź' => 'Z',
        ];
        return strtr($s, $map);
    }

    /** Lowercase, Diakritika-gefaltet, nur Buchstaben/Zahlen (für Teilstring-Suche). */
    private function normText(string $s): string
    {
        $s = BibTeXParser::decodeLatex($s);
        $s = mb_strtolower($s, 'UTF-8');
        $translit = ['ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss', 'é' => 'e', 'è' => 'e', 'ê' => 'e',
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'ú' => 'u',
            'ñ' => 'n', 'ç' => 'c', 'å' => 'a', 'ø' => 'o', 'æ' => 'ae', 'œ' => 'oe', 'ł' => 'l',
            'š' => 's', 'ž' => 'z', 'č' => 'c', 'ę' => 'e', 'ą' => 'a', 'ć' => 'c', 'ń' => 'n',
            'ń' => 'n', 'ő' => 'o', 'ű' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'đ' => 'd', 'ð' => 'd', 'þ' => 'th'];
        $s = strtr($s, $translit);
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? '';
        return trim(preg_replace('/\s+/', ' ', $s) ?? '');
    }

    private function numToKey(int $num): string
    {
        return match ($num) {
            1 => 'qp', 2 => 'qt', 4 => 'qs', 6 => 'qj', 7 => 'qy',
            default => 'q',
        };
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'article' => 'Aufsatz (Zeitschrift)',
            'book' => 'Buch',
            'incollection' => 'Sammelwerksbeitrag',
            'inbook' => 'Buchkapitel',
            'inproceedings' => 'Konferenzbeitrag',
            'proceedings' => 'Konferenzband',
            'collection' => 'Sammelwerk',
            'online' => 'Online-Ressource',
            'report', 'techreport' => 'Bericht',
            'thesis', 'phdthesis', 'mastersthesis' => 'Hochschulschrift',
            'unpublished' => 'Unveröffentlicht',
            'letter' => 'Brief',
            'dataset' => 'Datenset',
            'software' => 'Software',
            'manual' => 'Handbuch',
            'patent' => 'Patent',
            'periodical' => 'Zeitschrift',
            'inreference' => 'Nachschlagewerk-Artikel',
            default => 'Sonstiges',
        };
    }
}
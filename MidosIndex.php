<?php
declare(strict_types=1);

/**
 * Class MidosIndex
 * Handles reading of legacy MIDOS index files (.pid, .pvw).
 * 
 * Based on legacy Perl logic (msuche.pl):
 * - .pid files are sorted text files containing "TERM !OFFSET"
 * - Search uses binary search on the text file (seek to middle, skip partial line)
 * - .pvw files contain lists of document IDs at the specified offset
 */
class MidosIndex implements OpacLibrary
{
    private string $dataDir;
    private ?PDO $db = null;

    public function __construct(string $dataDir)
    {
        $this->dataDir = rtrim($dataDir, '/\\');
        $this->ensureIndexIsFresh();
    }

    private function getDb(): PDO
    {
        if ($this->db === null) {
            $dbFile = $this->dataDir . DIRECTORY_SEPARATOR . 'index.db';
            $this->db = new PDO("sqlite:$dbFile");
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
        return $this->db;
    }

    private function ensureIndexIsFresh(): void
    {
        $pdkFile = $this->dataDir . DIRECTORY_SEPARATOR . 'pdok.pdk';
        $dbFile  = $this->dataDir . DIRECTORY_SEPARATOR . 'index.db';

        if (!file_exists($pdkFile)) return;

        $pdkTime = filemtime($pdkFile);
        $dbTime  = file_exists($dbFile) ? filemtime($dbFile) : 0;

        if ($pdkTime > $dbTime) {
            $this->rebuild($pdkFile, $dbFile);
        }
    }

    /**
     * Register-Suche. $indexNum darf neben der Legacy-Nummer auch der
     * Feldname ('qp','qt','qs','qj','qy') sein – resolveIndex() vereinheitlicht
     * beides. Der Aufrufer (msuche.php) übergibt den Feldnamen.
     */
    public function search(string $term, int|string $indexNum): array
    {
        $field = $this->resolveIndex($indexNum);
        $term = $this->normalize($term);

        $db = $this->getDb();
        // Index uses prefix match in legacy? "TERM" matches "TERMIN".
        // Let's use LIKE 'TERM%' for compatibility.
        $stmt = $db->prepare("SELECT DISTINCT doc_id FROM search_index WHERE field = ? AND term LIKE ?");
        $stmt->execute([$field, $term . '%']);
        
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function searchBoolean(string $query, int|string $indexNum): array
    {
        preg_match_all('/"(?:\\\\.|[^\\\\"])*"|\S+/', trim($query), $matches);
        $tokens = $matches[0] ?? [];
        if (count($tokens) === 0) return [];

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

            $ids = $this->search($tok, $indexNum); 
            
            if ($negateNext) {
                if ($result !== null) $result = array_diff($result, $ids);
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

    public function getTerms(int|string $indexNum, string $prefix, int $limit = 100): array
    {
        $field = $this->resolveIndex($indexNum);
        $prefix = $this->normalize($prefix);
        
        $db = $this->getDb();
        // Use LIKE to match only terms starting with the prefix
        $stmt = $db->prepare("SELECT MIN(display_term) as term, COUNT(DISTINCT doc_id) as count 
                                    FROM search_index 
                                    WHERE field = ? AND term LIKE ? 
                                    GROUP BY term 
                                    ORDER BY term 
                                    LIMIT ?");
        $stmt->execute([$field, $prefix . '%', $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTermCount(int|string $indexNum): int
    {
        $field = $this->resolveIndex($indexNum);
        $db = $this->getDb();
        $stmt = $db->prepare("SELECT COUNT(DISTINCT term) FROM search_index WHERE field = ?");
        $stmt->execute([$field]);
        return (int)$stmt->fetchColumn();
    }

    public function getTermsByOffset(int|string $indexNum, int $offset, int $limit = 50): array
    {
        $field = $this->resolveIndex($indexNum);
        $db = $this->getDb();
        $stmt = $db->prepare("SELECT MIN(display_term) as term, COUNT(DISTINCT doc_id) as count 
                                    FROM search_index 
                                    WHERE field = ? 
                                    GROUP BY term 
                                    ORDER BY term 
                                    LIMIT ? OFFSET ?");
        $stmt->execute([$field, $limit, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getRecord(int $docId): ?array
    {
        $raw = $this->readRawRecord($docId);
        if ($raw === null) return null;
        return $this->toOpacRecord($docId, $raw, true);
    }

    public function getRecordLight(int $docId): ?array
    {
        $raw = $this->readRawRecord($docId);
        if ($raw === null) return null;
        return $this->toOpacRecord($docId, $raw, false);
    }

    /**
     * Sortierschlüssel für eine ID-Menge (Autor/Jahr/Titel).
     *
     * Die Offsets kommen aus dem Index; die pdk wird einmal geöffnet und
     * sequenziell angelesen, statt pro Datensatz neu zu öffnen.
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
        sort($ids, SORT_NUMERIC);

        $pdkFile = $this->dataDir . DIRECTORY_SEPARATOR . 'pdok.pdk';
        if (!is_readable($pdkFile)) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->getDb()->prepare(
                "SELECT doc_id, byte_offset FROM doc_offsets WHERE doc_id IN ($placeholders)"
            );
            $stmt->execute($ids);
            $offsets = array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
        } catch (Throwable) {
            return [];
        }
        if ($offsets === []) {
            return [];
        }

        $fp = fopen($pdkFile, 'rb');
        if ($fp === false) {
            return [];
        }
        $out = [];
        foreach ($ids as $id) {
            if (!isset($offsets[$id])) {
                continue;
            }
            if (fseek($fp, $offsets[$id]) !== 0) {
                continue;
            }
            $line = fgets($fp);
            if ($line === false) {
                continue;
            }
            $f = parse_pdok_fields(mb_convert_encoding(rtrim($line), 'UTF-8', 'ISO-8859-1'));
            $out[$id] = [
                'author' => sort_key((string) ($f['VER'] ?? '')),
                'year' => trim((string) ($f['ERJ'] ?? ($f['JA'] ?? ''))),
                'title' => sort_key((string) ($f['HST'] ?? ($f['TI'] ?? ''))),
            ];
        }
        fclose($fp);
        return $out;
    }

    public function iterateLight(): Traversable
    {
        $pdkFile = $this->dataDir . DIRECTORY_SEPARATOR . 'pdok.pdk';
        if (!file_exists($pdkFile)) return;
        $fp = fopen($pdkFile, 'r');
        if (!$fp) return;
        $docId = 0;
        while (($line = fgets($fp)) !== false) {
            $docId++;
            if (trim($line) === '') continue;
            $utf8 = mb_convert_encoding(rtrim($line), 'UTF-8', 'ISO-8859-1');
            $fields = parse_pdok_fields($utf8);
            yield [
                'id' => $docId,
                'title' => $fields['HST'] ?? ($fields['TI'] ?? ($fields['T'] ?? '(ohne Titel)')),
                'alltext' => $utf8,
                'abstract' => $fields['ABS'] ?? ($fields['ZUS'] ?? ''),
            ];
        }
        fclose($fp);
    }

    public function countRecords(): int
    {
        try {
            $db = $this->getDb();
            return (int)$db->query('SELECT COUNT(*) FROM doc_offsets')->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * MIDOS-Bestand kennt weder Abgleich-Zeitpunkt noch Dateigröße; die
     * Frontend-Anzeige blendet beide Angaben dann einfach aus.
     *
     * @return array{last_sync:int|null,file:string,file_size:int,pending:array|null}
     */
    public function stockInfo(): array
    {
        return [
            'last_sync' => null,
            'file' => '',
            'file_size' => 0,
            'pending' => null,
        ];
    }

    /**
     * Rohdatensatz als UTF-8.
     *
     * pdok.pdk ist ISO-8859-1; ohne die Umwandlung findet parse_pdok_fields()
     * das Trennzeichen '¿' (2 Byte in UTF-8) nicht und alle Felder blieben
     * leer. Der Indexaufbau und iterateLight() wandeln ebenfalls hier um.
     */
    private function readRawRecord(int $docId): ?string
    {
        if ($docId < 1) return null;
        $pdkFile = $this->dataDir . DIRECTORY_SEPARATOR . 'pdok.pdk';
        if (!file_exists($pdkFile)) return null;

        $db = $this->getDb();
        $stmt = $db->prepare("SELECT byte_offset FROM doc_offsets WHERE doc_id = ?");
        $stmt->execute([$docId]);
        $offset = $stmt->fetchColumn();
        if ($offset === false) return null;

        $fp = fopen($pdkFile, 'rb');
        if (!$fp) return null;
        if (fseek($fp, (int)$offset) !== 0) { fclose($fp); return null; }
        $line = fgets($fp);
        fclose($fp);

        if ($line === false) return null;
        return mb_convert_encoding(rtrim($line), 'UTF-8', 'ISO-8859-1');
    }

    /** MIDOS-Rohtext -> normalisiertes Opac-Record (identischer Vertrag wie BibLibrary::getRecord). */
    private function toOpacRecord(int $docId, string $rawUtf8, bool $withHtml): array
    {
        $fields = parse_pdok_fields($rawUtf8);
        $fmt = $withHtml ? format_pdok_record($rawUtf8) : ['title' => ($fields['HST'] ?? ($fields['TI'] ?? '(ohne Titel)')), 'html' => ''];

        return [
            'id' => $docId,
            'source' => 'midos',
            'citekey' => (string)($fields['INN'] ?? ('m' . $docId)),
            'entry_type' => (string)($fields['DTY'] ?? ''),
            'type_label' => (string)($fields['DTY'] ?? 'MIDOS'),
            'title' => $fmt['title'],
            'subtitle' => (string)($fields['ZUS'] ?? ''),
            'authors' => array_values(array_filter(array_map('trim', explode('|', $fields['VER'] ?? '')))),
            'editors' => [],
            'journal' => (string)($fields['ZNA'] ?? ''),
            'year' => (string)($fields['ERJ'] ?? ($fields['JA'] ?? '')),
            'volume' => (string)($fields['ZJG'] ?? ''),
            'issue' => (string)($fields['ZHE'] ?? ''),
            'pages' => (string)($fields['KOL'] ?? ''),
            'publisher' => '',
            'location' => (string)($fields['ORT'] ?? ''),
            'url' => (string)($fields['URL'] ?? ''),
            'doi' => '',
            'isbn_issn' => (string)($fields['ISSN'] ?? ($fields['ISBN'] ?? '')),
            'language' => (string)($fields['LAN'] ?? ''),
            'abstract' => (string)($fields['ABS'] ?? ($fields['ZUS'] ?? '')),
            'keywords' => array_values(array_filter(array_map('trim', explode('|', ($fields['SW'] ?? '') . '|' . ($fields['OSW'] ?? '') . '|' . ($fields['FISSW'] ?? ''))))),
            'fields' => $fields,
            'alltext' => $rawUtf8,
            'html' => $fmt['html'],
        ];
    }


    private function resolveIndex(int|string $index): string
    {
        if (is_string($index)) $index = match($index) {
            'qp' => 1, 'qt' => 2, 'qs' => 4, 'qj' => 6, 'qy' => 7,
            default => 0,
        };
        return $this->mapIndexNumToField($index);
    }

    private function mapIndexNumToField(int $num): string
    {
        return match($num) {
            1 => 'qp',
            2 => 'qt',
            4 => 'qs',
            6 => 'qj',
            7 => 'qy',
            default => 'q'
        };
    }

    private function normalize(string $s): string
    {
        $s = mb_strtoupper($s, 'UTF-8');
        $map = [
            'Ä' => 'A', 'Ö' => 'O', 'Ü' => 'U', 'ß' => 'S',
            'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Å' => 'A',
            'Ç' => 'C',
            'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'Ð' => 'D', 'Ñ' => 'N',
            'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ø' => 'O',
            'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U',
            'Ý' => 'Y', 'Ÿ' => 'Y'
        ];
        return strtr($s, $map);
    }

    private function rebuild(string $pdkFile, string $dbFile): void
    {
        if (file_exists($dbFile)) unlink($dbFile);
        $db = new PDO("sqlite:$dbFile");
        $db->exec("CREATE TABLE search_index (term TEXT, display_term TEXT, field TEXT, doc_id INTEGER)");
        $db->exec("CREATE TABLE doc_offsets (doc_id INTEGER PRIMARY KEY, byte_offset INTEGER)");
        
        $stmt = $db->prepare("INSERT INTO search_index (term, display_term, field, doc_id) VALUES (?, ?, ?, ?)");
        $stmtOffset = $db->prepare("INSERT INTO doc_offsets (doc_id, byte_offset) VALUES (?, ?)");
        $fp = fopen($pdkFile, 'rb');
        $docId = 0;
        $db->beginTransaction();
        
        while (true) {
            $byteOffset = ftell($fp);
            $line = fgets($fp);
            if ($line === false) break;
            $docId++;
            $stmtOffset->execute([$docId, $byteOffset]);
            $line = mb_convert_encoding($line, 'UTF-8', 'ISO-8859-1');
            $parts = explode('¿', trim($line));
            $fields = [];
            foreach ($parts as $p) {
                $pos = strpos($p, ':');
                if ($pos !== false) $fields[substr($p, 0, $pos)] = substr($p, $pos + 1);
            }

            // Indexing logic
            // Author
            $authors = $fields['VER'] ?? '';
            foreach (explode('|', $authors) as $a) {
                $a = trim($a);
                $norm = $this->normalize($a);
                if ($norm !== '') $stmt->execute([$norm, $a, 'qp', $docId]);
            }
            // Title words
            $title = ($fields['HST'] ?? '') . ' ' . ($fields['TI'] ?? '');
            foreach (array_unique(preg_split('/[^a-zA-Z0-9ÄÖÜäöüß]+/', $title, -1, PREG_SPLIT_NO_EMPTY)) as $w) {
                $norm = $this->normalize($w);
                if (strlen($norm) > 2) $stmt->execute([$norm, $w, 'qt', $docId]);
            }
            // Journal
            $zna = trim($fields['ZNA'] ?? '');
            if ($zna !== '') $stmt->execute([$this->normalize($zna), $zna, 'qj', $docId]);
            // Keywords
            $sw = ($fields['SW'] ?? '') . ' ' . ($fields['OSW'] ?? '') . ' ' . ($fields['FISSW'] ?? '');
            foreach (array_unique(preg_split('/[^a-zA-Z0-9ÄÖÜäöüß]+/', $sw, -1, PREG_SPLIT_NO_EMPTY)) as $w) {
                $norm = $this->normalize($w);
                if (strlen($norm) > 1) $stmt->execute([$norm, $w, 'qs', $docId]);
            }
            // Year (ERJ / JA)
            $year = trim($fields['ERJ'] ?? ($fields['JA'] ?? ''));
            if ($year !== '') {
                $norm = $this->normalize($year);
                $stmt->execute([$norm, $year, 'qy', $docId]);
            }

            if ($docId % 2000 === 0) {
                $db->commit();
                $db->beginTransaction();
            }
        }
        $db->commit();
        fclose($fp);
        $db->exec("CREATE INDEX idx_term ON search_index (term)");
        $db->exec("CREATE INDEX idx_field_term ON search_index (field, term)");
    }
}

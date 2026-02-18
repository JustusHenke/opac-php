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
class MidosIndex
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

    public function search(string $term, int $indexNum): array
    {
        $field = $this->mapIndexNumToField($indexNum);
        $term = $this->normalize($term);

        $db = $this->getDb();
        // Index uses prefix match in legacy? "TERM" matches "TERMIN".
        // Let's use LIKE 'TERM%' for compatibility.
        $stmt = $db->prepare("SELECT DISTINCT doc_id FROM search_index WHERE field = ? AND term LIKE ?");
        $stmt->execute([$field, $term . '%']);
        
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function searchBoolean(string $query, int $indexNum): array
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

    public function getTerms(int $indexNum, string $prefix, int $limit = 100): array
    {
        $field = $this->mapIndexNumToField($indexNum);
        $prefix = $this->normalize($prefix);
        
        $db = $this->getDb();
        $stmt = $db->prepare("SELECT MIN(display_term) as term, COUNT(DISTINCT doc_id) as count 
                                    FROM search_index 
                                    WHERE field = ? AND term >= ? 
                                    GROUP BY term 
                                    ORDER BY term 
                                    LIMIT ?");
        $stmt->execute([$field, $prefix, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTermCount(int $indexNum): int
    {
        $field = $this->mapIndexNumToField($indexNum);
        $db = $this->getDb();
        $stmt = $db->prepare("SELECT COUNT(DISTINCT term) FROM search_index WHERE field = ?");
        $stmt->execute([$field]);
        return (int)$stmt->fetchColumn();
    }

    public function getTermsByOffset(int $indexNum, int $offset, int $limit = 50): array
    {
        $field = $this->mapIndexNumToField($indexNum);
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

    public function getRecord(int $docId): ?string
    {
        if ($docId < 1) return null;
        $pd2File = $this->dataDir . DIRECTORY_SEPARATOR . 'pdok.pd2';
        $pdkFile = $this->dataDir . DIRECTORY_SEPARATOR . 'pdok.pdk';

        if (!file_exists($pd2File) || !file_exists($pdkFile)) return null;

        $offsetPos = ($docId - 1) * 11;
        $fp = fopen($pd2File, 'rb');
        if (!$fp) return null;
        if (fseek($fp, $offsetPos) !== 0) { fclose($fp); return null; }
        $offsetStr = fread($fp, 10);
        fclose($fp);

        if ($offsetStr === false || strlen($offsetStr) < 10) return null;
        $offset = (int)$offsetStr;

        $fp = fopen($pdkFile, 'rb');
        if (!$fp) return null;
        if (fseek($fp, $offset) !== 0) { fclose($fp); return null; }
        $line = fgets($fp);
        fclose($fp);

        return $line !== false ? rtrim($line) : null;
    }

    private function mapIndexNumToField(int $num): string
    {
        return match($num) {
            1 => 'qp',
            2 => 'qt',
            4 => 'qs',
            6 => 'qj',
            18 => 'qa',
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
        
        $stmt = $db->prepare("INSERT INTO search_index (term, display_term, field, doc_id) VALUES (?, ?, ?, ?)");
        $fp = fopen($pdkFile, 'rb');
        $docId = 0;
        $db->beginTransaction();
        
        while (($line = fgets($fp)) !== false) {
            $docId++;
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
            // Abstract words
            $abs = $fields['ABS'] ?? ($fields['ZUS'] ?? '');
            foreach (array_unique(preg_split('/[^a-zA-Z0-9ÄÖÜäöüß]+/', $abs, -1, PREG_SPLIT_NO_EMPTY)) as $w) {
                $norm = $this->normalize($w);
                if (strlen($norm) > 3) $stmt->execute([$norm, $w, 'qa', $docId]);
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

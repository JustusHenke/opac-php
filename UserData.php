<?php
declare(strict_types=1);

/**
 * Class UserData
 * Handles storage of private user notes and document profiles using SQLite.
 */
class UserData
{
    private PDO $db;

    public function __construct(string $dataDir)
    {
        $dbFile = rtrim($dataDir, '/\\') . DIRECTORY_SEPARATOR . 'user_data.db';
        $this->db = new PDO("sqlite:$dbFile");
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->initSchema();
    }

    private function initSchema(): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS notes (
            username TEXT,
            doc_id INTEGER,
            content TEXT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (username, doc_id)
        )");

        $this->db->exec("CREATE TABLE IF NOT EXISTS profiles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT,
            profile_name TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $this->db->exec("CREATE TABLE IF NOT EXISTS profile_items (
            profile_id INTEGER,
            doc_id INTEGER,
            PRIMARY KEY (profile_id, doc_id),
            FOREIGN KEY (profile_id) REFERENCES profiles(id) ON DELETE CASCADE
        )");

        $this->db->exec("CREATE TABLE IF NOT EXISTS users (
            username TEXT PRIMARY KEY,
            password_hash TEXT,
            first_name TEXT,
            last_name TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    }

    // --- Users ---

    public function registerUser(string $username, string $password, string $first = '', string $last = ''): bool
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        try {
            $stmt = $this->db->prepare("INSERT INTO users (username, password_hash, first_name, last_name) VALUES (?, ?, ?, ?)");
            return $stmt->execute([$username, $hash, $first, $last]);
        } catch (PDOException $e) {
            return false; // Typically username already exists
        }
    }

    public function authenticateUser(string $username, string $password): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            return $user;
        }
        return null;
    }

    public function userExists(string $username): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM users WHERE username = ?");
        $stmt->execute([$username]);
        return (bool)$stmt->fetchColumn();
    }

    // --- Notes ---

    public function getNote(string $username, int $docId): ?string
    {
        $stmt = $this->db->prepare("SELECT content FROM notes WHERE username = ? AND doc_id = ?");
        $stmt->execute([$username, $docId]);
        return $stmt->fetchColumn() ?: null;
    }

    public function saveNote(string $username, int $docId, string $content): void
    {
        $content = trim($content);
        if ($content === '') {
            $stmt = $this->db->prepare("DELETE FROM notes WHERE username = ? AND doc_id = ?");
            $stmt->execute([$username, $docId]);
        } else {
            $stmt = $this->db->prepare("INSERT INTO notes (username, doc_id, content, updated_at) 
                VALUES (?, ?, ?, CURRENT_TIMESTAMP)
                ON CONFLICT(username, doc_id) DO UPDATE SET content = excluded.content, updated_at = CURRENT_TIMESTAMP");
            $stmt->execute([$username, $docId, $content]);
        }
    }

    // --- Profiles ---

    public function getProfiles(string $username): array
    {
        $stmt = $this->db->prepare("SELECT * FROM profiles WHERE username = ? ORDER BY created_at DESC");
        $stmt->execute([$username]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getProfileItems(int $profileId): array
    {
        $stmt = $this->db->prepare("SELECT doc_id FROM profile_items WHERE profile_id = ?");
        $stmt->execute([$profileId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function createProfile(string $username, string $name, array $docIds): int
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("INSERT INTO profiles (username, profile_name) VALUES (?, ?)");
            $stmt->execute([$username, trim($name)]);
            $id = (int)$this->db->lastInsertId();

            $stmt = $this->db->prepare("INSERT INTO profile_items (profile_id, doc_id) VALUES (?, ?)");
            foreach ($docIds as $docId) {
                $stmt->execute([$id, (int)$docId]);
            }
            $this->db->commit();
            return $id;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function deleteProfile(int $id, string $username): void
    {
        $stmt = $this->db->prepare("DELETE FROM profiles WHERE id = ? AND username = ?");
        $stmt->execute([$id, $username]);
    }
}

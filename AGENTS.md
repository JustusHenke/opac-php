# AGENTS.md — OPAC-PHP

## Project overview

MIDOS-WEB-Retrieval: a PHP 8.1+ academic document search/management app (OPAC). Reads MIDOS-format documents from `data/midos/pdok.pdk` (flatfile), provides full-text + fielded search via an SQLite index, and stores user data (accounts, notes, profiles) in a separate SQLite database.

**No build system, no test framework, no package manager.** Deploy by pointing a web server (Apache/Nginx + PHP-FPM) at the project root.

## Directory layout

```
config.php          # Shared config, helpers, render_header/footer, CSRF, auth, pdok record formatter
UserData.php        # UserData class — SQLite schema + user/notes/profile CRUD
MidosIndex.php      # MidosIndex class — SQLite index rebuild, search, boolean queries, record fetch
index.php           # Entry point → redirects to mlogin.php
maske.php           # Search mask (multi-field form)
msuche.php          # Search results page
mindex.php          # A-Z index browsing (persons, titles, keywords, journals, years)
mlogin.php          # Login/logout (session-based)
mregister.php       # User registration
mprofiles.php       # Document profiles/collections management
mnote.php           # Per-document note editor
mtools.php          # Cart + BibTeX export
data/midos/         # Runtime data: pdok.pdk, index.db, user_data.db, stat.log
```

## Architecture & data flow

1. **Request** hits any `m*.php` page → `config.php` sets up session, CSRF helpers, paths.
2. **Auth**: `check_auth()` redirects to `mlogin.php` if `$_SESSION['username']` is empty. Guest users have `userid = 'guest'`.
3. **Search** (`msuche.php`):
   - Specific-field queries (`qt`, `qp`, `qj`, `qs`) → routed through `MidosIndex::searchBoolean()` against the SQLite `search_index` table (prefix match via `LIKE 'term%'`).
   - Free-text only (`q` with no field queries) → full sequential scan of `pdok.pdk`.
   - Results capped at 1000.
4. **Index rebuild**: `MidosIndex` constructor calls `ensureIndexIsFresh()` which compares `pdok.pdk` mtime vs `index.db` mtime. If PDK is newer, `rebuild()` recreates the entire SQLite index from scratch.
5. **Record fetch**: `MidosIndex::getRecord($docId)` looks up `byte_offset` from `doc_offsets` table, then `fseek`+`fgets` on `pdok.pdk`.
6. **Encoding**: `pdok.pdk` is **ISO-8859-1**. All reads convert to UTF-8 via `mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1')`.
7. **Data format**: Each line in `pdok.pdk` is `Feld:Wert¿Feld:Wert¿...` (¿ as field separator). Parsed by `parse_pdok_fields()` in `config.php`.

## SQLite schemas

### `index.db` (MidosIndex)
- `search_index(term, display_term, field, doc_id)` — inverted index. `field` values: `qp` (persons), `qt` (title words), `qs` (keywords), `qj` (journals), `qy` (years).
- `doc_offsets(doc_id INTEGER PRIMARY KEY, byte_offset INTEGER)` — maps document sequence number to byte offset in `pdok.pdk`.

### `user_data.db` (UserData)
- `users(username PK, password_hash, first_name, last_name, created_at)`
- `notes(username PK, doc_id PK, content, updated_at)` — upsert on conflict.
- `profiles(id PK AUTOINCREMENT, username, profile_name, created_at)`
- `profile_items(profile_id PK, doc_id PK)` — CASCADE DELETE on profile removal.

## Key conventions

- **All files**: `<?php declare(strict_types=1);` + `require __DIR__ . '/config.php';` at top.
- **Auth-guarded pages**: call `check_auth()` immediately after config.
- **Parameter access**: use `req('key', $default)` helper — checks `$_POST` then `$_GET`.
- **Redirects**: use `redirect($url)` helper (header + exit).
- **XSS defense**: all output goes through `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`.
- **CSRF**: `get_csrf_token()` / `validate_csrf_token()` on all state-changing POSTs.
- **Session cart**: `$_SESSION['cart']` is an array of line numbers (1-based doc IDs).
- **CSS**: embedded in `render_header()` via `render_app_header()`. Variables use `--primary`, `--secondary`, etc. class names: `.container`, `.header`, `.btn`, `.btn-primary`, `.search-result`, `.search-result-title`, `.muted`, `.error`, `.success`, `.input-text`, `.field-label`.
- **Translation**: `ueb($text)` is a stub for i18n (currently identity). German UI text throughout.
- **Index normalization**: `MidosIndex::normalize()` uppercases and maps German umlauts (Ä→A, ß→S, etc.).

## MIDOS field mapping (pdok.pdk)

| Field | Meaning | BibTeX key |
|-------|---------|------------|
| HST | Haupttitel (main title) | title |
| TI | Titel (title) | title (fallback) |
| VER | Verfasser (author, pipe-separated) | author |
| ZNA | Zeitschrift (journal) | journal |
| ZJG | Jahrgang (volume) | volume |
| ZHE | Heft (issue) | number |
| ERJ / JA | Jahr (year) | year |
| KOL | Kolumnen (pages) | pages |
| ORT | Ort (address) | address |
| ISBN / ISSN | — | isbn / issn |
| ABS / ZUS | Abstract | abstract |
| SIG | Signatur | — |
| SW / OSW / FISSW | Schlagwörter (keywords) | — |
| URL | Volltext link | — |
| DTY | Dokumenttyp (ZA→article, Druckwerk→book) | type hint |

## Important gotchas

- **`pdok.pdk` is excluded from git** (listed in `.gitignore`). It must exist on the server for search to work.
- **Index auto-rebuild** on every request if `pdok.pdk` is newer than `index.db`. This can be slow for large datasets.
- **Guest users** (`userid = 'guest'`) cannot save notes, create profiles, or register. They get read-only access.
- **Cart is session-only** — not persisted. Lost on session expiry.
- **`mlogin.php` handles both login and logout** via `?action=logout`.
- **`mprofiles.php?action=create_from_cart`** creates a profile from the cart and clears it.
- **`mtools.php?action=export_bibtex`** streams BibTeX directly to the browser (no file creation).
- **`MidosIndex::search()`** uses prefix match (`LIKE 'term%'`) — not exact match.
- **`searchBoolean()`** tokenizes with quoted-string awareness, but does NOT support nested parentheses.
- **`parse_pdok_fields()`** skips fields starting with `@` (internal).

## Commands

No build/lint/test commands. For local dev:

```bash
# Start PHP built-in server
php -S localhost:8080

# Verify PHP syntax on all files
php -l config.php
php -l *.php

# Check PHP version
php -v  # needs to be >= 8.1
```

## Security notes

- Passwords stored with `password_hash()` (bcrypt via `PASSWORD_DEFAULT`).
- Session cookies: `HttpOnly=1`, `SameSite=Lax`.
- `session_regenerate_id(true)` called on login to prevent fixation.
- All SQLite queries use prepared statements.
- Legacy `user.dat` fallback removed; authentication is SQLite-only.

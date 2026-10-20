# 📚 OPAC - MIDOS-WEB-Retrieval (PHP Version)

[![PHP Version](https://img.shields.io/badge/php-%3E%3D%208.1-8892bf.svg)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![Database](https://img.shields.io/badge/Database-SQLite-003b57.svg)](https://www.sqlite.org/)

Dies ist die moderne PHP-Portierung des ursprünglich Perl-basierten **MIDOS-WEB-Retrieval Systems**. Die Anwendung ermöglicht eine effiziente Suche und Verwaltung von Dokumentbeständen auf Basis bewährter MIDOS-Datenstrukturen – optimiert für moderne Web-Umgebungen.

---

## 🚀 Key Features

- **🔍 Intelligente Suche**:
    - Mehrfeld-Suche über `Titel`, `Abstract`, `Quelle` und `Personen`.
    - Volle Unterstützung von Bool-Operatoren (`AND`, `OR`, `NOT`).
    - **Wortgenaue Suche mit Anführungszeichen**: Ein Begriff in doppelten
      Anführungszeichen (`qp="Henke"`) wird nur als eigenständiges Wort
      gefunden – ohne Anführungszeichen bleibt es bei der Teilstring-Suche.
      Damit trennt `"Henke"` sauber von „Schenker", `"Medium"` von „Medien"
      und `"Digitalisierung"` von „hochschulforumdigitalisierung". Wirkt in
      allen Feldern, in der API ebenso wie in „In Treffern suchen".
    - Sequentielle Verarbeitung der `pdok.pdk` Bestände.
    - **Sortierung der Trefferliste** nach Autor, Jahr und Titel – jeweils auf-
      und absteigend. `Relevanz` bleibt die Vorgabe und entspricht unverändert
      der Reihenfolge aus der Suche (`sort=relevance|author_asc|author_desc|
      year_asc|year_desc|title_asc|title_desc`). Datensätze ohne Wert (z. B. ohne
      Verfasser) stehen immer am Ende. Sortiert wird über die Treffer-ID-Menge
      vor dem Laden der Datensätze, nicht über den ganzen Bestand.
    - **„In Treffern suchen“**: ein zweiter Bool-Ausdruck (`AND`/`OR`/`NOT`,
      auch `UND`/`ODER`/`NICHT` in Großschreibung), der nur auf die *angezeigten*
      Treffer wirkt. Die Ausgangsmenge sind exakt die auf der Seite stehenden
      Datensätze (höchstens 1.000, in Anzeigereihenfolge) – eine Verfeinerung
      kann daher nie Treffer außerhalb der Liste liefern und nie versehentlich
      den Gesamtbestand durchsuchen. Die Reihenfolge der Ausgangsmenge bleibt
      erhalten; bei `sort=relevance` also die Relevanzreihenfolge.
    - Die Herkunft eines Datensatzes steht ausschließlich als HTML-Kommentar
      (`<!-- source: bibtex -->`) im Quelltext, nicht als sichtbares Badge.
- **👤 Benutzerverwaltung**:
    - Sichere Registrierung & Login mit **Bcrypt Hashing**.
    - Session-basiertes Rechtesystem inkl. Gastzugang.
    - Abwärtskompatibel zu Legacy-Datenbeständen.
- **🛠️ Interaktive Werkzeuge**:
    - **Warenkorb**: Sammeln von Fundstellen für spätere Bearbeitung.
    - **Sammlungen**: Erstellen permanenter Dokument-Profile.
    - **Notizen**: Persönliche Annotationen direkt am Datensatz.
    - **Export**: BibTeX-Unterstützung für wissenschaftliche Weiterverarbeitung.
- **📑 Index-Browsing**: Komfortables A-Z Browsing durch Personen-, Titel- und Schlagwortregister.
- **🔌 JSON-API**: Rein lesende Fernabfrage des Bestands über `api.php`
    (Suche, Datensatz, Register, Statistik) – ohne das Frontend, optional per
    API-Key geschützt. Siehe [JSON-API](#-json-api-apiphp).

## 📦 Datenquelle: BibTeX (statt MIDOS)

Der Bestand wird aus BibTeX-Dateien im Verzeichnis `data/bib/` aufgebaut:

- Die **neueste** `.bib`-Datei (nach Änderungsdatum) bildet immer den **Vollbestand** ab.
- Import = **Abgleich (Reconcile)**: Neue Einträge werden importiert, geänderte
  aktualisiert (Datensatz-ID bleibt stabil), Einträge, die in der neuesten Datei
  nicht mehr enthalten sind, werden aus dem Bestand entfernt.
- Das Delta dient nur der Statistik und Effizienz — im Zweifel kann über
  `mimport.php` oder CLI ein **Vollimport** erzwungen werden (`php import_bibtex.php --force`).
- Doppelungen innerhalb der Datei werden über Fingerprints (DOI bzw. Titel+Jahr+Erstautor) erkannt.
- Der Abgleich läuft **streamend**: Es ist immer nur ein Eintrag im Speicher. Dadurch bleibt der
  Import auch bei sehr großen Dateien (z. B. 90 MB / 64.000 Einträge) unter ~105 MB Peak-Speicher
  und benötigt ca. 20 s – statt wie früher den mehrfachen Dateispeicher (damalige Folge:
  `Allowed memory size exhausted` → HTTP 500).
- **Große Bestände werden nicht mehr automatisch beim Seitenaufruf importiert.** Ein Request darf
  keinen Import starten, der an `max_execution_time`/`memory_limit` scheitern kann. Stattdessen:
  - Dateien bis 8 MB werden weiterhin automatisch abgeglichen (Obergrenze über die
    Umgebungsvariable `OPAC_AUTO_SYNC_MAX_BYTES` überschreibbar, `0` = nie).
  - Größere Dateien werden nur markiert. Suchmaske und `mimport.php` zeigen dann
    „Import ausstehend“ mit Hinweis auf den manuellen Import.
- **Das Frontend enthält bewusst keinen Import-Link.** Die Suchmaske zeigt ausschließlich
  Bestandsangaben (Einträge im Bestand, Datum der letzten Aktualisierung, Größe der
  zuletzt importierten `.bib`-Datei) und – falls nötig – einen reinen Hinweis auf den
  ausstehenden Abgleich. Ausgelöst wird der Import nur über `mimport.php`
  (Admin-Secret) oder `php import_bibtex.php` (CLI).
- Der Abgleich ist gegen parallele Abgleiche gesichert (Sperrdatei `data/bib/sync.lock`).
- **Jeder Import protokolliert nach `data/bib/import.log`** (angehängt, ein Block je Lauf):
  - Kopf mit Zeitstempel, Quelldatei inkl. Größe und Änderungsdatum, MD5, Modus und Dauer,
  - alle **Dubletten innerhalb der Quelldatei** (Titel, Jahr, Erstautor, DOI, Citekey, Fingerprint)
    – der erste Treffer behält den Datensatz, jeder weitere wird verworfen,
  - alle **aus dem Bestand entfernten Datensätze** (ID, Titel, Jahr, Citekey, DOI, Herkunftsdatei),
  - Abschlusszeile mit `parsed/unique/duplicates/inserted/updated/unchanged/removed/total/errors`.
  - Bei Abbruch werden Rollback und Fehlermeldung protokolliert. Die Detailzeilen je Kategorie und Lauf
    sind auf 20.000 begrenzt, die Summen bleiben immer exakt.
- **Nach einem Bestandswechsel den Import über die Kommandozeile ausführen** – dort gelten
  keine Request-Limits:
  ```bash
  php import_bibtex.php            # Abgleich
  php import_bibtex.php --force    # Vollimport
  ```
- Der alte MIDOS-Pfad (`pdok.pdk`) bleibt über `$DATA_SOURCE = "midos"` in `config.php`
  als Rückfallposition aktivierbar.
- **Import per Admin-Secret geschützt** (es gibt kein Admin-Rollenkonzept): Secret in der
  Datei `.env` im Projekt-Root (`OPAC_ADMIN_SECRET=...`, Vorlage `.env.example`) oder als
  Umgebungsvariable; es muss bei jeder Import-Aktion mitgeliefert werden. Die `.env` ist
  gitignored und per Root-`.htaccess` vor Web-Zugriff geschützt. Ohne Secret ist der Import
  deaktiviert (fail-closed). CLI (`import_bibtex.php`) gilt als serverseitig vertrauenswürdig
  und benötigt kein Secret.
- Der Import ist gegen parallele Abgleiche gesichert (Sperrdatei `data/bib/sync.lock`).

## 🔌 JSON-API (`api.php`)

Der OPAC stellt eine schlanke, **rein lesende** JSON-API bereit. Damit lassen
sich Bestandsabfragen fernab des HTML-Frontends ausführen – aus Skripten, R,
Python, cron-Jobs oder eigenen Anwendungen.

> **Kurzanleitung im Web:** Die Seite `mapi.php` erklärt die Schnittstelle im
> OPAC selbst und ist über den Menüpunkt **API** erreichbar (auch ohne
> Anmeldung). Die dort angezeigte Basisadresse wird dynamisch aus der laufenden
> Anfrage ermittelt (HTTP/HTTPS, Unterverzeichnis, Reverse-Proxy); über die
> Umgebungsvariable `OPAC_BASE_URL` lässt sie sich fest vorgeben.

Alle Antworten sind UTF-8-JSON mit dem einheitlichen Umschlag:

```json
{ "ok": true, "data": { … }, "meta": { … } }
```

Fehler liefern `{"ok":false,"error":{"code":…,"message":…}}` und einen passenden
HTTP-Status (400/401/404/405/500). Die API ist **nur lesend**: sie ändert keine
Datensätze und startet keine Importe. Bestandsänderungen laufen weiterhin über
`mimport.php` bzw. `import_bibtex.php` (CLI).

### Authentifizierung (optional)

Ist `OPAC_API_KEY` konfiguriert (`.env`, Vorlage `.env.example`, oder als
Umgebungsvariable), muss der Schlüssel bei **jeder** Anfrage mitgeliefert
werden – sonst antwortet die API mit HTTP 401. Übergabe (eine Variante genügt):

- Header `X-API-Key: <key>`
- Header `Authorization: Bearer <key>`
- Query-Parameter `?key=<key>`

Ist **kein** Schlüssel gesetzt, ist die API offen (fail-open, nur lesend).
Schlüssel generieren: `openssl rand -hex 24`.

### Endpunkte

| Aktion | Parameter | Beschreibung |
| --- | --- | --- |
| `search` | `q`, `qt`, `qa`, `qp`, `qj`, `qs`, `qy`, `sort`, `limit`, `offset`, `in`, `inf` | Mehrfeld-Suche (AND-Verknüpfung der Felder; Bool-Syntax `AND`/`OR`/`NOT` je Feld) |
| `record` | `id` | Einzelner Datensatz |
| `records` | `ids` (kommagetrennt, max. 500) | Mehrere Datensätze in einem Aufruf |
| `index` | `idx` (`qp`/`qt`/`qs`/`qj`/`qy`), `prefix`, `limit`, `offset` | A–Z-Register (Personen, Titelwörter, Schlagwörter, Zeitschriften, Jahre) |
| `stats` | – | Bestandskennzahlen (Einträge, letzter Abgleich, Quelldatei, ausstehender Import) |

**Suchfelder**: `q` = Freitext (alle Felder), `qt` = Titel, `qa` = Zusammenfassung,
`qp` = Person(en)/Verfasser, `qj` = Zeitschrift/Quelle, `qs` = Schlagwort, `qy` = Jahr.
Alle gesetzten Felder werden per AND kombiniert; ein gesetztes Feld ohne Treffer
leert das Gesamtergebnis.

**Teilstring vs. wortgenau**: Ohne Anführungszeichen sucht ein Begriff als
Teilstring (auch mitten im Wort), mit Anführungszeichen wortgenau:

| Anfrage | Bedeutung | Beispiel |
| --- | --- | --- |
| `qp=Henke` | Teilstring | findet auch „Schenker" |
| `qp="Henke"` | wortgenau (URL-kodiert: `%22Henke%22`) | nur „Henke" |
| `q="Digitalisierung"` | ganzes Wort | ohne „hochschulforumdigitalisierung" |

Die Anführungszeichen wirken in allen Feldern; innerhalb eines Feldes lassen
sie sich mit `AND`/`OR`/`NOT` kombinieren, z. B. `qp="Henke" AND qy=2022`.

**Sortierung** (`sort`): `relevance` (Vorgabe), `author_asc`, `author_desc`,
`year_asc`, `year_desc`, `title_asc`, `title_desc`.

**Paginierung**: `limit` (0–1000, Vorgabe 20; `0` = bis zum Sicherheitsdeckel 1000)
und `offset` (ab 0).

**„In Treffern suchen“** (Refine): `in` = Bool-Ausdruck, `inf` = Feld
(`q`/`qt`/`qa`/`qp`/`qj`/`qs`/`qy`). Der Ausdruck wirkt ausschließlich auf die
Treffermenge des Suchauftrags – nie auf den Gesamtbestand. Anders als im Frontend
ist die Ausgangsmenge hier die volle Treffermenge (nicht nur die angezeigte Seite).

### Beispiele

```bash
# Freitext-Suche, 20 Treffer, als JSON
curl "https://opac.example.org/api.php?action=search&q=Digitalisierung"

# Titelsuche, neueste zuerst, Seite 2 (je 25 Treffer)
curl "https://opac.example.org/api.php?action=search&qt=Hochschule&sort=year_desc&limit=25&offset=25"

# Verfeinern: in den Digitalisierungs-Treffern nur solche zu „Medien“
curl "https://opac.example.org/api.php?action=search&q=Digitalisierung&in=Medien&inf=q"

# Einzelner Datensatz
curl "https://opac.example.org/api.php?action=record&id=97"

# Personen-Register, alle „Sch…“
curl "https://opac.example.org/api.php?action=index&idx=qp&prefix=Sch&limit=50"

# Mit API-Key (Header)
curl -H "X-API-Key: $OPAC_API_KEY" "https://opac.example.org/api.php?action=stats"
```

```r
# R: Bestand durchsuchen
library(jsonlite)
res <- fromJSON("https://opac.example.org/api.php?action=search&q=Digitalisierung&limit=100")
res$data$total
res$data$records[, c("id", "title", "year", "doi")]
```

```python
# Python
import requests
r = requests.get("https://opac.example.org/api.php",
                 params={"action": "search", "qt": "Hochschule", "limit": 50},
                 headers={"X-API-Key": "…"}).json()
for rec in r["data"]["records"]:
    print(rec["id"], rec["title"], rec["year"])
```

### Datensatzformat

Jeder Treffer enthält u. a. `id`, `citekey`, `entry_type`, `type_label`, `title`,
`subtitle`, `authors`, `editors`, `journal`, `year`, `volume`, `issue`, `pages`,
`publisher`, `location`, `url`, `doi`, `isbn_issn`, `language`, `abstract`,
`keywords` sowie das Feld `fields` mit den **unveränderten Originalfeldern**
(z. B. direkt für einen BibTeX-Export nutzbar). Frontend-Markup (`html`) und der
Volltextblob (`alltext`) werden in der API bewusst weggelassen.

## 🛠 Tech Stack

- **Backend**: PHP 8.1+ (Strict Typing)
- **Frontend**: Vanilla PHP, CSS3 (Modern UI), JavaScript (Fetch API)
- **Storage**: SQLite 3 (Userdata & Metadata), MIDOS PDK (Flatfile Document Data)
- **Server**: Apache / Nginx (PHP-FPM)

## 📦 Installation & Setup

1. **Repository klonen**
   ```bash
   git clone https://github.com/JustusHenke/opac-php.git
   cd opac-php
   ```

2. **Server-Konfiguration**
   Lassen Sie das Webroot Ihres Servers auf das Projektverzeichnis zeigen.

3. **Berechtigungen anpassen**
   Stellen Sie sicher, dass PHP Schreibrechte für das Datenverzeichnis hat:
   ```bash
   chmod -R 775 ./data/midos/
   ```

4. **Konfiguration prüfen**
   Passen Sie die Pfade und Einstellungen in der `config.php` an.

## 🛡️ Security Features

Die Anwendung wurde nach modernen Sicherheitsstandards gehärtet:
- ✅ **CSRF Protection**: Tokens für alle zustandsändernden Aktionen.
- ✅ **Session Security**: Automatische `session_regenerate_id` und sichere Cookie-Flags (`HttpOnly`, `SameSite=Lax`).
- ✅ **SQLi Prevention**: Einsatz von Prepared Statements für alle SQLite-Interaktionen.
- ✅ **XSS Defense**: Konsistentes Escaping über `htmlspecialchars`.

> [!CAUTION]
> **Legacy Password Warning**: Der Klartext-Fallback in `config.php` ist nur für die Migration gedacht. Verwenden Sie für neue Setups ausschließlich das integrierte SQLite-System.

## 📄 Lizenz

Dieses Projekt ist unter der **MIT Lizenz** lizenziert. Siehe [LICENSE](LICENSE) für Details.

---

<p align="center">
  <i>Portiert mit ❤️ von Perl zu PHP.</i>
</p>

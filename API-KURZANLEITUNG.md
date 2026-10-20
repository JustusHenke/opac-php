# OPAC-Schnittstelle (JSON-API) – Kurzanleitung

Der OPAC des [Hochschulforschungsschwerpunkts](https://hof.uni-halle.de/) stellt
seinen Bestand auch als **offene Schnittstelle (API)** bereit. Damit lassen sich
Suchabfragen automatisiert durchführen – aus Skripten, eigener Software, R,
Python oder Tabellenkalkulationen. Das Datenformat ist JSON.

**Basisadresse:** `https://hof.uni-halle.de/opac/api.php`

## Grundprinzip

Jede Anfrage ist eine **GET-Anfrage** an `api.php` mit dem Parameter `action`.
Alle Antworten sind JSON. Eine erfolgreiche Antwort ist so aufgebaut:

```json
{
  "ok": true,
  "data": { … },
  "meta": { … }
}
```

Tritt ein Fehler auf, steht in `ok` der Wert `false` und das Feld `error`
enthält eine kurze Erklärung:

```json
{ "ok": false, "error": { "code": "no_criteria", "message": "…" } }
```

Die Schnittstelle ist **rein lesend** – über die API lässt sich der Bestand
abfragen, aber nichts verändern oder löschen.

## Aktionen im Überblick

| Aktion | Zweck |
| --- | --- |
| `search` | Dokumente suchen |
| `record` | Einen einzelnen Datensatz abrufen |
| `records` | Mehrere Datensätze auf einmal abrufen |
| `index` | Register durchblättern (z. B. alle Autorinnen und Autoren) |
| `stats` | Kennzahlen des Bestands abrufen |

---

## 1. Suchen (`action=search`)

```
https://hof.uni-halle.de/opac/api.php?action=search&q=Digitalisierung
```

### Suchfelder

Sie können mehrere Felder gleichzeitig angeben; die Bedingungen werden dann
miteinander **verknüpft** (alle müssen zutreffen).

| Parameter | Bedeutung |
| --- | --- |
| `q` | Freitext – durchsucht den gesamten Datensatz |
| `qt` | Titel |
| `qa` | Zusammenfassung |
| `qp` | Person / Verfasserin bzw. Verfasser |
| `qj` | Zeitschrift / Quelle |
| `qs` | Schlagwort |
| `qy` | Erscheinungsjahr |

Beispiel – Titelstichwort „Hochschule" aus dem Jahr 2024:

```
https://hof.uni-halle.de/opac/api.php?action=search&qt=Hochschule&qy=2024
```

**Mehrere Wörter pro Feld:** Innerhalb eines Feldes lassen sich Begriffe mit
`AND`, `OR` und `NOT` verbinden (Auswertung von links nach rechts, ohne
Klammern). Begriffe mit Leerzeichen werden in Anführungszeichen gesetzt:

```
…?action=search&qt=Lehre AND Evaluation NOT Rezension
…?action=search&qp="Preiß, Christine"
```

### Genaue Suche mit Anführungszeichen

Ein Begriff in doppelten Anführungszeichen wird **wortgenau** gesucht – er muss
als eigenständiges Wort vorkommen. Ohne Anführungszeichen wird jedes Vorkommen
gefunden, auch mitten in einem längeren Wort.

| Suche | Findet | Findet nicht |
| --- | --- | --- |
| `qp=Henke` | „Henke", aber auch „Sc**henke**r" | – |
| `qp="Henke"` | nur „Henke" | „Schenker" |
| `q="Medien"` | nur „Medien" | „Medium" |
| `q="Digitalisierung"` | „Digitalisierung" als Wort | „hochschulforumdigitalisierung" |

Praktisch ist die wortgenaue Suche vor allem bei Personennamen und kurzen
Begriffen. In der Adresszeile des Browsers müssen die Anführungszeichen als
`%22` geschrieben werden:

```
…?action=search&qp=%22Henke%22
…?action=search&qp=%22Henke%22&qy=2022
```

In Programmen (R, Python, curl) können die Anführungszeichen direkt geschrieben
werden – die Kodierung übernimmt die jeweilige Bibliothek.

### Sortierung (`sort`)

| Wert | Reihenfolge |
| --- | --- |
| `relevance` | Relevanz (Standard) |
| `author_asc` / `author_desc` | Autorin/Autor auf- bzw. absteigend |
| `year_asc` / `year_desc` | Jahr auf- bzw. absteigend |
| `title_asc` / `title_desc` | Titel auf- bzw. absteigend |

### Ergebnisse begrenzen und blättern

| Parameter | Bedeutung |
| --- | --- |
| `limit` | Anzahl der Ergebnisse (Standard 20, maximal 1000) |
| `offset` | Ergebnisse überspringen – zum seitenweisen Blättern |

Beispiel – die zweite Seite mit je 25 Ergebnissen, neueste zuerst:

```
…?action=search&q=Digitalisierung&sort=year_desc&limit=25&offset=25
```

### Innerhalb der Treffer weitersuchen (`in`)

Mit `in` lässt sich ein zweiter Suchbegriff auf das bereits gefundene Ergebnis
anwenden; `inf` legt fest, in welchem Feld gesucht wird (Standard: `q`). Auch
hier wirkt die Anführungszeichen-Syntax.

```
…?action=search&q=Digitalisierung&in=Medien&inf=q
```

### Die Antwort

`data` enthält u. a.:

- `total` – Gesamtzahl der Treffer
- `count` – Anzahl der in dieser Antwort enthaltenen Datensätze
- `ids` – die Datensatz-Nummern der Treffer
- `records` – die Datensätze selbst

Jeder Datensatz enthält unter anderem `id`, `title`, `authors`, `journal`,
`year`, `volume`, `issue`, `pages`, `doi`, `url`, `abstract` und `keywords`.

---

## 2. Einzelnen Datensatz abrufen (`action=record`)

```
…?action=record&id=97
```

Die `id` entnehmen Sie den Suchergebnissen (Feld `ids`) oder der Trefferliste im
OPAC („Datensatz-ID").

## 3. Mehrere Datensätze abrufen (`action=records`)

```
…?action=records&ids=12,15,97
```

Es können bis zu 500 IDs in einer Anfrage übergeben werden.

## 4. Register durchblättern (`action=index`)

```
…?action=index&idx=qp&prefix=Sch&limit=50
```

| Parameter | Bedeutung |
| --- | --- |
| `idx` | Register: `qp` = Personen, `qt` = Titelwörter, `qs` = Schlagwörter, `qj` = Zeitschriften, `qy` = Jahre |
| `prefix` | nur Einträge, die mit diesem Text beginnen |
| `limit` / `offset` | Anzahl / Position (zum Blättern) |

## 5. Bestandskennzahlen (`action=stats`)

```
…?action=stats
```

Liefert u. a. die Anzahl der Datensätze, den Zeitpunkt der letzten Aktualisierung
und die Datenquelle.

---

## Beispiele

### Browser / Adresszeile

Einfach die Beispiele oben direkt in die Adresszeile des Browsers einfügen.

### R

```r
library(jsonlite)

res <- fromJSON("https://hof.uni-halle.de/opac/api.php?action=search&q=Digitalisierung&limit=100")
res$data$total                                  # Anzahl der Treffer
res$data$records[, c("id", "title", "year", "doi")]

# Wortgenaue Suche nach einer Person
res2 <- fromJSON('https://hof.uni-halle.de/opac/api.php?action=search&qp=%22Henke%22')
```

### Python

```python
import requests

r = requests.get(
    "https://hof.uni-halle.de/opac/api.php",
    params={"action": "search", "qt": "Hochschule", "limit": 50},
).json()

for rec in r["data"]["records"]:
    print(rec["id"], rec["year"], rec["title"])
```

### Kommandozeile

```bash
curl "https://hof.uni-halle.de/opac/api.php?action=search&q=Digitalisierung&limit=5"
curl "https://hof.uni-halle.de/opac/api.php?action=search&qp=%22Henke%22"
```

---

## Zugangsschlüssel

Die Schnittstelle ist **frei zugänglich** und ohne Anmeldung nutzbar. Sollte für
Ihre Nutzung ein Zugangsschlüssel erforderlich sein, wird er bei jeder Anfrage
mitgeliefert – auf eine der folgenden Arten:

- als Header `X-API-Key: <Schlüssel>`
- als Header `Authorization: Bearer <Schlüssel>`
- als Parameter `?key=<Schlüssel>`

Ob ein Schlüssel nötig ist und wie Sie ihn erhalten, erfahren Sie bei den
[Betreibern des OPAC](https://hof.uni-halle.de/).

## Hinweise zur Nutzung

- Bitte beschränken Sie umfangreiche automatische Abfragen auf das Nötige und
  verteilen Sie viele Anfragen zeitlich, damit der Dienst für alle verfügbar
  bleibt.
- Ein `limit` von 1000 Ergebnissen pro Anfrage sollte nicht überschritten
  werden; nutzen Sie für größere Mengen `offset`.
- Die API ist ausschließlich lesend. Änderungen am Bestand sind über sie nicht
  möglich.

---

*Bei Fragen oder für einen Zugangsschlüssel wenden Sie sich bitte an die
Betreiber des OPAC.*

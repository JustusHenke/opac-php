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
    - Sequentielle Verarbeitung der `pdok.pdk` Bestände.
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

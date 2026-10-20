<?php
declare(strict_types=1);

/**
 * Dokumentationsseite zur JSON-API (mapi.php).
 *
 * Bewusst OHNE check_auth(): die API ist offen und rein lesend, die Erklärung
 * dazu soll jeder lesen können. Die Basis-URL wird dynamisch aus der laufenden
 * Anfrage ermittelt (opac_base_url()), damit die Beispiele auch in einem
 * Unterverzeichnis oder hinter einem Proxy stimmen.
 */

require __DIR__ . '/config.php';

$apiUrl = opac_api_url();
$h = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

render_header($HTML_TITLE . ' – API');
render_app_header(ueb('Schnittstelle (API)'));
?>

<div class="search-box" style="margin-bottom: 20px;">
    <h2 style="margin-top: 0;"><?= $h(ueb('OPAC-Schnittstelle (JSON-API)')) ?></h2>

    <p>
        <?= $h(ueb('Der Bestand des OPAC ist auch über eine offene Schnittstelle abrufbar. Damit lassen sich Suchabfragen automatisiert durchführen – aus Skripten, eigener Software, R, Python oder Tabellenkalkulationen. Das Datenformat ist JSON.')) ?>
    </p>

    <p>
        <strong><?= $h(ueb('Basisadresse:')) ?></strong>
        <code><?= $h($apiUrl) ?></code>
    </p>

    <p class="status-muted" style="margin-bottom: 16px;">
        <?= $h(ueb('Rein lesender Zugriff – über die Schnittstelle lässt sich der Bestand abfragen, aber nichts verändern.')) ?>
    </p>

    <h3><?= $h(ueb('Aufbau einer Anfrage')) ?></h3>
    <p>
        <?= $h(ueb('Jede Anfrage ist eine GET-Anfrage mit dem Parameter')) ?>
        <code>action</code>.
        <?= $h(ueb('Eine erfolgreiche Antwort liefert')) ?>
        <code>{"ok": true, "data": …}</code>,
        <?= $h(ueb('im Fehlerfall')) ?>
        <code>{"ok": false, "error": …}</code>.
    </p>

    <h3><?= $h(ueb('Aktionen')) ?></h3>
    <table style="border-collapse: collapse; width: 100%; max-width: 720px; margin-bottom: 16px;">
        <thead>
            <tr>
                <th style="text-align: left; padding: 6px 12px 6px 0; border-bottom: 1px solid var(--border);"><?= $h(ueb('Aktion')) ?></th>
                <th style="text-align: left; padding: 6px 0; border-bottom: 1px solid var(--border);"><?= $h(ueb('Zweck')) ?></th>
            </tr>
        </thead>
        <tbody>
            <tr><td style="padding: 6px 12px 6px 0;"><code>search</code></td><td style="padding: 6px 0;"><?= $h(ueb('Dokumente suchen')) ?></td></tr>
            <tr><td style="padding: 6px 12px 6px 0;"><code>record</code></td><td style="padding: 6px 0;"><?= $h(ueb('Einen einzelnen Datensatz abrufen')) ?></td></tr>
            <tr><td style="padding: 6px 12px 6px 0;"><code>records</code></td><td style="padding: 6px 0;"><?= $h(ueb('Mehrere Datensätze auf einmal abrufen')) ?></td></tr>
            <tr><td style="padding: 6px 12px 6px 0;"><code>index</code></td><td style="padding: 6px 0;"><?= $h(ueb('Register durchblättern (z. B. alle Autorinnen und Autoren)')) ?></td></tr>
            <tr><td style="padding: 6px 12px 6px 0;"><code>stats</code></td><td style="padding: 6px 0;"><?= $h(ueb('Kennzahlen des Bestands abrufen')) ?></td></tr>
        </tbody>
    </table>

    <h3><?= $h(ueb('Suchen')) ?></h3>
    <p>
        <?= $h(ueb('Suchbegriffe werden über benannte Felder übergeben; mehrere Felder werden miteinander verknüpft (alle müssen zutreffen).')) ?>
    </p>
    <table style="border-collapse: collapse; width: 100%; max-width: 720px; margin-bottom: 16px;">
        <thead>
            <tr>
                <th style="text-align: left; padding: 6px 12px 6px 0; border-bottom: 1px solid var(--border);"><?= $h(ueb('Parameter')) ?></th>
                <th style="text-align: left; padding: 6px 0; border-bottom: 1px solid var(--border);"><?= $h(ueb('Bedeutung')) ?></th>
            </tr>
        </thead>
        <tbody>
            <tr><td style="padding: 6px 12px 6px 0;"><code>q</code></td><td style="padding: 6px 0;"><?= $h(ueb('Freitext – durchsucht den gesamten Datensatz')) ?></td></tr>
            <tr><td style="padding: 6px 12px 6px 0;"><code>qt</code></td><td style="padding: 6px 0;"><?= $h(ueb('Titel')) ?></td></tr>
            <tr><td style="padding: 6px 12px 6px 0;"><code>qa</code></td><td style="padding: 6px 0;"><?= $h(ueb('Zusammenfassung')) ?></td></tr>
            <tr><td style="padding: 6px 12px 6px 0;"><code>qp</code></td><td style="padding: 6px 0;"><?= $h(ueb('Person / Verfasserin bzw. Verfasser')) ?></td></tr>
            <tr><td style="padding: 6px 12px 6px 0;"><code>qj</code></td><td style="padding: 6px 0;"><?= $h(ueb('Zeitschrift / Quelle')) ?></td></tr>
            <tr><td style="padding: 6px 12px 6px 0;"><code>qs</code></td><td style="padding: 6px 0;"><?= $h(ueb('Schlagwort')) ?></td></tr>
            <tr><td style="padding: 6px 12px 6px 0;"><code>qy</code></td><td style="padding: 6px 0;"><?= $h(ueb('Erscheinungsjahr')) ?></td></tr>
        </tbody>
    </table>

    <p>
        <?= $h(ueb('Innerhalb eines Feldes lassen sich Begriffe mit')) ?>
        <code>AND</code>, <code>OR</code> <?= $h(ueb('und')) ?> <code>NOT</code>
        <?= $h(ueb('verbinden. Begriffe mit Leerzeichen werden in Anführungszeichen gesetzt.')) ?>
    </p>

    <h3><?= $h(ueb('Genaue Suche mit Anführungszeichen')) ?></h3>
    <p>
        <?= $h(ueb('Ein Begriff in doppelten Anführungszeichen wird wortgenau gesucht – er muss als eigenständiges Wort vorkommen. Ohne Anführungszeichen wird jedes Vorkommen gefunden, auch mitten in einem längeren Wort.')) ?>
    </p>
    <table style="border-collapse: collapse; width: 100%; max-width: 720px; margin-bottom: 16px;">
        <thead>
            <tr>
                <th style="text-align: left; padding: 6px 12px 6px 0; border-bottom: 1px solid var(--border);"><?= $h(ueb('Suche')) ?></th>
                <th style="text-align: left; padding: 6px 0; border-bottom: 1px solid var(--border);"><?= $h(ueb('Findet nicht')) ?></th>
            </tr>
        </thead>
        <tbody>
            <tr><td style="padding: 6px 12px 6px 0;"><code>qp="Henke"</code></td><td style="padding: 6px 0;">&bdquo;Schenker&ldquo;</td></tr>
            <tr><td style="padding: 6px 12px 6px 0;"><code>q="Medien"</code></td><td style="padding: 6px 0;">&bdquo;Medium&ldquo;</td></tr>
            <tr><td style="padding: 6px 12px 6px 0;"><code>q="Digitalisierung"</code></td><td style="padding: 6px 0;">&bdquo;hochschulforumdigitalisierung&ldquo;</td></tr>
        </tbody>
    </table>
    <p class="status-muted">
        <?= $h(ueb('In der Adresszeile des Browsers werden die Anführungszeichen als')) ?>
        <code>%22</code> <?= $h(ueb('geschrieben:')) ?>
        <code>…&amp;qp=%22Henke%22</code>.
    </p>

    <p>
        <strong><?= $h(ueb('Sortierung')) ?></strong> (<code>sort</code>):
        <code>relevance</code> (<?= $h(ueb('Standard')) ?>), <code>author_asc</code>,
        <code>author_desc</code>, <code>year_asc</code>, <code>year_desc</code>,
        <code>title_asc</code>, <code>title_desc</code>.
    </p>
    <p>
        <strong><?= $h(ueb('Ergebnisse begrenzen:')) ?></strong> <code>limit</code>
        (<?= $h(ueb('Standard 20, maximal 1000')) ?>) <?= $h(ueb('und')) ?>
        <code>offset</code> (<?= $h(ueb('zum seitenweisen Blättern')) ?>).
    </p>
    <p>
        <strong><?= $h(ueb('In Treffern weitersuchen:')) ?></strong> <code>in</code>
        (<?= $h(ueb('Suchbegriff')) ?>) <?= $h(ueb('und')) ?> <code>inf</code>
        (<?= $h(ueb('Feld, Standard')) ?> <code>q</code>).
    </p>

    <h3><?= $h(ueb('Beispiele')) ?></h3>

    <p class="status-muted" style="margin-bottom: 6px;"><?= $h(ueb('Freitextsuche:')) ?></p>
    <p><code><?= $h($apiUrl) ?>?action=search&amp;q=Digitalisierung</code></p>

    <p class="status-muted" style="margin-bottom: 6px;"><?= $h(ueb('Titelstichwort „Hochschule“, Jahr 2024, neueste zuerst:')) ?></p>
    <p><code><?= $h($apiUrl) ?>?action=search&amp;qt=Hochschule&amp;qy=2024&amp;sort=year_desc</code></p>

    <p class="status-muted" style="margin-bottom: 6px;"><?= $h(ueb('Wortgenaue Personensuche:')) ?></p>
    <p><code><?= $h($apiUrl) ?>?action=search&amp;qp=%22Henke%22</code></p>

    <p class="status-muted" style="margin-bottom: 6px;"><?= $h(ueb('Zweite Ergebnisseite mit je 25 Treffern:')) ?></p>
    <p><code><?= $h($apiUrl) ?>?action=search&amp;q=Digitalisierung&amp;limit=25&amp;offset=25</code></p>

    <p class="status-muted" style="margin-bottom: 6px;"><?= $h(ueb('Einzelner Datensatz:')) ?></p>
    <p><code><?= $h($apiUrl) ?>?action=record&amp;id=97</code></p>

    <p class="status-muted" style="margin-bottom: 6px;"><?= $h(ueb('Personen-Register, alle Einträge mit „Sch…“:')) ?></p>
    <p><code><?= $h($apiUrl) ?>?action=index&amp;idx=qp&amp;prefix=Sch&amp;limit=50</code></p>

    <h3><?= $h(ueb('Zugangsschlüssel')) ?></h3>
    <p>
        <?= $h(ueb('Die Schnittstelle ist frei zugänglich und ohne Anmeldung nutzbar. Sollte für Ihre Nutzung ein Zugangsschlüssel erforderlich sein, wird er bei jeder Anfrage mitgeliefert – als Header')) ?>
        <code>X-API-Key</code>, <?= $h(ueb('als Header')) ?>
        <code>Authorization: Bearer …</code> <?= $h(ueb('oder als Parameter')) ?>
        <code>?key=…</code>.
    </p>

    <h3><?= $h(ueb('Hinweise zur Nutzung')) ?></h3>
    <ul>
        <li><?= $h(ueb('Umfangreiche automatische Abfragen bitte auf das Nötige beschränken und zeitlich verteilen.')) ?></li>
        <li><?= $h(ueb('Pro Anfrage höchstens 1000 Ergebnisse; für größere Mengen offset verwenden.')) ?></li>
        <li><?= $h(ueb('Die Schnittstelle ist ausschließlich lesend.')) ?></li>
    </ul>

    <p class="status-muted" style="margin-bottom: 0;">
        <?= $h(ueb('Bei Fragen oder für einen Zugangsschlüssel wenden Sie sich bitte an die Betreiber des OPAC.')) ?>
    </p>
</div>

<?php
render_footer();

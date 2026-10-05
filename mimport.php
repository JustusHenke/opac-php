<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
check_auth();

global $BIB_DIR, $DATA_SOURCE, $BASE_DIR;

// Statusansicht für alle angemeldeten Nutzer sichtbar; die Import-Aktion
// selbst ist per Admin-Secret geschützt (siehe POST-Zweig unten).
require_once __DIR__ . '/BibLibrary.php';

$action = req('action', 'view');
$message = '';
$error = '';
$lastStats = null;

if ($DATA_SOURCE !== 'bibtex') {
    redirect('maske.php');
}

$bib = new BibLibrary($BIB_DIR);

$secretConfigured = opac_admin_secret() !== '';

if ($action === 'sync' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token(req('csrf_token'))) {
        die('CSRF token mismatch');
    }
    // Admin-Secret ist Pflicht für jede Import-Aktion (kein Rollenkonzept).
    // Wird nur aus POST gelesen, damit es nicht in URLs/Access-Logs landet.
    $secretInput = isset($_POST['admin_secret']) && is_string($_POST['admin_secret'])
        ? $_POST['admin_secret'] : null;
    if (!check_admin_secret($secretInput)) {
        error_log('[mimport] Abgelehnt: falsches/fehlendes Admin-Secret (User: '
            . ($_SESSION['username'] ?? '?') . ')');
        sleep(1); // Bruteforce-Versuche dämpfen
        $error = $secretConfigured
            ? 'Falsches oder fehlendes Admin-Secret.'
            : 'Import deaktiviert: Es ist kein Admin-Secret konfiguriert (config.php / OPAC_ADMIN_SECRET).';
    } else {
        $forceFull = req('mode') === 'full';
        try {
            $lastStats = $bib->sync($forceFull);
            if (($lastStats['status'] ?? '') === 'busy') {
                $error = 'Es läuft bereits ein Import. Bitte kurz warten und erneut versuchen.';
                $lastStats = null;
            } else {
                $message = ueb('Abgleich abgeschlossen.');
            }
        } catch (Throwable $e) {
            // Bewusst generisch: rohe Exception-Meldungen können Pfade enthalten.
            // Details stehen in meta 'last_error' (unten escaped) und im Server-Log.
            error_log('[mimport] ' . get_class($e) . ': ' . $e->getMessage());
            $error = 'Import fehlgeschlagen — Einzelheiten unter „Letzter Fehler“ bzw. im Server-Log.';
        }
    }
}

$stats = $bib->getStats();
$newest = $bib->findNewestBib();
// Protokoll über Dubletten, Entfernungen und Kennzahlen der Importläufe.
$logPath = $bib->importLogPath();

render_header($HTML_TITLE . ' – BibTeX-Import');
render_app_header(ueb('BibTeX-Import'));
?>

<div class="search-box">
    <h3><?= htmlspecialchars(ueb('BibTeX-Bestand importieren')) ?></h3>

    <p class="status-muted" style="margin-bottom: 16px;">
        Der OPAC bezieht seinen Bestand aus BibTeX-Dateien im Verzeichnis
        <code>data/bib/</code>. Die <strong>neueste</strong> Datei bildet dabei immer den
        Vollbestand ab und ist die Quelle der Wahrheit: Beim Abgleich werden neue
        Einträge importiert, geänderte aktualisiert und Einträge, die in der neuesten
        Datei nicht mehr enthalten sind, aus dem Bestand entfernt (Datenbank wird bereinigt).
        Da sich über den Formatwechsel (MIDOS → BibTeX) hinweg kein zuverlässiges Delta
        bilden lässt, kann alternativ ein <strong>Vollimport</strong> erzwungen werden.
    </p>

    <?php if ($message): ?><p class="status-success"><?= htmlspecialchars($message) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="status-error"><?= htmlspecialchars($error) ?></p><?php endif; ?>

    <?php if (!empty($stats['sync_pending'])): ?>
        <p class="status-error">
            <strong>Import ausstehend:</strong> Die neueste Datei
            (<?= htmlspecialchars((string) ($stats['sync_pending']['file'] ?? '?')) ?><?php
                if (!empty($stats['sync_pending']['size'])): ?>,
                <?= number_format(((int) $stats['sync_pending']['size']) / 1048576, 1, ',', '.') ?> MB<?php endif; ?>)
            wurde noch nicht abgeglichen. Sie wird beim Seitenaufruf bewusst <em>nicht</em> automatisch
            importiert – große Bestände sprengen dabei das Zeit-/Speicherlimit der Anfrage (HTTP 500).
            <?php if ($newest !== null && (int) ($newest['size'] ?? 0) > 8 * 1024 * 1024): ?>
            <br><span class="muted">Bei dieser Dateigröße am besten über die Kommandozeile importieren
                (kein Request-Timeout): <code>php import_bibtex.php</code></span>
            <?php endif; ?>
        </p>
    <?php endif; ?>
    <?php if ($stats['last_error'] !== ''): ?>
        <?php // Serverpfade in Fehlermeldungen maskieren ?>
        <p class="status-error">Letzter Fehler: <?= htmlspecialchars(str_replace([$BASE_DIR, str_replace('\\', '/', $BASE_DIR)], '…', $stats['last_error'])) ?></p>
    <?php endif; ?>

    <table style="border-collapse: collapse; margin-bottom: 20px; width: 100%; max-width: 640px;">
        <tr><td style="padding: 6px 12px 6px 0;" class="muted">Einträge im Bestand:</td>
            <td style="padding: 6px 0;"><strong><?= number_format((int) $stats['total'], 0, ',', '.') ?></strong></td></tr>
        <tr><td style="padding: 6px 12px 6px 0;" class="muted">Neueste Quelldatei:</td>
            <td style="padding: 6px 0;"><strong><?= htmlspecialchars($newest['name'] ?? '— (keine .bib gefunden)') ?></strong></td></tr>
        <?php if ($newest !== null): ?>
        <tr><td style="padding: 6px 12px 6px 0;" class="muted">Datei geändert am:</td>
            <td style="padding: 6px 0;"><?= date('d.m.Y H:i', $newest['mtime']) ?></td></tr>
        <?php endif; ?>
        <tr><td style="padding: 6px 12px 6px 0;" class="muted">Letzter Abgleich:</td>
            <td style="padding: 6px 0;"><?= htmlspecialchars($stats['last_sync'] !== '' ? $stats['last_sync'] : '—') ?></td></tr>
        <?php if ($logPath !== ''): ?>
        <tr><td style="padding: 6px 12px 6px 0;" class="muted">Import-Log:</td>
            <td style="padding: 6px 0;">
                <code><?= htmlspecialchars(str_replace([$BASE_DIR, str_replace('\\', '/', $BASE_DIR)], '…', $logPath)) ?></code>
                (<?= is_file($logPath) ? number_format((int) filesize($logPath) / 1024, 1, ',', '.') . ' KB' : 'noch nicht vorhanden' ?>)
            </td></tr>
        <?php endif; ?>
        <?php if (!empty($stats['last_stats']) && is_array($stats['last_stats'])): ?>
        <tr><td style="padding: 6px 12px 6px 0;" class="muted">Ergebnis letzter Abgleich:</td>
            <td style="padding: 6px 0;">
                <?= number_format((int) ($stats['last_stats']['inserted'] ?? 0), 0, ',', '.') ?> neu,
                <?= number_format((int) ($stats['last_stats']['updated'] ?? 0), 0, ',', '.') ?> aktualisiert,
                <?= number_format((int) ($stats['last_stats']['removed'] ?? 0), 0, ',', '.') ?> entfernt,
                <?= number_format((int) ($stats['last_stats']['unchanged'] ?? 0), 0, ',', '.') ?> unverändert
            </td></tr>
        <?php endif; ?>
    </table>

    <?php if (!$secretConfigured): ?>
        <p class="status-error">Der Import ist derzeit <strong>deaktiviert</strong>: Es ist kein Admin-Secret
        konfiguriert. In <code>config.php</code> ($OPAC_ADMIN_SECRET) oder als Umgebungsvariable
        <code>OPAC_ADMIN_SECRET</code> setzen.</p>
    <?php endif; ?>
    <form method="post" action="mimport.php?action=sync">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token()) ?>">
        <div class="form-group">
            <label class="form-label" for="mode">Modus</label>
            <select name="mode" id="mode" class="form-input" style="max-width: 420px;">
                <option value="reconcile">Abgleich mit neuester Datei (Delta: neu/aktualisiert/entfernt)</option>
                <option value="full">Vollimport erzwingen (alles neu importieren, Bestand bereinigen)</option>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label" for="admin_secret">Admin-Secret</label>
            <input type="password" name="admin_secret" id="admin_secret" class="form-input"
                   style="max-width: 420px;" autocomplete="off"
                   placeholder="Secret für Import-Aktionen (config.php / OPAC_ADMIN_SECRET)">
        </div>
        <div style="display: flex; gap: 12px; flex-wrap: wrap;">
            <button type="submit" class="btn btn-primary"<?php if (!$secretConfigured) { echo ' disabled title="Kein Admin-Secret konfiguriert"'; } ?>>Import jetzt ausführen</button>
            <a href="maske.php" class="btn">Abbrechen</a>
        </div>
    </form>

    <?php if ($lastStats !== null && ($lastStats['status'] ?? '') === 'synced'): ?>
        <div style="margin-top: 20px;" class="status-info">
            <strong>Ergebnis:</strong>
            <?= number_format((int) ($lastStats['parsed'] ?? 0), 0, ',', '.') ?> Einträge gelesen
            (<?= number_format((int) ($lastStats['unique'] ?? 0), 0, ',', '.') ?> eindeutig,
            <?= number_format((int) ($lastStats['duplicates'] ?? 0), 0, ',', '.') ?> Duplikate in der Datei) —
            <?= number_format((int) ($lastStats['inserted'] ?? 0), 0, ',', '.') ?> neu,
            <?= number_format((int) ($lastStats['updated'] ?? 0), 0, ',', '.') ?> aktualisiert,
            <?= number_format((int) ($lastStats['removed'] ?? 0), 0, ',', '.') ?> entfernt,
            <?= number_format((int) ($lastStats['unchanged'] ?? 0), 0, ',', '.') ?> unverändert.
            <?php if ((int) ($lastStats['errors'] ?? 0) > 0): ?>
                <br><span class="status-error"><?= (int) $lastStats['errors'] ?> Parsing-Warnungen.</span>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php
render_footer();
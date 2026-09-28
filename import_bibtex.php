<?php
declare(strict_types=1);

/**
 * CLI: BibTeX-Bestand abgleichen (Reconcile mit der neuesten Datei unter data/bib).
 *
 * Aufruf:
 *   php import_bibtex.php            Abgleich (Delta: neu/aktualisiert/entfernt)
 *   php import_bibtex.php --force    Vollimport erzwingen (alles neu, Bestand bereinigt)
 *
 * CLI gilt als serverseitig vertrauenswürdig und benötigt kein Admin-Secret;
 * das Secret-Gate betrifft nur den Web-Import über mimport.php.
 */
if (PHP_SAPI !== 'cli') {
    exit('Nur für CLI.');
}

require __DIR__ . '/config.php';
require_once __DIR__ . '/BibLibrary.php';

global $BIB_DIR, $DATA_SOURCE;

if ($DATA_SOURCE !== 'bibtex') {
    echo "Hinweis: \$DATA_SOURCE steht auf 'midos' – BibTeX-Import ist inaktiv.\n";
    exit(1);
}

$forceFull = in_array('--force', $_SERVER['argv'] ?? [], true);
$bib = new BibLibrary($BIB_DIR);

echo "BibTeX-Bestand " . ($forceFull ? '(Vollimport)' : '(Abgleich)') . " ...\n";
$stats = $bib->sync($forceFull);
if (($stats['status'] ?? '') === 'busy') {
    echo "Hinweis: Es läuft bereits ein Import (Sperrdatei data/bib/sync.lock). Nichts verändert.\n";
    exit(1);
}

printf(
    "Status: %s\n  Datei: %s\n  Gelesen: %d (eindeutig: %d, Duplikate in Datei: %d)\n" .
    "  Neu: %d | Aktualisiert: %d | Entfernt: %d | Unverändert: %d | Bestand: %d | Parser-Hinweise: %d\n",
    $stats['status'] ?? '?',
    $stats['file'] ?? '-',
    $stats['parsed'] ?? 0,
    $stats['unique'] ?? 0,
    $stats['duplicates'] ?? 0,
    $stats['inserted'] ?? 0,
    $stats['updated'] ?? 0,
    $stats['removed'] ?? 0,
    $stats['unchanged'] ?? 0,
    $stats['total'] ?? 0,
    $stats['errors'] ?? 0
);
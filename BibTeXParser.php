<?php
declare(strict_types=1);

/**
 * Robuster BibTeX/BibLaTeX-Parser.
 *
 * Unterstützt:
 * - @article{key, field = value, ...} (auch runde Klammern)
 * - Verschachtelte { } in Werten, "..."-Strings mit { }-Schutz
 * - String-Konkatenation mit #
 * - @string{...}-Definitionen und Standard-Monatsmakros (jan..dec)
 * - @comment / @preamble werden übersprungen
 * - Encoding: UTF-8 (Zotero-/BibLaTeX-Export)
 */
class BibTeXParser
{
    /** @var array<string,string> Makro-Definitionen aus @string */
    private array $macros = [];

    /** @var string[] Fehler/Warnungen beim Parsen */
    public array $errors = [];

    private string $src = '';
    private int $len = 0;
    private int $pos = 0;

    private const MONTHS = [
        'jan' => 'January', 'feb' => 'February', 'mar' => 'March',
        'apr' => 'April',   'may' => 'May',      'jun' => 'June',
        'jul' => 'July',    'aug' => 'August',   'sep' => 'September',
        'oct' => 'October', 'nov' => 'November', 'dec' => 'December',
    ];

    /**
     * Parst eine .bib-Quelle und liefert eine Liste von Einträgen:
     * [['citekey' => string, 'type' => string, 'fields' => array<string,string>], ...]
     *
     * Achtung: Die vollständige Liste liegt danach im Speicher. Für große
     * Bestände stream()-benutzen (siehe dort).
     *
     * @return array<int,array{citekey:string,type:string,fields:array<string,string>}>
     */
    public function parse(string $source): array
    {
        return iterator_to_array($this->stream($source), false);
    }

    /**
     * Parst eine .bib-Quelle eintragsweise als Generator.
     *
     * Der Speicherbedarf bleibt dadurch (bis auf die Quelldatei) konstant und
     * unabhängig von der Anzahl der Einträge. Für große Bestände (z. B. 90 MB
     * mit 60.000+ Einträgen) ist das zwingend: als Array läge die Parse-Ergebnis
     * mehrfach so groß im Speicher wie die Datei und der Import scheitert an
     * memory_limit/max_execution_time.
     *
     * @return Generator<int,array{citekey:string,type:string,fields:array<string,string>}>
     */
    public function stream(string $source): Generator
    {
        $this->errors = [];
        $this->macros = [];

        if (substr($source, 0, 3) === "\xEF\xBB\xBF") {
            $source = substr($source, 3); // BOM entfernen
        }
        // Zeilenenden nur bei Bedarf normalisieren (spart bei reinen LF-Dateien
        // eine Komplettkopie der Quelldatei im Speicher).
        if (strpos($source, "\r") !== false) {
            $source = str_replace(["\r\n", "\r"], "\n", $source);
        }
        $this->src = $source;
        $this->len = strlen($this->src);
        $this->pos = 0;

        while (($at = stripos($this->src, '@', $this->pos)) !== false) {
            $this->pos = $at + 1;
            $this->skipWs();
            $type = strtolower($this->readIdent());
            $this->skipWs();
            if ($this->pos >= $this->len) {
                break;
            }
            $c = $this->src[$this->pos];
            if ($c !== '{' && $c !== '(') {
                continue; // kein gültiger Eintragsbeginn
            }
            $close = $c === '{' ? '}' : ')';
            $this->pos++;

            switch ($type) {
                case 'comment':
                case 'preamble':
                    $this->skipBalanced($close);
                    break;
                case 'string':
                    $this->parseStringDef($close);
                    break;
                default:
                    $entry = $this->parseEntry($close, $type);
                    if ($entry !== null) {
                        yield $entry;
                    }
            }
        }
    }

    // ------------------------------------------------------------------ Innenleben

    private function parseEntry(string $close, string $type): array
    {
        $this->skipWs();
        $citekey = '';
        while ($this->pos < $this->len) {
            $ch = $this->src[$this->pos];
            if ($ch === ',' || $ch === $close) {
                break;
            }
            $citekey .= $ch;
            $this->pos++;
        }
        $citekey = trim($citekey);

        $fields = [];
        while ($this->pos < $this->len) {
            $this->skipWs();
            if ($this->pos >= $this->len) {
                $this->errors[] = "Unerwartetes Ende in Eintrag '{$citekey}'";
                break;
            }
            $ch = $this->src[$this->pos];
            if ($ch === $close) {
                $this->pos++;
                break;
            }
            if ($ch === ',') {
                $this->pos++;
                continue;
            }

            $name = strtolower($this->readIdent());
            $this->skipWs();
            if ($this->pos < $this->len && $this->src[$this->pos] === '=') {
                $this->pos++;
            } else {
                // Ungültiges Feld – bis zum nächsten Komma/Ende springen
                while ($this->pos < $this->len && $this->src[$this->pos] !== ',' && $this->src[$this->pos] !== $close) {
                    $this->pos++;
                }
                if ($name !== '') {
                    $this->errors[] = "Feld '{$name}' ohne '=' in Eintrag '{$citekey}'";
                }
                continue;
            }

            $value = $this->parseValue($close);
            if ($name !== '') {
                $fields[$name] = trim($value);
            }
        }

        return ['citekey' => $citekey, 'type' => $type, 'fields' => $fields];
    }

    private function parseValue(string $close): string
    {
        $parts = [];
        while ($this->pos < $this->len) {
            $this->skipWs();
            if ($this->pos >= $this->len) {
                break;
            }
            $ch = $this->src[$this->pos];
            if ($ch === '{') {
                $parts[] = $this->readBraced();
            } elseif ($ch === '"') {
                $parts[] = $this->readQuoted();
            } else {
                $ident = $this->readIdent();
                if ($ident === '') {
                    break;
                }
                $parts[] = $this->lookupMacro(strtolower($ident));
            }
            $this->skipWs();
            if ($this->pos < $this->len && $this->src[$this->pos] === '#') {
                $this->pos++;
                continue; // Konkatenation
            }
            break;
        }
        return implode('', $parts);
    }

    /**
     * Liest ein {...}-Literal (Klammern werden ausgezählt), liefert Inhalt ohne äußere Klammern.
     *
     * Springt über Klammerfreie Strecken mit strcspn() statt zeichenweise zu
     * laufen – sonst ist der Parser für große Dateien um Größenordnungen langsamer.
     */
    private function readBraced(): string
    {
        $this->pos++; // {
        $start = $this->pos;
        $depth = 1;
        while ($this->pos < $this->len) {
            $this->pos += strcspn($this->src, '{}', $this->pos);
            if ($this->pos >= $this->len) {
                break;
            }
            if ($this->src[$this->pos] === '{') {
                $depth++;
                $this->pos++;
            } else {
                $depth--;
                $this->pos++;
                if ($depth === 0) {
                    break;
                }
            }
        }
        $end = $depth === 0 ? $this->pos - 1 : $this->pos; // schließende Klammer auslassen
        return substr($this->src, $start, $end - $start);
    }

    /** Liest ein "..."-Literal; { }-Klammern innerhalb schützen Anführungszeichen. */
    private function readQuoted(): string
    {
        $this->pos++; // "
        $start = $this->pos;
        $brace = 0;
        while ($this->pos < $this->len) {
            $this->pos += strcspn($this->src, '{}"', $this->pos);
            if ($this->pos >= $this->len) {
                break;
            }
            $ch = $this->src[$this->pos];
            $this->pos++;
            if ($ch === '{') {
                $brace++;
            } elseif ($ch === '}') {
                if ($brace > 0) {
                    $brace--;
                }
            } else { // '"' auf Ebene 0 beendet den Wert
                if ($brace === 0) {
                    break;
                }
            }
        }
        $end = ($brace === 0 && $this->pos <= $this->len && $this->pos > $start
            && $this->src[$this->pos - 1] === '"') ? $this->pos - 1 : $this->pos;
        return substr($this->src, $start, $end - $start);
    }

    private function readIdent(): string
    {
        $start = $this->pos;
        $this->pos += strcspn($this->src, ",={}()\"# \n\t", $this->pos);
        return rtrim(substr($this->src, $start, $this->pos - $start));
    }

    private function parseStringDef(string $close): void
    {
        $this->skipWs();
        $name = strtolower($this->readIdent());
        $this->skipWs();
        if ($this->pos < $this->len && $this->src[$this->pos] === '=') {
            $this->pos++;
        }
        $value = $this->parseValue($close);
        if ($name !== '') {
            $this->macros[$name] = $value;
        }
        $this->skipWs();
        if ($this->pos < $this->len && $this->src[$this->pos] === $close) {
            $this->pos++;
        }
    }

    private function skipBalanced(string $close): void
    {
        $depth = 1;
        $stop = $close . '{}'; // schließende Klammer + geschweifte Klammern
        while ($this->pos < $this->len) {
            $this->pos += strcspn($this->src, $stop, $this->pos);
            if ($this->pos >= $this->len) {
                break;
            }
            $ch = $this->src[$this->pos];
            $this->pos++;
            if ($ch === '{') {
                $depth++;
            } else {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
        }
    }

    private function lookupMacro(string $m): string
    {
        if (isset(self::MONTHS[$m])) {
            return self::MONTHS[$m];
        }
        return $this->macros[$m] ?? $m;
    }

    private function skipWs(): void
    {
        if ($this->pos < $this->len) {
            $this->pos += strspn($this->src, " \n\t", $this->pos);
        }
    }

    // ------------------------------------------------------------------ LaTeX-Ausgabe dekodieren

    /**
     * Entfernt typische LaTeX-Konstrukte ({\"a}, \ss, \&, `` '', ---) und
     * liefert Klartext mit Unicode. Nicht erkannte Sequenzen bleiben erhalten.
     */
    public static function decodeLatex(string $s): string
    {
        // Literale Klammern schützen: \{ und \} bleiben als Zeichen erhalten,
        // alle anderen Klammern sind nur Gruppierung (Großschreibungserhaltung)
        // und werden am Ende entfernt. Die Platzhalter liegen im privaten
        // Unicode-Bereich, damit die Dekodierung wiederholbar bleibt.
        $s = strtr($s, ['\\{' => "\u{E000}", '\\}' => "\u{E001}"]);

        $accent = function (array $m): string {
            $ch = $m['ch'];
            $mark = $m['mark'];
            $base = null;
            switch ($mark) {
                case '"': // Umlaut
                    $base = ['a' => 'ä', 'e' => 'ë', 'i' => 'ï', 'o' => 'ö', 'u' => 'ü', 'y' => 'ÿ',
                             'A' => 'Ä', 'E' => 'Ë', 'I' => 'Ï', 'O' => 'Ö', 'U' => 'Ü'][$ch] ?? null;
                    break;
                case "'": // Akut
                    $base = ['a' => 'á', 'e' => 'é', 'i' => 'í', 'o' => 'ó', 'u' => 'ú', 'y' => 'ý',
                             'c' => 'ć', 'n' => 'ń', 's' => 'ś', 'z' => 'ź',
                             'A' => 'Á', 'E' => 'É', 'I' => 'Í', 'O' => 'Ó', 'U' => 'Ú'][$ch] ?? null;
                    break;
                case '`': // Gravis
                    $base = ['a' => 'à', 'e' => 'è', 'i' => 'ì', 'o' => 'ò', 'u' => 'ù',
                             'A' => 'À', 'E' => 'È'][$ch] ?? null;
                    break;
                case '^': // Zirkumflex
                    $base = ['a' => 'â', 'e' => 'ê', 'i' => 'î', 'o' => 'ô', 'u' => 'û',
                             'A' => 'Â'][$ch] ?? null;
                    break;
                case '~': // Tilde
                    $base = ['a' => 'ã', 'n' => 'ñ', 'o' => 'õ', 'N' => 'Ñ'][$ch] ?? null;
                    break;
                case '=': // Makron
                    $base = ['a' => 'ā', 'e' => 'ē', 'o' => 'ō', 'u' => 'ū'][$ch] ?? null;
                    break;
                case '.': // Punkt oben (i → ı wird separat behandelt)
                    $base = $ch;
                    break;
            }
            return $base ?? $ch;
        };

        // {\accent x} / {\"{x}} / \"x / \'{x} …
        $s = preg_replace_callback(
            '/\{?\\\\(["\'`^~=.])\{?([a-zA-Z])\}?\}?/u',
            fn (array $m): string => $accent(['ch' => $m[2], 'mark' => $m[1]]),
            $s
        );
        // \c{c} (Cedille) und \v{c}, \u{r}, \H{o}, \b{b}, \d{n}, \k{a}
        $s = preg_replace_callback(
            '/\\\\([cvuHbdk])\{([a-zA-Z])\}/u',
            function (array $m): string {
                $base = $m[2];
                $combining = match ($m[1]) {
                    'c' => "\u{0327}", 'v' => "\u{030C}", 'u' => "\u{0306}",
                    'H' => "\u{030B}", 'b' => "\u{0331}", 'd' => "\u{0323}", 'k' => "\u{0328}",
                    default => '',
                };
                return $base . $combining;
            },
            $s
        );
        // Einfache Makros
        $simple = [
            '\\ss' => 'ß', '\\SS' => 'ẞ', '\\ae' => 'æ', '\\AE' => 'Æ',
            '\\oe' => 'œ', '\\OE' => 'Œ', '\\o' => 'ø', '\\O' => 'Ø',
            '\\aa' => 'å', '\\AA' => 'Å', '\\l' => 'ł', '\\L' => 'Ł',
            '\\i' => 'ı', '\\j' => 'ȷ', '\\dh' => 'ð', '\\DH' => 'Ð',
            '\\th' => 'þ', '\\TH' => 'Þ', '\\ng' => 'ŋ',
            '\\textemdash' => '—', '\\textendash' => '–',
            '\\textquotedblleft' => '“', '\\textquotedblright' => '”',
            '\\textquoteleft' => '‘', '\\textquoteright' => '’',
            '\\textbf{' => '', // Restliche Formatierung grob entfernen:
            '\\&' => '&', '\\%' => '%', '\\_' => '_', '\\#' => '#', '\\$' => '$',
            '\\,' => ' ', '\\;' => ' ', '\\:' => ' ', '\\!' => ' ',
        ];
        $s = strtr($s, $simple);
        // \emph{...}, \textit{...}, \textbf{...} → Inhalt
        $s = preg_replace('/\\\\(?:emph|textit|textbf|textsc|texttt|mbox)\{([^{}]*)\}/u', '$1', $s);
        // Anführungszeichen
        $s = str_replace(['``', "''"], ['“', '”'], $s);
        // Gedankenstriche
        $s = str_replace(['---', '--'], ['—', '–'], $s);
        // Gruppierungsklammern entfernen: In BibTeX dienen sie nur dazu, die
        // Großschreibung zu erhalten ({WiKet} -> WiKet). Sie gehören nicht in
        // die Anzeige. Geschützte literale Klammern bleiben erhalten (eine
        // Anwendung genügt – alle Anzeigewege dekodieren genau einmal; die
        // Suche normalisiert ohnehin alle Nicht-Buchstaben weg).
        $s = str_replace(['{', '}'], '', $s);
        return strtr($s, ["\u{E000}" => '{', "\u{E001}" => '}']);
    }
}

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
     * @return array<int,array{citekey:string,type:string,fields:array<string,string>}>
     */
    public function parse(string $source): array
    {
        $this->errors = [];
        $this->macros = [];

        $src = $source;
        if (substr($src, 0, 3) === "\xEF\xBB\xBF") {
            $src = substr($src, 3); // BOM entfernen
        }
        $this->src = str_replace(["\r\n", "\r"], "\n", $src);
        $this->len = strlen($this->src);
        $this->pos = 0;

        $entries = [];
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
                        $entries[] = $entry;
                    }
            }
        }
        return $entries;
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

    /** Liest ein {...}-Literal (Klammern werden ausgezählt), liefert Inhalt ohne äußere Klammern. */
    private function readBraced(): string
    {
        $this->pos++; // {
        $out = '';
        $depth = 1;
        while ($this->pos < $this->len) {
            $ch = $this->src[$this->pos];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    $this->pos++;
                    break;
                }
            }
            $out .= $ch;
            $this->pos++;
        }
        return $out;
    }

    /** Liest ein "..."-Literal; { }-Klammern innerhalb schützen Anführungszeichen. */
    private function readQuoted(): string
    {
        $this->pos++; // "
        $out = '';
        $brace = 0;
        while ($this->pos < $this->len) {
            $ch = $this->src[$this->pos];
            if ($ch === '{') {
                $brace++;
            } elseif ($ch === '}') {
                if ($brace > 0) {
                    $brace--;
                }
            } elseif ($ch === '"' && $brace === 0) {
                $this->pos++;
                break;
            }
            $out .= $ch;
            $this->pos++;
        }
        return $out;
    }

    private function readIdent(): string
    {
        $start = $this->pos;
        while ($this->pos < $this->len) {
            $ch = $this->src[$this->pos];
            if ($ch === ',' || $ch === '=' || $ch === '{' || $ch === '}' || $ch === '('
                || $ch === ')' || $ch === '"' || $ch === '#' || $ch === ' '
                || $ch === "\n" || $ch === "\t") {
                break;
            }
            $this->pos++;
        }
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
        while ($this->pos < $this->len) {
            $ch = $this->src[$this->pos];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}' || $ch === $close) {
                $depth--;
                if ($depth === 0) {
                    $this->pos++;
                    break;
                }
            }
            $this->pos++;
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
        while ($this->pos < $this->len) {
            $ch = $this->src[$this->pos];
            if ($ch === ' ' || $ch === "\n" || $ch === "\t") {
                $this->pos++;
            } else {
                break;
            }
        }
    }

    // ------------------------------------------------------------------ LaTeX-Ausgabe dekodieren

    /**
     * Entfernt typische LaTeX-Konstrukte ({\"a}, \ss, \&, `` '', ---) und
     * liefert Klartext mit Unicode. Nicht erkannte Sequenzen bleiben erhalten.
     */
    public static function decodeLatex(string $s): string
    {
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
            '\\{' => '{', '\\}' => '}',
        ];
        $s = strtr($s, $simple);
        // \emph{...}, \textit{...}, \textbf{...} → Inhalt
        $s = preg_replace('/\\\\(?:emph|textit|textbf|textsc|texttt|mbox)\{([^{}]*)\}/u', '$1', $s);
        // Anführungszeichen
        $s = str_replace(['``', "''"], ['“', '”'], $s);
        // Gedankenstriche
        $s = str_replace(['---', '--'], ['—', '–'], $s);
        return $s;
    }
}

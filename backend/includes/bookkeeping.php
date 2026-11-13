<?php
declare(strict_types=1);

// Einfache Buchhaltung fuer die EUER des Einzelunternehmens selbst
// (Eingangs-/seltene Ausgangsrechnungen ausserhalb des Buchungsflusses -
// siehe CLAUDE.md Abschnitt "Buchhaltung (EUER)"). Bewusst kein hartes
// OCR-Framework: Texterkennung ist nur eine Hilfe, um ein Formular
// vorauszufuellen - der Admin bestaetigt/korrigiert immer von Hand, bevor
// etwas gespeichert wird. Schlagen die Werkzeuge fehl oder sind nicht
// installiert, bleibt das Formular einfach leer (manuelle Eingabe).

// Ordner fuer hochgeladene Belege, eigener Konfigurationsschluessel mit
// Fallback auf einen Ordner neben storage_dir - analog zu gallery_storage_dir().
function bookkeeping_storage_dir(): string
{
    $cfg = backend_config();
    if (!empty($cfg['bookkeeping_storage_dir'])) {
        return rtrim($cfg['bookkeeping_storage_dir'], '/');
    }
    return rtrim(dirname(rtrim($cfg['storage_dir'], '/')), '/') . '/bookkeeping';
}

// Bestmoegliche Textextraktion eines hochgeladenen Belegs - fuer
// textbasierte PDFs per `pdftotext` (poppler-utils), fuer Bilder optional
// per `tesseract`, falls auf dem Server installiert. Liefert '' zurueck
// (statt eines Fehlers), wenn das Werkzeug fehlt oder der Beleg ein
// gescanntes/bildbasiertes PDF ohne Text ist - die Buchhaltung bleibt so
// auch ganz ohne diese Werkzeuge nutzbar (nur ohne Vorausfuellen).
function bookkeeping_extract_text(string $filePath, string $mimeType): string
{
    if (!is_file($filePath)) {
        return '';
    }

    if ($mimeType === 'application/pdf') {
        if (!bookkeeping_tool_available('pdftotext')) {
            return '';
        }
        $out = @shell_exec('pdftotext -layout ' . escapeshellarg($filePath) . ' - 2>/dev/null');
        return is_string($out) ? trim($out) : '';
    }

    if (in_array($mimeType, ['image/jpeg', 'image/png'], true)) {
        if (!bookkeeping_tool_available('tesseract')) {
            return '';
        }
        $out = @shell_exec('tesseract ' . escapeshellarg($filePath) . ' stdout -l deu 2>/dev/null');
        return is_string($out) ? trim($out) : '';
    }

    return '';
}

function bookkeeping_tool_available(string $binary): bool
{
    static $cache = [];
    if (!array_key_exists($binary, $cache)) {
        $path = @shell_exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null');
        $cache[$binary] = is_string($path) && trim($path) !== '';
    }
    return $cache[$binary];
}

// Betrag-Vorschlag: sucht nach Betrags-Schluesselwoertern (deutsch und
// englisch, da z.B. Amazon-Rechnungen teils englische Begriffe nutzen) und
// nimmt den Betrag in derselben Zeile - die Reihenfolge der Liste ist eine
// Prioritaet (spezifischstes Schluesselwort zuerst), nicht nur eine
// Trefferliste: sonst wuerde z.B. eine frueher stehende "Zwischensumme"-
// Zeile faelschlich vor dem eigentlichen Gesamtbetrag treffen. Steht das
// Schluesselwort mehrfach im Text (z.B. einmal in einer Fusszeile, einmal
// beim eigentlichen Betrag), wird das **letzte** Vorkommen verwendet - der
// tatsaechliche Gesamtbetrag steht auf den allermeisten Rechnungen ganz
// unten, nach Netto-/Steuer-Aufschluesselung. Ohne jeden Keyword-Treffer
// wird ersatzweise der groesste im Text gefundene Betrag verwendet.
function bookkeeping_guess_amount_cents(string $text): ?int
{
    if ($text === '') {
        return null;
    }

    $keywords = [
        'endbetrag', 'rechnungsendbetrag', 'zahlbetrag', 'gesamtbetrag',
        'rechnungsbetrag', 'bruttobetrag', 'zu zahlen', 'zu zahlender betrag',
        'amount due', 'grand total', 'invoice total', 'total amount',
        'gesamtsumme', 'gesamt', 'total', 'summe', 'betrag',
    ];
    $lines = explode("\n", $text);

    foreach ($keywords as $keyword) {
        $lastMatch = null;
        foreach ($lines as $line) {
            if (str_contains(mb_strtolower($line), $keyword)) {
                $amount = bookkeeping_find_amount_in_line($line);
                if ($amount !== null) {
                    $lastMatch = $amount;
                }
            }
        }
        if ($lastMatch !== null) {
            return $lastMatch;
        }
    }

    $all = bookkeeping_find_all_amounts($text);
    return $all ? max($all) : null;
}

// Sucht den ersten Betrag in einer einzelnen Zeile - deutsches Format
// (1.234,56) zuerst, da ein Komma als Dezimaltrennzeichen praktisch nie in
// einem Datum vorkommt (eindeutig sicher). Das englische Format
// (1,234.56, z.B. bei manchen Amazon-Rechnungen) wird nur akzeptiert, wenn
// direkt eine Waehrung danebensteht - ohne diese Einschraenkung wuerde ein
// Datum wie "20.11.2026" faelschlich als Betrag "20.11" gelesen werden
// (ein Punkt als Dezimaltrennzeichen ist sonst nicht von einem Datum zu
// unterscheiden).
function bookkeeping_find_amount_in_line(string $line): ?int
{
    if (preg_match('/\d{1,3}(?:\.\d{3})*,\d{2}(?!\d)/', $line, $m)) {
        return bookkeeping_amount_to_cents($m[0]);
    }
    if (preg_match('/(?:€|EUR|USD|\$|GBP|£)\s?(\d{1,3}(?:,\d{3})*\.\d{2})(?!\d)|(\d{1,3}(?:,\d{3})*\.\d{2})(?!\d)\s?(?:€|EUR|USD|\$|GBP|£)/i', $line, $m)) {
        return bookkeeping_amount_to_cents($m[1] !== '' ? $m[1] : $m[2]);
    }
    return null;
}

function bookkeeping_find_all_amounts(string $text): array
{
    $amounts = [];
    if (preg_match_all('/\d{1,3}(?:\.\d{3})*,\d{2}(?!\d)/', $text, $all)) {
        foreach ($all[0] as $m) {
            $amounts[] = bookkeeping_amount_to_cents($m);
        }
    }
    if (!$amounts && preg_match_all('/(?:€|EUR|USD|\$|GBP|£)\s?(\d{1,3}(?:,\d{3})*\.\d{2})(?!\d)|(\d{1,3}(?:,\d{3})*\.\d{2})(?!\d)\s?(?:€|EUR|USD|\$|GBP|£)/i', $text, $all, PREG_SET_ORDER)) {
        foreach ($all as $m) {
            $amounts[] = bookkeeping_amount_to_cents($m[1] !== '' ? $m[1] : $m[2]);
        }
    }
    return $amounts;
}

function bookkeeping_amount_to_cents(string $amount): int
{
    if (preg_match('/,\d{2}$/', $amount)) {
        // Deutsches Format: Punkt ist Tausendertrenner, Komma ist Dezimaltrennzeichen.
        $normalized = str_replace('.', '', $amount);
        $normalized = str_replace(',', '.', $normalized);
    } else {
        // Englisches Format: Komma ist Tausendertrenner, Punkt ist Dezimaltrennzeichen.
        $normalized = str_replace(',', '', $amount);
    }
    return (int) round(((float) $normalized) * 100);
}

// Deutsche Monatsnamen fuer Datumsangaben wie "Musterhausen, den 5. November
// 2026" (typisches Format fuer Gemeinde-/Behoerdenschreiben, die kein
// TT.MM.JJJJ nutzen).
const BOOKKEEPING_GERMAN_MONTHS = [
    'januar' => 1, 'februar' => 2, 'märz' => 3, 'maerz' => 3, 'april' => 4,
    'mai' => 5, 'juni' => 6, 'juli' => 7, 'august' => 8, 'september' => 9,
    'oktober' => 10, 'november' => 11, 'dezember' => 12,
];

// Datums-Vorschlag, in dieser Prioritaet:
// 1. Zeile mit der deutschen Briefkopf-Datumszeile "Ort, den <Datum>"
//    (uebliche Konvention bei Behoerden-/Geschaeftsbriefen, z.B. Gemeinde-
//    Bescheiden) - steht dort unabhaengig vom Wort "Datum".
// 2. Zeile mit "Rechnungsdatum"/"invoice date"/"Datum" (nicht Liefer-/
//    Faelligkeitsdatum - das waere ein anderes Datum als das Rechnungsdatum).
// 3. Ohne Treffer: das zuerst im Text vorkommende Datum, egal in welchem der
//    beiden Formate (TT.MM.JJJJ oder "5. November 2026") - **nach Position
//    im Text**, nicht "erst alle TT.MM.JJJJ versuchen, dann alle Textdatum-
//    Varianten" - sonst wuerde z.B. ein spaeter im Text stehendes
//    Faelligkeitsdatum in TT.MM.JJJJ-Form faelschlich vor einem frueher
//    stehenden Datum im Textformat gewinnen.
function bookkeeping_guess_date(string $text): ?string
{
    if ($text === '') {
        return null;
    }

    foreach (explode("\n", $text) as $line) {
        if (preg_match('/,\s*den\s+/iu', $line)) {
            $found = bookkeeping_find_date_in_text($line);
            if ($found !== null) {
                return $found;
            }
        }
    }

    foreach (explode("\n", $text) as $line) {
        $lower = mb_strtolower($line);
        // Sowohl "fälligkeitsdatum" als auch die ae-Transliteration
        // "faelligkeitsdatum" ausschliessen - manche PDF-Schriften liefern
        // bei der Textextraktion Umlaute nicht zuverlaessig als echtes "ä".
        if (str_contains($lower, 'rechnungsdatum') || str_contains($lower, 'invoice date')
            || (str_contains($lower, 'datum')
                && !str_contains($lower, 'lieferdatum')
                && !str_contains($lower, 'fälligkeitsdatum')
                && !str_contains($lower, 'faelligkeitsdatum'))
        ) {
            $found = bookkeeping_find_date_in_text($line);
            if ($found !== null) {
                return $found;
            }
        }
    }

    return bookkeeping_find_date_in_text($text);
}

// Liefert das im Text am frueheste vorkommende Datum, egal welches der
// beiden Formate - vergleicht dafuer die Fundstelle (Offset) beider Muster
// statt ein Format pauschal zu bevorzugen.
function bookkeeping_find_date_in_text(string $text): ?string
{
    $numericOffset = preg_match('/\b(\d{1,2})\.(\d{1,2})\.(\d{2,4})\b/', $text, $numericMatch, PREG_OFFSET_CAPTURE)
        ? $numericMatch[0][1] : null;
    $namedOffset = preg_match('/\b(\d{1,2})\.\s*([A-Za-zäöüÄÖÜ]+)\s+(\d{4})\b/u', $text, $namedMatch, PREG_OFFSET_CAPTURE)
        ? $namedMatch[0][1] : null;

    $useNamed = $namedOffset !== null && ($numericOffset === null || $namedOffset < $numericOffset);

    if ($useNamed) {
        $day = (int) $namedMatch[1][0];
        $month = BOOKKEEPING_GERMAN_MONTHS[mb_strtolower($namedMatch[2][0])] ?? null;
        $year = (int) $namedMatch[3][0];
        if ($month !== null && $day >= 1 && $day <= 31) {
            try {
                return (new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day)))->format('Y-m-d');
            } catch (Exception $e) {
                return null;
            }
        }
        return null;
    }

    if ($numericOffset !== null) {
        $day = (int) $numericMatch[1][0];
        $month = (int) $numericMatch[2][0];
        $year = (int) $numericMatch[3][0];
        if ($year < 100) {
            $year += 2000;
        }
        if ($month >= 1 && $month <= 12 && $day >= 1 && $day <= 31) {
            try {
                return (new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day)))->format('Y-m-d');
            } catch (Exception $e) {
                return null;
            }
        }
    }

    return null;
}

// Rechtsformen-Suffixe, die typischerweise im Firmennamen selbst stehen -
// eine Zeile, die eines davon enthaelt, ist ein deutlich zuverlaessigerer
// Hinweis auf den Rechnungssteller als einfach "die erste Zeile", da
// Rechnungen oft mit Logo-Text, Bestellnummer oder Adresszeilen beginnen,
// bevor ueberhaupt der Firmenname auftaucht (z.B. bei Amazon-Rechnungen).
const BOOKKEEPING_LEGAL_FORM_PATTERN = '/\b(GmbH|mbH|AG|KG|OHG|SE|UG|e\.?\s?K\.?|Inc\.?|Ltd\.?|LLC|Corp\.?|PLC|B\.?V\.?|N\.?V\.?|S\.?\s?[aà]\.?\s?r\.?\s?l\.?|SARL|& Co\.?)\b/ui';

// Firmen-/Absender-Vorschlag: durchsucht die ersten 20 Zeilen nach einer
// Rechtsform (GmbH/AG/KG/...) - typischerweise der Firmenname im Briefkopf.
// Ohne Treffer faellt es auf die erste nicht-leere, nicht rein numerische
// Zeile zurueck (z.B. fuer Gemeinde-Schreiben ohne Rechtsform im Namen).
// Nur ein grober Vorschlag, der Admin prueft/korrigiert ihn im Formular.
function bookkeeping_guess_counterparty(string $text): ?string
{
    $lines = array_slice(explode("\n", $text), 0, 20);

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '' && mb_strlen($line) <= 150 && preg_match(BOOKKEEPING_LEGAL_FORM_PATTERN, $line)) {
            return $line;
        }
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || mb_strlen($trimmed) > 150) {
            continue;
        }
        // Reine Zahlen-/Datumszeilen (Rechnungsnummer, Datum, Seitenzahl)
        // taugen nicht als Firmenname.
        if (preg_match('/^[\d.\-\/\s]+$/', $trimmed)) {
            continue;
        }
        // Metadaten-Zeilen (Bestell-/Rechnungs-/Kundennummer, Datum) sind
        // trotz enthaltener Buchstaben kein Firmenname.
        if (preg_match('/\b(bestellnummer|rechnungsnummer|kundennummer|steuernummer|auftragsnummer|datum|seite)\b/iu', $trimmed)) {
            continue;
        }
        return $trimmed;
    }

    return null;
}

function bookkeeping_direction_label(string $direction): string
{
    return $direction === 'einnahme' ? 'Einnahme (Ausgangsrechnung)' : 'Ausgabe (Eingangsrechnung)';
}

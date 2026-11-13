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

// Betrag-Vorschlag: sucht zuerst nach deutschen Betrags-Schluesselwoertern
// ("gesamt", "summe", "zu zahlen"...) und nimmt den Betrag in derselben
// Zeile; ohne Treffer wird der groesste im Text gefundene Betrag verwendet
// (auf einer Rechnung ist das meist der Gesamtbetrag, nicht ein Einzelpreis).
function bookkeeping_guess_amount_cents(string $text): ?int
{
    if ($text === '') {
        return null;
    }

    $amountPattern = '\d{1,3}(?:\.\d{3})*,\d{2}';
    // Reihenfolge ist eine Prioritaet, nicht nur eine Trefferliste: zuerst
    // der gesamte Text nach dem spezifischsten Schluesselwort durchsucht
    // (z.B. "gesamtbetrag"), erst danach nach allgemeineren wie "summe" -
    // sonst wuerde eine frueher stehende "Zwischensumme"-Zeile faelschlich
    // vor dem eigentlichen Gesamtbetrag treffen.
    $keywords = ['gesamtbetrag', 'rechnungsbetrag', 'zu zahlen', 'gesamtsumme', 'gesamt', 'total', 'summe', 'betrag'];
    $lines = explode("\n", $text);

    foreach ($keywords as $keyword) {
        foreach ($lines as $line) {
            if (str_contains(mb_strtolower($line), $keyword) && preg_match('/' . $amountPattern . '/', $line, $m)) {
                return bookkeeping_amount_to_cents($m[0]);
            }
        }
    }

    if (preg_match_all('/' . $amountPattern . '/', $text, $all) && $all[0]) {
        $cents = array_map('bookkeeping_amount_to_cents', $all[0]);
        return max($cents);
    }

    return null;
}

function bookkeeping_amount_to_cents(string $amount): int
{
    $normalized = str_replace('.', '', $amount);
    $normalized = str_replace(',', '.', $normalized);
    return (int) round(((float) $normalized) * 100);
}

// Datums-Vorschlag: erstes gefundenes TT.MM.JJJJ/TT.MM.JJ-Datum im Text.
function bookkeeping_guess_date(string $text): ?string
{
    if ($text === '' || !preg_match('/\b(\d{1,2})\.(\d{1,2})\.(\d{2,4})\b/', $text, $m)) {
        return null;
    }

    $day = (int) $m[1];
    $month = (int) $m[2];
    $year = (int) $m[3];
    if ($year < 100) {
        $year += 2000;
    }
    if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
        return null;
    }

    try {
        return (new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day)))->format('Y-m-d');
    } catch (Exception $e) {
        return null;
    }
}

// Firmen-/Absender-Vorschlag: naiv die erste nicht-leere Zeile des Textes -
// auf den meisten Rechnungen steht dort der Name des Rechnungsstellers
// (Briefkopf). Nur ein grober Vorschlag, der Admin korrigiert bei Bedarf.
function bookkeeping_guess_counterparty(string $text): ?string
{
    foreach (explode("\n", $text) as $line) {
        $line = trim($line);
        if ($line !== '' && mb_strlen($line) <= 150) {
            return $line;
        }
    }
    return null;
}

function bookkeeping_direction_label(string $direction): string
{
    return $direction === 'einnahme' ? 'Einnahme (Ausgangsrechnung)' : 'Ausgabe (Eingangsrechnung)';
}

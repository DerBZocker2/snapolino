<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/fpdf/fpdf.php';

// FPDFs Core-Fonts (Helvetica etc.) erwarten CP1252, nicht UTF-8 - unsere
// Strings (DB, Formulareingaben) sind aber immer UTF-8. utf8_decode() ist
// seit PHP 8.2 deprecated, deshalb ueber mbstring konvertieren.
function pdf_txt(string $utf8): string
{
    return mb_convert_encoding($utf8, 'CP1252', 'UTF-8');
}

function invoice_storage_dir(): string
{
    return __DIR__ . '/../storage/invoices';
}

function invoice_pdf_path(string $invoiceNumber): string
{
    return invoice_storage_dir() . '/' . basename($invoiceNumber) . '.pdf';
}

// Erzeugt die Rechnungs-PDF fuer eine bereits bezahlte Buchung
// ($booking muss invoice_number und paid_at bereits gesetzt haben) und
// speichert sie unter storage/invoices/<Rechnungsnummer>.pdf. Liefert den
// vollen Dateipfad.
function generate_invoice_pdf(array $booking): string
{
    $bookingId = (int) $booking['id'];
    $items = booking_invoice_items($bookingId);
    $total = (int) $booking['total_price_cents'];

    $businessName = get_setting('business_name', 'Snapolino') ?: 'Snapolino';
    $businessAddress = (string) get_setting('business_address', '');
    $taxNote = (string) get_setting('business_tax_note', '');

    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetMargins(20, 20, 20);
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->AddPage();

    $pdf->SetFont('Helvetica', 'B', 16);
    $pdf->Cell(0, 8, pdf_txt($businessName), 0, 1);

    $pdf->SetFont('Helvetica', '', 10);
    foreach (preg_split('/\R/', $businessAddress) as $line) {
        if (trim($line) !== '') {
            $pdf->Cell(0, 5, pdf_txt($line), 0, 1);
        }
    }
    $pdf->Ln(10);

    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell(0, 6, pdf_txt('Rechnungsempfänger'), 0, 1);
    $pdf->SetFont('Helvetica', '', 10);
    if ($booking['invoice_to_company'] && !empty($booking['customer_company'])) {
        $pdf->Cell(0, 5, pdf_txt((string) $booking['customer_company']), 0, 1);
    }
    $pdf->Cell(0, 5, pdf_txt($booking['customer_name']), 0, 1);
    foreach (booking_address_lines($booking) as $line) {
        $pdf->Cell(0, 5, pdf_txt($line), 0, 1);
    }
    $pdf->Ln(10);

    $pdf->SetFont('Helvetica', 'B', 14);
    $pdf->Cell(0, 8, pdf_txt('Rechnung ' . $booking['invoice_number']), 0, 1);
    $pdf->SetFont('Helvetica', '', 10);
    $paidAt = $booking['paid_at'] ? new DateTimeImmutable((string) $booking['paid_at']) : new DateTimeImmutable();
    $eventDate = new DateTimeImmutable((string) $booking['event_date']);
    $pdf->Cell(0, 5, pdf_txt('Rechnungsdatum: ' . $paidAt->format('d.m.Y')), 0, 1);
    $pdf->Cell(0, 5, pdf_txt('Leistungsdatum (Event): ' . $eventDate->format('d.m.Y')), 0, 1);
    $pdf->Ln(6);

    $pdf->SetFillColor(240, 237, 250);
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell(90, 8, pdf_txt('Position'), 1, 0, 'L', true);
    $pdf->Cell(20, 8, pdf_txt('Menge'), 1, 0, 'R', true);
    $pdf->Cell(35, 8, pdf_txt('Einzelpreis'), 1, 0, 'R', true);
    $pdf->Cell(25, 8, pdf_txt('Gesamt'), 1, 1, 'R', true);

    $pdf->SetFont('Helvetica', '', 10);
    foreach ($items as $item) {
        $lineTotal = $item['unit_amount_cents'] * $item['quantity'];
        $pdf->Cell(90, 7, pdf_txt($item['name']), 1);
        $pdf->Cell(20, 7, (string) $item['quantity'], 1, 0, 'R');
        $pdf->Cell(35, 7, pdf_txt(money_from_cents((int) $item['unit_amount_cents'])), 1, 0, 'R');
        $pdf->Cell(25, 7, pdf_txt(money_from_cents($lineTotal)), 1, 1, 'R');
    }

    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell(145, 8, pdf_txt('Gesamtsumme'), 1);
    $pdf->Cell(25, 8, pdf_txt(money_from_cents($total)), 1, 1, 'R');

    $pdf->Ln(10);
    $pdf->SetFont('Helvetica', '', 9);
    if ($taxNote !== '') {
        $pdf->MultiCell(0, 5, pdf_txt($taxNote));
        $pdf->Ln(2);
    }
    $pdf->Cell(0, 5, pdf_txt('Bezahlt am ' . $paidAt->format('d.m.Y') . ' per Online-Zahlung.'), 0, 1);

    $dir = invoice_storage_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    $path = invoice_pdf_path((string) $booking['invoice_number']);
    $pdf->Output('F', $path);

    return $path;
}

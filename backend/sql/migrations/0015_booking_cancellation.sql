-- Stornierung einer bereits bezahlten Buchung durch den Admin loest jetzt
-- automatisch eine Stripe-Rueckerstattung plus Stornorechnung
-- (Stripe Credit Note zur bestehenden Stripe-Rechnung) aus und verschickt
-- eine Stornobestaetigung per Mail - siehe cancel_booking() in
-- includes/payments.php. Diese Spalten halten die dabei entstehenden
-- Referenzen fest (fuer die Anzeige in booking_detail.php und als
-- Nachweis, dass wirklich zurueckerstattet wurde).
ALTER TABLE bookings
    ADD COLUMN cancelled_at              DATETIME NULL AFTER paid_at,
    ADD COLUMN stripe_refund_id          VARCHAR(255) NULL AFTER stripe_invoice_hosted_url,
    ADD COLUMN stripe_credit_note_id     VARCHAR(255) NULL AFTER stripe_refund_id,
    ADD COLUMN stripe_credit_note_pdf_url VARCHAR(500) NULL AFTER stripe_credit_note_id;

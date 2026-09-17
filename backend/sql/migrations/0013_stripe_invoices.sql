-- Stripe erstellt ab jetzt zusaetzlich zur eigenen, fortlaufend nummerierten
-- Rechnung (invoice_number/generate_invoice_pdf) bei jeder Checkout-Zahlung
-- automatisch eine eigene, bei Stripe gehostete Rechnung (siehe
-- includes/stripe.php, create_stripe_checkout_session()/
-- create_addon_charge_checkout_session(), Parameter invoice_creation).
-- Die eigene Rechnungsnummer bleibt die massgebliche fuer die Buchhaltung -
-- die Stripe-Rechnung ist eine zusaetzliche, bei Stripe gespeicherte Kopie.
ALTER TABLE bookings
    ADD COLUMN stripe_invoice_id VARCHAR(255) NULL AFTER invoice_number,
    ADD COLUMN stripe_invoice_pdf_url VARCHAR(500) NULL AFTER stripe_invoice_id,
    ADD COLUMN stripe_invoice_hosted_url VARCHAR(500) NULL AFTER stripe_invoice_pdf_url;

-- Zusatzzahlungen (booking_addon_charges) hatten bisher gar keine Rechnung -
-- bekommen jetzt ebenfalls eine Stripe-Rechnung.
ALTER TABLE booking_addon_charges
    ADD COLUMN stripe_invoice_id VARCHAR(255) NULL,
    ADD COLUMN stripe_invoice_pdf_url VARCHAR(500) NULL,
    ADD COLUMN stripe_invoice_hosted_url VARCHAR(500) NULL;

-- Nachtraegliche Admin-Aenderungen mit Zahlungslink (booking_addon_charges)
-- wurden bisher sofort auf die Buchung angewendet, unabhaengig davon, ob der
-- Kunde tatsaechlich bezahlt hat - nur der Zahlungsstatus selbst wartete auf
-- Stripe. Ab jetzt wird die gewuenschte Aenderung (Eventdatum, Layouts,
-- Extras, neuer Gesamtpreis) hier zwischengespeichert und erst von
-- mark_addon_charge_paid() (ausgeloest durch den Stripe-Webhook) tatsaechlich
-- auf die Buchung uebernommen. "Kostenlos uebernehmen" bleibt unveraendert
-- sofort wirksam und legt keine Zeile hier an.
ALTER TABLE booking_addon_charges
    ADD COLUMN pending_changes_json TEXT NULL AFTER amount_cents;

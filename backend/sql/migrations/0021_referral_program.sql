-- Empfehlungsprogramm: jede bestaetigte Buchung bekommt automatisch einen
-- persoenlichen Rabattcode (coupons.referral_owner_booking_id), den die
-- Buchende Person an Freunde weitergeben kann - siehe
-- ensure_referral_coupon_for_booking() in includes/functions.php. Nutzt eine
-- neue Buchung so einen Code und wird sie ihrerseits bestaetigt, bekommt die
-- werbende Person automatisch einen eigenen, einmaligen Belohnungsgutschein
-- per Mail (reward_referral_owner_if_applicable()). bookings.referral_reward_sent_at
-- verhindert eine doppelte Belohnung fuer dieselbe geworbene Buchung.
ALTER TABLE coupons
    ADD COLUMN referral_owner_booking_id INT UNSIGNED NULL AFTER is_active,
    ADD FOREIGN KEY (referral_owner_booking_id) REFERENCES bookings(id) ON DELETE SET NULL;

ALTER TABLE bookings
    ADD COLUMN referral_reward_sent_at DATETIME NULL AFTER reminder_sent_at;

INSERT IGNORE INTO settings (name, value) VALUES
    ('referral_discount_percent', '10'),
    ('referral_reward_cents', '1500');

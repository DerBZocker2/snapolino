-- Migration 0008: Admin-PIN je Box (fuers Admin-Panel direkt auf der Box,
-- ueber Cloud-Sync verteilt statt in box.ini - siehe backend/README.md).
-- Auf einer bereits laufenden Installation von Hand ausfuehren:
--   mysql --default-character-set=utf8mb4 -u snapolino -p snapolino < backend/sql/migrations/0008_box_admin_pin_and_booking_sync.sql

ALTER TABLE boxes ADD COLUMN admin_pin VARCHAR(20) NULL AFTER api_key;

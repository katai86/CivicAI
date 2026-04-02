-- EU-wide admin subdivision storage (provider-first normalized JSON)
-- Run: mysql ... < sql/2026-27-admin-subdivision-json.sql

ALTER TABLE reports ADD COLUMN admin_subdivision_json JSON NULL;

ALTER TABLE authorities ADD COLUMN country_code CHAR(2) NULL;
ALTER TABLE authorities ADD COLUMN municipality_type VARCHAR(64) NULL;
ALTER TABLE authorities ADD COLUMN subdivision_aware TINYINT(1) NOT NULL DEFAULT 0;

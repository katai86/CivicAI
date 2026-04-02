-- MILESTONE 3 – Green Community Actions
-- civil_events bővítése: event_type (civil|green_action) + participants_count.
-- Futtatás: 2026-14 után.

ALTER TABLE civil_events
  ADD COLUMN event_type VARCHAR(32) NOT NULL DEFAULT 'civil',
  ADD COLUMN participants_count INT NULL DEFAULT 0;

CREATE INDEX idx_civil_events_type ON civil_events (event_type);


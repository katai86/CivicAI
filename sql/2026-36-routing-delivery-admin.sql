-- Admin routing override + external ticket reference

SET @db := DATABASE();

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'reports' AND COLUMN_NAME = 'routing_override_target') = 0,
  'ALTER TABLE reports ADD COLUMN routing_override_target VARCHAR(64) NULL',
  'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'reports' AND COLUMN_NAME = 'external_ticket_id') = 0,
  'ALTER TABLE reports ADD COLUMN external_ticket_id VARCHAR(128) NULL',
  'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'report_routing_log' AND COLUMN_NAME = 'delivery_channel') = 0,
  'ALTER TABLE report_routing_log ADD COLUMN delivery_channel VARCHAR(32) NULL',
  'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'report_routing_log' AND COLUMN_NAME = 'external_response') = 0,
  'ALTER TABLE report_routing_log ADD COLUMN external_response TEXT NULL',
  'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

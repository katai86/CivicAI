-- ========== DEPLOY full milestones (civicai_core) ==========
-- Run IN ORDER in phpMyAdmin:
--   1) sql/2026-33-unified-observation-foundation.sql
--   2) sql/2026-34-plant-tree-city-intelligence.sql
--   3) sql/DEPLOY_city_intelligence_phpmyadmin.sql (if City Intel tables missing)
--
-- Verify:
SELECT COUNT(*) AS tree_inspections_ok FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'tree_inspections';
SELECT COUNT(*) AS city_priorities_ok FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'city_priorities';
SELECT COUNT(*) AS evidence_links_ok FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'civic_evidence_links';

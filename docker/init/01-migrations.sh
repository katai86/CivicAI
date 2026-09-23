#!/bin/bash
set -euo pipefail

echo "[civicai] Running migrations..."
mysql -uroot -proot civicai_core < /sql/00_run_all_migrations_safe.sql
if [ -f /sql/2026-32-city-intelligence.sql ]; then
  mysql -uroot -proot civicai_core < /sql/2026-32-city-intelligence.sql || true
fi
if [ -f /sql/2026-33-unified-observation-foundation.sql ]; then
  mysql -uroot -proot civicai_core < /sql/2026-33-unified-observation-foundation.sql || true
fi
if [ -f /sql/2026-34-plant-tree-city-intelligence.sql ]; then
  mysql -uroot -proot civicai_core < /sql/2026-34-plant-tree-city-intelligence.sql || true
fi
echo "[civicai] Migrations done."

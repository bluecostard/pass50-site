#!/usr/bin/env bash
# Per-boot reconciliation for Cloud Agents. Must be idempotent and terminate.
set -euo pipefail
cd /workspace

sudo mkdir -p /run/mysqld
sudo chown mysql:mysql /run/mysqld

if ! pgrep -x mariadbd >/dev/null 2>&1 && ! pgrep -x mysqld >/dev/null 2>&1; then
  sudo mysqld_safe --datadir=/var/lib/mysql --user=mysql >/tmp/mysqld_safe.log 2>&1 &
fi

for _ in $(seq 1 60); do
  if sudo mysql -e "SELECT 1" >/dev/null 2>&1; then
    break
  fi
  sleep 1
done
sudo mysql -e "SELECT 1" >/dev/null

sudo mysql -e "
CREATE DATABASE IF NOT EXISTS pass50 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS pass50_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'pass50'@'localhost' IDENTIFIED BY 'pass50_dev';
GRANT ALL PRIVILEGES ON pass50.* TO 'pass50'@'localhost';
GRANT ALL PRIVILEGES ON pass50_test.* TO 'pass50'@'localhost';
FLUSH PRIVILEGES;
"

if ! mysql -u pass50 -ppass50_dev pass50 -e "SHOW TABLES" 2>/dev/null | grep -q '^users$'; then
  mysql -u pass50 -ppass50_dev pass50 < schema.sql
fi

if [[ ! -f api/config.php ]]; then
  cp api/config.example.php api/config.php
  python3 - <<'PY'
from pathlib import Path
p = Path('api/config.php')
text = p.read_text()
repl = {
    "'base_url' => 'https://votre-domaine.fr'": "'base_url' => 'http://127.0.0.1:8080'",
    "'show_confirmation_link_in_response' => false": "'show_confirmation_link_in_response' => true",
    "'host' => 'dbXXXXXXXX.hosting-data.io'": "'host' => '127.0.0.1'",
    "'name' => 'dbsXXXXXXXX'": "'name' => 'pass50'",
    "'user' => 'dbuXXXXXXXX'": "'user' => 'pass50'",
    "'password' => 'CHANGEZ_CE_MOT_DE_PASSE'": "'password' => 'pass50_dev'",
    "'api_key' => 'xkeysib-VOTRE_CLE_API_BREVO'": "'api_key' => 'dev-disabled'",
    "'sender_email' => 'contact@votre-domaine.fr'": "'sender_email' => 'dev@localhost'",
}
for a, b in repl.items():
    text = text.replace(a, b, 1)
p.write_text(text)
PY
fi

mkdir -p uploads/profile uploads/event uploads/avatars
echo "PASS50 cloud start: MariaDB prêt, config locale OK."

#!/usr/bin/env bash
set -euo pipefail
ROOT="${1:-.}"
python3 "$(dirname "$0")/apply_pass50_v27.py" "$ROOT"
python3 "$(dirname "$0")/apply_pass50_v27.py" "$ROOT" --check

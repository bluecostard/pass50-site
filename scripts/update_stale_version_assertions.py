#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REPLACEMENTS = {
    "live-radar-v3.js?v=1.6": "live-radar-v3.js?v=1.7",
    "v9-tools.js?v=15.3": "v9-tools.js?v=15.5",
    "v9-tools.js?v=15.4": "v9-tools.js?v=15.5",
}

changed = []
for path in sorted((ROOT / "tests").glob("test_*.py")):
    original = path.read_text(encoding="utf-8")
    updated = original
    for old, new in REPLACEMENTS.items():
        updated = updated.replace(old, new)
    if updated != original:
        path.write_text(updated, encoding="utf-8")
        changed.append(str(path.relative_to(ROOT)))

if not changed:
    print("Aucune assertion périmée à modifier.")
else:
    print("Assertions mises à jour:")
    for path in changed:
        print(f"- {path}")

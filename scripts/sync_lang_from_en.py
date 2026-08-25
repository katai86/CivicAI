#!/usr/bin/env python3
"""Merge missing keys from lang/en.php into de/fr/it/es/sl (and report gaps vs hu)."""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
LANG_DIR = ROOT / "lang"
KEY_RE = re.compile(
    r"^\s*'((?:\\'|[^'])*)'\s*=>\s*((?:'(?:\\'|[^'])*'|\"(?:\\\"|[^\"])*\"))\s*,?\s*$"
)


def parse_lang(path: Path) -> dict[str, str]:
    text = path.read_text(encoding="utf-8")
    out: dict[str, str] = {}
    for line in text.splitlines():
        m = KEY_RE.match(line)
        if not m:
            continue
        key = m.group(1).replace("\\'", "'")
        raw = m.group(2)
        out[key] = raw  # keep quoted literal
    return out


def php_escape(s: str) -> str:
    return "'" + s.replace("\\", "\\\\").replace("'", "\\'") + "'"


def main() -> int:
    en = parse_lang(LANG_DIR / "en.php")
    hu = parse_lang(LANG_DIR / "hu.php")
    targets = ["de", "fr", "it", "es", "sl"]
    for code in targets:
        path = LANG_DIR / f"{code}.php"
        cur = parse_lang(path)
        missing = [k for k in en if k not in cur]
        if not missing:
            print(f"{code}: ok ({len(cur)} keys)")
            continue
        lines = path.read_text(encoding="utf-8").rstrip().splitlines()
        # Drop trailing ]; and blank lines at end
        while lines and lines[-1].strip() in ("", "];", "]"):
            last = lines.pop()
            if last.strip() in ("];", "]"):
                break
        block = ["", "    // --- synced from en.php (translate when possible) ---"]
        for k in sorted(missing):
            block.append(f"    {php_escape(k)} => {en[k]},")
        block.append("];")
        path.write_text("\n".join(lines + block) + "\n", encoding="utf-8")
        print(f"{code}: added {len(missing)} keys (now ~{len(cur) + len(missing)})")

    only_hu = [k for k in hu if k not in en]
    only_en = [k for k in en if k not in hu]
    if only_hu:
        print("WARNING only in hu:", ", ".join(only_hu[:20]))
    if only_en:
        print("WARNING only in en:", ", ".join(only_en[:20]))
    return 0


if __name__ == "__main__":
    sys.exit(main())

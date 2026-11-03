#!/usr/bin/env python3
"""Build the installable WordPress plugin, excluding repository tooling."""
from pathlib import Path
import re
import zipfile

root = Path(__file__).resolve().parent.parent
version = re.search(r"^ \* Version: (.+)$", (root / "ws40-weather-signature.php").read_text(), re.M).group(1).strip()
files = [root / name for name in ("ws40-weather-signature.php", "readme.txt", "readme.html")]
files += sorted(p for folder in ("assets", "includes") for p in (root / folder).rglob("*") if p.is_file())
output = root / "dist" / f"ws40-weather-signature-v{version}.zip"
output.parent.mkdir(exist_ok=True)
with zipfile.ZipFile(output, "w", zipfile.ZIP_DEFLATED) as archive:
    for path in files:
        archive.write(path, "ws40-weather-signature/" + path.relative_to(root).as_posix())
print(output)

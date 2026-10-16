"""Build the shared-hosting ZIP: the application plus instructions, no data."""
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED
root = Path(__file__).resolve().parents[1]
target = root / 'dist/vmeste-php.zip'
with ZipFile(target, 'w', ZIP_DEFLATED) as archive:
    archive.write(root / 'index.php', 'index.php')
    archive.writestr('README.txt', (root / 'README.md').read_text())
with ZipFile(target) as archive:
    assert archive.testzip() is None
    assert archive.namelist() == ['index.php', 'README.txt']
print(f'Built {target.name}: index.php + README.txt ({target.stat().st_size} bytes)')

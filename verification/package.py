#!/usr/bin/env python3
"""Build a Moodle ZIP with one correctly named plugin directory."""
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED
import argparse
root = Path(__file__).resolve().parents[1]
p = argparse.ArgumentParser()
p.add_argument('output', type=Path)
args = p.parse_args()
args.output.parent.mkdir(parents=True, exist_ok=True)
with ZipFile(args.output, 'w', ZIP_DEFLATED) as z:
    for path in sorted(root.rglob('*')):
        rel = path.relative_to(root)
        if not path.is_file() or any(v in {'.git', '.github', 'verification', '__pycache__'} for v in rel.parts) or path.suffix == '.zip' or path.name in {'.gitignore', '.DS_Store'}:
            continue
        z.write(path, Path('districtupdate') / rel)
with ZipFile(args.output) as z:
    assert z.testzip() is None
    assert 'districtupdate/version.php' in z.namelist()
    assert all(n.startswith('districtupdate/') and '..' not in Path(n).parts for n in z.namelist())
print(f'Created {args.output}')

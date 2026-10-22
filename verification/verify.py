#!/usr/bin/env python3
"""Offline release checks. This does not install Moodle or connect to AD."""
from pathlib import Path
import re
import subprocess
import xml.etree.ElementTree as ET
root = Path(__file__).resolve().parents[1]
files = sorted(root.rglob('*.php'))
for path in files:
    subprocess.run(['php', '-l', str(path)], check=True, capture_output=True, text=True)
print(f'PHP syntax passed: {len(files)} files', flush=True)
xml = ET.parse(root / 'db/install.xml').getroot()
assert xml.attrib['PATH'] == 'local/districtupdate/db'
assert (root/'lang/en/local_districtupdate.php').is_file()
assert not (root/'lang/en/local_districtreview.php').exists()
# Moodle's role editor derives each capability label from pluginname:capability.
langkeys = set(re.findall(r"\$string\[['\"]([^'\"]+)['\"]\]", (root/'lang/en/local_districtupdate.php').read_text()))
for capability in re.findall(r"['\"](local/[^'\"]+:[^'\"]+)['\"]\s*=>", (root/'db/access.php').read_text()):
    labelkey = capability.split('/', 1)[1]
    assert labelkey in langkeys, f"Missing capability language label: {labelkey}"

expected = {'local_districtupdate_dist', 'local_districtupdate_access', 'local_districtupdate_cycle', 'local_districtupdate_review', 'local_districtupdate_roster', 'local_districtupdate_audit'}
tables = xml.findall('./TABLES/TABLE')
assert {t.attrib['NAME'] for t in tables} == expected
fields = {}
for table in tables:
    name = table.attrib['NAME']
    cols = [f.attrib['NAME'] for f in table.findall('./FIELDS/FIELD')]
    fields[name] = set(cols)
    assert len(cols) == len(set(cols)) and cols[0] == 'id'
    for field in table.findall('./FIELDS/FIELD'):
        if field.attrib['TYPE'] == 'char' and field.attrib.get('NOTNULL') == 'true':
            assert field.attrib.get('DEFAULT') != '', (
                f"{name}.{field.attrib['NAME']}: XMLDB forbids an empty default on CHAR NOT NULL"
            )
    assert table.find('./KEYS/KEY').attrib['TYPE'] == 'primary'
    for index in table.findall('./INDEXES/INDEX'):
        assert set(index.attrib['FIELDS'].replace(' ', '').split(',')) <= set(cols)
assert next(f for t in tables if t.attrib['NAME'] == 'local_districtupdate_roster' for f in t.findall('./FIELDS/FIELD') if f.attrib['NAME'] == 'status').attrib['DEFAULT'] == 'still'
print('XMLDB structural checks passed: 6 tables', flush=True)
pickersource = (root/'amd/src/reviewer_picker.js').read_text()
assert (root/'amd/build/reviewer_picker.min.js').read_text() == pickersource.replace(
    'define([', "define('local_districtupdate/reviewer_picker', [", 1
), 'The packaged Moodle AMD module must match its source'
# Every DB table referenced by plugin source must be declared in install.xml.
references = set()
for path in files:
    if 'verification' not in path.parts:
        references.update(re.findall(r'local_districtupdate_(?:dist|access|cycle|review|roster|audit)\b', path.read_text()))
assert references == expected
assert "$plugin->requires = 2025100601" in (root/'version.php').read_text()
assert "'livewrites'" in (root/'classes/directory.php').read_text()
subprocess.run(['php', str(root/'verification/check.php')], check=True)
print('Offline verification passed. Moodle installation, browser behavior, mail and live AD remain pilot checks.')

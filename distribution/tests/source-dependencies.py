"""Read-only source-inventory closure check; generated vendor trees are packages.

This does not load application PHP, a database, production config or certificates.
"""
import hashlib
import ast
import json
from pathlib import Path
import re

ROOT=Path(__file__).resolve().parents[2]
manifest=json.loads((ROOT/'distribution/source-files.json').read_text(encoding='utf8'))
rows=manifest['files']+manifest['installer_files'];paths={r['path'] for r in rows}
assert len(paths)==len(rows),'Duplicate manifest path'
missing=set()
for row in rows:
    name=row['path'];source=(ROOT/name).read_bytes()
    if row.get('normalized_lf'):source=source.replace(b'\r\n',b'\n')
    assert len(source)==row['bytes'] and hashlib.sha256(source).hexdigest()==row['sha256'],name
    if name.endswith('.py'):
        for item in ast.walk(ast.parse(source.decode('utf-8-sig'),filename=name)):
            modules=([alias.name for alias in item.names] if isinstance(item,ast.Import) else [item.module] if isinstance(item,ast.ImportFrom) and item.module else [])
            for module in modules:
                target=(ROOT/name).parent.joinpath(module.replace('.','/')+'.py').resolve()
                if target.is_file() and target.is_relative_to(ROOT) and target.relative_to(ROOT).as_posix() not in paths:
                    missing.add(target.relative_to(ROOT).as_posix())
    if not name.endswith('.php'):continue
    for part in re.findall(r'''(?:require|include)(?:_once)?\s*\(?\s*__DIR__\s*\.\s*['"]([^'"]+)['"]''',source.decode('utf8')):
        target=(ROOT/name).parent.joinpath(part.lstrip('/')).resolve()
        if not target.is_relative_to(ROOT) or not target.is_file():continue
        relative=target.relative_to(ROOT).as_posix()
        # Composer trees are installed from the separate dependency packages.
        if '/vendor/' not in relative and relative not in paths:missing.add(relative)
for name in ['eric-bridge.py','eric_feedback.py','render-activity-report.py','ebilanz_builder.py','ebilanz_assets.py','ebilanz_tax_assets.py','ebilanz_partial_assets.py','ebilanz_mapping.py','ebilanz_degressive.py','ebilanz_sales.py','ebilanz_club_scope.py','ebilanz_whole_club.py']:
    if 'vereinsverwaltung/tools/'+name not in paths:missing.add('vereinsverwaltung/tools/'+name)
for name in ['087_tax_filings.sql','088_balance_ledger.sql','089_tax_auto.sql','090_activity_report_review.sql',
             '091_tax_attachments.sql','092_balance_sources.sql','093_balance_source_history.sql','094_asset_sale_links.sql','097_asset_partial_reviews.sql','098_balance_segments.sql','099_balance_clearings.sql']:
    if 'vereinsverwaltung/migrations/mysql/'+name not in paths:missing.add('vereinsverwaltung/migrations/mysql/'+name)
assert not missing,'Required source missing from installation inventory: '+', '.join(sorted(missing))
print(f'{len(paths)} file hashes and static source dependencies verified; no application code executed')

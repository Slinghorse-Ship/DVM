"""Check local calculation modules participate in the approval fingerprint. Read only."""
import ast
from pathlib import Path
import re

root = Path(__file__).resolve().parent.parent
source = (root / 'src/Finance/Tax/TaxFilings.php').read_text(encoding='utf8')
method = source.split('public static function runtimeHash():string', 1)[1].split('public static function contentHash(', 1)[0]
names = re.findall(r"'([^']+\.(?:php|py|json))'", method)
assert len(names) == len(set(names)), 'Duplicate fingerprint source'
covered = {(root / 'src' / name).resolve() for name in names}
assert all(p.is_file() for p in covered), 'Missing fingerprint source'
assert "hash_file('sha256',$path)" in method and '$parts.=$hash;' in method
todo = [root / 'tools/eric-process.py', root / 'tools/eric-bridge.py', root / 'tools/ebilanz_builder.py']
visited = set()
while todo:
    path = todo.pop().resolve()
    if path in visited:
        continue
    visited.add(path)
    assert path in covered, f'Python calculation omitted from approval fingerprint: {path.name}'
    for node in ast.walk(ast.parse(path.read_text(encoding='utf8'))):
        modules = ([a.name for a in node.names] if isinstance(node, ast.Import)
                   else [node.module] if isinstance(node, ast.ImportFrom) and node.module else [])
        for module in modules:
            dependency = path.parent / (module.replace('.', '/') + '.py')
            if dependency.is_file():
                todo.append(dependency)
for name in ['Finance/Balance/BalanceSourceRead.php', 'Finance/Balance/BalanceTaxAssetValues.php', 'Finance/Balance/BalanceTaxAssetSale.php', 'Finance/Balance/BalanceDisposalAccounts.php', 'Finance/Assets/AssetPartialDisposal.php',
             'Finance/Assets/AssetPartialDisposalRecords.php', 'Finance/Assets/AssetPartialDisposalReview.php', 'Finance/Balance/BalanceSources.php',
             'Finance/Balance/BalanceSourceHistory.php', 'Finance/Balance/BalanceLedger.php', 'Finance/Assets/AssetSale.php', 'Finance/Balance/BalanceSegmentCalculation.php', 'Finance/Balance/BalanceSegments.php', 'Finance/Balance/BalanceClubTaxReport.php', 'Finance/Balance/BalanceWholeClubReport.php', 'Finance/Balance/BalanceCapitalComparison.php', 'Finance/Balance/BalanceCombinedClubValues.php', 'Finance/Balance/BalanceAssetAllocation.php', 'Finance/Balance/BalanceClearings.php', 'Finance/Balance/BalanceInternalClearing.php', 'Finance/Balance/BalanceInternalClearingExport.php', 'Finance/Balance/BalanceClearingStatement.php',
             'Finance/Balance/BalancePeriodFilingValues.php', 'Finance/Balance/BalancePeriodFilingSource.php', 'Finance/Balance/BalancePeriodWholeFiling.php', 'Finance/Balance/BalanceCalendarFilingSource.php', 'Finance/Balance/BalancePeriodTaxValues.php', 'Finance/Balance/BalancePeriodTaxAssetValues.php', 'Finance/Balance/BalancePeriodTaxReconciliations.php', 'Finance/Balance/BalancePeriodAssetUse.php']:
    assert (root / 'src' / name).resolve() in covered, f'PHP calculation omitted: {name}'
print(f'{len(covered)} fingerprint files and {len(visited)} Python dependency modules verified; read-only')

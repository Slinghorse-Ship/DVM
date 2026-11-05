"""Full original PHP cost values -> the ordinary runtime XML path."""
import copy,importlib.util,json,os,subprocess,sys,unittest
from pathlib import Path
HERE=Path(__file__).resolve().parent;ROOT=HERE.parent
sys.path.insert(0,str(ROOT/'tools'))
import ebilanz_assets as assets
import ebilanz_builder as builder
import ebilanz_cost_assets as cost
from ebilanz_tax_assets import verify
from ebilanz_mapping import automatic_mapping
from ebilanz_period_assets import rebuild
spec=importlib.util.spec_from_file_location('cost_xml_native_inputs',HERE/'ebilanz-native.py');base=importlib.util.module_from_spec(spec);spec.loader.exec_module(base)

def fixtures(code,variant,mode='annual'):
    r=subprocess.run([os.environ['VEREIN_FIXTURE_PHP'],str(HERE/'fixtures/ebilanz-cost-assets.php'),code,variant,mode],capture_output=True,encoding='utf8',timeout=60)
    if r.returncode or r.stderr:raise RuntimeError(r.stdout+r.stderr)
    return json.loads(r.stdout)

def inputs(values):
    mapping,profile=base.inputs(values);mapping={k:v for k,v in mapping.items() if k in values['tax']['accounts']};mapping.update(automatic_mapping(values))
    if 'whole_club_scope' in values:profile.update(kind='whole_taxable_club',source_scope='complete_club_ledger',tax_privileged=False,asset_scope='whole_club')
    if 'period' in values:
        changed=any(i['cost']['opening_difference_cents'] or i['cost']['closing_difference_cents'] or any(e['asset_difference_cents'] for e in (i['cost']['events'] or {}).values())
            for i in values['asset_tax']['items'].values())
        profile.update(asset_values_unchanged=not changed,asset_tax_values_reviewed=changed)
    return mapping,profile

def build(values):
    mapping,profile=inputs(values)
    return builder.EbilanzBuilder().build(values,mapping,profile,'12345')

HISTORIC_COST={'02150','02350','09200'}

class CostAssets(unittest.TestCase):
    def test_php_values_are_independently_reconstructed_before_xml(self):
        for code in sorted(HISTORIC_COST):
            for mode in ['annual','whole']:
                for variant in ['unchanged','impairment','tax-only','multiple','writeup','writeup-release','fully']:
                    for row in fixtures(code,variant,mode):
                        values=row['values'];before=copy.deepcopy(values);mapping,profile=inputs(values)
                        self.assertEqual(automatic_mapping(values),row['automatic'])
                        self.assertEqual(verify(values,profile,mapping),values['asset_tax']['tax_schedule'])
                        package=build(values);self.assertEqual(package['asset_items'],1);self.assertGreater(package['asset_fact_count'],0)
                        self.assertIn('revaluation',package['xml']);self.assertEqual(values,before)
    def test_partial_month_periods_keep_original_tax_carry_and_all_valuation_columns(self):
        for code in sorted(HISTORIC_COST):
            for mode in ['period','period-whole']:
                for variant in ['unchanged','impairment','tax-only','multiple','writeup','writeup-release','fully']:
                    for row in fixtures(code,variant,mode):
                        values=row['values'];before=copy.deepcopy(values)
                        self.assertEqual(automatic_mapping(values),row['automatic']);rebuild(values)
                        package=build(values);self.assertEqual(package['asset_items'],1)
                        self.assertIn(values['period']['start'],package['xml']);self.assertEqual(values,before)
    def test_mixed_ordinary_and_cost_assets_reconcile_separate_accounts_for_the_same_disposal_code(self):
        for code in sorted(HISTORIC_COST):
            for mode in ['mixed','mixed-whole']:
                for variant in ['unchanged','impairment','tax-only','multiple','writeup','writeup-release']:
                    for row in fixtures(code,variant,mode):
                        values=row['values'];self.assertEqual(automatic_mapping(values),row['automatic'])
                        package=build(values);self.assertEqual(package['asset_items'],2)
    def test_tampered_tax_journal_and_counterpart_do_not_gain_permission(self):
        original=fixtures('02150','writeup')[-1]['values']
        for mutate in [lambda v:v['asset_tax']['tax_schedule']['items']['synthetic-cost'].__setitem__('writeup_cents',1),
            lambda v:v['asset_schedule']['items']['synthetic-cost']['cost_plan'].__setitem__('months',60),
            lambda v:v['tax']['accounts']['fixed'].__setitem__('debit_cents',1),
            lambda v:v['asset_schedule']['items']['synthetic-cost']['valuation_accounts'].__setitem__('49100','capital')]:
            bad=copy.deepcopy(original);mutate(bad);payload={k:v for k,v in bad.items() if k not in ('sha256','transmission')}
            import hashlib
            bad['sha256']=hashlib.sha256(json.dumps(payload,ensure_ascii=False,separators=(',',':')).encode()).hexdigest()
            with self.assertRaises(ValueError):build(bad)
    def test_generic_securities_require_an_evidenced_kind(self):
        with self.assertRaises(RuntimeError):fixtures('09000','impairment')
        approved=fixtures('02150','impairment')[0]['values'];mapping,profile=inputs(approved)
        self.assertEqual(builder.EbilanzBuilder().build(approved,mapping,profile,'12345')['asset_items'],1)
    def test_cost_combinations_and_unproven_gross_movements_cannot_be_ignored(self):
        original=fixtures('02150','multiple')[0]['values']
        for extra in ['disposal','gwg','degressive','sphere_change','partial_plan']:
            bad=copy.deepcopy(original);bad['asset_schedule']['items']['synthetic-cost'][extra]={}
            with self.assertRaises(ValueError):build(bad)
        v=fixtures('02150','tax-only','period')[0]['values'];m,p=inputs(v)
        p.update(asset_values_unchanged=True,asset_tax_values_reviewed=False)
        with self.assertRaises(ValueError):builder.EbilanzBuilder().build(v,m,p,'12345')
    def test_temporary_financial_impairment_is_book_only_in_every_scope(self):
        for mode in ['annual','whole','period','period-whole','mixed','mixed-whole']:
            row=fixtures('09200','temporary',mode)[0];v=row['values'];self.assertEqual(v['tax']['accounts']['src_asset_valuation_72010']['amount_cents'],0)
            self.assertEqual(v['book']['accounts']['src_asset_valuation_72010']['amount_cents'],4000)
            build(v)
    def test_new_cost_targets_cannot_be_selected_without_a_cost_valuation(self):
        values=base.fixture('unchanged');mapping,profile=base.inputs(values)
        for code,target in cost.COUNTERPARTS.items():
            # Already qualified standalone intangible recovery and ordinary intangible disposal keep their existing source contracts.
            if code in ('68950','49110','68960'):continue
            v=copy.deepcopy(values);group='revenue' if code.startswith('49') else 'expense';key=next(k for k,a in v['tax']['accounts'].items() if a['group']==group)
            for ledger in ('book','tax'):v[ledger]['accounts'][key]['skr_code']=code
            v['accounts'][key]['skr_code']=code
            import hashlib
            v['sha256']=hashlib.sha256(json.dumps({k:n for k,n in v.items() if k not in ('sha256','transmission')},ensure_ascii=False,separators=(',',':')).encode()).hexdigest()
            selected=copy.deepcopy(mapping);selected[key]=target;p=copy.deepcopy(profile);p['tax_values_sha256']=v['sha256']
            with self.assertRaisesRegex(ValueError,'ursprünglichen Bewertungsnachweis'):builder.EbilanzBuilder().build(v,selected,p,'12345')

if __name__=='__main__':unittest.main(verbosity=2)

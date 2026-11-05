"""Own XBRL generation from reviewed tax balances; validation-only integration.

Profiles: separately balanced business, aggregation of ALL taxable club units
(SGEB), complete non-tax-privileged club (B/GuV/BVV), or original whole-club
B/GuV plus taxable SGEB. AO or explicit HGBM, GKV, 2025/2026.
Dated journals: separate business with independently replayed asset movements.
No network, certificate, transmission API, sample XML template or inferred mapping.
"""
import hashlib
import json
from pathlib import Path
import re
import xml.etree.ElementTree as ET
from ebilanz_assets import prepare_assets, ASSETS, TANGIBLE, INTANGIBLE, BUILDING, GOODWILL, GWG, SCRAPPING, SOFTWARE_END
from ebilanz_mapping import automatic_mapping
from ebilanz_sales import GAIN_SALE,GAIN_BOOK,LOSS_SALE,fact_amount
from ebilanz_club_scope import validate as validate_club_scope
from ebilanz_whole_club import is_whole, bvv
from ebilanz_operating import REVENUE as OPERATING_REVENUE, EXPENSE as OPERATING_EXPENSE
from ebilanz_working_capital import SUPPORTED as WORKING_CAPITAL, disclosures

NS = {'e': 'http://www.elster.de/elsterxml/schema/v11',
      'b': 'http://rzf.fin-nrw.de/RMS/EBilanz/2016/XMLSchema',
      'xbrli': 'http://www.xbrl.org/2003/instance', 'link': 'http://www.xbrl.org/2003/linkbase',
      'xlink': 'http://www.w3.org/1999/xlink', 'xsi': 'http://www.w3.org/2001/XMLSchema-instance',
      'xbrldi': 'http://xbrl.org/2006/xbrldi', 'iso4217': 'http://www.xbrl.org/2003/iso4217',
      'de-gcd': 'http://www.xbrl.de/taxonomies/de-gcd-2025-04-01',
      'de-gaap-ci': 'http://www.xbrl.de/taxonomies/de-gaap-ci-2025-04-01'}
for prefix, uri in NS.items():
    ET.register_namespace(prefix, uri)
BASE = 'DeterminationOfTaxableIncomeSpec.forProfitOrganisationsAndUtilities.'
LIMIT = 100000000000
SALES = 'is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.netSales.grossSales.'
# Explicitly tested mappings, not inference from account numbers or account labels.
SUPPORTED = {
    'cash': {'bs.ass.currAss.cashEquiv.bank', 'bs.ass.currAss.cashEquiv.cash'},
    'receivables': {'bs.ass.currAss.receiv.trade.'+s for s in ('other','shareholders','misc')},
    'liabilities': {'bs.eqLiab.liab.trade.'+s for s in ('genOther','shareholders','misc')}
        | {'bs.eqLiab.liab.other.'+s for s in ('other','shareholders','profitPartLoans','employees','thereoffSocSec','theroffTax.vat')}
        | {'bs.eqLiab.liab.bank','bs.eqLiab.liab.statutoryBenefits.commitments','bs.eqLiab.liab.conditionalRepayableDonations'},
    'member_payables': {'bs.eqLiab.liab.other.other'},
    'other_assets': {'bs.ass.currAss.receiv.other.'+s for s in ('other','vat','shareholders','bodies','employees',
        'otherTaxRec','tradeTaxOverpayment','corpTaxOverpayment','socInsur','jointWork')},
    'inventory': {'bs.ass.currAss.inventory.'+suffix for suffix in ('material','inProgress','inProgress.goods','inProgress.services','finishedAndMerch','finishedAndMerch.goods','finishedAndMerch.merchandise','advPaymPaid')},
    'prepaid': {'bs.ass.prepaidExp'},
    'deferred': {'bs.eqLiab.defIncome'},
    'provisions': {'bs.eqLiab.accruals.other.'+s for s in ('other','guarantees','businessRecordsRetension')}
        | {'bs.eqLiab.accruals.tax.gewst','bs.eqLiab.accruals.tax.kst'},
    'equity': {'bs.eqLiab.equity.'+suffix for suffix in ('retainedEarnings','association','SurplusOfUnspentFunds','statutoryBoundCapital','reallocationSurplus')},
    'reserves': {'bs.eqLiab.equity.revenueRes.'+suffix for suffix in ('charityAppropriated.project','charityAppropriated.replacement','charityAppropriated.workingCapital','charityAppropriated.shareAcquisition','unappropriated.assetManagement','unappropriated.otherMeansDueCourse','investment','other','statutory')},
    'revenue': {SALES+suffix for suffix in ('taxExemptUStG19_1','generalRateVAT','reducedRateVAT')} | {GAIN_SALE,GAIN_BOOK} | OPERATING_REVENUE,
    'expense': {'is.netIncome.regular.operatingTC.otherCost.'+suffix for suffix in (
        'administration','communication','freight','fees','vehicles','leaseMoveable.other','custodyFee',
        'insurance','legalConsulting','marketing','publicRelations','leaseFix.other','energyCost','fixingLandBuildings','fixing','training',
        'leasingAll.moveable.other','leasingAll.immovable.other','leaseFix.shareholders','leaseMoveable.shareholders',
        'otherOrdinary','otherTaxes.nonDeductibleInputVAT','transferValuatonPresentYear.global',
        'transferValuatonPresentYear.specific','regAllowance','concessLicenses.toresidentRecipients',
        'concessLicenses.toNonresidentRecipients','concessLicenses.nonallocable')}
        | {'is.netIncome.regular.operatingTC.staff.'+suffix for suffix in (
            'salaries.misc','salaries.managerPartner','salaries.minijobs','salaries.voluntayBenefits','salaries.inKind',
            'salaries.nonAllocable','social.socExp.other','social.pensions.shareholderManager','social.pensions.other',
            'social.pensions.misc','social.welfare')}
        | {'is.netIncome.regular.operatingTC.grossTradingProfit.materialServices.material.'+suffix for suffix in (
            'rawMatConsSup.reducedRateVAT','rawMatConsSup.generalRateVAT','rawMatConsSup.noDeductVAT',
            'rawMatConsSup.intraEU','rawMatConsSup.unknownVAT','purchased.reducedRateVAT','purchased.generalRateVAT',
            'purchased.withoutVAT','purchased.intraEU','purchased.unknownVAT')}
        | {'is.netIncome.otherTaxes.other',TANGIBLE, INTANGIBLE, BUILDING, GOODWILL, GWG, SCRAPPING,SOFTWARE_END,LOSS_SALE} | OPERATING_EXPENSE,
    'fixed_assets': set(ASSETS),
}
from ebilanz_pool_original_values import TARGET as POOL_TARGET, EXPENSE as POOL_EXPENSE, mapping as pool_mapping
SUPPORTED['fixed_assets'].add(POOL_TARGET)
SUPPORTED['expense'].add(POOL_EXPENSE)
from ebilanz_income_use import CONTRACTS as INCOME_USE_CONTRACTS
SUPPORTED['result_use']={t for c in INCOME_USE_CONTRACTS.values() for t in c['targets']}
from ebilanz_tax_equalization import TARGET as TAX_EQUALIZATION_TARGET
SUPPORTED['result_use'].add(TAX_EQUALIZATION_TARGET)
from ebilanz_commercial import SUPPORTED as COMMERCIAL_TARGETS
for group, targets in COMMERCIAL_TARGETS.items():
    SUPPORTED[group] |= targets
for group, targets in WORKING_CAPITAL.items():
    SUPPORTED[group] |= targets
from ebilanz_cost_assets import TARGETS as COST_TARGETS, COUNTERPARTS as COST_COUNTERPARTS, mapping as cost_mapping
COST_GUARDED = COST_TARGETS | (set(COST_COUNTERPARTS.values()) - {SCRAPPING,'is.netIncome.regular.operatingTC.otherOpRevenue.revalFixAss.intan','is.netIncome.regular.operatingTC.deprAmort.fixAss.specific.except.otherIntan','is.netIncome.regular.operatingTC.otherCost.disposFixAss.bookValue.intan'})
SUPPORTED['fixed_assets'] |= COST_TARGETS
from ebilanz_partnership_holdings import TARGETS as HOLDING_TARGETS, mapping as holding_mapping, verify_values as holding_values
from ebilanz_own_intangible_books import TARGET as OWN_HGB_TARGET, COUNTERS as OWN_HGB_COUNTERS, mapping as own_hgb_mapping
SUPPORTED['fixed_assets'].add(OWN_HGB_TARGET)
for code,target in OWN_HGB_COUNTERS.items():SUPPORTED['revenue' if code in ('48250','49110') else 'expense'].add(target)
COST_GUARDED |= {OWN_HGB_TARGET,OWN_HGB_COUNTERS['48250']}
SUPPORTED['fixed_assets'] |= HOLDING_TARGETS
COST_GUARDED |= HOLDING_TARGETS
SUPPORTED['inventory'].add('bs.ass.currAss.inventory.advPaymReceived')
from ebilanz_exceptional_original import TARGET as EXCEPTIONAL_TARGET,GOODWILL_TARGET
SUPPORTED['expense'].update((EXCEPTIONAL_TARGET,GOODWILL_TARGET))
from ebilanz_pension_offsets import CODES as PENSION_CODES,GROUPS as PENSION_GROUPS,target as pension_target
for code in PENSION_CODES:SUPPORTED[PENSION_GROUPS[code]].add(pension_target(code))
from ebilanz_special_tax_7g import TARGET as SPECIAL_7G_TARGET
SUPPORTED['expense'].add(SPECIAL_7G_TARGET)
from ebilanz_basis_reduction_6b import TARGET as BASIS_6B_TARGET
SUPPORTED['expense'].add(BASIS_6B_TARGET)
from ebilanz_financial_reserve_xml import TARGETS as FINANCIAL_RESERVE_TARGETS
SUPPORTED['expense'] |= FINANCIAL_RESERVE_TARGETS
from ebilanz_basis_reduction_7g import TARGET as BASIS_7G_TARGET
SUPPORTED['expense'].add(BASIS_7G_TARGET)
# Source-typed investment fund counterparts remain individually cost-evidenced.
for code,target in {'49120': 'is.netIncome.regular.operatingTC.otherOpRevenue.revalFixAss.fin.investmentShares', '68970': 'is.netIncome.regular.operatingTC.otherCost.disposFixAss.bookValue.fin.investmentShares', '72000': 'is.netIncome.regular.fin.netParticipation.amortFinanc.financialsExcept.investementShares', '72010': 'is.netIncome.regular.fin.netParticipation.amortFinanc.financialsExcept.investementShares'}.items():SUPPORTED['revenue' if code.startswith('49') else 'expense'].add(target)
for target in ['is.netIncome.regular.fin.netParticipation.amortFinanc.financialsExcept.receivCorp25pt.domestic', 'is.netIncome.regular.fin.netParticipation.amortFinanc.financialsExcept.receivCorp25pt.foreign']:SUPPORTED['expense'].add(target)
for code,target in COST_COUNTERPARTS.items():
    SUPPORTED['revenue' if code.startswith('49') else 'expense'].add(target)


def node(parent, prefix, name, value=None, **attrs):
    el = ET.SubElement(parent, '{'+NS[prefix]+'}'+name, attrs)
    if value is not None:
        el.text = str(value)
    return el


def text(value, label, length=200):
    if not isinstance(value, str) or not value.strip() or len(value) > length or re.search(r'[\x00-\x1f]', value):
        raise ValueError(label+' fehlt oder ist ungültig.')
    return value.strip()


def cents(value):
    if type(value) is not int or abs(value) > LIMIT:
        raise ValueError('Betrag muss ganzzahlig in Cent vorliegen.')
    return value


def money(value):
    value = cents(value)
    return ('-' if value < 0 else '')+str(abs(value)//100)+'.'+str(abs(value)%100).zfill(2)


class EbilanzBuilder:
    def __init__(self, taxonomy='6.9', *, environment='test'):
        if not isinstance(environment, str) or environment not in ('test', 'production'):
            raise ValueError('E-Bilanz-Umgebung prüfen.')
        self.environment = environment
        # Historic/future sources also supply target inspection. Their output
        # adapters need a separate native acceptance before build() can use them.
        from taxonomy_sources import source
        self.taxonomy = taxonomy
        self.taxonomy_bytes, self.meta = source(taxonomy)
        self.concepts = self.meta['concepts']['de-gaap-ci']

    def permitted(self, name, year, *, book_only=None):
        c = self.concepts.get(name, {})
        r = c.get('refs', {})
        if getattr(self,'whole_club',False) and 'UKV' in r.get('typeOperatingResult',[]):
            return False
        if c.get('abstract', True) or c.get('type') != 'xbrli:monetaryItemType':
            return False
        if any('true' in r.get(k,[]) for k in ('legalFormEU','legalFormPG')) and 'true' not in r.get('legalFormKSt',[]):
            return False
        original_books=getattr(self,'book_only',False) if book_only is None else book_only
        forbidden={'handelsrechtlicher Einzelabschluss','Einreichung an Finanzverwaltung'} | (set() if original_books else {'steuerlich'})
        if set(r.get('notPermittedFor', [])) & forbidden:
            return False
        if 'handelsrechtlicher Einzelabschluss' in r.get('tradeAccountingNotPermittedFor',[]):
            return False
        start = getattr(self,'period',{}).get('start',str(year)+'-01-01')
        end = getattr(self,'period',{}).get('end',str(year)+'-12-31')
        # Official general reference dates are independent of fiscal dates.
        # ERiC checks the beginning AND the balance-sheet date.
        for since, through in (('ValidSince','ValidThrough'),('fiscalValidSince','fiscalValidThrough')):
            if any(bound > start for bound in r.get(since,[])) or any(bound < end for bound in r.get(through,[])):
                return False
        return True

    def build(self, values, mapping, profile, manufacturer):
        if self.taxonomy != '6.9':
            raise ValueError('Für diese Taxonomie ist der Ausgabeadapter noch nicht nativ abgenommen.')
        if profile.get('kind')=='tax_privileged_club_with_subsidiary':
            from ebilanz_subsidiary import build
            return build(self,values,mapping,profile,manufacturer)
        if profile.get('kind')=='tax_privileged_club_with_sgeb':
            from ebilanz_combined_club import build
            return build(self,values,mapping,profile,manufacturer)
        root,balance,meta=self._build_tree(values,mapping,profile,manufacturer)
        return self._serialize(root,balance,meta)

    def _build_tree(self, values, mapping, profile, manufacturer, *, book_only=False):
        # Private checked section builder. book_only is used solely by the
        # composite profile, whose original books cannot contain tax corrections.
        payload = {k: v for k, v in values.items() if k not in ('sha256','transmission')}
        expected_hash = hashlib.sha256(json.dumps(payload,ensure_ascii=False,separators=(',',':')).encode('utf8')).hexdigest()
        if expected_hash != values.get('sha256'):
            raise ValueError('Steuerwerte wurden nach der Bestätigung verändert.')
        year = values.get('year')
        from ebilanz_release import package
        try:
            self.release_package = package('6.9',year)
        except ValueError as exc:
            raise ValueError('Taxonomie 6.9: '+str(exc)) from None
        if hashlib.sha256(self.taxonomy_bytes.replace(b'\r\n',b'\n')).hexdigest() != self.release_package['taxonomy']['sha256']:
            raise ValueError('Geladene Taxonomie und Jahrespaket stimmen nicht überein.')
        if any(type(values.get(side)) is not dict or type(values[side].get('year')) is not int
               or values[side]['year'] != year for side in ('book', 'tax')):
            raise ValueError('Buch- und Steuerbilanz gehören nicht zum bestätigten Abschlussjahr.')
        from ebilanz_period import dates
        self.period, self.previous_period = dates(values,profile,book_only=book_only)
        if profile.get('scope_confirmed') is not True or profile.get('tax_values_sha256') != values.get('sha256'):
            raise ValueError('Abgegrenzten Geschäftsbetrieb und genau diesen Steuerwertestand bestätigen.')
        validate_club_scope(values,profile,book_only=book_only)
        self.whole_club=is_whole(profile)
        self.book_only=book_only
        if book_only and 'partnership_holdings' in values:raise ValueError('Ursprüngliche HGB-Beteiligungsmatrix noch nicht an den zusammengesetzten Bericht angebunden.')
        holding_values(values)
        legal_status=profile.get('company',{}).get('legal_status','')
        if not isinstance(legal_status,str):raise ValueError('Bestätigte Rechtsform muss eine Zeichenfolge sein.')
        from ebilanz_financial_profile import check_reporter
        check_reporter(values,legal_status,book_only)
        self.legal_status=legal_status or 'Other.KOER'
        if self.legal_status not in ('Other.KOER','EV','ST','GMBH','gGMBH','AG','UG','EG'):raise ValueError('Bestätigte Rechtsform der Körperschaft auswählen.')
        # The published GCD gGMBH choice is forbidden for fiscal submission.
        # A charitable GmbH has the GmbH legal form; exemption remains separate.
        if self.legal_status=='gGMBH':
            ref=self.meta['concepts']['de-gcd']['genInfo.company.id.legalStatus.legalStatus.gGMBH']['refs']
            if 'Einreichung an Finanzverwaltung' not in ref.get('notPermittedFor',[]):raise ValueError('Amtliche Zuordnung der gemeinnützigen GmbH prüfen.')
            self.legal_status='GMBH'
        if not re.fullmatch('[a-f0-9]{64}', values.get('sha256', '')) or values.get('transmission') != 'unavailable':
            raise ValueError('Geprüfter Steuerwertestand fehlt.')
        tax = values['tax']
        if tax.get('issues') or tax.get('difference_cents') != 0:
            raise ValueError('Steuerbilanz ist nicht abgestimmt.')
        if not self.whole_club and any(sphere != '4' for sphere in values.get('source_spheres', ['unknown'])):
            raise ValueError('Das Quelljournal enthält Buchungen anderer Vereinsbereiche.')
        for sphere in ('1', '2', '3'):
            if not self.whole_club and any(cents(tax['spheres'][sphere][k]) != 0 for k in ('income_cents', 'expense_cents')):
                raise ValueError('Andere Vereinsbereiche dürfen nicht als Geschäftsbetrieb übermittelt werden.')
        # Even cancelling entries from another sphere must never be silently included.
        if type(profile.get('tax_privileged')) is not bool:
            raise ValueError('Steuerbegünstigung des Vereins ausdrücklich angeben.')
        if not isinstance(mapping, dict) or set(mapping) - set(tax['accounts']):
            raise ValueError('Kontenzuordnung enthält unbekannte Konten.')
        # Explicit reviewed exceptions override exact-code defaults. Unsupported
        # codes remain unresolved, never sent to a miscellaneous catch-all account.
        mapping = automatic_mapping(values,mapping) | mapping
        pool_bound=pool_mapping(values)
        if any(target in (POOL_TARGET,POOL_EXPENSE) and pool_bound.get(key)!=target for key,target in mapping.items()):
            raise ValueError('Sammelposten: ursprüngliche Wahl, HGB-Einzelwerte und beide vollständigen Journale fehlen.')
        from ebilanz_commercial import check_values as commercial_values
        commercial_excluded=commercial_values(values,mapping,book_only=book_only)
        from ebilanz_own_intangible_values import excluded as own_intangible_excluded
        commercial_excluded |= own_intangible_excluded(values,book_only)
        from ebilanz_pension_offsets import check_values as pension_offsets
        pension_excluded,pension_facts,pension_reduction=pension_offsets(values,mapping,book_only)
        from ebilanz_pension_offsets import SURPLUS as PENSION_SURPLUS,INTEREST as PENSION_INTEREST
        if any(t in (PENSION_SURPLUS,PENSION_INTEREST) and k not in pension_excluded for k,t in mapping.items()):raise ValueError('Pensionsverrechnung benötigt die ursprünglichen einzeln gebundenen Konten.')
        commercial_excluded |= pension_excluded
        mapping={k:t for k,t in mapping.items() if k not in commercial_excluded}
        from ebilanz_commercial import partnership_mapping
        mapping,partnership_facts=partnership_mapping(values,mapping,book_only=book_only)
        cost_bound=cost_mapping(values)
        from ebilanz_substance_original import original_mapping as substance_mapping
        for key,target in substance_mapping(values).items():
            if key in cost_bound and cost_bound[key]!=target:raise ValueError('Ursprüngliche Substanzanlage widerspricht der Kostenbewertung.')
            cost_bound[key]=target
        for key,target in own_hgb_mapping(values).items():
            if key in mapping and mapping[key]!=target:raise ValueError('Ursprünglicher selbst geschaffener HGB-Wert wurde anders zugeordnet.')
            mapping[key]=target;cost_bound[key]=target
        for key,target in holding_mapping(values).items():
            if key in cost_bound and cost_bound[key]!=target:raise ValueError('Ursprüngliche Beteiligung und gewöhnliche Kostenanlage haben widersprüchliche Quellen.')
            cost_bound[key]=target
        if any(target in COST_GUARDED and cost_bound.get(key)!=target for key,target in mapping.items()):
            raise ValueError('Kostenanlagen: Diese XML-Zuordnung benötigt den ursprünglichen Bewertungsnachweis.')
        from ebilanz_account_closing import check_values
        allowances = check_values(values,mapping,profile)
        from ebilanz_directional_closing import check_values as directional_values
        directional = directional_values(values,mapping)
        from ebilanz_reserve_closing import check_values as reserve_values
        reserve_contra=reserve_values(values,mapping)
        from ebilanz_own_shares import check_values as own_share_values
        own_share_contra,own_share_facts=own_share_values(values,mapping,profile)
        reserve_contra |= own_share_contra
        from ebilanz_original_offsets import check_values as original_offsets
        reserve_contra |= original_offsets(values,mapping)
        from ebilanz_original_reclassification import check_values as original_split_values
        reserve_contra |= original_split_values(values,mapping)
        from ebilanz_technical_closing import check_values as technical_closing
        technical_closing(values,mapping)
        from ebilanz_tax_equalization import presentation as equalization_presentation
        equalization=equalization_presentation(values,mapping,book_only)
        from ebilanz_income_use import check_values as income_use_values
        income_use=income_use_values(values,mapping,book_only=book_only);self.has_income_use=income_use['active']
        self.income_use_family=income_use['family'];self.ends_with_balance_profit=self.has_income_use and self.income_use_family=='corporate'
        if self.ends_with_balance_profit and not self.whole_club:raise ValueError('Ergebnisverwendung der Kapitalgesellschaft benötigt die vollständige Bilanz; ein einzelner steuerpflichtiger Vereinsbetrieb genügt nicht.')
        from ebilanz_income_use import network
        income_network=network() if self.has_income_use else {'calculations':{},'parents':{}}
        calculations=self.meta['calculations']|income_network['calculations']
        parents=self.meta['parents']|income_network['parents']
        from ebilanz_special_tax_7g import check as special_tax_7g
        special_tax_7g(values,mapping,book_only)
        from ebilanz_special_tax_7b import check as special_tax_7b
        special_tax_7b(values,mapping,book_only)
        from ebilanz_basis_reduction_6b import check as basis_reduction_6b
        basis_reduction_6b(values,mapping,book_only)
        from ebilanz_basis_reduction_7g import check as basis_reduction_7g
        basis_reduction_7g(values,mapping,book_only)
        assets = prepare_assets(values,mapping,profile,self.meta)
        if book_only:
            from ebilanz_financial_profile import book_presentation
            mapping,assets=book_presentation(values,mapping,assets,self.meta)
        facts = {}; accounts = {}
        for key, account in tax['accounts'].items():
            if key in equalization['keys'] or key in commercial_excluded or key in income_use['keys'] or (self.ends_with_balance_profit and account['skr_code'] in ('29700','29780')):continue
            amount = cents(account['amount_cents'])
            if any(cents(account[k]) != 0 for k in ('opening_cents', 'debit_cents', 'credit_cents')) and key not in mapping:
                raise ValueError('E-Bilanz-Zuordnung fehlt: '+key)
            if key not in mapping:
                continue
            target = mapping[key]
            if not isinstance(target, str) or not self.permitted(target, year) or not target.startswith(('bs.', 'is.')):
                raise ValueError('Nicht unterstützte Taxonomieposition: '+str(target))
            if target=='bs.eqLiab.liab.bank':raise ValueError('Bankverbindlichkeit auf der bestätigten Unterposition ausweisen.')
            group = account['group']
            expected = 'is.' if group in ('revenue', 'expense') else ('bs.ass.' if group in ('cash','receivables','inventory','prepaid','other_assets','fixed_assets') else 'bs.eqLiab.')
            if key in directional:expected='bs.ass.' if target.startswith('bs.ass.') else 'bs.eqLiab.'
            if not target.startswith(expected):
                raise ValueError('Kontenart und Taxonomieposition widersprechen sich: '+key)
            if target not in SUPPORTED.get(group,set()):
                raise ValueError('Dieser Konten-/Taxonomiefall ist noch nicht freigegeben: '+key)
            if group not in ('equity','revenue','expense') and amount < 0 and key not in allowances and key not in directional and key not in reserve_contra:
                raise ValueError('Gegenseitigen Saldo getrennt ausweisen: '+key)
            facts[target] = cents(facts.get(target, 0)+(directional[key]['amount_cents'] if key in directional else fact_amount(target,amount,group)))
            accounts.setdefault(target, []).append((key, directional.get(key,account)))
        for target in {mapping[k] for k in allowances if k in mapping}:
            if facts.get(target,0) < 0: raise ValueError('Wertberichtigung überschreitet die zugehörige Schuldnergruppe.')
        for target, amount in disclosures(values, mapping, self.meta, self.permitted).items():
            if target in facts:
                raise ValueError('Zusatzangabe darf nicht als eigenes Bilanzkonto gebucht werden.')
            facts[target] = amount
        for target,amount in partnership_facts.items():
            if target in facts:raise ValueError("Beteiligungsertrag: Davon-Angabe darf nicht zusätzlich gebucht werden.")
            facts[target]=amount
        from ebilanz_financial_profile import disclosures as financial_disclosures
        for target,amount in financial_disclosures(values,book_only).items():
            if target in facts:raise ValueError('Anteile an der herrschenden Gesellschaft zusätzlich zuordnen; keine zweite Buchung des gleichen Kapitalbetrags.')
            facts[target]=cents(amount)
        from ebilanz_partnership_holdings import disclosures as holding_disclosures
        for target,amount in holding_disclosures(values,book_only).items():
            if target in accounts:raise ValueError('Ursprüngliche herrschende Beteiligung darf keine zusätzliche Bilanzbuchung erzeugen.')
            facts[target]=cents(facts.get(target,0)+amount)
        for target,amount in own_share_facts.items():
            if target in facts:raise ValueError('Mehrerwerbskosten eigener Anteile sind eine Zusatzangabe, keine zweite Buchung.')
            facts[target]=cents(amount)
        from ebilanz_own_intangible_books import disclosures as own_hgb_disclosures
        for target,amount in own_hgb_disclosures(values,book_only).items():
            if target in facts:raise ValueError('Davon-Ausweis selbst geschaffener Werte darf keine zweite Buchung erzeugen.')
            facts[target]=cents(amount)
        for target,amount in pension_facts.items():
            if not self.permitted(target,year):raise ValueError('Pensionsverrechnung: handelsrechtliche Taxonomieposition unzulässig.')
            facts[target]=cents(facts.get(target,0)+amount)
        # Calculated annual result is equity, never an invented balancing account.
        result = cents(tax['annual_result_cents'])
        result_position = 'bs.eqLiab.equity.profitLoss' if self.ends_with_balance_profit else 'bs.eqLiab.equity.netIncome'
        if result_position in facts:
            raise ValueError('Jahresergebnis darf nicht doppelt zugeordnet werden.')
        for target,n in equalization['facts'].items():
            if target in facts:raise ValueError('Steuerlicher Ausgleichsposten darf nicht zusätzlich gebucht werden.')
            if not self.permitted(target,year):raise ValueError('Steuerlicher Ausgleichsposten ist für diese Ausgabe unzulässig.')
            facts[target]=cents(n)
        facts[result_position] = cents(result+income_use['balance_effect']) if self.ends_with_balance_profit else cents(result+equalization['result_effect'])
        facts.update(income_use['facts'])
        if self.has_income_use and self.income_use_family=='foundation':
            key=values['income_use_source']['source']['result_account'];target=mapping[key]
            if target not in ('bs.eqLiab.equity.association','bs.eqLiab.equity.SurplusOfUnspentFunds'):raise ValueError('Ergebnisverwendung muss in das bestätigte Vereinskapital oder den Ergebnisvortrag abgeschlossen werden.')
            facts[target]=cents(facts.get(target,0)+income_use['balance_effect'])
            accounts.setdefault(target,[]).extend((k,tax['accounts'][k]) for k in sorted(income_use['keys']))
        # A ledger containing only balance-sheet movements has an actual zero
        # result, not an unknown income statement. Every active account has
        # already passed mapping/group checks above. Do not infer zero when
        # any income-statement fact exists (including cancelling movements).
        if result == 0 and not any(name.startswith('is.') for name in facts):
            facts['is.netIncome'] = 0

        def calculate(name, stack=()):
            if name in stack:
                raise ValueError('Zyklus in Taxonomie.')
            children = calculations.get(name, {})
            parts = [(weight, calculate(child, stack+(name,))) for child, weight in children.items()]
            present = [(w, v) for w, v in parts if v is not None]
            if present:
                if name in facts:
                    raise ValueError('Überlappende Taxonomiezuordnung: '+name)
                return cents(sum(w*v for w, v in present))
            return facts.get(name)

        derived = {name: calculate(name) for name in calculations if name not in facts}
        facts.update({k: v for k, v in derived.items() if v is not None})
        for target, measures in assets['rows'].items():
            if facts.get(target,0)!=measures['all']:
                raise ValueError('Anlagenspiegel und Bilanzposition stimmen nicht überein: '+target)
        for key, expected in [('bs.ass', tax['asset_total_cents']-pension_reduction), ('bs.eqLiab', tax['passive_total_cents']-pension_reduction), ('is.netIncome', result)]:
            if facts.get(key) != expected:
                raise ValueError('Taxonomie und Journal stimmen nicht überein: '+key)
        required = {n for n, c in self.concepts.items() if (n.startswith(('bs.', 'is.')) or (self.has_income_use and n.startswith('incomeUse.'))) and self.permitted(n, year)
                    and any(r.startswith('Mussfeld') for r in c['refs'].get('fiscalRequirement', []))}
        selected = set(facts) | required
        pending = list(selected)
        while pending:
            for parent in parents.get(pending.pop(), []):
                if self.permitted(parent, year) and parent not in selected:
                    selected.add(parent); pending.append(parent)
        self.year = year
        self.company = profile['company']
        self.tax_privileged = profile['tax_privileged']
        self.has_assets = bool(assets['rows'])
        self.business = text(profile.get('business_name'), 'Geschäftsbetrieb')
        revision = profile.get('filing_revision', {'status':'E'})
        if not isinstance(revision,dict) or revision.get('status') not in ('E','B','G','BG','sK'):
            raise ValueError('Abgabeart der E-Bilanz prüfen.')
        self.revision_status = revision['status']
        if revision != {'status':'E'}:
            if set(revision) != {'status','reason','legal_basis','confirmed','source','journal_sha256','identity_sha256'} or revision['confirmed'] is not True:
                raise ValueError('Bestätigte Abgabeart fehlt.')
            for key in ('journal_sha256','identity_sha256'):
                if not isinstance(revision[key],str) or not re.fullmatch('[a-f0-9]{64}',revision[key]):
                    raise ValueError('Abgabeart und Abschlussbezug prüfen.')
            text(revision['reason'],'Grund der Abgabeart',1000)
            if self.revision_status=='E':
                if revision['source'] is not None or revision['legal_basis']!='':raise ValueError('Erstabgabe darf keine frühere Abgabe ersetzen.')
            else:
                text(revision['legal_basis'],'Zulässigkeit der Korrektur',1000)
                source=revision['source']
                if not isinstance(source,dict) or set(source)!={'filing_id','attempt_id','xml_sha256','transfer_ticket','journal_sha256','approval_sha256'}:
                    raise ValueError('Ursprünglicher Übermittlungsnachweis fehlt.')
                for key in ('filing_id','attempt_id','xml_sha256','journal_sha256','approval_sha256'):
                    size=32 if key in ('filing_id','attempt_id') else 64
                    if not isinstance(source[key],str) or not re.fullmatch('[a-f0-9]{'+str(size)+'}',source[key]):raise ValueError('Ursprünglichen Übermittlungsnachweis prüfen.')
                text(source['transfer_ticket'],'Ursprüngliches Transferticket',256)
        self.tax_number = text(self.company.get('tax_number'), 'Steuernummer', 13)
        if not re.fullmatch(r'[0-9]{13}', self.tax_number) or not re.fullmatch(r'[0-9]{5}', manufacturer):
            raise ValueError('Steuernummer oder Herstellerkennung ungültig.')
        root = ET.Element('{'+NS['e']+'}Elster')
        header = node(root, 'e', 'TransferHeader', version='11')
        for key, value in [('Verfahren','ElsterBilanz'),('DatenArt','Bilanz'),('Vorgang','send-Auth'),('Testmerker','700000004'),('HerstellerID',manufacturer),('DatenLieferant',text(profile.get('supplier'), 'Datenlieferant'))]:
            if key == 'Testmerker' and self.environment == 'production':
                continue
            node(header, 'e', key, value)
        file = node(header, 'e', 'Datei')
        for key, value in [('Verschluesselung','CMSEncryptedData'),('Kompression','GZIP'),('TransportSchluessel',None)]:
            node(file, 'e', key, value)
        node(header, 'e', 'VersionClient', 'Vereinsmanager ebilanz-6.9-1')
        block = node(node(root,'e','DatenTeil'),'e','Nutzdatenblock')
        h = node(block,'e','NutzdatenHeader',version='11'); node(h,'e','NutzdatenTicket','1')
        node(h,'e','Empfaenger',self.tax_number[:4],id='F')
        producer = node(h,'e','Hersteller'); node(producer,'e','ProduktName','Vereinsmanager'); node(producer,'e','ProduktVersion','ebilanz-6.9-1')
        node(h,'e','DatenLieferant',profile['supplier'])
        balance = node(node(block,'e','Nutzdaten'),'b','EBilanz',version='000002')
        node(balance,'b','stichtag',self.period['end'].replace('-',''))
        self.x = node(balance,'xbrli','xbrl')
        # Namespaces used in QName values must be explicitly retained by ElementTree.
        self.x.set('xmlns:iso4217', NS['iso4217'])
        for mod, suffix in [('de-gcd','shell'),('de-gaap-ci','shell-fiscal')]:
            node(self.x,'link','schemaRef',**{'{'+NS['xlink']+'}type':'simple','{'+NS['xlink']+'}href':f'http://www.xbrl.de/taxonomies/{mod}-2025-04-01/{mod}-2025-04-01-{suffix}.xsd'})
        self.context('D', False)
        if self.whole_club:self.context('I',True)
        unit = node(self.x,'xbrli','unit',id='EUR'); node(unit,'xbrli','measure','iso4217:EUR')
        self.gcd()
        for index, target in enumerate(sorted(selected)):
            duration = target.startswith(('is.','incomeUse.')) or target=='incomeUse.resultFoundations'
            section = 'IncomeStatement' if duration else 'balanceSheet'
            ctx = ('D' if duration else 'I')+str(index)
            if self.whole_club or target.startswith('incomeUse.') or target=='incomeUse.resultFoundations':
                ctx='D' if duration else 'I'
                concept=target
            else:
                self.context(ctx, not duration, target, section)
                concept = BASE+section+'.valueOfItem'
            self.fact(self.x,'de-gaap-ci',concept,facts.get(target),ctx,True)
            account_ctx = ctx
            if self.whole_club:account_ctx='D'
            elif not duration and accounts.get(target):
                account_ctx = 'A'+str(index)
                self.context(account_ctx,False,target,section)
            for key, account in accounts.get(target, []):
                tup = node(self.x,'de-gaap-ci','detailedInformation.accountBalances')
                # ELSTER's account-description character set excludes U+00B7.
                # Change only the display separator in the export, never the
                # retained account, its number, original review or source hash.
                description=account['name'].replace('·','-')
                for part, value in [('positionName',concept),('accountNumber',account.get('skr_code') or key),('accountDescription',description)]:
                    self.fact(tup,'de-gaap-ci','detailedInformation.accountbalances.'+part,value,account_ctx)
                self.fact(tup,'de-gaap-ci','detailedInformation.accountbalances.amount',fact_amount(target,account['amount_cents'],account['group']),account_ctx,True)
        if self.whole_club and not book_only:
            for concept,value in bvv(values).items():self.fact(self.x,'de-gaap-ci',concept,value,'D',True)
        asset_fact_count = 0
        for index,(target,measures) in enumerate(sorted(assets['rows'].items())):
            ctx='FA'+str(index)
            context=self.context(ctx,False)
            scenario=node(context,'xbrli','scenario')
            node(scenario,'xbrldi','explicitMember','de-gaap-ci:'+target,dimension='de-gaap-ci:dim_changes.nt.ass.gross')
            node(scenario,'xbrldi','explicitMember','de-gaap-ci:dim_taxBal',dimension='de-gaap-ci:dim_taxTrans')
            for concept,amount in sorted(measures.items()):
                if not self.permitted(concept,year):
                    if amount: raise ValueError('Anlagenspiegel: Unzulässige Wertentwicklung: '+concept)
                    continue
                self.fact(self.x,'de-gaap-ci',concept,amount,ctx,True); asset_fact_count+=1
        release_meta = {}
        if self.environment == 'production':
            from ebilanz_release import digest
            release_meta = {'release_id': self.release_package['id'],
                            'release_sha256': digest(self.release_package)}
        return root,balance,{'profile': profile['kind'], 'revision_status': self.revision_status,
                'taxonomy': '6.9', 'taxonomy_sha256': hashlib.sha256(self.taxonomy_bytes).hexdigest(),
                'tax_values_sha256': values['sha256'], 'journal_sha256': values['journal_sha256'],
                'fact_count': len(selected), 'asset_fact_count': asset_fact_count,
                'asset_items': assets['items'], 'asset_schedule_sha256': assets['sha256'],
                'transmission': 'unavailable', 'environment': self.environment} | ({'period':self.period,'previous_period':self.previous_period} if 'period' in values else {}) | release_meta

    def _serialize(self,root,balance,meta):
        # ELSTER rejects namespace declarations hoisted onto the outer envelope.
        # Serialize with scoped declarations while keeping ElementTree's escaping.
        namespaces = {uri: prefix for prefix, uri in NS.items()}
        def scoped_name(name):
            if not name.startswith('{'):
                return name
            uri, local = name[1:].split('}',1)
            prefix = namespaces[uri]
            return local if prefix == 'e' else prefix+':'+local
        for element in root.iter():
            element.tag = scoped_name(element.tag)
            element.attrib = {scoped_name(k): v for k,v in element.attrib.items()}
        root.set('xmlns',NS['e']); balance.set('xmlns:b',NS['b'])
        for prefix, uri in NS.items():
            if prefix not in ('e','b'):
                self.x.set('xmlns:'+prefix,uri)
        xml = ET.tostring(root, encoding='utf-8', xml_declaration=True).decode('utf-8')
        return {'xml': xml, 'xml_sha256': hashlib.sha256(xml.encode()).hexdigest()} | meta

    def context(self, identifier, instant, target=None, section=None):
        ctx = node(self.x,'xbrli','context',id=identifier)
        entity = node(ctx,'xbrli','entity'); node(entity,'xbrli','identifier',self.tax_number,scheme='http://www.rzf-nrw.de/Steuernummer')
        period = node(ctx,'xbrli','period')
        if instant:
            node(period,'xbrli','instant',self.period['end'])
        else:
            node(period,'xbrli','startDate',self.period['start']); node(period,'xbrli','endDate',self.period['end'])
        if target:
            scenario = node(ctx,'xbrli','scenario')
            node(scenario,'xbrldi','explicitMember','de-gaap-ci:'+target,dimension='de-gaap-ci:'+BASE+section+'.table.item')
            member = node(scenario,'xbrldi','typedMember',dimension='de-gaap-ci:dim_BgAKey')
            node(member,'de-gaap-ci','dim_keyType',self.business)
        return ctx

    def fact(self, parent, module, concept, value=None, context='D', monetary=False):
        attrs = {'contextRef':context}
        if monetary:
            attrs['unitRef']='EUR'
        if value is None:
            attrs['{'+NS['xsi']+'}nil']='true'
        elif monetary:
            attrs['decimals']='2'; value=money(value)
        return node(parent,module,concept,value,**attrs)

    def gcd(self):
        def f(concept, value=None, parent=None):
            return self.fact(self.x if parent is None else parent,'de-gcd',concept,value)
        def choice(concept, suffix):
            p=node(self.x,'de-gcd',concept); f(concept+'.'+suffix,'',p)
        for concept, suffix in [('genInfo.report.id.reportStatus','reportStatus.E'),('genInfo.report.id.revisionStatus','revisionStatus.'+self.revision_status),
                                ('genInfo.report.id.statementType','statementType.E'),('genInfo.report.id.accountingStandard','accountingStandard.AO'),
                                ('genInfo.report.id.specialAccountingStandard','K'),
                                ('genInfo.report.id.incomeStatementFormat','incomeStatementFormat.GKV'),('genInfo.report.id.consolidationRange','consolidationRange.EA'),
                                ('genInfo.company.id.incomeClassification','trade'),('genInfo.company.id.legalStatus','legalStatus.'+self.legal_status)]:
            choice(concept,suffix)
        if not self.whole_club:
            choice('genInfo.company.id.entityWithTaxablePurposeBusiness','taxPrivileged' if self.tax_privileged else 'normal')
            choice('genInfo.company.id.transmissionOptionofCorpWithSpecialCaseProfitDetermination','taxablePurposeBusinessTable')
        else:
            p=node(self.x,'de-gcd','genInfo.company.id.entityWithTaxablePurposeBusiness')
            f('genInfo.company.id.entityWithTaxablePurposeBusiness.merchantVesselsInInternationalTraffic',None,p)
        f('genInfo.report.id.incomeStatementendswithBalProfit','true' if self.ends_with_balance_profit else 'false')
        p=node(self.x,'de-gcd','genInfo.report.id.reportElement')
        for part in ['B','BAL','BVV','EB','EV','GuV','KS','SGEB','STU']:
            enabled=(('B','BVV','GuV','KS') if self.whole_club else ('KS','SGEB'))+(('EV',) if self.has_income_use else ())
            f('genInfo.report.id.reportElement.reportElements.'+part,'' if part in enabled or (part=='BAL' and self.has_assets) else None,p)
        f('genInfo.report.id.reportElement.reportElements.transmissionNotYetPossible',None)
        prior = self.previous_period or {'start':None,'end':None}
        for key, value in [('fiscalYearBegin',self.period['start']),('fiscalYearEnd',self.period['end']),('balSheetClosingDate',self.period['end']),
                           ('fiscalPreviousYearBegin',prior['start']),('fiscalPreviousYearEnd',prior['end']),('balSheetClosingDatePreviousYear',prior['end'])]:
            f('genInfo.report.period.'+key,value)
        for key, name in [('name','name'),('location.street','street'),('location.houseNo','house_number'),('location.zipCode','postal_code'),('location.city','city'),('location.country','country')]:
            f('genInfo.company.id.'+key,text(self.company.get(name),name))
        p=node(self.x,'de-gcd','genInfo.company.id.idNo')
        for key,value in [('ST13',self.tax_number),('STID',None),('STWID',None)]:
            f('genInfo.company.id.idNo.type.companyId.'+key,value,p)
        f('genInfo.company.id.business',self.business)
        p=node(self.x,'de-gcd','genInfo.company.id.domesticBranchOfForeignCompany')
        f('genInfo.company.id.domesticBranchOfForeignCompany.other',None,p)
        p=node(self.x,'de-gcd','genInfo.report.id.accordingTo')
        f('genInfo.report.id.accordingTo.name',None,p)
        s=node(p,'de-gcd','genInfo.report.id.accordingTo.idNo')
        for key in ['ST13','BF4']:
            f('genInfo.company.id.idNo.type.companyId.'+key,None,s)
        f('genInfo.report.id.accordingTo.yearEnd',None,p)
        p=node(self.x,'de-gcd','genInfo.company.id.shareholder')
        for key in ['name','id','taxnumber','taxid','WID']:
            f('genInfo.company.id.shareholder.'+key,None,p)
        s=node(p,'de-gcd','genInfo.company.id.shareholder.legalStatus'); f('genInfo.company.id.shareholder.legalStatus.legalStatus.KOER',None,s)
        s=node(p,'de-gcd','genInfo.company.id.shareholder.ShareDivideKey')
        for key in ['numerator','denominator']:
            f('genInfo.company.id.shareholder.ShareDivideKey.'+key,None,s)


if __name__ == '__main__':
    # Developer/private process entry point, not an HTTP route. Always a test draft.
    import sys
    sys.stdout.reconfigure(encoding='utf-8')
    sys.stderr.reconfigure(encoding='utf-8')
    try:
        raw = sys.stdin.buffer.read(8*1024*1024+1)
        if len(raw) > 8*1024*1024:
            raise ValueError('E-Bilanz-Auftrag ist zu groß.')
        request = json.loads(raw)
        if not isinstance(request,dict) or set(request) != {'action','values','mapping','profile','manufacturer_id'} or request['action'] != 'generate_test':
            raise ValueError('Nur Erzeugung eines Testentwurfs ist erlaubt.')
        package = EbilanzBuilder().build(request['values'],request['mapping'],request['profile'],request['manufacturer_id'])
        print(json.dumps(package,ensure_ascii=False))
    except (ValueError,KeyError,TypeError) as error:
        print(json.dumps({'error':str(error),'transmission':'unavailable'},ensure_ascii=False))
        sys.exit(2)

"""Checked gross asset rollforward for a single, separately accounted business.

Only retained, invoice-linked linear/GWG/degressive plans with identical book/tax asset values.
No disposal, impairment, reclassification or tax adjustment is inferred from net
balances. ERiC skips some asset checks for SGEB, so these checks are mandatory.
"""
from datetime import date
import hashlib
import json
import re
from ebilanz_degressive import depreciation as degressive_depreciation
from ebilanz_sales import sale_values,GAIN_SALE,GAIN_BOOK,LOSS_SALE,LOSS_BOOK,fact_amount,check_movements
from ebilanz_whole_club import asset_scope, spheres
from ebilanz_asset_types import TYPES, ACCOUNTS, DISPOSABLE, accepts_target

LIMIT = 100000000000
FIELDS = ('cost_opening_cents','additions_cents','cost_closing_cents',
          'accumulated_opening_cents','depreciation_cents','accumulated_closing_cents',
          'book_opening_cents','book_closing_cents')
DISPOSAL_FIELDS = ('disposals_cents','accumulated_disposals_cents','book_disposals_cents')
MEASURES = dict(zip(FIELDS, ('grossCost.beginning','gross.addition','gross',
                           'accDepr.beginning','accDepr.DeprPeriod.regular','accDepr',
                           'all_Prev_period','all')))
GOODWILL = 'is.netIncome.regular.operatingTC.deprAmort.fixAss.goodwill'
BUILDING = 'is.netIncome.regular.operatingTC.deprAmort.fixAss.tan.buildings'
TANGIBLE = 'is.netIncome.regular.operatingTC.deprAmort.fixAss.tan.other'
INTANGIBLE = 'is.netIncome.regular.operatingTC.deprAmort.fixAss.otherIntan'
GWG = 'is.netIncome.regular.operatingTC.deprAmort.fixAss.tan.lowValueAs'
SCRAPPING = 'is.netIncome.regular.operatingTC.otherCost.disposFixAss.bookValue.tan.other'
SOFTWARE_END = 'is.netIncome.regular.operatingTC.otherCost.disposFixAss.bookValue.intan'
ASSETS = {target:definition['expense'] for target,definition in TYPES.items() if definition['expense'] is not None}


def integer(value):
    if type(value) is not int or value < 0 or value > LIMIT:
        raise ValueError('Anlagenspiegel: Betrag muss als nichtnegative ganze Centzahl vorliegen.')
    return value


def prepare_assets(values, mapping, profile, meta):
    schedule=values.get('asset_schedule')
    items=schedule.get('items') if isinstance(schedule,dict) else None
    evidence=values.get('asset_tax')
    proofs=evidence.get('items') if isinstance(evidence,dict) else None
    if isinstance(items,dict) and isinstance(proofs,dict) and any(
        isinstance(item,dict) and item.get('depreciation_code')=='62500' and identifier in proofs
        for identifier,item in items.items()
    ):
        raise ValueError('Kaufleasing: abweichende steuerliche Nutzungsdauer gesondert prüfen. Hier sind nur gleiche Buch- und Steuer-Anlagenwerte qualifiziert.')
    if any(isinstance(i,dict) and i.get('method')=='substance_original' for i in (items or {}).values()):
        from ebilanz_substance_original import prepare
        return prepare(values,mapping,profile,meta)
    if any(isinstance(i,dict) and i.get('method')=='exceptional_original' for i in (items or {}).values()):
        from ebilanz_exceptional_original import prepare
        return prepare(values,mapping,profile,meta)
    if 'basis_reduction_6b' in values:
        from ebilanz_basis_reduction_6b import prepare as reduction_prepare
        return reduction_prepare(values,mapping,profile,meta,_prepare_assets)
    if 'basis_reduction_7g' in values:
        from ebilanz_basis_reduction_7g import prepare as reduction_prepare
        return reduction_prepare(values,mapping,profile,meta,_prepare_assets)
    if 'special_tax_7b' in values:
        from ebilanz_special_tax_7b import prepare as special_prepare
        return special_prepare(values,mapping,profile,meta,_prepare_assets)
    if 'special_tax_7g' in values:
        from ebilanz_special_tax_7g import prepare as special_prepare
        return special_prepare(values,mapping,profile,meta,_prepare_assets)
    if 'pool_original_sources' in values:
        from ebilanz_pool_original_values import prepare
        return prepare(values,mapping,profile,meta)
    if 'own_hgb_sources' in values:
        from ebilanz_own_intangible_books import prepare
        return prepare(values,mapping,profile,meta)
    if 'own_intangible_sources' in values:
        from ebilanz_own_intangible_values import prepare
        return prepare(values,mapping,profile,meta,prepare_assets)
    if 'partnership_holdings' in values:
        from ebilanz_partnership_holdings import prepare
        return prepare(values,mapping,profile,meta,prepare_assets)
    if 'period' in values:
        from ebilanz_period_assets import prepare
        return prepare(values,mapping,profile,meta)
    if 'asset_tax' in values:
        from ebilanz_tax_assets import prepare_tax_assets
        return prepare_tax_assets(values,mapping,profile,meta,_prepare_assets)
    return _prepare_assets(values,mapping,profile,meta)


def _prepare_assets(values, mapping, profile, meta, tax_derived=False, book_derived=False):
    tax, book = values['tax']['accounts'], values['book']['accounts']
    fixed = {k:a for k,a in tax.items() if a['group']=='fixed_assets'}
    schedule = values.get('asset_schedule')
    active = any(any(a[k] for k in ('opening_cents','debit_cents','credit_cents')) for a in fixed.values())
    if schedule is None:
        if active:
            raise ValueError('Anlagespiegel: Festgeschriebene Einzelgegenstände fehlen.')
        if profile.get('no_retained_fixed_assets') is not True:
            raise ValueError('Anlagenspiegel: Auch vollständig abgeschriebene Gegenstände müssen nachgewiesen werden.')
        schedule = {'year':values['year'],'items':{},'totals':dict.fromkeys(FIELDS,0),'issues':[]}
    if not isinstance(schedule,dict) or schedule.get('year')!=values['year'] or schedule.get('issues'):
        raise ValueError('Anlagenspiegel: Jahr oder offene Prüfpunkte klären.')
    items = schedule.get('items')
    if items == []: items = {}  # PHP encodes an empty keyed collection as [].
    if not isinstance(items,dict) or len(items)>10000:
        raise ValueError('Anlagenspiegel: Einzelgegenstände fehlen oder sind zu umfangreich.')
    if items and (profile.get('asset_scope')!=asset_scope(profile) or profile.get('asset_values_unchanged') is not True):
        raise ValueError('Anlagenspiegel: Geschäftszugehörigkeit und unveränderte steuerliche Anlagenwerte bestätigen.')
    if any(isinstance(item,dict) and item.get('method')=='cost' for item in items.values()):
        from ebilanz_cost_assets import prepare
        return prepare(values,mapping,profile,meta,_prepare_assets,tax_derived)
    for entry in values['opening_adjustments']+values['current_adjustments']:
        if any(line['account'] in fixed or mapping.get(line['account']) in (TANGIBLE,INTANGIBLE,BUILDING,GOODWILL,GWG,SCRAPPING,SOFTWARE_END,GAIN_SALE,GAIN_BOOK,LOSS_SALE) for line in entry['lines']):
            raise ValueError('Anlagenspiegel: Abweichende Steuerwerte oder Abschreibungen benötigen einen eigenen Nachweis.')
    fields=FIELDS+DISPOSAL_FIELDS if any(isinstance(item,dict) and 'disposal' in item for item in items.values()) else FIELDS
    by_account = {}; totals = dict.fromkeys(fields,0); rows = {}; dep = {TANGIBLE:0,INTANGIBLE:0,BUILDING:0,GOODWILL:0,GWG:0,SCRAPPING:0,SOFTWARE_END:0,GAIN_SALE:0,GAIN_BOOK:0,LOSS_SALE:0}
    from ebilanz_original_depreciation import code as original_depreciation_code,ordinary_gwg,check_annual
    sale_invoices=set()
    allowed = set(fields) | {'item_id','invoice_id','receipt_number','account','skr_code','sphere','acquired_on',
                            'months','residual_cents','expected_depreciation_cents','acquisition_hash','depreciation_hash','name','method','gwg','degressive',
                            'depreciation_code','original_leasing','basis_cents','disposal','disposal_hash','disposal_accounts','partial_plan'}
    for identifier, item in items.items():
        if not isinstance(item,dict) or set(item)-allowed or item.get('item_id')!=identifier:
            raise ValueError('Anlagenspiegel: Unbekannter Bewegungsfall oder ungültiger Gegenstand.')
        original_depreciation_code(item)
        if 'original_leasing' in item and ('period' in values or item['acquired_on'][:4]!=str(values['year']) or 'disposal' in item or 'club_scope' in values):raise ValueError('Kaufleasing: nur vollständigen anfänglichen Kalenderabschluss qualifiziert; Teilzuordnung, Abgang und Vortrag gesondert prüfen.')
        account = item.get('account'); target = mapping.get(account)
        expense=ACCOUNTS.get(item.get('skr_code'),{}).get('expense')
        if account not in fixed or target not in ASSETS:
            raise ValueError('Anlagenspiegel: Anlagenkonto oder geprüfte Zuordnung fehlt.')
        if item.get('sphere') not in spheres(profile) or item.get('skr_code')!=tax[account].get('skr_code'):
            raise ValueError('Anlagenspiegel: Vereinsbereich oder SKR42-Konto stimmt nicht überein.')
        if not accepts_target(item['skr_code'],target):
            raise ValueError('Anlagenspiegel: Diese Anlagenart benötigt einen eigenen geprüften Bewertungsweg.')
        for key in ('acquisition_hash','depreciation_hash'):
            value = item.get(key)
            if key=='depreciation_hash' and (item.get('depreciation_cents')==0 or tax_derived) and value is None: continue
            if not isinstance(value,str) or not re.fullmatch('[a-f0-9]{64}',value):
                raise ValueError('Anlagenspiegel: Unveränderter Herkunftsnachweis fehlt.')
        amounts = {k:integer(item.get(k)) for k in fields}
        disposal=item.get('disposal');disposed=None;through_month=12
        partial=isinstance(disposal,dict) and disposal.get('kind')=='partial_scrap'
        software_end=isinstance(disposal,dict) and disposal.get('kind')=='software_end'
        if 'partial_plan' in item and not partial:raise ValueError('Teilabgang: Unvollständiger Nachweis.')
        cost = integer(item.get('basis_cents')) if disposal is not None else amounts['cost_closing_cents']
        if disposal is None and any(k in item for k in ('basis_cents','disposal_hash','disposal','disposal_accounts')):raise ValueError('Anlagenspiegel: Unvollständiger Anlagenabgang.')
        residual=integer(item.get('residual_cents')); months=item.get('months')
        gwg=item.get('method')=='gwg_immediate'
        if item.get('method','linear_monthly') not in ('linear_monthly','gwg_immediate','degressive'):
            raise ValueError('Anlagenspiegel: Unbekannte Abschreibungsmethode.')
        if not cost or residual>=cost or type(months) is not int or (not gwg and (not 12<=months<=1200 or months%12)):
            raise ValueError('Anlagenspiegel: Abschreibungsplan prüfen.')
        if item['skr_code']=='01500' and (residual!=0 or item.get('method','linear_monthly')!='linear_monthly' or not book_derived and values.get('annual_section')!='books' and months!=180):
            raise ValueError('Erworbener Geschäfts- oder Firmenwert: steuerlich 15 Jahre und kein Restwert; abweichende Buchwerte vollständig überleiten.')
        acquired = item.get('acquired_on')
        if not isinstance(acquired,str) or not re.fullmatch(r'\d{4}-\d{2}-\d{2}',acquired):
            raise ValueError('Anlagenspiegel: Anschaffungsdatum fehlt.')
        acquired = date.fromisoformat(acquired); year=values['year']
        if not 1900<=acquired.year<=year:
            raise ValueError('Anlagenspiegel: Anschaffung liegt außerhalb des Berichtszeitraums.')
        if disposal is not None:
            if (not isinstance(disposal,dict) or not {'kind','date','reason','document_id','revision','sha256','complete'}<=set(disposal)
                or disposal['kind'] not in ('scrap','sale','partial_scrap','software_end') or (disposal['complete'] is not False if partial else disposal['complete'] is not True)
                or not isinstance(disposal['reason'],str) or not disposal['reason'].strip() or len(disposal['reason'])>2000
                or not isinstance(disposal['date'],str) or not re.fullmatch(r'\d{4}-\d{2}-\d{2}',disposal['date'])
                or not isinstance(disposal['document_id'],str) or not re.fullmatch('[a-f0-9]{32}',disposal['document_id'])
                or type(disposal['revision']) is not int or disposal['revision']<1
                or not isinstance(disposal['sha256'],str) or not re.fullmatch('[a-f0-9]{64}',disposal['sha256'])
                or not isinstance(item.get('disposal_hash'),str) or not re.fullmatch('[a-f0-9]{64}',item['disposal_hash'])
                or item['skr_code'] not in (('01350',) if software_end else DISPOSABLE)):
                raise ValueError('Anlagenspiegel: Vollständigen Ausmusterungsnachweis ohne Erlös prüfen.')
            if software_end:
                if (set(disposal)!={'kind','date','reason','document_id','revision','sha256','no_proceeds','complete','rights_ended'}
                    or disposal['no_proceeds'] is not True or disposal['rights_ended'] is not True
                    or item.get('method','linear_monthly')!='linear_monthly'):
                    raise ValueError('Anlagenspiegel: Ende aller Softwarelizenzrechte ohne Erlös vollständig nachweisen.')
            elif disposal['kind']=='scrap':
                if set(disposal)!={'kind','date','reason','document_id','revision','sha256','no_proceeds','complete'} or disposal['no_proceeds'] is not True:
                    raise ValueError('Anlagenspiegel: Ausmusterung ohne Erlös nachweisen.')
            elif not partial:
                sale_values(disposal,amounts['book_disposals_cents'])
                if disposal['sale_entry'] in sale_invoices:raise ValueError('Anlagenverkauf: Verkaufsrechnung mehrfach zugeordnet.')
                sale_invoices.add(disposal['sale_entry'])
            disposed=date.fromisoformat(disposal['date'])
            if disposed<acquired or disposed.year>year:raise ValueError('Anlagenspiegel: Ausmusterungsdatum prüfen.')
            through_month=disposed.month if disposed.year==year else 12
        if item.get('method')=='degressive':
            if 'gwg' in item or expense!=TANGIBLE:
                raise ValueError('Anlagenspiegel: Degressive Methode und Anlagenkonto widersprechen sich.')
            opening_dep,closing_dep=degressive_depreciation(cost,acquired,months,item.get('degressive'),year,item['skr_code'],residual,through_month)
        elif gwg:
            if 'degressive' in item:raise ValueError('Anlagenspiegel: Widersprüchliche Abschreibungsnachweise.')
            proof=item.get('gwg')
            if not isinstance(proof,dict) or set(proof)!={'net_cents','vat_cents','deductible_cents','independent_movable','no_pool'}:
                raise ValueError('Anlagenspiegel: GWG-Nachweis fehlt.')
            net,vat,deductible=(integer(proof[k]) for k in ('net_cents','vat_cents','deductible_cents'))
            if not 0<net<=80000 or vat>(net*19+50)//100 or deductible>vat or cost!=net+vat-deductible or acquired.year not in (2025,2026) or proof['independent_movable'] is not True or proof['no_pool'] is not True or months!=1 or residual!=0 or item['skr_code']!='06700' or expense!=GWG:
                raise ValueError('Anlagenspiegel: GWG-Grenze, Kosten, Nutzbarkeit oder Jahreswahlrecht prüfen.')
            opening_dep=cost if acquired.year<year else 0
            closing_dep=cost
        else:
            if 'gwg' in item or 'degressive' in item or (expense==GWG and not ordinary_gwg(item)):
                raise ValueError('Anlagenspiegel: GWG-Methode und Anlagenkonto widersprechen sich.')
            before=max(0,min(months,(year-acquired.year)*12-(acquired.month-1)))
            through=max(0,min(months,(year-acquired.year)*12+through_month-(acquired.month-1)))
            opening_dep=((cost-residual)*before+months//2)//months
            closing_dep=((cost-residual)*through+months//2)//months
        expected=dict(zip(FIELDS,(cost if acquired.year<year else 0, cost if acquired.year==year else 0, cost,
                                 opening_dep,closing_dep-opening_dep,closing_dep,
                                 cost-opening_dep if acquired.year<year else 0,cost-closing_dep)))
        if fields!=FIELDS:expected.update(dict.fromkeys(DISPOSAL_FIELDS,0))
        if disposed:
            if disposed.year<year:expected=dict.fromkeys(fields,0)
            else:expected.update(cost_closing_cents=0,accumulated_closing_cents=0,book_closing_cents=0,
                                 disposals_cents=cost,accumulated_disposals_cents=closing_dep,book_disposals_cents=cost-closing_dep)
        if partial:
            from ebilanz_partial_assets import item_values
            expected=item_values(item,year,tax_derived)
        if amounts!=expected or integer(item.get('expected_depreciation_cents'))!=expected['depreciation_cents']:
            raise ValueError('Anlagenspiegel: Anschaffung, Abschreibung und Restwert sind nicht abgestimmt.')
        by_account.setdefault(account,dict.fromkeys(fields,0)); rows.setdefault(target,dict.fromkeys(fields,0))
        for key,amount in amounts.items():
            for out in (by_account[account],rows[target],totals):out[key]=integer(out[key]+amount)
        dep[expense]=integer(dep[expense]+amounts['depreciation_cents'])
        if disposal and disposal['kind']=='sale' and disposed.year==year:
            sale=sale_values(disposal,expected['book_disposals_cents'])
            revenue,carrying=(GAIN_SALE,GAIN_BOOK) if sale['gain'] else (LOSS_SALE,LOSS_BOOK)
            dep[revenue]=integer(dep[revenue]+sale['net']);dep[carrying]=integer(dep[carrying]+sale['book'])
        else:
            loss_fact=SOFTWARE_END if software_end else SCRAPPING
            dep[loss_fact]=integer(dep[loss_fact]+amounts.get('book_disposals_cents',0))
    if schedule.get('totals')!=totals:
        raise ValueError('Anlagenspiegel: Gesamtsummen stimmen nicht mit den Einzelgegenständen überein.')
    check_movements(items,tax,values['year'])
    check_annual(items,tax)
    for account, a in fixed.items():
        sums=by_account.get(account,dict.fromkeys(fields,0))
        for ledger in (book,tax):
            for key,field in [('opening_cents','book_opening_cents'),('amount_cents','book_closing_cents'),
                              ('debit_cents','additions_cents'),('credit_cents','depreciation_cents')]:
                expected=sums[field]+(sums.get('book_disposals_cents',0) if key=='credit_cents' else 0)
                if ledger[account][key]!=expected:
                    raise ValueError('Anlagenspiegel: Anlagenbewegungen und Bilanzjournal stimmen nicht überein.')
    for concept,amount in dep.items():
        actual=sum(fact_amount(concept,a['amount_cents']) for key,a in tax.items() if mapping.get(key)==concept)
        if actual!=amount:
            raise ValueError('Anlagenspiegel: Abschreibung stimmt nicht mit der Gewinnermittlung überein.')
    # Follow the official row hierarchy, each ancestor only once per leaf.
    leaves=list(rows.items())
    for leaf,amounts in leaves:
        ancestors=set(); pending=list(meta['asset_parents'].get(leaf,[]))
        while pending:
            parent=pending.pop()
            if parent==leaf: raise ValueError('Anlagenspiegel: Zyklus in der Taxonomie.')
            if parent in ancestors: continue
            ancestors.add(parent); pending.extend(meta['asset_parents'].get(parent,[]))
        if 'bs.ass.fixAss' not in ancestors:
            raise ValueError('Anlagenspiegel: Position fehlt in der amtlichen Tabellenhierarchie.')
        for parent in ancestors:
            if parent in dict(leaves): raise ValueError('Anlagenspiegel: Überlappende Zuordnung.')
            rows.setdefault(parent,dict.fromkeys(fields,0))
            for key,amount in amounts.items():rows[parent][key]=integer(rows[parent][key]+amount)
    columns=set(meta['asset_calculations']) | {c for children in meta['asset_calculations'].values() for c in children}
    columns.add('all_Prev_period')
    result={}
    for target,amounts in rows.items():
        # All movements other than acquisition and supported ordinary depreciation
        # are explicitly excluded above, not inferred as zero from a net balance.
        measures=dict.fromkeys(columns,0)
        measures.update({MEASURES[k]:v for k,v in amounts.items() if k in MEASURES})
        measures['gross.release']=amounts.get('disposals_cents',0)
        measures['accDepr.release']=amounts.get('accumulated_disposals_cents',0)
        measures['accDepr.DeprPeriod']=amounts['depreciation_cents']
        for parent,children in meta['asset_calculations'].items():
            if measures[parent]!=sum(measures[c]*weight for c,weight in children.items()):
                raise ValueError('Anlagenspiegel: Amtliche Bewegungsgleichung nicht erfüllt: '+parent)
        result[target]=measures
    return {'rows':result,'items':len(items),'totals':totals,
            'sha256':hashlib.sha256(json.dumps(schedule,ensure_ascii=False,separators=(',',':')).encode()).hexdigest()}

"""Original whole B/GuV plus SGEB subsidiary computation (official FAQ 4 m).

Independently replay journal lines, valuation, off-ledger amounts and carry.
Allocation/clearing authorization is additionally replayed by the PHP service.
No taxable partial balance, artificial accounts, external XML or transport.
"""
import re
import xml.etree.ElementTree as ET
from ebilanz_club_scope import require, amount, digest, mapping, calculate
from ebilanz_whole_club import validate_tax

KIND = 'tax_privileged_club_with_subsidiary'
RESULT = 'DeterminationOfTaxableIncomeSpec.forProfitOrganization.taxableIncome'
EXPLANATION = 'DeterminationOfTaxableIncomeSpec.forProfitOrganization.determinationOfTaxableIncome'


def sealed(v, transport=False):
    require(isinstance(v, dict))
    omitted = ('sha256', 'transmission') if transport else ('sha256',)
    require(v.get('sha256') == digest({k: n for k, n in v.items() if k not in omitted}))
    if transport:
        require(v.get('transmission') == 'unavailable')


def wording(s, limit=1500):
    require(isinstance(s, str) and s.strip() and len(s) <= limit and not re.search(r'[\x00-\x1f]', s))
    return s


def period_packet(v, depth=0):
    """Internal replay evidence only; no public export profile admits this section."""
    require(depth<=100)
    if 'period' not in v:
        from ebilanz_calendar_source import packet
        return packet(v)
    require(v.get('schema')==2)
    source=v['income']['scope']['source']
    fields=('year','state','closed_sha256','previous_sha256','result_account','issues','accounts','opening','entries','calculation','asset_schedule','period')
    review_fields=('year','journal_sha256','complete','opening_adjustments','current_adjustments','asset_tax_plans','period')
    sealed(v['valuation'],True)
    value={k:x for k,x in v['valuation'].items() if k not in ('sha256','transmission')}
    value['period_section']='subsidiary'
    value['period_source']={'source':{k:source[k] for k in fields},'review':{k:v['review'][k] for k in review_fields},
                            'previous':period_packet(v['previous'],depth+1) if v['previous'] else None}
    return value|{'sha256':digest(value),'transmission':'unavailable'}


def verify(v, asset_mapping, whole_profile, meta, depth=0):
    """Recompute every number emitted in the subsidiary section from real lines."""
    require(depth <= 100)
    sealed(v)
    dated='period' in v
    require(set(v) == {'schema','kind','year','journal_sha256','income','review','previous','valuation','accounts','off_balance','result','tax_export_ready','sha256'}|({'period'} if dated else set())|({'sphere_reconciliation'} if 'sphere_reconciliation' in v else set()))
    require(type(v['schema']) is int and v['schema'] == (2 if dated else 1) and v['kind'] == 'club_tax_reconciliation' and v['tax_export_ready'] is False)
    income, review, values = v['income'], v['review'], v['valuation']
    sealed(income); sealed(values, True)
    source = income['scope']['source']; chart = source['accounts']; year = source['year']
    require(set(review) == {'year','journal_sha256','income_sha256','complete','opening_adjustments','current_adjustments','asset_tax_plans','off_balance_adjustments'}|({'period'} if dated else set()))
    require(review['complete'] is True and review['year'] == v['year'] == values['year'] == income['year'] == year
            and review['journal_sha256'] == v['journal_sha256'] == values['journal_sha256'] == source['closed_sha256']
            and review['income_sha256'] == income['sha256'] and source['state'] == 'closed' and source['issues'] == [])
    period=v['period'] if dated else None
    if dated:require(period==source.get('period')==income.get('period')==review.get('period')==values.get('period'))
    else:require('period' not in source and 'period' not in values)
    book = calculate(year, chart, source['opening'], source['entries'],period)
    require(book == source['calculation'] == values['book'] and source['asset_schedule'] == values['asset_schedule'])
    if dated:
        from ebilanz_period import verify as verify_period
        packet=period_packet(v);verify_period(packet,section='subsidiary')
        chart={k:{f:a[f] for f in ('name','group','skr_code')} for k,a in values['accounts'].items()}
    else:validate_tax(values, source)
    tax = values['tax']
    for sphere in ('1','2','3'):
        require(tax['spheres'][sphere] == book['spheres'][sphere])
    for entry in values['current_adjustments']:
        for line in entry['lines']:
            require(chart[line['account']]['group'] not in ('expense','revenue') or line['sphere'] == '4')
    plans = mapping(review['asset_tax_plans'])
    generated = {'opening_adjustments': [], 'current_adjustments': []}
    if dated:
        from ebilanz_period_assets import prepare
        prepare(packet,asset_mapping,whole_profile|{'asset_values_unchanged':not bool(plans),'asset_tax_values_reviewed':bool(plans)},meta)
        generated['current_adjustments']=values['asset_tax']['generated_current']
    elif plans:
        evidence = values.get('asset_tax'); require(isinstance(evidence, dict))
        require({k: x['plan'] for k, x in evidence['items'].items()} == plans)
        for key in plans:
            require(values['asset_schedule']['items'][key]['sphere'] == '4')
        # Real whole ledger counterpostings are ONLY arithmetic evidence here.
        # Its tax balance is NOT rendered or classified as a taxable whole club.
        from ebilanz_assets import prepare_assets
        p = whole_profile | {'asset_values_unchanged': False, 'asset_tax_values_reviewed': True}
        prepare_assets(values, asset_mapping, p, meta)
        generated = {'opening_adjustments': evidence['generated_opening'], 'current_adjustments': evidence['generated_current']}
    else:
        require('asset_tax' not in values)
    managed = {k for k, a in chart.items() if a['group'] == 'fixed_assets' or a['skr_code'] in ('62000','62200','62600','48450','48490','48550','68850','68890','68950','68960','48440','48480','68840','68880')}
    for entry in source['entries']:
        if any(chart[l['account']]['group'] == 'fixed_assets' for l in entry['lines']):
            managed.update(l['account'] for l in entry['lines'] if chart[l['account']]['group'] in ('expense','revenue'))
    for period in generated:
        require(isinstance(review[period], list) and values[period] == review[period] + generated[period])
        for entry in review[period]:
            for line in entry['lines']:
                require(line['account'] not in managed or (period == 'opening_adjustments' and v['previous'] is not None))

    # ALL taxable original lines, irrespective of the unit funding the payment.
    nets = {k: 0 for k, a in chart.items() if a['group'] in ('expense','revenue')}
    for entry in source['entries']:
        for line in entry['lines']:
            if line['sphere'] == '4':
                sign = 1 if chart[line['account']]['group'] == 'expense' else -1
                nets[line['account']] = amount(nets[line['account']] + sign*(line['debit_cents']-line['credit_cents']))
    rows = {}
    for key, n in nets.items():
        diff = tax['accounts'][key]['amount_cents'] - book['accounts'].get(key,{}).get('amount_cents',0)
        rows[key] = chart[key] | {'book_cents': n, 'valuation_difference_cents': diff, 'tax_cents': amount(n+diff)}
    require(rows == v['accounts'])
    sources = {}
    for kind, vouchers in [('journal', source['entries']), ('tax', values['current_adjustments'])]:
        for entry in vouchers:
            for index, line in enumerate(entry['lines']):
                group = chart[line['account']]['group']
                if group in ('expense','revenue') and line['sphere'] == '4':
                    sources[f"{kind}:{entry['id']}:{index}"] = {'account': line['account'], 'group': group, 'cents': (1 if group == 'expense' else -1)*(line['debit_cents']-line['credit_cents'])}
    require(isinstance(review['off_balance_adjustments'], list) and len(review['off_balance_adjustments']) <= 200)
    seen = set(); used = {}; entries = []; add = deduct = 0
    from ebilanz_period_spheres import expense_sources
    sphere_dep,asset_expenses=expense_sources(source,values)
    for entry in review['off_balance_adjustments']:
        require(isinstance(entry,dict) and isinstance(entry.get('sources'),dict))
        require(all(type(k) is str for k in entry['sources']))
        if any(k.startswith('asset-depreciation:') for k in entry['sources']):sources.update(asset_expenses)
    for entry in review['off_balance_adjustments']:
        require(set(entry) == {'id','kind','sources','reason','legal_basis','confirmed'} and entry['confirmed'] is True
                and isinstance(entry['id'], str) and re.fullmatch('[a-z0-9_-]{1,40}', entry['id']) and entry['id'] not in seen
                and entry['kind'] in ('non_deductible_expense','tax_exempt_income'))
        seen.add(entry['id']); wording(entry['reason']); wording(entry['legal_basis'])
        require(isinstance(entry['sources'], dict) and 1 <= len(entry['sources']) <= 1000)
        total = 0
        for key, n in entry['sources'].items():
            src = sources.get(key); amount(n)
            require(src is not None and n != 0 and (n > 0) == (src['cents'] > 0)
                    and src['group'] == ('expense' if entry['kind'] == 'non_deductible_expense' else 'revenue'))
            require(key in asset_expenses or chart[src['account']]['skr_code'] not in sphere_dep)
            used[key] = amount(used.get(key, 0)+n); require(abs(used[key]) <= abs(src['cents']))
            total = amount(total+n)
        entries.append(entry | {'cents': total})
        if entry['kind'] == 'non_deductible_expense': add = amount(add+total)
        else: deduct = amount(deduct+total)
    require(v['off_balance'] == {'sources': sources or [], 'entries': entries, 'additions_cents': add, 'deductions_cents': deduct})
    from ebilanz_period_spheres import bridge
    sphere_bridge=bridge(source) if dated else None
    require(v.get('sphere_reconciliation')==sphere_bridge and ('sphere_reconciliation' in v)==(sphere_bridge is not None))
    commercial = book['spheres']['4']['result_cents']; tax_result = amount(tax['spheres']['4']['result_cents']+(sphere_bridge['valuation_difference_cents'] if sphere_bridge else 0))
    require(v['result'] == {'book_cents': commercial, 'valuation_difference_cents': tax_result-commercial,
            'tax_book_cents': tax_result, 'additions_cents': add, 'deductions_cents': deduct, 'taxable_income_cents': amount(tax_result+add-deduct)})
    previous = v['previous']
    if source['previous_sha256'] is None:
        require(previous is None and income['scope']['previous'] is None)
    else:
        require(isinstance(previous, dict))
        verify(previous, asset_mapping, whole_profile, meta, depth+1)
        prior_source = previous['income']['scope']['source']; prior = previous['valuation']
        require(income['scope']['previous'] == previous['income']['scope'] and prior_source['closed_sha256'] == source['previous_sha256']
                and (dated or prior_source['year']+1 == year) and source['result_account'] == prior_source['result_account'])
        # The dated replay already checked actual adjacent dates, every original
        # carry, the raw valuation hash and unchanged item-specific tax lives.
        if dated:return
        for key, a in prior_source['accounts'].items():
            require(key in chart and all(chart[key][f] == a[f] for f in ('group','skr_code')))
        carried = {}; difference = {}
        for key, a in prior['book']['accounts'].items():
            if a['group'] in ('expense','revenue'): continue
            n = a['closing_cents']; d = prior['tax']['accounts'][key]['closing_cents']-n
            if key == source['result_account']:
                n -= prior['book']['annual_result_cents']; d -= prior['result_difference_cents']
            if n: carried[key] = n
            if d: difference[key] = d
        require(carried == {l['account']: l['debit_cents']-l['credit_cents'] for l in source['opening']})
        expected = []
        if difference:
            expected = [{'id': 'carry-'+prior['sha256'][:32], 'reason': 'Unveränderter steuerlicher Wertvortrag aus '+str(year-1)+'.',
                         'lines': [{'account': k, 'debit_cents': max(0,n), 'credit_cents': max(0,-n), 'sphere': ''} for k,n in sorted(difference.items())]}]
        require(review['opening_adjustments'] == expected)
        for key, plan in mapping(previous['review']['asset_tax_plans']).items():
            require(plans.get(key) == plan)


def build(builder, values, account_mapping, profile, manufacturer):
    from ebilanz_builder import NS, node
    from ebilanz_mapping import automatic_mapping
    sealed(values, True)
    dated='period' in values
    require(set(values) == {'schema','kind','year','journal_sha256','whole','subsidiary','sha256','transmission'}|({'period'} if dated else set())
            and type(values['schema']) is int and values['schema'] == (2 if dated else 1) and values['kind'] == KIND
            and profile.get('kind') == KIND and profile.get('source_scope') == 'complete_club_with_reviewed_subsidiary'
            and profile.get('tax_privileged') is True and profile.get('scope_confirmed') is True
            and profile.get('tax_values_sha256') == values['sha256'] and profile.get('whole_book_standard') == 'HGBM'
            and profile.get('asset_scope') == 'whole_and_subsidiary')
    w, s = values['whole'], values['subsidiary']
    source=s['income']['scope']['source']
    if dated:
        source={k:source[k] for k in w['whole_club_scope']['source']}
        require(values['period']==w.get('period')==s.get('period') and w.get('period_section')=='books')
    require(w['whole_club_scope']['source'] == source
            and w['year'] == s['year'] == values['year'] and w['journal_sha256'] == s['journal_sha256'] == values['journal_sha256']
            and w['opening_adjustments'] == w['current_adjustments'] == [] and (dated or 'asset_tax' not in w) and w['book'] == w['tax'])
    changed=(bool(mapping(s['review']['asset_tax_plans'])) or 'sphere_reconciliation' in s) if dated else 'asset_tax' in s['valuation']
    if changed:
        require(profile.get('asset_tax_values_reviewed') is True and profile.get('asset_values_unchanged') is False)
    else:
        require(profile.get('asset_values_unchanged') is True)
    wp = profile | {'kind': 'whole_taxable_club', 'source_scope': 'complete_club_ledger', 'tax_privileged': False,
                    'asset_scope': 'whole_club', 'tax_values_sha256': w['sha256'], 'business_name': profile.get('whole_business_name'),
                    'asset_values_unchanged': True, 'asset_tax_values_reviewed': False}
    # This checked internal section emits only original books; immediately restore
    # the true tax-privileged umbrella profile before serializing anything.
    root, balance, meta = builder._build_tree(w, account_mapping, wp, manufacturer, book_only=True)
    verify(s, automatic_mapping(w, account_mapping) | account_mapping, wp, builder.meta)
    x = builder.x
    q = lambda prefix, name: '{'+NS[prefix]+'}'+name
    def choice(name, child):
        current = x.find(q('de-gcd', name)); require(current is not None); current.clear()
        node(current, 'de-gcd', child, '', contextRef='D')
    choice('genInfo.company.id.entityWithTaxablePurposeBusiness','genInfo.company.id.entityWithTaxablePurposeBusiness.taxPrivileged')
    choice('genInfo.report.id.accountingStandard','genInfo.report.id.accountingStandard.accountingStandard.HGBM')
    option = node(x, 'de-gcd', 'genInfo.company.id.transmissionOptionofCorpWithSpecialCaseProfitDetermination')
    node(option, 'de-gcd', 'genInfo.company.id.transmissionOptionCorporateEntitySpecialCaseProfitDetermination.umbrellaBodyAdjustments', '', contextRef='D')
    report = x.find(q('de-gcd','genInfo.report.id.reportElement')); require(report is not None)
    for name in ('BVV','SGEB'):
        f = report.find(q('de-gcd','genInfo.report.id.reportElement.reportElements.'+name)); require(f is not None)
        if name == 'BVV': f.set(q('xsi','nil'),'true'); f.text = None
        else: f.attrib.pop(q('xsi','nil'), None); f.text = ''
    for ctx in x.findall(q('xbrli','context')):
        if ctx.get('id','').startswith('FA'):
            for dim in ctx.iter(q('xbrldi','explicitMember')):
                if dim.get('dimension') == 'de-gaap-ci:dim_taxTrans': dim.text = 'de-gaap-ci:dim_comBal'
    builder.fact(x, 'de-gaap-ci', RESULT, s['result']['taxable_income_cents'], 'D', True).set('id','subsidiary_result')
    builder.fact(x, 'de-gaap-ci', EXPLANATION, 'Gewinnermittlung für sämtliche steuerpflichtigen wirtschaftlichen Geschäftsbetriebe; Einzelberechnung siehe Fußnote.', 'D')
    footnote(x, s)
    meta |= {'profile': KIND, 'tax_values_sha256': values['sha256'], 'journal_sha256': values['journal_sha256'],
             'fact_count': meta['fact_count']+2, 'sections': {'whole': w['sha256'], 'subsidiary': s['sha256']},
             'subsidiary_result_cents': s['result']['taxable_income_cents']}
    return builder._serialize(root, balance, meta)


def footnote(x, s):
    from ebilanz_builder import NS, node, money
    attrs = lambda **kw: {'{'+NS['xlink']+'}'+k: v for k, v in kw.items()}
    link = node(x,'link','footnoteLink',**attrs(type='extended',role='http://www.xbrl.org/2003/role/link'))
    node(link,'link','loc',**attrs(type='locator',href='#subsidiary_result',label='result'))
    f = node(link,'link','footnote',**attrs(type='resource',label='computation',role='http://www.xbrl.org/2003/role/footnote'))
    f.set('xml:lang','de')
    html = ET.SubElement(f,'html',{'xmlns':'http://www.w3.org/1999/xhtml'})
    label=s['period']['start']+' bis '+s['period']['end'] if 'period' in s else str(s['year'])
    head = ET.SubElement(html,'head'); ET.SubElement(head,'title').text = 'Steuerliche Nebenrechnung '+label
    body = ET.SubElement(html,'body')
    ET.SubElement(body,'p').text = 'Zusammengefasste Gewinnermittlung sämtlicher steuerpflichtiger wirtschaftlicher Geschäftsbetriebe. Alle Beträge in EUR. Die Gesamtbilanz und Gesamt-GuV enthalten unveränderte Buchwerte. Interne Vorschüsse und Rückzahlungen sind erfolgsneutral.'
    def table(headers, rows):
        t = ET.SubElement(body,'table'); h = ET.SubElement(t,'tr')
        for title in headers: ET.SubElement(h,'th').text = title
        for row in rows:
            r = ET.SubElement(t,'tr')
            for cell in row: ET.SubElement(r,'td').text = str(cell)
    labels = [('book_cents','Buchführungsergebnis'),('valuation_difference_cents','Steuerliche Bewertungsunterschiede'),
              ('tax_book_cents','Ergebnis zu Steuerbuchwerten'),('additions_cents','Außerbilanzielle Hinzurechnungen'),
              ('deductions_cents','Außerbilanzielle Kürzungen'),('taxable_income_cents','Ergebnis nach den bestätigten Korrekturen')]
    table(['Berechnung','EUR'], [(label,money(s['result'][key])) for key,label in labels])
    table(['Konto','Bezeichnung','Art','Buchwert','Wertänderung','Steuerwert'],
          [(a['skr_code'] or key,a['name'].replace('\u00b7','-'),'Aufwand' if a['group']=='expense' else 'Ertrag',money(a['book_cents']),money(a['valuation_difference_cents']),money(a['tax_cents']))
           for key,a in s['accounts'].items() if any(a[k] for k in ('book_cents','valuation_difference_cents','tax_cents'))])
    if 'sphere_reconciliation' in s:
        ET.SubElement(body,'p').text = 'Anlagenwechsel zwischen steuerfreien Bereichen und wirtschaftlichen Geschäftsbetrieben. Die nachfolgende Überleitung ergänzt die Kontenrechnung; Einlagen und Entnahmen sind keine Betriebseinnahmen oder Betriebsausgaben.'
        for identifier,proof in s['sphere_reconciliation']['items'].items():
            item=s['valuation']['asset_schedule']['items'][identifier];b=proof['book_taxable'];t=proof['tax']
            ET.SubElement(body,'p').text = 'Anlage: '+item['name'].replace('\u00b7','-')
            table(['Steuerpflichtiger Bereich','Buchwerte EUR','Steuerwerte EUR'],[(label,money(b[key]),money(t[key])) for key,label in
                [('opening_cents','Anfangswert'),('additions_cents','Anschaffungen'),('contributions_cents','Einlagen'),('withdrawals_cents','Entnahmen'),('depreciation_cents','Abschreibung'),('closing_cents','Schlusswert')]])
            table(['Ergebnisüberleitung','EUR'],[('Bewertungsergebnis beim Austritt',money(t['valuation_result_cents'])),('Änderung des steuerpflichtigen Ergebnisses',money(proof['valuation_difference_cents']))])
            for m in proof['movements']:
                ET.SubElement(body,'p').text=m['on']+': Bereich '+m['from_sphere']+' nach '+m['to_sphere']+'; '+m['reason'].replace('\u00b7','-')
                table(['Steuerwert vor Wechsel','Steuerwert nach Wechsel','Einlage','Entnahme','Bewertungsergebnis'],[[money(m[k]) for k in ('before_cents','after_cents','contribution_cents','withdrawal_cents','result_cents')]])
    for period,label in [('opening_adjustments','Steuerliche Anfangsunterschiede'),('current_adjustments','Steuerliche Wertänderungen des Jahres')]:
        for entry in s['valuation'][period]:
            ET.SubElement(body,'p').text = label+': '+entry['reason']
            chart = s['valuation']['accounts']
            table(['Konto','Soll','Haben'], [(chart[l['account']]['skr_code'] or l['account'],money(l['debit_cents']),money(l['credit_cents'])) for l in entry['lines']])
    for entry in s['off_balance']['entries']:
        ET.SubElement(body,'p').text = ('Hinzurechnung: ' if entry['kind']=='non_deductible_expense' else 'Kürzung: ')+money(entry['cents'])+' EUR; '+entry['reason']+'; Rechtsgrundlage: '+entry['legal_basis']
        table(['Beleg / Gegenstand' if any(k.startswith('asset-depreciation:') for k in entry['sources']) else 'Belegzeile','EUR'], [(('Steuerliche Abschreibung: '+s['off_balance']['sources'][key]['name']).replace('\u00b7','-') if key.startswith('asset-depreciation:') else key,money(n)) for key,n in entry['sources'].items()])
    for key, plan in mapping(s['review']['asset_tax_plans']).items():
        item = s['valuation']['asset_schedule']['items'][key]
        label=item['name'] if 'period' in s else str(item['receipt_number'])
        ET.SubElement(body,'p').text = 'Anlage '+label+': steuerliche Nutzungsdauer '+str(plan['months'])+' Monate; '+plan['reason']
    ET.SubElement(body,'p').text = 'Vor Verlustabzug und Freibeträgen. Keine Berechnung der festzusetzenden Steuer.'
    node(link,'link','footnoteArc',**attrs(type='arc',arcrole='http://www.xbrl.org/2003/arcrole/fact-footnote',**{'from':'result','to':'computation'}),order='1.0')

"""Same reviewed SKR42 registry as the PHP preview; no current-year fallback."""
import hashlib
import json
from functools import lru_cache
from pathlib import Path
from ebilanz_sales import movement
from ebilanz_asset_types import AMORTIZABLE_INTANGIBLE, MOVABLE, ORDINARY_EQUIPMENT, BUILDING, GOODWILL, GWG_CODES, DISPOSABLE


@lru_cache(maxsize=4)
def checked_source(year, registry_raw, catalog_raw):
    """Cache verified immutable bytes, never mtime/year alone or user balances.

    Every caller reads both files. Even an in-place same-size edit within the
    filesystem's timestamp resolution invalidates this bounded cache.
    """
    rules=json.loads(registry_raw)
    source=rules['sources'].get(str(year))
    if not source:raise ValueError('Geprüfte Jahreszuordnung fehlt.')
    catalog=json.loads(catalog_raw)
    if hashlib.sha256(catalog_raw).hexdigest()!=source['catalog_sha256'] or catalog['sha256']!=source['pdf_sha256']:
        raise ValueError('Kontenplan geändert. Jahreszuordnung erneut prüfen.')
    from datev_comparison import review,CODES
    comparison=set(CODES) if review(catalog) is not None else set()
    catalog_codes={a['code']:a for a in catalog['accounts']}
    def reserved(code):
        return any(a['function']=='R' and a['code']<=code<=a['end'] for a in catalog['ranges'])
    by_code={}
    for rule in rules['rules']:
        if 'years' in rule and year not in rule['years']: continue
        for code in rule['codes']:
            account=catalog_codes.get(code,{})
            if code in comparison or code in by_code or not account or account.get('function')=='R' or 'alternatives' in account or 'range_issue' in account:
                raise ValueError('Kontenzuordnung und Jahreskatalog widersprechen sich.')
            if reserved(code):
                raise ValueError('Reserviertes Konto in der Zuordnung.')
            by_code[code]=rule
    invalid={code for code,a in catalog_codes.items() if a.get('function')=='R'
        or 'alternatives' in a or 'range_issue' in a or reserved(code) or code.startswith('9') or code in comparison}
    return catalog_codes,by_code,invalid


def automatic_mapping(values, explicit=None):
    root=Path(__file__).resolve().parent.parent
    year=values['year']
    if type(year) is not int or year not in (2025,2026):raise ValueError('Geprüfte Jahreszuordnung fehlt.')
    registry_raw=(root/'resources/ebilanz/skr42-6.9.json').read_bytes().replace(b'\r\n',b'\n')
    catalog_raw=(root/f'data/skr42-{year}.json').read_bytes().replace(b'\r\n',b'\n')
    from datev_comparison import review
    review(json.loads(catalog_raw)) # Validate proof bytes on every call, including cached registry reads.
    catalog_codes,by_code,invalid=checked_source(year,registry_raw,catalog_raw)
    accounts=values['tax']['accounts']; schedule=(values.get('asset_tax') or {}).get('tax_schedule') or values.get('asset_schedule') or {}; result={}
    from ebilanz_financial_reserve_xml import assert_exportable
    assert_exportable(accounts)
    from ebilanz_partnership_holdings import mapping as holding_mapping
    holdings=holding_mapping(values)
    from ebilanz_own_intangible_books import mapping as own_hgb_mapping
    from ebilanz_own_intangible_values import mapping as own_tax_mapping
    from ebilanz_pool_original_values import mapping as pool_original_mapping
    pools=pool_original_mapping(values)
    own=own_hgb_mapping(values)|own_tax_mapping(values)
    period_assets=None
    if 'period' in values:
        from ebilanz_period_assets import rebuild
        period_assets=rebuild(values)['expected']['tax']
    for key,account in accounts.items():
        code=account.get('skr_code');rule=by_code.get(code)
        if code:
            original=catalog_codes.get(code)
            if not original or code in invalid:
                raise ValueError('SKR42-Konto ist im gewählten Jahr nicht als Bilanzkonto buchbar: '+str(code))
            if rule and rule['group']!=account['group']:
                raise ValueError('Geprüftes SKR42-Konto und Bilanzbereich widersprechen sich: '+str(code))
        if key in pools:
            if key in (explicit or {}) and explicit[key]!=pools[key]:raise ValueError('Ursprünglicher Sammelposten und gewählte XML-Position widersprechen sich.')
            result[key]=pools[key]
            continue
        if key in own:
            if key in (explicit or {}) and explicit[key]!=own[key]:raise ValueError('Ursprünglicher Entwicklungsnachweis und gewählte XML-Position widersprechen sich.')
            result[key]=own[key]
            continue
        if key in holdings:
            if key in (explicit or {}) and explicit[key]!=holdings[key]:raise ValueError('Beteiligungsquelle und gewählte XML-Position widersprechen sich.')
            result[key]=holdings[key]
            continue
        if not rule or rule['group']!=account['group']:continue
        if key in (explicit or {}):
            if explicit[key] not in rule.get('allowed_targets',[rule['target']]):
                raise ValueError('Kontenbedeutung und Zuordnung widersprechen sich: '+str(code))
        if 'directional_balance' in rule and key in (explicit or {}):
            from ebilanz_directional_closing import choices
            if explicit[key] not in choices(year,account):raise ValueError('Verrechnungskonto: Forderung und Verbindlichkeit wurden auf die falsche Bilanzseite zugeordnet.')
        if rule.get('condition') in ('reserve_purpose','trade_detail','detail_choice'):
            continue  # Source/purpose cannot be inferred from account number or balance.
        elif rule.get('condition')=='balance_direction':
            amount=account.get('amount_cents')
            if type(amount) is not int:raise ValueError('Geprüfter Kontensaldo fehlt: '+str(code))
            target=rule['negative_target'] if amount<0 else rule['target']
            if key in (explicit or {}) and explicit[key]!=target:
                raise ValueError('Kontensaldo und Zuordnung widersprechen sich: '+str(code))
            result[key]=target
            continue
        elif period_assets is not None and rule.get('condition') in ('equipment_sale','equipment_disposal','software_disposal','equipment_schedule','software_schedule','building_schedule','goodwill_schedule','gwg_schedule'):
            if key not in period_assets or period_assets[key]!=account['amount_cents']:continue
        elif rule.get('condition') in ('own_intangible_original','pool_original'):
            continue  # Only independently replayed original HGB or fiscal source proof resolves this.
        elif rule.get('condition')=='partnership_original':
            continue  # Only verified original capital/book/journal/matrix sources above resolve this.
        elif rule.get('condition')=='cost_schedule':
            continue  # Only the independently reconstructed cost_mapping below may resolve this.
        elif rule.get('condition')=='vat_clearing_zero':
            # Only already settled journal accounts; never fabricate tax closing entries.
            if type(account.get('amount_cents')) is not int or account['amount_cents']!=0:
                raise ValueError('Umsatzsteuerabschluss prüfen: Vorsteuer und Umsatzsteuer auf die verbleibende Forderung oder Verbindlichkeit abstimmen: '+str(code))
        elif rule.get('condition') in ('equipment_sale','equipment_disposal','software_disposal'):
            items=schedule.get('items') or {}
            if not isinstance(items,dict) or schedule.get('year')!=values['year'] or schedule.get('issues'):continue
            expected=[movement(item,code,values['year']) for item in items.values()]
            expected=[v for v in expected if v is not None]
            peers=[a for a in accounts.values() if a.get('skr_code')==code]
            if not expected or any(type(a.get('amount_cents')) is not int for a in peers) or sum(expected)!=sum(a['amount_cents'] for a in peers):continue
        elif 'condition' in rule:
            items=schedule.get('items') or {}
            if not isinstance(items,dict) or schedule.get('year')!=values['year'] or schedule.get('issues'):continue
            codes={'software_schedule':AMORTIZABLE_INTANGIBLE,'equipment_schedule':ORDINARY_EQUIPMENT,'building_schedule':BUILDING,'goodwill_schedule':GOODWILL,'gwg_schedule':GWG_CODES,'equipment_disposal':DISPOSABLE}.get(rule['condition'])
            if codes is None: raise ValueError('Unbekannte Zuordnungsregel.')
            from ebilanz_original_depreciation import code as original_depreciation_code
            relevant=[item for item in items.values() if item.get('skr_code') in codes and original_depreciation_code(item)==code]
            field='book_disposals_cents' if rule['condition']=='equipment_disposal' else 'depreciation_cents'
            if not relevant or sum(item.get(field,0) for item in relevant)!=account['amount_cents']:continue
            if sum(a.get('skr_code')==code for a in accounts.values())!=1:continue
        result[key]=rule['target']
    from ebilanz_cost_assets import cost_items,mapping as cost_mapping
    if cost_items(values.get('asset_schedule') or {}):
        for key,target in cost_mapping(values).items():
            if key in (explicit or {}) and explicit[key]!=target:
                raise ValueError('Kostenanlagen: Kontenzweck und belegte Bewertung widersprechen sich.')
            result[key]=target
    # A cost_schedule condition applies even to explicit mappings. A reviewed
    # source row may not acquire permission solely from a manual target choice.
    for key,target in (explicit or {}).items():
        rule=by_code.get(accounts[key].get('skr_code'),{})
        if rule.get('condition') in ('cost_schedule','partnership_original','own_intangible_original') and result.get(key)!=target:
            raise ValueError('Diese XML-Zuordnung benötigt den vollständigen ursprünglichen Bewertungsnachweis.')
    return result

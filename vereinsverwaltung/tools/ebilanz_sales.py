"""Explicit full tangible-asset sales; no VAT exemption or gain/loss inferred from account names."""
import re
from ebilanz_operating import SALES_DEDUCTIONS, INVENTORY_DECREASES, TAX_REFUNDS

GAIN_SALE='is.netIncome.regular.operatingTC.otherOpRevenue.disposFixAss.sale.tan.other'
GAIN_BOOK='is.netIncome.regular.operatingTC.otherOpRevenue.disposFixAss.bookValue.tan.other'
LOSS_SALE='is.netIncome.regular.operatingTC.otherCost.disposFixAss.sale.tan.other'
LOSS_BOOK='is.netIncome.regular.operatingTC.otherCost.disposFixAss.bookValue.tan.other'
CONTRA={GAIN_BOOK,LOSS_SALE} | SALES_DEDUCTIONS | INVENTORY_DECREASES | TAX_REFUNDS

def sale_codes(proof):
    kind=proof.get('vat_kind','domestic');rate=proof.get('rate')
    if kind not in ('domestic','export_4_1a','intra_eu_4_1b') or type(rate) is not int or rate not in (0,19) or (kind!='domestic' and rate!=0):
        raise ValueError('Anlagenverkauf: belegte Steuerbefreiung und Lieferungsart prüfen.')
    return {'export_4_1a':('48440','68840'),'intra_eu_4_1b':('48480','68880')}.get(kind,('48450' if rate==19 else '48490','68850' if rate==19 else '68890'))

def sale_values(proof,book):
    keys={'kind','date','reason','document_id','revision','sha256','complete','sale_entry','sale_hash','gross_cents','tax_cents','rate','vat_reason'}
    if (not isinstance(proof,dict) or set(proof)-{'vat_kind'}!=keys or proof.get('kind')!='sale'
        or not isinstance(proof['sale_entry'],str) or not re.fullmatch('[a-f0-9]{32}',proof['sale_entry'])
        or not isinstance(proof['sale_hash'],str) or not re.fullmatch('[a-f0-9]{64}',proof['sale_hash'])
        or type(proof['gross_cents']) is not int or not 0<proof['gross_cents']<=100000000000
        or type(proof['tax_cents']) is not int or type(proof['rate']) is not int or proof['rate'] not in (0,19)
        or not isinstance(proof['vat_reason'],str) or len(proof['vat_reason'])>500 or (proof['rate']==0 and not proof['vat_reason'].strip())
        or type(book) is not int or not 0<=book<=100000000000):
        raise ValueError('Anlagenverkauf: Vollständigen Verkaufsnachweis prüfen.')
    gross,rate=proof['gross_cents'],proof['rate'];vat=(gross*rate+(100+rate)//2)//(100+rate)
    if vat!=proof['tax_cents']:raise ValueError('Anlagenverkauf: Umsatzsteuer ist nicht abgestimmt.')
    net=gross-vat;gain=net>=book;gain_code,loss_code=sale_codes(proof)
    return {'net':net,'book':book,'gain':gain,
            'sale_code':gain_code if gain else loss_code,
            'book_code':'48550' if gain else '68950'}

def movement(item,code,year):
    if item.get('method')=='cost':
        from ebilanz_cost_assets import movement as cost_movement
        return cost_movement(item,code) if code in item.get('valuation_accounts',{}) else None
    proof=item.get('disposal')
    if not proof or not isinstance(proof.get('date'),str) or proof['date'][:4]!=str(year):return None
    book=item.get('book_disposals_cents',0)
    if proof.get('kind') in ('scrap','partial_scrap'):return book if code=='68950' else None
    if proof.get('kind')=='software_end':return book if code=='68960' else None
    if proof.get('kind')!='sale':return None
    sale=sale_values(proof,book)
    if code==sale['sale_code']:return sale['net'] if sale['gain'] else -sale['net']
    if code==sale['book_code']:return -book if sale['gain'] else book
    if code in (*sale_codes(proof),'48550','68950'):return 0
    return None

def fact_amount(target,amount,group=None):
    # Ledger uses credit-positive revenue/debit-positive expense. Official
    # deduction facts use positive values and a negative calculation weight.
    if target=='is.netIncome.regular.fin.netParticipation.earnings.partnerships' and group=='expense':return -amount
    if target=='is.netIncome.incomeSharing.loss.atypPart' and group=='expense':return -amount
    if target=='is.netIncome.tax.deferred.addition' and group=='expense':return -amount
    if target.startswith('is.netIncome.tax.releaseTaxProv.') and group in ('revenue','expense'):
        return amount if group=='revenue' else -amount
    return -amount if target in CONTRA else amount


def retained_accounts(item,accounts):
    if 'disposal_accounts' not in item:return None
    proof=item.get('disposal');selected=item['disposal_accounts']
    if not isinstance(proof,dict) or not isinstance(selected,dict):raise ValueError('Abgangskontennachweis fehlt.')
    if proof.get('kind') in ('scrap','partial_scrap'):codes={'68950':'expense'}
    elif proof.get('kind')=='software_end':codes={'68960':'expense'}
    elif proof.get('kind')=='sale' and type(proof.get('rate')) is int and proof['rate'] in (0,19):
        gain,loss=sale_codes(proof);codes={gain:'revenue','48550':'revenue',loss:'expense','68950':'expense'}
    else:raise ValueError('Unbekannter Abgangskontennachweis.')
    if set(selected)!=set(codes):raise ValueError('Abgangskontennachweis ist unvollständig.')
    for code,group in codes.items():
        key=selected[code]
        if not isinstance(key,str) or key not in accounts or accounts[key]['skr_code']!=code or accounts[key]['group']!=group:
            raise ValueError('Gespeichertes Abgangskonto und Kontenplan widersprechen sich.')
    return selected


def choose_account(item,accounts,code,group):
    selected=retained_accounts(item,accounts)
    if selected is not None:
        key=selected.get(code)
        if key is None or accounts[key]['group']!=group:raise ValueError('Beleggebundenes Abgangskonto fehlt.')
        return key
    candidates=[key for key,a in accounts.items() if a['skr_code']==code and a['group']==group]
    if len(candidates)!=1:raise ValueError('Älterem Anlagenabgang fehlt ein eindeutiges Gegenkonto.')
    return candidates[0]


def check_movements(items,accounts,year):
    codes=set()
    for item in items.values():
        selected=retained_accounts(item,accounts)
        if selected is not None:codes.update(selected)
    for code in sorted(codes):
        peers={key:a for key,a in accounts.items() if a['skr_code']==code};expected=dict.fromkeys(peers,0)
        for item in items.values():
            amount=movement(item,code,year)
            if amount is None:continue
            selected=item['valuation_accounts'] if item.get('method')=='cost' else retained_accounts(item,accounts)
            if selected is None:
                if len(peers)!=1:raise ValueError('Älterer Abgang mit mehrdeutigen Konten bleibt ungeklärt.')
                key=next(iter(peers))
            else:key=selected.get(code)
            if key is None:raise ValueError('Beleggebundenes Abgangskonto fehlt.')
            expected[key]+=amount
        for key,account in peers.items():
            if type(account['amount_cents']) is not int or account['amount_cents']!=expected[key]:
                raise ValueError('Anlagenabgang und gespeichertes Gegenkonto stimmen nicht überein.')

# Debit-positive deductions from capital have negative official calculation weights.
CONTRA |= {'bs.ass.currAss.inventory.advPaymReceived', 'bs.eqLiab.equity.subscribed.ownSharesdeducted', 'bs.eqLiab.equity.foundation.endowCap.notContributed', 'bs.eqLiab.equity.subscribed.unpaidCap'}

# Original debit/credit source is retained; official subtraction facts reverse only XML presentation.
CONTRA |= {'is.netIncome.regular.operatingTC.otherCost.disposFixAss.sale.fin.other', 'is.netIncome.regular.operatingTC.otherOpRevenue.disposFixAss.bookValue.fin.investmentShares', 'is.netIncome.regular.operatingTC.otherOpRevenue.disposFixAss.bookValue.fin.domesticCorp', 'is.netIncome.regular.operatingTC.otherOpRevenue.disposFixAss.bookValue.fin.foreignCorp', 'is.netIncome.regular.operatingTC.otherCost.disposFixAss.sale.fin.investmentShares', 'is.netIncome.regular.operatingTC.otherCost.disposFixAss.sale.fin.foreignCorp', 'is.netIncome.regular.operatingTC.otherOpRevenue.disposFixAss.bookValue.fin.other', 'is.netIncome.regular.operatingTC.otherOpRevenue.disposFixAss.bookValue.intan', 'is.netIncome.regular.operatingTC.otherCost.disposFixAss.sale.fin.domesticCorp', 'is.netIncome.regular.operatingTC.otherCost.disposFixAss.sale.intan'}

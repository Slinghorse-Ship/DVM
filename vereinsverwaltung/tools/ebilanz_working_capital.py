"""Reviewed current-asset/debt facts and additive 'of which' disclosures.

The yearly, source-bound purpose supplies these facts. Neither account digits,
free-text labels nor a guessed invoice date determine a maturity. Disclosures
never participate in a balance-sheet total and never duplicate an account list.
"""
from pathlib import Path

SUPPORTED = {
    'inventory': {'bs.ass.currAss.inventory.inProgress.constructionInProgress',
                  'bs.ass.currAss.inventory.inProgress.ordersInProgress'},
    'receivables': {'bs.ass.currAss.receiv.affil', 'bs.ass.currAss.receiv.particip'},
    'other_assets': {'bs.ass.currAss.securities.affil', 'bs.ass.currAss.securities.affil.parentComp', 'bs.ass.currAss.securities.affil.subsidiaries'}
        | {'bs.ass.currAss.securities.other.'+s for s in ('bonds','convertBond','mutInvestm','other','partCertificate','securities','shares','warrants')},
    'provisions': {'bs.eqLiab.accruals.pensions.'+s for s in ('direct','externalFunds','shareholder','other')}
        | {'bs.eqLiab.accruals.tax.'+s for s in ('other','additionalTax','AO233aOnAdditionalTax')}
        | {'bs.eqLiab.accruals.other.uncertainLiab.'+s for s in ('other','maintenance','landReclaim')}
        | {'bs.eqLiab.accruals.other.statutoryLiab'},
    'liabilities': {'bs.eqLiab.liab.advPaym', 'bs.eqLiab.liab.other.jointVent',
                    'bs.eqLiab.liab.other.silentPartner.typ',
                    'bs.eqLiab.liab.other.theroffTax.operatingTaxes',
                    'bs.eqLiab.liab.other.theroffTax.otherTaxes',
                    'bs.eqLiab.liab.securities', 'bs.eqLiab.liab.otherDebtInstruments'}
        | {'bs.eqLiab.liab.assocComp.'+s for s in ('other','partner','SilentPartner.typ','SilentPartner.misc','misc')}
        | {'bs.eqLiab.liab.particip.'+s for s in ('other','otherShareholder','silentPartner.typ','silentPartner.misc','misc')},
}

# Only non-additive, officially defined disclosures; no arbitrary extra XML.
DISCLOSURES = {
    'bs.ass.currAss.receiv.trade': ('upTo1year', 'above1year'),
    'bs.ass.currAss.receiv.affil': ('upTo1year', 'above1year', 'trade'),
    'bs.ass.currAss.receiv.particip': ('upTo1year', 'above1year', 'trade'),
    'bs.eqLiab.liab.trade': ('upTo1year', 'above1year'),
    'bs.eqLiab.liab.advPaym': ('upTo1year', 'above1year'),
    'bs.eqLiab.liab.other': ('upTo1year', 'above1year'),
    'bs.ass.currAss.receiv.other': ('upTo1year', 'above1year'),
    'bs.eqLiab.liab.securities': ('upTo1year', 'above1year', 'convertible',
                                'upTo1year.nonConvertible', 'upTo1year.warrants'),
    'bs.eqLiab.liab.assocComp': ('upTo1year', 'above1year', 'payables', 'upTo1year.trade'),
    'bs.eqLiab.liab.particip': ('upTo1year', 'above1year', 'accountsPayables', 'upTo1year.trade'),
    'is.netIncome.regular.fin.netParticipation.earnings': ('groupComp',),
    'is.netIncome.regular.fin.netParticipation.earningSecurities': ('assoc',),
    'is.netIncome.regular.fin.netInterest.income': ('assoc',),
    'is.netIncome.regular.fin.netInterest.expenses': ('assoc',),
}


def validate(rule, meta):
    extra = rule.get('disclosures', [])
    if not isinstance(extra, list) or any(not isinstance(t, str) for t in extra) or len(set(extra)) != len(extra):
        raise ValueError('Zusatzangaben der Jahreszuordnung prüfen.')
    for target in extra:
        bases = [base for base, suffixes in DISCLOSURES.items()
                 if target in {base+'.'+s for s in suffixes}]
        if target == 'is.netIncome.regular.operatingTC.otherOpRevenue.licenses':
            bases = ['is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.netSales.grossSales']
        if len(bases) != 1:
            raise ValueError('Ungeprüfte Zusatzangabe der Jahreszuordnung: '+target)
        base = bases[0]
        choices = set([rule['target'], *rule.get('allowed_targets', [])])
        if any(t != base and not t.startswith(base+'.') for t in choices):
            raise ValueError('Zusatzangabe und Kontenzweck widersprechen sich.')
        # A disclosure must not be a balance amount or feed any sum, directly
        # or indirectly. Any later taxonomy change must be reviewed explicitly.
        if meta['calculations'].get(target) or any(target in children for children in meta['calculations'].values()):
            raise ValueError('Zusatzangabe darf keine Bilanzsumme verändern.')
    for base in DISCLOSURES:
        suffixes = {t[len(base)+1:] for t in extra if t.startswith(base+'.')}
        if {'upTo1year','above1year'} <= suffixes:
            raise ValueError('Widersprüchliche Restlaufzeiten der Jahreszuordnung.')
        if any(s.startswith('upTo1year.') for s in suffixes) and 'upTo1year' not in suffixes:
            raise ValueError('Unterangabe benötigt die zugehörige Restlaufzeit.')
        if base.endswith('.securities'):
            if 'upTo1year.nonConvertible' in suffixes and suffixes & {'convertible','upTo1year.warrants'}:
                raise ValueError('Widersprüchliche Angaben zur Wandelanleihe.')
            if 'upTo1year.warrants' in suffixes and 'convertible' not in suffixes:
                raise ValueError('Wandelanleihe benötigt die Angabe konvertibel.')
        if 'upTo1year.trade' in suffixes and not suffixes & {'payables','accountsPayables'}:
            raise ValueError('Unterangabe benötigt den Lieferungs- und Leistungsbezug.')


def disclosures(values, mapping, meta, permitted):
    from ebilanz_mapping import checked_source
    root = Path(__file__).resolve().parent.parent
    year = values['year']
    _, rules, _ = checked_source(year,
        (root/'resources/ebilanz/skr42-6.9.json').read_bytes().replace(b'\r\n', b'\n'),
        (root/f'data/skr42-{year}.json').read_bytes().replace(b'\r\n', b'\n'))
    facts = {}
    for key, account in values['tax']['accounts'].items():
        rule = rules.get(account.get('skr_code'))
        if not rule or not rule.get('disclosures') or key not in mapping:
            continue
        validate(rule, meta)
        if account['group'] != rule['group'] or mapping[key] not in rule.get('allowed_targets', [rule['target']]):
            raise ValueError('Zusatzangabe und zugeordnetes Konto widersprechen sich.')
        amount = account['amount_cents']
        from ebilanz_account_closing import is_allowance
        minimum = -100000000000 if rule['group'] in ('revenue','expense') or is_allowance(year,account) else 0
        if type(amount) is not int or not minimum <= amount <= 100000000000:
            raise ValueError('Zusatzangabe benötigt einen abgestimmten Kontensaldo.')
        for target in rule['disclosures']:
            if not permitted(target, year):
                raise ValueError('Zusatzangabe ist für dieses Steuerjahr nicht zulässig.')
            facts[target] = facts.get(target, 0) + amount
            if not -100000000000 <= facts[target] <= 100000000000:
                raise ValueError('Zusatzangabe überschreitet die Betragsgrenze.')
    if any(value < 0 for target,value in facts.items() if target.startswith('bs.')):
        raise ValueError('Wertberichtigung überschreitet die belegte Restlaufzeit.')
    return facts

# Revision 25: exact foundation/corporation capital and original special-post facts.
SUPPORTED.setdefault('deferred', set()).update(['bs.eqLiab.otherSpecRes.other.EStG6b', 'bs.eqLiab.otherSpecRes.other.other', 'bs.eqLiab.otherSpecRes.other.releaseWithdrawalEStG4g', 'bs.eqLiab.otherSpecRes.other.replacement', 'bs.eqLiab.otherSpecRes.other.subsidies', 'bs.eqLiab.otherSpecRes.subsidies', 'bs.eqLiab.otherSpecRes.unspentDonationFunds.bound', 'bs.eqLiab.otherSpecRes.unspentDonationFunds.statutoryUse'])
SUPPORTED.setdefault('equity', set()).update(['bs.eqLiab.equity.capRes', 'bs.eqLiab.equity.foundation.consumable', 'bs.eqLiab.equity.foundation.donation', 'bs.eqLiab.equity.foundation.endowCap.notContributed', 'bs.eqLiab.equity.foundation.estab', 'bs.eqLiab.equity.foundation.injectionRevRes', 'bs.eqLiab.equity.statutoryBoundCapital', 'bs.eqLiab.equity.subscribed.corp', 'bs.eqLiab.equity.subscribed.ownSharesdeducted', 'bs.eqLiab.equity.subscribed.unpaidCap'])
SUPPORTED.setdefault('liabilities', set()).update(['bs.eqLiab.liab.statutoryBenefits.other'])
SUPPORTED.setdefault('other_assets', set()).update(['bs.ass.currAss.receiv.other.reInsurClaim', 'bs.ass.currAss.receiv.other.unpaidCapital', 'bs.ass.currAss.receiv.other.unpaidSupplementaryCalls'])
SUPPORTED.setdefault('provisions', set()).update(['bs.eqLiab.accruals.tax.other'])
SUPPORTED.setdefault('reserves', set()).update(['bs.eqLiab.equity.revenueRes.capMaintenance', 'bs.eqLiab.equity.revenueRes.legal', 'bs.eqLiab.equity.revenueRes.sharesParentComp'])
DISCLOSURES['bs.eqLiab.equity.capRes'] = ('sharePremium', 'warrantPremium', 'contrPremShares', 'other', 'cashRequGmbh')

# Revision 29: source-reviewed foreign taxes and contract-selected silent liabilities.
SUPPORTED['liabilities'] |= {'bs.eqLiab.liab.other.silentPartner.misc'}

# Revision 30: official non-additive affiliated financial impairment disclosure.
DISCLOSURES['is.netIncome.regular.fin.netParticipation.amortFinanc'] = ('group',)

# Revision 34: original cheques and statutory prepaid-expense subpositions.
SUPPORTED.setdefault('cash', set()).update(['bs.ass.currAss.cashEquiv.cheques'])
SUPPORTED.setdefault('prepaid', set()).update(['bs.ass.prepaidExp.customTaxDuty', 'bs.ass.prepaidExp.vat'])

# Revision 35: only source-bound directional contracts can use the opposite BS side.
SUPPORTED.setdefault('other_assets', set()).update(['bs.ass.currAss.cashEquiv.bank', 'bs.eqLiab.liab.other.other', 'bs.eqLiab.liab.other.theroffTax.vat'])
SUPPORTED.setdefault('liabilities', set()).update(['bs.ass.currAss.cashEquiv.bank', 'bs.ass.currAss.receiv.other.employees', 'bs.ass.currAss.receiv.other.other', 'bs.ass.currAss.receiv.other.otherTaxRec', 'bs.ass.currAss.receiv.other.socInsur'])

# Original bank clearing debt: native summation requires the confirmed ordinary bank-debt leaf.
SUPPORTED['liabilities'].add('bs.eqLiab.liab.bank.other')

# Original bank-loan maturity disclosures never contribute to balance totals.
DISCLOSURES['bs.eqLiab.liab.bank'] = ('upTo1year','above1year')

# Source-bound value-recovery reserve: disclosure, never a primary BS amount.
DISCLOSURES['bs.eqLiab.equity.revenueRes.other']=('AktG58_2a',)

# Original debtor/vendor advance clearing; confirmed signed side, no zero replacement.
SUPPORTED['other_assets'].add('bs.eqLiab.liab.advPaym')
SUPPORTED['liabilities'].add('bs.ass.currAss.inventory.advPaymPaid')

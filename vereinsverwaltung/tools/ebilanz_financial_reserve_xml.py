"""Reject an unqualified active financial reserve even with an explicit target.

Unused chart accounts do not grant an approval. No catalogue rule is added.
"""
CODES = {'72500', '72550'}
PREFIX = 'is.netIncome.regular.fin.netParticipation.amortFinanc.'
TARGETS = {PREFIX + suffix for suffix in ('financialsExcept.participCorp.domesticCorp', 'financialsExcept.participCorp.foreignCorp', 'financialsExcept.participCorp.other', 'other', 'financialsExcept.loansSharehRelPart.domestic', 'financialsExcept.loansSharehRelPart.foreign', 'financialsExcept.loansSharehRelPart.other')}


def assert_exportable(accounts):
    for account in accounts.values():
        if account.get('skr_code') not in CODES:
            continue
        amount = account.get('amount_cents')
        if type(amount) is not int:
            raise ValueError('Geprüfter Finanzanlagenbetrag fehlt.')
        if amount != 0:
            raise ValueError('72500/72550: Originalrücklage, Rechtsnachfolge, Anlagenbasis und tatsächliche Steuerbuchungen sind noch nicht fachlich abgenommen; eine manuelle XML-Position ersetzt diese Prüfung nicht.')

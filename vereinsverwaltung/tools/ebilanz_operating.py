"""Reviewed operating facts, including debit-positive sales deductions.

These are explicit taxonomy concepts, not prefix-based permissions. No VAT is
calculated here: the reviewed original journal supplies net/actual amounts.
"""
BASE='is.netIncome.regular.operatingTC.grossTradingProfit.'
SALES=BASE+'totalOutput.netSales.grossSales.'
DEDUCTIONS=BASE+'totalOutput.netSales.reductionsFromGrossSales.'
MATERIAL=BASE+'materialServices.material.'
SERVICES=BASE+'materialServices.services.'
REVENUE={SALES+s for s in (
    'otherRateVAT','taxExemptOther','taxExemptUStG4_1a','taxExemptUStG4_1b.VIES_ID',
    'taxExemptUStG4_1b.vehicleWithoutVAT_ID','taxExemptUStG4_8.other','taxExemptUStG4_8.UStG4_12',
    'taxExemptUStG4_2til7','UStG13b','UStG24','UStG25_25a','triangularTransactionUStG25b_2_4',
    'untaxable','unknownVAT')}
SALES_DEDUCTIONS={DEDUCTIONS+s for s in (
    'generalRateVAT','reducedRateVAT','otherRateVAT','taxExemptOther','taxExemptUStG4_1a',
    'taxExemptUStG4_1b.VAT_ID','taxExemptUStG4_8.other',
    'taxExemptUStG4_2til7','UStGs25_2_4','UStGss25and25a','taxExemptUSt19_1','untaxable','untaxed','unknownVAT')}
REVENUE |= SALES_DEDUCTIONS
EXPENSE={MATERIAL+s for s in ('misc','purchased.misc','purchased.inventoryChange',
    'rawMatConsSup.misc','rawMatConsSup.inventoryChange')}
EXPENSE |= {SERVICES+s for s in ('OtherDedInputTax','OtherNonDedInputTax',
    'UStG13bDedInputTax','UStG13bNonDedInputTax','unknownVAT')}

# Revision 15: exact expense/income concepts, never a blanket permission for
# their parent subtree. Tax deductibility is a separate reconciliation, not an
# effect of choosing a presentation concept.
OTHER_REVENUE='is.netIncome.regular.operatingTC.otherOpRevenue.'
OTHER_COST='is.netIncome.regular.operatingTC.otherCost.'
INTEREST='is.netIncome.regular.fin.netInterest.'
REVENUE |= {OTHER_REVENUE+s for s in (
    'subsidies.other','subsidies.taxExempt','miscellaneous','currGains',
    'releaseLossProv.globalValuation','releaseLossProv.specificValuation',
    'recoveryWriteoffs','releaseProv','releasLiab','insuranceRefunds')}
REVENUE |= {INTEREST+'income.'+s for s in (
    'deposits.AO233a','deposits.banks','deposits.thirdParty','deposits.otherMisc',
    'other')}
EXPENSE |= {OTHER_COST+s for s in (
    'currLoss','employee','staffRelated','otherTaxes.other',
    'limitedDeductible.gifts.deductible','limitedDeductible.gifts.nondeductible',
    'limitedDeductible.entertainment.deductible',
    'limitedDeductible.entertainment.nonDeductible',
    'limitedDeductible.entertainment.other',
    'limitedDeductible.other.miscellaneous.deductible',
    'limitedDeductible.other.miscellaneous.nonDeductible')}
EXPENSE |= {INTEREST+'expenses.'+s for s in (
    'regularInterest.AO233a.deductible','regularInterest.AO233a.nondeductible',
    'regularInterest.AO234to237.deductible','regularInterest.AO234to237.nonDeductible',
    'regularInterest.relatedPayments.deduct','regularInterest.relatedPayments.nondeduct',
    'regularInterest.other','amortDiscount','loanFees','other')}

# Exact source families from the printed SKR42 statements. Supplementary
# "davon" concepts and EU/PG-only withdrawals are deliberately NOT permitted.
STATUTORY=BASE+'totalOutput.statutoryDuties.'
INVENTORY_CHANGE=BASE+'totalOutput.inventoryChange.'
REVENUE |= {STATUTORY+s for s in (
    'membershipFees','contributions','other','donations.immediatelyRecognised','donations.use')}
REVENUE |= {OTHER_REVENUE+s for s in (
    'ownConsumption.otherWithdrawals','ownConsumption.nonCashBenefitsCompCar',
    'ownConsumption.nonCashBenefitsOther','acqudFreeOfCharge')}
REVENUE |= {INVENTORY_CHANGE+s for s in (
    'increasefinished','decreasefinished','increaseGoodsInProgress','decreaseGoodsInProgress',
    'increaseConstructionInProgress','decreaseConstructionInProgress')}
# The ledger retains credit-positive revenue; a decrease is a positive fact
# under the official negative-weight calculation arc, not a second expense.
INVENTORY_DECREASES={INVENTORY_CHANGE+s for s in (
    'decreasefinished','decreaseGoodsInProgress','decreaseConstructionInProgress')}
EXPENSE |= {OTHER_COST+s for s in (
    'provisions',
    'limitedDeductible.other.donations.nondeductibleOther.nonallocable',
    'limitedDeductible.other.donations.nondeductiblePoliticalParties',
    'limitedDeductible.other.donations.taxprivileged',
    'limitedDeductible.other.supervisoryboard.domesticMembers.deductible',
    'limitedDeductible.other.supervisoryboard.domesticMembers.nonDeductible',
    'limitedDeductible.other.supervisoryboard.foreignMembers.deductible',
    'limitedDeductible.other.supervisoryboard.foreignMembers.nonDeductible')}
TAX='is.netIncome.tax.'
EXPENSE |= {TAX+s for s in (
    'kst','soli.other','soli.kestcreditable','gewst','kest.creditable','kest.noncreditable',
    'kest.other','deductableForeignIncomeTaxes','nonDeductableForeignIncomeTaxes',
    'prevPeriodPaid.kst','prevPeriodPaid.soli','prevPeriodPaid.gewst',
    'prevPeriodReceived.kst','prevPeriodReceived.soli','prevPeriodReceived.gewst',
    'releaseTaxProv.Gew')}
TAX_REFUNDS={TAX+s for s in (
    'prevPeriodReceived.kst','prevPeriodReceived.soli','prevPeriodReceived.gewst','releaseTaxProv.Gew')}
EXPENSE.add('is.netIncome.otherTaxes.releaseTaxProvision')
TAX_REFUNDS.add('is.netIncome.otherTaxes.releaseTaxProvision')

# Financial income: individually reviewed leaves. Selecting these presentation
# facts never substitutes for the separately confirmed tax reconciliation.
PARTICIPATION='is.netIncome.regular.fin.netParticipation.'
for branch in ('earnings.', 'earningSecurities.'):
    REVENUE |= {PARTICIPATION+branch+'corporations.'+place+'.'+size
                for place in ('domestic','foreign')
                for size in ('smaller10pt','10to15pt','notLessThan15pt','other')}
    REVENUE.add(PARTICIPATION+branch+'corporations.other')
REVENUE |= {PARTICIPATION+s for s in (
    'earnings.silentPart','earningSecurities.partnerships',
    'earningSecurities.minorInterestReceived','earningSecurities.shareholder',
    'earningSecurities.interestDividend.interestOtherLoans.Employee',
    'earningSecurities.interestDividend.interestOtherLoans.misc')}
REVENUE |= {INTEREST+'income.securities.dividends.'+s for s in (
    'domestic.smaller10pt','domestic.10to15pt','domestic.notLessThan15pt','domestic.misc',
    'foreign.smaller10pt.DTTexempt','foreign.smaller10pt.other',
    'foreign.10to15pt','foreign.notLessThan15pt')}
REVENUE.add(INTEREST+'income.securities.interest')
REVENUE.add(INTEREST+'income.valueDiscount.accruals')
REVENUE |= {INTEREST+'income.affiliated.comp.'+s for s in ('corp','partnership','solePropietor','other')}
EXPENSE |= {INTEREST+'expenses.'+s for s in (
    'regularInterest.PartnersLoans.other','regularInterest.PartnersLoans.participationOver25pt',
    'annuities','calcInterestOnPensProv','discount','valueDiscount.liabilities')}

# Revision 24: original current-asset impairment, tax-reserve and contract facts.
REVENUE |= {'is.netIncome.regular.operatingTC.otherOpRevenue.releasePreTaxRes.investmentShares', 'is.netIncome.regular.fin.netParticipation.earningSecurities.interestDividend.dividend.nonDomesticCorp.less10pt', 'is.netIncome.regular.fin.netParticipation.earningSecurities.interestDividend.interest', 'is.netIncome.regular.fin.netParticipation.earningSecurities.interestDividend.dividend.domesticCorp.larger15pt', 'is.netIncome.regular.fin.netParticipation.earningSecurities.interestDividend.interestOtherLoans.nonAllocable', 'is.netIncome.incomeSharing.loss.typPart', 'is.netIncome.regular.operatingTC.otherOpRevenue.releasePreTaxRes.other', 'is.netIncome.regular.fin.netParticipation.earningSecurities.interestDividend.dividend.domesticCorp.less10pt', 'is.netIncome.regular.fin.netParticipation.earningSecurities.interestDividend.dividend.domesticCorp.10to15pt', 'is.netIncome.incomeSharing.loss.partialProfitTransfer', 'is.netIncome.regular.operatingTC.otherOpRevenue.releasePreTaxRes.EStG6b_3', 'is.netIncome.regular.fin.netParticipation.earningSecurities.interestDividend.dividend.nonDomesticCorp.10to15pt.thirdCountry', 'is.netIncome.regular.fin.netParticipation.earningSecurities.interestDividend.dividend.nonDomesticCorp.10to15pt.EU', 'is.netIncome.regular.fin.netParticipation.earningProfSharing.fiscalUnity.profitTransfer', 'is.netIncome.regular.operatingTC.otherOpRevenue.releasePreTaxRes.substEStR6_6', 'is.netIncome.regular.fin.netParticipation.earningSecurities.interestDividend.dividend.nonDomesticCorp.NonAllocableInterests', 'is.netIncome.regular.operatingTC.otherOpRevenue.releasePreTaxRes.misc', 'is.netIncome.incomeSharing.loss.profitTransfer', 'is.netIncome.regular.fin.netParticipation.earningProfSharing.profPooling', 'is.netIncome.regular.fin.netParticipation.earningSecurities.interestDividend.dividend.NonAllocable', 'is.netIncome.regular.fin.netParticipation.earningSecurities.interestDividend.dividend.domesticCorp.nonAllocable', 'is.netIncome.regular.operatingTC.otherOpRevenue.releasePreTaxRes.EStG5_7', 'is.netIncome.regular.fin.netParticipation.earningSecurities.interestDividend.dividend.nonDomesticCorp.larger15pt', 'is.netIncome.regular.fin.netParticipation.earningProfSharing.partialProfitTransfer', 'is.netIncome.regular.operatingTC.otherOpRevenue.releasePreTaxRes.KStGs34_6e', 'is.netIncome.incomeSharing.loss.profPooling', 'is.netIncome.regular.operatingTC.otherOpRevenue.releasePreTaxRes.EStG4g', 'is.netIncome.extraord.income.merger'}
EXPENSE |= {'is.netIncome.regular.operatingTC.deprAmort.currAss.receiv.misc', 'is.netIncome.incomeSharing.gain.profPooling', 'is.netIncome.regular.fin.netParticipation.loss.partialProfitTransfer.silentPart', 'is.netIncome.regular.operatingTC.deprAmort.currAss.receiv.other', 'is.netIncome.regular.operatingTC.otherCost.addPreTaxRes.substEStR6_6', 'is.netIncome.regular.operatingTC.otherCost.addPreTaxRes.misc', 'is.netIncome.regular.operatingTC.otherCost.addPreTaxRes.EStG5_7', 'is.netIncome.incomeSharing.gain.fiscalUnity', 'is.netIncome.incomeSharing.gain.other', 'is.netIncome.regular.operatingTC.deprAmort.currAss.receiv.againstCorpParticip.foreignRelated', 'is.netIncome.regular.fin.netParticipation.loss.profPooling', 'is.netIncome.regular.operatingTC.deprAmort.currAss.receiv.againstCorpParticip.domesticRelated', 'is.netIncome.regular.operatingTC.otherCost.merger', 'is.netIncome.regular.fin.netParticipation.loss.partialProfitTransfer.other', 'is.netIncome.regular.operatingTC.deprAmort.currAss.receiv.sharehRelPart.foreign', 'is.netIncome.regular.operatingTC.otherCost.addPreTaxRes.EStG6b_3', 'is.netIncome.regular.fin.netParticipation.loss.fiscalUnity.lossTransfer', 'is.netIncome.regular.operatingTC.deprAmort.currAss.misc', 'is.netIncome.regular.operatingTC.deprAmort.currAss.receiv.againstCorpParticip.domesticUnrelated', 'is.netIncome.regular.operatingTC.deprAmort.currAss.inventory', 'is.netIncome.regular.operatingTC.otherCost.addPreTaxRes.EStG4g', 'is.netIncome.regular.operatingTC.deprAmort.currAss.receiv.againstCorpParticip.foreignUnrelated', 'is.netIncome.regular.operatingTC.otherCost.addPreTaxRes.other', 'is.netIncome.regular.operatingTC.deprAmort.currAss.receiv.sharehRelPart.domestic'}

# Revision 30: exact source-reviewed current securities and financial/tax selections.
REVENUE |= set(['is.netIncome.regular.fin.netParticipation.earnings.other', 'is.netIncome.tax.releaseTaxProv.Gew', 'is.netIncome.tax.releaseTaxProv.KSt', 'is.netIncome.tax.releaseTaxProv.Soli', 'is.netIncome.tax.releaseTaxProv.minst', 'is.netIncome.tax.releaseTaxProv.other'])
EXPENSE |= set(['is.netIncome.regular.fin.netParticipation.amortFinanc.secCurrAss.investmentShares', 'is.netIncome.regular.fin.netParticipation.amortFinanc.secCurrAss.other', 'is.netIncome.regular.fin.netParticipation.amortFinanc.secCurrAss.taxneutralDomesticCorp', 'is.netIncome.regular.fin.netParticipation.amortFinanc.secCurrAss.taxneutralForeignCorp', 'is.netIncome.tax.minst', 'is.netIncome.tax.otherIncomeTaxes'])

# Revision 32: exact original source families, with separately checked disclosures.
REVENUE |= set(['is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.netSales.taxFromGrossSales', 'is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.ownWork.fixing', 'is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.ownWork.plants', 'is.netIncome.regular.operatingTC.otherOpRevenue.disposCurrAss.domesticCorp', 'is.netIncome.regular.operatingTC.otherOpRevenue.disposCurrAss.foreignCorp', 'is.netIncome.regular.operatingTC.otherOpRevenue.disposCurrAss.other', 'is.netIncome.regular.operatingTC.otherOpRevenue.revalCurrAss.domesticCorp', 'is.netIncome.regular.operatingTC.otherOpRevenue.revalCurrAss.domesticLoan.relatedParty', 'is.netIncome.regular.operatingTC.otherOpRevenue.revalCurrAss.domesticLoan.unrelatedParty', 'is.netIncome.regular.operatingTC.otherOpRevenue.revalCurrAss.foreignCorp', 'is.netIncome.regular.operatingTC.otherOpRevenue.revalCurrAss.other'])
EXPENSE |= set(['is.netIncome.regular.fin.netParticipation.amortFinanc.financialsExcept.participCorp.domesticCorp', 'is.netIncome.regular.fin.netParticipation.amortFinanc.financialsExcept.participCorp.foreignCorp', 'is.netIncome.regular.operatingTC.otherCost.disposCurrAss.domesticCorp', 'is.netIncome.regular.operatingTC.otherCost.disposCurrAss.foreignCorp', 'is.netIncome.regular.operatingTC.otherCost.disposCurrAss.other', 'is.netIncome.regular.operatingTC.otherCost.disposalCorp.domestic', 'is.netIncome.regular.operatingTC.otherCost.disposalCorp.foreign', 'is.netIncome.regular.operatingTC.otherCost.disposalCorp.misc'])
SALES_DEDUCTIONS.add('is.netIncome.regular.operatingTC.grossTradingProfit.totalOutput.netSales.taxFromGrossSales')

# Revision 41: exact original asset-sale, book-value and write-up facts.
REVENUE |= {'is.netIncome.regular.operatingTC.otherOpRevenue.disposFixAss.bookValue.fin.investmentShares', 'is.netIncome.regular.operatingTC.otherOpRevenue.disposFixAss.sale.fin.investmentShares', 'is.netIncome.regular.operatingTC.otherOpRevenue.disposFixAss.bookValue.fin.domesticCorp', 'is.netIncome.regular.operatingTC.otherOpRevenue.disposFixAss.sale.fin.domesticCorp', 'is.netIncome.regular.operatingTC.otherOpRevenue.disposFixAss.bookValue.fin.foreignCorp', 'is.netIncome.regular.operatingTC.otherOpRevenue.revalFixAss.fin.domesticCorp', 'is.netIncome.regular.operatingTC.otherOpRevenue.revalFixAss.fin.domesticLoan.relatedParty', 'is.netIncome.regular.operatingTC.otherOpRevenue.revalFixAss.intan', 'is.netIncome.regular.operatingTC.otherOpRevenue.revalFixAss.fin.domesticLoan.unrelatedParty', 'is.netIncome.regular.operatingTC.otherOpRevenue.disposFixAss.bookValue.intan', 'is.netIncome.regular.operatingTC.otherOpRevenue.disposFixAss.bookValue.fin.other', 'is.netIncome.regular.operatingTC.otherOpRevenue.disposFixAss.sale.intan', 'is.netIncome.regular.operatingTC.otherOpRevenue.revalFixAss.fin.foreignLoan.relatedParty', 'is.netIncome.regular.operatingTC.otherOpRevenue.disposFixAss.sale.fin.other', 'is.netIncome.regular.operatingTC.otherOpRevenue.revalFixAss.fin.foreignLoan.unrelatedParty', 'is.netIncome.regular.operatingTC.otherOpRevenue.revalFixAss.fin.foreignCorp', 'is.netIncome.regular.operatingTC.otherOpRevenue.disposFixAss.sale.fin.foreignCorp'}
EXPENSE |= {'is.netIncome.regular.operatingTC.otherCost.disposFixAss.sale.fin.other', 'is.netIncome.regular.operatingTC.otherCost.disposFixAss.sale.fin.investmentShares', 'is.netIncome.regular.operatingTC.otherCost.disposFixAss.sale.fin.foreignCorp', 'is.netIncome.regular.operatingTC.otherCost.disposFixAss.bookValue.fin.domesticCorp', 'is.netIncome.regular.operatingTC.otherCost.disposFixAss.bookValue.fin.foreignCorp', 'is.netIncome.regular.operatingTC.otherCost.disposFixAss.sale.fin.domesticCorp', 'is.netIncome.regular.operatingTC.otherCost.disposFixAss.sale.intan'}

# Revision 43: exact reviewed financial-impairment and typical silent-contract facts.
EXPENSE |= {'is.netIncome.incomeSharing.gain.silentTypical'}

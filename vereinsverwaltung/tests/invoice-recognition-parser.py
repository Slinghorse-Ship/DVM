"""Pure parsing checks; this is not a substitute for native OCR fixture tests."""
import importlib.util
from pathlib import Path
spec=importlib.util.spec_from_file_location('recognition',Path(__file__).resolve().parents[1]/'tools'/'recognize-invoice.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
r=m.fields_from_text('Rechnung\nGesamtbetrag: 119,00 EUR\nGesamtbetrag: 129,00 EUR\nRechnungsdatum: 31.02.2026\nIBAN: DE89370400440532013001')
assert 'Rechnungsbetrag brutto' not in r['fields'];assert 'Rechnungsdatum' not in r['fields'];assert 'IBAN' not in r['fields']
r=m.fields_from_text('Gutschrift\nGesamtbetrag: 119,00 EUR');assert r['fields']['Belegart']=='Gutschrift'
r=m.fields_from_text('Lieferant: Fiktive GmbH\nTeststrasse 12\n26180 Fiktivstadt\nRechnung\n1 Podest 3 Stk 299,00 897,00\n2 Podest 1 Stk 399,00 399,00\nGesamtbetrag: 1296,00 EUR')
assert len(r['items'])==2 and r['items'][0]['quantity']=='3';assert r['items'][0]['net_amount']=='' and r['items'][0]['ambiguous'] is True;assert r['fields']['Anschrift Rechnungssteller']=='Teststrasse 12\n26180 Fiktivstadt'
print('PASS parser: ambiguous totals, invalid date, invalid IBAN withheld; credit note; two uncertain items; address boundary')

# Entirely fictional re-typesetting of common PDF reading-order patterns.
text="""Bankverbindungen:
Example Bank AG Teststadt BIC: COBADEFFXXX
BUEHNENBEDARF TEST GmbH · Musterweg 22 · 12345 Beispielstadt · Tel.: 00000
Empfänger Beispielverein
Rechnung-Nr. TEST-2026-77
Rechnungsdatum: 07.09.2026
RECHNUNG
Pos. Beschreibung Menge Einheit Einzelpreis BruttoBetrag (Brutto)
3 Satz 35,90 107,70
TEST Fussset vier Füsse
1 ART0001 Testmarke
Zolltarifnummer: 00000000
3 Stück 299,00 897,00
TEST Bühnenpodest
2 ART0002 Testmarke
4 Stück 7,70 30,80
TEST Verbinder
3 ART0003 Testmarke
Summe 10 Total EUR inkl. MwSt. 1.035,50
MwSt. Netto-Betrag MwSt.-Betrag
19 % 870,17 165,33
Bitte geben Sie unsere Rechnungsnr. an: TEST-2026-77
"""
r=m.fields_from_text(text)
assert r['fields']['Rechnungsnummer']=='TEST-2026-77'
assert r['fields']['Rechnungssteller']=='BUEHNENBEDARF TEST GmbH'
assert r['fields']['Anschrift Rechnungssteller']=='Musterweg 22\n12345 Beispielstadt'
assert r['fields']['Rechnungsbetrag brutto']=='1035.50' and r['fields']['Rechnungsbetrag netto']=='870.17' and r['fields']['Umsatzsteuerbetrag']=='165.33'
assert len(r['items'])==3 and r['items'][0]['quantity']=='3' and r['items'][1]['gross_amount']=='897.00' and r['items'][1]['tax_rate']=='19'
assert r['items'][0]['uncertain'] is True
r=m.fields_from_text("""TESTSHOP · Musterweg 1 · 12345 Beispielstadt
Rechnung Nr.: TEST-88 07.09.2026
1 ART0001 Testware 1 (19 %) 45,01 € 45,01 €
2 Versandkosten
Versand (Shipment): Germany
Grundpreis: 6,90 €
1 (19 %) 6,90 € 6,90 €
Gesamt-Brutto 51,90 €
Gesamt ohne MwSt. 43,62 €
19% MWSt. (2026) (auf 43,62 €) 8,28 €""")
assert r['fields']['Umsatzsteuerbetrag']=='8.28' and r['fields']['Rechnungsbetrag netto']=='43.62'
assert len(r['items'])==2 and r['items'][1]['name']=='Versandkosten'
assert any('Positionssumme weicht' in warning for warning in r['warnings'])
print('PASS synthetic PDF blocks: supplier not bank/recipient; gross/net/VAT; item quantities/unit prices; shipment continuation; rounding mismatch retained')

r=m.fields_from_text('Rechnungsdatum: 04.04.2022\nFälligkeit: 14.04.22');assert r['fields']['Fälligkeit']=='2022-04-14'

# Fictional scan layout: no company suffix, OCR uses spaced hyphens in sender line.
scan='''Musterhandel
TESTVERSAND - Beispielweg 14 - 12345 Musterstadt
Rechnungsempfänger:
Fiktiver Käufer GmbH
Kundenweg 88
54321 Käuferstadt
Rechnung Nr.: SYN-42 07.09.2026
1 Testartikel 1 Stk 45,01 45,01
2 Versand 1 Stk 6,90 6,90
Gesamt-Brutto 51,90 EUR
Gesamt ohne MwSt. 43,62 EUR
19% MWSt. (auf 43,62 EUR) 8,28 EUR
TESTVERSAND - Inhaber Beispielperson - Beispielweg 14 - 12345 Musterstadt
'''
r=m.fields_from_text(scan)
assert r['fields']['Rechnungssteller']=='TESTVERSAND'
assert r['fields']['Anschrift Rechnungssteller']=='Beispielweg 14\n12345 Musterstadt'
assert r['fields']['Umsatzsteuerbetrag']=='8.28' and len(r['items'])==2
for marker in ['Rechnungsempfänger:', 'Lieferadresse:', 'Bill to:', 'Ship to:']:
    r=m.fields_from_text(marker+'\nKAUFENDER BETRIEB GmbH - Kundenweg 88 - 54321 Käuferstadt\nRechnung SYN-42')
    assert 'Rechnungssteller' not in r['fields'] and 'Anschrift Rechnungssteller' not in r['fields']
r=m.fields_from_text('Lieferant: Fiktiver Verkäufer\nLieferadresse:\nKundenweg 88\n54321 Käuferstadt')
assert r['fields']['Rechnungssteller']=='Fiktiver Verkäufer' and 'Anschrift Rechnungssteller' not in r['fields']
r=m.fields_from_text('Lieferant: Firma A\nLieferant: Firma B\nFirma C - Musterweg 4 - 12345 Musterstadt')
assert 'Rechnungssteller' not in r['fields']
r=m.fields_from_text('Firma A - Musterweg 4 - 12345 Musterstadt\nFirma B - Testweg 5 - 23456 Teststadt')
assert 'Rechnungssteller' not in r['fields']
r=m.fields_from_text('Unbeschrifteter Käufer GmbH\nKundenweg 88\n54321 Käuferstadt\nRechnung SYN-42')
assert 'Rechnungssteller' not in r['fields']
r=m.fields_from_text('TEST-VERSAND – Beispiel-Weg 14 – 12345 Musterstadt\nRechnung SYN-42')
assert r['fields']['Rechnungssteller']=='TEST-VERSAND'
print('PASS sender scan: hyphen letterhead; buyer/shipping blocks excluded; conflicting senders withheld; no legal-suffix guessing')

def fictional_iban(account):
    digits='12345678'+str(account).zfill(10)
    return 'DE'+str(98-int(digits+'131400')%97).zfill(2)+digits

bank_rows=[]
for index in range(1,6):
    iban=fictional_iban(index)
    bank_rows.append(f'Musterbank {chr(64+index)} BLZ 12345678 Konto {index} BIC (SWIFT): TESTDEFF00{index} IBAN: {iban}')
contact_text='''FIKTIVER SHOP - Musterweg 12 - 12345 Teststadt
Telefon: +49 (0)30 123456 - 0 · Fax: - 99 · E-Mail: rechnung@example.invalid
Rechnungsempfänger:
Kaufender Verein
E-Mail: kunde@example.invalid · Telefon: 030 999999
Rechnung SYN-90
Rechnungsdatum: 07.09.2026
Gesamtbetrag: 119,00 EUR
Bankverbindungen:
'''+ '\n'.join(bank_rows)
r=m.fields_from_text(contact_text)
assert r['fields']['E-Mail Rechnungssteller']=='rechnung@example.invalid'
assert r['fields']['Telefon Rechnungssteller']=='+49 (0)30 123456 - 0'
assert len(r['bank_accounts'])==5 and 'IBAN' not in r['fields'] and 'BIC' not in r['fields']
for index,bank in enumerate(r['bank_accounts'],1):
    assert bank=={'iban':fictional_iban(index),'bic':f'TESTDEFF00{index}','bank_name':f'Musterbank {chr(64+index)}'}
r=m.fields_from_text(contact_text+'\n'+bank_rows[0])
assert len(r['bank_accounts'])==5
r=m.fields_from_text('Lieferadresse:\nIBAN: '+fictional_iban(9)+'\nBIC: TESTDEFF999')
assert r['bank_accounts']==[] and 'IBAN' not in r['fields']
r=m.fields_from_text('IBAN: '+fictional_iban(1)+'0')
assert r['bank_accounts']==[]
r=m.fields_from_text('IBAN: '+fictional_iban(1)+' BIC: TESTDEFF001\nIBAN: '+fictional_iban(1)+' BIC: TESTDEFF002')
assert len(r['bank_accounts'])==1 and r['bank_accounts'][0]['bic']=='' and 'BIC' not in r['fields']
r=m.fields_from_text('IBAN: '+fictional_iban(1)+' / IBAN: '+fictional_iban(2)+' BIC: TESTDEFF001')
assert len(r['bank_accounts'])==2 and all(not bank['bic'] for bank in r['bank_accounts'])
r=m.fields_from_text('SHOP - Musterweg 12 - 12345 Teststadt\nE-Mail: a@example.invalid · E-Mail: b@example.invalid')
assert 'E-Mail Rechnungssteller' not in r['fields']
print('PASS supplier contact and five bank candidates: recipient excluded, deduplicated, checksum validated, ambiguous BIC/email withheld')

# Fictional packages: only the parent carries a price. Child counts describe
# its contents and must never become additional charged invoice rows.
package='''TESTLICHT GmbH · Testweg 4 · 12345 Musterstadt
Rechnungsdatum: 10.06.2021
Pos. Beschreibung Menge Einheit Einzelpreis BruttoBetrag (Brutto)
1 Stück 59,00 59,00
Test-Steuergerät
1 SYNTH001 Beispielmarke
1 Stück 120,00 120,00
Test-Lichtpaket
2.0 PACK001
2 Stück
Test-Scheinwerfer
2.1 SYNTH002
2 Stück
Test-Kabel 5m
2.2 SYNTH003 Beispielmarke
1 Stück 40,00 40,00
Test-Zusatz
3.0 PACK002
3 Stück
Test-Zubehör
3.1 SYNTH004
Summe 8 Total EUR inkl. MwSt. 219,00
MwSt. Netto-Betrag MwSt.-Betrag
19 % 184,03 34,97
Zahlungsbedingung: Klarna
'''
r=m.fields_from_text(package.replace('\n','\r\n'))
assert len(r['items'])==3 and [i['gross_amount'] for i in r['items']]==['59.00','120.00','40.00']
assert [c['quantity'] for c in r['items'][1]['components']]==['2','2']
assert [c['name'] for c in r['items'][2]['components']]==['Test-Zubehör']
assert all('gross_amount' not in c and 'unit_price' not in c for c in r['items'][1]['components'])
assert '2× Test-Kabel 5m' in r['items'][1]['name'] and 'ohne Einzelpreis' in r['items'][1]['original_line']
assert not any('Positionssumme weicht' in w or 'Keine eindeutig' in w for w in r['warnings'])
assert r['fields']['Zahlungsart']=='Klarna' and any('bestätigt keine Zahlung' in w for w in r['warnings'])
for suffix in ['\nIBAN: '+fictional_iban(42), '\n'+'\n'.join('IBAN: '+fictional_iban(i) for i in range(1,6))]:
    r=m.fields_from_text(package+suffix)
    assert r['bank_accounts'] and 'IBAN' not in r['fields']
r=m.fields_from_text(package+'\nZahlungsart: Banküberweisung')
assert 'Zahlungsart' not in r['fields'] and any('Mehrere Zahlungsarten' in w for w in r['warnings'])
r=m.fields_from_text('Zahlungsart:\nIBAN: '+fictional_iban(42))
assert 'Zahlungsart' not in r['fields']
r=m.fields_from_text(package.replace('2 Stück\nTest-Kabel 5m','2 Stück 5,00 10,00\nTest-Kabel 5m').replace('219,00','229,00'))
assert len(r['items'])==4 and len(r['items'][1]['components'])==1
print('PASS package parser: dotted parent without brand, separate packages, unpriced contents preserved without double billing; labelled payment never confirms payment or selects a footer account')

single='''Lieferadresse: Testkäufer | Kundenweg 88 | 54321 Käuferstadt
TESTDRUCK GmbH | Musterweg 7 | 12345 Beispielstadt
Rechnung Nr.: SYNTH-2025-009
Rechnungsdatum: 23.07.2025
Artikel: Test-Display inklusive Druck (PVC\ufffefrei) und Versand 42.00 €
Stück: 1 x 1 Stück
Format: 800 x 1800 mm
Sorte: Test-Banner mit Aufdruck
Seiten: 1 -seitig
Farbigkeit: 4/0 -farbig
Rechnungsbetrag netto: 42.00 €
zzgl. 19% MwSt.: 7.98 €
Rechnungsbetrag brutto: 49.98 €
Bezahlung durch: PayPal
Bezahlt: 49.98 €
Restbetrag: 0.00 €
TESTDRUCK GmbH
Musterweg 7
12345 Beispielstadt
Tel.: +49 (0) 123 / 456789
E-Mail: info@testdruck.example
'''
r=m.fields_from_text(single.replace('\n','\r\n'))
assert r['fields']['Rechnungssteller']=='TESTDRUCK GmbH' and r['fields']['E-Mail Rechnungssteller']=='info@testdruck.example'
assert r['fields']['Telefon Rechnungssteller']=='+49 (0) 123 / 456789'
assert r['fields']['Rechnungsbetrag brutto']=='49.98' and r['fields']['Rechnungsbetrag netto']=='42.00' and r['fields']['Umsatzsteuerbetrag']=='7.98'
assert r['fields']['Zahlungsart']=='PayPal' and 'bezahlt' not in r['fields']
assert len(r['items'])==1 and r['items'][0]['quantity']=='1' and r['items'][0]['unit_price']=='42.00'
assert r['items'][0]['price_basis']=='net' and r['items'][0]['gross_amount']=='49.98' and r['items'][0]['ambiguous'] is False
assert 'PVC-frei' in r['items'][0]['name'] and 'Format: 800 x 1800 mm' in r['items'][0]['original_line']
assert not any('Positionssumme weicht' in w for w in r['warnings'])
for broken in [single.replace('7.98','7.97'),single.replace('49.98','49.99'),single+'\nzzgl. 7% MwSt.: 0.00 €',single.replace('42.00 €\nStück','41.00 €\nStück')]:
    r=m.fields_from_text(broken)
    assert len(r['items'])==1 and r['items'][0]['ambiguous'] is True and r['items'][0]['gross_amount']==''
r=m.fields_from_text(single.replace('1 x 1 Stück','2 x 3 Stück'))
assert r['items'][0]['quantity']=='6' and r['items'][0]['unit_price']==''
for broken in [single.replace('1 x 1 Stück','0 x 1 Stück'), single+'\nArtikel: Zweite Testware 9.00 €\nStück: 1 Stück',single+'\nRechnungsbetrag brutto: 39.98 €']:
    r=m.fields_from_text(broken)
    assert not r['items'] or r['items'][0]['ambiguous'] is True
r=m.fields_from_text('Bestelldatum: 19.07.2025\nReferenz: 25.07.2025')
assert 'Rechnungsdatum' not in r['fields']
r=m.fields_from_text('TESTDRUCK GmbH | Musterweg 7 | 12345 Beispielstadt\nGeschäftsführer: Testperson\nE-Mail: privat@example.invalid')
assert 'E-Mail Rechnungssteller' not in r['fields']
print('PASS labelled article: exact net/VAT/gross reconciliation, included shipment, quantity factors, no averaged prices, contradictory totals withheld; sender boundary and payment state preserved')

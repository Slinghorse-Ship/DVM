# Zahlungswege und PayPal-Finanzkonto

Auftragsergänzung: PayPal ist sowohl ein Zahlungsweg an der Kasse als auch ein eigenes API-gestütztes Finanzkonto. Ein PayPal.Me-Link ersetzt keinen der beiden API-Verträge.

## Implementierte Dienstgrenzen

- `native/Pos/Register.php`: serverseitige Artikelpreise, versionierter Warenkorb, Bargeld/Rückgeld, reservierte gekaufte Wertgutscheine, Mischzahlungen, persönlicher Mitgliedervorteil als getrennte Minderung, unveränderlicher Originalbeleg, nachvollziehbarer Storno und einmalige Netto-Bargeldübernahme in die vorhandene Handkasse. Artikel brauchen eine explizite fachliche Sphäre. Ein bezahlter Wertgutschein ist kein Mitgliedervorteil.
- `native/Payments/SumUp.php`: Leser-REST-Auftrag mit beständiger Affiliate-Referenz; unabhängiger Transaktionsabruf für Leser und natives Tap to Pay. Händler, Referenz, Betrag, EUR, erfolgreicher POS-Status und geeignete Karten-Eingabeart müssen passen. Browser-Callbacks bestätigen keine Zahlung. Unsichere Versuche bleiben gespeichert und werden nicht automatisch neu ausgelöst.
- `native/Payments/PayPal.php`: OAuth, Orders v2, Capture, unabhängiger Abschlussabruf, Refund sowie Reporting/Transactions und Reporting/Balances. Zahlungsreferenzen und Händlerkennung werden geprüft. API-Zugang wird zentral verschlüsselt gespeichert.
- `src/PayPalAccount.php`: produktives PayPal-Konto auf ein eigenes bestehendes Bankkonto abbilden; vollständige Abrufe bis 31 Tage, Kontoidentität und Seitenzahlen prüfen; Bruttoumsatz und Gebühren separat in den vorhandenen dauerhaften Bankimport überführen. Erstattungen/Umbuchungen behalten die von PayPal gelieferte Richtung und Referenz. Fremdwährungen, vorgemerkte Umsätze und geänderte bereits gebuchte Quellen werden durch den bestehenden Prüfworkflow behandelt. API-Salden sind datierte Beobachtungen und überschreiben keine Buchungen.

## Quellen und lokale Nachweise

SumUp-Vertrag: offizielles Repository [sumup/sumup-openapi](https://github.com/sumup/sumup-openapi), gelesener Commit `c359e2f1291fa81bb533298c5958ab95f0ba11c4`. Rückerstattungen verwenden `/v1.0/merchants/{merchant_code}/payments/{transaction_id}/refunds`.

PayPal-Verträge: [checkout_orders_v2.json](https://github.com/paypal/paypal-rest-api-specifications/blob/main/openapi/checkout_orders_v2.json), [payments_payment_v2.json](https://github.com/paypal/paypal-rest-api-specifications/blob/main/openapi/payments_payment_v2.json), [reporting_transactions_v1.json](https://github.com/paypal/paypal-rest-api-specifications/blob/main/openapi/reporting_transactions_v1.json). Gelieferte Links werden nicht für serverseitige API-Anfragen verwendet; feste HTTPS-Ursprünge, TLS-Prüfung, Antwortgrenzen und keine Redirects.

`vereinsverwaltung/tests/native-pos.php`: 44 Prüfungen gegen neue synthetische MySQL-Fixture und injizierte API-Antworten bestanden. Keine tatsächliche Karte belastet, keine Rückzahlung ausgelöst, keine reale PayPal-Verbindung getestet. Gerätezulassung, SDK-Build und produktive Konto-Freigaben sind damit noch nicht abgenommen. Die Touch-Oberfläche und native Gerätebrücke werden anschließend verbunden und gesondert geprüft.

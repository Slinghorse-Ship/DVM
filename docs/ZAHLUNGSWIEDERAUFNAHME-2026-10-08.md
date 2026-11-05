# Zahlungs- und Rückzahlungsabgleich

Ein Gerätecallback oder eine erfolgreiche Rückzahlungs-POST-Antwort allein
bestätigt keinen gebuchten Verkauf bzw. kein abgeschlossenes Storno.

SumUp-Rückzahlungen werden unabhängig über die ursprüngliche Händlertransaktion
und ihre dokumentierten `transaction_events` geprüft: Original-ID, Referenz,
Händler, EUR, Betrag, POS-Zahlung, Rückzahlungsstatus und eindeutige Ereignis-IDs.
Kompakte `events` und detaillierte `transaction_events` werden nicht doppelt
addiert. Rückbelastungen und unvollständige Beträge bestätigen kein Storno.
Auch der normale Zahlungsabgleich erkennt das dokumentierte Feld `event_type`.

PayPal-Rückzahlungen erhalten eine beständige Request-ID sowie eigene
`invoice_id`/`custom_id`. Anschließend werden Erstattung und Original-Capture
unabhängig gelesen. Betrag, EUR, Status, Ursprungs-Capture und Referenzen müssen
passen. Eine bekannte Erstattungskennung bleibt auch bei fehlendem endgültigen
Ergebnis gespeichert. Geht schon die POST-Antwort verloren, kann der GET-Abruf
der Originalbestellung eine eindeutig zugeordnete Erstattung finden. Liefert
PayPal dort keine eindeutige Kennung, kann eine Kennung aus dem Anbieterstatus
eingegeben werden; sie wird anschließend vollständig geprüft. Die Wiederaufnahme
sendet ausschließlich GET-Anfragen und wiederholt keinen Erstattungs-POST.

Betroffene neu verkaufte Wertgutscheine werden vor der Rückzahlung gesperrt,
damit sie nicht gleichzeitig ausgegeben und erstattet werden können. Unklare
Erstattungen verhindern den Kassenabschluss. Erst ein passender unabhängiger
Nachweis beendet das Storno, stellt eingelöste Teilbeträge einmalig wieder her
und sperrt verkaufte Wertgutscheine endgültig. Der Originalbeleg bleibt erhalten;
der bestätigende Vereinszugang wird im Prüfverlauf festgehalten.

Die iOS-Brücke liest vor Tap to Pay zunächst eine unverändernde Serveranweisung
und prüft Verfügbarkeit/Aktivierung im offiziellen SDK. Erst danach wird der
beständige Zahlungsversuch gestartet. Vorbereitete, nie gestartete Versuche
können ausdrücklich verworfen werden. Begonnene/unklare Versuche können das
nicht. Eine zweite Anweisung mit geändertem Betrag, Händler oder Referenz wird
verworfen. Ein SDK-Callback bestätigt weiterhin keine serverseitige Zahlung.

## Aktueller Nachweis

29 Vertragsprüfungen mit ausdrücklich eingesetztem Testtransport, 15 MySQL-
Prüfungen zur Rückzahlungswiederaufnahme, 10 Prüfungen des nativen Server-
Vorabgleichs, 44 bestehende Kassen-/Zahlungs-/PayPal-Kontoprüfungen und 15
Browserprüfungen der befüllten Touch-Kasse bestanden. Keine echten Zahlungen.
Xcode-Build, Entitlements, SumUp-/PayPal-Kontofreigaben und Geräteabnahme bleiben
extern ungetestet. Diese Prüfungen behaupten keine TSE- oder Produktionsfreigabe.

Offizielle Verträge: [SumUp OpenAPI](https://github.com/sumup/sumup-openapi),
[PayPal Payments v2](https://github.com/paypal/paypal-rest-api-specifications/blob/main/openapi/payments_payment_v2.json),
[PayPal Orders v2](https://github.com/paypal/paypal-rest-api-specifications/blob/main/openapi/checkout_orders_v2.json),
[SumUp iOS SDK](https://github.com/sumup/sumup-ios-sdk).

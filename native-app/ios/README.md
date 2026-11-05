# Native SumUp-Brücke für die Touch-Kasse

Die Objective-C-Brücke verwendet die offiziellen Header des SumUp-iOS-SDKs (`SMPCheckoutRequest`, `foreignTransactionID`, `paymentMethod`, `checkTapToPayAvailability` und `checkoutWithRequest`). Abhängigkeit über die vom Hersteller dokumentierte SDK-Installation beziehen; keine SDK-Binaries oder privaten Affiliate-Schlüssel in dieses Projekt kopieren.

Das neue Projekt `DVM.xcodeproj` bindet den offiziellen SDK 7.2.0 und die Brücke ein; Einrichtung und Prüfgrenzen stehen in [native-app/README.md](../README.md).

Die ältere direkte Verwendung kann `KulturhusPaymentBridge` als `kulturhusPayment`-Handler der `WKUserContentController` registrieren; dabei die WKWebView-Navigation konsequent auf die konfigurierte Vereins-HTTPS-Domain beschränken. SumUp initialisieren und über dessen native Anmeldung den richtigen Händler anmelden. Bei Tap to Pay sind kompatibles iPhone, Länder-/Kontofreigabe, Entitlements und aktivierter Dienst notwendig. Im Browser findet kein NFC-Zahlungsvorgang statt.

Die Brücke bezieht Betrag, Währung, Händler und beständige Referenz mit derselben geschützten Sitzung direkt vom Server. Sie speichert den begonnenen Versuch vor dem Geräteaufruf. Ein Callback setzt keinen Verkaufsstatus; nach Geräteabschluss oder Abbruch wird dieselbe serverseitige Transaktion unabhängig abgefragt. Verlorene Antworten werden nicht durch einen neuen Geräteaufruf ersetzt.

Für Tap to Pay liest sie zunächst `native-info`, prüft die tatsächliche SDK-Verfügbarkeit
und Aktivierung und sendet erst danach `native-start`. Die zweite Serverantwort muss
dieselbe Zahlungsanweisung enthalten. Ist das Gerät nicht verfügbar, bleibt der
Versuch ungestartet. Ein solcher vorbereiteter Versuch kann ausdrücklich verworfen
und durch einen anderen Zahlungsweg ersetzt werden. Begonnene Versuche bleiben
für den unabhängigen Abgleich gesperrt.

Die Brücke ist Bestandteil des nativen App-Builds. Tatsächliche frühere Xcode-/Simulatorergebnisse und ihre Quellstände stehen in der App-Dokumentation. Ein neuer Geräte-Build für die AltStore-IPA benötigt macOS/Xcode; private Signierung, Entitlements, reales SumUp-Konto und Hardwareabnahme bleiben gesondert. Offizielle Referenz: https://github.com/sumup/sumup-ios-sdk. SDK-Header und Herstellerlizenz vor dem App-Build in der konkret bezogenen Version prüfen.

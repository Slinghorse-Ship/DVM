# Native App-Clients und noch offene Geräteabnahme

Stand: 3. November 2026. Die nativen Clients und reproduzierbaren Buildwege sind
implementiert. Die gemeinsame Geräteabnahme ist **nicht abgeschlossen**. Die
beiden App-Punkte in den Registern bleiben deshalb offen.

Aktuelle Arbeitsweise: Auf Nutzerauftrag ausschließlich lokal ohne GitHub-CLI
weiterprüfen. Alle gehosteten Workflows starten nur manuell. Frühere tatsächliche
Xcode-/Android-Ergebnisse bleiben unten an ihren Quellstand gebunden; neue
gehostete Läufe werden nicht zur Voraussetzung der weiteren Arbeit gemacht.
Der [fortsetzbare lokale Prüfweg](LOKALE-PRUEFUNGEN-OHNE-GITHUB-CI.md) ergänzt die
Softwareabnahme. Android-Gerätebedienung benötigt weiterhin eine arbeitsfähige
eigene Laufzeit; vorhandene SDKs/AVDs werden erhalten.

## Installierbare Android-Ausgaben und AltStore Classic

Generation 2026101838 ergänzt die tatsächliche App-Verteilung. Beide Android-
Varianten sind lokal neu in Debug und Release gebaut; alle vier Lint-XML-Berichte
enthalten null Error/Fatal. Die 28 tatsächlichen Android-/Shared-Quelldateien
entsprechen bytegleich dem Buildstand 3c2e8b0b. Beide Release-APKs verwenden
Zertifikat SHA-256 `ee0067ddef31dc029500196fe89b8a2c5e571b65c0fde8541b1a4bde4a6ce910`;
V2-Signaturen und 16-KB-Ausrichtung sind unabhängig geprüft.

| Datei | Byte | SHA-256 |
| --- | ---: | --- |
| DVM-Android-Standard-1.0.apk | 1765070 | `e3d8c6899dd1446aabae413f12d5edbf287366540b811a8da35c5c90b703db40` |
| DVM-Android-Terminal-1.0.apk | 45283538 | `a276c0546a166190534dfdb5d9421cbe326b33f5d3e85c91b4586b47ef6485a9` |

Acht Paket-/Schlüsselverträge bestehen. Zusätzlich verweigert der tatsächliche
Android-Signierweg einen Debug-Build und ein falsches Schlüsselpasswort ohne neue
APK; eine vorhandene signierte Originaldatei bleibt exakt. Die Schlüssel liegen
privat außerhalb Git und müssen dauerhaft geschützt gesichert werden.

Der iOS-Weg baut unter macOS das festgelegte Xcode-Projekt als unsignierte
Geräte-App und erstellt `Payload/DVM.app` in einer IPA samt SHA-/JSON-Nachweis.
Die manuelle CI sichert diese IPA künftig ebenfalls. In dieser Linux-Umgebung
liegt noch **kein neuer tatsächlicher IPA-Build** vor. Die Paketprüfungen verwenden
synthetische Mach-O-Fixtures und ersetzen keinen Xcode- oder Gerätenachweis.
AltStore Classic signiert bei der Installation mit dem Apple-Konto; eine
unsignierte Verteilung ersetzt diese Signierung nicht.

[Installations- und Buildanleitung Deutsch/Englisch](../native-app/RELEASE-INSTALLATION.md).
Die unten dokumentierten tatsächlichen älteren Xcode-/Geräteergebnisse behalten
ihren jeweiligen Quellbezug. Keine neue gehostete Action wurde gestartet.

## Aktueller tatsächlicher Prüfstand – 3. November 2026

| Weg | Nachgewiesenes Ergebnis |
| --- | --- |
| iPhone und iPad | App-Lauf `41585515296` (historischer Lauf außerhalb dieses öffentlichen Repositorys), Quellstand `97c8a6ee`: unsignierter iOS-Geräte-Release-Build und je sechs erfolgreiche gehostete XCTest-Methoden auf iPhone 17 Pro und iPad Pro 13-inch (M5), iOS 26.2. Keine Fehler oder ausgelassenen Methoden. |
| Sprache und Eingabekorrektur | Beide tatsächlich gebündelten Sprachpakete, fünf lokalisierte Datenschutzhinweise und der echte UIKit-Dialog mit erhaltenen ungültigen Originaleingaben sind geprüft. Die Bildanhänge beider Geräte aus den Ergebnisbundles wurden unverändert ausgelesen und visuell geprüft. |
| Android-Build | Lauf `41585096768`, Quellstand `e162c119`: Standard und Terminal in Debug und unsigniertem Release gebaut; Lint und Debug-Signaturen erfolgreich. Android-/gemeinsame App-Quellen sind bis `97c8a6ee` unverändert. |
| Android-Bedienlauf | Die frühere 12-GB-Standardpartition ist für das eigene neue Testgerät auf 2048 MiB begrenzt. Der nächste Job `125752966591` wurde bereits vor Runner-/Schrittstart von GitHub wegen Kontozahlungen beziehungsweise Ausgabenlimit gesperrt. Deshalb liegt noch kein tatsächlicher erfolgreicher Boot-/DE-/EN-End-to-End-Nachweis mit dieser Korrektur vor. |

Originalmeldung des nicht gestarteten Android-Jobs:

> The job was not started because recent account payments have failed or your spending limit needs to be increased. Please check the 'Billing & plans' section in your settings

CLI, API und Push funktionieren. Wiederholtes Starten, Neuinstallation der CLI oder
eine erneute SDK-/AVD-Einrichtung in der bestehenden Cloud beheben diese Kontosperre
nicht. Bereits laufende Prüfungen werden nicht abgebrochen. Es werden keine
Ausgabenlimits oder Zahlungsdaten automatisch geändert. Physische Geräte,
Produktivsignierung, Kamera-/Druckerwege und reale freigegebene SDK-Zahlungswege
bleiben gesondert abzunehmen.

## Implementierte Software

- Android für Smartphones/Tablets ab API 26, Ziel-/Build-API 36. Standardvariante
  und optionale Terminalvariante mit offiziellem SumUp-SDK 7.1.0.
- iPhone/iPad ab iOS/iPadOS 17 mit vollständigem Xcode-Projekt, App-/Scene-Lebenszyklus,
  Origin-XCTest-Ziel, eigenen Produkticons und Privacy-Manifest. Offizieller
  SumUp-SDK auf 7.2.0 und Herstellerrevision festgelegt.
- Eigene Vereinsadresse, HTTPS- und TLS-Prüfung, getrennte Browser-/Appnavigation,
  sichere QR-Kamerafreigabe, native Datei-/Fotoauswahl, Drucken, Byte-erhaltender
  Export aus Blob-/Dateidownloads, OS-Teilen und Speichern. Keine festen Konten.
- Native Zurück-/Aktualisieren-Aktionen berücksichtigen ungespeicherte Eingaben.
  Drehung und Android-Systemschriftwechsel erhalten die Webansicht. Aktualisieren
  und Wiederaufnahme senden keine ursprüngliche POST-Anfrage erneut.
- Native lokale Chat-Hinweise nach ausdrücklicher Gerätefreigabe; der Klick behält
  die ursprüngliche Chatzuordnung. Dies ist kein APNs-/FCM-Hintergrundkanal für
  geschlossene Apps.
- Kassenanbindung verwendet die ursprüngliche geschützte Sitzung und verbindliche
  Serveranweisung. Händler, EUR, Betrag und Referenz müssen übereinstimmen.
  Dauerhafte Startmarkierung vor dem SDK verhindert einen blinden Geräte-Neustart.
  SDK-Callbacks markieren keinen Verkauf als bezahlt. Abgleich bleibt serverseitig.
  Android Tap to Pay wird vor einem Geräteversuch als nicht verfügbar angezeigt;
  der implementierte Android-SDK unterstützt den Kartenleserweg. iPhone Tap to Pay
  benötigt zusätzlich Konto-/Geräte-/Länderfreigabe und Apple-Entitlements.

Einrichtung und genaue Grenzen: [App-README](../native-app/README.md).
SDKs und private Signier-/Zugangsdaten werden nicht in Git kopiert.

## Ergänzter automatisierter Prüfweg – 3. November 2026

Der neue GitHub-Workflow **Native apps** richtet einen tatsächlichen Xcode-Build
mit iPhone-/iPad-Simulatorprüfungen und einen beschleunigten Android-Lauf ein.
Zusätzlich werden die Geräte-Release-Varianten ohne Produktivsignierung gebaut.
Beide Wege verwenden die bereits festgelegten Hersteller-SDKs; Quellprüfung oder
ein vorhandener Debug-Build können einen fehlgeschlagenen Lauf nicht ersetzen.
APK-Signaturen/Prüfsummen, echte Testresultate, Bilder und Systemprotokolle werden
als Artefakte gesichert. Ausbleibende Geräte, übersprungene XCTest-Methoden und ein
unvollständiger Android-Lauf führen nicht zu einer erfolgreichen Abnahme.

Der tatsächliche korrigierte Lauf `41583629849` für `54aa2ea0` bestätigt den
unsignierten iOS-Geräte-Release-Build sowie je vier erfolgreiche gehostete XCTest-
Methoden auf iPhone 17 Pro und iPad Pro 13-inch (M5), jeweils iOS 26.2. Die
Diagnoseartefakte wurden erfolgreich gesichert. Der Android-End-to-End-Nachweis
bleibt offen. Linux kann selbst keinen Xcode-Build bestätigen; die bisherige lokale Android-Umgebung wird wegen
des nachgewiesenen Laufzeitfehlers nicht erneut aufgebaut oder als bestanden
gewertet. Die neue Prüfung läuft isoliert auf dem jeweiligen GitHub-Läufer.
Produktivsignierung, physische Geräte und reale Zahlungswege sind dadurch nicht
freigegeben. Ausführliche Befehle: [App-README](../native-app/README.md).

## Erster tatsächlicher Xcode-Lauf – 3. November 2026

Der App-Lauf `41582791283` für `97a96d78` bestätigt mit Xcode 16.4, iPhoneOS SDK
18.5 und der festgelegten SumUp-Revision erstmals einen erfolgreichen
unsignierten Geräte-Release-Build. Beide Simulator-Testziele scheiterten dagegen
beim Linken: Die Anwendung versteckte im Debug-Build die Klasse `DVMOrigin` vor
dem gehosteten Testbundle. Der Projektgenerator exportiert die Symbole jetzt nur
für Debug und aktiviert die vorgesehene Testbarkeit. Die Release-Konfiguration
bleibt dabei unverändert. Der korrigierte Lauf bestätigt anschließend die vier
Methoden auf beiden tatsächlichen Simulatorzielen ohne Fehler oder Auslassung.

Zusätzlich verweigerte der Artefakt-Upload den nicht normalisierten `..`-Pfad.
Die eigenen Ergebnis- und Toolverzeichnisse liegen jetzt ohne relative
Pfadbestandteile neben dem Checkout. Produktionssignierung, physische Geräte und
Zahlungswege sind dadurch weiterhin nicht abgenommen. Der laufende Android-Test
wird für diese Korrekturen nicht abgebrochen.

## Korrigierbare iOS-Vereinsadresse

Die Vereinswahl validiert die Adresse bereits während der Eingabe. Ungültige
Angaben bleiben im geöffneten Dialog mit lokalisierter Erklärung erhalten;
Öffnen ist erst bei gültiger Adresse verfügbar. Die erfolgreiche Vereinswahl und
die bisherigen Origin-/TLS-Prüfungen bleiben erhalten. Ein neuer gehosteter
UIKit-XCTest bedient das echte Textfeld, prüft ursprüngliche ungültige Eingaben,
Korrektur und unveränderte Einstellungen und bewahrt ein tatsächliches Bildschirm-
bild auf. Er sendet keine Anfrage. Der tatsächliche Lauf `41585515296` bestätigt auf
beiden Simulatorfamilien jeweils sechs Methoden ohne Fehler oder Auslassung,
einschließlich dieser UI-Korrektur. Die tatsächlichen Bildanhänge zeigen auf
iPhone und iPad einen lesbaren unverzerrten Dialog.

## Tatsächlicher Android-Startfehler und begrenztes Testgerät

Der abgeschlossene Job `125745246189` im Lauf `41583629849` bestätigt beide
Debug-/Release-Builds, Lint, gültige Debug-Signaturen und nutzbares KVM. Sein
Emulatorprotokoll nennt die genaue Startursache: Das Pixel-Profil verlangt eine
12-GB-Datenpartition, verfügbar sind 3133,20 MB. Es liegt kein fehlgeschlagener
Manager- oder WebView-Test vor; dieser wurde noch nicht erreicht.

Nur das frisch erzeugte eigene CI-Gerät `dvm-api36` erhält jetzt 2048 MiB
Datenpartition ohne zusätzliche SD-Karte. Vorhandene SDKs, Caches und Geräte werden
nicht gelöscht. Die Konfiguration prüft ihre Gerätekennung und mindestens 2,5 GiB
freien Platz; unbekannte Einstellungen bleiben erhalten. Der nachfolgende Android-Job wurde vor seinem Start durch die oben dokumentierte
GitHub-Kontosperre abgewiesen. Neuer Emulatorstart und vollständiger App-Lauf
bleiben deshalb ungeprüft.

## Native Bedienung Deutsch/Englisch

89 feste Texte werden aus einer gemeinsamen DE-/EN-Quelle in Android-Ressourcen,
iOS-Sprachpakete und die gemeinsame native Browserkomponente erzeugt. Native
Navigation, Vereinswahl, Export/Teilen, Eingabeschutz, Wiederaufnahmehinweise,
Zahlungsdialoge und die fünf iOS-Berechtigungsbeschreibungen sind eingeschlossen.
Die App-Sprache folgt dem Gerät beziehungsweise dessen App-Einstellung; die
Managerseiten behalten die persönliche Kontosprache. Originalnachrichten,
Dateibytes, Providertexte und Zahlungsdaten bleiben erhalten.

40 Chromium-Komponentenbedingungen bestehen für beide Sprachen und beide
Transportzweige, einschließlich unverändertem mehrteiligem Export und
Fehler-/Abbruchwegen. Dieser Transport ist simuliert. Der inverse Quellvergleich
bestätigt die unveränderte Logik der Export-/Zahlungsbrücken; die Ressourcenerzeugung
ist deterministisch. Der neue gehostete XCTest prüft beide tatsächlich gebündelten
Sprachen und alle Datenschutztexte. Der installierte Android-Test prüft DE und EN
mit je mindestens 13 Bedingungen einschließlich Sprachwechsel ohne Neuladen der
Webansicht. Der tatsächliche Xcode-Lauf `41585515296` bestätigt beide Sprachpakete und
die UIKit-Korrektur. Android-Build/Lint sind für den unveränderten App-Quellstand
bestätigt. Der installierte DE-/EN-Bedienlauf bleibt wegen der GitHub-Kontosperre
offen; ein Komponenten- oder Buildnachweis ersetzt ihn nicht.

## Frühere lokale Ausgangsprüfungen vom 15. Oktober 2026

- Reproduzierbares Android-Setup erneut auf dem vorhandenen privaten Cache
  ausgeführt; vorhandene Daten/Schlüssel erhalten. Hersteller-Prüfsummen aktiv.
- Beide damaligen Android-Varianten gebaut und vollständige Android-Lint-Ziele
  erfolgreich: 95 Gradle-Aufgaben, 0 Lintfehler je Variante. Je zwei Hinweise
  betreffen eine neuere Gradle-Version und das benötigte JavaScript im WebView.
  Keine Lintregeln/Prüfungen wurden abgeschaltet.
- Buildlog SHA-256:
  `7b7d4e50499c2d8c71037242e8025433b64d825b9fbf717d34b47a4046af120b`.
- Damals geprüfte Standard-Debug-APK: 2.280.450 Byte,
  SHA-256 `9c7650d73860abf34e5edad887dbab93cc0006fdde449fb1481e7eb91c366c02`.
- Damals geprüfte Terminal-Debug-APK: 50.412.461 Byte,
  SHA-256 `010d66cbf8b95c906cdc2bb92de7050a65beca8d50243fde3d2c39b07f063e55`.
  Beide Signaturen mit den offiziellen Android-Buildtools geprüft. Die drei
  enthaltenen ARM64-Bibliotheken der Terminalvariante haben mindestens 16-KB-
  Segmentausrichtung; die Standardvariante enthält keine ARM64-Nativbibliotheken.
- Java-Originpolicy: 37 tatsächlich ausgeführte Bedingungen.
- Gemeinsame JavaScript-Komponente: 20 Chromium-Bedingungen, darunter beide
  Transportpfade, Byte-Erhaltung über mehrere Chunks, direkte Chatzuordnung,
  Nonce-Abweisung sowie Abbruch/Fehlerweitergabe bei unvollständigen Exporten.
  Der native Transport ist bei diesem Komponententest ausdrücklich simuliert.
  Log-SHA-256 `97bedf550c007b6dff63db5524b1957aa7ee83885652c7d42b71961f7e99ce95`.
- Angrenzende Kassen-/Ticket-/Gutscheinwege: 44 POS-Bedingungen sowie 49 gemeinsame
  Verkaufsbedingungen einschließlich 99 tatsächlicher Browserbedingungen und
  echter synthetischer Kameraframes; beide Gruppen gemeinsam erfolgreich.
  Log-SHA-256 `9791050f36d3ead3be2013778b5ee88eb98f51004093f340d07973a95459ad3b`.
- Deterministisches Xcode-Projekt mit einem echten OpenStep-Parser gelesen:
  69 Objekte, App-/Testziel und festgelegte offizielle SDK-Abhängigkeit.
  Alle App-/Privacy-Plists und das opake RGB-Icon geprüft.

## Noch nicht nachgewiesen

Die installierte Debug-App wurde im eigenen AOSP-API-36-Emulator gestartet.
Der tatsächliche geschützte End-to-End-Lauf scheiterte vor der Vereinsanmeldung:
System/Quickstep reagierten nicht; außerdem ist ein nativer SIGTRAP innerhalb
`libwebviewchromium.so` dokumentiert. Die Cloud hat kein `/dev/kvm`; auch der
zweite Lauf mit vier virtuellen CPUs, 4 GiB RAM und deaktiviertem Vulkan liefert
keine belastbare App-Bedienabnahme. Zusätzlich wurde die unveränderte mitgelieferte
`org.chromium.webview_shell` mit `about:blank` gestartet: identischer SIGTRAP in
`libwebviewchromium.so`, identischer Build-Identifier und Offset `0x249c840`.
Der Fehler ist damit auch außerhalb des DVM-Codes reproduziert. Eine funktionierende
Android-Laufzeit bleibt erforderlich. Diese fehlgeschlagenen Läufe bleiben rot.
Daraus werden keine bestandenen Smartphone-/Tablet-/Kamera-/Druckerprüfungen
abgeleitet. Die Ursache wird separat von einem normalen Buildnachweis behandelt.

Der Xcode-Build und die vier Origin-Tests beider Simulatorfamilien sind auf
GitHub bestätigt. Eine vollständige UIKit-/WebKit-Bedienprüfung und physische
iPhone-/iPad-Geräteabnahme ersetzen diese begrenzten Simulatorprüfungen nicht. SDK-
Anmeldung, echtes Lesegerät, Tap to Pay, Drucker, native RAW-/HEIC-Fotoauswahl und
Signierung/Entitlements sind ebenfalls noch nicht abgenommen. Keine realen
Zahlungen, Steuerübermittlungen oder Store-Veröffentlichungen wurden ausgelöst.

Der nächste unabhängige Softwareteil ist die abschließende gemeinsame Statusseite.
Die noch erforderlichen Geräte- und ERiC-Originalnachweise bleiben ausdrücklich
im Gesamtauftrag offen. Es wird keine Gesamtquote von 100 Prozent behauptet.

## Ergänzende Cloud-Geräteprüfung am 15. Oktober 2026

Zusätzlich wurde ein zweites offizielles AOSP-Systemimage (API 35, Revision 2,
x86_64) in einem eigenen Emulator eingerichtet; das API-36-Gerät und seine Daten
wurden erhalten. API 35 meldet den abgeschlossenen Systemstart, liefert aber schon
für die unveränderte `org.chromium.webview_shell` mit `about:blank` einen
Startzeitüberschritt. Anschließend ist kein verlässlicher Shell-Prozess verfügbar;
die Systemprotokolle enthalten zudem Launcher-/Bluetooth-Abstürze. Die DVM-App
wurde in diesem ergänzenden Versuch nicht ausgeführt. Daraus folgt weder eine
bestandene App-Abnahme noch eine festgestellte DVM-Regression unter API 35.
Beide eigenen Emulatorprozesse wurden beendet; die privaten AVD-Daten bleiben.
Ein belastbarer Xcode-/Gerätenachweis bleibt erforderlich. APK-/Lint-Nachweise
oben gelten weiterhin; Hintergrundzustellung über APNs/FCM wird nicht behauptet.

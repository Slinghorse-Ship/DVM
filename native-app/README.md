# DVM für iPhone/iPad und Android

Native Clients öffnen den ausgewählten Verein und verwenden dieselben geschützten
Managerseiten, Rollen, Daten und serverseitigen Abläufe. Es werden keine festen
Vereinsadressen oder Anmeldeinformationen eingebaut. Standardmäßig ist eine
HTTPS-Adresse ohne Pfad erforderlich; ausschließlich Debug-Builds erlauben lokale
HTTP-Testadressen. Zertifikatsfehler werden nicht übergangen.

Die Apps enthalten native Navigation, Eingabeschutz beim Verlassen, Kamera-
Freigabe für QR-Codes, Dateiauswahl, Drucken, private Dateiexporte und OS-Teilen/
Speichern. Exporte aus dem Bildeditor werden auch als Blob übernommen. Private
Dateien bleiben im App-Cache, bis der Nutzer einen Speicher-/Teilenweg auswählt.
Die Webansicht behält beim Drehen ihren Zustand. Ein Aktualisieren sendet keine
ursprüngliche POST-Anfrage erneut. Nach einem unterbrochenen Vorgang muss der
serverseitig gespeicherte Stand geprüft werden.

Chat-Hinweise verwenden nach ausdrücklicher Gerätefreigabe lokale OS-Hinweise.
Der Klick führt zum ursprünglichen Chat. Dies benötigt eine geöffnete Anwendung;
eine geschlossene App besitzt damit noch keinen APNs-/FCM-Hintergrundkanal.

## Sprache / Language

Die nativen Menüs, festen Fehlertexte und Datenschutzhinweise unterstützen Deutsch
und Englisch. Die Geräte-/App-Sprache bestimmt die nativen Dialoge. Android 13+
bietet beide Sprachen zusätzlich in den App-Einstellungen an. Die Managerseiten
verwenden weiterhin die persönliche Sprache des angemeldeten Kontos. Ein
Android-Sprachwechsel erhält die offene Webansicht und ihre Eingaben.

**English:** Native menus, fixed error messages and privacy explanations support
German and English through the device/app language. Android 13+ also lists both
languages in app settings. Manager pages follow the signed-in account's language.
Changing the Android app language keeps the current web document and inputs.
Messages, file contents, provider responses and payment references retain their
original text.

Feste Texte werden ausschließlich in `shared/interface-messages.json` gepflegt:

```sh
python3 native-app/tools/interface-resources.py
python3 native-app/tools/interface-resources.py --check
python3 native-app/tools/xcode-project.py
```

Ungültige Vereinsadressen bleiben im iOS-Dialog korrigierbar. Öffnen wird erst bei
einer gültigen Adresse verfügbar; während der Korrektur wird nichts gespeichert
oder an den Server gesendet.

Die CI prüft die erzeugten Android-/iOS-Ressourcen. Xcode testet zusätzlich beide
Sprachpakete und alle fünf lokalisierten Berechtigungsbeschreibungen im wirklichen
App-Bundle. Der Android-Gerätelauf bedient beide Sprachen einschließlich
Sprachwechsel, Drehung, Tabletansicht und unverändertem Originalexport.

## Apps installieren und verteilen

Signierte Android-APKs und der unsignierte IPA-Paketweg für AltStore Classic sind
in [Release und Installation](RELEASE-INSTALLATION.md) beschrieben. AltStore
signiert beim Installieren mit dem eigenen Apple-Konto; für die ursprüngliche
iOS-Kompilation wird weiterhin ein Mac mit Xcode benötigt.

## Android bauen

Linux x64, Python 3.11+, curl und tar; Quellcode aus diesem Checkout:

```sh
python3 native-app/tools/android.py setup --accept-android-licenses
python3 native-app/tools/android.py policy-test
python3 native-app/tools/android.py build
python3 native-app/tools/android.py release-check
```

`--cache /privater/pfad` oder `DVM_MOBILE_TOOLS` wählen einen vorhandenen
Toolcache. Das Werkzeug erhält vorhandene Laufzeitdaten und Schlüssel. JDK und
Gradle werden mit SHA-256, Android-Tools anhand des originalen Google-Prüfwerts
verifiziert. Die SDK-Verwaltung prüft ihre Herstellerpakete. Proxy und
Zertifikatsprüfung bleiben aktiv. `--emulator` installiert zusätzlich die
AOSP-API-36-Testumgebung, erstellt/startet aber kein fremdes Gerät und löscht
keine Gerätedaten. Ein arbeitsfähiger Emulator benötigt geeignete Hostressourcen.

Android Studio oder andere Hosts können den geprüften Gradle-Wrapper mit JDK 21
und Android SDK 36 verwenden:

```sh
cd native-app/android
./gradlew assembleStandardDebug lintStandardDebug
./gradlew assembleTerminalDebug lintTerminalDebug
```

Die Standardvariante benötigt keinen Zahlungs-SDK. Die Terminalvariante bindet
den offiziellen SumUp-Android-SDK 7.1.0 ein. Beide nutzen dieselbe App-ID und sind
Alternativen, keine getrennten Vereine. Die Affiliate-Zuordnung kann außerhalb Git
als Gradle-Eigenschaft `dvmSumUpAffiliateKey` konfiguriert werden. Ohne diese
Zuordnung startet kein SumUp-SDK. Keine Zugangstokens, Händlerpasswörter oder
Signierschlüssel in Quellcode/Gradle-Dateien eintragen.

Die Ausgaben liegen unter `android/app/build/outputs/apk/{standard,terminal}/debug/`.
Debugsignierung ist eine Entwicklungsprüfung, keine Store-Veröffentlichung.

## iPhone und iPad bauen

`ios/DVM.xcodeproj` und das gemeinsame Scheme `DVM` mit aktuellem Xcode öffnen.
Das Projekt enthält App, XCTest-Originprüfungen, Privacy-Manifest und beide
Gerätefamilien. Der offizielle SumUp-iOS-SDK ist auf 7.2.0 einschließlich Revision
festgelegt. Private Team-/Signierungsdaten bleiben in Xcode oder außerhalb Git.
`DVM_SUMUP_AFFILIATE_KEY` ist eine optionale Build-Einstellung; ohne Zuordnung wird
der SDK nicht initialisiert. Der iOS-Simulator benötigt keinen privaten
Produktivsignierschlüssel. Auf einem Mac beispielsweise:

```sh
xcodebuild -project native-app/ios/DVM.xcodeproj -scheme DVM \
  -destination 'platform=iOS Simulator,name=iPhone 17' test
```

Den tatsächlich installierten Simulator wählen und auch ein iPad prüfen. Die
Deterministik des Projekts lässt sich mit `python3 native-app/tools/xcode-project.py`
wiederherstellen; dieses Werkzeug enthält keine privaten Build-Einstellungen.

## Kasse und Grenzen

Beide Zahlungspfade übernehmen Betrag, EUR-Währung, Händler und beständige
Referenz aus dem geschützten Serverablauf. `native-info` prüft vor dem Beginn;
`native-start` liefert dieselbe verbindliche Anweisung. Weiterleitungen übernehmen
keine authentifizierten Cookies. Ein begonnener Versuch wird lokal markiert;
Geräte-Callbacks bestätigen keine Zahlung. Danach wird die Kasse per GET geöffnet
und derselbe Versuch unabhängig auf dem Server geprüft.

Die Android-Terminalvariante unterstützt den offiziellen Kartenleserweg. Android
Tap to Pay gehört zu einem separaten Anbieter-SDK und wird hier nicht als
verfügbar ausgegeben oder gestartet. iPhone Tap to Pay prüft die tatsächliche
SDK-Verfügbarkeit vor `native-start`; Land/Konto, kompatibles iPhone und Apples
Entitlements müssen zusätzlich freigegeben sein. iPad/Kartenleser, Drucker,
Kamera, RAW-/HEIC-Auswahl und echte Anbieterzugänge benötigen Geräteabnahme.
Die gehosteten SumUp-/Stripe-/PayPal-Wege verwenden den Systembrowser und die
bestehende unabhängige Serverbestätigung.

## Prüfungen

`tests/native-client-browser.mjs` prüft die JavaScript-Komponente in echtem Chromium
mit ausdrücklich simuliertem nativen Transport. Das ist keine Geräteabnahme.
`tests/OriginPolicyTest.java` prüft die tatsächlich verwendete Java-Originpolicy.
Der optionale echte Android-Test hängt an der vorhandenen geschützten,
persönlich authentifizierten und ausschließlich synthetischen Manager-Fixture:

```sh
DVM_ANDROID_APP_BROWSER=1 DVM_ANDROID_OWNED_EMULATOR=synthetic-only \
DVM_ANDROID_ADB=/privater/sdk/pfad/platform-tools/adb \
bash tools/ci/local-native.sh --core native-manager-controls
```

Dieser Lauf verlangt den eigenen AVD `dvm-api36` und eine bereits installierte
aktuelle APK. Er verwendet reale native Bedienelemente und die tatsächliche
WebView; er übernimmt keine Entwicklungskonten. Ursprüngliche Geschäftstabellen
und Schlüssel müssen bytegleich bleiben. Eine blockierte Emulator-Systemoberfläche
ist ein fehlgeschlagener/unvollständiger Test und wird nicht als Erfolg ausgegeben.

## Automatisierte App-Abnahme

Der Workflow **Native apps** steht ausschließlich zum manuellen Start bereit.
Die automatische Ausführung ist wegen der aufgebrauchten Quote auf Nutzerauftrag
pausiert. Die [lokale Softwareabnahme](../docs/LOKALE-PRUEFUNGEN-OHNE-GITHUB-CI.md)
benötigt keine GitHub-CLI oder Actions. Ein später bewusst gestarteter App-Workflow
führt die folgenden zwei getrennten Prüfungen aus.
Der macOS-Läufer baut das unveränderte Xcode-Projekt gegen die festgelegte SDK-
Revision, erstellt einen unsignierten Geräte-Release-Build und führt die vier
Origin-XCTest-Methoden, den gebündelten Sprachressourcentest und die tatsächliche
UIKit-Eingabekorrektur auf je einem verfügbaren iPhone- und iPad-
Simulator aus. Fehlende Simulatoren, übersprungene Tests oder eine andere SDK-
Revision lassen den Lauf fehlschlagen. Ergebnisse und Xcode-Bundles bleiben als
Prüfartefakte erhalten. Ein Simulatornachweis ist keine physische Geräteprüfung.

Der Linux-Läufer verlangt funktionsfähige KVM-Beschleunigung. Er verwendet einen
neuen privaten Toolcache und ausschließlich den eigenen AVD `dvm-api36`. Beide
Debug- und unsignierten Release-Varianten werden gebaut und mit Android Lint
geprüft. Debug-APK-Signaturen und alle APK-Prüfsummen werden erfasst. Die
Standard-App durchläuft anschließend den bestehenden geschützten Manager-Test
mit echten nativen Bedienelementen und echter WebView. Bilder, Systemprotokoll,
Ergebnisse und APKs werden gesichert. Ein unvollständiger oder abgestürzter
Emulatorlauf bleibt fehlgeschlagen; ein reiner Komponententest ersetzt ihn nicht.

Die CI benötigt keine Affiliate-, Zahlungs-, Store- oder Produktivsignierschlüssel.
Sie führt keine echte Zahlung aus und veröffentlicht keine App. Tatsächliche
Ergebnisse stehen am jeweiligen GitHub-Lauf; die Einrichtung des Workflows allein
ist kein bestandener App-Build. Private Signierung, Kamera, Drucker, tatsächliche
iPhone-/iPad-/Android-Geräte sowie freigegebene Zahlungs-SDK-Wege bleiben gesondert
abzunehmen.

**English:** The Native apps workflow checks the real locked Xcode project, an
unsigned iOS device release build and all Origin XCTest methods, bundled language resources and actual UIKit input
correction on an available
iPhone and iPad simulator. Android requires working KVM, builds and lints both
flavors in debug and unsigned release, verifies debug signatures, and runs the
installed standard app against its own authenticated synthetic manager. Missing
devices, skipped tests and incomplete emulator runs fail the job. Logs, result
files, screenshots and APKs are retained. Configuration alone is not a successful
build. Physical devices, production signing and real payment hardware remain
separate acceptance steps; this workflow performs no real payment or publication.

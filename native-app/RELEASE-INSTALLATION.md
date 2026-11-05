# Android und iOS über AltStore Classic

Die Apps verwenden den eingerichteten Vereinsmanager unter der eigenen HTTPS-Adresse.
Sie enthalten keine Vereinskonten oder festen Serveradressen. Smartphone und Tablet
werden unterstützt. Die Funktionen und Geräteprüfgrenzen stehen in der README.

## Android installieren

Die signierte `DVM-Android-Standard-1.0.apk` herunterladen und auf dem Gerät öffnen.
Android verlangt gegebenenfalls einmalig die Erlaubnis, Dateien aus dem verwendeten
Browser beziehungsweise Dateimanager zu installieren. Anschließend die eigene
Vereinsadresse eingeben und mit dem persönlichen Managerkonto anmelden.

Die optionale Terminalvariante enthält zusätzlich den offiziellen SumUp-SDK.
Beide Varianten besitzen dieselbe App-ID und denselben Release-Schlüssel; sie
ersetzen einander. Für normalen Managerzugriff genügt Standard. Die Android-
Geräte-/Zahlungsabnahme muss auf unterstützter echter Hardware abgeschlossen werden.

Updates mit demselben privaten Release-Schlüssel signieren. Keystore und Passwort
getrennt und geschützt sichern; ein verlorener Schlüssel lässt sich für vorhandene
Installationen nicht durch einen neuen ersetzen. Einen früheren Debug-Build mit
anderem Schlüssel kann Android nicht direkt durch den Release-Build ersetzen.
Vor einer erforderlichen Deinstallation zunächst lokale Exporte sichern.

## Android-Release selbst erstellen

```sh
python3 native-app/tools/android.py setup --cache /privat/mobile-tools --accept-android-licenses
python3 native-app/tools/android.py release-check --cache /privat/mobile-tools
```

Einmalig eine private Passwortdatei mit mindestens 16 zufälligen Zeichen außerhalb
des Checkouts und Dateirecht 0600 anlegen. Dann einmalig den Schlüssel erstellen:

```sh
python3 native-app/tools/android-release.py key-create \
  --keystore /privat/signierung/dvm-release.p12 \
  --password-file /privat/signierung/passwort.txt
```

Das Werkzeug ersetzt keine vorhandenen Schlüssel. Jede Release-APK wird vor der
Ausgabe als DVM-Paket ohne Debug-Freigabe erkannt, auf 16-KB-Ausrichtung geprüft
und mit dem ausgewählten Zertifikat unabhängig verifiziert:

```sh
python3 native-app/tools/android-release.py sign --tools /privat/mobile-tools \
  --input native-app/android/app/build/outputs/apk/standard/release/app-standard-release-unsigned.apk \
  --output /privat/ausgabe/DVM-Android-Standard-1.0.apk --flavor standard \
  --keystore /privat/signierung/dvm-release.p12 \
  --password-file /privat/signierung/passwort.txt
```

Für Terminal `standard` durch `terminal` ersetzen und denselben Keystore verwenden.
Die Ausgabe enthält APK, SHA-256-Datei und ein JSON-Protokoll mit Paketversion,
Zertifikatsfingerabdruck und ursprünglichem Eingabehash. Passwort und Schlüssel
werden weder in Argumenten noch im Protokoll veröffentlicht. Die VersionCode- und
versionName-Felder in `android/app/build.gradle` vor künftigen Updates erhöhen.

## iPhone/iPad: IPA bauen

Ein Mac mit Xcode und den Voraussetzungen aus der README ist erforderlich:

```sh
python3 native-app/tools/ios-release.py build --artifacts /privat/DVM-iOS-Release
```

Das Werkzeug baut das vorhandene Projekt für echte iOS-Geräte in Release, erhält
die festgelegte SDK-Version und erzeugt `DVM.ipa` mit `Payload/DVM.app`, SHA-256
und JSON-Protokoll. Private Apple-Teamdaten sind dafür nicht nötig. Ein bereits
kompilierter Geräte-Build kann auch auf einem anderen Rechner verpackt werden:

```sh
python3 native-app/tools/ios-release.py package \
  --app /privat/DeviceRelease/Build/Products/Release-iphoneos/DVM.app \
  --output /privat/ausgabe/DVM.ipa
```

Simulator-Builds, eingebettete Simulator-Frameworks, Debug-HTTP-Freigaben,
unaufgelöste Versionsfelder, fehlende Sprachpakete und aus dem App-Bundle
führende Links werden abgelehnt. Vorhandene Ausgaben bleiben erhalten.
Info.plist enthält die App- und Buildversion; diese vor neuen iOS-Updates erhöhen.
Ein erfolgreiches Paketvertrags-Testfixture ist kein Nachweis eines Xcode-Builds.

## IPA mit AltStore Classic installieren

1. AltServer auf dem eigenen Mac/Windows-Rechner einrichten und AltStore Classic
   nach dessen Anleitung auf dem iPhone/iPad installieren.
2. Die tatsächlich gebaute `DVM.ipa` auf das Gerät übertragen, in AltStore Classic
   unter **My Apps → +** auswählen und mit dem eigenen Apple-Konto signieren lassen.
3. Falls iOS es verlangt, Entwicklervertrauen und Entwicklermodus in den
   Systemeinstellungen gemäß AltStore-Anleitung freigeben.
4. DVM öffnen, die Vereins-HTTPS-Adresse wählen und persönlich anmelden.

Die IPA darf unsigniert verteilt werden. AltStore Classic signiert sie bei der
Installation; ein Apple-Konto und AltServer bleiben erforderlich. Bei kostenlosen
Apple-Entwicklerkonten gelten üblicherweise sieben Tage Gültigkeit und Apples
Begrenzungen aktiver Apps/App-IDs. Rechtzeitig über AltStore aktualisieren.
Kontodaten ausschließlich bei Apple/AltStore eingeben. Zahlungsdienste, Tap to Pay
und Wallet benötigen gegebenenfalls zusätzliche Anbieter-/Apple-Freigaben;
AltStore ersetzt diese nicht.

## English

Android: install the signed standard APK, allow installation from the selected
browser/file manager if Android requests it, then enter your club's HTTPS origin
and sign in. The terminal flavor adds the SumUp SDK and replaces the standard app.
Keep the persistent signing key and password securely backed up for future updates.

iOS/iPadOS: run `ios-release.py build` on macOS with Xcode, or package an actual
Release-iphoneos `.app` with `ios-release.py package`. Import the resulting IPA
through **My Apps → +** in AltStore Classic. AltStore signs it with your Apple
account; an unsigned download does not bypass Apple's signing requirements.
Free developer accounts normally require renewal within seven days. Hardware,
provider entitlements and real payment acceptance remain separate checks.

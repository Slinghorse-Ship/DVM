# Native Erweiterungen und Hooks

Stand: 15. Oktober 2026. Die native Erweiterungsbasis ersetzt P-16/P-17 im
vereinbarten Umfang: lokale registrierte PHP-Module, explizite Lebenszyklen,
Abhängigkeiten, Konfiguration und dokumentierte Hookverträge. Fremder PHP-Code
wird weder im Browser hochgeladen noch automatisch aus einem Verzeichnis geladen.
Vertrauenswürdiger PHP-Code läuft im selben Prozess. Das ist keine Sandbox für
unbekannten oder bösartigen Code.

## Bedienung

Die Vereinsadministration öffnet **Einstellungen → Erweiterungen**. Das
mitgelieferte Modul **Website-Hinweis** ist zunächst nicht installiert und inaktiv.
Nach der Installation können Text, eine optionale lokale Zieladresse und eine
optionale Ergänzung des Seitentitels gespeichert werden. **Aktivieren** prüft
die wirkliche Initialisierung, bevor der neue Zustand übernommen wird. Der Text
erscheint maskiert im öffentlichen HTML, die Titelerweiterung im Seitentitel.
`GET /extensions/website-notice` liefert nur den ausdrücklich aktivierten
öffentlichen Hinweis als JSON; `HEAD` liefert dieselben Rechteprüfungen ohne
Inhalt. Schreibmethoden sind gesperrt.

**Deaktivieren** zieht Titel, Hinweis und Adresse sofort zurück. **Deinstallieren**
ist erst nach Deaktivierung und ausdrücklicher Bestätigung möglich und entfernt
nur die Einstellungen und Daten dieses Moduls. Erforderliche Module können
erst entfernt werden, wenn ihre abhängigen Module deinstalliert sind. Andere
Vereine und ursprüngliche Geschäftsdatensätze bleiben erhalten.

Der Katalog zeigt höchstens neun Karten je Seite. Einstellungen, Entfernen und
der auf 20 Einträge begrenzte Verlauf bleiben zunächst geschlossen. Die Formulare
funktionieren ohne JavaScript und behalten die aktuellen Werte nach erfolgreichem
Speichern. Nach einer zwischenzeitlichen Änderung fordert die Verwaltung ein
Neuladen, statt einen neueren Zustand zu überschreiben. Ungültige Eingaben bleiben
im geöffneten Formular erhalten; ein Konflikt erhält dabei ausdrücklich seine
alte Revision. Auch über Deinstallation und erneute Installation bleiben die
Revisionen monoton, damit alte Tabs keine spätere Installation ändern können.

### Sprache und ursprüngliche Fehlerangaben

Die feste Verwaltungsoberfläche, ihre Fehler und die mitgelieferten Modultexte
folgen der persönlichen deutschen oder englischen Managersprache. Eigene
Modulnamen, Autoren-Feldbezeichnungen und gespeicherte Konfiguration bleiben
Originalwerte. Der Verlauf zeigt die vorhandenen UTC-Zeitpunkte als Berliner
Datum in der gewählten Sprache.

Ein typgerechter Eingabefehler bei noch aktuellem Stand erhält die ursprünglichen
Felder zur Korrektur. Bei einer alten, fehlenden oder strukturiert übergebenen
Revision, unbekanntem Modul/Feld oder nicht sicher prüfbarem Ergebnis werden
keine aktuellen Angaben als neuer Auftrag übernommen. Die Originaleingaben
bleiben lesbar; Formulare und andere Lebenszyklusaktionen sind dann gesperrt.
**Aktuelle Erweiterungen öffnen** liest den gespeicherten Stand ausdrücklich neu,
bevor ein neuer Auftrag bestätigt werden kann. Die nötige Versionsbindung bleibt
verdeckt, es erscheinen keine zusätzlichen technischen Formularzähler.

Konflikte werden mit HTTP409, ungültige Eingaben mit HTTP422 und nicht sicher
bestätigtes Speichern mit HTTP503 behandelt. Eine verlorene COMMIT-Bestätigung
kann auf eine bereits angenommene Änderung folgen; der bisherige Stand wird in
diesem Fall nicht als unverändert versprochen. Ein erneuter Versuch mit alter
Revision erzeugt keine zweite Speicherung oder Historie. Auch Fehler auf HEAD
bleiben ohne Antwortinhalt. CSRF, Sitzung, aktuelle Vereinsadministration und
die bereits gespeicherten Vereinsgrenzen werden weiterhin geprüft.

Ein Lifecycle-Auftrag bindet die beim Start geprüfte lokale Paketquelle. Unter
der Vereinssperre sowie nach Lifecycle und Initialisierung wird dieselbe Quelle
bestätigt. Ein zwischenzeitlicher Austausch der eigenen Datei bricht den
Vorgang mit HTTP409 ab und rollt seine Datenbankänderungen zurück. Reines
Deaktivieren bleibt auch bei einer fehlenden Hilfsdatei möglich: Es ruft keinen
Paketcallback auf und behält den vorhandenen gespeicherten Quellstand. Die Sperre
verweigert konkurrierende Arbeit unmittelbar. Es wird kein Modul automatisch
installiert, aktualisiert oder freigegeben.

Die zusätzliche registrierte Gruppe `native-extensions-interface` prüft diese
Abläufe mit tatsächlichen SQL-Sperren, Abbruch und verlorener COMMIT-Bestätigung.
Die befüllte Browserprüfung verwendet eigene native Sitzungen und eine private
geprüfte Registrierung mit vier Feldtypen und mehr als einer Katalogseite:

```sh
DVM_EXTENSIONS_INTERFACE_BROWSER=1 bash tools/ci/local-native.sh --core native-extensions-interface
```


## Lokale Module entwickeln

Ein geprüftes Modul implementiert `Extensions\Plugin`. Der Produktionshost liest
zusätzliche Module ausschließlich aus der festen privaten Datei
`VEREIN_PRIVATE_ROOT/reviewed-plugins.php`. Dafür muss kein Corecode geändert werden.
`Catalog` lässt sich außerdem für eine eigene geprüfte Laufzeit als
Konstruktorargument injizieren. IDs sind
eindeutig, Versionen folgen `major.minor.patch`, Abhängigkeiten nennen die
Mindestversion. Fehlende Abhängigkeiten, unzureichende Versionen und Zyklen
verhindern den Start. Die native API-Version ist derzeit 1.

Die private Registrierung gibt eine Liste geprüfter Modulinstanzen zurück, etwa:

```php
<?php declare(strict_types=1);
require_once __DIR__.'/extensions/PageTitleSuffix.php';
return [new Customer\Modules\PageTitleSuffix()];
```

Ein vollständiges minimales Modul in der genannten privaten Klassendatei:

```php
<?php declare(strict_types=1);
namespace Customer\Modules;
use Kulturhus\Native\Extensions\{Context,Definition,Event,HookScope,Plugin,UrlScope};
final class PageTitleSuffix implements Plugin {
    public function definition(): Definition {
        return new Definition('page-title-suffix','Seitentitel','1.0.0',[],[
            'suffix'=>['type'=>'text','label'=>'Titelergänzung','max'=>80,'default'=>''],
        ]);
    }
    public function install(Context $context): void {}
    public function upgrade(Context $context,string $previousVersion): void {}
    public function uninstall(Context $context): void {}
    public function initialize(HookScope $hooks,UrlScope $urls,Context $context): void {
        $suffix=$context->configuration['suffix'];
        $hooks->on('site.title','after',static function(Event $event)use($suffix):void {
            if($suffix!=='')$event->setResult(mb_substr($event->result().' · '.$suffix,0,500));
        });
    }
}
```

Dateien und Verzeichnisse müssen außerhalb des Webroots liegen, dem tatsächlich
ausführenden PHP-Benutzer gehören und privat sein: Verzeichnisse `0700`, PHP-Dateien
`0600` oder nur für den Eigentümer lesbar. Symbolische Links an der Registrierung,
am Modul oder in einem Pfadvorfahren werden abgewiesen. Die Prüfung verlangt eine
POSIX-Laufzeit mit `posix_geteuid`; bei nicht überprüfbaren Windows-ACLs lädt dieser
optionale private Codeweg keine zusätzlichen Module. Das mitgelieferte Modul bleibt
verfügbar. Der feste Basispfad kommt ausschließlich aus der Serverkonfiguration,
niemals aus einem Formular, Requestheader, Datenbankwert oder Upload.

Fehlende Registrierung bedeutet nur die mitgelieferten Module. Eine beschädigte,
zu offen lesbare oder ungültige Registrierung verwirft ihren gesamten zusätzlichen
Katalog. Syntaxfehler, normale Exceptions und versehentliche Textausgabe werden
isoliert, nicht an Websitebesucher ausgegeben. Die geschützte Verwaltung zeigt
einen allgemeinen Ladefehler. Nach Reparatur werden dieselben gespeicherten
Zustände und Konfigurationen wieder aufgenommen. Die Registrierung selbst darf
keine Geschäftsaktionen, Headeränderungen oder Prozessabbrüche auslösen; wie jeder
andere vertrauenswürdige PHP-Code ist sie keine Sandbox für bösartige Programme.

`Definition` enthält Name, Version, Abhängigkeiten, typisierte Konfigurationsfelder
und die Autoload-Entscheidung. Unterstützt werden begrenzter Text, Boolean,
begrenzte Ganzzahl und geschlossene Auswahl. Unbekannte Felder werden abgelehnt.
Der Textformatvertrag `local-path` verhindert externe, kodierte oder mehrdeutige
Zieladressen. Hier werden keine Zugangsdatenfelder angeboten. Geheimnisse gehören
weiterhin in die vorhandenen privaten, verschlüsselten Fachdienste.

Installation, Aktualisierung und Deinstallation erhalten einen namensgebundenen
`Context`. `get`, `put` und `delete` bearbeiten ausschließlich einfache JSON-Werte
des eigenen Vereins und Moduls. Änderungen sind nur während der expliziten
Lebenszyklustransaktion möglich. Modulinitialisierung ist schreibgeschützt;
sie registriert Hooks und GET/HEAD-Adressen. Eigene DDL- oder Außendienstaktionen
gehören nicht in diese Callbacks. Schemaänderungen erfolgen im vorhandenen
geprüften Paket-/Wartungsweg.

Eine neue Version oder geänderte lokale Quelldatei bleibt bis zur ausdrücklichen
Aktualisierung inaktiv. Die Bestätigung bindet an Beschreibung und SHA-256 der
konkreten Modulklasse. Änderungen an zusätzlichen Hilfsdateien müssen daher eine
neue Modulversion erhalten. Ein Downgrade wird abgelehnt; eine Rückkehr zu einem
alten Gesamtpaket verwendet den geprüften Paket-Rollback.

Nicht automatisch geladene Module können vom Host mit dem vierten
`Runtime`-Konstruktorargument ausdrücklich angefordert werden. Erforderliche
Abhängigkeiten werden davor initialisiert. Ein nicht geladenes manuelles Modul
wird in der Verwaltung entsprechend bezeichnet.

## Hosteigene Hookverträge

`Contract` definiert den eindeutigen Namen, Typ (`method`, `property`, `url`), eine
zwingende aktuelle Rechteprüfung und Validatoren für Argumente und Ergebnis.
Eine Ersetzung muss der Host ausdrücklich erlauben. Ein Modul bekommt lediglich
`HookScope::on`, nicht die Möglichkeit, den hosteigenen Vertrag oder seine Rechte
zu ersetzen. Die echten öffentlichen Anschlüsse sind:

- `site.title`: öffentliche Seitentiteleigenschaft, vor/nach der Originalauflösung.
- `site.response`: vollständige erfolgreiche öffentliche HTML-Antwort als Methode.
- `/extensions/<eigene-modul-id>/…`: reservierte GET/HEAD-Adressen mit begrenzten
  benannten Parametern, eigener aktueller Freigabe und validierter Antwort.

Vorher-Hooks können Argumente ändern, Nachher-Hooks das Ergebnis. Replace-Hooks
erhalten eine einmalige Fortsetzung und sind nur an ausdrücklich ersetzbaren
Verträgen zulässig. Prioritäten reichen von -1000 bis 1000; gleiche Priorität
verwendet die Registrierungsreihenfolge. Reentranz desselben Vertrags und mehr
als 16 verschachtelte Verträge werden gesperrt. Ereignisse erhalten tiefe Kopien
einfacher Werte, keine Datenbank-, Sitzungs- oder Identitätsobjekte.

Die Hostberechtigung wird vor dem Aufruf, vor/nach jedem Callback und nach der
Originalfunktion erneut geprüft. Die Modulfreigabe bindet zusätzlich an den
ursprünglich berechtigten aktiven Eigentümer, den aktuellen Vereinsbezug, die
Revision, Quellenbindung und weiterhin gültige Abhängigkeiten. Rollenentzug,
Sperrung, Deaktivierung oder Konfigurationsänderung entziehen auch schon
initialisierten Instanzen die Wirkung. Modul-URLs ersetzen keine Login-, API-,
Manager- oder CMS-Adressen. Antworten dürfen keine Sitzungs-, Weiterleitungs-
oder Sicherheitsheader überschreiben.
Während einer Lebenszyklustransaktion werden die aktuellen Administrations- und
Anmeldezeilen zusätzlich gesperrt, damit ein paralleler Rechteentzug nicht durch
einen älteren MySQL-Lesesnapshot übersehen wird.

## Fehler und Wiederaufnahme

Ein Fehler eines Vorher-/Nachher-Hooks verwirft nur dessen unbestätigte Änderung;
die letzte gültige Fassung bleibt erhalten. Ein fehlgeschlagener Replace-Hook
kann die Originalfunktion höchstens einmal ausführen. Ein bereits ausgeführter
Geschäftsvorgang wird bei Callbackfehlern nicht erneut ausgeführt. Fehler der
Originalfunktion werden weitergegeben und können vom Hook nicht als erfolgreiche
Geschäftsaktion ausgegeben werden. Ein entzogener Hostzugriff beendet den Aufruf.

Eine fehlgeschlagene Installation oder Aktualisierung rollt die eigenen neuen
Zustands- und Datenwerte vollständig zurück; die vorige Revision bleibt erhalten.
Ein neuer Versuch setzt dort an. Bei späteren Initialisierungsfehlern werden
teilweise registrierte Hooks und Adressen entfernt, abhängige Module starten
ebenfalls nicht. Die Verwaltung zeigt den fehlenden Start an und bietet
**Erneut starten**. Eine fehlende lokale Modulquelle lässt die gespeicherten Werte
erhalten und fordert das Ergänzen des geprüften Pakets.

Diagnosen enthalten nur Modul-/Vertragsnamen, Phase und einen geschlossenen
Fehlercode. Exceptiontexte, Konfiguration und Zugangsdaten werden weder im
öffentlichen Ergebnis noch im Lebenszyklusverlauf ausgegeben. Erfolgreiche
Lebenszyklusaktionen werden mit ursprünglichem Verein und Akteur protokolliert;
der Verlauf ist getrennt nach Verein und seitenweise abrufbar.

## Prüfwege

`native-extension-hooks` prüft Prioritäten, Methoden, Properties, Ersetzung,
einmalige Originalausführung, Rekursion, Rechteentzug, Datenkopien, URL-Kollisionen,
Antwortheader und Konfigurationsgrenzen. `native-extensions` prüft MySQL-Zustände,
Abhängigkeiten, Installation, Aktivierung, Konfiguration, Aktualisierung,
Deinstallation, fehlgeschlagene Zwischenstände, Wiederaufnahme, Quellenbindung,
Mandantentrennung und unveränderte ursprüngliche Geschäftstabellen.

Die zusätzliche tatsächliche Browserabnahme wird mit
`DVM_EXTENSIONS_BROWSER=1 bash tools/ci/local-native.sh --core native-extensions`
gestartet. Sie verwendet frische eigene native Anmeldungen, die echte geschützte
Verwaltung, öffentliche Originalinhalte und lokale HTTP-Anfragen. Reale
Anbieter-, Zahlungs-, Versand- oder Veröffentlichungsdienste werden nicht benutzt.

Gemeinsame Abnahme am 15. Oktober 2026:

- 50 Bedingungen für generische Hookverträge und Fehler-/Rechtewege.
- 61 Bedingungen für echten MySQL-Lebenszyklus, Wiederaufnahme und Originaldaten;
  397 ursprüngliche Geschäftstabellen unverändert.
- 24 tatsächlich geschützte Browseransichten: sechs Größen, 16/32px Schrift,
  JavaScript an/aus, einschließlich geöffneter Konfiguration. 269 Bedingungen,
  sechs tatsächliche Formular-POSTs, keine fremde Anfrage und kein JavaScriptfehler.
- Vollständige Browserabläufe für Installation, Konfiguration, Aktivierung,
  öffentliche Titel-/HTML-/URL-Wirkung, Berechtigungs-/CSRF-/Revisionsfehler,
  Speichern ohne JavaScript, Deaktivierung und bestätigte Deinstallation.
  396 ursprüngliche Geschäftstabellen und beide Originalschlüssel unverändert.

Beide registrierten Gruppen sind im gemeinsamen Lauf grün. Nachweisdatei:
`/tmp/kulturhus-extensions-ui-final.log`, SHA-256
`9fca6c08a13c98ecb9e4782390e07e98012e233dbe450ec00d9da57a42d2afe5`.
Der vorher gefundene Überlauf der langen Überschrift bei 320px und doppelter
Schrift sowie der zuvor zu kleine öffentliche Link wurden vor dieser Abnahme
korrigiert. Browser warten auf tatsächliche Ansicht und geladene Schriften;
Hintergrundverkehr des Managers wird nicht als Wartebedingung benutzt.

`native-extension-hosts` prüft ergänzend die tatsächliche private Hostregistrierung
ohne Coreänderung: Dateirechte, Pfadvorfahren und Symlinks, Syntax-/Ausgabe-/Quellfehler,
erneute Rechteprüfung des zwischengespeicherten Katalogs sowie tatsächliche öffentliche
Titel-, HTML- und URL-Hooks. Fehler ziehen nur die zusätzlichen Module zurück und
lassen ursprüngliche Website, Geschäftsdaten und beide Schlüssel erhalten.
Der gemeinsame Abschlusslauf aller drei Gruppen ist grün: 19 Hostbedingungen,
61 Lebenszyklusbedingungen und 50 Hookbedingungen, jeweils 397 ursprüngliche
Geschäftstabellen und beim Host beide Originalschlüssel unverändert. Nachweis:
`/tmp/kulturhus-extension-hosts-final.log`, SHA-256
`46a17f97db661ac436c74775c3ed4a8e63e345e511075da5ffba115f13e8ae11`.


## Isolierte Paketabnahme

Der aus dem gestagten Git-Bestand exportierte Featurestand wurde getrennt von
weiteren, noch unfertigen CMS-Änderungen geprüft. Drei Erweiterungsgruppen,
Installation, Paketupgrade, Wiederherstellung und unterbrochene Wiederaufnahme
wurden ausgeführt. Die drei Erweiterungsgruppen bestehen zusammen 130
Fachbedingungen sowie 269 Browserbedingungen in 24 tatsächlichen Ansichten.
Die kleinere isolierte Datenbank enthält 385 ursprüngliche Geschäftstabellen;
kein zuvor angelegter CMS-Ausbau wird für dieses Feature vorausgesetzt.
Installation besteht 233 Bedingungen, das tatsächliche Paketupgrade 150 und die
unterbrochene Wiederaufnahme 27 mit drei echten Prozessabbrüchen.

Der erste Siebenerlauf besteht sechs Gruppen. Sein Wiederherstellungstest
startete den Worker fälschlich aus dem reinen Quellarchiv ohne installierte
Abhängigkeiten. Der Test wurde auf den tatsächlichen Worker im gerade
wiederhergestellten vollständigen Paket umgestellt. Dieser getrennte korrigierte
Lauf besteht alle 221 Wiederherstellungsbedingungen und prüft die Sperre vor der
Betreiberprüfung am richtigen Einstieg. Die unveränderten funktionalen
Featuredateien wurden mit beiden unveränderlichen Exporten byteweise
abgeglichen. Der erste fehlgeschlagene Lauf wird nicht als vollständig grün
ausgegeben.

Nachweise:

- `/tmp/kulturhus-extensions-isolated-feature-proof.log`, SHA-256
  `ec2147ad9fd7e18075ed70c3ecec135b42733e3ba2607cd5f41b01a228643ca1`.
- `/tmp/kulturhus-extensions-recovery-corrected.log`, SHA-256
  `40c7f3fad651b11ba1a3f5e52a6e4e7072b5ebee11b1ef1825c176eca781ce05`.

Der abschließende Dokumentations-/Paketexport wird zusätzlich gegen alle
4479 Quelldateien und 192 Installerdateien geprüft. Sechs Inventar- und neun
Extraktionstests prüfen Offlinepakete und Pfadschutz. Es werden weder eine
Anbieteraktion noch ein Release oder eine Gesamtproduktquote daraus abgeleitet.

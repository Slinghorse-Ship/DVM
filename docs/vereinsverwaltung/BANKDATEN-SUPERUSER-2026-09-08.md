# Superuser-Zugriff auf Mitgliederbankdaten

Auf ausdrücklichen Nutzerwunsch erhält ein aktiver zentraler Superuser (`actors.is_superuser`) zusätzlich zu Mitglied und bestätigtem Kassenwart Zugriff auf die Mitgliederbankdaten seines Vereins. Dies ersetzt die entsprechende Superuser-Einschränkung der unmittelbar vorherigen Bankdaten-Veröffentlichung.

`MemberBankVault` prüft dieselbe Verwaltungsregel für Anzeige, Änderungen und Mitgliederauswahl. `WorkspaceAccess` gibt den Menü- und Direktzugang frei. Hat der Superuser selbst keine aktuelle Mitgliedschaft, öffnet der unqualifizierte Bankdatenbereich das erste verfügbare Mitglied. Ein ausdrücklich angefragtes unzulässiges Mitglied wird weiterhin abgewiesen.

Der Zugriff setzt weiterhin eine aktuelle aktive oder passive Mitgliedschaft des betroffenen Mitglieds voraus. Andere Vereine, archivierte Kontakte, deaktivierte Zugänge und das separate Redaktionskonto bleiben ausgeschlossen. Ein gewöhnliches Vorstandsamt oder eine Verwaltungsrolle allein erhält keine Bankrechte. Beim Kassenwart bleiben Finanz-Schreibrecht und aktuelle Amtsbestätigung erforderlich.

Vollständige Angaben erfordern auch beim Superuser das eigene Website-Passwort und den gegebenenfalls aktivierten zweiten Faktor. Eine Anzeige-Freigabe erlaubt keine Speicherung. Änderungen verlangen eine neue, an die Angaben gebundene Bestätigung und werden protokolliert. Fremde SEPA-Mandate kann auch ein Superuser nicht erteilen. Verschlüsselung, Ausblendung der Anzeige und bestehende Nachweise bleiben erhalten.

Oberflächentexte und Handbuch sind entsprechend angepasst. Die Änderung benötigt keine Datenmigration; Schema62 bleibt bestehen. Tests verwenden ausschließlich synthetische Daten und Test-Authentifizierung. Es werden keine Mails an Mitglieder oder Vorstand versendet und keine echten Bankvorgänge ausgelöst.

Zwei-Faktor bleibt für jedes Konto freiwillig, einschließlich Superuser. Ein Code wird nur bei im persönlichen Konto aktivierter Zwei-Faktor-Anmeldung verlangt; die Passwortbestätigung für Bankdaten gilt unabhängig davon.

Prüfung: Vault42, Reveal39, Superuser36 und Controller19 Einzelprüfungen bestanden. Der vollständige Passwort-/Code-Ablauf wurde sowohl für ein eigenes Mitglied als auch für einen Superuser ohne eigene Kontaktverknüpfung mit fremdem Mitglied geprüft, jeweils mit und ohne aktivierten zweiten Faktor. Menü, Direktzugang, entzogenes Superuserrecht, deaktivierte zentrale Identität, falsche Grants und die unerlaubte Erteilung fremder Mandate sind abgedeckt. Zusätzlich StepUp19, Einstellungen12, Hilferouten59 und PHP-Lint bestanden.

## Veröffentlichung

Am 8. September 2026 veröffentlicht: Runtime-Commit `d969a101650f206b1d1083c95a8ea036863772e2`, Ausgangsstand `c4bd9f8`. Vorab entsprachen alle betroffenen Live-Dateien exakt der Git-Ausgangsversion. Sieben veröffentlichte Zieldateien anschließend bytegenau geprüft. Sicherung unter `/tmp/kulturhus-verein/rollback-bank-superuser-20260908`, globales Runtime-Gate mit begrenzter Ablaufwartezeit und Worker-Lockprüfung während der Veröffentlichung. Keine Datenmigration und keine Änderung an Bankdatensätzen oder persönlichen Zwei-Faktor-Einstellungen.

Prüfung über die tatsächliche Webruntime PHP 8.3.33, SAPI `cgi-fcgi`: Schema62 unverändert, Integrität `ok`, keine Fremdschlüsselfehler. Der zentrale Live-Superuser hat sowohl die Verwaltungsberechtigung im Vault als auch den Zugang zur Bankroute. Vier öffentliche Seiten antworten mit HTTP200; 14 öffentliche Skript-/Style-Dateien entsprechen Git. Die erste Ausführung des privaten Prüfhelfers scheiterte an einem dort fehlenden `require_once` für WorkspaceAccess; dieser Prüfhelferfehler wurde behoben und die vollständige lesende Prüfung erfolgreich wiederholt.

Die zunächst wegen abgelaufener Anmeldung offene Browserprüfung wurde am 8. September nach erneuter Anmeldung nachgeholt: Die Bankroute `portal&tab=bank` wurde live als Admin geöffnet. Mitgliederauswahl, leeres Formular bei einem Mitglied ohne Bankdaten und Website-Passwortbestätigung waren sichtbar; SEPA war deaktiviert. Bei 390 Pixeln gab es keine horizontale Überbreite.

Dabei wurde keine Anzeige-Freigabe erteilt, kein Passwort-/Code-Challenge ausgelöst und kein Bankdatensatz geändert. Vollständige Anzeigen-/Schreibabläufe und die freiwillige Zwei-Faktor-Prüfung sind weiterhin ausschließlich mit synthetischen Daten getestet. Der lesende Live-Oberflächentest ist kein realer Bankdaten-Schreibtest. Keine Mails oder Zahlungen wurden ausgelöst.

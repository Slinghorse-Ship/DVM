## 2. Kontakte und Mitglieder finden, anlegen und pflegen

Ein Kontakt enthält Personendaten wie Name, Anschrift und E-Mail. Eine Mitgliedschaft ergänzt Mitgliedsnummer, Eintritt und Status. Auch jedes Mitglied besitzt daher ein Kontaktprofil. In der Menüansicht „Kontakte“ stehen Personen ohne Mitgliedschaft; Mitglieder findest du in den Mitgliedschaftslisten. Für dieselbe Person soll kein zweites Profil entstehen.

Die linke Menügruppe „Mitglieder & Kontakte“ hat getrennte Einstiege für „Mitglieder“ und „Kontakte“. Nach dem Öffnen stehen die zugehörigen Listen oben als Reiter. „Gespeicherte Mitgliederlisten“ und „Gespeicherte Kontaktlisten“ zeigen jeweils die passenden gespeicherten Filter. Über „Geburtstage & Jubiläen“ wechselst du zwischen den beiden Anlässen; diese Ansichten lösen keine Glückwunschmails aus.

### Eine vorhandene Person finden

Menüweg: Mitglieder & Kontakte → Kontakte oder Mitglieder & Kontakte → Mitglieder → Aktuelle Mitglieder, Alle Mitglieder beziehungsweise Ehemalige Mitglieder. Voraussetzung: Leserecht für Mitglieder und Kontakte. Ein normales Mitglied kann über Vereinsportal → Mein Profil & Belege → Mein Profil ausschließlich sein zugeordnetes Profil öffnen.

1. Wähle die passende Liste. Suche bei unklarem Mitgliedsstatus zuerst unter „Alle Mitglieder“ und anschließend unter „Kontakte“.
2. Klicke in das Suchfeld mit dem Hinweis „Name, E-Mail, aktuelle oder alte Mitgliedsnummer“.
3. Gib einen Namen, eine E-Mail-Adresse oder eine bekannte Nummer ein und klicke „Suchen“.
4. Klicke auf den Namen in der Ergebniszeile oder rechts auf „Öffnen“. Beide Wege öffnen dasselbe Profil.
5. Vergleiche Name, Anschrift, Geburtsdatum und Mitgliedsnummer, bevor du etwas änderst. Eine gemeinsame E-Mail-Adresse allein unterscheidet Familienangehörige nicht.

Ergebnis: Das vorhandene Profil ist geöffnet. Der Rückpfeil führt passend zum Profil zur Kontakt-, Mitglieder- oder Ehemaligenliste.

Wenn die Person fehlt: Entferne aktive Such- und Statusfilter. Bei leerer Trefferliste gibt es „Suche und Filter zurücksetzen“. Eine archivierte Person erscheint nur im „Geschützten Archiv“; siehe Kapitel 5. Lege sie deshalb nicht vorschnell neu an.

### Mitgliedschaft zu einem Stichtag auswerten

Öffne in der Mitgliederliste **Filter und Stichtag**, trage bei **Mitgliedschaft am Stichtag** ein Datum ein und wähle **Filter anwenden**. Das funktioniert mit Tastatur, Touch und ohne JavaScript. Ohne Datum gilt weiterhin der gespeicherte Status. Eintritt und Austritt zählen jeweils einschließlich des angegebenen Tages; ein späterer Wiedereintritt ersetzt keine frühere Mitgliedsnummer. Pro Profil wird der zuletzt begonnene gespeicherte Zeitraum ausgewertet.

- **Aktiv / Passiv:** Die Art ist für den ausgewerteten Zeitraum erhalten. Bei neuen Austritten und Archivierungen wird sie atomar im bestehenden Änderungsverlauf festgehalten; vorhandene alte Zeilen werden nicht umgeschrieben.
- **Mitglied · Art unbekannt:** Der Zeitraum ist belegt, die frühere Aktiv-/Passivart eines älteren Austritts wurde nicht erhalten. Sie wird nicht nachträglich erfunden. Diese Person zählt am Stichtag zu den Mitgliedern.
- **Eintritt am Stichtag ungewiss:** Es ist nur das Eintrittsjahr bekannt und der Stichtag liegt vor dessen Jahresende. Ein genauer Eintrittstag wird nicht angenommen; diese Zeile zählt nicht zur Liste bestätigter aktueller Mitglieder. Über den Statusfilter ist sie zur gesonderten Prüfung erreichbar.

Suche, Sortierung und Seitenwechsel behalten das Datum. **Liste speichern** speichert einen festen Stichtag; auch nach erneutem Öffnen wandert dieser nicht mit dem heutigen Tag. Ein bereits verwendeter Listenname lässt die Eingabe zur Korrektur stehen. Nach Öffnen eines Profils weist die Oberfläche auf dessen aktuellen Stand hin; der Zurück-Pfeil führt zur ursprünglichen Stichtagsliste.

Markiere Profile und wähle **Auswahl als Excel** oder **Als CSV**. Der Export führt zusätzlich Mitgliedsnummer, ausgewerteten Status und Stichtag mit. Leserechte erlauben die Auswertung; Export und eigene gespeicherte Listen erfordern Schreibrechte. Such- und Exportrechte gelten weiterhin für den aktuellen Verein und das eigene Profil. Geschützte archivierte Personen werden nicht aus dem Archiv offengelegt. Kontaktangaben, Namensänderungen und Archivzugänge werden nicht auf einen früheren Stand zurückgerechnet; die Auswertung ist kein historisches Registerabbild.

Die fachliche Regression ist `bash tools/ci/local-native.sh --core native-member-reference-date`; mit `DVM_MEMBER_REFERENCE_BROWSER=1` umfasst sie zusätzlich die wirklichen Formulare, gespeicherten Listen, CSV-Downloads und Rollen. Die Browsergruppe ist auch im vollständigen lokalen Prüfweg registriert. Sämtliche Prüfungen verwenden eigene synthetische Daten und lösen keine externen Aktionen aus.

### Sich im geöffneten Profil zurechtfinden

Oben stehen der Name und bei einem Mitglied der Status mit Mitgliedsnummer. Darunter siehst du die persönlichen Angaben. Weitere Themen sind zunächst eingeklappt, damit du nicht durch sämtliche Formulare scrollen musst.

1. Für Name, E-Mail, Telefon oder Anschrift bleibst du bei „Persönliche Angaben ändern“.
2. Für Geburtstag, alte Mitgliedsnummer, Ehrenmitglied oder Notizen klicke auf „Weitere persönliche Angaben“. Die ganze Überschriftszeile ist anklickbar; rechts steht ein Pluszeichen. Nach dem Öffnen zeigt ein Minuszeichen, dass du den Abschnitt wieder schließen kannst.
3. Für Eintritt, Familienzuordnung oder Jahresbeitrag öffne „Mitgliedschaft & Beitrag“. Dort findest du die vorhandenen Mitgliedschaften und darunter „Familie & Beitrag“.
4. Weitere Aufgaben stehen unter „Bankverbindung & SEPA“, „Inventar & Ausleihen“, „Website-Zugang & Newsletter“ und „Dokumente & Nachweise“. Bei Firmen heißt der Bankabschnitt „Bankverbindung des Lieferanten“. Du siehst nur die für deinen Zugang verfügbaren Bereiche.
5. Der Knopf „Persönliche Angaben speichern“ steht unter den persönlichen Angaben und dem Abschnitt „Weitere persönliche Angaben“. Er speichert auch dort eingegebene Werte, wenn du den Abschnitt wieder eingeklappt hast.

Wichtig: Einklappen speichert oder löscht nichts. Verlasse die Seite erst nach dem passenden Speichern. Änderungen an Mitgliedschaft, Familie, Zugang oder Bankverbindung haben eigene Schaltflächen im jeweiligen Abschnitt; der Knopf für persönliche Angaben speichert diese anderen Formulare nicht mit.

Fehlt eine erforderliche Angabe, wird sie rot markiert. Liegt sie in einem eingeklappten Abschnitt, öffnet sich dieser zum Korrigieren. Deine übrigen Eingaben bleiben stehen. Nach erfolgreichem Speichern kannst du das Profil erneut öffnen und den gespeicherten Wert kontrollieren.

### Als Mitglied die eigenen Angaben öffnen

Menüweg: Vereinsportal → Mein Profil & Belege → Mein Profil. Voraussetzung: persönlicher angemeldeter Zugang, der deinem Mitgliederprofil zugeordnet ist.

1. Öffne im Hauptmenü die Gruppe „Vereinsportal“ und klicke darunter „Mein Profil & Belege“. Wähle anschließend oben „Mein Profil“.
2. Auf der Profilübersicht steht dein Name. Klicke „Mein Profil öffnen“.
3. Lies im geöffneten Profil Name, E-Mail, Telefon, Geburtstag und Anschrift. Öffne darunter „Mitgliedschaft & Beitrag“, um Nummer und gespeicherten Eintritt zu sehen.
4. Wenn Name, Kontakt-E-Mail, Telefon oder Anschrift falsch sind, klicke **Meine Angaben ändern**. Die vier Felder sind bereits ausgefüllt. Ändere nur die unrichtigen Angaben und klicke **Zur Prüfung einreichen**.
5. Mit dem Rückpfeil „Zur Profilübersicht“ kommst du zur vorherigen Übersicht zurück.

Ergebnis: Du kannst deine zugeordneten Angaben lesen und Korrekturen zur Prüfung einreichen. Die alten Angaben bleiben gültig, bis der berechtigte Vorstand die Änderung übernimmt. Den Stand findest du unter **Meine Angaben ändern**. Die ausführliche Schrittfolge einschließlich Zurückziehen und gemeinsamer Familien-E-Mail steht in Kapitel 3. Steht bereits auf der Übersicht, dass kein eigenes Mitgliederprofil zugeordnet ist, muss die Verwaltung die Verbindung zum richtigen bestehenden Profil herstellen; lege dafür kein neues Mitglied an.

### Als Vorstand eingereichte Datenänderungen prüfen

**Menüweg:** Mitglieder & Kontakte → Mitglieder → **Datenänderungen prüfen**. **Voraussetzung:** bestätigtes aktuelles Vorstandsamt oder Superuser und zusätzlich Schreibrecht für Mitglieder. Ein reines Leserecht oder die Mitgliederverwaltung ohne Vorstandsrecht reicht für diesen Prüfweg nicht.

1. Öffne **Datenänderungen prüfen**. **Wartet auf Prüfung** zeigt zunächst die noch offenen Anträge. Bei vielen Einträgen trage den Namen in **Mitglied suchen** ein, wähle den Status und klicke **Anzeigen**. Jede Seite enthält höchstens zwölf Anträge.
2. Klicke auf die betreffende Person. Vergleiche bei jedem geänderten Feld **Bei Einreichung** mit **Vorgeschlagen**. Unveränderte Felder werden in diesem Vergleich ausgelassen.
3. Sind die Angaben richtig, kannst du unter **Rückmeldung an das Mitglied** eine kurze Antwort ergänzen. Bestätige, dass du den ursprünglichen Antrag mit den aktuellen Profildaten verglichen hast, und klicke **Geprüfte Angaben übernehmen**.
4. Wenn die Angaben nicht stimmen, schreibe den Grund in **Rückmeldung an das Mitglied**, bestätige die Prüfung und klicke **Nicht übernehmen**. Die bisherigen Profildaten bleiben erhalten.
5. Öffne nach der Übernahme das Mitgliederprofil und kontrolliere die gespeicherten Angaben. Der Änderungsantrag trägt nun **Übernommen**. Das Mitglied sieht denselben Stand und deine Rückmeldung in seinem Konto. Es wird keine automatische E-Mail verschickt.

**Wenn sich das Profil zwischenzeitlich geändert hat:** Der Vergleich zeigt zusätzlich **Inzwischen im Profil**. **Geprüfte Angaben übernehmen** wird dann nicht angeboten. Begründe die Ablehnung und bitte das Mitglied, die aktuellen Angaben zu vergleichen und neu einzureichen. Dadurch gehen neuere Änderungen nicht verloren.

**Bei einem Fehler oder zwischenzeitlicher Bearbeitung:** Die ursprüngliche Entscheidung und deine vollständige Rückmeldung bleiben erhalten. Ein inzwischen entschiedener Antrag wird nicht überschrieben. Prüfe den aktuellen Antrag über den getrennten Link in einem anderen Fenster. Bei unbestätigtem Speichern kontrolliere zuerst das dort sichtbare Ergebnis und bestätige eine angebotene Wiederaufnahme ausdrücklich. **Schrittweise Hilfe** öffnet die Anleitung neben dem laufenden Vorgang.

:::bild member-aenderung-pruefen

**Was unverändert bleibt:** Dieser Prüfweg verändert nur Name, Kontakt-E-Mail, Telefon und Anschrift. Eintritt, Mitgliedsnummer, Beiträge, Bankverbindung, Rechte sowie Benutzername, Passwort und Passwort-Zurücksetzen-Adresse werden darüber nicht geändert. Für diese anderen Aufgaben verwendest du den jeweiligen Bereich.

### Eine Person zunächst nur als Kontakt anlegen

Menüweg: Mitglieder & Kontakte → Kontakte → Kontakt hinzufügen. Voraussetzung: Schreibrecht für Mitglieder und Kontakte.

1. Suche zunächst wie oben nach einem bestehenden Profil.
2. Klicke „Kontakt hinzufügen“. Bei „Anlegen als“ muss „Nur Kontakt ohne Mitgliedschaft“ ausgewählt sein.
3. Wähle unter „Profilart“ die passende Art: „Person“ oder „Firma“.
4. Trage unter „Name“ den vollständigen Namen ein. Ergänze bekannte Angaben in „Telefon“, „E-Mail“ und „Anschrift“. Nicht bekannte Angaben bleiben leer; keine erfundenen Adressen eintragen.
5. Öffne „Weitere persönliche Angaben“. Dort kannst du bei einer Person „Geburtstag“ und bei Bedarf „Notizen“ ergänzen. Alte Mitgliedsnummern gehören in „Alte Mitgliedsnummer (optional)“. Zusätzliche Pflichtfelder des Vereins sind mit einem Stern gekennzeichnet.
6. Klicke unter dem Formular auf „Kontakt anlegen“.

Ergebnis: Die Kontaktdaten sind gespeichert. Es wurde keine Mitgliedschaft angelegt. Die Mitgliedschaftsfelder sind bei dieser Auswahl ausgeblendet.

Wenn versehentlich „Mitglied mit Kontaktprofil“ ausgewählt wurde: Vor dem Speichern „Anlegen als“ ändern. Die Beschriftung der Schaltfläche wechselt ebenfalls. Ein Kontaktprofil ist noch kein Website-Zugang und keine Newsletter-Anmeldung.

### Ein bereits beschlossenes Mitglied direkt anlegen

Menüweg: Mitglieder & Kontakte → Mitglieder → Aktuelle Mitglieder → Mitglied hinzufügen. Voraussetzung: Schreibrecht für Mitglieder. Für einen noch zu entscheidenden Antrag verwende stattdessen Kapitel 6.

1. Prüfe, ob die Person bereits als Kontakt vorhanden ist. Falls ja, verwende „Als Mitglied übernehmen“ im vorhandenen Profil.
2. Klicke „Mitglied hinzufügen“. Prüfe bei „Anlegen als“ die Auswahl „Mitglied mit Kontaktprofil“.
3. Fülle mindestens „Name“ aus und ergänze die bekannten persönlichen und Kontaktdaten.
4. Ergänze bei Bedarf Geburtstag, alte Mitgliedsnummer und Notizen unter „Weitere persönliche Angaben“. Fülle dort auch vorhandene zusätzliche Pflichtfelder aus.
5. Gehe zu „Mitgliedschaft & Familie“. Wähle den „Status“: „Aktiv“ oder „Passiv“ entsprechend den Vereinsunterlagen. Diese Auswahl bezeichnet die Mitgliedschaft, nicht die Anmeldung am Computer.
6. Wähle bei „Datumsgenauigkeit“ „Genaues Datum bekannt“ oder „Nur Eintrittsjahr bekannt“. Trage nur die tatsächlich bekannte Angabe in das sichtbare Datums- beziehungsweise Jahresfeld ein.
7. Wähle bei „Einzelmitglied oder Familie“ die passende Zuordnung. Einzelheiten und Familienzahler erklärt Kapitel 3.
8. Die Mitgliedsnummer wird automatisch vergeben, wenn du nichts eingibst. Nur für eine ausdrücklich zugeordnete freie Nummer öffnest du „Mitgliedsnummer festlegen (optional)“ und trägst die Nummer ein.
9. Öffne „Website-Zugang und Zugangsmail“, um die Versandauswahl zu prüfen. Vorausgewählt ist „Konto anlegen, keine Mail senden“. Wähle die Variante mit Zugangsmail nur, wenn die Einladung versendet werden soll. Eine gültige eigene oder gemeinsame Familien-E-Mail muss zum Profil passen; ohne gültige Adresse bleibt die Kontoanlage zur Klärung stehen.
10. Klicke unten „Mitglied anlegen“. Öffne im gespeicherten Profil „Mitgliedschaft & Beitrag“ und kontrolliere Mitgliedschaft sowie „Familie & Beitrag“. Den Zugangsstatus findest du unter „Website-Zugang & Newsletter“ → „Website-Zugang“.

:::bild member-stammdaten

:::bild member-eintritt

Ergebnis: Person und Mitgliedschaft werden gemeinsam gespeichert. Die Zugangsbearbeitung ist im Profil als eigener Status sichtbar. Die direkte Stammdatenanlage ist kein Zahlungseingang; Beiträge und Rechnungen prüfst du anschließend im Beitragsbereich.

Bei einer Fehlermeldung zu Name, Eintritt, Nummer oder Familie: Korrigiere das markierte beziehungsweise genannte Feld und speichere erneut. Eine bereits einer anderen Mitgliedschaft gehörende Nummer darf nicht umverteilt werden. Die Mitgliedschaftsnummer ist nicht dasselbe wie „Alte Mitgliedsnummer“.

### Einen bestehenden Kontakt zum Mitglied machen

Menüweg: Mitglieder & Kontakte → Kontakte → Name → Mitgliedschaft & Beitrag → Als Mitglied übernehmen. Voraussetzung: Schreibrecht für Mitglieder.

1. Öffne den vorhandenen Kontakt und prüfe seine Identität.
2. Klicke auf „Mitgliedschaft & Beitrag“ und darin auf „Als Mitglied übernehmen“.
3. Fülle Status, Datumsgenauigkeit, Eintritt und Familienzuordnung wie bei einer Neuanlage aus. Für eine bestimmte freie Nummer öffne „Mitgliedsnummer festlegen (optional)“; ohne Eingabe erfolgt die Nummernvergabe automatisch.
4. Öffne „Website-Zugang und Zugangsmail“ und prüfe die Auswahl „Zugangsmail“.
5. Klicke „Mitgliedschaft anlegen“.

Ergebnis: Dasselbe Kontaktprofil erhält eine Mitgliedschaft. Bestehende Dokument- und sonstige Zuordnungen bleiben an dieser Person. Bei bereits vorhandenen Mitgliedschaften heißt der Abschnitt „Weitere Mitgliedschaft hinzufügen“; nutze ihn nur für eine tatsächliche zusätzliche Mitgliedschaft beziehungsweise einen Wiedereintritt.

Wenn eine überlappende Mitgliedschaft gemeldet wird: Prüfe den bereits gespeicherten Zeitraum. Korrigiere den vorhandenen Eintrag, statt dieselbe Mitgliedschaft ein zweites Mal anzulegen.

### Kontaktdaten oder Eintritt korrigieren

Menüweg: Mitglieder & Kontakte → Mitglieder oder Kontakte → Name. Voraussetzung: Schreibrecht. Lesende sehen nur die Angaben; Änderungen übernimmt die Mitgliederverwaltung.

1. Ändere Name, Anschrift, Telefon oder E-Mail im oberen Profilformular. Geburtstag, alte Mitgliedsnummer und Notizen findest du nach einem Klick auf „Weitere persönliche Angaben“.
2. Klicke darunter „Persönliche Angaben speichern“. Diese Schaltfläche speichert die Stammdaten einschließlich der weiteren persönlichen Angaben.
3. Für eine Eintrittskorrektur öffne gesondert „Mitgliedschaft & Beitrag“ → „Eintritt bearbeiten“ bei der betroffenen Mitgliedschaft.
4. Wähle „Datumsgenauigkeit“. Trage „Eintrittsdatum“ oder „Eintrittsjahr (falls nur Jahr bekannt)“ ein.
5. Klicke „Eintritt speichern“ und prüfe die angezeigte Mitgliedschaft.

Ergebnis: Die ausgewählten Angaben sind aktualisiert. Bereits ausgestellte Beitragsrechnungen werden durch eine Eintrittskorrektur nicht neu berechnet. Familienänderungen und Zugangseinstellungen besitzen ebenfalls eigene Speicherschaltflächen; „Persönliche Angaben speichern“ übernimmt diese anderen Formulare nicht mit.

Bei „inzwischen geändert“: Öffne das Profil neu, vergleiche den aktuellen Stand und übernimm deine Korrektur in dieses neue Formular. Nicht dieselbe veraltete Seite immer wieder abschicken.

### Listen filtern, Spalten wählen und eine Auswahl exportieren

Menüweg: eine Kontakt- oder Mitgliederliste. Voraussetzung: Lesen für die Ansicht; Schreibrecht für die angebotenen Auswahl- und Exportaktionen.

1. Klicke oben auf „Filter anzeigen“. Darunter öffnet sich die Filterauswahl.
2. Wähle „Stammliste“, „Status“ und gegebenenfalls „Profilart“. Klicke „Filter anwenden“.
3. Sortiere bei Bedarf über die Überschriften „Nr.“, „Name“ oder „Geburtstag“. Die Pfeile wechseln die angebotene Reihenfolge.
4. Klicke „Spalten auswählen“. Blende E-Mail, Telefon, Geburtstag oder Anschrift nach Bedarf ein oder aus.
5. Markiere die gewünschten Personen links in der Tabelle. Das Kästchen in der Kopfzeile wählt die aktuell angezeigten Profile, nicht automatisch sämtliche anderen Seiten.
6. Prüfe die Zahl „ausgewählt“. Mit **Auswahl als Excel** erhältst du eine Excel-Datei; **Auswahl als CSV** bietet alternativ eine Texttabelle. Wähle das Format, das du für die weitere Bearbeitung brauchst.

Ergebnis: Die gewählte Auswahl und die angebotenen sichtbaren Kontaktdaten werden heruntergeladen. Der Export verschickt keine Mail. Die ausführliche Excel-Schrittfolge und den Import mit Vorschau findest du in Kapitel 4.

### Eine Filterliste speichern oder einen Favoriten merken

Menüweg: Mitglieder & Kontakte → Kontakte → Gespeicherte Kontaktlisten oder Mitglieder & Kontakte → Mitglieder → Gespeicherte Mitgliederlisten; für persönliche Markierungen Mitglieder & Kontakte → Kontakte → Favoriten. Voraussetzung: Schreibrecht zum Speichern und Markieren.

1. Stelle in einer Liste die gewünschten Filter ein.
2. Klicke oben „Liste speichern“. Im geöffneten Bereich trage unter „Name der dynamischen Liste“ einen verständlichen Namen ein und klicke dort „Liste speichern“.
3. Öffne die gespeicherte Liste später unter „Gespeicherte Kontaktlisten“ beziehungsweise „Gespeicherte Mitgliederlisten“. Sie verwendet weiterhin ihre Filter; passende neue oder geänderte Profile erscheinen dadurch automatisch.
4. Zum Merken einer einzelnen Person öffne deren Profil und klicke den Stern „Als Favorit merken“.
5. Öffne „Favoriten“, um deine persönlich markierten Profile wiederzufinden. Ein erneuter Sternklick entfernt die Markierung. Die Markierung lässt sich auch direkt in den Geburtstags- und Jubiläumslisten setzen oder entfernen. Sie gilt nur für deinen eigenen Zugang.

Ergebnis: Filterlisten ersparen wiederholtes Einstellen. Favoriten sind deinem Zugang zugeordnet. „Entfernen“ bei einer gespeicherten Liste löscht nur die Liste, nicht die Personen.

### Geburtstage und Jubiläen ansehen

Menüweg: Mitglieder & Kontakte → Geburtstage & Jubiläen → Geburtstage beziehungsweise Jubiläen. Voraussetzung: Leserecht; Schreibrecht zum Ändern der Jubiläumsregeln.

1. Öffne die gewünschte Liste und suche bei Bedarf einen Namen.
2. Bei Jubiläen begrenze „Stichtag von“ und „Stichtag bis“ und klicke „Filtern“.
3. Lies Geburtstag, kommendes Alter beziehungsweise Jubiläum und Stichtag in der Zeile. Klicke den Namen für das zugrunde liegende Profil.
4. Wenn eine Mitgliedsdauer anders berücksichtigt werden soll, öffne „Jubiläumsregeln“.
5. Bearbeite je Regel „Name“, „Dauer“ und „Einheit“. Beispiel: Name „25 Jahre Mitglied“, Dauer „25“, Einheit „Jahre“.
6. Für eine weitere Regel klicke „Weiteres Jubiläum hinzufügen“ und fülle dieselben Felder aus. Höchstens 24 Regeln sind möglich. Hinzufügen und Entfernen bearbeiten zunächst nur deinen Entwurf und funktionieren auch ohne JavaScript. Erst „Jubiläumsregeln speichern“ übernimmt die Regeln.
7. Zum Entfernen einer falschen Regel klicke deren kleines Kreuz mit der Bezeichnung „Jubiläumsregel entfernen“.
8. Klicke „Jubiläumsregeln speichern“. Mit „Abbrechen“ verlässt du die Regelbearbeitung ohne die Änderungen zu speichern.

Ergebnis: Die Listen und Dashboard-Anzeigen folgen den hinterlegten Daten und Regeln. Geburtstage und Jubiläen lösen keine automatischen Glückwunschmails aus. Falsche Ergebnisse korrigierst du im Geburts- beziehungsweise Eintrittsdatum; das Entfernen einer Jubiläumsregel verändert keine Mitgliedschaft.


**Fehler und Wiederaufnahme:** Eine ungültige Suche, Seitengröße oder Seite wird nicht durch eine andere Auswahl ersetzt. Die ursprünglichen Angaben bleiben lesbar. In einem korrigierbaren Regelentwurf bleiben Namen, Dauer, Einheit und ursprünglicher Stand erhalten. Wurden Regeln zwischenzeitlich geändert oder konnte eine Speicherung nicht bestätigt werden, bleibt der alte Entwurf gesperrt. Über **Gespeicherte Regeln in neuem Fenster prüfen** liest du den aktuellen Stand zum Vergleich; erst anschließend bewusst einen neuen Entwurf bearbeiten. Passwort- und Sicherheitsangaben erscheinen nicht im ursprünglichen Wertepaket.

### Weitere Angaben ausfüllen

Die Mitgliederverwaltung kann zusätzliche Felder für alle Mitglieder und Kontakte festlegen, zum Beispiel eine Qualifikation. Bei einem bearbeitbaren Profil stehen sie innerhalb von **Weitere persönliche Angaben** unter der Überschrift **Weitere Angaben**.

1. Öffne **Mitglieder** und wähle die Person aus. Für externe Kontakte wähle zuerst die Kontaktliste.
2. Öffne im Profil **Weitere persönliche Angaben** und scrolle darin zu **Weitere Angaben**. Ein Stern kennzeichnet ein Pflichtfeld. Fehlt noch eine erforderliche Zusatzangabe, kann der Abschnitt bereits geöffnet sein.
3. Trage Text oder eine Zahl ein. Dezimalzahlen dürfen ein Komma enthalten. Ein Datum kannst du im Datumsfeld auswählen. Zeigt das Feld einen unpassenden früheren Textwert, korrigiere ihn als **JJJJ-MM-TT**, zum Beispiel **2026-09-07**.
4. Bei **Ja/Nein** wähle ausdrücklich eine Antwort. Auch **Nein** erfüllt ein Pflichtfeld. Bei einer Auswahlliste wähle einen der angebotenen Werte.
5. Klicke unter dem persönlichen Formular auf **Persönliche Angaben speichern**. Bei einer Neuanlage heißt dieser Knopf **Mitglied anlegen** beziehungsweise **Kontakt anlegen**. Öffne das Profil anschließend erneut und kontrolliere deine Angaben.

Bei einer Fehlermeldung bleiben die Eingaben im Formular stehen. Prüfe das genannte Feld und speichere erneut. Wenn die Felddefinition oder das Profil zwischenzeitlich geändert wurde, lade die Seite neu und vergleiche deine Eingaben; ein veraltetes Formular darf neuere Änderungen nicht überschreiben.

**Bisheriger Wert – bitte neu auswählen** bedeutet, dass eine frühere Auswahl nicht mehr angeboten wird. Der alte Wert bleibt sichtbar, bis du ihn bewusst ersetzt. Nach einer Änderung des Feldtyps erscheint ebenfalls ein Prüfhinweis. Unter **Frühere Angaben zu entfernten Feldern** bleiben bereits gespeicherte Werte lesbar. Ein entferntes Feld wird nicht still aus den Personenprofilen gelöscht.

Mit Leserechten kannst du die Angaben ansehen. Änderungen übernimmt die Mitgliederverwaltung. Beim geschützten Archivieren werden diese Angaben zusammen mit dem Profil verschlüsselt und beim Wiederherstellen zurückgeholt.

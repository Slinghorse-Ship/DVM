# Newsletter und Presse im Mitgliederprofil

Unter **Mitglieder → Profil → Zugang und Kommunikation → Newsletter & Presse**
stehen die aktuell zur Profiladresse gespeicherten nativen Angaben. Ein neues
CMS enthält keine importierten Empfänger oder Einwilligungen.

Die Anzeige unterscheidet ausstehende Bestätigung, Bestätigung und Abmeldung.
Sie zeigt einen gespeicherten Bestätigungszeitpunkt in deutscher Ortszeit und
einen Pressekontakt mit Kennzeichnung, wenn er inaktiv ist. Ein nicht vorhandener
Datensatz wird nicht als Anmeldung interpretiert. Eine geänderte Profiladresse
liest ausschließlich den Status dieser neuen Adresse. Eine nach Datenschutzregeln
anonymisierte Abmeldung wird nicht über die frühere Adresse rekonstruiert.

Verwenden mehrere nicht archivierte Profile dieselbe Adresse, weist die Anzeige
darauf hin. Die Einwilligung gilt für die Adresse; sie wird keiner einzelnen
Person der Familie zugeschrieben. Die Ansicht bezeichnet die ursprüngliche
Einwilligung nicht pauschal als Linkbestätigung.

Jeder Abruf prüft die aktuelle Profilberechtigung und Vereinszuordnung. Mitglieder
sehen nur ihr eigenes erlaubtes Profil; Redaktion und gesperrte Personen bekommen
keinen zusätzlichen Profilzugriff. Abonnenten eines anderen Vereins werden auch
bei gleicher Adresse nicht angezeigt. Tokens, private Einwilligungsbelege und
Empfängernamen werden nicht abgefragt oder ausgegeben. Die Anzeige schreibt keine
Daten, legt keine Tabellen an und löst keine Nachrichten aus.

23 isolierte MySQL-/Darstellungsprüfungen belegen Statuswechsel, Rechteentzug,
Vereinstrennung, gemeinsame Adressen, Escaping und unveränderte Originalbytes.
23 tatsächliche Browserprüfungen im angemeldeten Manager decken 1440/820/390 Pixel,
Tastaturbedienung der Profilbereiche, 200 % Zoom und sofort sichtbare Bestätigung
und Abmeldung ab. Die eigene synthetische Browseradresse endet auf `.invalid`;
Transport und externe Anbieter werden nicht aufgerufen.

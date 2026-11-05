# Aktuelle Rechte im direkten CMS

Persönliche Vereinskonten verwenden im direkten CMS und im eingebetteten
Vereinsmanager dieselben aktuellen Vereinsrollen. Ein Rechteentzug gilt auch
für eine bereits angemeldete Sitzung. Dafür ist weder eine erneute Anmeldung
noch das Öffnen einer anderen Verwaltungsansicht erforderlich.

Die Änderung erhält die persönliche Konto- und Vereinszuordnung. Eigenständige
native Konten ohne Vereinsverknüpfung behalten ihre tatsächlich vergebenen
nativen Einzelrechte. Verlorene oder geänderte Verknüpfungen eines bereits
geprüften Vereinskontos eröffnen keinen zweiten Berechtigungsweg.

Geplante Veröffentlichungen prüfen ebenfalls die aktuelle Vereinsberechtigung
und das bestätigte Konto ihres tatsächlichen Erstellers. Das gilt auch für
ältere Aufträge, deren gespeicherte Herkunft `native` lautet. Bei einem
Rechteentzug bleibt der ursprüngliche Auftrag erhalten; der Lauf wird blockiert,
und der private Entwurf wird nicht veröffentlicht. Gespeicherte Herkunft,
Inhalt und Originalhash werden dabei nicht umgeschrieben. Das normale Abmelden
eines einzelnen Geräts hebt eine separat gültige Veröffentlichungsfreigabe
nicht auf.

Die Rechteauflösung erfolgt über `Auth/SessionAccess`, `Shared/Authority` und
den bestehenden `Integration/ManagerBridge`. Die persönliche Sitzung wird
weiter geprüft; im fachlichen Speichervorgang werden aktuelle Rechtezeilen
gesperrt gelesen. Die Verzeichnissuche verwendet weiterhin die vorhandene
transaktionale Vereinsprojektion. Die neue Rechteprüfung selbst benötigt
keine Aktualisierung zwischengespeicherter nativer Rechte.

`tests/native-session-authority.php` prüft 61 tatsächliche Bedingungen mit
neuen persönlichen Konten, echter Anmeldung, Rollenänderungen, atomaren
abgewiesenen Inhaltsänderungen und einer wirklich ausgeführten lokalen
Veröffentlichungsplanung. Die Probe erhält alle 368 vorher vorhandenen
vollständigen Originaltabellen ihrer isolierten Datenbank. Gegenproben mit den
gesicherten alten Klassen weisen beide Rechtefehler nach. Die bestehenden
Gruppen `native-publication` und `native-account-panels` bestehen zusätzlich
mit 70 beziehungsweise 187 Prüfungen; es wurde kein externer Dienst angesprochen.

Die direkte Chat-Mitgliederprüfung und Nachrichtenanlage verwenden bei
verknüpften Konten weiterhin die vorhandene Transaktion mit aktueller
Vereinsprojektion. Die direkten Rechteverknüpfungen für Nachfolge und lokale
Hinweise lesen anschließend diese Projektion. Der eigentliche Pushversand
prüft persönliche Zuordnung, Konto, aktuelle Chatberechtigung und Teilnahme
erneut. Diese Änderung schreibt keine Chatnachrichten oder Zustelloriginale um
und ersetzt keine gesonderte Geräte- oder Anbieterabnahme.

# Mehrkanalton als Stereo

Das Reelstudio übernimmt nun alle ursprünglichen Kanäle in den lokalen
Audiopuffer. Mono, Stereo, Quad und 5.1 werden über die standardisierte
Web-Audio-Lautsprechermischung als Stereo mit 48 kHz verarbeitet. Bei mehr als
zwei unterstützten Kanälen steht sichtbar **Mehrkanalton wird als Stereo
übernommen**. Die fertige MP4 behält das gemeinsame AAC-Stereoprofil.

Der bisherige Weg kopierte nur die ersten beiden Quellkanäle. Dadurch wurde
ein Video mit Ton ausschließlich im Center-Kanal vollständig stumm. Eine echte
synthetische 5.1-Datei reproduziert diesen Fehler: die ursprünglichen linken
und rechten Kanäle sind stumm, der Center-Kanal enthält ein hörbares Signal.
Die korrigierte Speicherung enthält dieses tatsächliche Signal auf beiden
Stereokanälen. Es wird keine erfundene oder ersatzweise stille Audiospur erzeugt.

Andere Kanalanordnungen werden nicht automatisch auf die ersten zwei Kanäle
verkürzt. Quelle und eingeschaltete Tonwahl bleiben erhalten; eine Stereoquelle
wählen oder den Ton ausdrücklich abwählen. Auch nicht dekodierbarer Originalton
blockiert nach ausdrücklicher Abwahl nicht die Verarbeitung des eigentlichen
Videos. Die bekannten Speicher-/Laufzeit-/Codecgrenzen gelten weiter.

21 tatsächliche Audiobrowserprüfungen vergleichen dekodierte Originalkanäle
und die neue eigene gespeicherte MP4. Eine wirkliche 7.1-Datei prüft die gesperrte
nicht unterstützte Mischung und die ausdrückliche Tonabwahl. Tatsächlicher AAC-Abbruch,
Workerende und genau ein nativer Upload bleiben geprüft. 22 Quell-/Lizenz-/
Datei-/Profilverträge sowie 162/164 Fach-/Originalverträge (zusammen 184/186)
prüfen zusätzliche genaue Quellbytes und die notwendige Stereo-Konvertierung
vor Verwendung im gemeinsamen Veröffentlichungsprofil. Die 29 früheren
Video-/Abbruch-/Downloadbrowserprüfungen und 171 Fachverträge bestehen erneut.
Zehn betroffene native Suiten und 89 genuine Produktionscontroller-Verträge
bestehen. Kommunikation bleibt 8, CMS 17; 144 Suiten registriert.

Alle früheren kompletten Datenbankzeilen, Schlüssel, Bild-/Videobytes,
Quellangaben, Freigaben und Veröffentlichungen bleiben erhalten. Nur eine neue
eigene private Videofassung wird gespeichert. Die originalen 5.1-/7.1-Prüfdateien
sind synthetisch erzeugt und nicht ins CMS übernommen. Hersteller-/Geräteabnahme,
weitere Formate, Videobausteine, Carousels und übrige Registeraufgaben bleiben offen.

149 tatsächliche CLI-Installer-/Upgrade-, 52 HTTP-/Browser-Installer- und
79 verschlüsselte Wiederherstellungsverträge bestehen ebenfalls. Beide
ursprünglichen gemeinsamen App-Bestände bleiben ohne Web-DDL unverändert;
kein vollständiger neuer 144-Suiten-Lauf und keine Geräteabnahme behauptet.

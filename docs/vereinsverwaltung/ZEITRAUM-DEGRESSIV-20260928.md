# Degressive Abschreibung für tatsächliche Wirtschaftsjahre

Entwicklungsstand 28.09.2026, kein allgemeiner Bilanzrelease.

Bestätigte degressive Anlagenpläne werden nach den tatsächlichen vollen Monaten
des gewählten Wirtschaftsjahrs gerechnet. Ein Januar innerhalb dieses Zeitraums
startet keine zweite Jahresabschreibung. Folgezeiträume übernehmen die zuvor
bestätigten Anschaffungskosten, kumulierte Abschreibung und den Restbuchwert.
Die Bestätigung ist an den vorherigen Anlagen-Nachweis und Originalplan gebunden.

Der zulässige Wechsel zur linearen Abschreibung wird mit Restnutzungsdauer,
Basis und centgenauer kumulierter Rundung gespeichert und fortgeführt. Ein
Rückwechsel oder eine stille Neuberechnung alter Festschreibungen ist ausgeschlossen.
Die bestehende fachliche Bestätigung und Jahreskontenbegrenzung bleiben erhalten.

Nachweis: `audit/ebilanz-mysql-host-20260918-58e8bbb02118bb37.json`.
52 neue Rechenprüfungen plus 103 Zeitraumprüfungen sowie 33 MySQL-Anlagenprüfungen
plus 43 Quellprüfungen bestanden. Enthalten: Zehnmonatsjahr, sechsmonatiger
Folgezeitraum, Kalenderkompatibilität, Vortragsbindung, Doppelsperre, Centfälle,
Methodenwechsel und vollständig abgeschriebene Anlagen.

Weiter offen: periodisierte Abgänge/Teilabgänge und Sphärenwechsel, Übernahme
historischer Kalender-Anlagenstände, Bereichs-/Steuerüberleitung und amtlicher
Zeitraumexport sowie vollständige fachliche Gesamt- und Veröffentlichungsabnahme.
Keine Änderung an Produktion oder ihrer EÜR-Einstellung.

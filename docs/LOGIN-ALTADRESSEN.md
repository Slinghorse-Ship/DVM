# Bisherige Login-Einstiege

Die beiden nativen Zugänge erhalten die bisherigen öffentlichen Login-Links:

| Bisheriger GET-/HEAD-Link | Fester persönlicher Zielweg |
|---|---|
| `/login/?forgot=1` | `/forgot` |
| `/login/?security=1` | `/account?section=security` |

Die vorübergehende Weiterleitung ist leer und `no-store`. Der Sicherheitsbereich
prüft am Ziel weiterhin die aktuelle persönliche Sitzung. Ohne gültige Anmeldung
erscheint das Loginformular; ein alter Link aktiviert keinen zweiten Faktor.

Nur der einzelne, exakt als `1` angegebene Modus wird berücksichtigt. Beide
Modusschlüssel zusammen, Arraywerte oder andere Werte behalten das normale
Loginformular. Zusätzliche Parameter werden nicht in das feste Ziel übernommen.
POST und andere Methoden folgen ihrem bisherigen Controller; der Alias führt
keine Formularaktion aus und ersetzt weder CSRF- noch Ursprungskontrollen.

Gezielte Prüfung ohne Anbieteraktionen:

```sh
bash tools/ci/local-native.sh --core native-login-aliases
```

Die Gruppe verwendet beide tatsächlichen Router, ein eigenes persönliches Konto,
eine unveränderte andere Sitzung, kanonische Formulare, gezielten Sitzungswiderruf
und genaue Datenbank-/Schlüsseloriginale. Eine reine URL-Grenzprüfung läuft mit
`DVM_LOGIN_ALIASES_PURE=1 php vereinsverwaltung/tests/native-login-aliases.php`.

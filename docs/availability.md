# Belegungskalender und Verwaltung

## Ablauf

Admin → `../storage/availability.json` → `/api/availability.php` → Kalender auf
`contact.html` → `arrival`/`departure` → `send.php` → Verfügbarkeitsprüfung → bestehender PHPMailer.

Eine Anfrage speichert **keine** Belegung. Erst nach manueller Bestätigung trägt
der Vermieter den Zeitraum unter `/admin/` ein.

Alle PHP-Aufrufer verwenden `includes/availability.php`. Die Regel lautet überall
`newStart < existingEnd && newEnd > existingStart`: Anreise inklusive, Abreise
exklusive. Eine Abreise am 20. ist auch dann möglich, wenn die nächste Belegung
am 20. beginnt. Im Kalender sind belegte Nächte schraffiert und durchgestrichen;
der Beginn einer Belegung ist nach Auswahl einer früheren Anreise als Abreise wählbar.

Datumswerte sind strikt `YYYY-MM-DD`. „Heute“ wird ausschließlich für diese
Funktion mit `Europe/Lisbon` bestimmt, passend zu Carvoeiro. Die globale PHP-Zeitzone
bleibt unverändert. Der API-Header `X-Availability-Today` gibt dem Browser denselben
Kalendertag vor, unabhängig vom Aufenthaltsort des Besuchers.

## Dateien

Geändert: `.gitignore`, `.prettierignore`, `compose.yaml` (lokal, weiterhin ignoriert), `contact.html`,
`send.php`, `js/contact-form.js`, `css/style.css`.
In `css/style.css` wurde ausschließlich der ungenutzte Google-Fonts-Import für Lora entfernt.
Die vorhandenen lokalen Schriften bleiben erhalten.

Neu:

- `includes/availability.php`, `includes/admin-auth.php`
- `api/availability.php`, `admin/index.php`
- `js/availability-calendar.js`, `css/availability.css`, `css/admin.css`
- `assets/flatpickr/`: Flatpickr 4.6.13 JS, CSS, deutsche Locale, MIT-Lizenz, Herkunftshinweis
- `bin/init-availability.php`, `bin/hash-admin-password.php`
- `config.example.php` (nur zusätzliche Admin-Konfiguration, kein Ersatz für die SMTP-Konfiguration)
- `tests/availability.php`, `tests/http-flows.php`, diese Anleitung
- Außerhalb des Repositories: `../storage/availability.json` und die zugehörige Lock-Datei

Gelöscht: keine Dateien.

## Docker

Die vorhandene `compose.yaml` wurde nur um folgenden Mount im Service `web` ergänzt:

```yaml
volumes:
  - ./:/var/www/html
  - ../private:/var/www/private:ro
  - ../storage:/var/www/storage
```

`compose.yaml` bleibt entsprechend der vorhandenen Git-Strategie ignoriert. Bei
einem neuen Checkout diesen Mount in die lokale Compose-Datei übernehmen.
`composer.json`, `composer.lock`, `vendor/` und sämtliche SMTP-Einstellungen bleiben unverändert.

Im Ordner `homepage` ausführen:

```powershell
docker compose up -d
docker compose exec web php -v
docker compose exec -u www-data web php bin/init-availability.php
```

Der Speicher wurde lokal bereits initialisiert. Das Skript überschreibt keine
vorhandene Datei. Eine beschädigte Datei wird ausdrücklich nicht zurückgesetzt.
Der Docker-Mount legt den Geschwisterordner `storage` bei Bedarf an. PHP benötigt
darin Schreibzugriff. Im getesteten Docker-Desktop-Mount konnte `www-data` lesen,
schreiben und atomar ersetzen. Ein zusätzlicher PHP-Webserver ist nicht erforderlich.

Falls bei einem neuen Setup `vendor/` fehlt:

```powershell
docker compose run --rm composer install
```

`allowed_host` enthält lokal bereits `localhost`. Dieser Wert bezeichnet nur den
Hostnamen; produktive Hostnamen werden nicht entfernt.

## Adminpasswort einrichten

Es wurde kein Passwort und kein echter Admin-Hash ins Projekt geschrieben.
Der reale Adminzugang bleibt bis zur Einrichtung gesperrt.

1. Im lokalen Terminal ausführen:

   ```powershell
   docker compose exec web php bin/hash-admin-password.php
   ```

2. Ein eigenes Passwort mit 12–72 Bytes zweimal eingeben. Die Eingabe ist verborgen;
   nur der mit `password_hash()` erzeugte Hash wird ausgegeben. Das Skript verändert keine Konfiguration.
3. Diesen Hash als **zusätzlichen** Eintrag in das bestehende Array der Datei
   `../private/config.php` übernehmen:

   ```php
   'admin_password_hash' => 'HIER_DEN_ERZEUGTEN_HASH_EINFUEGEN',
   ```

   Alle bestehenden SMTP- und Host-Einträge erhalten. `config.example.php` niemals
   als vollständigen Ersatz der produktiven Konfiguration verwenden.
4. `http://localhost:8080/admin/` öffnen und anmelden; produktiv ausschließlich HTTPS nutzen.

Die Anmeldung verwendet `password_verify()`. Nach erfolgreichem Login wechseln
Session-ID und CSRF-Token. Cookies sind HttpOnly und SameSite=Strict, bei HTTPS
zusätzlich Secure. Nach 30 Minuten Inaktivität oder spätestens acht Stunden ist
eine erneute Anmeldung nötig. Ein Passwort-Hash-Wechsel entwertet bestehende Anmeldungen.
Fünf Passwortversuche pro IP innerhalb von 15 Minuten sind erlaubt; weitere liefern HTTP 429.
Hinzufügen, Löschen, Login und Logout sind POST-Aktionen mit CSRF-Prüfung.

## Deployment auf Hostinger

Die bestehende produktive Webroot-Zuordnung bleibt erhalten. Wenn der Webroot
`public_html` heißt, muss die Struktur relativ dazu so aussehen:

```text
Domain-Verzeichnis/
├── private/
│   └── config.php                 # bestehende SMTP-Konfiguration + Admin-Hash
├── storage/
│   ├── availability.json          # nur Belegungszeiträume
│   └── availability.json.lock     # automatisch erzeugt, nicht während des Betriebs löschen
└── public_html/
    ├── send.php
    ├── contact.html
    ├── vendor/autoload.php
    ├── admin/index.php
    ├── api/availability.php
    ├── includes/...
    ├── assets/flatpickr/...
    ├── css/...
    ├── js/...
    └── übrige bestehende Website
```

1. **Vorbereitung:** Die aktuellen produktiven Dateien und eine gegebenenfalls
   vorhandene Belegungsdatei sichern. PHP 8.3 wie lokal verwenden (Code benötigt
   mindestens PHP 8.1). PHP-Sessions, JSON, mbstring und OpenSSL müssen verfügbar sein.
   PHP-Version, Erweiterungen und Optionen lassen sich im hPanel prüfen;
   siehe [Hostinger PHP-Konfiguration](https://www.hostinger.com/support/which-php-extensions-and-configuration-options-are-supported-at-hostinger/).
2. **Speicher:** `storage` direkt neben dem vorhandenen Webroot anlegen, niemals darin.
   Falls `availability.json` noch nicht existiert, eine UTF-8-Datei **ohne BOM** mit
   Inhalt `[]` erstellen. Alternativ nach Upload von `bin/init-availability.php`
   über SSH im Webroot `php bin/init-availability.php` ausführen.
   Eine bestehende Datei niemals durch die leere lokale Datei ersetzen.
3. **Rechte:** Der ausführende PHP-Benutzer muss `private/config.php` lesen und in
   `storage` Dateien erstellen, lesen, schreiben und umbenennen dürfen. Bei gleichem
   Eigentümer: Verzeichnis `0700`, Belegungsdatei `0600`; die Anwendung schreibt neue
   JSON-Dateien mit `0600`. Der übergeordnete Pfad muss für PHP durchsuchbar sein.
   Nicht pauschal `0777` setzen. Bei anderem PHP-Benutzer den Eigentümer bzw. gezielte
   Rechte mit dem Hostinganbieter klären. Auch PHPs `open_basedir` muss Zugriff auf
   beide Geschwisterverzeichnisse erlauben. Der tatsächliche produktive Schreibzugriff
   ist noch zu prüfen; der erfolgreiche lokale Test beweist ihn nicht.
   [Hostinger: Dateirechte verwalten](https://www.hostinger.com/support/1583479-how-to-fix-file-permissions-in-hostinger/).
4. **Upload:** `send.php`, `contact.html`, `js/contact-form.js`, `css/style.css` sowie
   die neuen Dateien unter `admin/`, `api/`, `includes/`, `assets/flatpickr/`,
   `js/availability-calendar.js`, `css/availability.css` und `css/admin.css` hochladen.
   Vorhandene sonstige Website-Dateien beibehalten. `tests/`, Docker-Konfiguration,
   `.git/`, Entwicklungswerkzeuge und Dokumentation sind keine Deployment-Dateien.
   Die CLI-Helfer unter `bin/` können optional für die Einrichtung hochgeladen werden;
   sie verweigern HTTP-Aufrufe.
5. **Composer:** Vorhandenes `vendor/` inklusive `vendor/autoload.php` beibehalten bzw.
   das mit dem bestehenden Lockfile erzeugte vollständige Verzeichnis hochladen.
   Bei serverseitigem Composer: `composer install --no-dev --prefer-dist --optimize-autoloader`
   mit dem bestehenden `composer.json` und `composer.lock`. Keine zweite PHPMailer-Installation.
6. **Konfiguration:** Den selbst erzeugten Admin-Hash in die bestehende private
   `config.php` ergänzen. SMTP-Zugangsdaten und produktive `allowed_host`-Werte erhalten.
   Die Pfade in `send.php` bleiben exakt `__DIR__ . '/../private/config.php'` und
   `__DIR__ . '/vendor/autoload.php'`.
7. **HTTPS und Sessions:** HTTPS erzwingen und sicherstellen, dass PHP HTTPS über
   `$_SERVER['HTTPS']` oder Server-Port 443 erkennt. Es werden bewusst keine
   unkontrollierten Forwarded-Header vertraut. Ein vorgeschalteter Proxy muss dies
   auf Serverebene korrekt übergeben. PHP braucht einen schreibbaren Session- und
   Temp-Pfad, auch für die Login- und Kontakt-Ratelimits. Produktiv `display_errors=Off`
   und `log_errors=On` verwenden; Fehlerdetails gehören ins PHP-Log.
8. **Webserver / Cache:** Keine neue `.htaccess` und keine Rewrite-Regel nötig,
   solange PHP-Dateien ausgeführt werden und `/admin/` standardmäßig `index.php`
   öffnet. Bei abweichendem DirectoryIndex gezielt `DirectoryIndex index.php` nur
   für `admin/` ergänzen. Bestehende `.htaccess` nicht überschreiben. `/admin/`,
   `/api/availability.php` und `/send.php` von Hosting-/Proxy-Caches ausnehmen;
   API/Admin senden bereits `Cache-Control: no-store`. Geänderte statische Dateien
   aus einem vorhandenen Cache entfernen. `storage` nicht über Aliase öffentlich machen.
9. **Abnahme:** API mit HTTP 200 und `success:true` prüfen. Unter HTTPS Cookie-Flags
   und Login testen, eine kontrollierte Belegung hinzufügen, im öffentlichen
   Kalender prüfen und anschließend wieder löschen. Erst nach dieser Abnahme
   den Kalender produktiv als einsatzbereit betrachten. Einen echten SMTP-Test
   nur bewusst durchführen; die Implementierung hat keine echten Testmails versandt.

Der vorhandene GitHub-Pages-Workflow bleibt unverändert. GitHub Pages führt kein
PHP aus und ist daher kein Laufzeit-Ziel für diese Funktion; das Ziel ist Hostinger.

## Speicherfehler und Wiederherstellung

Eine fehlende, leere, beschädigte oder unlesbare Datei bedeutet **nicht** „alles frei“.
Die API und das Formular melden HTTP 503, der Kalender sperrt die Auswahl.
Bei fehlenden Schreibrechten verweigert die Verwaltung die Änderung.

Leser und Schreiber verwenden dieselbe separate Lock-Datei. Unter exklusivem Lock
werden Daten erneut gelesen, Überschneidungen geprüft und neue Daten in eine temporäre
Datei im selben Ordner geschrieben. Nach `fflush()` und `fsync()` ersetzt `rename()`
die JSON-Datei atomar. Leser nutzen Shared Locks. Nach zwei Sekunden ohne Lock
wird mit Fehler abgebrochen. Die Datei enthält ausschließlich robuste Zufalls-IDs
und `from`/`to`, keine Gästedaten.

Regelmäßig die Belegungsdatei außerhalb des Webroots sichern. Wiederherstellungen
bei pausierten Admin-Schreibzugriffen durchführen und anschließend die API prüfen.
Nicht während laufender Anfragen die Lock-Datei austauschen. Das ist eine Lösung
für einen Webserver mit gemeinsamem lokalen Dateisystem, kein verteiltes Buchungssystem.

## Tests

```powershell
docker compose exec -u www-data web php tests/availability.php
docker compose exec -u www-data web php tests/http-flows.php
docker compose exec web php -l send.php
docker compose exec web php -l admin/index.php
```

Die PHP-Tests verwenden temporäre eigene Daten. Die Integrationstests kopieren die
betroffenen PHP-Dateien in einen isolierten Testbaum und ersetzen dort den Mailer
durch eine Attrappe ohne Netzwerkzugriff. Die reale Konfiguration wird nicht verändert.

Ausgeführt unter Windows + Docker Desktop, PHP 8.3.33:

| Test | Ergebnis |
| --- | --- |
| A: keine Belegungen | PHP und Browser bestanden |
| B: Zeitraum vor Belegung | PHP und Browser bestanden |
| C: Überschneidung / belegten Zeitraum überspringen | PHP und Browser gesperrt |
| D: Anreise am Abreisetag | PHP und Browser erlaubt |
| E/F: Abreise am Beginn der nächsten Belegung | PHP und Browser erlaubt |
| G: letzte belegte Nacht als Anreise | PHP und Browser gesperrt |
| H: manipulierter direkter POST | HTTP 409, kein PHPMailer geladen |
| I: Admin-Überschneidung | HTTP 409, keine Speicherung |
| J: angrenzende Admin-Belegungen | erfolgreich gespeichert |
| K: Admin ohne Login | nur Login, keine Verwaltungsdaten oder Schreibaktionen |
| L: fehlendes/falsches CSRF | HTTP 403 für Hinzufügen, Löschen, Logout |
| M: API-Ausfall | Kalender deaktiviert; erneutes Laden funktioniert |
| Beschädigtes JSON, leere/fehlende Datei | gesperrt, Daten nicht überschrieben |
| Lese-/Schreibrechte, Lock-Timeout | Fehler zuverlässig erkannt |
| Konkurrierende überlappende Schreibzugriffe | genau eine Speicherung |
| Ungültige Daten, Vergangenheit, manipulierte ID/POST-Arrays | abgewiesen |
| Login, Session-ID-Wechsel, Ablauf, Logout, Ratelimit | bestanden |
| PHPMailer-Autoload, HTML/Plaintext, Reply-To, Bestätigung | mit bestehendem Autoload bzw. isolierter Attrappe geprüft |
| Honeypot, Origin, POST-Prüfung, Header-Injection, Kontakt-Ratelimit | erhalten und geprüft |
| Anfrage erzeugt keine Belegung | bestanden |
| Desktop 1440 px, Smartphones 375/320 px | zwei/ein Monat, kein horizontaler Überlauf |
| Tastatur | Pfeiltaste öffnet Tagesnavigation, Enter wählt Anreise und Abreise |
| Browser-Konsole / Netzwerk | keine JavaScript-Fehler oder Konsolenwarnungen, keine fehlgeschlagenen Ressourcen im normalen Betrieb, keine externen Seitenrequests |

Browserprüfung: Headless Chrome über temporäres Playwright, da der Browser-Plugin-Skill
nicht verfügbar war. Kontaktkalender mit abgefangenen Test-API-Antworten; echte HTTP-
Admin- und API-Tests zusätzlich über einen temporären isolierten Alias im bestehenden
Docker-Apache. Dieser Testbereich wurde wieder entfernt. Kein weiterer Webserver.

Offen für die Inbetriebnahme: eigenes Adminpasswort konfigurieren, Hostinger-Deployment
und produktive Rechte/HTTPS/Sessions prüfen. Ein echter SMTP-Versand wurde bewusst
nicht ausgeführt. Safari und Firefox wurden nicht separat getestet.

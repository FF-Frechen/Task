# Task

Eine bewusst einfache Aufgaben-App für ein kleines Team, gedacht vor allem für das **Smartphone**.

- **Drei Spalten:** Ideen → In Bearbeitung → Erledigt
- **Idee eintippen + Enter**, fertig. Optional gleich festlegen, *für wen* sie ist.
- **Zuständige** sieht man als farbige Kreise auf jeder Karte (jeder Benutzer hat seine Farbe).
- **Farbmarkierung** pro Aufgabe (z. B. Rot = dringend), frei nach Absprache.
- **Ein Tipp** auf „▶ Starten“ / „✓ Erledigt“ schiebt die Karte weiter.
  Wer eine Aufgabe ohne Zuständige startet, wird automatisch zuständig.
- **Fotos & Dateien** an Aufgaben hängen (am Handy direkt aus der Kamera oder Mediathek).
  Bilder erscheinen als Vorschau, die Karte zeigt 📎 mit der Anzahl.
- **Speicherlimit 10 GB** für alle Dateien zusammen. Ist es voll, kommt eine Fehlermeldung,
  und man muss erst alte Dateien oder Aufgaben löschen.
- **Filter:** „Alle“, pro Person, „Niemand zugeteilt“.
- Am Handy: Spalten als Tabs, zum Wechseln auch wischen. Am PC: Karten per Drag & Drop verschieben.
- Änderungen der anderen erscheinen automatisch (alle 15 Sekunden bzw. beim Zurückkehren in die App).
- Lässt sich auf den Home-Bildschirm legen und startet dann wie eine App.

Technik: PHP 8.1+ und SQLite. Keine Datenbank-Einrichtung, kein Composer, kein Build-Schritt.
Die App läuft direkt in `httpdocs`. Am Dokumentenstamm muss nichts geändert werden.

---

## Installation in Plesk

### 1. Domain und HTTPS

1. Domain oder Subdomain anlegen, z. B. `task.example.de`.
2. **SSL/TLS-Zertifikate** → Let's Encrypt-Zertifikat ausstellen.
3. In den **Hosting-Einstellungen** „Permanente SEO-sichere 301-Weiterleitung von HTTP zu HTTPS“ einschalten.
4. **PHP-Einstellungen:** PHP 8.1 oder neuer (getestet bis 8.5). `pdo_sqlite` ist in Plesk standardmäßig aktiv.
   Alle anderen Werte können auf „Standard“ bleiben (zu `open_basedir` siehe unten).
5. Optional: Im **Dateimanager** die Plesk-Standarddateien in `httpdocs` löschen (`index.html`, Ordner `css`,
   `img` usw.). Nötig ist das nicht, die `.htaccess` sorgt dafür, dass trotzdem Task erscheint.

### 2. Code per Git holen

1. **Git** → Repository hinzufügen → „Remote Git-Hosting“.
2. Repository-URL: `git@github.com:FF-Frechen/task.git` (SSH, da das Repo privat ist).
3. Plesk zeigt einen **öffentlichen SSH-Schlüssel**. Diesen in GitHub unter
   *FF-Frechen/task → Settings → Deploy keys → Add deploy key* eintragen (nur Lesezugriff).
4. Bereitstellungsmodus „Automatisch“, Bereitstellungspfad `httpdocs`.
5. Aktualisierungen: In Plesk auf **„Aktualisierungen abrufen“** klicken.
   Wer es automatisch möchte, trägt die von Plesk angezeigte Webhook-URL in GitHub unter
   *Settings → Webhooks* ein.

### 3. Ersteinrichtung

1. Die Domain im Browser öffnen. Es erscheint **Ersteinrichtung**.
2. Den **Einrichtungscode** findest du im Plesk-Dateimanager in der Datei `SETUP-CODE`.
   Die Einrichtungsseite zeigt an, in welchem Ordner sie liegt (siehe „Wo liegen die Daten?“).
   Die Datei wird nach der Einrichtung automatisch gelöscht.
3. Ersten Benutzer anlegen. Er wird Administrator.
4. Unter **Konto & Benutzer** (Klick auf den eigenen Kreis oben rechts) die anderen Benutzer anlegen
   und ihnen ihr Startpasswort geben. Jeder kann sein Passwort danach selbst ändern.

> Tipp: Mindestens **zwei Administratoren** anlegen. Dann kann einer dem anderen ein neues Passwort
> setzen, falls es vergessen wurde.

### 4. Aufs Handy legen

- **iPhone (Safari):** Teilen-Symbol → „Zum Home-Bildschirm“.
- **Android (Chrome):** Menü ⋮ → „App installieren“ bzw. „Zum Startbildschirm hinzufügen“.

Beim Login „Angemeldet bleiben“ ankreuzen, dann bleibt man 30 Tage angemeldet.

---

## Sicherheit

- Nur über **HTTPS** erreichbar (HTTP wird umgeleitet, HSTS aktiv).
- Passwörter mit `password_hash` (bcrypt/argon2) gespeichert, mindestens 6 Zeichen.
- **Schutz gegen Passwort-Raten:** Nach 5 Fehlversuchen pro Benutzer bzw. 20 pro IP-Adresse
  ist der Login 15 Minuten gesperrt.
- Sitzungs-Cookie: `HttpOnly`, `Secure`, `SameSite=Lax`, `__Host-`-Präfix, neue Sitzungs-ID beim Login.
- Abmeldung nach 12 h Inaktivität (bzw. 30 Tage mit „Angemeldet bleiben“).
- Passwortänderung meldet alle anderen Geräte dieses Benutzers ab.
- CSRF-Token bei allen Änderungen, nur vorbereitete SQL-Statements, konsequentes Escaping.
- Strenge Content-Security-Policy (kein Inline-JavaScript), Schutz gegen Einbetten (Clickjacking),
  `noindex` für Suchmaschinen.
- Datenbank und Sitzungen sind aus dem Internet nicht abrufbar (siehe „Wo liegen die Daten?“).
- Interne Dateien (`src/`, `README.md`, Konfiguration, Datenordner) sind per `.htaccess` gesperrt (mit Apache 2.4 getestet).
- Ersteinrichtung nur mit dem Einrichtungscode vom Server möglich.

## Wo liegen die Daten?

Das hängt von der Plesk-Einstellung **open_basedir** ab (PHP-Einstellungen der Domain):

| open_basedir | Speicherort | Einschätzung |
|---|---|---|
| `{DOCROOT}{/}{:}{TMP}{/}…` **(Standard)** | `httpdocs/data-<Zufallsname>/` | sicher: per `.htaccess` gesperrt, Name nicht erratbar |
| `{WEBSPACEROOT}{/}{:}{TMP}{/}{:}/var/lib/php/sessions` | `task-data/` neben `httpdocs` | am sichersten: liegt gar nicht im Web-Ordner |

Beides funktioniert. Die Standard-Einstellung hat den Vorteil, dass PHP dieser Domain keine Dateien
anderer Websites im selben Webspace lesen kann. Wechselt man später auf die zweite Option, ziehen die
Daten beim nächsten Aufruf automatisch um.

## Dateianhänge

- Im Aufgaben-Dialog unter **Dateien** auf „＋ Foto / Datei“ tippen. Mehrere auf einmal sind möglich.
- **Gesamtlimit 10 GB** (änderbar über `storage_limit_gb` in `config.local.php`). Ist es erreicht,
  wird der Upload mit der Meldung „Speicher voll … Bitte zuerst alte Dateien oder Aufgaben löschen.“ abgelehnt.
- **Größe pro Datei** begrenzt Plesk über `upload_max_filesize` und `post_max_size`
  (PHP-Einstellungen, Standard bei euch 128M → ca. 127 MB pro Datei). Für größere Videos dort beide Werte erhöhen.
- Wird eine Aufgabe gelöscht, werden ihre Dateien mitgelöscht.
- Dateien liegen im gesperrten Datenordner unter Zufallsnamen und werden nur nach Login ausgeliefert.
  Nur Bilder, Videos und Audio zeigt der Browser direkt an; alles andere (z. B. HTML) wird nur heruntergeladen.

## Datensicherung

Alle Daten stecken in der Datei `task.sqlite` und im Unterordner `files/` (Ort siehe oben). Das normale Plesk-Backup des Webspace
sichert sie mit. Sie ist nicht im Git-Repo und wird bei Updates nicht angefasst.

## Einstellungen (optional)

`config.example.php` nach `config.local.php` kopieren und anpassen, z. B. App-Name,
Ablageort der Daten, Dauer der Anmeldung oder Login-Sperre. `config.local.php` bleibt bei Updates erhalten.

## Lokal ausprobieren

```bash
php -S localhost:8080
```

Dann <http://localhost:8080> öffnen. Der Einrichtungscode steht in `../task-data/SETUP-CODE`.

## Aufbau

```
httpdocs/            ← Repo-Inhalt (Plesk-Git-Bereitstellung)
  index.php          Board
  login.php          Anmeldung
  setup.php          Ersteinrichtung
  users.php          Mein Konto & Benutzerverwaltung
  api.php            JSON-Schnittstelle für das Board (inkl. Upload)
  file.php           Liefert Dateianhänge aus (nur angemeldet)
  assets/            CSS, JavaScript, Icons
  src/               Konfiguration, Sicherheit, Session, Datenbank (gesperrt)
task-data/           ← Datenbank & Sitzungen, wird automatisch angelegt (nicht im Web, nicht im Git)
```

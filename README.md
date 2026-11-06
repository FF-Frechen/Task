# Task

Eine bewusst einfache Aufgaben-App für ein kleines Team, gedacht vor allem für das **Smartphone**.

- **Drei Spalten:** Ideen → In Bearbeitung → Erledigt
- **Idee eintippen + Enter**, fertig. Optional gleich festlegen, *für wen* sie ist.
- **Zuständige** sieht man als farbige Kreise auf jeder Karte (jeder Benutzer hat seine Farbe).
- **Farbmarkierung** pro Aufgabe (z. B. Rot = dringend), frei nach Absprache.
- **Ein Tipp** auf „▶ Starten“ / „✓ Erledigt“ schiebt die Karte weiter.
  Wer eine Aufgabe ohne Zuständige startet, wird automatisch zuständig.
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
4. **PHP-Einstellungen:** PHP 8.1 oder neuer (8.3 empfohlen). `pdo_sqlite` ist in Plesk standardmäßig aktiv.
5. Im **Dateimanager** die Plesk-Standarddateien in `httpdocs` löschen (`index.html`, Ordner `css`, `img`,
   `favicon.ico` usw.), sonst erscheint statt Task die Plesk-Willkommensseite.

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
2. Den **Einrichtungscode** findest du im Plesk-Dateimanager in der Datei `task-data/SETUP-CODE`.
   Der Ordner `task-data` liegt **neben** `httpdocs` (ganz oben in der Ordnerliste), also nicht im Web.
   Die Datei wird nach der Einrichtung automatisch gelöscht. Die Seite zeigt den genauen Ort ebenfalls an.
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
- Passwörter mit `password_hash` (bcrypt/argon2) gespeichert, mindestens 10 Zeichen.
- **Schutz gegen Passwort-Raten:** Nach 5 Fehlversuchen pro Benutzer bzw. 20 pro IP-Adresse
  ist der Login 15 Minuten gesperrt.
- Sitzungs-Cookie: `HttpOnly`, `Secure`, `SameSite=Lax`, `__Host-`-Präfix, neue Sitzungs-ID beim Login.
- Abmeldung nach 12 h Inaktivität (bzw. 30 Tage mit „Angemeldet bleiben“).
- Passwortänderung meldet alle anderen Geräte dieses Benutzers ab.
- CSRF-Token bei allen Änderungen, nur vorbereitete SQL-Statements, konsequentes Escaping.
- Strenge Content-Security-Policy (kein Inline-JavaScript), Schutz gegen Einbetten (Clickjacking),
  `noindex` für Suchmaschinen.
- Datenbank und Sitzungen liegen im Ordner `task-data` **neben** `httpdocs`, also außerhalb des Web-Ordners.
  Sie sind aus dem Internet grundsätzlich nicht abrufbar.
  Notlösung, falls PHP dort nicht schreiben darf: ein Unterordner `data-<Zufallsname>` in `httpdocs`,
  gesperrt per `.htaccess`. Admins sehen dann oben einen Hinweis.
- Interne Dateien (`src/`, `README.md`, Konfiguration) sind per `.htaccess` gesperrt.
- Ersteinrichtung nur mit dem Einrichtungscode vom Server möglich.

## Datensicherung

Alle Daten stecken in der Datei `task-data/task.sqlite` (neben `httpdocs`). Das normale Plesk-Backup
des Webspace sichert sie mit. Der Ordner ist nicht im Git-Repo und wird bei Updates nicht angefasst.

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
  api.php            JSON-Schnittstelle für das Board
  assets/            CSS, JavaScript, Icons
  src/               Konfiguration, Sicherheit, Session, Datenbank (gesperrt)
task-data/           ← Datenbank & Sitzungen, wird automatisch angelegt (nicht im Web, nicht im Git)
```

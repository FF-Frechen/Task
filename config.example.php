<?php
/**
 * Optionale Einstellungen. Zum Verwenden diese Datei als "config.local.php"
 * in denselben Ordner kopieren und anpassen. config.local.php wird nicht
 * ins Git-Repo übernommen und bei Deployments nicht überschrieben.
 */
return [
    // Name oben links und im Browser-Tab
    'app_name' => 'Task',

    // Ablageort für Datenbank & Sitzungen. Standard (null): automatisch der Ordner
    // "task-data" eine Ebene über dem App-Ordner, also neben httpdocs.
    // Nur setzen, wenn ein anderer Ort gewünscht ist (immer außerhalb von httpdocs!):
    // 'data_dir' => '/var/www/vhosts/example.de/task-data',

    // HTTP automatisch auf HTTPS umleiten (sollte an bleiben)
    'force_https' => true,

    // Abmeldung nach so vielen Sekunden Inaktivität (ohne "Angemeldet bleiben")
    'session_idle' => 12 * 3600,

    // "Angemeldet bleiben" gilt so viele Tage
    'remember_days' => 30,

    // Login-Sperre: nach X Fehlversuchen pro Benutzer bzw. IP für Y Minuten
    'max_fails_user' => 5,
    'max_fails_ip'   => 20,
    'lock_minutes'   => 15,

    // Speicherlimit für alle Dateianhänge zusammen (in GB). Ist es erreicht,
    // muss erst etwas gelöscht werden, bevor neue Dateien hochgeladen werden können.
    'storage_limit_gb' => 10,
];

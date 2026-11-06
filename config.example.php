<?php
/**
 * Optionale Einstellungen. Zum Verwenden diese Datei als "config.local.php"
 * in denselben Ordner kopieren und anpassen. config.local.php wird nicht
 * ins Git-Repo übernommen und bei Deployments nicht überschrieben.
 */
return [
    // Name oben links und im Browser-Tab
    'app_name' => 'Task',

    // Ablageort für Datenbank & Sessions. Standard: Ordner "data" im Projekt.
    // Tipp: außerhalb von httpdocs ablegen, z. B. '/var/www/vhosts/example.de/task-data'
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
];

<?php
/** Liefert einen Dateianhang aus – nur für angemeldete Benutzer. */
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/files.php';

if (current_user() === null) {
    redirect('login.php');
}
session_write_close();

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if ($id === false) {
    http_response_code(404);
    exit;
}
send_file($id, isset($_GET['thumb']), isset($_GET['dl']));

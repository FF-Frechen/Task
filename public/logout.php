<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_post();
    end_session();
}
redirect('login.php');

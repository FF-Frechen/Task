<?php
/**
 * JSON-API für das Board.
 *   GET  api.php?action=state           → Benutzer + Aufgaben
 *   GET  api.php?action=files&task=ID   → Dateien einer Aufgabe + Speicherbelegung
 *   POST api.php?action=create|update|move|delete|delete_file   (JSON-Body, Header X-CSRF-Token)
 *   POST api.php?action=upload           (multipart: task, file, thumb; Header X-CSRF-Token)
 */
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/files.php';

header('Content-Type: application/json; charset=utf-8');

function respond(mixed $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $status = 400): never
{
    respond(['error' => $message], $status);
}

$me = current_user();
if ($me === null) {
    fail('Nicht angemeldet.', 401);
}

$action = (string)($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET' && $action === 'state') {
    session_write_close();
    respond(load_state());
}
if ($method === 'GET' && $action === 'files') {
    session_write_close();
    $taskId = existing_task_id($_GET['task'] ?? null);
    respond(['files' => task_files($taskId), 'storage' => storage_info()]);
}

if ($method !== 'POST') {
    fail('Methode nicht erlaubt.', 405);
}
if (!csrf_valid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    fail('Sitzung abgelaufen – bitte Seite neu laden.', 403);
}
session_write_close();

if ($action === 'upload') {
    if (empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        fail('Die Datei ist zu groß (max. ' . format_bytes(max_upload_size()) . ' pro Datei).', 413);
    }
    $taskId = existing_task_id($_POST['task'] ?? null);
    $file = $_FILES['file'] ?? null;
    if (!is_array($file) || is_array($file['name'] ?? null)) {
        fail('Es wurde keine Datei übertragen.');
    }
    $thumb = isset($_FILES['thumb']) && !is_array($_FILES['thumb']['name'] ?? null) ? $_FILES['thumb'] : null;
    try {
        store_upload($taskId, $file, $thumb, $me['id']);
    } catch (UploadError $e) {
        fail($e->getMessage(), $e->getCode() ?: 400);
    }
    respond(['ok' => true, 'files' => task_files($taskId), 'storage' => storage_info()] + load_state());
}

$raw = file_get_contents('php://input', false, null, 0, 65536);
$in = json_decode($raw ?: '{}', true);
if (!is_array($in)) {
    fail('Ungültige Anfrage.');
}

$pdo = db();

switch ($action) {
    case 'create':
        $title = clean_title($in['title'] ?? '');
        $status = clean_status($in['status'] ?? 'idea');
        $pdo->beginTransaction();
        $pdo->prepare(
            "INSERT INTO tasks (title, description, status, color, position, created_by, done_at)
             VALUES (?, ?, ?, ?, ?, ?, CASE WHEN ? = 'done' THEN datetime('now') END)"
        )->execute([
            $title,
            clean_description($in['description'] ?? ''),
            $status,
            clean_color($in['color'] ?? null),
            top_position($status),
            $me['id'],
            $status,
        ]);
        $id = (int)$pdo->lastInsertId();
        set_assignees($id, $in['assignees'] ?? []);
        $pdo->commit();
        respond(['ok' => true, 'id' => $id] + load_state());

    case 'update':
        $id = existing_task_id($in['id'] ?? null);
        $pdo->beginTransaction();
        $fields = [];
        $params = [];
        if (array_key_exists('title', $in)) {
            $fields[] = 'title = ?';
            $params[] = clean_title($in['title']);
        }
        if (array_key_exists('description', $in)) {
            $fields[] = 'description = ?';
            $params[] = clean_description($in['description']);
        }
        if (array_key_exists('color', $in)) {
            $fields[] = 'color = ?';
            $params[] = clean_color($in['color']);
        }
        if ($fields) {
            $fields[] = "updated_at = datetime('now')";
            $params[] = $id;
            $pdo->prepare('UPDATE tasks SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
        }
        if (array_key_exists('assignees', $in)) {
            set_assignees($id, $in['assignees']);
        }
        if (array_key_exists('status', $in)) {
            change_status($id, clean_status($in['status']));
        }
        $pdo->commit();
        respond(['ok' => true] + load_state());

    case 'move':
        // {id, status, order: [ids der Zielspalte in neuer Reihenfolge]}
        $id = existing_task_id($in['id'] ?? null);
        $status = clean_status($in['status'] ?? '');
        $pdo->beginTransaction();
        change_status($id, $status, false);
        $order = is_array($in['order'] ?? null) ? array_slice($in['order'], 0, 2000) : [$id];
        $stmt = $pdo->prepare('UPDATE tasks SET position = ? WHERE id = ? AND status = ?');
        foreach (array_values($order) as $pos => $taskId) {
            $stmt->execute([$pos, (int)$taskId, $status]);
        }
        $pdo->commit();
        respond(['ok' => true] + load_state());

    case 'delete':
        $id = existing_task_id($in['id'] ?? null);
        delete_files('task_id = ?', [$id]);
        $pdo->prepare('DELETE FROM tasks WHERE id = ?')->execute([$id]);
        respond(['ok' => true] + load_state());

    case 'delete_file':
        $fileId = filter_var($in['id'] ?? null, FILTER_VALIDATE_INT);
        $stmt = $pdo->prepare('SELECT task_id FROM files WHERE id = ?');
        $stmt->execute([$fileId === false ? 0 : $fileId]);
        $taskId = $stmt->fetchColumn();
        if ($taskId === false) {
            fail('Diese Datei gibt es nicht mehr.', 404);
        }
        delete_files('id = ?', [$fileId]);
        respond(['ok' => true, 'files' => task_files((int)$taskId), 'storage' => storage_info()] + load_state());

    default:
        fail('Unbekannte Aktion.', 404);
}

/* ------------------------------------------------------------------ */

function load_state(): array
{
    $pdo = db();
    $users = $pdo->query('SELECT id, username, color FROM users ORDER BY username COLLATE NOCASE')->fetchAll();
    foreach ($users as &$u) {
        $u['id'] = (int)$u['id'];
    }
    unset($u);

    $rows = $pdo->query(
        "SELECT t.id, t.title, t.description, t.status, t.color, t.position, t.created_by,
                t.created_at, t.updated_at, t.done_at,
                (SELECT GROUP_CONCAT(tu.user_id) FROM task_users tu WHERE tu.task_id = t.id) AS assignees,
                (SELECT COUNT(*) FROM files f WHERE f.task_id = t.id) AS file_count
           FROM tasks t
          ORDER BY CASE WHEN t.status = 'done' THEN 1 ELSE 0 END,
                   CASE WHEN t.status = 'done' THEN t.done_at END DESC,
                   t.position, t.id DESC"
    )->fetchAll();

    $tasks = array_map(static fn(array $t): array => [
        'id'          => (int)$t['id'],
        'title'       => $t['title'],
        'description' => $t['description'],
        'status'      => $t['status'],
        'color'       => $t['color'],
        'createdBy'   => $t['created_by'] !== null ? (int)$t['created_by'] : null,
        'createdAt'   => iso($t['created_at']),
        'updatedAt'   => iso($t['updated_at']),
        'doneAt'      => iso($t['done_at']),
        'assignees'   => $t['assignees'] ? array_map('intval', explode(',', $t['assignees'])) : [],
        'fileCount'   => (int)$t['file_count'],
    ], $rows);

    return ['me' => current_user()['id'], 'users' => $users, 'tasks' => $tasks];
}

function clean_title(mixed $title): string
{
    $title = is_string($title) ? trim(preg_replace('/\s+/u', ' ', $title) ?? '') : '';
    if ($title === '') {
        fail('Bitte einen Titel eingeben.');
    }
    if (str_length($title) > 200) {
        fail('Der Titel ist zu lang (max. 200 Zeichen).');
    }
    return $title;
}

function clean_description(mixed $text): string
{
    $text = is_string($text) ? trim(str_replace("\r\n", "\n", $text)) : '';
    if (str_length($text) > 5000) {
        fail('Die Beschreibung ist zu lang (max. 5000 Zeichen).');
    }
    return $text;
}

function clean_status(mixed $status): string
{
    if (!is_string($status) || !in_array($status, STATUSES, true)) {
        fail('Ungültiger Status.');
    }
    return $status;
}

function clean_color(mixed $color): ?string
{
    if ($color === null || $color === '') {
        return null;
    }
    if (!valid_color($color)) {
        fail('Ungültige Farbe.');
    }
    return $color;
}

function existing_task_id(mixed $id): int
{
    $id = filter_var($id, FILTER_VALIDATE_INT);
    if ($id === false) {
        fail('Ungültige Aufgabe.');
    }
    $stmt = db()->prepare('SELECT 1 FROM tasks WHERE id = ?');
    $stmt->execute([$id]);
    if (!$stmt->fetchColumn()) {
        fail('Diese Aufgabe gibt es nicht mehr.', 404);
    }
    return $id;
}

function top_position(string $status): int
{
    $stmt = db()->prepare('SELECT COALESCE(MIN(position), 0) - 1 FROM tasks WHERE status = ?');
    $stmt->execute([$status]);
    return (int)$stmt->fetchColumn();
}

function set_assignees(int $taskId, mixed $ids): void
{
    $pdo = db();
    $ids = is_array($ids) ? array_unique(array_map('intval', array_slice($ids, 0, 100))) : [];
    $pdo->prepare('DELETE FROM task_users WHERE task_id = ?')->execute([$taskId]);
    $stmt = $pdo->prepare('INSERT INTO task_users (task_id, user_id) SELECT ?, id FROM users WHERE id = ?');
    foreach ($ids as $userId) {
        $stmt->execute([$taskId, $userId]);
    }
}

function change_status(int $id, string $status, bool $toTop = true): void
{
    $stmt = db()->prepare('SELECT status FROM tasks WHERE id = ?');
    $stmt->execute([$id]);
    if ($stmt->fetchColumn() === $status) {
        return;
    }
    $position = $toTop ? top_position($status) : 0;
    db()->prepare(
        "UPDATE tasks SET status = ?, position = ?, updated_at = datetime('now'),
                done_at = CASE WHEN ? = 'done' THEN datetime('now') ELSE NULL END
          WHERE id = ?"
    )->execute([$status, $position, $status, $id]);

    // Wer eine Aufgabe ohne Zuständige startet, übernimmt sie automatisch.
    if ($status === 'doing') {
        db()->prepare(
            'INSERT INTO task_users (task_id, user_id)
             SELECT ?, ? WHERE NOT EXISTS (SELECT 1 FROM task_users WHERE task_id = ?)'
        )->execute([$id, current_user()['id'], $id]);
    }
}

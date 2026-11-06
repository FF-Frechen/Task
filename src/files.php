<?php
/**
 * Dateianhänge an Aufgaben: speichern, auflisten, löschen, ausliefern.
 *
 * Dateien liegen im (gesperrten) Datenordner unter files/<zufallsname>,
 * ohne Dateiendung, und werden nur über file.php nach Login ausgeliefert.
 */
declare(strict_types=1);

if (!defined('APP_DIR')) {
    http_response_code(404);
    exit;
}

/** Typen, die der Browser direkt anzeigen darf. Alles andere wird heruntergeladen. */
const INLINE_TYPES = [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif',
    'video/mp4', 'video/quicktime', 'video/webm', 'audio/mpeg', 'audio/mp4',
];
/** Bildtypen, für die ein Vorschaubild angenommen wird. */
const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];

final class UploadError extends RuntimeException
{
}

function files_dir(): string
{
    $dir = data_dir() . '/files';
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new UploadError('Der Ordner für Dateien kann nicht angelegt werden.', 500);
    }
    return $dir;
}

function storage_limit(): int
{
    return (int)round((float)cfg('storage_limit_gb') * 1024 ** 3);
}

function storage_used(): int
{
    return (int)db()->query('SELECT COALESCE(SUM(size), 0) FROM files')->fetchColumn();
}

function ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $factor = match (strtolower(substr($value, -1))) {
        'g' => 1024 ** 3,
        'm' => 1024 ** 2,
        'k' => 1024,
        default => 1,
    };
    return (int)((float)$value * $factor);
}

/** Größte mögliche Datei pro Upload laut PHP-Einstellungen (mit Reserve für Vorschaubild & Felder). */
function max_upload_size(): int
{
    $upload = ini_bytes((string)ini_get('upload_max_filesize')) ?: PHP_INT_MAX;
    $post = ini_bytes((string)ini_get('post_max_size')) ?: PHP_INT_MAX;
    $reserve = min(1024 * 1024, intdiv($post, 8));
    return max(0, min($upload, $post - $reserve));
}

function storage_info(): array
{
    return ['used' => storage_used(), 'limit' => storage_limit(), 'maxFile' => max_upload_size()];
}

function format_bytes(int $bytes): string
{
    $units = ['Bytes', 'KB', 'MB', 'GB'];
    $i = 0;
    $value = (float)$bytes;
    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }
    return str_replace('.', ',', (string)round($value, $i >= 2 ? 1 : 0)) . ' ' . $units[$i];
}

function task_files(int $taskId): array
{
    $stmt = db()->prepare(
        'SELECT id, name, mime, size, has_thumb, uploaded_by, created_at FROM files WHERE task_id = ? ORDER BY id'
    );
    $stmt->execute([$taskId]);
    return array_map(static fn(array $f): array => [
        'id'         => (int)$f['id'],
        'name'       => $f['name'],
        'mime'       => $f['mime'],
        'size'       => (int)$f['size'],
        'thumb'      => (bool)$f['has_thumb'],
        'uploadedBy' => $f['uploaded_by'] !== null ? (int)$f['uploaded_by'] : null,
        'createdAt'  => iso($f['created_at']),
    ], $stmt->fetchAll());
}

function clean_filename(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '', " .");
    if ($name === '') {
        return 'Datei';
    }
    if (str_length($name) > 150) {
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $ext = ($ext !== '' && strlen($ext) <= 10) ? '.' . $ext : '';
        $base = function_exists('mb_substr') ? mb_substr($name, 0, 140, 'UTF-8') : substr($name, 0, 140);
        $name = $base . $ext;
    }
    return $name;
}

function detect_mime(string $path, string $name): string
{
    $mime = '';
    if (class_exists('finfo')) {
        $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($path);
    }
    if ($mime === '' || $mime === 'application/octet-stream') {
        $byExt = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
            'webp' => 'image/webp', 'heic' => 'image/heic', 'heif' => 'image/heif',
            'mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'pdf' => 'application/pdf',
            'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'txt' => 'text/plain',
        ];
        $mime = $byExt[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    }
    return preg_match('#^[\w.+-]+/[\w.+-]+$#', $mime) ? strtolower($mime) : 'application/octet-stream';
}

function upload_error_message(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
            'Die Datei ist zu groß (max. ' . format_bytes(max_upload_size()) . ' pro Datei).',
        UPLOAD_ERR_PARTIAL => 'Der Upload wurde unterbrochen. Bitte erneut versuchen.',
        UPLOAD_ERR_NO_FILE => 'Es wurde keine Datei übertragen.',
        default => 'Die Datei konnte auf dem Server nicht gespeichert werden.',
    };
}

/**
 * Speichert eine hochgeladene Datei zu einer Aufgabe.
 * Wirft UploadError (Code = HTTP-Status), z. B. wenn das Speicherlimit erreicht ist.
 */
function store_upload(int $taskId, array $file, ?array $thumb, int $userId): int
{
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        throw new UploadError(upload_error_message($error), $error === UPLOAD_ERR_INI_SIZE ? 413 : 400);
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if (!is_uploaded_file($tmp)) {
        throw new UploadError('Ungültiger Upload.', 400);
    }
    $size = (int)filesize($tmp);
    if ($size > max_upload_size()) {
        throw new UploadError(upload_error_message(UPLOAD_ERR_INI_SIZE), 413);
    }

    $name = clean_filename((string)($file['name'] ?? ''));
    $mime = detect_mime($tmp, $name);
    $dir = files_dir();

    $free = @disk_free_space($dir);
    if ($free !== false && $free < $size + 50 * 1024 * 1024) {
        throw new UploadError('Auf dem Server ist kein Speicherplatz mehr frei.', 507);
    }

    $key = bin2hex(random_bytes(16));
    $dest = $dir . '/' . $key;
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $used = storage_used();
        $limit = storage_limit();
        if ($used + $size > $limit) {
            throw new UploadError(
                'Speicher voll: Das Limit von ' . format_bytes($limit) . ' ist erreicht'
                . ' (noch ' . format_bytes(max(0, $limit - $used)) . ' frei). '
                . 'Bitte zuerst alte Dateien oder Aufgaben löschen.',
                413
            );
        }
        if (!move_uploaded_file($tmp, $dest)) {
            throw new UploadError(upload_error_message(UPLOAD_ERR_CANT_WRITE), 500);
        }
        @chmod($dest, 0640);

        $hasThumb = false;
        if ($thumb !== null && in_array($mime, IMAGE_TYPES, true) && valid_thumb($thumb)) {
            $hasThumb = move_uploaded_file($thumb['tmp_name'], $dest . '.thumb');
        }

        $pdo->prepare(
            'INSERT INTO files (task_id, name, mime, size, storage_key, has_thumb, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$taskId, $name, $mime, $size, $key, $hasThumb ? 1 : 0, $userId]);
        $id = (int)$pdo->lastInsertId();
        $pdo->exec('COMMIT');
        return $id;
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        @unlink($dest);
        @unlink($dest . '.thumb');
        throw $e;
    }
}

/** Vorschaubild (vom Browser erzeugt): nur kleines JPEG wird angenommen. */
function valid_thumb(array $thumb): bool
{
    if ((int)($thumb['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return false;
    }
    $tmp = (string)($thumb['tmp_name'] ?? '');
    if (!is_uploaded_file($tmp) || filesize($tmp) > 600 * 1024) {
        return false;
    }
    $info = @getimagesize($tmp);
    return $info !== false && $info[2] === IMAGETYPE_JPEG && $info[0] <= 1024 && $info[1] <= 1024;
}

/** Löscht Dateien (Datenbank + Festplatte), ausgewählt per WHERE-Bedingung. */
function delete_files(string $where, array $params): void
{
    $stmt = db()->prepare('SELECT id, storage_key FROM files WHERE ' . $where);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        return;
    }
    $del = db()->prepare('DELETE FROM files WHERE id = ?');
    $dir = files_dir();
    foreach ($rows as $row) {
        $del->execute([$row['id']]);
        @unlink($dir . '/' . $row['storage_key']);
        @unlink($dir . '/' . $row['storage_key'] . '.thumb');
    }
}

function content_disposition(string $type, string $name): string
{
    $ascii = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $name) ?: 'Datei';
    return $type . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name);
}

/** Liefert eine Datei aus (mit Unterstützung für Teilabrufe, z. B. für Videos). */
function send_file(int $id, bool $thumb, bool $download): never
{
    $stmt = db()->prepare('SELECT name, mime, size, storage_key, has_thumb FROM files WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    $path = $row ? files_dir() . '/' . $row['storage_key'] . ($thumb ? '.thumb' : '') : '';
    if (!$row || ($thumb && !$row['has_thumb']) || !is_file($path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Datei nicht gefunden.');
    }

    $mime = $thumb ? 'image/jpeg' : $row['mime'];
    $inline = !$download && ($thumb || in_array($mime, INLINE_TYPES, true));
    if (!$inline && preg_match('#html|svg|xml|javascript#i', $mime)) {
        $mime = 'application/octet-stream';
    }

    header_remove('Pragma');
    header_remove('Expires');
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . content_disposition($inline ? 'inline' : 'attachment', $row['name']));
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'"
        . ($inline ? '' : '; sandbox'));
    header('Cache-Control: private, max-age=86400');
    header('Accept-Ranges: bytes');

    $size = (int)filesize($path);
    $start = 0;
    $end = $size - 1;
    $range = (string)($_SERVER['HTTP_RANGE'] ?? '');
    if ($size > 0 && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m) && ($m[1] !== '' || $m[2] !== '')) {
        if ($m[1] === '') {
            $start = max(0, $size - (int)$m[2]);
        } else {
            $start = (int)$m[1];
            if ($m[2] !== '') {
                $end = min((int)$m[2], $size - 1);
            }
        }
        if ($start > $end) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }
    $length = $size > 0 ? $end - $start + 1 : 0;
    header('Content-Length: ' . $length);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD' || $length === 0) {
        exit;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    @set_time_limit(0);
    $fp = fopen($path, 'rb');
    fseek($fp, $start);
    $left = $length;
    while ($left > 0 && !feof($fp) && !connection_aborted()) {
        $chunk = fread($fp, (int)min(1024 * 1024, $left));
        if ($chunk === false || $chunk === '') {
            break;
        }
        echo $chunk;
        flush();
        $left -= strlen($chunk);
    }
    fclose($fp);
    exit;
}

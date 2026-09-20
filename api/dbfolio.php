<?php
/**
 * dbfolio PHP/Dropbox adapter.
 *
 * Implements the contract in docs/manifest.md against the Dropbox
 * authentication model proven in spike/dropbox-poc.php: app-level auth
 * (client credentials) scoped to the shared folder link in dbfolio.json,
 * no per-user OAuth.
 *
 * Routes (see docs/project-plan.md, "Browser/backend division"):
 *   GET  ?action=gallery
 *   GET  ?action=thumbnail&id=...
 *   GET  ?action=image&id=...
 *   POST ?action=unlock          (password gate, when enabled)
 */

declare(strict_types=1);

const SUPPORTED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'tif', 'tiff', 'bmp'];
const GALLERY_CACHE_TTL = 120;     // seconds; within the 60-300s target range
const MEDIA_CACHE_TTL = 60 * 60 * 24 * 7; // 7 days; safe because the cache key includes the Dropbox revision
const APP_TOKEN_SAFETY_MARGIN = 60; // refresh this many seconds before real expiry
const RATE_LIMIT_WINDOW = 60;      // seconds
const RATE_LIMIT_DEFAULT = 60;     // requests per window, per IP, for gallery/thumbnail/image
const RATE_LIMIT_UNLOCK = 8;       // requests per window, per IP, for the password endpoint
const SESSION_COOKIE = 'dbfolio_session';
const SESSION_TTL = 60 * 60 * 12;  // 12 hours

// --- bootstrap ----------------------------------------------------------

$config = load_config();
$cacheDir = getenv('DBFOLIO_CACHE_DIR') ?: sys_get_temp_dir() . '/dbfolio-cache';
ensure_cache_dir($cacheDir);

$action = $_GET['action'] ?? '';

header('X-Content-Type-Options: nosniff');

try {
    switch ($action) {
        case 'unlock':
            handle_unlock($config, $cacheDir);
            break;
        case 'gallery':
            enforce_rate_limit($cacheDir, 'gallery', RATE_LIMIT_DEFAULT);
            require_session_if_protected($config);
            handle_gallery($config, $cacheDir);
            break;
        case 'thumbnail':
            enforce_rate_limit($cacheDir, 'thumbnail', RATE_LIMIT_DEFAULT);
            require_session_if_protected($config);
            handle_media($config, $cacheDir, 'thumbnail');
            break;
        case 'image':
            enforce_rate_limit($cacheDir, 'image', RATE_LIMIT_DEFAULT);
            require_session_if_protected($config);
            handle_media($config, $cacheDir, 'image');
            break;
        case 'metadata':
            enforce_rate_limit($cacheDir, 'metadata', RATE_LIMIT_DEFAULT);
            require_session_if_protected($config);
            handle_metadata($config, $cacheDir);
            break;
        default:
            respond_error(400, 'bad_request', 'Unknown or missing action.');
    }
} catch (AdapterError $e) {
    respond_error($e->status, $e->errorCode, $e->getMessage(), $e->headers);
} catch (Throwable $e) {
    error_log('dbfolio adapter error: ' . $e->getMessage());
    respond_error(500, 'source_unavailable', 'Something went wrong loading the gallery. Please try again shortly.');
}

// --- error type -----------------------------------------------------------

final class AdapterError extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $headers = []
    ) {
        parent::__construct($message);
    }
}

function fail(int $status, string $code, string $message, array $headers = []): never
{
    throw new AdapterError($status, $code, $message, $headers);
}

// --- config -----------------------------------------------------------

function load_config(): array
{
    $path = getenv('DBFOLIO_CONFIG_PATH') ?: (__DIR__ . '/../dbfolio.json');
    if (!is_file($path)) {
        fail(500, 'configuration_invalid', 'dbfolio.json was not found.');
    }
    $raw = file_get_contents($path);
    $config = json_decode((string) $raw, true);
    if (!is_array($config)) {
        fail(500, 'configuration_invalid', 'dbfolio.json could not be parsed.');
    }
    if (empty($config['source']['url'])) {
        fail(500, 'source_not_configured', 'No Dropbox shared folder URL is configured.');
    }
    return $config;
}

// --- HTTP response helpers ---------------------------------------------

function respond_json(int $status, array $data, array $headers = []): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    foreach ($headers as $name => $value) {
        header("{$name}: {$value}");
    }
    echo json_encode($data);
    exit;
}

function respond_error(int $status, string $code, string $message, array $headers = []): never
{
    respond_json($status, ['error' => ['code' => $code, 'message' => $message]], $headers);
}

// --- filesystem cache ---------------------------------------------------

function ensure_cache_dir(string $dir): void
{
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
}

/** Returns null on miss/expired/unavailable — callers must have a fallback. */
function cache_get(string $dir, string $key): mixed
{
    $path = $dir . '/' . sha1($key) . '.json';
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $entry = json_decode($raw, true);
    if (!is_array($entry) || !isset($entry['expires_at'], $entry['value'])) {
        return null;
    }
    if (time() >= $entry['expires_at']) {
        return null;
    }
    return $entry['value'];
}

function cache_set(string $dir, string $key, mixed $value, int $ttlSeconds): void
{
    $path = $dir . '/' . sha1($key) . '.json';
    $entry = ['expires_at' => time() + $ttlSeconds, 'value' => $value];
    $fh = @fopen($path, 'c');
    if ($fh === false) {
        return; // fail open — caching is a performance optimization, not a requirement
    }
    if (flock($fh, LOCK_EX)) {
        ftruncate($fh, 0);
        fwrite($fh, json_encode($entry));
        fflush($fh);
        flock($fh, LOCK_UN);
    }
    fclose($fh);
}

/**
 * Raw-byte cache for thumbnail/image content, kept separate from cache_get/
 * cache_set (which JSON/base64-encode) to avoid ~33% size bloat and JSON
 * decode overhead on multi-megabyte originals. Two files per entry: the
 * raw bytes, and a small JSON sidecar carrying the expiry and content type.
 */
function binary_cache_get(string $dir, string $key): ?array
{
    $base = $dir . '/' . sha1($key);
    $metaRaw = @file_get_contents($base . '.meta.json');
    if ($metaRaw === false) {
        return null;
    }
    $meta = json_decode($metaRaw, true);
    if (!is_array($meta) || !isset($meta['expires_at'], $meta['contentType']) || time() >= $meta['expires_at']) {
        return null;
    }
    $bytes = @file_get_contents($base . '.bin');
    if ($bytes === false) {
        return null;
    }
    return ['bytes' => $bytes, 'contentType' => $meta['contentType']];
}

function binary_cache_set(string $dir, string $key, string $bytes, string $contentType, int $ttlSeconds): void
{
    $base = $dir . '/' . sha1($key);
    $meta = ['expires_at' => time() + $ttlSeconds, 'contentType' => $contentType];

    $dataFh = @fopen($base . '.bin', 'c');
    if ($dataFh === false) {
        return; // fail open
    }
    if (flock($dataFh, LOCK_EX)) {
        ftruncate($dataFh, 0);
        fwrite($dataFh, $bytes);
        fflush($dataFh);
        flock($dataFh, LOCK_UN);
    }
    fclose($dataFh);

    @file_put_contents($base . '.meta.json', json_encode($meta));
}

// --- rate limiting --------------------------------------------------------

function client_ip_hash(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return sha1($ip);
}

function enforce_rate_limit(string $dir, string $bucket, int $limit): void
{
    $key = "ratelimit:{$bucket}:" . client_ip_hash();
    $path = $dir . '/' . sha1($key) . '.json';

    $fh = @fopen($path, 'c+');
    if ($fh === false) {
        return; // fail open, per project plan
    }

    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        return;
    }

    $raw = stream_get_contents($fh);
    $state = json_decode((string) $raw, true);
    $now = time();

    if (!is_array($state) || !isset($state['windowStart'], $state['count']) || $now - $state['windowStart'] >= RATE_LIMIT_WINDOW) {
        $state = ['windowStart' => $now, 'count' => 0];
    }

    $state['count']++;

    rewind($fh);
    ftruncate($fh, 0);
    fwrite($fh, json_encode($state));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);

    if ($state['count'] > $limit) {
        $retryAfter = RATE_LIMIT_WINDOW - ($now - $state['windowStart']);
        fail(429, 'rate_limited', 'Too many requests. Please slow down.', ['Retry-After' => (string) max(1, $retryAfter)]);
    }
}

// --- password protection -------------------------------------------------

function session_secret(): string
{
    $secret = getenv('DBFOLIO_SESSION_SECRET');
    if ($secret === false || $secret === '') {
        fail(500, 'configuration_invalid', 'DBFOLIO_SESSION_SECRET is not set.');
    }
    return $secret;
}

function is_password_protected(array $config): bool
{
    return (bool) ($config['access']['passwordProtected'] ?? false);
}

function verify_password(string $password, string $storedHash): bool
{
    // format: sha256$<salt>$<hex digest of salt+password>
    $parts = explode('$', $storedHash);
    if (count($parts) !== 3 || $parts[0] !== 'sha256') {
        return false;
    }
    [, $salt, $expected] = $parts;
    $actual = hash('sha256', $salt . $password);
    return hash_equals($expected, $actual);
}

function issue_session_cookie(): void
{
    $expires = time() + SESSION_TTL;
    $payload = (string) $expires;
    $signature = hash_hmac('sha256', $payload, session_secret());
    $token = $payload . '.' . $signature;

    setcookie(SESSION_COOKIE, $token, [
        'expires' => $expires,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Strict',
        'secure' => !empty($_SERVER['HTTPS']),
    ]);
}

function has_valid_session(): bool
{
    $token = $_COOKIE[SESSION_COOKIE] ?? '';
    if ($token === '' || !str_contains($token, '.')) {
        return false;
    }
    [$payload, $signature] = explode('.', $token, 2);
    $expected = hash_hmac('sha256', $payload, session_secret());
    if (!hash_equals($expected, $signature)) {
        return false;
    }
    return (int) $payload > time();
}

function require_session_if_protected(array $config): void
{
    if (!is_password_protected($config)) {
        return;
    }
    if (!has_valid_session()) {
        fail(401, 'unauthorized', 'A password is required to view this gallery.');
    }
}

function handle_unlock(array $config, string $cacheDir): void
{
    enforce_rate_limit($cacheDir, 'unlock', RATE_LIMIT_UNLOCK);

    if (!is_password_protected($config)) {
        respond_json(200, ['ok' => true]);
    }

    $password = $_POST['password'] ?? '';
    $storedHash = $config['access']['passwordHash'] ?? '';

    if ($password === '' || $storedHash === '' || !verify_password($password, $storedHash)) {
        fail(401, 'unauthorized', 'Incorrect password.');
    }

    issue_session_cookie();
    respond_json(200, ['ok' => true]);
}

// --- Dropbox client (app-level auth, shared-link scoped) -----------------

function dropbox_app_token(string $cacheDir): string
{
    $cached = cache_get($cacheDir, 'dropbox:app_token');
    if (is_string($cached)) {
        return $cached;
    }

    $appKey = getenv('DBFOLIO_DROPBOX_APP_KEY');
    $appSecret = getenv('DBFOLIO_DROPBOX_APP_SECRET');
    if (!$appKey || !$appSecret) {
        fail(500, 'configuration_invalid', 'Dropbox app credentials are not configured.');
    }

    $response = http_request('https://api.dropboxapi.com/oauth2/token', [
        CURLOPT_POST => true,
        CURLOPT_USERPWD => "{$appKey}:{$appSecret}",
        CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'client_credentials']),
    ]);

    if ($response['status'] !== 200) {
        error_log('dbfolio: dropbox token request failed: ' . $response['body']);
        fail(502, 'source_unavailable', 'Could not authenticate with the photo source.');
    }

    $data = json_decode($response['body'], true);
    $token = $data['access_token'] ?? null;
    $expiresIn = (int) ($data['expires_in'] ?? 14400);
    if (!$token) {
        fail(502, 'source_unavailable', 'Could not authenticate with the photo source.');
    }

    cache_set($cacheDir, 'dropbox:app_token', $token, max(60, $expiresIn - APP_TOKEN_SAFETY_MARGIN));

    return $token;
}

/** @return array{status:int, body:string} */
function http_request(string $url, array $options): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, $options + [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        $error = curl_error($ch);
        error_log("dbfolio: request to {$url} failed: {$error}");
        fail(502, 'source_unavailable', 'The photo source could not be reached.');
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return ['status' => $status, 'body' => (string) $body];
}

function dropbox_api_call(string $token, string $endpoint, array $body): array
{
    $response = http_request("https://api.dropboxapi.com/2/{$endpoint}", [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($body),
    ]);

    if ($response['status'] === 401 || $response['status'] === 403) {
        error_log('dbfolio: dropbox api auth error on ' . $endpoint . ': ' . $response['body']);
        fail(502, 'source_unavailable', 'The photo source rejected the request. Please contact the gallery owner.');
    }
    if ($response['status'] === 429) {
        fail(429, 'rate_limited', 'The photo source is temporarily busy. Please try again shortly.');
    }
    if ($response['status'] !== 200) {
        error_log("dbfolio: dropbox api error on {$endpoint}: {$response['body']}");
        fail(502, 'source_unavailable', 'The photo source could not be reached.');
    }

    return json_decode($response['body'], true) ?? [];
}

/** @return array<int, array<string, mixed>> */
function list_shared_folder(string $token, string $shareUrl): array
{
    $entries = [];

    $response = dropbox_api_call($token, 'files/list_folder', [
        'path' => '',
        'shared_link' => ['url' => $shareUrl],
    ]);
    $entries = array_merge($entries, $response['entries'] ?? []);

    $hasMore = $response['has_more'] ?? false;
    $cursor = $response['cursor'] ?? null;

    while ($hasMore && $cursor !== null) {
        $response = dropbox_api_call($token, 'files/list_folder/continue', ['cursor' => $cursor]);
        $entries = array_merge($entries, $response['entries'] ?? []);
        $hasMore = $response['has_more'] ?? false;
        $cursor = $response['cursor'] ?? null;
    }

    return $entries;
}

/** @return array<int, array<string, mixed>> cached raw Dropbox entries */
function cached_folder_entries(array $config, string $cacheDir): array
{
    $shareUrl = $config['source']['url'];
    $cacheKey = 'dropbox:entries:' . $shareUrl;

    $cached = cache_get($cacheDir, $cacheKey);
    if (is_array($cached)) {
        return $cached;
    }

    $token = dropbox_app_token($cacheDir);
    $entries = list_shared_folder($token, $shareUrl);
    cache_set($cacheDir, $cacheKey, $entries, GALLERY_CACHE_TTL);

    return $entries;
}

function is_supported_image(string $name): bool
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return in_array($ext, SUPPORTED_EXTENSIONS, true);
}

// --- orientation overrides -------------------------------------------------

function load_orientation_overrides(): array
{
    $path = getenv('DBFOLIO_ORIENTATION_OVERRIDES_PATH') ?: (__DIR__ . '/../orientation-overrides.json');
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

// --- manifest building -----------------------------------------------------

function build_manifest(array $config, array $entries): array
{
    $overrides = load_orientation_overrides();

    $images = [];
    foreach ($entries as $entry) {
        if (($entry['.tag'] ?? null) !== 'file') {
            continue; // discard directories for MVP, per project plan
        }
        $name = (string) ($entry['name'] ?? '');
        if (!is_supported_image($name)) {
            continue;
        }

        $id = (string) ($entry['id'] ?? '');
        $orientation = $overrides[$id] ?? 0;

        $images[] = [
            'id' => $id,
            'name' => $name,
            'path' => '/' . ltrim($name, '/'),
            'revision' => (string) ($entry['rev'] ?? ''),
            'modified' => (string) ($entry['server_modified'] ?? ''),
            'size' => isset($entry['size']) ? (int) $entry['size'] : null,
            'orientation' => in_array($orientation, [0, 90, 180, 270], true) ? $orientation : 0,
            'thumbnail' => media_url('thumbnail', $id),
            'image' => media_url('image', $id),
        ];
    }

    sort_images($images, $config['gallery']['sort'] ?? 'filename', $config['gallery']['direction'] ?? 'ascending');

    return [
        'gallery' => [
            'title' => $config['gallery']['title'] ?? '',
            'description' => $config['gallery']['description'] ?? '',
        ],
        'images' => $images,
    ];
}

function sort_images(array &$images, string $sort, string $direction): void
{
    usort($images, function (array $a, array $b) use ($sort) {
        if ($sort === 'modified') {
            return strcmp($a['modified'], $b['modified']);
        }
        return strnatcasecmp($a['name'], $b['name']); // natural order: photo2 < photo10
    });
    if ($direction === 'descending') {
        $images = array_reverse($images);
    }
}

function media_url(string $action, string $id): string
{
    // Absolute path (not basename) so manifest URLs resolve correctly
    // regardless of where the page that fetches the manifest is served from.
    $script = $_SERVER['SCRIPT_NAME'] ?? '/api/dbfolio.php';
    return "{$script}?action={$action}&id=" . rawurlencode($id);
}

// --- request handlers -----------------------------------------------------

function handle_gallery(array $config, string $cacheDir): void
{
    $entries = cached_folder_entries($config, $cacheDir);
    $manifest = build_manifest($config, $entries);
    respond_json(200, $manifest, ['Cache-Control' => 'no-store']);
}

function resolve_image_entry(array $config, string $cacheDir, string $id): array
{
    if ($id === '') {
        fail(400, 'bad_request', 'Missing id.');
    }

    $entries = cached_folder_entries($config, $cacheDir);
    foreach ($entries as $entry) {
        if (($entry['id'] ?? null) === $id) {
            $name = (string) $entry['name'];
            if (!is_supported_image($name)) {
                fail(404, 'not_found', 'That photo is no longer available.');
            }
            return $entry;
        }
    }
    fail(404, 'not_found', 'That photo is no longer available.');
}

/**
 * Byte cache keyed on id+revision, not just id: a changed file gets a
 * new revision from Dropbox and therefore a new cache key automatically,
 * so this cache never needs explicit invalidation.
 *
 * @return array{bytes:string, contentType:string}
 */
function get_or_fetch_media_bytes(array $config, string $cacheDir, string $kind, array $match): array
{
    $id = (string) $match['id'];
    $name = (string) $match['name'];
    $path = '/' . ltrim($name, '/');
    $contentType = guess_content_type($name);

    $cacheKey = "media:{$kind}:{$id}:" . ($match['rev'] ?? '');
    $cached = binary_cache_get($cacheDir, $cacheKey);
    if ($cached !== null) {
        return $cached;
    }

    $token = dropbox_app_token($cacheDir);
    $shareUrl = $config['source']['url'];

    $bytes = $kind === 'thumbnail'
        ? fetch_thumbnail($token, $shareUrl, $path)
        : fetch_full_image($token, $shareUrl, $path);

    binary_cache_set($cacheDir, $cacheKey, $bytes, $contentType, MEDIA_CACHE_TTL);

    return ['bytes' => $bytes, 'contentType' => $contentType];
}

function handle_media(array $config, string $cacheDir, string $kind): void
{
    $id = $_GET['id'] ?? '';
    $match = resolve_image_entry($config, $cacheDir, $id);
    $media = get_or_fetch_media_bytes($config, $cacheDir, $kind, $match);

    header('Content-Type: ' . $media['contentType']);
    header('Cache-Control: public, max-age=86400');
    header('Content-Length: ' . strlen($media['bytes']));
    echo $media['bytes'];
    exit;
}

function handle_metadata(array $config, string $cacheDir): void
{
    $id = $_GET['id'] ?? '';
    $match = resolve_image_entry($config, $cacheDir, $id);

    // Reuses the same cache entry the lightbox's own display fetch
    // already warmed, so this is normally free — only a cold-path
    // direct request forces a fresh Dropbox fetch.
    $media = get_or_fetch_media_bytes($config, $cacheDir, 'image', $match);

    $cacheKey = 'exif:' . $id . ':' . ($match['rev'] ?? '');
    $exif = cache_get($cacheDir, $cacheKey);
    if ($exif === null) {
        $exif = extract_exif_metadata($media['bytes'], $media['contentType']);
        cache_set($cacheDir, $cacheKey, $exif, MEDIA_CACHE_TTL);
    }

    respond_json(200, ['metadata' => $exif], ['Cache-Control' => 'public, max-age=86400']);
}

/**
 * Reads EXIF straight out of the original image bytes we already
 * downloaded and cached — no separate Dropbox media-info API call.
 * Only JPEG/TIFF carry EXIF; other formats (PNG, GIF, ...) simply
 * return an empty result, which the frontend treats as "nothing to
 * show" rather than an error.
 */
function extract_exif_metadata(string $bytes, string $contentType): array
{
    if (!function_exists('exif_read_data')) {
        return [];
    }
    if (!in_array($contentType, ['image/jpeg', 'image/tiff'], true)) {
        return [];
    }

    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $bytes);
    rewind($stream);
    $raw = @exif_read_data($stream, null, true);
    fclose($stream);

    if ($raw === false) {
        return [];
    }

    // exif_read_data groups fields under sections (IFD0, EXIF, ...)
    // whose exact layout varies by camera/software; flatten them so
    // lookups below don't need to know which section a field lives in.
    $flat = [];
    foreach ($raw as $section) {
        if (is_array($section)) {
            $flat += $section;
        }
    }

    $result = [];

    $make = trim((string) ($flat['Make'] ?? ''));
    $model = trim((string) ($flat['Model'] ?? ''));
    if ($model !== '') {
        $result['camera'] = ($make !== '' && !str_contains($model, $make)) ? "{$make} {$model}" : $model;
    }

    $taken = $flat['DateTimeOriginal'] ?? $flat['DateTime'] ?? null;
    if ($taken) {
        // EXIF datetime ("YYYY:MM:DD HH:MM:SS") carries no timezone —
        // it's the camera's local clock, not a zone-aware instant. Format
        // it as a plain display string server-side rather than emitting
        // ISO 8601 with an implied offset, which the frontend would
        // otherwise reinterpret and shift to the viewer's own timezone.
        $parsed = DateTime::createFromFormat('Y:m:d H:i:s', (string) $taken);
        if ($parsed !== false) {
            $result['taken'] = $parsed->format('F j, Y, g:i A');
        }
    }

    if (isset($flat['ExposureTime'])) {
        $result['exposureTime'] = format_exif_exposure((string) $flat['ExposureTime']);
    }
    if (isset($flat['FNumber'])) {
        $fNumber = format_exif_rational((string) $flat['FNumber']);
        if ($fNumber !== null) {
            $result['aperture'] = 'f/' . rtrim(rtrim(number_format($fNumber, 1), '0'), '.');
        }
    }
    $iso = $flat['ISOSpeedRatings'] ?? $flat['ISOSpeedRatings'][0] ?? null;
    if ($iso) {
        $result['iso'] = (string) (is_array($iso) ? ($iso[0] ?? '') : $iso);
    }
    if (isset($flat['FocalLength'])) {
        $focalLength = format_exif_rational((string) $flat['FocalLength']);
        if ($focalLength !== null) {
            $result['focalLength'] = round($focalLength) . 'mm';
        }
    }

    return $result;
}

/** EXIF rationals arrive as "num/den" strings (or plain numbers on some builds). */
function format_exif_rational(string $value): ?float
{
    if (str_contains($value, '/')) {
        [$num, $den] = array_map('floatval', explode('/', $value, 2));
        return $den != 0.0 ? $num / $den : null;
    }
    return is_numeric($value) ? (float) $value : null;
}

function format_exif_exposure(string $value): ?string
{
    $seconds = format_exif_rational($value);
    if ($seconds === null || $seconds <= 0) {
        return null;
    }
    if ($seconds >= 1) {
        return rtrim(rtrim(number_format($seconds, 1), '0'), '.') . 's';
    }
    return '1/' . round(1 / $seconds) . 's';
}

function fetch_thumbnail(string $token, string $shareUrl, string $path): string
{
    $response = http_request('https://content.dropboxapi.com/2/files/get_thumbnail_v2', [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Dropbox-API-Arg: ' . json_encode([
                'resource' => ['.tag' => 'link', 'url' => $shareUrl, 'path' => $path],
                'format' => 'jpeg',
                'size' => 'w640h480',
                'mode' => 'strict',
            ]),
        ],
    ]);
    if ($response['status'] !== 200) {
        error_log("dbfolio: thumbnail fetch failed for {$path}: {$response['body']}");
        fail(502, 'source_unavailable', 'Could not generate a thumbnail for this photo.');
    }
    return $response['body'];
}

function fetch_full_image(string $token, string $shareUrl, string $path): string
{
    $response = http_request('https://content.dropboxapi.com/2/sharing/get_shared_link_file', [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Dropbox-API-Arg: ' . json_encode(['url' => $shareUrl, 'path' => $path]),
        ],
    ]);
    if ($response['status'] !== 200) {
        error_log("dbfolio: image fetch failed for {$path}: {$response['body']}");
        fail(502, 'source_unavailable', 'Could not load this photo.');
    }
    return $response['body'];
}

function guess_content_type(string $name): string
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return match ($ext) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'tif', 'tiff' => 'image/tiff',
        'bmp' => 'image/bmp',
        default => 'application/octet-stream',
    };
}

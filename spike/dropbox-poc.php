<?php
/**
 * dbfolio Phase 1 spike.
 *
 * Proves that a Dropbox app can, using ONLY app-level authentication
 * (app key + app secret, client-credentials grant) scoped to a shared
 * folder link, without any per-user OAuth consent flow:
 *
 *   1. list a shared folder's contents (with pagination)
 *   2. fetch a thumbnail for a file in it
 *   3. fetch the full file content
 *
 * This is a command-line spike, not the production adapter. It exists
 * to validate the authentication model described in docs/project-plan.md
 * before any gallery UI is built.
 *
 * Usage:
 *   export DBFOLIO_DROPBOX_APP_KEY=...
 *   export DBFOLIO_DROPBOX_APP_SECRET=...
 *   export DBFOLIO_DROPBOX_SHARE_URL='https://www.dropbox.com/scl/fo/...'
 *   php spike/dropbox-poc.php
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

const SUPPORTED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'tif', 'tiff', 'bmp'];

function fail(string $message): never
{
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
}

function require_env(string $name): string
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        fail("Missing required environment variable: {$name}");
    }
    return $value;
}

/**
 * @return array{status:int, headers:string, body:string}
 */
function http_request(string $url, array $options = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, $options + [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        fail("cURL request to {$url} failed: {$error}");
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    return [
        'status' => $status,
        'headers' => substr($raw, 0, $headerSize),
        'body' => substr($raw, $headerSize),
    ];
}

/**
 * Client-credentials grant: exchange app key/secret for an app-level
 * access token. No user, no browser consent, no refresh token to manage.
 */
function get_app_access_token(string $appKey, string $appSecret): string
{
    $response = http_request('https://api.dropboxapi.com/oauth2/token', [
        CURLOPT_POST => true,
        CURLOPT_USERPWD => "{$appKey}:{$appSecret}",
        CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'client_credentials']),
    ]);

    if ($response['status'] !== 200) {
        fail("Token request failed (HTTP {$response['status']}): {$response['body']}");
    }

    $data = json_decode($response['body'], true);
    if (!isset($data['access_token'])) {
        fail("Token response did not contain access_token: {$response['body']}");
    }

    return $data['access_token'];
}

/**
 * List every entry in the shared folder, following pagination via
 * files/list_folder/continue until has_more is false.
 *
 * @return array<int, array<string, mixed>>
 */
function list_shared_folder(string $token, string $shareUrl): array
{
    $entries = [];

    $response = api_call($token, 'files/list_folder', [
        'path' => '',
        'shared_link' => ['url' => $shareUrl],
    ]);

    $entries = array_merge($entries, $response['entries']);
    $hasMore = $response['has_more'] ?? false;
    $cursor = $response['cursor'] ?? null;

    while ($hasMore && $cursor !== null) {
        $response = api_call($token, 'files/list_folder/continue', [
            'cursor' => $cursor,
        ]);
        $entries = array_merge($entries, $response['entries']);
        $hasMore = $response['has_more'] ?? false;
        $cursor = $response['cursor'] ?? null;
    }

    return $entries;
}

/**
 * @return array<string, mixed>
 */
function api_call(string $token, string $endpoint, array $body): array
{
    $response = http_request("https://api.dropboxapi.com/2/{$endpoint}", [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($body),
    ]);

    if ($response['status'] !== 200) {
        fail("API call to {$endpoint} failed (HTTP {$response['status']}): {$response['body']}");
    }

    return json_decode($response['body'], true) ?? [];
}

/**
 * Fetch a thumbnail for a file inside the shared folder link, using
 * files/get_thumbnail_v2 with a "link" resource — this is the endpoint
 * that supports shared-link-scoped access without full account auth.
 */
function get_thumbnail(string $token, string $shareUrl, string $path): string
{
    $response = http_request('https://content.dropboxapi.com/2/files/get_thumbnail_v2', [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Dropbox-API-Arg: ' . json_encode([
                'resource' => [
                    '.tag' => 'link',
                    'url' => $shareUrl,
                    'path' => $path,
                ],
                'format' => 'jpeg',
                'size' => 'w640h480',
                'mode' => 'strict',
            ]),
        ],
    ]);

    if ($response['status'] !== 200) {
        fail("Thumbnail request for {$path} failed (HTTP {$response['status']}): {$response['body']}");
    }

    return $response['body'];
}

/**
 * Fetch full file content from the shared folder link.
 */
function get_file(string $token, string $shareUrl, string $path): string
{
    $response = http_request('https://content.dropboxapi.com/2/sharing/get_shared_link_file', [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Dropbox-API-Arg: ' . json_encode([
                'url' => $shareUrl,
                'path' => $path,
            ]),
        ],
    ]);

    if ($response['status'] !== 200) {
        fail("File request for {$path} failed (HTTP {$response['status']}): {$response['body']}");
    }

    return $response['body'];
}

function is_supported_image(string $name): bool
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return in_array($ext, SUPPORTED_EXTENSIONS, true);
}

// --- main -------------------------------------------------------------

$appKey = require_env('DBFOLIO_DROPBOX_APP_KEY');
$appSecret = require_env('DBFOLIO_DROPBOX_APP_SECRET');
$shareUrl = require_env('DBFOLIO_DROPBOX_SHARE_URL');

echo "1. Requesting app access token via client-credentials grant...\n";
$token = get_app_access_token($appKey, $appSecret);
echo "   OK — token acquired (no user login involved).\n\n";

echo "2. Listing shared folder (following pagination)...\n";
$entries = list_shared_folder($token, $shareUrl);
echo '   ' . count($entries) . " entries returned.\n";

$images = array_values(array_filter(
    $entries,
    fn (array $e) => ($e['.tag'] ?? null) === 'file' && is_supported_image((string) ($e['name'] ?? ''))
));

echo '   ' . count($images) . " are supported image files:\n";
foreach ($images as $image) {
    printf(
        "     - %s (%s bytes, rev %s, modified %s)\n",
        $image['name'],
        $image['size'] ?? '?',
        $image['rev'] ?? '?',
        $image['server_modified'] ?? '?'
    );
}
echo "\n";

if (count($images) === 0) {
    fail('No supported images found in the shared folder — nothing to fetch. Add a .jpg/.png/etc. to the folder and re-run.');
}

$first = $images[0];
$firstPath = '/' . ltrim($first['name'], '/');

echo "3. Fetching thumbnail for {$first['name']}...\n";
$thumbBytes = get_thumbnail($token, $shareUrl, $firstPath);
$thumbOut = __DIR__ . '/output-thumbnail.jpg';
file_put_contents($thumbOut, $thumbBytes);
echo '   OK — ' . strlen($thumbBytes) . " bytes written to {$thumbOut}\n\n";

echo "4. Fetching full image for {$first['name']}...\n";
$imageBytes = get_file($token, $shareUrl, $firstPath);
$imageOut = __DIR__ . '/output-image' . (pathinfo($first['name'], PATHINFO_EXTENSION) ? '.' . pathinfo($first['name'], PATHINFO_EXTENSION) : '');
file_put_contents($imageOut, $imageBytes);
echo '   OK — ' . strlen($imageBytes) . " bytes written to {$imageOut}\n\n";

echo "All steps succeeded. Phase 1 spike is proven:\n";
echo "  - app-level auth (no user OAuth) works against a shared folder link\n";
echo "  - folder listing + pagination works\n";
echo "  - thumbnail retrieval works\n";
echo "  - full image retrieval works\n";

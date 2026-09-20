<?php
/**
 * dbfolio secrets.
 *
 * Copy this file to dbfolio.secrets.php (same directory) and fill in
 * your values. It is loaded with require(), never fetched by the
 * browser, and never needs SetEnv/env vars configured on your host.
 *
 * dbfolio.secrets.php is gitignored — never commit your real one.
 */

// Refuse to output anything if this file is ever requested directly
// instead of required by api/dbfolio.php. Belt-and-suspenders: a bare
// `return [...]` already produces no output on its own as long as PHP
// execution works for this file — which it must, for dbfolio to run
// at all — this just fails loudly instead of silently relying on that.
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit;
}

return [
    'DBFOLIO_DROPBOX_APP_KEY' => '',
    'DBFOLIO_DROPBOX_APP_SECRET' => '',

    // Only required if access.passwordProtected is true in dbfolio.json.
    // Any random string.
    'DBFOLIO_SESSION_SECRET' => '',
];

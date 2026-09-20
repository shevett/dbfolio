# dbfolio

A lightweight photo-gallery frontend for a Dropbox shared folder. Put photos in a Dropbox folder, share it, paste the link into a config file, and dbfolio turns it into a clean, fast, responsive web gallery — no database, no admin UI, no build step.

Full design rationale, architecture, and the manifest/adapter contract live in [`docs/project-plan.md`](docs/project-plan.md) and [`docs/manifest.md`](docs/manifest.md). This README covers installing and configuring it.

## How it works

```
Browser  --->  api/dbfolio.php  --->  Dropbox
(static HTML/CSS/JS)   (PHP adapter, reads dbfolio.json)
```

The frontend (`index.html`, `assets/`) is static and knows nothing about Dropbox — it only talks to a small JSON API. The PHP adapter (`api/dbfolio.php`) is the only thing that talks to Dropbox, using app-level authentication scoped to your shared folder link (no Dropbox login required from visitors, and no OAuth consent flow to maintain).

## Requirements

- A web host with PHP 8.1+ and the **cURL** and **exif** extensions enabled (both are standard on virtually all PHP installs, including shared hosting)
- Ability to set environment variables for your site (most hosts support this via a control panel, `.htaccess`/`SetEnv`, or a platform-specific config)
- A Dropbox account and a folder you've shared via a "view-only" shared link

## 1. Create a Dropbox app

1. Go to the [Dropbox App Console](https://www.dropbox.com/developers/apps) and create a new app.
2. Choose **Scoped access**.
3. Under the **Permissions** tab, enable:
   - `sharing.read`
   - `files.content.read`

   Both are required — `files.content.read` alone is enough for listing the folder and generating thumbnails, but fetching full-size images additionally needs `sharing.read`, and will fail with a scope error if it's missing.
4. On the **Settings** tab, note the **App key** and **App secret**. You will not put these in `dbfolio.json` — they go in server-side environment variables (step 3 below).
5. In Dropbox itself, share the folder you want to publish and copy its shared link (Share → Copy link). It looks like `https://www.dropbox.com/scl/fo/.../...?rlkey=...&dl=0`.

## 2. Copy the files to your web host

Copy the whole project to your host. A typical deployment is a flat layout at your site's document root:

```
your-site/
├── index.html
├── dbfolio.json          (you create this — see step 4)
├── assets/
│   ├── dbfolio.css
│   └── dbfolio.js
└── api/
    └── dbfolio.php
```

No build step, no `composer install`, no Node — just copy the files.

Make sure `api/dbfolio.php` is reachable and actually executes as PHP (some hosts disable PHP execution per-directory). If visiting `api/dbfolio.php?action=gallery` returns raw PHP source, or a 403/404, check your host's PHP configuration for that directory before going further.

## 3. Set environment variables

| Variable | Required | Purpose |
|---|---|---|
| `DBFOLIO_DROPBOX_APP_KEY` | always | from the Dropbox App Console |
| `DBFOLIO_DROPBOX_APP_SECRET` | always | from the Dropbox App Console — keep this secret |
| `DBFOLIO_SESSION_SECRET` | only if `access.passwordProtected` is `true` | any random string; used to sign session cookies for the password gate |
| `DBFOLIO_CONFIG_PATH` | no | path to `dbfolio.json`, if not alongside `api/` (default: `<api dir>/../dbfolio.json`) |
| `DBFOLIO_CACHE_DIR` | no | where the adapter caches Dropbox metadata/thumbnails/images (default: a `dbfolio-cache` folder under the system temp directory) |
| `DBFOLIO_ORIENTATION_OVERRIDES_PATH` | no | path to `orientation-overrides.json`, if not alongside `dbfolio.json` (see [Fixing a rotated photo](#fixing-a-rotated-photo)) |

How you set these depends on your host — common options are your hosting control panel's "environment variables" section, a `SetEnv` directive in Apache config, or (for PHP-FPM) `env[...]` in the pool config. Consult your host's docs; `.env` files are not read automatically by this adapter.

## 4. Configure `dbfolio.json`

Copy `dbfolio.example.json` to `dbfolio.json` (same directory as `api/`, by default) and edit it:

```bash
cp dbfolio.example.json dbfolio.json
```

At minimum, set `source.url` to your Dropbox shared folder link and `gallery.title`:

```json
{
  "source": {
    "type": "dropbox",
    "url": "https://www.dropbox.com/scl/fo/xxxxx/yyyyy?rlkey=zzzzz&dl=0"
  },
  "gallery": {
    "title": "Block Island 2026",
    "description": "Photographs from the trip"
  }
}
```

`dbfolio.json` is fetched directly by the browser, so **never put your Dropbox app key/secret in it** — those stay in environment variables. It's fine for `source.url` to be public; it only grants read access to the one folder you shared.

See `dbfolio.example.json` for every available option (layout, columns, theming, sorting, contact info, feature toggles). `docs/project-plan.md`'s "Configuration" section documents each field.

### Password-protecting the gallery

To gate the whole gallery behind a single shared password:

1. Generate a hash for your chosen password:
   ```bash
   php api/hash-password.php 'your chosen password'
   ```
2. Paste the output into `dbfolio.json`:
   ```json
   "access": {
     "passwordProtected": true,
     "passwordHash": "sha256$...$..."
   }
   ```
3. Set `DBFOLIO_SESSION_SECRET` (see the table above) — it's required once `passwordProtected` is `true`.

There's no per-user accounts system — this is a single shared password for the whole gallery, intended to keep casual visitors and search engines out, not to serve as strong access control.

### Fixing a rotated photo

If a photo displays rotated incorrectly, you can pin a manual correction that survives future gallery refreshes. Create `orientation-overrides.json` next to `dbfolio.json`:

```json
{
  "id:abc123xyz": 90
}
```

The key is the photo's `id` (visible in the gallery manifest JSON, `?action=gallery`), and the value is a clockwise rotation in degrees (`0`, `90`, `180`, or `270`).

## Running it locally

PHP's built-in server is enough for local testing (it's single-threaded, so a full gallery load will be slower than on real hosting — that's a dev-server limitation, not a production one):

```bash
export DBFOLIO_DROPBOX_APP_KEY=your_app_key
export DBFOLIO_DROPBOX_APP_SECRET=your_app_secret
export DBFOLIO_SESSION_SECRET=any-string   # only needed if password-protected

php -S localhost:8080 -t .
```

Then visit `http://localhost:8080/`.

## Project layout

```
dbfolio/
├── README.md
├── LICENSE
├── dbfolio.example.json      config template — copy to dbfolio.json
├── index.html
├── assets/
│   ├── dbfolio.css
│   └── dbfolio.js
├── api/
│   ├── dbfolio.php           the PHP/Dropbox adapter
│   └── hash-password.php     CLI helper for the password feature
├── docs/
│   ├── project-plan.md       full design/architecture doc
│   └── manifest.md           the frontend/adapter API contract
└── spike/
    └── dropbox-poc.php       standalone script proving the auth model
```

## License

[PolyForm Noncommercial 1.0.0](https://polyformproject.org/licenses/noncommercial/1.0.0) — free to use, copy, modify, and share with attribution; not for use in a commercial product or service without permission. See `LICENSE`.

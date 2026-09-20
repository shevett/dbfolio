# Phase 1 spike — Dropbox shared-link access

This proves the authentication model in `docs/project-plan.md` (Dropbox
integration → Authentication model) actually works before any gallery UI
is built: app-level auth only, scoped to one shared folder link, no user
OAuth consent flow.

## Prerequisites

* PHP with the cURL extension enabled (`php -m | grep curl`)
* A Dropbox app (create one at https://www.dropbox.com/developers/apps)
  * App type: Scoped access
  * Permissions needed: `sharing.read`, `files.content.read`
  * Note the app key and app secret
* A Dropbox folder shared via a "view-only" shared link, containing at
  least one `.jpg`/`.png`/etc. file

## Running it

```bash
export DBFOLIO_DROPBOX_APP_KEY=your_app_key
export DBFOLIO_DROPBOX_APP_SECRET=your_app_secret
export DBFOLIO_DROPBOX_SHARE_URL='https://www.dropbox.com/scl/fo/xxxxx/yyyyy?rlkey=zzzzz&dl=0'

php spike/dropbox-poc.php
```

On success it writes `spike/output-thumbnail.jpg` and
`spike/output-image.*` (both gitignored) from the first supported image
found in the folder, and prints the full listing.

## What a failure here means

* **Token request fails** — check the app key/secret, and that the app
  has `sharing.read` + `files.content.read` permissions enabled in the
  Dropbox App Console.
* **Folder listing fails** — check the share URL is a live "Anyone with
  the link" folder link, not expired/revoked, not a file link.
* **Thumbnail/file fetch fails for a specific entry** — Dropbox can't
  always generate thumbnails for very large images or some formats;
  the production adapter needs to handle this gracefully (see
  "File support" in the project plan), but a totally failing spike run
  most likely indicates a path-encoding or permission problem instead.

Do not proceed to Phase 2 (manifest/adapter contract) until this spike
runs cleanly against a real shared folder.

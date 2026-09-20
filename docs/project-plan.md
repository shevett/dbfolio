# dbfolio

## Product concept

`dbfolio` is a lightweight photo-gallery frontend for a Dropbox shared folder.

The owner puts photographs into a Dropbox folder, creates a view-only shared link, places that URL into `dbfolio.json`, and deploys dbfolio.

Visitors see a clean responsive photo gallery rather than Dropbox's folder interface.

The primary design goals are:

* extremely easy deployment
* Dropbox remains the source of truth for photographs
* no database
* no administrative interface
* no frontend framework
* minimal JavaScript and minimal dependencies
* excellent desktop, tablet and mobile behavior
* photographs should not all be downloaded at page load
* configuration should be editable without rebuilding the application

---

# Core architectural principles

Two design decisions are fundamental to dbfolio and should guide implementation.

## 1. dbfolio is a static gallery with a replaceable Dropbox adapter

The application should **not** be designed conceptually as a PHP application.

The product consists of:

```text
Static frontend
    +
small media/source adapter
```

The frontend owns all gallery behavior and should be independently usable regardless of how the image manifest and image data are obtained.

The initial distribution will include a PHP Dropbox adapter because PHP provides an extremely easy deployment target on conventional web hosting.

However, the PHP adapter is only one implementation of a defined backend interface.

The browser must interact with the backend through a small, documented HTTP API contract.

For example:

```text
GET /api/gallery
GET /api/thumbnail
GET /api/image
```

The frontend must not contain PHP-specific assumptions.

This makes it possible to replace the PHP implementation later with:

```text
Cloudflare Worker
AWS Lambda
Node server
another serverless function
static generated manifest
```

without modifying the gallery application itself.

The architectural boundary should therefore be:

```text
                     +------------------+
                     |  dbfolio frontend |
                     | HTML / CSS / JS   |
                     +---------+--------+
                               |
                         HTTP API contract
                               |
              +----------------+----------------+
              |                                 |
        PHP adapter                       future adapters
              |                                 |
              +------------- Dropbox ----------+
```

## 2. Fully static deployment is a deliberate future target

dbfolio should also be designed so that a future release can operate with **no runtime backend at all**.

In this mode, a synchronization/build process would periodically read Dropbox and generate the assets required by the gallery.

Example:

```text
Dropbox
   |
sync/build process
   |
   +-- gallery-manifest.json
   +-- thumbnails and/or cached image URLs
   |
static website
   |
GitHub Pages / S3 / Cloudflare Pages
```

The browser would consume `gallery-manifest.json` through the same conceptual interface it currently receives from `/api/gallery`.

This mode trades immediate Dropbox synchronization for extremely simple, inexpensive static hosting.

The initial MVP should **not implement this mode**, but the architecture must avoid making it difficult.

In particular:

* gallery rendering must not depend directly on Dropbox APIs
* Dropbox metadata must be normalized before reaching UI code
* the gallery manifest format should be documented and stable
* the frontend should consume normalized image records rather than Dropbox-specific records
* image URLs should be treated abstractly by the frontend
* static-manifest support should eventually require little more than replacing the data-source adapter

These two principles are explicit project requirements, not optional future refactoring ideas.

---

# Initial architecture

Use a mostly-static architecture.

Frontend:

```text
index.html
assets/
    dbfolio.js
    dbfolio.css
dbfolio.json
```

Initial PHP Dropbox adapter:

```text
api/
    dbfolio.php
```

The frontend should be plain HTML, CSS and vanilla JavaScript using ES modules.

Do not use React, Vue, Angular, jQuery, Bootstrap or another application framework.

The PHP adapter should also be deliberately lightweight. Prefer native PHP/cURL and no Composer dependencies unless there is a compelling reason otherwise.

The Dropbox app credentials MUST NOT be stored in `dbfolio.json`.

They should come from server-side environment variables.

For example:

```text
DBFOLIO_DROPBOX_APP_KEY
DBFOLIO_DROPBOX_APP_SECRET
```

The public Dropbox shared-folder URL belongs in `dbfolio.json`.

## Browser support

Target evergreen and near-evergreen browsers capable of native ES modules, `fetch`, and CSS Grid — roughly:

```text
Chrome / Edge     last ~5 years
Firefox           last ~5 years
Safari (desktop)  last ~5 years
Safari (iOS)      last ~5 years
Android WebView / Chrome for Android   last ~5 years
```

This is not a bleeding-edge target: it comfortably includes older phones and budget/retro Android devices still running a reasonably current browser app, since mobile browsers auto-update independently of the OS on most platforms. It does **not** include legacy browsers that predate ES modules — Internet Explorer (any version) and old Android "stock" browsers (pre-Chrome, pre-~2013) are explicitly unsupported. This follows directly from the "vanilla JS via ES modules, no framework" decision above, rather than being a separate tradeoff.

Practical implication: avoid bleeding-edge CSS/JS features (e.g. very new CSS functions, experimental APIs) without a graceful fallback, but do not attempt to polyfill or transpile for pre-ES-module browsers. No build/transpile step is required to meet this baseline.

Swipe/touch gestures, keyboard navigation, and the lightbox should degrade gracefully rather than error out on a browser with partial feature support — e.g. if the Fullscreen API or `IntersectionObserver` is unavailable, the gallery should still function, just without that specific enhancement.

---

# Browser/backend division

The browser owns all UI behavior:

* load configuration
* request gallery manifest
* render gallery
* responsive layout
* lazy loading
* lightbox
* keyboard navigation
* touch/swipe navigation
* theming
* captions
* browser history behavior
* error presentation

The source adapter owns:

* obtaining the image inventory
* authenticating with Dropbox when necessary
* translating Dropbox metadata into the dbfolio manifest format
* providing thumbnails
* providing larger display images
* optional source metadata caching

The initial PHP implementation should expose approximately:

```text
GET /api/dbfolio.php?action=gallery
GET /api/dbfolio.php?action=thumbnail&path=...&size=...
GET /api/dbfolio.php?action=image&path=...
```

These URLs may later be cleaned up to REST-style paths without changing the underlying API model.

Do not let a browser request supply an arbitrary Dropbox shared URL.

The backend should read the configured Dropbox URL itself. This prevents somebody from turning the dbfolio API into a generic Dropbox proxy using the application's credentials.

---

# dbfolio manifest format

The frontend should never operate directly on raw Dropbox API records.

The adapter must translate source-specific data into a normalized dbfolio manifest.

Example:

```json
{
  "images": [
    {
      "id": "id:abc123",
      "name": "IMG_1042.jpg",
      "path": "/IMG_1042.jpg",
      "revision": "abc123",
      "modified": "2026-09-18T17:42:13Z",
      "size": 4382811,
      "orientation": 0,
      "thumbnail": "/api/dbfolio.php?action=thumbnail&id=id%3Aabc123",
      "image": "/api/dbfolio.php?action=image&id=id%3Aabc123"
    }
  ]
}
```

The exact schema may evolve during implementation, but it should be treated as a dbfolio API rather than a Dropbox API passthrough.

## Orientation overrides

Dropbox folder metadata does not reliably expose EXIF orientation, and even when a viewer can infer it, some images still render rotated incorrectly (mis-tagged EXIF, screenshots, scans, etc.).

`orientation` on an image record is a normalized rotation hint consumed by the frontend when rendering thumbnails and the lightbox image, expressed in degrees clockwise:

```text
0    no rotation
90
180
270
```

The value defaults to `0` (or an EXIF-derived value, if the adapter chooses to read EXIF during manifest generation) whenever a photo is first seen.

A visitor-facing rotation control is out of scope for MVP. Instead, an explicit correction mechanism is needed so the owner can fix a misrotated photo once and have it stick:

* the adapter maintains a small persistent **orientation override store**, keyed by image `id` (not by manifest position, since manifest order/content is regenerated on every rescan)
* suggested storage: a flat JSON file, e.g. `orientation-overrides.json`, living alongside the backend cache
* on each manifest generation, after normalizing Dropbox metadata, the adapter merges any matching override into the `orientation` field for that image `id`
* an override persists across folder rescans, cache expiry, and photo modification (unless the file's `id` itself changes, e.g. if it is deleted and re-uploaded)
* removing an override reverts the image to its default/EXIF-derived orientation on the next manifest generation

Setting an override for MVP can be a manual/CLI operation (e.g. editing `orientation-overrides.json` directly, or a small helper script) rather than a UI feature — an admin interface is an explicit non-goal. The mechanism should nonetheless be designed so a future lightweight admin tool, or the static-generator sync process, could read/write the same override store without a schema change.

This manifest abstraction is important because a future static build could instead generate:

```json
{
  "images": [
    {
      "id": "abc123",
      "name": "IMG_1042.jpg",
      "modified": "2026-09-18T17:42:13Z",
      "size": 4382811,
      "thumbnail": "media/thumbs/IMG_1042.jpg",
      "image": "media/display/IMG_1042.jpg"
    }
  ]
}
```

The gallery rendering code should work with either manifest without knowing how it was produced.

---

# Dropbox integration

Use Dropbox authentication on the adapter side rather than requiring the gallery visitor to log into Dropbox.

## Authentication model

dbfolio deliberately does **not** use full user OAuth (an interactive consent flow producing a long-lived refresh token plus short-lived access tokens for full-account access). That model exists for apps that need broad access to a user's Dropbox account and requires ongoing token-refresh handling.

dbfolio only ever needs to read the contents of one specific, already-shared folder. Dropbox supports this directly via **app-level authentication scoped to a shared link**:

1. the adapter obtains an app access token from `DBFOLIO_DROPBOX_APP_KEY` / `DBFOLIO_DROPBOX_APP_SECRET` (a client-credentials-style call, no human interaction, no refresh-token lifecycle)
2. that app token is used with endpoints that accept a `shared_link` argument, scoped to the URL from `source.url` in `dbfolio.json`:
   * `sharing/get_shared_link_metadata` — folder metadata
   * `files/list_folder` (with `shared_link` set) — folder listing, with pagination via `files/list_folder/continue`
   * `sharing/get_shared_link_file` — file/thumbnail content for a path within that shared link
3. this grants access only to that shared folder's contents, not the owner's Dropbox account generally

This means there is no token expiry/refresh concern to design around: the app key/secret are long-lived credentials the owner sets once as environment variables, and every request is authenticated fresh from them. If the owner revokes or regenerates the app's key/secret, or un-shares/deletes the folder link, the gallery simply stops working until reconfigured — there is no separate "reconnect Dropbox" step or stale-refresh-token failure mode to handle.

This simpler auth model does not remove the need for a server-side adapter. `DBFOLIO_DROPBOX_APP_SECRET` must never reach the browser, and minting the app access token, plus every authenticated `files/list_folder` / `sharing/get_shared_link_file` call, requires that secret. The browser only ever talks to the dbfolio API (`?action=gallery`/`thumbnail`/`image`); it never calls Dropbox directly.

Use the configured shared-folder link with Dropbox's folder-list API.

The gallery operation should:

1. list the folder
2. follow Dropbox pagination until complete
3. discard directories for MVP
4. discard unsupported file types
5. normalize the metadata
6. sort the resulting images
7. return the dbfolio gallery manifest

Do not expose Dropbox credentials or unnecessary Dropbox API metadata to the client.

Dropbox-specific logic should be isolated as much as practical from general API-response logic.

---

# Thumbnail handling

Gallery pages MUST display thumbnails rather than originals.

Initial thumbnail target should be roughly 480 or 640 pixels.

Use browser-native:

```html
loading="lazy"
decoding="async"
```

Do not preload every thumbnail manually.

Thumbnail URLs should go through the source adapter in the initial PHP implementation.

Example:

```text
/api/dbfolio.php?action=thumbnail&id=abc123&rev=abc123
```

The `rev` parameter may be used for cache busting.

The API should return useful cache headers when the revision is part of the URL.

Suggested starting point:

```text
Cache-Control: public, max-age=86400
```

A later optimization can use longer immutable caching.

The frontend should treat thumbnail URLs as opaque URLs supplied by the manifest.

It should not construct Dropbox URLs itself.

---

# Lightbox

Clicking/tapping a gallery image opens a fullscreen lightbox.

Desktop interaction:

```text
Left Arrow       previous image
Right Arrow      next image
Escape           close lightbox
click left       previous
click right      next
```

Mobile interaction:

```text
tap image        open
swipe left       next
swipe right      previous
tap X            close
```

When a lightbox opens:

1. load only the current large image
2. once loaded, preload the previous image
3. preload the next image
4. do not preload the rest of the gallery

Use approximately 2048px Dropbox thumbnails or equivalent display-sized images for normal lightbox viewing rather than immediately fetching the original photograph.

An optional "view/download original" feature may be added later.

The lightbox image must always fit within the viewport using behavior equivalent to:

```css
object-fit: contain;
```

---

# Gallery presentation

MVP should support two layout choices:

```text
grid
masonry
```

`grid` should be the default because it gives predictable loading and minimal layout shift.

Grid cells may use a configurable aspect ratio and `object-fit: cover`.

Example:

```text
1:1
4:3
3:2
```

Masonry may use natural image proportions and can be implemented later or as a secondary mode.

The gallery must adapt automatically to screen width.

Example behavior:

```text
phone        2 columns
tablet       3-4 columns
desktop      4-6 columns
wide screen  configurable maximum
```

Do this with CSS rather than JavaScript wherever practical.

---

# Configuration

Use one `dbfolio.json`.

Example:

```json
{
  "source": {
    "type": "dropbox",
    "url": "https://www.dropbox.com/scl/fo/..."
  },

  "gallery": {
    "title": "Block Island 2026",
    "description": "Photographs from the trip",
    "published": "2026-09-18",
    "layout": "grid",
    "sort": "filename",
    "direction": "ascending",
    "columns": {
      "minimumWidth": 240,
      "maximum": 6
    },
    "thumbnailSize": 640,
    "lightboxSize": 2048
  },

  "appearance": {
    "theme": "dark",
    "background": "#111111",
    "foreground": "#eeeeee",
    "accent": "#aaaaaa",
    "gap": 8,
    "borderRadius": 2
  },

  "contact": {
    "name": "Dave Shevett",
    "email": "",
    "website": ""
  },

  "features": {
    "showFilenames": false,
    "showContact": true,
    "allowOriginalDownload": false
  },

  "access": {
    "passwordProtected": false,
    "passwordHash": "",
    "allowIndexing": true
  }
}
```

CSS custom properties should be generated from the appearance configuration rather than generating arbitrary CSS rules dynamically.

For example:

```css
:root {
    --dbfolio-background: #111;
    --dbfolio-foreground: #eee;
    --dbfolio-accent: #aaa;
    --dbfolio-gap: 8px;
}
```

## Page metadata

`gallery.title`, `gallery.description`, and `gallery.published` are the single source of truth for both on-page display and document/head metadata. There should be no separate meta configuration block.

On load, the frontend should set at minimum:

```text
document <title>
<meta name="description">
<meta property="og:title">
<meta property="og:description">
```

`gallery.published`, if present, may be surfaced in the UI (e.g. near the gallery title) and used in `og:` metadata where relevant. It is optional and purely informational — it does not affect sorting, caching, or manifest generation.

A favicon is a static asset (`public/assets/favicon.ico` or similar), not a config value, since it rarely changes per-deployment.

If `access.passwordProtected` is `true`, the page should still render a minimal `<title>`/description before unlocking (so shared links look reasonable), but should avoid describing gallery contents in meta tags until unlocked.

## Search engine indexing

`access.allowIndexing` (boolean, default `true`) controls whether search engines are invited to index the gallery.

A fully dynamic, config-driven `robots.txt` would require either a webserver rewrite rule or serving it through the PHP adapter, both of which cut against the "copy files and edit configuration" deployment goal. Instead:

* when `allowIndexing` is `false`, the frontend inserts `<meta name="robots" content="noindex, nofollow">` into `<head>` before rendering the gallery
* `public/robots.txt` ships as a static file with a permissive default; an owner who wants a hard crawler-level `Disallow: /` as well can edit it manually — this is optional belt-and-suspenders, not required
* if `access.passwordProtected` is `true`, indexing is force-disabled regardless of `allowIndexing` — a password-gated gallery should not appear in search results, and content behind the gate can't meaningfully be indexed anyway

This reliably works for crawlers that execute JavaScript (Google, Bing) but not for simpler bots that only honor a static `robots.txt`. That tradeoff is acceptable given the no-build-step deployment goal; a guaranteed block would require the build/routing layer available in deployment Modes 2/3.

---

# Password protection

dbfolio should support an optional, minimal single-shared-password gate — not a user-account system.

`dbfolio.json` is fetched by the browser, so it must never hold the password in plaintext. Instead it holds a salted hash:

```json
"access": {
  "passwordProtected": true,
  "passwordHash": "sha256$<salt>$<hash>"
}
```

The owner sets this by hashing a chosen password with a small provided helper (CLI script or one-off command) and pasting the result into `dbfolio.json`. There is no admin UI for this, consistent with the project's non-goals.

Behavior:

* the frontend reads `access.passwordProtected` from config; if `true`, it shows a password prompt before requesting the gallery manifest
* the entered password is sent to a backend verification endpoint, e.g. `POST /api/dbfolio.php?action=unlock`, rather than compared client-side, so the real secret and hash never need to reach the browser bundle logic
* the backend re-derives the hash from the submitted password (using the salt from `dbfolio.json`) and compares it to `passwordHash`
* on success, the backend sets a short-lived signed session cookie; on failure it returns a generic error
* `?action=gallery`, `?action=thumbnail`, and `?action=image` must all require a valid session when `passwordProtected` is `true` — the gate must protect direct image/thumbnail URLs, not just the initial page load
* there is a single shared password for the whole gallery; there are no per-user accounts, roles, or permissions
* rate-limit unlock attempts to slow brute-forcing (e.g. a short delay or attempt cap per IP), and log failures server-side rather than exposing detail to the client
* when `passwordProtected` is `false` or the `access` block is omitted, the gallery behaves exactly as before — this is an opt-in feature with no effect on the default deployment

This feature does not conflict with the eventual fully-static deployment mode, but it does not apply to it either: a statically generated site with no runtime backend has no server-side session to enforce, so password protection is inherently a runtime-backend-only feature (Modes 1 and 2). This should be documented, not silently unsupported.

---

# Sorting

Support:

```text
filename
modified
```

and:

```text
ascending
descending
```

Filename sort should use natural numeric ordering so:

```text
photo2.jpg
photo9.jpg
photo10.jpg
```

sorts correctly.

Future possibilities:

```text
EXIF capture date
random
explicit manifest order
```

Do not implement those in MVP.

---

# Routing/history

Opening an image should optionally update the URL without navigating away.

Example:

```text
/gallery/#IMG_1042.jpg
```

or:

```text
/gallery/#image=17
```

This makes browser Back close the lightbox and allows a specific image to be shared.

The implementation must still function correctly when JavaScript history manipulation is unavailable.

---

# Accessibility

The gallery cannot rely only on pointer interaction.

Requirements:

* keyboard accessible thumbnails
* visible focus state
* Escape closes lightbox
* arrow keys navigate
* navigation controls have accessible labels
* focus is trapped inside the open lightbox
* focus returns to the selected thumbnail when lightbox closes
* `prefers-reduced-motion` disables large transitions
* buttons must be large enough for touch use

Animations should be subtle.

Do not implement elaborate photo transitions.

---

# Performance

Performance is an explicit product requirement.

The initial page request should consist primarily of:

```text
HTML
CSS
JavaScript
config JSON
gallery manifest
visible thumbnails
```

Original images must not be fetched during initial gallery rendering.

Avoid large third-party JS packages.

Use native browser APIs whenever possible.

Do not implement a client-side image cache beyond normal browser caching unless profiling demonstrates a need.

The goal is for the site to remain comfortable on mobile connections with galleries containing hundreds of photographs.

---

# Backend caching

The PHP adapter should cache Dropbox folder metadata for a short period.

Initial target:

```text
60-300 seconds
```

This means adding an image to Dropbox does not cause every gallery visitor to trigger a complete Dropbox folder query.

Use a simple filesystem JSON cache if available.

If filesystem caching is unavailable, the application must still work without it.

Media responses should send standard HTTP cache headers.

## Self rate limiting

Folder-metadata caching above protects Dropbox from repeated `?action=gallery` calls, but `?action=thumbnail` and `?action=image` are not cached the same way — each request fetches image bytes from Dropbox fresh. A client (misbehaving script, scraper, or someone just holding down a key) hitting either endpoint rapidly can still generate excessive Dropbox traffic and hosting bandwidth/CPU use even though the manifest itself is cached.

The adapter should apply a simple per-IP rate limit to its own endpoints, using the same filesystem-JSON approach already used for metadata caching — no database or external service required:

* track a request count per IP (or a hash of it, for privacy) within a fixed short window, e.g. a JSON file per IP under the cache directory, updated with `flock` to avoid race conditions between concurrent requests
* suggested starting limits: generous for `?action=gallery`/`?action=thumbnail`/`?action=image` (e.g. on the order of 60/minute per IP), stricter for `?action=unlock` (the password-verification endpoint from the "Password protection" section — e.g. on the order of 5-10/minute per IP) to slow brute-force attempts
* exceeding the limit returns `429 Too Many Requests` with a `Retry-After` header, not a Dropbox-shaped error
* consistent with the metadata-cache fallback behavior: if the filesystem is unavailable or not writable, the adapter should fail open (skip rate limiting) rather than fail the whole gallery, but this should be logged server-side as a configuration issue worth fixing

This is a basic abuse guard appropriate to a small personal gallery, not a general-purpose DDoS defense — a host-level or CDN-level rate limit remains the right tool for larger-scale abuse and is outside dbfolio's scope.

---

# Error behavior

Handle the following cleanly:

* invalid configuration
* missing Dropbox share URL
* Dropbox link no longer exists
* Dropbox API unavailable
* rate limiting
* empty gallery
* unsupported image format
* image deleted between manifest generation and viewing
* thumbnail generation failure

Visitors should see simple human-readable gallery errors.

Detailed Dropbox/API errors should be logged server-side rather than displayed publicly.

---

# File support

MVP gallery extensions:

```text
.jpg
.jpeg
.png
.webp
.gif
.tif
.tiff
.bmp
```

Treat filename extensions case-insensitively.

HEIC should be explicitly considered unsupported in MVP unless testing demonstrates a simple Dropbox-native conversion mechanism.

Large images for which Dropbox cannot produce a thumbnail should fail gracefully rather than causing the gallery itself to fail.

Later releases may add server-side fallback conversion.

---

# License

dbfolio is source-available, not OSI-approved "open source": free to use, copy, modify, and share with attribution, but **not** for incorporation into a commercial product or service without explicit permission from the author.

License: [PolyForm Noncommercial 1.0.0](https://polyformproject.org/licenses/noncommercial/1.0.0), text in `LICENSE`.

Commercial use requires contacting the author directly for a separate license.

---

# Security

Never expose:

```text
Dropbox app secret
Dropbox application authentication token
server filesystem paths
raw Dropbox API errors
```

Do not accept arbitrary Dropbox URLs through API request parameters.

Sanitize all requested image paths or IDs.

Only serve a media object if it belongs to the configured gallery.

Set a restrictive Content Security Policy where practical.

If the API and frontend are hosted on separate domains, CORS should allow only the configured frontend origin rather than `*`.

---

# Deployment modes

The following deployment models should be considered part of the product architecture.

## Mode 1 — PHP adapter

This is the initial implementation.

```text
Apache/nginx/PHP
    index.html
    dbfolio.json
    assets/*
    api/dbfolio.php
```

This provides simple deployment on inexpensive conventional hosting.

The PHP component is an adapter, not the application itself.

Prerequisite: the host must have the PHP interpreter enabled for the `api/` directory (or site-wide, which most conventional shared hosting provides by default). Some restrictive hosts disable PHP execution per-directory or require it to be explicitly turned on — this should be called out in deployment documentation as a first thing to verify if `api/dbfolio.php` returns raw source or a 403/404 instead of executing.

## Mode 2 — static frontend + serverless adapter

A future deployment may use:

```text
GitHub Pages / S3 / Cloudflare Pages
            |
            v
Cloudflare Worker / Lambda / similar
            |
            v
Dropbox
```

The browser application must remain unchanged.

Only the implementation behind the dbfolio source API changes.

## Mode 3 — generated fully static gallery

This is an explicit future architectural target.

```text
Dropbox
   |
sync/build program
   |
   +-- gallery-manifest.json
   +-- thumbnails/display images as needed
   |
S3 / GitHub Pages / Cloudflare Pages
```

Possible synchronization mechanisms include:

```text
GitHub Action
local command-line program
cron job
CI/CD pipeline
scheduled serverless job
```

The generated website requires no PHP, Node, database, API credentials or runtime server-side code.

The tradeoff is that Dropbox changes appear only after synchronization occurs.

A future implementation might provide a command such as:

```text
dbfolio sync
```

which:

1. reads the Dropbox folder
2. determines added/removed/changed photographs
3. generates or refreshes thumbnails
4. writes `gallery-manifest.json`
5. publishes or leaves a completely static deployable tree

Do not implement this in MVP.

However, consider compatibility with it when defining the manifest and frontend APIs.

---

# Suggested project structure

```text
dbfolio/
├── README.md
├── LICENSE
├── dbfolio.example.json
│
├── public/
│   ├── index.html
│   ├── dbfolio.json
│   │
│   └── assets/
│       ├── dbfolio.css
│       └── dbfolio.js
│
├── api/
│   └── dbfolio.php
│
└── docs/
    ├── architecture.md
    ├── manifest.md
    ├── configuration.md
    └── deployment.md
```

No build system should be necessary for normal PHP deployment.

Development tooling is acceptable, but a downloaded release should be deployable by copying files and editing configuration.

`docs/architecture.md` should explicitly document the frontend/adapter separation.

`docs/manifest.md` should document the normalized dbfolio manifest independently of Dropbox.

---

# Implementation phases

## Phase 1 — Dropbox proof of concept

Before building UI, create a small PHP script proving that the selected Dropbox authentication approach can:

1. read the configured Dropbox shared folder
2. list its contents
3. fetch a thumbnail for a returned file
4. fetch/display that file
5. handle pagination

This is the critical technical spike.

Do not proceed to elaborate UI work until this works reliably with current Dropbox shared-folder links.

## Phase 2 — define dbfolio manifest and adapter contract

Before building the full API, formally define the normalized image structure returned to the frontend.

Document:

```text
gallery response
image record
thumbnail URL semantics
display-image URL semantics
errors
cache behavior
```

The contract must contain no unnecessary Dropbox-specific assumptions.

This is important because both serverless adapters and a future static-manifest generator must be able to implement the same conceptual interface.

## Phase 3 — PHP adapter

Implement:

```text
?action=gallery
?action=thumbnail
?action=image
```

Add filtering, path/ID validation, Dropbox error translation and caching.

Keep Dropbox-specific code isolated where practical.

## Phase 4 — basic frontend

Create:

```text
index.html
dbfolio.js
dbfolio.css
```

Load config, fetch the normalized gallery manifest and render responsive thumbnails.

No lightbox libraries.

## Phase 5 — lightbox

Implement fullscreen viewing, keyboard controls, close behavior, previous/next navigation and adjacent-image preload.

## Phase 6 — mobile

Implement touch-friendly controls and swipe navigation.

Test narrow screens and high-DPI screens.

## Phase 7 — configuration/theming

Move all presentation options into `dbfolio.json`.

Ensure a usable gallery requires changing only:

```text
Dropbox URL
gallery title
optional contact information
```

## Phase 8 — production hardening

Add:

* caching
* error states
* accessibility
* CSP/security headers
* rate-limit behavior
* documentation
* example configuration

## Future Phase — static generator

After the normal Dropbox-backed version is mature, create a separate adapter/build utility capable of generating:

```text
gallery-manifest.json
media/thumbs/*
media/display/*
```

for completely static deployment.

The existing frontend should require little or no modification to consume it.

---

# MVP acceptance criteria

A new PHP installation should require approximately:

1. copy dbfolio files to a web host
2. create/configure Dropbox application credentials
3. set required server environment variables
4. paste Dropbox shared-folder URL into `dbfolio.json`
5. visit `index.html`

The user must then see the folder's photographs.

A visitor must be able to:

```text
browse responsive thumbnails
click a photo
see a large version
move left/right
use keyboard arrows
use swipe gestures on mobile
press Escape to close
return to the gallery without reloading
```

Changing or adding photographs in the Dropbox folder should automatically be reflected after the metadata cache expires.

The frontend must not know that its manifest came from Dropbox or PHP.

That requirement should be testable by replacing the live gallery response with a static JSON manifest and confirming that gallery rendering still works.

---

# Explicit non-goals for MVP

Do not add:

* user accounts
* comments
* image uploads
* photo editing
* database storage
* React/Vue/etc.
* complex animation libraries
* admin dashboard
* EXIF editor
* album hierarchy
* video support
* slideshow
* image conversion pipeline
* search
* static Dropbox synchronization
* a formal automated testing strategy (unit tests, CI, etc.)

The gallery is a read-only, non-destructive tool — it never writes to Dropbox or holds state that a bug could corrupt or lose. That risk profile is a deliberate reason to skip formal testing infrastructure for MVP rather than an oversight; manual verification against a real shared folder during each phase is sufficient.

The initial product should do one thing extremely well:

**turn a Dropbox shared photo folder into a clean, fast, configurable web gallery.**

The implementation should accomplish that while preserving two intentional long-term capabilities:

**the Dropbox adapter can be replaced without rewriting the frontend, and the entire product can eventually be deployed as a generated static website with no runtime backend.**


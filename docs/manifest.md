# dbfolio manifest & adapter contract

This document is the normative contract between the dbfolio frontend and
any backend/adapter that serves it. It corresponds to Phase 2 in
`docs/project-plan.md`.

It intentionally contains **no Dropbox-specific concepts**. Any adapter
— the initial PHP/Dropbox adapter, a future serverless adapter, or a
statically generated `gallery-manifest.json` with no runtime backend at
all — must be interchangeable as long as it satisfies this contract.

The frontend must be implementable and testable entirely against this
document, without reference to Dropbox.

---

## 1. Gallery response

Requested via the conceptual `GET /api/gallery` operation (in the
initial PHP adapter: `GET /api/dbfolio.php?action=gallery`; in a static
deployment: `gallery-manifest.json` fetched directly).

```json
{
  "gallery": {
    "title": "Block Island 2026",
    "description": "Photographs from the trip"
  },
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

`gallery.title` / `gallery.description` in the response are informational
echoes of the configured values (useful for a static-manifest deployment
that has no separate `dbfolio.json` fetch). The frontend's own
`dbfolio.json` remains the authoritative source for display and page
metadata; these fields exist so the manifest is self-describing on its
own.

### Top-level fields

| Field | Type | Required | Notes |
|---|---|---|---|
| `gallery.title` | string | no | informational only |
| `gallery.description` | string | no | informational only |
| `images` | array of image records | yes | may be empty |

### Ordering is not authoritative

The order of `images` in the response is **not guaranteed** to reflect
the sort order requested in `dbfolio.json` (`gallery.sort` /
`gallery.direction`). An adapter *may* pre-sort for efficiency, but the
frontend **must** apply the configured sort itself regardless of manifest
order. This keeps correctness independent of any given adapter's
behavior — including a hand-built or third-party static manifest that
was never sorted at all.

---

## 2. Image record

| Field | Type | Required | Notes |
|---|---|---|---|
| `id` | string | yes | opaque, stable identifier. Must be unique within the manifest. Must not be assumed to be a file path. |
| `name` | string | yes | display filename, e.g. `IMG_1042.jpg` |
| `path` | string | no | adapter-specific path hint; frontend must not construct URLs from it |
| `revision` | string | no | opaque version/change marker for the file's current content. Used for cache-busting and change detection, not for display. |
| `modified` | string | yes | ISO 8601 UTC timestamp, e.g. `2026-09-18T17:42:13Z` |
| `size` | integer | no | bytes, if known |
| `orientation` | integer | no | one of `0`, `90`, `180`, `270` — clockwise rotation hint. Defaults to `0` if absent. See "Orientation overrides" in the project plan. |
| `thumbnail` | string (URL) | yes | see §3 |
| `image` | string (URL) | yes | see §4 |

Only `id`, `name`, `modified`, `thumbnail`, and `image` are required. This
minimal required set is deliberate: it is achievable by both a live
adapter and a very simple static generator without extra bookkeeping.

`id` stability matters most: it is the join key for orientation
overrides (see the project plan) and for any future client-side state
keyed to a specific photo. An adapter must not reuse an `id` for a
different photo, and should keep the same `id` for the same photo across
manifest regenerations whenever possible.

---

## 3. Thumbnail URL semantics

`thumbnail` is an **opaque URL** supplied by the manifest. The frontend
must not construct, guess, or transform it — it renders exactly the URL
given.

Requirements on whatever serves that URL:

* returns an image suitable for grid display (initial target: ~480–640px
  on the long edge; see "Thumbnail handling" in the project plan)
* responds to a normal `GET` with no additional client-supplied
  parameters required
* should include cache headers appropriate to how stable the URL is (see
  §5); if the URL embeds `revision`, it may be treated as effectively
  immutable and cached aggressively
* on failure for a specific image (e.g. Dropbox couldn't generate a
  thumbnail), must fail as a normal HTTP error for that one request
  (e.g. `404` or `502`) rather than corrupting the manifest or failing
  the whole gallery request

---

## 4. Display-image URL semantics

`image` is likewise an **opaque URL**, used by the lightbox for the
large/display-size view.

* target size: approximately 2048px on the long edge, or whatever the
  adapter considers its standard "display" rendition — not necessarily
  the original full-resolution file (see "Lightbox" in the project plan)
* same opacity, GET, and per-image failure requirements as §3
* an adapter *may* choose to serve the original file here if it has no
  separate display-size rendition; the frontend must not assume either
  way — it just requests the URL and displays whatever comes back

Fetching the original/full-resolution file (if a "download original"
feature is enabled) is a separate, explicitly opt-in concern — see
`features.allowOriginalDownload` in the project plan — and is out of
scope for this contract's `image` field.

---

## 5. Cache behavior

Two different things are cached, at different layers, and this contract
only governs the second:

* **Manifest/metadata caching** (adapter-internal, e.g. the PHP adapter's
  60–300 second filesystem cache) is an implementation detail of a given
  adapter and not part of this contract — a static-manifest deployment
  has no equivalent at all.
* **HTTP cache headers on `thumbnail`/`image` responses** are part of
  this contract: any adapter serving those URLs should send standard
  `Cache-Control` headers, and should treat a URL that embeds `revision`
  or `id`+`revision` as safely cacheable long-term, since a changed file
  is expected to produce a different URL (or at least a different
  `revision` in the manifest, prompting a different query string).

Suggested starting point (also in the project plan):

```text
Cache-Control: public, max-age=86400
```

The frontend must not itself implement a manual image cache beyond
normal browser caching (see "Performance" in the project plan) — it
relies on these headers.

---

## 6. Errors

### Gallery-level errors

If the gallery-level request itself fails (not a single image), the
adapter must respond with a non-2xx HTTP status and a JSON body:

```json
{
  "error": {
    "code": "source_unavailable",
    "message": "The photo source could not be reached. Please try again shortly."
  }
}
```

| Field | Type | Notes |
|---|---|---|
| `error.code` | string | stable, machine-readable identifier — see table below |
| `error.message` | string | human-readable, safe to display to visitors as-is |

`error.message` must never contain adapter-internal or source-specific
detail (Dropbox error text, file paths, stack traces, credentials). That
detail belongs in server-side logs only — see "Security" and "Error
behavior" in the project plan.

Suggested `error.code` values, mapped to the scenarios in the project
plan's "Error behavior" section:

| `error.code` | Typical HTTP status | Scenario |
|---|---|---|
| `configuration_invalid` | 500 | invalid/missing configuration |
| `source_not_configured` | 500 | missing share URL / source config |
| `source_not_found` | 404 | the shared link no longer exists |
| `source_unavailable` | 502 | upstream (e.g. Dropbox) API unavailable |
| `rate_limited` | 429 | upstream or dbfolio's own rate limit hit — include `Retry-After` |
| `unauthorized` | 401 | password-protected gallery, no/invalid session |

An empty gallery (zero supported images found) is **not** an error: it
is a normal 200 response with `images: []`. The frontend is responsible
for presenting an "empty gallery" state.

### Per-image errors

A single broken/missing thumbnail or image is not a gallery-level error.
The `thumbnail`/`image` endpoints return a normal HTTP error status
(`404`, `502`, etc.) for that one request; the frontend must handle a
single failed image gracefully (e.g. a placeholder tile) without failing
the rest of the gallery.

---

## 7. Adapter conformance checklist

An implementation (PHP adapter, future serverless adapter, or static
generator) satisfies this contract if it:

* [ ] returns the gallery response shape in §1, with `images` as an array
      (possibly empty) of valid image records
* [ ] every image record has at minimum `id`, `name`, `modified`,
      `thumbnail`, `image`
* [ ] `id` is stable and unique within the manifest
* [ ] `thumbnail` and `image` are directly fetchable URLs requiring no
      further client-side construction
* [ ] gallery-level failures use the `error` shape in §6, with a
      `message` safe for direct display and no source-specific detail
* [ ] a single broken image fails only that image's request, not the
      whole manifest
* [ ] `orientation`, when present, is one of `0`/`90`/`180`/`270`

This checklist is what "the frontend must not know that its manifest
came from Dropbox or PHP" (project plan, MVP acceptance criteria) means
in concrete, testable terms: swap in any implementation that satisfies
this document, and the frontend should work unmodified.

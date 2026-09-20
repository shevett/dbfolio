// dbfolio frontend — Phase 4 (config/manifest/grid) + Phase 5 (lightbox).
// No touch/swipe handling yet (Phase 6).

const galleryEl = document.getElementById('db-gallery');
const statusEl = document.getElementById('db-status');
const titleEl = document.getElementById('db-title');
const descriptionEl = document.getElementById('db-description');
const contactEl = document.getElementById('db-contact');
const unlockEl = document.getElementById('db-unlock');
const unlockFormEl = document.getElementById('db-unlock-form');
const unlockErrorEl = document.getElementById('db-unlock-error');

const lightboxEl = document.getElementById('db-lightbox');
const lightboxImageEl = document.getElementById('db-lightbox-image');
const lightboxCaptionEl = document.getElementById('db-lightbox-caption');
const lightboxCloseEl = document.getElementById('db-lightbox-close');
const lightboxPrevEl = document.getElementById('db-lightbox-prev');
const lightboxNextEl = document.getElementById('db-lightbox-next');
const lightboxInfoEl = document.getElementById('db-lightbox-info');
const lightboxMetaEl = document.getElementById('db-lightbox-meta');

const API_URL = 'api/dbfolio.php';

// Lightbox state
let lightboxImages = [];
let lightboxIndex = -1;
let lightboxTriggerEl = null;
let lightboxShowFilenames = false;
let lightboxMetaVisible = false;

async function main() {
  let config;
  try {
    config = await loadConfig();
  } catch (err) {
    setStatus(`This gallery is not configured correctly. Please contact the site owner. (${err.message})`);
    console.error('dbfolio: failed to load dbfolio.json', err);
    return;
  }

  applyAppearance(config);
  applyPageMetadata(config);

  await loadAndRenderGallery(config);
}

async function loadConfig() {
  let response;
  try {
    response = await fetch('dbfolio.json', { cache: 'no-store' });
  } catch (err) {
    // A network-level failure (fetch itself rejecting) — e.g. the page
    // was opened as a local file:// URL, where fetch of a sibling file
    // is blocked by the browser regardless of dbfolio.json's contents.
    throw new Error(`could not fetch dbfolio.json: ${err.message}`);
  }

  if (!response.ok) {
    throw new Error(`dbfolio.json: HTTP ${response.status} — check the file exists at this path and is readable`);
  }

  try {
    return await response.json();
  } catch (err) {
    throw new Error(`dbfolio.json is not valid JSON: ${err.message}`);
  }
}

function applyAppearance(config) {
  const appearance = config.appearance || {};
  const gallery = config.gallery || {};
  const root = document.documentElement.style;

  if (appearance.background) root.setProperty('--dbfolio-background', appearance.background);
  if (appearance.foreground) root.setProperty('--dbfolio-foreground', appearance.foreground);
  if (appearance.accent) root.setProperty('--dbfolio-accent', appearance.accent);
  if (appearance.gap != null) root.setProperty('--dbfolio-gap', `${appearance.gap}px`);
  if (appearance.borderRadius != null) root.setProperty('--dbfolio-border-radius', `${appearance.borderRadius}px`);

  const columns = gallery.columns || {};
  if (columns.minimumWidth) root.setProperty('--dbfolio-column-min-width', `${columns.minimumWidth}px`);
  if (columns.maximum) root.setProperty('--dbfolio-column-max', String(columns.maximum));
}

function applyPageMetadata(config) {
  const gallery = config.gallery || {};
  const access = config.access || {};

  const title = gallery.title || 'dbfolio';
  const description = gallery.description || '';

  document.title = title;
  setMeta('description', description);
  setMeta('og:title', title, 'property');
  setMeta('og:description', description, 'property');

  titleEl.textContent = title;
  descriptionEl.textContent = description;

  const allowIndexing = access.allowIndexing !== false && !access.passwordProtected;
  setMeta('robots', allowIndexing ? 'index, follow' : 'noindex, nofollow');

  if (config.features && config.features.showContact && config.contact) {
    renderContact(config.contact);
  }
}

function setMeta(name, content, attr = 'name') {
  let el = document.querySelector(`meta[${attr}="${name}"]`);
  if (!el) {
    el = document.createElement('meta');
    el.setAttribute(attr, name);
    document.head.appendChild(el);
  }
  el.setAttribute('content', content);
}

function renderContact(contact) {
  const parts = [];
  if (contact.name) parts.push(escapeHtml(contact.name));
  if (contact.email) parts.push(`<a href="mailto:${escapeAttr(contact.email)}">${escapeHtml(contact.email)}</a>`);
  if (contact.website) parts.push(`<a href="${escapeAttr(contact.website)}">${escapeHtml(contact.website)}</a>`);
  if (parts.length === 0) return;
  contactEl.innerHTML = parts.join(' &middot; ');
  contactEl.hidden = false;
}

async function loadAndRenderGallery(config) {
  setStatus('Loading gallery…');

  const response = await fetch(`${API_URL}?action=gallery`, { credentials: 'same-origin' });

  if (response.status === 401) {
    setStatus('');
    showUnlockForm(config);
    return;
  }

  if (!response.ok) {
    const body = await safeJson(response);
    setStatus(body?.error?.message || 'The gallery could not be loaded. Please try again shortly.');
    return;
  }

  const manifest = await response.json();
  renderGallery(manifest, config);
}

function showUnlockForm(config) {
  unlockEl.hidden = false;
  unlockFormEl.addEventListener('submit', async (event) => {
    event.preventDefault();
    unlockErrorEl.hidden = true;

    const password = document.getElementById('db-password').value;
    const body = new URLSearchParams({ password });

    const response = await fetch(`${API_URL}?action=unlock`, {
      method: 'POST',
      credentials: 'same-origin',
      body,
    });

    if (!response.ok) {
      const data = await safeJson(response);
      unlockErrorEl.textContent = data?.error?.message || 'Incorrect password.';
      unlockErrorEl.hidden = false;
      return;
    }

    unlockEl.hidden = true;
    await loadAndRenderGallery(config);
  });
}

function renderGallery(manifest, config) {
  const images = sortImages([...(manifest.images || [])], config.gallery || {});

  if (images.length === 0) {
    setStatus('No photos yet.');
    return;
  }

  setStatus('');
  galleryEl.innerHTML = '';

  const showFilenames = !!(config.features && config.features.showFilenames);

  lightboxImages = images;
  lightboxShowFilenames = showFilenames;

  images.forEach((image, index) => {
    galleryEl.appendChild(renderTile(image, index, showFilenames));
  });
}

function renderTile(image, index, showFilenames) {
  const button = document.createElement('button');
  button.type = 'button';
  button.className = 'db-tile';
  button.dataset.imageId = image.id;

  const img = document.createElement('img');
  img.src = image.thumbnail;
  img.alt = image.name || '';
  img.loading = 'lazy';
  img.decoding = 'async';
  if (image.orientation) {
    img.style.transform = `rotate(${image.orientation}deg)`;
  }
  button.appendChild(img);

  if (showFilenames) {
    const caption = document.createElement('span');
    caption.className = 'db-filename';
    caption.textContent = image.name;
    button.appendChild(caption);
  }

  button.addEventListener('click', () => openLightbox(index, button));

  return button;
}

// --- lightbox -----------------------------------------------------------

function openLightbox(index, triggerEl) {
  lightboxTriggerEl = triggerEl;
  lightboxIndex = index;

  lightboxEl.hidden = false;
  document.addEventListener('keydown', onLightboxKeydown);

  showLightboxImage(lightboxIndex);
  lightboxCloseEl.focus();
}

const exifCache = new Map(); // image id -> metadata object, avoids refetching on repeat toggles

function hideMeta() {
  lightboxMetaVisible = false;
  lightboxMetaEl.hidden = true;
  lightboxInfoEl.setAttribute('aria-pressed', 'false');
}

async function toggleMeta() {
  if (lightboxMetaVisible) {
    hideMeta();
    return;
  }
  const image = lightboxImages[lightboxIndex];
  if (!image) return;

  lightboxMetaVisible = true;
  lightboxInfoEl.setAttribute('aria-pressed', 'true');
  lightboxMetaEl.innerHTML = '<dd class="db-lightbox-meta-loading">Loading details…</dd>';
  lightboxMetaEl.hidden = false;

  const exif = await fetchExifMetadata(image.id);

  // The user may have closed the panel or navigated to a different
  // photo while the fetch was in flight — don't clobber that.
  if (!lightboxMetaVisible || lightboxImages[lightboxIndex] !== image) return;

  renderMeta(image, exif);
}

async function fetchExifMetadata(id) {
  if (exifCache.has(id)) {
    return exifCache.get(id);
  }
  try {
    const response = await fetch(`${API_URL}?action=metadata&id=${encodeURIComponent(id)}`, {
      credentials: 'same-origin',
    });
    if (!response.ok) return {};
    const data = await response.json();
    const metadata = data.metadata || {};
    exifCache.set(id, metadata);
    return metadata;
  } catch {
    return {}; // metadata is a nice-to-have; never block the viewer on it
  }
}

function renderMeta(image, exif) {
  lightboxMetaEl.innerHTML = '';

  // Prefer the real EXIF capture time; Dropbox's file-modified date is
  // only a fallback for photos where EXIF is unavailable (PNGs, screen-
  // shots, or files EXIF was stripped from).
  if (exif.taken) {
    addMetaRow('Taken', exif.taken);
  } else {
    addMetaRow('Modified', formatDate(image.modified));
  }

  addMetaRow('Camera', exif.camera || '');

  const exposureParts = [exif.exposureTime, exif.aperture, exif.iso ? `ISO ${exif.iso}` : ''].filter(Boolean);
  if (exposureParts.length > 0) {
    addMetaRow('Exposure', exposureParts.join(' · '));
  }
  addMetaRow('Focal length', exif.focalLength || '');

  addMetaRow('Filename', image.name || '');
  if (image.size != null) {
    addMetaRow('Size', formatBytes(image.size));
  }
}

function addMetaRow(label, value) {
  if (!value) return;
  const dt = document.createElement('dt');
  dt.textContent = label;
  const dd = document.createElement('dd');
  dd.textContent = value;
  lightboxMetaEl.appendChild(dt);
  lightboxMetaEl.appendChild(dd);
}

function formatDate(iso) {
  if (!iso) return '';
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return '';
  return date.toLocaleString(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
  });
}

function formatBytes(bytes) {
  if (bytes < 1024) return `${bytes} B`;
  const units = ['KB', 'MB', 'GB'];
  let value = bytes / 1024;
  let unitIndex = 0;
  while (value >= 1024 && unitIndex < units.length - 1) {
    value /= 1024;
    unitIndex++;
  }
  return `${value.toFixed(1)} ${units[unitIndex]}`;
}

function closeLightbox() {
  lightboxEl.hidden = true;
  document.removeEventListener('keydown', onLightboxKeydown);
  lightboxIndex = -1;

  if (lightboxTriggerEl) {
    lightboxTriggerEl.focus();
    lightboxTriggerEl = null;
  }
}

function showLightboxImage(index) {
  const image = lightboxImages[index];
  if (!image) return;

  hideMeta(); // avoid showing stale metadata for the previous photo

  lightboxImageEl.classList.add('db-loading');
  lightboxImageEl.src = image.image;
  lightboxImageEl.alt = image.name || '';
  lightboxImageEl.style.transform = image.orientation ? `rotate(${image.orientation}deg)` : '';
  lightboxImageEl.onload = () => lightboxImageEl.classList.remove('db-loading');

  lightboxCaptionEl.textContent = lightboxShowFilenames ? (image.name || '') : '';

  lightboxPrevEl.disabled = lightboxImages.length <= 1;
  lightboxNextEl.disabled = lightboxImages.length <= 1;

  preloadAdjacent(index);
}

function preloadAdjacent(index) {
  const prev = lightboxImages[index - 1];
  const next = lightboxImages[index + 1];
  if (prev) new Image().src = prev.image;
  if (next) new Image().src = next.image;
}

function showPrevImage() {
  if (lightboxImages.length === 0) return;
  lightboxIndex = (lightboxIndex - 1 + lightboxImages.length) % lightboxImages.length;
  showLightboxImage(lightboxIndex);
}

function showNextImage() {
  if (lightboxImages.length === 0) return;
  lightboxIndex = (lightboxIndex + 1) % lightboxImages.length;
  showLightboxImage(lightboxIndex);
}

function onLightboxKeydown(event) {
  switch (event.key) {
    case 'Escape':
      event.preventDefault();
      closeLightbox();
      break;
    case 'ArrowLeft':
      event.preventDefault();
      showPrevImage();
      break;
    case 'ArrowRight':
      event.preventDefault();
      showNextImage();
      break;
    case 'Tab':
      trapFocus(event);
      break;
  }
}

function trapFocus(event) {
  const focusable = [lightboxInfoEl, lightboxCloseEl, lightboxPrevEl, lightboxNextEl].filter((el) => !el.disabled);
  if (focusable.length === 0) return;

  const first = focusable[0];
  const last = focusable[focusable.length - 1];

  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault();
    last.focus();
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault();
    first.focus();
  }
}

lightboxCloseEl.addEventListener('click', closeLightbox);
lightboxPrevEl.addEventListener('click', showPrevImage);
lightboxNextEl.addEventListener('click', showNextImage);
lightboxInfoEl.addEventListener('click', toggleMeta);
lightboxImageEl.addEventListener('click', toggleMeta);
lightboxMetaEl.addEventListener('click', hideMeta);
lightboxEl.addEventListener('click', (event) => {
  if (event.target === lightboxEl) {
    closeLightbox();
  }
});

function sortImages(images, gallery) {
  const sort = gallery.sort || 'filename';
  const direction = gallery.direction || 'ascending';

  images.sort((a, b) => {
    if (sort === 'modified') {
      return a.modified < b.modified ? -1 : a.modified > b.modified ? 1 : 0;
    }
    return naturalCompare(a.name || '', b.name || '');
  });

  if (direction === 'descending') {
    images.reverse();
  }

  return images;
}

function naturalCompare(a, b) {
  return a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' });
}

function setStatus(message) {
  statusEl.textContent = message;
}

async function safeJson(response) {
  try {
    return await response.json();
  } catch {
    return null;
  }
}

function escapeHtml(value) {
  return String(value).replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  })[c]);
}

function escapeAttr(value) {
  return escapeHtml(value);
}

main();

// dbfolio frontend — Phase 4: config, manifest fetch, responsive grid.
// No lightbox yet (Phase 5) and no touch/swipe handling yet (Phase 6).

const galleryEl = document.getElementById('db-gallery');
const statusEl = document.getElementById('db-status');
const titleEl = document.getElementById('db-title');
const descriptionEl = document.getElementById('db-description');
const contactEl = document.getElementById('db-contact');
const unlockEl = document.getElementById('db-unlock');
const unlockFormEl = document.getElementById('db-unlock-form');
const unlockErrorEl = document.getElementById('db-unlock-error');

const API_URL = 'api/dbfolio.php';

async function main() {
  let config;
  try {
    config = await loadConfig();
  } catch (err) {
    setStatus('This gallery is not configured correctly. Please contact the site owner.');
    console.error(err);
    return;
  }

  applyAppearance(config);
  applyPageMetadata(config);

  await loadAndRenderGallery(config);
}

async function loadConfig() {
  const response = await fetch('dbfolio.json', { cache: 'no-store' });
  if (!response.ok) {
    throw new Error(`Failed to load dbfolio.json: HTTP ${response.status}`);
  }
  return response.json();
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

  for (const image of images) {
    galleryEl.appendChild(renderTile(image, showFilenames));
  }
}

function renderTile(image, showFilenames) {
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

  // Lightbox open behavior arrives in Phase 5.

  return button;
}

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

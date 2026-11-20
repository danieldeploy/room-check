(() => {
  'use strict';
  const dataNode = document.getElementById('rental-data');
  if (!dataNode) return;
  const data = JSON.parse(dataNode.textContent);
  const cards = [...document.querySelectorAll('.room-card')];
  const filters = document.querySelector('.filters');
  const count = document.querySelector('.result-count');
  const filterButtons = [...document.querySelectorAll('[data-filter]')];
  if (filters) filters.hidden = false;
  const setFilter = value => {
    cards.forEach(card => { card.hidden = value !== 'all' && card.dataset.bathroom !== value; });
    filterButtons.forEach(button => button.setAttribute('aria-pressed', String(button.dataset.filter === value)));
    count.textContent = `${cards.filter(card => !card.hidden).length} ${data.labels.count}`;
  };
  filterButtons.forEach(button => button.addEventListener('click', () => setFilter(button.dataset.filter)));
  setFilter('all');
  const dialog = document.getElementById('gallery');
  let photos = [], position = 0, opener;
  const image = document.getElementById('gallery-image');
  const caption = document.getElementById('gallery-caption');
  const thumbs = document.getElementById('gallery-thumbs');
  const renderPhoto = () => {
    const photo = photos[position];
    image.src = `rentals/${photo.file}`;
    image.alt = photo.label;
    caption.textContent = `${photo.label} · ${position + 1} / ${photos.length}`;
    [...thumbs.children].forEach((thumb, i) => thumb.setAttribute('aria-current', String(i === position)));
  };
  const move = delta => { position = (position + delta + photos.length) % photos.length; renderPhoto(); };
  const commonPhotos = data.common.map(photo => ({file: photo.file, label: photo[data.lang]}));
  if (dialog && typeof dialog.showModal === 'function') {
    document.querySelectorAll('[data-gallery]').forEach(link => link.addEventListener('click', event => {
      const key = link.dataset.gallery;
      const room = data.rooms.find(item => String(item.number) === key);
      photos = room ? room.photos.map(file => ({file, label: `${data.labels.room} ${room.number}`})).concat(commonPhotos) : commonPhotos;
      if (!photos.length) return;
      event.preventDefault(); opener = link;
      const clicked = link.getAttribute('href').split('/').pop();
      position = Math.max(0, photos.findIndex(photo => photo.file === clicked));
      document.getElementById('gallery-title').textContent = room ? `${data.labels.room} ${room.number}` : data.labels.common;
      thumbs.replaceChildren();
      photos.forEach((photo, index) => {
        const button = document.createElement('button'); button.type = 'button';
        button.setAttribute('aria-label', `${photo.label} ${index + 1}`);
        const thumb = document.createElement('img'); thumb.src = `rentals/${photo.file}`; thumb.alt = ''; thumb.loading = 'lazy';
        button.append(thumb); button.addEventListener('click', () => {position = index; renderPhoto();}); thumbs.append(button);
      });
      renderPhoto(); dialog.showModal(); document.body.classList.add('gallery-open');
      document.getElementById('gallery-close').focus();
    }));
    document.getElementById('gallery-close').addEventListener('click', () => dialog.close());
    document.getElementById('gallery-prev').addEventListener('click', () => move(-1));
    document.getElementById('gallery-next').addEventListener('click', () => move(1));
    dialog.addEventListener('keydown', event => {
      if (event.key === 'ArrowLeft') {event.preventDefault(); move(-1);}
      if (event.key === 'ArrowRight') {event.preventDefault(); move(1);}
    });
    dialog.addEventListener('click', event => {if (event.target === dialog) dialog.close();});
    dialog.addEventListener('close', () => {document.body.classList.remove('gallery-open'); opener?.focus();});
  }
  const copy = document.querySelector('.copy-link');
  if (copy) {
    copy.hidden = false;
    copy.addEventListener('click', async () => {
      try {await navigator.clipboard.writeText(location.href); document.getElementById('copy-status').textContent = data.labels.copied;}
      catch {document.getElementById('copy-status').textContent = data.labels.copyFailed;}
    });
  }
})();

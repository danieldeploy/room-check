'use strict';
const receiptPreviewClosers=new Map();
function initializeReceiptPreviews(){
  for(const [root,close] of receiptPreviewClosers)if(!root.isConnected){close();receiptPreviewClosers.delete(root);}
  document.querySelectorAll('[data-receipt-preview]').forEach((root) => {
  if(receiptPreviewClosers.has(root))return;
  const button = root.querySelector('summary');
  const panel = root.querySelector('[data-preview-panel]');
  const status = panel.querySelector('[role="status"]');
  const content = panel.querySelector('[data-document-content]');
  let controller = null;
  let objectUrl = null;
  function close() {
    if (controller) controller.abort();
    controller = null;
    content.replaceChildren();
    if (objectUrl) URL.revokeObjectURL(objectUrl);
    objectUrl = null;
    status.textContent = '';
  }
  root.addEventListener('toggle', async () => {
    if (!root.open) { close(); return; }
    status.textContent = root.dataset.loading;
    const pending = new AbortController();
    controller = pending;
    try {
      const response = await fetch(root.dataset.url, { credentials: 'same-origin', cache: 'no-store', signal: pending.signal });
      if (!response.ok || !['application/pdf', 'image/jpeg'].includes(response.headers.get('Content-Type'))) throw new Error('unavailable');
      const blob = await response.blob();
      if (controller !== pending) return;
      objectUrl = URL.createObjectURL(blob);
      const view = document.createElement(blob.type === 'application/pdf' ? 'iframe' : 'img');
      view.src = objectUrl;
      view.title = root.dataset.show;
      if (view.tagName === 'IMG') view.alt = root.dataset.show;
      view.style.width = '100%';
      view.style.border = '0';
      if (view.tagName === 'IFRAME') view.style.height = '70vh';
      content.replaceChildren(view);
      status.textContent = '';
    } catch (error) {
      if (controller === pending && error.name !== 'AbortError') status.textContent = root.dataset.error;
    }
  });
  receiptPreviewClosers.set(root,close);
});
}
initializeReceiptPreviews();
window.addEventListener('receipt-history-updated',initializeReceiptPreviews);
window.addEventListener('pagehide',()=>{for(const close of receiptPreviewClosers.values())close();});

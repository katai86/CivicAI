(() => {
  const root = document.body;
  if (!root) return;
  const I18N = (typeof window.REPORT_PAGE_I18N === 'object' && window.REPORT_PAGE_I18N) ? window.REPORT_PAGE_I18N : {};
  const rid = Number(root.dataset.reportId || 0);
  const apiList = root.dataset.apiAttachments || '';
  const apiDelete = root.dataset.apiDelete || '';
  const apiUpload = root.dataset.apiUpload || '';
  if (!rid || !apiList || !apiDelete || !apiUpload) return;

  const gallery = document.getElementById('gallery');
  const file = document.getElementById('file');
  const btn = document.getElementById('uploadBtn');
  const msg = document.getElementById('upMsg');
  if (!gallery || !file || !btn || !msg) return;

  const Api = window.CivicApi;

  function esc(s){
    return String(s || '').replace(/[&<>"']/g, c => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
    }[c]));
  }

  function errText(j, fallback) {
    if (Api && typeof Api.parseErrorMessage === 'function') {
      return Api.parseErrorMessage(j, fallback || '');
    }
    if (j && typeof j.error === 'string') return j.error;
    return fallback || '';
  }

  async function fetchJson(url, opts) {
    if (Api && typeof Api.fetchJson === 'function') {
      return Api.fetchJson(url, opts || { credentials: 'same-origin' });
    }
    const r = await fetch(url, Object.assign({ credentials: 'same-origin' }, opts || {}));
    const j = await r.json().catch(() => null);
    if (!r.ok) throw new Error(errText(j, String(r.status)));
    return j;
  }

  async function load(){
    gallery.innerHTML = '';
    try{
      const j = await fetchJson(`${apiList}?id=${encodeURIComponent(rid)}`);
      if(!j.ok || !j.data || !j.data.length){
        gallery.innerHTML = '<div class="small">' + esc(I18N.no_attachments || '') + '</div>';
        return;
      }
      gallery.innerHTML = j.data.map(a => `
        <div class="thumb">
          <a href="${esc(a.url)}" target="_blank" rel="noopener" class="link">
            <img src="${esc(a.url)}" alt="">
          </a>
          <div class="cap">
            ${esc(a.filename)}<br><span class="small">${esc(a.created_at)}</span>
            <div class="actions">
              <button class="btn" data-del="${a.id}" type="button">${esc(I18N.delete || '')}</button>
            </div>
          </div>
        </div>
      `).join('');

      gallery.querySelectorAll('button[data-del]').forEach((delBtn) => {
        delBtn.addEventListener('click', async () => {
          const id = Number(delBtn.getAttribute('data-del'));
          if (!id) return;
          if (!confirm(I18N.delete_confirm || '')) return;
          delBtn.disabled = true;

          try{
            const j2 = await fetchJson(apiDelete, {
              method:'POST',
              headers:{ 'Content-Type':'application/json' },
              body: JSON.stringify({ id })
            });
            if(!j2 || !j2.ok){
              alert(errText(j2, I18N.delete_error || ''));
            }else{
              await load();
            }
          }catch(e){
            alert(I18N.delete_error || '');
          }finally{
            delBtn.disabled = false;
          }
        });
      });
    }catch(e){
      gallery.innerHTML = '<div class="small">' + esc(I18N.load_error || '') + '</div>';
    }
  }

  btn.addEventListener('click', async () => {
    msg.textContent = '';
    if(!file.files || !file.files[0]){ msg.textContent = I18N.pick_file || ''; return; }
    btn.disabled = true;
    msg.textContent = I18N.uploading || '';

    const fd = new FormData();
    fd.append('report_id', String(rid));
    fd.append('file', file.files[0]);

    try{
      const j = await fetchJson(apiUpload, {method:'POST', body:fd});
      if(!j || !j.ok){
        msg.textContent = errText(j, I18N.upload_error || '');
      }else{
        msg.textContent = I18N.upload_ok || '';
        file.value = '';
        await load();
      }
    }catch(e){
      msg.textContent = I18N.upload_error || '';
    }finally{
      btn.disabled = false;
      setTimeout(()=>{ msg.textContent=''; }, 2500);
    }
  });

  load();
})();

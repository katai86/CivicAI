(() => {
  const root = document.body;
  if (!root) return;
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

  function esc(s){
    return String(s || '').replace(/[&<>"']/g, c => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
    }[c]));
  }

  async function load(){
    gallery.innerHTML = '';
    try{
      const r = await fetch(`${apiList}?id=${encodeURIComponent(rid)}`, {credentials:'same-origin'});
      const j = await r.json();
      if(!j.ok || !j.data || !j.data.length){
        gallery.innerHTML = '<div class="small">Nincs csatolmány.</div>';
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
              <button class="btn" data-del="${a.id}" type="button">Törlés</button>
            </div>
          </div>
        </div>
      `).join('');

      gallery.querySelectorAll('button[data-del]').forEach((delBtn) => {
        delBtn.addEventListener('click', async () => {
          const id = Number(delBtn.getAttribute('data-del'));
          if (!id) return;
          if (!confirm('Biztos törlöd a képet?')) return;
          delBtn.disabled = true;

          try{
            const r = await fetch(apiDelete, {
              method:'POST',
              headers:{ 'Content-Type':'application/json' },
              credentials:'same-origin',
              body: JSON.stringify({ id })
            });
            const j2 = await r.json().catch(() => null);
            if(!j2 || !j2.ok){
              alert((j2 && j2.error) ? j2.error : 'Törlési hiba.');
            }else{
              await load();
            }
          }catch(e){
            alert('Törlési hiba.');
          }finally{
            delBtn.disabled = false;
          }
        });
      });
    }catch(e){
      gallery.innerHTML = '<div class="small">Hiba a csatolmányok betöltésekor.</div>';
    }
  }

  btn.addEventListener('click', async () => {
    msg.textContent = '';
    if(!file.files || !file.files[0]){ msg.textContent = 'Válassz ki egy képet!'; return; }
    btn.disabled = true;
    msg.textContent = 'Feltöltés...';

    const fd = new FormData();
    fd.append('report_id', String(rid));
    fd.append('file', file.files[0]);

    try{
      const r = await fetch(apiUpload, {method:'POST', body:fd, credentials:'same-origin'});
      const j = await r.json().catch(() => null);
      if(!j || !j.ok){
        msg.textContent = (j && j.error) ? j.error : 'Feltöltési hiba.';
      }else{
        msg.textContent = 'Sikeres feltöltés.';
        file.value = '';
        await load();
      }
    }catch(e){
      msg.textContent = 'Feltöltési hiba.';
    }finally{
      btn.disabled = false;
      setTimeout(()=>{ msg.textContent=''; }, 2500);
    }
  });

  load();
})();

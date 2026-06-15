(function(){
  const $ = sel => document.querySelector(sel);
  const tokenInput = $('#token');
  const loadBtn = $('#load');
  const status = $('#status');
  const tbody = $('#events tbody');

  function setStatus(msg){ status.textContent = msg; }

  async function loadEvents(){
    const token = tokenInput.value.trim();
    if (!token) { setStatus('Admin token required'); return; }
    setStatus('Loading...');
    try {
      const resp = await fetch('/api/v1/admin/events', { headers: { 'X-Admin-Token': token } });
      if (!resp.ok) { setStatus('Error: ' + resp.status); return; }
      const data = await resp.json();
      render(data.events || {});
      setStatus('Loaded ' + Object.keys(data.events || {}).length + ' events');
    } catch (e) {
      setStatus('Fetch error: ' + e.message);
    }
  }

  function render(events){
    tbody.innerHTML = '';
    const keys = Object.keys(events).sort((a,b)=> (events[b].created_at||'') < (events[a].created_at||'') ? 1 : -1);
    keys.forEach(k => {
      const ev = events[k];
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td><code>${escapeHtml(k)}</code></td>
        <td>${escapeHtml(ev.provider||'')}</td>
        <td>${escapeHtml(ev.reference||'')}</td>
        <td>${escapeHtml(ev.status||'')}</td>
        <td>${escapeHtml(String(ev.attempts||0))}</td>
        <td>${escapeHtml(ev.created_at||'')}</td>
        <td>${escapeHtml(ev.last_attempt_at||'')}</td>
        <td>${escapeHtml(ev.next_retry_at||'')}</td>
        <td><button data-key="${encodeURIComponent(k)}" class="retry">Retry</button></td>
      `;
      tbody.appendChild(tr);
    });
    document.querySelectorAll('.retry').forEach(btn => btn.addEventListener('click', onRetry));
  }

  async function onRetry(e){
    const key = decodeURIComponent(e.target.getAttribute('data-key'));
    const token = tokenInput.value.trim();
    if (!token) { setStatus('Admin token required'); return; }
    setStatus('Retrying ' + key + '...');
    try {
      const resp = await fetch(`/api/v1/admin/events/${encodeURIComponent(key)}/retry`, { method: 'POST', headers: { 'X-Admin-Token': token } });
      const data = await resp.json();
      if (!resp.ok) { setStatus('Retry failed: ' + JSON.stringify(data)); return; }
      setStatus('Retry queued/processed');
      loadEvents();
    } catch (e) {
      setStatus('Retry error: ' + e.message);
    }
  }

  function escapeHtml(s){ return String(s).replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]); }

  loadBtn.addEventListener('click', loadEvents);
})();

<?php include __DIR__ . '/../layout_header.php'; $u=require_role('admin'); ?>
<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
    <h2 style="margin:0">لوحة حيّة — عدة عمال</h2>
    <div style="display:flex;align-items:center;gap:8px">
      <label>ترتيب بحسب
        <select id="sortBy">
          <option value="state">الحالة</option>
          <option value="last_seen">آخر ظهور</option>
          <option value="id">المعرف</option>
        </select>
      </label>
      <label><input type="checkbox" id="onlyOnline"> المتصلون الآن</label>
    </div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
  <span class="badge" id="rq24" title="عمليات إعادة الاصطفاف خلال 24 ساعة" role="status" aria-live="polite" aria-atomic="true">Requeue 24h: —</span>
  <span class="badge" id="dup24" title="نسبة التكرارات خلال 24 ساعة" role="status" aria-live="polite" aria-atomic="true">Dup 24h: —</span>
    <span class="badge" id="stuckC" title="مهام قيد المعالجة ولم تتقدم منذ فترة" role="status" aria-live="polite" aria-atomic="true">Stuck: —</span>
    <span class="muted" id="ts" role="status" aria-live="polite" aria-atomic="true">—</span>
    </div>
  </div>
  <div id="root" class="grid"></div>
</div>
<style>
  .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px}
  .cardw{background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:12px}
  .head{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px}
  .wid{font-weight:700;color:var(--text)}
  .muted{color:var(--muted)}
  .dot{display:inline-block;width:10px;height:10px;border-radius:50%;vertical-align:middle;margin-inline-start:6px}
  .ok{background:#10b981}.bad{background:#ef4444}.warn{background:#f59e0b}.idle{background:#3b82f6}
  .kv{display:grid;grid-template-columns:auto 1fr;gap:4px 8px}
  .k{color:var(--muted)}
  .v{font-weight:700;color:var(--text)}
  .blink{animation:bl 1s linear infinite}
  @keyframes bl{0%{opacity:1}50%{opacity:.25}100%{opacity:1}}
  pre{white-space:pre-wrap;word-break:break-word;max-height:120px;overflow:auto;background:var(--panel-2);padding:8px;border-radius:8px;border:1px solid var(--border);color:var(--text)}
</style>
<script nonce="<?php echo htmlspecialchars(csp_nonce()); ?>">
(function(){
  // Friendly names map from settings (server-rendered)
  const NAMES = (function(){ try{ return JSON.parse('<?php echo json_encode(json_decode((string)get_setting('worker_name_overrides_json','{}'), true) ?: new stdClass(), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>'); }catch(_){ return {}; } })();
  const root = document.getElementById('root');
  const MANAGE_BASE = '<?php echo linkTo('admin/worker_manage.php'); ?>';
  const ts = document.getElementById('ts');
  const rq24 = document.getElementById('rq24');
  const stuckC = document.getElementById('stuckC');
  const dup24 = document.getElementById('dup24');
  const sortBy = document.getElementById('sortBy');
  const onlyOnline = document.getElementById('onlyOnline');
  const cards = new Map();
  function cardEl(id){
    if(cards.has(id)) return cards.get(id);
    const el = document.createElement('div'); el.className = 'cardw';
    el.innerHTML = `<div class=head><div class=wid>${id} <div class=muted id=fname style="font-size:12px"></div> <span class=dot></span></div><div style="display:flex;gap:8px;align-items:center"><a class="btn xs" target="_blank" rel="noopener" href="${MANAGE_BASE}?id=${encodeURIComponent(id)}">إدارة</a><div class=muted id=ls>—</div></div></div>
      <div class=kv>
        <div class=k>الحالة</div><div class=v id=st>—</div>
        <div class=k>المهمة</div><div class=v id=job>—</div>
        <div class=k>آخر تقرير</div><div class=v id=lr>—</div>
      </div>`;
    root.appendChild(el); cards.set(id, el); return el;
  }
  function fmtLR(lr){ if(!lr) return '—'; try{ const t = new Date(lr.t||Date.now()); return t.toLocaleTimeString('ar') + ' • +' + (lr.added||0) + ' • ' + (lr.cursor||0); }catch(_){ return '—'; } }
  let lastData = null;
  function render(list){
    // derive computed fields
    const cutMs = Date.now() - (<?php echo intval(workers_online_window_sec()); ?> * 1000);
    const items = list.map(w=>{
      const info = w.info ? (typeof w.info==='string'? JSON.parse(w.info): w.info) : {};
      const m = info.metrics||{};
      // Parse as local time (SQLite timestamps are server-local), avoid forcing UTC
      const last = w.last_seen ? Date.parse(w.last_seen.replace(' ', 'T')) : 0;
      const isOnline = last >= cutMs;
      const paused = !!m.paused, active = !!m.active;
      const state = isOnline ? (paused? 'paused' : (active? 'active' : 'idle')) : 'offline';
      return { id:w.worker_id, last_seen:w.last_seen||'', lastMs:last, state, m };
    }).filter(x=> !onlyOnline.checked || x.state!=='offline');

    // sort
    const by = sortBy.value||'state';
    items.sort((a,b)=>{
      if(by==='last_seen'){ return (b.lastMs||0) - (a.lastMs||0); }
      if(by==='id'){ return String(a.id).localeCompare(String(b.id), 'ar'); }
      // state order: active, paused, idle, offline
      const rank = s=> s==='active'?0 : s==='paused'?1 : s==='idle'?2 : 3;
      const ra = rank(a.state), rb = rank(b.state);
      return ra===rb ? String(a.id).localeCompare(String(b.id), 'ar') : ra - rb;
    });

    // render cards
    root.innerHTML = '';
    items.forEach(x=>{
  const el = cardEl(x.id);
      // Friendly name if available
      try{ const n = NAMES && NAMES[x.id]; const fn = el.querySelector('#fname'); if(fn){ fn.textContent = n? String(n) : ''; } }catch(_){ }
      el.querySelector('#ls').textContent = x.last_seen||'—';
      const dot = el.querySelector('.dot');
      dot.className = 'dot ' + (x.state==='active'? 'ok' : x.state==='paused'? 'warn' : x.state==='idle'? 'idle' : 'bad');
      el.querySelector('#st').textContent = x.state==='paused'? 'موقوف مؤقتًا' : x.state==='active'? 'ينفّذ' : x.state==='idle'? 'متصل' : 'غير متصل';
  if(x.m.lastJob){ const lj = x.m.lastJob; el.querySelector('#job').textContent = '#' + (lj.id||'?') + ' — ' + (lj.query||'') + ' @ ' + (lj.ll||''); }
      else el.querySelector('#job').textContent = '—';
      // Tooltip: attempt/lease if active_job present from SSE payload
      try{
        const info = (x.m ? x.m : {});
        const aj = (x.active_job || null);
        const jobEl = el.querySelector('#job');
        if(aj && (aj.attempt_id || aj.lease_expires_at)){
          jobEl.title = `attempt: ${aj.attempt_id||'-'}\nlease: ${aj.lease_expires_at||'-'}`;
          // blink if lease ends within 30s
          try{
            if(aj.lease_expires_at){
              const ms = Date.parse(aj.lease_expires_at.replace(' ','T')) - Date.now();
              if(ms>0 && ms <= 30000) jobEl.classList.add('blink'); else jobEl.classList.remove('blink');
            } else jobEl.classList.remove('blink');
          }catch(_){ jobEl.classList.remove('blink'); }
        } else { jobEl.removeAttribute('title'); }
      }catch(_){ }
      el.querySelector('#lr').textContent = fmtLR(x.m.lastReport);
      root.appendChild(el);
    });
  }
  function apply(j){
    ts.textContent = 'آخر تحديث: ' + (j.now||'');
    const jobs = j.stats && j.stats.jobs ? j.stats.jobs : {};
    if(rq24) rq24.textContent = 'Requeue 24h: ' + (jobs.requeue24h!=null ? jobs.requeue24h : '—');
  const ing = j.stats && j.stats.ingest ? j.stats.ingest : null;
  if(dup24){ dup24.textContent = 'Dup 24h: ' + (ing? (ing.dup_ratio + '%') : '—'); dup24.title = ing? ('added='+ing.added+', dup='+ing.duplicates) : dup24.title; }
    const stuckArr = (j.stats && Array.isArray(j.stats.jobs_stuck)) ? j.stats.jobs_stuck : [];
    if(stuckC) stuckC.textContent = 'Stuck: ' + stuckArr.length;
    const list = (j.stats && j.stats.workers && j.stats.workers.list) ? j.stats.workers.list : [];
    lastData = list; render(list);
  }
  function poll(){ fetch('<?php echo linkTo('api/monitor_stats.php'); ?>', { headers: { 'X-Requested-With':'fetch' } }).then(r=>r.json()).then(j=>{ if(j&&j.ok) apply(j); }).catch(()=>{}); }
  try{
    const es = new EventSource('<?php echo linkTo('api/monitor_events.php'); ?>');
    es.onmessage = (ev)=>{ try{ const j = JSON.parse(ev.data); if(j&&j.ok) apply(j); }catch(_){ } };
    es.onerror = ()=>{ es.close(); setInterval(poll, 5000); };
  }catch(_){ setInterval(poll, 5000); }
  poll();
  if(sortBy) sortBy.addEventListener('change', ()=>{ if(lastData) render(lastData); });
  if(onlyOnline) onlyOnline.addEventListener('change', ()=>{ if(lastData) render(lastData); });
})();
</script>
<?php include __DIR__ . '/../layout_footer.php'; ?>
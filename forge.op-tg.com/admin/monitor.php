<?php include __DIR__ . '/../layout_header.php'; $u=require_role('admin'); ?>
<div class="card">
  <h2 style="display:flex;align-items:center;gap:10px;justify-content:space-between">
    <span>مراقبة مباشرة</span>
    <span style="display:flex;gap:8px">
      <a class="btn sm" href="<?php echo linkTo('admin/monitor_workers.php'); ?>">لوحة عدة عمال</a>
      <a class="btn sm" href="<?php echo linkTo('admin/attempts.php'); ?>">محاولات حديثة</a>
    </span>
  </h2>
  <div id="mon-root">
    <div class="grid-3">
      <div class="card"><h3>النظام</h3><div id="mon-system"></div></div>
      <div class="card"><h3>الوظائف</h3><div id="mon-jobs"></div></div>
      <div class="card"><h3>الوحدات الطرفية</h3><div id="mon-workers"></div></div>
    </div>
    <div class="card" style="margin-top:12px">
      <h3>أكثر المدن وجودًا في الأرقام</h3>
  <table id="mon-topcities" data-wrap data-table-key="admin:monitor:top-cities" data-sticky-first="1"><thead><tr>
        <th data-default="1">المدينة</th>
        <th>المنطقة</th>
        <th data-default="1">العدد</th>
      </tr></thead><tbody></tbody></table>
    </div>
    <div class="card" style="margin-top:12px">
      <h3>آخر الوحدات الطرفية ظهورًا</h3>
  <table id="mon-workers-table" data-wrap data-table-key="admin:monitor:workers" data-sticky-first="1"><thead><tr>
        <th data-default="1">Worker ID</th>
        <th data-default="1">آخر ظهور</th>
        <th>معلومات</th>
      </tr></thead><tbody></tbody></table>
    </div>
    <div class="card" style="margin-top:12px">
      <h3>اتجاه جودة الإدراج (7 أيام)</h3>
  <table id="mon-ingest-trend" data-wrap data-table-key="admin:monitor:ingest-trend" data-sticky-first="1"><thead><tr>
        <th data-default="1">اليوم</th>
        <th data-default="1">Added</th>
        <th data-default="1">Duplicates</th>
        <th data-default="1">Dup%</th>
      </tr></thead><tbody></tbody></table>
    </div>
  </div>
  <div class="muted" id="mon-time" role="status" aria-live="polite" aria-atomic="true"></div>
</div>
<script nonce="<?php echo htmlspecialchars(csp_nonce()); ?>">
(function(){
  const $sys = document.getElementById('mon-system');
  const $jobs = document.getElementById('mon-jobs');
  const $workers = document.getElementById('mon-workers');
  const $time = document.getElementById('mon-time');
  const $tcBody = document.querySelector('#mon-topcities tbody');
  const $trendBody = document.querySelector('#mon-ingest-trend tbody');
    function render(j){
        const s = j.stats;
      $sys.innerHTML = `إيقاف شامل: <b>${s.system.stopped? 'نعم':'لا'}</b><br>فترة الإيقاف: <b>${s.system.pause_active? 'نشطة':'غير نشطة'}</b><br>ترتيب الالتقاط: <span class="badge">${s.system.pick_order}</span>`;
  const ing = s.ingest || {dup_ratio:0,added:0,duplicates:0};
  $jobs.innerHTML = `Queued: <b>${s.jobs.queued}</b><br>Processing: <b>${s.jobs.processing}</b><br>Expired: <b>${s.jobs.expired}</b><br>Done (24h): <b>${s.jobs.done24h}</b><br>Requeue (24h): <b>${s.jobs.requeue24h||0}</b><br>Dup ratio (24h): <span class="badge" title="added=${ing.added}, dup=${ing.duplicates}">${ing.dup_ratio}%</span>`;
      $workers.innerHTML = `متصل الآن (~2m): <b>${s.workers.online}</b> من إجمالي <b>${s.workers.total}</b>`;
      // Top cities
      $tcBody.innerHTML = '';
      (s.top_cities||[]).forEach(c => {
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${c.name_ar||'-'}</td><td>${c.region_code||'-'}</td><td class="kbd">${c.count||0}</td>`;
        $tcBody.appendChild(tr);
      });
      // Ingestion 7-day trend
      try{
        $trendBody.innerHTML = '';
        (s.ingest_trend||[]).forEach(x=>{
          const tr = document.createElement('tr');
          tr.innerHTML = `<td class="muted">${x.day}</td><td class="kbd">${x.added||0}</td><td class="kbd">${x.duplicates||0}</td><td><span class="badge">${(x.dup_ratio!=null?x.dup_ratio:0)}%</span></td>`;
          $trendBody.appendChild(tr);
        });
      }catch(_){ }
      const tbody = document.querySelector('#mon-workers-table tbody');
      tbody.innerHTML = '';
      (s.workers.list||[]).forEach(w=>{
        const tr = document.createElement('tr');
        const info = (function(){ try{ return w.info? JSON.stringify(JSON.parse(w.info), null, 2) : ''; }catch(e){ return w.info||''; }})();
        const safe = (s)=> (s||'').replace(/[&<>]/g, (ch)=>({"&":"&amp;","<":"&lt;","</":"&lt;/","< ":"&lt; ",">":"&gt;"}[ch]||ch));
        const enc = safe(info);
        const encAttr = safe(info).replace(/"/g,'&quot;');
        tr.innerHTML = `<td class="kbd">${w.worker_id||''}</td><td class="muted">${w.last_seen||''}</td>
          <td>
            <details class="collapsible">
              <summary>عرض التفاصيل</summary>
              <div class="collapsible-body">
                <div class="row" style="justify-content:flex-end">
                  <button type="button" class="btn xs outline" data-copy data-copy-text="${encAttr}">نسخ</button>
                </div>
                <pre class="code-block">${enc}</pre>
              </div>
            </details>
          </td>`;
        tbody.appendChild(tr);
      });
      // Stuck alerts
      try{
        const stuck = j.stats.jobs_stuck||[];
        const thr = j.stats.stuck_threshold_min;
        const boxId = 'stuck-box';
        let box = document.getElementById(boxId);
        if(!box){ box = document.createElement('div'); box.id = boxId; box.className = 'card'; document.getElementById('mon-root').appendChild(box); }
        if(stuck.length){
          const lis = stuck.map(x=>`<li>Job <a href="<?php echo linkTo('api/debug_job.php'); ?>?id=${x.id}" target="_blank" rel="noopener" class="kbd">#${x.id}</a> — عامل: ${x.worker_id||'-'} — آخر تقدم: ${x.last_progress_at||'-'} — Lease: ${x.lease_expires_at||'-'}</li>`).join('');
          box.innerHTML = `<h3>تحذير: مهام بلا تقدم منذ ≥ ${thr} دقيقة</h3><ul>${lis}</ul>
          <div style="margin-top:8px"><a class="btn" href="<?php echo linkTo('admin/stuck_jobs.php'); ?>">إدارة المهام العالقة</a></div>`;
        } else {
          box.innerHTML = '';
        }
      }catch(_){ }
        $time.textContent = 'آخر تحديث: ' + (j.now||'');
    }
    function poll(){
      fetch('<?php echo linkTo('api/monitor_stats.php'); ?>', { headers: { 'X-Requested-With':'fetch' } })
        .then(r=>r.json()).then(j=>{ if(j&&j.ok) render(j); })
        .catch(e=>{ $time.textContent = 'تعذَّر تحديث اللوحة: '+e; });
    }
    // Try SSE first, fallback to polling
    (function init(){
      try{
        const es = new EventSource('<?php echo linkTo('api/monitor_events.php'); ?>');
        es.onmessage = (ev)=>{ try{ const j = JSON.parse(ev.data); render(j); }catch(e){} };
        es.onerror = ()=>{ es.close(); setInterval(poll, 5000); };
      }catch(e){ setInterval(poll, 5000); }
      poll();
    })();
})();
</script>
<?php include __DIR__ . '/../layout_footer.php'; ?>

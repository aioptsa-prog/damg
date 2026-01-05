<?php include __DIR__ . '/../layout_header.php'; $u=require_role('admin'); $wid = isset($_GET['id'])? trim((string)$_GET['id']) : ''; ?>
<div class="card">
  <h2>العامل — بث مباشر</h2>
  <form method="get" style="margin-bottom:8px">
    <label>Worker ID</label>
    <input name="id" value="<?php echo htmlspecialchars($wid); ?>" placeholder="wrk-xxxx">
    <button class="btn">فتح</button>
  </form>
  <?php if($wid===''): ?>
    <div class="muted">أدخل معرف العامل أعلاه لعرض حالته وسجلاته في الزمن الحقيقي.</div>
  <?php else: ?>
    <div class="grid-2">
      <div class="card">
        <h3>الحالة</h3>
        <div class="k">المعرّف</div><div class="v" id="wid">—</div>
        <div class="k">آخر ظهور</div><div class="v" id="lastSeen">—</div>
        <div class="k">الاتصال</div><div class="v" id="conn">—</div>
        <div class="k">المهمة الحالية</div><div class="v" id="job">—</div>
    <div class="k">Attempt</div><div class="v" id="attempt">—</div>
  <div class="k">انتهاء التأجير</div><div class="v" id="lease">— <span id="leaseBadge" class="badge" style="display:none"></span></div>
  <div class="k">آخر تقرير</div><div class="v" id="lastReport">— <span id="lrBadge" class="badge" style="display:none"></span></div>
  <div class="k">إجراءات</div>
  <div class="v" id="acts"><button class="btn sm" id="btn_rq" disabled title="تظهر عند وجود مهمة نشطة">Requeue</button> <button class="btn sm danger" id="btn_cancel" disabled title="تظهر عند وجود مهمة نشطة">Cancel</button> <a class="btn sm" id="btn_dbg" target="_blank" rel="noopener" href="#" style="display:none">Debug</a></div>
  <div class="k"></div><div class="v muted">ملاحظة: تُفعّل الأزرار فقط عند وجود مهمة نشطة للعامل (processing). استخدم لوحة الصحة لإعادة صف المهام المنتهية الحجز.</div>
        <div class="k">البيانات الخام</div>
        <div class="v">
          <details class="collapsible">
            <summary>عرض البيانات</summary>
            <div class="collapsible-body">
              <div class="row" style="justify-content:flex-end"><button type="button" class="btn xs outline" id="copyMeta">نسخ</button></div>
              <pre class="code-block" id="meta" style="max-height:260px"></pre>
            </div>
          </details>
        </div>
      </div>
      <div class="card">
        <h3>السجلات</h3>
        <details class="collapsible">
          <summary>عرض السجلات</summary>
          <div class="collapsible-body">
            <div class="row" style="justify-content:flex-end"><button type="button" class="btn xs outline" id="copyLogs">نسخ</button></div>
            <pre class="code-block" id="logs" style="max-height:360px"></pre>
          </div>
        </details>
      </div>
    </div>
  <div class="muted" id="ts" role="status" aria-live="polite" aria-atomic="true"></div>
  <script nonce="<?php echo htmlspecialchars(csp_nonce()); ?>">
  const meta = document.getElementById('meta');
  const logs = document.getElementById('logs');
  const copyMeta = document.getElementById('copyMeta');
  const copyLogs = document.getElementById('copyLogs');
      const ts = document.getElementById('ts');
      const wid = document.getElementById('wid');
      const lastSeen = document.getElementById('lastSeen');
      const conn = document.getElementById('conn');
      const job = document.getElementById('job');
      const lastReport = document.getElementById('lastReport');
      const csrf = '<?php echo htmlspecialchars(csrf_token()); ?>';
      let currentJobId = null;
      let leaseTimer = null; let leaseEndMs = null;
      let lrTimer = null; let lastReportMs = null; let lastSeenMs = null; let flags = {connected:false, active:false, paused:false};
      const reportEveryMs = parseInt('<?php echo (int)get_setting('worker_report_every_ms','15000'); ?>') || 15000;
      const reportFirstMs = parseInt('<?php echo (int)get_setting('worker_report_first_ms','2000'); ?>') || 2000;
      const warnMs = Math.max(60000, Math.round(reportEveryMs * 1.8));
      const critMs = Math.max(90000, Math.round(reportEveryMs * 3.5));
      function updateLrBadge(){
        const b = document.getElementById('lrBadge');
        // Base visibility logic
        if(flags.paused){ b.style.display='inline-block'; b.textContent='موقوف'; b.style.background='#475569'; return; }
        if(!flags.connected){ b.style.display='inline-block'; b.textContent='غير متصل'; b.style.background='#7f1d1d'; return; }
        const now = Date.now();
        if(lastReportMs && isFinite(lastReportMs)){
          const dt = now - lastReportMs;
          if(dt < warnMs){ b.style.display='inline-block'; b.textContent='OK'; b.style.background='#14532d'; }
          else if(dt < critMs){ b.style.display='inline-block'; b.textContent='متأخر'; b.style.background='#b45309'; }
          else { b.style.display='inline-block'; b.textContent='متوقف'; b.style.background='#7f1d1d'; }
          b.title = 'آخر تقرير منذ ~' + Math.floor(dt/1000) + ' ثانية';
          return;
        }
        // No last report yet while connected
        if(lastSeenMs && (now - lastSeenMs) < (reportFirstMs + reportEveryMs)*2){
          b.style.display='inline-block'; b.textContent='بانتظار أول تقرير'; b.style.background='#b45309';
        } else {
          b.style.display='inline-block'; b.textContent='متوقف'; b.style.background='#7f1d1d';
        }
      }
      function updateLeaseBadge(){
        const b = document.getElementById('leaseBadge');
        if(!leaseEndMs || !isFinite(leaseEndMs)){ b.style.display='none'; return; }
        const left = Math.floor((leaseEndMs - Date.now())/1000);
        if(left <= 0){ b.style.display='inline-block'; b.textContent='منتهي'; b.style.background='#7f1d1d'; }
        else if(left <= 30){ b.style.display='inline-block'; b.textContent='وشيك الانتهاء ('+left+'s)'; b.style.background='#b45309'; }
        else { b.style.display='inline-block'; b.textContent='جيد ('+left+'s)'; b.style.background='#14532d'; }
      }
      async function doAction(act){
        if(!currentJobId) return;
        try{
          const res = await fetch('<?php echo linkTo('api/job_action.php'); ?>', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({csrf, job_id: currentJobId, action: act}) });
          const r = await res.json(); if(!r.ok) throw new Error(r.error||'failed');
        }catch(e){ alert('فشل الإجراء: '+e.message); }
      }
      document.getElementById('btn_rq').addEventListener('click', ()=>{ if(currentJobId && confirm('تأكيد إعادة الاصطفاف؟')) doAction('force_requeue'); });
      document.getElementById('btn_cancel').addEventListener('click', ()=>{ if(currentJobId && confirm('تأكيد الإلغاء؟')) doAction('cancel'); });

      function apply(j){
        try{
          const w = j.worker||{}; const info = w.info||{}; const m = (info.metrics||{});
          wid.textContent = w.worker_id||'—';
          lastSeen.textContent = w.last_seen||'—';
          // capture lastSeen as ms
          try{ lastSeenMs = w.last_seen ? Date.parse((w.last_seen+'').replace(' ','T')) : null; }catch(_){ lastSeenMs = null; }
          const connected = !!m.connected;
          const active = !!m.active;
          const paused = !!m.paused;
          flags = {connected, active, paused};
          conn.textContent = paused? 'موقوف مؤقتًا' : (connected? (active? 'ينفّذ' : 'متصل') : 'غير متصل');
          if(m.lastJob && typeof m.lastJob==='object'){
            const lj = m.lastJob; job.textContent = '#' + (lj.id||'?') + ' — ' + (lj.query||'') + ' @ ' + (lj.ll||'');
          } else job.textContent = '—';
            // Attempt/lease from server side active_job snapshot
            const aj = w.active_job || null;
            document.getElementById('attempt').textContent = (aj && aj.attempt_id) ? aj.attempt_id : (m.lastJob && m.lastJob.attempt_id ? m.lastJob.attempt_id : '—');
            const leaseEl = document.getElementById('lease');
            leaseEl.textContent = (aj && aj.lease_expires_at) ? aj.lease_expires_at : '—';
          // Live countdown hint
          try{
            if(aj && aj.lease_expires_at){
              const end = Date.parse(aj.lease_expires_at.replace(' ','T'));
              const left = Math.floor((end - Date.now())/1000);
              if(isFinite(left)) leaseEl.title = 'ينتهي خلال ~' + Math.max(0,left) + ' ثانية';
                // Start/refresh badge updater
                leaseEndMs = isFinite(end) ? end : null;
                if(leaseTimer) { clearInterval(leaseTimer); leaseTimer=null; }
                updateLeaseBadge(); leaseTimer = setInterval(updateLeaseBadge, 1000);
            } else { leaseEl.removeAttribute('title'); }
          }catch(_){ leaseEl.removeAttribute('title'); }
          // Actions
          currentJobId = (aj && aj.id) ? parseInt(aj.id) : (m.lastJob && m.lastJob.id ? parseInt(m.lastJob.id) : null);
          const en = !!currentJobId;
          document.getElementById('btn_rq').disabled = !en;
          document.getElementById('btn_cancel').disabled = !en;
          const dbg = document.getElementById('btn_dbg');
          if(en){ dbg.style.display='inline-block'; dbg.href = '<?php echo linkTo('api/debug_job.php'); ?>?id=' + encodeURIComponent(currentJobId); } else { dbg.style.display='none'; }
          if(m.lastReport && typeof m.lastReport==='object'){
            const lr = m.lastReport; const tms = (typeof lr.t==='number')? lr.t : Date.now();
            lastReport.textContent = (new Date(tms)).toLocaleTimeString('ar') + ' • +' + (lr.added||0) + ' • ' + (lr.cursor||0);
            lastReportMs = (typeof lr.t==='number')? lr.t : null;
          } else { lastReport.textContent = '—'; lastReportMs = null; }
          // Start/refresh LR badge updater
          if(lrTimer){ clearInterval(lrTimer); lrTimer=null; }
          updateLrBadge(); lrTimer = setInterval(updateLrBadge, 1000);
          meta.textContent = JSON.stringify(w, null, 2);
          logs.textContent = (j.log_tail||'');
          logs.scrollTop = logs.scrollHeight;
          ts.textContent = 'آخر تحديث: ' + (j.now||'');
        }catch(_){ }
      }
      (function(){
        const url = '<?php echo linkTo('api/worker_stream.php'); ?>?id=' + encodeURIComponent('<?php echo htmlspecialchars($wid, ENT_QUOTES); ?>');
        const urlPoll = '<?php echo linkTo('api/worker_status.php'); ?>?id=' + encodeURIComponent('<?php echo htmlspecialchars($wid, ENT_QUOTES); ?>');
        let fallbackTimer = null;
        function pollOnce(){ fetch(urlPoll, { headers: { 'X-Requested-With':'fetch' } }).then(r=>r.json()).then(j=>{ if(j&&j.ok) apply(j); }).catch(()=>{}); }
        try{
          const es = new EventSource(url);
          es.onmessage = (ev)=>{ try{ const j = JSON.parse(ev.data); apply(j); }catch(_){ } };
          es.onerror = ()=>{ ts.textContent = 'انقطع البث — سيُعاد المحاولة تلقائيًا'; if(!fallbackTimer){ fallbackTimer = setInterval(pollOnce, 5000); } };
        }catch(_){ ts.textContent = 'تعذر فتح بث SSE'; fallbackTimer = setInterval(pollOnce, 5000); }
  })();
  // Copies
  try{ if(copyMeta){ copyMeta.addEventListener('click', ()=>{ try{ navigator.clipboard.writeText(meta.textContent||''); showToast && showToast('تم النسخ','success'); }catch(e){} }); } }catch(_){ }
  try{ if(copyLogs){ copyLogs.addEventListener('click', ()=>{ try{ navigator.clipboard.writeText(logs.textContent||''); showToast && showToast('تم النسخ','success'); }catch(e){} }); } }catch(_){ }
    </script>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/../layout_footer.php'; ?>

<?php include __DIR__ . '/../layout_header.php'; $u=require_role('admin'); ?>
<div class="card">
  <h2>مهام عالقة</h2>
  <div class="muted">المهام قيد المعالجة التي لم تُحدِّث التقدم منذ مدة العتبة بينما العامل متصل. يمكن إعادة الاصطفاف أو الإلغاء بقوة.</div>
  <div style="margin:8px 0">
    <a class="btn" href="<?php echo linkTo('admin/monitor.php'); ?>">العودة إلى المراقبة</a>
  </div>
  <table id="tbl" data-dt="1" data-table-key="admin:stuck-jobs" data-sticky-first="1"><thead><tr>
    <th data-default="1">ID</th>
    <th data-default="1">العامل</th>
    <th>آخر تقدم</th>
    <th>Lease</th>
    <th data-required="1">إجراء</th>
  </tr></thead><tbody></tbody></table>
  <div class="muted" id="hint">—</div>
</div>
<script nonce="<?php echo htmlspecialchars(csp_nonce()); ?>">
(function(){
  const tbody = document.querySelector('#tbl tbody');
  const hint = document.getElementById('hint');
  async function load(){
    const r = await fetch('<?php echo linkTo('api/monitor_stats.php'); ?>', { headers:{'X-Requested-With':'fetch'} });
    const j = await r.json(); if(!j.ok) return;
    const stuck = j.stats.jobs_stuck||[]; const thr=j.stats.stuck_threshold_min;
    tbody.innerHTML = '';
    stuck.forEach(x=>{
      const tr = document.createElement('tr');
      tr.innerHTML = `<td class="kbd">#${x.id}</td><td>${x.worker_id||'-'}</td><td>${x.last_progress_at||'-'}</td><td>${x.lease_expires_at||'-'}</td><td>
        <button class="btn sm" data-act="rq" data-id="${x.id}">Requeue</button>
        <button class="btn sm danger" data-act="cancel" data-id="${x.id}">Cancel</button>
  <a class="btn sm" target="_blank" rel="noopener" href="<?php echo linkTo('api/debug_job.php'); ?>?id=${x.id}">Debug</a>
      </td>`;
      tbody.appendChild(tr);
    });
    hint.textContent = stuck.length? (`عدد المهام العالقة: ${stuck.length} — العتبة: ${thr} دقيقة`) : 'لا توجد مهام عالقة حالياً';
  }
  async function action(id, act){
    try{
      const res = await fetch('<?php echo linkTo('api/job_action.php'); ?>', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({csrf:'<?php echo htmlspecialchars(csrf_token()); ?>', job_id:id, action:act}) });
      const j = await res.json(); if(!j.ok) throw new Error(j.error||'fail');
      await load();
    }catch(e){ alert('فشل الإجراء: '+e.message); }
  }
  document.addEventListener('click', (e)=>{
    const b = e.target.closest('button[data-act]'); if(!b) return;
    const id = parseInt(b.getAttribute('data-id'))||0; const act=b.getAttribute('data-act');
    if(id && act){
      if(confirm('تأكيد الإجراء؟')) action(id, act);
    }
  });
  load(); setInterval(load, 5000);
})();
</script>
<?php include __DIR__ . '/../layout_footer.php'; ?>

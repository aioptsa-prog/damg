<?php include __DIR__ . '/../layout_header.php'; $u=require_role('admin'); $pdo=db();
$msg=null; $warn=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!csrf_verify($_POST['csrf'] ?? '')){ $warn='فشل CSRF'; $_POST=[]; }
  else if(($_POST['action'] ?? '')==='add_rule'){
    $cid=(int)($_POST['category_id'] ?? 0);
    $target=trim($_POST['target'] ?? 'name');
    $mode=trim($_POST['match_mode'] ?? 'contains');
    $pattern=trim($_POST['pattern'] ?? '');
    $weight=(float)($_POST['weight'] ?? 1.0);
    if($cid<=0 || $pattern===''){ $warn='اختر قسم وأدخل نمط المطابقة'; }
    else {
      $pdo->prepare("INSERT INTO category_rules(category_id,target,pattern,match_mode,weight,note,created_at) VALUES(?,?,?,?,?,?,datetime('now'))")
          ->execute([$cid,$target,$pattern,$mode,$weight,trim($_POST['note'] ?? '')]);
      $msg='تمت إضافة القاعدة';
    }
  } else if(($_POST['action'] ?? '')==='del_rule'){
    $id=(int)($_POST['id'] ?? 0);
    if($id>0){ $pdo->prepare("DELETE FROM category_rules WHERE id=?")->execute([$id]); $msg='تم حذف القاعدة'; }
  } else if(($_POST['action'] ?? '')==='toggle_rule'){
    $id=(int)($_POST['id'] ?? 0);
    if($id>0){ $pdo->exec("UPDATE category_rules SET enabled = CASE enabled WHEN 1 THEN 0 ELSE 1 END WHERE id=".$id); $msg='تم تغيير حالة القاعدة'; }
  } else if(($_POST['action'] ?? '')==='bulk_rules'){
    $ids = array_map('intval', $_POST['ids'] ?? []);
    $do = $_POST['do'] ?? '';
    if(!empty($ids)){
      $in = implode(',', array_fill(0, count($ids), '?'));
      if($do==='enable' || $do==='disable'){
        $en = $do==='enable' ? 1 : 0;
        $st = $pdo->prepare("UPDATE category_rules SET enabled=? WHERE id IN ($in)");
        $st->execute(array_merge([$en], $ids));
        $msg = 'تم تنفيذ الإجراء الجماعي (حالة) على '.count($ids).' قاعدة';
      } elseif($do==='delete'){
        $st = $pdo->prepare("DELETE FROM category_rules WHERE id IN ($in)");
        $st->execute($ids);
        $msg = 'تم حذف '.count($ids).' قاعدة';
      }
      // audit log
      try{ $logDir = __DIR__ . '/../storage/logs'; if(!is_dir($logDir)) @mkdir($logDir,0777,true); file_put_contents($logDir.'/audit.log', sprintf("[%s] classify_rules_bulk do=%s ids=%d by_user=%d\n", date('c'), $do, count($ids), (int)($u['id']??0)), FILE_APPEND); }catch(Throwable $e){}
    }
  }
}
$cats=$pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();
// Filters
$f_cat = isset($_GET['cat']) ? (int)$_GET['cat'] : 0;
$f_target = trim($_GET['target'] ?? '');
$f_mode = trim($_GET['mode'] ?? '');
$f_enabled = isset($_GET['enabled']) && $_GET['enabled'] !== '' ? (int)$_GET['enabled'] : null;
$where=[]; $params=[];
if($f_cat>0){ $where[]='r.category_id=:cid'; $params[':cid']=$f_cat; }
if($f_target!==''){ $where[]='r.target=:t'; $params[':t']=$f_target; }
if($f_mode!==''){ $where[]='r.match_mode=:m'; $params[':m']=$f_mode; }
if($f_enabled!==null){ $where[]='r.enabled=:en'; $params[':en']=$f_enabled; }
$sqlRules = "SELECT r.*, c.name cname FROM category_rules r JOIN categories c ON c.id=r.category_id".
            (count($where)?(' WHERE '.implode(' AND ',$where)):'').
            " ORDER BY c.name, r.id DESC";
$stR=$pdo->prepare($sqlRules); foreach($params as $k=>$v){ $stR->bindValue($k,$v); } $stR->execute(); $rules=$stR->fetchAll();
// Batch reclassification
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='reclassify_batch'){
  if(!csrf_verify($_POST['csrf'] ?? '')){ $warn='فشل CSRF'; }
  else {
    $limit=max(1,min(1000,(int)($_POST['limit'] ?? 200)));
    $onlyEmpty = isset($_POST['only_empty']);
    $where = $onlyEmpty ? 'WHERE category_id IS NULL' : '';
    $leads = $pdo->query("SELECT id, phone, name, city, country, website, email, gmap_types AS types, source_url FROM leads $where ORDER BY id DESC LIMIT ".$limit)->fetchAll();
    require_once __DIR__ . '/../lib/classify.php';
    $upd = $pdo->prepare("UPDATE leads SET category_id=:cid WHERE id=:id");
    $updated=0; $skipped=0;
    foreach($leads as $L){
      $cls = classify_lead([
        'name'=>$L['name'] ?? '',
        'gmap_types'=>$L['types'] ?? '',
        'website'=>$L['website'] ?? '',
        'email'=>$L['email'] ?? '',
        'source_url'=>$L['source_url'] ?? '',
        'city'=>$L['city'] ?? '',
        'country'=>$L['country'] ?? '',
        'phone'=>$L['phone'] ?? '',
      ]);
      $cid = $cls['category_id'] ?? null;
      if($cid){ $upd->execute([':cid'=>$cid, ':id'=>$L['id']]); $updated++; }
      else $skipped++;
    }
    $msg = "أُعيد تصنيف $updated من أصل ".count($leads)." (تجاوز $skipped)";
  }
}
?>
<div class="card">
  <h2>محرك التصنيف — قواعد متقدمة</h2>
  <?php if($warn): ?><p class="badge danger"><?php echo htmlspecialchars($warn); ?></p><?php endif; ?>
  <?php if($msg): ?><p class="badge"><?php echo htmlspecialchars($msg); ?></p><?php endif; ?>
  <form method="post" class="grid-4 card">
    <?php echo csrf_input(); ?>
    <input type="hidden" name="action" value="add_rule" />
    <div><label>القسم</label>
      <select name="category_id" required>
        <?php foreach($cats as $c): ?>
          <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><label>الحقل المستهدف</label>
      <select name="target">
        <?php foreach(['name','types','website','email','source_url','city','country','phone'] as $t): ?>
          <option value="<?php echo $t; ?>"><?php echo $t; ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><label>وضع المطابقة</label>
      <select name="match_mode">
        <option value="contains">contains</option>
        <option value="exact">exact</option>
        <option value="regex">regex</option>
      </select>
    </div>
    <div><label>الوزن</label><input type="number" step="0.1" name="weight" value="1.0"></div>
    <div style="grid-column:1/-1"><label>النمط</label><input name="pattern" placeholder="مثال: bakery|مخبز إذا regex"></div>
    <div style="grid-column:1/-1"><label>ملاحظة</label><input name="note" placeholder="وصف مختصر"></div>
    <div style="grid-column:1/-1"><button class="btn">إضافة قاعدة</button></div>
  </form>
  <div class="grid-1">
    <div class="card">
      <h3>القواعد</h3>
  <form method="get" class="grid-4" style="margin-bottom:8px" data-persist>
        <div><label>القسم</label>
          <select name="cat">
            <option value="0">الكل</option>
            <?php foreach($cats as $c): $sel=$f_cat===(int)$c['id']?'selected':''; ?>
              <option value="<?php echo (int)$c['id']; ?>" <?php echo $sel; ?>><?php echo htmlspecialchars($c['name']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div><label>الحقل</label>
          <select name="target">
            <option value="">الكل</option>
            <?php foreach(['name','types','website','email','source_url','city','country','phone'] as $t): $sel=$f_target===$t?'selected':''; ?>
              <option value="<?php echo $t; ?>" <?php echo $sel; ?>><?php echo $t; ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div><label>الوضع</label>
          <select name="mode">
            <option value="">الكل</option>
            <?php foreach(['contains','exact','regex'] as $m): $sel=$f_mode===$m?'selected':''; ?>
              <option value="<?php echo $m; ?>" <?php echo $sel; ?>><?php echo $m; ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div><label>الحالة</label>
          <select name="enabled">
            <option value="">الكل</option>
            <option value="1" <?php echo $f_enabled===1?'selected':''; ?>>مفعّل</option>
            <option value="0" <?php echo $f_enabled===0?'selected':''; ?>>معطّل</option>
          </select>
        </div>
        <div style="display:flex;align-items:end;gap:6px">
          <button class="btn">تصفية</button>
          <button class="btn outline" type="button" data-persist-reset title="مسح التفضيلات">مسح</button>
        </div>
      </form>
      <form id="bulk-form" method="post" style="margin-bottom:8px;">
        <?php echo csrf_input(); ?>
        <input type="hidden" name="action" value="bulk_rules" />
        <div style="display:flex; gap:8px; align-items:center; margin-bottom:8px">
          <label>إجراء جماعي</label>
          <select name="do">
            <option value="enable">تفعيل</option>
            <option value="disable">تعطيل</option>
            <option value="delete">حذف</option>
          </select>
          <button class="btn">تنفيذ على المحدد</button>
        </div>
  <table data-dt="1" data-table-key="admin:classification:rules" data-sticky-first="1">
        <thead><tr>
          <th data-required="1"><input type="checkbox" onclick="document.querySelectorAll('input[name=\'ids[]\']').forEach(cb=>cb.checked=this.checked)"></th>
          <th data-required="1">#</th>
          <th data-default="1">القسم</th>
          <th>الحقل</th>
          <th>الوضع</th>
          <th data-default="1">النمط</th>
          <th>الوزن</th>
          <th data-default="1">حالة</th>
          <th>ملاحظة</th>
          <th data-required="1"></th>
        </tr></thead>
        <tbody>
          <?php foreach($rules as $r): ?>
            <tr>
              <td><input type="checkbox" name="ids[]" value="<?php echo (int)$r['id']; ?>" onclick="event.stopPropagation()"></td>
              <td><?php echo (int)$r['id']; ?></td>
              <td><?php echo htmlspecialchars($r['cname']); ?></td>
              <td><?php echo htmlspecialchars($r['target']); ?></td>
              <td><?php echo htmlspecialchars($r['match_mode']); ?></td>
              <td><code><?php echo htmlspecialchars($r['pattern']); ?></code></td>
              <td><?php echo htmlspecialchars($r['weight']); ?></td>
              <td><span class="badge" style="background:<?php echo ((int)$r['enabled']===1)?'#0b3a1a':'#7f1d1d'; ?>"><?php echo ((int)$r['enabled']===1)?'enabled':'disabled'; ?></span></td>
              <td><?php echo htmlspecialchars($r['note'] ?? ''); ?></td>
              <td>
                <form method="post" style="display:inline-block" onsubmit="return confirm('تغيير حالة القاعدة؟');">
                  <?php echo csrf_input(); ?>
                  <input type="hidden" name="action" value="toggle_rule" />
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>" />
                  <button class="btn small"><?php echo ((int)$r['enabled']===1)?'تعطيل':'تفعيل'; ?></button>
                </form>
                <form method="post" onsubmit="return confirm('حذف القاعدة؟');">
                  <?php echo csrf_input(); ?>
                  <input type="hidden" name="action" value="del_rule" />
                  <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>" />
                  <button class="btn danger"><i class="fa fa-trash"></i></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </form>
      <form method="post" action="<?php echo linkTo('api/import_classification_full.php'); ?>" onsubmit="return confirm('سيتم استيراد الحزمة الشاملة المدمجة. متابعة؟');" class="mt-3">
        <?php echo csrf_input(); ?>
        <div>
          <label><input type="checkbox" name="replace"> وضع الاستبدال الكامل (حذف الكلمات والقواعد الحالية أولًا)</label>
        </div>
        <button class="btn blue"><i class="fa fa-cloud-download-alt"></i> استيراد الحزمة الشاملة (مُضمنة)</button>
      </form>
    </div>
    <div class="card" style="margin-top:16px">
      <h3>تجربة سريعة (Preview)</h3>
      <div class="grid-3">
        <div><label>الاسم</label><input id="pv-name" placeholder="مثال: مطعم الأصدقاء"></div>
        <div><label>Place Types</label><input id="pv-types" placeholder="restaurant,cafe,food"></div>
        <div><label>الموقع الإلكتروني</label><input id="pv-website" placeholder="https://..."></div>
        <div><label>البريد</label><input id="pv-email" placeholder="info@..."></div>
        <div><label>مصدر/رابط</label><input id="pv-src" placeholder="https://maps.google.com/..."></div>
        <div><label>الهاتف</label><input id="pv-phone" placeholder="9665..."></div>
        <div><label>المدينة</label><input id="pv-city" placeholder="الرياض"></div>
        <div><label>الدولة</label><input id="pv-country" placeholder="السعودية"></div>
        <div style="grid-column:1/-1">
          <button class="btn" id="pv-run"><i class="fa fa-magic"></i> جرّب</button>
          <span id="pv-status" class="muted"></span>
        </div>
        <div id="pv-result" style="grid-column:1/-1; margin-top:8px" class="muted"></div>
      </div>
  <script nonce="<?php echo htmlspecialchars(csp_nonce()); ?>">
      (function(){
        const btn = document.getElementById('pv-run');
        const status = document.getElementById('pv-status');
        const out = document.getElementById('pv-result');
        btn?.addEventListener('click', async function(){
          status.textContent = 'جارٍ...'; out.textContent='';
          const payload = {
            csrf: '<?php echo htmlspecialchars(csrf_token()); ?>',
            name: document.getElementById('pv-name').value,
            types: document.getElementById('pv-types').value,
            website: document.getElementById('pv-website').value,
            email: document.getElementById('pv-email').value,
            source_url: document.getElementById('pv-src').value,
            phone: document.getElementById('pv-phone').value,
            city: document.getElementById('pv-city').value,
            country: document.getElementById('pv-country').value
          };
          try{
            const res = await fetch('<?php echo linkTo('api/classify_preview.php'); ?>', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)});
            const j = await res.json();
            if(!j.ok) throw new Error('failed');
            status.textContent = `score=${j.score} (threshold=${j.threshold}) — category: ${j.category_name||'—'}`;
            let html='';
            if(Array.isArray(j.matched)){
              html += '<ul>';
              for(const m of j.matched){
                if(m.kind==='kw-name' || m.kind==='kw-types'){
                  html += `<li>${m.kind} — kw: <code>${(m.kw||'')}</code> — w=${m.w}</li>`;
                } else {
                  html += `<li>rule — target=<code>${(m.target||'')}</code>, mode=<code>${(m.mode||'')}</code>, w=${m.w}, pattern=<code>${(m.p||'')}</code></li>`;
                }
              }
              html += '</ul>';
            }
            out.innerHTML = html || '<span class="muted">لا توجد مطابقات.</span>';
          }catch(e){ status.textContent='فشل التجربة'; out.textContent=''; }
        });
      })();
      </script>
    </div>
    <div class="card" style="margin-top:16px">
      <h3>نسخ احتياطي واستيراد</h3>
      <div style="margin-bottom:8px">
  <a class="btn" href="<?php echo linkTo('api/export_classification.php?csrf='.urlencode(csrf_token())); ?>" target="_blank" rel="noopener"><i class="fa fa-download"></i> تصدير JSON</a>
      </div>
      <form method="post" action="<?php echo linkTo('api/import_classification.php'); ?>" onsubmit="return confirm('سيتم إدراج الأقسام والكلمات والقواعد في النظام. متابعة؟');">
        <?php echo csrf_input(); ?>
        <details id="import-json-collapse" class="collapsible">
          <summary>منطقة JSON (انقر للفتح) — <span class="muted">الصق أو حرر المحتوى ثم اضغط استيراد</span></summary>
          <div class="collapsible-body">
            <div><label>JSON</label>
              <textarea id="import-json" name="json" rows="8" placeholder="الصق JSON هنا" style="width:100%"></textarea>
            </div>
          </div>
        </details>
        <div>
          <button type="button" class="btn" onclick="loadStarter()"><i class="fa fa-file-import"></i> تحميل مثال جاهز</button>
          <button type="button" class="btn" onclick="loadFull()"><i class="fa fa-database"></i> تحميل الحزمة الشاملة</button>
          <button class="btn"><i class="fa fa-upload"></i> استيراد</button>
        </div>
        <div style="margin-top:8px">
          <label><input type="checkbox" name="replace"> وضع الاستبدال الكامل (سيتم حذف الكلمات والقواعد الحالية أولًا — مع الحفاظ على الأقسام)</label>
        </div>
      </form>
  <script nonce="<?php echo htmlspecialchars(csp_nonce()); ?>">
      async function loadStarter(){
        try{
          const res = await fetch('<?php echo linkTo('assets/classification_starter.json'); ?>');
          const txt = await res.text();
          const ta = document.getElementById('import-json');
          ta.value = txt;
          const det = document.getElementById('import-json-collapse'); if(det){ det.open = true; }
          ta.scrollIntoView({behavior:'smooth', block:'center'});
        }catch(e){ alert('تعذر تحميل الملف التجريبي'); }
      }
      async function loadFull(){
        try{
          const res = await fetch('<?php echo linkTo('assets/classification_full.json'); ?>');
          const txt = await res.text();
          const ta = document.getElementById('import-json');
          ta.value = txt;
          const det = document.getElementById('import-json-collapse'); if(det){ det.open = true; }
          ta.scrollIntoView({behavior:'smooth', block:'center'});
        }catch(e){ alert('تعذر تحميل الحزمة الشاملة'); }
      }
      </script>
    </div>
    <form method="post" class="card" style="margin-top:16px">
      <?php echo csrf_input(); ?>
      <input type="hidden" name="action" value="reclassify_batch" />
      <h3>إعادة تصنيف دفعية</h3>
      <div><label>الحد الأقصى للمعالجة</label><input type="number" name="limit" value="200" min="1" max="1000"></div>
      <div><label><input type="checkbox" name="only_empty" checked> فقط الذين بلا تصنيف</label></div>
  <div><label><input type="checkbox" name="override"> تجاوز التصنيف الموجود</label></div>
      <div><button class="btn">تشغيل الآن</button></div>
      <small class="muted">تشغّل في الطلب الحالي، للدفعات الكبيرة يُفضّل التكرار يدويًا أو استخدام Worker مخصص لاحقًا.</small>
    </form>
    <div class="card" style="margin-top:16px">
      <h3>تشغيل مستمر (AJAX)</h3>
      <div class="grid-3">
        <div><label>الحد لكل دفعة</label><input id="rc-limit" type="number" value="<?php echo htmlspecialchars(get_setting('reclassify_default_limit','200')); ?>" min="10" max="2000"></div>
        <div style="grid-column:1/-1"><label><input id="rc-only-empty" type="checkbox" <?php echo get_setting('reclassify_only_empty','1')==='1'?'checked':''; ?>> فقط الذين بلا تصنيف</label></div>
        <div style="grid-column:1/-1"><label><input id="rc-override" type="checkbox" <?php echo get_setting('reclassify_override','0')==='1'?'checked':''; ?>> تجاوز التصنيف الموجود</label></div>
        <div style="grid-column:1/-1">
          <button id="rc-start" class="btn primary"><i class="fa fa-play"></i> ابدأ</button>
          <button id="rc-stop" class="btn danger" disabled><i class="fa fa-stop"></i> إيقاف</button>
          <span id="rc-status" class="muted" style="margin-inline-start:10px"></span>
        </div>
      </div>
  <script nonce="<?php echo htmlspecialchars(csp_nonce()); ?>">
      (function(){
        const btnStart = document.getElementById('rc-start');
        const btnStop = document.getElementById('rc-stop');
        const elStatus = document.getElementById('rc-status');
        const elLimit = document.getElementById('rc-limit');
        const elOnly = document.getElementById('rc-only-empty');
        let running=false; let totalUpdated=0, totalProcessed=0;
        async function tick(){
          if(!running) return;
          try{
            elStatus.textContent = '...';
            const payload = {
              csrf: '<?php echo htmlspecialchars(csrf_token()); ?>',
              limit: parseInt(elLimit.value||'200'),
              only_empty: elOnly.checked,
              override: document.getElementById('rc-override').checked
            };
            const res = await fetch('<?php echo linkTo('api/reclassify.php'); ?>', {
              method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)
            });
            const j = await res.json();
            if(!j.ok){ throw new Error('Request failed'); }
            totalUpdated += j.updated || 0; totalProcessed += j.processed || 0;
            const remainStr = (j.remaining==null) ? '' : (' — المتبقي بلا تصنيف: '+j.remaining);
            elStatus.textContent = `تمت معالجة ${totalProcessed}, تحديث ${totalUpdated}${remainStr}`;
            // If nothing processed or remaining is zero, stop
            if((j.processed||0) === 0 || (j.remaining!==null && j.remaining<=0)){
              running=false; btnStart.disabled=false; btnStop.disabled=true;
              return;
            }
          }catch(err){
            elStatus.textContent = 'خطأ في الطلب';
            running=false; btnStart.disabled=false; btnStop.disabled=true;
            return;
          }
          // small delay to yield UI
          setTimeout(tick, 250);
        }
        btnStart?.addEventListener('click', function(){
          if(running) return; running=true; totalUpdated=0; totalProcessed=0;
          btnStart.disabled=true; btnStop.disabled=false; elStatus.textContent='جارٍ التشغيل...';
          tick();
        });
        btnStop?.addEventListener('click', function(){ running=false; btnStart.disabled=false; btnStop.disabled=true; });
      })();
      </script>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../layout_footer.php'; ?>

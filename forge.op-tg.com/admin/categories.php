<?php include __DIR__ . '/../layout_header.php'; $u=require_role('admin'); $pdo=db(); require_once __DIR__.'/../lib/categories.php';
$msg=null; $warn=null; $ok=null;

function slugify($s){ $s = trim(mb_strtolower($s)); $s = preg_replace('/[^\p{L}\p{N}]+/u','-', $s); $s = trim($s,'-'); if($s===''){ $s = 'cat-'.substr(sha1(uniqid('',true)),0,6); } return $s; }
function category_recalc_depth_path(PDO $pdo){
  try{
    $all = $pdo->query("SELECT id,parent_id,name FROM categories")->fetchAll(PDO::FETCH_ASSOC);
    $map=[]; foreach($all as $r){ $map[(int)$r['id']] = ['p'=>$r['parent_id']? (int)$r['parent_id'] : null, 'name'=>$r['name']]; }
    $depths=[]; $paths=[];
    $calc = function($id) use (&$calc,&$map,&$depths,&$paths){ if(isset($depths[$id])) return $depths[$id]; $v=$map[$id]??null; if(!$v){ $depths[$id]=0; $paths[$id]=null; return 0; } $p=$v['p']; if($p && $p===$id){ $depths[$id]=0; $paths[$id]=$v['name']; return 0; } $d=0; $names=[$v['name']]; $guard=0; while($p && isset($map[$p]) && $guard++<50){ $d++; array_unshift($names,$map[$p]['name']); $np=$map[$p]['p']; if($np===$p){ break; } $p=$np; }
      $depths[$id]=$d; $paths[$id]=implode(' / ', $names); return $d; };
    foreach(array_keys($map) as $id){ $calc($id); }
    $pdo->beginTransaction();
    $st = $pdo->prepare("UPDATE categories SET depth=:d, path=:p, updated_at=datetime('now') WHERE id=:id");
    foreach($depths as $id=>$d){ $st->execute([':d'=>$d, ':p'=>$paths[$id]??null, ':id'=>$id]); }
    $pdo->commit();
  }catch(Throwable $e){ try{$pdo->rollBack();}catch(Throwable $e2){} }
}

if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!csrf_verify($_POST['csrf'] ?? '')){ $warn='فشل CSRF'; $_POST=[]; }
  else {
    $act = $_POST['action'] ?? '';
    if($act==='add_cat'){
      $name=trim($_POST['name'] ?? ''); $parent=(int)($_POST['parent_id'] ?? 0) ?: null; $slug=trim($_POST['slug'] ?? ''); $active = isset($_POST['is_active']) ? 1 : 0;
      $icon_type = trim($_POST['icon_type'] ?? ''); $icon_value = trim($_POST['icon_value'] ?? '');
      if($name===''){ $warn='أدخل اسم القسم'; }
      else {
        if($slug===''){ $slug = slugify($name); }
        // ensure slug unique
        $ex = $pdo->prepare("SELECT 1 FROM categories WHERE slug=? LIMIT 1"); $ex->execute([$slug]); if($ex->fetch()){ $slug = $slug.'-'.substr(sha1(uniqid('',true)),0,4); }
        $pdo->prepare("INSERT INTO categories(parent_id,name,slug,is_active,created_at,updated_at) VALUES(?,?,?,?,datetime('now'),datetime('now'))")
            ->execute([$parent,$name,$slug,$active]);
        // Best-effort: set creator and icon fields if available
        try{
          $cid = (int)$pdo->lastInsertId();
          if($cid>0){
            try{ $pdo->prepare("UPDATE categories SET created_by_user_id=? WHERE id=?")->execute([ (int)$u['id'], $cid ]); }catch(Throwable $e){}
            if($icon_type!=='' || $icon_value!==''){
              try{ $pdo->prepare("UPDATE categories SET icon_type=:t, icon_value=:v WHERE id=:id")->execute([':t'=>$icon_type?:null, ':v'=>$icon_value?:null, ':id'=>$cid]); }catch(Throwable $e){}
            }
          }
        }catch(Throwable $e){}
        category_recalc_depth_path($pdo);
        $ok='تم إضافة القسم';
      }
    } else if($act==='edit_cat'){
      $id=(int)($_POST['id'] ?? 0); $name=trim($_POST['name'] ?? ''); $parent=(int)($_POST['parent_id'] ?? 0) ?: null; $slug=trim($_POST['slug'] ?? ''); $active = isset($_POST['is_active']) ? 1 : 0; $icon_type = trim($_POST['icon_type'] ?? ''); $icon_value = trim($_POST['icon_value'] ?? '');
      if($id<=0){ $warn='معرّف غير صالح'; }
      else {
        // Prevent cycles: parent cannot be self or descendant
        if($parent && $parent===$id){ $warn='لا يمكن اختيار نفس التصنيف كأب'; }
        else {
          $desc = category_get_descendant_ids($id); if(in_array($parent, $desc, true)){ $warn='لا يمكن نقل التصنيف إلى أحد توابعه'; }
          else {
            if($slug===''){ $slug = slugify($name ?: ('c'.$id)); }
            $ex = $pdo->prepare("SELECT 1 FROM categories WHERE slug=? AND id<>? LIMIT 1"); $ex->execute([$slug,$id]); if($ex->fetch()){ $slug = $slug.'-'.substr(sha1(uniqid('',true)),0,4); }
            $pdo->prepare("UPDATE categories SET name=:n, parent_id=:p, slug=:s, is_active=:a, updated_at=datetime('now') WHERE id=:id")
                ->execute([':n'=>$name, ':p'=>$parent, ':s'=>$slug, ':a'=>$active, ':id'=>$id]);
            // Best-effort icon update
            try{ $pdo->prepare("UPDATE categories SET icon_type=:t, icon_value=:v, updated_at=datetime('now') WHERE id=:id")
                      ->execute([':t'=>$icon_type?:null, ':v'=>$icon_value?:null, ':id'=>$id]); }catch(Throwable $e){}
            category_recalc_depth_path($pdo);
            $ok='تم تحديث التصنيف';
          }
        }
      }
    } else if($act==='delete_cat'){
      $id=(int)($_POST['id'] ?? 0);
      if($id<=0){ $warn='معرّف غير صالح'; }
      else {
        // guard: deny delete if leads reference this category
        $c = (int)$pdo->query("SELECT COUNT(*) c FROM leads WHERE category_id=".$id)->fetch()['c'];
        if($c>0){ $warn='لا يمكن الحذف لوجود سجلات مرتبطة (Leads). انقلها أو اختر تصنيف آخر.'; }
        else {
          $pdo->prepare("DELETE FROM categories WHERE id=?")->execute([$id]);
          category_recalc_depth_path($pdo);
          $ok='تم حذف التصنيف';
        }
      }
    } else if($act==='toggle_active'){
      $id=(int)($_POST['id'] ?? 0); $a = isset($_POST['is_active']) ? 1 : 0;
      if($id>0){ $pdo->prepare("UPDATE categories SET is_active=:a, updated_at=datetime('now') WHERE id=:id")->execute([':a'=>$a, ':id'=>$id]); $ok='تم التحديث'; }
    } else if($act==='add_kw'){
      $cid=(int)($_POST['category_id'] ?? 0); $kw=trim($_POST['keyword'] ?? '');
      if($cid<=0||$kw===''){ $warn='اختر قسم وأدخل كلمة مفتاحية'; }
      else { $pdo->prepare("INSERT OR IGNORE INTO category_keywords(category_id,keyword,created_at) VALUES(?,?,datetime('now'))")->execute([$cid,$kw]); $ok='تمت إضافة الكلمة'; }
    } else if($act==='del_kw'){
      $id=(int)($_POST['id'] ?? 0); if($id>0){ $pdo->prepare("DELETE FROM category_keywords WHERE id=?")->execute([$id]); $ok='حُذفت الكلمة'; }
    } else if($act==='add_tpl'){
      $cid=(int)($_POST['category_id'] ?? 0); $tpl=trim($_POST['template'] ?? '');
      if($cid<=0||$tpl===''){ $warn='اختر قسم وأدخل قالب بحث'; }
      else { $pdo->prepare("INSERT OR IGNORE INTO category_query_templates(category_id,template,created_at) VALUES(?,?,datetime('now'))")->execute([$cid,$tpl]); $ok='تمت إضافة القالب'; }
    } else if($act==='del_tpl'){
      $id=(int)($_POST['id'] ?? 0); if($id>0){ $pdo->prepare("DELETE FROM category_query_templates WHERE id=?")->execute([$id]); $ok='حُذف القالب'; }
    } else if($act==='seed_defaults'){
      // Seed a small default taxonomy, idempotent
      $pdo->beginTransaction();
      try{
        $legacy = $pdo->prepare("INSERT OR IGNORE INTO categories(slug,name,is_active,created_at,updated_at) VALUES('legacy-uncategorized','غير مصنّف (Legacy)',1,datetime('now'),datetime('now'))"); $legacy->execute();
        $dent = $pdo->prepare("INSERT OR IGNORE INTO categories(slug,name,is_active,created_at,updated_at) VALUES('medical-dental','طبي / تجميل / عيادات أسنان',1,datetime('now'),datetime('now'))"); $dent->execute();
        // Link parent if not set
        $dentRow = $pdo->query("SELECT id FROM categories WHERE slug='medical-dental'")->fetch(PDO::FETCH_ASSOC); $dentId = (int)($dentRow['id']??0);
        // Add keywords
        foreach(['عيادة أسنان','مستشفى أسنان','مركز أسنان'] as $kw){ $pdo->prepare("INSERT OR IGNORE INTO category_keywords(category_id,keyword,created_at) VALUES(?,?,datetime('now'))")->execute([$dentId,$kw]); }
        $pdo->commit();
        category_recalc_depth_path($pdo);
        $ok='تمت التعبئة المبدئية';
      }catch(Throwable $e){ try{$pdo->rollBack();}catch(Throwable $e2){} $warn='فشل إعداد البيانات: '.$e->getMessage(); }
    }
  }
}

// Load data
$cats=$pdo->query("SELECT * FROM categories ORDER BY COALESCE(depth,0) ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
$kws=$pdo->query("SELECT k.*, c.name cname FROM category_keywords k JOIN categories c ON c.id=k.category_id ORDER BY c.name, k.keyword")->fetchAll();
$tpls=$pdo->query("SELECT t.*, c.name cname FROM category_query_templates t JOIN categories c ON c.id=t.category_id ORDER BY c.name, t.template")->fetchAll();
?>
<div class="card">
  <h2>التصنيفات والكلمات المفتاحية</h2>
  <?php if($warn): ?><p class="badge danger"><?php echo htmlspecialchars($warn); ?></p><?php endif; ?>
  <?php if($ok): ?><p class="badge success"><?php echo htmlspecialchars($ok); ?></p><?php endif; ?>
  <div class="grid-2">
    <form method="post" class="card" autocomplete="off">
      <?php echo csrf_input(); ?>
      <input type="hidden" name="action" value="add_cat" />
      <h3>قسم جديد</h3>
      <div><label>الاسم</label><input name="name" required></div>
      <div><label>Slug</label><input name="slug" placeholder="يُولّد تلقائيًا إذا تُرك فارغًا"></div>
      <div><label>قسم أب</label>
        <select name="parent_id">
          <option value="">—</option>
          <?php foreach($cats as $c): ?>
            <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="display:flex; gap:6px; align-items:center; flex-wrap:wrap">
        <label>أيقونة</label>
        <select name="icon_type" title="نوع الأيقونة">
          <option value="">— بدون —</option>
          <option value="fa">FontAwesome</option>
          <option value="img">صورة</option>
        </select>
        <input name="icon_value" placeholder="fa-folder-tree أو /uploads/icon.png" style="min-width:220px">
        <button type="button" class="btn" data-open-icon-picker>اختيار أيقونة</button>
      </div>
      <label><input type="checkbox" name="is_active" value="1" checked> مفعّل</label>
      <button class="btn primary">إضافة</button>
    </form>
    <form method="post" class="card" autocomplete="off">
      <?php echo csrf_input(); ?>
      <input type="hidden" name="action" value="add_kw" />
      <h3>كلمة مفتاحية</h3>
      <div><label>القسم</label>
        <select name="category_id" required>
          <?php foreach($cats as $c): ?>
            <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label>الكلمة</label><input name="keyword" required placeholder="مثال: مخبز, bakery"></div>
      <button class="btn">إضافة</button>
    </form>
  </div>
  <div class="grid-2">
    <form method="post" class="card" autocomplete="off">
      <?php echo csrf_input(); ?>
      <input type="hidden" name="action" value="add_tpl" />
      <h3>قالب بحث للتصنيف</h3>
      <div><label>القسم</label>
        <select name="category_id" required>
          <?php foreach($cats as $c): ?>
            <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label>القالب</label><input name="template" required placeholder="مثال: &quot;{kw}&quot; near Riyadh"></div>
      <button class="btn">إضافة قالب</button>
    </form>
    <form method="post" class="card">
      <?php echo csrf_input(); ?>
      <input type="hidden" name="action" value="seed_defaults" />
      <h3>تعبئة مبدئية</h3>
      <p class="muted">إنشاء &quot;غير مصنّف (Legacy)&quot; و&quot;طبي / تجميل / عيادات أسنان&quot; مع كلمات أساسية.</p>
      <button class="btn" onclick="return confirm('تنفيذ التعبئة المبدئية؟')">تشغيل</button>
    </form>
  </div>
  <h3>الكلمات</h3>
  <table data-dt="1" data-table-key="admin:categories:keywords" data-sticky-first="1">
    <thead><tr>
      <th data-default="1">القسم</th>
      <th data-default="1">الكلمة</th>
      <th>حذف</th>
      <th>تاريخ</th>
    </tr></thead>
    <tbody>
      <?php foreach($kws as $k): ?>
        <tr>
          <td><?php echo htmlspecialchars($k['cname']); ?></td>
          <td><?php echo htmlspecialchars($k['keyword']); ?></td>
          <td>
            <form method="post" style="display:inline">
              <?php echo csrf_input(); ?>
              <input type="hidden" name="action" value="del_kw">
              <input type="hidden" name="id" value="<?php echo (int)$k['id']; ?>">
              <button class="btn small" onclick="return confirm('حذف الكلمة؟')">حذف</button>
            </form>
          </td>
          <td class="muted"><?php echo htmlspecialchars($k['created_at']); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <h3>قوالب البحث</h3>
  <table data-dt="1" data-table-key="admin:categories:templates" data-sticky-first="1">
    <thead><tr>
      <th data-default="1">القسم</th>
      <th data-default="1">القالب</th>
      <th>حذف</th>
      <th>تاريخ</th>
    </tr></thead>
    <tbody>
      <?php foreach($tpls as $t): ?>
        <tr>
          <td><?php echo htmlspecialchars($t['cname']); ?></td>
          <td><?php echo htmlspecialchars($t['template']); ?></td>
          <td>
            <form method="post" style="display:inline">
              <?php echo csrf_input(); ?>
              <input type="hidden" name="action" value="del_tpl">
              <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
              <button class="btn small" onclick="return confirm('حذف القالب؟')">حذف</button>
            </form>
          </td>
          <td class="muted"><?php echo htmlspecialchars($t['created_at']); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <h3>كل التصنيفات</h3>
  <table data-dt="1" data-table-key="admin:categories:list" data-sticky-first="1">
    <thead><tr>
      <th>#</th>
      <th>الاسم</th>
      <th>Slug</th>
      <th>الأب</th>
      <th>العمق</th>
      <th>مفعّل</th>
      <th>إجراءات</th>
    </tr></thead>
    <tbody>
      <?php foreach($cats as $c): ?>
        <tr>
          <td class="kbd"><?php echo (int)$c['id']; ?></td>
          <td title="<?php echo htmlspecialchars($c['path'] ?? $c['name']); ?>"><?php echo htmlspecialchars($c['name']); ?></td>
          <td class="muted"><?php echo htmlspecialchars($c['slug']); ?></td>
          <td class="muted"><?php echo $c['parent_id']? (int)$c['parent_id'] : '—'; ?></td>
          <td class="muted"><?php echo (int)($c['depth'] ?? 0); ?></td>
          <td>
            <form method="post" style="display:inline">
              <?php echo csrf_input(); ?>
              <input type="hidden" name="action" value="toggle_active">
              <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>">
              <input type="hidden" name="is_active" value="<?php echo $c['is_active']?1:0; ?>">
              <span class="badge <?php echo $c['is_active']?'success':'muted'; ?>"><?php echo $c['is_active']?'مفعّل':'معطّل'; ?></span>
            </form>
          </td>
          <td>
            <details>
              <summary>تعديل</summary>
              <form method="post" class="mt-1" autocomplete="off">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="edit_cat">
                <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>">
                <div style="display:flex; gap:6px; flex-wrap:wrap">
                  <input name="name" value="<?php echo htmlspecialchars($c['name']); ?>" placeholder="الاسم">
                  <input name="slug" value="<?php echo htmlspecialchars($c['slug']); ?>" placeholder="Slug">
                  <select name="parent_id">
                    <option value="">—</option>
                    <?php foreach($cats as $c2): if((int)$c2['id']===(int)$c['id']) continue; ?>
                      <option value="<?php echo (int)$c2['id']; ?>" <?php echo ((int)($c['parent_id']??0)===(int)$c2['id'])?'selected':''; ?>><?php echo htmlspecialchars($c2['name']); ?></option>
                    <?php endforeach; ?>
                  </select>
                  <select name="icon_type" title="نوع الأيقونة">
                    <?php $it = (string)($c['icon_type']??''); ?>
                    <option value="" <?php echo $it===''?'selected':''; ?>>— بدون —</option>
                    <option value="fa" <?php echo $it==='fa'?'selected':''; ?>>FontAwesome</option>
                    <option value="img" <?php echo $it==='img'?'selected':''; ?>>صورة</option>
                  </select>
                  <input name="icon_value" value="<?php echo htmlspecialchars((string)($c['icon_value']??'')); ?>" placeholder="fa-folder-tree أو /uploads/icon.png" style="min-width:200px">
                  <button type="button" class="btn" data-open-icon-picker data-cat-id="<?php echo (int)$c['id']; ?>">اختيار أيقونة</button>
                  <label><input type="checkbox" name="is_active" value="1" <?php echo $c['is_active']?'checked':''; ?>> مفعّل</label>
                  <button class="btn">حفظ</button>
                </div>
              </form>
              <form method="post" onsubmit="return confirm('حذف التصنيف؟ لا يمكن التراجع.');" class="mt-1">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="delete_cat">
                <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>">
                <button class="btn danger">حذف</button>
              </form>
            </details>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<div id="icon-picker-modal" class="modal-backdrop" style="display:none; align-items:center; justify-content:center;">
  <div class="modal" style="min-width:380px; max-width:720px;">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
      <strong>اختيار أيقونة</strong>
      <button class="btn outline" type="button" data-ip-close>إغلاق</button>
    </div>
    <div class="tabs" style="margin-bottom:8px;">
      <button class="btn small" data-ip-tab="fa">مكتبة FA</button>
      <button class="btn small" data-ip-tab="upload">رفع صورة</button>
      <button class="btn small" data-ip-tab="none">بدون</button>
    </div>
    <div data-ip-pane="fa">
      <div class="row" style="gap:6px; align-items:center;">
        <input type="text" id="ip-fa-q" placeholder="ابحث عن أيقونة FA (مثال: tooth, scissors)" autocomplete="off" style="flex:1">
        <button class="btn small" id="ip-fa-search">بحث</button>
      </div>
      <div id="ip-fa-grid" style="display:grid; grid-template-columns:repeat(6,1fr); gap:8px; margin-top:8px;"></div>
    </div>
    <div data-ip-pane="upload" style="display:none">
      <form id="ip-upload-form" method="post" enctype="multipart/form-data">
        <?php echo csrf_input(); ?>
        <input type="hidden" name="category_id" id="ip-cat-id" value="">
        <input type="hidden" name="action" value="upload">
        <input type="file" name="file" accept="image/png, image/svg+xml" required>
        <button class="btn">رفع</button>
        <span class="muted">SVG/PNG فقط، ≤200KB، ≤512×512</span>
      </form>
      <div class="row" style="justify-content:flex-end; margin-top:6px;">
        <button class="btn danger small" id="ip-clear">إزالة الأيقونة</button>
      </div>
    </div>
    <div data-ip-pane="none" style="display:none">
      <p class="muted">سيتم ضبط نوع الأيقونة على none (سيظهر fallback fa-folder-tree).</p>
      <button class="btn" id="ip-choose-none">تعيين لا شيء</button>
    </div>
  </div>
</div>
<script nonce="<?php echo htmlspecialchars(csp_nonce()); ?>">
(function(){
  const modal = document.getElementById('icon-picker-modal'); if(!modal) return;
  function show(){ modal.style.display='flex'; modal.classList.add('show'); }
  function hide(){ modal.style.display='none'; modal.classList.remove('show'); }
  modal.querySelector('[data-ip-close]').addEventListener('click', hide);
  // Tabs
  const panes = modal.querySelectorAll('[data-ip-pane]');
  modal.querySelectorAll('[data-ip-tab]').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const t = btn.getAttribute('data-ip-tab');
      panes.forEach(p=>{ p.style.display = (p.getAttribute('data-ip-pane')===t)?'block':'none'; });
    });
  });
  // Openers
  document.querySelectorAll('[data-open-icon-picker]').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const form = btn.closest('form');
      const idInput = form && form.querySelector('input[name="id"]');
      const catId = idInput ? idInput.value : '';
      modal.dataset.catId = catId;
      const ipCatId = document.getElementById('ip-cat-id'); if(ipCatId) ipCatId.value = catId;
      show();
    });
  });
  // FA search (client-side suggestions list)
  const FA_SUG = ['fa-tooth','fa-scissors','fa-utensils','fa-store','fa-shirt','fa-plug','fa-pizza-slice','fa-fire-burner','fa-kit-medical','fa-folder-tree','fa-car','fa-bag-shopping','fa-house','fa-phone','fa-flask'];
  function renderFaGrid(list){
    const grid = document.getElementById('ip-fa-grid'); if(!grid) return;
    grid.innerHTML = list.map(cls=>`<button class="btn" data-fa="${cls}" title="${cls}"><i class="fa ${cls}"></i></button>`).join('');
    grid.querySelectorAll('button[data-fa]').forEach(b=>{
      b.addEventListener('click', ()=>{
        const cls = b.getAttribute('data-fa');
        // set on current edit form
        const catId = modal.dataset.catId||'';
        const targetForm = document.querySelector(`form input[name="id"][value="${catId}"]`)?.closest('form') || document.querySelector('form[action][method]');
        if(targetForm){
          const tSel = targetForm.querySelector('select[name="icon_type"]');
          const tVal = targetForm.querySelector('input[name="icon_value"]');
          if(tSel && tVal){ tSel.value='fa'; tVal.value = cls; }
        }
        hide();
      });
    });
  }
  renderFaGrid(FA_SUG);
  document.getElementById('ip-fa-search').addEventListener('click', ()=>{
    const q = (document.getElementById('ip-fa-q').value||'').trim().toLowerCase();
    const list = q? FA_SUG.filter(x=>x.toLowerCase().includes(q)) : FA_SUG;
    renderFaGrid(list);
  });
  // Upload
  const upForm = document.getElementById('ip-upload-form');
  upForm.addEventListener('submit', async (e)=>{
    e.preventDefault();
    const fd = new FormData(upForm);
    const res = await fetch('<?php echo linkTo('api/category_icon_upload.php'); ?>', {method:'POST', body: fd, credentials:'same-origin'});
    const j = await res.json();
    if(j && j.ok){
      const catId = modal.dataset.catId||'';
      const targetForm = document.querySelector(`form input[name="id"][value="${catId}"]`)?.closest('form');
      if(targetForm){ const tSel = targetForm.querySelector('select[name="icon_type"]'); const tVal = targetForm.querySelector('input[name="icon_value"]'); if(tSel && tVal){ tSel.value='img'; tVal.value=j.path||''; } }
      hide();
    } else {
      alert('فشل رفع الأيقونة');
    }
  });
  document.getElementById('ip-clear').addEventListener('click', async ()=>{
    const catId = modal.dataset.catId||''; if(!catId) return;
    const fd = new FormData(); fd.append('csrf','<?php echo htmlspecialchars(csrf_token()); ?>'); fd.append('category_id',catId); fd.append('action','clear');
    const res = await fetch('<?php echo linkTo('api/category_icon_upload.php'); ?>', {method:'POST', body: fd, credentials:'same-origin'});
    const j = await res.json(); if(j && j.ok){
      const targetForm = document.querySelector(`form input[name="id"][value="${catId}"]`)?.closest('form');
      if(targetForm){ const tSel = targetForm.querySelector('select[name="icon_type"]'); const tVal = targetForm.querySelector('input[name="icon_value"]'); if(tSel && tVal){ tSel.value='none'; tVal.value=''; } }
      hide();
    }
  });
})();
</script>
<?php include __DIR__ . '/../layout_footer.php'; ?>

// UI bootstrap (no inline scripts; CSP-friendly)
(function(){
  'use strict';

  // Read feature flags from <meta> fallback (set by server)
  function readFlag(name, def){
    try{
      var m = document.querySelector('meta[name="'+name+'"]');
      if(!m) return def;
      var v = (m.getAttribute('content')||'').toLowerCase();
      if(v==='true' || v==='1') return true;
      if(v==='false' || v==='0') return false;
      return def;
    }catch(e){ return def; }
  }

  // Fallback for Leaflet CDN failure (no inline handlers)
  function ensureLeaflet(){
    try{
      if(window.L) return; // already present
      var s = document.getElementById('leaflet-cdn');
      if(!s) return;
      var injected = false;
      var injectFallback = function(){
        if(injected) return; injected = true;
        try{
          // JS fallback
          var s2 = document.createElement('script');
          s2.src = 'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js';
          document.head.appendChild(s2);
          // CSS fallback
          var l2 = document.createElement('link');
          l2.rel = 'stylesheet';
          l2.href = 'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css';
          document.head.appendChild(l2);
        }catch(e){}
      };
      s.addEventListener('error', injectFallback);
      // If script loads but L still undefined, try after a short delay
      s.addEventListener('load', function(){ setTimeout(function(){ if(!window.L) injectFallback(); }, 1000); });
      // Also time-based safety
      setTimeout(function(){ if(!window.L) injectFallback(); }, 3000);
    }catch(e){}
  }

  // Auto-init DataTables for tables that opt-in
  function initDataTables(){
    try{
      if(window.jQuery && jQuery.fn && jQuery.fn.DataTable){
        jQuery('table[data-dt="1"]').each(function(){
          var $t = jQuery(this);
          if(jQuery.fn.dataTable.isDataTable(this)){
            $t.data('dt-inited', true);
            return;
          }
          // Skip auto-init for live-updating or very large tables to avoid jank/freezes
          try{
            var el = this;
            var id = (el.getAttribute('id')||'').toLowerCase();
            var isLive = el.getAttribute('data-live')==='1' || id.indexOf('mon-')===0;
            var rows = (el.querySelectorAll('tbody tr')||[]).length;
            if(isLive || rows>1000){ return; }
          }catch(_){ }
          if(!$t.data('dt-inited')){
            var init = function(){
              try{
                $t.DataTable({ pageLength: 25, order: [], responsive: true, language: { url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/ar.json' } });
                $t.data('dt-inited', true);
              }catch(e){ /* swallow DT init errors to avoid page break */ }
            };
            if('requestIdleCallback' in window){ requestIdleCallback(init, {timeout: 500}); }
            else { setTimeout(init, 0); }
          }
        });
      }
    }catch(e){}
  }

  // Small admin tweaks previously inline
  function initAdminTweaks(){
    try{
      var el = document.getElementById('btn-to-top');
      if(el) el.setAttribute('data-inited','1');
    }catch(e){}
    try{
      var sel = document.querySelector('select[name="channel"]');
      if(sel) sel.title = 'القناة الافتراضية';
    }catch(e){}
  }

  document.addEventListener('DOMContentLoaded', function(){
    // Expose flag for UI (read from meta)
    window.__uiPersistFilters = !!readFlag('ui-persist-filters', false);
    initDataTables();
    ensureLeaflet();
    initAdminTweaks();
    // Wrap tables for better overflow handling and visual affordances
    (function(){
      try{
        function updateShadows(wrap){
          var el = wrap; var x = el.scrollLeft; var max = el.scrollWidth - el.clientWidth;
          // In RTL, scrollLeft can be negative in some browsers; normalize
          var hasLeft = x > 1 || (x < -1);
          var hasRight = (Math.abs(x) < Math.abs(max) - 1);
          if(hasLeft) el.classList.add('show-left-shadow'); else el.classList.remove('show-left-shadow');
          if(hasRight) el.classList.add('show-right-shadow'); else el.classList.remove('show-right-shadow');
        }
        document.querySelectorAll('table').forEach(function(t){
          // Only wrap significant tables (many columns) or those opting-in via data-wrap
          var cols = t.querySelectorAll('thead th').length || t.querySelectorAll('tr:first-child th, tr:first-child td').length;
          var wants = t.hasAttribute('data-wrap') || t.hasAttribute('data-dt') || cols >= 6;
          if(!wants) return;
          if(t.closest('.table-wrap')) return;
          var wrap = document.createElement('div'); wrap.className='table-wrap';
          t.parentNode.insertBefore(wrap, t); wrap.appendChild(t);
          wrap.addEventListener('scroll', function(){ updateShadows(wrap); }, {passive:true});
          // Initial shadows after layout
          setTimeout(function(){ updateShadows(wrap); }, 0);
          // Resize observer for dynamic width changes
          try{ var ro = new ResizeObserver(function(){ updateShadows(wrap); }); ro.observe(wrap); }catch(_){ }
          // Sticky-first opt-in
          if(t.getAttribute('data-sticky-first')==='1'){ t.classList.add('table-sticky-first'); }
        });
      }catch(_){ }
    })();
  });
})();
(function(){
  function qs(s, el=document){ return el.querySelector(s); }
  function on(el, ev, fn){ el && el.addEventListener(ev, fn); }
  // Lightweight error surface for quick diagnostics
  (function(){
    var shown = 0;
    function show(msg){ try{ if(window.showToast && shown<3){ shown++; showToast(msg,'error'); } }catch(_){} }
    window.addEventListener('error', function(e){
      var m = (e && e.message) ? e.message : 'خطأ غير معروف';
      show('خطأ بالواجهة: '+m);
    });
    window.addEventListener('unhandledrejection', function(e){
      var m = (e && e.reason && (e.reason.message||e.reason)) ? (e.reason.message||e.reason) : 'وعد مرفوض بدون سبب';
      show('خطأ بالواجهة: '+m);
    });
  })();
  document.addEventListener('DOMContentLoaded', function(){
    // Sidebar: desktop collapse + mobile drawer
    (function(){
      try{ if(localStorage.getItem('sidebar-collapsed')==='1'){ document.body.classList.add('sidebar-collapsed'); } }catch(_){ }
      var btn = qs('[data-toggle-sidebar]');
      // Backdrop for mobile
      var backdrop = document.querySelector('.sidebar-backdrop');
      if(!backdrop){ backdrop = document.createElement('div'); backdrop.className='sidebar-backdrop'; document.body.appendChild(backdrop); }

    function isMobile(){ return window.matchMedia && window.matchMedia('(max-width: 1100px)').matches; }
    function setAria(expanded){ try{ var b = qs('[data-toggle-sidebar]'); if(b){ b.setAttribute('aria-expanded', expanded? 'true':'false'); } }catch(_){ } }
    function openMobile(){ document.body.classList.add('sidebar-open','modal-open'); setAria(true); }
    function closeMobile(){ document.body.classList.remove('sidebar-open','modal-open'); setAria(false); }
    function toggle(){ if(isMobile()){ if(document.body.classList.contains('sidebar-open')) closeMobile(); else openMobile(); } else { document.body.classList.toggle('sidebar-collapsed'); setAria(!document.body.classList.contains('sidebar-collapsed')); try{ localStorage.setItem('sidebar-collapsed', document.body.classList.contains('sidebar-collapsed') ? '1' : '0'); }catch(_){ } } }

      on(btn, 'click', function(){ toggle(); });
  on(backdrop, 'click', function(){ closeMobile(); });
      window.addEventListener('keydown', function(e){ if(e.key==='Escape'){ closeMobile(); } });
      // Close drawer on route changes or hash changes
      window.addEventListener('hashchange', closeMobile);
  window.addEventListener('pageshow', function(){ closeMobile(); });
  document.addEventListener('visibilitychange', function(){ if(document.visibilityState==='hidden'){ closeMobile(); } });
  // Ensure closed by default on first load in mobile
  if(isMobile()){ closeMobile(); var sb = document.querySelector('.sidebar'); if(sb){ sb.style.visibility='hidden'; sb.style.transform='translateX(100%)'; setTimeout(function(){ sb.style.visibility=''; sb.style.transform=''; }, 10); } }
  // On resize, clear mobile-open state and rely on collapse on desktop
  window.addEventListener('resize', function(){ if(!isMobile()){ closeMobile(); } else { closeMobile(); var sb = document.querySelector('.sidebar'); if(sb){ sb.style.visibility='hidden'; sb.style.transform='translateX(100%)'; setTimeout(function(){ sb.style.visibility=''; sb.style.transform=''; }, 10); } } });
      // Close when tapping a link inside sidebar
      var side = document.querySelector('.sidebar');
      if(side){ side.addEventListener('click', function(e){ var a=e.target.closest('a'); if(a && isMobile()){ closeMobile(); } }); }
    })();

    // Theme toggle with persistence
    var root = document.documentElement;
    var saved = localStorage.getItem('theme');
    function systemPref(){ try{ return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'; }catch(_){ return 'light'; } }
    function applyTheme(theme){ root.setAttribute('data-theme', theme); var tbtn = qs('[data-toggle-theme]'); if(tbtn){ tbtn.setAttribute('aria-pressed', theme==='dark' ? 'true':'false'); var icon = tbtn.querySelector('i'); if(icon){ icon.classList.remove('fa-moon','fa-sun'); icon.classList.add(theme==='dark' ? 'fa-sun' : 'fa-moon'); } tbtn.title = theme==='dark' ? 'الوضع الداكن مفعّل — اضغط للتبديل إلى الفاتح' : 'الوضع الفاتح مفعّل — اضغط للتبديل إلى الداكن'; } }
    var initial = (saved==='light'||saved==='dark') ? saved : systemPref();
    applyTheme(initial);
    var tbtn = qs('[data-toggle-theme]');
    on(tbtn, 'click', function(){ var cur = root.getAttribute('data-theme') || initial; var next = cur==='dark' ? 'light' : 'dark'; applyTheme(next); localStorage.setItem('theme', next); });
    // React to system changes if user didn't explicitly choose
    try{ if(!saved && window.matchMedia){ window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function(e){ applyTheme(e.matches ? 'dark' : 'light'); }); } }catch(_){ }

    // Toast helper
    window.showToast = function(msg, type){
      var cont = qs('.toast-container');
      if(!cont){ cont = document.createElement('div'); cont.className='toast-container'; cont.setAttribute('role','region'); cont.setAttribute('aria-live','polite'); document.body.appendChild(cont); }
      var el = document.createElement('div'); el.className='toast ' + (type||''); el.textContent = msg; el.setAttribute('role', (type==='error'?'alert':'status')); el.setAttribute('aria-atomic','true'); cont.appendChild(el);
      setTimeout(function(){ el.style.opacity='0'; el.style.transform='translateY(6px)'; setTimeout(function(){ el.remove(); if(!cont.childElementCount) cont.remove(); }, 300); }, 2500);
    }

    // Prevent double submit + loading state for forms with [data-loading]
    document.querySelectorAll('form[data-loading]')?.forEach(function(f){
      f.addEventListener('submit', function(){
        var btn = f.querySelector('button[type="submit"],button:not([type])');
        if(btn){
          btn.dataset.originalText = btn.textContent;
          btn.textContent = '... جارٍ التنفيذ';
          btn.disabled = true;
        }
      });
    });

    // Persist filters for forms with [data-persist] using localStorage
    try{
      var persistEnabled = !!(window.__uiPersistFilters);
      if(persistEnabled){
        document.querySelectorAll('form[data-persist]')?.forEach(function(form){
          var key = 'persist:'+ (form.getAttribute('id') || form.getAttribute('name') || location.pathname);
          // Load only if form is effectively empty (to avoid overriding explicit query params)
          var isEmpty = true;
          form.querySelectorAll('input,select,textarea').forEach(function(el){
            if(!el.name) return;
            if(el.type==='hidden') return;
            if(el.type==='checkbox' || el.type==='radio'){ if(el.checked){ isEmpty=false; } }
            else if((el.value||'').toString().trim()!==''){ isEmpty=false; }
          });
          if(isEmpty){
            var raw = localStorage.getItem(key);
            if(raw){ try{ var obj = JSON.parse(raw);
              Object.keys(obj).forEach(function(k){ var el = form.querySelector('[name="'+k+'"]'); if(!el) return; if(el.tagName==='SELECT' || el.tagName==='INPUT' || el.tagName==='TEXTAREA'){ el.value = obj[k]; }
              });
            }catch(e){}
            }
          }
          // Save on change/submit
          var save = function(){ var data={}; form.querySelectorAll('input,select,textarea').forEach(function(el){ if(!el.name) return; if(el.type==='checkbox' || el.type==='radio'){ if(!el.checked) return; data[el.name] = el.value; } else { data[el.name]=el.value; } }); localStorage.setItem(key, JSON.stringify(data)); };
          form.addEventListener('change', save);
          form.addEventListener('submit', save);
          // reset handler
          form.querySelectorAll('[data-persist-reset]')?.forEach(function(btn){
            btn.addEventListener('click', function(e){ e.preventDefault(); try{ localStorage.removeItem(key);}catch(_){}; if(form.reset){ form.reset(); } else { form.querySelectorAll('input,select,textarea').forEach(function(el){ if(el.type==='checkbox' || el.type==='radio'){ el.checked=false; } else if(el.type!=='hidden'){ el.value=''; } }); } });
          });
        });
      }
    }catch(e){ /* ignore */ }

    // Bulk select helpers for tables: a checkbox with [data-select-all] toggles inputs [name="lead_ids[]"]
    document.querySelectorAll('[data-select-all]')?.forEach(function(master){
      var table = master.closest('table') || document;
      master.addEventListener('change', function(){
        var boxes = table.querySelectorAll('input[name="lead_ids[]"]');
        boxes.forEach(function(cb){ cb.checked = master.checked; });
        var badge = document.querySelector('[data-selected-count]'); if(badge){ badge.textContent = table.querySelectorAll('input[name="lead_ids[]"]:checked').length; }
      });
    });
    document.addEventListener('change', function(e){
      if(e.target && e.target.matches('input[name="lead_ids[]"]')){
        var table = e.target.closest('table') || document;
        var badge = document.querySelector('[data-selected-count]');
        var count = table.querySelectorAll('input[name="lead_ids[]"]:checked').length;
        if(badge){ badge.textContent = count; }
        var form = e.target.closest('form');
        if(form){ var bulkBtn = form.querySelector('button.btn.primary'); if(bulkBtn){ bulkBtn.disabled = (count<=0); } }
      }
    });

    // Initialize selected count on page load
  (function(){ var table = document.querySelector('table'); var badge = document.querySelector('[data-selected-count]'); if(table && badge){ var n = table.querySelectorAll('input[name="lead_ids[]"]:checked').length; badge.textContent = n; var form = table.closest('form'); if(form){ var bulkBtn = form.querySelector('button.btn.primary'); if(bulkBtn){ bulkBtn.disabled = (n<=0); } } } })();

    // Copy to clipboard buttons
    document.addEventListener('click', function(e){
      var t = e.target.closest('[data-copy]');
      if(!t) return;
      e.preventDefault();
      var txt = t.getAttribute('data-copy-text') || '';
      if(!txt) return;
      try{
        navigator.clipboard.writeText(txt).then(function(){ window.showToast && showToast('تم النسخ','success'); }).catch(function(){ fallbackCopy(txt); });
      }catch(_){ fallbackCopy(txt); }
      function fallbackCopy(text){
        var ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta); ta.select(); try{ document.execCommand('copy'); window.showToast && showToast('تم النسخ','success'); }catch(e){}; ta.remove();
      }
    });

    // Copy selected phones (admin leads)
    document.addEventListener('click', function(e){
      var b = e.target.closest('[data-copy-selected]');
      if(!b) return;
      e.preventDefault();
      var table = b.closest('.card') || document;
      var items = Array.from(table.querySelectorAll('input[name="lead_ids[]"]:checked')).map(function(cb){ return (cb.getAttribute('data-phone')||'').trim(); }).filter(Boolean);
      if(!items.length){ window.showToast && showToast('لا توجد أرقام محددة',''); return; }
      var text = items.join('\n');
      try{
        navigator.clipboard.writeText(text).then(function(){ window.showToast && showToast('تم نسخ '+items.length+' رقم','success'); }).catch(function(){ fallback(text); });
      }catch(_){ fallback(text); }
      function fallback(t){ var ta=document.createElement('textarea'); ta.value=t; document.body.appendChild(ta); ta.select(); try{ document.execCommand('copy'); window.showToast && showToast('تم نسخ '+items.length+' رقم','success'); }catch(e){}; ta.remove(); }
    });

    // AJAX forms: submit via fetch and show toast
    document.querySelectorAll('form[data-ajax]')?.forEach(function(f){
      f.addEventListener('submit', function(e){
        e.preventDefault();
        var fd = new FormData(f);
        fetch(location.href, { method:'POST', body: fd }).then(function(r){ return r.json(); }).then(function(j){
          if(j && j.ok){
            window.showToast && showToast('تم الحفظ','success');
            // Update status badge in the same row if present
            try{
              var row = f.closest('tr');
              var sel = f.querySelector('select[name="status"]');
              var badge = row ? row.querySelector('td:nth-child(7) .badge') : null; // agent table: 7th column is status
              if(badge && sel){
                var txt = sel.options[sel.selectedIndex].textContent.trim();
                badge.textContent = txt;
              }
            }catch(_){ }
          }
          else { window.showToast && showToast('تعذر الحفظ','error'); }
        }).catch(function(){ window.showToast && showToast('تعذر الحفظ','error'); });
      });
    });

    // Autofocus first text input in filter/search forms
    document.querySelectorAll('form.searchbar')?.forEach(function(f){ var el = f.querySelector('input[type="text"],input[type="search"]'); if(el){ el.focus({preventScroll:true}); } });
    // Keyboard shortcuts: '/'=focus filters, 'a'=toggle select-all, 'c'=copy selected
    document.addEventListener('keydown', function(e){
      var tag = (e.target && e.target.tagName) ? e.target.tagName.toLowerCase() : '';
      var isTyping = tag==='input' || tag==='textarea' || tag==='select' || (e.target && e.target.isContentEditable);
      if(!isTyping && e.key === '/'){
        var f = document.querySelector('form.searchbar'); var el = f && f.querySelector('input[type="text"],input[type="search"]'); if(el){ e.preventDefault(); el.focus({preventScroll:true}); el.select && el.select(); return; }
      }
      if(!isTyping && (e.key==='a' || e.key==='A')){
        var table = document.querySelector('table'); if(!table) return;
        var boxes = Array.from(table.querySelectorAll('input[name="lead_ids[]"]'));
        if(!boxes.length) return;
        var anyUnchecked = boxes.some(function(cb){ return !cb.checked; });
        boxes.forEach(function(cb){ cb.checked = anyUnchecked; });
        var badge = document.querySelector('[data-selected-count]'); if(badge){ badge.textContent = table.querySelectorAll('input[name="lead_ids[]"]:checked').length; }
        var form = table.closest('form'); if(form){ var bulkBtn = form.querySelector('button.btn.primary'); if(bulkBtn){ bulkBtn.disabled = !anyUnchecked; } }
        e.preventDefault(); return;
      }
      if(!isTyping && (e.key==='c' || e.key==='C')){
        var table = document.querySelector('table'); if(!table) return;
        var checked = Array.from(table.querySelectorAll('input[name="lead_ids[]"]:checked'));
        if(!checked.length){ window.showToast && showToast('لا توجد أرقام محددة',''); return; }
        var items = checked.map(function(cb){ return (cb.getAttribute('data-phone')||'').trim(); }).filter(Boolean);
        var text = items.join('\n');
        try{ navigator.clipboard.writeText(text).then(function(){ window.showToast && showToast('تم نسخ '+items.length+' رقم','success'); }); }
        catch(_){ var ta=document.createElement('textarea'); ta.value=text; document.body.appendChild(ta); ta.select(); try{ document.execCommand('copy'); window.showToast && showToast('تم نسخ '+items.length+' رقم','success'); }catch(e){} ta.remove(); }
        e.preventDefault(); return;
      }
    });

    // Persist pagination page size (per) across visits: apply only if URL lacks per
    try{
      var pagerForm = document.querySelector('div.searchbar form[action=""], div.searchbar form[method="get"]');
      if(pagerForm){
        var selPer = pagerForm.querySelector('select[name="per"]');
        if(selPer){
          var k = 'pager:'+location.pathname;
          var urlHasPer = /[?&]per=/.test(location.search);
          if(!urlHasPer){
            var savedPer = localStorage.getItem(k);
            if(savedPer && savedPer !== selPer.value){ selPer.value = savedPer; pagerForm.submit(); }
          }
          selPer.addEventListener('change', function(){ try{ localStorage.setItem(k, selPer.value); }catch(e){} });
        }
      }
    }catch(e){ /* ignore */ }

    // Shift-click range selection for lead checkboxes
    (function(){
      var lastIndex = null;
      document.addEventListener('click', function(e){
        var cb = e.target.closest('input[type="checkbox"][name="lead_ids[]"]');
        if(!cb) return;
        var list = Array.from((cb.closest('table')||document).querySelectorAll('input[type="checkbox"][name="lead_ids[]"]'));
        var idx = list.indexOf(cb);
        if(e.shiftKey && lastIndex!=null && idx>=0){
          var start = Math.min(lastIndex, idx), end = Math.max(lastIndex, idx);
          var val = cb.checked;
          for(var i=start; i<=end; i++){ list[i].checked = val; }
          var badge = document.querySelector('[data-selected-count]'); if(badge){ badge.textContent = (cb.closest('table')||document).querySelectorAll('input[name="lead_ids[]"]:checked').length; }
        }
        lastIndex = idx;
      });
    })();

    // Bulk threshold confirm for forms posting selected leads
    document.querySelectorAll('form[data-loading]')?.forEach(function(f){
      f.addEventListener('submit', function(e){
        var thresh = parseInt(f.getAttribute('data-bulk-threshold')||'100',10);
        var table = f.querySelector('table'); if(!table) return;
        var count = table.querySelectorAll('input[name="lead_ids[]"]:checked').length;
        if(count>thresh){
          var ok = confirm('ستقوم بإرسال رسائل لعدد كبير ('+count+'). هل أنت متأكد؟');
          if(!ok){ e.preventDefault(); return false; }
        }
      }, true);
    });

    // Selection helpers: clear and invert
    document.addEventListener('click', function(e){
      var none = e.target.closest('[data-select-none]');
      var inv = e.target.closest('[data-select-invert]');
      if(!none && !inv) return;
      e.preventDefault();
      var root = (none||inv).closest('.card') || document;
      var boxes = root.querySelectorAll('input[name="lead_ids[]"]');
      if(none){ boxes.forEach(function(cb){ cb.checked=false; }); }
      if(inv){ boxes.forEach(function(cb){ cb.checked=!cb.checked; }); }
      var table = root.querySelector('table'); var badge = root.querySelector('[data-selected-count]'); if(table && badge){ badge.textContent = table.querySelectorAll('input[name="lead_ids[]"]:checked').length; }
    });

    // Ripple effect on buttons
    document.addEventListener('click', function(e){
      var btn = e.target.closest('.btn');
      if(!btn) return;
      var rect = btn.getBoundingClientRect();
      var r = document.createElement('span'); r.className='ripple';
      var size = Math.max(rect.width, rect.height); r.style.width=r.style.height=size+'px';
      var x = (e.clientX - rect.left) - size/2; var y = (e.clientY - rect.top) - size/2;
      r.style.left = x+'px'; r.style.top = y+'px';
      btn.appendChild(r);
      setTimeout(function(){ r.remove(); }, 650);
    }, true);

    // Floating to-top button
    (function(){
      var btn = document.getElementById('btn-to-top');
      if(!btn) return;
      btn.hidden = false;
      function onScroll(){
        var y = window.scrollY || document.documentElement.scrollTop || 0;
        if(y > 400){ document.body.classList.add('show-to-top'); }
        else { document.body.classList.remove('show-to-top'); }
      }
      window.addEventListener('scroll', onScroll, {passive:true});
      on(btn, 'click', function(){ window.scrollTo({top:0, behavior:'smooth'}); });
      // initialize
      onScroll();
    })();

    // Global modal helpers: ESC to close, focus trap, and body scroll lock
    (function(){
      function closeTopModal(){
        var m = document.querySelector('.modal-backdrop.show');
        if(!m) return;
        m.classList.remove('show');
        if(!document.querySelector('.modal-backdrop.show')){ document.body.classList.remove('modal-open'); }
      }
      document.addEventListener('keydown', function(e){
        if(e.key === 'Escape'){
          closeTopModal();
        }
      });
      document.addEventListener('keydown', function(e){
        if(e.key !== 'Tab') return;
        var m = document.querySelector('.modal-backdrop.show .modal');
        if(!m) return;
        var focusables = m.querySelectorAll('a[href], button, input, select, textarea, [tabindex]:not([tabindex="-1"])');
        if(!focusables.length) return;
        var first = focusables[0], last = focusables[focusables.length-1];
        if(e.shiftKey){
          if(document.activeElement === first){ e.preventDefault(); last.focus(); }
        } else {
          if(document.activeElement === last){ e.preventDefault(); first.focus(); }
        }
      });
      // Note: We manage body scroll lock explicitly within modal open/close code to avoid costly observers.
    })();

    // Column visibility manager (DataTables): per-table controls, defaults, and persistence
    try{
      if(window.jQuery && jQuery.fn && jQuery.fn.DataTable){
        function tableKey(t){
          var key = t.getAttribute('data-table-key') || t.getAttribute('id');
          if(key) return key;
          var ths = Array.from(t.querySelectorAll('thead th')).map(function(th){ return (th.textContent||'').trim(); }).join('|').slice(0,120);
          return location.pathname + '::' + ths;
        }
        function loadPref(key){ try{ var raw=localStorage.getItem('colvis:'+key); return raw? JSON.parse(raw): null; }catch(_){ return null; } }
        function savePref(key, vis){ try{ localStorage.setItem('colvis:'+key, JSON.stringify(vis)); }catch(_){ } }
        function defaultsFor(t, headers){
          var required = headers.map(function(th,i){ return (th.getAttribute('data-required')==='1' || th.classList.contains('col-required')) ? i : null; }).filter(function(i){ return i!=null; });
          var def = headers.map(function(th,i){ return (th.getAttribute('data-default')==='1' || th.classList.contains('col-default')) ? i : null; }).filter(function(i){ return i!=null; });
          if(def.length===0){ def = headers.slice(0, Math.min(3, headers.length)).map(function(_,i){ return i; }); }
          var set = {}; required.concat(def).forEach(function(i){ set[i]=true; });
          return Object.keys(set).map(function(k){ return parseInt(k,10); }).sort(function(a,b){ return a-b; });
        }
        function applyVisibility(dt, visibleIdx){
          var cols = dt.columns().indexes().toArray();
          cols.forEach(function(i){ dt.column(i).visible(visibleIdx.indexOf(i)!==-1); });
          dt.columns.adjust();
          if (dt.responsive && typeof dt.responsive.recalc === 'function') { dt.responsive.recalc(); }
        }
        function buildControls(t, dt, key){
          var hdrs = Array.from(t.querySelectorAll('thead th'));
          var bar = document.createElement('div'); bar.className='table-controls'; bar.style.display='flex'; bar.style.gap='8px'; bar.style.margin='6px 0';
          var btn = document.createElement('button'); btn.className='btn sm'; btn.textContent='الأعمدة'; bar.appendChild(btn);
          var reset = document.createElement('button'); reset.className='btn sm outline'; reset.textContent='الافتراضي'; bar.appendChild(reset);
          var compact = document.createElement('button'); compact.className='btn sm outline'; compact.textContent='مختصر'; compact.title='إظهار الأعمدة الأساسية فقط'; bar.appendChild(compact);
          var detailed = document.createElement('button'); detailed.className='btn sm outline'; detailed.textContent='تفصيلي'; detailed.title='إظهار جميع الأعمدة'; bar.appendChild(detailed);
          var density = document.createElement('button'); density.className='btn sm outline'; density.textContent='مضغوط'; density.title='تقليل ارتفاع الصفوف لعرض المزيد'; bar.appendChild(density);
          t.parentNode.insertBefore(bar, t);
          var backdrop = document.createElement('div'); backdrop.className='modal-backdrop';
          var modal = document.createElement('div'); modal.className='modal';
          modal.innerHTML = '<div class="modal-header"><h3>اختيار الأعمدة</h3><button class="modal-close" data-close>✕</button></div>' +
            '<div class="row wrap gap">'+ hdrs.map(function(th,i){
              var name = (th.textContent||'').trim() || ('عمود '+(i+1));
              var req = th.getAttribute('data-required')==='1' || th.classList.contains('col-required');
              return '<label><input type="checkbox" data-col-idx="'+i+'" '+(req?'disabled checked':'')+'> '+name+'</label>';
            }).join('') + '</div>' +
            '<div class="row gap" style="justify-content:flex-end;margin-top:10px"><button class="btn" data-apply>تطبيق</button></div>';
          backdrop.appendChild(modal); document.body.appendChild(backdrop);
          function open(){ backdrop.classList.add('show'); syncChecks(); try{ document.body.classList.add('modal-open'); }catch(_){} }
        function close(){ backdrop.classList.remove('show'); try{ if(!document.querySelector('.modal-backdrop.show')){ document.body.classList.remove('modal-open'); } }catch(_){} }
          function syncChecks(){
            var vis = dt.columns().visible().toArray();
            modal.querySelectorAll('input[type="checkbox"][data-col-idx]')?.forEach(function(cb){ var idx=parseInt(cb.getAttribute('data-col-idx'),10); cb.checked = !!vis[idx]; });
          }
          btn.addEventListener('click', open);
          modal.querySelector('[data-close]').addEventListener('click', close);
          backdrop.addEventListener('click', function(e){ if(e.target===backdrop) close(); });
          modal.querySelector('[data-apply]').addEventListener('click', function(){
            var idxs=[]; modal.querySelectorAll('input[type="checkbox"][data-col-idx]')?.forEach(function(cb){ var i=parseInt(cb.getAttribute('data-col-idx'),10); if(cb.checked) idxs.push(i); });
            applyVisibility(dt, idxs); savePref(key, idxs); close();
          });
          reset.addEventListener('click', function(){ var idxs = defaultsFor(t, hdrs); applyVisibility(dt, idxs); savePref(key, idxs); window.showToast && showToast('تم تطبيق الأعمدة الافتراضية','success'); });
          compact.addEventListener('click', function(){
            var required = hdrs.map(function(th,i){ return (th.getAttribute('data-required')==='1' || th.classList.contains('col-required')) ? i : null; }).filter(function(i){ return i!=null; });
            var idxs = required.length ? required : [0];
            applyVisibility(dt, idxs); savePref(key, idxs);
          });
          detailed.addEventListener('click', function(){
            var idxs = hdrs.map(function(_,i){ return i; });
            applyVisibility(dt, idxs); savePref(key, idxs);
          });

          // Density toggle per table with persistence
          try{
            var kD = 'density:'+key;
            var savedD = localStorage.getItem(kD);
            if(savedD==='compact'){ t.classList.add('table-compact'); }
            density.addEventListener('click', function(){
              var isCompact = t.classList.toggle('table-compact');
              try{ localStorage.setItem(kD, isCompact ? 'compact' : ''); }catch(_){ }
            });
          }catch(_){ }
        }
        jQuery('table[data-dt="1"]').each(function(){
          var t = this; var $t = jQuery(this);
          var key = tableKey(t); var hdrs = Array.from(t.querySelectorAll('thead th'));
          function initWith(dt){
            if(!$t.data('colvis-inited')){ buildControls(t, dt, key); $t.data('colvis-inited', true); }
            var saved = loadPref(key); var idxs = (Array.isArray(saved) && saved.length) ? saved : defaultsFor(t, hdrs);
            applyVisibility(dt, idxs);
          }
          if(jQuery.fn.dataTable && jQuery.fn.dataTable.isDataTable(t)){
            var dt = $t.DataTable();
            initWith(dt);
          } else {
            $t.one('init.dt', function(e, settings){
              var dt = $t.DataTable();
              initWith(dt);
            });
          }
        });
      }
    }catch(e){ /* ignore col manager init */ }
  });
})();

<?php
/**
 * CIBILI - SERVER CONFIGURATION, API, AND SECURE INDEX LOADER
 *
 * Update sesuai permintaan:
 * ✅ Rupiah SO: hapus prefix "Rp" (tetap format ribuan Indonesia)
 * ✅ Fix Android: setelah tampil tabel Clerek di iframe, menu lainnya tetap bisa dibuka (modal tidak ketutup iframe)
 * ✅ Tetap 1 file (single file) siap upload
 */

ini_set('display_errors', 0);
// Data sesi PHP dipertahankan agar Android/browser yang membuang proses tab
// tidak membuat user logout sendiri. Batas idle aplikasi tetap diperiksa oleh
// token aktif dan pengaturan sesi dari halaman admin.
ini_set('session.gc_maxlifetime', '31536000');
ini_set('session.cookie_lifetime', '31536000');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ini_set('session.cookie_secure', '1');
date_default_timezone_set('Asia/Jakarta');
if(function_exists('session_status')){
  if(session_status() !== PHP_SESSION_ACTIVE){ @session_start(); }
}else{
  @session_start();
}

if(!headers_sent()){
  header('X-Content-Type-Options: nosniff');
  header('X-Frame-Options: SAMEORIGIN');
  header('Referrer-Policy: same-origin');
}


// Inject guard JS untuk auto logout saat web/tab tidak aktif.
if(!defined('CIBILI_AUTOLOGOUT_OB')){
  define('CIBILI_AUTOLOGOUT_OB', true);
  @ob_start(function($html){
    if(!is_string($html) || $html === '') return $html;
    if(isset($_GET['js']) || isset($_GET['download']) || in_array((string)($_GET['api'] ?? ''), ['ikt_dashboard','ikt_proxy'], true) || stripos($html, 'cibiliAutoLogoutGuard') !== false) return $html;
    // Beberapa halaman aplikasi dibuka melalui parameter api. Sisipkan guard
    // hanya pada dokumen HTML utuh agar respons JSON atau file tidak berubah.
    if(stripos($html, '<html') === false || stripos($html, '</body>') === false) return $html;

    // PENTING: jangan pernah sisipkan heartbeat/autologout di halaman login.
    // Versi sebelumnya memanggil session_heartbeat saat belum login, mendapat 401,
    // lalu redirect ke layar sesi berakhir terus-menerus sehingga form login auto refresh.
    $isLoginScreen = (stripos($html, 'id="loginPage"') !== false && stripos($html, 'id="mainPage" style="display:none"') !== false);
    if($isLoginScreen) return $html;

    // Guard tambahan: hanya aktif bila session/cookie memang sudah valid.
    $hasLogin = false;
    if(function_exists('cookie_read_session')){
      $hasLogin = (cookie_read_session() !== '');
    }else{
      $hasLogin = (!empty($_SESSION['storeId']) || !empty($_COOKIE['ALFASTORE_SESSION']));
    }
    if(!$hasLogin) return $html;

    $self = (string)($_SERVER['PHP_SELF'] ?? 'index.php');
    $selfJs = json_encode($self, JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
    $script = <<<'HTML'
<script id="cibiliAutoLogoutGuard">
(function(){
  if(window.__CIBILI_AUTO_LOGOUT__) return;
  window.__CIBILI_AUTO_LOGOUT__ = true;

  var API = __CIBILI_API__;
  var lastPing = 0;
  var lastSignature = '';
  var timer = null;
  var activityHint = '';
  var activityHintKey = '';
  var clickTimer = null;
  var lastKnownTimeoutMs = 86400000;
  var hiddenAt = 0;

  var PAGE_LABELS = {
    plano:'Planogram + OH',
    oh_realtime:'Cek OH Sedang SO',
    rupiah_so:'Cetak Selisih Rupiah',
    jadwal_so:'Jadwal SO',
    register_dokumen_toko:'Register Dokumen Toko',
    register_dokumen_nr:'Laporan NR',
    sogrand_taskforce:'SO Grand Task Force',
    sogrand_key_admin:'Admin Key SO Grand',
    new_user_key_admin:'Admin Store NEW',
    oh979:'OH Custom 979',
    oh_st_rokok:'OH ST / Rokok',
    plan_shift:'Plan Shift Karyawan'
  };
  var API_LABELS = {
    oh979_page:'OH Custom 979',
    register_dokumen_toko:'Register Dokumen Toko',
    register_dokumen_nr:'Laporan NR',
    sis_report:'Laporan SIS',
    ikt_dashboard:'IKT'
  };
  var MODAL_LABELS = {
    reportPopup:'Menu Laporan',
    stockPopup:'Menu Stock Opname',
    clerekPopup:'Menu Clerek',
    lainnyaPopup:'Menu Lainnya',
    profileModal:'Profil',
    helpModal:'Bantuan',
    notifModal:'Notifikasi',
    alfaNotifModal:'Notifikasi',
    cekOhPopup:'Menu Cek OH',
    ohMultiStoreModal:'OH Multi Toko',
    datePickerModal:'Pilih Tanggal',
    adminModal:'Admin'
  };
  var PATH_LABELS = {
    daily_performance:'Daily Performance',
    rep_gabungan_23_24:'Laporan Gabungan',
    laporan_plu_tak_main_toko:'PLU Tidak Main',
    penjualan_per_kasir:'Penjualan Per Kasir',
    report_pps:'Laporan PPS',
    new_setoran_kasir:'Setoran Kasir',
    laporan_sales_member:'Sales Member',
    setoran_per_kasir_detail_tipe_kartu:'Detail Tipe Kartu',
    setoran_per_kasir_detail_non_commerce:'Detail Non Commerce',
    detail_sarana_promosi:'Sarana Promosi',
    rekap_kerja_sama_tenant:'Kerja Sama Tenant',
    register_dokumen_toko_NR:'Laporan NR',
    csel_last_so_absolute_desc:'Cetak Selisih'
  };

  function cleanText(value, max){
    var text = String(value == null ? '' : value)
      .replace(/<[^>]*>/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();
    return text.slice(0, max || 80);
  }
  function makeKey(value){
    return cleanText(value, 60).toLowerCase().replace(/[^a-z0-9_.:-]+/g, '-').replace(/^-+|-+$/g, '') || 'page';
  }
  function titleCase(value){
    return cleanText(String(value || '').replace(/[_-]+/g, ' '), 80).replace(/\b\w/g, function(c){ return c.toUpperCase(); });
  }
  function isVisible(el){
    if(!el || !el.isConnected) return false;
    var style;
    try{ style = window.getComputedStyle(el); }catch(e){ return false; }
    return style.display !== 'none' && style.visibility !== 'hidden' && Number(style.opacity || 1) !== 0 && el.getClientRects().length > 0;
  }
  function labelFromUrl(raw){
    if(!raw || raw === 'about:blank') return null;
    try{
      var url = new URL(raw, location.href);
      var page = String(url.searchParams.get('page') || '').toLowerCase();
      if(page) return {key:'page:'+page, title:PAGE_LABELS[page] || titleCase(page)};
      var api = String(url.searchParams.get('api') || '').toLowerCase();
      if(api === 'go_prd'){
        var reportPath = String(url.searchParams.get('path') || '');
        var reportName = reportPath.split('/').filter(Boolean).pop() || '';
        return {key:'report:'+makeKey(reportName), title:PATH_LABELS[reportName] || titleCase(reportName) || 'Laporan'};
      }
      if(api && API_LABELS[api]) return {key:'api:'+api, title:API_LABELS[api]};
    }catch(e){}
    return null;
  }
  function labelFromHtml(html){
    var source = String(html || '');
    var match = source.match(/<title[^>]*>([\s\S]*?)<\/title>/i) ||
      source.match(/<(?:h1|h2|h3)[^>]*>([\s\S]*?)<\/(?:h1|h2|h3)>/i) ||
      source.match(/<[^>]+class=["'][^"']*\btitle\b[^"']*["'][^>]*>([\s\S]*?)<\/[^>]+>/i);
    var title = match ? cleanText(match[1], 80) : '';
    return title ? {key:'frame:'+makeKey(title), title:title} : null;
  }
  function modalActivity(){
    var nodes = Array.prototype.slice.call(document.querySelectorAll('.modal,.payment-screen'));
    var visible = nodes.filter(isVisible).filter(function(el){
      return !/^(loginPage|pinLoginPage)$/i.test(String(el.id || ''));
    });
    if(!visible.length) return null;
    visible.sort(function(a,b){
      var za = parseInt(window.getComputedStyle(a).zIndex,10) || 0;
      var zb = parseInt(window.getComputedStyle(b).zIndex,10) || 0;
      if(za !== zb) return za - zb;
      return nodes.indexOf(a) - nodes.indexOf(b);
    });
    var modal = visible[visible.length - 1];
    var known = MODAL_LABELS[String(modal.id || '')] || '';
    var heading = modal.querySelector('h1,h2,h3,.modal-title,.title');
    var title = known || cleanText(heading ? heading.textContent : '', 80);
    if(!title) return null;
    return {key:'modal:'+makeKey(modal.id || title), title:title};
  }
  function iframeActivity(){
    var frames = Array.prototype.slice.call(document.querySelectorAll('iframe')).filter(isVisible);
    if(!frames.length) return null;
    var frame = document.getElementById('contentFrame');
    if(!frame || !isVisible(frame)) frame = frames[frames.length - 1];
    var title = '';
    try{
      var doc = frame.contentDocument;
      if(doc){
        var heading = doc.querySelector('h1,h2,h3,.title');
        title = cleanText((doc.title || (heading && heading.textContent) || ''), 80);
      }
    }catch(e){}
    if(title && !/^(cibili|about:blank)$/i.test(title)) return {key:'frame:'+makeKey(title), title:title};
    var fromUrl = labelFromUrl(frame.getAttribute('src') || frame.src || '');
    if(fromUrl) return fromUrl;
    if(activityHint) return {key:activityHintKey || ('frame:'+makeKey(activityHint)), title:activityHint};
    var attrTitle = cleanText(frame.getAttribute('title') || '', 80);
    return attrTitle ? {key:'frame:'+makeKey(attrTitle), title:attrTitle} : null;
  }
  function activityInfo(){
    var modal = modalActivity();
    if(modal) return modal;

    var frame = iframeActivity();
    if(frame) return frame;

    var fromLocation = labelFromUrl(location.href);
    if(fromLocation) return fromLocation;

    if(document.body && document.body.classList.contains('admin-page')) return {key:'admin', title:'Admin'};
    var visiblePage = Array.prototype.slice.call(document.querySelectorAll('[id$="Page"],.page')).filter(isVisible).filter(function(el){
      return !/login/i.test(String(el.id || ''));
    }).pop();
    if(visiblePage && String(visiblePage.id || '').toLowerCase() !== 'mainpage'){
      var pageHeading = visiblePage.querySelector('h1,h2,h3,.title');
      var pageTitle = cleanText(pageHeading ? pageHeading.textContent : '', 80);
      if(pageTitle) return {key:'section:'+makeKey(visiblePage.id || pageTitle), title:pageTitle};
    }
    return {key:'home', title:'Beranda'};
  }
  function setActivityHint(info){
    if(!info || !info.title) return;
    activityHint = cleanText(info.title, 80);
    activityHintKey = cleanText(info.key || ('frame:'+makeKey(activityHint)), 60);
  }
  window.cibiliActivityNow = function(title, key){
    setActivityHint({title:title || 'Beranda', key:key || ('page:'+makeKey(title || 'Beranda'))});
    ping(true);
  };
  function patchFrameFunctions(){
    if(typeof window.setIframeUrl === 'function' && !window.setIframeUrl.__cibiliActivityPatched){
      var originalUrl = window.setIframeUrl;
      var wrappedUrl = function(url){
        var info = labelFromUrl(url);
        if(info) setActivityHint(info);
        var result = originalUrl.apply(this, arguments);
        setTimeout(function(){ pingIfChanged(); }, 20);
        return result;
      };
      wrappedUrl.__cibiliActivityPatched = true;
      window.setIframeUrl = wrappedUrl;
    }
    if(typeof window.setIframeHtml === 'function' && !window.setIframeHtml.__cibiliActivityPatched){
      var originalHtml = window.setIframeHtml;
      var wrappedHtml = function(html){
        var info = labelFromHtml(html);
        if(info) setActivityHint(info);
        var result = originalHtml.apply(this, arguments);
        setTimeout(function(){ pingIfChanged(); }, 20);
        return result;
      };
      wrappedHtml.__cibiliActivityPatched = true;
      window.setIframeHtml = wrappedHtml;
    }
  }
  function goLogin(){
    window.location.replace(API);
  }
  function ping(force){
    if(document.hidden) return;
    patchFrameFunctions();
    var now = Date.now();
    var activity = activityInfo();
    var signature = String(activity.key || '') + '|' + String(activity.title || '');
    var changed = signature !== lastSignature;
    if(!force && !changed && now - lastPing < 12000) return;
    lastPing = now;
    lastSignature = signature;
    fetch(API + '?api=session_heartbeat&_=' + now, {
      method:'POST',
      cache:'no-store',
      credentials:'same-origin',
      headers:{
        'Content-Type':'application/json',
        'Accept':'application/json',
        'X-Requested-With':'XMLHttpRequest',
        'X-CIBILI-Page-Visible':'1',
        'X-CIBILI-Session-Recover':'1'
      },
      body:JSON.stringify({pageKey:activity.key || 'page', pageTitle:activity.title || 'Beranda', pageVisible:true})
    }).then(function(response){
      if(response.status === 401 || response.status === 403){ goLogin(); return null; }
      return response.json().catch(function(){ return null; });
    }).then(function(data){
      if(data && Number(data.timeoutSec) > 0) lastKnownTimeoutMs = Math.max(60000, Number(data.timeoutSec) * 1000);
    }).catch(function(){});
  }
  function pingIfChanged(){
    if(document.hidden) return;
    var activity = activityInfo();
    var signature = String(activity.key || '') + '|' + String(activity.title || '');
    if(signature !== lastSignature) ping(true);
  }
  function start(){
    if(timer) clearInterval(timer);
    if(!document.hidden){
      patchFrameFunctions();
      ping(true);
      timer = setInterval(function(){ ping(false); }, 15000);
    }
  }
  function stop(){
    if(timer){ clearInterval(timer); timer = null; }
  }
  function resumeOrStart(){
    if(document.hidden) return;
    // Jangan memutus sesi hanya karena Android men-throttle tab di background.
    // Heartbeat visible di bawah akan meminta server memvalidasi/recover sesi.
    hiddenAt = 0;
    start();
  }

  document.addEventListener('click', function(){
    clearTimeout(clickTimer);
    clickTimer = setTimeout(function(){ patchFrameFunctions(); pingIfChanged(); }, 25);
  }, true);
  document.addEventListener('visibilitychange', function(){
    if(document.hidden){ hiddenAt = Date.now(); stop(); }
    else resumeOrStart();
  });
  window.addEventListener('pageshow', resumeOrStart);
  window.addEventListener('popstate', function(){ setTimeout(pingIfChanged, 80); });
  window.addEventListener('hashchange', function(){ setTimeout(pingIfChanged, 80); });
  window.addEventListener('pagehide', function(){ hiddenAt = Date.now(); stop(); });
  start();
})();
</script>
HTML;
    $script = str_replace('__CIBILI_API__', ($selfJs !== false ? $selfJs : '"index.php"'), $script);
    return preg_replace('~</body>~i', $script . '</body>', $html, 1);
  });
}

// PHP 7.x compatibility
if(!function_exists('str_starts_with')){
  function str_starts_with($haystack, $needle){
    $haystack = (string)$haystack;
    $needle = (string)$needle;
    return $needle === '' || strpos($haystack, $needle) === 0;
  }
}

/* =========================
   SENSITIVE CONFIG (moved to proxy.php)
========================= */
define('CIBILI_INDEX_KEY_B64', '/ZN8NXll0nbyodmroMgbNd4p0l3wOAdA4iRBdp2l5BA=');
define('ADMIN_STORE_ID', 'M604');
define('ADMIN_PASSWORD', 'debby');
define('ADMIN_REPORT_PASSWORD', '27');
define('DEVELOPER_PIN', '2727');
define('DEFAULT_PIN', '0000');
define('SOGRAND_PIN', '9999');
define('CIBILI_REPORT_USER_ID', '23067884');
define('CIBILI_IKT_DASHBOARD_URL', 'https://dash-opr-mobile-dot-opr-mobile-reporting-sat-prd.et.r.appspot.com/iktDashboard');

define('ALFA_PRD_API_BASE', 'https://app.alfastore.co.id/prd/api');
define('ALFA_TO_API_BASE', 'https://app.alfastore.co.id/to/api');

/* =========================
   IKT SAME-ORIGIN REVERSE PROXY
   - Menghindari penolakan iframe (X-Frame-Options/CSP) dari host IKT.
   - Autentikasi NIK/PIN/OTP tetap dilakukan oleh server IKT; tidak dibypass.
   - Cookie upstream disimpan terpisah per sesi CIBILI di sisi server.
========================= */
function cibili_ikt_base_host(){
  $host = (string)parse_url(CIBILI_IKT_DASHBOARD_URL, PHP_URL_HOST);
  return strtolower($host);
}

function cibili_ikt_cookie_file(){
  $sid = function_exists('session_id') ? (string)session_id() : '';
  if($sid === '') $sid = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '') . '|' . (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
  return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'cibili_ikt_' . hash('sha256', $sid) . '.cookie';
}

function cibili_ikt_register_host($host){
  $host = strtolower(trim((string)$host));
  if($host === '') return;
  if(!isset($_SESSION['cibili_ikt_hosts']) || !is_array($_SESSION['cibili_ikt_hosts'])) $_SESSION['cibili_ikt_hosts'] = [];
  $_SESSION['cibili_ikt_hosts'][$host] = time();
}

function cibili_ikt_host_allowed($host){
  $host = strtolower(trim((string)$host));
  if($host === '' || $host === cibili_ikt_base_host()) return $host !== '';
  $known = (isset($_SESSION['cibili_ikt_hosts']) && is_array($_SESSION['cibili_ikt_hosts'])) ? $_SESSION['cibili_ikt_hosts'] : [];
  return isset($known[$host]);
}

function cibili_ikt_origin($url){
  $parts = parse_url((string)$url);
  if(!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) return '';
  $origin = strtolower((string)$parts['scheme']) . '://' . (string)$parts['host'];
  if(isset($parts['port'])) $origin .= ':' . (int)$parts['port'];
  return $origin;
}

function cibili_ikt_resolve_url($value, $baseUrl){
  $value = html_entity_decode(trim((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
  if($value === '' || $value[0] === '#' || preg_match('~^(?:data:|javascript:|mailto:|tel:|blob:)~i', $value)) return '';
  if(preg_match('~^https?://~i', $value)) return $value;

  $base = parse_url((string)$baseUrl);
  if(!is_array($base) || empty($base['scheme']) || empty($base['host'])) return '';
  $origin = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . (int)$base['port'] : '');
  if(strpos($value, '//') === 0) return $base['scheme'] . ':' . $value;
  if($value[0] === '/') return $origin . $value;

  $path = (string)($base['path'] ?? '/');
  if($path === '' || substr($path, -1) !== '/') $path = preg_replace('~/[^/]*$~', '/', $path);
  $joined = $path . $value;
  $segments = [];
  foreach(explode('/', $joined) as $seg){
    if($seg === '' || $seg === '.') continue;
    if($seg === '..'){ array_pop($segments); continue; }
    $segments[] = $seg;
  }
  return $origin . '/' . implode('/', $segments);
}

function cibili_ikt_local_url($absolute){
  $self = (string)($_SERVER['PHP_SELF'] ?? 'index.php');
  return $self . '?api=ikt_proxy&u=' . rawurlencode((string)$absolute);
}

function cibili_ikt_rewrite_one_url($value, $baseUrl){
  $absolute = cibili_ikt_resolve_url($value, $baseUrl);
  if($absolute === '') return $value;
  $host = strtolower((string)parse_url($absolute, PHP_URL_HOST));
  if($host !== '' && cibili_ikt_host_allowed($host)) return cibili_ikt_local_url($absolute);
  return $value;
}

function cibili_ikt_rewrite_html($html, $baseUrl){
  $html = (string)$html;
  // Base eksternal dapat membuat URL relatif keluar dari proxy; hapus lalu atur lewat patch JS.
  $html = preg_replace('~<base\\b[^>]*>~i', '', $html);

  $html = preg_replace_callback('~\\b(href|src|action|poster)\\s*=\\s*(["\\\'])(.*?)\\2~is', function($m) use ($baseUrl){
    $rewritten = cibili_ikt_rewrite_one_url($m[3], $baseUrl);
    return $m[1] . '=' . $m[2] . htmlspecialchars($rewritten, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . $m[2];
  }, $html);

  $html = preg_replace_callback('~\\bsrcset\\s*=\\s*(["\\\'])(.*?)\\1~is', function($m) use ($baseUrl){
    $parts = preg_split('/\\s*,\\s*/', $m[2]);
    $out = [];
    foreach($parts as $part){
      if($part === '') continue;
      $bits = preg_split('/\\s+/', trim($part), 2);
      $url = cibili_ikt_rewrite_one_url($bits[0] ?? '', $baseUrl);
      $out[] = $url . (isset($bits[1]) && $bits[1] !== '' ? (' ' . $bits[1]) : '');
    }
    return 'srcset=' . $m[1] . htmlspecialchars(implode(', ', $out), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . $m[1];
  }, $html);

  $self = (string)($_SERVER['PHP_SELF'] ?? 'index.php');
  $allowedHosts = [cibili_ikt_base_host()];
  if(isset($_SESSION['cibili_ikt_hosts']) && is_array($_SESSION['cibili_ikt_hosts'])){
    foreach(array_keys($_SESSION['cibili_ikt_hosts']) as $h){ if($h && !in_array($h, $allowedHosts, true)) $allowedHosts[] = $h; }
  }
  $jsBase = json_encode((string)$baseUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
  $jsSelf = json_encode($self, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
  $jsHosts = json_encode(array_values($allowedHosts), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
  $patch = <<<HTML
<script id="cibiliIktProxyPatch">
(function(){
  if(window.__CIBILI_IKT_PROXY__) return;
  window.__CIBILI_IKT_PROXY__=true;
  const UPSTREAM={$jsBase};
  const SELF={$jsSelf};
  const HOSTS={$jsHosts};
  function proxify(raw){
    try{
      raw=String(raw==null?'':raw);
      if(!raw || raw[0]==='#' || /^(?:data:|javascript:|mailto:|tel:|blob:)/i.test(raw)) return raw;
      const local=new URL(raw, location.href);
      if(local.origin===location.origin && local.searchParams.get('api')==='ikt_proxy') return local.href;
      const u=new URL(raw, UPSTREAM);
      if(!/^https?:$/.test(u.protocol) || HOSTS.indexOf(u.hostname.toLowerCase())===-1) return raw;
      return SELF+'?api=ikt_proxy&u='+encodeURIComponent(u.href);
    }catch(e){ return raw; }
  }
  window.__cibiliIktProxify=proxify;
  try{
    const oldFetch=window.fetch;
    if(oldFetch) window.fetch=function(input,init){
      try{
        if(typeof input==='string' || input instanceof URL) input=proxify(String(input));
        else if(typeof Request!=='undefined' && input instanceof Request){
          const next=proxify(input.url); if(next!==input.url) input=new Request(next,input);
        }
      }catch(e){}
      return oldFetch.call(this,input,init);
    };
  }catch(e){}
  try{
    const oldOpen=XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open=function(method,url){
      const args=[].slice.call(arguments); args[1]=proxify(url); return oldOpen.apply(this,args);
    };
  }catch(e){}
  document.addEventListener('submit',function(ev){
    try{ const f=ev.target; if(f&&f.tagName==='FORM'){ const raw=f.getAttribute('action')||UPSTREAM; f.setAttribute('action',proxify(raw)); } }catch(e){}
  },true);
  document.addEventListener('click',function(ev){
    try{ const a=ev.target&&ev.target.closest?ev.target.closest('a[href]'):null; if(a){ const raw=a.getAttribute('href'); const next=proxify(raw); if(next!==raw) a.setAttribute('href',next); } }catch(e){}
  },true);
  try{
    const oldSet=Element.prototype.setAttribute;
    Element.prototype.setAttribute=function(name,value){
      const n=String(name||'').toLowerCase();
      if((n==='src'||n==='href'||n==='action'||n==='poster') && typeof value==='string') value=proxify(value);
      return oldSet.call(this,name,value);
    };
  }catch(e){}
  try{
    const oa=Location.prototype.assign, or=Location.prototype.replace;
    if(oa) Location.prototype.assign=function(url){ return oa.call(this,proxify(url)); };
    if(or) Location.prototype.replace=function(url){ return or.call(this,proxify(url)); };
  }catch(e){}
})();
</script>
HTML;
  if(stripos($html, '</head>') !== false) $html = preg_replace('~</head>~i', $patch . '</head>', $html, 1);
  elseif(stripos($html, '<body') !== false) $html = preg_replace('~<body\\b~i', $patch . '<body', $html, 1);
  else $html = $patch . $html;
  return $html;
}

function cibili_ikt_rewrite_css($css, $baseUrl){
  return preg_replace_callback('~url\\(\\s*(["\\\']?)([^)"\\\']+)\\1\\s*\\)~i', function($m) use ($baseUrl){
    $url = cibili_ikt_rewrite_one_url(trim($m[2]), $baseUrl);
    return 'url(' . ($m[1] ?: '"') . $url . ($m[1] ?: '"') . ')';
  }, (string)$css);
}

function cibili_ikt_proxy_handle($targetUrl){
  $targetUrl = trim((string)$targetUrl);
  $parts = parse_url($targetUrl);
  $scheme = strtolower((string)($parts['scheme'] ?? ''));
  $host = strtolower((string)($parts['host'] ?? ''));
  if($scheme !== 'https' || $host === '' || !cibili_ikt_host_allowed($host)){
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html><body style="font-family:Arial;padding:24px"><b>Tujuan IKT tidak diizinkan.</b></body></html>';
    exit;
  }

  cibili_ikt_register_host($host);
  $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
  $rawBody = ($method !== 'GET' && $method !== 'HEAD') ? (string)file_get_contents('php://input') : '';
  if($rawBody === '' && !empty($_POST) && $method !== 'GET' && $method !== 'HEAD') $rawBody = http_build_query($_POST);
  $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? 'text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8');
  $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? 'Mozilla/5.0 (Linux; Android 15) AppleWebKit/537.36 Chrome/120 Safari/537.36');
  $lastReferer = (string)($_SESSION['cibili_ikt_last_url'] ?? $targetUrl);

  $body = false;
  $status = 0;
  $contentType = '';
  $effective = $targetUrl;
  $respHeaders = [];
  $err = '';

  if(function_exists('curl_init')){
    /*
     * Redirect IKT ditangani manual. Jangan memakai CURLOPT_FOLLOWLOCATION
     * bersama CURLOPT_CUSTOMREQUEST POST, karena cURL dapat mempertahankan
     * POST sampai URL dashboard hasil redirect. Dashboard hanya menerima GET
     * dan akhirnya menampilkan "Method Not Allowed".
     *
     * Perilaku di bawah mengikuti browser: 301/302/303 setelah submit form
     * diubah menjadi GET, sedangkan 307/308 mempertahankan method + body.
     */
    $cookieFile = cibili_ikt_cookie_file();
    if(!is_file($cookieFile)) @touch($cookieFile);

    $currentUrl = $targetUrl;
    $currentMethod = $method;
    $currentBody = $rawBody;
    $currentContentType = (string)($_SERVER['CONTENT_TYPE'] ?? '');

    // Jika PHP sudah mem-parsing form dan php://input kosong, body dibuat ulang
    // sebagai urlencoded. Jangan teruskan Content-Type multipart lama karena
    // boundary-nya tidak lagi cocok dengan body hasil http_build_query().
    if($rawBody !== '' && !empty($_POST) && stripos($currentContentType, 'multipart/form-data') === 0 && empty($_FILES)){
      $currentContentType = 'application/x-www-form-urlencoded; charset=UTF-8';
    }

    for($hop=0; $hop<9; $hop++){
      $forwardHeaders = ['Accept: ' . $accept];
      if(!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) $forwardHeaders[] = 'Accept-Language: ' . (string)$_SERVER['HTTP_ACCEPT_LANGUAGE'];
      if($currentMethod !== 'GET' && $currentMethod !== 'HEAD' && $currentContentType !== '') $forwardHeaders[] = 'Content-Type: ' . $currentContentType;
      if(!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) $forwardHeaders[] = 'X-Requested-With: ' . (string)$_SERVER['HTTP_X_REQUESTED_WITH'];
      if(!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) $forwardHeaders[] = 'X-CSRF-Token: ' . (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
      if(!empty($_SERVER['HTTP_X_XSRF_TOKEN'])) $forwardHeaders[] = 'X-XSRF-Token: ' . (string)$_SERVER['HTTP_X_XSRF_TOKEN'];
      if(!empty($_SERVER['HTTP_AUTHORIZATION'])) $forwardHeaders[] = 'Authorization: ' . (string)$_SERVER['HTTP_AUTHORIZATION'];
      $origin = cibili_ikt_origin($currentUrl);
      if($currentMethod !== 'GET' && $currentMethod !== 'HEAD' && $origin !== '') $forwardHeaders[] = 'Origin: ' . $origin;
      if($lastReferer !== '') $forwardHeaders[] = 'Referer: ' . $lastReferer;

      $respHeaders = [];
      $ch = curl_init();
      curl_setopt_array($ch, [
        CURLOPT_URL => $currentUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING => '',
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_USERAGENT => $ua,
        CURLOPT_HTTPHEADER => $forwardHeaders,
        CURLOPT_HEADERFUNCTION => function($curl, $line) use (&$respHeaders){
          $len = strlen($line);
          $trim = trim($line);
          if($trim === '') return $len;
          if(stripos($trim, 'HTTP/') === 0){ $respHeaders = []; return $len; }
          $pos = strpos($line, ':');
          if($pos !== false){
            $name = strtolower(trim(substr($line,0,$pos)));
            $value = trim(substr($line,$pos+1));
            if($name !== '') $respHeaders[$name][] = $value;
          }
          return $len;
        }
      ]);

      if($currentMethod === 'HEAD'){
        curl_setopt($ch, CURLOPT_NOBODY, true);
      }elseif($currentMethod === 'POST'){
        curl_setopt($ch, CURLOPT_POST, true);
        if($currentBody !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $currentBody);
      }elseif($currentMethod !== 'GET'){
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $currentMethod);
        if($currentBody !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $currentBody);
      }

      $tmpBody = curl_exec($ch);
      $err = (string)curl_error($ch);
      $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
      $effective = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $currentUrl;
      curl_close($ch);

      if($tmpBody === false && $status === 0){
        $body = false;
        break;
      }
      $body = ($tmpBody === false) ? '' : $tmpBody;

      $location = isset($respHeaders['location'][0]) ? trim((string)$respHeaders['location'][0]) : '';
      if($status >= 300 && $status < 400 && $location !== '' && $hop < 8){
        $next = cibili_ikt_resolve_url($location, $currentUrl);
        $nextScheme = strtolower((string)parse_url($next, PHP_URL_SCHEME));
        $nextHost = strtolower((string)parse_url($next, PHP_URL_HOST));
        if($next === '' || $nextScheme !== 'https' || $nextHost === ''){
          $err = 'Redirect IKT tidak valid.';
          break;
        }
        cibili_ikt_register_host($nextHost);
        $lastReferer = $currentUrl;
        $currentUrl = $next;

        if(in_array($status, [301,302,303], true) && $currentMethod !== 'GET' && $currentMethod !== 'HEAD'){
          $currentMethod = 'GET';
          $currentBody = '';
          $currentContentType = '';
        }
        continue;
      }
      break;
    }
  }else{
    // Fallback hosting tanpa ekstensi cURL: gunakan stream HTTPS dan simpan
    // cookie upstream di session. Redirect ditangani manual agar host login
    // yang sah dapat ikut diproxy tanpa membuka proxy ke host sembarang.
    if(!isset($_SESSION['cibili_ikt_stream_cookies']) || !is_array($_SESSION['cibili_ikt_stream_cookies'])) $_SESSION['cibili_ikt_stream_cookies'] = [];
    $cookies =& $_SESSION['cibili_ikt_stream_cookies'];
    $currentUrl = $targetUrl;
    $currentMethod = $method;
    $currentBody = $rawBody;
    for($hop=0; $hop<9; $hop++){
      $h = ['Accept: ' . $accept, 'User-Agent: ' . $ua];
      if(!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) $h[] = 'Accept-Language: ' . (string)$_SERVER['HTTP_ACCEPT_LANGUAGE'];
      if($currentMethod !== 'GET' && $currentMethod !== 'HEAD' && !empty($_SERVER['CONTENT_TYPE'])) $h[] = 'Content-Type: ' . (string)$_SERVER['CONTENT_TYPE'];
      if(!empty($cookies)){
        $pairs=[]; foreach($cookies as $ck=>$cv){ $pairs[]=$ck.'='.$cv; }
        if($pairs) $h[]='Cookie: '.implode('; ',$pairs);
      }
      $origin = cibili_ikt_origin($currentUrl);
      if($currentMethod !== 'GET' && $currentMethod !== 'HEAD' && $origin !== '') $h[] = 'Origin: ' . $origin;
      if($lastReferer !== '') $h[] = 'Referer: ' . $lastReferer;
      $opts = [
        'http'=>[
          'method'=>$currentMethod,
          'header'=>implode("\r\n",$h)."\r\n",
          'ignore_errors'=>true,
          'follow_location'=>0,
          'timeout'=>60,
        ],
        'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]
      ];
      if($currentMethod !== 'GET' && $currentMethod !== 'HEAD' && $currentBody !== '') $opts['http']['content']=$currentBody;
      $ctx=stream_context_create($opts);
      $http_response_header=[];
      $tmp=@file_get_contents($currentUrl,false,$ctx);
      $headers=is_array($http_response_header)?$http_response_header:[];
      $respHeaders=[]; $status=0; $location=''; $contentType='';
      foreach($headers as $line){
        if(preg_match('~^HTTP/\\S+\\s+(\\d{3})~i',$line,$m)){ $status=(int)$m[1]; continue; }
        $pos=strpos($line,':'); if($pos===false) continue;
        $name=strtolower(trim(substr($line,0,$pos))); $value=trim(substr($line,$pos+1));
        if($name!=='') $respHeaders[$name][]=$value;
        if($name==='location') $location=$value;
        if($name==='content-type') $contentType=$value;
        if($name==='set-cookie'){
          $first=trim(explode(';',$value,2)[0]);
          $eq=strpos($first,'=');
          if($eq!==false){
            $ck=trim(substr($first,0,$eq)); $cv=trim(substr($first,$eq+1));
            if($ck!==''){
              if($cv==='') unset($cookies[$ck]); else $cookies[$ck]=$cv;
            }
          }
        }
      }
      if($tmp===false && $status===0){ $err='Koneksi HTTPS IKT gagal.'; $body=false; break; }
      $body=$tmp===false?'':$tmp;
      $effective=$currentUrl;
      if($status>=300 && $status<400 && $location!=='' && $hop<8){
        $next=cibili_ikt_resolve_url($location,$currentUrl);
        $nextHost=strtolower((string)parse_url($next,PHP_URL_HOST));
        if($next==='' || strtolower((string)parse_url($next,PHP_URL_SCHEME))!=='https' || $nextHost===''){ $err='Redirect IKT tidak valid.'; break; }
        cibili_ikt_register_host($nextHost);
        $lastReferer=$currentUrl;
        $currentUrl=$next;
        if(in_array($status,[301,302,303],true) && $currentMethod!=='GET' && $currentMethod!=='HEAD'){
          $currentMethod='GET'; $currentBody='';
        }
        continue;
      }
      break;
    }
  }

  if($body === false){
    http_response_code(502);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html><body style="font-family:Arial;padding:24px"><b>IKT gagal dimuat.</b><div style="margin-top:8px;color:#64748b">' . htmlspecialchars($err ?: 'Koneksi upstream gagal.', ENT_QUOTES, 'UTF-8') . '</div></body></html>';
    exit;
  }

  $effectiveHost = strtolower((string)parse_url($effective, PHP_URL_HOST));
  if($effectiveHost !== '') cibili_ikt_register_host($effectiveHost);
  $_SESSION['cibili_ikt_last_url'] = $effective ?: $targetUrl;

  if($status > 0) http_response_code($status);
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  if(isset($respHeaders['content-disposition'][0])) header('Content-Disposition: ' . $respHeaders['content-disposition'][0]);

  $baseForRewrite = $effective ?: $targetUrl;
  if($contentType === '' && isset($respHeaders['content-type'][0])) $contentType = $respHeaders['content-type'][0];
  $ctLower = strtolower($contentType);
  if(strpos($ctLower, 'text/html') !== false || stripos((string)$body, '<html') !== false){
    header('Content-Type: text/html; charset=UTF-8');
    echo cibili_ikt_rewrite_html((string)$body, $baseForRewrite);
  }elseif(strpos($ctLower, 'text/css') !== false){
    header('Content-Type: ' . ($contentType ?: 'text/css; charset=UTF-8'));
    echo cibili_ikt_rewrite_css((string)$body, $baseForRewrite);
  }else{
    if($contentType !== '') header('Content-Type: ' . $contentType);
    echo $body;
  }
  exit;
}

/*
 * Menjalankan index terenkripsi. Kunci dan pemeriksaan integritas hanya berada
 * pada sisi server. Payload memakai stream HMAC-SHA256 dan tag autentikasi.
 */
function cibili_b64url_decode($value){
  $value = strtr((string)$value, '-_', '+/');
  $pad = strlen($value) % 4;
  if($pad !== 0) $value .= str_repeat('=', 4 - $pad);
  return base64_decode($value, true);
}

function cibili_payload_xor($data, $key, $nonce){
  $data = (string)$data;
  $out = '';
  $length = strlen($data);
  for($counter = 0, $offset = 0; $offset < $length; $counter++, $offset += 32){
    $block = hash_hmac('sha256', $nonce . pack('N', $counter), $key, true);
    $chunk = substr($data, $offset, 32);
    $out .= $chunk ^ substr($block, 0, strlen($chunk));
  }
  return $out;
}

function cibili_run_encrypted_index($payload){
  $parts = explode('.', (string)$payload, 4);
  if(count($parts) !== 4 || $parts[0] !== 'CIB1'){
    http_response_code(500);
    exit('Berkas antarmuka tidak valid.');
  }

  $key = base64_decode((string)CIBILI_INDEX_KEY_B64, true);
  $nonce = cibili_b64url_decode($parts[1]);
  $ciphertext = cibili_b64url_decode($parts[2]);
  $tag = cibili_b64url_decode($parts[3]);
  if(!is_string($key) || strlen($key) !== 32 || !is_string($nonce) || strlen($nonce) !== 16 || !is_string($ciphertext) || !is_string($tag) || strlen($tag) !== 32){
    http_response_code(500);
    exit('Berkas antarmuka tidak dapat dibaca.');
  }

  $expected = hash_hmac('sha256', "CIB1\0" . $nonce . $ciphertext, $key, true);
  if(!hash_equals($expected, $tag)){
    http_response_code(500);
    exit('Integritas antarmuka gagal diverifikasi.');
  }

  $source = cibili_payload_xor($ciphertext, $key, $nonce);
  if(substr($source, 0, 5) !== '<?php'){
    http_response_code(500);
    exit('Format antarmuka tidak didukung.');
  }

  eval('?>' . $source);
}

/**
 * Menampilkan halaman khusus ketika sesi login berakhir.
 * URL login dibuat relatif agar aman digunakan dari index maupun halaman fitur.
 */
function cibili_render_session_expired($loginUrl = 'index.php'){
  $loginUrl = trim((string)$loginUrl);
  if($loginUrl === '' || preg_match('~^(?:javascript|data):~i', $loginUrl)) $loginUrl = 'index.php';
  // Tidak menampilkan halaman perantara. Sesi yang habis langsung kembali ke
  // login. Kode toko yang tersimpan membuat aplikasi membuka form PIN saja.
  if(!headers_sent()){
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Location: ' . $loginUrl, true, 302);
    exit;
  }
  $target = json_encode($loginUrl, JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
  echo '<script>window.location.replace(' . ($target !== false ? $target : '"index.php"') . ');</script>';
  exit;
}


/* =========================
   INDEX PUBLIC HELPERS: nilai/teks penting disimpan di proxy.php
========================= */
function proxy_app_brand(){
  return 'CIBILI';
}
function proxy_developer_store_id(){
  return defined('ADMIN_STORE_ID') ? (string)ADMIN_STORE_ID : '';
}

function proxy_public_asset_url($key){
  $map = [
    'bootstrap_css' => 'https://hocdnsso0201.sat.co.id/v3/static/css/bootstrap.min.css',
    'style_css' => 'https://hocdnsso0201.sat.co.id/v3/static/css/style.min.css',
    'favicon' => 'https://hocdnsso0201.sat.co.id/v3/static/img/logo_title.png',
    'jszip' => 'https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js',
    'sqljs' => 'https://cdnjs.cloudflare.com/ajax/libs/sql.js/1.10.2/sql-wasm.js',
    'fontawesome_css' => 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css',
  ];
  return (string)($map[(string)$key] ?? '');
}
function proxy_ui_text($key){
  $map = [
    'url_placeholder' => 'https://contoh.com',
    'secret_input_type' => 'password',
    'secret_autocomplete_current' => 'current-password',
    'secret_autocomplete_new' => 'new-password',
    'admin_secret_title' => 'Password Admin',
    'secret_label_lower' => 'password',
    'secret_label' => 'Password',
    'admin_secret_placeholder' => 'Password admin',
    'admin_login_button' => 'Masuk Admin',
    'secret_required' => 'Password wajib diisi.',
    'secret_wrong' => 'Password salah.',
    'profile_cache_key' => 'cibili_profile_avatar_cache_global',
    'developer_store_code' => ADMIN_STORE_ID,
    'developer_admin_password' => '',
  ];
  return (string)($map[(string)$key] ?? '');
}


function proxy_is_developer_session(){
  return function_exists('m604_is_developer_session') ? m604_is_developer_session() : false;
}

/* =========================
   CONFIG
========================= */
define('ONE_DAY_SEC', 86400);
define('STORE_DB_FILE', __DIR__ . '/alfastore_allowed_stores.json');
define('ONHAND_STORAGE_FILE', __DIR__ . '/onhand_saved_lists.json');
define('PLANOGRAM_SAVED_FILE', __DIR__ . '/planogram_saved_raks.json');
define('OH979_CONFIG_FILE', __DIR__ . '/alfastore_979_config.json');
define('OH979_CUSTOM_RAK_DIR', __DIR__ . '/oh979_custom_raks'); // rak custom OH979 disimpan per-user/toko

define('BANNER_META_FILE', __DIR__ . '/alfastore_banner.json');
define('BANNER_DIR', __DIR__ . '/banner_uploads');
define('ALERT_META_FILE', __DIR__ . '/alfastore_alert.json');
define('NOTIF_META_FILE', __DIR__ . '/alfastore_notifications.json');
define('DEVELOPER_NOTIF_TARGET', 'DEVELOPER'); // kanal notifikasi internal khusus sesi developer, terpisah dari user M604
define('HOME_INFO_FILE', __DIR__ . '/alfastore_home_info.json');
define('M604_SERVER_STATUS_FILE', __DIR__ . '/alfastore_m604_server_status.json'); // switch gangguan server khusus user M604
define('ADMIN_CREDENTIALS_FILE', __DIR__ . '/alfastore_admin_credentials.json'); // PIN developer, sandi admin, dan PIN laporan

define('EXPIRY_FILE', __DIR__ . '/alfastore_expiry.json'); // per-user expiry (JSON file, tanpa DB)
define('EXPIRY_HISTORY_FILE', __DIR__ . '/alfastore_expiry_history.json'); // riwayat semua penambahan masa aktif
define('PIN_FILE', __DIR__ . '/alfastore_pins.json'); // per-user pin 4 digit (JSON file, tanpa DB)
define('PREMIUM_FILE', __DIR__ . '/alfastore_premium.json'); // per-user premium access (JSON file, tanpa DB)
define('ADMIN2_FILE', __DIR__ . '/alfastore_admin2.json'); // per-user admin2 access (JSON file, tanpa DB)
define('CHAT_STORAGE_FILE', __DIR__ . '/alfastore_chat.json');
define('POPUP_ORDER_FILE', __DIR__ . '/popup_order_global.json'); // group chat global antar user
define('PRESENCE_FILE', __DIR__ . '/alfastore_presence.json'); // online/offline + login realtime
define('TOP_ONLINE_FILE', __DIR__ . '/alfastore_top_online_monthly.json'); // ranking bulanan sering online
define('QRISPY_API_URL', 'https://api.qrispy.id');
define('QRISPY_API_TOKEN', 'cki_eRExmygLqeuA4xWSAqtniz7lcHex5zy28ZJGiIhUXuDKr6Uk');
if(!defined('INVITE_HISTORY_TTL_SECONDS')) define('INVITE_HISTORY_TTL_SECONDS', 172800); // 2x24 jam
define('REGISTRATION_AMOUNT', 50000);
define('QRIS_SETTINGS_FILE', __DIR__ . '/alfastore_qris_settings.json');
define('QRIS_PAYMENT_CACHE_FILE', __DIR__ . '/alfastore_qris_payment_cache.json');
define('QRIS_APPLY_LOG_FILE', __DIR__ . '/alfastore_qris_apply_log.json');
define('PROMO_FILE', __DIR__ . '/alfastore_promo_codes.json');
define('UI_CONFIG_FILE', __DIR__ . '/alfastore_ui_config.json');
define('MANUAL_REGISTRATION_FILE', __DIR__ . '/alfastore_registration_requests.json'); // permintaan daftar/perpanjang dari daftar.html
define('MANUAL_REGISTRATION_SETTINGS_FILE', __DIR__ . '/alfastore_registration_settings.json'); // switch promo 2 bulan

define('SOGRAND_KEY_FILE', __DIR__ . '/alfastore_sogrand_keys.json'); // riwayat key SO Grand
define('SOGRAND_USER_FILE', __DIR__ . '/alfastore_sogrand_users.json'); // user khusus Key Grand, terpisah dari user admin
define('NEWUSER_KEY_FILE', __DIR__ . '/alfastore_newuser_keys.json'); // riwayat key NEW untuk tambah user otomatis
define('INVITE_POINTS_FILE', __DIR__ . '/alfastore_invite_points.json'); // point undang teman: relasi undangan + reward 1x
define('NEWUSER_KEY_TTL_SEC', 172800); // default lama: 2 hari
define('NEWUSER_KEY_OPTION_1D_SEC', ONE_DAY_SEC);
define('NEWUSER_KEY_OPTION_2D_SEC', 2 * ONE_DAY_SEC);
define('NEWUSER_KEY_OPTION_3D_SEC', 3 * ONE_DAY_SEC);
define('NEWUSER_KEY_OPTION_1M_SEC', 30 * ONE_DAY_SEC);
define('NEWUSER_KEY_OPTION_2M_SEC', 60 * ONE_DAY_SEC);
define('SOGRAND_KEY_TTL_SEC', 172800); // 2 hari
define('STORE_NAME_CACHE_FILE', __DIR__ . '/alfastore_store_name_cache.json'); // cache nama toko supaya admin cepat

define('ONLINE_WINDOW_SEC', 45);

/* =========================
   M604 SERVER MAINTENANCE SWITCH
   - Developer M604 tetap normal.
   - User M604 (PIN 0000 / non-developer) dikunci real-time.
========================= */
function m604_server_status_default(){
  return [
    'enabled' => false,
    'updatedAt' => null,
    'updatedTs' => 0,
    'updatedBy' => '',
  ];
}
function m604_server_status_read(){
  $default = m604_server_status_default();
  if(!defined('M604_SERVER_STATUS_FILE') || !is_file(M604_SERVER_STATUS_FILE)) return $default;
  $raw = @file_get_contents(M604_SERVER_STATUS_FILE);
  $data = json_decode((string)$raw, true);
  if(!is_array($data)) return $default;
  return [
    'enabled' => !empty($data['enabled']),
    'updatedAt' => !empty($data['updatedAt']) ? (string)$data['updatedAt'] : null,
    'updatedTs' => max(0, (int)($data['updatedTs'] ?? 0)),
    'updatedBy' => strtoupper(substr(preg_replace('/[^A-Z0-9]/','',(string)($data['updatedBy'] ?? '')),0,10)),
  ];
}
function m604_server_status_write($enabled, $actor=''){
  $enabled = (bool)$enabled;
  $actor = strtoupper(substr(preg_replace('/[^A-Z0-9]/','',(string)$actor),0,10));
  $row = [
    'enabled' => $enabled,
    'updatedAt' => date('c'),
    'updatedTs' => time(),
    'updatedBy' => $actor,
  ];
  $dir = dirname(M604_SERVER_STATUS_FILE);
  if(!is_dir($dir)) @mkdir($dir, 0755, true);
  $fp = @fopen(M604_SERVER_STATUS_FILE, 'c+');
  if(!$fp) return [false, m604_server_status_read()];
  $ok = false;
  if(@flock($fp, LOCK_EX)){
    rewind($fp);
    ftruncate($fp, 0);
    $json = json_encode($row, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $ok = ($json !== false && fwrite($fp, $json) !== false);
    fflush($fp);
    @flock($fp, LOCK_UN);
  }
  fclose($fp);
  return [$ok, $ok ? $row : m604_server_status_read()];
}
function m604_server_access_code(){
  return '7SZU2_' . date('Ymd') . '_B2C_6L7C9';
}
function m604_server_message(){
  return 'Server sedang bermasalah, silahkan input AHO dengan menyertakan kode berikut : ' . m604_server_access_code();
}
function m604_server_status_payload(){
  $row = m604_server_status_read();
  return [
    'ok' => true,
    'enabled' => !empty($row['enabled']),
    'code' => m604_server_access_code(),
    'message' => m604_server_message(),
    'updatedAt' => $row['updatedAt'],
    'updatedTs' => (int)$row['updatedTs'],
    'updatedBy' => (string)$row['updatedBy'],
    'serverTs' => time(),
  ];
}
function m604_server_block_applies($storeId=null){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','',(string)($storeId ?? ($_SESSION['storeId'] ?? ''))));
  if($storeId !== ADMIN_STORE_ID) return false;
  if(function_exists('m604_is_developer_session') && m604_is_developer_session()) return false;
  $row = m604_server_status_read();
  return !empty($row['enabled']);
}
function m604_server_render_locked_page($status=null){
  if(!is_array($status)) $status = m604_server_status_payload();
  $message = htmlspecialchars((string)($status['message'] ?? m604_server_message()), ENT_QUOTES, 'UTF-8');
  $code = htmlspecialchars((string)($status['code'] ?? m604_server_access_code()), ENT_QUOTES, 'UTF-8');
  $self = (string)($_SERVER['PHP_SELF'] ?? 'index.php');
  $apiUrl = json_encode($self, JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
  if(!headers_sent()){
    http_response_code(503);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Retry-After: 2');
  }
  echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>Server Bermasalah</title><style>
  *{box-sizing:border-box}html,body{margin:0;min-height:100%;font-family:Arial,Helvetica,sans-serif;background:linear-gradient(145deg,#eff6ff,#f8fafc);color:#0f172a}
  body{min-height:100vh;display:grid;place-items:center;padding:18px}.card{width:min(560px,100%);background:#fff;border:1px solid #bfdbfe;border-radius:22px;padding:24px;box-shadow:0 24px 70px rgba(15,23,42,.15);text-align:center}
  .icon{width:72px;height:72px;margin:0 auto 14px;border-radius:999px;display:grid;place-items:center;background:#dbeafe;color:#1d4ed8;font-size:34px;font-weight:1000}
  h1{font-size:24px;margin:0 0 10px}.msg{font-size:15px;line-height:1.65;font-weight:800;color:#334155}.code{display:block;margin:16px 0 8px;padding:14px 12px;border-radius:14px;background:#ecfdf5;border:1px solid #86efac;color:#166534;font:1000 17px/1.35 ui-monospace,SFMono-Regular,Menlo,monospace;word-break:break-all}
  .small{font-size:12px;color:#64748b;font-weight:700}.actions{display:flex;gap:8px;margin-top:16px}.actions button{flex:1;border:0;border-radius:12px;padding:11px 12px;font-weight:900;cursor:pointer}.refresh{background:#2563eb;color:#fff}.logout{background:#fee2e2;color:#991b1b}
  </style></head><body><main class="card"><div class="icon">!</div><h1>Server sedang bermasalah</h1><div class="msg">'.$message.'</div><code id="ahoCode" class="code">'.$code.'</code><div class="actions"><button class="refresh" onclick="backHome()">Kembali ke Menu</button><button class="logout" onclick="logoutNow()">Keluar</button></div></main><script>
  const API='.($apiUrl!==false?$apiUrl:'"index.php"').';
  async function checkStatus(force){try{const r=await fetch(API+"?api=m604_server_status&_="+Date.now(),{cache:"no-store",credentials:"same-origin"});const j=await r.json();if(j&&j.code){document.getElementById("ahoCode").textContent=j.code}if(j&&!j.enabled){location.replace(API);}}catch(e){if(force)location.reload();}}
  function backHome(){location.replace(API);} async function logoutNow(){try{await fetch(API+"?api=logout",{method:"POST",credentials:"same-origin"});}catch(e){}location.replace(API);}
  setInterval(checkStatus,1200);document.addEventListener("visibilitychange",()=>{if(!document.hidden)checkStatus(false)});</script></body></html>';
  exit;
}


function sogrand_key_clean($key){
  return strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$key));
}
function sogrand_key_random($len=12){
  $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
  $out = 'GRAND';
  $max = strlen($chars) - 1;
  for($i=0; $i<$len; $i++){
    if(function_exists('random_int')) $out .= $chars[random_int(0, $max)];
    else $out .= $chars[mt_rand(0, $max)];
  }
  return $out;
}
function sogrand_keys_read_all(){
  if(!file_exists(SOGRAND_KEY_FILE)) return ['items'=>[], 'updatedAt'=>null];
  $j = json_decode(@file_get_contents(SOGRAND_KEY_FILE), true);
  if(!is_array($j) || !is_array($j['items'] ?? null)) return ['items'=>[], 'updatedAt'=>null];
  return $j;
}
function sogrand_keys_write_all($items){
  $clean = [];
  foreach((array)$items as $k=>$row){
    $key = sogrand_key_clean($k);
    if($key==='') $key = sogrand_key_clean($row['key'] ?? '');
    if($key==='') continue;
    $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)($row['storeId'] ?? '')));
    $createdTs = (int)($row['createdTs'] ?? time());
    $expiresTs = (int)($row['expiresTs'] ?? ($createdTs + SOGRAND_KEY_TTL_SEC));
    $clean[$key] = [
      'key'=>$key,
      'storeId'=>$storeId,
      'createdTs'=>$createdTs,
      'expiresTs'=>$expiresTs,
      'used'=>(!empty($row['used']) || $storeId !== ''),
      'usedTs'=>(int)($row['usedTs'] ?? ((!empty($row['used']) || $storeId !== '') ? time() : 0)),
      'boundTs'=>(int)($row['boundTs'] ?? ($storeId !== '' ? (int)($row['usedTs'] ?? time()) : 0)),
      'duration'=>(string)($row['duration'] ?? ''),
      'durationLabel'=>(string)($row['durationLabel'] ?? ''),
      'durationDays'=>(int)($row['durationDays'] ?? 0),
    ];
  }
  @file_put_contents(SOGRAND_KEY_FILE, json_encode(['items'=>$clean,'updatedAt'=>date('c')], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
  return ['items'=>$clean,'updatedAt'=>date('c')];
}
function sogrand_keys_cleanup(){
  // Key expired tetap disimpan sebagai riwayat agar tidak dapat dipakai ulang / dibuat ulang.
  return sogrand_keys_read_all();
}
function sogrand_key_generate(){
  $all = sogrand_keys_cleanup();
  $items = is_array($all['items'] ?? null) ? $all['items'] : [];
  do { $key = sogrand_key_random(12); } while(isset($items[$key]));
  $now = time();
  $row = ['key'=>$key,'storeId'=>'','createdTs'=>$now,'expiresTs'=>$now+SOGRAND_KEY_TTL_SEC,'used'=>false,'usedTs'=>0,'boundTs'=>0];
  $items[$key] = $row;
  sogrand_keys_write_all($items);
  return $row;
}
function sogrand_key_generate_for_store($storeId){
  // Kompatibilitas lama: sekarang key dibuat tanpa binding toko.
  return sogrand_key_generate();
}
function sogrand_key_use($storeId, $key){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  $key = sogrand_key_clean($key);
  if($storeId==='' || $key==='') return [false, 'Kode toko / key kosong', null];
  $all = sogrand_keys_cleanup();
  $items = is_array($all['items'] ?? null) ? $all['items'] : [];
  $row = is_array($items[$key] ?? null) ? $items[$key] : null;
  if(!$row) return [false, 'Key tidak ditemukan atau sudah expired', null];
  if((int)($row['expiresTs'] ?? 0) <= time()) return [false, 'Key sudah expired', null];
  $boundStore = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)($row['storeId'] ?? '')));
  if(!empty($row['used']) || $boundStore !== '') return [false, 'Key sudah pernah dipakai dan hanya berlaku sekali', null];
  $row['storeId'] = $storeId;
  $row['used'] = true;
  $row['usedTs'] = time();
  $row['boundTs'] = time();
  $items[$key] = $row;
  sogrand_keys_write_all($items);
  return [true, 'OK', $row];
}


function sogrand_users_read_all(){
  $empty = ['users'=>[], 'updatedAt'=>null];
  if(!defined('SOGRAND_USER_FILE')) return $empty;
  if(!file_exists(SOGRAND_USER_FILE)) return $empty;
  $raw = @file_get_contents(SOGRAND_USER_FILE);
  $j = json_decode($raw, true);
  if(!is_array($j)) return $empty;
  $users = [];
  if(is_array($j['users'] ?? null)){
    foreach($j['users'] as $k=>$row){
      if(!is_array($row)) continue;
      $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)($row['storeId'] ?? $k)));
      if($storeId==='') continue;
      $users[$storeId] = [
        'storeId'=>$storeId,
        'pin'=>(string)($row['pin'] ?? SOGRAND_PIN),
        'key'=>(string)($row['key'] ?? ''),
        'createdTs'=>(int)($row['createdTs'] ?? time()),
        'expiresTs'=>(int)($row['expiresTs'] ?? 0),
        'lastLoginTs'=>(int)($row['lastLoginTs'] ?? 0),
      ];
    }
  }
  return ['users'=>$users, 'updatedAt'=>(string)($j['updatedAt'] ?? null)];
}
function sogrand_users_write_all($users){
  $clean = [];
  foreach((array)$users as $k=>$row){
    if(!is_array($row)) continue;
    $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)($row['storeId'] ?? $k)));
    if($storeId==='') continue;
    $clean[$storeId] = [
      'storeId'=>$storeId,
      'pin'=>SOGRAND_PIN,
      'key'=>(string)($row['key'] ?? ''),
      'createdTs'=>(int)($row['createdTs'] ?? time()),
      'expiresTs'=>(int)($row['expiresTs'] ?? 0),
      'lastLoginTs'=>(int)($row['lastLoginTs'] ?? 0),
    ];
  }
  $payload = ['users'=>$clean, 'updatedAt'=>date('c')];
  @file_put_contents(SOGRAND_USER_FILE, json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
  return $payload;
}
function sogrand_users_cleanup(){
  $all = sogrand_users_read_all();
  $users = is_array($all['users'] ?? null) ? $all['users'] : [];
  $now = time(); $changed = false;
  foreach($users as $storeId=>$row){
    $exp = (int)($row['expiresTs'] ?? 0);
    if($exp > 0 && $exp <= $now){ unset($users[$storeId]); $changed = true; }
  }
  return $changed ? sogrand_users_write_all($users) : $all;
}
function sogrand_cleanup_all(){
  // Bersihkan hanya JSON Key Grand: file riwayat key dan file user Key Grand.
  // Tidak menyentuh JSON user/admin biasa.
  sogrand_keys_cleanup();
  return sogrand_users_cleanup();
}
function sogrand_user_get($storeId){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  if($storeId==='') return null;
  $all = sogrand_users_cleanup();
  $users = is_array($all['users'] ?? null) ? $all['users'] : [];
  $row = is_array($users[$storeId] ?? null) ? $users[$storeId] : null;
  if(!$row) return null;
  $exp = (int)($row['expiresTs'] ?? 0);
  if($exp > 0 && $exp <= time()) return null;
  return $row;
}
function sogrand_user_set($storeId, $key, $expiresTs){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  if($storeId==='') return null;
  $all = sogrand_users_cleanup();
  $users = is_array($all['users'] ?? null) ? $all['users'] : [];
  $old = is_array($users[$storeId] ?? null) ? $users[$storeId] : [];
  $now = time();
  $users[$storeId] = [
    'storeId'=>$storeId,
    'pin'=>SOGRAND_PIN,
    'key'=>sogrand_key_clean($key),
    'createdTs'=>(int)($old['createdTs'] ?? $now),
    'expiresTs'=>(int)$expiresTs,
    'lastLoginTs'=>$now,
  ];
  sogrand_users_write_all($users);
  return $users[$storeId];
}
function sogrand_user_touch($storeId){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  if($storeId==='') return false;
  $all = sogrand_users_cleanup();
  $users = is_array($all['users'] ?? null) ? $all['users'] : [];
  if(!is_array($users[$storeId] ?? null)) return false;
  $users[$storeId]['lastLoginTs'] = time();
  sogrand_users_write_all($users);
  return true;
}
function sogrand_user_remaining($storeId){
  $u = sogrand_user_get($storeId);
  if(!$u) return 0;
  $exp = (int)($u['expiresTs'] ?? 0);
  return max(0, $exp - time());
}


function newuser_key_clean($key){
  return strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$key));
}
function newuser_key_random($len=12){
  $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
  $out = 'NEW';
  $max = strlen($chars) - 1;
  for($i=0; $i<$len; $i++){
    if(function_exists('random_int')) $out .= $chars[random_int(0, $max)];
    else $out .= $chars[mt_rand(0, $max)];
  }
  return $out;
}
function newuser_key_duration_options(){
  return [
    '1d' => ['label'=>'1 Hari', 'seconds'=>NEWUSER_KEY_OPTION_1D_SEC, 'days'=>1],
    '2d' => ['label'=>'2 Hari', 'seconds'=>NEWUSER_KEY_OPTION_2D_SEC, 'days'=>2],
    '3d' => ['label'=>'3 Hari', 'seconds'=>NEWUSER_KEY_OPTION_3D_SEC, 'days'=>3],
    '1m' => ['label'=>'1 Bulan', 'seconds'=>NEWUSER_KEY_OPTION_1M_SEC, 'days'=>30],
    '2m' => ['label'=>'2 Bulan', 'seconds'=>NEWUSER_KEY_OPTION_2M_SEC, 'days'=>60],
  ];
}
function newuser_key_duration_row($duration){
  $duration = strtolower(trim((string)$duration));
  $opts = newuser_key_duration_options();
  return $opts[$duration] ?? $opts['2d'];
}
function newuser_keys_read_all(){
  if(!defined('NEWUSER_KEY_FILE') || !file_exists(NEWUSER_KEY_FILE)) return ['items'=>[], 'updatedAt'=>null];
  $j = json_decode(@file_get_contents(NEWUSER_KEY_FILE), true);
  if(!is_array($j) || !is_array($j['items'] ?? null)) return ['items'=>[], 'updatedAt'=>null];
  return $j;
}
function newuser_keys_write_all($items){
  $clean = [];
  foreach((array)$items as $k=>$row){
    $key = newuser_key_clean($k);
    if($key==='') $key = newuser_key_clean($row['key'] ?? '');
    if($key==='' || strpos($key, 'NEW') !== 0) continue;
    $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)($row['storeId'] ?? '')));
    $createdTs = (int)($row['createdTs'] ?? time());
    $expiresTs = (int)($row['expiresTs'] ?? ($createdTs + NEWUSER_KEY_TTL_SEC));
    $clean[$key] = [
      'key'=>$key,
      'storeId'=>$storeId,
      'createdTs'=>$createdTs,
      'expiresTs'=>$expiresTs,
      'used'=>(!empty($row['used']) || $storeId !== ''),
      'usedTs'=>(int)($row['usedTs'] ?? ((!empty($row['used']) || $storeId !== '') ? time() : 0)),
      'boundTs'=>(int)($row['boundTs'] ?? ($storeId !== '' ? (int)($row['usedTs'] ?? time()) : 0)),
    ];
  }
  $payload = ['items'=>$clean,'updatedAt'=>date('c')];
  @file_put_contents(NEWUSER_KEY_FILE, json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
  return $payload;
}
function newuser_keys_cleanup(){
  // Key expired tetap disimpan sebagai riwayat agar tidak dapat dipakai ulang / dibuat ulang.
  return newuser_keys_read_all();
}
function newuser_key_generate($duration='2d'){
  $opt = newuser_key_duration_row($duration);
  $all = newuser_keys_cleanup();
  $items = is_array($all['items'] ?? null) ? $all['items'] : [];
  do { $key = newuser_key_random(12); } while(isset($items[$key]));
  $now = time();
  $row = [
    'key'=>$key,
    'storeId'=>'',
    'createdTs'=>$now,
    'expiresTs'=>$now + (int)$opt['seconds'],
    'used'=>false,
    'usedTs'=>0,
    'boundTs'=>0,
    'duration'=>(string)$duration,
    'durationLabel'=>(string)$opt['label'],
    'durationDays'=>(int)$opt['days'],
  ];
  $items[$key] = $row;
  newuser_keys_write_all($items);
  return $row;
}
function newuser_key_use($storeId, $key){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  $key = newuser_key_clean($key);
  if($storeId==='' || $key==='') return [false, 'Kode toko / key kosong', null];
  if(strpos($key, 'NEW') !== 0) return [false, 'Key NEW tidak valid', null];
  $all = newuser_keys_cleanup();
  $items = is_array($all['items'] ?? null) ? $all['items'] : [];
  $row = is_array($items[$key] ?? null) ? $items[$key] : null;
  if(!$row) return [false, 'Key NEW tidak ditemukan atau sudah expired', null];
  if((int)($row['expiresTs'] ?? 0) <= time()) return [false, 'Key NEW sudah expired', null];
  $boundStore = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)($row['storeId'] ?? '')));
  if(!empty($row['used']) || $boundStore !== '') return [false, 'Key NEW sudah pernah dipakai dan hanya berlaku sekali', null];
  $row['storeId'] = $storeId;
  $row['used'] = true;
  $row['usedTs'] = time();
  $row['boundTs'] = time();
  $items[$key] = $row;
  newuser_keys_write_all($items);
  return [true, 'OK', $row];
}

function qris_settings_read(){
  if(!file_exists(QRIS_SETTINGS_FILE)){
    $init = ["registration_amount" => REGISTRATION_AMOUNT, "updatedAt" => date('c')];
    @file_put_contents(QRIS_SETTINGS_FILE, json_encode($init, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    return $init;
  }
  $j = json_decode(@file_get_contents(QRIS_SETTINGS_FILE), true);
  if(!is_array($j)) $j = [];
  $amount = (int)($j['registration_amount'] ?? REGISTRATION_AMOUNT);
  if($amount <= 0) $amount = REGISTRATION_AMOUNT;
  return ["registration_amount" => $amount, "updatedAt" => (string)($j['updatedAt'] ?? null)];
}
function qris_settings_write_amount($amount){
  $amount = (int)$amount;
  if($amount <= 0) $amount = REGISTRATION_AMOUNT;
  $payload = ["registration_amount" => $amount, "updatedAt" => date('c')];
  @file_put_contents(QRIS_SETTINGS_FILE, json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
  return $payload;
}
function qris_registration_amount(){
  $cfg = qris_settings_read();
  return (int)($cfg['registration_amount'] ?? REGISTRATION_AMOUNT);
}

function ui_config_defaults(){
  return ['show_register_button' => true, 'updatedAt' => null];
}
function ui_config_read(){
  $defaults = ui_config_defaults();
  if(!file_exists(UI_CONFIG_FILE)){
    @file_put_contents(UI_CONFIG_FILE, json_encode(['show_register_button'=>$defaults['show_register_button'],'updatedAt'=>date('c')], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    $defaults['updatedAt'] = date('c');
    return $defaults;
  }
  $j = json_decode(@file_get_contents(UI_CONFIG_FILE), true);
  if(!is_array($j)) return $defaults;
  return [
    'show_register_button' => array_key_exists('show_register_button', $j) ? (bool)$j['show_register_button'] : true,
    'updatedAt' => (string)($j['updatedAt'] ?? null),
  ];
}
function ui_config_write($updates){
  $current = ui_config_read();
  $payload = [
    'show_register_button' => array_key_exists('show_register_button', (array)$updates) ? (bool)$updates['show_register_button'] : (bool)$current['show_register_button'],
    'updatedAt' => date('c'),
  ];
  @file_put_contents(UI_CONFIG_FILE, json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
  return $payload;
}

function store_name_cache_read(){
  if(!file_exists(STORE_NAME_CACHE_FILE)) return ["stores"=>[], "updatedAt"=>null];
  $j = json_decode(@file_get_contents(STORE_NAME_CACHE_FILE), true);
  if(!is_array($j)) return ["stores"=>[], "updatedAt"=>null];
  if(!isset($j['stores']) || !is_array($j['stores'])) $j['stores'] = [];
  return $j;
}
function store_name_cache_write($map){
  $clean = [];
  foreach((array)$map as $sid=>$row){
    $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$sid));
    if($storeId==='') continue;
    $clean[$storeId] = [
      'header2'=>(string)($row['header2'] ?? ''),
      'header5'=>(string)($row['header5'] ?? ''),
      'city'=>(string)($row['city'] ?? ''),
      'dcId'=>(string)($row['dcId'] ?? ''),
      'ts'=>(int)($row['ts'] ?? time()),
    ];
  }
  @file_put_contents(STORE_NAME_CACHE_FILE, json_encode(['stores'=>$clean,'updatedAt'=>date('c')], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
  return $clean;
}
function store_detail_fetch_cached($storeId, $maxAgeSec=86400){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  if($storeId==='') return null;
  $cache = store_name_cache_read();
  $stores = $cache['stores'] ?? [];
  $row = is_array($stores[$storeId] ?? null) ? $stores[$storeId] : null;
  $now = time();
  if($row && !empty($row['header2']) && ($now - (int)($row['ts'] ?? 0)) <= $maxAgeSec){
    return ['storeId'=>$storeId,'header2'=>(string)($row['header2'] ?? ''),'header5'=>(string)($row['header5'] ?? ''),'city'=>(string)($row['city'] ?? ''),'dcId'=>(string)($row['dcId'] ?? ''),'cached'=>true];
  }
  $url = 'https://app.alfastore.co.id/prd/api/sis/master/status_toko/?storeId=' . urlencode($storeId);
  $raw = null;
  if(function_exists('curl_init')){
    $ch = curl_init();
    curl_setopt_array($ch, [CURLOPT_URL=>$url,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>4,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Accept: application/json','User-Agent: ALFASTORE/1.0']]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if($raw === false || $code >= 400) $raw = null;
  }else{
    $ctx = stream_context_create(['http'=>['method'=>'GET','timeout'=>4,'header'=>"Accept: application/json\r\nUser-Agent: ALFASTORE/1.0\r\n"]]);
    $raw = @file_get_contents($url, false, $ctx);
    if($raw === false) $raw = null;
  }
  $j = $raw ? json_decode((string)$raw, true) : null;
  if(is_array($j)){
    $out = ['storeId'=>$storeId,'header2'=>(string)($j['header2'] ?? ''),'header5'=>(string)($j['header5'] ?? ''),'city'=>(string)($j['city'] ?? ''),'dcId'=>(string)($j['dcId'] ?? ''),'cached'=>false];
    $stores[$storeId] = $out + ['ts'=>$now];
    store_name_cache_write($stores);
    return $out;
  }
  if($row){ return ['storeId'=>$storeId,'header2'=>(string)($row['header2'] ?? ''),'header5'=>(string)($row['header5'] ?? ''),'city'=>(string)($row['city'] ?? ''),'dcId'=>(string)($row['dcId'] ?? ''),'cached'=>true]; }
  return null;
}
function store_names_map_for_admin($stores){
  $cache = store_name_cache_read();
  $src = $cache['stores'] ?? [];
  $out = [];
  foreach((array)$stores as $sid){
    $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$sid));
    if($storeId==='') continue;
    $row = is_array($src[$storeId] ?? null) ? $src[$storeId] : null;
    $out[$storeId] = ($row && !empty($row['header2'])) ? (string)$row['header2'] : '-';
  }
  return $out;
}

function store_names_fetch_batch($stores, $maxAgeSec=86400){
  $ids = [];
  foreach((array)$stores as $sid){
    $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$sid));
    if($storeId!=='' && !in_array($storeId,$ids,true)) $ids[] = $storeId;
  }
  $ids = array_slice($ids, 0, 300);
  $cache = store_name_cache_read();
  $src = is_array($cache['stores'] ?? null) ? $cache['stores'] : [];
  $now = time(); $out = []; $missing = [];
  foreach($ids as $id){
    $row = is_array($src[$id] ?? null) ? $src[$id] : null;
    if($row && !empty($row['header2']) && ($now - (int)($row['ts'] ?? 0)) <= $maxAgeSec){ $out[$id]=(string)$row['header2']; }
    else $missing[] = $id;
  }
  if($missing && function_exists('curl_multi_init')){
    $mh = curl_multi_init(); $chs=[];
    foreach($missing as $id){
      $ch=curl_init('https://app.alfastore.co.id/prd/api/sis/master/status_toko/?storeId='.urlencode($id));
      curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>5,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Accept: application/json','User-Agent: ALFASTORE/1.0']]);
      curl_multi_add_handle($mh,$ch); $chs[$id]=$ch;
    }
    $running=null; do{ curl_multi_exec($mh,$running); curl_multi_select($mh,0.2); }while($running>0);
    foreach($chs as $id=>$ch){
      $raw=curl_multi_getcontent($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
      if($raw!==false && $code>=200 && $code<300){
        $j=json_decode((string)$raw,true); if(is_array($j)){
          $h=(string)($j['header2'] ?? ($j[0]['header2'] ?? ($j['data']['header2'] ?? '')));
          if($h!==''){ $out[$id]=$h; $src[$id]=['header2'=>$h,'header5'=>(string)($j['header5'] ?? ''),'city'=>(string)($j['city'] ?? ''),'dcId'=>(string)($j['dcId'] ?? ''),'ts'=>$now]; }
        }
      }
      curl_multi_remove_handle($mh,$ch); curl_close($ch);
    }
    curl_multi_close($mh); store_name_cache_write($src);
  }
  foreach($ids as $id){ if(!isset($out[$id])) $out[$id] = (string)(($src[$id]['header2'] ?? '') ?: '-'); }
  return $out;
}

function chat_purge_storage(){
  @file_put_contents(CHAT_STORAGE_FILE, json_encode(['messages'=>[], 'updatedAt'=>date('c')], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
  return true;
}


function promo_read_all(){
  if(!file_exists(PROMO_FILE)) return ["items"=>[],"updatedAt"=>null];
  $j = json_decode(@file_get_contents(PROMO_FILE), true);
  if(!is_array($j)) return ["items"=>[],"updatedAt"=>null];
  if(!isset($j['items']) || !is_array($j['items'])) $j['items'] = [];
  return $j;
}
function promo_write_all($items){
  $clean = [];
  foreach((array)$items as $code=>$row){
    $code = strtoupper(preg_replace('/[^A-Z0-9_-]/','', (string)$code));
    if($code==='') continue;
    $type = strtolower((string)($row['type'] ?? 'fixed'));
    if(!in_array($type, ['active30_once','fixed','free3d_once','free3d_multi','free3d'], true)) $type = 'active30_once';
    if($type === 'free3d') $type = 'free3d_once';
    if($type === 'fixed') $type = 'active30_once';
    $value = (int)($row['value'] ?? 0);
    if($value <= 0) continue;
    $usedCount = max(0, (int)($row['used_count'] ?? 0));
    $reservedBy = trim((string)($row['reserved_qris_id'] ?? ''));
    $reservedAt = trim((string)($row['reserved_at'] ?? ''));
    if($reservedBy !== '' && $reservedAt !== ''){
      $ts = strtotime($reservedAt);
      if($ts && (time() - $ts) > 1200) { $reservedBy=''; $reservedAt=''; }
    }
    $clean[$code] = [
      'code'=>$code,'type'=>$type,'value'=>$value,'used_count'=>$usedCount,
      'reserved_qris_id'=>$reservedBy,'reserved_at'=>$reservedAt,
      'created_at'=>(string)($row['created_at'] ?? date('c')),'used_at'=>(string)($row['used_at'] ?? '')
    ];
  }
  ksort($clean, SORT_NATURAL|SORT_FLAG_CASE);
  $payload = ['items'=>$clean,'updatedAt'=>date('c')];
  @file_put_contents(PROMO_FILE, json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
  return $payload;
}
function promo_admin_create($code, $type, $value){
  $code = strtoupper(preg_replace('/[^A-Z0-9_-]/','', (string)$code));
  $typeRaw = strtolower((string)$type);
  $type = in_array($typeRaw, ['active30_once','fixed','free3d_once','free3d_multi','free3d'], true) ? $typeRaw : 'active30_once';
  $value = (int)$value;
  if($code==='') return ['ok'=>false,'msg'=>'Kode promo kosong'];
  if($type === 'free3d') $type = 'free3d_once';
  if($type === 'fixed') $type = 'active30_once';
  if($type === 'free3d_once' || $type === 'free3d_multi') $value = 3;
  if($type === 'active30_once') $value = 30;
  if($value<=0) return ['ok'=>false,'msg'=>'Nilai promo harus lebih dari 0'];
  $all = promo_read_all();
  $items = $all['items'] ?? [];
  if(isset($items[$code]) && (int)($items[$code]['used_count'] ?? 0) > 0) return ['ok'=>false,'msg'=>'Kode promo sudah pernah dipakai'];
  $items[$code] = ['code'=>$code,'type'=>$type,'value'=>$value,'used_count'=>0,'reserved_qris_id'=>'','reserved_at'=>'','created_at'=>date('c'),'used_at'=>''];
  promo_write_all($items);
  return ['ok'=>true,'code'=>$code];
}
function promo_compute_discount($baseAmount, $promo){
  $base = max(0, (int)$baseAmount);
  if($base <= 0 || !is_array($promo)) return 0;
  $type = strtolower((string)($promo['type'] ?? 'active30_once'));
  if($type === 'fixed') $type = 'active30_once';
  if($type === 'active30_once' || $type === 'free3d' || $type === 'free3d_once' || $type === 'free3d_multi') return $base;
  $value = (int)($promo['value'] ?? 0);
  if($value <= 0) return 0;
  return min($base, $value);
}
function promo_validate_code($code, $baseAmount, $currentQrisId=''){
  $code = strtoupper(preg_replace('/[^A-Z0-9_-]/','', (string)$code));
  if($code==='') return ['ok'=>false,'msg'=>'Kode promo kosong'];
  $all = promo_read_all();
  $items = $all['items'] ?? [];
  if(!isset($items[$code])) return ['ok'=>false,'msg'=>'Kode promo tidak ditemukan'];
  $item = $items[$code];
  if((int)($item['used_count'] ?? 0) > 0) return ['ok'=>false,'msg'=>'Kode promo sudah dipakai'];
  $reservedBy = trim((string)($item['reserved_qris_id'] ?? ''));
  if($reservedBy !== '' && $reservedBy !== (string)$currentQrisId) return ['ok'=>false,'msg'=>'Kode promo sedang dipakai transaksi lain'];
  $discount = promo_compute_discount($baseAmount, $item);
  $type = strtolower((string)($item['type'] ?? 'active30_once'));
  if($type === 'free3d') $type = 'free3d_once';
  if($type === 'fixed') $type = 'active30_once';
  if($discount <= 0 && !in_array($type, ['active30_once','free3d_once','free3d_multi'], true)) return ['ok'=>false,'msg'=>'Promo tidak valid'];
  $bypassTypes = ['active30_once','free3d_once','free3d_multi'];
  $bypass = (in_array($type, $bypassTypes, true) || ((int)$baseAmount - $discount) <= 0);
  $freeDays = 0;
  if($bypass){
    if($type === 'active30_once') $freeDays = 30;
    elseif(in_array($type, ['free3d_once','free3d_multi'], true)) $freeDays = max(1, (int)($item['value'] ?? 3));
    else $freeDays = 3;
  }
  $finalAmount = $bypass ? 0 : max(0, (int)$baseAmount - $discount);
  return ['ok'=>true,'promo'=>$item,'discount_amount'=>$discount,'final_amount'=>$finalAmount,'bypass_payment'=>$bypass,'free_days'=>$freeDays];
}
function promo_consume_code($code){
  $code = strtoupper(preg_replace('/[^A-Z0-9_-]/','', (string)$code));
  if($code==='') return null;
  $all = promo_read_all(); $items = $all['items'] ?? [];
  if(!isset($items[$code])) return null;
  $type = strtolower((string)($items[$code]['type'] ?? 'fixed'));
  if($type === 'free3d') $type = 'free3d_once';
  if($type !== 'free3d_multi'){
    $items[$code]['used_count'] = max(1, (int)($items[$code]['used_count'] ?? 0) + 1);
    $items[$code]['used_at'] = date('c');
  }
  $items[$code]['reserved_qris_id'] = '';
  $items[$code]['reserved_at'] = '';
  $hit = $items[$code];
  promo_write_all($items);
  return $hit;
}
function promo_reserve_code($code, $qrisId){
  $code = strtoupper(preg_replace('/[^A-Z0-9_-]/','', (string)$code));
  $qrisId = trim((string)$qrisId);
  if($code==='' || $qrisId==='') return false;
  $all = promo_read_all(); $items = $all['items'] ?? [];
  if(!isset($items[$code])) return false;
  if((int)($items[$code]['used_count'] ?? 0) > 0) return false;
  $items[$code]['reserved_qris_id'] = $qrisId;
  $items[$code]['reserved_at'] = date('c');
  promo_write_all($items);
  return true;
}
function promo_consume_reserved_by_qris($qrisId){
  $qrisId = trim((string)$qrisId);
  if($qrisId==='') return null;
  $all = promo_read_all(); $items = $all['items'] ?? [];
  $hit = null;
  foreach($items as $code=>$item){
    if(trim((string)($item['reserved_qris_id'] ?? '')) === $qrisId){
      $type = strtolower((string)($item['type'] ?? 'fixed'));
      if($type === 'free3d') $type = 'free3d_once';
      if($type !== 'free3d_multi'){
        $items[$code]['used_count'] = max(1, (int)($item['used_count'] ?? 0) + 1);
        $items[$code]['used_at'] = date('c');
      }
      $items[$code]['reserved_qris_id'] = '';
      $items[$code]['reserved_at'] = '';
      $hit = $items[$code];
      break;
    }
  }
  if($hit !== null) promo_write_all($items);
  return $hit;
}
function promo_release_qris($qrisId){
  $qrisId = trim((string)$qrisId);
  if($qrisId==='') return false;
  $all = promo_read_all(); $items = $all['items'] ?? []; $changed=false;
  foreach($items as $code=>$item){
    if(trim((string)($item['reserved_qris_id'] ?? '')) === $qrisId && (int)($item['used_count'] ?? 0) <= 0){
      $items[$code]['reserved_qris_id'] = '';
      $items[$code]['reserved_at'] = '';
      $changed=true;
    }
  }
  if($changed) promo_write_all($items);
  return $changed;
}

function qrispy_get_merchant_balance(){
  $paths = [
    '/api/merchant/balance',
    '/api/payment/merchant/balance',
    '/api/payment/qris/balance',
    '/api/qris/balance',
    '/api/merchant/balances',
    '/api/merchant/account/balance',
    '/api/merchant/wallet',
    '/api/merchant/wallet/balance',
    '/api/merchant',
    '/api/merchant/profile',
    '/api/profile',
    '/api/account/profile',
    '/api/balance',
    '/api/wallet',
    '/api/wallet/balance',
    '/api/dashboard',
    '/api/dashboard/summary',
    '/api/user',
    '/api/user/profile',
    '/api/auth/me',
  ];
  $last = ['ok'=>false,'http_code'=>0,'json'=>null,'raw'=>''];

  $toAmount = function($v){
    if(is_int($v) || is_float($v)) return (int)round((float)$v);
    if(is_string($v)){
      $raw = trim($v);
      if($raw === '') return 0;
      // Mendukung format "Rp 1.234.567", "1,234,567", dan "1234567.00".
      $clean = preg_replace('/[^0-9,\.\-]/', '', $raw);
      if($clean === '' || $clean === '-' || $clean === '.' || $clean === ',') return 0;
      if(strpos($clean, ',') !== false && strpos($clean, '.') !== false){
        // Format Indonesia biasanya 1.234.567,89; format internasional 1,234,567.89.
        if(strrpos($clean, ',') > strrpos($clean, '.')) $clean = str_replace('.', '', $clean);
        else $clean = str_replace(',', '', $clean);
      }else{
        // Jika hanya titik/koma dan tepat 3 digit setelahnya, anggap pemisah ribuan.
        if(preg_match('/^[0-9]+([\.,][0-9]{3})+$/', $clean)) $clean = str_replace([',','.'], '', $clean);
        else $clean = str_replace(',', '.', $clean);
      }
      return (int)round((float)$clean);
    }
    return 0;
  };

  $balanceKeys = ['balance','merchant_balance','available_balance','wallet_balance','saldo','saldo_akhir','saldoakhir','current_balance','ending_balance','available','available_amount','amount_balance','qris_balance','balance_amount','total_balance'];
  $merchantIdKeys = ['merchant_id','merchantId','id','mid','merchant_code'];
  $merchantNameKeys = ['merchant_name','merchantName','name','merchant','merchant_title','business_name'];

  $pick = function($src) use (&$pick, $toAmount, $balanceKeys, $merchantIdKeys, $merchantNameKeys){
    if(!is_array($src)) return null;
    $merchantId = '';
    $merchantName = '';
    $currency = (string)($src['currency'] ?? $src['currency_code'] ?? $src['currencyCode'] ?? 'IDR');

    foreach($merchantIdKeys as $k){ if(isset($src[$k]) && !is_array($src[$k])){ $merchantId = (string)$src[$k]; break; } }
    foreach($merchantNameKeys as $k){ if(isset($src[$k]) && !is_array($src[$k])){ $merchantName = (string)$src[$k]; break; } }
    foreach($balanceKeys as $k){
      if(array_key_exists($k, $src) && !is_array($src[$k])){
        return ['merchant_id'=>$merchantId,'merchant_name'=>$merchantName,'balance'=>$toAmount($src[$k]),'currency'=>$currency,'balance_key'=>$k];
      }
    }

    foreach(['data','result','merchant','merchant_data','payload','account','wallet','balance_data','balances'] as $k){
      if(isset($src[$k]) && is_array($src[$k])){
        $found = $pick($src[$k]);
        if($found !== null){
          if(($found['merchant_id'] ?? '') === '') $found['merchant_id'] = $merchantId;
          if(($found['merchant_name'] ?? '') === '') $found['merchant_name'] = $merchantName;
          if(($found['currency'] ?? '') === '') $found['currency'] = $currency;
          return $found;
        }
      }
    }

    // Beberapa API mengirim array list; cari saldo pada item pertama yang cocok.
    foreach($src as $v){
      if(is_array($v)){
        $found = $pick($v);
        if($found !== null) return $found;
      }
    }

    return null;
  };

  foreach($paths as $path){
    $resp = qrispy_request('GET', $path);
    $last = $resp;
    $json = $resp['json'] ?? null;
    if(!$resp['ok'] || !is_array($json)) continue;
    $statusText = strtolower((string)($json['status'] ?? $json['status_code'] ?? 'success'));
    if($statusText !== '' && !in_array($statusText, ['success','ok','200','true'], true)) continue;
    $picked = $pick($json);
    if($picked !== null){
      return ['ok'=>true,'data'=>$picked];
    }
  }

  return ['ok'=>false,'msg'=>'Balance QRIS tidak ditemukan pada respons QRISPy','debug_http_code'=>(int)($last['http_code'] ?? 0),'debug_keys'=>is_array($last['json'] ?? null) ? array_keys($last['json']) : []];
}




function top_online_month_key(){ return date('Y-m'); }
function top_online_empty(){ return ['month'=>top_online_month_key(), 'stores'=>[], 'updatedAt'=>date('c')]; }
function top_online_read_all(){
  $month = top_online_month_key();
  if(!defined('TOP_ONLINE_FILE') || !file_exists(TOP_ONLINE_FILE)) return top_online_empty();
  $j = json_decode(@file_get_contents(TOP_ONLINE_FILE), true);
  if(!is_array($j) || (string)($j['month'] ?? '') !== $month || !is_array($j['stores'] ?? null)) return top_online_empty();
  return $j;
}
function top_online_write_all($stores){
  $clean = [];
  foreach((array)$stores as $sid=>$row){
    $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$sid));
    if($storeId==='' || $storeId===ADMIN_STORE_ID) continue;
    $clean[$storeId] = [
      'count'=>max(0, (int)($row['count'] ?? 0)),
      'lastOnlineTs'=>max(0, (int)($row['lastOnlineTs'] ?? 0)),
      'updatedAt'=>(string)($row['updatedAt'] ?? date('c')),
    ];
  }
  $payload = ['month'=>top_online_month_key(), 'stores'=>$clean, 'updatedAt'=>date('c')];
  @file_put_contents(TOP_ONLINE_FILE, json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
  return $payload;
}
function top_online_increment($storeId){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  if($storeId==='' || $storeId===ADMIN_STORE_ID) return false;
  $all = top_online_read_all();
  $stores = is_array($all['stores'] ?? null) ? $all['stores'] : [];
  if(!isset($stores[$storeId]) || !is_array($stores[$storeId])) $stores[$storeId] = ['count'=>0,'lastOnlineTs'=>0];
  $stores[$storeId]['count'] = (int)($stores[$storeId]['count'] ?? 0) + 1;
  $stores[$storeId]['lastOnlineTs'] = time();
  $stores[$storeId]['updatedAt'] = date('c');
  top_online_write_all($stores);
  return true;
}
function top_online_admin_list($limit=5){
  $all = top_online_read_all();
  $items = [];
  foreach((array)($all['stores'] ?? []) as $sid=>$row){
    $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$sid));
    if($storeId==='') continue;
    $items[] = ['storeId'=>$storeId, 'count'=>(int)($row['count'] ?? 0), 'lastOnlineTs'=>(int)($row['lastOnlineTs'] ?? 0)];
  }
  usort($items, function($a,$b){
    $c = ((int)$b['count']) <=> ((int)$a['count']);
    if($c !== 0) return $c;
    return ((int)$b['lastOnlineTs']) <=> ((int)$a['lastOnlineTs']);
  });
  $items = array_slice($items, 0, max(1, (int)$limit));
  $names = function_exists('store_names_map_for_admin') ? store_names_map_for_admin(array_map(function($x){ return $x['storeId']; }, $items)) : [];
  foreach($items as &$it){ $it['name'] = (string)($names[$it['storeId']] ?? '-'); }
  unset($it);
  return ['month'=>(string)($all['month'] ?? top_online_month_key()), 'items'=>$items, 'updatedAt'=>(string)($all['updatedAt'] ?? '')];
}

function presence_read_all(){
  if(!file_exists(PRESENCE_FILE)) return ["stores"=>[], "updatedAt"=>null];
  $j = json_decode(@file_get_contents(PRESENCE_FILE), true);
  if(!is_array($j)) return ["stores"=>[], "updatedAt"=>null];
  if(!isset($j["stores"]) || !is_array($j["stores"])) $j["stores"] = [];
  if(!isset($j["categories"]) || !is_array($j["categories"])) $j["categories"] = [];
  if(!isset($j["customRaks"]) || !is_array($j["customRaks"])) $j["customRaks"] = [];
  return $j;
}
function presence_lock_open(){
  $fp = @fopen(PRESENCE_FILE . '.lock', 'c+');
  if(!$fp) return false;
  if(!@flock($fp, LOCK_EX)){ @fclose($fp); return false; }
  return $fp;
}
function presence_lock_close($fp){
  if(is_resource($fp)){ @flock($fp, LOCK_UN); @fclose($fp); }
}
function presence_activity_text($value, $maxLength=80){
  $text = is_scalar($value) ? (string)$value : '';
  $text = strip_tags($text);
  $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);
  $text = preg_replace('/\s+/u', ' ', (string)$text);
  $text = trim((string)$text);
  $maxLength = max(1, (int)$maxLength);
  if(function_exists('mb_substr')) return mb_substr($text, 0, $maxLength, 'UTF-8');
  return substr($text, 0, $maxLength);
}
function presence_activity_payload($activity){
  if(!is_array($activity)) return [];
  $title = presence_activity_text($activity['pageTitle'] ?? $activity['activityTitle'] ?? '', 80);
  $key = strtolower(presence_activity_text($activity['pageKey'] ?? $activity['activityKey'] ?? '', 60));
  $key = trim((string)preg_replace('/[^a-z0-9_.:-]+/i', '-', $key), '-');
  if($title === '') return [];
  if($key === '') $key = 'page';
  return ['activityTitle'=>$title, 'activityKey'=>$key];
}
function presence_write_all($map){
  // Pertahankan riwayat terakhir dilihat. Penulisan bersamaan dari banyak user
  // tidak boleh menghapus timestamp user lain yang sudah pernah online.
  $lock = presence_lock_open();
  $existing = [];
  if(file_exists(PRESENCE_FILE)){
    $raw = json_decode((string)@file_get_contents(PRESENCE_FILE), true);
    if(is_array($raw) && is_array($raw['stores'] ?? null)) $existing = $raw['stores'];
  }
  $source = is_array($existing) ? $existing : [];
  foreach((array)$map as $sid=>$row) $source[$sid] = $row;

  $clean = [];
  foreach($source as $sid=>$row){
    $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$sid));
    if($storeId==='') continue;
    $row = is_array($row) ? $row : [];
    $old = is_array($existing[$storeId] ?? null) ? $existing[$storeId] : [];
    $lastSeenTs = max(
      (int)($row['lastSeenTs'] ?? $row['last_seen_ts'] ?? 0),
      (int)($old['lastSeenTs'] ?? $old['last_seen_ts'] ?? 0)
    );
    $lastLoginTs = max(
      (int)($row['lastLoginTs'] ?? $row['last_login_ts'] ?? 0),
      (int)($old['lastLoginTs'] ?? $old['last_login_ts'] ?? 0)
    );
    $rowActivityTs = (int)($row['activityUpdatedTs'] ?? 0);
    $oldActivityTs = (int)($old['activityUpdatedTs'] ?? 0);
    $activitySource = ($rowActivityTs >= $oldActivityTs) ? $row : $old;
    $activityTitle = presence_activity_text($activitySource['activityTitle'] ?? $activitySource['pageTitle'] ?? '', 80);
    $activityKey = strtolower(presence_activity_text($activitySource['activityKey'] ?? $activitySource['pageKey'] ?? '', 60));
    $activityKey = trim((string)preg_replace('/[^a-z0-9_.:-]+/i', '-', $activityKey), '-');
    $activityUpdatedTs = max($rowActivityTs, $oldActivityTs);
    $rowStatusTs = (int)($row['statusUpdatedTs'] ?? $row['lastSeenTs'] ?? 0);
    $oldStatusTs = (int)($old['statusUpdatedTs'] ?? $old['lastSeenTs'] ?? 0);
    $statusSource = ($rowStatusTs >= $oldStatusTs) ? $row : $old;
    $clean[$storeId] = [
      "lastSeenTs" => max(0, $lastSeenTs),
      "lastLoginTs" => max(0, $lastLoginTs),
      "isOnline" => !empty($statusSource['isOnline']),
      "statusUpdatedTs" => max($rowStatusTs, $oldStatusTs),
      "activityTitle" => $activityTitle,
      "activityKey" => $activityKey,
      "activityUpdatedTs" => max(0, $activityUpdatedTs),
      "updatedAt" => date('c'),
    ];
  }
  ksort($clean, SORT_NATURAL|SORT_FLAG_CASE);
  $payload = ["stores"=>$clean, "updatedAt"=>date('c')];
  $tmp = PRESENCE_FILE . '.tmp';
  $encoded = json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
  $saved = ($encoded !== false) ? @file_put_contents($tmp, $encoded, LOCK_EX) : false;
  if($saved !== false && !@rename($tmp, PRESENCE_FILE)) $saved = @file_put_contents(PRESENCE_FILE, $encoded, LOCK_EX);
  presence_lock_close($lock);
  return $saved === false ? false : $payload;
}
function presence_touch($storeId, $isLogin=false, $activity=[]){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  if($storeId==='') return false;
  // Developer M604 tidak dihitung online dan tidak masuk ranking/admin sebagai user online.
  if($storeId === ADMIN_STORE_ID && function_exists('m604_is_developer_session') && m604_is_developer_session()) return false;
  $all = presence_read_all();
  if(!isset($all["stores"][$storeId]) || !is_array($all["stores"][$storeId])) $all["stores"][$storeId] = [];
  $now = time();
  $prevLastSeen = (int)($all["stores"][$storeId]["lastSeenTs"] ?? 0);
  $prevOnline = !empty($all["stores"][$storeId]["isOnline"]) && $prevLastSeen > 0 && (($now - $prevLastSeen) <= ONLINE_WINDOW_SEC);
  if(!$prevOnline && function_exists('top_online_increment')) top_online_increment($storeId);
  $all["stores"][$storeId]["lastSeenTs"] = $now;
  $all["stores"][$storeId]["isOnline"] = true;
  $all["stores"][$storeId]["statusUpdatedTs"] = $now;
  if($isLogin || empty($all["stores"][$storeId]["lastLoginTs"])){
    $all["stores"][$storeId]["lastLoginTs"] = $now;
  }
  $activityData = presence_activity_payload($activity);
  if(!empty($activityData['activityTitle'])){
    $all["stores"][$storeId]["activityTitle"] = $activityData['activityTitle'];
    $all["stores"][$storeId]["activityKey"] = $activityData['activityKey'];
    $all["stores"][$storeId]["activityUpdatedTs"] = $now;
  }
  presence_write_all([$storeId=>$all["stores"][$storeId]]);
  return $all["stores"][$storeId];
}
function presence_set_offline($storeId){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  if($storeId==='') return false;
  $all = presence_read_all();
  if(!isset($all["stores"][$storeId]) || !is_array($all["stores"][$storeId])) $all["stores"][$storeId] = [];
  $all["stores"][$storeId]["isOnline"] = false;
  $all["stores"][$storeId]["statusUpdatedTs"] = time();
  $all["stores"][$storeId]["updatedAt"] = date('c');
  presence_write_all([$storeId=>$all["stores"][$storeId]]);
  return $all["stores"][$storeId];
}
function presence_get_status_map($stores=[]){
  $all = presence_read_all();
  $src = $all["stores"] ?? [];
  $now = time();
  $out = [];
  $targets = is_array($stores) ? $stores : [];
  foreach($targets as $sid){
    $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$sid));
    if($storeId==='') continue;
    $row = is_array($src[$storeId] ?? null) ? $src[$storeId] : [];
    $lastSeenTs = (int)($row['lastSeenTs'] ?? $row['last_seen_ts'] ?? 0);
    $lastLoginTs = (int)($row['lastLoginTs'] ?? $row['last_login_ts'] ?? 0);
    // Data lama kadang hanya memiliki updatedAt. Gunakan sebagai cadangan riwayat,
    // sehingga user yang pernah online kemarin atau beberapa hari lalu tidak kembali strip.
    if($lastSeenTs <= 0 && !empty($row['updatedAt'])){
      $legacyTs = strtotime((string)$row['updatedAt']);
      if($legacyTs !== false) $lastSeenTs = (int)$legacyTs;
    }
    if($lastLoginTs <= 0) $lastLoginTs = $lastSeenTs;
    $flagOnline = !empty($row['isOnline']);
    $out[$storeId] = [
      "online" => ($flagOnline && $lastSeenTs > 0 && ($now - $lastSeenTs) <= ONLINE_WINDOW_SEC),
      "lastSeenTs" => $lastSeenTs,
      "lastLoginTs" => $lastLoginTs,
      "activityTitle" => presence_activity_text($row['activityTitle'] ?? $row['pageTitle'] ?? '', 80),
      "activityKey" => strtolower(presence_activity_text($row['activityKey'] ?? $row['pageKey'] ?? '', 60)),
      "activityUpdatedTs" => (int)($row['activityUpdatedTs'] ?? 0),
    ];
  }
  return $out;
}


function qrispy_request($method, $path, $payload=null, $query=[]){
  $url = rtrim(QRISPY_API_URL, '/') . $path;
  if(!empty($query)) $url .= '?' . http_build_query($query);
  $jsonBody = $payload !== null ? json_encode($payload) : null;
  $headerSets = [
    ['Accept: application/json', 'X-API-Token: ' . QRISPY_API_TOKEN],
    ['Accept: application/json', 'Authorization: Bearer ' . QRISPY_API_TOKEN],
    ['Accept: application/json', 'Authorization: Bearer ' . QRISPY_API_TOKEN, 'X-API-Token: ' . QRISPY_API_TOKEN],
  ];
  foreach($headerSets as &$headers){ if($payload !== null) $headers[] = 'Content-Type: application/json'; }
  unset($headers);

  $last = ['ok'=>false,'http_code'=>0,'error'=>'request_failed','json'=>null,'raw'=>''];

  if(function_exists('curl_init')){
    foreach($headerSets as $headers){
      $ch = curl_init($url);
      curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CUSTOMREQUEST=>strtoupper($method),
        CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_TIMEOUT=>30,
        CURLOPT_SSL_VERIFYPEER=>true,
        CURLOPT_SSL_VERIFYHOST=>2,
      ]);
      if($jsonBody !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
      $resp = curl_exec($ch);
      $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $err = curl_error($ch);
      curl_close($ch);
      if($resp === false || $err){
        $last = ['ok'=>false,'http_code'=>$code ?: 0,'error'=>$err ?: 'curl_error','json'=>null,'raw'=>''];
        continue;
      }
      $json = json_decode($resp, true);
      $last = ['ok'=>($code>=200 && $code<300),'http_code'=>$code,'json'=>$json,'raw'=>$resp];
      if($last['ok']) return $last;
      $msg = strtolower(trim((string)(is_array($json) ? ($json['message'] ?? $json['msg'] ?? '') : '')));
      if($code !== 401 && strpos($msg, 'token') === false && strpos($msg, 'api token') === false && strpos($msg, 'authorization') === false){
        return $last;
      }
    }
    return $last;
  }

  foreach($headerSets as $headers){
    $ctx = stream_context_create(['http'=>[
      'method'=>strtoupper($method),
      'header'=>implode("
", $headers),
      'content'=>$jsonBody !== null ? $jsonBody : '',
      'timeout'=>30,
      'ignore_errors'=>true
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    $code = 0;
    if(isset($http_response_header) && is_array($http_response_header)){
      foreach($http_response_header as $line){ if(preg_match('~HTTP/\S+\s+(\d{3})~', $line, $m)){ $code=(int)$m[1]; break; } }
    }
    $json = json_decode((string)$resp, true);
    $last = ['ok'=>($code>=200 && $code<300),'http_code'=>$code,'json'=>$json,'raw'=>$resp];
    if($last['ok']) return $last;
    $msg = strtolower(trim((string)(is_array($json) ? ($json['message'] ?? $json['msg'] ?? '') : '')));
    if($code !== 401 && strpos($msg, 'token') === false && strpos($msg, 'api token') === false && strpos($msg, 'authorization') === false){
      return $last;
    }
  }
  return $last;
}

function qrispy_generate_registration_payment($storeId, $amount=null){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $dir = rtrim(dirname($_SERVER['PHP_SELF'] ?? '/index.php'), '/\\');
  if($dir === '') $dir = '';
  $amount = (int)$amount; if($amount <= 0) $amount = qris_registration_amount();
  $payload = ['amount'=>$amount,'payment_reference'=>'REG-' . $storeId . '-' . date('YmdHis'),'return_url'=>$scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/index.php'];
  return qrispy_request('POST', '/api/payment/qris/generate', $payload);
}

function qrispy_enrich_generated_payment($row){
  $row = is_array($row) ? $row : [];
  $qrisId = trim((string)($row['qris_id'] ?? $row['id'] ?? ''));
  if($qrisId === '') return $row;
  $needImage = empty($row['qris_image_base64']) && empty($row['qris_image_url']);
  $needMeta = empty($row['expired_at']) || empty($row['amount']);
  if(!$needImage && !$needMeta) return $row;
  $detail = qrispy_request('GET', '/api/payment/qris/' . rawurlencode($qrisId));
  $json = $detail['json'] ?? null;
  if($detail['ok'] && is_array($json) && strtolower((string)($json['status'] ?? '')) === 'success' && is_array($json['data'] ?? null)){
    $data = $json['data'];
    foreach(['qris_image_url','qris_image_base64','amount','requested_amount','unique_id','expired_at','expires_in_seconds','checkout_url','payment_reference','return_url','is_active','is_expired','is_paid','paid_at'] as $k){
      if((!isset($row[$k]) || $row[$k] === '' || $row[$k] === null) && array_key_exists($k, $data)) $row[$k] = $data[$k];
    }
  }
  return $row;
}
function qrispy_status_normalize($status){
  $status = strtolower(trim((string)$status));
  if($status==='') return '';
  $map = [
    'sukses'=>'paid','success'=>'paid','successful'=>'paid','paid'=>'paid','settled'=>'paid','completed'=>'paid','complete'=>'paid',
    'capture'=>'paid','captured'=>'paid','done'=>'paid','approved'=>'paid','accept'=>'paid','accepted'=>'paid',
    'waiting'=>'pending','waiting_payment'=>'pending','unpaid'=>'pending','open'=>'pending','created'=>'pending','new'=>'pending','process'=>'pending','processing'=>'pending',
    'expire'=>'expired','expired'=>'expired','cancel'=>'cancelled','cancelled'=>'cancelled','canceled'=>'cancelled','failed'=>'failed','deny'=>'failed','denied'=>'failed'
  ];
  return $map[$status] ?? $status;
}
function qrispy_amount_from_row($row, $keys){
  foreach((array)$keys as $k){
    if(!array_key_exists($k, (array)$row)) continue;
    $v = $row[$k];
    if(is_array($v)) continue;
    if(is_string($v)) $v = preg_replace('/[^0-9]/','', $v);
    $n = (int)$v;
    if($n > 0) return $n;
  }
  return 0;
}
function qrispy_payment_cache_read_all(){
  if(!file_exists(QRIS_PAYMENT_CACHE_FILE)) return ['items'=>[], 'updatedAt'=>null];
  $j = json_decode(@file_get_contents(QRIS_PAYMENT_CACHE_FILE), true);
  if(!is_array($j) || !isset($j['items']) || !is_array($j['items'])) return ['items'=>[], 'updatedAt'=>null];
  return $j;
}
function qrispy_payment_cache_write_all($items){
  $payload = ['items'=>(array)$items, 'updatedAt'=>date('c')];
  @file_put_contents(QRIS_PAYMENT_CACHE_FILE, json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
  return $payload;
}
function qrispy_payment_cache_get($qrisId){
  $qrisId = trim((string)$qrisId); if($qrisId==='') return null;
  $all = qrispy_payment_cache_read_all();
  $row = $all['items'][$qrisId] ?? null;
  return is_array($row) ? $row : null;
}

function qris_apply_log_read_all(){
  if(!file_exists(QRIS_APPLY_LOG_FILE)) return ['items'=>[]];
  $j = json_decode(@file_get_contents(QRIS_APPLY_LOG_FILE), true);
  if(!is_array($j) || !is_array($j['items'] ?? null)) return ['items'=>[]];
  return $j;
}
function qris_apply_log_write_all($items){
  @file_put_contents(QRIS_APPLY_LOG_FILE, json_encode(['items'=>$items], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
}
function qris_apply_log_get($qrisId){
  $qrisId = trim((string)$qrisId);
  if($qrisId==='') return null;
  $all = qris_apply_log_read_all();
  $row = $all['items'][$qrisId] ?? null;
  return is_array($row) ? $row : null;
}
function qris_apply_log_set($qrisId, $data=[]){
  $qrisId = trim((string)$qrisId);
  if($qrisId==='') return false;
  $all = qris_apply_log_read_all();
  $items = $all['items'] ?? [];
  $prev = is_array($items[$qrisId] ?? null) ? $items[$qrisId] : [];
  $items[$qrisId] = array_merge($prev, [
    'qris_id'=>$qrisId,
    'applied_at'=>date('c'),
  ], (array)$data);
  qris_apply_log_write_all($items);
  return $items[$qrisId];
}
function qrispy_payment_cache_mark_success($qrisId, $row=[]){
  $qrisId = trim((string)$qrisId); if($qrisId==='') return false;
  $all = qrispy_payment_cache_read_all();
  $items = is_array($all['items'] ?? null) ? $all['items'] : [];
  $items[$qrisId] = [
    'qris_id' => $qrisId,
    'payment_status' => 'paid',
    'row' => is_array($row) ? $row : [],
    'updatedAt' => date('c'),
    'updatedTs' => time(),
  ];
  qrispy_payment_cache_write_all($items);
  return true;
}
function qrispy_normalize_payment_row($row){
  if(!is_array($row)) return null;
  if(!isset($row['qris_id']) && isset($row['id'])) $row['qris_id'] = $row['id'];
  $paymentStatus = qrispy_status_normalize($row['payment_status'] ?? $row['status'] ?? $row['transaction_status'] ?? $row['paymentStatus'] ?? '');
  $isPaid = array_key_exists('is_paid', $row) ? !empty($row['is_paid']) : false;
  $hasPaidMarker = !empty($row['paid_at']) || !empty($row['paidAt']) || !empty($row['payment_time']) || !empty($row['settlement_time']) || !empty($row['settled_at']);
  $requestedAmount = qrispy_amount_from_row($row, ['requested_amount','request_amount','gross_amount','total_amount','nominal','price']);
  $paidAmount = qrispy_amount_from_row($row, ['paid_amount','amount_paid','paid','amount','gross_amount','total_amount']);
  if(($paymentStatus === '' || $paymentStatus === 'pending') && ($isPaid || $hasPaidMarker)) $paymentStatus = 'paid';
  if(($paymentStatus === '' || $paymentStatus === 'pending') && $requestedAmount > 0 && $paidAmount >= $requestedAmount) $paymentStatus = 'paid';
  if($paymentStatus === '' && !empty($row['is_expired'])) $paymentStatus = 'expired';
  $row['payment_status'] = $paymentStatus !== '' ? $paymentStatus : 'pending';
  return $row;
}
function qrispy_extract_payment_row_from_json($json, $qrisId=''){
  if(!is_array($json) || !isset($json['data'])) return null;
  $data = $json['data'];
  if(is_array($data) && (isset($data['qris_id']) || isset($data['id']))){
    $row = qrispy_normalize_payment_row($data);
    if($qrisId === '' || (string)($row['qris_id'] ?? '') === (string)$qrisId) return $row;
  }
  if(is_array($data)){
    foreach($data as $row){
      if(!is_array($row)) continue;
      $row = qrispy_normalize_payment_row($row);
      if($qrisId === '' || (string)($row['qris_id'] ?? '') === (string)$qrisId) return $row;
    }
  }
  return null;
}
function qrispy_find_payment($qrisId, $maxWaitMs=0){
  $qrisId = trim((string)$qrisId); if($qrisId==='') return null;
  $cached = qrispy_payment_cache_get($qrisId);
  if(is_array($cached['row'] ?? null) && qrispy_payment_is_success($cached['row'])) return qrispy_normalize_payment_row($cached['row']);
  $maxWaitMs = max(0, (int)$maxWaitMs);
  $startedAt = (int)round(microtime(true) * 1000);
  $attempt = 0;
  do {
    $attempt++;
    $tries = [
      ['GET', '/api/payment/qris/' . rawurlencode($qrisId) . '/status', []],
      ['GET', '/api/payment/qris/' . rawurlencode($qrisId), []],
      ['GET', '/api/payment/transactions', ['limit'=>100, 'generated_via'=>'api', 'status'=>'all']],
      ['GET', '/api/payment/transactions', ['page'=>1, 'per_page'=>100]],
      ['GET', '/api/payment/transactions', ['limit'=>100, 'status'=>'success']],
      ['GET', '/api/payment/transactions', ['limit'=>100, 'status'=>'paid']],
    ];
    foreach($tries as $t){
      $res = qrispy_request($t[0], $t[1], null, $t[2]);
      $row = qrispy_extract_payment_row_from_json($res['json'] ?? null, $qrisId);
      if($row){
        if(qrispy_payment_is_success($row)) qrispy_payment_cache_mark_success($qrisId, $row);
        return $row;
      }
    }
    if($maxWaitMs <= 0) break;
    $elapsed = (int)round(microtime(true) * 1000) - $startedAt;
    if($elapsed >= $maxWaitMs) break;
    usleep($attempt <= 1 ? 90000 : 140000);
  } while(true);
  if(is_array($cached['row'] ?? null)) return qrispy_normalize_payment_row($cached['row']);
  return null;
}
function qrispy_payment_is_expired($row){
  if(!is_array($row)) return false;
  $expiredAt = trim((string)($row['expired_at'] ?? $row['expires_at'] ?? ''));
  if($expiredAt !== ''){
    $ts = strtotime($expiredAt);
    if($ts !== false && $ts > 0 && time() > $ts) return true;
  }
  $status = qrispy_status_normalize($row['payment_status'] ?? $row['status'] ?? $row['transaction_status'] ?? '');
  return in_array($status, ['expired','cancelled','failed'], true);
}

function qrispy_payment_is_success($row, $expectedAmount=null){
  if(!is_array($row)) return false;
  $row = qrispy_normalize_payment_row($row);
  $status = qrispy_status_normalize($row['payment_status'] ?? $row['status'] ?? '');
  $expectedAmount = (int)$expectedAmount; if($expectedAmount <= 0) $expectedAmount = qris_registration_amount();
  $requestedAmount = qrispy_amount_from_row($row, ['requested_amount','request_amount','gross_amount','total_amount','nominal','price']);
  $paidAmount = qrispy_amount_from_row($row, ['paid_amount','amount_paid','paid','amount','gross_amount','total_amount']);
  $isPaid = !empty($row['is_paid']) || !empty($row['paid_at']) || !empty($row['paidAt']) || !empty($row['payment_time']) || !empty($row['settlement_time']) || !empty($row['settled_at']);
  $amountOk = false;
  if($requestedAmount > 0){
    $amountOk = ($requestedAmount === $expectedAmount) && ($paidAmount <= 0 || $paidAmount >= $requestedAmount);
  }else{
    $amountOk = ($paidAmount >= $expectedAmount);
  }
  return ($isPaid || in_array($status, ['paid','success','settled','completed'], true)) && $amountOk;
}

define('COOKIE_SECRET', 'ALFASTORE_SECRET_CHANGE_ME_2026');
define('COOKIE_NAME', 'ALFASTORE_SESSION_V2');
define('LAST_STORE_COOKIE_NAME', 'CIBILI_LAST_STORE_CODE');
define('LAST_STORE_COOKIE_MAX_AGE_SEC', 31536000); // kode toko disimpan 1 tahun, terpisah dari sesi login
define('ACTIVE_SESSION_FILE', __DIR__ . '/alfastore_active_sessions.json');
define('SESSION_CONFIG_FILE', __DIR__ . '/alfastore_session_config.json');
define('SESSION_IDLE_TIMEOUT_SEC', 86400); // bawaan 24 jam sebelum admin menyimpan pengaturan
define('SESSION_MAX_TIMEOUT_SEC', 31536000); // maksimal 365 hari
define('SESSION_TOKEN_MAX_AGE_SEC', 31536000); // batas teknis token session cookie
define('SESSION_HEARTBEAT_SEC', 15);
define('SESSION_VISIBLE_RECOVERY_GRACE_SEC', 300); // toleransi throttling/background Android
if(!defined('ADMIN_SESSION_LIFETIME_SEC')) define('ADMIN_SESSION_LIFETIME_SEC', 86400); // admin valid tepat 24 jam

$DEFAULT_STORES = ["M604"];

/* =========================
   HELPERS
========================= */

function json_file_read_array_safe($file, $fallback=[]){
  clearstatcache(true, $file);
  $paths = [$file, $file . '.bak'];
  foreach($paths as $path){
    if(!is_file($path)) continue;
    for($i=0; $i<3; $i++){
      $fp = @fopen($path, 'rb');
      if(!$fp){ usleep(20000); continue; }
      @flock($fp, LOCK_SH);
      $raw = stream_get_contents($fp);
      @flock($fp, LOCK_UN);
      @fclose($fp);
      $j = json_decode((string)$raw, true);
      if(is_array($j)) return $j;
      usleep(30000);
    }
  }
  return is_array($fallback) ? $fallback : [];
}
function json_file_write_array_safe($file, $payload){
  $dir = dirname($file);
  if(!is_dir($dir)) @mkdir($dir, 0775, true);
  $json = json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
  if($json === false) return false;
  if(is_file($file)){ @copy($file, $file . '.bak'); }
  $tmp = $file . '.tmp.' . getmypid() . '.' . mt_rand(1000,9999);
  $ok = @file_put_contents($tmp, $json, LOCK_EX);
  if($ok === false){ @unlink($tmp); return false; }
  if(function_exists('chmod')) @chmod($tmp, 0664);
  if(!@rename($tmp, $file)){
    $ok2 = @file_put_contents($file, $json, LOCK_EX);
    @unlink($tmp);
    return $ok2 !== false;
  }
  return true;
}


/* =========================
   ADMIN CREDENTIALS (dapat diubah dari popup PWD)
========================= */
function admin_credentials_default(){
  return [
    'developerPin' => (string)DEVELOPER_PIN,
    'adminPassword' => (string)ADMIN_PASSWORD,
    'reportPin' => (string)ADMIN_REPORT_PASSWORD,
    'updatedAt' => null,
    'updatedBy' => '',
  ];
}
function admin_credentials_read(){
  $defaults = admin_credentials_default();
  $data = json_file_read_array_safe(ADMIN_CREDENTIALS_FILE, $defaults);
  if(!is_array($data)) $data = $defaults;
  $developerPin = preg_replace('/[^0-9]/', '', (string)($data['developerPin'] ?? $defaults['developerPin']));
  $adminPassword = trim((string)($data['adminPassword'] ?? $defaults['adminPassword']));
  $reportPin = preg_replace('/[^0-9]/', '', (string)($data['reportPin'] ?? $defaults['reportPin']));
  if(strlen($developerPin) !== 4) $developerPin = $defaults['developerPin'];
  if(strlen($adminPassword) < 4 || strlen($adminPassword) > 64) $adminPassword = $defaults['adminPassword'];
  if(strlen($reportPin) < 2 || strlen($reportPin) > 8) $reportPin = $defaults['reportPin'];
  return [
    'developerPin' => $developerPin,
    'adminPassword' => $adminPassword,
    'reportPin' => $reportPin,
    'updatedAt' => $data['updatedAt'] ?? null,
    'updatedBy' => strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)($data['updatedBy'] ?? ''))),
  ];
}
function admin_developer_pin(){
  $data = admin_credentials_read();
  return (string)$data['developerPin'];
}
function admin_login_password(){
  $data = admin_credentials_read();
  return (string)$data['adminPassword'];
}
function admin_report_pin(){
  $data = admin_credentials_read();
  return (string)$data['reportPin'];
}
function admin_credentials_update($developerPin, $adminPassword, $reportPin, $actor=''){
  $current = admin_credentials_read();
  $developerPin = trim((string)$developerPin);
  $adminPassword = trim((string)$adminPassword);
  $reportPin = trim((string)$reportPin);

  if($developerPin !== ''){
    $developerPin = preg_replace('/[^0-9]/', '', $developerPin);
    if(strlen($developerPin) !== 4) return [false, 'PIN developer wajib tepat 4 angka.', $current];
    if(hash_equals((string)DEFAULT_PIN, (string)$developerPin)) return [false, 'PIN developer tidak boleh sama dengan PIN user M604 0000.', $current];
    $current['developerPin'] = $developerPin;
  }
  if($adminPassword !== ''){
    if(strlen($adminPassword) < 4 || strlen($adminPassword) > 64 || preg_match('/\s/', $adminPassword)){
      return [false, 'Sandi admin wajib 4-64 karakter tanpa spasi.', $current];
    }
    $current['adminPassword'] = $adminPassword;
  }
  if($reportPin !== ''){
    $reportPin = preg_replace('/[^0-9]/', '', $reportPin);
    if(strlen($reportPin) < 2 || strlen($reportPin) > 8) return [false, 'PIN laporan wajib 2-8 angka.', $current];
    $current['reportPin'] = $reportPin;
  }
  if($developerPin === '' && $adminPassword === '' && $reportPin === ''){
    return [false, 'Isi minimal satu data yang ingin diubah.', $current];
  }
  $current['updatedAt'] = date('c');
  $current['updatedBy'] = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)$actor));
  $ok = json_file_write_array_safe(ADMIN_CREDENTIALS_FILE, $current);
  return [$ok, $ok ? 'Password dan PIN berhasil diperbarui.' : 'Gagal menyimpan. Periksa izin tulis folder.', $current];
}


/* =========================
   MANUAL REGISTRATION / RENEWAL APPROVAL
   - daftar.html mengirim permintaan ke JSON ini
   - admin menyetujui/menolak dari menu Approval
   - masa aktif selalu ditambahkan dari expired yang masih tersisa
========================= */
function manual_registration_default_data(){
  return ['items'=>[], 'updatedAt'=>null];
}
function manual_registration_read_all(){
  $data = json_file_read_array_safe(MANUAL_REGISTRATION_FILE, manual_registration_default_data());
  if(!is_array($data)) $data = manual_registration_default_data();
  if(!isset($data['items']) || !is_array($data['items'])) $data['items'] = [];
  return $data;
}
function manual_registration_with_lock($callback){
  $dir = dirname(MANUAL_REGISTRATION_FILE);
  if(!is_dir($dir)) @mkdir($dir, 0775, true);
  $fp = @fopen(MANUAL_REGISTRATION_FILE, 'c+');
  if(!$fp) return ['ok'=>false,'msg'=>'Penyimpanan approval tidak tersedia.'];
  if(!@flock($fp, LOCK_EX)){ @fclose($fp); return ['ok'=>false,'msg'=>'Penyimpanan approval sedang sibuk.']; }
  rewind($fp);
  $raw = stream_get_contents($fp);
  $data = json_decode((string)$raw, true);
  if(!is_array($data)) $data = manual_registration_default_data();
  if(!isset($data['items']) || !is_array($data['items'])) $data['items'] = [];
  try{
    $result = $callback($data);
  }catch(Throwable $e){
    @flock($fp, LOCK_UN); @fclose($fp);
    return ['ok'=>false,'msg'=>'Proses approval gagal: '.$e->getMessage()];
  }
  $data['updatedAt'] = date('c');
  $json = json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
  if($json === false){ @flock($fp, LOCK_UN); @fclose($fp); return ['ok'=>false,'msg'=>'Gagal menyusun data approval.']; }
  rewind($fp); ftruncate($fp, 0);
  $written = fwrite($fp, $json);
  fflush($fp);
  @flock($fp, LOCK_UN); @fclose($fp);
  if($written === false) return ['ok'=>false,'msg'=>'Gagal menyimpan data approval.'];
  return is_array($result) ? $result : ['ok'=>true];
}
function manual_registration_settings_read(){
  $data = json_file_read_array_safe(MANUAL_REGISTRATION_SETTINGS_FILE, ['promo2Enabled'=>true,'updatedAt'=>null]);
  if(!is_array($data)) $data = [];
  return [
    'promo2Enabled'=>array_key_exists('promo2Enabled',$data) ? (bool)$data['promo2Enabled'] : true,
    'updatedAt'=>(string)($data['updatedAt'] ?? '')
  ];
}
function manual_registration_promo_enabled(){
  $settings = manual_registration_settings_read();
  return !empty($settings['promo2Enabled']);
}
function manual_registration_settings_write($enabled){
  $payload = ['promo2Enabled'=>(bool)$enabled,'updatedAt'=>date('c')];
  return json_file_write_array_safe(MANUAL_REGISTRATION_SETTINGS_FILE, $payload) ? $payload : false;
}
function manual_registration_new_id(){
  try{ return 'REG-' . strtoupper(bin2hex(random_bytes(6))); }
  catch(Throwable $e){ return 'REG-' . strtoupper(substr(sha1(uniqid('', true).mt_rand()),0,12)); }
}
function manual_registration_extend_months_ts($storeId, $months){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  $months = max(1, min(24, (int)$months));
  $tz = new DateTimeZone('Asia/Jakarta');
  $currentTs = (int)expiry_get_ts($storeId);
  $baseTs = $currentTs > time() ? $currentTs : time();
  $dt = new DateTime('@'.$baseTs);
  $dt->setTimezone($tz);
  $dt->modify('+'.$months.' months');
  $dt->setTime(23,59,59);
  return $dt->getTimestamp();
}
function manual_registration_public_row($row){
  if(!is_array($row)) return [];
  return [
    'id'=>(string)($row['id'] ?? ''),
    'storeId'=>(string)($row['storeId'] ?? ''),
    'pin'=>(string)($row['pin'] ?? ''),
    'plan'=>(string)($row['plan'] ?? ''),
    'planLabel'=>(string)($row['planLabel'] ?? ''),
    'months'=>(int)($row['months'] ?? 0),
    'amount'=>(int)($row['amount'] ?? 50000),
    'status'=>(string)($row['status'] ?? 'pending'),
    'createdAt'=>(string)($row['createdAt'] ?? ''),
    'updatedAt'=>(string)($row['updatedAt'] ?? ''),
    'approvedAt'=>(string)($row['approvedAt'] ?? ''),
    'rejectedAt'=>(string)($row['rejectedAt'] ?? ''),
    'oldExpiryTs'=>(int)($row['oldExpiryTs'] ?? 0),
    'newExpiryTs'=>(int)($row['newExpiryTs'] ?? 0),
    'isExistingUser'=>!empty($row['isExistingUser'])
  ];
}

define('GIFT_WHEEL_FILE', __DIR__ . '/cibili_gift_wheel.json');
define('GIFT_WHEEL_FORM_FILE', __DIR__ . '/cibili_gift_wheel_form.json');
define('GIFT_WHEEL_REQUEST_FILE', __DIR__ . '/cibili_gift_wheel_requests.json');
function gift_wheel_allowed_prizes(){
  return ['Coba Lagi','Voucher Alfa 10K','Voucher Alfa 25K','Voucher Alfa 50K','Voucher Alfa 100K','Expired 3 Hari','Expired 7 Hari','Expired 15 Hari','Expired 1 Bulan','Expired 2 Bulan','Expired 3 Bulan','Saldo E-money 10K','Saldo E-money 20K','Saldo E-money 50K','Pulsa 5K','Pulsa 10K','Pulsa 20K','Pulsa 50K'];
}
function gift_wheel_requires_phone($winner){
  return (bool)preg_match('/^(?:Saldo E-money|Pulsa)\s/i', trim((string)$winner));
}
function gift_wheel_is_emoney($winner){
  return (bool)preg_match('/^Saldo E-money\s/i', trim((string)$winner));
}
function gift_wheel_allowed_wallets(){
  return ['DANA','OVO','GOPAY','SHOPEEPAY','LINK AJA'];
}
function gift_wheel_normalize_wallet($wallet){
  $wallet=strtoupper(trim(strip_tags((string)$wallet)));
  return in_array($wallet,gift_wheel_allowed_wallets(),true)?$wallet:'';
}
function gift_wheel_random_winner(){
  $weights = [
    'Coba Lagi'=>35,
    'Voucher Alfa 10K'=>8, 'Voucher Alfa 25K'=>4, 'Voucher Alfa 50K'=>2, 'Voucher Alfa 100K'=>1,
    'Expired 3 Hari'=>12, 'Expired 7 Hari'=>10, 'Expired 15 Hari'=>7, 'Expired 1 Bulan'=>4, 'Expired 2 Bulan'=>2, 'Expired 3 Bulan'=>1,
    'Saldo E-money 10K'=>5, 'Saldo E-money 20K'=>3, 'Saldo E-money 50K'=>1,
    'Pulsa 5K'=>6, 'Pulsa 10K'=>4, 'Pulsa 20K'=>2, 'Pulsa 50K'=>1,
  ];
  $total = array_sum($weights);
  try{ $pick = random_int(1, max(1,$total)); }catch(Throwable $e){ $pick = mt_rand(1, max(1,$total)); }
  foreach($weights as $winner=>$weight){
    $pick -= (int)$weight;
    if($pick <= 0) return $winner;
  }
  return 'Coba Lagi';
}
function gift_wheel_form_read(){
  $d=json_file_read_array_safe(GIFT_WHEEL_FORM_FILE,['winner'=>'Coba Lagi']);
  $winner=trim((string)($d['winner']??'Coba Lagi'));
  if(!in_array($winner,gift_wheel_allowed_prizes(),true)) $winner='Coba Lagi';
  return ['winner'=>$winner,'prizes'=>implode("\n",gift_wheel_allowed_prizes()),'updated_at'=>(string)($d['updated_at']??'')];
}
function gift_wheel_form_save($winner,$prizes=''){
  $winner=trim(strip_tags((string)$winner));
  if(!in_array($winner,gift_wheel_allowed_prizes(),true)) return ['ok'=>false,'msg'=>'Hadiah tidak valid'];
  $payload=['winner'=>$winner,'updated_at'=>date('c')];
  if(!json_file_write_array_safe(GIFT_WHEEL_FORM_FILE,$payload)) return ['ok'=>false,'msg'=>'Gagal menyimpan pilihan hadiah'];
  return ['ok'=>true]+$payload+['prizes'=>implode("\n",gift_wheel_allowed_prizes())];
}
function gift_wheel_normalize_code($code){ return strtoupper(substr(preg_replace('/[^A-Z0-9_-]/','',strtoupper(trim((string)$code))),0,24)); }
function gift_wheel_read(){ $d=json_file_read_array_safe(GIFT_WHEEL_FILE,['items'=>[]]); if(!isset($d['items'])||!is_array($d['items']))$d['items']=[]; return $d; }
function gift_wheel_random_code(){ return 'RODA-'.strtoupper(substr(bin2hex(random_bytes(5)),0,8)); }
function gift_wheel_create($code,$winner,$prizes=[]){
  $code=gift_wheel_normalize_code($code); if($code==='')$code=gift_wheel_random_code();
  $winner=trim((string)$winner); $clean=gift_wheel_allowed_prizes();
  if(!in_array($winner,$clean,true))return ['ok'=>false,'msg'=>'Pilih hadiah dari daftar yang tersedia'];
  $d=gift_wheel_read(); foreach($d['items'] as $it){if(strtoupper((string)($it['code']??''))===$code)return ['ok'=>false,'msg'=>'Kode sudah tersedia'];}
  array_unshift($d['items'],['code'=>$code,'winner'=>$winner,'prizes'=>$clean,'used'=>false,'used_by'=>'','reward_applied'=>false,'wallet_type'=>'','phone_number'=>'','phone_submitted_at'=>'','created_at'=>date('c'),'used_at'=>'']); $d['items']=array_slice($d['items'],0,500); $d['updated_at']=date('c');
  if(!json_file_write_array_safe(GIFT_WHEEL_FILE,$d))return ['ok'=>false,'msg'=>'Gagal menyimpan data roda']; return ['ok'=>true,'code'=>$code];
}
function gift_wheel_find($code){$code=gift_wheel_normalize_code($code);$d=gift_wheel_read();foreach($d['items'] as $i=>$it){if(strtoupper((string)($it['code']??''))===$code)return [$d,$i,$it];}return [$d,-1,null];}
function gift_wheel_expiry_duration($winner){
  $map=['Expired 3 Hari'=>['days'=>3,'months'=>0],'Expired 7 Hari'=>['days'=>7,'months'=>0],'Expired 15 Hari'=>['days'=>15,'months'=>0],'Expired 1 Bulan'=>['days'=>0,'months'=>1],'Expired 2 Bulan'=>['days'=>0,'months'=>2],'Expired 3 Bulan'=>['days'=>0,'months'=>3]];
  return $map[$winner]??null;
}
function gift_wheel_apply_reward($store,$winner,$code){
  $duration=gift_wheel_expiry_duration($winner);
  if($duration){
    $tz=new DateTimeZone('Asia/Jakarta'); $old=(int)expiry_get_ts($store); $base=$old>time()?$old:time();
    $dt=new DateTime('@'.$base); $dt->setTimezone($tz);
    if($duration['months']>0)$dt->modify('+'.$duration['months'].' months');
    if($duration['days']>0)$dt->modify('+'.$duration['days'].' days');
    $dt->setTime(23,59,59); $new=$dt->getTimestamp();
    expiry_set_ts($store,$new,['source'=>'gift_wheel','actor'=>$store,'code'=>$code,'winner'=>$winner,'days'=>$duration['days'],'months'=>$duration['months']]); premium_set($store,true);
    notif_add_message('Hadiah Roda Diterima',"Selamat, Anda mendapatkan {$winner}. Expired akun otomatis bertambah sampai ".date('d/m/Y H:i',$new).' WIB.',$store);
    return ['expiryTs'=>$new];
  }
  if(gift_wheel_requires_phone($winner)){
    notif_add_message('Hadiah Roda Diterima',"Selamat, Anda mendapatkan {$winner}. Isi nomor HP pada halaman roda agar hadiah dapat diproses admin.",$store);
    return ['requires_phone'=>true];
  }
  notif_add_message('Hadiah Roda Diterima',"Selamat, Anda mendapatkan {$winner} dari kode {$code}.",$store);
  return [];
}
function gift_wheel_spin_once($code,$store){
  $code=gift_wheel_normalize_code($code); $store=strtoupper(preg_replace('/[^A-Z0-9]/','',(string)$store));
  $fp=@fopen(GIFT_WHEEL_FILE,'c+'); if(!$fp)return ['ok'=>false,'msg'=>'Penyimpanan roda tidak tersedia']; @flock($fp,LOCK_EX); rewind($fp); $raw=stream_get_contents($fp); $d=json_decode((string)$raw,true); if(!is_array($d))$d=['items'=>[]]; if(!isset($d['items'])||!is_array($d['items']))$d['items']=[];
  $idx=-1; foreach($d['items'] as $i=>$it){if(strtoupper((string)($it['code']??''))===$code){$idx=$i;break;}} if($idx<0){@flock($fp,LOCK_UN);@fclose($fp);return ['ok'=>false,'msg'=>'Kode roda tidak ditemukan'];}
  $it=$d['items'][$idx]; if(!empty($it['used'])){@flock($fp,LOCK_UN);@fclose($fp);return ['ok'=>false,'msg'=>'Kode ini sudah pernah digunakan'];}
  $prizes=gift_wheel_allowed_prizes(); $winner=(string)($it['winner']??'Coba Lagi'); if(!in_array($winner,$prizes,true))$winner='Coba Lagi'; $wi=array_search($winner,$prizes,true);
  $d['items'][$idx]['used']=true;$d['items'][$idx]['used_by']=$store;$d['items'][$idx]['reward_applied']=true;$d['items'][$idx]['used_at']=date('c');$d['updated_at']=date('c'); rewind($fp);ftruncate($fp,0);fwrite($fp,json_encode($d,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));fflush($fp);@flock($fp,LOCK_UN);@fclose($fp);
  $extra=gift_wheel_apply_reward($store,$winner,$code);
  gift_wheel_mark_request_consumed($store,$code);
  return ['ok'=>true,'winner'=>$winner,'prizes'=>$prizes,'winner_index'=>(int)$wi,'requires_phone'=>gift_wheel_requires_phone($winner),'requires_wallet'=>gift_wheel_is_emoney($winner)]+$extra;
}

function gift_wheel_delete_code_internal($code){
  $code=gift_wheel_normalize_code($code);
  if($code==='') return false;
  $d=gift_wheel_read();
  $before=count($d['items']);
  $d['items']=array_values(array_filter($d['items'],function($it) use ($code){ return strtoupper((string)($it['code']??''))!==$code; }));
  if(count($d['items'])===$before) return false;
  $d['updated_at']=date('c');
  return json_file_write_array_safe(GIFT_WHEEL_FILE,$d);
}
function gift_wheel_save_phone($code,$store,$phone,$wallet=''){
  $code=gift_wheel_normalize_code($code);
  $store=strtoupper(preg_replace('/[^A-Z0-9]/','',(string)$store));
  $phone=substr(preg_replace('/[^0-9]/','',(string)$phone),0,13);
  $wallet=gift_wheel_normalize_wallet($wallet);
  if($code==='' || $store==='') return ['ok'=>false,'msg'=>'Data hadiah tidak valid'];
  if(strlen($phone)<8 || strlen($phone)>13) return ['ok'=>false,'msg'=>'Nomor HP harus 8 sampai 13 angka'];
  $fp=@fopen(GIFT_WHEEL_FILE,'c+');
  if(!$fp) return ['ok'=>false,'msg'=>'Penyimpanan roda tidak tersedia'];
  @flock($fp,LOCK_EX); rewind($fp); $raw=stream_get_contents($fp); $d=json_decode((string)$raw,true);
  if(!is_array($d))$d=['items'=>[]]; if(!isset($d['items'])||!is_array($d['items']))$d['items']=[];
  $idx=-1;
  foreach($d['items'] as $i=>$it){ if(strtoupper((string)($it['code']??''))===$code){$idx=$i;break;} }
  if($idx<0){@flock($fp,LOCK_UN);@fclose($fp);return ['ok'=>false,'msg'=>'Kode roda tidak ditemukan'];}
  $it=$d['items'][$idx];
  if(empty($it['used']) || strtoupper((string)($it['used_by']??''))!==$store){@flock($fp,LOCK_UN);@fclose($fp);return ['ok'=>false,'msg'=>'Hadiah ini bukan milik akun Anda'];}
  $winner=(string)($it['winner']??'');
  if(!gift_wheel_requires_phone($winner)){@flock($fp,LOCK_UN);@fclose($fp);return ['ok'=>false,'msg'=>'Hadiah ini tidak memerlukan nomor HP'];}
  if(gift_wheel_is_emoney($winner) && $wallet===''){@flock($fp,LOCK_UN);@fclose($fp);return ['ok'=>false,'msg'=>'Pilih jenis saldo E-money'];}
  if(!gift_wheel_is_emoney($winner))$wallet='';
  $d['items'][$idx]['wallet_type']=$wallet;
  $d['items'][$idx]['phone_number']=$phone;
  $d['items'][$idx]['phone_submitted_at']=date('c');
  $d['updated_at']=date('c');
  rewind($fp); ftruncate($fp,0); $ok=fwrite($fp,json_encode($d,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)); fflush($fp); @flock($fp,LOCK_UN); @fclose($fp);
  if($ok===false) return ['ok'=>false,'msg'=>'Gagal menyimpan nomor HP'];
  $walletText=$wallet!==''?$wallet.' - ':'';
  notif_add_message('Nomor Hadiah Tersimpan',"Tujuan {$walletText}{$phone} untuk hadiah {$winner} berhasil dikirim ke admin.",$store);
  notif_add_message('Hadiah Roda Perlu Diproses',"Toko {$store} memenangkan {$winner}. Tujuan: {$walletText}{$phone}. Buka menu Admin > Roda untuk melihat detail.",notif_developer_target());
  return ['ok'=>true,'phone'=>$phone,'wallet'=>$wallet,'winner'=>$winner];
}

function gift_wheel_request_default(){ return ['items'=>[],'updated_at'=>null]; }
function gift_wheel_request_read(){
  $d=json_file_read_array_safe(GIFT_WHEEL_REQUEST_FILE,gift_wheel_request_default());
  if(!isset($d['items'])||!is_array($d['items']))$d['items']=[];
  return $d;
}
function gift_wheel_request_write($d){
  if(!is_array($d))$d=gift_wheel_request_default();
  if(!isset($d['items'])||!is_array($d['items']))$d['items']=[];
  $d['items']=array_slice(array_values($d['items']),0,500);
  $d['updated_at']=date('c');
  return json_file_write_array_safe(GIFT_WHEEL_REQUEST_FILE,$d);
}
function gift_wheel_request_id(){
  try{return 'WR-'.strtoupper(bin2hex(random_bytes(6)));}catch(Throwable $e){return 'WR-'.strtoupper(substr(sha1(uniqid('',true).mt_rand()),0,12));}
}
function gift_wheel_request_public($row,$admin=false){
  $out=[
    'id'=>(string)($row['id']??''),'storeId'=>(string)($row['storeId']??''),'status'=>(string)($row['status']??'pending'),
    'cost_days'=>(int)($row['cost_days']??7),'created_at'=>(string)($row['created_at']??''),'decided_at'=>(string)($row['decided_at']??''),
    'code'=>(string)($row['code']??''),'old_expiry_ts'=>(int)($row['old_expiry_ts']??0),'new_expiry_ts'=>(int)($row['new_expiry_ts']??0),
    'remaining_days_at_request'=>(int)($row['remaining_days_at_request']??0),'message'=>(string)($row['message']??''),
    'consumed_at'=>(string)($row['consumed_at']??'')
  ];
  if($admin)$out['winner']=(string)($row['winner']??'');
  return $out;
}
function gift_wheel_request_for_store($store){
  $store=strtoupper(preg_replace('/[^A-Z0-9]/','',(string)$store));
  $d=gift_wheel_request_read(); $rows=[];
  foreach($d['items'] as $row){
    if(strtoupper((string)($row['storeId']??''))===$store && trim((string)($row['consumed_at']??''))==='')$rows[]=gift_wheel_request_public($row,false);
  }
  return array_slice($rows,0,20);
}
function gift_wheel_request_create($store){
  $store=strtoupper(preg_replace('/[^A-Z0-9]/','',(string)$store));
  if($store==='')return ['ok'=>false,'msg'=>'Silakan login ulang'];
  $old=(int)expiry_get_ts($store); $now=jakarta_now_ts(); $new=$old-(7*ONE_DAY_SEC);
  if($old<=0 || $new<=$now)return ['ok'=>false,'msg'=>'Sisa expired tidak cukup. Setelah ditukar 7 hari akun harus tetap aktif.'];
  $d=gift_wheel_request_read();
  foreach($d['items'] as $row){
    if(strtoupper((string)($row['storeId']??''))===$store && (string)($row['status']??'')==='pending')return ['ok'=>false,'msg'=>'Masih ada permintaan kode yang menunggu approval admin.'];
  }
  $row=['id'=>gift_wheel_request_id(),'storeId'=>$store,'status'=>'pending','cost_days'=>7,'remaining_days_at_request'=>expiry_remaining_days($store),'old_expiry_ts'=>$old,'new_expiry_ts'=>0,'code'=>'','winner'=>'','message'=>'Menunggu approval admin','created_at'=>date('c'),'decided_at'=>'','decided_by'=>'','consumed_at'=>''];
  array_unshift($d['items'],$row);
  if(!gift_wheel_request_write($d))return ['ok'=>false,'msg'=>'Gagal menyimpan permintaan kode'];
  notif_add_message('Permintaan Kode Roda',"Toko {$store} meminta 1 kode roda dengan menukar 7 hari expired.",notif_developer_target());
  return ['ok'=>true,'item'=>gift_wheel_request_public($row,false),'remaining_days'=>expiry_remaining_days($store)];
}
function gift_wheel_mark_request_consumed($store,$code){
  $store=strtoupper(preg_replace('/[^A-Z0-9]/','',(string)$store));
  $code=gift_wheel_normalize_code($code);
  if($store==='' || $code==='')return false;
  $lock=@fopen(GIFT_WHEEL_REQUEST_FILE.'.lock','c+'); if($lock)@flock($lock,LOCK_EX);
  $d=gift_wheel_request_read(); $changed=false;
  foreach($d['items'] as $i=>$row){
    if(strtoupper((string)($row['storeId']??''))===$store && strtoupper((string)($row['code']??''))===$code){
      $d['items'][$i]['consumed_at']=date('c');
      $d['items'][$i]['message']='Kode sudah digunakan. Riwayat otomatis dihapus dari halaman user.';
      $changed=true;
      break;
    }
  }
  $ok=$changed?gift_wheel_request_write($d):false;
  if($lock){@flock($lock,LOCK_UN);@fclose($lock);}
  return $ok;
}
function gift_wheel_request_clear_history(){
  $lock=@fopen(GIFT_WHEEL_REQUEST_FILE.'.lock','c+'); if($lock)@flock($lock,LOCK_EX);
  $d=gift_wheel_request_read(); $before=count($d['items']);
  $d['items']=array_values(array_filter($d['items'],function($row){return (string)($row['status']??'pending')==='pending';}));
  $deleted=$before-count($d['items']);
  $ok=gift_wheel_request_write($d);
  if($lock){@flock($lock,LOCK_UN);@fclose($lock);}
  return $ok?['ok'=>true,'deleted'=>$deleted]:['ok'=>false,'msg'=>'Gagal menghapus riwayat approval'];
}
function gift_wheel_expiry_write_exact($store,$ts){
  $store=strtoupper(preg_replace('/[^A-Z0-9]/','',(string)$store)); $ts=(int)$ts;
  if($store==='' || $ts<=0)return false;
  $all=expiry_read_all(); if(!isset($all['stores'])||!is_array($all['stores']))$all['stores']=[];
  $all['stores'][$store]=$ts;
  return json_file_write_array_safe(EXPIRY_FILE,['stores'=>$all['stores'],'updatedAt'=>date('c')]);
}
function gift_wheel_request_decide($id,$decision,$actor,$winner=''){
  $id=preg_replace('/[^A-Za-z0-9_-]/','',(string)$id);
  $decision=strtolower(trim((string)$decision));
  $actor=strtoupper(preg_replace('/[^A-Z0-9]/','',(string)$actor));
  $winner=trim(strip_tags((string)$winner));
  if($id==='' || !in_array($decision,['approve','reject'],true))return ['ok'=>false,'msg'=>'Permintaan tidak valid'];
  $lock=@fopen(GIFT_WHEEL_REQUEST_FILE.'.lock','c+'); if($lock)@flock($lock,LOCK_EX);
  $d=gift_wheel_request_read(); $idx=-1;
  foreach($d['items'] as $i=>$row){if((string)($row['id']??'')===$id){$idx=$i;break;}}
  if($idx<0){if($lock){@flock($lock,LOCK_UN);@fclose($lock);}return ['ok'=>false,'msg'=>'Permintaan tidak ditemukan'];}
  $row=$d['items'][$idx];
  if((string)($row['status']??'')!=='pending'){if($lock){@flock($lock,LOCK_UN);@fclose($lock);}return ['ok'=>false,'msg'=>'Permintaan sudah diproses'];}
  $store=strtoupper(preg_replace('/[^A-Z0-9]/','',(string)($row['storeId']??'')));
  if($decision==='reject'){
    $row['status']='rejected';$row['message']='Permintaan ditolak admin';$row['decided_at']=date('c');$row['decided_by']=$actor;
    $d['items'][$idx]=$row; $ok=gift_wheel_request_write($d);
    if($lock){@flock($lock,LOCK_UN);@fclose($lock);}
    if(!$ok)return ['ok'=>false,'msg'=>'Gagal menyimpan keputusan'];
    notif_add_message('Permintaan Kode Roda Ditolak','Permintaan tukar 7 hari expired ditolak admin. Masa aktif Anda tidak dikurangi.',$store);
    return ['ok'=>true,'item'=>gift_wheel_request_public($row,true)];
  }
  if(!in_array($winner,gift_wheel_allowed_prizes(),true)){if($lock){@flock($lock,LOCK_UN);@fclose($lock);}return ['ok'=>false,'msg'=>'Pilih hadiah untuk user sebelum approval'];}
  $old=(int)expiry_get_ts($store); $new=$old-(7*ONE_DAY_SEC); $now=jakarta_now_ts();
  if($old<=0 || $new<=$now){if($lock){@flock($lock,LOCK_UN);@fclose($lock);}return ['ok'=>false,'msg'=>'Sisa expired user tidak cukup untuk dipotong 7 hari'];}
  if(!gift_wheel_expiry_write_exact($store,$new)){if($lock){@flock($lock,LOCK_UN);@fclose($lock);}return ['ok'=>false,'msg'=>'Gagal mengurangi expired user'];}
  $created=null;
  for($try=0;$try<5;$try++){
    $code=gift_wheel_random_code(); $created=gift_wheel_create($code,$winner,[]);
    if(!empty($created['ok']))break;
  }
  if(empty($created['ok'])){
    gift_wheel_expiry_write_exact($store,$old);
    if($lock){@flock($lock,LOCK_UN);@fclose($lock);}
    return ['ok'=>false,'msg'=>'Gagal membuat kode roda. Expired tidak jadi dikurangi.'];
  }
  $code=(string)$created['code'];
  $row['status']='approved';$row['message']='Disetujui. Expired dikurangi 7 hari dan kode siap digunakan.';$row['old_expiry_ts']=$old;$row['new_expiry_ts']=$new;$row['code']=$code;$row['winner']=$winner;$row['decided_at']=date('c');$row['decided_by']=$actor;
  $d['items'][$idx]=$row;
  if(!gift_wheel_request_write($d)){
    gift_wheel_delete_code_internal($code); gift_wheel_expiry_write_exact($store,$old);
    if($lock){@flock($lock,LOCK_UN);@fclose($lock);}
    return ['ok'=>false,'msg'=>'Gagal menyimpan approval. Perubahan dibatalkan.'];
  }
  if($lock){@flock($lock,LOCK_UN);@fclose($lock);}
  notif_add_message('Kode Roda Disetujui',"Permintaan disetujui. Expired berkurang 7 hari. Kode roda Anda: {$code}",$store);
  return ['ok'=>true,'item'=>gift_wheel_request_public($row,true),'remaining_days'=>expiry_remaining_days($store)];
}

function json_out($arr, $code=200){
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');
  echo json_encode($arr, JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0));
  exit;
}
function read_store_db(){
  global $DEFAULT_STORES;
  $init = ["stores"=>$DEFAULT_STORES, "createdMap"=>[], "updatedAt"=>date('c')];
  if(!file_exists(STORE_DB_FILE)){
    json_file_write_array_safe(STORE_DB_FILE, $init);
    return $init;
  }
  $j = json_file_read_array_safe(STORE_DB_FILE, $init);
  if(!is_array($j) || !isset($j['stores']) || !is_array($j['stores'])){
    $j = $init;
  }
  $stores = [];
  $removedExpired = false;
  foreach($j['stores'] as $s){
    $s = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$s));
    if($s==='' || in_array($s,$stores,true)) continue;
    if($s !== ADMIN_STORE_ID && is_store_expired($s)){
      $removedExpired = true;
      expiry_set_ts($s, 0);
      pin_delete($s);
      if(function_exists('pin_sogrand_delete')) pin_sogrand_delete($s);
      premium_delete($s);
      admin2_delete($s);
      oh979_delete($s);
      plano_delete_store($s);
      continue;
    }
    $stores[] = $s;
  }
  // Pertahankan urutan input; user baru tetap di bawah.
  
  $updatedAt = $j['updatedAt'] ?? date('c');
  if($removedExpired){
    $saved = write_store_db($stores);
    $updatedAt = $saved['updatedAt'] ?? date('c');
  }
  if(!isset($j['createdMap']) || !is_array($j['createdMap'])) $j['createdMap'] = [];
  $nowIso = date('c');
  foreach($stores as $st){ if(empty($j['createdMap'][$st])) $j['createdMap'][$st] = ($st === ADMIN_STORE_ID ? ($j['updatedAt'] ?? $nowIso) : $nowIso); }
  foreach(array_keys($j['createdMap']) as $st){ if(!in_array($st, $stores, true)) unset($j['createdMap'][$st]); }
  $j['stores']=$stores;
  $j['updatedAt']=$updatedAt;
  return $j;
}
function write_store_db($stores){
  $stores = array_values(array_unique(array_map(function($s){
    return strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$s));
  }, $stores)));
  $stores = array_values(array_filter($stores, fn($x)=>$x!==''));
  // Pertahankan urutan input; user baru tetap di bawah.
  $old = file_exists(STORE_DB_FILE) ? json_file_read_array_safe(STORE_DB_FILE, ["stores"=>[], "createdMap"=>[], "updatedAt"=>date('c')]) : ["stores"=>[], "createdMap"=>[], "updatedAt"=>date('c')];
  $createdMap = is_array($old['createdMap'] ?? null) ? $old['createdMap'] : [];
  $nowIso = date('c');
  foreach($stores as $st){ if(empty($createdMap[$st])) $createdMap[$st] = $nowIso; }
  foreach(array_keys($createdMap) as $st){ if(!in_array($st, $stores, true)) unset($createdMap[$st]); }
  $payload = ["stores"=>$stores, "createdMap"=>$createdMap, "updatedAt"=>$nowIso];
  json_file_write_array_safe(STORE_DB_FILE, $payload);
  return $payload;
}
function hmac_sign($data){ return hash_hmac('sha256', $data, COOKIE_SECRET); }

/* =========================
   ADMIN2 HELPERS
   - disimpan di file JSON (tanpa DB)
   - format: { "stores": { "M10": true, ... }, "updatedAt": "..." }
========================= */
function admin2_read_all(){
  if(!file_exists(ADMIN2_FILE)) return ["stores"=>[], "updatedAt"=>null];
  $j = json_file_read_array_safe(ADMIN2_FILE, ["stores"=>[], "updatedAt"=>null]);
  if(!is_array($j)) return ["stores"=>[], "updatedAt"=>null];
  if(!isset($j["stores"]) || !is_array($j["stores"])) $j["stores"] = [];
  if(!isset($j["categories"]) || !is_array($j["categories"])) $j["categories"] = [];
  if(!isset($j["customRaks"]) || !is_array($j["customRaks"])) $j["customRaks"] = [];
  return $j;
}
function admin2_write_all($map){
  $clean = [];
  foreach((array)$map as $k=>$v){
    $sid = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$k));
    if($sid!=='' && $v) $clean[$sid] = true;
  }
  ksort($clean, SORT_NATURAL|SORT_FLAG_CASE);
  $payload = ["stores"=>$clean, "updatedAt"=>date('c')];
  @file_put_contents(ADMIN2_FILE, json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
  return $payload;
}
function admin2_get($storeId){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  if($storeId==='') return false;
  // Admin utama bukan Admin2. Hak aksesnya terpisah.
  if($storeId === ADMIN_STORE_ID) return false;
  $all = admin2_read_all();
  return !empty($all["stores"][$storeId]);
}
function admin2_set($storeId, $isAdmin2){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  if($storeId==='' || $storeId===ADMIN_STORE_ID) return false;
  $all = admin2_read_all();
  if($isAdmin2) $all["stores"][$storeId] = true;
  else unset($all["stores"][$storeId]);
  admin2_write_all($all["stores"]);
  return true;
}
function admin2_delete($storeId){ return admin2_set($storeId, false); }


/* =========================
   ONHAND PARSIAL HELPERS
========================= */
function normalize_plus_ohp($raw){
  if(is_array($raw)){
    $raw = implode(',', array_map(function($v){ return is_scalar($v) ? (string)$v : ''; }, $raw));
  }
  $text = trim((string)$raw);
  if($text==='') return [];
  preg_match_all('/\d{1,}/', $text, $m);
  $arr = [];
  $seen = [];
  foreach(($m[0] ?? []) as $n){
    $n = trim((string)$n);
    if($n==='') continue;
    if(isset($seen[$n])) continue;
    $seen[$n] = true;
    $arr[] = $n;
  }
  return array_values($arr);
}
function load_onhand_storage(){
  if(!file_exists(ONHAND_STORAGE_FILE)) return ["lists"=>[]];
  $json = json_decode(@file_get_contents(ONHAND_STORAGE_FILE), true);
  return is_array($json) ? $json : ["lists"=>[]];
}
function save_onhand_storage($data){
  @file_put_contents(ONHAND_STORAGE_FILE, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
}

/* =========================
   CHAT HELPERS
   - group chat global antar user
========================= */
function chat_u_substr($text, $length){
  $text = (string)$text;
  return function_exists('mb_substr') ? mb_substr($text, 0, $length, 'UTF-8') : substr($text, 0, $length);
}
function chat_u_strlen($text){
  $text = (string)$text;
  return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
}
function chat_read_all(){
  if(!file_exists(CHAT_STORAGE_FILE)) return ["messages"=>[], "updatedAt"=>null];
  $j = json_decode(@file_get_contents(CHAT_STORAGE_FILE), true);
  if(!is_array($j)) return ["messages"=>[], "updatedAt"=>null];
  if(!isset($j['messages']) || !is_array($j['messages'])) $j['messages'] = [];
  return $j;
}
function chat_extract_bracket_alias($name){
  $name = trim(preg_replace('/\s+/u', ' ', (string)$name));
  if($name === '' || $name === '-') return '';
  if(preg_match('/\[([^\]\r\n]{1,30})\]/u', $name, $m)){
    $alias = trim(preg_replace('/\s+/u', ' ', (string)($m[1] ?? '')));
    if($alias !== '') return '[' . chat_u_substr($alias, 30) . ']';
  }
  return '';
}
function chat_clean_store_name($name, $storeId=''){
  $name = trim(preg_replace('/\s+/u', ' ', (string)$name));
  if($name === '' || $name === '-') return '';
  // Nama lengkap untuk Developer: buang semua kode alias [....] dari nama toko.
  $name = trim(preg_replace('/\s*\[[^\]]{1,30}\]\s*/u', ' ', $name));
  $name = trim(preg_replace('/\s+/u', ' ', $name));
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  if($storeId !== ''){
    $name = trim(preg_replace('/^\s*' . preg_quote($storeId, '/') . '\s*(?:[-|:]+)\s*/iu', '', $name));
  }
  return chat_u_substr($name, 80);
}
function chat_store_raw_name($storeId){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  if($storeId === '') return '';
  $detail = function_exists('store_detail_fetch_cached') ? store_detail_fetch_cached($storeId, 604800) : null;
  return is_array($detail) ? trim((string)($detail['header2'] ?? '')) : '';
}
function chat_user_alias($storeId, $rawName=''){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  $alias = chat_extract_bracket_alias($rawName);
  if($alias === '' && $storeId !== '') $alias = chat_extract_bracket_alias(chat_store_raw_name($storeId));
  // Fallback tetap ringkas bila data status toko tidak mempunyai kode [....].
  return $alias !== '' ? $alias : ($storeId !== '' ? '['.$storeId.']' : '[USER]');
}
function chat_developer_user_name($storeId, $rawName=''){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  if($storeId === '') return 'User';
  if(trim((string)$rawName) === '') $rawName = chat_store_raw_name($storeId);
  $clean = chat_clean_store_name($rawName, $storeId);
  return $clean !== '' ? ($storeId . ' | ' . $clean) : $storeId;
}
function chat_store_name($storeId){
  // Tampilan default user biasa selalu disamarkan memakai kode dalam [....].
  return chat_user_alias($storeId, chat_store_raw_name($storeId));
}
function chat_display_name($storeId, $isDeveloper=false){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  if($storeId === '') return 'User';
  if($isDeveloper) return 'Developer';
  return chat_store_name($storeId);
}
function chat_enrich_messages($messages, $viewerIsDeveloper=false){
  $messages = is_array($messages) ? $messages : [];
  $storeIds = [];
  foreach($messages as $m){
    if(!is_array($m) || !empty($m['isDeveloper'])) continue;
    $sid = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)($m['storeId'] ?? '')));
    if($sid !== '' && !in_array($sid, $storeIds, true)) $storeIds[] = $sid;
  }
  $nameMap = function_exists('store_names_fetch_batch') ? store_names_fetch_batch($storeIds, 604800) : [];
  $out = [];
  foreach($messages as $m){
    if(!is_array($m)) continue;
    $sid = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)($m['storeId'] ?? '')));
    if($sid === '') continue;
    $legacyName = trim((string)($m['name'] ?? ''));
    $isDeveloper = !empty($m['isDeveloper']);
    // Migrasi pesan lama yang dulu menyimpan M604 sebagai "Developer".
    if(!$isDeveloper && $sid === ADMIN_STORE_ID && strcasecmp($legacyName, 'Developer') === 0) $isDeveloper = true;
    if($isDeveloper){
      $displayName = 'Developer';
    }else{
      $rawName = trim((string)($nameMap[$sid] ?? ''));
      if($rawName === '') $rawName = $legacyName;
      if($viewerIsDeveloper){
        // Developer melihat identitas toko penuh, contoh: M650 | KARANG JATI.
        $displayName = chat_developer_user_name($sid, $rawName);
      }else{
        // User biasa hanya melihat kode alias dalam kurung, contoh: [KOPN].
        $displayName = chat_user_alias($sid, $rawName);
      }
    }
    $m['storeId'] = $sid;
    $m['name'] = $displayName;
    $m['isDeveloper'] = $isDeveloper;
    $out[] = $m;
  }
  return $out;
}
function chat_write_all($messages){
  $clean = [];
  foreach((array)$messages as $m){
    if(!is_array($m)) continue;
    $id = preg_replace('/[^a-zA-Z0-9_-]/','', (string)($m['id'] ?? ''));
    $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)($m['storeId'] ?? '')));
    $isDeveloper = !empty($m['isDeveloper']);
    $rawStoredName = trim(preg_replace('/\s+/u', ' ', (string)($m['name'] ?? '')));
    $name = $isDeveloper ? 'Developer' : chat_u_substr($rawStoredName, 120);
    $text = trim((string)($m['text'] ?? ''));
    $createdAt = (string)($m['createdAt'] ?? '');
    $createdTs = (int)($m['createdTs'] ?? 0);
    if($id==='' || $storeId==='' || $text==='') continue;
    if($createdAt==='') $createdAt = date('c');
    if($createdTs <= 0) $createdTs = time();
    $clean[] = [
      'id' => $id,
      'storeId' => $storeId,
      'name' => $name !== '' ? $name : $storeId,
      'isDeveloper' => $isDeveloper,
      'text' => chat_u_substr($text, 1000),
      'createdAt' => $createdAt,
      'createdTs' => $createdTs,
    ];
  }
  usort($clean, function($a,$b){ return ((int)$a['createdTs']) <=> ((int)$b['createdTs']); });
  if(count($clean) > 300) $clean = array_slice($clean, -300);
  $payload = ['messages'=>$clean, 'updatedAt'=>date('c')];
  @file_put_contents(CHAT_STORAGE_FILE, json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), LOCK_EX);
  return $payload;
}
function chat_add_message($storeId, $text, $isDeveloper=false){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  $text = trim((string)$text);
  if($storeId === '' || $text === '') return false;
  $all = chat_read_all();
  $messages = is_array($all['messages'] ?? null) ? $all['messages'] : [];
  $messages[] = [
    'id' => 'msg_' . date('YmdHis') . '_' . substr(md5($storeId . '|' . microtime(true) . '|' . $text), 0, 10),
    'storeId' => $storeId,
    'name' => $isDeveloper ? 'Developer' : (chat_store_raw_name($storeId) ?: chat_display_name($storeId, false)),
    'isDeveloper' => (bool)$isDeveloper,
    'text' => chat_u_substr($text, 1000),
    'createdAt' => date('c'),
    'createdTs' => time(),
  ];
  return chat_write_all($messages);
}
function chat_delete_message($messageId){
  $messageId = preg_replace('/[^a-zA-Z0-9_-]/','', (string)$messageId);
  if($messageId === '') return false;
  $all = chat_read_all();
  $messages = is_array($all['messages'] ?? null) ? $all['messages'] : [];
  $before = count($messages);
  $messages = array_values(array_filter($messages, function($m) use ($messageId){
    return (string)($m['id'] ?? '') !== $messageId;
  }));
  if(count($messages) === $before) return false;
  return chat_write_all($messages);
}
function chat_delete_all_messages(){
  return chat_write_all([]);
}

/* =========================
   PLANOGRAM SAVED RAK HELPERS
   - disimpan di file JSON (tanpa DB)
   - format: { "stores": { "M604": { "ZS1": {"plus":[...],"updatedAt":"..."}, ... } }, "updatedAt":"..." }
========================= */
function plano_read_all(){
  if(!file_exists(PLANOGRAM_SAVED_FILE)) return ["stores"=>[], "updatedAt"=>null];
  $j = json_decode(@file_get_contents(PLANOGRAM_SAVED_FILE), true);
  if(!is_array($j)) return ["stores"=>[], "updatedAt"=>null];
  if(!isset($j["stores"]) || !is_array($j["stores"])) $j["stores"] = [];
  if(!isset($j["categories"]) || !is_array($j["categories"])) $j["categories"] = [];
  if(!isset($j["customRaks"]) || !is_array($j["customRaks"])) $j["customRaks"] = [];
  return $j;
}
function plano_write_all($storesMap){
  $payload = ["stores"=>$storesMap, "updatedAt"=>date('c')];
  @file_put_contents(PLANOGRAM_SAVED_FILE, json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
  return $payload;
}
function plano_list_raks($storeId){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  if($storeId==='') return [];
  $all = plano_read_all();
  $m = $all["stores"][$storeId] ?? [];
  if(!is_array($m)) $m = [];
  $names = array_keys($m);
  sort($names, SORT_NATURAL|SORT_FLAG_CASE);
  return $names;
}
function plano_get_rak($storeId, $rak){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  $rak = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$rak));
  if($storeId==='' || $rak==='') return null;
  $all = plano_read_all();
  $m = $all["stores"][$storeId] ?? [];
  if(!is_array($m)) return null;
  return $m[$rak] ?? null;
}
function plano_set_rak($storeId, $rak, $plusArr){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  $rak = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$rak));
  if($storeId==='' || $rak==='') return false;
  $all = plano_read_all();
  if(!isset($all["stores"]) || !is_array($all["stores"])) $all["stores"] = [];
  if(!isset($all["stores"][$storeId]) || !is_array($all["stores"][$storeId])) $all["stores"][$storeId] = [];
  $plusArr = is_array($plusArr) ? $plusArr : [];
  $plusArr = array_values(array_unique(array_filter(array_map(function($x){
    $x = preg_replace('/[^0-9]/','', (string)$x);
    return $x !== '' ? $x : null;
  }, $plusArr))));
  sort($plusArr, SORT_NUMERIC);
  $all["stores"][$storeId][$rak] = ["plus"=>$plusArr, "updatedAt"=>date('c')];
  plano_write_all($all["stores"]);
  return true;
}
function plano_delete_rak($storeId, $rak){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  $rak = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$rak));
  if($storeId==='' || $rak==='') return false;
  $all = plano_read_all();
  if(isset($all["stores"][$storeId]) && is_array($all["stores"][$storeId]) && isset($all["stores"][$storeId][$rak])){
    unset($all["stores"][$storeId][$rak]);
    plano_write_all($all["stores"]);
  }
  return true;
}

function plano_delete_store($storeId){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  if($storeId==='') return false;
  $all = plano_read_all();
  if(isset($all["stores"]) && is_array($all["stores"]) && array_key_exists($storeId, $all["stores"])){
    unset($all["stores"][$storeId]);
    plano_write_all($all["stores"]);
  }
  return true;
}

function plano_get_store_raks($storeId){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  if($storeId==='') return [];
  $all = plano_read_all();
  $rows = [];
  foreach((array)($all["stores"][$storeId] ?? []) as $rak=>$row){
    $rakName = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$rak));
    if($rakName==='') continue;
    $plusArr = normalize_plus_ohp(is_array($row) ? ($row['plus'] ?? []) : $row);
    if(empty($plusArr)) continue;
    $rows[] = [
      'rak' => $rakName,
      'plusArr' => $plusArr,
      'plus' => implode(',', $plusArr),
      'updatedAt' => is_array($row) && !empty($row['updatedAt']) ? (string)$row['updatedAt'] : null
    ];
  }
  usort($rows, function($a, $b){ return strnatcasecmp((string)$a['rak'], (string)$b['rak']); });
  return $rows;
}
function oh979_sync_from_planogram($storeId){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  if($storeId==='') return false;
  $raks = plano_get_store_raks($storeId);
  $plus = [];
  foreach($raks as $row){
    foreach((array)($row['plusArr'] ?? []) as $plu){
      $plu = trim((string)$plu);
      if($plu!=='') $plus[] = $plu;
    }
  }
  $plus = normalize_plus_ohp($plus);
  if(empty($plus)){
    oh979_delete($storeId);
    return false;
  }
  oh979_set($storeId, $plus);
  return true;
}

/* =========================
   OH Custom CONFIG HELPERS
   - disimpan di file JSON
   - format: { "stores": { "M604": {"plus": [...], "updatedAt": "..."} }, "updatedAt": "..." }
========================= */
function oh979_read_all(){
  if(!file_exists(OH979_CONFIG_FILE)) return ["stores"=>[], "updatedAt"=>null];
  $j = json_decode(@file_get_contents(OH979_CONFIG_FILE), true);
  if(!is_array($j)) return ["stores"=>[], "updatedAt"=>null];
  if(!isset($j["stores"]) || !is_array($j["stores"])) $j["stores"] = [];
  if(!isset($j["categories"]) || !is_array($j["categories"])) $j["categories"] = [];
  if(!isset($j["customRaks"]) || !is_array($j["customRaks"])) $j["customRaks"] = [];
  return $j;
}
function oh979_write_all($storesMap){
  $clean = [];
  foreach((array)$storesMap as $sid=>$row){
    $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$sid));
    if($storeId==='') continue;
    $plus = normalize_plus_ohp(is_array($row) ? ($row['plus'] ?? []) : $row);
    if(empty($plus)) continue;
    $clean[$storeId] = [
      'plus' => $plus,
      'updatedAt' => is_array($row) && !empty($row['updatedAt']) ? (string)$row['updatedAt'] : date('c')
    ];
  }
  ksort($clean, SORT_NATURAL|SORT_FLAG_CASE);
  $oldPayload = oh979_read_all();
  $payload = ["stores"=>$clean, "categories"=>(is_array($oldPayload['categories'] ?? null) ? $oldPayload['categories'] : []), "customRaks"=>(is_array($oldPayload['customRaks'] ?? null) ? $oldPayload['customRaks'] : []), "updatedAt"=>date('c')];
  @file_put_contents(OH979_CONFIG_FILE, json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
  return $payload;
}
function oh979_get($storeId){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  if($storeId==='') return null;
  $all = oh979_read_all();
  $row = $all['stores'][$storeId] ?? null;
  if(!is_array($row)) return null;
  $plus = normalize_plus_ohp($row['plus'] ?? []);
  if(empty($plus)) return null;
  return [
    'storeId' => $storeId,
    'plus' => implode(',', $plus),
    'plusArr' => $plus,
    'updatedAt' => $row['updatedAt'] ?? null
  ];
}
function oh979_set($storeId, $plusRaw){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  $plus = normalize_plus_ohp($plusRaw);
  if($storeId==='' || empty($plus)) return false;
  $all = oh979_read_all();
  $all['stores'][$storeId] = ['plus'=>$plus, 'updatedAt'=>date('c')];
  oh979_write_all($all['stores']);
  return oh979_get($storeId);
}
function oh979_delete($storeId){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  if($storeId==='') return false;
  $all = oh979_read_all();
  if(isset($all['stores'][$storeId])){
    unset($all['stores'][$storeId]);
    oh979_write_all($all['stores']);
  }
  return true;
}

function oh979_norm_type($type){
  $type = strtolower(preg_replace('/[^a-z]/','', (string)$type));
  if($type === 'beanspot') return 'beanspot';
  if($type === 'strokok') return 'strokok';
  return 'reguler';
}
function oh979_type_label($type){
  return oh979_norm_type($type) === 'beanspot' ? 'Rack 979 Beanspot' : (oh979_norm_type($type) === 'strokok' ? 'Rack 000' : 'Rack 979 Reguler');
}
function oh979_write_payload($payload){
  if(!is_array($payload)) $payload = [];
  if(!isset($payload['stores']) || !is_array($payload['stores'])) $payload['stores'] = [];
  if(!isset($payload['categories']) || !is_array($payload['categories'])) $payload['categories'] = [];
  if(!isset($payload['customRaks']) || !is_array($payload['customRaks'])) $payload['customRaks'] = [];
  $cleanCats = [];
  foreach(['reguler','beanspot','strokok'] as $type){
    $row = $payload['categories'][$type] ?? null;
    $plus = normalize_plus_ohp(is_array($row) ? ($row['plus'] ?? []) : []);
    if(empty($plus)) continue;
    $cleanCats[$type] = [
      'plus'=>$plus,
      'updatedAt'=>is_array($row) && !empty($row['updatedAt']) ? (string)$row['updatedAt'] : date('c')
    ];
  }
  $payload['categories'] = $cleanCats;
  $payload['customRaks'] = oh979_clean_custom_raks($payload['customRaks']);
  $payload['updatedAt'] = date('c');
  @file_put_contents(OH979_CONFIG_FILE, json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
  return $payload;
}
function oh979_get_type($type){
  $type = oh979_norm_type($type);
  $all = oh979_read_all();
  $row = $all['categories'][$type] ?? null;
  if(!is_array($row)) return null;
  $plus = normalize_plus_ohp($row['plus'] ?? []);
  if(empty($plus)) return null;
  return [
    'type'=>$type,
    'label'=>oh979_type_label($type),
    'plus'=>implode(',', $plus),
    'plusArr'=>$plus,
    'updatedAt'=>$row['updatedAt'] ?? null
  ];
}
function oh979_set_type($type, $plusRaw){
  $type = oh979_norm_type($type);
  $plus = normalize_plus_ohp($plusRaw);
  if(empty($plus)) return false;
  $all = oh979_read_all();
  if(!isset($all['categories']) || !is_array($all['categories'])) $all['categories'] = [];
  $all['categories'][$type] = ['plus'=>$plus, 'updatedAt'=>date('c')];
  oh979_write_payload($all);
  return oh979_get_type($type);
}
function oh979_delete_type($type){
  $type = oh979_norm_type($type);
  $all = oh979_read_all();
  if(isset($all['categories'][$type])) unset($all['categories'][$type]);
  oh979_write_payload($all);
  return true;
}


function oh979_custom_store_file($storeId){
  $sid = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  if($sid==='') return '';
  if(!is_dir(OH979_CUSTOM_RAK_DIR)) @mkdir(OH979_CUSTOM_RAK_DIR, 0775, true);
  return OH979_CUSTOM_RAK_DIR . '/oh979_custom_raks_' . $sid . '.json';
}
function oh979_custom_read_store($storeId){
  $sid = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  if($sid==='') return ['storeId'=>'', 'types'=>['reguler'=>[], 'beanspot'=>[], 'strokok'=>[]], 'updatedAt'=>null];
  $file = oh979_custom_store_file($sid);
  $payload = null;
  if($file !== '' && file_exists($file)){
    $payload = json_decode(@file_get_contents($file), true);
  }
  if(!is_array($payload)){
    // Migrasi otomatis dari format lama di alfastore_979_config.json agar rak lama tidak hilang.
    $all = oh979_read_all();
    $old = $all['customRaks'][$sid] ?? [];
    $payload = ['storeId'=>$sid, 'types'=>is_array($old) ? $old : [], 'updatedAt'=>null];
  }
  if(!isset($payload['types']) || !is_array($payload['types'])){
    $payload['types'] = [
      'reguler' => is_array($payload['reguler'] ?? null) ? $payload['reguler'] : [],
      'beanspot' => is_array($payload['beanspot'] ?? null) ? $payload['beanspot'] : [],
      'strokok' => is_array($payload['strokok'] ?? null) ? $payload['strokok'] : []
    ];
  }
  $clean = oh979_clean_custom_raks([$sid => $payload['types']]);
  return [
    'storeId'=>$sid,
    'types'=>[
      'reguler'=>array_values($clean[$sid]['reguler'] ?? []),
      'beanspot'=>array_values($clean[$sid]['beanspot'] ?? []),
      'strokok'=>array_values($clean[$sid]['strokok'] ?? [])
    ],
    'updatedAt'=>$payload['updatedAt'] ?? null
  ];
}
function oh979_custom_write_store($storeId, $types){
  $sid = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  if($sid==='') return false;
  $clean = oh979_clean_custom_raks([$sid => (is_array($types) ? $types : [])]);
  $payload = [
    'storeId'=>$sid,
    'types'=>[
      'reguler'=>array_values($clean[$sid]['reguler'] ?? []),
      'beanspot'=>array_values($clean[$sid]['beanspot'] ?? []),
      'strokok'=>array_values($clean[$sid]['strokok'] ?? [])
    ],
    'updatedAt'=>date('c')
  ];
  $file = oh979_custom_store_file($sid);
  if($file==='') return false;
  return @file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
}


function oh979_clean_custom_raks($customRaw){
  $clean = [];
  foreach((array)$customRaw as $storeId=>$types){
    $sid = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
    if($sid==='') continue;
    foreach(['reguler','beanspot','strokok'] as $type){
      $rows = is_array($types ?? null) ? ($types[$type] ?? []) : [];
      if(!is_array($rows)) continue;
      foreach($rows as $row){
        if(!is_array($row)) continue;
        $name = trim((string)($row['name'] ?? ''));
        $plus = normalize_plus_ohp($row['plus'] ?? []);
        if($name==='' || empty($plus)) continue;
        $id = preg_replace('/[^A-Za-z0-9_\-]/','', (string)($row['id'] ?? ''));
        if($id==='') $id = 'rak_' . substr(sha1($sid.'|'.$type.'|'.$name.'|'.implode(',',$plus)), 0, 12);
        $clean[$sid][$type][] = [
          'id'=>$id,
          'name'=>$name,
          'plus'=>implode(',', $plus),
          'plusArr'=>$plus,
          'updatedAt'=>!empty($row['updatedAt']) ? (string)$row['updatedAt'] : date('c')
        ];
      }
    }
  }
  return $clean;
}
function oh979_custom_list($storeId, $type){
  $sid = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  $type = oh979_norm_type($type);
  if($sid==='') return [];
  $payload = oh979_custom_read_store($sid);
  return array_values($payload['types'][$type] ?? []);
}
function oh979_custom_save($storeId, $type, $id, $name, $plusRaw){
  $sid = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  $type = oh979_norm_type($type);
  $name = trim((string)$name);
  $plus = normalize_plus_ohp($plusRaw);
  if($sid==='' || $name==='' || empty($plus)) return false;
  $id = preg_replace('/[^A-Za-z0-9_\-]/','', (string)$id);
  if($id==='') $id = 'rak_' . time() . '_' . substr(sha1($sid.$type.$name.implode(',',$plus).microtime(true)),0,6);
  $payload = oh979_custom_read_store($sid);
  $types = is_array($payload['types'] ?? null) ? $payload['types'] : ['reguler'=>[], 'beanspot'=>[]];
  if(!isset($types[$type]) || !is_array($types[$type])) $types[$type] = [];
  $saved = ['id'=>$id,'name'=>$name,'plus'=>implode(',', $plus),'plusArr'=>$plus,'updatedAt'=>date('c')];
  $found = false;
  foreach($types[$type] as $k=>$row){
    if(($row['id'] ?? '') === $id){ $types[$type][$k] = $saved; $found = true; break; }
  }
  if(!$found) $types[$type][] = $saved;
  oh979_custom_write_store($sid, $types);
  return $saved;
}
function oh979_custom_delete($storeId, $type, $id){
  $sid = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  $type = oh979_norm_type($type);
  $id = preg_replace('/[^A-Za-z0-9_\-]/','', (string)$id);
  if($sid==='' || $id==='') return false;
  $payload = oh979_custom_read_store($sid);
  $types = is_array($payload['types'] ?? null) ? $payload['types'] : ['reguler'=>[], 'beanspot'=>[]];
  if(isset($types[$type]) && is_array($types[$type])){
    $types[$type] = array_values(array_filter($types[$type], fn($r)=>($r['id'] ?? '') !== $id));
  }
  oh979_custom_write_store($sid, $types);
  return true;
}

function oh979_get_any($storeId){
  $row = oh979_get($storeId);
  if($row) return $row;
  $raks = plano_get_store_raks($storeId);
  if(empty($raks)) return null;
  $plus = [];
  $updatedAt = null;
  foreach($raks as $rakRow){
    foreach((array)($rakRow['plusArr'] ?? []) as $plu){
      $plu = trim((string)$plu);
      if($plu!=='') $plus[] = $plu;
    }
    $ts = (string)($rakRow['updatedAt'] ?? '');
    if($ts !== '' && ($updatedAt === null || strcmp($ts, $updatedAt) > 0)) $updatedAt = $ts;
  }
  $plus = normalize_plus_ohp($plus);
  if(empty($plus)) return null;
  return [
    'storeId' => strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId)),
    'plus' => implode(',', $plus),
    'plusArr' => $plus,
    'updatedAt' => $updatedAt,
    'raks' => $raks
  ];
}
function oh979_fetch_product_detail($storeId, $plu){
  $url = ALFA_TO_API_BASE . '/cex/get_product_detail/?storeId=' . urlencode($storeId) . '&plu=' . urlencode($plu);
  if(!function_exists('curl_init')){
    return ["plu"=>$plu, "barcode"=>"", "descp"=>"Gagal mengambil data", "nama"=>"Gagal mengambil data", "on_hand"=>0, "oh"=>0];
  }
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTPHEADER => [
      'Accept: application/json',
      'Cache-Control: no-cache',
      'Pragma: no-cache',
      'User-Agent: Mozilla/5.0'
    ]
  ]);
  $response = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $curlError = curl_error($ch);
  curl_close($ch);
  if($curlError || !$response || $httpCode >= 400){
    return ["plu"=>$plu, "barcode"=>"", "descp"=>"Gagal mengambil data", "nama"=>"Gagal mengambil data", "on_hand"=>0, "oh"=>0];
  }
  $json = json_decode($response, true);
  if(json_last_error() !== JSON_ERROR_NONE){
    return ["plu"=>$plu, "barcode"=>"", "descp"=>"Respon tidak valid", "nama"=>"Respon tidak valid", "on_hand"=>0, "oh"=>0];
  }
  $row = null;
  if(is_array($json) && isset($json[0]) && is_array($json[0])) $row = $json[0];
  elseif(is_array($json) && isset($json['data'][0]) && is_array($json['data'][0])) $row = $json['data'][0];
  elseif(is_array($json) && isset($json['data']) && is_array($json['data']) && !isset($json['data'][0])) $row = $json['data'];
  elseif(is_array($json) && isset($json['plu'])) $row = $json;
  if(is_array($row)){
    $name = $row['descp'] ?? $row['nama'] ?? $row['name'] ?? $row['product_name'] ?? $row['productName'] ?? $row['description'] ?? '-';
    $barcode = $row['barcode'] ?? $row['BARCODE'] ?? $row['barCode'] ?? $row['bcode'] ?? $row['bar_code'] ?? $row['kodeBarcode'] ?? '';
    $oh = $row['onhand'] ?? $row['on_hand'] ?? $row['oh'] ?? $row['qty'] ?? $row['stock'] ?? 0;
    return [
      "plu" => (string)($row['plu'] ?? $row['PLU'] ?? $row['prdcd'] ?? $row['PRDCD'] ?? $plu),
      "barcode" => (string)$barcode,
      "descp" => (string)$name,
      "nama" => (string)$name,
      "on_hand" => is_numeric($oh) ? (float)$oh : 0,
      "oh" => is_numeric($oh) ? (float)$oh : 0
    ];
  }
  return ["plu"=>$plu, "barcode"=>"", "descp"=>"Tidak ditemukan", "nama"=>"Tidak ditemukan", "on_hand"=>0, "oh"=>0];
}

/* =========================
   BANNER HELPERS
========================= */
function banner_read_meta(){
  if(!file_exists(BANNER_META_FILE)) return ["file"=>null,"updatedAt"=>null];
  $j = json_decode(@file_get_contents(BANNER_META_FILE), true);
  if(!is_array($j)) return ["file"=>null,"updatedAt"=>null];
  $file = isset($j["file"]) ? basename((string)$j["file"]) : null;
  if($file==='') $file=null;
  return ["file"=>$file, "updatedAt"=>$j["updatedAt"] ?? null];
}
function banner_write_meta($file){
  $payload = ["file"=>basename((string)$file), "updatedAt"=>date('c')];
  @file_put_contents(BANNER_META_FILE, json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
  return $payload;
}

function alert_read_meta(){
  if(!file_exists(ALERT_META_FILE)) return ["enabled"=>false,"title"=>'',"message"=>'',"buttonText"=>'',"buttonUrl"=>'',"updatedAt"=>null];
  $j = json_decode(@file_get_contents(ALERT_META_FILE), true);
  if(!is_array($j)) return ["enabled"=>false,"title"=>'',"message"=>'',"buttonText"=>'',"buttonUrl"=>'',"updatedAt"=>null];
  return [
    "enabled" => !empty($j['enabled']) && trim((string)($j['title'] ?? '')) !== '' && trim((string)($j['message'] ?? '')) !== '',
    "title" => trim((string)($j['title'] ?? '')),
    "message" => trim((string)($j['message'] ?? '')),
    "buttonText" => trim((string)($j['buttonText'] ?? '')),
    "buttonUrl" => trim((string)($j['buttonUrl'] ?? '')),
    "updatedAt" => $j['updatedAt'] ?? null
  ];
}
function alert_write_meta($title, $message, $buttonText='', $buttonUrl=''){
  $payload = [
    "enabled"=>true,
    "title"=>trim((string)$title),
    "message"=>trim((string)$message),
    "buttonText"=>trim((string)$buttonText),
    "buttonUrl"=>trim((string)$buttonUrl),
    "updatedAt"=>date('c')
  ];
  @file_put_contents(ALERT_META_FILE, json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
  return $payload;
}

function home_info_default_message(){
  return 'Gunakan web dengan bijak dan jangan asal share web ini ke grup nasional ya terimakasih.';
}
function home_info_read(){
  if(!file_exists(HOME_INFO_FILE)) return ['message'=>home_info_default_message(),'updatedAt'=>null];
  $j = json_decode(@file_get_contents(HOME_INFO_FILE), true);
  if(!is_array($j)) return ['message'=>home_info_default_message(),'updatedAt'=>null];
  $message = (string)($j['message'] ?? '');
  if(trim($message) === '') $message = home_info_default_message();
  return ['message'=>$message,'updatedAt'=>$j['updatedAt'] ?? null];
}
function home_info_write($message){
  $message = (string)$message;
  if(function_exists('mb_substr')) $message = mb_substr($message,0,500);
  else $message = substr($message,0,500);
  $payload = ['message'=>$message,'updatedAt'=>date('c')];
  $ok = @file_put_contents(HOME_INFO_FILE, json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), LOCK_EX);
  if($ok === false) return false;
  return $payload;
}

function notif_read_all(){
  if(!file_exists(NOTIF_META_FILE)) return ['items'=>[], 'reads'=>[], 'deleted'=>[], 'updatedAt'=>null];
  $j = json_decode(@file_get_contents(NOTIF_META_FILE), true);
  if(!is_array($j)) return ['items'=>[], 'reads'=>[], 'deleted'=>[], 'updatedAt'=>null];
  if(!is_array($j['items'] ?? null)) $j['items'] = [];
  if(!is_array($j['reads'] ?? null)) $j['reads'] = [];
  if(!is_array($j['deleted'] ?? null)) $j['deleted'] = [];
  return $j;
}
function notif_write_all($data){
  $items = is_array($data['items'] ?? null) ? array_values($data['items']) : [];
  usort($items, function($a,$b){ return (int)($b['ts'] ?? 0) <=> (int)($a['ts'] ?? 0); });
  $items = array_slice($items, 0, 50);

  // Simpan daftar notifikasi yang disembunyikan secara terpisah untuk setiap user.
  // ID lama yang sudah tidak ada dipangkas agar file JSON tidak terus membesar.
  $validIds = [];
  foreach($items as $item){
    $id = (string)($item['id'] ?? '');
    if($id !== '') $validIds[$id] = true;
  }
  $deleted = [];
  $rawDeleted = is_array($data['deleted'] ?? null) ? $data['deleted'] : [];
  foreach($rawDeleted as $storeId=>$ids){
    $store = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
    if($store === '' || !is_array($ids)) continue;
    $cleanIds = [];
    foreach($ids as $id){
      $id = preg_replace('/[^A-Za-z0-9_-]/','', (string)$id);
      if($id !== '' && isset($validIds[$id])) $cleanIds[$id] = true;
    }
    if($cleanIds) $deleted[$store] = array_slice(array_keys($cleanIds), -50);
  }

  $payload = [
    'items'=>$items,
    'reads'=>is_array($data['reads'] ?? null) ? $data['reads'] : [],
    'deleted'=>$deleted,
    'updatedAt'=>date('c')
  ];
  @file_put_contents(NOTIF_META_FILE, json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), LOCK_EX);
  return $payload;
}
function notif_developer_target(){
  return defined('DEVELOPER_NOTIF_TARGET') ? (string)DEVELOPER_NOTIF_TARGET : 'DEVELOPER';
}
function notif_viewer_key($storeId){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  if($storeId === ADMIN_STORE_ID && function_exists('m604_is_developer_session') && m604_is_developer_session()){
    return notif_developer_target();
  }
  return $storeId;
}
function notif_effective_target($item){
  $target = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)($item['targetStore'] ?? '')));
  // Kompatibilitas data lama: notifikasi antrean admin yang dahulu diarahkan ke
  // M604 sekarang hanya tampil pada sesi developer, bukan user M604 PIN 0000.
  if($target === ADMIN_STORE_ID){
    $title = strtolower(trim((string)($item['title'] ?? '')));
    $message = strtolower(trim((string)($item['message'] ?? '')));
    $developerOnlyTitles = [
      'hadiah roda perlu diproses',
      'permintaan kode roda',
      'approval pendaftaran baru',
      'approval pendaftaran diperbarui'
    ];
    if(in_array($title, $developerOnlyTitles, true) || strpos($message, 'buka menu admin') !== false){
      return notif_developer_target();
    }
  }
  return $target;
}
function notif_add_message($title, $message, $targetStore=''){
  $data = notif_read_all();
  $id = 'N' . date('YmdHis') . substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 4);
  $targetStore = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$targetStore));
  $data['items'][] = ['id'=>$id, 'title'=>(string)$title, 'message'=>(string)$message, 'targetStore'=>$targetStore, 'ts'=>time()];
  return notif_write_all($data);
}
function notif_clear_all(){
  $payload = ['items'=>[], 'reads'=>[], 'deleted'=>[], 'updatedAt'=>date('c')];
  @file_put_contents(NOTIF_META_FILE, json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), LOCK_EX);
  return $payload;
}
function notif_for_store($storeId, $markRead=false){
  $viewerKey = notif_viewer_key($storeId);
  $data = notif_read_all();
  $deletedIds = [];
  foreach((array)(($data['deleted'] ?? [])[$viewerKey] ?? []) as $id){
    $deletedIds[(string)$id] = true;
  }
  $items = array_values(array_filter($data['items'] ?? [], function($it) use ($viewerKey, $deletedIds){
    $target = notif_effective_target($it);
    $id = (string)($it['id'] ?? '');
    return ($target==='' || $target===$viewerKey) && !isset($deletedIds[$id]);
  }));
  $readTs = (int)(($data['reads'] ?? [])[$viewerKey] ?? 0);
  $unread = 0;
  foreach($items as $it){ if((int)($it['ts'] ?? 0) > $readTs) $unread++; }
  if($markRead && $viewerKey !== ''){
    $data['reads'][$viewerKey] = time();
    notif_write_all($data);
    $unread = 0;
  }
  return ['items'=>$items, 'unread'=>$unread];
}
function notif_delete_for_store($storeId, $notifId){
  $viewerKey = notif_viewer_key($storeId);
  $notifId = preg_replace('/[^A-Za-z0-9_-]/','', (string)$notifId);
  if($viewerKey === '' || $notifId === '') return false;

  $data = notif_read_all();
  $visible = false;
  foreach((array)($data['items'] ?? []) as $item){
    if((string)($item['id'] ?? '') !== $notifId) continue;
    $target = notif_effective_target($item);
    if($target === '' || $target === $viewerKey) $visible = true;
    break;
  }
  if(!$visible) return false;

  if(!is_array($data['deleted'] ?? null)) $data['deleted'] = [];
  $ids = is_array($data['deleted'][$viewerKey] ?? null) ? $data['deleted'][$viewerKey] : [];
  $ids[] = $notifId;
  $data['deleted'][$viewerKey] = array_values(array_unique($ids));
  notif_write_all($data);
  return true;
}
function notif_delete_all_for_store($storeId){
  $viewerKey=notif_viewer_key($storeId);
  if($viewerKey==='')return ['ok'=>false,'count'=>0];
  $data=notif_read_all(); $ids=[];
  foreach((array)($data['items']??[]) as $item){
    $target=notif_effective_target($item);
    $id=preg_replace('/[^A-Za-z0-9_-]/','',(string)($item['id']??''));
    if($id!=='' && ($target==='' || $target===$viewerKey))$ids[$id]=true;
  }
  if(!is_array($data['deleted']??null))$data['deleted']=[];
  $data['deleted'][$viewerKey]=array_keys($ids);
  if(!is_array($data['reads']??null))$data['reads']=[];
  $data['reads'][$viewerKey]=time();
  notif_write_all($data);
  return ['ok'=>true,'count'=>count($ids)];
}


/* =========================
   INVITE POINT HELPERS
   - data point dan riwayat undangan toko
   - reward berlaku 1x per toko undangan
   - format: {"points":{"M604":10},"invites":{"A123":{"inviter":"M604","pin":"1234","awarded":true,...}},"logs":[]}
========================= */
function invite_norm_store($storeId){ return strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId)); }
function invite_norm_pin($pin){ return substr(preg_replace('/[^0-9]/','', (string)$pin),0,4); }
function invite_payment_amount($v){ return max(0, (int)preg_replace('/[^0-9]/','', (string)$v)); }
function invite_rupiah_text($v){ return 'Rp ' . number_format(invite_payment_amount($v), 0, ',', '.'); }
function invite_wib_text($iso){ $ts = strtotime((string)$iso); if($ts === false || $ts <= 0) return ''; return date('d/m/Y H:i:s', $ts) . ' WIB'; }
function invite_point_value($v){
  // Point disimpan sebagai angka point murni di server.
  // Tidak dikonversi dari nominal rupiah agar tambah/kurang point admin tidak hilang sendiri.
  return max(0, (int)$v);
}

function invite_points_purge_old_history_array($j){
  $changed = false;
  $ttl = defined('INVITE_HISTORY_TTL_SECONDS') ? (int)INVITE_HISTORY_TTL_SECONDS : 172800;
  $now = time();
  foreach(['invites','pending'] as $bucket){
    if(!isset($j[$bucket]) || !is_array($j[$bucket])) continue;
    foreach($j[$bucket] as $key=>$row){
      if(!is_array($row)) continue;
      $date = (string)($row['paidAt'] ?? $row['createdAt'] ?? $row['processedAt'] ?? '');
      $ts = strtotime($date);
      if($ts !== false && $ts > 0 && ($now - $ts) >= $ttl){
        unset($j[$bucket][$key]);
        $changed = true;
      }
    }
  }
  if($changed) $j['updatedAt'] = date('c');
  return [$j, $changed];
}
function invite_points_read_all(){
  $fallback = ["points"=>[], "invites"=>[], "pending"=>[], "logs"=>[], "updatedAt"=>null];
  if(!defined('INVITE_POINTS_FILE') || !file_exists(INVITE_POINTS_FILE)) return $fallback;
  $j = json_file_read_array_safe(INVITE_POINTS_FILE, $fallback);
  if(!is_array($j)) $j = $fallback;
  if(!isset($j['points']) || !is_array($j['points'])) $j['points'] = [];
  if(!isset($j['invites']) || !is_array($j['invites'])) $j['invites'] = [];
  if(!isset($j['pending']) || !is_array($j['pending'])) $j['pending'] = [];
  if(!isset($j['logs']) || !is_array($j['logs'])) $j['logs'] = [];
  foreach((array)$j['points'] as $sid=>$v){ $j['points'][$sid] = invite_point_value($v); }
  foreach((array)$j['invites'] as $target=>$row){ if(is_array($row)) $j['invites'][$target]['points'] = invite_point_value($row['points'] ?? 10); }
  [$j, $purged] = invite_points_purge_old_history_array($j);
  if($purged && defined('INVITE_POINTS_FILE')) json_file_write_array_safe(INVITE_POINTS_FILE, $j);
  return $j;
}
function invite_points_write_all($data){
  $points = [];
  foreach((array)($data['points'] ?? []) as $sid=>$v){
    $sid = invite_norm_store($sid); if($sid==='') continue;
    $points[$sid] = invite_point_value($v);
  }
  $invites = [];
  foreach((array)($data['invites'] ?? []) as $target=>$row){
    if(!is_array($row)) continue;
    $target = invite_norm_store($target ?: ($row['target'] ?? ''));
    $inviter = invite_norm_store($row['inviter'] ?? '');
    if($target==='' || $inviter==='') continue;
    $invites[$target] = [
      'target'=>$target,
      'inviter'=>$inviter,
      'pin'=>invite_norm_pin($row['pin'] ?? ''),
      'awarded'=>!empty($row['awarded']),
      'points'=>invite_point_value($row['points'] ?? 10),
      'createdAt'=>(string)($row['createdAt'] ?? date('c')),
      'awardedAt'=>(string)($row['awardedAt'] ?? ''),
      'oldExpiryTs'=>(int)($row['oldExpiryTs'] ?? 0),
      'newExpiryTs'=>(int)($row['newExpiryTs'] ?? 0),
      'amount'=>invite_payment_amount($row['amount'] ?? 1000),
      'paidAmount'=>invite_payment_amount($row['paidAmount'] ?? ($row['amount'] ?? 1000)),
      'qrisId'=>(string)($row['qrisId'] ?? ''),
      'paymentReference'=>(string)($row['paymentReference'] ?? ''),
      'paidAt'=>(string)($row['paidAt'] ?? ''),
    ];
  }
  $pending = [];
  foreach((array)($data['pending'] ?? []) as $id=>$row){
    if(!is_array($row)) continue;
    $target = invite_norm_store($row['target'] ?? '');
    $inviter = invite_norm_store($row['inviter'] ?? '');
    if($target==='' || $inviter==='') continue;
    $pid = preg_replace('/[^A-Za-z0-9_\-]/','', (string)($id ?: ($row['id'] ?? '')));
    if($pid==='') $pid = $target.'_'.$inviter.'_'.substr(md5((string)($row['createdAt'] ?? microtime(true))),0,8);
    $pending[$pid] = [
      'id'=>$pid,
      'target'=>$target,
      'inviter'=>$inviter,
      'pin'=>invite_norm_pin($row['pin'] ?? ''),
      'name'=>(string)($row['name'] ?? ''),
      'status'=>(string)($row['status'] ?? 'pending'),
      'createdAt'=>(string)($row['createdAt'] ?? date('c')),
      'processedAt'=>(string)($row['processedAt'] ?? ''),
      'processedBy'=>invite_norm_store($row['processedBy'] ?? ''),
      'note'=>(string)($row['note'] ?? ''),
      'amount'=>invite_payment_amount($row['amount'] ?? 0),
      'qrisId'=>(string)($row['qrisId'] ?? ($row['id'] ?? '')),
      'paymentReference'=>(string)($row['paymentReference'] ?? ($row['payment_reference'] ?? '')),
      'expiredAt'=>(string)($row['expiredAt'] ?? ($row['expired_at'] ?? '')),
    ];
  }
  $logs = array_values((array)($data['logs'] ?? []));
  if(count($logs) > 300) $logs = array_slice($logs, -300);
  $payload = ['points'=>$points, 'invites'=>$invites, 'pending'=>$pending, 'logs'=>$logs, 'updatedAt'=>date('c')];
  [$payload, $_purgedOldInviteHistory] = invite_points_purge_old_history_array($payload);
  json_file_write_array_safe(INVITE_POINTS_FILE, $payload);
  return $payload;
}
function invite_points_get($storeId){
  $storeId = invite_norm_store($storeId); if($storeId==='') return 0;
  $all = invite_points_read_all();
  return max(0, (int)($all['points'][$storeId] ?? 0));
}
function invite_points_set($storeId, $amount){
  $storeId = invite_norm_store($storeId); if($storeId==='') return 0;
  $all = invite_points_read_all();
  $all['points'][$storeId] = invite_point_value($amount);
  $all['logs'][] = ['type'=>'admin_point_set','storeId'=>$storeId,'points'=>$all['points'][$storeId],'createdAt'=>date('c')];
  invite_points_write_all($all);
  return $all['points'][$storeId];
}
function invite_points_adjust($storeId, $delta){
  $storeId = invite_norm_store($storeId); if($storeId==='') return 0;
  return invite_points_set($storeId, invite_points_get($storeId) + (int)$delta);
}
function invite_referral_register($inviter, $target, $pin='', $amount=1000, $qrisId='', $paymentReference='', $paidAt=''){
  $inviter = invite_norm_store($inviter); $target = invite_norm_store($target); $pin = invite_norm_pin($pin);
  if($inviter==='' || $target==='' || $inviter===$target) return [false, 'Kode toko undangan tidak valid.'];
  if(strlen($target) > 4 || strlen($inviter) > 4) return [false, 'Kode toko maksimal 4 huruf/angka.'];
  if($pin!=='' && !preg_match('/^\d{1,4}$/', $pin)) return [false, 'PIN maksimal 4 angka.'];
  $all = invite_points_read_all();
  $existing = $all['invites'][$target] ?? null;
  if(is_array($existing) && !empty($existing['awarded'])) return [true, 'Undangan sudah pernah mendapat point.'];
  $all['invites'][$target] = [
    'target'=>$target,
    'inviter'=>$inviter,
    'pin'=>$pin,
    'awarded'=>false,
    'points'=>10,
    'createdAt'=>(string)($existing['createdAt'] ?? date('c')),
    'awardedAt'=>'',
    'oldExpiryTs'=>(int)($existing['oldExpiryTs'] ?? 0),
    'newExpiryTs'=>(int)($existing['newExpiryTs'] ?? 0),
    'amount'=>invite_payment_amount($amount ?: ($existing['amount'] ?? 1000)),
    'paidAmount'=>invite_payment_amount($amount ?: ($existing['paidAmount'] ?? 1000)),
    'qrisId'=>(string)($qrisId ?: ($existing['qrisId'] ?? '')),
    'paymentReference'=>(string)($paymentReference ?: ($existing['paymentReference'] ?? '')),
    'paidAt'=>(string)($paidAt ?: ($existing['paidAt'] ?? date('c'))),
  ];
  invite_points_write_all($all);
  return [true, 'Undangan tersimpan.'];
}
function invite_referral_create_pending($inviter, $target, $pin=''){
  $inviter = invite_norm_store($inviter); $target = invite_norm_store($target); $pin = invite_norm_pin($pin);
  if($inviter==='' || $target==='' || $inviter===$target) return [false, 'Kode toko undangan tidak valid.', null];
  if(strlen($target) > 4 || strlen($inviter) > 4) return [false, 'Kode toko maksimal 4 huruf/angka.', null];
  if($pin==='' || !preg_match('/^\d{1,4}$/', $pin)) return [false, 'PIN wajib angka maksimal 4 digit.', null];
  $all = invite_points_read_all();
  if(invite_referral_already_awarded($all, $inviter, $target)) return [false, 'Referral toko ini sudah pernah tersimpan.', null];
  foreach((array)($all['pending'] ?? []) as $row){
    if(!is_array($row)) continue;
    if(invite_norm_store($row['target'] ?? '') === $target && (string)($row['status'] ?? 'pending') === 'pending'){
      return [true, 'Undangan toko ini sudah tersimpan.', $row];
    }
  }
  $detail = function_exists('store_detail_fetch_cached') ? store_detail_fetch_cached($target, 86400) : null;
  $name = '';
  if(is_array($detail)){
    $name = trim((string)($detail['header2'] ?? ''));
    if($name==='') $name = trim((string)($detail['header5'] ?? ''));
  }
  if($name==='') $name = 'TOKO '.$target;
  $id = $target.'_'.$inviter.'_'.date('YmdHis').'_'.substr(md5($target.$inviter.microtime(true)),0,6);
  $row = ['id'=>$id,'target'=>$target,'inviter'=>$inviter,'pin'=>$pin,'name'=>$name,'status'=>'pending','createdAt'=>date('c'),'processedAt'=>'','processedBy'=>'','note'=>''];
  $all['pending'][$id] = $row;
  $all['logs'][] = ['type'=>'invite_pending','id'=>$id,'inviter'=>$inviter,'target'=>$target,'createdAt'=>date('c')];
  invite_points_write_all($all);
  return [true, 'Undangan tersimpan.', $row];
}
function invite_referral_already_awarded($all, $inviter, $target){
  $inviter = invite_norm_store($inviter);
  $target = invite_norm_store($target);
  if($inviter==='' || $target==='') return true;
  $row = $all['invites'][$target] ?? null;
  if(is_array($row) && !empty($row['awarded'])) return true;
  foreach((array)($all['logs'] ?? []) as $log){
    if(!is_array($log)) continue;
    $type = (string)($log['type'] ?? '');
    if(($type === 'invite_approved' || $type === 'invite_reward')
      && invite_norm_store($log['inviter'] ?? '') === $inviter
      && invite_norm_store($log['target'] ?? '') === $target
      && ((int)($log['points'] ?? 0)) > 0){
      return true;
    }
  }
  return false;
}
function invite_referral_award_for_expiry_change($targetStore, $oldTs, $newTs){
  // Fungsi reward otomatis lama dimatikan agar point tidak dobel.
  return false;
  $targetStore = invite_norm_store($targetStore);
  $oldTs = (int)$oldTs; $newTs = (int)$newTs;
  if($targetStore==='' || $newTs <= 0) return false;
  $now = function_exists('jakarta_now_ts') ? jakarta_now_ts() : time();
  $baseTs = $oldTs > $now ? $oldTs : $now;
  $addedSec = $newTs - $baseTs;
  if($addedSec < (20 * 86400)) return false;
  $all = invite_points_read_all();
  if(empty($all['invites'][$targetStore]) || !is_array($all['invites'][$targetStore])) return false;
  if(!empty($all['invites'][$targetStore]['awarded'])) return false;
  $inviter = invite_norm_store($all['invites'][$targetStore]['inviter'] ?? '');
  if($inviter==='' || $inviter===$targetStore) return false;
  $points = (int)($all['invites'][$targetStore]['points'] ?? 10);
  if($points <= 0 || $points > 10) $points = 10;
  $all['points'][$inviter] = (int)($all['points'][$inviter] ?? 0) + $points;
  $all['invites'][$targetStore]['awarded'] = true;
  $all['invites'][$targetStore]['awardedAt'] = date('c');
  $all['invites'][$targetStore]['oldExpiryTs'] = $oldTs;
  $all['invites'][$targetStore]['newExpiryTs'] = $newTs;
  $all['logs'][] = ['type'=>'invite_reward','inviter'=>$inviter,'target'=>$targetStore,'points'=>$points,'oldExpiryTs'=>$oldTs,'newExpiryTs'=>$newTs,'createdAt'=>date('c')];
  invite_points_write_all($all);
  return true;
}
function invite_points_status_payload($storeId){
  $storeId = invite_norm_store($storeId);
  $all = invite_points_read_all();
  $refs = [];
  foreach((array)($all['invites'] ?? []) as $target=>$row){
    if(!is_array($row)) continue;
    if(invite_norm_store($row['inviter'] ?? '') !== $storeId) continue;
    $refs[] = [
      'target'=>invite_norm_store($target),
      'awarded'=>!empty($row['awarded']),
      'points'=>invite_point_value($row['points'] ?? 10),
      'createdAt'=>(string)($row['createdAt'] ?? ''),
      'awardedAt'=>(string)($row['awardedAt'] ?? ''),
    ];
  }
  usort($refs, function($a,$b){ return strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? '')); });
  return ['points'=>invite_points_get($storeId), 'referrals'=>$refs];
}

/* =========================
   EXPIRY (PER USER) HELPERS
   - disimpan di file JSON (tanpa DB & tanpa localStorage)
   - format: { "stores": { "M604": 1760000000, ... }, "updatedAt": "..." }
========================= */
function expiry_read_all(){
  if(!file_exists(EXPIRY_FILE)) return ["stores"=>[], "updatedAt"=>null];
  $j = json_file_read_array_safe(EXPIRY_FILE, ["stores"=>[], "updatedAt"=>null]);
  if(!is_array($j)) return ["stores"=>[], "updatedAt"=>null];
  if(!isset($j["stores"]) || !is_array($j["stores"])) $j["stores"] = [];
  if(!isset($j["categories"]) || !is_array($j["categories"])) $j["categories"] = [];
  if(!isset($j["customRaks"]) || !is_array($j["customRaks"])) $j["customRaks"] = [];
  return $j;
}
function expiry_write_all($map){
  $payload = ["stores"=>$map, "updatedAt"=>date('c')];
  json_file_write_array_safe(EXPIRY_FILE, $payload);
  return $payload;
}
function expiry_get_ts($storeId){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  if($storeId==='') return 0;
  $all = expiry_read_all();
  $ts = (int)($all["stores"][$storeId] ?? 0);
  return $ts > 0 ? $ts : 0;
}

/* =========================
   PIN (PER USER) HELPERS
   - disimpan di file JSON yang sama (tanpa DB)
   - tetap kompatibel dengan format lama:
     { "stores": { "M10":"0000" }, "sograndStores": { "M10":"9999" } }
   - format tambahan baru agar terlihat sebagai 2 user walau kode toko sama:
     "accounts": [
       { "storeId":"M10", "pin":"0000", "type":"normal" },
       { "storeId":"M10", "pin":"9999", "type":"sogrand" }
     ]
========================= */
function pin_norm_store($storeId){
  return strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
}
function pin_norm_pin($pin){
  return preg_replace('/[^0-9]/','', (string)$pin);
}
function pin_accounts_from_maps($stores, $sograndStores){
  $accounts = [];
  foreach((array)$stores as $sid=>$pin){
    $sid = pin_norm_store($sid); $pin = pin_norm_pin($pin);
    if($sid!=='' && strlen($pin)===4) $accounts[] = ["storeId"=>$sid, "pin"=>$pin, "type"=>"normal"];
  }
  foreach((array)$sograndStores as $sid=>$pin){
    $sid = pin_norm_store($sid); $pin = pin_norm_pin($pin);
    if($sid!=='' && strlen($pin)===4) $accounts[] = ["storeId"=>$sid, "pin"=>$pin, "type"=>"sogrand"];
  }
  return $accounts;
}
function pin_accounts_clean($accounts, $stores=[], $sograndStores=[]){
  $out = [];
  $seen = [];
  foreach((array)$accounts as $row){
    if(!is_array($row)) continue;
    $sid = pin_norm_store($row['storeId'] ?? '');
    $pin = pin_norm_pin($row['pin'] ?? '');
    $type = strtolower(preg_replace('/[^a-z0-9_]/','', (string)($row['type'] ?? 'normal')));
    if($type !== 'sogrand') $type = 'normal';
    if($sid==='' || strlen($pin)!==4) continue;
    $key = $sid . '|' . $type;
    if(isset($seen[$key])) continue;
    $seen[$key] = true;
    $out[] = ["storeId"=>$sid, "pin"=>$pin, "type"=>$type];
  }
  foreach(pin_accounts_from_maps($stores, $sograndStores) as $row){
    $key = $row['storeId'] . '|' . $row['type'];
    if(isset($seen[$key])) continue;
    $seen[$key] = true;
    $out[] = $row;
  }
  usort($out, function($a,$b){
    $c = strcmp($a['storeId'], $b['storeId']);
    if($c!==0) return $c;
    return strcmp($a['type'], $b['type']);
  });
  return $out;
}
function pin_payload_write($stores, $sograndStores, $accounts=null){
  $cleanStores = [];
  foreach((array)$stores as $sid=>$pin){
    $sid = pin_norm_store($sid); $pin = pin_norm_pin($pin);
    if($sid!=='' && strlen($pin)===4) $cleanStores[$sid] = $pin;
  }
  $cleanSogrand = [];
  foreach((array)$sograndStores as $sid=>$pin){
    $sid = pin_norm_store($sid); $pin = pin_norm_pin($pin);
    if($sid!=='' && strlen($pin)===4) $cleanSogrand[$sid] = $pin;
  }
  $cleanAccounts = pin_accounts_clean($accounts ?? [], $cleanStores, $cleanSogrand);
  $payload = ["stores"=>$cleanStores, "sograndStores"=>$cleanSogrand, "accounts"=>$cleanAccounts, "updatedAt"=>date('c')];
  @file_put_contents(PIN_FILE, json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
  return $payload;
}
function pin_read_all(){
  if(!file_exists(PIN_FILE)) return ["stores"=>[], "sograndStores"=>[], "accounts"=>[], "updatedAt"=>null];
  $j = json_decode(@file_get_contents(PIN_FILE), true);
  if(!is_array($j)) return ["stores"=>[], "sograndStores"=>[], "accounts"=>[], "updatedAt"=>null];
  if(!isset($j["stores"]) || !is_array($j["stores"])) $j["stores"] = [];
  if(!isset($j["sograndStores"]) || !is_array($j["sograndStores"])) $j["sograndStores"] = [];
  if(!isset($j["accounts"]) || !is_array($j["accounts"])) $j["accounts"] = [];
  $j["accounts"] = pin_accounts_clean($j["accounts"], $j["stores"], $j["sograndStores"]);
  return $j;
}
function pin_write_all($map){
  $current = pin_read_all();
  return pin_payload_write($map, ($current["sograndStores"] ?? []), ($current["accounts"] ?? []));
}
function pin_sogrand_set($storeId, $pin=SOGRAND_PIN){
  $storeId = pin_norm_store($storeId);
  $pin = pin_norm_pin($pin);
  if($storeId==='' || strlen($pin)!==4) return false;
  $all = pin_read_all();
  $sg = $all["sograndStores"] ?? [];
  $sg[$storeId] = $pin;
  $accounts = $all["accounts"] ?? [];
  $found = false;
  foreach($accounts as &$row){
    if(($row['storeId'] ?? '') === $storeId && ($row['type'] ?? '') === 'sogrand'){
      $row['pin'] = $pin; $found = true; break;
    }
  }
  unset($row);
  if(!$found) $accounts[] = ["storeId"=>$storeId, "pin"=>$pin, "type"=>"sogrand"];
  pin_payload_write(($all["stores"] ?? []), $sg, $accounts);
  return true;
}
function pin_sogrand_get($storeId){
  $storeId = pin_norm_store($storeId);
  if($storeId==='') return '';
  $all = pin_read_all();
  foreach((array)($all['accounts'] ?? []) as $row){
    if(($row['storeId'] ?? '') === $storeId && ($row['type'] ?? '') === 'sogrand'){
      $v = pin_norm_pin($row['pin'] ?? '');
      return strlen($v)===4 ? $v : '';
    }
  }
  $v = isset($all["sograndStores"][$storeId]) ? pin_norm_pin($all["sograndStores"][$storeId]) : '';
  return strlen($v)===4 ? $v : '';
}
function pin_sogrand_delete($storeId){
  $storeId = pin_norm_store($storeId);
  if($storeId==='') return false;
  $all = pin_read_all();
  $sg = $all["sograndStores"] ?? [];
  if(isset($sg[$storeId])) unset($sg[$storeId]);
  $accounts = array_values(array_filter((array)($all['accounts'] ?? []), function($row) use ($storeId){
    return !(is_array($row) && ($row['storeId'] ?? '') === $storeId && ($row['type'] ?? '') === 'sogrand');
  }));
  pin_payload_write(($all["stores"] ?? []), $sg, $accounts);
  return true;
}
function pin_get($storeId){
  $storeId = pin_norm_store($storeId);
  if($storeId==='') return '0000';
  $all = pin_read_all();
  foreach((array)($all['accounts'] ?? []) as $row){
    if(($row['storeId'] ?? '') === $storeId && ($row['type'] ?? '') === 'normal'){
      $v = pin_norm_pin($row['pin'] ?? '');
      return strlen($v)===4 ? $v : '0000';
    }
  }
  $m = $all["stores"] ?? [];
  $v = isset($m[$storeId]) ? pin_norm_pin($m[$storeId]) : '';
  return strlen($v)===4 ? $v : '0000';
}
function pin_set($storeId, $pin){
  $storeId = pin_norm_store($storeId);
  $pin = pin_norm_pin($pin);
  if($storeId==='' || strlen($pin)!==4) return false;
  $all = pin_read_all();
  $stores = $all["stores"] ?? [];
  $stores[$storeId] = $pin;
  $accounts = $all["accounts"] ?? [];
  $found = false;
  foreach($accounts as &$row){
    if(($row['storeId'] ?? '') === $storeId && ($row['type'] ?? '') === 'normal'){
      $row['pin'] = $pin; $found = true; break;
    }
  }
  unset($row);
  if(!$found) $accounts[] = ["storeId"=>$storeId, "pin"=>$pin, "type"=>"normal"];
  pin_payload_write($stores, ($all["sograndStores"] ?? []), $accounts);
  return true;
}

/* =========================
   PREMIUM ACCESS JSON (tanpa DB)
   - format: { "stores": { "M604": true, ... }, "updatedAt": "..." }
========================= */
function premium_read_all(){
  if(!file_exists(PREMIUM_FILE)) return ["stores"=>[], "updatedAt"=>null];
  $j = json_decode(@file_get_contents(PREMIUM_FILE), true);
  if(!is_array($j)) return ["stores"=>[], "updatedAt"=>null];
  if(!isset($j["stores"]) || !is_array($j["stores"])) $j["stores"] = [];
  if(!isset($j["categories"]) || !is_array($j["categories"])) $j["categories"] = [];
  if(!isset($j["customRaks"]) || !is_array($j["customRaks"])) $j["customRaks"] = [];
  return $j;
}
function premium_write_all($map){
  $payload = ["stores"=>$map, "updatedAt"=>date('c')];
  @file_put_contents(PREMIUM_FILE, json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
  return $payload;
}
function premium_get($storeId){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  if($storeId==='') return false;
  $all = premium_read_all();
  $m = $all["stores"] ?? [];
  if(array_key_exists($storeId, $m)) return (bool)$m[$storeId];

  // default: toko bawaan dianggap premium, toko baru default NON-premium
  global $DEFAULT_STORES;
  return in_array($storeId, $DEFAULT_STORES, true);
}
function premium_set($storeId, $isPremium){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  if($storeId==='') return false;
  $all = premium_read_all();
  if(!isset($all["stores"]) || !is_array($all["stores"])) $all["stores"] = [];
  $all["stores"][ $storeId ] = $isPremium ? true : false;
  premium_write_all($all["stores"]);
  return true;
}

function pin_delete($storeId){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  if($storeId==='') return false;
  $all = pin_read_all();
  if(isset($all["stores"]) && is_array($all["stores"]) && isset($all["stores"][ $storeId ])){
    unset($all["stores"][ $storeId ]);
    pin_write_all($all["stores"]);
  }
  return true;
}

function premium_delete($storeId){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  if($storeId==='') return false;
  $all = premium_read_all();
  if(isset($all["stores"]) && is_array($all["stores"]) && array_key_exists($storeId, $all["stores"])){
    unset($all["stores"][ $storeId ]);
    premium_write_all($all["stores"]);
  }
  return true;
}


function jakarta_now_ts(){
  return (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->getTimestamp();
}
function expiry_ts_from_date_end($dateStr){
  $dateStr = trim((string)$dateStr);
  if($dateStr === '') return 0;
  $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $dateStr . ' 23:59:59', new DateTimeZone('Asia/Jakarta'));
  if(!$dt) return false;
  return $dt->getTimestamp();
}

function expiry_days_from_now_ts($days=30){
  $days = max(1, (int)$days);
  $tz = new DateTimeZone('Asia/Jakarta');
  $dt = new DateTime('now', $tz);
  if($days > 1) $dt->modify('+' . ($days - 1) . ' day');
  $dt->setTime(23, 59, 59);
  return $dt->getTimestamp();
}
function registration_premium_expiry_ts(){
  return expiry_days_from_now_ts(30);
}
function registration_promo_expiry_ts($days=3){
  return expiry_days_from_now_ts($days);
}
function expiry_extend_days_ts($storeId, $days=30){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  $days = max(1, (int)$days);
  $currentTs = (int)expiry_get_ts($storeId);
  if($currentTs > jakarta_now_ts()) return $currentTs + ($days * 86400);
  return expiry_days_from_now_ts($days);
}
function expiry_extend_after_renew_ts($storeId, $days=30){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  $days = max(1, (int)$days);
  $remainingDays = expiry_remaining_days($storeId);
  return expiry_days_from_now_ts($remainingDays + $days);
}
function expiry_extend_30_days_ts($storeId){
  return expiry_extend_after_renew_ts($storeId, 30);
}
function expiry_extend_3_days_ts($storeId){
  return expiry_extend_days_ts($storeId, 3);
}
function expiry_remaining_days($storeId){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  $ts = (int)expiry_get_ts($storeId);
  if($ts <= 0) return 0;
  $diff = $ts - jakarta_now_ts();
  if($diff <= 0) return 0;
  return (int)ceil($diff / 86400);
}
function expiry_warning_days($storeId){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  $ts = (int)expiry_get_ts($storeId);
  if($ts <= 0) return 0;
  $diff = $ts - jakarta_now_ts();
  if($diff <= 0) return 0;
  $days = (int)floor($diff / 86400);
  if($days < 1) return 1;
  return $days;
}

function expiry_warning_payload($storeId){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  if($storeId==='') return null;
  // Developer M604 unlimited, tetapi M604 user biasa tetap mendapat peringatan expired.
  if($storeId===ADMIN_STORE_ID && function_exists('m604_is_developer_session') && m604_is_developer_session()) return null;
  $days = (int)expiry_warning_days($storeId);
  if($days < 1 || $days > 3) return null;
  return [
    "show" => true,
    "days" => $days,
    "message" => "Sisa expired anda tersisa {$days} hari. Perpanjang melalui tombol bantuan",
    "buttonText" => "",
    "buttonUrl" => ""
  ];
}

/* =========================
   EXPIRY EXTENSION HISTORY
   - tersimpan terpisah di alfastore_expiry_history.json
   - hanya mencatat perubahan yang benar-benar menambah masa aktif
========================= */
function expiry_history_read_all(){
  $empty = ['items'=>[], 'updatedAt'=>null];
  if(!defined('EXPIRY_HISTORY_FILE')) return $empty;
  $j = json_file_read_array_safe(EXPIRY_HISTORY_FILE, $empty);
  if(!is_array($j)) return $empty;
  if(!isset($j['items']) || !is_array($j['items'])) $j['items'] = [];
  return $j;
}
function expiry_history_write_all($items){
  $clean = [];
  foreach((array)$items as $row){
    if(!is_array($row)) continue;
    $storeId = strtoupper(preg_replace('/[^A-Z0-9]/', '', is_scalar($row['storeId'] ?? null) ? (string)$row['storeId'] : ''));
    $oldTs = (int)($row['oldExpiryTs'] ?? 0);
    $newTs = (int)($row['newExpiryTs'] ?? 0);
    if($storeId === '' || $newTs <= 0 || $newTs <= $oldTs) continue;
    $idRaw = is_scalar($row['id'] ?? null) ? (string)$row['id'] : '';
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $idRaw);
    if($id === '') $id = sha1($storeId . '|' . $oldTs . '|' . $newTs . '|' . (string)($row['createdTs'] ?? time()));
    $sourceRaw = is_scalar($row['source'] ?? null) ? (string)$row['source'] : 'system';
    $source = preg_replace('/[^a-zA-Z0-9_-]/', '', strtolower($sourceRaw));
    $actorRaw = is_scalar($row['actor'] ?? null) ? (string)$row['actor'] : '';
    $actor = strtoupper(preg_replace('/[^A-Z0-9]/', '', $actorRaw));
    $createdTs = max(1, (int)($row['createdTs'] ?? time()));
    $clean[] = [
      'id'=>$id,
      'storeId'=>$storeId,
      'oldExpiryTs'=>$oldTs,
      'newExpiryTs'=>$newTs,
      'oldExpiryAt'=>$oldTs > 0 ? date('c', $oldTs) : null,
      'newExpiryAt'=>date('c', $newTs),
      'addedMonths'=>max(0, (int)($row['addedMonths'] ?? 0)),
      'addedDays'=>max(0, (int)($row['addedDays'] ?? 0)),
      'addedTotalDays'=>max(0, (int)($row['addedTotalDays'] ?? 0)),
      'source'=>$source !== '' ? $source : 'system',
      'actor'=>$actor,
      'createdTs'=>$createdTs,
      'createdAt'=>date('c', $createdTs),
    ];
  }
  usort($clean, function($a, $b){ return (int)$a['createdTs'] <=> (int)$b['createdTs']; });
  if(count($clean) > 5000) $clean = array_slice($clean, -5000);
  $payload = ['items'=>array_values($clean), 'updatedAt'=>date('c')];
  json_file_write_array_safe(EXPIRY_HISTORY_FILE, $payload);
  return $payload;
}
function expiry_history_new_id(){
  try{
    if(function_exists('random_bytes')) return bin2hex(random_bytes(12));
  }catch(Exception $e){}
  return sha1(uniqid('expiry_', true) . '|' . mt_rand());
}
function expiry_history_append($storeId, $oldTs, $newTs, $meta=[]){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/', '', is_scalar($storeId) ? (string)$storeId : ''));
  $oldTs = max(0, (int)$oldTs);
  $newTs = max(0, (int)$newTs);
  $now = time();
  $baseTs = $oldTs > $now ? $oldTs : $now;
  if($storeId === '' || $newTs <= $baseTs) return false;
  if(!is_array($meta)) $meta = [];
  $months = max(0, min(120, (int)($meta['months'] ?? 0)));
  $days = max(0, min(3660, (int)($meta['days'] ?? 0)));
  $totalDays = max(1, (int)ceil(($newTs - $baseTs) / ONE_DAY_SEC));
  if($months <= 0 && $days <= 0) $days = $totalDays;
  $sourceRaw = is_scalar($meta['source'] ?? null) ? (string)$meta['source'] : 'system';
  $source = preg_replace('/[^a-zA-Z0-9_-]/', '', strtolower($sourceRaw));
  $actorRaw = is_scalar($meta['actor'] ?? null) ? (string)$meta['actor'] : '';
  if($actorRaw === '' && function_exists('cookie_read_session')) $actorRaw = (string)cookie_read_session();
  $actor = strtoupper(preg_replace('/[^A-Z0-9]/', '', $actorRaw));
  $all = expiry_history_read_all();
  $items = is_array($all['items'] ?? null) ? $all['items'] : [];
  $items[] = [
    'id'=>expiry_history_new_id(),
    'storeId'=>$storeId,
    'oldExpiryTs'=>$oldTs,
    'newExpiryTs'=>$newTs,
    'addedMonths'=>$months,
    'addedDays'=>$days,
    'addedTotalDays'=>$totalDays,
    'source'=>$source !== '' ? $source : 'system',
    'actor'=>$actor,
    'createdTs'=>$now,
  ];
  expiry_history_write_all($items);
  return true;
}
function expiry_history_delete($id=''){
  $id = preg_replace('/[^a-zA-Z0-9_-]/', '', is_scalar($id) ? (string)$id : '');
  $all = expiry_history_read_all();
  $items = is_array($all['items'] ?? null) ? $all['items'] : [];
  if($id === '') return expiry_history_write_all([]);
  $items = array_values(array_filter($items, function($row) use ($id){
    return !is_array($row) || (string)($row['id'] ?? '') !== $id;
  }));
  return expiry_history_write_all($items);
}

function expiry_set_ts($storeId, $ts, $historyMeta=[]){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  $ts = (int)$ts;
  $all = expiry_read_all();
  if($storeId==='') return $all;
  $oldTs = (int)($all["stores"][$storeId] ?? 0);
  if($ts <= 0){
    unset($all["stores"][$storeId]);
  }else{
    $all["stores"][$storeId] = $ts;
  }
  $saved = expiry_write_all($all["stores"]);
  if($ts > 0 && function_exists('invite_referral_award_for_expiry_change')){
    invite_referral_award_for_expiry_change($storeId, $oldTs, $ts);
  }
  if($ts > $oldTs){
    expiry_history_append($storeId, $oldTs, $ts, is_array($historyMeta) ? $historyMeta : []);
  }
  return $saved;
}
function is_store_expired($storeId){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  // Hanya sesi Developer M604 yang bebas expired. M604 PIN 0000 mengikuti user biasa.
  if($storeId === ADMIN_STORE_ID && function_exists('m604_is_developer_session') && m604_is_developer_session()) return false;
  $ts = expiry_get_ts($storeId);
  return ($ts > 0 && jakarta_now_ts() >= $ts);
}

function session_duration_unit($unit){
  $unit = strtolower(trim((string)$unit));
  $aliases = [
    'minute'=>'minute', 'minutes'=>'minute', 'menit'=>'minute',
    'hour'=>'hour', 'hours'=>'hour', 'jam'=>'hour',
    'day'=>'day', 'days'=>'day', 'hari'=>'day'
  ];
  return (string)($aliases[$unit] ?? '');
}
function session_duration_seconds($value, $unit){
  $value = (int)$value;
  $unit = session_duration_unit($unit);
  $multipliers = ['minute'=>60, 'hour'=>3600, 'day'=>86400];
  if($value < 1 || !isset($multipliers[$unit])) return 0;
  $maxValue = (int)floor(SESSION_MAX_TIMEOUT_SEC / $multipliers[$unit]);
  if($value > $maxValue) return 0;
  return $value * $multipliers[$unit];
}
function session_duration_label($value, $unit){
  $value = max(1, (int)$value);
  $unit = session_duration_unit($unit);
  $labels = ['minute'=>'menit', 'hour'=>'jam', 'day'=>'hari'];
  return $value . ' ' . ($labels[$unit] ?? 'menit');
}
function session_config_default(){
  return [
    'value'=>1,
    'unit'=>'day',
    'seconds'=>SESSION_IDLE_TIMEOUT_SEC,
    'label'=>'1 hari',
    'updatedAt'=>null,
    'updatedBy'=>null
  ];
}
function session_config_read(){
  $fallback = session_config_default();
  if(!defined('SESSION_CONFIG_FILE') || !is_file(SESSION_CONFIG_FILE)) return $fallback;
  $j = json_file_read_array_safe(SESSION_CONFIG_FILE, $fallback);
  $unit = session_duration_unit($j['unit'] ?? '');
  $value = (int)($j['value'] ?? 0);
  // Migrasi nilai bawaan lama 3 menit. Pengaturan 3 menit yang memang pernah
  // disimpan admin tetap dihormati karena memiliki updatedBy/updatedAt.
  if($value === 3 && $unit === 'minute' && empty($j['updatedBy']) && empty($j['updatedAt'])) return $fallback;
  $seconds = session_duration_seconds($value, $unit);
  if($seconds <= 0) return $fallback;
  return [
    'value'=>$value,
    'unit'=>$unit,
    'seconds'=>$seconds,
    'label'=>session_duration_label($value, $unit),
    'updatedAt'=>(string)($j['updatedAt'] ?? ''),
    'updatedBy'=>(string)($j['updatedBy'] ?? '')
  ];
}
function session_config_write($value, $unit, $actor=''){
  $unit = session_duration_unit($unit);
  $value = (int)$value;
  $seconds = session_duration_seconds($value, $unit);
  if($seconds <= 0) return false;
  $payload = [
    'value'=>$value,
    'unit'=>$unit,
    'seconds'=>$seconds,
    'label'=>session_duration_label($value, $unit),
    'updatedAt'=>date('c'),
    'updatedBy'=>strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$actor))
  ];
  return json_file_write_array_safe(SESSION_CONFIG_FILE, $payload) ? $payload : false;
}
function admin_report_session_unlocked(){
  return (int)($_SESSION['admin_report_ok_until'] ?? 0) >= time();
}

function active_sessions_read_all(){
  if(!defined('ACTIVE_SESSION_FILE') || !file_exists(ACTIVE_SESSION_FILE)) return ['sessions'=>[], 'updatedAt'=>null];
  $j = json_decode(@file_get_contents(ACTIVE_SESSION_FILE), true);
  if(!is_array($j)) return ['sessions'=>[], 'updatedAt'=>null];
  if(!isset($j['sessions']) || !is_array($j['sessions'])) $j['sessions'] = [];
  return $j;
}
function active_sessions_lock_open(){
  if(!defined('ACTIVE_SESSION_FILE')) return false;
  $fp = @fopen(ACTIVE_SESSION_FILE . '.lock', 'c+');
  if(!$fp) return false;
  if(!@flock($fp, LOCK_EX)){ @fclose($fp); return false; }
  return $fp;
}
function active_sessions_lock_close($fp){
  if(is_resource($fp)){ @flock($fp, LOCK_UN); @fclose($fp); }
}
function active_session_timeout_for_store($storeId, $role=''){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  $role = strtolower(preg_replace('/[^a-z]/','', (string)$role));
  if($role === '' && $storeId === ADMIN_STORE_ID){
    $role = strtolower((string)($_SESSION['m604_role'] ?? ''));
  }
  if($storeId === ADMIN_STORE_ID && in_array($role, ['developer','bantuan'], true)){
    return ADMIN_SESSION_LIFETIME_SEC;
  }
  $config = session_config_read();
  return max(60, (int)($config['seconds'] ?? SESSION_IDLE_TIMEOUT_SEC));
}
function active_sessions_write_all($items, $lockHeld=false){
  $lock = $lockHeld ? false : active_sessions_lock_open();
  $clean = [];
  $now = time();
  foreach((array)$items as $k=>$row){
    if(!is_array($row)) continue;
    $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)($row['storeId'] ?? '')));
    $token = preg_replace('/[^a-f0-9]/i','', (string)($row['token'] ?? $k));
    $role = strtolower(preg_replace('/[^a-z]/','', (string)($row['role'] ?? '')));
    if(!in_array($role, ['developer','bantuan'], true)) $role = '';
    if($storeId !== ADMIN_STORE_ID) $role = '';
    if($storeId==='' || $token==='') continue;
    $last = (int)($row['lastSeenTs'] ?? 0);
    $expiresAt = (int)($row['expiresAt'] ?? 0);
    $active = !empty($row['active']);
    $closedAt = max(0, (int)($row['closedAt'] ?? 0));
    if($expiresAt > 0 && $now >= $expiresAt) continue;
    if($last > 0 && ($now - $last) > (active_session_timeout_for_store($storeId, $role) * 4)) continue;
    if(!$active && ($closedAt <= 0 || ($now - $closedAt) > 86400)) continue;
    $clean[$token] = [
      'storeId'=>$storeId,
      'token'=>$token,
      'role'=>$role,
      'lastSeenTs'=>$last,
      'expiresAt'=>$expiresAt,
      'active'=>$active,
      'closedAt'=>$closedAt,
      'updatedAt'=>date('c')
    ];
  }
  $payload = ['sessions'=>$clean,'updatedAt'=>date('c')];
  $encoded = json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
  $tmp = ACTIVE_SESSION_FILE . '.tmp';
  $saved = ($encoded !== false) ? @file_put_contents($tmp, $encoded, LOCK_EX) : false;
  if($saved !== false){
    if(!@rename($tmp, ACTIVE_SESSION_FILE)) $saved = @file_put_contents(ACTIVE_SESSION_FILE, $encoded, LOCK_EX);
  }
  if(!$lockHeld) active_sessions_lock_close($lock);
  return $saved === false ? false : $payload;
}
function active_session_new($storeId, $role=''){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  $role = strtolower(preg_replace('/[^a-z]/','', (string)$role));
  if(!in_array($role, ['developer','bantuan'], true)) $role = '';
  if($storeId !== ADMIN_STORE_ID) $role = '';
  try{ $token = function_exists('random_bytes') ? bin2hex(random_bytes(16)) : md5(uniqid('', true).mt_rand()); }
  catch(Throwable $e){ $token = md5(uniqid('', true).mt_rand()); }
  $lock = active_sessions_lock_open();
  $all = active_sessions_read_all();
  $items = is_array($all['sessions'] ?? null) ? $all['sessions'] : [];
  $isAdminSession = ($storeId === ADMIN_STORE_ID && $role !== '');
  $items[$token] = [
    'storeId'=>$storeId,
    'token'=>$token,
    'role'=>$role,
    'lastSeenTs'=>time(),
    'expiresAt'=>$isAdminSession ? time() + ADMIN_SESSION_LIFETIME_SEC : 0,
    'active'=>true,
    'closedAt'=>0,
    'updatedAt'=>date('c')
  ];
  $saved = active_sessions_write_all($items, true);
  active_sessions_lock_close($lock);
  if($saved === false) return '';
  $_SESSION['active_token'] = $token;
  $_SESSION['last_activity'] = time();
  return $token;
}
function active_session_touch($storeId='', $token=''){
  static $touchedInRequest = [];
  $storeInput = (is_scalar($storeId) && (string)$storeId !== '') ? $storeId : ($_SESSION['storeId'] ?? '');
  $storeId = is_scalar($storeInput) ? strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)$storeInput)) : '';
  $tokenInput = (is_scalar($token) && (string)$token !== '') ? $token : ($_SESSION['active_token'] ?? '');
  $token = is_scalar($tokenInput) ? preg_replace('/[^a-f0-9]/i', '', (string)$tokenInput) : '';
  if($storeId==='' || $token==='') return false;
  if(isset($touchedInRequest[$token])) return true;
  $lock = active_sessions_lock_open();
  $all = active_sessions_read_all();
  $items = is_array($all['sessions'] ?? null) ? $all['sessions'] : [];
  $row = is_array($items[$token] ?? null) ? $items[$token] : null;
  if(!$row || empty($row['active']) || !hash_equals((string)($row['storeId'] ?? ''), $storeId)){
    active_sessions_lock_close($lock);
    return false;
  }
  $role = strtolower((string)($row['role'] ?? ($_SESSION['m604_role'] ?? '')));
  $expiresAt = (int)($row['expiresAt'] ?? 0);
  if($expiresAt > 0 && time() >= $expiresAt){ active_sessions_lock_close($lock); return false; }
  if((time() - (int)($row['lastSeenTs'] ?? 0)) > active_session_timeout_for_store($storeId, $role)){
    active_sessions_lock_close($lock);
    return false;
  }
  $items[$token]['lastSeenTs'] = time();
  $items[$token]['active'] = true;
  $items[$token]['closedAt'] = 0;
  $saved = active_sessions_write_all($items, true);
  active_sessions_lock_close($lock);
  if($saved === false) return false;
  $touchedInRequest[$token] = true;
  $_SESSION['last_activity'] = time();
  return true;
}
function active_session_is_valid($storeId, $token, $role=''){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  $token = preg_replace('/[^a-f0-9]/i','', (string)$token);
  if($storeId==='' || $token==='') return false;
  $all = active_sessions_read_all();
  $row = is_array(($all['sessions'] ?? [])[$token] ?? null) ? $all['sessions'][$token] : null;
  if(!$row || empty($row['active']) || !hash_equals((string)($row['storeId'] ?? ''), $storeId)) return false;
  $rowRole = strtolower((string)($row['role'] ?? $role));
  $expiresAt = (int)($row['expiresAt'] ?? 0);
  if($expiresAt > 0 && time() >= $expiresAt) return false;
  return ((time() - (int)($row['lastSeenTs'] ?? 0)) <= active_session_timeout_for_store($storeId, $rowRole));
}
function active_session_recover_visible_page($storeId, $token, $role=''){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  $token = preg_replace('/[^a-f0-9]/i','', (string)$token);
  $role = strtolower(preg_replace('/[^a-z]/','', (string)$role));
  if(!in_array($role, ['developer','bantuan'], true)) $role = '';
  if($storeId !== ADMIN_STORE_ID) $role = '';
  if($storeId==='' || $token==='') return false;
  $lock = active_sessions_lock_open();
  $all = active_sessions_read_all();
  $items = is_array($all['sessions'] ?? null) ? $all['sessions'] : [];
  $row = is_array($items[$token] ?? null) ? $items[$token] : null;
  // Sesi yang ditutup secara eksplisit atau sudah melewati 24 jam tidak boleh dihidupkan kembali.
  if(!$row || empty($row['active']) || !hash_equals((string)($row['storeId'] ?? ''), $storeId)){
    active_sessions_lock_close($lock);
    return false;
  }
  if((int)($row['expiresAt'] ?? 0) > 0 && time() >= (int)$row['expiresAt']){
    active_sessions_lock_close($lock);
    return false;
  }
  $lastSeen = max(0, (int)($row['lastSeenTs'] ?? 0));
  $recoverLimit = active_session_timeout_for_store($storeId, $role) + SESSION_VISIBLE_RECOVERY_GRACE_SEC;
  if($lastSeen <= 0 || (time() - $lastSeen) > $recoverLimit){
    active_sessions_lock_close($lock);
    return false;
  }
  $isAdminSession = ($storeId === ADMIN_STORE_ID && $role !== '');
  $items[$token] = [
    'storeId'=>$storeId,
    'token'=>$token,
    'role'=>$role,
    'lastSeenTs'=>time(),
    'expiresAt'=>$row ? (int)($row['expiresAt'] ?? 0) : ($isAdminSession ? time() + ADMIN_SESSION_LIFETIME_SEC : 0),
    'active'=>true,
    'closedAt'=>0,
    'updatedAt'=>date('c')
  ];
  $saved = active_sessions_write_all($items, true);
  active_sessions_lock_close($lock);
  if($saved === false) return false;
  $_SESSION['active_token'] = $token;
  $_SESSION['last_activity'] = time();
  return true;
}
function active_session_close($storeId='', $token=''){
  $storeInput = (is_scalar($storeId) && (string)$storeId !== '') ? $storeId : ($_SESSION['storeId'] ?? '');
  $storeId = is_scalar($storeInput) ? strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)$storeInput)) : '';
  $tokenInput = (is_scalar($token) && (string)$token !== '') ? $token : ($_SESSION['active_token'] ?? '');
  $token = is_scalar($tokenInput) ? preg_replace('/[^a-f0-9]/i', '', (string)$tokenInput) : '';
  if($token==='') return false;
  $lock = active_sessions_lock_open();
  $all = active_sessions_read_all();
  $items = is_array($all['sessions'] ?? null) ? $all['sessions'] : [];
  if(isset($items[$token])){
    $items[$token]['active'] = false;
    $items[$token]['lastSeenTs'] = 0;
    $items[$token]['closedAt'] = time();
  }
  $saved = active_sessions_write_all($items, true);
  active_sessions_lock_close($lock);
  return $saved !== false;
}

function cookie_clean_last_store($storeId){
  return strtoupper(substr(preg_replace('/[^A-Z0-9]/','', (string)$storeId), 0, 4));
}
function cookie_set_last_store($storeId){
  $storeId = cookie_clean_last_store($storeId);
  if(strlen($storeId) !== 4) return false;
  setcookie(LAST_STORE_COOKIE_NAME, $storeId, [
    "expires"=>time() + LAST_STORE_COOKIE_MAX_AGE_SEC,
    "path"=>"/",
    "httponly"=>false,
    "samesite"=>"Lax",
    "secure"=>(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off'),
  ]);
  $_COOKIE[LAST_STORE_COOKIE_NAME] = $storeId;
  return true;
}
function cookie_read_last_store(){
  $storeId = cookie_clean_last_store($_COOKIE[LAST_STORE_COOKIE_NAME] ?? '');
  return strlen($storeId) === 4 ? $storeId : '';
}

function cookie_set_session($storeId, $_legacyPersistentLogin=false, $role=''){
  if(function_exists('session_status') && session_status() !== PHP_SESSION_ACTIVE) @session_start();
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  $role = strtolower(preg_replace('/[^a-z]/','', (string)$role));
  if(!in_array($role, ['developer','bantuan'], true)) $role = '';
  if($storeId !== ADMIN_STORE_ID) $role = '';
  if($storeId === '') return false;
  $previousToken = is_scalar($_SESSION['active_token'] ?? '') ? (string)($_SESSION['active_token'] ?? '') : '';
  if($previousToken !== '') active_session_close((string)($_SESSION['storeId'] ?? ''), $previousToken);
  if(function_exists('session_regenerate_id')){ @session_regenerate_id(true); }
  // Argumen kedua dipertahankan untuk kompatibilitas pemanggil lama. Cookie
  // autentikasi dibuat persisten; batas idle tetap ditentukan server.
  $isAdminSession = ($storeId === ADMIN_STORE_ID && in_array($role, ['developer','bantuan'], true));
  $sessionLifetime = $isAdminSession ? ADMIN_SESSION_LIFETIME_SEC : SESSION_TOKEN_MAX_AGE_SEC;
  $activeToken = active_session_new($storeId, $role);
  if($activeToken === '') return false;
  $payload = [
    "storeId"=>$storeId,
    "exp"=> time() + $sessionLifetime,
    "activeToken" => $activeToken,
    "role" => $role
  ];
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
  $b64  = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
  $sig  = hmac_sign($b64);
  $val  = $b64 . "." . $sig;

  $cookieParams = [
    "path"=>"/",
    "expires"=>time() + $sessionLifetime,
    "httponly"=>true,
    "samesite"=>"Lax",
    "secure"=>(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off'),
  ];
  setcookie(COOKIE_NAME, $val, $cookieParams);
  $_COOKIE[COOKIE_NAME] = $val;

  $_SESSION['storeId'] = $storeId;
  $_SESSION['exp'] = $payload["exp"];
  $_SESSION['active_token'] = $activeToken;
  $_SESSION['m604_role'] = $role;
  $_SESSION['last_activity'] = time();
  if($isAdminSession && $role === 'developer'){
    $_SESSION['admin_ok'] = true;
    $_SESSION['admin_ok_ts'] = time();
    $_SESSION['admin_ok_until'] = (int)$payload['exp'];
  }
  return true;
}

function cookie_clear_session(){
  active_session_close();
  setcookie(COOKIE_NAME, '', [
    "expires"=> time()-3600,
    "path"=>"/",
    "httponly"=>true,
    "samesite"=>"Lax",
    "secure"=>(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off'),
  ]);
  unset($_COOKIE[COOKIE_NAME]);
  $_SESSION = [];
  if(session_id()!==''){
    $phpSessionName = session_name();
    @session_destroy();
    if($phpSessionName !== ''){
      setcookie($phpSessionName, '', [
        "expires"=>time()-3600,
        "path"=>"/",
        "httponly"=>true,
        "samesite"=>"Lax",
        "secure"=>(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off'),
      ]);
    }
  }
}

function m604_session_role(){
  $role = strtolower((string)($_SESSION['m604_role'] ?? ''));
  return in_array($role, ['developer','bantuan'], true) ? $role : '';
}
function m604_is_developer_session(){
  if((string)($_SESSION['storeId'] ?? '') !== ADMIN_STORE_ID) return false;
  return m604_session_role() === 'developer';
}
function m604_is_bantuan_session(){
  if((string)($_SESSION['storeId'] ?? '') !== ADMIN_STORE_ID) return false;
  return m604_session_role() === 'bantuan';
}

function enforce_expiry_cleanup($storeId){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  if($storeId==='') return;
  if($storeId===ADMIN_STORE_ID && function_exists('m604_is_developer_session') && m604_is_developer_session()) return;
  if(is_store_expired($storeId)){
    // Akun M604 user biasa boleh expired, tetapi data akun developer M604 tidak dihapus.
    if($storeId===ADMIN_STORE_ID){ presence_set_offline($storeId); return; }
    expiry_set_ts($storeId, 0);
    pin_delete($storeId);
    premium_delete($storeId);
    admin2_delete($storeId);
    oh979_delete($storeId);
    plano_delete_store($storeId);
    presence_set_offline($storeId);
    $db = read_store_db();
    if(isset($db['stores']) && is_array($db['stores'])){
      $stores = array_values(array_filter($db['stores'], fn($s)=>$s!==$storeId));
      write_store_db($stores);
    }
  }
}
function cleanup_all_expired_stores(){
  $all = expiry_read_all();
  $stores = $all['stores'] ?? [];
  if(!is_array($stores) || !$stores) return 0;
  $removed = 0;
  foreach(array_keys($stores) as $storeId){
    $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
    if($storeId === '' || $storeId === ADMIN_STORE_ID) continue;
    if(is_store_expired($storeId)){
      enforce_expiry_cleanup($storeId);
      $removed++;
    }
  }
  return $removed;
}

function ensure_global_expiry_cleanup(){
  static $done = false;
  if($done) return 0;
  $done = true;
  return cleanup_all_expired_stores();
}

function cookie_read_session($allowVisiblePageRecovery=null){
  // Pemanggil halaman HTML tanpa argumen boleh memakai toleransi recovery.
  // Endpoint API selalu mengirim boolean eksplisit sehingga timeout admin tetap
  // benar-benar diterapkan dan polling latar tidak menghidupkan sesi mati.
  if($allowVisiblePageRecovery === null){
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    $allowVisiblePageRecovery = (!isset($_GET['api']) && $method === 'GET' && ($accept === '' || strpos($accept, 'text/html') !== false));
  }else{
    $allowVisiblePageRecovery = (bool)$allowVisiblePageRecovery;
  }
  if(isset($_SESSION['storeId'], $_SESSION['exp'])){
    $sid = is_scalar($_SESSION['storeId']) ? (string)$_SESSION['storeId'] : '';
    if(time() < (int)$_SESSION['exp']){
      // Request data biasa hanya memeriksa sesi. Perpanjangan sesi dilakukan
      // khusus oleh heartbeat halaman yang sedang terlihat agar polling latar
      // belakang tidak menggagalkan batas sesi yang ditetapkan admin.
      $activeTokenRaw = $_SESSION['active_token'] ?? '';
      $activeToken = is_scalar($activeTokenRaw) ? (string)$activeTokenRaw : '';
      $activeOk = ($sid !== '' && active_session_is_valid($sid, $activeToken, (string)($_SESSION['m604_role'] ?? '')));
      if(!$activeOk && !($allowVisiblePageRecovery && active_session_recover_visible_page($sid, $activeToken, (string)($_SESSION['m604_role'] ?? '')))){
        cookie_clear_session();
        return '';
      }
      active_session_touch($sid, $activeToken);
      if($sid === ADMIN_STORE_ID && strtolower((string)($_SESSION['m604_role'] ?? '')) === 'developer'){
        $_SESSION['admin_ok'] = true;
        if(empty($_SESSION['admin_ok_ts'])) $_SESSION['admin_ok_ts'] = time();
        $_SESSION['admin_ok_until'] = (int)$_SESSION['exp'];
      }
      // Developer M604 saja yang tidak divalidasi sebagai user biasa.
      // M604 dengan PIN 0000 tetap diperlakukan seperti user umum.
      if($sid === ADMIN_STORE_ID && function_exists('m604_is_developer_session') && m604_is_developer_session()){
        return $sid;
      }
      if(is_store_expired($sid)){
        enforce_expiry_cleanup($sid);
        cookie_clear_session();
        return '';
      }
      $dbS = read_store_db();
      // User Key Grand disimpan terpisah dari user toko biasa, jadi jangan paksa ada di allowed stores.
      $isSograndSession = function_exists('sogrand_user_get') && sogrand_user_get($sid);
      if(!$isSograndSession && !in_array($sid, ($dbS['stores']??[]), true)){
        cookie_clear_session();
        return '';
      }
      return $sid;
    }
  }
  $raw = $_COOKIE[COOKIE_NAME] ?? '';
  if(!$raw || strpos($raw,'.')===false) return '';
  [$b64,$sig] = explode('.', $raw, 2);
  if(!$b64 || !$sig) return '';
  if(!hash_equals(hmac_sign($b64), $sig)) return '';
  $json = base64_decode(strtr($b64, '-_', '+/'), true);
  if($json===false) return '';
  $p = json_decode($json, true);
  if(!is_array($p) || empty($p['storeId']) || empty($p['exp']) || empty($p['activeToken'])) return '';
  if(time() >= (int)$p['exp']) return '';
  if(!active_session_is_valid((string)$p['storeId'], (string)$p['activeToken'], (string)($p['role'] ?? '')) && !($allowVisiblePageRecovery && active_session_recover_visible_page((string)$p['storeId'], (string)$p['activeToken'], (string)($p['role'] ?? '')))){ cookie_clear_session(); return ''; }
  // cek expired per user. Developer M604 saja yang tidak wajib ada di allowed stores.
  $isDeveloperCookieSession = ((string)$p['storeId'] === ADMIN_STORE_ID && strtolower((string)($p['role'] ?? '')) === 'developer');
  if(!$isDeveloperCookieSession){
    if(is_store_expired((string)$p['storeId'])){
      enforce_expiry_cleanup((string)$p['storeId']);
      cookie_clear_session();
      return '';
    }
    // cek store masih ada di allowed stores (jika admin hapus -> auto logout saat refresh).
    // Pengecualian: user Key Grand valid boleh masuk Cetak Selisih meski tidak terdaftar sebagai user toko biasa.
    $db2 = read_store_db();
    $isSograndCookie = function_exists('sogrand_user_get') && sogrand_user_get((string)$p['storeId']);
    if(!$isSograndCookie && !in_array((string)$p['storeId'], ($db2['stores']??[]), true)){
      cookie_clear_session();
      return '';
    }
  }
  $_SESSION['storeId'] = (string)$p['storeId'];
  $_SESSION['exp'] = (int)$p['exp'];
  $_SESSION['active_token'] = (string)$p['activeToken'];
  $_SESSION['m604_role'] = strtolower((string)($p['role'] ?? ''));
  $_SESSION['last_activity'] = time();
  if((string)$p['storeId'] === ADMIN_STORE_ID && strtolower((string)($p['role'] ?? '')) === 'developer'){
    $_SESSION['admin_ok'] = true;
    $_SESSION['admin_ok_ts'] = time();
    $_SESSION['admin_ok_until'] = (int)$p['exp'];
  }
  active_session_touch((string)$p['storeId'], (string)$p['activeToken']);
  return (string)$p['storeId'];
}

function impersonation_is_active(){
  $admin = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)($_SESSION['impersonation_admin'] ?? '')));
  $target = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)($_SESSION['storeId'] ?? '')));
  return ($admin !== '' && $admin === ADMIN_STORE_ID && $target !== '' && $target !== ADMIN_STORE_ID);
}
function impersonation_admin_store(){
  return impersonation_is_active() ? strtoupper((string)($_SESSION['impersonation_admin'] ?? '')) : '';
}
function impersonation_start($targetStoreId){
  $targetStoreId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$targetStoreId));
  if($targetStoreId === '' || $targetStoreId === ADMIN_STORE_ID) return false;
  $_SESSION['impersonation_admin'] = ADMIN_STORE_ID;
  $_SESSION['impersonation_started_at'] = time();
  if(!cookie_set_session($targetStoreId, false)){
    unset($_SESSION['impersonation_admin'], $_SESSION['impersonation_started_at']);
    return false;
  }
  $_SESSION['impersonation_admin'] = ADMIN_STORE_ID;
  $_SESSION['impersonation_started_at'] = time();
  return true;
}
function impersonation_stop(){
  if(!impersonation_is_active()) return false;
  $admin = strtoupper((string)($_SESSION['impersonation_admin'] ?? ''));
  unset($_SESSION['impersonation_admin'], $_SESSION['impersonation_started_at']);
  // Kembali ke akun Developer M604 (PIN 2727), bukan sesi M604 user biasa/PIN 0000.
  if(!cookie_set_session($admin, false, 'developer')) return false;
  $_SESSION['admin_ok'] = true;
  $_SESSION['admin_ok_ts'] = time();
  $_SESSION['admin_ok_until'] = time() + ADMIN_SESSION_LIFETIME_SEC;
  return true;
}

/*
 * Guard halaman/iframe/download non-API. Saat M604 user biasa dikunci,
 * seluruh tampilan termasuk report PPS, Clerek, dan halaman iframe diganti
 * dengan keterangan AHO. Request aset JS/CSS serta API status tetap lewat.
 */
if(!isset($_GET['api']) && !isset($_GET['js']) && !isset($_GET['css'])){
  $m604GuardStore = cookie_read_session();
  $m604FeatureRequest = (
    isset($_GET['m604_server_locked']) ||
    isset($_GET['page']) ||
    isset($_GET['download']) ||
    isset($_GET['type']) ||
    isset($_GET['path'])
  );
  // Halaman utama tetap dapat dibuka. Keterangan server baru muncul setelah
  // user M604 membuka salah satu menu/fitur.
  if($m604FeatureRequest && m604_server_block_applies($m604GuardStore)){
    m604_server_render_locked_page();
  }
}

/* =========================
   API ROUTES
========================= */


/* =========================
   PLANOGRAM PROXY (kompatibel Plano.php)
   - Endpoint: ?type=list&storeId=XXXX
   - Endpoint: ?type=onhand&storeId=XXXX&plus=1,2,3
   Catatan: Ini dibuat supaya UI Planogram + OH benar-benar sama outputnya seperti plano.php
========================= */
function planogram_curl_get($url, $timeout = 8){
  if(!function_exists('curl_init')) return [false, "cURL tidak tersedia di server"];

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => 2,
    CURLOPT_TIMEOUT        => $timeout,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_HTTPHEADER     => ["Accept: application/json", "Cache-Control: no-cache", "Pragma: no-cache"],
    CURLOPT_USERAGENT      => "Mozilla/5.0 (PlanogramProxyFast/1.1)"
  ]);

  $body = curl_exec($ch);
  $err  = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if($body === false) return [false, $err ?: "Gagal request"];
  if($code < 200 || $code >= 300) return [false, "HTTP $code"];
  return [true, $body];
}

function planogram_cache_dir(){
  $dir = __DIR__ . '/.alfa_cache';
  if(!is_dir($dir)) @mkdir($dir, 0775, true);
  return is_dir($dir) && is_writable($dir) ? $dir : sys_get_temp_dir();
}
function planogram_cache_file($storeId){
  return planogram_cache_dir() . '/product_list_' . preg_replace('/[^A-Z0-9]/','', strtoupper((string)$storeId)) . '.json';
}
function planogram_cache_read($storeId, $ttl = 21600){
  $file = planogram_cache_file($storeId);
  if(!is_file($file)) return null;
  if((time() - (int)@filemtime($file)) > $ttl) return null;
  $body = @file_get_contents($file);
  if($body === false || $body === '') return null;
  json_decode($body, true);
  if(json_last_error() !== JSON_ERROR_NONE) return null;
  return $body;
}
function planogram_cache_write($storeId, $body){
  $file = planogram_cache_file($storeId);
  @file_put_contents($file, (string)$body, LOCK_EX);
}

function planogram_onhand_cache_file($storeId, $plu){
  return planogram_cache_dir() . '/onhand_' . preg_replace('/[^A-Z0-9]/','', strtoupper((string)$storeId)) . '_' . preg_replace('/[^0-9]/','', (string)$plu) . '.json';
}
function planogram_onhand_cache_read($storeId, $plu, $ttl = 300){
  $file = planogram_onhand_cache_file($storeId, $plu);
  if(!is_file($file)) return null;
  if((time() - (int)@filemtime($file)) > $ttl) return null;
  $body = @file_get_contents($file);
  if($body === false || $body === '') return null;
  $j = json_decode($body, true);
  if(!is_array($j) || !array_key_exists('on_hand', $j)) return null;
  return $j;
}
function planogram_onhand_cache_write($storeId, $plu, $onhand){
  $file = planogram_onhand_cache_file($storeId, $plu);
  @file_put_contents($file, json_encode(['plu'=>(string)$plu,'on_hand'=>$onhand,'cachedAt'=>time()], JSON_UNESCAPED_UNICODE), LOCK_EX);
}
function planogram_onhand_multi_fetch($storeId, $pluList, $timeout = 10){
  $result = [];
  $pending = [];
  foreach($pluList as $plu){
    $plu = preg_replace('/[^0-9]/','', (string)$plu);
    if($plu === '') continue;
    $cached = planogram_onhand_cache_read($storeId, $plu, 300);
    if($cached !== null){
      $result[$plu] = ['plu'=>$plu, 'on_hand'=>$cached['on_hand'], 'cache'=>true];
    }else{
      $pending[] = $plu;
      $result[$plu] = ['plu'=>$plu, 'on_hand'=>null];
    }
  }
  if(!$pending) return $result;
  if(!function_exists('curl_multi_init')){
    foreach($pending as $plu){
      $url = "https://app.alfastore.co.id/to/api/cex/get_product_detail/?storeId=".urlencode($storeId)."&plu=".urlencode($plu);
      [$ok, $body] = planogram_curl_get($url, $timeout);
      if($ok){
        $json = json_decode($body, true);
        $oh = null;
        if(isset($json[0]) && is_array($json[0])) $oh = isset($json[0]['onhand']) ? (int)$json[0]['onhand'] : null;
        $result[$plu] = ['plu'=>$plu, 'on_hand'=>$oh];
        planogram_onhand_cache_write($storeId, $plu, $oh);
      }
    }
    return $result;
  }
  $mh = curl_multi_init();
  $handles = [];
  foreach($pending as $plu){
    $url = "https://app.alfastore.co.id/to/api/cex/get_product_detail/?storeId=".urlencode($storeId)."&plu=".urlencode($plu);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_CONNECTTIMEOUT => 2,
      CURLOPT_TIMEOUT => $timeout,
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_SSL_VERIFYHOST => 2,
      CURLOPT_HTTPHEADER => ["Accept: application/json"],
      CURLOPT_USERAGENT => "Mozilla/5.0 (AlfastoreOHBatch/2.0)"
    ]);
    curl_multi_add_handle($mh, $ch);
    $handles[(int)$ch] = [$ch, $plu];
  }
  $running = null;
  do {
    $status = curl_multi_exec($mh, $running);
    if($running) curl_multi_select($mh, 0.5);
  } while($running && $status == CURLM_OK);
  foreach($handles as $pair){
    [$ch, $plu] = $pair;
    $body = curl_multi_getcontent($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $oh = null;
    if($body !== false && $code >= 200 && $code < 300){
      $json = json_decode($body, true);
      if(isset($json[0]) && is_array($json[0])) $oh = isset($json[0]['onhand']) ? (int)$json[0]['onhand'] : null;
      planogram_onhand_cache_write($storeId, $plu, $oh);
    }
    $result[$plu] = ['plu'=>$plu, 'on_hand'=>$oh];
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
  }
  curl_multi_close($mh);
  return $result;
}

if(isset($_GET['type']) && !isset($_GET['api'])){
  $type    = (string)($_GET['type'] ?? 'list');
  $storeId = strtoupper(trim((string)($_GET['storeId'] ?? '')));
  $plus    = trim((string)($_GET['plus'] ?? ''));

  $storeId = preg_replace('/[^A-Z0-9]/', '', $storeId);

  if(!$storeId){
    json_out(["status"=>false,"message"=>"storeId kosong"], 400);
  }

  // LIST PLANOGRAM: return ARRAY langsung (bukan wrapper) agar JS kompatibel (seperti plano.php)
  if($type === "list"){
    // Fast respond: gunakan cache server 6 jam supaya autocomplete nama barang tidak menunggu API eksternal setiap kali modal dibuka.
    $cachedBody = planogram_cache_read($storeId, 21600);
    if($cachedBody !== null){
      header("Content-Type: application/json; charset=UTF-8");
      header("Cache-Control: public, max-age=300, stale-while-revalidate=21600");
      header("X-Alfa-Cache: HIT");
      echo $cachedBody;
      exit;
    }

    $url = "https://app.alfastore.co.id/to/api/cex/get_product_list/?storeId=".$storeId;
    [$ok, $body] = planogram_curl_get($url, 8);
    if(!$ok){
      json_out(["status"=>false,"message"=>"Gagal ambil list: ".$body], 502);
    }

    $decoded = json_decode($body, true);
    if($decoded === null && json_last_error() !== JSON_ERROR_NONE){
      json_out(["status"=>false,"message"=>"Response bukan JSON valid"], 502);
    }

    $out = json_encode($decoded, JSON_UNESCAPED_UNICODE);
    planogram_cache_write($storeId, $out);
    header("Content-Type: application/json; charset=UTF-8");
    header("Cache-Control: public, max-age=300, stale-while-revalidate=21600");
    header("X-Alfa-Cache: MISS");
    echo $out;
    exit;
  }
  // ON HAND: return wrapper status/data (seperti plano.php)
  if($type === "onhand"){
    if(!$plus){
      json_out(["status"=>false,"message"=>"plu kosong"], 400);
    }

    $plus = preg_replace('/[^0-9,]/', '', $plus);
    $pluList = array_values(array_unique(array_filter(array_map(function($x){ return preg_replace('/[^0-9]/','', trim((string)$x)); }, explode(',', $plus)))));
    if(count($pluList) === 0){
      json_out(["status"=>false,"message"=>"plu tidak valid"], 400);
    }
    // Dinaikkan supaya data banyak tetap bisa diproses. Request eksternal dijalankan paralel + cache 5 menit.
    if(count($pluList) > 1000){
      json_out(["status"=>false,"message"=>"Maksimal 1000 PLU per request"], 413);
    }

    $map = planogram_onhand_multi_fetch($storeId, $pluList, 10);
    $result = [];
    foreach($pluList as $plu){
      $result[] = $map[$plu] ?? ["plu"=>$plu, "on_hand"=>null];
    }

    header("Cache-Control: public, max-age=60, stale-while-revalidate=300");
    json_out(["status"=>true, "data"=>$result, "serverTs"=>time()], 200);
  }

  json_out(["status"=>false,"message"=>"type tidak dikenal"], 400);
}


/* =========================
   JS ASSET HANDLER
========================= */
function js_out($js){
  header('Content-Type: application/javascript; charset=utf-8');
  header('X-Content-Type-Options: nosniff');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  echo $js;
  exit;
}

function css_out($css){
  header('Content-Type: text/css; charset=utf-8');
  header('X-Content-Type-Options: nosniff');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  echo $css;
  exit;
}
function serve_css_asset($name){
  switch((string)$name){

    case 'style-1':
      css_out(<<<'CSS'
body{margin:0;font-family:system-ui,Segoe UI,Roboto,Arial;background:linear-gradient(135deg,#4f46e5,#2563eb);padding:18px}.box{max-width:720px;margin:auto;background:#fff;border-radius:18px;box-shadow:0 20px 60px rgba(2,6,23,.22);padding:18px}a{color:#4f46e5;font-weight:900}
CSS
      );
      break;

    case 'style-2':
      css_out(<<<'CSS'

:root{
  --ungu-utama:#2563eb;--ungu-kedua:#3b82f6;--ungu-ketiga:#93c5fd;--ungu-terang:#eff6ff;
  --ungu-border:#bfdbfe;--putih:#ffffff;--teks:#0f2f5f;--teks-soft:#42699d;--bahaya:#e11d48;
  --bahaya-hover:#be123c;--bayangan:0 14px 34px rgba(37,99,235,.16);
}
*{box-sizing:border-box}
body{margin:0;font-family:Arial,Helvetica,sans-serif;background:#f8fbff;color:var(--teks);min-height:100vh}

.oh979-topbar{position:sticky;top:0;z-index:1000;display:flex;justify-content:flex-end;padding:10px 14px;background:rgba(255,255,255,.88);backdrop-filter:blur(10px);border-bottom:1px solid rgba(37,99,235,.16)}
.oh979-back{padding:11px 18px;border-radius:5px;background:#2563eb;color:#fff;box-shadow:0 8px 18px rgba(37,99,235,.18);font-size:14px}
.btn-store-type{padding:14px 20px;font-size:16px;border-radius:5px;background:#2563eb;color:#fff;box-shadow:0 8px 18px rgba(37,99,235,.16)}
.btn-store-type.active{outline:3px solid rgba(37,99,235,.20);filter:brightness(.95)}
.container{position:relative;width:min(98%,1600px);margin:18px auto;background:rgba(255,255,255,.97);border:1px solid var(--ungu-border);border-radius:22px;padding:18px;box-shadow:var(--bayangan);backdrop-filter:blur(8px)}
.info-box,.rack-card,.summary-card{background:#ffffff;border:2px solid rgba(37,99,235,.18);border-radius:18px;box-shadow:0 8px 20px rgba(37,99,235,.10)}
.info-box{padding:14px 16px;margin-bottom:18px;line-height:1.7;font-size:15px}.info-title{font-size:17px;font-weight:700;margin-bottom:6px;color:#1d4ed8}
.header{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px}h2{margin:0;font-size:28px;color:#1d4ed8}.subtitle{margin-top:6px;font-size:14px;color:var(--teks-soft);font-weight:700}
.actions{display:flex;gap:12px;flex-wrap:wrap}button{border:none;border-radius:5px;cursor:pointer;font-weight:700;transition:.2s ease}
.btn-start{padding:16px 34px;font-size:20px;background:linear-gradient(135deg,#2563eb,#3b82f6,#93c5fd);color:#fff;box-shadow:0 12px 26px rgba(37,99,235,.28)}
.btn-start:hover{transform:translateY(-1px) scale(1.01);filter:brightness(1.03)}.oh979-back-inline{background:#e5e7eb!important;color:#111827!important;box-shadow:none!important}.btn-delete{padding:14px 24px;font-size:16px;background:linear-gradient(135deg,var(--bahaya),#fb7185);color:#fff;box-shadow:0 10px 22px rgba(225,29,72,.20)}.btn-delete:hover{background:linear-gradient(135deg,var(--bahaya-hover),var(--bahaya))}
.status-wrap{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px}.loading{min-height:24px;font-weight:700;color:#1d4ed8;background:linear-gradient(135deg,rgba(37,99,235,.10),rgba(147,197,253,.12));border:1px solid var(--ungu-border);padding:10px 14px;border-radius:5px;width:100%}
.summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:16px}.summary-card{padding:14px}.summary-label{font-size:12px;font-weight:800;color:var(--teks-soft);text-transform:uppercase;letter-spacing:.5px}.summary-value{font-size:24px;font-weight:800;color:#1d4ed8;margin-top:6px}
.racks{display:grid;gap:16px}.rack-card{padding:14px}.rack-head{display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:10px}.rack-title{font-size:22px;font-weight:800;color:#1d4ed8}.rack-meta{font-size:13px;color:var(--teks-soft);font-weight:700}
.table-wrap{width:100%;overflow:auto}table{width:100%;border-collapse:collapse;table-layout:fixed;background:var(--putih);border-radius:5px;overflow:hidden}th,td{border:1px solid #dbeafe;padding:9px 8px;text-align:center;font-size:13px;word-wrap:break-word}th{background:linear-gradient(135deg,#2563eb,#3b82f6,#93c5fd);color:#fff;font-size:13px}td:nth-child(3){text-align:left}tbody tr:nth-child(even){background:#f8fbff}tbody tr:hover{background:#eff6ff}.empty{text-align:center;color:var(--teks-soft);padding:20px 12px;font-style:italic}
.btn-add-rak{background:#2563eb!important;color:#fff!important;border-radius:5px!important}
.rack-actions{display:flex;align-items:center;justify-content:flex-end;gap:8px;flex-wrap:wrap}
.btn-download-excel{border:0;border-radius:5px;background:#16a34a;color:#fff;font-weight:900;padding:10px 14px;box-shadow:0 8px 18px rgba(22,163,74,.18);cursor:pointer}
.btn-download-excel:hover{filter:brightness(1.04);transform:translateY(-1px)}
.custom-rak-list{display:flex;gap:10px;flex-wrap:wrap;margin:0 0 14px}
.custom-rak-pill{display:inline-flex;align-items:center;gap:8px;border:1px solid #bfdbfe;background:#fff;color:#1d4ed8;border-radius:5px;padding:10px 12px;font-weight:800;box-shadow:0 7px 16px rgba(37,99,235,.10)}
.custom-rak-pill.active{background:#2563eb;color:#fff}
.custom-rak-pill .rak-x{display:inline-grid;place-items:center;width:22px;height:22px;border-radius:5px;border:1px solid currentColor;background:rgba(255,255,255,.14);font-weight:900;line-height:1}
.custom-rak-modal{position:fixed;inset:0;display:none;align-items:center;justify-content:center;background:rgba(15,23,42,.45);z-index:100000;padding:18px}
.custom-rak-modal.show{display:flex}
.custom-rak-box{width:min(94vw,460px);background:#fff;border-radius:18px;border:1px solid #bfdbfe;box-shadow:0 25px 70px rgba(15,23,42,.28);padding:18px}
.custom-rak-box h3{margin:0 0 12px;color:#1d4ed8}.custom-rak-box label{display:block;margin:10px 0 6px;font-weight:900;color:#0f2f5f}.custom-rak-box input,.custom-rak-box textarea{width:100%;border:1px solid #bfdbfe;border-radius:5px;padding:12px;font-size:15px;outline:none}.custom-rak-box input:focus,.custom-rak-box textarea:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.12)}
.custom-rak-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:14px}.btn-cancel-rak,.btn-save-rak{border-radius:5px;padding:12px;font-size:15px}.btn-cancel-rak{background:#e5e7eb;color:#111827}.btn-save-rak{background:#2563eb;color:#fff}
.actions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;width:min(100%,520px)}.actions .btn-store-type,.actions .btn-delete{width:100%;min-width:0;min-height:52px;display:flex;align-items:center;justify-content:center;white-space:normal;padding:11px 10px;font-size:14px;line-height:1.25;border-radius:10px}.actions .btn-delete{grid-column:auto}.oh979-back-inline{display:none!important}
@media (max-width:768px){.container{width:calc(100% - 16px);margin:10px auto;padding:12px;border-radius:16px}h2{font-size:24px}.header{align-items:stretch}.actions{width:100%;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.actions .btn-delete,.actions .btn-store-type{width:100%;min-height:50px;padding:10px 7px;font-size:12px}.btn-start{font-size:22px}th,td{font-size:12px;padding:7px 6px}.rack-title{font-size:18px}.custom-rak-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.custom-rak-pill{width:100%;min-width:0;justify-content:space-between;overflow:hidden}.custom-rak-pill>span:first-child{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}}

CSS
      );
      break;


    case 'oh979-loading':
      css_out(<<<'CSS'
.oh979-loading-overlay{position:fixed;inset:0;display:none;align-items:center;justify-content:center;background:rgba(15,23,42,.45);backdrop-filter:blur(2px);z-index:99999;padding:20px}
.oh979-loading-overlay.show{display:flex}
.oh979-loading-card{width:min(92vw,360px);background:#fff;border-radius:22px;padding:24px 20px;box-shadow:0 25px 60px rgba(2,6,23,.28);text-align:center}
.oh979-loading-spinner{width:58px;height:58px;margin:0 auto 14px;border-radius:999px;border:6px solid #e5e7eb;border-top-color:#4f46e5;animation:oh979spin 1s linear infinite}
.oh979-loading-text{font-size:17px;line-height:1.45;font-weight:900;color:#111827}
@keyframes oh979spin{to{transform:rotate(360deg)}}
CSS
      );
      break;

    case 'sogrand-taskforce':
      css_out(<<<'CSS'
:root{--blue:#23449a;--blue-2:#2f86db;--blue-3:#1f3374;--bg:#eaf2fb;--card:#ffffff;--line:#d7e6f7;--text:#163454;--muted:#5c7690;--chip:#eaf3ff;--chip-b:#cfe0f5;--soft:#f8fcff;--shadow:0 18px 38px rgba(35,68,154,.10);--danger:#d44d4d;--success:#22a45a}
*{box-sizing:border-box}
html,body{margin:0;padding:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:var(--bg);color:var(--text);width:100%;max-width:100%;overflow-x:hidden}
body{min-height:100vh}
.sg-header{background:var(--blue);color:#fff;padding:26px 32px;box-shadow:0 2px 0 rgba(255,255,255,.10) inset}
.sg-header-row{width:100%;max-width:1600px;margin:0 auto;display:flex;align-items:flex-start;justify-content:space-between;gap:16px}
.sg-title{font-weight:900;font-size:22px;line-height:1.1;margin:0}
.sg-sub{margin-top:4px;font-size:14px;font-weight:700;opacity:.95}
.sg-right{font-size:22px;font-weight:900;color:#fff;opacity:1;padding-top:0;white-space:nowrap;letter-spacing:.04em}
.sg-wrap{width:min(1600px,100%);margin:0 auto;padding:22px 18px 30px}
.sg-card{background:rgba(255,255,255,.98);border-radius:22px;box-shadow:var(--shadow);padding:26px 24px 24px;border:1px solid rgba(34,63,150,.10)}
.sg-toolbar{display:grid;grid-template-columns:minmax(180px,220px) minmax(180px,220px) 1fr;gap:14px;align-items:end}
.sg-field{min-width:0}
.sg-field label{display:block;font-size:14px;font-weight:900;margin-bottom:8px;color:var(--blue-3)}
.sg-input,.sg-date,.sg-search{width:100%;height:48px;border:2px solid #e4ebf5;border-radius:12px;background:#fff;padding:0 14px;font-size:15px;font-weight:800;color:#44556d;outline:none}
.sg-input[readonly]{background:#fff;cursor:not-allowed}
.sg-actions{display:flex;gap:10px;align-items:center;justify-content:flex-start;flex-wrap:wrap}
.sg-countdown{margin-top:14px;border:1px solid #bfdbfe;background:#eff6ff;color:#1e3a8a;border-radius:5px;padding:12px 14px;font-size:16px;font-weight:900}
.sg-countdown b{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:#dc2626}
.sg-btn{height:48px;min-width:150px;border:0;border-radius:5px;padding:0 20px;font-size:15px;font-weight:900;cursor:pointer;box-shadow:0 8px 18px rgba(47,98,218,.16);display:inline-flex;align-items:center;justify-content:center;text-align:center;white-space:nowrap}
.sg-actions .sg-btn,.sg-filter-top .sg-btn,.sg-desktop-btn{height:48px!important;min-width:150px!important;border-radius:5px!important;padding:0 20px!important}
.sg-btn-primary{background:linear-gradient(180deg,#3c72ea,#2f62da);color:#fff}
#sgBtnBack{display:none!important}
.sg-btn-muted{background:#dc2626;color:#fff;box-shadow:0 8px 18px rgba(220,38,38,.16)}
.sg-btn-download{background:#16a34a;color:#fff;box-shadow:0 8px 18px rgba(22,163,74,.16)}
.sg-btn-oh{background:#2563eb;color:#fff;box-shadow:0 8px 18px rgba(37, 99, 235,.16)}
.sg-divider{height:2px;background:linear-gradient(90deg,#dde5f0,#97a7c1,#dde5f0);margin:18px 0 14px;border-radius:999px}
.sg-filter-top{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px}
.sg-filter-title{font-size:15px;font-weight:900;color:var(--blue-3)}.sg-select-all{display:inline-flex;align-items:center;gap:10px;min-height:48px;padding:0 15px;border-radius:12px;background:#eaf3ff;border:1px solid #cfe0f5;color:#23449a;font-weight:900;cursor:pointer}.sg-select-all input{width:22px;height:22px;accent-color:#2563eb;cursor:pointer}
.sg-racks{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
.sg-rack{display:flex;align-items:center;gap:10px;background:var(--chip);border:1px solid var(--chip-b);border-radius:12px;min-height:50px;padding:0 14px;cursor:pointer}
.sg-rack input{width:22px;height:22px;accent-color:var(--blue-2);cursor:pointer;flex:0 0 auto}
.sg-rack span{margin-left:auto;font-weight:900;color:#345292;font-size:14px}
.sg-summary{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin:24px 0 16px}
.sg-sum{position:relative;background:linear-gradient(180deg,#f7fcff,#eef8ff);border-radius:18px;padding:18px 20px;min-height:96px;border:1px solid #d4ebfc}
.sg-sum:before{content:"";position:absolute;left:0;top:10px;bottom:10px;width:5px;border-radius:8px;background:linear-gradient(180deg,#7cc8ff,#2f86db)}
.sg-sum-label{margin-left:10px;color:#2a74b8;font-weight:800;font-size:14px}
.sg-sum-value{margin-left:10px;margin-top:6px;font-size:22px;font-weight:900;color:#12406b;letter-spacing:-.02em}
.sg-sum-value.money-pos{color:var(--success)}
.sg-sum-value.money-neg{color:var(--danger)}
.sg-table-top{display:flex;justify-content:flex-end;margin:8px 0}
.sg-search-wrap{display:flex;align-items:center;gap:8px;font-size:14px;font-weight:800;color:var(--blue-3)}
.sg-table-wrap{overflow-x:visible;overflow-y:auto;-webkit-overflow-scrolling:touch;border-radius:5px;border:1px solid #e4ebf5;background:#fff}
table{width:100%;border-collapse:separate;border-spacing:0;table-layout:auto;min-width:0}
thead th{position:sticky;top:0;background:#fff;color:var(--blue-3);font-size:14px;text-align:left;padding:14px 12px;border-bottom:2px solid #e8eef8;white-space:nowrap;z-index:3}
tbody td{padding:12px;border-bottom:1px solid #ecf1f7;font-size:14px;color:#44556d;vertical-align:top;word-break:break-word;background:#fff}
tbody td.sg-cell-strong{color:#111;font-weight:900}
tbody tr:nth-child(even) td{background:#fbfdff}
tbody tr:hover td{background:#f4f8ff}
.num{text-align:right;font-variant-numeric:tabular-nums}
.rack-pill{display:inline-flex;align-items:center;justify-content:center;min-width:56px;padding:4px 10px;background:#eef5ff;border:1px solid #d5e3fa;color:#36518e;border-radius:999px;font-weight:900}
.money-pos{color:var(--success);font-weight:900}
.money-neg{color:var(--danger);font-weight:900}
.sg-empty,.sg-error{padding:22px;border-radius:5px;text-align:center;font-weight:800}
.sg-empty{background:#f8fbff;color:#61728d;border:1px dashed #cfd9ea}
.sg-error{background:#fff5f5;color:#b42318;border:1px solid #f2c8c8}

/* FIX alignment kolom Cetak Selisih: Fisik / OH / Selisih / Selisih Rupiah rata per baris */
#sgTableWrap table.sg-main-table{width:100%!important;table-layout:fixed!important;border-collapse:separate!important;border-spacing:0!important}
#sgTableWrap .sg-col-plu{width:9%}#sgTableWrap .sg-col-name{width:31%}#sgTableWrap .sg-col-rack{width:9%}#sgTableWrap .sg-col-fisik{width:12%}#sgTableWrap .sg-col-oh{width:9%}#sgTableWrap .sg-col-selisih{width:10%}#sgTableWrap .sg-col-rupiah{width:20%}
#sgTableWrap th,#sgTableWrap td{box-sizing:border-box!important;vertical-align:middle!important;line-height:1.2!important}
#sgTableWrap .sg-th-fisik,#sgTableWrap .sg-th-oh,#sgTableWrap .sg-th-selisih,#sgTableWrap .sg-th-rupiah,#sgTableWrap .sg-td-fisik,#sgTableWrap .sg-td-oh,#sgTableWrap .sg-td-selisih,#sgTableWrap .sg-td-rupiah{text-align:right!important;font-variant-numeric:tabular-nums!important;white-space:nowrap!important}
#sgTableWrap .sg-th-rack,#sgTableWrap .sg-td-rack{text-align:center!important}
#sgTableWrap .sg-th-plu,#sgTableWrap .sg-td-plu{text-align:left!important;white-space:nowrap!important}
#sgTableWrap .sg-th-name,#sgTableWrap .sg-td-name{text-align:left!important}
#sgTableWrap [hidden]{display:none!important}
@media(max-width:720px){#sgTableWrap .sg-col-plu{width:10%}#sgTableWrap .sg-col-name{width:28%}#sgTableWrap .sg-col-rack{width:10%}#sgTableWrap .sg-col-fisik{width:12%}#sgTableWrap .sg-col-oh{width:9%}#sgTableWrap .sg-col-selisih{width:10%}#sgTableWrap .sg-col-rupiah{width:21%}#sgTableWrap .sg-th-fisik,#sgTableWrap .sg-th-oh,#sgTableWrap .sg-th-selisih,#sgTableWrap .sg-th-rupiah,#sgTableWrap .sg-td-fisik,#sgTableWrap .sg-td-oh,#sgTableWrap .sg-td-selisih,#sgTableWrap .sg-td-rupiah{white-space:normal!important}}


.sg-export-modal{position:fixed;inset:0;z-index:10000;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(8,20,49,.56);backdrop-filter:blur(4px)}
.sg-export-modal[hidden]{display:none!important}
.sg-export-card{width:min(94vw,430px);background:#fff;border-radius:24px;padding:26px 22px;text-align:center;box-shadow:0 28px 72px rgba(12,30,78,.30);border:1px solid rgba(47,98,218,.14);animation:sgPop .18s ease-out}
.sg-export-icon{width:64px;height:64px;margin:0 auto 12px;border-radius:20px;display:grid;place-items:center;background:#eff6ff;color:#1d4ed8;font-size:30px}
.sg-export-card h3{margin:0 0 8px;color:#1f3374;font-size:22px;font-weight:1000}
.sg-export-card p{margin:0;color:#5c7690;font-weight:750;line-height:1.55}
.sg-export-actions{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:18px}
.sg-export-close{margin-top:12px;width:100%;height:44px;border:0;border-radius:5px;background:#e5e7eb;color:#111827;font-weight:1000;cursor:pointer}
.sg-loading-inline{display:inline-flex;align-items:center;gap:10px}
.sg-loading-inline:before{content:"";width:18px;height:18px;border:3px solid rgba(255,255,255,.55);border-top-color:#fff;border-radius:999px;animation:sgSpin .75s linear infinite}
@keyframes sgSpin{to{transform:rotate(360deg)}}@keyframes sgPop{from{transform:translateY(10px) scale(.97);opacity:.6}to{transform:none;opacity:1}}
@media(max-width:520px){.sg-export-actions{grid-template-columns:1fr}}
.sg-footer{background:var(--blue);color:#fff;text-align:center;padding:16px 20px;font-size:12px;font-weight:800;margin-top:26px}
.sg-desktop-modal{position:fixed;inset:0;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(8,20,49,.58);backdrop-filter:blur(3px);z-index:9999}
.sg-desktop-modal[hidden]{display:none}
.sg-desktop-card{width:min(92vw,420px);background:#fff;border-radius:24px;padding:24px 22px 22px;box-shadow:0 24px 60px rgba(12,30,78,.28);border:1px solid rgba(47,98,218,.12);text-align:center}
.sg-desktop-icon{width:68px;height:68px;margin:0 auto 14px;border-radius:5px;display:flex;align-items:center;justify-content:center;background:linear-gradient(180deg,#edf5ff,#dceafe);color:var(--blue);font-size:32px;box-shadow:inset 0 1px 0 rgba(255,255,255,.8)}
.sg-desktop-title{margin:0 0 8px;font-size:22px;line-height:1.2;color:var(--blue-3);font-weight:900}
.sg-desktop-text{margin:0;color:var(--muted);font-size:15px;line-height:1.6;font-weight:700}
.sg-desktop-actions{margin-top:18px}
.sg-desktop-btn{width:100%;height:50px;border:0;border-radius:5px;background:linear-gradient(180deg,#3c72ea,#2f62da);color:#fff;font-size:16px;font-weight:900;cursor:pointer;box-shadow:0 12px 26px rgba(47,98,218,.24)}
#sgTableWrap thead th:first-child{left:0;z-index:5;box-shadow:1px 0 0 #d9effd}
#sgTableWrap tbody td:first-child{position:sticky;left:0;z-index:2;background:inherit;box-shadow:1px 0 0 #e5f2fd}
#sgTableWrap th:nth-child(1),#sgTableWrap td:nth-child(1){width:10%}
#sgTableWrap th:nth-child(2),#sgTableWrap td:nth-child(2){width:42%}
#sgTableWrap th:nth-child(3),#sgTableWrap td:nth-child(3){width:12%}
#sgTableWrap th:nth-child(4),#sgTableWrap td:nth-child(4){width:14%}
#sgTableWrap th:nth-child(5),#sgTableWrap td:nth-child(5){width:22%}
@media (max-width:1024px){.sg-header{padding:22px 28px}.sg-header-row{max-width:none}.sg-wrap{width:100%;max-width:none;padding:22px 18px 30px}.sg-card{padding:24px 22px}.sg-toolbar{grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:14px}.sg-actions{grid-column:1/-1}.sg-racks{grid-template-columns:repeat(2,minmax(0,1fr))}.sg-right{display:none}.sg-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.sg-table-wrap{overflow:visible}table{width:100%;min-width:0;table-layout:fixed}#sgTableWrap th,#sgTableWrap td{white-space:normal;word-break:break-word;overflow-wrap:anywhere}#sgTableWrap th:nth-child(1),#sgTableWrap td:nth-child(1){width:9%}#sgTableWrap th:nth-child(2),#sgTableWrap td:nth-child(2){width:32%}#sgTableWrap th:nth-child(3),#sgTableWrap td:nth-child(3){width:9%}#sgTableWrap th:nth-child(4),#sgTableWrap td:nth-child(4){width:11%}#sgTableWrap th:nth-child(5),#sgTableWrap td:nth-child(5){width:8%}#sgTableWrap th:nth-child(6),#sgTableWrap td:nth-child(6){width:9%}#sgTableWrap th:nth-child(7),#sgTableWrap td:nth-child(7){width:22%}}
@media (max-width:720px){html,body{min-width:0;overflow-x:hidden}.sg-header{padding:3.4vw 3.3vw}.sg-title{font-size:clamp(16px,5.2vw,22px)}.sg-sub{font-size:clamp(11px,3.3vw,14px)}.sg-wrap{width:100%;max-width:none;padding:2.4vw 1.8vw 4vw}.sg-card{border-radius:clamp(14px,4.8vw,22px);padding:3.2vw 2.5vw;box-shadow:0 12px 28px rgba(35,68,154,.12)}.sg-toolbar{grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:2vw;align-items:end}.sg-field label{font-size:clamp(11px,3.1vw,14px);margin-bottom:1.2vw}.sg-input,.sg-date,.sg-search{height:clamp(34px,8.8vw,48px);border-radius:clamp(8px,2.8vw,12px);padding:0 2.6vw;font-size:clamp(11px,3.3vw,15px);border-width:1.5px}.sg-actions{grid-column:1/-1;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1.6vw;flex-wrap:nowrap}.sg-btn,.sg-actions .sg-btn,.sg-filter-top .sg-btn,.sg-desktop-btn{width:100%!important;min-width:0!important;height:clamp(34px,8.8vw,48px)!important;border-radius:clamp(5px,1.4vw,8px)!important;padding:0 1.6vw!important;font-size:clamp(10px,3.1vw,15px)!important;line-height:1.15}.sg-divider{margin:2.8vw 0 2.4vw}.sg-filter-top{display:grid;grid-template-columns:1fr 1fr;align-items:center;gap:2vw;margin-bottom:2vw}.sg-filter-title{font-size:clamp(11px,3.3vw,15px)}.sg-filter-top>div:last-child{display:grid!important;grid-template-columns:1fr 1fr;width:100%;gap:1.6vw;grid-column:1/-1}.sg-racks{grid-template-columns:repeat(2,minmax(0,1fr));gap:2vw}.sg-rack{min-height:clamp(38px,10vw,50px);border-radius:clamp(9px,3vw,12px);padding:0 3vw}.sg-rack input{width:clamp(16px,5vw,22px);height:clamp(16px,5vw,22px)}.sg-rack span{font-size:clamp(11px,3.2vw,14px)}.sg-summary{grid-template-columns:repeat(2,minmax(0,1fr));gap:2vw;margin:4vw 0 2.5vw}.sg-sum{border-radius:clamp(12px,4vw,18px);padding:3vw 3.2vw;min-height:clamp(72px,18vw,96px)}.sg-sum-label{font-size:clamp(10px,3.1vw,14px);margin-left:1.8vw}.sg-sum-value{font-size:clamp(16px,5vw,22px);margin-left:1.8vw}.sg-table-top{justify-content:flex-end;margin:2vw 0}.sg-search-wrap{gap:1.5vw;font-size:clamp(10px,3vw,14px)}.sg-search{width:clamp(112px,36vw,180px)!important;flex:0 0 auto}.sg-table-wrap{overflow:visible;border-radius:clamp(10px,3.6vw,16px)}table{min-width:0;width:100%;table-layout:fixed}thead th{font-size:clamp(9px,2.7vw,14px);padding:clamp(6px,1.8vw,14px) clamp(3px,1.4vw,12px)}tbody td{font-size:clamp(9px,2.7vw,14px);padding:clamp(6px,1.8vw,12px) clamp(3px,1.4vw,12px);line-height:1.25}.rack-pill{min-width:0;padding:.6vw 1.8vw;font-size:inherit}#sgTableWrap th:nth-child(1),#sgTableWrap td:nth-child(1){width:9%}#sgTableWrap th:nth-child(2),#sgTableWrap td:nth-child(2){width:32%}#sgTableWrap th:nth-child(3),#sgTableWrap td:nth-child(3){width:9%}#sgTableWrap th:nth-child(4),#sgTableWrap td:nth-child(4){width:11%}#sgTableWrap th:nth-child(5),#sgTableWrap td:nth-child(5){width:8%}#sgTableWrap th:nth-child(6),#sgTableWrap td:nth-child(6){width:9%}#sgTableWrap th:nth-child(7),#sgTableWrap td:nth-child(7){width:22%}.sg-footer{font-size:clamp(10px,2.8vw,12px);padding:3vw 2vw;margin-top:4vw}}
/* FORCE SO GRAND DESKTOP-ZOOM LOOK ON ALL DEVICES */
html{overflow-x:hidden!important;background:#eaf4ff!important}
body{overflow-x:hidden!important;min-width:1180px!important;width:1180px!important;margin:0 auto!important;background:linear-gradient(180deg,#eef7ff 0%,#e9f4ff 100%)!important;transform-origin:top center!important}
.sg-header{padding:28px 38px!important}
.sg-header-row{width:100%!important;max-width:1120px!important;margin:0 auto!important}
.sg-title{font-size:25px!important}.sg-sub{font-size:16px!important}.sg-right{display:none!important}
.sg-wrap{width:100%!important;max-width:1120px!important;margin:0 auto!important;padding:28px 22px 42px!important;box-sizing:border-box!important}
.sg-card{border-radius:24px!important;padding:32px 28px 30px!important;box-sizing:border-box!important}
.sg-toolbar{display:grid!important;grid-template-columns:1fr 1fr!important;gap:16px!important;align-items:end!important}
.sg-actions{grid-column:1/-1!important;display:flex!important;gap:12px!important;flex-wrap:wrap!important;justify-content:flex-start!important}
.sg-field label{font-size:15px!important;margin-bottom:9px!important}.sg-input,.sg-date,.sg-search{height:56px!important;border-radius:5px!important;font-size:16px!important;padding:0 18px!important;box-sizing:border-box!important}
.sg-btn,.sg-actions .sg-btn,.sg-filter-top .sg-btn,.sg-desktop-btn{width:auto!important;min-width:180px!important;height:58px!important;border-radius:6px!important;padding:0 24px!important;font-size:16px!important;line-height:1.1!important}
.sg-divider{margin:22px 0 18px!important}.sg-filter-top{display:flex!important;align-items:center!important;justify-content:space-between!important;gap:14px!important;flex-wrap:wrap!important;margin-bottom:16px!important}.sg-filter-title{font-size:16px!important}.sg-filter-top>div:last-child{display:flex!important;gap:12px!important;width:auto!important;grid-column:auto!important}
.sg-racks{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:16px!important}.sg-rack{min-height:58px!important;border-radius:5px!important;padding:0 20px!important}.sg-rack input{width:24px!important;height:24px!important}.sg-rack span{font-size:15px!important}
.sg-summary{grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:20px!important;margin:28px 0 18px!important}.sg-sum{border-radius:5px!important;padding:20px 24px!important;min-height:98px!important}.sg-sum-label{font-size:15px!important}.sg-sum-value{font-size:25px!important}
.sg-table-top{justify-content:flex-end!important;margin:12px 0!important}.sg-search-wrap{font-size:15px!important}.sg-search{width:220px!important;flex:0 0 auto!important}
.sg-table-wrap{overflow:visible!important;border-radius:18px!important}table{width:100%!important;min-width:0!important;table-layout:fixed!important}thead th{font-size:14px!important;padding:16px 14px!important}tbody td{font-size:15px!important;padding:16px 14px!important;line-height:1.25!important;white-space:normal!important;word-break:break-word!important;overflow-wrap:anywhere!important}.rack-pill{min-width:64px!important;padding:6px 14px!important;font-size:14px!important}
#sgTableWrap th:nth-child(1),#sgTableWrap td:nth-child(1){width:9%!important}#sgTableWrap th:nth-child(2),#sgTableWrap td:nth-child(2){width:32%!important}#sgTableWrap th:nth-child(3),#sgTableWrap td:nth-child(3){width:9%!important}#sgTableWrap th:nth-child(4),#sgTableWrap td:nth-child(4){width:11%!important}#sgTableWrap th:nth-child(5),#sgTableWrap td:nth-child(5){width:8%!important}#sgTableWrap th:nth-child(6),#sgTableWrap td:nth-child(6){width:9%!important}#sgTableWrap th:nth-child(7),#sgTableWrap td:nth-child(7){width:22%!important}.sg-footer{font-size:13px!important;padding:18px 20px!important;margin-top:28px!important}
/* RAPATKAN TABEL 7 KOLOM AGAR FULL TERLIHAT */
body{min-width:1040px!important;width:1040px!important}.sg-header-row,.sg-wrap{max-width:1000px!important}.sg-card{padding:22px 18px 22px!important}.sg-table-wrap{overflow-x:hidden!important}thead th{font-size:12px!important;padding:9px 6px!important;line-height:1.15!important;white-space:normal!important}tbody td{font-size:12px!important;padding:8px 6px!important;line-height:1.15!important}.rack-pill{min-width:0!important;padding:4px 7px!important;font-size:12px!important}.sg-search{height:40px!important}.sg-summary{margin:18px 0 10px!important}.sg-sum{min-height:74px!important;padding:13px 16px!important}
@media (max-width:1039px){body{transform:scale(calc(100vw / 1040))!important;margin-bottom:calc((100vw / 1040 - 1) * 100vh)!important}}
@media (min-width:1040px){body{transform:none!important}}

CSS
      );
      break;

    case 'style-3':
      css_out(<<<'CSS'

      :root{--bg1:#4f46e5;--bg2:#2563eb;--panel:#fff;--text:#0f172a;--shadow:0 20px 60px rgba(2,6,23,.22);}
      body{margin:0;font-family:system-ui,Segoe UI,Roboto,Arial;background:linear-gradient(135deg,var(--bg1),var(--bg2));padding:18px;}
      .box{max-width:720px;margin:auto;background:var(--panel);border-radius:18px;box-shadow:var(--shadow);padding:18px}
      a{color:var(--bg1);font-weight:900}
    


/* === RSO FIX FULL TABLE: tabel selisih tidak terpotong di layar kecil === */
html{overflow-x:hidden}
body.rso-page{overflow-x:hidden !important;min-width:320px;transform-origin:top left}
body.rso-page .top{padding:12px 10px}
body.rso-page .wrap{width:100%;max-width:1180px;padding:10px;margin:0 auto}
body.rso-page .table-wrap{overflow:visible !important;width:100%;max-width:100%;border-radius:12px}
body.rso-page table{width:100% !important;max-width:100% !important;min-width:0 !important;table-layout:fixed !important}
body.rso-page .col-plu{width:13% !important}
body.rso-page .col-name{width:34% !important}
body.rso-page .col-rack{width:14% !important}
body.rso-page .col-stock{width:17% !important}
body.rso-page .col-money{width:22% !important}
body.rso-page thead th,body.rso-page tbody td{padding:8px 5px;font-size:13px;line-height:1.18;white-space:normal !important;word-break:break-word;overflow-wrap:anywhere}
body.rso-page .badgeRack{max-width:100%;white-space:normal;padding:5px 7px}
@media(max-width:700px){
  body.rso-page .wrap{padding:8px}
  body.rso-page .top{font-size:14px}
  body.rso-page .sub{font-size:11px}
  body.rso-page .pill{font-size:12px;padding:7px 9px}
  body.rso-page .search input{padding:8px 10px;font-size:12px}
  body.rso-page .chip{font-size:12px;padding:7px 8px;gap:6px}
  body.rso-page .meta2{font-size:11px}
  body.rso-page thead th,body.rso-page tbody td{font-size:11px;padding:7px 4px}
  body.rso-page .col-plu{width:13% !important}
  body.rso-page .col-name{width:35% !important}
  body.rso-page .col-rack{width:13% !important}
  body.rso-page .col-stock{width:16% !important}
  body.rso-page .col-money{width:23% !important}
  body.rso-page .badgeRack{font-size:11px;padding:4px 5px}
}
@media(max-width:420px){
  body.rso-page thead th,body.rso-page tbody td{font-size:10px;padding:6px 3px;letter-spacing:-.15px}
  body.rso-page .col-name{width:34% !important}
  body.rso-page .col-money{width:24% !important}
  body.rso-page .badgeRack{font-size:10px;padding:3px 4px}
}

/* === UI FIX: tombol radius 5px (termasuk tombol pilih file) === */
button, .btn, input[type="button"], input[type="submit"], input[type="reset"]{
  border-radius:5px !important;
}
input[type="file"]::file-selector-button{
  border-radius:5px !important;
}
input[type="file"]::-webkit-file-upload-button{
  border-radius:5px !important;
}


/* === Planogram + OH: header ungu + tabel tidak overflow === */
.table-wrap{overflow-x:auto; max-width:100%;}
.plano-table{width:100%; table-layout:fixed;}
.plano-table th, .plano-table td{word-break:break-word; overflow-wrap:anywhere;}
.plano-table thead th{background:#1d4ed8 !important; color:#fff !important;}



/* ===== Premium gate & admin wide tweaks ===== */
.btn-mini.ghost{ background:rgba(255,255,255,.08); border-color:rgba(255,255,255,.14); color:#fff; }
.btn-mini.ghost:hover{ background:rgba(255,255,255,.12); }

#adminModal .modal-box{ width:100vw; height:100vh; max-height:none; max-width:none; overflow:auto; border-radius:0; padding:18px; }
#storeDetailModal .modal-box,
#expiryModal .modal-box,
#pinModal .modal-box,
#bannerModal .modal-box,
#adminPassModal .modal-box{ width:min(920px,96vw); max-height:90vh; overflow:auto; }

.admin-item{ gap:12px; }
.admin-item > div:first-child{ min-width:84px; }
.admin-item .admin-actions{ display:flex; gap:8px; align-items:center; flex-wrap:wrap; justify-content:flex-end; }

.premium-pop{ display:grid; gap:12px; }
.premium-actions{ display:grid; gap:10px; }
.premium-hero{
  padding:14px 14px;
  border-radius:5px;
  background:linear-gradient(135deg, rgba(255,255,255,.12), rgba(255,255,255,.06));
  border:1px solid rgba(255,255,255,.14);
}
.premium-hero .title{ font-size:18px; font-weight:900; letter-spacing:.2px; margin:0; }
.premium-hero .desc{ margin:6px 0 0; font-size:13px; font-weight:800; color:rgba(255,255,255,.88); line-height:1.35; }

#waFloat{
  position:fixed;
  right:16px;
  bottom:16px;
  z-index:99999;
  width:56px;
  height:56px;
  border-radius:999px;
  border:1px solid rgba(255,255,255,.18);
  background:linear-gradient(135deg, rgba(126,34,206,.95), rgba(88,28,135,.92));
  box-shadow:0 14px 40px rgba(0,0,0,.35);
  display:flex;
  align-items:center;
  justify-content:center;
  cursor:pointer;
}
#waFloat:hover{ transform:translateY(-1px); }
#waFloat svg{ width:28px; height:28px; }
#waFloat .dot{
  position:absolute;
  top:10px;
  right:10px;
  width:10px;
  height:10px;
  border-radius:99px;
  background:#22c55e;
  box-shadow:0 0 0 3px rgba(34,197,94,.18);
}


/* =========================================================
   UI/UX REVAMP 2026 (override) - warna tetap
   Catatan: ini hanya override agar aman untuk fitur existing.
========================================================= */
:root{
  --bg:#0b1220;
  --panel:rgba(255,255,255,.92);
  --panel2:rgba(255,255,255,.86);
  --stroke:rgba(255,255,255,.18);
  --text:#0f172a;
  --muted:#334155;

  /* keep existing palette hooks */
  /* var(--blue), var(--tosca), var(--grad) already defined above in file */
}

/* page background */
body{
  background:
    radial-gradient(1200px 700px at 15% 0%, rgba(109,40,217,.22), transparent 60%),
    radial-gradient(900px 600px at 90% 10%, rgba(20,184,166,.18), transparent 55%),
    linear-gradient(180deg, #0b1220, #0b1220 60%, #090f1c);
}

/* header: modern, cleaner */
.header{
  border-radius:0 0 28px 28px !important;
  padding:18px 16px 14px !important;
  border-bottom:1px solid rgba(255,255,255,.14) !important;
}

/* keep title readable and avoid under buttons */
.header h1,.header p,.expiry-box{
  max-width:calc(100% - 260px) !important;
}

/* actions always top-right, wrap if needed but stay up */
.header-actions{
  position:fixed !important;
  top:12px !important;
  right:12px !important;
  z-index:1200 !important;
  display:flex !important;
  gap:8px !important;
  flex-wrap:wrap !important;
  justify-content:flex-end !important;
  max-width:56vw !important;
}

/* top buttons */
.btn-top{
  border-radius:12px !important;
  padding:10px 12px !important;
  font-size:12px !important;
  letter-spacing:.2px;
  box-shadow:0 12px 26px rgba(0,0,0,.22) !important;
  backdrop-filter: blur(8px);
}
.btn-top.blue,.btn-top.tosca{ background: rgba(255,255,255,0.18) !important; }

/* container spacing below fixed header */
#mainPage .container{
  padding-top:118px !important;
}

/* cards */
.card{
  background: rgba(255,255,255,.92) !important;
  border:1px solid rgba(255,255,255,.32) !important;
  border-radius:18px !important;
  box-shadow:0 18px 45px rgba(17,24,39,.25) !important;
}
.card + .card{ margin-top:14px !important; }

/* main buttons */
.btn, button.btn{
  border-radius:5px !important;
  font-weight:900 !important;
  letter-spacing:.2px;
  box-shadow:0 14px 30px rgba(0,0,0,.16) !important;
  transition: transform .12s ease, filter .12s ease, box-shadow .12s ease;
}
.btn:active{ transform: translateY(1px) scale(.99); filter:saturate(1.05); }

/* options list items */
.opt{
  border-radius:5px !important;
  border:1px solid rgba(15,23,42,.08) !important;
  background: rgba(255,255,255,.86) !important;
  box-shadow: 0 12px 26px rgba(17,24,39,.10);
  padding:14px 14px !important;
}
.opt:hover{ transform: translateY(-1px); }

/* inputs */
input[type="text"],input[type="password"],input[type="date"],input[type="number"],textarea,select{
  border-radius:5px !important;
  border:1px solid rgba(15,23,42,.12) !important;
  background: rgba(255,255,255,.92) !important;
  box-shadow: inset 0 1px 0 rgba(255,255,255,.6);
}
input:focus,textarea:focus,select:focus{
  outline:none !important;
  border-color: rgba(109,40,217,.42) !important;
  box-shadow: 0 0 0 4px rgba(109,40,217,.16) !important;
}

/* modal */
.modal-box{
  border-radius:5px !important;
  border:1px solid rgba(255,255,255,.22) !important;
  background: rgba(255,255,255,.94) !important;
  box-shadow:0 30px 70px rgba(0,0,0,.34) !important;
}
.modal{
  backdrop-filter: blur(10px);
}

/* iframe card polish */
.fullscreen-box{
  border-radius:18px !important;
  overflow:hidden !important;
}
.zoom-controls{
  position:sticky;
  top:0;
  z-index:5;
  backdrop-filter: blur(10px);
  background: rgba(255,255,255,.70) !important;
  border-bottom:1px solid rgba(15,23,42,.08);
}

/* responsive */
@media (max-width:420px){
  .header h1{ font-size:18px !important; letter-spacing:1.2px !important; }
  .header #storeText{ font-size:12px !important; }
  .header h1,.header p,.expiry-box{ max-width:calc(100% - 165px) !important; }
  .header-actions{ max-width:62vw !important; gap:6px !important; }
  .btn-top{ padding:9px 10px !important; font-size:11px !important; border-radius:12px !important; }
  #mainPage .container{ padding-top:112px !important; }
}


/* === FORCE FIX: Upload ZIP button radius 5px === */
#adminModal input[type="file"] {
    border-radius: 5px !important;
}
#adminModal input[type="file"]::file-selector-button {
    border-radius: 5px !important;
}
#adminModal input[type="file"]::-webkit-file-upload-button {
    border-radius: 5px !important;
}


CSS
      );
      break;

    case 'style-4':
      css_out(<<<'CSS'

body{
  font-family: Arial, sans-serif;
  background:#f4f6fb;
  margin:0;
  padding:20px;
}
.box{
  max-width:1000px;
  margin:auto;
  background:#fff;
  padding:20px;
  border-radius:12px;
  box-shadow:0 6px 20px rgba(0,0,0,.1);
}
h2{ margin-top:0; text-align:center; }
.input-group{
  display:flex;
  flex-direction:column;
  gap:10px;
  margin-bottom:15px;
}
input{
  padding:12px;
  font-size:14px;
  border:1px solid #ccc;
  border-radius:6px;
}
button{
  width:100%;
  padding:12px;
  border:none;
  background:#f0627d;
  color:#fff;
  cursor:pointer;
  border-radius:6px;
  font-size:15px;
}
button:hover{opacity:.9}
table{
  width:100%;
  border-collapse:collapse;
  margin-top:10px;
}
th,td{border:1px solid #ddd;padding:8px;font-size:13px;vertical-align:top;font-weight:900;}
th{
  background:#f0627d;
  color:#fff;
  text-align:left;
}
tr:nth-child(even){ background:#f9f9f9; }
.loading{
  text-align:center;
  font-weight:bold;
  padding:15px;
}
.small-note{
  text-align:center;
  font-size:12px;
  opacity:.7;
  margin-top:10px;
}

/* === THEME OVERRIDE: samakan dengan halaman utama === */
:root{
  --bg1:#4f46e5;--bg2:#2563eb;--panel:#ffffff;--text:#0f172a;--muted:#64748b;
  --line:#e5e7eb;--shadow:0 20px 60px rgba(2,6,23,.22);
}
body{background:linear-gradient(135deg,var(--bg1),var(--bg2)) !important; padding:18px !important; }

.box{background:var(--panel) !important; box-shadow:var(--shadow) !important; border-radius:18px !important;}
h2{color:var(--text) !important;}
button{background:var(--bg1) !important; border-radius:10px !important; font-weight:800;}
button:hover{filter:brightness(1.05); opacity:1 !important;}
th{background:rgba(79,70,229,.12) !important;}
.small-note{color:var(--muted); font-size:12px; font-weight:700; margin-top:-6px}
.toplink{display:flex; justify-content:space-between; gap:12px; align-items:center; margin-bottom:10px}
.toplink a{color:#fff; text-decoration:none; font-weight:900}
.toplink a:hover{text-decoration:underline}




/* === RSO FIX FULL TABLE: tabel selisih tidak terpotong di layar kecil === */
html{overflow-x:hidden}
body.rso-page{overflow-x:hidden !important;min-width:320px;transform-origin:top left}
body.rso-page .top{padding:12px 10px}
body.rso-page .wrap{width:100%;max-width:1180px;padding:10px;margin:0 auto}
body.rso-page .table-wrap{overflow:visible !important;width:100%;max-width:100%;border-radius:12px}
body.rso-page table{width:100% !important;max-width:100% !important;min-width:0 !important;table-layout:fixed !important}
body.rso-page .col-plu{width:13% !important}
body.rso-page .col-name{width:34% !important}
body.rso-page .col-rack{width:14% !important}
body.rso-page .col-stock{width:17% !important}
body.rso-page .col-money{width:22% !important}
body.rso-page thead th,body.rso-page tbody td{padding:8px 5px;font-size:13px;line-height:1.18;white-space:normal !important;word-break:break-word;overflow-wrap:anywhere}
body.rso-page .badgeRack{max-width:100%;white-space:normal;padding:5px 7px}
@media(max-width:700px){
  body.rso-page .wrap{padding:8px}
  body.rso-page .top{font-size:14px}
  body.rso-page .sub{font-size:11px}
  body.rso-page .pill{font-size:12px;padding:7px 9px}
  body.rso-page .search input{padding:8px 10px;font-size:12px}
  body.rso-page .chip{font-size:12px;padding:7px 8px;gap:6px}
  body.rso-page .meta2{font-size:11px}
  body.rso-page thead th,body.rso-page tbody td{font-size:11px;padding:7px 4px}
  body.rso-page .col-plu{width:13% !important}
  body.rso-page .col-name{width:35% !important}
  body.rso-page .col-rack{width:13% !important}
  body.rso-page .col-stock{width:16% !important}
  body.rso-page .col-money{width:23% !important}
  body.rso-page .badgeRack{font-size:11px;padding:4px 5px}
}
@media(max-width:420px){
  body.rso-page thead th,body.rso-page tbody td{font-size:10px;padding:6px 3px;letter-spacing:-.15px}
  body.rso-page .col-name{width:34% !important}
  body.rso-page .col-money{width:24% !important}
  body.rso-page .badgeRack{font-size:10px;padding:3px 4px}
}

/* === UI FIX: tombol radius 5px (termasuk tombol pilih file) === */
button, .btn, input[type="button"], input[type="submit"], input[type="reset"]{
  border-radius:5px !important;
}
input[type="file"]::file-selector-button{
  border-radius:5px !important;
}
input[type="file"]::-webkit-file-upload-button{
  border-radius:5px !important;
}


/* === Planogram + OH: header ungu + tabel tidak overflow === */
.table-wrap{overflow-x:auto; max-width:100%;}
.plano-table{width:100%; table-layout:fixed;}
.plano-table th, .plano-table td{word-break:break-word; overflow-wrap:anywhere;}
.plano-table thead th{background:#1d4ed8 !important; color:#fff !important;}


/* === FORCE FIX: Upload ZIP button radius 5px === */
#adminModal input[type="file"] {
    border-radius: 5px !important;
}
#adminModal input[type="file"]::file-selector-button {
    border-radius: 5px !important;
}
#adminModal input[type="file"]::-webkit-file-upload-button {
    border-radius: 5px !important;
}


CSS
      );
      break;

    case 'style-5':
      css_out(<<<'CSS'

      :root{
        --bg1:#4f46e5;--bg2:#2563eb;--panel:#ffffff;--text:#0f172a;--muted:#64748b;
        --line:#e5e7eb;--shadow: 0 20px 60px rgba(2,6,23,.22);--shadow2: 0 10px 25px rgba(2,6,23,.16);
        --good:#16a34a;--warn:#f59e0b;--bad:#ef4444;
      }
      *{box-sizing:border-box}
      html,body{height:100%}
      body{
        margin:0;
        font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial, "Noto Sans", "Liberation Sans", sans-serif;
        color:var(--text);
        background:#ffffff;
        display:flex;align-items:stretch;justify-content:center;padding:10px;
      }
      .wrap{width:100%;max-width:760px;display:flex;flex-direction:column;gap:10px}
      .top{
        background: rgba(255,255,255,.14);
        border: 1px solid rgba(255,255,255,.22);
        border-radius: 20px;
        padding: 14px 16px;
        backdrop-filter: blur(10px);
        box-shadow: var(--shadow2);
        display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;
      }
      .brand{display:flex;align-items:center;gap:12px;color:#fff}
      .logo{
        width:44px;height:44px;border-radius:5px;background: rgba(255,255,255,.18);
        border: 1px solid rgba(255,255,255,.25);display:grid;place-items:center;font-weight:800;letter-spacing:.5px;
      }
      .brand h1{font-size:16px;margin:0;line-height:1.2}
      .brand p{margin:2px 0 0;color:rgba(255,255,255,.85);font-size:12px}
      .actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
      .chip{
        color:#fff;font-size:12px;padding:8px 10px;border-radius:999px;
        background: rgba(255,255,255,.16);border: 1px solid rgba(255,255,255,.22);white-space:nowrap;
      }
      .panel{background: var(--panel);border-radius: 5px;box-shadow: var(--shadow);overflow:hidden}
      .panelHead{
        padding: 14px 16px;border-bottom: 1px solid var(--line);
        display:flex;align-items:flex-start;justify-content:space-between;gap:10px;flex-wrap:wrap;
      }
      .panelHead .title{display:flex;flex-direction:column;gap:4px}
      .panelHead .title h2{margin:0;font-size:15px;letter-spacing:.2px}
      .panelHead .title .sub{color: var(--muted);font-size:12px}
      .badge{
        font-size:12px;padding:7px 10px;border-radius:999px;border:1px solid var(--line);
        background:#f8fafc;color:#0f172a;display:flex;gap:8px;align-items:center;white-space:nowrap;
      }
      .dot{width:8px;height:8px;border-radius:999px;background:var(--good)}
      .dot.warn{background:var(--warn)} .dot.bad{background:var(--bad)}
      .searchRow{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px}
      .searchRow input{
        width:min(380px, 100%);padding:10px 12px;border-radius:5px;border:1px solid var(--line);
        font-weight:800;outline:none;background:#fff;
      }
      .hint{color:var(--muted);font-size:12px;font-weight:800}
      .tableWrap{overflow:visible;padding:0 8px 8px}
      table{width:100%;border-collapse:separate;border-spacing:0;min-width:0;table-layout:fixed;border:1px solid var(--line);border-radius:10px;overflow:hidden;background:#fff;box-shadow:0 8px 22px rgba(15,23,42,.08)}
      thead th:first-child{border-top-left-radius:10px}
      thead th:last-child{border-top-right-radius:10px}
      tbody tr:last-child td:first-child{border-bottom-left-radius:10px}
      tbody tr:last-child td:last-child{border-bottom-right-radius:10px}
      thead th{
        position:sticky; top:0;background:linear-gradient(135deg,#4f46e5,#2563eb);border-bottom:1px solid #c4b5fd;
        font-size:12px;text-transform:uppercase;letter-spacing:.7px;color:#fff;padding:10px 8px;text-align:left;white-space:nowrap;
      }
      tbody td{padding:8px 7px;border-bottom:1px solid var(--line);font-size:13px;color:#0f172a;vertical-align:middle;line-height:1.3;white-space:normal;word-break:break-word}
      tbody tr:nth-child(even) td{background:#f8fafc}
      tbody tr:hover td{background:#eef2ff}
      .pill{display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border-radius:999px;font-weight:900;font-size:12px;border:1px solid var(--line);background:#fff}
      .pill .mini{width:8px;height:8px;border-radius:99px}
      .pill.custom .mini{background:var(--good)} .pill.partial .mini{background:var(--warn)}
      .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace}
.expiry-box{
  display:none;
  font-weight:1000;
  color:#ffffff;
  letter-spacing:.3px;
  text-shadow:0 2px 10px rgba(0,0,0,.25);
  font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;
  font-size:11px;
  line-height:1.15;
}
      .empty{padding: 22px 16px;color: var(--muted);font-size:13px}
      .err{padding: 12px 16px;border-top:1px solid var(--line);background:#fff7ed;color:#9a3412;font-size:13px}
      .foot{margin-top:2px;text-align:center;color:rgba(255,255,255,.9);font-size:12px}
      .foot b{color:#fff}
      .dateBadge{display:inline-flex;align-items:center;min-height:38px;padding:9px 12px;border-radius:5px;background:#f8fafc;border:1px solid var(--line);color:#0f172a;font-size:12px;font-weight:900}
      #rackQ{flex:1;min-width:190px}
      .rackCell b{font-size:14px;white-space:normal;word-break:break-word}
      @media (max-width:520px){
        body{padding:6px}
        .panelHead{padding:10px}
        .searchRow{gap:6px}
        thead th{font-size:10px;padding:8px 5px;letter-spacing:.2px}
        tbody td{font-size:11px;padding:7px 5px;line-height:1.25}
      }
    
/* Saved dropdown+search (fallback untuk Android yang tidak tampilkan datalist) */
.saved-panel{
  position: relative;
  margin-top: 6px;
  max-height: 180px;
  overflow:auto;
  border: 1px solid rgba(255,255,255,.18);
  background: rgba(0,0,0,.35);
  border-radius: 10px;
  padding: 6px;
}
.saved-item{
  padding: 8px 10px;
  border-radius: 8px;
  cursor: pointer;
  user-select:none;
}
.saved-item:hover{ background: rgba(255,255,255,.12); }
.saved-empty{
  padding: 8px 10px;
  opacity:.8;
  font-size: 12px;
}




/* === RSO FIX FULL TABLE: tabel selisih tidak terpotong di layar kecil === */
html{overflow-x:hidden}
body.rso-page{overflow-x:hidden !important;min-width:320px;transform-origin:top left}
body.rso-page .top{padding:12px 10px}
body.rso-page .wrap{width:100%;max-width:1180px;padding:10px;margin:0 auto}
body.rso-page .table-wrap{overflow:visible !important;width:100%;max-width:100%;border-radius:12px}
body.rso-page table{width:100% !important;max-width:100% !important;min-width:0 !important;table-layout:fixed !important}
body.rso-page .col-plu{width:13% !important}
body.rso-page .col-name{width:34% !important}
body.rso-page .col-rack{width:14% !important}
body.rso-page .col-stock{width:17% !important}
body.rso-page .col-money{width:22% !important}
body.rso-page thead th,body.rso-page tbody td{padding:8px 5px;font-size:13px;line-height:1.18;white-space:normal !important;word-break:break-word;overflow-wrap:anywhere}
body.rso-page .badgeRack{max-width:100%;white-space:normal;padding:5px 7px}
@media(max-width:700px){
  body.rso-page .wrap{padding:8px}
  body.rso-page .top{font-size:14px}
  body.rso-page .sub{font-size:11px}
  body.rso-page .pill{font-size:12px;padding:7px 9px}
  body.rso-page .search input{padding:8px 10px;font-size:12px}
  body.rso-page .chip{font-size:12px;padding:7px 8px;gap:6px}
  body.rso-page .meta2{font-size:11px}
  body.rso-page thead th,body.rso-page tbody td{font-size:11px;padding:7px 4px}
  body.rso-page .col-plu{width:13% !important}
  body.rso-page .col-name{width:35% !important}
  body.rso-page .col-rack{width:13% !important}
  body.rso-page .col-stock{width:16% !important}
  body.rso-page .col-money{width:23% !important}
  body.rso-page .badgeRack{font-size:11px;padding:4px 5px}
}
@media(max-width:420px){
  body.rso-page thead th,body.rso-page tbody td{font-size:10px;padding:6px 3px;letter-spacing:-.15px}
  body.rso-page .col-name{width:34% !important}
  body.rso-page .col-money{width:24% !important}
  body.rso-page .badgeRack{font-size:10px;padding:3px 4px}
}

/* === UI FIX: tombol radius 5px (termasuk tombol pilih file) === */
button, .btn, input[type="button"], input[type="submit"], input[type="reset"]{
  border-radius:5px !important;
}
input[type="file"]::file-selector-button{
  border-radius:5px !important;
}
input[type="file"]::-webkit-file-upload-button{
  border-radius:5px !important;
}


/* === Planogram + OH: header ungu + tabel tidak overflow === */
.table-wrap{overflow-x:auto; max-width:100%;}
.plano-table{width:100%; table-layout:fixed;}
.plano-table th, .plano-table td{word-break:break-word; overflow-wrap:anywhere;}
.plano-table thead th{background:#1d4ed8 !important; color:#fff !important;}


/* === FORCE FIX: Upload ZIP button radius 5px === */
#adminModal input[type="file"] {
    border-radius: 5px !important;
}
#adminModal input[type="file"]::file-selector-button {
    border-radius: 5px !important;
}
#adminModal input[type="file"]::-webkit-file-upload-button {
    border-radius: 5px !important;
}


CSS
      );
      break;

    case 'style-6':
      css_out(<<<'CSS'

      :root{--grad:linear-gradient(135deg,#4f46e5,#2563eb);--bg:#f4f6fb;--text:#0f1222;--border:#111827;--grid:#e5e7eb}
      *{box-sizing:border-box}
      body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;background:var(--bg);color:var(--text)}
      .top{background:var(--grad);color:#fff;padding:14px 12px;font-weight:1000}
      .sub{opacity:.92;font-weight:900;font-size:12px;margin-top:4px}
      .wrap{max-width:1180px;margin:0 auto;padding:12px}
      .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between;margin:10px 0}
      .pill{padding:8px 12px;border-radius:5px;background:#fff;border:1px solid #e5e7eb;font-weight:1000}
      .search{width:min(520px,100%)}
      .search input{width:100%;padding:10px 12px;border-radius:12px;border:1px solid #e5e7eb;font-weight:900;outline:none}
      .info{margin-top:10px;padding:10px 12px;border:1px solid #fee2e2;background:#fff1f2;color:#9f1239;border-radius:12px;font-weight:1000}

      .rackbar{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0}
      .chip{
        border:1px solid #e5e7eb;background:#fff;border-radius:5px;
        padding:8px 10px;font-weight:1000;cursor:pointer;
        display:flex;gap:8px;align-items:center;
      }
      .chip b{font-weight:1100}
      .amt{font-weight:1100}
      .amt.neg{color:#dc2626}
      .amt.pos{color:#16a34a}
      .chip .amt{opacity:.9}
      .chip.active{outline:3px solid rgba(37, 99, 235,.22);border-color:#a78bfa}
      .chip.all{background:linear-gradient(135deg,#ffffff,#f3f4f6)}
      .chip .dot{width:10px;height:10px;border-radius:99px;background:linear-gradient(135deg,#4f46e5,#2563eb)}
      .meta2{font-size:12px;font-weight:900;color:#6b7280;margin-top:4px}

      .table-wrap{margin-top:10px;border:2px solid var(--border);border-radius:12px;overflow:hidden;background:#fff}
      table{border-collapse:collapse;min-width:0;width:100%;table-layout:fixed}
      thead th{position:sticky;top:0;background:#fff;z-index:2;border-bottom:2px solid var(--border);border-right:2px solid var(--border);padding:10px;text-align:center;font-weight:1000;font-size:13px;white-space:normal;word-break:break-word}
      thead th:last-child{border-right:0}
      tbody td{border-top:1px solid var(--grid);padding:10px;font-weight:900;font-size:13px;white-space:normal;word-break:break-word}
      tbody tr:hover{background:#f8fafc}
      td.num{text-align:right} td.center{text-align:center} td.name{width:auto} td.money{font-weight:1100}
      .badgeRack{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;background:#eef2ff;color:#312e81;font-weight:1000}
    @media (max-width: 520px){
        thead th, tbody td{font-size:12px;padding:8px}
      }
    


/* === RSO FIX FULL TABLE: tabel selisih tidak terpotong di layar kecil === */
html{overflow-x:hidden}
body.rso-page{overflow-x:hidden !important;min-width:320px;transform-origin:top left}
body.rso-page .top{padding:12px 10px}
body.rso-page .wrap{width:100%;max-width:1180px;padding:10px;margin:0 auto}
body.rso-page .table-wrap{overflow:visible !important;width:100%;max-width:100%;border-radius:12px}
body.rso-page table{width:100% !important;max-width:100% !important;min-width:0 !important;table-layout:fixed !important}
body.rso-page .col-plu{width:13% !important}
body.rso-page .col-name{width:34% !important}
body.rso-page .col-rack{width:14% !important}
body.rso-page .col-stock{width:17% !important}
body.rso-page .col-money{width:22% !important}
body.rso-page thead th,body.rso-page tbody td{padding:8px 5px;font-size:13px;line-height:1.18;white-space:normal !important;word-break:break-word;overflow-wrap:anywhere}
body.rso-page .badgeRack{max-width:100%;white-space:normal;padding:5px 7px}
@media(max-width:700px){
  body.rso-page .wrap{padding:8px}
  body.rso-page .top{font-size:14px}
  body.rso-page .sub{font-size:11px}
  body.rso-page .pill{font-size:12px;padding:7px 9px}
  body.rso-page .search input{padding:8px 10px;font-size:12px}
  body.rso-page .chip{font-size:12px;padding:7px 8px;gap:6px}
  body.rso-page .meta2{font-size:11px}
  body.rso-page thead th,body.rso-page tbody td{font-size:11px;padding:7px 4px}
  body.rso-page .col-plu{width:13% !important}
  body.rso-page .col-name{width:35% !important}
  body.rso-page .col-rack{width:13% !important}
  body.rso-page .col-stock{width:16% !important}
  body.rso-page .col-money{width:23% !important}
  body.rso-page .badgeRack{font-size:11px;padding:4px 5px}
}
@media(max-width:420px){
  body.rso-page thead th,body.rso-page tbody td{font-size:10px;padding:6px 3px;letter-spacing:-.15px}
  body.rso-page .col-name{width:34% !important}
  body.rso-page .col-money{width:24% !important}
  body.rso-page .badgeRack{font-size:10px;padding:3px 4px}
}

/* === UI FIX: tombol radius 5px (termasuk tombol pilih file) === */
button, .btn, input[type="button"], input[type="submit"], input[type="reset"]{
  border-radius:5px !important;
}
input[type="file"]::file-selector-button{
  border-radius:5px !important;
}
input[type="file"]::-webkit-file-upload-button{
  border-radius:5px !important;
}


/* === Planogram + OH: header ungu + tabel tidak overflow === */
.table-wrap{overflow-x:auto; max-width:100%;}
.plano-table{width:100%; table-layout:fixed;}
.plano-table th, .plano-table td{word-break:break-word; overflow-wrap:anywhere;}
.plano-table thead th{background:#1d4ed8 !important; color:#fff !important;}


/* === FORCE FIX: Upload ZIP button radius 5px === */
#adminModal input[type="file"] {
    border-radius: 5px !important;
}
#adminModal input[type="file"]::file-selector-button {
    border-radius: 5px !important;
}
#adminModal input[type="file"]::-webkit-file-upload-button {
    border-radius: 5px !important;
}


CSS
      );
      break;

    case 'style-7':
      css_out(<<<'CSS'

:root{--grad:linear-gradient(135deg,#4f46e5,#2563eb);--text:#1f2937;--muted:#6b7280;--success:#16a34a;--danger:#dc2626}
*{box-sizing:border-box;font-family:'Plus Jakarta Sans',system-ui,-apple-system,'Segoe UI',Roboto,Arial}
body{margin:0;min-height:100vh;background:linear-gradient(135deg,#4f46e5,#2563eb);padding:20px;color:var(--text);display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden;touch-action:pan-x pan-y}
body::before,body::after{content:"";position:absolute;inset:auto;pointer-events:none;opacity:.35}
body::before{width:520px;height:520px;border-radius:999px;background:rgba(255,255,255,.22);top:-240px;right:-240px}
body::after{width:520px;height:520px;border-radius:999px;background:rgba(255,255,255,.14);bottom:-260px;left:-260px}
.wrap{max-width:520px;width:100%;margin:0 auto;position:relative;z-index:1}.card{background:rgba(255,255,255,.94);border-radius:28px;padding:24px;box-shadow:0 28px 70px rgba(0,0,0,.28);border:1px solid rgba(255,255,255,.55);backdrop-filter:blur(10px)}
.logo{width:68px;height:68px;border-radius:18px;background:var(--grad);color:#fff;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;font-size:28px;font-weight:900}
h1{margin:0 0 8px;font-size:24px;text-align:center;color:#312e81}.sub{margin:0 0 18px;text-align:center;color:var(--muted);font-size:14px;line-height:1.6}
.field{display:grid;gap:8px;margin-bottom:14px}label{font-size:13px;font-weight:800;color:#4338ca}
input{width:100%;padding:14px;border:1px solid #dbeafe;border-radius:5px;font-size:16px;font-weight:800;text-align:center;outline:none}
input:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37, 99, 235,.16)}
.btn{width:100%;border:none;border-radius:5px;padding:14px 16px;cursor:pointer;font-size:15px;font-weight:900;transition:.2s;text-align:center;text-decoration:none;display:flex;align-items:center;justify-content:center}.btn-primary{background:var(--grad);color:#fff}.btn-primary:hover{transform:translateY(-1px)}.btn-secondary{background:#eff6ff;color:#1e3a8a}.btn-login-link{background:var(--grad);color:#fff;box-shadow:0 10px 24px rgba(37, 99, 235,.22)}.btn-login-link:hover{transform:translateY(-1px);filter:brightness(1.03)}.btn-secondary[disabled],.btn-primary[disabled]{opacity:.6;cursor:not-allowed;transform:none}
.action-stack{display:grid;gap:10px;margin-top:2px}
.box{margin-top:18px;padding:16px;border-radius:18px;background:#faf5ff;border:1px solid #dbeafe}.hidden{display:none!important}.note{font-size:12px;color:var(--muted);line-height:1.6}.err{color:var(--danger);font-size:13px;font-weight:800;min-height:18px;margin-top:8px;text-align:center}.ok{color:var(--success)}
.qris-preview{margin-top:14px;text-align:center}.qris-preview img{max-width:100%;width:280px;background:#fff;border-radius:18px;padding:12px;border:1px solid #e9d5ff;box-shadow:0 10px 30px rgba(37, 99, 235,.12)}
.success-inline{margin-top:14px;padding:14px 16px;border-radius:5px;background:#ecfdf5;border:1px solid #86efac;color:#166534;text-align:center}.success-inline h4{margin:0 0 8px;font-size:18px}.success-actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:12px}.status-help{display:flex;gap:10px;flex-wrap:wrap;justify-content:center;margin-top:12px}
.meta{display:grid;gap:8px;margin-top:14px;font-size:13px;color:#4b5563}.meta strong{color:#312e81}.badge{display:inline-flex;align-items:center;justify-content:center;padding:8px 12px;border-radius:999px;background:#eff6ff;color:#1e40af;font-size:12px;font-weight:900}.back{display:flex;align-items:center;justify-content:center;margin-top:14px;padding:14px 16px;border-radius:5px;background:var(--grad);color:#fff;text-decoration:none;font-weight:900;box-shadow:0 10px 24px rgba(37, 99, 235,.22);transition:.2s}.back:hover{transform:translateY(-1px);filter:brightness(1.03)}
.modal,.payment-screen{position:fixed;inset:0;background:rgba(15,23,42,.42);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);display:none;align-items:center;justify-content:center;padding:18px;z-index:99999}.modal.show,.payment-screen.show{display:flex}.modal-box{background:#fff;max-width:360px;width:100%;border-radius:24px;padding:22px;text-align:center;box-shadow:0 25px 70px rgba(0,0,0,.32)}.modal-box h3{margin:0 0 8px;color:#166534}.modal-box p{margin:0 0 16px;color:#475569;line-height:1.6}.payment-modal-box{max-width:420px;text-align:left}.payment-modal-box h3{color:#312e81;text-align:center}.payment-modal-box .qris-preview{margin-top:0}.payment-modal-box .meta{margin-top:12px}.payment-modal-actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:16px}
.payment-screen{overflow:hidden;z-index:100000;align-items:center;padding:8px}.payment-shell{width:min(92vw,320px);margin:0 auto;padding:0}.payment-card{background:#fff;border-radius:22px;padding:12px 12px 8px;box-shadow:0 20px 50px rgba(15,23,42,.18);max-height:calc(100vh - 16px);overflow:auto;overflow-x:hidden;overscroll-behavior:contain}.qris-box{background:#fff;border-radius:18px;padding:10px;box-shadow:0 6px 18px rgba(15,23,42,.07);border:1px solid #eef2f7;text-align:center;min-height:24px}.qris-box img{width:100%;max-width:190px;display:block;margin:0 auto}.merchant-title{display:none}.timer-inline{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:7px;margin:10px 0 8px}.timer-value{font-size:24px;font-weight:900;color:#16a34a;line-height:1}.timer-bar{height:9px;border-radius:999px;background:#cfeedd;overflow:hidden;width:100%;max-width:190px;box-shadow:inset 0 1px 2px rgba(0,0,0,.06)}.timer-bar>span{display:block;height:100%;width:100%;background:#22c55e;transition:width .9s linear}.amount-card{background:#eef5ff;border:1px solid #cfe0ff;border-radius:5px;padding:12px;margin:10px 0 12px}.amount-row{display:flex;justify-content:space-between;gap:12px;font-size:14px;color:#4b5563;margin-bottom:7px}.amount-row:last-child{margin-bottom:0}.amount-divider{height:1px;background:#bfdbfe;margin:8px 0}.amount-total{font-size:16px;font-weight:900;color:#0f172a}.amount-total strong{font-size:19px;color:#2563eb}.discount-row{color:#2563eb;font-weight:800}.promo-box{background:#f7f0ff;border:1px solid #dbeafe;border-radius:5px;padding:12px;margin-top:8px}.promo-title{font-size:13px;font-weight:900;color:#1e40af;margin-bottom:10px;text-align:center}.promo-form{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;align-items:center}.promo-form input{min-width:0;text-align:left;padding:11px 12px;border-radius:5px}.promo-status{display:none;margin-top:8px;font-size:12px;font-weight:800;text-align:center;color:#6b7280}.promo-status.show{display:block}.promo-status.ok{color:#16a34a}.promo-status.used{color:#dc2626}.promo-msg{display:none;margin-top:6px;font-size:12px;font-weight:700;text-align:center;color:#6b7280}.promo-msg.show{display:block}.promo-msg.ok{color:#16a34a}.promo-chip{display:none;margin-top:8px;text-align:center;font-size:12px;font-weight:800;color:#1d4ed8}.pay-note{font-size:12px;color:#6b7280;text-align:center;margin:10px 0 12px}.big-btn{width:100%;display:flex;align-items:center;justify-content:center;gap:10px;border:none;border-radius:5px;padding:15px 16px;font-size:15px;font-weight:900;cursor:pointer;transition:.2s;margin-bottom:10px}.big-btn.teal{background:#0f9f97;color:#fff}.big-btn.soft{background:#eef3fb;color:#2563eb;border:1px solid #d7e3f7}.big-btn.blue{background:#2563eb;color:#fff}.big-btn.green{background:#10b981;color:#fff}.big-btn:disabled{opacity:.65;cursor:not-allowed}.success-check{width:140px;height:140px;border-radius:999px;border:6px solid #dcfce7;color:#86efac;display:flex;align-items:center;justify-content:center;font-size:72px;margin:8px auto 22px}.success-title{font-size:34px;font-weight:900;color:#4b5563;margin:0 0 12px}.success-sub{font-size:16px;color:#374151;margin:0 0 18px}.success-card{background:#edf9f1;border:1px solid #c7efd0;border-radius:18px;padding:18px;text-align:left;margin-bottom:20px}.success-card .amount-row{font-size:18px}.success-card .amount-total strong{font-size:26px;color:#16a34a}.ok-btn{width:120px;margin:0 auto;border:none;border-radius:5px;background:#10b981;color:#fff;padding:16px 18px;font-size:20px;font-weight:900;cursor:pointer;display:block}
@media (max-width:520px){body{padding:14px}.card{padding:20px;border-radius:24px}.payment-modal-box{padding:18px}.payment-screen{padding:6px}.payment-shell{width:min(92vw,300px)}.payment-card{border-radius:5px;padding:11px 11px 8px;transform:scale(.97);transform-origin:center center}.qris-box img{max-width:176px}.timer-value{font-size:22px}.promo-form{grid-template-columns:1fr}.success-title{font-size:28px}.success-check{width:120px;height:120px;font-size:62px}}
.help-fab{position:fixed;right:18px;bottom:18px;width:58px;height:58px;border:none;border-radius:999px;background:linear-gradient(135deg,#25d366,#128c7e);color:#fff;font-size:28px;font-weight:900;display:flex;align-items:center;justify-content:center;box-shadow:0 16px 34px rgba(18,140,126,.35);cursor:pointer;z-index:100002}.help-fab:hover{transform:translateY(-2px)}.help-fab.hidden{display:none!important}.close-x{position:absolute;top:10px;right:10px;width:38px;height:38px;border:none;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:24px;line-height:1;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 8px 18px rgba(109,40,217,.16)}.close-x:hover{filter:brightness(.98)}.help-modal-box{text-align:left;max-width:420px;padding-top:52px}.help-modal-box h3{color:#128c7e}.help-form{display:grid;gap:12px}.help-form label{font-size:13px;font-weight:800;color:#4338ca}.help-form input,.help-form textarea{width:100%;padding:14px;border:1px solid #dbeafe;border-radius:5px;font-size:15px;font-weight:700;outline:none}.help-form textarea{min-height:108px;resize:vertical}.help-form small{color:#6b7280;font-size:12px}.help-actions{display:flex;align-items:center;justify-content:center;gap:16px;margin-top:10px}.help-actions .btn{flex:1;min-height:44px;border-radius:5px;font-weight:900}.help-actions .btn+ .btn{margin-left:0}@media (max-width:520px){.help-fab{right:14px;bottom:14px;width:54px;height:54px;font-size:26px}.help-actions{gap:12px}}

CSS
      );
      break;

    case 'style-8':
      css_out(<<<'CSS'

@import url('https://fonts.googleapis.com/css2?family=Black+Ops+One&family=Rubik+Wet+Paint&family=Unbounded:wght@600;700;800;900&family=Plus+Jakarta+Sans:wght@400;600;700;800;900&display=swap');
:root{
  --grad:linear-gradient(135deg,#4f46e5,#2563eb);
  --bg:#f4f6fb;
  --text:#1f2937;
  --tosca:linear-gradient(135deg,#06b6d4,#14b8a6);
  --blue:linear-gradient(135deg,#2563eb,#4f46e5);
}
*{box-sizing:border-box;font-family:'Plus Jakarta Sans',system-ui,-apple-system,'Segoe UI',Roboto,Arial; -webkit-tap-highlight-color: transparent; font-weight:800;}
body{margin:0;background:var(--bg);color:var(--text); font-weight:800}
body *{font-weight:800 !important;}

/* LOGIN */
#loginPage{min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--grad);padding:20px;position:relative;overflow:hidden}
#loginPage::before,#loginPage::after{content:"";position:absolute;inset:auto;filter:blur(0px);opacity:.35;pointer-events:none}
#loginPage::before{width:520px;height:520px;border-radius:999px;background:rgba(255,255,255,.22);top:-240px;right:-240px}
#loginPage::after{width:520px;height:520px;border-radius:999px;background:rgba(255,255,255,.14);bottom:-260px;left:-260px}
.login-card{background:rgba(255,255,255,.94);width:100%;max-width:420px;border-radius:26px;padding:28px 22px;box-shadow:0 28px 70px rgba(0,0,0,.28);text-align:center;animation:fadeUp .5s ease;border:1px solid rgba(255,255,255,.55);backdrop-filter: blur(10px)}
.login-logo{width:64px;height:64px;border-radius:5px;margin:auto;margin-bottom:12px;background:var(--grad);display:flex;align-items:center;justify-content:center;color:#fff;font-size:26px;font-weight:700}
.login-card h3{margin:10px 0 6px;font-size:22px;font-weight:900;color:#312e81;font-family:'Black Ops One','Unbounded','Plus Jakarta Sans',system-ui,-apple-system,'Segoe UI',Roboto,Arial;letter-spacing:1px}
.login-card p{font-size:13px;color:#6b7280;margin-bottom:14px}
.login-fields{display:grid;gap:10px;margin-top:10px}
.login-card input{width:100%;padding:14px;border-radius:5px;border:1px solid #e5e7eb;font-size:15px;text-align:center;letter-spacing:2px;font-weight:900;outline:none;background:#fff}
.login-card input:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37, 99, 235,.18)}
.login-btn{width:100%;margin-top:14px;padding:14px;border:none;border-radius:5px;background:var(--grad);color:#fff;font-size:15px;font-weight:800;cursor:pointer;transition:.2s}
.login-btn:hover{transform:translateY(-1px);box-shadow:0 12px 25px rgba(79,70,229,.35)}
.login-footer{margin-top:14px;font-size:12px;color:#6b7280}

.expiry-warning-overlay{position:fixed;inset:0;background:rgba(15,23,42,.5);backdrop-filter:blur(2px);display:none;align-items:center;justify-content:center;padding:18px;z-index:100000}
.expiry-warning-overlay.show{display:flex}
.expiry-warning-card{width:min(92vw,420px);background:#fff;border-radius:24px;padding:22px 18px;box-shadow:0 30px 70px rgba(2,6,23,.28);text-align:center}
.expiry-warning-badge{display:inline-flex;align-items:center;justify-content:center;width:58px;height:58px;border-radius:18px;background:#dcfce7;color:#166534;font-size:28px;font-weight:900;margin:0 auto 12px}
.expiry-warning-title{font-size:20px;font-weight:900;color:#111827;margin:0 0 8px}
.expiry-warning-text{font-size:15px;line-height:1.6;color:#111827;font-weight:800;margin:0}
.expiry-warning-actions{display:flex;gap:10px;justify-content:center;margin-top:16px}.expiry-warning-later-btn{border:0;border-radius:12px;background:#2563eb;color:#fff;font-weight:900;padding:11px 18px;cursor:pointer;box-shadow:0 10px 24px rgba(37, 99, 235,.22)}.expiry-warning-later-btn:hover{filter:brightness(.96)}
.error{font-size:12px;color:#ef4444;text-align:center;margin-top:6px}
@keyframes fadeUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:none}}
/* Bantuan/Admin1/Admin2 dirapikan */
.help-modal-box{text-align:center;max-width:380px!important;border-radius:18px!important}
.help-modal-box h3{margin:0 0 8px;color:#312e81;font-weight:1000}
.help-modal-box p{margin:0 0 14px;color:#64748b;font-weight:850}
.help-actions{display:grid!important;grid-template-columns:1fr 1fr;gap:10px;margin-top:12px}
.help-actions .btn{width:100%;height:48px;border-radius:5px!important}
.help-actions .btn:first-child{background:linear-gradient(135deg,#4f46e5,#2563eb)!important}
.help-actions .btn:last-child{background:linear-gradient(135deg,#06b6d4,#0f766e)!important}

/* HEADER */
.header{background:var(--grad);padding:18px 16px 14px;color:#fff;border-radius:0 0 40px 0px;position:fixed;top:0;left:0;right:0;z-index:1000;display:flex;flex-direction:column;align-items:flex-start;gap:2px;backdrop-filter:saturate(140%) blur(10px);box-shadow:0 18px 45px rgba(17,24,39,.22);border-bottom:1px solid rgba(255,255,255,.14)}
.header h1{margin:0;font-size:24px;font-weight:900;letter-spacing:1.6px;font-family:'Black Ops One','Unbounded','Plus Jakarta Sans',system-ui,-apple-system,'Segoe UI',Roboto,Arial;text-shadow:0 10px 30px rgba(0,0,0,.25);line-height:1;text-transform:uppercase}
.header h1,.header p,.expiry-box{max-width:calc(100% - 220px);word-break:break-word}
.header p{margin:4px 0}
.header #storeText{margin-top:4px;font-family:'Plus Jakarta Sans',system-ui,-apple-system,'Segoe UI',Roboto,Arial;font-weight:900;letter-spacing:.8px;font-size:13px;opacity:.98;text-shadow:0 8px 22px rgba(0,0,0,.18)}
.header-actions{position:absolute;right:16px;top:14px;display:flex;gap:8px;align-items:center;flex-wrap:nowrap}
.btn-top{border:none;padding:9px 12px;border-radius:5px;color:#fff;cursor:pointer;font-weight:900;background:var(--tosca);box-shadow:0 10px 22px rgba(0,0,0,.18);transition:.15s}
.btn-top.blue,.btn-top.tosca{background:rgba(255,255,255,0.18)!important}.btn-top:hover{transform:translateY(-1px);filter:saturate(1.05)}

/* LAYOUT */
.container{padding:16px;max-width:480px;margin:auto;padding-top:calc(var(--headerH,120px) + 16px)}
.header-impersonation-alert{max-width:100%;margin:4px 0 10px;padding:0}
.header-impersonation-alert-inner{display:flex;align-items:flex-start;gap:10px;background:linear-gradient(135deg,rgba(79,70,229,.97),rgba(6,182,212,.95));border:1px solid rgba(255,255,255,.24);color:#fff;border-radius:5px;padding:12px 14px;box-shadow:0 14px 30px rgba(79,70,229,.18);font-size:13px;line-height:1.55}
.header-impersonation-alert-badge{display:inline-flex;align-items:center;justify-content:center;min-width:58px;height:28px;padding:0 10px;border-radius:999px;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.24);color:#fff;font-size:11px;letter-spacing:.5px;box-shadow:none}
.card{background:rgba(255,255,255,.92);border-radius:18px;padding:14px;margin-bottom:16px;box-shadow:0 14px 38px rgba(17,24,39,.08);border:1px solid rgba(37, 99, 235,.10)}
.btn{padding:12px 14px;border:none;border-radius:5px;background:var(--grad);color:#fff;font-weight:900;cursor:pointer;transition:.15s;box-shadow:0 12px 26px rgba(79,70,229,.22)}
.btn:hover{transform:translateY(-1px);box-shadow:0 10px 22px rgba(79,70,229,.18)}
.btn.danger{background:var(--tosca)}
.btn-group{display:grid;grid-template-columns:1fr 1fr;gap:10px}

/* MODAL */
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);align-items:center;justify-content:center;z-index:99999}
.modal-box{background:#fff;width:90%;max-width:420px;border-radius:5px;padding:16px;box-shadow:0 20px 50px rgba(0,0,0,.25);position:relative;z-index:100000}
/* ADMIN popup full layar */
#adminModal .modal-box{box-sizing:border-box;flex:0 1 1800px;width:min(1800px,100%);max-width:none;height:100%;max-height:none;overflow-x:hidden;overflow-y:auto;border-radius:16px;padding:18px}
#adminModal{inset:0;width:100dvw;height:100dvh;box-sizing:border-box;align-items:stretch;justify-content:center;padding:4px;overflow:hidden}

/* ADMIN POPUP LAYER FIX 2026-05-14
   Saat halaman admin dipakai sebagai halaman penuh, popup anak tetap di atas layout. */
body.admin-page #adminModal{z-index:1!important;isolation:isolate!important}
body.admin-page #adminModal .modal-box{position:relative!important;z-index:1!important}
body.admin-page .modal:not(#adminModal),body.admin-page .payment-screen{position:fixed!important;inset:0!important;z-index:2147483000!important;align-items:center!important;justify-content:center!important;padding:18px!important}
body.admin-page .modal:not(#adminModal)[style*="display: flex"],body.admin-page .modal:not(#adminModal).show,body.admin-page .payment-screen[style*="display: flex"],body.admin-page .payment-screen.show{display:flex!important}
body.admin-page .modal:not(#adminModal) .modal-box,body.admin-page .payment-screen .modal-box,body.admin-page .payment-screen .payment-shell{position:relative!important;z-index:2147483001!important;max-height:92vh!important;overflow:auto!important}
body.admin-page #adminActionModal{z-index:2147483010!important}
body.admin-page #storeDetailModal,body.admin-page #expiryModal,body.admin-page #pinModal,body.admin-page #bannerModal,body.admin-page #adminPassModal,body.admin-page #admin2Modal,body.admin-page #admin2AddUserModal,body.admin-page #admin979Modal{z-index:2147483020!important}
#admin979SwitchLoading{display:none;align-items:center;gap:8px;margin:10px auto 0;justify-content:center;font-size:12px;font-weight:800;color:#4f46e5}
#admin979SwitchLoading .spin{width:16px;height:16px;border:3px solid #eff6ff;border-top-color:#1d4ed8;border-radius:999px;display:inline-block;animation:admin979Spin .75s linear infinite}
.admin979-type-btn.is-loading{opacity:.75;pointer-events:none}
@keyframes admin979Spin{to{transform:rotate(360deg)}}
body.admin-page .close-x{z-index:2147483021!important}
.close-x{position:absolute;top:10px;right:10px;width:38px;height:38px;border:none;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:24px;line-height:1;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 8px 18px rgba(109,40,217,.16);z-index:3}.close-x:hover{filter:brightness(.98)}
/* === UI FIX (ADMIN): tombol pilih file ZIP radius 5px === */
#adminModal input[type="file"]::file-selector-button{border-radius:5px !important;}
#adminModal input[type="file"]::-webkit-file-upload-button{border-radius:5px !important;}

.opt{padding:10px;margin:6px 0;border-radius:10px;cursor:pointer;background:#f4f6fb;font-weight:400;color:#111827}
.opt:hover{background:#dbeafe}
.small{font-size:12px;color:#6b7280}
label{font-weight:900;color:#111827;font-size:12px;letter-spacing:.2px}
input{width:100%;padding:12px;border-radius:10px;border:1px solid #e5e7eb;margin-top:8px}
.admin-list{display:grid;gap:12px}
.admin-item{display:flex;flex-direction:column;gap:12px;padding:14px;border-radius:5px;background:linear-gradient(180deg,#ffffff,#f8fafc);border:1px solid rgba(37, 99, 235,.12);box-shadow:0 14px 34px rgba(15,23,42,.08)}
.admin-left{display:flex;flex-direction:column;gap:8px}
.admin-code-row{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
.admin-code{font-size:20px;letter-spacing:.6px;color:#312e81;font-weight:900;font-family:"Unbounded","Plus Jakarta Sans",system-ui}
.admin-role-badge{display:inline-flex;align-items:center;justify-content:center;padding:6px 10px;border-radius:999px;font-size:11px;font-weight:1000;letter-spacing:.3px;background:#dbeafe;color:#3730a3;border:1px solid #c7d2fe}.admin-role-badge.admin2{background:#ccfbf1;color:#115e59;border-color:#99f6e4}
.admin-code-meta{display:inline-flex;align-items:center;gap:8px;flex-wrap:wrap;margin-left:auto}
.admin-presence-chip{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;background:#f8fafc;border:1px solid #e2e8f0;color:#334155;font-size:11px}
.admin-presence-dot{width:10px;height:10px;border-radius:999px;display:inline-block;box-shadow:0 0 0 3px rgba(15,23,42,.06)}
.admin-name-row{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
.admin-name{font-size:14px;color:#0f172a;font-weight:900}
.expiry-badge{padding:7px 10px;border-radius:999px;font-size:11px;letter-spacing:.3px}
.expiry-green{background:#dcfce7;color:#166534;border:1px solid #86efac}
.expiry-red{background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5}
.admin-presence-text{margin-top:0;color:#64748b;display:flex;flex-direction:column;gap:4px}
.admin-right{display:block}
.admin-buttons-bottom{display:grid;grid-template-columns:1fr 1fr;gap:10px;width:100%}
.admin-buttons-bottom .btn-mini{width:100%;height:44px;border-radius:5px;box-shadow:0 10px 20px rgba(79,70,229,.14)}
@media (max-width:480px){.admin-code{font-size:18px}.admin-buttons-bottom{grid-template-columns:1fr 1fr}}


/* admin-badge-pin-final-fix-proxy */
#adminCount{display:inline-flex;align-items:center;justify-content:center;min-width:86px;height:30px;border-radius:5px!important;background:#eef2ff;color:#312e81;border:1px solid #c7d2fe;font-weight:1000}
.badge.expiry-badge,#adminActionExpiryBadge.expiry-badge{display:inline-flex!important;align-items:center!important;justify-content:center!important;min-width:92px!important;height:30px!important;padding:0 10px!important;border-radius:5px!important;text-align:center!important;white-space:nowrap!important;line-height:1!important;box-sizing:border-box!important}
.admin-name-row .badge.expiry-badge{margin-left:auto}
.pin-inputs input{-webkit-text-security:disc!important;text-security:disc!important;caret-color:transparent!important}

/* IFRAME */
.placeholder{width:100%;max-height:72vh;object-fit:contain;background:#f8fafc}
.iframe-wrapper{
  width:100%;
  /* tinggi responsif: tidak kepanjangan / tidak kependekan */
  height:clamp(380px, calc(100vh - 320px), 860px);
  overflow:hidden; /* hindari double scroll + menu tidak "lebih" */
  border-radius:5px;
  border:1px solid #eef2ff;
  background:#fff;
  position:relative;
  z-index:1;
}
iframe{
  width:100%;
  height:100%;
  min-height:0;
  max-height:none;
  border:none;
  display:none;
  background:#fff;
}

/* HEADER RESPONSIVE (tombol ADMIN/Bantuan/Logout tetap di atas) */
@media (max-width:480px){
  .header{padding:16px 12px 12px;}
  .header h1{font-size:18px;}
  .header #storeText{font-size:12px;}
  .btn-top{padding:8px 10px;font-size:12px;}
  .header-actions{position:absolute;right:12px;top:12px;gap:6px;flex-wrap:nowrap}
  .header-actions .btn-top{white-space:nowrap}
  .header h1,.header p,.expiry-box{max-width:calc(100% - 190px);}
  .header-impersonation-alert{padding:0;margin:2px 0 10px}
  .header-impersonation-alert-inner{padding:11px 12px;font-size:12px}
}

@media (max-width:480px){
  .iframe-wrapper{height:auto!important;min-height:0!important;max-height:none!important;flex:1 1 auto!important}
}


/* BANNER MODE (placeholder only) */
#frameCard.banner-mode .iframe-wrapper{height:auto;min-height:0;max-height:none;border:none;background:transparent;overflow:visible}
#frameCard.banner-mode iframe{display:none !important}
#frameCard.banner-mode .zoom-controls{display:none !important}
#frameCard.banner-mode .placeholder{display:block !important;border-radius:5px;border:1px solid #eef2ff}

.special-content-wrap{display:none;flex-direction:column;gap:18px;width:100%}
#frameCard.banner-mode .special-content-wrap{display:flex !important}
.special-banner-block,.special-alert-block{width:100%}
.special-banner-block{display:none;padding:0;background:transparent}
.special-alert-block{display:none;padding:0;background:transparent}
#frameCard.banner-mode .special-banner-block.show,
#frameCard.banner-mode .special-alert-block.show{display:block}
.custom-alert-card{display:none!important}
.custom-alert-popup-overlay{position:fixed;inset:0;z-index:99990;background:rgba(15,23,42,.35);backdrop-filter:none;-webkit-backdrop-filter:none;display:none;align-items:center;justify-content:center;padding:18px}
.custom-alert-popup-overlay.show{display:flex}
.custom-alert-popup-card{width:min(430px,100%);background:#fff;border-radius:5px;border:1px solid rgba(191,219,254,.95);box-shadow:0 28px 80px rgba(15,23,42,.28);overflow:hidden;animation:customAlertPop .2s ease-out;position:relative}
.custom-alert-popup-head{padding:18px 18px 12px;background:linear-gradient(135deg,#eff6ff,#fff);display:flex;gap:12px;align-items:flex-start}
.custom-alert-popup-icon{width:42px;height:42px;border-radius:5px;background:linear-gradient(135deg,#1d4ed8,#3b82f6);color:#fff;display:grid;place-items:center;font-size:22px;font-weight:1000;box-shadow:0 10px 22px rgba(37,99,235,.25);flex:0 0 42px}
.custom-alert-popup-title{font-size:19px;line-height:1.25;font-weight:1000;color:#1e3a8a;margin:0;padding-top:2px}
.custom-alert-popup-text{padding:0 18px 14px;color:#475569;font-size:14px;line-height:1.65;white-space:pre-line}
.custom-alert-popup-actions{padding:0 18px 14px;display:flex;gap:10px;justify-content:flex-end;align-items:center}
.custom-alert-popup-button,.custom-alert-popup-close{border:0;border-radius:5px;padding:10px 14px;font-size:13px;font-weight:900;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}
.custom-alert-popup-button{background:linear-gradient(135deg,#1d4ed8,#3b82f6);color:#fff;box-shadow:0 12px 24px rgba(37,99,235,.25)}
.custom-alert-popup-close{background:#eff6ff;color:#1d4ed8}
.custom-alert-popup-x{position:absolute;right:12px;top:10px;width:34px;height:34px;border:0;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:24px;font-weight:1000;line-height:1;cursor:pointer;z-index:2}
.custom-alert-popup-never{display:block;width:100%;border:0;border-top:1px solid #e5e7eb;background:#fff;color:#64748b;font-size:12px;font-weight:900;padding:11px;cursor:pointer;text-align:center}
.custom-alert-popup-never:hover{background:#f8fafc;color:#1d4ed8}
@keyframes customAlertPop{from{opacity:0;transform:translateY(12px) scale(.98)}to{opacity:1;transform:translateY(0) scale(1)}}
@media(max-width:640px){.custom-alert-popup-card{max-width:94vw}.custom-alert-popup-actions{flex-direction:column-reverse}.custom-alert-popup-button,.custom-alert-popup-close{width:100%}}
.banner-alert-admin-layout{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-top:14px;align-items:start}
.banner-admin-section,.alert-admin-section{background:#f8fafc;border:1px solid #e5e7eb;border-radius:5px;padding:14px}
.banner-admin-section h4,.alert-admin-section h4{margin:0 0 6px;font-size:16px;color:#111827}
.banner-admin-section .small,.alert-admin-section .small{line-height:1.55}
.alert-action-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:12px}
.alert-action-row .btn{width:100%;padding:8px 10px;font-size:13px;border-radius:10px}
@media (max-width:640px){.custom-alert-card{padding:16px;gap:12px;border-radius:18px}.custom-alert-icon{width:38px;height:38px;font-size:20px;border-radius:12px}.custom-alert-title{font-size:16px}.custom-alert-text{font-size:13px}.custom-alert-button{align-self:stretch;min-width:0}.banner-alert-admin-layout{grid-template-columns:1fr;gap:12px}.banner-admin-section,.alert-admin-section{padding:12px}.alert-action-row{grid-template-columns:1fr}}

/* ZOOM CONTROLS */
.zoom-controls{display:none;gap:8px;margin-bottom:10px}
.zoom-controls button{flex:1;padding:10px;border:none;border-radius:10px;background:var(--grad);color:#fff;font-weight:900;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px}
.zoom-controls button.tosca{background:var(--tosca)}
.zoom-controls button.blue{background:var(--blue)}
.ico{width:18px;height:18px;stroke:#fff;stroke-width:2.6;fill:none;filter:drop-shadow(0 2px 6px rgba(255,255,255,.35))}

.fullscreen-box:fullscreen{background:#fff;padding:max(6px,env(safe-area-inset-top)) max(6px,env(safe-area-inset-right)) max(6px,env(safe-area-inset-bottom)) max(6px,env(safe-area-inset-left));width:100dvw;height:100dvh;overflow:hidden}
.fullscreen-box:fullscreen .iframe-wrapper{height:auto;max-height:none;min-height:0;flex:1 1 auto;border-radius:10px}
.fullscreen-box:fullscreen iframe{height:100%;max-height:none;min-height:100%}

/* LOADING - simple */
.loading{
  position:absolute;inset:0;display:none;align-items:center;justify-content:center;
  backdrop-filter: blur(2px);
  background: rgba(255,255,255,.72);
  z-index:9999;
}
.loader-card{
  width:min(320px,92%);
  background:#fff;border-radius:18px;
  box-shadow:0 18px 45px rgba(0,0,0,.18);
  padding:14px 14px 12px;
  text-align:center;
}
.loader-title{font-weight:1000;color:#312e81;letter-spacing:.3px}
.loader-sub{margin-top:6px;font-size:12px;color:#6b7280;font-weight:800}
.oh-name-status{display:flex;align-items:center;gap:8px;min-height:20px}
.oh-name-status.is-loading::before{content:"";width:14px;height:14px;border-radius:999px;border:2px solid #dbe3ff;border-top-color:#2563eb;animation:spin .75s linear infinite;flex:0 0 14px}
.simple-spin{
  width:34px;height:34px;border-radius:999px;border:4px solid #e5e7eb;border-top-color:#2563eb;
  margin:14px auto 0;
  animation:spin 1s linear infinite;
}
@keyframes spin{100%{transform:rotate(360deg)}}

/* FILE BUTTON */
.file-btn-wrapper{display:block;position:relative;overflow:hidden;cursor:pointer;margin-top:8px;border-radius:10px;background:var(--grad);color:#fff;padding:10px 18px;font-weight:900;width:100%;box-sizing:border-box;text-align:center}
/* === MATCH: tombol "Pilih File ZIP" samakan dengan tombol .btn di bawahnya === */
#adminModal .file-btn-wrapper{
  border-radius:5px !important;
  box-shadow:0 14px 30px rgba(0,0,0,.16) !important;
}
#adminModal .file-btn-wrapper:hover{transform:translateY(-1px);filter:brightness(1.02);}
.file-btn-wrapper input[type=file]{position:absolute;left:0;top:0;opacity:0;cursor:pointer;width:100%;height:100%}
.upload-zip-btn{display:flex !important;align-items:center;justify-content:center;gap:10px;min-height:46px;border-radius:5px !important;box-shadow:0 12px 24px rgba(79,70,229,.18);letter-spacing:.2px}
.upload-zip-icon{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:999px;background:rgba(255,255,255,.22);font-size:18px;line-height:1}
.upload-zip-text{font-weight:1000}

/* ADMIN */


.admin-list{max-height:420px;overflow:auto;border:1px solid #e5e7eb;border-radius:5px;padding:10px;background:#fafbff}
.admin-item{display:flex;flex-direction:column;align-items:stretch;gap:12px;padding:14px;border-radius:5px;background:#fff;border:1px solid #e9edff;margin-bottom:10px}
.admin-left{min-width:0;display:flex;flex-direction:column;gap:8px}
.admin-code-row{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:nowrap}
.admin-code{font-weight:900;font-size:16px;line-height:1.2;letter-spacing:.2px;color:#312e81}
.admin-code-meta{display:inline-flex;align-items:center;gap:8px;flex-wrap:wrap;margin-left:auto}
.admin-presence-chip{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;background:#f8fafc;border:1px solid #e2e8f0;color:#334155;font-size:11px;white-space:nowrap}
.admin-presence-dot{width:10px;height:10px;border-radius:999px;display:inline-block;box-shadow:0 0 0 3px rgba(15,23,42,.06)}
.admin-name-row{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
.admin-name,.admin-presence-text{font-size:13px;font-weight:800;color:#312e81;line-height:1.35;word-break:break-word}
.admin-presence-text{margin-top:0;display:flex;flex-direction:column;gap:4px}
.admin-right{display:block;width:100%}
.admin-buttons-bottom{display:grid;grid-template-columns:1fr 1fr;gap:10px;width:100%;margin-top:2px}
.admin-buttons-bottom .btn-mini{width:100%;height:44px;border-radius:5px;box-shadow:0 10px 20px rgba(79,70,229,.14)}
.admin-badges,.admin-buttons{display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:flex-end}
.admin-badges .badge{border-radius:5px!important}
.admin-action-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:12px}
.admin-action-code{font-size:18px;font-weight:1000;line-height:1.15}
.admin-action-name{margin-top:4px;font-size:14px;font-weight:800;color:rgba(255,255,255,.88);line-height:1.35}
.admin-action-badges{display:flex;gap:8px;flex-wrap:wrap}
.admin-action-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.admin-action-grid .btn-mini{width:100%;padding:11px 12px}
.admin-action-grid .btn-mini.danger{background:#ef4444}
.badge{font-weight:900;font-size:12px;padding:6px 10px;border-radius:999px;background:#eef2ff;color:#312e81}
.badge.expiry-badge,.badge.pin-badge,.badge.account-badge{border-radius:5px!important;border:1px solid transparent}
.badge.expiry-red{background:#fee2e2;color:#991b1b;border-color:#fecaca}
.badge.expiry-green{background:#dcfce7;color:#166534;border-color:#bbf7d0}
.badge.pin-badge{background:#eef2ff;color:#312e81;border-color:#c7d2fe}
.badge.account-blue{background:#dbeafe;color:#1d4ed8;border-color:#bfdbfe}
.badge.account-green{background:#dcfce7;color:#166534;border-color:#bbf7d0}
.btn-mini{border:none;border-radius:10px;padding:8px 10px;font-weight:900;color:#fff;cursor:pointer;background:var(--tosca)}
.btn-mini.blue{background:var(--blue)}
.pill2{display:inline-flex;align-items:center;gap:8px;padding:8px 10px;border-radius:999px;background:#fff;border:1px solid #e5e7eb;font-weight:1000}
/* Clerek popup lines: blue + radius 6px */
#clerekModal .pill2{border-color:var(--blue);border-radius:6px}
#clerekModal input,#clerekModal select,#clerekModal textarea{border:1px solid var(--blue)!important;border-radius:6px!important}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace}
.expiry-box{
  display:none;
  font-weight:1000;
  color:#ffffff;
  letter-spacing:.3px;
  text-shadow:0 2px 10px rgba(0,0,0,.25);
  font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;
  font-size:11px;
  line-height:1.15;
}

/* fitur pendaftaran toko dihapus */
#helpBtn{border-radius:5px!important}



/* === RSO FIX FULL TABLE: tabel selisih tidak terpotong di layar kecil === */
html{overflow-x:hidden}
body.rso-page{overflow-x:hidden !important;min-width:320px;transform-origin:top left}
body.rso-page .top{padding:12px 10px}
body.rso-page .wrap{width:100%;max-width:1180px;padding:10px;margin:0 auto}
body.rso-page .table-wrap{overflow:visible !important;width:100%;max-width:100%;border-radius:12px}
body.rso-page table{width:100% !important;max-width:100% !important;min-width:0 !important;table-layout:fixed !important}
body.rso-page .col-plu{width:13% !important}
body.rso-page .col-name{width:34% !important}
body.rso-page .col-rack{width:14% !important}
body.rso-page .col-stock{width:17% !important}
body.rso-page .col-money{width:22% !important}
body.rso-page thead th,body.rso-page tbody td{padding:8px 5px;font-size:13px;line-height:1.18;white-space:normal !important;word-break:break-word;overflow-wrap:anywhere}
body.rso-page .badgeRack{max-width:100%;white-space:normal;padding:5px 7px}
@media(max-width:700px){
  body.rso-page .wrap{padding:8px}
  body.rso-page .top{font-size:14px}
  body.rso-page .sub{font-size:11px}
  body.rso-page .pill{font-size:12px;padding:7px 9px}
  body.rso-page .search input{padding:8px 10px;font-size:12px}
  body.rso-page .chip{font-size:12px;padding:7px 8px;gap:6px}
  body.rso-page .meta2{font-size:11px}
  body.rso-page thead th,body.rso-page tbody td{font-size:11px;padding:7px 4px}
  body.rso-page .col-plu{width:13% !important}
  body.rso-page .col-name{width:35% !important}
  body.rso-page .col-rack{width:13% !important}
  body.rso-page .col-stock{width:16% !important}
  body.rso-page .col-money{width:23% !important}
  body.rso-page .badgeRack{font-size:11px;padding:4px 5px}
}
@media(max-width:420px){
  body.rso-page thead th,body.rso-page tbody td{font-size:10px;padding:6px 3px;letter-spacing:-.15px}
  body.rso-page .col-name{width:34% !important}
  body.rso-page .col-money{width:24% !important}
  body.rso-page .badgeRack{font-size:10px;padding:3px 4px}
}

/* === UI FIX: tombol radius 5px (termasuk tombol pilih file) === */
button, .btn, input[type="button"], input[type="submit"], input[type="reset"]{
  border-radius:5px !important;
}
input[type="file"]::file-selector-button{
  border-radius:5px !important;
}
input[type="file"]::-webkit-file-upload-button{
  border-radius:5px !important;
}


/* === Planogram + OH: header ungu + tabel tidak overflow === */
.table-wrap{overflow-x:auto; max-width:100%;}
.plano-table{width:100%; table-layout:fixed;}
.plano-table th, .plano-table td{word-break:break-word; overflow-wrap:anywhere;}
.plano-table thead th{background:#1d4ed8 !important; color:#fff !important;}


/* === FORCE FIX: Upload ZIP button radius 5px === */
#adminModal input[type="file"] {
    border-radius: 5px !important;
}
#adminModal input[type="file"]::file-selector-button {
    border-radius: 5px !important;
}
#adminModal input[type="file"]::-webkit-file-upload-button {
    border-radius: 5px !important;
}




/* === HOMEPAGE BUTTON RESTORE ===
   Mengembalikan ukuran tombol halaman utama agar normal dan nyaman ditekan. */
body:not(.admin-page) .btn{
  padding:12px 14px !important;
  font-size:15px !important;
  line-height:1.35 !important;
  min-height:44px !important;
  border-radius:5px !important;
}
body:not(.admin-page) .btn-top{
  background: rgba(255,255,255,0.18) !important;
  padding:9px 12px !important;
  font-size:13px !important;
  line-height:1.25 !important;
  min-height:38px !important;
  border-radius:5px !important;
}
body:not(.admin-page) .login-btn{
  padding:14px !important;
  font-size:15px !important;
  min-height:46px !important;
}
body:not(.admin-page) .zoom-controls button{
  padding:9px 11px !important;
  font-size:13px !important;
  min-height:38px !important;
}
body:not(.admin-page) .btn-group{gap:10px !important;}
body:not(.admin-page) .opt{padding:10px !important;margin:6px 0 !important;font-size:14px !important;line-height:1.35 !important;}
body:not(.admin-page) .card{padding:14px !important;border-radius:18px !important;margin-bottom:16px !important;}
body:not(.admin-page) .header{padding:18px 16px 14px !important;border-radius:0 0 40px 0 !important;}
body:not(.admin-page) .header h1{font-size:24px !important;line-height:1 !important;}
body:not(.admin-page) .container{max-width:480px !important;padding-left:16px !important;padding-right:16px !important;}
@media(max-width:480px){
  body:not(.admin-page) .btn{padding:12px 12px !important;font-size:14px !important;min-height:44px !important;}
  body:not(.admin-page) .btn-top{padding:8px 10px !important;font-size:12px !important;min-height:36px !important;}
  body:not(.admin-page) .btn-group{grid-template-columns:1fr 1fr !important;gap:10px !important;}
  body:not(.admin-page) .header h1{font-size:21px !important;}
}
CSS
      );
      break;

    case 'style-9':
      css_out(<<<'CSS'
input[type=\"file\"]{border-radius:5px !important;}
/* === FORCE FIX: Upload ZIP button radius 5px === */
#adminModal input[type="file"] {
    border-radius: 5px !important;
}
#adminModal input[type="file"]::file-selector-button {
    border-radius: 5px !important;
}
#adminModal input[type="file"]::-webkit-file-upload-button {
    border-radius: 5px !important;
}


CSS
      );
      break;

  }
  json_out(['ok'=>false,'msg'=>'Unknown css asset'], 404);
}
function serve_js_asset($name){
  switch((string)$name){

    case 'oh979':
      js_out(<<<'JS'

const params = new URLSearchParams(window.location.search);
const SCRIPT_SRC = (document.currentScript && document.currentScript.src) ? document.currentScript.src : window.location.href;
const API_BASE = SCRIPT_SRC.split('?')[0];
const STORE_ID = (params.get('storeId') || ((document.currentScript && document.currentScript.dataset && document.currentScript.dataset.storeReq) || '')).toUpperCase().replace(/[^A-Z0-9]/g, '');
document.getElementById('storeText').textContent = STORE_ID || '-';
let OH979_TOKO_MODE='reguler';
let CUSTOM_RAK_ACTIVE_ID='';
let CUSTOM_RAK_EDIT_ID='';
function apiUrl(q){ return `${API_BASE}${q}`; }
function setLoading(msg){ const el=document.getElementById('loading'); if(el) el.textContent = msg; }
function labelMode979(){ return OH979_TOKO_MODE==='beanspot' ? 'Rack 979 Beanspot' : (OH979_TOKO_MODE==='strokok' ? 'Rack 000' : 'Rack 979 Reguler'); }
function storageKey979(){ return STORE_ID ? `OH979_GLOBAL_${STORE_ID}_${OH979_TOKO_MODE}` : `OH979_GLOBAL_UNKNOWN_${OH979_TOKO_MODE}`; }
function storageMetaKey979(){ return `${storageKey979()}_META`; }
function customRakKey(){ return STORE_ID ? `OH979_CUSTOM_RAK_${STORE_ID}_${OH979_TOKO_MODE}` : `OH979_CUSTOM_RAK_UNKNOWN_${OH979_TOKO_MODE}`; }
function getLoadingOverlay(){ return document.getElementById('oh979LoadingOverlay'); }
function showLoadingOverlay(){ const el = getLoadingOverlay(); if(el){ el.classList.add('show'); el.setAttribute('aria-hidden','false'); } }
function hideLoadingOverlay(){ const el = getLoadingOverlay(); if(el){ el.classList.remove('show'); el.setAttribute('aria-hidden','true'); } }
function getRackWrap(){ return document.getElementById('rackWrap'); }
function getSummaryGrid(){ return document.getElementById('summaryGrid'); }
function esc(str){ return String(str ?? '').replace(/[&<>"']/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[s])); }

function excelEsc(v){ return String(v ?? '').replace(/[&<>"]/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[s])); }
function normalizeOh979Rows(payload){
  const rows=[];
  const push=(rackName, items)=>{ (Array.isArray(items)?items:[]).forEach((item,idx)=>rows.push({rack:rackName||'', no:idx+1, plu:item?.plu ?? '-', nama:item?.nama ?? '-', fisik:item?.fisik ?? '', on_hand:item?.on_hand ?? 0})); };
  const raks=Array.isArray(payload?.raks)?payload.raks:[];
  if(raks.length){ raks.forEach((rakObj,rakIdx)=>push(rakObj?.rak || (rakIdx+1), rakObj?.data)); }
  else { push(payload?.label || labelMode979(), Array.isArray(payload?.data)?payload.data:[]); }
  return rows;
}
function makeOh979DownloadButton(payload){
  const rows=normalizeOh979Rows(payload);
  const baseLabel=(payload?.label || (payload?.raks && payload.raks[0] && payload.raks[0].rak ? ('Rak_'+payload.raks[0].rak) : (CUSTOM_RAK_ACTIVE_ID ? 'Rak' : labelMode979())) || 'OH979');
  const fileLabel=String(baseLabel).replace(/[^a-z0-9_-]+/gi,'_').replace(/^_+|_+$/g,'') || 'OH979';
  window.__OH979_EXPORTS__=window.__OH979_EXPORTS__||{};
  const id='exp_'+Math.random().toString(36).slice(2,10);
  window.__OH979_EXPORTS__[id]={label:fileLabel, rows};
  return `<button type="button" class="btn-download-excel" onclick="downloadOh979Excel('${id}')">Download Excel</button>`;
}
function crc32(str){
  const table=crc32.table||(crc32.table=(()=>{let c,t=[];for(let n=0;n<256;n++){c=n;for(let k=0;k<8;k++)c=(c&1)?(0xEDB88320^(c>>>1)):(c>>>1);t[n]=c>>>0;}return t;})());
  let crc=0^(-1); for(let i=0;i<str.length;i++) crc=(crc>>>8)^table[(crc^str.charCodeAt(i))&0xff]; return (crc^(-1))>>>0;
}
function dosDateTime(d=new Date()){ const time=(d.getHours()<<11)|(d.getMinutes()<<5)|(Math.floor(d.getSeconds()/2)); const date=((d.getFullYear()-1980)<<9)|((d.getMonth()+1)<<5)|d.getDate(); return {time,date}; }
function u16(n){ return String.fromCharCode(n&255,(n>>>8)&255); }
function u32(n){ return String.fromCharCode(n&255,(n>>>8)&255,(n>>>16)&255,(n>>>24)&255); }
function utf8bin(str){ return unescape(encodeURIComponent(str)); }
function zipStore(files){
  let local='', central='', offset=0; const dt=dosDateTime();
  files.forEach(f=>{ const name=utf8bin(f.name), data=utf8bin(f.data); const crc=crc32(data), size=data.length;
    const lh='PK'+u16(20)+u16(0x0800)+u16(0)+u16(dt.time)+u16(dt.date)+u32(crc)+u32(size)+u32(size)+u16(name.length)+u16(0)+name+data;
    local+=lh;
    central+='PK'+u16(20)+u16(20)+u16(0x0800)+u16(0)+u16(dt.time)+u16(dt.date)+u32(crc)+u32(size)+u32(size)+u16(name.length)+u16(0)+u16(0)+u16(0)+u16(0)+u32(0)+u32(offset)+name;
    offset+=lh.length;
  });
  return local+central+'PK'+u16(0)+u16(0)+u16(files.length)+u16(files.length)+u32(central.length)+u32(local.length)+u16(0);
}
function xlsxCell(v, r, c){ const ref=c+r; const val=String(v ?? ''); if(/^[-+]?\d+(\.\d+)?$/.test(val) && val.length<15) return `<c r="${ref}"><v>${val}</v></c>`; return `<c r="${ref}" t="inlineStr"><is><t>${excelEsc(val)}</t></is></c>`; }
function makeXlsxBlob(rows){
  const head=['Rak','No','PLU','Nama Barang','Fisik','On Hand']; const letters=['A','B','C','D','E','F'];
  const all=[head].concat(rows.map(r=>[r.rack,r.no,r.plu,r.nama,r.fisik,r.on_hand]));
  const sheetRows=all.map((row,i)=>`<row r="${i+1}">${row.map((v,j)=>xlsxCell(v,i+1,letters[j])).join('')}</row>`).join('');
  const sheet=`<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"/></sheetViews><sheetFormatPr defaultRowHeight="15"/><cols><col min="1" max="1" width="18" customWidth="1"/><col min="2" max="2" width="8" customWidth="1"/><col min="3" max="3" width="16" customWidth="1"/><col min="4" max="4" width="44" customWidth="1"/><col min="5" max="6" width="14" customWidth="1"/></cols><sheetData>${sheetRows}</sheetData></worksheet>`;
  const files=[
    {name:'[Content_Types].xml',data:`<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>`},
    {name:'_rels/.rels',data:`<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>`},
    {name:'docProps/core.xml',data:`<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:creator>ALFASTORE</dc:creator><cp:lastModifiedBy>ALFASTORE</cp:lastModifiedBy><dcterms:created xsi:type="dcterms:W3CDTF">${new Date().toISOString()}</dcterms:created></cp:coreProperties>`},
    {name:'docProps/app.xml',data:`<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>ALFASTORE</Application></Properties>`},
    {name:'xl/workbook.xml',data:`<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="OH979" sheetId="1" r:id="rId1"/></sheets></workbook>`},
    {name:'xl/_rels/workbook.xml.rels',data:`<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>`},
    {name:'xl/worksheets/sheet1.xml',data:sheet}
  ];
  const zip=zipStore(files); const bytes=new Uint8Array(zip.length); for(let i=0;i<zip.length;i++) bytes[i]=zip.charCodeAt(i)&255;
  return new Blob([bytes], {type:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'});
}
function downloadOh979Excel(id){
  const pack=(window.__OH979_EXPORTS__ && window.__OH979_EXPORTS__[id]) || {label:'OH979', rows:[]};
  const rows=Array.isArray(pack.rows)?pack.rows:[];
  if(!rows.length){ alert('Tidak ada data untuk didownload.'); return; }
  const a=document.createElement('a');
  a.href=URL.createObjectURL(makeXlsxBlob(rows));
  a.download=`OH979_${STORE_ID||'TOKO'}_${pack.label}_${getTodayKey()}.xlsx`;
  document.body.appendChild(a); a.click();
  setTimeout(()=>{ URL.revokeObjectURL(a.href); a.remove(); }, 800);
}
function normalizePlus(raw){ const out=[]; const seen=new Set(); String(raw||'').split(/[^0-9]+/).map(v=>v.trim()).filter(Boolean).forEach(v=>{ if(!seen.has(v)){ seen.add(v); out.push(v); } }); return out.join(','); }
function getTodayKey(){ const now = new Date(); return `${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}-${String(now.getDate()).padStart(2,'0')}`; }
function readStorageMeta(){ try{ return JSON.parse(localStorage.getItem(storageMetaKey979()) || 'null') || {}; }catch(e){ return {}; } }
function clearStoredData(silent=false){ localStorage.removeItem(storageKey979()); localStorage.removeItem(storageMetaKey979()); if(!silent){ renderEmpty('Data otomatis dihapus karena sudah pergantian hari. data akan dimuat otomatis.'); setLoading('Data OH Custom direset otomatis saat pergantian hari.'); } }
function ensureFreshStorage(){ const meta=readStorageMeta(); const today=getTodayKey(); if(meta.savedDate && meta.savedDate!==today){ clearStoredData(true); return false; } return true; }
function simpanData(data, activeRakId=''){
  localStorage.setItem(storageKey979(), JSON.stringify(data || null));
  localStorage.setItem(storageMetaKey979(), JSON.stringify({savedDate:getTodayKey(), savedAt:new Date().toISOString(), storeId:STORE_ID || '', type:OH979_TOKO_MODE, activeRakId:String(activeRakId||CUSTOM_RAK_ACTIVE_ID||'')}));
}
function ambilDataTersimpan(){ if(!ensureFreshStorage()) return null; try{return JSON.parse(localStorage.getItem(storageKey979()) || 'null');}catch(e){return null;} }
function restoreActiveRakFromStorage(){ const meta=readStorageMeta(); CUSTOM_RAK_ACTIVE_ID=String(meta.activeRakId||''); }
function scheduleDailyReset(){ const now=new Date(); const next=new Date(now); next.setHours(24,0,0,0); const delay=Math.max(1000,next.getTime()-now.getTime()); window.setTimeout(()=>{ const had=!!localStorage.getItem(storageKey979()); clearStoredData(true); if(had){ renderEmpty('Data otomatis dihapus karena sudah pergantian hari. data akan dimuat otomatis.'); setLoading('Data OH Custom dihapus otomatis saat pergantian hari.'); } scheduleDailyReset(); }, delay); }
let CUSTOM_RAKS=[];
async function fetchCustomRaks(){
  if(!STORE_ID){ CUSTOM_RAKS=[]; return []; }
  try{
    const res=await fetch(apiUrl(`?api=oh979_custom_list&storeId=${encodeURIComponent(STORE_ID)}&type=${encodeURIComponent(OH979_TOKO_MODE)}`), {cache:'no-store', credentials:'same-origin'});
    const json=await res.json().catch(()=>null);
    CUSTOM_RAKS=(json&&json.status&&Array.isArray(json.data))?json.data:[];
  }catch(e){ CUSTOM_RAKS=[]; }
  return CUSTOM_RAKS;
}
function readCustomRaks(){ return Array.isArray(CUSTOM_RAKS)?CUSTOM_RAKS:[]; }
async function renderCustomRakButtons(){ const wrap=document.getElementById('customRakList'); if(!wrap) return; const arr=readCustomRaks(); wrap.innerHTML=arr.map(r=>`<button type="button" class="custom-rak-pill ${r.id===CUSTOM_RAK_ACTIVE_ID?'active':''}" onclick="loadCustomRak('${esc(String(r.id)).replace(/'/g,'&#39;')}')"><span>${esc(r.name)}</span><span class="rak-x" onclick="event.stopPropagation();deleteCustomRak('${esc(String(r.id)).replace(/'/g,'&#39;')}')">×</span></button>`).join(''); }
async function refreshCustomRakButtons(){ await fetchCustomRaks(); await renderCustomRakButtons(); }
function openCustomRakModal(editId=''){ CUSTOM_RAK_EDIT_ID=editId||''; const modal=document.getElementById('customRakModal'); const title=document.getElementById('customRakTitle'); const name=document.getElementById('customRakName'); const plus=document.getElementById('customRakPlus'); let item=null; if(editId){ item=readCustomRaks().find(r=>r.id===editId)||null; } if(title) title.textContent=item?'Edit Rak':'Tambah Rak'; if(name) name.value=item?item.name:''; if(plus) plus.value=item?item.plus:''; if(modal){ modal.classList.add('show'); modal.setAttribute('aria-hidden','false'); } setTimeout(()=>{ try{ (name||plus).focus(); }catch(e){} },50); }
function closeCustomRakModal(){ const modal=document.getElementById('customRakModal'); if(modal){ modal.classList.remove('show'); modal.setAttribute('aria-hidden','true'); } CUSTOM_RAK_EDIT_ID=''; }
async function saveCustomRak(){ const nameEl=document.getElementById('customRakName'); const plusEl=document.getElementById('customRakPlus'); const name=String(nameEl?.value||'').trim(); const plus=normalizePlus(plusEl?.value||''); if(!name){ alert('Nama rak wajib diisi.'); return; } if(!plus){ alert('PLU wajib diisi. Contoh: 234,244,888'); return; } showLoadingOverlay(); setLoading('Menyimpan rak '+name+'...'); try{ const res=await fetch(apiUrl('?api=oh979_custom_save'), {method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({storeId:STORE_ID,type:OH979_TOKO_MODE,id:CUSTOM_RAK_EDIT_ID,name,plus})}); const json=await res.json().catch(()=>null); if(!json||json.status!==true) throw new Error((json&&json.message)||'Gagal simpan rak.'); closeCustomRakModal(); await refreshCustomRakButtons(); await loadCustomRak(json.data.id); }catch(e){ alert((e&&e.message)||'Gagal simpan rak.'); }finally{ hideLoadingOverlay(); } }
async function loadCustomRak(id){ let item=readCustomRaks().find(r=>r.id===id); if(!item){ await fetchCustomRaks(); item=readCustomRaks().find(r=>r.id===id); } if(!item) return; CUSTOM_RAK_ACTIVE_ID=id; renderCustomRakButtons(); showLoadingOverlay(); setLoading(`Mengambil data ${item.name}...`); try{ const res=await fetch(apiUrl(`?api=oh979_custom_data&storeId=${encodeURIComponent(STORE_ID)}&type=${encodeURIComponent(OH979_TOKO_MODE)}&id=${encodeURIComponent(id)}`), {cache:'no-store', credentials:'same-origin'}); const json=await res.json().catch(()=>null); if(!json || json.status!==true) throw new Error((json&&json.message)||'Gagal mengambil data rak.'); const payload={status:true,label:item.name,plus:json.plus||item.plus,updatedAt:item.updatedAt,data:json.data||[],raks:[]}; simpanData(payload,id); renderData(payload); setLoading(`Rak ${item.name} tampil. Total PLU: ${(json.data||[]).length}`); }catch(e){ const payload={label:item.name,plus:item.plus,data:String(item.plus||'').split(',').filter(Boolean).map(plu=>({plu,nama:'-',on_hand:0})),raks:[]}; simpanData(payload,id); renderData(payload); setLoading((e&&e.message)?e.message:'Data rak tampil tanpa detail produk.'); }finally{ hideLoadingOverlay(); } }
async function deleteCustomRak(id){ const arr=readCustomRaks(); const item=arr.find(r=>r.id===id); if(!item) return; if(!confirm(`Apakah Anda yakin menghapus rak ini?\n\n${item.name}`)) return; showLoadingOverlay(); try{ const res=await fetch(apiUrl('?api=oh979_custom_delete'), {method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({storeId:STORE_ID,type:OH979_TOKO_MODE,id})}); const json=await res.json().catch(()=>null); if(!json||json.status!==true) throw new Error((json&&json.message)||'Gagal hapus rak.'); if(CUSTOM_RAK_ACTIVE_ID===id){ CUSTOM_RAK_ACTIVE_ID=''; renderEmpty('Rak sudah dihapus. Silakan pilih rak di atas.'); } await refreshCustomRakButtons(); setLoading(`Rak ${item.name} dihapus.`); }catch(e){ alert((e&&e.message)||'Gagal hapus rak.'); }finally{ hideLoadingOverlay(); } }
async function pilihTokoOH979(mode){ OH979_TOKO_MODE = mode === 'beanspot' ? 'beanspot' : 'reguler'; CUSTOM_RAK_ACTIVE_ID=''; document.getElementById('btnReguler')?.classList.toggle('active', OH979_TOKO_MODE==='reguler'); document.getElementById('btnBeanspot')?.classList.toggle('active', OH979_TOKO_MODE==='beanspot'); clearStoredData(true); await loadData(); }
function renderEmpty(msg){ const sg=getSummaryGrid(), rw=getRackWrap(); if(sg) sg.style.display='none'; if(rw) rw.innerHTML = `<div class="rack-card"><div class="empty">${esc(msg)}</div></div>`; }
function renderData(payload){
  const wrap=getRackWrap();
  const summary=getSummaryGrid();
  const raks=Array.isArray(payload?.raks)?payload.raks:[];
  const flat=Array.isArray(payload?.data)?payload.data:[];
  if(raks.length===0 && flat.length===0){ renderEmpty('Silakan pilih rak di atas.'); return; }
  let totalRak=raks.length;
  let totalPlu=0;
  summary.style.display='grid';
  if(raks.length>0){
    wrap.innerHTML=raks.map((rakObj,rakIdx)=>{
      const items=Array.isArray(rakObj?.data)?rakObj.data:[];
      totalPlu+=items.length;
      return `<div class="rack-card"><div class="rack-head"><div><div class="rack-title">Rak ${esc(rakObj.rak || (rakIdx+1))}</div><div class="rack-meta">Total PLU: ${items.length} · Update: ${esc(rakObj.updatedAt || '-')}</div></div><div class="rack-actions">${makeOh979DownloadButton({raks:[rakObj]})}</div></div><div class="table-wrap"><table><thead><tr><th style="width:8%">No</th><th style="width:16%">PLU</th><th style="width:44%">Nama Barang</th><th style="width:14%">Fisik</th><th style="width:18%">On Hand</th></tr></thead><tbody>${items.map((item,idx)=>`<tr><td>${idx+1}</td><td>${esc(item.plu ?? '-')}</td><td>${esc(item.nama ?? '-')}</td><td>${esc(item.fisik ?? '')}</td><td>${esc(item.on_hand ?? 0)}</td></tr>`).join('') || `<tr><td colspan="5" class="empty">Rak kosong.</td></tr>`}</tbody></table></div></div>`;
    }).join('');
  }else{
    totalRak=1;
    totalPlu=flat.length;
    wrap.innerHTML=`<div class="rack-card"><div class="rack-head"><div><div class="rack-title">${esc(payload.label || labelMode979())}</div><div class="rack-meta">Total PLU: ${flat.length}</div></div><div class="rack-actions">${makeOh979DownloadButton(payload)}</div></div><div class="table-wrap"><table><thead><tr><th style="width:8%">No</th><th style="width:16%">PLU</th><th style="width:44%">Nama Barang</th><th style="width:14%">Fisik</th><th style="width:18%">On Hand</th></tr></thead><tbody>${flat.map((item,idx)=>`<tr><td>${idx+1}</td><td>${esc(item.plu ?? '-')}</td><td>${esc(item.nama ?? '-')}</td><td>${esc(item.fisik ?? '')}</td><td>${esc(item.on_hand ?? 0)}</td></tr>`).join('')}</tbody></table></div></div>`;
  }
  document.getElementById('sumRak').textContent=String(totalRak);
  document.getElementById('sumPlu').textContent=String(totalPlu);
}
async function loadData(){ CUSTOM_RAK_ACTIVE_ID=''; await refreshCustomRakButtons(); if(!STORE_ID){ setLoading('Kode toko tidak ditemukan pada URL.'); renderEmpty('Kode toko tidak ditemukan. Buka halaman ini dari tombol OH Custom.'); return; } showLoadingOverlay(); setLoading('Mengambil data OH Custom...'); try{ const res=await fetch(apiUrl(`?api=oh979_data&storeId=${encodeURIComponent(STORE_ID)}&type=${encodeURIComponent(OH979_TOKO_MODE)}`), {cache:'no-store', credentials:'same-origin'}); const json=await res.json(); if(!json || json.status!==true){ const msg=(json&&json.message)?json.message:'Data OH Custom belum ditambahkan admin.'; setLoading(msg); renderEmpty(msg); return; } simpanData(json); renderData(json); const totalPlu=Array.isArray(json.raks)&&json.raks.length?json.raks.reduce((n,r)=>n+((Array.isArray(r.data)?r.data.length:0)),0):((Array.isArray(json.data)?json.data.length:0)); setLoading(`Berhasil memuat ${totalPlu} PLU ${labelMode979()} untuk toko ${STORE_ID}.`); }catch(err){ const saved=ambilDataTersimpan(); if(saved){ renderData(saved); setLoading('Gagal ambil data baru. Menampilkan data tersimpan terakhir.'); } else { setLoading('Gagal mengambil data dari server.'); renderEmpty('Gagal mengambil data dari server.'); } }finally{ hideLoadingOverlay(); } }
function hapusData(){ clearStoredData(true); renderEmpty('Silakan pilih rak di atas.'); setLoading('Data tersimpan dihapus. Silakan pilih rak di atas.'); }
(async function init(){ ensureFreshStorage(); scheduleDailyReset(); await refreshCustomRakButtons(); const saved=ambilDataTersimpan(); if(saved){ restoreActiveRakFromStorage(); await renderCustomRakButtons(); renderData(saved); setLoading('Menampilkan data OH Custom tersimpan. Data tidak diulang/hilang saat refresh.'); } else { renderEmpty('Silakan pilih rak di atas.'); setLoading('Silakan pilih rak di atas.'); } })();

JS
      );
      break;

    case 'hydrate-links':
      js_out(<<<'JS'

/* === OBFUSCATION (SAFE) hydrate /rpt/ attributes === */
(function(){
  function d(b64){ try { return atob(b64); } catch(e){ return b64; } }
  function apply(attr){
    var sel = '[data-b64-' + attr + ']';
    document.querySelectorAll(sel).forEach(function(el){
      var v = el.getAttribute('data-b64-' + attr);
      if (!v) return;
      el.setAttribute(attr, d(v));
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function(){
      apply('href'); apply('src'); apply('action');
    });
  } else {
    apply('href'); apply('src'); apply('action');
  }
})();

JS
      );
      break;



    case 'jadwal-filter':
      js_out(<<<'JS'

      function applyJadwalFilter(){
        const q = (document.getElementById('rackQ')?.value || '').trim().toLowerCase();
        const selectedDate = (document.getElementById('jadwalDate')?.value || '').trim();
        const rows = document.querySelectorAll('#tJadwal tbody tr');
        let shown = 0;
        rows.forEach(tr=>{
          const dateIso = (tr.getAttribute('data-date-iso')||'').trim();
          const rack = (tr.getAttribute('data-rak')||'').trim().toLowerCase();
          const textAll = (tr.innerText || '').toLowerCase();
          const okDate = !selectedDate || dateIso === selectedDate;
          const okText = !q || rack.includes(q) || textAll.includes(q);
          const ok = okDate && okText;
          tr.style.display = ok ? '' : 'none';
          if(ok) shown++;
        });
        const info = document.getElementById('jadwalShownInfo');
        if(info) info.innerHTML = 'Rak tampil: <b>'+shown+'</b>';
      }
      if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', applyJadwalFilter); else applyJadwalFilter();
    
JS
      );
      break;

    case 'onhand-filter':
      js_out(<<<'JS'

      let PICKED_RACK = "__ALL__";

      function pickRack(r){
        PICKED_RACK = r || "__ALL__";
        document.querySelectorAll("#rackbar .chip").forEach(x=>{
          x.classList.toggle("active", (x.dataset.rack||"") === PICKED_RACK);
        });
        applyFilters();
      }

      function applyFilters(){
        const q=(document.getElementById('q').value||'').trim().toLowerCase();
        let shown = 0;

        document.querySelectorAll('#t tbody tr').forEach(tr=>{
          const rack = tr.getAttribute("data-rack") || "";
          const okRack = (PICKED_RACK === "__ALL__") || (rack === PICKED_RACK);
          const okText = ((tr.innerText||'').toLowerCase().includes(q));
          const show = okRack && okText;
          tr.style.display = show ? "" : "none";
          if(show) shown++;
        });

        const meta2 = document.getElementById("meta2");
        const rackLabel = (PICKED_RACK==="__ALL__") ? "SEMUA RACK" : PICKED_RACK;
        meta2.innerHTML = `Filter: <b>${rackLabel}</b> · Baris tampil: <b>${shown}</b>`;
      }

      function autoZoomRupiahSO(){
        if(!document.body.classList.contains("rso-page")) return;
        const table = document.getElementById("t");
        if(!table) return;
        document.documentElement.style.overflowX = "hidden";
        document.body.style.overflowX = "hidden";
        document.body.style.transform = "";
        document.body.style.width = "100%";
        document.body.style.minHeight = "";
        document.body.style.zoom = "1";
        requestAnimationFrame(()=>{
          const vw = Math.max(320, window.innerWidth || document.documentElement.clientWidth || 360);
          const docW = Math.max(document.body.scrollWidth||0, document.documentElement.scrollWidth||0, table.scrollWidth||0, 760);
          let scale = Math.min(1, (vw - 2) / docW);
          scale = Math.max(0.48, Math.min(1, scale));
          if(scale < 0.995){
            if("zoom" in document.body.style){
              document.body.style.zoom = String(scale);
              document.body.style.width = (100 / scale) + "%";
            }else{
              document.body.style.transformOrigin = "top left";
              document.body.style.transform = `scale(${scale})`;
              document.body.style.width = (100 / scale) + "%";
              document.body.style.minHeight = `calc(100vh / ${scale})`;
            }
          }
        });
      }

      window.addEventListener("load", autoZoomRupiahSO);
      window.addEventListener("resize", autoZoomRupiahSO);
      window.addEventListener("orientationchange", ()=>setTimeout(autoZoomRupiahSO, 250));

      applyFilters();
      autoZoomRupiahSO();
    
JS
      );
      break;
      /* registration-app dihapus */

    case 'admin-tools':
      js_out(<<<'JS'
function qrisFormatRupiahAdmin(n){ const num = Number(String(n||0).replace(/[^0-9]/g,'')) || 0; return 'Rp ' + new Intl.NumberFormat('en-US').format(num); }
function qrisFormatNumberInput(n){ const num = String(n||'').replace(/[^0-9]/g,''); return num ? new Intl.NumberFormat('en-US').format(Number(num)) : ''; }
function qrisBindAmountCommaInput(){ const input=document.getElementById('qrisAmountInput'); if(!input || input.dataset.bound==='1') return; input.dataset.bound='1'; input.addEventListener('input', ()=>{ const digits=String(input.value||'').replace(/[^0-9]/g,''); input.value = qrisFormatNumberInput(digits); }); }
function qrisFormatDateAdmin(iso){ if(!iso) return '-'; const d = new Date(iso); if(Number.isNaN(d.getTime())) return iso; return new Intl.DateTimeFormat('id-ID',{dateStyle:'medium',timeStyle:'short'}).format(d); }
function openQrisSettingsModal(){ const el=document.getElementById('qrisSettingsModal'); if(el) el.style.display='flex'; qrisBindAmountCommaInput(); loadQrisSettings(); }
function closeQrisSettingsModal(){ const el=document.getElementById('qrisSettingsModal'); if(el) el.style.display='none'; }
async function loadQrisSettings(){ const msg=document.getElementById('qrisSettingsMsg'); if(msg) msg.textContent='Memuat...'; try{ const res=await fetch('?api=admin_get_qris_amount'); const j=await res.json(); if(!j.ok){ if(msg) msg.textContent=j.msg||'Gagal memuat nominal'; return; } document.getElementById('qrisAmountInput').value = qrisFormatNumberInput(j.amount||''); document.getElementById('qrisAmountCurrent').textContent = qrisFormatRupiahAdmin(j.amount||0); document.getElementById('qrisAmountUpdatedAt').textContent = qrisFormatDateAdmin(j.updatedAt||''); if(msg) msg.textContent=''; }catch(e){ if(msg) msg.textContent='Koneksi gagal'; } }
async function loadUiConfig(){ const msg=document.getElementById('uiConfigMsg'); const toggle=document.getElementById('toggleRegisterButton'); if(msg) msg.textContent='Memuat...'; try{ const res=await fetch('?api=admin_get_ui_config',{cache:'no-store',credentials:'same-origin',headers:{'Accept':'application/json'}}); const j=await res.json(); if(!j.ok){ if(msg) msg.textContent=j.msg||'Gagal memuat pengaturan'; return; } if(toggle) toggle.checked = !!j.show_register_button; if(msg) msg.textContent = 'Status tombol daftar: ' + (j.show_register_button ? 'Tampil' : 'Sembunyi'); }catch(e){ if(msg) msg.textContent='Koneksi gagal'; } }
async function saveUiConfig(){ const msg=document.getElementById('uiConfigMsg'); const toggle=document.getElementById('toggleRegisterButton'); if(!toggle) return; if(msg) msg.textContent='Menyimpan...'; try{ const res=await fetch('?api=admin_set_ui_config',{method:'POST',cache:'no-store',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({show_register_button: !!toggle.checked})}); const j=await res.json(); if(!j.ok){ if(msg) msg.textContent=j.msg||'Gagal menyimpan pengaturan'; return; } if(toggle) toggle.checked = !!j.show_register_button; if(msg) msg.textContent='Berhasil disimpan. Tombol daftar sekarang ' + (j.show_register_button ? 'tampil' : 'disembunyikan'); const helpBtn=document.getElementById('helpBtn'); if(helpBtn){ helpBtn.dataset.enabled = j.show_register_button ? '1' : '0'; helpBtn.style.display = j.show_register_button ? 'block' : 'none'; } }catch(e){ if(msg) msg.textContent='Koneksi gagal'; } }
async function saveQrisSettings(){ const input=document.getElementById('qrisAmountInput'); const msg=document.getElementById('qrisSettingsMsg'); const amount=String((input&&input.value)||'').replace(/[^0-9]/g,''); if(!amount || Number(amount)<=0){ if(msg) msg.textContent='Nominal harus lebih dari 0'; return; } if(msg) msg.textContent='Menyimpan...'; try{ const res=await fetch('?api=admin_set_qris_amount',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({amount:Number(amount)})}); const j=await res.json(); if(!j.ok){ if(msg) msg.textContent=j.msg||'Gagal menyimpan nominal'; return; } document.getElementById('qrisAmountCurrent').textContent = qrisFormatRupiahAdmin(j.amount||0); document.getElementById('qrisAmountUpdatedAt').textContent = qrisFormatDateAdmin(j.updatedAt||''); if(msg) msg.textContent='Nominal QRIS berhasil disimpan'; }catch(e){ if(msg) msg.textContent='Koneksi gagal'; } }
function openPromoAdminModal(){ const el=document.getElementById('promoAdminModal'); if(el) el.style.display='flex'; onPromoAdminTypeChange(); loadPromoAdminList(); }
function closePromoAdminModal(){ const el=document.getElementById('promoAdminModal'); if(el) el.style.display='none'; }
function randomPromoCode(){ return 'PROMO' + Math.random().toString(36).slice(2,8).toUpperCase(); }
function onPromoAdminTypeChange(){ const typeEl=document.getElementById('promoAdminType'); const valueEl=document.getElementById('promoAdminValue'); if(!typeEl || !valueEl) return; const type=String(typeEl.value||'active30_once'); if(type==='active30_once'){ valueEl.value='30'; valueEl.disabled=true; valueEl.placeholder='30 hari'; }else if(type==='free3d_once' || type==='free3d_multi'){ valueEl.value='3'; valueEl.disabled=true; valueEl.placeholder='3 hari'; }else{ if(valueEl.disabled) valueEl.value=''; valueEl.disabled=false; valueEl.placeholder='Jumlah hari'; } }
async function copyTextToClipboardAdmin(text){ const value=String(text||'').trim(); if(!value) return false; try{ if(navigator.clipboard && window.isSecureContext){ await navigator.clipboard.writeText(value); return true; } }catch(e){} try{ const ta=document.createElement('textarea'); ta.value=value; ta.setAttribute('readonly','readonly'); ta.style.position='fixed'; ta.style.opacity='0'; ta.style.pointerEvents='none'; document.body.appendChild(ta); ta.focus(); ta.select(); const ok=document.execCommand('copy'); document.body.removeChild(ta); return !!ok; }catch(e){ return false; } }
async function copyPromoCodeAdmin(){ const codeEl=document.getElementById('promoAdminCode'); const msg=document.getElementById('promoAdminMsg'); const code=String((codeEl&&codeEl.value)||'').trim().toUpperCase(); if(!code){ if(msg) msg.textContent='Belum ada kode promo untuk disalin'; return false; } const ok=await copyTextToClipboardAdmin(code); if(msg) msg.textContent = ok ? ('Kode promo tersalin: '+code) : 'Gagal menyalin kode promo'; return ok; }
async function generatePromoCodeAdmin(){ const codeEl=document.getElementById('promoAdminCode'); const typeEl=document.getElementById('promoAdminType'); const valueEl=document.getElementById('promoAdminValue'); const msg=document.getElementById('promoAdminMsg'); let code=String((codeEl&&codeEl.value)||'').trim().toUpperCase(); if(!code) code=randomPromoCode(); const type=String((typeEl&&typeEl.value)||'active30_once'); const value=(type==='active30_once' ? 30 : ((type==='free3d_once' || type==='free3d_multi') ? 3 : Number(String((valueEl&&valueEl.value)||'').replace(/[^0-9]/g,'')))); if(!value||value<=0){ if(msg) msg.textContent='Nilai promo harus lebih dari 0'; return; } if(msg) msg.textContent='Menyimpan promo...'; try{ const res=await fetch('?api=admin_promo_create',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({code,type,value})}); const j=await res.json(); if(!j.ok){ if(msg) msg.textContent=j.msg||'Gagal membuat promo'; return; } const finalCode=String(j.code||code).toUpperCase(); if(codeEl) codeEl.value=finalCode; const copied=await copyTextToClipboardAdmin(finalCode); if(msg) msg.textContent = copied ? ('Kode promo berhasil dibuat dan langsung tersalin: '+finalCode) : ('Kode promo berhasil dibuat: '+finalCode); onPromoAdminTypeChange(); loadPromoAdminList(); }catch(e){ if(msg) msg.textContent='Koneksi gagal'; } }
async function loadPromoAdminList(){ const wrap=document.getElementById('promoAdminList'); const msg=document.getElementById('promoAdminMsg'); if(wrap) wrap.innerHTML='Memuat...'; try{ const res=await fetch('?api=admin_promo_list',{cache:'no-store'}); const j=await res.json(); if(!j.ok){ if(wrap) wrap.innerHTML='<div class="small">'+(j.msg||'Gagal memuat promo')+'</div>'; return; } const items=Array.isArray(j.items)?j.items:[]; if(!items.length){ if(wrap) wrap.innerHTML='<div class="small">Belum ada kode promo.</div>'; return; } if(wrap) wrap.innerHTML=items.map(it=>{ const rawType=String(it.type||'active30_once'); const type=(rawType==='fixed'?'active30_once':rawType); const isReusable=type==='free3d_multi'; const used=(Number(it.used_count||0)>0) && !isReusable; const typeText=(type==='active30_once') ? 'Aktif 30 hari · 1x digunakan' : (type==='free3d_once') ? 'Aktif 3 hari tanpa QRIS · 1x digunakan' : (type==='free3d_multi' ? 'Aktif 3 hari tanpa QRIS · berkali-kali' : ('Aktif '+String(it.value||0)+' hari')); const statusText=isReusable ? '<span style="color:#16a34a;font-weight:900">Belum dipakai</span> <span style="color:#6b7280">(bisa berkali-kali)</span>' : (used ? '<span style="color:#dc2626;font-weight:900">Sudah dipakai</span>' : '<span style="color:#16a34a;font-weight:900">Belum dipakai</span>'); return `<div style="border:1px solid #dbeafe;border-radius:5px;padding:12px;margin-bottom:8px;background:#faf5ff"><div style="display:flex;justify-content:space-between;gap:8px;align-items:center"><div><div style="font-weight:900;color:#1e40af">${it.code}</div><div class="small">${typeText} · ${statusText}</div></div><button class="btn-mini danger" onclick="deletePromoAdmin('${it.code}')">Hapus</button></div></div>`; }).join(''); if(msg) msg.textContent=''; }catch(e){ if(wrap) wrap.innerHTML='<div class="small">Koneksi gagal</div>'; } }
async function deletePromoAdmin(code){ const msg=document.getElementById('promoAdminMsg'); if(msg) msg.textContent='Menghapus promo...'; try{ const res=await fetch('?api=admin_promo_delete',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({code})}); const j=await res.json(); if(!j.ok){ if(msg) msg.textContent=j.msg||'Gagal menghapus promo'; return; } if(msg) msg.textContent='Promo dihapus'; onPromoAdminTypeChange(); loadPromoAdminList(); }catch(e){ if(msg) msg.textContent='Koneksi gagal'; } }
JS
      );
      break;

    case 'planogram-list':
      js_out(<<<'JS'

const __SCRIPT_CFG = (document.currentScript && document.currentScript.dataset) ? document.currentScript.dataset : {};
const API_URL = __SCRIPT_CFG.apiUrl || '';
const PRD_API = __SCRIPT_CFG.prdApi || '';
// ADMIN_STORE_ID tidak diekspos di index.php (disimpan di proxy.php)
const STORE_ID = __SCRIPT_CFG.storeId || '';

const __LS = {
  store: "planooh_cache_store",
  rak:   "planooh_cache_rak",
  qplu:  "planooh_cache_qplu",
  html:  "planooh_cache_html",
  ts:    "planooh_cache_ts"
};
function clearPlanoCache(){
  try{
    localStorage.removeItem(__LS.store);
    localStorage.removeItem(__LS.rak);
    localStorage.removeItem(__LS.qplu);
    localStorage.removeItem(__LS.html);
    localStorage.removeItem(__LS.ts);
  }catch(e){}
}

// Restore cache hanya jika masih rak yang sama (dan toko yang sama)
(function restorePlanoCache(){
  try{
    const tbody = document.getElementById("tbody");
    const rakEl = document.getElementById("rak");
    const qEl   = document.getElementById("qplu");
    if(!tbody || !rakEl) return;

    const cStore = localStorage.getItem(__LS.store) || "";
    const cRak   = localStorage.getItem(__LS.rak) || "";
    const cQplu  = localStorage.getItem(__LS.qplu) || "";
    const cHtml  = localStorage.getItem(__LS.html) || "";

    if(!cStore || !cRak || !cHtml) return;
    if(String(cStore).toUpperCase() !== String(STORE_ID||"").toUpperCase()) return;

    // Jika input rak kosong, isi dari cache agar user lanjut dari rak terakhir
    if(!rakEl.value.trim()){
      rakEl.value = String(cRak).toUpperCase();
    }

    // Tampilkan cache hanya bila rak yang sedang dipilih sama
    if(String(rakEl.value||"").trim().toUpperCase() === String(cRak).trim().toUpperCase()){
      tbody.innerHTML = cHtml;
      if(qEl && !qEl.value.trim() && cQplu) qEl.value = String(cQplu);
    }
  }catch(e){}
})();

let __pluTimer = null;
function onPluInput(){
  // Auto refresh saat user mengetik / menghapus PLU tanpa perlu klik "Tampilkan".
  // Hanya jalan jika rak sudah diisi dan minimal pernah load data.
  const rakVal = (document.getElementById('rak').value||'').trim();
  if(!rakVal){
    return; // belum ada rak, jangan hit api
  }
  clearTimeout(__pluTimer);
  __pluTimer = setTimeout(() => {
    loadData();
  }, 250);
}

function onRakInput(){
  // Saat user pindah/ubah rak, langsung bersihkan cache + reset tabel agar tidak menampilkan data rak sebelumnya
  try{
    const storeId = String(STORE_ID||"").trim().toUpperCase();
    const rakNow  = (document.getElementById("rak").value||"").trim().toUpperCase();
    const lastStore = (localStorage.getItem(__LS.store)||"").toUpperCase();
    const lastRak   = (localStorage.getItem(__LS.rak)||"").toUpperCase();
    if(lastStore && lastStore === storeId && lastRak && rakNow && lastRak !== rakNow){
      clearPlanoCache();
      const tbody = document.getElementById("tbody");
      if(tbody){
        tbody.innerHTML = `<tr><td colspan="5" class="loading">Rak berubah. Klik Tampilkan untuk ambil data.</td></tr>`;
      }
    }
  }catch(e){}
}


// init label + restore params
(function initPlano(){
  const lab = document.getElementById("storeLabel");
  if(lab) lab.textContent = STORE_ID || "-";
  const sp = new URLSearchParams(location.search);
  const rak = (sp.get("rak")||"").toUpperCase();
  const qplu = (sp.get("plu")||"").replace(/[^0-9]/g,"");
  const rakEl = document.getElementById("rak");
  const qEl = document.getElementById("qplu");
  if(rakEl && rak) rakEl.value = rak;
  if(qEl && qplu) qEl.value = qplu;
  if(rak) loadData();
})();

function loadData(){
  const storeId = String(STORE_ID||"").trim().toUpperCase();
  const rak     = (document.getElementById("rak").value||"").trim().toUpperCase();
  const qplu    = (document.getElementById("qplu").value||"").trim().replace(/[^0-9]/g,"");
  const tbody   = document.getElementById("tbody");

  // Jika pindah rak, hapus cache lama (otomatis hapus localStorage data sebelumnya)
  try{
    const lastStore = (localStorage.getItem(__LS.store)||"").toUpperCase();
    const lastRak   = (localStorage.getItem(__LS.rak)||"").toUpperCase();
    if(lastStore && lastStore === storeId && lastRak && lastRak !== rak){
      clearPlanoCache();
    }
  }catch(e){}

  // persist agar refresh tidak hilang
  try{
    const u = new URL(location.href);
    u.searchParams.set("page","plano");
    if(rak) u.searchParams.set("rak", rak); else u.searchParams.delete("rak");
    if(qplu) u.searchParams.set("plu", qplu); else u.searchParams.delete("plu");
    history.replaceState(null, "", u.toString());
  }catch(e){}
if(!storeId){
    tbody.innerHTML = `<tr><td colspan="5" class="loading">Session login tidak ditemukan. Silakan login dulu di halaman utama.</td></tr>`;
    return;
  }
if(!rak){
    tbody.innerHTML = `<tr><td colspan="5" class="loading">Rak wajib diisi</td></tr>`;
    return;
  }

  tbody.innerHTML = `<tr><td colspan="5" class="loading">Loading data...</td></tr>`;

  // AMBIL DATA PLANOGRAM (dari file yang sama)
  fetch(`${API_URL}?type=list&storeId=${encodeURIComponent(storeId)}`, {credentials:"same-origin"})
    .then(async (res) => {
      const txt = await res.text();
      let j = null;
      try{ j = JSON.parse(txt); }catch(e){ j = null; }
      if(!j) throw new Error("Response bukan JSON valid");
      if(!res.ok){
        const msg = (j && (j.message||j.msg||j.error)) ? (j.message||j.msg||j.error) : ("HTTP "+res.status);
        throw new Error(msg);
      }
      return j;
    })
    .then(async (list) => {
      const normalizeArray = (data)=>{
        if(Array.isArray(data)) return data;
        if(data && typeof data === 'object'){
          for(const k of ['data','result','items','rows','list']){
            if(Array.isArray(data[k])) return data[k];
          }
          // jika object map -> values
          return Object.values(data).filter(x=>x && typeof x==='object');
        }
        return [];
      };

      const arr = normalizeArray(list);
      if(!arr.length){
        const msg = (list && (list.message || list.msg || list.error)) ? String(list.message || list.msg || list.error) : "Format data salah / gagal ambil list";
        tbody.innerHTML = `<tr><td colspan="5" class="loading">${escapeHtml(msg)}</td></tr>`;
        return;
      }
// FILTER RAK (awalan planogram)
      let filtered = arr.filter(item =>
        item.planogram &&
        String(item.planogram).toUpperCase().startsWith(rak)
      );

      

      // FILTER PLU (angka)
      if(qplu){
        filtered = filtered.filter(it => String(it.plu||it.PLU||"").replace(/[^0-9]/g,"").includes(qplu));
      }
if(filtered.length === 0){
        tbody.innerHTML = `<tr><td colspan="5" class="loading">Data tidak ditemukan</td></tr>`;
        return;
      }

      // PARSE SLV + POSISI (lebih aman)
      filtered = filtered.map(item => {
        const plan = String(item.planogram || "");
        const parts = plan.split("-");
        return {
          ...item,
          slv: parseInt(parts[1] || 0, 10) || 0,
          pos: parseInt(parts[5] || 0, 10) || 0
        };
      });

      // SORT SLV → POSISI
      filtered.sort((a,b)=>{
        if(a.slv !== b.slv) return a.slv - b.slv;
        return a.pos - b.pos;
      });

      tbody.innerHTML = "";
      const pluArr = [];

      // RENDER TABEL
      for(const item of filtered){
        const plu = String(item.plu || "").trim();
        pluArr.push(plu);

        const slvText = String(item.slv).padStart(2,"0");
        const descp = (item.descp ?? item.desc ?? "").toString();

        tbody.innerHTML += `
          <tr>
            <td>${slvText}</td>
            <td>${plu}</td>
            <td>${escapeHtml(descp)}</td>
            <td>${escapeHtml(String(item.planogram || ""))}</td>
            <td id="onhand-${plu}">Loading...</td>
          </tr>
        `;

      }

      // Simpan cache setelah tabel tampil (untuk Android/refresh)
      try{
        localStorage.setItem(__LS.store, storeId);
        localStorage.setItem(__LS.rak, rak);
        localStorage.setItem(__LS.qplu, qplu);
        localStorage.setItem(__LS.html, tbody.innerHTML);
        localStorage.setItem(__LS.ts, String(Date.now()));
      }catch(e){}

      // AMBIL ON HAND (batch via file yang sama)
      fetch(`${API_URL}?type=onhand&storeId=${encodeURIComponent(storeId)}&plus=${encodeURIComponent(pluArr.join(","))}`)
        .then(res => res.json())
        .then(res => {
          if(!res || !res.status || !Array.isArray(res.data)) return;

          res.data.forEach(d => {
            const plu = String(d.plu ?? "");
            const cell = document.getElementById("onhand-"+plu);
            if(!cell) return;

            const oh = d.on_hand;
            if(oh === null || oh === undefined){
              cell.innerText = "-";
              return;
            }

            cell.innerText = oh;

            if(Number(oh) === 0){
              cell.style.color = "red";
              cell.style.fontWeight = "bold";
            }
          });
        })
        .catch(()=>{
          // kalau OH gagal, tidak usah mematikan tabel
          for(const plu of pluArr){
            const cell = document.getElementById("onhand-"+plu);
            if(cell) cell.innerText = "-";
          }
        });

    })
    .catch(err => {
      console.error(err);
      tbody.innerHTML = `<tr><td colspan="5" class="loading">Gagal load data: ${escapeHtml((err && (err.message||err)) ? (err.message||err) : err)}</td></tr>`;
    });
}

function escapeHtml(str){
  return String(str)
    .replaceAll("&","&amp;")
    .replaceAll("<","&lt;")
    .replaceAll(">","&gt;")
    .replaceAll('"',"&quot;")
    .replaceAll("'","&#039;");
}

JS
      );
      break;

    case 'jadwal-filter':
      js_out(<<<'JS'

      function applyJadwalFilter(){
        const q = (document.getElementById('rackQ')?.value || '').trim().toLowerCase();
        const selectedDate = (document.getElementById('jadwalDate')?.value || '').trim();
        const rows = document.querySelectorAll('#tJadwal tbody tr');
        let shown = 0;
        rows.forEach(tr=>{
          const dateIso = (tr.getAttribute('data-date-iso')||'').trim();
          const rack = (tr.getAttribute('data-rak')||'').trim().toLowerCase();
          const textAll = (tr.innerText || '').toLowerCase();
          const okDate = !selectedDate || dateIso === selectedDate;
          const okText = !q || rack.includes(q) || textAll.includes(q);
          const ok = okDate && okText;
          tr.style.display = ok ? '' : 'none';
          if(ok) shown++;
        });
        const info = document.getElementById('jadwalShownInfo');
        if(info) info.innerHTML = 'Rak tampil: <b>'+shown+'</b>';
      }
      if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', applyJadwalFilter); else applyJadwalFilter();
    
JS
      );
      break;

    case 'onhand-filter':
      js_out(<<<'JS'

      let PICKED_RACK = "__ALL__";

      function pickRack(r){
        PICKED_RACK = r || "__ALL__";
        document.querySelectorAll("#rackbar .chip").forEach(x=>{
          x.classList.toggle("active", (x.dataset.rack||"") === PICKED_RACK);
        });
        applyFilters();
      }

      function applyFilters(){
        const q=(document.getElementById('q').value||'').trim().toLowerCase();
        let shown = 0;

        document.querySelectorAll('#t tbody tr').forEach(tr=>{
          const rack = tr.getAttribute("data-rack") || "";
          const okRack = (PICKED_RACK === "__ALL__") || (rack === PICKED_RACK);
          const okText = ((tr.innerText||'').toLowerCase().includes(q));
          const show = okRack && okText;
          tr.style.display = show ? "" : "none";
          if(show) shown++;
        });

        const meta2 = document.getElementById("meta2");
        const rackLabel = (PICKED_RACK==="__ALL__") ? "SEMUA RACK" : PICKED_RACK;
        meta2.innerHTML = `Filter: <b>${rackLabel}</b> · Baris tampil: <b>${shown}</b>`;
      }

      function autoZoomRupiahSO(){
        if(!document.body.classList.contains("rso-page")) return;
        const table = document.getElementById("t");
        if(!table) return;
        document.documentElement.style.overflowX = "hidden";
        document.body.style.overflowX = "hidden";
        document.body.style.transform = "";
        document.body.style.width = "100%";
        document.body.style.minHeight = "";
        document.body.style.zoom = "1";
        requestAnimationFrame(()=>{
          const vw = Math.max(320, window.innerWidth || document.documentElement.clientWidth || 360);
          const docW = Math.max(document.body.scrollWidth||0, document.documentElement.scrollWidth||0, table.scrollWidth||0, 760);
          let scale = Math.min(1, (vw - 2) / docW);
          scale = Math.max(0.48, Math.min(1, scale));
          if(scale < 0.995){
            if("zoom" in document.body.style){
              document.body.style.zoom = String(scale);
              document.body.style.width = (100 / scale) + "%";
            }else{
              document.body.style.transformOrigin = "top left";
              document.body.style.transform = `scale(${scale})`;
              document.body.style.width = (100 / scale) + "%";
              document.body.style.minHeight = `calc(100vh / ${scale})`;
            }
          }
        });
      }

      window.addEventListener("load", autoZoomRupiahSO);
      window.addEventListener("resize", autoZoomRupiahSO);
      window.addEventListener("orientationchange", ()=>setTimeout(autoZoomRupiahSO, 250));

      applyFilters();
      autoZoomRupiahSO();
    
JS
      );
      break;
      /* registration-app dihapus */

    case 'admin-tools':
      js_out(<<<'JS'
function qrisFormatRupiahAdmin(n){ const num = Number(String(n||0).replace(/[^0-9]/g,'')) || 0; return 'Rp ' + new Intl.NumberFormat('en-US').format(num); }
function qrisFormatNumberInput(n){ const num = String(n||'').replace(/[^0-9]/g,''); return num ? new Intl.NumberFormat('en-US').format(Number(num)) : ''; }
function qrisBindAmountCommaInput(){ const input=document.getElementById('qrisAmountInput'); if(!input || input.dataset.bound==='1') return; input.dataset.bound='1'; input.addEventListener('input', ()=>{ const digits=String(input.value||'').replace(/[^0-9]/g,''); input.value = qrisFormatNumberInput(digits); }); }
function qrisFormatDateAdmin(iso){ if(!iso) return '-'; const d = new Date(iso); if(Number.isNaN(d.getTime())) return iso; return new Intl.DateTimeFormat('id-ID',{dateStyle:'medium',timeStyle:'short'}).format(d); }
function openQrisSettingsModal(){ const el=document.getElementById('qrisSettingsModal'); if(el) el.style.display='flex'; qrisBindAmountCommaInput(); loadQrisSettings(); }
function closeQrisSettingsModal(){ const el=document.getElementById('qrisSettingsModal'); if(el) el.style.display='none'; }
async function loadQrisSettings(){ const msg=document.getElementById('qrisSettingsMsg'); if(msg) msg.textContent='Memuat...'; try{ const res=await fetch('?api=admin_get_qris_amount'); const j=await res.json(); if(!j.ok){ if(msg) msg.textContent=j.msg||'Gagal memuat nominal'; return; } document.getElementById('qrisAmountInput').value = qrisFormatNumberInput(j.amount||''); document.getElementById('qrisAmountCurrent').textContent = qrisFormatRupiahAdmin(j.amount||0); document.getElementById('qrisAmountUpdatedAt').textContent = qrisFormatDateAdmin(j.updatedAt||''); if(msg) msg.textContent=''; }catch(e){ if(msg) msg.textContent='Koneksi gagal'; } }
async function loadUiConfig(){ const msg=document.getElementById('uiConfigMsg'); const toggle=document.getElementById('toggleRegisterButton'); if(msg) msg.textContent='Memuat...'; try{ const res=await fetch('?api=admin_get_ui_config',{cache:'no-store',credentials:'same-origin',headers:{'Accept':'application/json'}}); const j=await res.json(); if(!j.ok){ if(msg) msg.textContent=j.msg||'Gagal memuat pengaturan'; return; } if(toggle) toggle.checked = !!j.show_register_button; if(msg) msg.textContent = 'Status tombol daftar: ' + (j.show_register_button ? 'Tampil' : 'Sembunyi'); }catch(e){ if(msg) msg.textContent='Koneksi gagal'; } }
async function saveUiConfig(){ const msg=document.getElementById('uiConfigMsg'); const toggle=document.getElementById('toggleRegisterButton'); if(!toggle) return; if(msg) msg.textContent='Menyimpan...'; try{ const res=await fetch('?api=admin_set_ui_config',{method:'POST',cache:'no-store',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({show_register_button: !!toggle.checked})}); const j=await res.json(); if(!j.ok){ if(msg) msg.textContent=j.msg||'Gagal menyimpan pengaturan'; return; } if(toggle) toggle.checked = !!j.show_register_button; if(msg) msg.textContent='Berhasil disimpan. Tombol daftar sekarang ' + (j.show_register_button ? 'tampil' : 'disembunyikan'); const helpBtn=document.getElementById('helpBtn'); if(helpBtn){ helpBtn.dataset.enabled = j.show_register_button ? '1' : '0'; helpBtn.style.display = j.show_register_button ? 'block' : 'none'; } }catch(e){ if(msg) msg.textContent='Koneksi gagal'; } }
async function saveQrisSettings(){ const input=document.getElementById('qrisAmountInput'); const msg=document.getElementById('qrisSettingsMsg'); const amount=String((input&&input.value)||'').replace(/[^0-9]/g,''); if(!amount || Number(amount)<=0){ if(msg) msg.textContent='Nominal harus lebih dari 0'; return; } if(msg) msg.textContent='Menyimpan...'; try{ const res=await fetch('?api=admin_set_qris_amount',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({amount:Number(amount)})}); const j=await res.json(); if(!j.ok){ if(msg) msg.textContent=j.msg||'Gagal menyimpan nominal'; return; } document.getElementById('qrisAmountCurrent').textContent = qrisFormatRupiahAdmin(j.amount||0); document.getElementById('qrisAmountUpdatedAt').textContent = qrisFormatDateAdmin(j.updatedAt||''); if(msg) msg.textContent='Nominal QRIS berhasil disimpan'; }catch(e){ if(msg) msg.textContent='Koneksi gagal'; } }
function openPromoAdminModal(){ const el=document.getElementById('promoAdminModal'); if(el) el.style.display='flex'; onPromoAdminTypeChange(); loadPromoAdminList(); }
function closePromoAdminModal(){ const el=document.getElementById('promoAdminModal'); if(el) el.style.display='none'; }
function randomPromoCode(){ return 'PROMO' + Math.random().toString(36).slice(2,8).toUpperCase(); }
function onPromoAdminTypeChange(){ const typeEl=document.getElementById('promoAdminType'); const valueEl=document.getElementById('promoAdminValue'); if(!typeEl || !valueEl) return; const type=String(typeEl.value||'active30_once'); if(type==='active30_once'){ valueEl.value='30'; valueEl.disabled=true; valueEl.placeholder='30 hari'; }else if(type==='free3d_once' || type==='free3d_multi'){ valueEl.value='3'; valueEl.disabled=true; valueEl.placeholder='3 hari'; }else{ if(valueEl.disabled) valueEl.value=''; valueEl.disabled=false; valueEl.placeholder='Jumlah hari'; } }
async function copyTextToClipboardAdmin(text){ const value=String(text||'').trim(); if(!value) return false; try{ if(navigator.clipboard && window.isSecureContext){ await navigator.clipboard.writeText(value); return true; } }catch(e){} try{ const ta=document.createElement('textarea'); ta.value=value; ta.setAttribute('readonly','readonly'); ta.style.position='fixed'; ta.style.opacity='0'; ta.style.pointerEvents='none'; document.body.appendChild(ta); ta.focus(); ta.select(); const ok=document.execCommand('copy'); document.body.removeChild(ta); return !!ok; }catch(e){ return false; } }
async function copyPromoCodeAdmin(){ const codeEl=document.getElementById('promoAdminCode'); const msg=document.getElementById('promoAdminMsg'); const code=String((codeEl&&codeEl.value)||'').trim().toUpperCase(); if(!code){ if(msg) msg.textContent='Belum ada kode promo untuk disalin'; return false; } const ok=await copyTextToClipboardAdmin(code); if(msg) msg.textContent = ok ? ('Kode promo tersalin: '+code) : 'Gagal menyalin kode promo'; return ok; }
async function generatePromoCodeAdmin(){ const codeEl=document.getElementById('promoAdminCode'); const typeEl=document.getElementById('promoAdminType'); const valueEl=document.getElementById('promoAdminValue'); const msg=document.getElementById('promoAdminMsg'); let code=String((codeEl&&codeEl.value)||'').trim().toUpperCase(); if(!code) code=randomPromoCode(); const type=String((typeEl&&typeEl.value)||'active30_once'); const value=(type==='active30_once' ? 30 : ((type==='free3d_once' || type==='free3d_multi') ? 3 : Number(String((valueEl&&valueEl.value)||'').replace(/[^0-9]/g,'')))); if(!value||value<=0){ if(msg) msg.textContent='Nilai promo harus lebih dari 0'; return; } if(msg) msg.textContent='Menyimpan promo...'; try{ const res=await fetch('?api=admin_promo_create',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({code,type,value})}); const j=await res.json(); if(!j.ok){ if(msg) msg.textContent=j.msg||'Gagal membuat promo'; return; } const finalCode=String(j.code||code).toUpperCase(); if(codeEl) codeEl.value=finalCode; const copied=await copyTextToClipboardAdmin(finalCode); if(msg) msg.textContent = copied ? ('Kode promo berhasil dibuat dan langsung tersalin: '+finalCode) : ('Kode promo berhasil dibuat: '+finalCode); onPromoAdminTypeChange(); loadPromoAdminList(); }catch(e){ if(msg) msg.textContent='Koneksi gagal'; } }
async function loadPromoAdminList(){ const wrap=document.getElementById('promoAdminList'); const msg=document.getElementById('promoAdminMsg'); if(wrap) wrap.innerHTML='Memuat...'; try{ const res=await fetch('?api=admin_promo_list',{cache:'no-store'}); const j=await res.json(); if(!j.ok){ if(wrap) wrap.innerHTML='<div class="small">'+(j.msg||'Gagal memuat promo')+'</div>'; return; } const items=Array.isArray(j.items)?j.items:[]; if(!items.length){ if(wrap) wrap.innerHTML='<div class="small">Belum ada kode promo.</div>'; return; } if(wrap) wrap.innerHTML=items.map(it=>{ const rawType=String(it.type||'active30_once'); const type=(rawType==='fixed'?'active30_once':rawType); const isReusable=type==='free3d_multi'; const used=(Number(it.used_count||0)>0) && !isReusable; const typeText=(type==='active30_once') ? 'Aktif 30 hari · 1x digunakan' : (type==='free3d_once') ? 'Aktif 3 hari tanpa QRIS · 1x digunakan' : (type==='free3d_multi' ? 'Aktif 3 hari tanpa QRIS · berkali-kali' : ('Aktif '+String(it.value||0)+' hari')); const statusText=isReusable ? '<span style="color:#16a34a;font-weight:900">Belum dipakai</span> <span style="color:#6b7280">(bisa berkali-kali)</span>' : (used ? '<span style="color:#dc2626;font-weight:900">Sudah dipakai</span>' : '<span style="color:#16a34a;font-weight:900">Belum dipakai</span>'); return `<div style="border:1px solid #dbeafe;border-radius:5px;padding:12px;margin-bottom:8px;background:#faf5ff"><div style="display:flex;justify-content:space-between;gap:8px;align-items:center"><div><div style="font-weight:900;color:#1e40af">${it.code}</div><div class="small">${typeText} · ${statusText}</div></div><button class="btn-mini danger" onclick="deletePromoAdmin('${it.code}')">Hapus</button></div></div>`; }).join(''); if(msg) msg.textContent=''; }catch(e){ if(wrap) wrap.innerHTML='<div class="small">Koneksi gagal</div>'; } }
async function deletePromoAdmin(code){ const msg=document.getElementById('promoAdminMsg'); if(msg) msg.textContent='Menghapus promo...'; try{ const res=await fetch('?api=admin_promo_delete',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({code})}); const j=await res.json(); if(!j.ok){ if(msg) msg.textContent=j.msg||'Gagal menghapus promo'; return; } if(msg) msg.textContent='Promo dihapus'; onPromoAdminTypeChange(); loadPromoAdminList(); }catch(e){ if(msg) msg.textContent='Koneksi gagal'; } }
JS
      );
      break;


    case 'm604-server-guard':
      js_out(<<<'JS'
(function(){
  'use strict';
  if(window.__M604_SERVER_GUARD__) return;
  window.__M604_SERVER_GUARD__ = true;

  const script = document.currentScript;
  const cfg = (script && script.dataset) ? script.dataset : {};
  const API = cfg.apiUrl || location.pathname || 'index.php';
  const STORE = String(cfg.storeId || '').toUpperCase();
  const IS_DEVELOPER = String(cfg.isDeveloper || '').toLowerCase() === 'true';
  const PROTECT_USER = STORE === 'M604' && !IS_DEVELOPER;
  const INITIAL_LOCKED = String(cfg.serverLocked || '').toLowerCase() === 'true';
  let state = {enabled:INITIAL_LOCKED,code:'',message:'',updatedAt:null};
  let busy = false;
  let selectedStore = '';
  let pollTimer = null;

  function esc(value){
    return String(value == null ? '' : value).replace(/[&<>"']/g, function(ch){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch] || ch;
    });
  }
  function openLockedPage(){
    if(!PROTECT_USER || !state.enabled) return false;
    const sep = API.indexOf('?') >= 0 ? '&' : '?';
    location.href = API + sep + 'm604_server_locked=1&_=' + Date.now();
    return true;
  }
  function renderUserLock(){
    // Jangan tampilkan popup/overlay saat login atau ketika berada di beranda.
    // Status hanya dipakai untuk mengalihkan klik menu ke halaman server.
    const existing = document.getElementById('m604ServerLockOverlay');
    if(existing) existing.remove();
    document.documentElement.classList.remove('m604-server-is-locked');
    document.body && document.body.classList.remove('m604-server-is-locked');
  }
  function interceptMenuClick(event){
    if(!PROTECT_USER || !state.enabled) return;
    const target = event.target && event.target.closest ? event.target.closest(
      '.pari-menu-card,.onhand-choice,#lainnyaPopup .opt,#reportPopup .opt,#stockPopup .opt,#clerekModal .btn:not(.danger),#reportDatePopup .btn:not(.danger)'
    ) : null;
    if(!target) return;
    event.preventDefault();
    event.stopPropagation();
    if(typeof event.stopImmediatePropagation === 'function') event.stopImmediatePropagation();
    openLockedPage();
  }
  function hookFeatureNavigation(){
    if(typeof window.setIframeUrl === 'function' && !window.setIframeUrl.__m604LockWrapped){
      const originalUrl = window.setIframeUrl;
      const wrappedUrl = function(){
        if(openLockedPage()) return false;
        return originalUrl.apply(this, arguments);
      };
      wrappedUrl.__m604LockWrapped = true;
      window.setIframeUrl = wrappedUrl;
    }
    if(typeof window.setIframeHtml === 'function' && !window.setIframeHtml.__m604LockWrapped){
      const originalHtml = window.setIframeHtml;
      const wrappedHtml = function(){
        if(openLockedPage()) return false;
        return originalHtml.apply(this, arguments);
      };
      wrappedHtml.__m604LockWrapped = true;
      window.setIframeHtml = wrappedHtml;
    }
  }
  function syncAdminSwitch(){
    const wrap = document.getElementById('m604ServerSwitchWrap');
    const input = document.getElementById('m604ServerSwitch');
    const label = document.getElementById('m604ServerSwitchStatus');
    if(!wrap || !input || !label) return;
    const show = IS_DEVELOPER && selectedStore === 'M604';
    wrap.style.display = show ? 'flex' : 'none';
    if(!show) return;
    input.checked = !!state.enabled;
    input.disabled = !!busy;
    wrap.classList.toggle('is-on', !!state.enabled);
    wrap.classList.toggle('is-busy', !!busy);
    label.textContent = busy
      ? 'Menyimpan status...'
      : (state.enabled ? ('KANAN · Server bermasalah · ' + (state.code || '')) : 'KIRI · Server normal, semua menu bisa diakses');
  }
  function applyState(data){
    if(!data || typeof data !== 'object') return;
    state = {
      enabled: !!data.enabled,
      code: String(data.code || ''),
      message: String(data.message || ''),
      updatedAt: data.updatedAt || null
    };
    renderUserLock();
    syncAdminSwitch();
  }
  async function loadStatus(){
    try{
      const res = await fetch(API + '?api=m604_server_status&_=' + Date.now(), {
        cache:'no-store',
        credentials:'same-origin',
        headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}
      });
      const data = await res.json();
      if(data && data.ok) applyState(data);
    }catch(e){}
  }
  window.m604ServerStatusToggle = async function(input){
    if(!IS_DEVELOPER || !input || busy) return;
    const next = !!input.checked;
    busy = true;
    syncAdminSwitch();
    try{
      const res = await fetch(API + '?api=admin_m604_server_status_set', {
        method:'POST',
        cache:'no-store',
        credentials:'same-origin',
        headers:{'Content-Type':'application/json','Accept':'application/json','X-Requested-With':'XMLHttpRequest'},
        body:JSON.stringify({enabled:next})
      });
      const data = await res.json();
      if(!res.ok || !data || !data.ok) throw new Error((data && data.msg) || 'Gagal menyimpan status server');
      applyState(data);
      try{
        if('BroadcastChannel' in window){
          const channel = new BroadcastChannel('cibili-m604-server-status');
          channel.postMessage({enabled:!!data.enabled,ts:Date.now()});
          channel.close();
        }
      }catch(e){}
    }catch(e){
      input.checked = !next;
      alert(e.message || 'Koneksi gagal');
      await loadStatus();
    }finally{
      busy = false;
      syncAdminSwitch();
    }
  };
  window.m604ServerStatusSyncForStore = function(storeId){
    selectedStore = String(storeId || '').trim().toUpperCase();
    syncAdminSwitch();
    if(selectedStore === 'M604') loadStatus();
  };
  function hookAdminAction(){
    if(typeof window.openAdminActionModal === 'function' && !window.openAdminActionModal.__m604ServerWrapped){
      const original = window.openAdminActionModal;
      const wrapped = function(storeId){
        const result = original.apply(this, arguments);
        window.m604ServerStatusSyncForStore(storeId);
        return result;
      };
      wrapped.__m604ServerWrapped = true;
      window.openAdminActionModal = wrapped;
    }
    if(typeof window.closeAdminActionModal === 'function' && !window.closeAdminActionModal.__m604ServerWrapped){
      const originalClose = window.closeAdminActionModal;
      const wrappedClose = function(){
        selectedStore = '';
        syncAdminSwitch();
        return originalClose.apply(this, arguments);
      };
      wrappedClose.__m604ServerWrapped = true;
      window.closeAdminActionModal = wrappedClose;
    }
  }
  function start(){
    hookAdminAction();
    hookFeatureNavigation();
    document.addEventListener('click', interceptMenuClick, true);
    loadStatus();
    if(pollTimer) clearInterval(pollTimer);
    pollTimer = setInterval(function(){
      hookAdminAction();
      hookFeatureNavigation();
      if(PROTECT_USER || (IS_DEVELOPER && selectedStore === 'M604')) loadStatus();
    }, PROTECT_USER ? 1200 : 2200);
  }
  try{
    if('BroadcastChannel' in window){
      const channel = new BroadcastChannel('cibili-m604-server-status');
      channel.onmessage = function(){ loadStatus(); };
    }
  }catch(e){}
  document.addEventListener('visibilitychange', function(){ if(!document.hidden) loadStatus(); });
  window.addEventListener('focus', loadStatus);
  if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
JS
      );
      break;

    case 'main-app':
      js_out(<<<'JS'

/* =========
   STATE
========= */
const __SCRIPT_CFG = (document.currentScript && document.currentScript.dataset) ? document.currentScript.dataset : {};
let STORE_ID = __SCRIPT_CFG.storeId || '';
let IS_ADMIN = String(__SCRIPT_CFG.isAdmin || '').toLowerCase() === 'true';
let IS_DEVELOPER = String(__SCRIPT_CFG.isDeveloper || '').toLowerCase() === 'true';
let IS_ADMIN2 = String(__SCRIPT_CFG.isAdmin2 || '').toLowerCase() === 'true';
let IS_IMPERSONATING = String(__SCRIPT_CFG.isImpersonating || '').toLowerCase() === 'true';
let IMPERSONATION_ADMIN = __SCRIPT_CFG.impersonationAdmin || '';
let ADMIN_RELOAD_BUSY = false;
let ADMIN_RELOAD_RETRY_TIMER = null;
let ADMIN_RELOAD_LAST_OK = 0;
let EXPIRY_TS = parseInt(__SCRIPT_CFG.expiryTs || '0', 10) || 0; // epoch seconds (akhir hari)
let IS_PREMIUM = String(__SCRIPT_CFG.isPremium || '').toLowerCase() === 'true';

// Base endpoint file (tanpa expose link API eksternal di source)
const API_URL = __SCRIPT_CFG.apiUrl || 'index.php';
const RENEW_PAGE_URL = 'index.php';
const HELP_WA_NUMBER = '6288294254279';
const HELP_WA_ADMIN2_NUMBER = '6285707557752';
function openHelpModal(){ if(IS_ADMIN2 && typeof openAdmin2AddUserModal === 'function'){ openAdmin2AddUserModal(); return; } showHelpChoiceModal(); }
function closeHelpModal(){ const modal=document.getElementById('helpModal'); if(modal){ modal.style.display='none'; modal.classList.remove('show'); } closeHelpChoiceModal(); }
function showHelpChoiceModal(){ let modal=document.getElementById('helpChoiceModal') || document.getElementById('helpModal'); if(!modal){ modal=document.createElement('div'); modal.id='helpChoiceModal'; modal.className='modal'; modal.innerHTML=`<div class="modal-box help-modal-box"><button type="button" class="close-x" onclick="closeHelpChoiceModal()" aria-label="Tutup">×</button><h3>Bantuan WhatsApp</h3><p>Pilih admin yang ingin dihubungi.</p><div class="help-actions"><button type="button" class="btn btn-primary" onclick="openHelpAdmin('admin1')">Admin1</button><button type="button" class="btn btn-primary" onclick="openHelpAdmin('admin2')">Admin2</button></div></div>`; document.body.appendChild(modal); } modal.style.display='flex'; modal.classList.add('show'); }
function closeHelpChoiceModal(){ const modal=document.getElementById('helpChoiceModal'); if(modal){ modal.style.display='none'; modal.classList.remove('show'); } }
function openHelpAdmin(which){ const n=(which==='admin2')?HELP_WA_ADMIN2_NUMBER:HELP_WA_NUMBER; const text='Halo admin, saya butuh bantuan.'; window.open('https://wa.me/'+n+'?text='+encodeURIComponent(text),'_blank'); closeHelpModal(); }
let _expiryWarningShown = false;

// Helper untuk membuka report PRD via redirect server-side (lihat proxy.php api=go_prd)
function prd(path, params={}){
  const qs = new URLSearchParams({ api: 'go_prd', path: String(path||'') });
  for(const [k,v] of Object.entries(params||{})) qs.set(k, String(v));
  return `${API_URL}?${qs.toString()}`;
}

// UX: label Clerek jika belum premium
window.addEventListener('DOMContentLoaded', ()=>{
  try{
    const fab=document.getElementById('helpFab');
    if(fab && IS_ADMIN2){
      fab.classList.add('admin2-add-user');
      fab.innerHTML='Add User';
      fab.setAttribute('aria-label','Add User');
      fab.style.fontSize='11px';
      fab.style.fontWeight='1000';
      fab.style.letterSpacing='.2px';
    }
    if(!IS_PREMIUM){
      const btns = Array.from(document.querySelectorAll('button.btn'));
      const cl = btns.find(b => (b.textContent||'').toLowerCase().includes('clerek'));
      if(cl) cl.innerHTML = '🔒 Clerek';
    }
  }catch(e){}
});

const contentFrame = document.getElementById("contentFrame");
const placeholder  = document.getElementById("placeholder");
const loading      = document.getElementById("loading");
const iframeWrap   = document.getElementById("iframeWrap");
const frameCard    = document.getElementById("frameCard");
const btnFull      = document.getElementById("btnFull");
const btnBack      = document.getElementById("btnBack");
let zoomLevel = 1;
let ADMIN_STORES = [];
let ADMIN_EXPIRY = {}; // {storeId: epochSeconds}
let ADMIN_PIN = {}; // {storeId: '1234'}
let ADMIN_PREMIUM = {};
let ADMIN_POINT = {};
let ADMIN_CREATED = {};
function adminNormalizeNumberMap(map){
  const out = {};
  Object.keys(map || {}).forEach(k=>{
    const key = String(k || "").trim().toUpperCase();
    if(key) out[key] = Number(map[k] || 0);
  });
  return out;
}
function adminNormalizeBoolMap(map){
  const out = {};
  Object.keys(map || {}).forEach(k=>{
    const key = String(k || "").trim().toUpperCase();
    if(key) out[key] = !!map[k];
  });
  return out;
}
let ADMIN_ADMIN2 = {};
let ADMIN_STORE_NAMES = {};
let ADMIN_STORE_NAME_LOADING = {};
let ADMIN_ACTION_STORE_ID = "";
let ADMIN_PRESENCE = {};
let ADMIN_SERVER_TS = 0;
let ADMIN_AUTO_REFRESH_TIMER = null;
let PRESENCE_PING_TIMER = null;
let lastFrameUrl = '';
let lastFrameHtml = '';
let lastFrameWithZoom = true;
const CLEREK_CACHE_TTL_MS = 60 * 60 * 1000;
let LAST_CLEREK_PAYLOAD = null;
function clerekCacheKey(){
  const sid = String(STORE_ID || '').trim().toUpperCase().replace(/[^A-Z0-9]/g,'') || 'UNKNOWN';
  // Versi cache dinaikkan agar hasil lama yang masih memasangkan tabel
  // berdasarkan rowid/nama tidak digunakan setelah pencocokan nomor member.
  return `CLEREK_CACHE_V4_${sid}`;
}
function clearClerekCache(){
  LAST_CLEREK_PAYLOAD = null;
  try{ localStorage.removeItem(clerekCacheKey()); }catch(e){}
  updateClerekSavedButtonVisibility();
}
function saveClerekCache(payload){
  const data = Object.assign({}, payload || {}, { cachedAt: Date.now(), ttlMs: CLEREK_CACHE_TTL_MS });
  LAST_CLEREK_PAYLOAD = data;
  try{
    localStorage.setItem(clerekCacheKey(), JSON.stringify(data));
  }catch(e){}
  updateClerekSavedButtonVisibility();
}
function loadClerekCache(){
  try{
    const raw = localStorage.getItem(clerekCacheKey());
    if(!raw){
      const memoryCachedAt = Number(LAST_CLEREK_PAYLOAD?.cachedAt || 0);
      if(memoryCachedAt && (Date.now() - memoryCachedAt) <= CLEREK_CACHE_TTL_MS) return LAST_CLEREK_PAYLOAD;
      return null;
    }
    const data = JSON.parse(raw);
    const cachedAt = Number(data?.cachedAt || 0);
    const ttlMs = Number(data?.ttlMs || CLEREK_CACHE_TTL_MS);
    if(!cachedAt || (Date.now() - cachedAt) > ttlMs){
      clearClerekCache();
      return null;
    }
    LAST_CLEREK_PAYLOAD = data;
    return data;
  }catch(e){
    clearClerekCache();
    return null;
  }
}
function formatDateTimeID(ts){
  try{
    return new Date(ts).toLocaleString('id-ID', { year:'numeric', month:'2-digit', day:'2-digit', hour:'2-digit', minute:'2-digit', second:'2-digit' });
  }catch(e){ return '-'; }
}
function updateClerekSavedButtonVisibility(){
  const btn = document.getElementById('clerekSavedBtn');
  if(!btn) return;
  btn.style.display = loadClerekCache() ? "block" : "none";
}
function updateClerekCacheInfo(){
  const box = document.getElementById('clerekCacheInfo');
  if(!box) return;
  const cache = loadClerekCache();
  if(cache){
    const expireAt = Number(cache.cachedAt || 0) + CLEREK_CACHE_TTL_MS;
    box.textContent = `Data Clerek terakhir tersimpan sampai ${formatDateTimeID(expireAt)}.`;
  }else{
    box.textContent = 'Data Clerek akan tersimpan 1 jam setelah berhasil diproses.';
  }
  updateClerekSavedButtonVisibility();
}
function buildClerekResultHtml(payload){
  const rows = Array.isArray(payload?.rows) ? payload.rows : [];
  const selectedDate = String(payload?.selectedDate || '').trim();
  const displayDate = selectedDate ? selectedDate.split('-').reverse().join('/') : todayISO().split('-').reverse().join('/');
  const safe = (value)=> String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
  const cards = rows.map((item)=>{
    const cashierName = safe(item?.cashierName || 'Nama kasir tidak ditemukan');
    const nik = safe(item?.nik || '-');
    const total = Number(item?.total || 0);
    return `<article class="cashier-card">
      <div class="info-table">
        <div class="info-row"><div class="label">Tanggal</div><div class="value">${safe(displayDate)}</div></div>
        <div class="info-row"><div class="label">Nama Kasir</div><div class="value cashier-name">${cashierName}</div></div>
        <div class="info-row"><div class="label">NIK Kasir</div><div class="value">${nik}</div></div>
      </div>
      <section class="total-card">
        <div class="total-label">TOTAL CLEREK</div>
        <div class="total-value">${total.toLocaleString('id-ID')}</div>
      </section>
    </article>`;
  }).join('') || '<div class="empty-card">Data Clerek tidak ditemukan.</div>';
  return `<!doctype html><html lang="id"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
      :root{--blue:#2563eb;--blue-dark:#1d4ed8;--blue-soft:#eff6ff;--bg:#f5f8ff;--border:#bfdbfe;--text:#0f172a;--muted:#475569;--green:#16a34a;--green-soft:#ecfdf5}
      *{box-sizing:border-box;font-weight:800}body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;margin:0;padding:14px;background:var(--bg);color:var(--text);font-weight:800}
      .page{width:100%;max-width:760px;margin:0 auto}.title-card{display:flex;align-items:center;gap:12px;padding:15px 16px;margin-bottom:14px;background:#fff;border:1px solid var(--border);border-radius:14px;box-shadow:0 8px 24px rgba(37,99,235,.10)}
      .title-icon{width:44px;height:44px;display:grid;place-items:center;flex:0 0 44px;border-radius:11px;background:linear-gradient(135deg,var(--blue),var(--blue-dark));color:#fff;font-size:20px;font-weight:1000}.title-text h3{margin:0;color:var(--blue-dark);font-size:19px;font-weight:1000}.title-text p{margin:3px 0 0;color:var(--muted);font-size:12px;font-weight:900}
      .cashier-card{margin-bottom:14px}.info-table{overflow:hidden;background:#fff;border:1px solid var(--border);border-radius:13px;box-shadow:0 9px 25px rgba(37,99,235,.09)}.info-row{display:grid;grid-template-columns:42% 58%;min-height:48px;border-bottom:1px solid #dbeafe}.info-row:last-child{border-bottom:0}.label,.value{display:flex;align-items:center;padding:12px 14px;font-size:13px;font-weight:1000}.label{background:var(--blue-soft);color:var(--blue-dark);border-right:1px solid #dbeafe}.value{justify-content:flex-end;text-align:right;color:var(--text)}.cashier-name{color:var(--blue-dark)}
      .total-card{margin-top:10px;padding:17px 14px;background:#fff;border:2px solid #86efac;border-radius:13px;text-align:center;box-shadow:0 9px 24px rgba(22,163,74,.10)}.total-label{color:#166534;font-size:12px;letter-spacing:.7px;font-weight:1000}.total-value{margin-top:5px;color:var(--green);font-size:30px;line-height:1.15;font-weight:1000;word-break:break-word}
      .empty-card{padding:25px;text-align:center;background:#fff;border:1px solid var(--border);border-radius:13px;color:var(--muted);font-weight:1000}.action-wrap{margin-top:15px;display:grid;gap:10px}.member-btn{width:100%;display:flex;align-items:center;justify-content:center;gap:10px;border:0;border-radius:12px;background:linear-gradient(135deg,var(--blue),var(--blue-dark));color:#fff;padding:14px 16px;font-size:14px;font-weight:1000;cursor:pointer;box-shadow:0 10px 22px rgba(37,99,235,.24)}.member-btn.receipt-btn{background:linear-gradient(135deg,#0f766e,#0d9488);box-shadow:0 10px 22px rgba(13,148,136,.22)}.member-btn:active{transform:scale(.99)}.member-icon{width:28px;height:28px;display:grid;place-items:center;border-radius:8px;background:rgba(255,255,255,.2);font-size:15px;font-weight:1000}
      @media(max-width:480px){body{padding:11px}.title-card{padding:13px}.label,.value{padding:11px 12px;font-size:12px}.info-row{grid-template-columns:40% 60%}.total-value{font-size:28px}}
    </style></head><body><main class="page">
      <section class="title-card"><div class="title-icon">C</div><div class="title-text"><h3>Total Clerek</h3><p>Ringkasan hasil kasir</p></div></section>
      ${cards}
      <div class="action-wrap">
        <button type="button" class="member-btn" onclick="if(window.parent&&typeof window.parent.showClerekMemberView==='function'){window.parent.showClerekMemberView();}"><span class="member-icon">👤</span><span>LIHAT NOMOR &amp; NAMA MEMBER</span></button>
        <button type="button" class="member-btn receipt-btn" onclick="if(window.parent&&typeof window.parent.showClerekReceiptView==='function'){window.parent.showClerekReceiptView();}"><span class="member-icon">🧾</span><span>LIHAT STRUK</span></button>
      </div>
    </main></body></html>`;
}
function buildClerekMemberHtml(payload){
  const fileName = String(payload?.fileName || '');
  const cleanMemberValue = (value)=>{ const text=String(value ?? '').trim(); return (!text || /^[-–—]+$/.test(text) || /^(?:null|undefined)$/i.test(text)) ? '' : text; };
  const memberRows = (Array.isArray(payload?.memberRows) ? payload.memberRows : [])
    .map(item=>({
      // Nomor tabel selalu memakai rowid asli tx_usi. Nama dan telepon sudah
      // dipasangkan lewat nomor member transaksi, bukan lewat urutan tabel.
      rowid: cleanMemberValue(item?.rowid),
      memberName: cleanMemberValue(item?.memberName),
      phone: cleanMemberValue(item?.phone)
    }))
    .filter(item=>item.rowid && item.memberName && item.phone);
  const safe = (value)=> String(value ?? '').replace(/[&<>"']/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[s]));
  const safeFile = safe(fileName);
  const rowsHtml = memberRows.map((item)=>{
    const rowid = safe(item.rowid);
    const memberName = safe(item.memberName);
    const phone = safe(item.phone);
    return `<tr><td class="number-cell">${rowid}</td><td>${memberName}</td><td class="phone-cell">${phone}</td></tr>`;
  }).join('') || `
    <tr>
      <td colspan="3" class="empty-cell">
        Data member lengkap tidak ditemukan. Pastikan DB memiliki <b>tx_usi.no_member/member_name</b> dan <b>tx_tsale.cust_id/phone</b>.
      </td>
    </tr>`;

  return `
    <!doctype html><html lang="id"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
      :root{--blue:#2563eb;--blue-dark:#1d4ed8;--blue-soft:#eff6ff;--bg:#f4f7ff;--border:#dbeafe;--text:#0f172a;--muted:#64748b}
      *{box-sizing:border-box}
      body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;margin:0;padding:16px;background:var(--bg);color:var(--text)}
      .topbar{display:flex;align-items:center;gap:10px;margin-bottom:12px}
      .back-btn{display:inline-flex;align-items:center;justify-content:center;min-width:42px;height:40px;border:1px solid #bfdbfe;border-radius:8px;background:#fff;color:var(--blue-dark);font-size:20px;font-weight:900;cursor:pointer}
      h3{margin:0;color:var(--blue-dark);font-size:19px}
      .summary{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:center;background:#fff;border:1px solid var(--border);border-radius:8px;padding:12px 14px;margin-bottom:12px;box-shadow:0 10px 25px rgba(37,99,235,.08)}
      .summary-label{font-size:11px;color:var(--muted);font-weight:900;letter-spacing:.45px;text-transform:uppercase}
      .summary-value{margin-top:3px;font-size:13px;font-weight:900;word-break:break-word}
      .count{min-width:66px;padding:9px 12px;border-radius:8px;background:var(--blue-soft);color:var(--blue-dark);text-align:center;font-size:12px;font-weight:900}
      .table-wrap{overflow-x:auto;border:1px solid var(--border);border-radius:8px;background:#fff;box-shadow:0 10px 25px rgba(37,99,235,.08)}
      table{width:100%;border-collapse:collapse;table-layout:fixed}
      th,td{padding:12px 11px;border-bottom:1px solid #eaf1ff;text-align:left;font-size:13px;font-weight:800}
      th{position:sticky;top:0;background:linear-gradient(135deg,var(--blue),var(--blue-dark));color:#fff;font-size:12px;letter-spacing:.2px}
      tbody tr:nth-child(even) td{background:#f8fbff}
      tbody tr:last-child td{border-bottom:0}
      .number-cell{width:62px;text-align:center}
      .phone-cell{width:38%;white-space:nowrap;font-variant-numeric:tabular-nums}
      .empty-cell{text-align:center;color:var(--muted);padding:28px 16px;line-height:1.55}
      @media(max-width:560px){
        body{padding:12px}
        h3{font-size:17px}
        .summary{grid-template-columns:1fr}
        .count{justify-self:start}
        th,td{padding:10px 9px;font-size:12px}
      }
    </style></head><body>
      <div class="topbar">
        <button type="button" class="back-btn" aria-label="Kembali ke Hasil Clerek" onclick="if(window.parent&&typeof window.parent.showSavedClerekResult==='function'){window.parent.showSavedClerekResult();}">‹</button>
        <h3>No &amp; Name Member</h3>
      </div>
      <div class="summary">
        <div>
          <div class="summary-label">File DB Clerek</div>
          <div class="summary-value">${safeFile}</div>
        </div>
        <div class="count">${memberRows.length.toLocaleString('id-ID')} Data</div>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th class="number-cell">NO</th><th>MEMBER_NAME</th><th class="phone-cell">PHONE</th></tr>
          </thead>
          <tbody>${rowsHtml}</tbody>
        </table>
      </div>
    </body></html>`;
}
function showClerekMemberView(){
  const cache = loadClerekCache();
  if(!cache){
    alert("Data Clerek tersimpan tidak ditemukan. Silakan unggah ulang file ZIP.");
    return false;
  }
  setIframeHtml(buildClerekMemberHtml(cache), true);
  return true;
}
function clerekReceiptEscape(value){
  return String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
}
function clerekReceiptText(value){
  let normalized = String(value ?? '')
    .replace(/\\r\\n|\\n|\\r/gi,'\n')
    .replace(/<br\s*\/?\s*>/gi,'\n')
    .replace(/&nbsp;/gi,' ')
    .replace(/\u00a0/g,' ')
    .replace(/\r\n?/g,'\n')
    /*
     * PENTING: log_receipt_prn menyimpan pergantian baris printer dengan
     * karakter "|" (contoh ZIP Android M604). Versi lama membiarkan "|"
     * tetap di satu baris sehingga seluruh isi struk menyambung dan kolom
     * qty/harga/nominal terlihat berantakan.
     */
    .replace(/\|/g,'\n')
    // <b2> adalah marker format printer, bukan teks yang harus tampil.
    .replace(/<b2>/gi,'')
    // Buang karakter kontrol printer/ESC-POS yang dapat menggeser kolom struk.
    .replace(/\x1B(?:\[[0-?]*[ -\/]*[@-~]|[@-_])/g,'')
    .replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/g,'');
  const lines = normalized.split('\n').map(line=>{
    let col = 0;
    let out = '';
    for(const ch of String(line)){
      if(ch === '\t'){
        const add = 4 - (col % 4);
        out += ' '.repeat(add);
        col += add;
      }else{
        out += ch;
        col += 1;
      }
    }
    // Pertahankan spasi di kiri karena merupakan posisi kolom asli printer.
    return out.replace(/\s+$/,'');
  });
  while(lines.length && !String(lines[0]).trim()) lines.shift();
  while(lines.length && !String(lines[lines.length-1]).trim()) lines.pop();
  return lines.join('\n');
}
function clerekReceiptFormat(value){
  const source = clerekReceiptText(value);
  if(!source) return {text:'', cols:46};
  const rawLines = source.split('\n');
  const visibleLen = text => Array.from(String(text ?? '')).length;
  const trimLine = text => String(text ?? '').replace(/^\s+|\s+$/g,'');
  const ruleIndexes = [];
  const ruleLengths = [];
  rawLines.forEach((line,idx)=>{
    const t = trimLine(line);
    if(/^[=]{8,}$/.test(t) || /^[-]{8,}$/.test(t) || /^\+[-]{7,}$/.test(t)){
      ruleIndexes.push(idx);
      ruleLengths.push(visibleLen(t));
    }
  });
  const rawMax = rawLines.reduce((m,line)=>Math.max(m,visibleLen(String(line).replace(/\s+$/,''))),0);
  // Printer kasir umumnya 40/42/46/48 kolom. Ikuti lebar garis asli bila tersedia,
  // lalu batasi agar tetap proporsional dan seluruh struk bisa di-fit ke kartu preview.
  let cols = ruleLengths.length ? Math.max(...ruleLengths) : rawMax;
  cols = Math.max(40, Math.min(52, cols || 46));

  const padCenter = text => {
    text = trimLine(text);
    const len = visibleLen(text);
    if(len >= cols) return text;
    return ' '.repeat(Math.floor((cols-len)/2)) + text;
  };
  const wrapWords = text => {
    text = trimLine(text);
    if(visibleLen(text) <= cols) return [text];
    const words = text.split(/\s+/).filter(Boolean);
    const out=[]; let current='';
    words.forEach(word=>{
      if(!current){ current=word; return; }
      if(visibleLen(current+' '+word) <= cols) current += ' '+word;
      else{ out.push(current); current=word; }
    });
    if(current) out.push(current);
    return out.length ? out : [text];
  };
  const alignLastNumber = line => {
    let t = String(line ?? '').replace(/\s+$/,'');
    const m = t.match(/^(.*\S)\s+(-?\d[\d.,]*)$/);
    if(!m) return t;
    const left = m[1];
    const amount = m[2];
    const leftLen = visibleLen(left), amountLen = visibleLen(amount);
    if(leftLen + 1 + amountLen > cols) return t;
    return left + ' '.repeat(cols-leftLen-amountLen) + amount;
  };

  const firstRule = ruleIndexes.length ? ruleIndexes[0] : -1;
  const lastEqRule = rawLines.reduce((last,line,idx)=>/^[=]{8,}$/.test(trimLine(line)) ? idx : last,-1);
  const formatted=[];
  rawLines.forEach((line,idx)=>{
    const t = trimLine(line);
    if(!t){ formatted.push(''); return; }
    if(/^[=]{8,}$/.test(t)){ formatted.push('='.repeat(cols)); return; }
    if(/^[-]{8,}$/.test(t)){ formatted.push('-'.repeat(cols)); return; }
    if(/^\+[-]{7,}$/.test(t)){ formatted.push('+'+'-'.repeat(Math.max(1,cols-1))); return; }

    // Header toko dan footer dibuat simetris seperti hasil cetak kertas kasir.
    if((firstRule >= 0 && idx < firstRule) || (lastEqRule >= 0 && idx > lastEqRule)){
      wrapWords(t).forEach(part=>formatted.push(padCenter(part)));
      return;
    }
    if(/^Bon\b/i.test(t) && /Kasir\s*:/i.test(t)){
      formatted.push(padCenter(t));
      return;
    }
    // Nominal paling kanan selalu berada pada kolom kanan; spasi di bagian tengah
    // (qty/harga satuan/DPP/PPN) tetap dipertahankan.
    formatted.push(alignLastNumber(String(line).replace(/^\s+/,'')));
  });
  while(formatted.length && !formatted[0].trim()) formatted.shift();
  while(formatted.length && !formatted[formatted.length-1].trim()) formatted.pop();
  return {text:formatted.join('\n'), cols};
}
function buildClerekReceiptUploadHtml(message=''){
  const safeMsg = clerekReceiptEscape(message || 'Data struk belum tersedia dari file ZIP yang diproses.');
  return `<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    *{box-sizing:border-box}html,body{margin:0;min-height:100%;background:#f7f9fc;color:#0f172a;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial}.page{max-width:760px;margin:0 auto;padding:18px 14px 30px}.box{background:#fff;border:1px solid #dbe5f1;border-radius:18px;padding:20px;box-shadow:0 8px 24px rgba(15,23,42,.07);text-align:center}.ico{width:54px;height:54px;margin:0 auto 12px;border-radius:16px;display:grid;place-items:center;background:#eaf2ff;color:#2563eb;font-size:27px}.box h3{margin:0 0 8px;font-size:20px}.msg{color:#475569;font-size:13px;line-height:1.55;font-weight:700}.hint{margin-top:12px;padding:11px 12px;border-radius:12px;background:#eff6ff;color:#1d4ed8;font-size:12px;font-weight:800}.back{width:100%;margin-top:14px;border:0;border-radius:10px;padding:13px;background:#2563eb;color:#fff;font-weight:900;cursor:pointer}
  </style></head><body><main class="page"><section class="box"><div class="ico">🧾</div><h3>Lihat Struk</h3><div class="msg">${safeMsg}</div><div class="hint">Struk memakai ZIP yang sama dari menu <b>Upload File Clerek</b>. Di halaman Lihat Struk tidak ada upload file kedua.</div><button class="back" onclick="window.parent.showSavedClerekResult()">KEMBALI KE CLEREK</button></section></main></body></html>`;
}
function clerekReceiptDateLabel(value){
  const raw = String(value ?? '').trim();
  if(!raw) return '-';
  let m = raw.match(/(20\d{2})[-\/]([01]?\d)[-\/]([0-3]?\d)/);
  if(m) return `${String(m[3]).padStart(2,'0')}/${String(m[2]).padStart(2,'0')}/${m[1]}`;
  m = raw.match(/([0-3]?\d)[-\/]([01]?\d)[-\/](20\d{2})/);
  if(m) return `${String(m[1]).padStart(2,'0')}/${String(m[2]).padStart(2,'0')}/${m[3]}`;
  return raw.split(/[ T]/)[0] || raw;
}
function clerekReceiptBillLabel(value, index){
  const raw = String(value ?? '').trim();
  const digits = (raw.match(/\d+/g) || []).join('');
  if(digits) return digits.slice(-4).padStart(4,'0');
  return String(index + 1).padStart(4,'0');
}
function buildClerekReceiptHtml(data){
  const sourceReceipts = Array.isArray(data?.receipts) ? data.receipts.slice() : [];
  sourceReceipts.sort((a,b)=>{
    const ad = String(a?.date_tx ?? ''), bd = String(b?.date_tx ?? '');
    if(ad !== bd) return ad.localeCompare(bd, undefined, {numeric:true});
    return String(a?.bill_no ?? '').localeCompare(String(b?.bill_no ?? ''), undefined, {numeric:true});
  });
  const receiptData = sourceReceipts.map((r,i)=>{
    const textParts = [r?.header,r?.body1,r?.body2,r?.body3,r?.addtl1,r?.addtl2,r?.addtl3,r?.footer].map(clerekReceiptText).filter(Boolean);
    const formattedReceipt = clerekReceiptFormat(textParts.join('\n\n'));
    const cash = Number(r?.cash || 0), change = Number(r?.change_pay || 0);
    return {
      bill: clerekReceiptBillLabel(r?.bill_no, i),
      rawBill: String(r?.bill_no ?? ''),
      date: clerekReceiptDateLabel(r?.date_tx),
      setoran: cash - change,
      cols: Number(formattedReceipt.cols || 46),
      text: formattedReceipt.text || 'Isi struk tidak tersedia.'
    };
  });
  const listHtml = receiptData.map((r,i)=>`<button type="button" class="bon-row ${i%2===1?'stripe':''}" data-receipt-index="${i}"><strong>Bon ${clerekReceiptEscape(r.bill)}</strong><span class="date">${clerekReceiptEscape(r.date)}</span><span class="deposit">Setoran : Rp ${Number(r.setoran||0).toLocaleString('id-ID')}</span></button>`).join('') || '<div class="empty">Data struk tidak ditemukan pada log_receipt_prn.</div>';
  const safeJson = JSON.stringify(receiptData).replace(/</g,'\\u003c').replace(/>/g,'\\u003e').replace(/&/g,'\\u0026');
  return `<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>
    *{box-sizing:border-box}html,body{margin:0;min-height:100%;background:#f7f9fc;color:#000;font-family:Arial,Helvetica,sans-serif}body{border-top:4px solid #2563eb}.page{width:100%;max-width:790px;margin:0 auto;padding:0 12px 30px}.bon-list{--bon-row-h:106px;width:100%;max-width:710px;margin:0 auto;background:#fff;border:1px solid #e3e8ef;border-top:0;border-radius:0 0 20px 20px;max-height:calc(var(--bon-row-h) * 5);overflow-x:hidden;overflow-y:auto;overscroll-behavior:contain;-webkit-overflow-scrolling:touch;scrollbar-gutter:stable;box-shadow:0 4px 15px rgba(15,23,42,.04)}.bon-list::-webkit-scrollbar{width:6px}.bon-list::-webkit-scrollbar-track{background:#eef2f7}.bon-list::-webkit-scrollbar-thumb{background:#8fb0e7;border-radius:999px}.bon-row{appearance:none;-webkit-appearance:none;width:100%;height:var(--bon-row-h);min-height:var(--bon-row-h);border:0;border-bottom:1px solid #e5e7eb;background:#fff;color:#000;display:flex;flex:0 0 var(--bon-row-h);flex-direction:column;align-items:center;justify-content:center;gap:4px;padding:12px 10px;cursor:pointer;text-align:center}.bon-row:last-child{border-bottom:0}.bon-row.stripe{background:#edf4ff}.bon-row.active{background:#dceaff}.bon-row[hidden]{display:none}.bon-row strong{font-size:22px;line-height:1.08;font-weight:800;color:#000}.bon-row .date{font-size:16px;color:#5f6670;line-height:1.2}.bon-row .deposit{font-size:16px;line-height:1.2;color:#000}.receipt-tools{width:100%;max-width:710px;margin:24px auto 0;display:grid;gap:10px}.search-wrap{position:relative}.bon-search{width:100%;height:44px;border:1px solid #cbd5e1;border-radius:11px;background:#fff;color:#000;padding:0 42px 0 14px;font-size:14px;font-weight:700;outline:none;box-shadow:0 3px 12px rgba(15,23,42,.04)}.bon-search::placeholder{color:#8b95a5}.bon-search:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.12)}.search-icon{position:absolute;right:14px;top:50%;transform:translateY(-50%);font-size:17px;color:#64748b;pointer-events:none}.download-receipt{width:100%;height:44px;border:0;border-radius:11px;background:#2563eb;color:#fff;font-size:14px;font-weight:900;cursor:pointer;box-shadow:0 8px 18px rgba(37,99,235,.20)}.download-receipt:active{transform:scale(.995)}.download-receipt:disabled{opacity:.55;cursor:not-allowed}.search-empty{display:none;text-align:center;padding:9px 10px 0;color:#64748b;font-size:12px;font-weight:800}.download-status{min-height:16px;text-align:center;color:#2563eb;font-size:11px;font-weight:800}.preview-title{text-align:center;color:#000;font-size:27px;font-weight:800;margin:24px 0 20px}.receipt-card{width:94%;max-width:690px;margin:0 auto;background:#fbfcfe;border:1px solid #dfe4ea;border-radius:22px;padding:29px 24px;box-shadow:0 3px 10px rgba(15,23,42,.025);overflow:hidden;display:flex;justify-content:center;align-items:flex-start}.receipt-paper{display:inline-block;width:max-content;min-width:0;max-width:none;margin:0;white-space:pre;overflow:visible;color:#000;direction:ltr;text-align:left;unicode-bidi:plaintext;tab-size:4;font-family:"Courier New",Courier,"Liberation Mono",ui-monospace,monospace;font-size:17px;font-weight:800;font-style:normal;font-variant-numeric:tabular-nums;font-variant-ligatures:none;line-height:1.30;letter-spacing:0;word-spacing:0;text-rendering:geometricPrecision;-webkit-font-smoothing:antialiased}.back{display:block;width:94%;max-width:690px;margin:18px auto 0;border:0;border-radius:10px;padding:12px 14px;background:#2563eb;color:#fff;font-size:13px;font-weight:900;cursor:pointer}.empty{padding:24px 12px;text-align:center;color:#64748b;font-weight:800;background:#fff}@media(max-width:560px){.page{padding-left:7px;padding-right:7px}.bon-list{--bon-row-h:84px;max-width:96%;border-radius:0 0 17px 17px}.bon-row{padding:9px 7px;gap:3px}.bon-row strong{font-size:18px}.bon-row .date,.bon-row .deposit{font-size:14px}.receipt-tools{max-width:94%;margin-top:18px;gap:8px}.bon-search,.download-receipt{height:41px;font-size:13px}.preview-title{font-size:23px;margin:21px 0 17px}.receipt-card{width:94%;border-radius:18px;padding:22px 10px}.receipt-paper{line-height:1.28}.back{width:94%;margin-top:16px}}@media(max-width:390px){.bon-list{--bon-row-h:80px}.bon-row strong{font-size:17px}.bon-row .date,.bon-row .deposit{font-size:13px}.receipt-card{padding-left:7px;padding-right:7px}.preview-title{font-size:22px}}
  </style></head><body><main class="page"><section class="bon-list" id="bonList">${listHtml}</section><section class="receipt-tools"><div class="search-wrap"><input id="bonSearch" class="bon-search" type="search" inputmode="search" autocomplete="off" placeholder="Cari nomor bon..."><span class="search-icon">⌕</span></div><button id="downloadReceipt" type="button" class="download-receipt">DOWNLOAD STRUK</button><div id="searchEmpty" class="search-empty">Nomor bon tidak ditemukan.</div><div id="downloadStatus" class="download-status"></div></section><h2 class="preview-title">Preview Struk</h2><section class="receipt-card"><pre id="receiptPreview" class="receipt-paper" data-cols="${receiptData.length?Number(receiptData[0].cols||46):46}">${receiptData.length?clerekReceiptEscape(receiptData[0].text):'Data struk tidak tersedia.'}</pre></section><button class="back" onclick="window.parent.showSavedClerekResult()">KEMBALI KE CLEREK</button></main><script>
    const RECEIPTS=${safeJson};
    let activeReceiptIndex=0;
    function fitReceiptPaper(){
      const pre=document.getElementById('receiptPreview');
      const card=pre && pre.closest('.receipt-card');
      if(!pre || !card) return;
      const baseSize=17;
      pre.style.fontSize=baseSize+'px';
      const cs=getComputedStyle(card);
      const available=Math.max(120,card.clientWidth-(parseFloat(cs.paddingLeft)||0)-(parseFloat(cs.paddingRight)||0)-2);
      const natural=Math.max(1,pre.scrollWidth);
      if(natural>available){
        const fitted=Math.max(7.5,Math.min(baseSize,baseSize*(available/natural)));
        pre.style.fontSize=fitted.toFixed(2)+'px';
      }
    }
    function showReceipt(index,scrollPreview){
      index=Number(index)||0;
      const row=RECEIPTS[index];
      if(!row) return;
      activeReceiptIndex=index;
      document.querySelectorAll('[data-receipt-index]').forEach((el,i)=>el.classList.toggle('active',i===index));
      const pre=document.getElementById('receiptPreview');
      if(pre){ pre.textContent=row.text||'Isi struk tidak tersedia.'; pre.dataset.cols=String(row.cols||46); requestAnimationFrame(fitReceiptPaper); }
      const active=document.querySelector('[data-receipt-index="'+index+'"]'); if(active && active.scrollIntoView) active.scrollIntoView({block:'nearest'});
      if(scrollPreview){ const title=document.querySelector('.preview-title'); if(title) title.scrollIntoView({behavior:'smooth',block:'start'}); }
      const status=document.getElementById('downloadStatus'); if(status) status.textContent='';
    }
    function filterBonList(){
      const input=document.getElementById('bonSearch');
      const q=String(input && input.value || '').trim().toLowerCase().replace(/^bon\\s*/i,'');
      let found=0, firstIndex=-1, activeVisible=false;
      document.querySelectorAll('[data-receipt-index]').forEach(function(el){
        const idx=Number(el.dataset.receiptIndex)||0;
        const row=RECEIPTS[idx]||{};
        const hay=(String(row.bill||'')+' '+String(row.rawBill||'')).toLowerCase();
        const visible=!q || hay.indexOf(q)!==-1;
        el.hidden=!visible;
        if(visible){ found++; if(firstIndex<0) firstIndex=idx; if(idx===activeReceiptIndex) activeVisible=true; }
      });
      const empty=document.getElementById('searchEmpty');
      if(empty) empty.style.display=(q && found===0)?'block':'none';
      const download=document.getElementById('downloadReceipt');
      if(download) download.disabled=RECEIPTS.length===0 || (q && found===0);
      if(found>0 && !activeVisible) showReceipt(firstIndex,false);
    }
    function roundedRect(ctx,x,y,w,h,r){
      const rr=Math.min(r,w/2,h/2);
      ctx.beginPath();
      ctx.moveTo(x+rr,y);ctx.arcTo(x+w,y,x+w,y+h,rr);ctx.arcTo(x+w,y+h,x,y+h,rr);ctx.arcTo(x,y+h,x,y,rr);ctx.arcTo(x,y,x+w,y,rr);ctx.closePath();
    }
    function downloadReceiptImage(){
      const row=RECEIPTS[activeReceiptIndex];
      if(!row) return;
      const lines=String(row.text||'Isi struk tidak tersedia.').replace(/\\r/g,'').split('\\n');
      const fontSize=31;
      const lineHeight=41;
      const padX=60;
      const padY=58;
      const radius=26;
      const test=document.createElement('canvas').getContext('2d');
      test.font='700 '+fontSize+'px "Courier New", Courier, monospace';
      let maxText=0;
      lines.forEach(function(line){ maxText=Math.max(maxText,test.measureText(line || ' ').width); });
      const width=Math.ceil(Math.max(760,maxText+padX*2));
      const height=Math.ceil(Math.max(300,lines.length*lineHeight+padY*2));
      const scale=Math.min(2,window.devicePixelRatio||1.5);
      const canvas=document.createElement('canvas');
      canvas.width=Math.ceil(width*scale); canvas.height=Math.ceil(height*scale);
      canvas.style.width=width+'px'; canvas.style.height=height+'px';
      const ctx=canvas.getContext('2d');
      ctx.scale(scale,scale);
      ctx.fillStyle='#ffffff'; ctx.fillRect(0,0,width,height);
      ctx.fillStyle='#fbfcfe'; roundedRect(ctx,3,3,width-6,height-6,radius); ctx.fill();
      ctx.strokeStyle='#dfe4ea'; ctx.lineWidth=2; roundedRect(ctx,3,3,width-6,height-6,radius); ctx.stroke();
      ctx.fillStyle='#000000'; ctx.font='700 '+fontSize+'px "Courier New", Courier, monospace'; ctx.textBaseline='top';
      lines.forEach(function(line,i){ ctx.fillText(line,padX,padY+i*lineHeight); });
      const safeBill=String(row.bill||'struk').replace(/[^a-z0-9_-]+/gi,'-');
      const filename='struk-bon-'+safeBill+'.png';
      const status=document.getElementById('downloadStatus');
      function saveUrl(url,revoke){
        const a=document.createElement('a'); a.href=url; a.download=filename; document.body.appendChild(a); a.click(); a.remove();
        if(revoke) setTimeout(function(){ URL.revokeObjectURL(url); },1200);
        if(status) status.textContent='Struk Bon '+String(row.bill||'')+' diunduh sebagai gambar.';
      }
      if(canvas.toBlob){
        canvas.toBlob(function(blob){ if(blob) saveUrl(URL.createObjectURL(blob),true); else saveUrl(canvas.toDataURL('image/png'),false); },'image/png');
      }else saveUrl(canvas.toDataURL('image/png'),false);
    }
    document.querySelectorAll('[data-receipt-index]').forEach(function(el){ el.addEventListener('click',function(){ showReceipt(el.dataset.receiptIndex,true); }); });
    const bonSearch=document.getElementById('bonSearch'); if(bonSearch) bonSearch.addEventListener('input',filterBonList);
    const downloadBtn=document.getElementById('downloadReceipt'); if(downloadBtn) downloadBtn.addEventListener('click',downloadReceiptImage);
    window.addEventListener('resize',function(){ requestAnimationFrame(fitReceiptPaper); });
    if(RECEIPTS.length) showReceipt(0,false); else { if(downloadBtn) downloadBtn.disabled=true; requestAnimationFrame(fitReceiptPaper); }
  </script></body></html>`;
}
async function requestClerekReceiptsClient(file){
  const zip = await JSZip.loadAsync(file);
  let dbFile = null;
  for(const name in zip.files){
    const lower = String(name || '').toLowerCase();
    if(!zip.files[name].dir && (lower.endsWith('.db') || lower.endsWith('.sqlite') || lower.endsWith('.sqlite3'))){ dbFile = zip.files[name]; break; }
  }
  if(!dbFile) throw new Error('Database tidak ditemukan di dalam ZIP.');
  const SQL = await initSqlJs({ locateFile: f => `https://cdnjs.cloudflare.com/ajax/libs/sql.js/1.10.2/${f}` });
  const db = new SQL.Database(new Uint8Array(await dbFile.async('arraybuffer')));
  const queryObjects = (sql)=>{
    const result = db.exec(sql);
    if(!result[0] || !Array.isArray(result[0].values)) return [];
    const cols = result[0].columns || [];
    return result[0].values.map(values=>{
      const row = {};
      cols.forEach((c,i)=>{ row[c]=values[i]; });
      return row;
    });
  };
  const hasTable = (name)=>{
    try{ return queryObjects(`SELECT name FROM sqlite_master WHERE type='table' AND name='${String(name).replace(/'/g,"''")}' LIMIT 1`).length > 0; }
    catch(e){ return false; }
  };
  const decodeValue = (value)=>{
    try{
      if(value instanceof Uint8Array) return new TextDecoder('utf-8').decode(value);
      if(value instanceof ArrayBuffer) return new TextDecoder('utf-8').decode(new Uint8Array(value));
      if(Array.isArray(value) && value.every(v=>Number.isInteger(v) && v>=0 && v<=255)) return new TextDecoder('utf-8').decode(new Uint8Array(value));
    }catch(e){}
    return value;
  };
  if(!hasTable('tx_tsale')){ db.close(); throw new Error('Tabel tx_tsale tidak ditemukan.'); }
  const info = queryObjects(`SELECT store_id, user_id, date_tx FROM tx_tsale ORDER BY date_tx DESC LIMIT 1`)[0] || {};
  const hasilRow = queryObjects(`SELECT SUM(cash) cash, SUM(change_pay) change_pay FROM tx_tsale WHERE date_tx=(SELECT MAX(date_tx) FROM tx_tsale)`)[0] || {};
  let receipts = [];
  if(hasTable('log_receipt_prn')){
    receipts = queryObjects(`SELECT l.bill_no, l.date_tx, t.user_id, t.cust_id, t.phone, t.cash, t.change_pay, l.header, l.body1, l.body2, l.body3, l.addtl1, l.addtl2, l.addtl3, l.footer FROM log_receipt_prn l LEFT JOIN tx_tsale t ON CAST(l.bill_no AS TEXT)=substr(t.faktur,-4) ORDER BY l.date_tx DESC`)
      .map(row=>{ const out={}; Object.keys(row).forEach(k=>{ out[k]=decodeValue(row[k]); }); return out; });
  }
  db.close();
  return {
    success:true,
    source:'browser-sqljs',
    file_name:String(file.name || ''),
    db_file:String(dbFile.name || '').split('/').pop(),
    store_id:decodeValue(info.store_id ?? '-'),
    user_id:decodeValue(info.user_id ?? '-'),
    tanggal:decodeValue(info.date_tx ?? '-'),
    hasil:Number(hasilRow.cash || 0)-Number(hasilRow.change_pay || 0),
    total_receipt:receipts.length,
    receipts
  };
}
async function requestClerekReceipts(file){
  if(!file) throw new Error('ZIP Clerek tidak tersedia. Pilih ZIP kembali.');
  const form = new FormData();
  form.append('zipfile', file, file.name || 'clerek.zip');
  try{
    const res = await fetch('?api=clerek_receipts', {method:'POST',body:form,credentials:'same-origin',cache:'no-store'});
    const data = await res.json().catch(()=>null);
    if(res.ok && data && data.success) return data;
    const serverMessage = (data && (data.error || data.msg)) || `HTTP ${res.status}`;
    if(res.status === 401 || res.status === 403) throw new Error(serverMessage);
    // Hosting yang tidak mengaktifkan ZipArchive/SQLite3 tetap dapat membaca
    // file memakai JSZip + sql.js yang sudah digunakan oleh halaman Clerek.
    return await requestClerekReceiptsClient(file);
  }catch(err){
    if(err && /login ulang|forbidden/i.test(String(err.message || ''))) throw err;
    return await requestClerekReceiptsClient(file);
  }
}
async function showClerekReceiptView(){
  const cache = loadClerekCache();
  if(cache && cache.receiptData && Array.isArray(cache.receiptData.receipts)){
    setIframeHtml(buildClerekReceiptHtml(cache.receiptData), true);
    return;
  }

  // Kompatibilitas data lama: bila file yang baru dipilih masih ada di input
  // Clerek, baca satu kali lalu simpan hasil struk ke cache yang sama.
  const currentFile = clerekZip && clerekZip.files && clerekZip.files[0] ? clerekZip.files[0] : null;
  if(currentFile){
    showLoading('Membaca struk…');
    try{
      const data = await requestClerekReceiptsClient(currentFile);
      if(cache){ cache.receiptData = data; saveClerekCache(cache); }
      hideLoading();
      setIframeHtml(buildClerekReceiptHtml(data), true);
      return;
    }catch(err){
      hideLoading();
      setIframeHtml(buildClerekReceiptUploadHtml(err && err.message ? err.message : 'Gagal membaca struk dari ZIP Clerek.'), true);
      return;
    }
  }

  setIframeHtml(buildClerekReceiptUploadHtml('Struk belum tersimpan. Kembali ke menu Clerek, pilih ZIP pada Upload File, lalu tekan proses satu kali.'), true);
}
async function processClerekReceiptFallback(){
  // Dipertahankan sebagai stub kompatibilitas; upload kedua sudah dihapus.
  return showClerekReceiptView();
}

function showSavedClerekResult(){
  const cache = loadClerekCache();
  if(!cache) return false;
  setIframeHtml(buildClerekResultHtml(cache), true);
  return true;
}
function openSavedClerekFromModal(){
  const ok = showSavedClerekResult();
  if(!ok){
    alert("Belum ada data Clerek tersimpan.");
    updateClerekCacheInfo();
    return;
  }
  closeClerekModal();
}


/* =========
   FAB CHAT GLOBAL
========= */
let CHAT_MESSAGES = [];
let CHAT_LAST_SIG = '';
let CHAT_POLL_TIMER = null;
let CHAT_FETCHING = false;
let CHAT_LAST_SEEN_TS = 0;
let CHAT_DELETE_MODE = false;
let CHAT_MY_NAME = '';
const CHAT_POLL_MS = 5000;

function chatStoreKey(){
  const sid = String(STORE_ID || 'guest').trim().toUpperCase().replace(/[^A-Z0-9]/g,'') || 'guest';
  return `ALFASTORE_CHAT_LAST_SEEN_${sid}`;
}
function escapeHtml(v){
  return String(v ?? '').replace(/[&<>"']/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[s]));
}
function formatChatClock(ts){
  if(!ts) return '-';
  try{
    return new Date(ts * 1000).toLocaleTimeString('id-ID', {hour:'2-digit', minute:'2-digit'});
  }catch(e){ return '-'; }
}
function isOwnChatMessage(m){
  const sameStore = String(m?.storeId || '').toUpperCase() === String(STORE_ID || '').toUpperCase();
  return IS_DEVELOPER ? !!m?.isDeveloper : (sameStore && !m?.isDeveloper);
}
function getChatUnreadCount(){
  const lastSeen = Number(CHAT_LAST_SEEN_TS || 0);
  return CHAT_MESSAGES.filter(m => Number(m.createdTs || 0) > lastSeen && !isOwnChatMessage(m)).length;
}
function updateChatBadge(){
  const badge = document.getElementById('chatFabBadge');
  if(!badge) return;
  const unread = getChatUnreadCount();
  if(unread > 0){
    badge.style.display = 'inline-flex';
    badge.textContent = unread > 99 ? '99+' : String(unread);
  }else{
    badge.style.display = 'none';
    badge.textContent = '0';
  }
}
function markChatSeen(){
  const latestTs = CHAT_MESSAGES.length ? Number(CHAT_MESSAGES[CHAT_MESSAGES.length - 1].createdTs || 0) : Math.floor(Date.now()/1000);
  CHAT_LAST_SEEN_TS = latestTs;
  try{ localStorage.setItem(chatStoreKey(), String(CHAT_LAST_SEEN_TS)); }catch(e){}
  updateChatBadge();
}
function ensureChatStyle(){
  if(document.getElementById('chatFabStyle')) return;
  const style = document.createElement('style');
  style.id = 'chatFabStyle';
  style.textContent = `
    .chat-fab{position:fixed;right:16px;bottom:16px;z-index:2147483002;width:60px;height:60px;border:0;border-radius:50%;background:linear-gradient(145deg,#2563eb 0%,#1d4ed8 100%);box-shadow:0 16px 34px rgba(37,99,235,.34),0 0 0 5px rgba(219,234,254,.88);display:flex;align-items:center;justify-content:center;color:#fff;cursor:pointer;transition:transform .18s ease,box-shadow .18s ease,filter .18s ease;-webkit-tap-highlight-color:transparent}
    .chat-fab:active{transform:scale(.94)}.chat-fab:hover{transform:translateY(-2px);box-shadow:0 19px 38px rgba(37,99,235,.38),0 0 0 5px rgba(219,234,254,.95)}
    .chat-fab svg{width:30px;height:30px;display:block;filter:drop-shadow(0 2px 3px rgba(0,0,0,.10))}
    .chat-fab-badge{position:absolute;top:-5px;right:-3px;min-width:22px;height:22px;padding:0 6px;border:2px solid #fff;border-radius:999px;background:#ef4444;color:#fff;font-size:10px;font-weight:900;display:none;align-items:center;justify-content:center;box-shadow:0 6px 16px rgba(239,68,68,.30)}
    .chat-popup{position:fixed;right:14px;bottom:88px;z-index:2147483001;width:min(402px,calc(100vw - 28px));height:min(630px,calc(100dvh - 116px));background:#fff;border:1px solid #e2e8f0;border-radius:26px;box-shadow:0 28px 80px rgba(15,23,42,.26),0 2px 8px rgba(15,23,42,.08);display:none;overflow:hidden;transform-origin:bottom right}
    .chat-popup.open{display:flex;flex-direction:column;animation:chatPopIn .16s ease-out}
    @keyframes chatPopIn{from{opacity:0;transform:translateY(8px) scale(.985)}to{opacity:1;transform:none}}
    .chat-popup.open + .chat-fab,.chat-fab.chat-hidden{display:none!important}
    .chat-head{padding:14px 14px 13px 16px;background:#fff;color:#0f172a;border-bottom:1px solid #e8eef7;display:flex;align-items:center;justify-content:space-between;gap:12px;flex:0 0 auto}
    .chat-head-left{display:flex;align-items:center;gap:11px;min-width:0}.chat-head-icon{width:42px;height:42px;border-radius:14px;background:linear-gradient(145deg,#2563eb,#1d4ed8);color:#fff;display:flex;align-items:center;justify-content:center;box-shadow:0 8px 18px rgba(37,99,235,.20);flex:0 0 auto}.chat-head-icon svg{width:22px;height:22px}
    .chat-head-title{display:flex;flex-direction:column;gap:3px;min-width:0}.chat-head-title strong{font-size:16px;line-height:1.15;letter-spacing:-.01em}.chat-head-title>span{font-size:11.5px;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:250px}.chat-head-title #chatLoginName{font-weight:800;color:#334155}
    .chat-status-dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:#22c55e;margin-right:5px;box-shadow:0 0 0 3px rgba(34,197,94,.10)}
    .chat-close{width:38px;height:38px;border:0;border-radius:12px;background:#f1f5f9;color:#475569;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:background .15s ease,transform .15s ease}.chat-close:hover{background:#e2e8f0}.chat-close:active{transform:scale(.94)}.chat-close svg{width:18px;height:18px}
    .chat-body{flex:1;overflow:auto;padding:15px 13px 16px;background:linear-gradient(180deg,#f8fafc 0%,#f3f7fd 100%);display:flex;flex-direction:column;gap:10px;min-height:0;-webkit-overflow-scrolling:touch;scrollbar-width:thin;scrollbar-color:#cbd5e1 transparent}
    .chat-empty{margin:auto 8px;padding:22px 18px;border:1px dashed #bfdbfe;border-radius:20px;background:rgba(255,255,255,.86);color:#64748b;text-align:center;font-size:13px;font-weight:700;line-height:1.5;box-shadow:0 8px 24px rgba(15,23,42,.04)}
    .chat-row{display:flex;align-items:flex-end}.chat-row.me{justify-content:flex-end}
    .chat-bubble{position:relative;max-width:84%;padding:10px 12px 11px;border-radius:18px 18px 18px 6px;background:#fff;border:1px solid #e2e8f0;box-shadow:0 4px 12px rgba(15,23,42,.055);color:#0f172a}
    .chat-row.me .chat-bubble{border:0;border-radius:18px 18px 6px 18px;background:linear-gradient(145deg,#2563eb,#1d4ed8);color:#fff;box-shadow:0 7px 17px rgba(37,99,235,.18)}
    .chat-meta{display:flex;align-items:center;gap:9px;justify-content:space-between;margin-bottom:4px;font-size:10.5px;font-weight:850;line-height:1.2}.chat-name{color:#1d4ed8;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.chat-row.me .chat-name{color:#fff}.chat-name.dev-verified,.chat-dev-name{display:inline-flex;align-items:center;gap:5px}.chat-verified{display:inline-flex;align-items:center;justify-content:center;width:15px;height:15px;border-radius:999px;background:#2563eb;color:#fff;font-size:9px;font-weight:900;line-height:1;box-shadow:0 4px 10px rgba(37,99,235,.22)}.chat-row.me .chat-verified{background:#fff;color:#2563eb;box-shadow:none}
    .chat-time{color:#94a3b8;white-space:nowrap;font-weight:700}.chat-row.me .chat-time{color:rgba(255,255,255,.76)}
    .chat-text{font-size:13.2px;line-height:1.45;white-space:pre-wrap;word-break:break-word;font-weight:600;color:inherit}
    .chat-delete{display:none;margin-top:8px;padding:6px 9px;border:0;border-radius:10px;background:#fee2e2;color:#b91c1c;font-size:10.5px;font-weight:850;cursor:pointer}.chat-row.me .chat-delete{background:rgba(255,255,255,.18);color:#fff}.chat-delete-mode .chat-delete{display:inline-flex;align-items:center;justify-content:center}
    .chat-dev-tools{padding:8px 10px;border-top:1px solid #e2e8f0;background:#fff;display:flex;justify-content:flex-end;gap:7px;flex-wrap:wrap}.chat-dev-btn{border:1px solid #e2e8f0;border-radius:12px;padding:8px 10px;font-size:11px;font-weight:850;cursor:pointer;background:#fff}.chat-dev-btn.delete-all{background:#fff1f2;border-color:#fecdd3;color:#be123c}.chat-dev-btn.delete-mode{background:#fff7ed;border-color:#fed7aa;color:#c2410c}.chat-dev-btn.delete-mode.active{background:#dc2626;border-color:#dc2626;color:#fff}
    .chat-foot{padding:10px;border-top:1px solid #e2e8f0;background:#fff;display:grid;grid-template-columns:minmax(0,1fr) 44px;gap:8px;flex:0 0 auto;position:sticky;bottom:0;left:0;right:0;padding-bottom:calc(10px + env(safe-area-inset-bottom,0px));align-items:end}.chat-input{width:100%;height:44px;min-height:44px;max-height:82px;resize:none;border:1px solid #dbe4ef;border-radius:16px;padding:11px 13px;font:inherit;font-size:13px;line-height:1.4;outline:none;background:#f8fafc;color:#0f172a;overflow:auto;transition:border-color .15s ease,box-shadow .15s ease,background .15s ease}.chat-input::placeholder{color:#94a3b8}.chat-input:focus{background:#fff;border-color:#60a5fa;box-shadow:0 0 0 4px rgba(59,130,246,.10)}
    .chat-send{width:44px;min-width:44px;height:44px;border:0;border-radius:14px;background:linear-gradient(145deg,#2563eb,#1d4ed8);color:#fff;font-weight:800;padding:0;cursor:pointer;display:flex;align-items:center;justify-content:center;align-self:end;box-shadow:0 7px 16px rgba(37,99,235,.20);transition:transform .15s ease,filter .15s ease}.chat-send svg{width:20px;height:20px}.chat-send:active{transform:scale(.94)}.chat-send[disabled]{opacity:.55;cursor:not-allowed;box-shadow:none}
    @media(max-width:640px){.chat-popup{right:8px;bottom:80px;width:calc(100vw - 16px);height:min(610px,calc(100dvh - 98px));border-radius:24px}.chat-fab{right:14px;bottom:14px;width:58px;height:58px}.chat-head{padding:12px 12px 11px 13px}.chat-head-icon{width:40px;height:40px;border-radius:13px}.chat-head-title>span{max-width:210px}.chat-body{padding:13px 10px 14px}.chat-bubble{max-width:87%}.chat-dev-tools{padding:7px 8px}.chat-foot{padding:8px;padding-bottom:calc(8px + env(safe-area-inset-bottom,0px));grid-template-columns:minmax(0,1fr) 42px;gap:7px}.chat-input{height:42px;min-height:42px;max-height:72px;padding:10px 12px;border-radius:15px}.chat-send{width:42px;min-width:42px;height:42px;border-radius:13px}}
  `;
  document.head.appendChild(style);
}
function syncChatViewport(){
  const popup = document.getElementById('chatPopup');
  if(!popup) return;
  const foot = popup.querySelector('.chat-foot');
  const body = popup.querySelector('.chat-body');
  const input = popup.querySelector('.chat-input');
  const vv = window.visualViewport;
  if(vv && popup.classList.contains('open') && vv.height < (window.innerHeight * 0.78)){
    popup.style.height = `${Math.max(260, Math.round(vv.height - 16))}px`;
    popup.style.top = `${Math.max(8, Math.round(vv.offsetTop + 8))}px`;
    popup.style.bottom = 'auto';
  }else{
    popup.style.height = '';
    popup.style.top = '';
    popup.style.bottom = '';
  }
  if(foot){
    const compactPad = window.innerWidth <= 640 ? 8 : 10;
    foot.style.paddingBottom = `calc(${compactPad}px + env(safe-area-inset-bottom,0px))`;
  }
  if(input){
    input.style.height = window.innerWidth <= 640 ? '42px' : '44px';
    input.style.height = `${Math.min(input.scrollHeight, window.innerWidth <= 640 ? 64 : 72)}px`;
  }
  if(body) body.style.paddingBottom = `${12 + (foot ? foot.offsetHeight : 0)}px`;
}
function chatDeveloperBadgeHtml(){
  return '<span class="chat-verified" title="Developer terverifikasi" aria-label="Developer terverifikasi">✓</span>';
}
function updateChatIdentity(){
  const el = document.getElementById('chatLoginName');
  if(!el) return;
  if(IS_DEVELOPER){
    el.innerHTML = '<span class="chat-dev-name">Developer '+chatDeveloperBadgeHtml()+'</span>';
  }else{
    el.textContent = CHAT_MY_NAME || STORE_ID || 'User';
  }
}
function renderChatMessages(){
  const popup = document.getElementById('chatPopup');
  const body = document.getElementById('chatPopupBody');
  if(!body) return;
  if(popup) popup.classList.toggle('chat-delete-mode', !!CHAT_DELETE_MODE);
  if(!CHAT_MESSAGES.length){
    body.innerHTML = `<div class="chat-empty">Belum ada pesan. Mulai percakapan antar toko.</div>`;
    return;
  }
  body.innerHTML = CHAT_MESSAGES.map((m)=>{
    const isMine = isOwnChatMessage(m);
    const isDevMessage = !!m.isDeveloper;
    const displayName = isDevMessage ? `Developer ${chatDeveloperBadgeHtml()}` : escapeHtml(m.name || m.storeId || 'User');
    return `
      <div class="chat-row ${isMine ? 'me' : ''}">
        <div class="chat-bubble">
          <div class="chat-meta">
            <span class="chat-name ${isDevMessage ? 'dev-verified' : ''}">${displayName}</span>
            <span class="chat-time">${escapeHtml(formatChatClock(Number(m.createdTs || 0)))}</span>
          </div>
          <div class="chat-text">${escapeHtml(m.text || '')}</div>
          ${IS_DEVELOPER ? `<button type="button" class="chat-delete" data-chat-delete-id="${escapeHtml(m.id || '')}">Hapus</button>` : ''}
        </div>
      </div>
    `;
  }).join('');
  syncChatViewport();
  body.scrollTop = body.scrollHeight + 400;
}

function createChatFab(){
  if(!STORE_ID || document.getElementById('chatFabButton')) return;
  ensureChatStyle();
  try{ CHAT_LAST_SEEN_TS = Number(localStorage.getItem(chatStoreKey()) || '0') || 0; }catch(e){ CHAT_LAST_SEEN_TS = 0; }

  const fab = document.createElement('button');
  fab.type = 'button';
  fab.id = 'chatFabButton';
  fab.className = 'chat-fab';
  fab.setAttribute('aria-label', 'Buka chat');
  fab.setAttribute('title', 'Chat Antar Toko');
  fab.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 3C6.93 3 3 6.57 3 11c0 2.22 1.02 4.25 2.72 5.72L5 21l4.13-2.07c.91.24 1.88.37 2.87.37 5.07 0 9-3.57 9-8.3S17.07 3 12 3Z"/><circle cx="8.25" cy="11" r="1.15" fill="#fff"/><circle cx="12" cy="11" r="1.15" fill="#fff"/><circle cx="15.75" cy="11" r="1.15" fill="#fff"/></svg><span id="chatFabBadge" class="chat-fab-badge">0</span>';

  const popup = document.createElement('div');
  popup.id = 'chatPopup';
  popup.className = 'chat-popup';
  popup.innerHTML = `
    <div class="chat-head">
      <div class="chat-head-left">
        <div class="chat-head-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path fill="currentColor" d="M12 3C6.93 3 3 6.57 3 11c0 2.22 1.02 4.25 2.72 5.72L5 21l4.13-2.07c.91.24 1.88.37 2.87.37 5.07 0 9-3.57 9-8.3S17.07 3 12 3Z"/><circle cx="8.25" cy="11" r="1" fill="#fff"/><circle cx="12" cy="11" r="1" fill="#fff"/><circle cx="15.75" cy="11" r="1" fill="#fff"/></svg></div>
        <div class="chat-head-title">
          <strong>Chat Antar Toko</strong>
          <span><i class="chat-status-dot"></i>Login sebagai <span id="chatLoginName">${IS_DEVELOPER ? 'Developer' : escapeHtml(CHAT_MY_NAME || STORE_ID)}</span></span>
        </div>
      </div>
      <button type="button" class="chat-close" id="chatPopupClose" aria-label="Tutup chat"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7 7l10 10M17 7 7 17" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg></button>
    </div>
    <div class="chat-body" id="chatPopupBody"><div class="chat-empty">Memuat percakapan...</div></div>
    ${IS_DEVELOPER ? `<div class="chat-dev-tools"><button type="button" id="chatToggleDeleteBtn" class="chat-dev-btn delete-mode">Hapus Satu-satu</button><button type="button" id="chatDeleteAllBtn" class="chat-dev-btn delete-all">Hapus Semua Chat</button></div>` : ''}
    <div class="chat-foot">
      <textarea id="chatInput" class="chat-input" rows="1" maxlength="500" placeholder="Tulis pesan..."></textarea>
      <button type="button" id="chatSendBtn" class="chat-send" aria-label="Kirim pesan" title="Kirim"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4.5 5.1 20 12 4.5 18.9l1.2-5.4 8.1-1.5-8.1-1.5-1.2-5.4Z" fill="currentColor"/></svg></button>
    </div>
  `;
  document.body.appendChild(popup);
  document.body.appendChild(fab);

  const openPopup = ()=>{
    popup.classList.add('open');
    fab.classList.add('chat-hidden');
    modalOpen();
    syncChatViewport();
    renderChatMessages();
    markChatSeen();
    setTimeout(()=>{
      const body = document.getElementById('chatPopupBody');
      if(body) body.scrollTop = body.scrollHeight + 400;
      const input = document.getElementById('chatInput');
      if(input) input.focus();
      syncChatViewport();
    }, 50);
  };
  const closePopup = ()=>{
    popup.classList.remove('open');
    fab.classList.remove('chat-hidden');
    popup.style.height = '';
    popup.style.top = '';
    popup.style.bottom = '';
    CHAT_DELETE_MODE = false;
    popup.classList.remove('chat-delete-mode');
    const toggleBtn = popup.querySelector('#chatToggleDeleteBtn');
    if(toggleBtn) toggleBtn.classList.remove('active');
    if(toggleBtn) toggleBtn.textContent = 'Hapus Satu-satu';
    modalClose();
    markChatSeen();
  };

  fab.addEventListener('click', ()=>{
    if(popup.classList.contains('open')) closePopup();
    else openPopup();
  });
  popup.querySelector('#chatPopupClose').addEventListener('click', closePopup);

  popup.addEventListener('click', async (e)=>{
    const delBtn = e.target.closest('[data-chat-delete-id]');
    if(!delBtn) return;
    if(!IS_DEVELOPER) return;
    const messageId = String(delBtn.getAttribute('data-chat-delete-id') || '').trim();
    if(!messageId) return;
    if(!confirm('Hapus chat ini?')) return;
    delBtn.disabled = true;
    try{
      const res = await fetch(`${API_URL}?api=chat_delete`, {
        method:'POST',
        credentials:'same-origin',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ id: messageId })
      });
      const j = await res.json().catch(()=>null);
      if(!j || !j.ok) throw new Error(j?.msg || 'Gagal hapus chat');
      CHAT_MESSAGES = Array.isArray(j.messages) ? j.messages : [];
      renderChatMessages();
      updateChatBadge();
    }catch(err){
      alert(err?.message || 'Gagal hapus chat');
    }finally{
      delBtn.disabled = false;
    }
  });

  const toggleDeleteBtn = popup.querySelector('#chatToggleDeleteBtn');
  if(toggleDeleteBtn) toggleDeleteBtn.addEventListener('click', ()=>{
    if(!IS_DEVELOPER) return;
    CHAT_DELETE_MODE = !CHAT_DELETE_MODE;
    popup.classList.toggle('chat-delete-mode', CHAT_DELETE_MODE);
    toggleDeleteBtn.classList.toggle('active', CHAT_DELETE_MODE);
    toggleDeleteBtn.textContent = CHAT_DELETE_MODE ? 'Selesai' : 'Hapus Satu-satu';
    renderChatMessages();
  });

  const deleteAllBtn = popup.querySelector('#chatDeleteAllBtn');
  if(deleteAllBtn) deleteAllBtn.addEventListener('click', async ()=>{
    if(!IS_DEVELOPER) return;
    if(!confirm('Hapus semua chat? Tindakan ini tidak bisa dibatalkan.')) return;
    deleteAllBtn.disabled = true;
    try{
      const res = await fetch(`${API_URL}?api=chat_delete_all`, {
        method:'POST',
        credentials:'same-origin'
      });
      const j = await res.json().catch(()=>null);
      if(!j || !j.ok) throw new Error(j?.msg || 'Gagal hapus semua chat');
      CHAT_MESSAGES = Array.isArray(j.messages) ? j.messages : [];
      renderChatMessages();
      markChatSeen();
    }catch(err){
      alert(err?.message || 'Gagal hapus semua chat');
    }finally{
      deleteAllBtn.disabled = false;
    }
  });

  const input = popup.querySelector('#chatInput');
  const sendBtn = popup.querySelector('#chatSendBtn');
  const handleViewportUpdate = ()=>{
    if(!popup.classList.contains('open')) return;
    syncChatViewport();
    setTimeout(()=>{
      const body = document.getElementById('chatPopupBody');
      if(body) body.scrollTop = body.scrollHeight + 400;
    }, 30);
  };
  if(window.visualViewport){
    window.visualViewport.addEventListener('resize', handleViewportUpdate);
    window.visualViewport.addEventListener('scroll', handleViewportUpdate);
  }
  if(input){
    input.addEventListener('focus', ()=>setTimeout(syncChatViewport, 80));
    input.addEventListener('blur', ()=>setTimeout(syncChatViewport, 80));
    input.addEventListener('input', ()=>{
      input.style.height = window.innerWidth <= 640 ? '42px' : '44px';
      input.style.height = `${Math.min(input.scrollHeight, window.innerWidth <= 640 ? 64 : 72)}px`;
      setTimeout(syncChatViewport, 0);
    });
  }
  const submitChat = async ()=>{
    const msg = String(input.value || '').trim();
    if(!msg) return;
    sendBtn.disabled = true;
    try{
      const res = await fetch(`${API_URL}?api=chat_send`, {
        method:'POST',
        credentials:'same-origin',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ message: msg })
      });
      const j = await res.json().catch(()=>null);
      if(!j || !j.ok) throw new Error(j?.msg || 'Gagal kirim chat');
      input.value = '';
      input.style.height = window.innerWidth <= 640 ? '42px' : '44px';
      if(Array.isArray(j.messages)){
        CHAT_MESSAGES = j.messages;
        renderChatMessages();
        markChatSeen();
      }else{
        await fetchChatMessages(true);
      }
    }catch(err){
      alert(err?.message || 'Gagal kirim chat');
    }finally{
      sendBtn.disabled = false;
      input.focus();
    }
  };
  sendBtn.addEventListener('click', submitChat);
  input.addEventListener('keydown', (e)=>{
    if(e.key === 'Enter' && !e.shiftKey){
      e.preventDefault();
      submitChat();
    }
  });
}
async function fetchChatMessages(forceRender=false){
  if(!STORE_ID || CHAT_FETCHING) return;
  CHAT_FETCHING = true;
  try{
    const res = await fetch(`${API_URL}?api=chat_list`, {cache:'no-store', credentials:'same-origin'});
    const j = await res.json().catch(()=>null);
    if(!j || !j.ok || !Array.isArray(j.messages)) return;
    CHAT_MY_NAME = String(j.myName || CHAT_MY_NAME || STORE_ID || '');
    updateChatIdentity();
    const sig = JSON.stringify(j.messages.map(m => [m.id, m.createdTs, m.text, !!m.isDeveloper, m.name]));
    const changed = sig !== CHAT_LAST_SIG;
    CHAT_LAST_SIG = sig;
    CHAT_MESSAGES = j.messages;
    if(changed || forceRender){
      renderChatMessages();
      const popup = document.getElementById('chatPopup');
      if(popup && popup.classList.contains('open')) markChatSeen();
      else updateChatBadge();
    }else{
      updateChatBadge();
    }
  }catch(e){
  }finally{
    CHAT_FETCHING = false;
  }
}
function startChatPolling(){
  if(!STORE_ID) return;
  createChatFab();
  fetchChatMessages(true);
  if(CHAT_POLL_TIMER) clearInterval(CHAT_POLL_TIMER);
  CHAT_POLL_TIMER = setInterval(()=>{ fetchChatMessages(false); }, CHAT_POLL_MS);
}
window.addEventListener('DOMContentLoaded', ()=>{ startChatPolling(); });

/* =========
   ANDROID IFRAME/MODAL FIX
   - pada sebagian Android, iframe bisa "numpuk" dan menutupi modal/menu
   - solusi: hide visibility iframe saat ada modal terbuka (counter)
========= */
let MODAL_OPEN_COUNT = 0;
function freezeIframeForModal(on){
  if(on){
    contentFrame.style.visibility = "hidden";
    iframeWrap.style.visibility  = "hidden";
  }else{
    contentFrame.style.visibility = "";
    iframeWrap.style.visibility  = "";
  }
}
function modalOpen(){
  MODAL_OPEN_COUNT++;
  if(MODAL_OPEN_COUNT === 1) freezeIframeForModal(true);
}
function modalClose(){
  MODAL_OPEN_COUNT = Math.max(0, MODAL_OPEN_COUNT - 1);
  if(MODAL_OPEN_COUNT === 0) freezeIframeForModal(false);
}

function openPremiumGate(){
  modalOpen();
  const m = document.getElementById("premiumGateModal");
  if(m) m.style.display="flex";
}
function closePremiumGate(){
  const m = document.getElementById("premiumGateModal");
  if(m) m.style.display="none";
  modalClose();
}
function openWhatsAppSupport(){
  showHelpChoiceModal();
}



function pad2(n){ return String(n).padStart(2,'0'); }
function todayISO(){
  // gunakan tanggal LOCAL (Asia/Jakarta), bukan UTC
  const d = new Date();
  const tz = d.getTimezoneOffset() * 60000;
  return new Date(d.getTime() - tz).toISOString().slice(0,10);
}
function formatDMY(iso){
  const d = new Date(iso);
  return `${pad2(d.getDate())}-${pad2(d.getMonth()+1)}-${d.getFullYear()}`;
}

let _LAST_TODAY_ISO = null;
function syncTodayFields(force=false){
  const t = todayISO();
  const prev = _LAST_TODAY_ISO;
  if(!force && prev === t) return;
  _LAST_TODAY_ISO = t;

  // update label "hari ini"
  const ct = document.getElementById("clerekToday");
  if(ct) ct.textContent = t;

  // update input yang biasanya default "hari ini"
  const ids = ["soRupiahDate","reportDateInput"];
  ids.forEach(id=>{
    const el = document.getElementById(id);
    if(!el) return;
    const v = (el.value||"").trim();
    // hanya auto-ubah jika masih kosong atau masih pakai "hari sebelumnya"
    if(!v || (prev && v === prev)) el.value = t;
  });

  if(typeof updateDailyHint === "function") updateDailyHint();
}

window.addEventListener('resize', ()=>{ updateHeaderSpacing(); });

function updateHeaderSpacing(){
  try{
    const h = document.querySelector('.header');
    if(!h) return;
    const hh = h.getBoundingClientRect().height;
    // tambah jarak kecil di bawah header agar aman saat teks header wrap
    // (misal nama toko panjang / tombol header jadi 2 baris)
    const extraGap = 12;
    document.documentElement.style.setProperty('--headerH', Math.ceil(hh + extraGap) + 'px');
  }catch(e){}
}

let _expiryTimer = null;
function startExpiryCountdown(ts){
  const box = document.getElementById("expiryBox");
  if(!box){ return; }
  if(_expiryTimer){ clearInterval(_expiryTimer); _expiryTimer=null; }

  const appConfig = document.querySelector('script[data-store-id]');
  const isDeveloperUnlimited = !!(appConfig && appConfig.dataset && appConfig.dataset.isDeveloper === 'true');
  if(isDeveloperUnlimited){
    box.style.display = "block";
    box.textContent = "EXPIRED: UNLIMITED";
    box.dataset.unlimited = "true";
    updateHeaderSpacing();
    return;
  }

  if(!ts || ts <= 0){
    box.style.display = "none";
    box.textContent = "";
    updateHeaderSpacing();
    return;
  }

  const expDate = new Date(ts * 1000);
  const expText = expDate.toLocaleString("id-ID", { year:"numeric", month:"2-digit", day:"2-digit", hour:"2-digit", minute:"2-digit", second:"2-digit" });

  function tick(){
    const now = Math.floor(Date.now()/1000);
    let left = ts - now;

    if(left <= 0){
      box.style.display = "block";
      box.textContent = `EXPIRED - Auto Logout…`;
      updateHeaderSpacing();
      clearInterval(_expiryTimer); _expiryTimer=null;
      // auto logout & hapus kode toko dari JSON cookie (server clear cookie)
      setTimeout(()=>{ logout(); }, 300);
      return;
    }

    const days = Math.floor(left / 86400); left %= 86400;
    const hh = Math.floor(left/3600); left%=3600;
    const mm = Math.floor(left/60); const ss = left%60;

    const sisa = (days>0)
      ? `${days} hari ${String(hh).padStart(2,'0')}:${String(mm).padStart(2,'0')}:${String(ss).padStart(2,'0')}`
      : `${String(hh).padStart(2,'0')}:${String(mm).padStart(2,'0')}:${String(ss).padStart(2,'0')}`;

    box.style.display = "block";
    box.textContent = `EXPIRED: ${sisa}`;
    updateHeaderSpacing();
  }
  tick();
  _expiryTimer = setInterval(tick, 1000);
}


function ensureExpiryWarningModal(){
  let wrap = document.getElementById('expiryWarningOverlay');
  if(wrap) return wrap;
  wrap = document.createElement('div');
  wrap.id = 'expiryWarningOverlay';
  wrap.className = 'expiry-warning-overlay';
  wrap.innerHTML = `
    <div class="expiry-warning-card">
      <div class="expiry-warning-badge">!</div>
      <div class="expiry-warning-title">Peringatan Expired</div>
      <div class="expiry-warning-text" id="expiryWarningText">Sisa expired anda tersisa 3 hari. Perpanjang melalui tombol bantuan</div>
      <div class="expiry-warning-actions"><button type="button" class="expiry-warning-later-btn" onclick="closeExpiryWarningModal()">Nanti</button></div>
    </div>
  `;
  document.body.appendChild(wrap);
  wrap.addEventListener('click', (e)=>{ if(e.target === wrap) wrap.classList.remove('show'); });
  return wrap;
}
function closeExpiryWarningModal(){
  const wrap = document.getElementById('expiryWarningOverlay');
  if(wrap) wrap.classList.remove('show');
}
function getExpiryWarningDays(ts){
  const expTs = Number(ts || 0);
  if(!expTs) return 0;
  const diff = expTs - Math.floor(Date.now()/1000);
  if(diff <= 0) return 0;
  const days = Math.floor(diff / 86400);
  return days < 1 ? 1 : days;
}
function maybeShowExpiryWarning(days){
  const n = Number(days || 0);
  if(_expiryWarningShown || !STORE_ID || !EXPIRY_TS || n < 1 || n > 3) return;
  _expiryWarningShown = true;
  const wrap = ensureExpiryWarningModal();
  const textEl = document.getElementById('expiryWarningText');
  if(textEl) textEl.textContent = `Sisa expired anda tersisa ${n} hari. Perpanjang melalui tombol bantuan`;
  wrap.classList.add('show');
}

function firstOfMonthISO(endISO){
  const d = new Date(endISO);
  return `${d.getFullYear()}-${pad2(d.getMonth()+1)}-01`;
}
function showZoom(show){ document.querySelector(".zoom-controls").style.display = show ? "flex" : "none"; }
function getFrameBaseHeight(){
  const h = iframeWrap ? iframeWrap.clientHeight : 0;
  return Math.max(1, h || window.innerHeight || document.documentElement.clientHeight || 600);
}
function applyZoom(){
  const baseH = getFrameBaseHeight();
  contentFrame.style.transform = `scale(${zoomLevel})`;
  contentFrame.style.transformOrigin = "top left";
  contentFrame.style.width = (100/zoomLevel) + "%";
  contentFrame.style.height = (baseH/zoomLevel) + "px";
  contentFrame.style.minHeight = (baseH/zoomLevel) + "px";
}
function zoomIn(){ zoomLevel = Math.min(2, +(zoomLevel + 0.1).toFixed(2)); applyZoom(); }
function zoomOut(){ zoomLevel = Math.max(0.5, +(zoomLevel - 0.1).toFixed(2)); applyZoom(); }
function resetZoom(){ zoomLevel = 1; applyZoom(); }
function syncFrameViewport(){
  if(frameCard && frameCard.classList.contains("show")){
    frameCard.style.height = ((window.visualViewport && window.visualViewport.height) || window.innerHeight) + "px";
    if(contentFrame.style.display==="block") requestAnimationFrame(applyZoom);
  }
}
window.addEventListener("resize", syncFrameViewport);
if(window.visualViewport){
  window.visualViewport.addEventListener("resize", syncFrameViewport);
  window.visualViewport.addEventListener("scroll", syncFrameViewport);
}


function showLoading(msg){
  if(msg){
    const t = loading.querySelector(".loader-title");
    if(t) t.textContent = msg;
  }
  loading.style.display="flex";
}
function hideLoading(){ loading.style.display="none"; }


/* BANNER MODE (menyesuaikan tinggi area iframe saat banner tampil) */
let _bannerModeOn = false;
function setBannerMode(on){
  _bannerModeOn = !!on;
  if(_bannerModeOn){
    frameCard.classList.add("banner-mode");
    // pastikan iframe tidak memakan tinggi saat banner tampil
    contentFrame.style.display = "none";
    showZoom(false);
  }else{
    frameCard.classList.remove("banner-mode");
  }
}


function customAlertSignature(data){
  if(!data) return '';
  return [data.updatedAt||'', data.title||'', data.message||'', data.buttonText||'', data.buttonUrl||''].map(v=>String(v||'')).join('|');
}
function customAlertDismissKey(){
  return 'alfastore_custom_alert_dismiss_' + String(STORE_ID || 'guest').toUpperCase();
}
function getDismissedCustomAlertSignature(){
  // Disimpan permanen per kode toko dan signature alert.
  // Setelah user klik "Jangan Munculkan Lagi", alert yang sama tidak tampil lagi pada login berikutnya.
  try{ return localStorage.getItem(customAlertDismissKey()) || ''; }catch(e){ return ''; }
}
function setDismissedCustomAlertSignature(sig){
  try{ localStorage.setItem(customAlertDismissKey(), String(sig||'')); }catch(e){}
}

function customAlertShownKey(){
  return 'alfastore_custom_alert_shown_' + String(STORE_ID || 'guest').toUpperCase();
}
function getSessionShownCustomAlertSignature(){
  try{ return sessionStorage.getItem(customAlertShownKey()) || ''; }catch(e){ return ''; }
}
function setSessionShownCustomAlertSignature(sig){
  try{ sessionStorage.setItem(customAlertShownKey(), String(sig||'')); }catch(e){}
}
function ensureCustomAlertPopup(){
  let wrap = document.getElementById('customAlertPopupOverlay');
  if(wrap) return wrap;
  wrap = document.createElement('div');
  wrap.id = 'customAlertPopupOverlay';
  wrap.className = 'custom-alert-popup-overlay';
  wrap.innerHTML = `
    <div class="custom-alert-popup-card" role="dialog" aria-modal="true" aria-labelledby="customAlertPopupTitle">
      <button type="button" class="custom-alert-popup-x" onclick="closeCustomAlertPopup()" aria-label="Tutup">×</button>
      <div class="custom-alert-popup-head">
        <div class="custom-alert-popup-icon">!</div>
        <h3 class="custom-alert-popup-title" id="customAlertPopupTitle"></h3>
      </div>
      <div class="custom-alert-popup-text" id="customAlertPopupText"></div>
      <div class="custom-alert-popup-actions">
        <a class="custom-alert-popup-button" id="customAlertPopupButton" target="_blank" rel="noopener" style="display:none"></a>
      </div>
      <button type="button" class="custom-alert-popup-never" onclick="dismissCustomAlertPopup()">Jangan Munculkan Lagi</button>
    </div>`;
  document.body.appendChild(wrap);
  wrap.addEventListener('click', function(e){ if(e.target === wrap) closeCustomAlertPopup(); });
  return wrap;
}
function closeCustomAlertPopup(){
  const wrap = document.getElementById('customAlertPopupOverlay');
  if(wrap) wrap.classList.remove('show');
}
function dismissCustomAlertPopup(){
  const wrap = document.getElementById('customAlertPopupOverlay');
  const sig = wrap ? (wrap.getAttribute('data-alert-signature') || '') : '';
  if(sig) setDismissedCustomAlertSignature(sig);
  closeCustomAlertPopup();
}
function renderCustomAlert(data){
  const legacyWrap = document.getElementById("customAlertCard");
  const legacyBlock = document.getElementById("specialAlertBlock");
  if(legacyWrap) legacyWrap.style.display = "none";
  if(legacyBlock) legacyBlock.classList.remove("show");
  const hasAlert = !!(data && data.enabled && data.title && data.message);
  if(!hasAlert) return false;
  const sig = customAlertSignature(data);
  if(sig && getDismissedCustomAlertSignature() === sig) return false;
  // Tampilkan alert setelah login. Tombol X hanya menutup sementara;
  // tombol Jangan Munculkan Lagi menyembunyikan alert yang sama secara permanen untuk kode toko ini.
  const wrap = ensureCustomAlertPopup();
  wrap.setAttribute('data-alert-signature', sig);
  const titleEl = document.getElementById('customAlertPopupTitle');
  const textEl = document.getElementById('customAlertPopupText');
  const btnEl = document.getElementById('customAlertPopupButton');
  if(titleEl) titleEl.textContent = String(data.title || '');
  if(textEl) textEl.textContent = String(data.message || '');
  const btnText = String(data.buttonText || '').trim();
  const btnUrl = String(data.buttonUrl || '').trim();
  if(btnEl){
    if(btnText && btnUrl){
      btnEl.textContent = btnText;
      btnEl.href = btnUrl;
      btnEl.style.display = 'inline-flex';
    }else{
      btnEl.style.display = 'none';
      btnEl.removeAttribute('href');
    }
  }
  setTimeout(()=>{ wrap.classList.add('show'); }, 180);
  return false;
}

async function loadBanner(){
  const specialWrap = document.getElementById("specialContentWrap");
  const bannerBlock = document.getElementById("specialBannerBlock");
  const alertBlock = document.getElementById("specialAlertBlock");
  const delBtn = document.getElementById("btnDeleteBanner");
  const st = document.getElementById("bannerStatus");
  let hasBanner = false;
  let hasAlert = false;
  try{
    const [bannerRes, alertRes] = await Promise.all([
      fetch("?api=banner_get", {credentials:"same-origin"}),
      fetch("?api=alert_get", {credentials:"same-origin"})
    ]);
    const j = await bannerRes.json().catch(()=>null);
    const a = await alertRes.json().catch(()=>null);
    if(j && j.ok && j.url){
      placeholder.src = j.url;
      placeholder.style.display = "block";
      if(bannerBlock) bannerBlock.classList.add("show");
      hasBanner = true;
      if(st) st.textContent = "ADA";
    }else{
      placeholder.style.display = "none";
      placeholder.removeAttribute("src");
      if(bannerBlock) bannerBlock.classList.remove("show");
      if(st) st.textContent = "KOSONG";
    }
    hasAlert = renderCustomAlert(a && a.ok ? a : null);
    if(!hasAlert && alertBlock) alertBlock.classList.remove("show");
    if(delBtn) delBtn.style.display = (IS_ADMIN && hasBanner) ? "inline-flex" : "none";
    if(specialWrap) specialWrap.style.display = (hasBanner || hasAlert) ? "flex" : "none";
    setBannerMode(hasBanner || hasAlert);
    return hasBanner || hasAlert;
  }catch(e){
    if(delBtn) delBtn.style.display = "none";
    placeholder.style.display = "none";
    placeholder.removeAttribute("src");
    renderCustomAlert(null);
    if(specialWrap) specialWrap.style.display = "none";
    setBannerMode(false);
    return false;
  }
}

let _IFRAME_LOAD_TOKEN = 0;

// onload/onerror global handler (mencegah loading nyangkut)
contentFrame.addEventListener('load', ()=>{
  hideLoading();
  try{
    const current = String(contentFrame.getAttribute('src') || contentFrame.src || '');
    if(!/[?&]page=plano(?:&|$)/i.test(current)) return;
    const doc = contentFrame.contentDocument || (contentFrame.contentWindow && contentFrame.contentWindow.document);
    if(!doc) return;
    const candidates = Array.from(doc.querySelectorAll('button,a,[role="button"]'));
    let back = candidates.find(el => /kembali/i.test(String(el.textContent || '') + ' ' + String(el.getAttribute('aria-label') || '') + ' ' + String(el.title || '')));
    if(!back) back = candidates.find(el => /(^|\s)[←‹](\s|$)/.test(String(el.textContent || '').trim()));
    if(back){
      back.textContent = 'Pilih Rack';
      back.setAttribute('aria-label','Pilih Rack');
      back.setAttribute('title','Pilih Rack');
      back.style.setProperty('background','#fff','important');
      back.style.setProperty('color','#1d4ed8','important');
      back.style.setProperty('border','0','important');
      back.style.setProperty('border-radius','10px','important');
      back.style.setProperty('padding','10px 14px','important');
      back.style.setProperty('font-weight','900','important');
      back.style.setProperty('box-shadow','none','important');
      const replacement = back.cloneNode(true);
      back.parentNode.replaceChild(replacement, back);
      replacement.addEventListener('click', function(e){
        e.preventDefault(); e.stopPropagation();
        showPlanogramRackPicker();
      }, true);
    }
  }catch(e){}
});
contentFrame.addEventListener('error', ()=>{ hideLoading(); });

function setIframeUrl(url, withZoom=true){
  try{
    const parsedExternal = new URL(String(url || ''), location.href);
    if(parsedExternal.hostname.toLowerCase() === 'hossomwv0201.sat.co.id'){
      hideLoading();
      window.open(parsedExternal.href, '_blank', 'noopener,noreferrer');
      return;
    }
  }catch(e){}
  resetZoom();
  setBannerMode(false);
  placeholder.style.display="none";
  placeholder.removeAttribute("src");
  const specialWrap = document.getElementById("specialContentWrap");
  const bannerBlock = document.getElementById("specialBannerBlock");
  const alertBlock = document.getElementById("specialAlertBlock");
  if(specialWrap) specialWrap.style.display="none";
  if(bannerBlock) bannerBlock.classList.remove("show");
  if(alertBlock) alertBlock.classList.remove("show");
  contentFrame.style.display="block";
  showZoom(!!withZoom);
  lastFrameWithZoom = !!withZoom;
  lastFrameUrl = url;
  lastFrameHtml = '';
  const token = ++_IFRAME_LOAD_TOKEN;

  showLoading("Loading…");

  // hentikan loading sebelumnya agar perpindahan iframe lebih cepat
  try{ contentFrame.removeAttribute("srcdoc"); }catch(e){}
  try{ contentFrame.src = "about:blank"; }catch(e){}

  // fallback: jangan biarkan overlay terlalu lama (cross-domain kadang onload tidak terpanggil)
  setTimeout(()=>{ if(token === _IFRAME_LOAD_TOKEN) hideLoading(); }, 1600);

  requestAnimationFrame(()=>{ try{ contentFrame.src = url; }catch(e){ hideLoading(); } });
}
function setIframeHtml(html, withZoom=true){
  resetZoom();
  setBannerMode(false);
  placeholder.style.display="none";
  placeholder.removeAttribute("src");
  const specialWrap = document.getElementById("specialContentWrap");
  const bannerBlock = document.getElementById("specialBannerBlock");
  const alertBlock = document.getElementById("specialAlertBlock");
  if(specialWrap) specialWrap.style.display="none";
  if(bannerBlock) bannerBlock.classList.remove("show");
  if(alertBlock) alertBlock.classList.remove("show");
  contentFrame.style.display="block";
  showZoom(!!withZoom);
  lastFrameWithZoom = !!withZoom;
  lastFrameHtml = html;
  lastFrameUrl = '';
  hideLoading();
  contentFrame.srcdoc = html;
}
function refreshIframe(){
  if(lastFrameUrl){
    const cur = lastFrameUrl;
    const token = ++_IFRAME_LOAD_TOKEN;
    showLoading("Loading…");
    setTimeout(()=>{ if(token===_IFRAME_LOAD_TOKEN) hideLoading(); }, 1800);
    contentFrame.src = cur + (cur.includes('?') ? '&' : '?') + '_r=' + Date.now();
    return;
  }
  if(lastFrameHtml){
    setIframeHtml(lastFrameHtml, lastFrameWithZoom);
  }
}

/* FULLSCREEN */
function isFullscreen(){ return !!document.fullscreenElement; }
async function enterFullscreen(){ try{ await frameCard.requestFullscreen(); }catch(e){ alert("Browser tidak mendukung full screen."); } }
async function exitFullscreen(){ try{ if(document.exitFullscreen) await document.exitFullscreen(); }catch(e){} }
async function handleFrameBack(){
  const returnToReport = String(window.__CIBILI_FRAME_RETURN_TARGET__ || '') === 'reportPopup';
  if(isFullscreen()) await exitFullscreen();
  if(returnToReport && typeof window.closeFramePopup === 'function') window.closeFramePopup();
}
document.addEventListener("fullscreenchange", ()=>{
  const on = isFullscreen();
  btnBack.style.display = on ? "inline-block" : "none";
  btnFull.style.display = on ? "none" : "inline-block";
  if(contentFrame.style.display==="block") applyZoom();
});


function formatPresenceTime(ts){
  const n = Number(ts || 0);
  if(!n) return '-';
  try{
    const d = new Date(n * 1000);
    const now = new Date();
    const startToday = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
    const startThat = new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
    const diffDays = Math.round((startToday - startThat) / 86400000);
    const jam = d.toLocaleTimeString('id-ID', {hour:'2-digit', minute:'2-digit'});
    if(diffDays === 0) return 'Hari ini, ' + jam;
    if(diffDays === 1) return 'Kemarin, ' + jam;
    const tanggal = d.toLocaleDateString('id-ID', {day:'2-digit', month:'long', year:'numeric'});
    return 'Tanggal ' + tanggal + ', ' + jam;
  }catch(e){ return '-'; }
}
async function pingPresence(){
  if(!STORE_ID || document.hidden) return;
  try{
    await fetch(`${API_URL}?api=presence_ping`, {
      method:'POST',
      cache:'no-store',
      credentials:'same-origin',
      keepalive:true,
      headers:{
        'Content-Type':'application/json',
        'X-Requested-With':'XMLHttpRequest',
        'X-CIBILI-Page-Visible':'1',
        'X-CIBILI-Session-Recover':'1'
      },
      body:'{}'
    });
  }catch(e){}
}
function sendPresenceOffline(){
  if(!STORE_ID) return;
  try{
    const url = `${API_URL}?api=presence_offline`;
    if(navigator.sendBeacon){
      navigator.sendBeacon(url, new Blob([], {type:'text/plain'}));
      return;
    }
    fetch(url, { method:'POST', cache:'no-store', credentials:'same-origin', keepalive:true }).catch(()=>{});
  }catch(e){}
}
function startPresenceHeartbeat(){
  if(!STORE_ID) return;
  pingPresence();
  if(PRESENCE_PING_TIMER) clearInterval(PRESENCE_PING_TIMER);
  PRESENCE_PING_TIMER = setInterval(()=>{ if(!document.hidden) pingPresence(); }, 5000);
}
function bindPresenceVisibility(){
  if(!STORE_ID || window.__presenceBound) return;
  window.__presenceBound = true;
  document.addEventListener('visibilitychange', ()=>{
    // Android sering menyembunyikan tab sesaat saat kamera, file picker, atau
    // perpindahan halaman dibuka. Jangan tandai logout/offline pada kejadian itu.
    if(!document.hidden) pingPresence();
  });
  window.addEventListener('focus', ()=>{ if(!document.hidden) pingPresence(); });
  document.addEventListener('resume', ()=>{ if(!document.hidden) pingPresence(); });
}

const POST_LOGIN_LOADING_KEY = 'CIBILI_POST_LOGIN_LOADING_V2';
let POST_LOGIN_LOADING_TIMER = null;
function ensurePostLoginLoading(){
  let overlay = document.getElementById('cibiliPostLoginLoading');
  if(overlay) return overlay;
  const style = document.createElement('style');
  style.id = 'cibiliPostLoginLoadingStyle';
  style.textContent = `
    #cibiliPostLoginLoading{position:fixed;inset:0;z-index:2147483647;display:none;align-items:center;justify-content:center;padding:24px;background:linear-gradient(145deg,rgba(232,243,255,.98),rgba(255,255,255,.99));font-family:Inter,system-ui,-apple-system,"Segoe UI",sans-serif;transition:opacity .18s ease}
    #cibiliPostLoginLoading.show{display:flex}
    #cibiliPostLoginLoading .ready-card{width:min(360px,100%);padding:30px 24px;border:1px solid #bfdbfe;border-radius:24px;background:#fff;text-align:center;box-shadow:0 26px 70px rgba(30,64,175,.20)}
    #cibiliPostLoginLoading .ready-spinner{width:58px;height:58px;margin:0 auto 18px;border:6px solid #dbeafe;border-top-color:#1673e6;border-radius:50%;animation:cibiliReadySpin .78s linear infinite}
    #cibiliPostLoginLoading .ready-title{color:#12315f;font-size:21px;font-weight:1000;line-height:1.25}
    #cibiliPostLoginLoading .ready-sub{margin-top:8px;color:#64748b;font-size:13px;font-weight:750;line-height:1.5}
    @keyframes cibiliReadySpin{to{transform:rotate(360deg)}}`;
  document.head.appendChild(style);
  overlay = document.createElement('div');
  overlay.id = 'cibiliPostLoginLoading';
  overlay.setAttribute('role','status');
  overlay.setAttribute('aria-live','polite');
  overlay.innerHTML = '<div class="ready-card"><div class="ready-spinner"></div><div class="ready-title" id="cibiliPostLoginTitle">Menyiapkan halaman...</div><div class="ready-sub">Mohon tunggu sampai data utama siap digunakan.</div></div>';
  document.body.appendChild(overlay);
  return overlay;
}
function showPostLoginLoading(message='Menyiapkan semua halaman...'){
  const overlay = ensurePostLoginLoading();
  const title = document.getElementById('cibiliPostLoginTitle');
  if(title) title.textContent = message;
  overlay.style.opacity = '1';
  overlay.classList.add('show');
  clearTimeout(POST_LOGIN_LOADING_TIMER);
  POST_LOGIN_LOADING_TIMER = setTimeout(()=>hidePostLoginLoading(true), 15000);
}
function setPostLoginLoadingPending(){
  try{ sessionStorage.setItem(POST_LOGIN_LOADING_KEY, '1'); }catch(e){}
}
function isPostLoginLoadingPending(){
  try{ return sessionStorage.getItem(POST_LOGIN_LOADING_KEY) === '1'; }catch(e){ return false; }
}
function hidePostLoginLoading(immediate=false){
  clearTimeout(POST_LOGIN_LOADING_TIMER);
  POST_LOGIN_LOADING_TIMER = null;
  try{ sessionStorage.removeItem(POST_LOGIN_LOADING_KEY); }catch(e){}
  const overlay = document.getElementById('cibiliPostLoginLoading');
  if(!overlay) return;
  if(immediate){ overlay.classList.remove('show'); overlay.style.opacity = ''; return; }
  const title = document.getElementById('cibiliPostLoginTitle');
  if(title) title.textContent = 'Halaman siap digunakan';
  setTimeout(()=>{
    overlay.style.opacity = '0';
    setTimeout(()=>{ overlay.classList.remove('show'); overlay.style.opacity = ''; }, 180);
  }, 220);
}

function startAdminAutoRefresh(){
  if(ADMIN_AUTO_REFRESH_TIMER) clearInterval(ADMIN_AUTO_REFRESH_TIMER);
  ADMIN_AUTO_REFRESH_TIMER = setInterval(()=>{
    const modal = document.getElementById("adminModal");
    if(!(modal && modal.style.display === "flex")) return;
    adminReload(false);
  }, 2500);
}
function stopAdminAutoRefresh(){
  if(ADMIN_AUTO_REFRESH_TIMER){
    clearInterval(ADMIN_AUTO_REFRESH_TIMER);
    ADMIN_AUTO_REFRESH_TIMER = null;
  }
}

async function loadStoreHeader2(){
  try{
    const storeId = String(STORE_ID||"").trim().toUpperCase();
    if(!storeId) return;
    // pakai endpoint internal yang mengambil dari API status_toko
    const res = await fetch(`?api=store_detail&storeId=${encodeURIComponent(storeId)}`);
    const j = await res.json().catch(()=>null);
    const name = (j && j.ok && j.header2) ? String(j.header2).trim() : "";
    // Developer M604 PIN 2727 tampil sebagai DEVELOPER di bawah header.
    document.getElementById("storeText").textContent = (IS_ADMIN && storeId === 'M604') ? 'DEVELOPER' : (name || storeId);
    // nama toko bisa panjang -> header jadi lebih tinggi; update padding konten di bawahnya
    requestAnimationFrame(()=>updateHeaderSpacing());
  }catch(e){
    document.getElementById("storeText").textContent = String(STORE_ID||"");
    requestAnimationFrame(()=>updateHeaderSpacing());
  }
}

/* INIT */
(async function init(){
  const postLoginPending = isPostLoginLoadingPending();
  if(postLoginPending) showPostLoginLoading('Menyiapkan semua halaman...');
  if(STORE_ID){
    document.getElementById("storeText").textContent = (IS_ADMIN && String(STORE_ID||"").toUpperCase()==='M604') ? 'DEVELOPER' : String(STORE_ID||"");
    const headerReady = loadStoreHeader2();
    updateHeaderSpacing();
    syncTodayFields(true);

    // Prioritas tampilan utama:
    // 1) banner iframe/placeholder
    // 2) jika banner tidak ada, baru tampilkan cache Clerek
    const hasBanner = await loadBanner();
    if(!hasBanner){
      showSavedClerekResult();
    }

    startPresenceHeartbeat();
    bindPresenceVisibility();
    startExpiryCountdown(EXPIRY_TS);
    const loginRemainingDays = getExpiryWarningDays(EXPIRY_TS);
    maybeShowExpiryWarning(loginRemainingDays);
    // auto refresh tanggal jika tab dibiarkan terbuka melewati jam 00:00
    setInterval(()=>syncTodayFields(false), 20000);
    if(postLoginPending){
      await Promise.allSettled([Promise.resolve(headerReady), Promise.resolve(pingPresence())]);
      await new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));
      hidePostLoginLoading(false);
    }
  }else if(postLoginPending){
    hidePostLoginLoading(true);
  }
})();

/* AUTH */
async function login(){
  const loginPage = document.getElementById("loginPage");
  const quickPinInput = document.getElementById("quickPinInput");
  const hiddenPinInput = document.getElementById("pinInput");
  if(loginPage && loginPage.classList.contains("saved-store-mode") && quickPinInput && hiddenPinInput){
    hiddenPinInput.value = String(quickPinInput.value || "").replace(/[^0-9]/g,'').slice(0,4);
  }
  const v = (document.getElementById("storeInput").value||"").trim().toUpperCase().replace(/[^A-Z0-9]/g,'').slice(0,4);
  const pin = (document.getElementById("pinInput").value||"").trim().replace(/[^0-9]/g,'');
  const otp = (document.getElementById("otpInput").value||"").trim().replace(/[^A-Za-z0-9]/g,'');
  const errorMsg = document.getElementById("errorMsg");
  const helpBtn = document.getElementById("helpBtn");
  const helpEnabled = !helpBtn || String(helpBtn.dataset.enabled || "1") === "1";
  errorMsg.textContent=""; if(helpBtn) helpBtn.style.display = helpEnabled ? "block" : "none";
  const adminOtpOnly = (!v && !pin && otp.length > 0);
  const sograndOtpLogin = (v && !pin && otp.length > 0);
  if(!adminOtpOnly && !sograndOtpLogin){
    if(!v){ errorMsg.textContent="Kode toko kosong"; return; }
    if(pin.length !== 4){ errorMsg.textContent="PIN harus 4 angka"; return; }
  }
  showPostLoginLoading('Memeriksa login...');
  try{
    const res = await fetch("?api=login",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({storeId:v,pin,otp})});
    const j = await res.json();
    if(!j.ok){ hidePostLoginLoading(true); errorMsg.textContent=j.msg||"Gagal login"; if(helpBtn) helpBtn.style.display = helpEnabled ? "block" : "none"; return; }
    if(j.isAdmin){
      try{ setAdminAuth(Date.now() + 86400000); }catch(_adminAuthError){}
    }
    const rememberedStore = String((j && j.storeId) || v || '').toUpperCase().replace(/[^A-Z0-9]/g,'').slice(0,4);
    if(rememberedStore){
      try{
        localStorage.setItem('CIBILI_LAST_STORE_CODE', rememberedStore);
        localStorage.setItem('ALFASTORE_LOGIN2_STORE_CODE', rememberedStore);
      }catch(_e){}
    }
    if(j.redirect){
      try{ sessionStorage.removeItem(POST_LOGIN_LOADING_KEY); }catch(e){}
      const loadingTitle = document.getElementById('cibiliPostLoginTitle');
      if(loadingTitle) loadingTitle.textContent = 'Membuka halaman...';
      window.location.href = j.redirect;
      return;
    }
    try{
      const sid = String((j && j.storeId) || v || '').toUpperCase();
      if(sid){
        sessionStorage.removeItem('alfastore_custom_alert_dismiss_' + sid);
        sessionStorage.removeItem('alfastore_custom_alert_shown_' + sid);
      }
    }catch(_){ }
    /* Login admin masuk ke halaman utama dulu; admin dibuka manual dari tombol ADMIN. */
    const warningDays = (j.expiryWarning && Number(j.expiryWarning.days||0) > 0) ? Number(j.expiryWarning.days||0) : getExpiryWarningDays(EXPIRY_TS);
    if(j.expiryWarning && warningDays >= 1 && warningDays <= 3){
      setPostLoginLoadingPending();
      showPostLoginLoading('Menyiapkan semua halaman...');
      maybeShowExpiryWarning(warningDays);
      setTimeout(()=>{ location.reload(); }, 250);
      return;
    }
    setPostLoginLoadingPending();
    showPostLoginLoading('Menyiapkan semua halaman...');
    location.reload();
  }catch(e){ hidePostLoginLoading(true); errorMsg.textContent="Koneksi gagal"; }
}
let logoutBusy = false;
function logout(ev){
  if(ev){ try{ ev.preventDefault(); ev.stopPropagation(); }catch(e){} }
  if(logoutBusy) return false;
  logoutBusy = true;
  const btns = Array.from(document.querySelectorAll('[onclick*=\"logout\"]'));
  btns.forEach(btn=>{ try{ btn.disabled = true; btn.style.pointerEvents = 'none'; btn.style.opacity = '0.7'; }catch(e){} });
  try{ sendPresenceOffline(); }catch(e){}

  try{
    if(navigator.sendBeacon){
      const blob = new Blob(['{}'], {type:'application/json'});
      navigator.sendBeacon('?api=logout', blob);
    }else{
      fetch('?api=logout', {
        method:'POST',
        cache:'no-store',
        credentials:'same-origin',
        keepalive:true,
        headers:{
          'Content-Type':'application/json',
          'Cache-Control':'no-store, no-cache, must-revalidate',
          'Pragma':'no-cache'
        },
        body:'{}'
      }).catch(()=>{});
    }
  }catch(e){}

  const target = 'index.php?logout=1&_=' + Date.now();
  try{ window.location.replace(target); }
  catch(e){ window.location.href = target; }
  return false;
}

// Enter mengikuti langkah login yang sedang tampil: kode toko, PIN, atau Key OTP.
document.addEventListener('keydown', (e)=>{
  const lp = document.getElementById('loginPage');
  if(!lp || lp.style.display === 'none' || e.key !== 'Enter' || e.defaultPrevented) return;
  const otpModal = document.getElementById('otpKeyModal');
  if(otpModal && (otpModal.classList.contains('is-open') || otpModal.style.display === 'flex')){
    e.preventDefault();
    if(typeof submitOtpKeyLogin === 'function') submitOtpKeyLogin();
    return;
  }
  const form = document.getElementById(lp.classList.contains('saved-store-mode') ? 'quickPinLoginForm' : 'pinLoginForm');
  if(!form) return;
  e.preventDefault();
  if(typeof form.requestSubmit === 'function') form.requestSubmit();
  else form.dispatchEvent(new Event('submit', {bubbles:true,cancelable:true}));
});

try{
  const needOpenAdminPass = localStorage.getItem('ALFASTORE_OPEN_ADMIN_PASS_AFTER_LOGOUT') === '1';
  if(needOpenAdminPass){
    localStorage.removeItem('ALFASTORE_OPEN_ADMIN_PASS_AFTER_LOGOUT');
    setTimeout(()=>{
      try{
        modalOpen();
        const m = document.getElementById('adminPassModal');
        const err = document.getElementById('adminPassErr');
        const inp = document.getElementById('adminPassInput');
        if(err){ err.style.display = 'none'; err.textContent = ''; }
        if(inp){ inp.value = ''; }
        if(m) m.style.display = 'flex';
        if(inp) inp.focus();
      }catch(e){}
    }, 180);
  }
  const needOpenAdminModal = localStorage.getItem('ALFASTORE_OPEN_ADMIN_MODAL') === '1';
  if(needOpenAdminModal){
    localStorage.removeItem('ALFASTORE_OPEN_ADMIN_MODAL');
    setTimeout(()=>{
      try{ if(IS_ADMIN){ openAdminModal(); } }catch(e){}
    }, 180);
  }
}catch(e){}

/* POPUPS */
function openStockOpnamePopup(){
  modalOpen();
  document.getElementById("stockPopup").style.display="flex";
  document.getElementById("soRupiahWrap").style.display="none";
  document.getElementById("soRupiahDate").value = todayISO();
}
function closeStockOpnamePopup(){
  document.getElementById("stockPopup").style.display="none";
  document.getElementById("soRupiahWrap").style.display="none";
  modalClose();
}

function openReportPopup(){
  modalOpen();
  document.getElementById("reportPopup").style.display="flex";
}

// Tandai bahwa halaman hasil dibuka dari menu Laporan. Penanda ini dipakai
// header hasil dan tombol Back Android untuk kembali ke popup Laporan, bukan
// keluar dari aplikasi.
function prepareReportFrameReturn(){
  window.__CIBILI_FRAME_RETURN_TARGET__ = 'reportPopup';
  if(typeof window.cibiliPrepareFrameReturn === 'function'){
    window.cibiliPrepareFrameReturn('reportPopup');
  }
}
function closeReportPopup(){
  const reportPopup = document.getElementById("reportPopup");
  const reportDatePopup = document.getElementById("reportDatePopup");
  if(reportPopup) reportPopup.style.display = "none";
  if(reportDatePopup) reportDatePopup.style.display = "none";
  window.REPORT_DATE_SELECTION = null;
  modalClose();
}

function openLainnyaPopup(){
  modalOpen();
  document.getElementById("lainnyaPopup").style.display="flex";
}
function closeLainnyaPopup(){
  document.getElementById("lainnyaPopup").style.display="none";
  modalClose();
}

function ensurePlanogramRackPicker(){
  let modal = document.getElementById('planogramRackPicker');
  if(modal) return modal;
  modal = document.createElement('div');
  modal.id = 'planogramRackPicker';
  modal.className = 'modal';
  modal.setAttribute('aria-hidden','true');
  modal.innerHTML = `
    <div class="modal-box" style="width:min(520px,94vw);max-height:86vh;overflow:auto;text-align:left;border-radius:18px;padding:18px;background:#fff;box-shadow:0 18px 48px rgba(0,0,0,.35)">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px">
        <h3 style="margin:0;color:#111827;font-size:21px;font-weight:1000">Pilih Rack</h3>
        <button type="button" onclick="closePlanogramRackPicker()" aria-label="Tutup" style="width:38px;height:38px;border:1px solid #111827;border-radius:10px;background:#fff;color:#111827;font-size:24px;font-weight:900;cursor:pointer;box-shadow:0 4px 10px rgba(0,0,0,.18)">×</button>
      </div>
      <div id="planogramRackStatus" style="display:none;margin-bottom:10px;border-radius:10px;padding:10px 12px;background:#fff;color:#111827;border:1px solid #d1d5db;font-size:13px;font-weight:850;box-shadow:0 4px 10px rgba(0,0,0,.12)"></div>
      <div id="planogramRackList" style="display:grid;grid-template-columns:1fr;gap:9px"></div>
    </div>`;
  modal.style.setProperty('z-index','2147483000','important');
  modal.addEventListener('click', function(e){ if(e.target === modal) closePlanogramRackPicker(); });
  document.body.appendChild(modal);
  return modal;
}
let PLANOGRAM_RACKS = [];
function normalizePlanogramRack(value){ return String(value || '').toUpperCase().replace(/[^A-Z0-9_-]/g,'').slice(0,30); }
function renderPlanogramRackList(){
  const list = document.getElementById('planogramRackList');
  if(!list) return;
  if(!PLANOGRAM_RACKS.length){
    list.innerHTML = '<div style="padding:16px;text-align:center;border:1px solid #d1d5db;border-radius:10px;background:#fff;color:#111827;font-weight:850;box-shadow:0 4px 10px rgba(0,0,0,.12)">Daftar rack tidak tersedia.</div>';
    return;
  }
  list.innerHTML = PLANOGRAM_RACKS.map(r => `<button type="button" data-rack="${String(r).replace(/"/g,'&quot;')}" style="width:100%;min-height:48px;border:1px solid #111827;border-radius:10px;background:#fff;color:#111827;font-size:15px;font-weight:1000;cursor:pointer;padding:11px 14px;text-align:left;box-shadow:0 5px 12px rgba(0,0,0,.18)">${r}</button>`).join('');
  list.querySelectorAll('[data-rack]').forEach(btn => btn.addEventListener('click', function(){
    openSelectedPlanogramRack(this.getAttribute('data-rack') || '');
  }));
}
async function loadPlanogramRacks(){
  const status = document.getElementById('planogramRackStatus');
  const list = document.getElementById('planogramRackList');
  if(status){ status.style.display='block'; status.textContent='Memuat daftar rack...'; status.style.background='#fff'; status.style.color='#111827'; }
  if(list) list.innerHTML='';
  try{
    const res = await fetch(`?api=planogram_rack_list&_=${Date.now()}`, {cache:'no-store',credentials:'same-origin',headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});
    const data = await res.json().catch(()=>null);
    if(!res.ok || !data || !data.ok) throw new Error((data && (data.msg || data.error)) || 'Gagal memuat daftar rack');
    PLANOGRAM_RACKS = Array.isArray(data.racks) ? data.racks.map(normalizePlanogramRack).filter(Boolean) : [];
    PLANOGRAM_RACKS = [...new Set(PLANOGRAM_RACKS)].sort((a,b)=>a.localeCompare(b,undefined,{numeric:true,sensitivity:'base'}));
    if(status){ status.style.display = PLANOGRAM_RACKS.length ? 'none' : 'block'; status.textContent = PLANOGRAM_RACKS.length ? '' : 'Daftar rack kosong.'; }
    renderPlanogramRackList();
  }catch(err){
    PLANOGRAM_RACKS=[];
    if(status){ status.style.display='block'; status.style.background='#fff'; status.style.color='#111827'; status.textContent=(err && err.message) ? err.message : 'Gagal memuat rack.'; }
    renderPlanogramRackList();
  }
}
function openSelectedPlanogramRack(value){
  const rack = normalizePlanogramRack(value || '');
  if(!rack) return;
  try{ localStorage.setItem('cibili_last_planogram_rack', rack); }catch(e){}
  if(typeof window.cibiliActivityNow === 'function') window.cibiliActivityNow('Planogram + OH - Rack ' + rack,'api:plano');
  closePlanogramRackPicker();
  const sid = (typeof STORE_ID !== 'undefined' && STORE_ID) ? STORE_ID : (document.querySelector('script[data-store-id]')?.getAttribute('data-store-id') || '');
  const url = `?page=plano&rack=${encodeURIComponent(rack)}${sid ? ('&storeId=' + encodeURIComponent(sid)) : ''}&_=${Date.now()}`;
  if(typeof setIframeUrl === 'function') setIframeUrl(url, true);
  else if(window.top && window.top !== window.self) window.top.location.href = url;
  else window.location.href = url;
}
function closePlanogramRackPicker(){
  const modal = document.getElementById('planogramRackPicker');
  if(modal){ modal.style.display='none'; modal.setAttribute('aria-hidden','true'); }
  if(typeof modalClose === 'function') modalClose();
}
function showPlanogramRackPicker(){
  const modal = ensurePlanogramRackPicker();
  if(typeof modalOpen === 'function') modalOpen();
  modal.style.display='flex';
  modal.setAttribute('aria-hidden','false');
  loadPlanogramRacks();
}
function openPlanogramOH(){
  if(typeof closeLainnyaPopup === 'function') closeLainnyaPopup();
  try{ localStorage.removeItem('cibili_last_planogram_rack'); }catch(e){}
  if(typeof window.cibiliActivityNow === 'function') window.cibiliActivityNow('Planogram + OH','api:plano');
  const sid = (typeof STORE_ID !== 'undefined' && STORE_ID) ? STORE_ID : '';
  const url = `?page=plano${sid ? ('&storeId=' + encodeURIComponent(sid)) : ''}&_=${Date.now()}`;
  if(typeof setIframeUrl === 'function') setIframeUrl(url, true);
  else window.location.href = url;
}




function buildPortalInternalHtml(){
  const portals = [
    {label:'Intranet', url:'https://intranet.sat.co.id/'},
    {label:'Human Capital', url:'https://intranet.sat.co.id/humancapital/public/index/mainmenu'},
    {label:'RRAK', url:'https://intranet.sat.co.id/rrak/public/'},
    {label:'BST Online', url:'https://intranet.sat.co.id/bst_online/signin'},
    {label:'Asset Online', url:'https://intranet.sat.co.id/asset/public/index'},
    {label:'KOPKAR', url:'https://intranet.sat.co.id/koperasi/public/'},
    {label:'SO Karyawan', url:'https://hosokartoko0201.sat.co.id'},
    {label:'SO MWV', url:'https://hossomwv0201.sat.co.id/'},
    {label:'Auto BTL', url:'https://hovab0201.sat.co.id/index'},
    {label:'OTP Online', url:'https://hootp0202.sat.co.id/public/'},
    {label:'RPO Online', url:'https://rpo.sat.co.id/public/'},
    {label:'Helpdesk', url:'https://intranet.sat.co.id/offices/public/'},
    {label:'Asset Online', url:'https://intranet.sat.co.id/asset/public/'},
    {label:'Alfalearning', url:'https://alfalearning.sat.co.id/login/index.php'},
    {label:'Alfashare', url:'https://alfashare.sat.co.id/?p=47687'},
    {label:'Reset pin Hc', url:'https://hcpinreset0201.sat.co.id'},
    {label:'Reset pin Etrans', url:'https://intranet.sat.co.id/pinreset-etrans/public/index/reset'},
    {label:'Reset Device Alfaone(absensi)', url:'https://qrcode-absensi-reset-device-rjzigbgnna-et.a.run.app/'}
  ];
  const cards = portals.map(item => {
    const label = escapeHtml(item.label);
    const url = escapeHtml(item.url);
    return `<div class="card"><a href="${url}" target="_blank" rel="noopener">${label}</a></div>`;
  }).join('');
  return `<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
  *{box-sizing:border-box}
  body{margin:0;padding:16px;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#f4f6fb;color:#111827}
  .wrap{max-width:980px;margin:0 auto}
  .title{font-size:20px;font-weight:900;margin:0 0 12px;color:#312e81}
  .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;align-items:stretch}
  .card{height:52px;border-radius:5px;background:#fff;border:1px solid #dbe3ff;box-shadow:0 8px 18px rgba(15,23,42,.07);overflow:hidden}
  .card a{width:100%;height:100%;display:flex;align-items:center;justify-content:center;text-align:center;padding:10px 12px;text-decoration:none;color:#312e81;font-weight:900;font-size:14px;line-height:1.2}
  .card a:hover{background:linear-gradient(135deg,#4f46e5,#2563eb);color:#fff}
  @media(max-width:520px){body{padding:12px}.grid{grid-template-columns:1fr 1fr;gap:8px}.card{height:50px}.card a{font-size:13px;padding:8px}}
</style>
</head>
<body>
  <div class="wrap">
    <h1 class="title">Portal Internal</h1>
    <div class="grid">${cards}</div>
  </div>
</body>
</html>`;
}

function openPortalInternal(){
  closeLainnyaPopup();
  setIframeHtml(buildPortalInternalHtml(), true);
}

function openIKT(){
  closeLainnyaPopup();
  if(typeof window.cibiliActivityNow === 'function') window.cibiliActivityNow('IKT','api:ikt_dashboard');
  const qs = new URLSearchParams({api:'ikt_dashboard',_:String(Date.now())});
  const internalUrl = `${API_URL}?${qs.toString()}`;
  window.__ALFA_LAST_FRAME_TITLE__ = 'IKT';
  // Tetap berada di aplikasi utama. Rute internal sekarang merender IKT
  // lewat reverse proxy same-origin, sehingga iframe tidak ditolak oleh host
  // eksternal dan setelah NIK/PIN/OTP tetap kembali ke dashboard IKT.
  if(typeof setIframeUrl === 'function') setIframeUrl(internalUrl, true);
  else window.location.assign(internalUrl);
}


function openOHRealTime(){
  closeLainnyaPopup();
  const frame = document.getElementById("contentFrame");
  const iframeWrap = document.getElementById("iframeWrap");
  const special = document.getElementById("specialContentWrap");
  if(special) special.style.display = "none";
  if(iframeWrap){
    iframeWrap.style.display = "block";
    iframeWrap.style.visibility = "";
  }
  const base = (API_URL || location.pathname || "index.php");
  const url = base + "?page=oh_realtime&t=" + Date.now();

  // FIX: Portal Internal memakai iframe srcdoc. Jika srcdoc tidak dibersihkan,
  // browser bisa tetap menampilkan HTML Portal Internal dan Cek OH ( Sedang SO ) tidak terbuka.
  if(typeof setIframeUrl === "function"){
    setIframeUrl(url, true);
    return;
  }

  if(frame){
    const token = ++_IFRAME_LOAD_TOKEN;
    showLoading("Memuat Cek OH ( Sedang SO )...");
    try{ frame.removeAttribute("srcdoc"); }catch(e){}
    try{ frame.src = "about:blank"; }catch(e){}
    frame.style.display = "block";
    frame.onload = function(){ if(token===_IFRAME_LOAD_TOKEN) hideLoading(); frame.style.display = "block"; };
    setTimeout(()=>{ if(token===_IFRAME_LOAD_TOKEN) hideLoading(); }, 1800);
    requestAnimationFrame(()=>{ frame.src = url; });
  }else{
    window.open(url, "_blank");
  }
}

function openSOGrandTaskForce(){
  closeStockOpnamePopup();
  const store = String(STORE_ID || '').trim().toUpperCase().replace(/[^A-Z0-9]/g,'');
  if(!store){
    alert('Kode toko login tidak ditemukan. Silakan login ulang.');
    return;
  }
  window.location.href = `${location.pathname}?page=sogrand_taskforce`;
}

function buildOH979Url(){
  const hasSessionStore = String(STORE_ID || '').trim().toUpperCase().replace(/[^A-Z0-9]/g,'') !== '';
  return hasSessionStore ? '?page=oh979' : '';
}

function buildOHStRokokUrl(){
  const hasSessionStore = String(STORE_ID || '').trim().toUpperCase().replace(/[^A-Z0-9]/g,'') !== '';
  return hasSessionStore ? '?page=oh_st_rokok' : '';
}

async function fetchOH979Config(type){
  const rawKategori = String(type || 'reguler').toLowerCase();
  const kategori = rawKategori === 'beanspot' ? 'beanspot' : (rawKategori === 'strokok' ? 'strokok' : 'reguler');
  try{
    const res = await fetch(`?api=oh979_get_config&type=${encodeURIComponent(kategori)}`, {cache:'no-store', credentials:'same-origin'});
    return await res.json().catch(()=>({status:false, message:'Respon tidak valid'}));
  }catch(e){
    return {status:false, message:'Gagal menghubungi 979.php'};
  }
}

function openOH979MissingModal(msg){
  // Popup OH979 dihapus. Halaman OH979 selalu dibuka di tab baru.
  openOH979();
}
function closeOH979MissingModal(){}
async function openOH979(){
  closeLainnyaPopup();
  window.open(buildOH979Url(), '_blank');
}

function openOHStRokok(){
  closeLainnyaPopup();
  const url = buildOHStRokokUrl();
  if(!url){
    alert('Kode toko login tidak ditemukan. Silakan login ulang.');
    return;
  }
  if(typeof window.cibiliActivityNow === 'function') window.cibiliActivityNow('OH ST / Rokok','page:oh_st_rokok');
  window.location.assign(url);
}

function openCekOH(){
  // pindah dari modal Lainnya ke modal OH tanpa buka/tutup counter (agar tidak flicker)
  document.getElementById("lainnyaPopup").style.display="none";
  document.getElementById("cekOHModal").style.display="flex";

  // default isi kode toko dari store login agar dropdown nama barang langsung bisa dipakai
  const s = (document.getElementById("ohStore").value || "").trim();
  if(!s && STORE_ID){
    document.getElementById("ohStore").value = String(STORE_ID).toUpperCase().replace(/[^A-Z0-9]/g,'');
  }
  // muat list nama barang (debounced)
  if(typeof ohLoadNameListDebounced === "function") ohLoadNameListDebounced();
}






function closeCekOH(){
  document.getElementById("cekOHModal").style.display="none";
  // ini menutup alur modal (dari Lainnya -> OH), jadi kita close 1x
  modalClose();
}

function openClerekModal(){
  if(!IS_PREMIUM){
    openPremiumGate();
    return;
  }
  modalOpen();
  document.getElementById("clerekFileName").textContent = "";
  document.getElementById("clerekZip").value = "";
  const clerekDateInput = document.getElementById("clerekSelectedDate");
  if(clerekDateInput) clerekDateInput.value = todayISO();
  const clerekTodayLabel = document.getElementById("clerekToday");
  if(clerekTodayLabel) clerekTodayLabel.textContent = todayISO();
  const fd = document.getElementById("clerekFileDate");
  if(fd) fd.textContent = "-";
  if(typeof setClerekMsg === "function") setClerekMsg("");
  updateClerekCacheInfo();
  document.getElementById("clerekModal").style.display="flex";
}
function closeClerekModal(){
  document.getElementById("clerekModal").style.display="none";
  modalClose();
}

function updateDailyHint(){ return; }
function toggleDailyWrap(){ return; }
function toggleGabunganWrap(){ return; }
function openReportDatePopup(config){
  const popup = document.getElementById("reportDatePopup");
  const titleEl = document.getElementById("reportDateTitle");
  const labelEl = document.getElementById("reportDateLabel");
  const inputEl = document.getElementById("reportDateInput");
  const hintEl = document.getElementById("reportDateHint");
  if(!popup || !titleEl || !labelEl || !inputEl || !hintEl) return;

  window.REPORT_DATE_SELECTION = {
    type: config.type || "",
    title: config.title || "Pilih Tanggal",
    label: config.label || "Tanggal",
    value: config.value || todayISO(),
    hint: config.hint || "",
    onConfirm: typeof config.onConfirm === "function" ? config.onConfirm : null,
    onCancel: typeof config.onCancel === "function" ? config.onCancel : null
  };

  titleEl.textContent = window.REPORT_DATE_SELECTION.title;
  labelEl.textContent = window.REPORT_DATE_SELECTION.label;
  inputEl.value = window.REPORT_DATE_SELECTION.value;
  hintEl.innerHTML = window.REPORT_DATE_SELECTION.hint;
  popup.style.display = "flex";

  setTimeout(() => {
    try{
      inputEl.focus();
      if(typeof inputEl.showPicker === "function") inputEl.showPicker();
      else inputEl.click();
    }catch(_e){
      try{ inputEl.click(); }catch(_e2){}
    }
  }, 60);
}
function closeReportDatePopup(runCancel = true){
  const popup = document.getElementById("reportDatePopup");
  const cfg = window.REPORT_DATE_SELECTION || null;
  if(popup) popup.style.display = "none";
  window.REPORT_DATE_SELECTION = null;
  if(runCancel && cfg && typeof cfg.onCancel === "function"){
    try{ cfg.onCancel(); }catch(_e){}
  }
}
function confirmReportDateSelection(){
  const inputEl = document.getElementById("reportDateInput");
  const iso = (inputEl?.value || "").trim();
  const cfg = window.REPORT_DATE_SELECTION || null;
  if(!cfg || typeof cfg.onConfirm !== "function"){
    closeReportDatePopup(false);
    return;
  }
  if(!iso){ alert("Pilih tanggal terlebih dahulu"); return; }
  closeReportDatePopup(false);
  cfg.onConfirm(iso);
}
function buildReportUrl(type, iso){
  const store = STORE_ID;
  const uid = STORE_ID;
  const today = iso || todayISO();
  const dmy = formatDMY(today);

  if(type==="daily"){
    const startISO = firstOfMonthISO(today);
    return {
      url: prd('/rpt/laporan/daily_performance', {storeId:store, periode1:formatDMY(startISO), periode2:dmy}),
      hint: `Periode otomatis: <b>${formatDMY(startISO)}</b> s/d <b>${dmy}</b>`
    };
  }
  if(type==="gabungan") return { url: prd('/rpt/laporan/rep_gabungan_23_24', {storeId:store, periode1:dmy}) };
  if(type==="tagdgs") return { url: prd('/rpt/laporan/rpt_plu_discontinue', {storeId:store, filter_tag:'DGS'}) };
  if(type==="plutakmain") return { url: prd('/rpt/laporan/laporan_plu_tak_main_toko', {storeId:store, date:dmy}) };
  if(type==="kasir") return { url: prd('/rpt/laporan/penjualan_per_kasir', {storeId:store, periode1:dmy}) };
  if(type==="pps") return { url: prd('/rpt/laporan/report_pps', {storeId:store, storeDate:dmy, dateTx:dmy}) };
  if(type==="setoran_kasir") return { url: prd('/rpt/laporan/new_setoran_kasir', {storeId:store, userId:uid, periode1:dmy}) };
  if(type==="sales_member"){
    const startISO = firstOfMonthISO(today);
    return {
      url: prd('/rpt/laporan/laporan_sales_member', {storeId:store, periode1:formatDMY(startISO), periode2:dmy}),
      hint: `Periode otomatis: <b>${formatDMY(startISO)}</b> s/d <b>${dmy}</b>`
    };
  }
  if(type==="tipe_kartu") return { url: prd('/rpt/laporan/setoran_per_kasir_detail_tipe_kartu', {storeId:store, userId:uid, periode1:dmy}) };
  if(type==="flazz") return { url: prd('/rpt/laporan/setoran_per_kasir_detail_non_commerce', {storeId:store, userId:uid, periode1:dmy}) };
  if(type==="sewa") return { url: prd('/rpt/laporan/detail_sarana_promosi', {storeId:store, userId:uid, dateTx:dmy}) };
  if(type==="tenan") return { url: prd('/rpt/laporan/rekap_kerja_sama_tenant', {storeId:store, userId:uid, dateTx:dmy}) };
  return { url: "" };
}
function reportFrameShell(type){
  const meta = {
    daily:{title:'Daily Performance',label:'Sampai tanggal'},
    gabungan:{title:'Gabungan 23,24',label:'Periode'},
    plutakmain:{title:'Plu Tidak Main',label:'Tanggal'},
    kasir:{title:'Laporan Penjualan per Kasir',label:'Tanggal'},
    pps:{title:'Report PPS',label:'Tanggal'},
    setoran_kasir:{title:'Setoran Kasir',label:'Tanggal'},
    sales_member:{title:'Sales Member',label:'Sampai tanggal'},
    tipe_kartu:{title:'Tipe Kartu',label:'Tanggal'},
    flazz:{title:'Flazz',label:'Tanggal'},
    sewa:{title:'Sewa',label:'Tanggal'},
    tenan:{title:'Tenan',label:'Tanggal'}
  }[type] || {title:'Laporan',label:'Tanggal'};
  const initial = buildReportUrl(type, todayISO());
  const title = String(meta.title).replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
  const label = String(meta.label).replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
  const initialUrl = JSON.stringify(initial.url || '');
  const reportType = JSON.stringify(type);
  const today = JSON.stringify(todayISO());
  return `<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><style>
  *{box-sizing:border-box}html,body{margin:0;width:100%;height:100%;overflow:hidden;background:#f4f7fb;font-family:Arial,sans-serif;color:#0f172a}.page{height:100%;display:flex;flex-direction:column;min-height:0}.head{flex:0 0 auto;background:#fff;border-bottom:1px solid #dbe5f1;padding:max(10px,env(safe-area-inset-top)) 12px 10px;box-shadow:0 3px 12px rgba(15,23,42,.07);z-index:2}.title{font-size:16px;font-weight:900;color:#1d4ed8;margin:0 0 9px}.controls{display:block}.field label{display:block;font-size:11px;font-weight:900;color:#475569;margin:0 0 5px}.field input{width:100%;height:42px;border:1px solid #bfdbfe;border-radius:12px;padding:0 11px;font-size:14px;font-weight:800;color:#0f172a;background:#fff;outline:none}.field input:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.12)}.hint{font-size:10px;color:#64748b;margin-top:6px;min-height:12px}.viewer{flex:1 1 auto;min-height:0;position:relative;background:#fff;overflow:auto;-webkit-overflow-scrolling:touch;touch-action:pan-x pan-y pinch-zoom}.viewer iframe{position:absolute;inset:0;width:100%;height:100%;border:0;background:#fff;transform-origin:0 0}.loading{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:#fff;z-index:3}.loading.hide{display:none}.spinner{width:42px;height:42px;border:4px solid #dbeafe;border-top-color:#2563eb;border-radius:50%;animation:spin .75s linear infinite}.loadingError{padding:12px;text-align:center;color:#64748b;font-size:12px;font-weight:800}@keyframes spin{to{transform:rotate(360deg)}}@media(max-width:600px){.head{padding-left:9px;padding-right:9px}.title{font-size:15px;margin-bottom:7px}.field input{height:40px;border-radius:10px}.hint{font-size:9px}.viewer iframe{width:125%;height:125%;transform:scale(.8)}}
  </style></head><body><div class="page"><div class="head"><div class="title">${title}</div><div class="controls"><div class="field"><label>${label}</label><input id="date" type="date"></div></div><div class="hint" id="hint"></div></div><div class="viewer"><div class="loading" id="loading"><div class="spinner" aria-label="Memuat laporan"></div></div><iframe id="report" title="${title}" allow="fullscreen"></iframe></div></div><script>
  const TYPE=${reportType}, TODAY=${today}; const input=document.getElementById('date'), frame=document.getElementById('report'), loading=document.getElementById('loading'), hint=document.getElementById('hint'); input.value=TODAY;
  function pad(n){return String(n).padStart(2,'0')} function dmy(iso){const p=String(iso).split('-');return p.length===3?pad(p[2])+'-'+pad(p[1])+'-'+p[0]:iso} function first(iso){const p=String(iso).split('-');return p.length===3?p[0]+'-'+p[1]+'-01':iso}
  function url(iso){const store=${JSON.stringify(String(STORE_ID))}, uid=store, dm=dmy(iso); let path='',q={}; if(TYPE==='daily'){path='/rpt/laporan/daily_performance';q={storeId:store,periode1:dmy(first(iso)),periode2:dm};hint.innerHTML='Periode: <b>'+dmy(first(iso))+'</b> s/d <b>'+dm+'</b>'}else if(TYPE==='gabungan'){path='/rpt/laporan/rep_gabungan_23_24';q={storeId:store,periode1:dm}}else if(TYPE==='plutakmain'){path='/rpt/laporan/laporan_plu_tak_main_toko';q={storeId:store,date:dm}}else if(TYPE==='kasir'){path='/rpt/laporan/penjualan_per_kasir';q={storeId:store,periode1:dm}}else if(TYPE==='pps'){path='/rpt/laporan/report_pps';q={storeId:store,storeDate:dm,dateTx:dm}}else if(TYPE==='setoran_kasir'){path='/rpt/laporan/new_setoran_kasir';q={storeId:store,userId:uid,periode1:dm}}else if(TYPE==='sales_member'){path='/rpt/laporan/laporan_sales_member';q={storeId:store,periode1:dmy(first(iso)),periode2:dm};hint.innerHTML='Periode: <b>'+dmy(first(iso))+'</b> s/d <b>'+dm+'</b>'}else if(TYPE==='tipe_kartu'){path='/rpt/laporan/setoran_per_kasir_detail_tipe_kartu';q={storeId:store,userId:uid,periode1:dm}}else if(TYPE==='flazz'){path='/rpt/laporan/setoran_per_kasir_detail_non_commerce';q={storeId:store,userId:uid,periode1:dm}}else if(TYPE==='sewa'){path='/rpt/laporan/detail_sarana_promosi';q={storeId:store,userId:uid,dateTx:dm}}else if(TYPE==='tenan'){path='/rpt/laporan/rekap_kerja_sama_tenant';q={storeId:store,userId:uid,dateTx:dm}} const qs=new URLSearchParams({api:'go_prd',path:path,...q}); qs.set('_',String(Date.now())); return 'proxy.php?'+qs.toString()}
  let loadTimer=null, debounceTimer=null; function showSpinner(){loading.innerHTML='<div class="spinner" aria-label="Memuat laporan"></div>';loading.classList.remove('hide')} function load(){if(!input.value)return;showSpinner();if(loadTimer)clearTimeout(loadTimer);frame.src='about:blank';setTimeout(()=>{frame.src=url(input.value)},30);loadTimer=setTimeout(()=>{if(!loading.classList.contains('hide'))loading.innerHTML='<div class="loadingError">Laporan belum berhasil dimuat. Pilih ulang tanggal untuk mencoba kembali.</div>'},30000)} frame.addEventListener('load',()=>{if(frame.src&&frame.src!=='about:blank'){loading.classList.add('hide');if(loadTimer)clearTimeout(loadTimer)}}); input.addEventListener('change',()=>{clearTimeout(debounceTimer);debounceTimer=setTimeout(load,180)}); load();
  <\/script></body></html>`;
}

const SIS_REPORT_META = Object.freeze({
  daily_online:{title:'Daily Performance Online',mode:'range'},
  item_retur:{title:'Item Harus Retur',mode:'none'},
  barang_hilang_sort:{title:'Barang Hilang Per Item (Sel Qty)',mode:'range'},
  overstock:{title:'Overstock Per Item',mode:'range'},
  top_100:{title:'100 Top Item',mode:'single'},
  flop_100:{title:'100 Flop Item',mode:'single'},
  kkp:{title:'Kertas Kerja PKM (KKP)',mode:'range'},
  barang_hilang:{title:'Barang Hilang Per Item',mode:'range'},
  git:{title:'Laporan GIT',mode:'none'},
  rak_detail:{title:'Performance Per Rak Detail',mode:'range'},
  rak_total:{title:'Performance Per Rak Total',mode:'range'},
  plu_tidak_terjual:{title:'PLU Tidak Terjual',mode:'range'},
  jual_harian:{title:'Perkembangan Jual Harian',mode:'range'},
  lpmp_rupiah:{title:'Posisi Mutasi Rupiah',mode:'range'},
  mutasi_tanggal:{title:'Posisi Mutasi Per Tanggal',mode:'range'},
  koreksi_rtd_rte:{title:'Register Koreksi RTD/RTE',mode:'range'},
  tenant_cards:{title:'Tenant Cards',mode:'single'}
});

function sisReportInternalUrl(key, values={}){
  const qs = new URLSearchParams({api:'sis_report',report:String(key||''),_:String(Date.now())});
  Object.entries(values||{}).forEach(([name,value])=>{ if(value) qs.set(name,String(value)); });
  return `proxy.php?${qs.toString()}`;
}

function sisReportFrameShell(key){
  const meta = SIS_REPORT_META[key] || {title:'Laporan SIS',mode:'single'};
  const title = String(meta.title).replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
  const mode = meta.mode === 'range' ? 'range' : 'single';
  const today = todayISO();
  const prior = new Date(today + 'T00:00:00');
  prior.setDate(prior.getDate()-1);
  const yesterday = `${prior.getFullYear()}-${pad2(prior.getMonth()+1)}-${pad2(prior.getDate())}`;
  const controls = mode === 'range'
    ? `<div class="controls range"><div class="field"><label>Tanggal 1</label><input id="date1" type="date" value="${yesterday}"></div><div class="field"><label>Tanggal 2</label><input id="date2" type="date" value="${today}"></div></div>`
    : `<div class="controls single"><div class="field"><label>Tanggal</label><input id="date" type="date" value="${today}"></div></div>`;
  return `<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><style>
  *{box-sizing:border-box}html,body{margin:0;width:100%;height:100%;overflow:hidden;background:#f4f7fb;font-family:Arial,sans-serif;color:#0f172a}.page{height:100%;display:flex;flex-direction:column;min-height:0}.head{flex:0 0 auto;background:#fff;border-bottom:1px solid #dbe5f1;padding:max(10px,env(safe-area-inset-top)) 12px 10px;box-shadow:0 3px 12px rgba(15,23,42,.07);z-index:2}.title{font-size:16px;font-weight:900;color:#1d4ed8;margin:0 0 9px}.controls.range{display:grid;grid-template-columns:1fr 1fr;gap:9px}.controls.single{display:block}.field{background:#f8fbff;border:1px solid #dbeafe;border-radius:12px;padding:8px}.field label{display:block;font-size:11px;font-weight:900;color:#475569;margin:0 0 5px}.field input{width:100%;height:40px;border:1px solid #bfdbfe;border-radius:10px;padding:0 9px;font-size:13px;font-weight:800;color:#0f172a;background:#fff;outline:none}.field input:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.12)}.hint{font-size:10px;color:#64748b;margin-top:6px;min-height:12px}.hint.error{color:#b91c1c;font-weight:900}.viewer{flex:1 1 auto;min-height:0;position:relative;background:#fff;overflow:auto;-webkit-overflow-scrolling:touch;touch-action:pan-x pan-y pinch-zoom}.viewer iframe{position:absolute;inset:0;width:100%;height:100%;border:0;background:#fff;transform-origin:0 0}.loading{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:#fff;z-index:3}.loading.hide{display:none}.spinner{width:42px;height:42px;border:4px solid #dbeafe;border-top-color:#2563eb;border-radius:50%;animation:spin .75s linear infinite}.loadingError{padding:12px;text-align:center;color:#64748b;font-size:12px;font-weight:800}@keyframes spin{to{transform:rotate(360deg)}}@media(max-width:600px){.head{padding-left:9px;padding-right:9px}.title{font-size:15px;margin-bottom:7px}.controls.range{gap:7px}.field{padding:7px;border-radius:10px}.field input{height:38px;font-size:12px}.viewer iframe{width:125%;height:125%;transform:scale(.8)}}
  </style></head><body><div class="page"><div class="head"><div class="title">${title}</div>${controls}<div class="hint" id="hint"></div></div><div class="viewer"><div class="loading" id="loading"><div class="spinner" aria-label="Memuat laporan"></div></div><iframe id="report" title="${title}" allow="fullscreen"></iframe></div></div><script>
  const KEY=${JSON.stringify(String(key))},MODE=${JSON.stringify(mode)};const frame=document.getElementById('report'),loading=document.getElementById('loading'),hint=document.getElementById('hint'),inputs=Array.from(document.querySelectorAll('input[type=date]'));
  function pad(n){return String(n).padStart(2,'0')}function dmy(v){const a=String(v||'').split('-');return a.length===3?pad(a[2])+'-'+pad(a[1])+'-'+a[0]:v}function show(){loading.innerHTML='<div class="spinner" aria-label="Memuat laporan"></div>';loading.classList.remove('hide')}function values(){if(MODE==='range')return{date1:inputs[0].value,date2:inputs[1].value};return{date:inputs[0].value}}function route(v){const q=new URLSearchParams({api:'sis_report',report:KEY,_:String(Date.now())});Object.keys(v).forEach(k=>{if(v[k])q.set(k,v[k])});return'proxy.php?'+q.toString()}
  let timer=null,deb=null;function load(){const v=values();if((MODE==='range'&&(!v.date1||!v.date2))||(MODE==='single'&&!v.date))return;if(MODE==='range'&&v.date1>v.date2){hint.classList.add('error');hint.textContent='Tanggal 1 tidak boleh melebihi Tanggal 2.';return}hint.classList.remove('error');hint.innerHTML=MODE==='range'?'Periode: <b>'+dmy(v.date1)+'</b> s/d <b>'+dmy(v.date2)+'</b>':'Tanggal: <b>'+dmy(v.date)+'</b>';show();frame.src='about:blank';setTimeout(()=>{frame.src=route(v)},30);clearTimeout(timer);timer=setTimeout(()=>{if(!loading.classList.contains('hide'))loading.innerHTML='<div class="loadingError">Laporan belum berhasil dimuat. Pilih ulang tanggal untuk mencoba kembali.</div>'},30000)}frame.addEventListener('load',()=>{if(frame.src&&frame.src!=='about:blank'){loading.classList.add('hide');clearTimeout(timer)}});inputs.forEach(input=>input.addEventListener('change',()=>{clearTimeout(deb);deb=setTimeout(load,180)}));load();
  <\/script></body></html>`;
}

function openSisReport(key){
  const meta = SIS_REPORT_META[key];
  if(!meta){ alert('Laporan tidak tersedia.'); return; }
  closeReportPopup();
  prepareReportFrameReturn();
  if(typeof window.cibiliActivityNow === 'function') window.cibiliActivityNow(meta.title,'report:'+key);
  if(meta.mode === 'none'){
    setIframeUrl(sisReportInternalUrl(key), true);
    return;
  }
  const html = sisReportFrameShell(key);
  if(typeof setIframeHtml === 'function') setIframeHtml(html, true);
  else { const w=window.open('','_blank'); if(w){w.document.open();w.document.write(html);w.document.close();} }
}

function openReportMenu(type){
  closeReportPopup();
  prepareReportFrameReturn();
  if(type === 'tagdgs'){
    const built = buildReportUrl(type, '');
    if(built.url) setIframeUrl(built.url, true);
    return;
  }
  const html = reportFrameShell(type);
  if(typeof setIframeHtml === 'function') setIframeHtml(html, true);
  else {
    const w=window.open('','_blank'); if(w){w.document.open();w.document.write(html);w.document.close();}
  }
}

function openRegisterDokumenToko(){
  closeReportPopup();
  closeStockOpnamePopup();
  const endISO = todayISO();
  setIframeUrl(`?page=register_dokumen_toko&storeId=${encodeURIComponent(STORE_ID)}&periode2=${encodeURIComponent(endISO)}`, true);
}

function nrReportFrameShell(){
  const store = String(STORE_ID || '');
  const today = todayISO();
  const d = new Date(today + 'T00:00:00'); d.setDate(d.getDate()-1);
  const yesterday = `${d.getFullYear()}-${pad2(d.getMonth()+1)}-${pad2(d.getDate())}`;
  return `<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><style>
  *{box-sizing:border-box}html,body{margin:0;width:100%;height:100%;overflow:hidden;background:#f4f7fb;font-family:Arial,sans-serif;color:#0f172a}.page{height:100%;display:flex;flex-direction:column;min-height:0}.head{flex:0 0 auto;background:#fff;border-bottom:1px solid #dbe5f1;padding:max(10px,env(safe-area-inset-top)) 12px 10px;box-shadow:0 3px 12px rgba(15,23,42,.07);z-index:2}.title{font-size:16px;font-weight:900;color:#1d4ed8;margin:0 0 9px}.controls{display:grid;grid-template-columns:1fr 1fr;gap:9px}.field{background:#f8fbff;border:1px solid #dbeafe;border-radius:12px;padding:8px}.field label{display:block;font-size:11px;font-weight:900;color:#475569;margin:0 0 5px}.field input{width:100%;height:40px;border:1px solid #bfdbfe;border-radius:10px;padding:0 9px;font-size:13px;font-weight:800;color:#0f172a;background:#fff;outline:none}.field input:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.12)}.hint{font-size:10px;color:#64748b;margin-top:6px;min-height:12px}.viewer{flex:1 1 auto;min-height:0;position:relative;background:#fff;overflow:auto;-webkit-overflow-scrolling:touch;touch-action:pan-x pan-y pinch-zoom}.viewer iframe{position:absolute;inset:0;width:100%;height:100%;border:0;background:#fff;transform-origin:0 0}.loading{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:#fff;z-index:3}.loading.hide{display:none}.spinner{width:42px;height:42px;border:4px solid #dbeafe;border-top-color:#2563eb;border-radius:50%;animation:spin .75s linear infinite}.loadingError{padding:12px;text-align:center;color:#64748b;font-size:12px;font-weight:800}@keyframes spin{to{transform:rotate(360deg)}}@media(max-width:600px){.head{padding-left:9px;padding-right:9px}.title{font-size:15px;margin-bottom:7px}.controls{gap:7px}.field{padding:7px;border-radius:10px}.field input{height:38px;font-size:12px}.viewer iframe{width:125%;height:125%;transform:scale(.8)}}
  </style></head><body><div class="page"><div class="head"><div class="title">NR</div><div class="controls"><div class="field"><label>Tanggal 1</label><input id="p1" type="date" value="${yesterday}"></div><div class="field"><label>Tanggal 2</label><input id="p2" type="date" value="${today}"></div></div><div class="hint" id="hint"></div></div><div class="viewer"><div class="loading" id="loading"><div class="spinner"></div></div><iframe id="report" title="NR" allow="fullscreen"></iframe></div></div><script>
  const STORE=${JSON.stringify(store)},p1=document.getElementById('p1'),p2=document.getElementById('p2'),frame=document.getElementById('report'),loading=document.getElementById('loading'),hint=document.getElementById('hint');
  function pad(n){return String(n).padStart(2,'0')} function dmy(v){const a=String(v).split('-');return a.length===3?pad(a[2])+'-'+pad(a[1])+'-'+a[0]:v}
  let timer=null,deb=null; function show(){loading.innerHTML='<div class="spinner"></div>';loading.classList.remove('hide')} function load(){if(!p1.value||!p2.value)return;show();hint.innerHTML='Periode: <b>'+dmy(p1.value)+'</b> s/d <b>'+dmy(p2.value)+'</b>';const q=new URLSearchParams({api:'go_prd',path:'/rpt/laporan/register_dokumen_toko_NR',storeId:STORE,periode1:dmy(p1.value),periode2:dmy(p2.value),_:String(Date.now())});frame.src='about:blank';setTimeout(()=>frame.src='proxy.php?'+q.toString(),30);clearTimeout(timer);timer=setTimeout(()=>{if(!loading.classList.contains('hide'))loading.innerHTML='<div class="loadingError">Laporan belum berhasil dimuat. Pilih ulang tanggal.</div>'},30000)}
  frame.addEventListener('load',()=>{if(frame.src&&frame.src!=='about:blank'){loading.classList.add('hide');clearTimeout(timer)}});[p1,p2].forEach(x=>x.addEventListener('change',()=>{clearTimeout(deb);deb=setTimeout(load,180)}));load();
  <\/script></body></html>`;
}
function openRegisterDokumenTokoNR(){
  closeReportPopup(); closeStockOpnamePopup();
  prepareReportFrameReturn();
  const html = nrReportFrameShell();
  if(typeof setIframeHtml === 'function') setIframeHtml(html, true);
  else { const w=window.open('','_blank'); if(w){w.document.open();w.document.write(html);w.document.close();} }
}

/* SETORAN DETAIL (tipe_kartu/flazz/sewa/tenan) -> popup pilih tanggal otomatis */
function openSetoranDetail(type){
  openReportMenu(type);
}

/* REPORT */
function openReport(type){
  openReportMenu(type);
}

/* STOCK OPNAME */
function openSO(type){
  if(type==="selisih"){
    closeStockOpnamePopup();
    const dmy = formatDMY(todayISO());
    setIframeUrl(prd('/rpt/laporan_so/csel_last_so_absolute_desc', {storeId:STORE_ID, dateSo:dmy}), true);
    return;
  }
  if(type==="rupiah"){
    const stockPopup = document.getElementById("stockPopup");
    if(stockPopup) stockPopup.style.display = "none";
    openReportDatePopup({
      type: "rupiah_so",
      title: "Cetak Selisih (Muncul Rupiah)",
      label: "Tanggal",
      value: todayISO(),
      hint: "Setelah klik <b>Oke</b>, laporan langsung diproses dan ditampilkan.",
      onCancel: function(){
        if(stockPopup) stockPopup.style.display = "flex";
      },
      onConfirm: function(iso){
        closeStockOpnamePopup();
        const dmy = formatDMY(iso);
        setIframeUrl(`?page=rupiah_so&storeId=${encodeURIComponent(STORE_ID)}&dateSo=${encodeURIComponent(dmy)}`, true);
      }
    });
    return;
  }

  if(type==="jadwal"){
    const stockPopup = document.getElementById("stockPopup");
    if(stockPopup) stockPopup.style.display = "none";
    openReportDatePopup({
      type: "jadwal_so",
      title: "Jadwal SO",
      label: "Tanggal Jadwal SO",
      value: todayISO(),
      hint: "Pilih tanggal terlebih dahulu. Rak yang tampil akan mengikuti tanggal tersebut.",
      onCancel: function(){ if(stockPopup) stockPopup.style.display = "flex"; },
      onConfirm: function(iso){
        closeStockOpnamePopup();
        setIframeUrl(`?page=jadwal_so&storeId=${encodeURIComponent(STORE_ID)}&tanggal=${encodeURIComponent(iso)}`, true);
      }
    });
    return;
  }
}
function confirmRupiahSO(){
  const input = document.getElementById("soRupiahDate");
  const iso = ((input && input.value) || (document.getElementById("reportDateInput")?.value) || "").trim();
  if(!iso){ alert("Pilih tanggal terlebih dahulu"); return; }
  closeStockOpnamePopup();
  const dmy = formatDMY(iso);
  setIframeUrl(`?page=rupiah_so&storeId=${encodeURIComponent(STORE_ID)}&dateSo=${encodeURIComponent(dmy)}`, true);
}

/* CEK OH */
function safeText(s){
  return String(s??'')
    .replace(/&/g,'&amp;')
    .replace(/</g,'&lt;')
    .replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;')
    .replace(/'/g,'&#39;');
}
function formatBarcode(v){
  if(v==null) return '';
  const s = String(v).trim();
  // kalau array/obj -> stringify singkat
  if(typeof v === 'object') return JSON.stringify(v);
  return s;
}
async function fetchProduct(storeId, plu){
  const url = `?api=proxy_product&storeId=${encodeURIComponent(storeId)}&plu=${encodeURIComponent(plu)}`;
  const r = await fetch(url);
  const j = await r.json().catch(()=>null);
  if(!r.ok) throw new Error((j && j.error) ? j.error : `HTTP ${r.status}`);
  if(!j || j.ok !== true) throw new Error((j && j.error) ? j.error : 'Proxy invalid');
  return j.data;
}
function renderProductDetail(dataArr, storeId, plu){
  // Robust: upstream kadang balikin object {header2, data:[...]}
  let root = dataArr;
  let items = [];
  if(Array.isArray(root)){
    items = root;
  }else if(root && typeof root === "object"){
    if(Array.isArray(root.data)) items = root.data;
    else if(Array.isArray(root.items)) items = root.items;
    else items = [root];
  }else{
    items = [];
  }
  const requestedPlu = String(plu ?? '').replace(/[^0-9]/g,'');
  const exactItem = items.find(it => String((it && (it.plu ?? it.PLU ?? it.prdcd ?? it.product_id ?? it.productId)) ?? '').replace(/[^0-9]/g,'') === requestedPlu);
  const obj = (exactItem || items[0] || {});
  const rootHeader2 = (root && typeof root === "object") ? (root.header2 ?? root.store_name ?? root.nama_toko ?? root.namaToko) : "";

  const vPLU     = safeText(String(obj.plu ?? plu ?? ''));
  const vName    = safeText(String(obj.descp ?? ''));
  const vOnHand  = safeText(String(obj.onhand ?? ''));
  const vBarcode = safeText(formatBarcode(obj.barcode));
  const vStore   = safeText(String(obj.store_id ?? storeId ?? ''));
  // Nama toko: sesuai permintaan ambil dari API field "header2" (fallback beberapa kemungkinan)
  const vStoreName = safeText(String(rootHeader2 ?? obj.header2 ?? obj.store_name ?? obj.nama_toko ?? obj.namaToko ?? ''));

  const html = `
<!doctype html><html lang="id"><head>
<meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1"/>
<style>
  body{font-family:system-ui; margin:0; background:#f4f6ff; color:#14131a;}
  .wrap{padding:14px;}
  .card{background:#fff;border-radius:18px;overflow:hidden;border:1px solid rgba(20,19,26,.06);box-shadow:0 10px 24px rgba(20,19,26,.08);}
  table{width:100%; border-collapse:separate; border-spacing:0;}
  td{padding:16px 14px;border-top:1px solid rgba(20,19,26,.06);vertical-align:middle;font-weight:800;}
  tr:first-child td{border-top:none;}
  .k{width:44%; background:rgba(123,97,255,.08); font-size:15px;}
  .v{width:56%; font-size:17px;}
  .onhand{color:#14a44d; font-weight:900;}



/* === RSO FIX FULL TABLE: tabel selisih tidak terpotong di layar kecil === */
html{overflow-x:hidden}
body.rso-page{overflow-x:hidden !important;min-width:320px;transform-origin:top left}
body.rso-page .top{padding:12px 10px}
body.rso-page .wrap{width:100%;max-width:1180px;padding:10px;margin:0 auto}
body.rso-page .table-wrap{overflow:visible !important;width:100%;max-width:100%;border-radius:12px}
body.rso-page table{width:100% !important;max-width:100% !important;min-width:0 !important;table-layout:fixed !important}
body.rso-page .col-plu{width:13% !important}
body.rso-page .col-name{width:34% !important}
body.rso-page .col-rack{width:14% !important}
body.rso-page .col-stock{width:17% !important}
body.rso-page .col-money{width:22% !important}
body.rso-page thead th,body.rso-page tbody td{padding:8px 5px;font-size:13px;line-height:1.18;white-space:normal !important;word-break:break-word;overflow-wrap:anywhere}
body.rso-page .badgeRack{max-width:100%;white-space:normal;padding:5px 7px}
@media(max-width:700px){
  body.rso-page .wrap{padding:8px}
  body.rso-page .top{font-size:14px}
  body.rso-page .sub{font-size:11px}
  body.rso-page .pill{font-size:12px;padding:7px 9px}
  body.rso-page .search input{padding:8px 10px;font-size:12px}
  body.rso-page .chip{font-size:12px;padding:7px 8px;gap:6px}
  body.rso-page .meta2{font-size:11px}
  body.rso-page thead th,body.rso-page tbody td{font-size:11px;padding:7px 4px}
  body.rso-page .col-plu{width:13% !important}
  body.rso-page .col-name{width:35% !important}
  body.rso-page .col-rack{width:13% !important}
  body.rso-page .col-stock{width:16% !important}
  body.rso-page .col-money{width:23% !important}
  body.rso-page .badgeRack{font-size:11px;padding:4px 5px}
}
@media(max-width:420px){
  body.rso-page thead th,body.rso-page tbody td{font-size:10px;padding:6px 3px;letter-spacing:-.15px}
  body.rso-page .col-name{width:34% !important}
  body.rso-page .col-money{width:24% !important}
  body.rso-page .badgeRack{font-size:10px;padding:3px 4px}
}

/* === UI FIX: tombol radius 5px (termasuk tombol pilih file) === */
button, .btn, input[type="button"], input[type="submit"], input[type="reset"]{
  border-radius:5px !important;
}
input[type="file"]::file-selector-button{
  border-radius:5px !important;
}
input[type="file"]::-webkit-file-upload-button{
  border-radius:5px !important;
}


/* === Planogram + OH: header ungu + tabel tidak overflow === */
.table-wrap{overflow-x:auto; max-width:100%;}
.plano-table{width:100%; table-layout:fixed;}
.plano-table th, .plano-table td{word-break:break-word; overflow-wrap:anywhere;}
.plano-table thead th{background:#1d4ed8 !important; color:#fff !important;}


/* === FORCE FIX: Upload ZIP button radius 5px === */
#adminModal input[type="file"] {
    border-radius: 5px !important;
}
#adminModal input[type="file"]::file-selector-button {
    border-radius: 5px !important;
}
#adminModal input[type="file"]::-webkit-file-upload-button {
    border-radius: 5px !important;
}


/* Inventory SIS subtitle */
#ohProductCount{color:#fff!important;}
</style>
</head><body>
  <div class="wrap">
    <div class="card">
      <table>
        <tr><td class="k">PLU</td><td class="v">${vPLU||'-'}</td></tr>
        <tr><td class="k">Nama</td><td class="v">${vName||'-'}</td></tr>
        <tr><td class="k">On Hand</td><td class="v"><span class="onhand">${vOnHand||'-'}</span></td></tr>
        <tr><td class="k">Barcode</td><td class="v">${vBarcode||'-'}</td></tr>
        <tr><td class="k">Kode Toko</td><td class="v">${vStore||'-'}</td></tr>
        <tr><td class="k">Nama Toko</td><td class="v">${vStoreName||'-'}</td></tr>
      </table>
    </div>
  </div>
</body></html>`;
  setIframeHtml(html, true);
}

/* =========================
   CEK OH TOKO LAIN - DROPDOWN NAMA BARANG FAST
   - Cache browser + cache server agar input nama barang langsung muncul pilihan.
========================= */
let OH_NAME_CACHE = {};
let OH_NAME_LOADING = {};
function ohSetNameStatus(msg, busy=false){ const el=document.getElementById("ohNameStatus"); if(el){ el.textContent=msg||"-"; el.classList.toggle("oh-name-status", true); el.classList.toggle("is-loading", !!busy); } }
function ohLocalKey(store){ return "ALFA_OH_NAME_CACHE_"+String(store||"").toUpperCase(); }
function ohNormalizeItems(raw){
  const arr = Array.isArray(raw) ? raw : (raw && typeof raw === "object" ? (raw.data || raw.result || raw.items || raw.rows || raw.list || Object.values(raw)) : []);
  const items=[];
  for(const obj of (Array.isArray(arr)?arr:[])){
    const plu=String((obj&&(obj.plu??obj.PLU??obj.sku??obj.SKU??obj.product_id??obj.productId))??"").replace(/[^0-9]/g,"").trim();
    const name=String((obj&&(obj.descp??obj.nama??obj.name??obj.product_name??obj.productName??obj.description))??"").trim();
    if(plu&&name) items.push({plu,name});
  }
  items.sort((a,b)=>a.name.localeCompare(b.name,"id",{sensitivity:"base"}));
  return items;
}
function ohReadLocalCache(store){
  try{
    const txt=localStorage.getItem(ohLocalKey(store));
    if(!txt) return null;
    const cached=JSON.parse(txt);
    if(!cached||!Array.isArray(cached.items)||!cached.items.length) return null;
    if((Date.now()-Number(cached.loadedAt||0))>24*60*60*1000) return null;
    OH_NAME_CACHE[store]=cached;
    return cached.items;
  }catch(_){ return null; }
}
function ohSaveLocalCache(store,items){
  const cached={loadedAt:Date.now(),items:Array.isArray(items)?items:[]};
  OH_NAME_CACHE[store]=cached;
  try{localStorage.setItem(ohLocalKey(store),JSON.stringify(cached));}catch(_){}
  return cached.items;
}
function ohBuildDatalist(items){
  const dl=document.getElementById("ohNameList");
  if(!dl) return;
  dl.innerHTML="";
  for(const it of (items||[]).slice(0,800)){
    const opt=document.createElement("option");
    opt.value=`${it.name} - ${it.plu}`;
    dl.appendChild(opt);
  }
}
function ohLoadNameListDebounced(){ ohLoadNameList(false); }
async function ohLoadNameList(forceFresh=false){
  const store=(document.getElementById("ohStore")?.value||STORE_ID||"").toUpperCase().replace(/[^A-Z0-9]/g,"");
  if(!store){ ohSetNameStatus("Isi kode toko untuk memuat daftar nama barang…"); return []; }
  const mem=OH_NAME_CACHE[store];
  if(!forceFresh && mem && Array.isArray(mem.items) && mem.items.length){ ohBuildDatalist(mem.items); ohSetNameStatus(`Daftar nama barang siap · ${mem.items.length} item`); return mem.items; }
  const local=!forceFresh ? ohReadLocalCache(store) : null;
  if(local&&local.length){ ohBuildDatalist(local); ohSetNameStatus(`Daftar nama barang siap · ${local.length} item`); ohLoadNameList(true).catch(()=>{}); return local; }
  if(OH_NAME_LOADING[store]) return OH_NAME_LOADING[store];
  ohSetNameStatus("Memuat daftar nama barang…", true);
  OH_NAME_LOADING[store]=(async()=>{
    try{
      const r=await fetch(`?type=list&storeId=${encodeURIComponent(store)}&fast=1`,{credentials:"same-origin",cache:"force-cache"});
      const j=await r.json().catch(()=>null);
      if(!r.ok) throw new Error((j&&(j.error||j.message||j.msg))?(j.error||j.message||j.msg):`HTTP ${r.status}`);
      const src=(j&&j.ok===true)?j.data:j;
      const items=ohNormalizeItems(src);
      ohSaveLocalCache(store,items);
      ohBuildDatalist(items);
      ohSetNameStatus(`Daftar nama barang siap · ${items.length} item`);
      return items;
    }catch(e){
      ohSetNameStatus(`Gagal memuat nama barang: ${(e&&(e.message||e))?(e.message||e):e}`);
      return [];
    }finally{ delete OH_NAME_LOADING[store]; }
  })();
  return OH_NAME_LOADING[store];
}
function ohHandleNameInput(){
  const val=String(document.getElementById("ohName")?.value||"").trim();
  if(!val) return;
  const m=val.match(/(?:—|-)+\s*(\d+)\s*$/);
  if(m&&m[1]){ const el=document.getElementById("ohPlu"); if(el) el.value=m[1]; }
}
async function searchOH(){
  const store = (document.getElementById("ohStore").value || STORE_ID || "").toUpperCase().replace(/[^A-Z0-9]/g,'');
  const plu   = (document.getElementById("ohPlu").value || "").trim().replace(/[^0-9]/g,'');
  if(!store){ alert("Kode toko wajib diisi"); return; }
  if(!plu){ alert("PLU wajib diisi"); return; }

  closeCekOH();
  try{
    showLoading("Mengambil data…");
    const data = await fetchProduct(store, plu);
    renderProductDetail(data, store, plu);
  }catch(e){
    hideLoading();
    const msg = String(e && (e.message||e) ? (e.message||e) : e);
    setIframeHtml(`<!doctype html><html><body style="font-family:system-ui;background:#fff;padding:16px"><b>Gagal ambil data</b><div style="margin-top:8px;color:#b00020">${safeText(msg)}</div></body></html>`, true);
  }
}


/* CLEREK (validasi mengikuti tanggal yang dipilih) */
const clerekZip = document.getElementById("clerekZip");
const clerekFileName = document.getElementById("clerekFileName");
const clerekFileDateEl = document.getElementById("clerekFileDate");
const clerekSelectedDateEl = document.getElementById("clerekSelectedDate");
const clerekMsg = document.getElementById("clerekMsg");

function ymd(d){
  const y = d.getFullYear();
  const m = String(d.getMonth()+1).padStart(2,'0');
  const dd = String(d.getDate()).padStart(2,'0');
  return `${y}-${m}-${dd}`;
}
function fmtDMY(d){
  const dd = String(d.getDate()).padStart(2,'0');
  const mm = String(d.getMonth()+1).padStart(2,'0');
  const y  = d.getFullYear();
  return `${dd}-${mm}-${y}`;
}

// Deteksi tanggal dari nama file (mendukung: YYYYMMDD, YYYY-MM-DD, DDMMYYYY, DD-MM-YYYY, DD_MM_YYYY)
function extractDateYMD(str){
  const s = String(str||"").toLowerCase();

  // 1) YYYY-MM-DD / YYYY_MM_DD / YYYYMMDD
  let m = s.match(/(20\d{2})[^\d]?([01]\d)[^\d]?([0-3]\d)/);
  if(m){
    const y = Number(m[1]), mo = Number(m[2]), da = Number(m[3]);
    if(mo>=1 && mo<=12 && da>=1 && da<=31) return `${y}-${String(mo).padStart(2,'0')}-${String(da).padStart(2,'0')}`;
  }
  m = s.match(/(20\d{2})([01]\d)([0-3]\d)/);
  if(m){
    const y = Number(m[1]), mo = Number(m[2]), da = Number(m[3]);
    if(mo>=1 && mo<=12 && da>=1 && da<=31) return `${y}-${String(mo).padStart(2,'0')}-${String(da).padStart(2,'0')}`;
  }

  // 2) DD-MM-YYYY / DD_MM_YYYY / DDMMYYYY
  m = s.match(/([0-3]\d)[^\d]?([01]\d)[^\d]?(20\d{2})/);
  if(m){
    const da = Number(m[1]), mo = Number(m[2]), y = Number(m[3]);
    if(mo>=1 && mo<=12 && da>=1 && da<=31) return `${y}-${String(mo).padStart(2,'0')}-${String(da).padStart(2,'0')}`;
  }
  m = s.match(/([0-3]\d)([01]\d)(20\d{2})/);
  if(m){
    const da = Number(m[1]), mo = Number(m[2]), y = Number(m[3]);
    if(mo>=1 && mo<=12 && da>=1 && da<=31) return `${y}-${String(mo).padStart(2,'0')}-${String(da).padStart(2,'0')}`;
  }

  return null;
}

function setClerekMsg(msg){
  if(!clerekMsg) return;
  clerekMsg.style.display = msg ? "block" : "none";
  clerekMsg.textContent = msg || "";
}

if(clerekSelectedDateEl){
  clerekSelectedDateEl.addEventListener("change", ()=>{
    const f = clerekZip && clerekZip.files && clerekZip.files[0];
    if(!f){ setClerekMsg(""); return; }
    const fileYMD = extractDateYMD(f.name);
    const selectedYMD = clerekSelectedDateEl.value || ymd(new Date());
    if(!fileYMD){
      setClerekMsg(`Tanggal file tidak terdeteksi. Rename ZIP agar memuat tanggal yang dipilih (${selectedYMD}), misalnya CLEREK_${selectedYMD}.zip`);
    }else if(fileYMD !== selectedYMD){
      setClerekMsg(`File ditolak. Tanggal file ${fileYMD} tidak sama dengan tanggal yang dipilih ${selectedYMD}.`);
    }else{
      setClerekMsg("");
    }
  });
}

clerekZip.addEventListener("change", (e)=>{
  const f = e.target.files && e.target.files[0];
  clerekFileName.innerHTML = f ? `📦 : <b>${f.name}</b>` : "";
  const selectedYMD = (clerekSelectedDateEl && clerekSelectedDateEl.value) ? clerekSelectedDateEl.value : ymd(new Date());

  const fileYMD = f ? (extractDateYMD(f.name) || null) : null;

  if(clerekFileDateEl){
    clerekFileDateEl.textContent = fileYMD ? fileYMD : "-";
  }

  if(f && !fileYMD){
    setClerekMsg(`Tanggal file tidak terdeteksi. Rename ZIP agar memuat tanggal yang dipilih (${selectedYMD}), misalnya CLEREK_${selectedYMD}.zip`);
  }else if(f && fileYMD && fileYMD !== selectedYMD){
    setClerekMsg(`File ditolak. Tanggal file ${fileYMD} tidak sama dengan tanggal yang dipilih ${selectedYMD}.`);
  }else{
    setClerekMsg("");
  }
});

async function processClerekZip(){

  const file = clerekZip.files && clerekZip.files[0];
  if(!file){ alert("Pilih file ZIP terlebih dahulu."); return; }

  // Validasi tanggal ZIP mengikuti tanggal yang dipilih
  const selectedYMD = (clerekSelectedDateEl && clerekSelectedDateEl.value) ? clerekSelectedDateEl.value : ymd(new Date());

  // Deteksi dari nama file ZIP + nama file DB di dalam ZIP (jika ada)
  const ymdFromZipName = extractDateYMD(file.name);

  if(ymdFromZipName && ymdFromZipName !== selectedYMD){
    setClerekMsg(`File ditolak. Tanggal file ${ymdFromZipName} tidak sama dengan tanggal yang dipilih ${selectedYMD}.`);
    openClerekModal();
    return;
  }

  closeClerekModal();
  showLoading("Memproses file…");

  try{
    const zip = await JSZip.loadAsync(file);
    let dbFile = null;

    for(const n in zip.files){
      const lower = n.toLowerCase();
      if(lower.endsWith(".db") || lower.endsWith(".sqlite") || lower.endsWith(".sqlite3")){
        dbFile = zip.files[n]; break;
      }
    }
    if(!dbFile){ hideLoading(); alert("DB (.db/.sqlite) tidak ditemukan di dalam ZIP."); return; }

    
    // Validasi tanggal dari nama file DB di dalam ZIP (kalau terdeteksi)
    const ymdFromDbName = extractDateYMD(dbFile.name || "");
    if(ymdFromDbName && ymdFromDbName !== selectedYMD){
      hideLoading();
      setClerekMsg(`File ditolak. Tanggal DB ${ymdFromDbName} tidak sama dengan tanggal yang dipilih ${selectedYMD}.`);
      openClerekModal();
      return;
    }
    // Jika tanggal tidak terdeteksi sama sekali (ZIP & DB), tolak proses
    if(!ymdFromZipName && !ymdFromDbName){
      hideLoading();
      setClerekMsg(`Tanggal tidak terdeteksi pada nama ZIP/DB. Rename file agar memuat tanggal yang dipilih (${selectedYMD}).`);
      openClerekModal();
      return;
    }


showLoading("Membaca database…");
    const SQL = await initSqlJs({ locateFile: f => `https://cdnjs.cloudflare.com/ajax/libs/sql.js/1.10.2/${f}` });
    const db = new SQL.Database(new Uint8Array(await dbFile.async("arraybuffer")));

    const hasTable = (name)=>{
      try{
        const r = db.exec(`SELECT name FROM sqlite_master WHERE type='table' AND name='${String(name).replace(/'/g,"''")}'`);
        return (r[0] && r[0].values && r[0].values.length>0);
      }catch(e){ return false; }
    };
    const hasColumn = (tableName, columnName)=>{
      try{
        const safeTable = String(tableName).replace(/'/g, "''");
        const safeColumn = String(columnName || '').trim().toLowerCase();
        if(!safeTable || !safeColumn) return false;
        const r = db.exec(`PRAGMA table_info('${safeTable}')`);
        const vals = (r[0] && r[0].values) ? r[0].values : [];
        return vals.some(v => String(v?.[1] || '').trim().toLowerCase() === safeColumn);
      }catch(e){ return false; }
    };
    const getTableColumns = (tableName)=>{
      try{
        const safeTable = String(tableName).replace(/'/g, "''");
        const r = db.exec(`PRAGMA table_info('${safeTable}')`);
        const vals = (r[0] && r[0].values) ? r[0].values : [];
        return vals.map(v=>String(v?.[1] || '').trim()).filter(Boolean);
      }catch(e){ return []; }
    };
    const quoteSqlIdentifier = (name)=>`"${String(name || '').replace(/"/g, '""')}"`;
    const queryObjects = (sql)=>{
      try{
        const result = db.exec(sql);
        if(!result[0] || !Array.isArray(result[0].values)) return [];
        const columns = (result[0].columns || []).map(col=>String(col || '').trim().toLowerCase());
        return result[0].values.map(values=>{
          const row = {};
          columns.forEach((column, index)=>{ row[column] = values[index]; });
          return row;
        });
      }catch(e){ return []; }
    };
    const extractClerekMemberRows = ()=>{
      if(!hasTable("tx_usi") || !hasTable("tx_tsale")) return [];
      if(
        !hasColumn("tx_usi", "member_name") ||
        !hasColumn("tx_usi", "no_member") ||
        !hasColumn("tx_tsale", "cust_id") ||
        !hasColumn("tx_tsale", "phone")
      ) return [];

      const cleanValue = (value)=>{
        const text = String(value ?? '').trim();
        return (!text || /^[-–—]+$/.test(text) || /^(?:null|undefined)$/i.test(text)) ? '' : text;
      };

      // rowid tx_usi dan tx_tsale adalah urutan independen. Jika ada transaksi
      // tambahan pada salah satu tabel, JOIN rowid=rowid akan menggeser nama
      // dan nomor. Pasangkan dengan identitas transaksi: no_member = cust_id.
      const matchConditions = [
        `TRIM(CAST(s.${quoteSqlIdentifier("cust_id")} AS TEXT)) = TRIM(CAST(u.${quoteSqlIdentifier("no_member")} AS TEXT))`
      ];
      if(hasColumn("tx_usi", "store_id") && hasColumn("tx_tsale", "store_id")){
        matchConditions.push(`TRIM(CAST(s.${quoteSqlIdentifier("store_id")} AS TEXT)) = TRIM(CAST(u.${quoteSqlIdentifier("store_id")} AS TEXT))`);
      }
      if(hasColumn("tx_usi", "date_tx") && hasColumn("tx_tsale", "date_tx")){
        matchConditions.push(`TRIM(CAST(s.${quoteSqlIdentifier("date_tx")} AS TEXT)) = TRIM(CAST(u.${quoteSqlIdentifier("date_tx")} AS TEXT))`);
      }
      if(hasColumn("tx_usi", "user_id") && hasColumn("tx_tsale", "user_id")){
        matchConditions.push(`TRIM(CAST(s.${quoteSqlIdentifier("user_id")} AS TEXT)) = TRIM(CAST(u.${quoteSqlIdentifier("user_id")} AS TEXT))`);
      }
      const matchSql = matchConditions.join(' AND ');

      const rows = queryObjects(`
        SELECT
          u.rowid AS source_rowid,
          u.${quoteSqlIdentifier("no_member")} AS member_no,
          u.${quoteSqlIdentifier("member_name")} AS member_name,
          (
            SELECT s.${quoteSqlIdentifier("phone")}
            FROM ${quoteSqlIdentifier("tx_tsale")} AS s
            WHERE ${matchSql}
              AND TRIM(CAST(s.${quoteSqlIdentifier("phone")} AS TEXT)) <> ''
            ORDER BY s.rowid
            LIMIT 1
          ) AS phone
        FROM ${quoteSqlIdentifier("tx_usi")} AS u
        ORDER BY u.rowid
      `);

      return rows.map(row=>({
        rowid: String(row.source_rowid ?? ''),
        memberNo: cleanValue(row.member_no),
        memberName: cleanValue(row.member_name),
        phone: cleanValue(row.phone)
      })).filter(item=>item.rowid && item.memberNo && item.memberName && item.phone);
    };
    const extractCashierNames = ()=>{
      if(!hasTable("log_receipt_prn") || !hasColumn("log_receipt_prn", "body1")) return [];
      const receiptRows = queryObjects(`SELECT ${quoteSqlIdentifier("body1")} AS body1 FROM ${quoteSqlIdentifier("log_receipt_prn")} WHERE ${quoteSqlIdentifier("body1")} IS NOT NULL`);
      const parsed = [];
      const seen = new Set();
      const decodeBody1 = (value)=>{
        try{
          if(value instanceof Uint8Array) return new TextDecoder('utf-8').decode(value);
          if(value instanceof ArrayBuffer) return new TextDecoder('utf-8').decode(new Uint8Array(value));
          if(Array.isArray(value) && value.every(v=>Number.isInteger(v) && v>=0 && v<=255)){
            return new TextDecoder('utf-8').decode(new Uint8Array(value));
          }
        }catch(e){}
        return String(value ?? '');
      };
      receiptRows.forEach(row=>{
        let body = decodeBody1(row.body1);
        body = body
          .replace(/\\r\\n|\\n|\\r/gi, '\n')
          .replace(/<br\s*\/?\s*>/gi, '\n')
          .replace(/\u0000/g, '')
          .replace(/\r\n?|\f/g, '\n');
        const nameMatch = body.match(/(?:^|\n|\|)\s*Kasir\s*[:=\-]\s*([^\n\r|]+)/i)
          || body.match(/\bKasir\s*[:=\-]\s*([^\n\r|]+)/i);
        if(!nameMatch) return;
        let cashierName = String(nameMatch[1] || '')
          .replace(/\s+(?:NIK(?:\s+Kasir)?|User(?:\s*ID)?|ID\s*Kasir|No\.?\s*Kasir)\s*[:=\-].*$/i, '')
          .replace(/\s{2,}(?:Tanggal|Jam|Shift|Terminal|Kassa|Register)\s*[:=\-].*$/i, '')
          .replace(/[;]+$/g, '')
          .trim();
        if(!cashierName) return;
        const nikMatch = body.match(/(?:NIK(?:\s+Kasir)?|User(?:\s*ID)?|ID\s*Kasir|No\.?\s*Kasir)\s*[:=\-]\s*([A-Z0-9._-]+)/i);
        const nik = nikMatch ? String(nikMatch[1] || '').trim() : '';
        const key = `${nik.toUpperCase()}|${cashierName.toUpperCase()}`;
        if(seen.has(key)) return;
        seen.add(key);
        parsed.push({nik, cashierName});
      });
      return parsed;
    };
    const normalizeStoreCode = (val)=> String(val ?? '').toUpperCase().replace(/[^A-Z0-9]/g,'');

    let rows = [];

    if(hasTable("tx_tsale") && hasColumn("tx_tsale", "store_id")){
      try{
        const rStore = db.exec(`select distinct store_id from tx_tsale where store_id is not null and trim(cast(store_id as text)) <> '' limit 10`);
        const valsStore = (rStore[0] && rStore[0].values) ? rStore[0].values : [];
        const foundStores = valsStore.map(v => normalizeStoreCode(v?.[0])).filter(Boolean);
        const uniqStores = [...new Set(foundStores)];
        const loginStore = normalizeStoreCode(STORE_ID || '');
        if(loginStore && uniqStores.length && !uniqStores.includes(loginStore)){
          hideLoading();
          setClerekMsg('File ini bukan toko kamu');
          openClerekModal();
          return;
        }
      }catch(e){}
    }

    if(hasTable("tx_tsale")){
      try{
        const r = db.exec(`select user_id, sum(cash-change_pay) as setor from tx_tsale group by user_id`);
        rows = (r[0] && r[0].values) ? r[0].values : [];
      }catch(e){}
    }

    if(!rows.length && hasTable("tx_tsale")){
      try{
        const r = db.exec(`select user_id, sum(cash) as cash_total, sum(change_pay) as change_total from tx_tsale group by user_id`);
        const vals = (r[0] && r[0].values) ? r[0].values : [];
        rows = vals.map(v => [v[0], Number(v[1]||0) - Number(v[2]||0)]);
      }catch(e){}
    }

    if(!rows.length){
      hideLoading();
      const safeName = String(file.name).replace(/[&<>"']/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
      const html = `
        <!doctype html><html lang="id"><head>
        <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
        <style>
          body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;padding:16px;background:#f4f6fb;margin:0}
          h3{margin:0 0 10px;color:#4f46e5}
          .box{background:#fff;border-radius:12px;padding:14px;border:1px solid #e5e7eb}
          .small{font-size:12px;color:#6b7280;font-weight:800}
          .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace}
.expiry-box{
  display:none;
  font-weight:1000;
  color:#ffffff;
  letter-spacing:.45px;
  text-shadow:0 3px 12px rgba(15,23,42,.36);
  font-family:'ITC Lubalin Graph','Poppins','Inter',system-ui,-apple-system,Segoe UI,Roboto,Arial;
  background:transparent!important;
  border:0!important;
  box-shadow:none!important;
  padding:0!important;
  border-radius:0!important;
}
        


/* === RSO FIX FULL TABLE: tabel selisih tidak terpotong di layar kecil === */
html{overflow-x:hidden}
body.rso-page{overflow-x:hidden !important;min-width:320px;transform-origin:top left}
body.rso-page .top{padding:12px 10px}
body.rso-page .wrap{width:100%;max-width:1180px;padding:10px;margin:0 auto}
body.rso-page .table-wrap{overflow:visible !important;width:100%;max-width:100%;border-radius:12px}
body.rso-page table{width:100% !important;max-width:100% !important;min-width:0 !important;table-layout:fixed !important}
body.rso-page .col-plu{width:13% !important}
body.rso-page .col-name{width:34% !important}
body.rso-page .col-rack{width:14% !important}
body.rso-page .col-stock{width:17% !important}
body.rso-page .col-money{width:22% !important}
body.rso-page thead th,body.rso-page tbody td{padding:8px 5px;font-size:13px;line-height:1.18;white-space:normal !important;word-break:break-word;overflow-wrap:anywhere}
body.rso-page .badgeRack{max-width:100%;white-space:normal;padding:5px 7px}
@media(max-width:700px){
  body.rso-page .wrap{padding:8px}
  body.rso-page .top{font-size:14px}
  body.rso-page .sub{font-size:11px}
  body.rso-page .pill{font-size:12px;padding:7px 9px}
  body.rso-page .search input{padding:8px 10px;font-size:12px}
  body.rso-page .chip{font-size:12px;padding:7px 8px;gap:6px}
  body.rso-page .meta2{font-size:11px}
  body.rso-page thead th,body.rso-page tbody td{font-size:11px;padding:7px 4px}
  body.rso-page .col-plu{width:13% !important}
  body.rso-page .col-name{width:35% !important}
  body.rso-page .col-rack{width:13% !important}
  body.rso-page .col-stock{width:16% !important}
  body.rso-page .col-money{width:23% !important}
  body.rso-page .badgeRack{font-size:11px;padding:4px 5px}
}
@media(max-width:420px){
  body.rso-page thead th,body.rso-page tbody td{font-size:10px;padding:6px 3px;letter-spacing:-.15px}
  body.rso-page .col-name{width:34% !important}
  body.rso-page .col-money{width:24% !important}
  body.rso-page .badgeRack{font-size:10px;padding:3px 4px}
}

/* === UI FIX: tombol radius 5px (termasuk tombol pilih file) === */
button, .btn, input[type="button"], input[type="submit"], input[type="reset"]{
  border-radius:5px !important;
}
input[type="file"]::file-selector-button{
  border-radius:5px !important;
}
input[type="file"]::-webkit-file-upload-button{
  border-radius:5px !important;
}


/* === Planogram + OH: header ungu + tabel tidak overflow === */
.table-wrap{overflow-x:auto; max-width:100%;}
.plano-table{width:100%; table-layout:fixed;}
.plano-table th, .plano-table td{word-break:break-word; overflow-wrap:anywhere;}
.plano-table thead th{background:#1d4ed8 !important; color:#fff !important;}


/* === FORCE FIX: Upload ZIP button radius 5px === */
#adminModal input[type="file"] {
    border-radius: 5px !important;
}
#adminModal input[type="file"]::file-selector-button {
    border-radius: 5px !important;
}
#adminModal input[type="file"]::-webkit-file-upload-button {
    border-radius: 5px !important;
}

</style></head><body>
          <h3>Hasil Clerek</h3>
          <div class="box">
            <div class="small"><b>File:</b> <span class="mono">${safeName}</span></div>
            <div style="margin-top:10px;font-weight:900;color:#111827">
              Data tidak ditemukan / format DB berbeda.
            </div>
            <div class="small" style="margin-top:8px">
              Pastikan ZIP berisi database Clerek yang benar (tabel <span class="mono">tx_tsale</span>).
            </div>
          </div>
        </body></html>
      `;
      setIframeHtml(html, true);
      return;
    }

    const cashierRecords = extractCashierNames();
    const normalizeNik = (value)=>String(value ?? '').replace(/[^A-Z0-9]/gi, '').toUpperCase();
    const cashierByNik = new Map();
    cashierRecords.forEach(item=>{
      const key = normalizeNik(item.nik);
      if(key && !cashierByNik.has(key)) cashierByNik.set(key, item.cashierName);
    });
    const uniqueCashierNames = [...new Set(cashierRecords.map(item=>String(item.cashierName || '').trim()).filter(Boolean))];
    const clerekRows = rows.map((x, rowIndex)=>{
      const nik = String(x?.[0] ?? '').trim();
      const rowNik = normalizeNik(nik);
      let cashierName = cashierByNik.get(rowNik) || '';
      if(!cashierName && rowNik){
        const partial = cashierRecords.find(item=>{
          const bodyNik = normalizeNik(item.nik);
          return bodyNik && (bodyNik.includes(rowNik) || rowNik.includes(bodyNik));
        });
        cashierName = partial?.cashierName || '';
      }
      if(!cashierName && uniqueCashierNames.length === rows.length) cashierName = uniqueCashierNames[rowIndex] || '';
      if(!cashierName && uniqueCashierNames.length === 1) cashierName = uniqueCashierNames[0];
      return {nik, cashierName: cashierName || 'Nama kasir tidak ditemukan', total: Number(x?.[1] ?? 0)};
    });
    const memberRows = extractClerekMemberRows();
    // Satu kali upload saja: setelah DB Clerek selesai dibaca, data struk ikut
    // diekstrak dan disimpan bersama cache Clerek. Halaman LIHAT STRUK tidak
    // pernah meminta file kedua.
    try{ db.close(); }catch(e){}
    let receiptData = null;
    try{
      receiptData = await requestClerekReceiptsClient(file);
    }catch(receiptErr){
      console.warn('Struk belum dapat diekstrak dari ZIP Clerek:', receiptErr);
    }
    const clerekPayload = {
      fileName: String(file.name || ''),
      selectedDate: selectedYMD,
      rows: clerekRows,
      memberRows,
      receiptData,
      createdAt: Date.now()
    };
    saveClerekCache(clerekPayload);
    updateClerekCacheInfo();
    const html = buildClerekResultHtml(clerekPayload);

    hideLoading();
    setIframeHtml(html, true);

  }catch(err){
    console.error(err);
    hideLoading();
    alert("Gagal memproses ZIP/DB. Pastikan file valid.");
  }
}

/* ADMIN (list/add/delete) */
// ADMIN_PASS dipindah ke proxy.php (server-side check)
const ADMIN_AUTH_KEY = "ALFASTORE_ADMIN_AUTH_V1";
function adminAuthValid(){
  // FIX: setelah login OTP admin (putri27), session server sudah valid.
  try{
    const raw = localStorage.getItem(ADMIN_AUTH_KEY);
    if(!raw) return false;
    const obj = JSON.parse(raw);
    if(!obj || !obj.exp) return false;
    return Date.now() < Number(obj.exp);
  }catch(e){ return false; }
}
function hasAdminAuth(){
  if(!IS_ADMIN) return false;
  if(adminAuthValid()) return true;
  // Login Developer/OTP sudah diverifikasi server. Sinkronkan cache UI agar
  // tombol ADMIN tidak meminta password ulang atau berhenti karena fungsi kosong.
  setAdminAuth(Date.now() + 86400000);
  return true;
}
function setAdminAuth(expMs){
  try{ localStorage.setItem(ADMIN_AUTH_KEY, JSON.stringify({exp: expMs})); }catch(e){}
}
function clearAdminAuth(){
  try{ localStorage.removeItem(ADMIN_AUTH_KEY); }catch(e){}
}

async function adminFetchStoreName(storeId){
  storeId = String(storeId || "").trim().toUpperCase();
  if(!storeId) return "-";
  if(ADMIN_STORE_NAMES[storeId]) return ADMIN_STORE_NAMES[storeId];
  if(ADMIN_STORE_NAME_LOADING[storeId]) return ADMIN_STORE_NAME_LOADING[storeId];

  ADMIN_STORE_NAME_LOADING[storeId] = (async ()=>{
    try{
      const res = await fetch(`?api=store_detail&storeId=${encodeURIComponent(storeId)}`, {cache:'force-cache', credentials:'same-origin'});
      const j = await res.json().catch(()=>null);
      const name = (j && j.ok && j.header2) ? String(j.header2).trim() : "";
      ADMIN_STORE_NAMES[storeId] = name || "-";
    }catch(e){
      ADMIN_STORE_NAMES[storeId] = ADMIN_STORE_NAMES[storeId] || "-";
    }finally{
      delete ADMIN_STORE_NAME_LOADING[storeId];
    }
    return ADMIN_STORE_NAMES[storeId] || "-";
  })();

  return ADMIN_STORE_NAME_LOADING[storeId];
}
let ADMIN_NAME_RENDER_TIMER = null;
function adminScheduleRenderNames(){
  if(ADMIN_NAME_RENDER_TIMER) return;
  ADMIN_NAME_RENDER_TIMER = setTimeout(()=>{ ADMIN_NAME_RENDER_TIMER=null; adminRenderFiltered(); }, 120);
}
async function adminWarmStoreNames(stores){
  const visibleFirst = (typeof ADMIN_STORES !== "undefined" && Array.isArray(ADMIN_STORES)) ? ADMIN_STORES.slice(0,80) : [];
  const rest = (Array.isArray(stores) ? stores : []).filter(s=>!visibleFirst.includes(s));
  const queue = visibleFirst.concat(rest).map(s=>String(s||"").trim().toUpperCase()).filter(s=>s && (!ADMIN_STORE_NAMES[s] || ADMIN_STORE_NAMES[s] === "-"));
  if(!queue.length) return;
  try{
    const res = await fetch('?api=admin_store_names_batch', {method:'POST',cache:'no-store',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({stores:queue})});
    const j = await res.json().catch(()=>null);
    if(j && j.ok && j.storeNameMap){
      Object.keys(j.storeNameMap).forEach(k=>{ const v=String(j.storeNameMap[k]||'').trim(); if(v && v !== '-') ADMIN_STORE_NAMES[String(k).toUpperCase()] = v; });
      adminRenderFiltered();
      return;
    }
  }catch(e){}
  let idx = 0;
  const worker = async ()=>{
    while(idx < queue.length){
      const cur = queue[idx++];
      await adminFetchStoreName(cur);
      adminScheduleRenderNames();
    }
  };
  for(let i=0;i<Math.min(8, queue.length);i++) worker();
}

function adminGetStoreName(storeId){
  storeId = String(storeId || "").trim().toUpperCase();
  return ADMIN_STORE_NAMES[storeId] || "-";
}

function applyAdminActionBadgeStyles(pin, prem){
  const pinEl = document.getElementById("adminActionPinBadge");
  const premiumEl = document.getElementById("adminActionPremiumBadge");
  if(pinEl){
    pinEl.textContent = `PIN: ${pin}`;
    pinEl.className = 'badge pin-badge';
  }
  if(premiumEl){
    premiumEl.textContent = `Akun: ${prem ? "Premium" : "Non Premium"}`;
    premiumEl.className = `badge account-badge ${prem ? "account-green" : "account-blue"}`;
  }
}

let ADMIN_ACTIVITY_TIMER = null;
let ADMIN_ACTIVITY_STORE_ID = '';
let ADMIN_ONLINE_USERS_TIMER = null;
function adminOnlineEscape(value){
  return String(value == null ? '' : value).replace(/[&<>"']/g, ch=>({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  }[ch]));
}
function ensureAdminOnlineUsersUi(){
  let modal = document.getElementById('adminOnlineUsersModal');
  if(modal) return modal;
  modal = document.createElement('div');
  modal.id = 'adminOnlineUsersModal';
  modal.className = 'modal';
  modal.setAttribute('aria-hidden','true');
  modal.innerHTML = `
    <div class="modal-box admin-online-users-box">
      <button type="button" class="close-x" aria-label="Tutup" onclick="closeAdminOnlineUsersModal()">×</button>
      <div class="admin-online-users-head">
        <div class="admin-online-users-icon">●</div>
        <div><h3>User Sedang Online</h3><p id="adminOnlineUsersSummary">Memuat pengguna online...</p></div>
      </div>
      <div id="adminOnlineUsersList" class="admin-online-users-list"><div class="admin-online-users-empty">Memuat...</div></div>
      <div class="admin-online-users-actions">
        <button type="button" class="btn" onclick="refreshAdminOnlineUsers(true)">Refresh</button>
        <button type="button" class="btn danger" onclick="closeAdminOnlineUsersModal()">Tutup</button>
      </div>
    </div>`;
  const style = document.createElement('style');
  style.id = 'adminOnlineUsersStyle';
  style.textContent = `
    #adminOnlineUsersModal{z-index:2147483646!important;padding:12px!important;align-items:center!important;justify-content:center!important}
    #adminOnlineUsersModal .admin-online-users-box{position:relative!important;width:min(620px,calc(100vw - 24px))!important;max-width:620px!important;max-height:calc(100dvh - 24px)!important;overflow:hidden!important;padding:20px!important;border:1px solid #bfdbfe!important;border-radius:20px!important;background:#fff!important;text-align:left!important;display:flex!important;flex-direction:column!important}
    .admin-online-users-head{display:flex;align-items:center;gap:12px;padding:0 42px 14px 0;border-bottom:1px solid #dbeafe}.admin-online-users-head h3{margin:0;color:#12315f;font-size:22px;font-weight:1000}.admin-online-users-head p{margin:4px 0 0;color:#64748b;font-size:12px;font-weight:800}.admin-online-users-icon{width:46px;height:46px;flex:0 0 46px;border-radius:14px;background:#dcfce7;color:#16a34a;display:grid;place-items:center;font-size:24px;box-shadow:inset 0 0 0 1px #86efac}
    .admin-online-users-list{min-height:120px;overflow:auto;padding:12px 2px;display:grid;gap:10px;-webkit-overflow-scrolling:touch}.admin-online-user-row{border:1px solid #dbeafe;border-radius:14px;background:linear-gradient(145deg,#f8fbff,#fff);padding:13px}.admin-online-user-top{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}.admin-online-user-code{color:#1d4ed8;font-size:19px;font-weight:1000}.admin-online-user-name{margin-top:2px;color:#475569;font-size:12px;font-weight:850;overflow-wrap:anywhere}.admin-online-user-live{display:inline-flex;align-items:center;gap:6px;border:1px solid #86efac;border-radius:999px;background:#dcfce7;color:#166534;padding:5px 8px;font-size:10px;font-weight:1000;white-space:nowrap}.admin-online-user-live:before{content:'';width:8px;height:8px;border-radius:50%;background:#22c55e}.admin-online-user-activity{margin-top:10px;border-radius:10px;background:#eff6ff;padding:10px;color:#1e3a8a;font-size:13px;font-weight:950;line-height:1.4}.admin-online-user-time{margin-top:6px;color:#64748b;font-size:10.5px;font-weight:800}.admin-online-users-empty{padding:26px 14px;border:1px dashed #bfdbfe;border-radius:13px;background:#f8fbff;color:#64748b;text-align:center;font-size:13px;font-weight:900}.admin-online-users-actions{display:grid;grid-template-columns:1fr 1fr;gap:9px;padding-top:12px;border-top:1px solid #dbeafe}.admin-online-users-actions .btn{width:100%!important;margin:0!important;border-radius:10px!important}
    #adminOnlineCount.admin-online-clickable{cursor:pointer!important;user-select:none!important;transition:transform .15s ease,filter .15s ease!important}#adminOnlineCount.admin-online-clickable:active{transform:scale(.96)!important;filter:brightness(.96)!important}
    @media(max-width:480px){#adminOnlineUsersModal{padding:6px!important}#adminOnlineUsersModal .admin-online-users-box{width:calc(100vw - 12px)!important;max-height:calc(100dvh - 12px)!important;padding:16px 13px!important;border-radius:16px!important}.admin-online-users-head h3{font-size:20px}.admin-online-user-row{padding:11px}}
  `;
  document.head.appendChild(style);
  modal.addEventListener('click', event=>{ if(event.target === modal) closeAdminOnlineUsersModal(); });
  document.body.appendChild(modal);
  return modal;
}
function adminOnlineRows(){
  return (Array.isArray(ADMIN_STORES) ? ADMIN_STORES : [])
    .map(store=>String(store||'').trim().toUpperCase())
    .filter(store=>store && getPresenceMeta(store).online)
    .sort((a,b)=>{
      const pa=getPresenceMeta(a), pb=getPresenceMeta(b);
      return Number(pb.activityUpdatedTs||pb.lastSeenTs||0)-Number(pa.activityUpdatedTs||pa.lastSeenTs||0) || a.localeCompare(b);
    });
}
function renderAdminOnlineUsers(){
  const list = document.getElementById('adminOnlineUsersList');
  const summary = document.getElementById('adminOnlineUsersSummary');
  if(!list) return;
  const rows = adminOnlineRows();
  if(summary) summary.textContent = rows.length + ' user aktif · diperbarui otomatis';
  if(!rows.length){
    list.innerHTML = '<div class="admin-online-users-empty">Belum ada user yang sedang online.</div>';
    return;
  }
  list.innerHTML = rows.map(store=>{
    const presence = getPresenceMeta(store);
    const name = adminGetStoreName(store) || '-';
    const activity = presence.activityTitle || 'Beranda';
    const updated = presence.activityUpdatedTs || presence.lastSeenTs || 0;
    return `<div class="admin-online-user-row">
      <div class="admin-online-user-top"><div><div class="admin-online-user-code">${adminOnlineEscape(store)}</div><div class="admin-online-user-name">${adminOnlineEscape(name)}</div></div><span class="admin-online-user-live">Online</span></div>
      <div class="admin-online-user-activity">Aktivitas: ${adminOnlineEscape(activity)}</div>
      <div class="admin-online-user-time">Terakhir aktif: ${adminOnlineEscape(formatPresenceTime(updated))}</div>
    </div>`;
  }).join('');
}
async function refreshAdminOnlineUsers(showLoading=false){
  const list = document.getElementById('adminOnlineUsersList');
  if(showLoading && list) list.innerHTML = '<div class="admin-online-users-empty">Memuat data terbaru...</div>';
  try{ if(typeof adminReload === 'function') await adminReload(false, 1); }catch(e){}
  renderAdminOnlineUsers();
  const stores = adminOnlineRows().filter(store=>!ADMIN_STORE_NAMES[store] || ADMIN_STORE_NAMES[store] === '-');
  if(stores.length){
    await Promise.all(stores.map(store=>adminFetchStoreName(store).catch(()=>'-')));
    renderAdminOnlineUsers();
  }
}
function openAdminOnlineUsersModal(event){
  if(event){ event.preventDefault(); event.stopPropagation(); }
  const modal = ensureAdminOnlineUsersUi();
  modal.style.display = 'flex';
  modal.setAttribute('aria-hidden','false');
  clearInterval(ADMIN_ONLINE_USERS_TIMER);
  refreshAdminOnlineUsers(true);
  ADMIN_ONLINE_USERS_TIMER = setInterval(()=>refreshAdminOnlineUsers(false), 3000);
  return false;
}
function closeAdminOnlineUsersModal(){
  clearInterval(ADMIN_ONLINE_USERS_TIMER);
  ADMIN_ONLINE_USERS_TIMER = null;
  const modal = document.getElementById('adminOnlineUsersModal');
  if(modal){ modal.style.display='none'; modal.setAttribute('aria-hidden','true'); }
}
function bindAdminOnlineBadge(){
  const badge = document.getElementById('adminOnlineCount');
  if(!badge || badge.dataset.onlinePopupBound === '1') return;
  badge.dataset.onlinePopupBound = '1';
  badge.classList.add('admin-online-clickable');
  badge.setAttribute('role','button');
  badge.setAttribute('tabindex','0');
  badge.setAttribute('title','Lihat semua user yang sedang online');
  badge.setAttribute('aria-label','Lihat semua user yang sedang online');
  badge.addEventListener('click', openAdminOnlineUsersModal);
  badge.addEventListener('keydown', event=>{
    if(event.key === 'Enter' || event.key === ' '){ event.preventDefault(); openAdminOnlineUsersModal(event); }
  });
}
function ensureAdminActivityUi(){
  let modal = document.getElementById('adminActivityModal');
  if(modal) return modal;
  modal = document.createElement('div');
  modal.id = 'adminActivityModal';
  modal.className = 'modal';
  modal.setAttribute('aria-hidden', 'true');
  modal.innerHTML = `
    <div class="modal-box" style="width:min(520px,96vw);text-align:left">
      <h3 style="margin:0 0 10px">Aktivitas User</h3>
      <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:12px;margin-bottom:12px">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
          <div>
            <div id="adminActivityStoreId" style="font-size:22px;font-weight:1000;color:#1e3a8a">-</div>
            <div id="adminActivityStoreName" class="small" style="font-weight:850;color:#475569">-</div>
          </div>
          <div id="adminActivityStatus" style="display:inline-flex;align-items:center;gap:7px;border-radius:999px;background:#dcfce7;color:#166534;padding:7px 10px;font-size:12px;font-weight:1000">
            <span id="adminActivityStatusDot" style="width:9px;height:9px;border-radius:999px;background:#22c55e"></span>
            <span id="adminActivityStatusText">Online</span>
          </div>
        </div>
      </div>
      <div style="font-size:12px;font-weight:900;color:#64748b;margin-bottom:5px">Halaman yang sedang dibuka</div>
      <div id="adminActivityPage" style="min-height:54px;display:flex;align-items:center;background:#fff;border:1px solid #dbeafe;border-radius:10px;padding:12px;font-size:18px;font-weight:1000;color:#1d4ed8">Memuat...</div>
      <div id="adminActivityUpdated" class="small" style="margin-top:9px;font-weight:800;color:#64748b">Diperbarui: -</div>
      <button type="button" class="btn danger" style="width:100%;margin-top:14px" onclick="closeAdminActivityModal()">Tutup</button>
    </div>`;
  modal.style.setProperty('z-index', '2147483050', 'important');
  modal.addEventListener('click', function(event){ if(event.target === modal) closeAdminActivityModal(); });
  document.body.appendChild(modal);
  return modal;
}
function syncAdminActivityButton(storeId){
  const store = String(storeId || ADMIN_ACTION_STORE_ID || '').trim().toUpperCase();
  const grid = document.querySelector('#adminActionModal .admin-action-grid');
  if(!grid) return;
  let button = document.getElementById('adminActionActivityBtn');
  if(!button){
    button = document.createElement('button');
    button.type = 'button';
    button.id = 'adminActionActivityBtn';
    button.className = 'btn-mini tosca';
    button.textContent = 'Aktivitas';
    button.onclick = openAdminActivityModal;
    const deleteButton = document.getElementById('adminActionDeleteBtn');
    if(deleteButton && deleteButton.parentElement === grid) grid.insertBefore(button, deleteButton);
    else grid.appendChild(button);
  }
  const presence = getPresenceMeta(store);
  button.style.display = (store && presence.online) ? '' : 'none';
  button.disabled = !(store && presence.online);
  button.setAttribute('aria-hidden', presence.online ? 'false' : 'true');
}
function adminActivitySetStatus(online){
  const badge = document.getElementById('adminActivityStatus');
  const dot = document.getElementById('adminActivityStatusDot');
  const text = document.getElementById('adminActivityStatusText');
  if(badge){
    badge.style.background = online ? '#dcfce7' : '#fee2e2';
    badge.style.color = online ? '#166534' : '#991b1b';
  }
  if(dot) dot.style.background = online ? '#22c55e' : '#ef4444';
  if(text) text.textContent = online ? 'Online' : 'Offline';
}
async function loadAdminUserActivity(storeId, silent=false){
  const store = String(storeId || ADMIN_ACTIVITY_STORE_ID || '').trim().toUpperCase();
  if(!store || store !== ADMIN_ACTIVITY_STORE_ID) return;
  const page = document.getElementById('adminActivityPage');
  const updated = document.getElementById('adminActivityUpdated');
  if(!silent && page) page.textContent = 'Memuat...';
  try{
    const response = await fetch(`?api=admin_user_activity&storeId=${encodeURIComponent(store)}&_=${Date.now()}`, {
      cache:'no-store',
      credentials:'same-origin',
      headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}
    });
    const data = await response.json().catch(()=>null);
    if(!response.ok || !data || !data.ok) throw new Error((data && data.msg) || 'Gagal memuat aktivitas');
    if(!ADMIN_PRESENCE[store]) ADMIN_PRESENCE[store] = {};
    ADMIN_PRESENCE[store].online = !!data.online;
    ADMIN_PRESENCE[store].lastSeenTs = Number(data.lastSeenTs || 0);
    ADMIN_PRESENCE[store].activityTitle = String(data.activityTitle || '');
    ADMIN_PRESENCE[store].activityKey = String(data.activityKey || '');
    ADMIN_PRESENCE[store].activityUpdatedTs = Number(data.activityUpdatedTs || 0);
    adminActivitySetStatus(!!data.online);
    if(page) page.textContent = data.online ? (String(data.activityTitle || '').trim() || 'Beranda') : 'User sudah tidak online';
    const updateTs = Number(data.activityUpdatedTs || data.lastSeenTs || 0);
    if(updated) updated.textContent = 'Diperbarui: ' + formatPresenceTime(updateTs);
    syncAdminActivityButton(store);
  }catch(error){
    if(page && !silent) page.textContent = error.message || 'Gagal memuat aktivitas';
    if(updated && !silent) updated.textContent = 'Diperbarui: gagal terhubung';
  }
}
function openAdminActivityModal(){
  const store = String(ADMIN_ACTION_STORE_ID || '').trim().toUpperCase();
  if(!store) return;
  if(!getPresenceMeta(store).online){
    syncAdminActivityButton(store);
    alert('User sudah tidak online.');
    return;
  }
  ADMIN_ACTIVITY_STORE_ID = store;
  const modal = ensureAdminActivityUi();
  const code = document.getElementById('adminActivityStoreId');
  const name = document.getElementById('adminActivityStoreName');
  if(code) code.textContent = store;
  if(name) name.textContent = adminGetStoreName(store) || '-';
  adminActivitySetStatus(true);
  const action = document.getElementById('adminActionModal');
  if(action){ action.style.opacity = '0'; action.style.pointerEvents = 'none'; }
  modal.style.display = 'flex';
  modal.setAttribute('aria-hidden', 'false');
  clearInterval(ADMIN_ACTIVITY_TIMER);
  loadAdminUserActivity(store, false);
  ADMIN_ACTIVITY_TIMER = setInterval(function(){ loadAdminUserActivity(store, true); }, 1000);
}
function closeAdminActivityModal(){
  clearInterval(ADMIN_ACTIVITY_TIMER);
  ADMIN_ACTIVITY_TIMER = null;
  const modal = document.getElementById('adminActivityModal');
  if(modal){ modal.style.display = 'none'; modal.setAttribute('aria-hidden', 'true'); }
  const action = document.getElementById('adminActionModal');
  if(action){ action.style.opacity = ''; action.style.pointerEvents = ''; action.style.display = 'flex'; }
  syncAdminActivityButton(ADMIN_ACTION_STORE_ID);
  ADMIN_ACTIVITY_STORE_ID = '';
}

function openAdminActionModal(storeId){
  const store = String(storeId || "").trim().toUpperCase();
  if(!store) return;
  ADMIN_ACTION_STORE_ID = store;
  const ts = Number(ADMIN_EXPIRY[store] || 0);
  const pin = String(ADMIN_PIN[store] || "0000").replace(/[^0-9]/g,'').padStart(4,'0').slice(0,4);
  const prem = !!(ADMIN_PREMIUM[store]);
  const canDel = (store !== STORE_ID);

  document.getElementById("adminActionStoreId").textContent = store;
  document.getElementById("adminActionStoreName").textContent = adminGetStoreName(store);
  applyAdminActionBadgeStyles(pin, prem);
  syncAdminActionJoinDate(store);
  document.getElementById("adminActionPremiumBtn").textContent = prem ? "Nonaktifkan Premium" : "Aktifkan Premium";
  document.getElementById("adminActionDeleteBtn").style.display = canDel ? "" : "none";
  document.getElementById("adminActionExpiryBtn").style.display = "";
  const impBtn = document.getElementById("adminActionImpersonateBtn");
  if(impBtn){
    impBtn.style.display = (store===STORE_ID || IS_IMPERSONATING) ? "none" : "";
    impBtn.textContent = "Masuk User";
  }
  const presence = getPresenceMeta(store);
  const presenceDot = document.getElementById("adminActionPresenceDot");
  if(presenceDot){
    presenceDot.style.background = presence.dotColor;
  }
  syncAdminActivityButton(store);
  const badgeText = renderExpiryBadge(ts);
  const badgeEl = document.getElementById("adminActionExpiryBadge");
  if(badgeText){
    badgeEl.style.display = "";
    badgeEl.textContent = badgeText;
    badgeEl.className = `badge ${expiryTone(ts)==="green"?"expiry-badge expiry-green":"expiry-badge expiry-red"}`;
  }else{
    badgeEl.style.display = "none";
    badgeEl.textContent = "";
    badgeEl.className = "badge";
  }
  let saldoBadge = document.getElementById('adminActionPointBadge');
  const nameRow = document.querySelector('#adminActionStoreName') ? document.querySelector('#adminActionStoreName').parentElement : null;
  if(saldoBadge){ saldoBadge.textContent='Point: '+adminFormatPoint(ADMIN_POINT[store]||0); saldoBadge.onclick=function(ev){ ev.stopPropagation(); openAdminPointModal(store); }; saldoBadge.title='Klik untuk tambah/kurang point (diamankan server)'; }

  const adm = document.getElementById("adminModal");
  if(adm && !document.body.classList.contains("admin-page")){
    adm.style.opacity = "0";
    adm.style.pointerEvents = "none";
  }
  document.getElementById("adminActionModal").style.display = "flex";

  if(!ADMIN_STORE_NAMES[store] || ADMIN_STORE_NAMES[store] === "-"){
    adminFetchStoreName(store).then(name=>{
      if(ADMIN_ACTION_STORE_ID === store){
        document.getElementById("adminActionStoreName").textContent = name || "-";
      }
      adminRenderFiltered();
    });
  }
}

function closeAdminActionModal(){
  clearInterval(ADMIN_ACTIVITY_TIMER);
  ADMIN_ACTIVITY_TIMER = null;
  ADMIN_ACTIVITY_STORE_ID = '';
  const activityModal = document.getElementById('adminActivityModal');
  if(activityModal){ activityModal.style.display = 'none'; activityModal.setAttribute('aria-hidden', 'true'); }
  document.getElementById("adminActionModal").style.display = "none";
  const adm = document.getElementById("adminModal");
  if(adm){
    adm.style.opacity = "";
    adm.style.pointerEvents = "";
    adm.style.display = "flex";
  }
}

function hideAdminActionForChildModal(){
  const action = document.getElementById("adminActionModal");
  if(action) action.style.display = "none";
  const adm = document.getElementById("adminModal");
  if(adm && !document.body.classList.contains("admin-page")){
    adm.style.opacity = "0";
    adm.style.pointerEvents = "none";
  }
}
function restoreAdminAfterChildModal(){
  const adm = document.getElementById("adminModal");
  if(adm){
    adm.style.opacity = "";
    adm.style.pointerEvents = "";
    adm.style.display = "flex";
  }
}

function adminActionOpenDetail(){
  const store = ADMIN_ACTION_STORE_ID;
  if(!store) return;
  hideAdminActionForChildModal();
  openStoreDetail(store);
}

function adminActionOpenPin(){
  const store = ADMIN_ACTION_STORE_ID;
  if(!store) return;
  hideAdminActionForChildModal();
  openPinModal(store);
}

function adminActionSetExpiry(){
  const store = ADMIN_ACTION_STORE_ID;
  if(!store) return;
  hideAdminActionForChildModal();
  adminSetExpiry(store);
}

function adminActionDelete(){
  const store = ADMIN_ACTION_STORE_ID;
  if(!store) return;
  hideAdminActionForChildModal();
  adminDelete(store);
}

async function adminImpersonate(storeId){
  const code = String(storeId || '').trim().toUpperCase().replace(/[^A-Z0-9]/g,'').slice(0,10);
  if(!code){ alert('Kode toko tidak valid'); return; }
  if(!IS_ADMIN){ alert('Hanya admin yang bisa menggunakan fitur ini'); return; }
  if(IS_IMPERSONATING){ alert('Anda sedang berada dalam mode masuk user. Kembali ke admin dulu.'); return; }
  if(code === STORE_ID){ alert('Anda sudah berada di akun admin'); return; }
  const ok = confirm(`Masuk sebagai user ${code}?`);
  if(!ok) return;
  try{
    const res = await fetch('?api=admin_impersonate',{
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ storeId: code })
    });
    const j = await res.json();
    if(!j.ok){ alert(j.msg || 'Gagal masuk sebagai user'); return; }
    window.location.href = 'index.php';
  }catch(e){
    alert('Koneksi gagal');
  }
}

async function exitAdminImpersonation(){
  if(!IS_IMPERSONATING){ alert('Mode admin tidak aktif'); return; }
  try{
    const res = await fetch('?api=admin_impersonation_exit',{
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body:'{}'
    });
    const j = await res.json();
    if(!j.ok){ alert(j.msg || 'Gagal kembali ke admin'); return; }
    try{
      localStorage.removeItem('ALFASTORE_OPEN_ADMIN_PASS_AFTER_LOGOUT');
      localStorage.setItem('ALFASTORE_OPEN_ADMIN_MODAL','1');
      localStorage.removeItem('ALFASTORE_LOGIN2_STORE_CODE');
    }catch(e){}
    const target = (j && j.redirect ? String(j.redirect) : 'index.php?open_admin=1');
    window.location.href = target.indexOf('open_admin=1') >= 0 ? target : (target + (target.indexOf('?')>=0 ? '&' : '?') + 'open_admin=1');
  }catch(e){
    alert('Koneksi gagal');
  }
}

function adminActionImpersonate(){
  const store = ADMIN_ACTION_STORE_ID;
  if(!store) return;
  adminImpersonate(store);
}

function adminActionTogglePremium(){
  const store = ADMIN_ACTION_STORE_ID;
  if(!store) return;
  adminTogglePremium(store);
  const prem = !!(ADMIN_PREMIUM[store]);
  setTimeout(()=>{
    const np = !!(ADMIN_PREMIUM[store]);
    document.getElementById("adminActionPremiumBtn").textContent = np ? "Nonaktifkan Premium" : "Aktifkan Premium";
    applyAdminActionBadgeStyles(String(ADMIN_PIN[store] || "0000").replace(/[^0-9]/g,'').padStart(4,'0').slice(0,4), np);
  }, 150);
}

function openAdmin2Modal(){
  if(!IS_ADMIN) return;
  const adm = document.getElementById('adminModal');
  if(adm){ adm.style.opacity='0'; adm.style.pointerEvents='none'; }
  const sel = document.getElementById('admin2Select');
  const toggle = document.getElementById('admin2Toggle');
  const saveBtn = document.getElementById('admin2SaveBtn');
  const stores = (Array.isArray(ADMIN_STORES) ? ADMIN_STORES : []).filter(s => String(s||'').toUpperCase() !== 'ADMIN');
  sel.innerHTML = stores.map(s=>{ const isA2 = !!ADMIN_ADMIN2[s]; return `<option value="${s}">${s}${isA2 ? ' (Admin2 ON)' : ''}</option>`; }).join('') || '<option value="">Belum ada toko</option>';
  const info = document.getElementById('admin2Info');
  const updateInfo = ()=>{
    const v = String(sel.value||'').toUpperCase();
    const enabled = !!(v && ADMIN_ADMIN2[v]);
    if(toggle) toggle.checked = enabled;
    if(saveBtn) saveBtn.textContent = enabled ? 'Simpan (Admin2 ON)' : 'Simpan (Admin2 OFF)';
    info.textContent = v ? (enabled ? `Status ${v}: Admin2 ON` : `Status ${v}: Admin2 OFF`) : '-';
  };
  sel.onchange = updateInfo;
  if(toggle) toggle.onchange = ()=>{
    const v = String(sel.value||'').toUpperCase();
    if(saveBtn) saveBtn.textContent = toggle.checked ? 'Simpan (Admin2 ON)' : 'Simpan (Admin2 OFF)';
    if(info && v) info.textContent = `Status ${v}: ${toggle.checked ? 'Admin2 ON' : 'Admin2 OFF'}`;
  };
  updateInfo();
  modalOpen();
  document.getElementById('admin2Modal').style.display='flex';
}
function closeAdmin2Modal(){
  const m = document.getElementById('admin2Modal');
  if(m) m.style.display='none';
  const adm = document.getElementById('adminModal');
  if(adm){ adm.style.opacity=''; adm.style.pointerEvents=''; adm.style.display='flex'; }
  modalClose();
}
async function saveAdmin2Selection(){
  const sel = document.getElementById('admin2Select');
  const toggle = document.getElementById('admin2Toggle');
  const storeId = String(sel?.value || '').trim().toUpperCase();
  const admin2 = !!(toggle && toggle.checked);
  if(!storeId){ alert('Pilih kode toko'); return; }
  try{
    const res = await fetch('?api=admin2_set',{
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ storeId, admin2 })
    });
    const j = await res.json().catch(()=>null);
    if(!j || !j.ok){ alert((j && j.msg) ? j.msg : 'Gagal simpan Admin2'); return; }
    ADMIN_ADMIN2 = (j.admin2Map || ADMIN_ADMIN2 || {});
    alert(`Admin2 ${admin2 ? 'diaktifkan' : 'dimatikan'} untuk ${storeId}.`);
    closeAdmin2Modal();
    adminReload();
  }catch(e){
    alert('Gagal simpan Admin2.');
  }
}
function openAdmin2AddUserModal(){
  if(!IS_ADMIN2) return;
  modalOpen();
  const m = document.getElementById('admin2AddUserModal');
  if(m) m.style.display='flex';
  const inp = document.getElementById('admin2AddUserInput');
  const pin = document.getElementById('admin2AddUserPinInput');
  if(inp) inp.value='';
  if(pin) pin.value='';
  setTimeout(()=>{ try{ (inp || pin).focus(); }catch(e){} }, 50);
}
function closeAdmin2AddUserModal(){
  const m = document.getElementById('admin2AddUserModal');
  if(m) m.style.display='none';
  modalClose();
}
async function admin2AddUser(){
  const inp = document.getElementById('admin2AddUserInput');
  const pinInp = document.getElementById('admin2AddUserPinInput');
  const storeId = String(inp?.value || '').trim().toUpperCase().replace(/[^A-Z0-9]/g,'').slice(0,4);
  const pin = String(pinInp?.value || '').replace(/[^0-9]/g,'').slice(0,4);
  if(!storeId || storeId.length > 4){ alert('Kode toko wajib diisi maksimal 4 angka / huruf.'); return; }
  if(pin.length !== 4){ alert('PIN wajib 4 angka.'); return; }
  try{
    const res = await fetch('?api=admin2_add_user',{
      method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ storeId, pin })
    });
    const j = await res.json().catch(()=>null);
    if(!j || !j.ok){ alert((j && j.msg) ? j.msg : 'Gagal tambah user'); return; }
    alert(`Sukses! User ${storeId} berhasil ditambahkan. Premium aktif dan expired 2 hari.`);
    closeAdmin2AddUserModal();
    try{ adminReload(false); }catch(e){}
  }catch(e){
    alert('Gagal tambah user.');
  }
}

let ADMIN979_TYPE = 'reguler';
let ADMIN979_AUTOSAVE_TIMER = null;
let ADMIN979_LOADING = false;
let ADMIN979_SWITCH_TOKEN = 0;
function admin979NormalizeType(type){ const t=String(type || 'reguler').toLowerCase(); return t === 'beanspot' ? 'beanspot' : (t === 'strokok' ? 'strokok' : 'reguler'); }
function admin979TypeLabel(type){ const t = admin979NormalizeType(type || ADMIN979_TYPE); return t === 'beanspot' ? 'Rack 979 Beanspot' : (t === 'strokok' ? 'Rack 000' : 'Rack 979 Reguler'); }
function admin979SetSwitchLoading(show, text='Memuat data...'){
  const box = document.getElementById('admin979SwitchLoading');
  const txt = document.getElementById('admin979SwitchLoadingText');
  const reg = document.getElementById('admin979TypeReguler');
  const bean = document.getElementById('admin979TypeBeanspot');
  const rak000 = document.getElementById('admin979TypeRak000');
  if(txt) txt.textContent = text;
  if(box) box.style.display = show ? 'flex' : 'none';
  reg?.classList.toggle('is-loading', !!show);
  bean?.classList.toggle('is-loading', !!show);
  rak000?.classList.toggle('is-loading', !!show);
}
function admin979ApplyTypeUI(type){
  const t = admin979NormalizeType(type);
  document.getElementById('admin979TypeReguler')?.classList.toggle('active', t === 'reguler');
  document.getElementById('admin979TypeBeanspot')?.classList.toggle('active', t === 'beanspot');
  document.getElementById('admin979TypeRak000')?.classList.toggle('active', t === 'strokok');
}
async function admin979SelectType(type){
  const nextType = admin979NormalizeType(type);
  const previousType = ADMIN979_TYPE;
  const token = ++ADMIN979_SWITCH_TOKEN;
  clearTimeout(ADMIN979_AUTOSAVE_TIMER);
  if(nextType !== previousType){ await admin979AutoSaveNow(true, previousType); }
  ADMIN979_TYPE = nextType;
  admin979ApplyTypeUI(nextType);
  admin979SetSwitchLoading(true, 'Memuat data 979 ' + admin979TypeLabel(nextType) + '...');
  try{
    await admin979LoadConfig(nextType, token);
  }finally{
    if(token === ADMIN979_SWITCH_TOKEN) admin979SetSwitchLoading(false);
  }
}
function admin979AutoSaveDraft(){
  if(ADMIN979_LOADING) return;
  clearTimeout(ADMIN979_AUTOSAVE_TIMER);
  const typeSnapshot = ADMIN979_TYPE;
  ADMIN979_AUTOSAVE_TIMER = setTimeout(() => admin979AutoSaveNow(true, typeSnapshot), 500);
}
function admin979CleanInputNow(){
  const inp = document.getElementById('admin979PluInput');
  if(!inp) return '';
  const cleaned = normalize979PluText(inp.value);
  if(inp.value !== cleaned) inp.value = cleaned;
  return cleaned;
}
async function admin979AutoSaveNow(silent=false, typeOverride=null){
  const inp = document.getElementById('admin979PluInput');
  if(!inp) return;
  const saveType = admin979NormalizeType(typeOverride || ADMIN979_TYPE);
  const plus = normalize979PluText(inp.value || '');
  if(!plus) return;
  try{
    const res = await fetch('?api=oh979_save_config', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({type: saveType, plus})
    });
    const j = await res.json().catch(()=>null);
    if(j && j.status){
      const savedPlus = normalize979PluText(String(j.plus || plus));
      // Jangan ubah isi textarea saat user sedang mengetik supaya koma tidak hilang.
      const info = document.getElementById('admin979Info');
      if(info && saveType === ADMIN979_TYPE) info.textContent = `PLU otomatis tersimpan di ${admin979TypeLabel(saveType)}. Total PLU: ${savedPlus.split(',').filter(Boolean).length}`;
    }else if(!silent){ alert((j && j.message) ? j.message : 'Gagal simpan otomatis data 979'); }
  }catch(e){ if(!silent) alert('Gagal simpan otomatis data 979.'); }
}

function openAdmin979Modal(storeId=''){
  if(!IS_ADMIN) return;
  const m = document.getElementById('admin979Modal');
  const adm = document.getElementById('adminModal');
  const act = document.getElementById('adminActionModal');
  if(adm && adm.style.display === 'flex'){ adm.style.opacity = '0'; adm.style.pointerEvents = 'none'; }
  if(act && act.style.display === 'flex'){ act.style.display = 'none'; }
  modalOpen();
  const inp = document.getElementById('admin979PluInput');
  const info = document.getElementById('admin979Info');
  if(inp) inp.value = '';
  if(info) info.textContent = 'Belum ada data.';
  m.style.display = 'flex';
  admin979SelectType(ADMIN979_TYPE || 'reguler');
}

function closeAdmin979Modal(){
  clearTimeout(ADMIN979_AUTOSAVE_TIMER);
  admin979SetSwitchLoading(false);
  const m = document.getElementById('admin979Modal');
  if(m) m.style.display = 'none';
  const adm = document.getElementById('adminModal');
  if(adm){ adm.style.opacity = ''; adm.style.pointerEvents = ''; adm.style.display = 'flex'; }
}

function adminActionOpen979(){ openAdmin979Modal(''); }

function openSOGrandKeyAdmin(){
  if(!IS_ADMIN) return;
  const adm = document.getElementById('adminModal');
  const act = document.getElementById('adminActionModal');
  if(act && act.style.display === 'flex'){ act.style.display = 'none'; }
  window.location.href = `${location.pathname}?page=sogrand_key_admin`;
}

function openNewUserKeyAdmin(){
  if(!IS_ADMIN) return;
  const adm = document.getElementById('adminModal');
  const act = document.getElementById('adminActionModal');
  if(act && act.style.display === 'flex'){ act.style.display = 'none'; }
  window.location.href = `${location.pathname}?page=new_user_key_admin`;
}

function normalize979PluText(raw){
  const nums = String(raw || '').split(/[^0-9]+/).map(v=>v.trim()).filter(Boolean);
  const uniq = [];
  const seen = new Set();
  for(const num of nums){
    if(seen.has(num)) continue;
    seen.add(num);
    uniq.push(num);
  }
  return uniq.join(',');
}

function admin979PreviewSort(){
  const inp = document.getElementById('admin979PluInput');
  if(!inp) return;
  inp.value = normalize979PluText(inp.value);
}

function admin979ResetFormState(message='Form PLU dikosongkan.'){
  const inp = document.getElementById('admin979PluInput');
  const info = document.getElementById('admin979Info');
  if(inp) inp.value = '';
  if(info) info.textContent = message;
}

async function admin979LoadConfig(typeOverride=null, token=null){
  const loadType = admin979NormalizeType(typeOverride || ADMIN979_TYPE);
  const info = document.getElementById('admin979Info');
  const inp = document.getElementById('admin979PluInput');
  ADMIN979_LOADING = true;
  if(info) info.textContent = `Memuat data ${admin979TypeLabel(loadType)}...`;
  try{
    const j = await fetchOH979Config(loadType);
    if(token !== null && token !== ADMIN979_SWITCH_TOKEN) return;
    if(loadType !== ADMIN979_TYPE) return;
    if(j && j.status){
      const normalized = normalize979PluText(String(j.plus || ''));
      if(inp) inp.value = normalized;
      if(info) info.textContent = `Data ditemukan untuk ${admin979TypeLabel(loadType)}. Total PLU: ${normalized.split(',').filter(Boolean).length}`;
    }else{
      admin979ResetFormState(`Belum ada data 979 untuk ${admin979TypeLabel(loadType)}.`);
    }
  }finally{
    ADMIN979_LOADING = false;
  }
}

async function admin979DeleteConfig(){
  const info = document.getElementById('admin979Info');
  const deleteType = admin979NormalizeType(ADMIN979_TYPE);
  if(!confirm(`Hapus data PLU 979 untuk ${admin979TypeLabel(deleteType)}?`)) return;
  try{
    showLoading('Hapus data 979...');
    const res = await fetch('?api=oh979_delete_config', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({type: deleteType})
    });
    const j = await res.json().catch(()=>null);
    if(!j || !j.status){ alert((j && j.message) ? j.message : 'Gagal hapus data 979'); return; }
    if(deleteType === ADMIN979_TYPE){
      admin979ResetFormState(`Data 979 untuk ${admin979TypeLabel(deleteType)} sudah dihapus.`);
      if(info) info.textContent = `Data 979 untuk ${admin979TypeLabel(deleteType)} sudah dihapus.`;
    }
    alert(`Data 979 berhasil dihapus untuk ${admin979TypeLabel(deleteType)}`);
  }catch(e){ alert('Gagal hapus data 979.'); }
  finally{ hideLoading(); }
}

async function admin979SaveConfig(){
  const info = document.getElementById('admin979Info');
  const inp = document.getElementById('admin979PluInput');
  const saveType = admin979NormalizeType(ADMIN979_TYPE);
  const plus = admin979CleanInputNow();
  if(!plus){ alert('PLU 979 kosong'); return; }
  if(inp) inp.value = plus;
  try{
    showLoading('Simpan data 979...');
    const res = await fetch('?api=oh979_save_config', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({type: saveType, plus})
    });
    const j = await res.json().catch(()=>null);
    if(!j || !j.status){ alert((j && j.message) ? j.message : 'Gagal simpan data 979'); return; }
    const savedPlus = normalize979PluText(String(j.plus || plus));
    if(saveType === ADMIN979_TYPE && inp) inp.value = savedPlus;
    if(saveType === ADMIN979_TYPE && info) info.textContent = `PLU tersimpan di ${admin979TypeLabel(saveType)}. Total PLU: ${savedPlus.split(',').filter(Boolean).length}`;
    alert(`PLU 979 berhasil tersimpan di ${admin979TypeLabel(saveType)}`);
  }catch(e){ alert('Gagal simpan data 979.'); }
  finally{ hideLoading(); }
}
function openAdminModal(){
  if(!IS_ADMIN) return;
  if(!hasAdminAuth()){ openAdminPassModal(); return; }
  // Admin dibuka sebagai popup modal setelah login.
  modalOpen();
  document.getElementById("adminModal").style.display="flex";
  adminReload();
  startAdminAutoRefresh();
}
function closeAdminModal(){
  const adm = document.getElementById("adminModal");
  adm.style.opacity = "";
  adm.style.pointerEvents = "";
  adm.style.display="none";
  const act = document.getElementById("adminActionModal");
  if(act) act.style.display = "none";
  clearInterval(ADMIN_ACTIVITY_TIMER);
  ADMIN_ACTIVITY_TIMER = null;
  ADMIN_ACTIVITY_STORE_ID = '';
  const activityModal = document.getElementById('adminActivityModal');
  if(activityModal){ activityModal.style.display = 'none'; activityModal.setAttribute('aria-hidden', 'true'); }
  stopAdminAutoRefresh();
  modalClose();
}

async function openStoreDetail(storeId){
  try{
    modalOpen();
    const m = document.getElementById("storeDetailModal");
    document.getElementById("detailStoreId").textContent = storeId || "-";
    document.getElementById("detailNama").textContent = "-";
    document.getElementById("detailAlamat").textContent = "-";
    document.getElementById("detailKota").textContent = "-";
    document.getElementById("detailDcId").textContent = "-";
    m.style.display = "flex";

    showLoading("Ambil detail toko...");
    const res = await fetch(`?api=store_detail&storeId=${encodeURIComponent(storeId||"")}`);
    const j = await res.json();
    if(!j || !j.ok){
      alert((j && j.msg) ? j.msg : "Gagal ambil detail toko");
      return;
    }

    // sesuai permintaan: ambil dari field header2/header5/city/dcId saja
    document.getElementById("detailNama").textContent = j.header2 || "-";
    document.getElementById("detailAlamat").textContent = j.header5 || "-";
    document.getElementById("detailKota").textContent = j.city || "-";
    document.getElementById("detailDcId").textContent = j.dcId || "-";
  }catch(e){
    alert("Gagal ambil detail toko.");
  }finally{
    hideLoading();
  }
}

function closeStoreDetail(){
  const m = document.getElementById("storeDetailModal");
  if(m) m.style.display = "none";
  modalClose();
  restoreAdminAfterChildModal();
}


function openAdminPassModal(){
  if(!IS_ADMIN) return;
  if(hasAdminAuth()){
    if(typeof openAdminModal === "function"){ openAdminModal(); }
    return;
  }
  modalOpen();
  const m = document.getElementById("adminPassModal");
  const err = document.getElementById("adminPassErr");
  const inp = document.getElementById("adminPassInput");
  if(err){ err.style.display="none"; err.textContent=""; }
  if(inp){ inp.value=""; }
  m.style.display="flex";
  // fokus setelah render
  setTimeout(()=>{ try{ inp && inp.focus(); }catch(e){} }, 50);
}
function closeAdminPassModal(){
  const m = document.getElementById("adminPassModal");
  m.style.display="none";
  modalClose();
}
async function submitAdminPassword(){
  const inp = document.getElementById("adminPassInput");
  const err = document.getElementById("adminPassErr");
  const val = (inp && inp.value) ? String(inp.value).trim() : "";
  {
    // cek password ke server (proxy.php)
    try{
      const res = await fetch(`${API_URL}?api=admin_pass_check`, {
        method:"POST",
        headers:{"Content-Type":"application/json"},
        body: JSON.stringify({pass: val}),
        credentials:"same-origin"
      });
      const js = await res.json().catch(()=>null);
      if(!res.ok || !js || js.ok!==true){
        if(err){ err.textContent = (js && (js.msg||js.error)) ? (js.msg||js.error) : "Password salah."; err.style.display="block"; }
        if(inp){ inp.focus(); inp.select && inp.select(); }
        return;
      }
    }catch(e){
      if(err){ err.textContent = "Gagal verifikasi password."; err.style.display="block"; }
      return;
    }
  }
  const exp = Date.now() + 86400000;
  setAdminAuth(exp);
  // buka admin
  document.getElementById("adminPassModal").style.display="none";
  modalClose();
  openAdminModal();
}

// allow enter key
document.addEventListener("keydown", (e)=>{
  const m = document.getElementById("adminPassModal");
  if(!m || m.style.display!=="flex") return;
  if(e.key === "Enter"){ e.preventDefault(); submitAdminPassword(); }
  if(e.key === "Escape"){ e.preventDefault(); closeAdminPassModal(); }
});
document.addEventListener("keydown", (e)=>{
  const m = document.getElementById("adminActionModal");
  if(!m || m.style.display!=="flex") return;
  if(e.key === "Escape"){ e.preventDefault(); closeAdminActionModal(); }
});

// expiry modal shortcuts
document.addEventListener("keydown", (e)=>{
  const m = document.getElementById("expiryModal");
  if(!m || m.style.display!=="flex") return;
  if(e.key === "Escape"){ e.preventDefault(); closeExpiryModal(); }
  if(e.key === "Enter"){ e.preventDefault(); saveExpiryFromModal(false); }
});

// pin modal shortcuts
document.addEventListener("keydown", (e)=>{
  const m = document.getElementById("pinModal");
  if(!m || m.style.display!=="flex") return;
  if(e.key === "Escape"){ e.preventDefault(); closePinModal(); }
  if(e.key === "Enter"){ e.preventDefault(); savePinFromModal(); }
});



/* BANNER (ADMIN) */
let _bannerInit = false;

function openBannerModal(){
  if(!IS_ADMIN) return;
  // tampilkan banner popup TANPA membuat admin modal "hilang" dulu (anti kedip)
  const adm = document.getElementById("adminModal");
  adm.style.opacity = "0";
  adm.style.pointerEvents = "none";
  document.getElementById("bannerModal").style.display="flex";
  if(!_bannerInit){
    _bannerInit = true;
    const bf = document.getElementById("bannerFile");
    bf.addEventListener("change", ()=>{
      const f = bf.files && bf.files[0];
      const nameEl = document.getElementById("bannerFileName");
      const prevWrap = document.getElementById("bannerPreviewWrap");
      const prev = document.getElementById("bannerPreview");
      if(!f){
        if(nameEl) nameEl.textContent = "-";
        if(prevWrap) prevWrap.style.display="none";
        return;
      }
      if(nameEl) nameEl.textContent = f.name;
      const url = URL.createObjectURL(f);
      prev.src = url;
      prevWrap.style.display="block";
    });
  }
  bannerLoadStatus();
}

function closeBannerModal(){
  document.getElementById("bannerModal").style.display="none";
  // balik ke admin modal (masih dalam alur modal)
  const adm = document.getElementById("adminModal");
  adm.style.opacity = "";
  adm.style.pointerEvents = "";
  adm.style.display = "flex";
}

function openButtonModal(){
  if(!IS_ADMIN) return;
  const adm = document.getElementById("adminModal");
  if(adm){ adm.style.opacity = "0"; adm.style.pointerEvents = "none"; }
  const m = document.getElementById("buttonModal");
  if(m) m.style.display = "flex";
  loadUiConfig();
}
function closeButtonModal(){
  const m = document.getElementById("buttonModal");
  if(m) m.style.display = "none";
  const adm = document.getElementById("adminModal");
  if(adm){ adm.style.opacity = ""; adm.style.pointerEvents = ""; adm.style.display = "flex"; }
}

async function bannerLoadStatus(){
  try{
    const [r1, r2] = await Promise.all([
      fetch("?api=banner_get", {credentials:"same-origin"}),
      fetch("?api=alert_get", {credentials:"same-origin"})
    ]);
    const j = await r1.json().catch(()=>null);
    const a = await r2.json().catch(()=>null);
    const st = document.getElementById("bannerStatus");
    const msg = document.getElementById("alertAdminMsg");
    if(st) st.textContent = (j && j.ok && j.url) ? "ADA" : "KOSONG";
    const titleEl = document.getElementById("alertTitleInput");
    const textEl = document.getElementById("alertMessageInput");
    const btnTextEl = document.getElementById("alertButtonTextInput");
    const btnUrlEl = document.getElementById("alertButtonUrlInput");
    if(titleEl) titleEl.value = (a && a.ok && a.enabled) ? String(a.title||'') : '';
    if(textEl) textEl.value = (a && a.ok && a.enabled) ? String(a.message||'') : '';
    if(btnTextEl) btnTextEl.value = (a && a.ok && a.enabled) ? String(a.buttonText||'') : '';
    if(btnUrlEl) btnUrlEl.value = (a && a.ok && a.enabled) ? String(a.buttonUrl||'') : '';
    if(msg) msg.textContent = (a && a.ok && a.enabled) ? 'Alert aktif' : 'Alert belum dibuat';
  }catch(e){}
}

async function saveCustomAlert(){
  const msg = document.getElementById('alertAdminMsg');
  const title = String((document.getElementById('alertTitleInput')||{}).value || '').trim();
  const message = String((document.getElementById('alertMessageInput')||{}).value || '').trim();
  const buttonText = String((document.getElementById('alertButtonTextInput')||{}).value || '').trim();
  const buttonUrl = String((document.getElementById('alertButtonUrlInput')||{}).value || '').trim();
  if(!title || !message){ if(msg) msg.textContent='Judul dan isi alert wajib diisi'; return; }
  if(buttonUrl && !/^https?:\/\//i.test(buttonUrl)){ if(msg) msg.textContent='Link tombol harus diawali http:// atau https://'; return; }
  if(msg) msg.textContent='Menyimpan alert...';
  try{
    const r = await fetch('?api=alert_save',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({title,message,buttonText,buttonUrl})});
    const j = await r.json().catch(()=>null);
    if(!j || !j.ok){ if(msg) msg.textContent=(j&&j.msg)?j.msg:'Gagal menyimpan alert'; return; }
    if(msg) msg.textContent='Alert berhasil disimpan';
    await loadBanner();
    await bannerLoadStatus();
  }catch(e){ if(msg) msg.textContent='Koneksi gagal'; }
}

async function deleteCustomAlert(){
  const msg = document.getElementById('alertAdminMsg');
  if(!confirm('Hapus alert ini?')) return;
  if(msg) msg.textContent='Menghapus alert...';
  try{
    const r = await fetch('?api=alert_delete',{method:'POST',credentials:'same-origin',headers:{'Accept':'application/json'}});
    const j = await r.json().catch(()=>null);
    if(!j || !j.ok){ if(msg) msg.textContent=(j&&j.msg)?j.msg:'Gagal menghapus alert'; return; }
    if(msg) msg.textContent='Alert dihapus';
    await loadBanner();
    await bannerLoadStatus();
  }catch(e){ if(msg) msg.textContent='Koneksi gagal'; }
}

async function uploadBanner(){
  const bf = document.getElementById("bannerFile");
  const f = bf.files && bf.files[0];
  if(!f){ alert("Pilih gambar dulu."); return; }
  const fd = new FormData();
  fd.append("banner", f);

  try{
    showLoading("Upload Banner…");
    const r = await fetch("?api=banner_upload", {method:"POST", body:fd, credentials:"same-origin"});
    const j = await r.json().catch(()=>null);
    if(!j || !j.ok){
      alert((j && j.msg) ? j.msg : "Gagal upload");
      return;
    }
    alert("Banner berhasil diupload.");
    // refresh placeholder banner
    await loadBanner();
    await bannerLoadStatus();
    // reset file input
    bf.value = "";
    const nameEl = document.getElementById("bannerFileName");
    if(nameEl) nameEl.textContent = "-";
    const prevWrap = document.getElementById("bannerPreviewWrap");
    if(prevWrap) prevWrap.style.display="none";
  }catch(e){
    alert("Gagal upload banner.");
  }finally{
    hideLoading();
  }
}

async function deleteBanner(){
  if(!IS_ADMIN){ return; }
  if(!confirm("Hapus banner sekarang?")) return;
  try{
    showLoading("Hapus Banner…");
    const r = await fetch("?api=banner_delete", {credentials:"same-origin"});
    const j = await r.json().catch(()=>null);
    if(!j || !j.ok){
      alert((j && j.msg) ? j.msg : "Gagal hapus banner");
      return;
    }
    alert("Banner dihapus.");
    await loadBanner();
    await bannerLoadStatus();
  }catch(e){
    alert("Gagal hapus banner.");
  }finally{
    hideLoading();
  }
}



function adminTopFormatTime(ts){
  ts = Number(ts||0);
  if(!ts) return '-';
  try{return new Intl.DateTimeFormat('id-ID',{dateStyle:'medium',timeStyle:'short',timeZone:'Asia/Jakarta'}).format(new Date(ts*1000));}catch(e){return '-';}
}
function openTopOnlineModal(){
  const adm = document.getElementById('adminModal');
  if(adm && !document.body.classList.contains('admin-page')){ adm.style.opacity='0'; adm.style.pointerEvents='none'; }
  const m = document.getElementById('topOnlineModal');
  if(m) m.style.display='flex';
  loadTopOnline();
}
function closeTopOnlineModal(){
  const m=document.getElementById('topOnlineModal'); if(m) m.style.display='none';
  const adm=document.getElementById('adminModal'); if(adm){ adm.style.opacity=''; adm.style.pointerEvents=''; adm.style.display='flex'; }
}
async function loadTopOnline(){
  const body=document.getElementById('topOnlineList');
  const month=document.getElementById('topOnlineMonth');
  if(body) body.innerHTML='<div class="small">Memuat TOP online...</div>';
  try{
    const res=await fetch('?api=admin_top_online&_ts='+Date.now(),{cache:'no-store',credentials:'same-origin',headers:{'Accept':'application/json'}});
    const j=await res.json().catch(()=>null);
    if(!j || !j.ok){ if(body) body.innerHTML='<div class="small" style="color:#ef4444;font-weight:900">Gagal memuat TOP.</div>'; return; }
    if(month) month.textContent='Periode: '+String(j.month||'-')+' · reset otomatis tiap awal bulan';
    const items=Array.isArray(j.items)?j.items:[];
    if(!items.length){ if(body) body.innerHTML='<div class="small">Belum ada data online bulan ini.</div>'; return; }
    if(body) body.innerHTML=items.map((it,idx)=>`<div class="top-online-row"><div class="top-rank">${idx+1}</div><div class="top-main"><div class="top-code">${String(it.storeId||'-')}</div><div class="top-name">${String(it.name||'-')}</div><div class="small">Terakhir online: ${adminTopFormatTime(it.lastOnlineTs)}</div></div><div class="top-count">${Number(it.count||0)}x</div></div>`).join('');
  }catch(e){ if(body) body.innerHTML='<div class="small" style="color:#ef4444;font-weight:900">Koneksi gagal.</div>'; }
}

async function adminReload(showBusy=true, retryCount=2){
  const list = document.getElementById("adminList");
  const countEl = document.getElementById("adminCount");
  if(!list) return;
  if(ADMIN_RELOAD_BUSY && retryCount > 0) return;
  ADMIN_RELOAD_BUSY = true;
  if(ADMIN_RELOAD_RETRY_TIMER){ clearTimeout(ADMIN_RELOAD_RETRY_TIMER); ADMIN_RELOAD_RETRY_TIMER = null; }
  if(showBusy) list.innerHTML = `<div class="small">Memuat...</div>`;
  try{
    const controller = (typeof AbortController !== "undefined") ? new AbortController() : null;
    const timeoutId = setTimeout(()=>{ try{ if(controller) controller.abort(); }catch(_e){} }, 15000);
    let res;
    try{
      res = await fetch("?api=admin_list&_ts=" + Date.now(), {
        cache:"no-store",
        credentials:"same-origin",
        headers:{ "Accept":"application/json", "Cache-Control":"no-store", "X-Requested-With":"XMLHttpRequest" },
        signal: controller ? controller.signal : undefined
      });
    }finally{ clearTimeout(timeoutId); }
    const text = await res.text();
    let j = null;
    try{ j = JSON.parse(text); }catch(e){ j = null; }
    if(!res.ok || !j || !j.ok) throw new Error((j && j.msg) ? j.msg : "Gagal load list");
    const nextStores = Array.isArray(j.stores) ? j.stores.slice() : [];
    // FIX: cegah bug tampilan admin kosong/hilang sesaat saat refresh realtime mendapat respons kosong/anomali.
    if(nextStores.length === 0 && Array.isArray(ADMIN_STORES) && ADMIN_STORES.length > 0){
      console.warn("admin_list kosong sementara, mempertahankan daftar sebelumnya");
      return;
    }
    ADMIN_STORES = nextStores;
    ADMIN_EXPIRY = adminNormalizeNumberMap(j.expiryMap || {});
    ADMIN_PIN = (j.pinMap || {});
    ADMIN_PREMIUM = adminNormalizeBoolMap(j.premiumMap || {});
    ADMIN_POINT = adminNormalizeNumberMap(j.pointMap || {});
    ADMIN_CREATED = (j.createdMap || {});
    window.ADMIN_INVITE_PENDING_COUNT = Number(j.invitePendingCount || 0);
    ADMIN_ADMIN2 = (j.admin2Map || {});
    ADMIN_PRESENCE = (j.presenceMap || {});
    ADMIN_SERVER_TS = Number(j.serverTs || 0);
    const prevStoreNames = Object.assign({}, ADMIN_STORE_NAMES || {});
    const serverStoreNames = (j.storeNameMap || {});
    ADMIN_STORE_NAMES = {};
    ADMIN_STORES.forEach(s=>{
      const code = String(s || "").toUpperCase();
      const fromServer = serverStoreNames[code];
      if(fromServer && fromServer !== "-") ADMIN_STORE_NAMES[code] = String(fromServer);
      else if(prevStoreNames[code]) ADMIN_STORE_NAMES[code] = prevStoreNames[code];
    });
    ADMIN_STORE_NAME_LOADING = {};
    if(countEl) countEl.textContent = `${ADMIN_STORES.length} toko`;
    const onlineCountEl = document.getElementById("adminOnlineCount");
    if(onlineCountEl){
      const onlineTotal = ADMIN_STORES.filter(st=>getPresenceMeta(st).online).length;
      onlineCountEl.textContent = `${onlineTotal} online`;
    }
    bindAdminOnlineBadge();
    ADMIN_RELOAD_LAST_OK = Date.now();
    adminRenderFiltered();
    adminWarmStoreNames(ADMIN_STORES);
  }catch(e){
    if(retryCount > 0){
      if(showBusy) list.innerHTML = `<div class="small">Memuat ulang...</div>`;
      ADMIN_RELOAD_RETRY_TIMER = setTimeout(()=>{ ADMIN_RELOAD_BUSY=false; adminReload(showBusy, retryCount - 1); }, retryCount > 1 ? 700 : 1400);
    }else if(!ADMIN_RELOAD_LAST_OK){
      list.innerHTML = `<div class="small" style="font-weight:900;color:#b91c1c;background:#fef2f2;border:1px solid #fecaca;padding:12px;border-radius:8px">Daftar belum berhasil dimuat. <button type="button" onclick="adminReload(true,2)" style="margin-left:6px;border:0;border-radius:7px;padding:7px 10px;background:#2563eb;color:#fff;font-weight:900">Coba Lagi</button></div>`;
    }
  }finally{
    ADMIN_RELOAD_BUSY = false;
  }
}

function expiryDatePartsJakarta(ts){
  ts = Number(ts || 0);
  if(!ts) return null;
  const dt = new Date(ts*1000);
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone:'Asia/Jakarta',
    year:'numeric', month:'2-digit', day:'2-digit'
  }).formatToParts(dt);
  const get = type => (parts.find(p=>p.type===type)||{}).value || '';
  const y = get('year'), m = get('month'), d = get('day');
  if(!y || !m || !d) return null;
  return { y, m, d, iso:`${y}-${m}-${d}` };
}
function todayJakartaIso(){
  const parts = expiryDatePartsJakarta(Math.floor(Date.now()/1000));
  return parts ? parts.iso : '';
}
function addDaysIso(iso, days){
  if(!iso) return '';
  const m = String(iso).match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if(!m) return '';
  const dt = new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3]) + Number(days)));
  return dt.toISOString().slice(0,10);
}
function expiryRelativeLabel(ts){
  const parts = expiryDatePartsJakarta(ts);
  if(!parts) return '';
  return `${parts.d}-${parts.m}-${parts.y}`;
}
function renderExpiryBadge(ts){
  ts = Number(ts || 0);
  if(!ts) return '';
  return expiryRelativeLabel(ts);
}
function expiryTone(ts){
  ts = Number(ts || 0);
  if(!ts) return '';
  const now = Date.now()/1000;
  if(now >= ts) return 'red';
  const label = expiryRelativeLabel(ts);
  if(label === 'HARI INI' || label === 'BESOK') return 'red';
  const daysLeft = (ts - now) / 86400;
  return (daysLeft <= 3) ? 'red' : 'green';
}

let _expiryTargetStore = "";
function openExpiryModal(storeId){
  _expiryTargetStore = storeId;
  const curTs = Number(ADMIN_EXPIRY[storeId] || 0);
  const dayInp = document.getElementById("expiryDaysInput");
  const monthInp = document.getElementById("expiryMonthsInput");
  const dateInp = document.getElementById("expiryDateInput");
  const curLbl = document.getElementById("expiryCurrentLabel");
  const lbl = document.getElementById("expiryStoreLabel");
  if(lbl) lbl.textContent = storeId;
  if(dayInp) dayInp.value = "";
  if(monthInp) monthInp.value = "";
  if(dateInp){
    const d = curTs ? new Date(curTs * 1000) : new Date();
    const parts = new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Jakarta',year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(d).reduce((a,x)=>(a[x.type]=x.value,a),{});
    dateInp.value = `${parts.year}-${parts.month}-${parts.day}`;
    dateInp.min = new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Jakarta',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
  }
  if(curLbl) curLbl.textContent = curTs ? expiryRelativeLabel(curTs) : "Tidak ada expired";
  modalOpen();
  document.getElementById("expiryModal").style.display="flex";
  setTimeout(()=>{ try{ dayInp && dayInp.focus(); }catch(e){} }, 50);
}
function closeExpiryModal(){
  const m = document.getElementById("expiryModal");
  if(m) m.style.display="none";
  modalClose();
  restoreAdminAfterChildModal();
}
async function saveExpiryFromModal(clear=false){
  const storeId = _expiryTargetStore;
  const dayInp = document.getElementById("expiryDaysInput");
  const monthInp = document.getElementById("expiryMonthsInput");
  const dateInp = document.getElementById("expiryDateInput");
  const days = clear ? 0 : Math.max(0, parseInt(String(dayInp?.value || '0').replace(/[^0-9]/g,''), 10) || 0);
  const months = clear ? 0 : Math.max(0, parseInt(String(monthInp?.value || '0').replace(/[^0-9]/g,''), 10) || 0);
  const date = clear ? '' : String(dateInp?.value || '').trim();
  const useDuration = days > 0 || months > 0;
  if(!clear && !useDuration && !/^\d{4}-\d{2}-\d{2}$/.test(date)){ alert('Pilih tanggal melalui kalender atau isi jumlah hari/bulan.'); return; }

  try{
    showLoading("Simpan Expired…");
    const res = await fetch("?api=admin_set_expiry", {
      method:"POST",
      headers:{ "Content-Type":"application/json" },
      body: JSON.stringify(useDuration
        ? { storeId: storeId, days: days, months: months }
        : { storeId: storeId, date: date })
    });
    const j = await res.json().catch(()=>null);
    if(!j || !j.ok){
      alert((j && j.msg) ? j.msg : "Gagal set expired");
      return;
    }
    ADMIN_EXPIRY[storeId] = Number(j.expiryTs || 0);
    closeExpiryModal();
    adminRenderFiltered();
  }catch(e){
    alert("Gagal set expired.");
  }finally{
    hideLoading();
  }
}

async function adminSetExpiry(storeId){
  openExpiryModal(storeId);
}

/* PIN MODAL (ADMIN) */
let _pinTargetStore = "";
function openPinModal(storeId){
  _pinTargetStore = storeId;
  const lbl = document.getElementById("pinStoreLabel");
  const inp = document.getElementById("pinInputAdmin");
  if(lbl) lbl.textContent = storeId || "";
  const cur = String(ADMIN_PIN[storeId] || "0000").replace(/[^0-9]/g,'').padStart(4,'0').slice(0,4);
  if(inp) inp.value = cur;
  modalOpen();
  document.getElementById("pinModal").style.display = "flex";
  setTimeout(()=>{ try{ inp && inp.focus(); inp && inp.select && inp.select(); }catch(e){} }, 50);
}
function closePinModal(){
  const m = document.getElementById("pinModal");
  if(m) m.style.display = "none";
  modalClose();
  restoreAdminAfterChildModal();
}
async function savePinFromModal(){
  const storeId = _pinTargetStore;
  const inp = document.getElementById("pinInputAdmin");
  const pin = String(inp?.value || "").replace(/[^0-9]/g,'').slice(0,4);
  if(pin.length !== 4){ alert("PIN harus 4 angka"); inp && inp.focus(); return; }
  try{
    showLoading("Simpan PIN…");
    const res = await fetch("?api=admin_set_pin", {
      method:"POST",
      headers:{"Content-Type":"application/json"},
      body: JSON.stringify({ storeId, pin })
    });
    const j = await res.json().catch(()=>null);
    if(!j || !j.ok){
      alert((j && j.msg) ? j.msg : "Gagal simpan PIN");
      return;
    }
    ADMIN_PIN[storeId] = j.pin;
    closePinModal();
    adminRenderFiltered();
  }catch(e){
    alert("Gagal simpan PIN.");
  }finally{
    hideLoading();
  }
}


async function adminSetPremium(storeId, val){
  try{
    const res = await fetch("?api=admin_set_premium",{
      method:"POST",
      headers:{ "Content-Type":"application/json" },
      body: JSON.stringify({ storeId, premium: !!val })
    });
    const j = await res.json();
    if(!j.ok){ alert(j.msg || "Gagal set premium"); return; }
    ADMIN_PREMIUM[storeId] = !!val;
    adminRenderFiltered();
}catch(e){
    alert("Gagal set premium.");
  }
}

function adminTogglePremium(storeId){
  const cur = !!(ADMIN_PREMIUM[storeId]);
  adminSetPremium(storeId, !cur);
}

function formatAdminJoinDate(v){
  if(v == null || v === '') return '-';
  let d = null;
  if(typeof v === 'number' || /^\d+$/.test(String(v))){ const n=Number(v); d=new Date(n>9999999999?n:n*1000); }
  else d = new Date(String(v));
  if(!d || isNaN(d.getTime())) return String(v).slice(0,10) || '-';
  const dd=String(d.getDate()).padStart(2,'0'), mm=String(d.getMonth()+1).padStart(2,'0'), yy=d.getFullYear();
  return `${dd}-${mm}-${yy}`;
}
function syncAdminActionJoinDate(store){
  const pinEl = document.getElementById('adminActionPinBadge');
  if(!pinEl) return;
  let meta = document.getElementById('adminActionJoinDateLine');
  if(!meta){
    meta = document.createElement('div');
    meta.id = 'adminActionJoinDateLine';
    meta.className = 'admin-action-meta-line';
    pinEl.insertAdjacentElement('afterend', meta);
  }
  const dt = formatAdminJoinDate((ADMIN_CREATED || {})[store] || '');
  meta.innerHTML = 'Join Date: <b>'+dt+'</b>';
}
function getPresenceMeta(storeId){
  const row = ADMIN_PRESENCE && ADMIN_PRESENCE[storeId] ? ADMIN_PRESENCE[storeId] : {};
  const online = !!row.online;
  const dotColor = online ? '#22c55e' : '#ef4444';
  const lastSeenTs = Number(row.lastSeenTs || 0);
  const lastLoginTs = Number(row.lastLoginTs || 0);
  const activityTitle = String(row.activityTitle || '').trim();
  const activityKey = String(row.activityKey || '').trim();
  const activityUpdatedTs = Number(row.activityUpdatedTs || 0);
  return { online, dotColor, lastSeenTs, lastLoginTs, activityTitle, activityKey, activityUpdatedTs };
}
function adminFormatPoint(v){ v=Math.max(0, parseInt(v||0,10)||0); return v+" Point"; }
function adminRenderFiltered(){
  const list = document.getElementById("adminList");
  if(!list) return;
  const q = (document.getElementById("adminSearch").value || "").trim().toUpperCase();
  const onlineOnly = !!(document.getElementById("adminOnlineOnly") && document.getElementById("adminOnlineOnly").checked);
  const onlineCountEl = document.getElementById("adminOnlineCount");
  if(onlineCountEl){
    const onlineTotal = ADMIN_STORES.filter(st=>getPresenceMeta(st).online).length;
    onlineCountEl.textContent = `${onlineTotal} online`;
    onlineCountEl.classList.toggle('is-zero', onlineTotal === 0);
    onlineCountEl.classList.toggle('is-online', onlineTotal >= 1);
  }
  bindAdminOnlineBadge();
  const filtered = ADMIN_STORES.filter(s=> {
    const code = String(s || "").toUpperCase();
    const name = String(adminGetStoreName(s) || "").toUpperCase();
    const matchSearch = !q || code.includes(q) || name.includes(q);
    const presence = getPresenceMeta(s);
    const matchOnline = !onlineOnly || presence.online;
    return matchSearch && matchOnline;
  });

  list.innerHTML = filtered.map(s=>{
    const name = adminGetStoreName(s);
    const ts = Number(ADMIN_EXPIRY[s] || 0);
    const expiryText = renderExpiryBadge(ts);
    const expiryClass = expiryText ? `badge ${expiryTone(ts)==="green"?"expiry-badge expiry-green":"expiry-badge expiry-red"}` : "";
    const presence = getPresenceMeta(s);
    const presenceLabel = presence.online ? 'Online' : 'Offline';
    return `
      <div class="admin-item">
        <div class="admin-left">
          <div class="admin-code-row">
            <div class="admin-code">${s}</div>${ADMIN_ADMIN2 && ADMIN_ADMIN2[s] ? '<span class="admin-role-badge admin2">Admin2</span>' : ''}
            <div class="admin-code-meta">
              <span class="admin-presence-chip"><span class="admin-presence-dot" style="background:${presence.dotColor}"></span>${presenceLabel}</span>
            </div>
          </div>
          <div class="admin-name-row">
            <div class="admin-name">${name || "-"}</div>
            ${expiryText ? `<span class="${expiryClass}">${expiryText}</span>` : ""}
          </div>
          <div class="small admin-presence-text">
            <div>${presence.online ? 'Aktif sekarang' : 'Terakhir dilihat: ' + formatPresenceTime(presence.lastSeenTs || presence.lastLoginTs)}</div>
          </div>
        </div>
        <div class="admin-right">
          <div class="admin-buttons-bottom">
            <button class="btn-mini blue" onclick="openAdminActionModal('${s}')">Lihat</button>
            <button class="btn-mini tosca" onclick="adminImpersonate('${s}')">Masuk User</button>
          </div>
        </div>
      </div>
    `;
  }).join("") || `<div class="small" style="font-weight:900;color:#6b7280">Tidak ada hasil.</div>`;
  const actionModal = document.getElementById('adminActionModal');
  if(actionModal && actionModal.style.display === 'flex' && ADMIN_ACTION_STORE_ID){
    syncAdminActivityButton(ADMIN_ACTION_STORE_ID);
  }
}
function openAdminPointModal(storeId){
  const store = String(storeId || ADMIN_ACTION_STORE_ID || '').trim().toUpperCase();
  if(!store) return;
  ADMIN_ACTION_STORE_ID = store;
  const m=document.getElementById('adminPointModal');
  if(!m) return;
  const b=document.getElementById('adminPointStoreBadge'); if(b) b.textContent='Toko: '+store+' · '+(adminGetStoreName(store)||'-');
  const c=document.getElementById('adminPointCurrent'); if(c) c.textContent=adminFormatPoint(ADMIN_POINT[store]||0);
  const a=document.getElementById('adminPointAmount'); if(a) a.value='';
  m.style.display='flex';
}
function closeAdminPointModal(){ const m=document.getElementById('adminPointModal'); if(m) m.style.display='none'; }
async function adminPointChange(mode){
  const store=String(ADMIN_ACTION_STORE_ID||'').trim().toUpperCase();
  const amount=parseInt((document.getElementById('adminPointAmount')||{}).value||'0',10)||0;
  if(!store){ alert('Toko belum dipilih'); return; }
  if(amount<=0){ alert('Nominal wajib diisi'); return; }
  try{
    const res=await fetch('?api=admin_adjust_point',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({storeId:store,amount:amount,mode:mode})});
    const txt=await res.text();
    let j; try{ j=JSON.parse(txt); }catch(_){ alert('Server bukan JSON: '+txt.slice(0,160)); return; }
    if(!res.ok || !j||!j.ok){ alert((j&&j.msg)||'Gagal ubah point'); return; }
    ADMIN_POINT[store]=Number(j.points||0);
    const c=document.getElementById('adminPointCurrent'); if(c) c.textContent=adminFormatPoint(ADMIN_POINT[store]);
    const a=document.getElementById('adminPointAmount'); if(a) a.value='';
    adminRenderFiltered();
    const actionBadge=document.getElementById('adminActionPointBadge');
    if(actionBadge) actionBadge.textContent='Point: '+adminFormatPoint(ADMIN_POINT[store]||0);
    const modal=document.getElementById('adminPointModal');
    if(modal && modal.style.display!=='none'){
      const b=document.getElementById('adminPointStoreBadge'); if(b) b.textContent='Toko: '+store+' · '+(adminGetStoreName(store)||'-');
    }
  }catch(e){ alert('Koneksi gagal'); }
}
function adminInviteRupiah(n){return 'Rp '+String(parseInt(n||0,10)).replace(/\B(?=(\d{3})+(?!\d))/g,'.')}
function adminInviteWib(v){ const d=v?new Date(v):null; if(!d||isNaN(d.getTime()))return '-'; return d.toLocaleString('id-ID',{timeZone:'Asia/Jakarta',day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false}).replace(/\./g,':')+' WIB'; }
async function openAdminInviteHistoryModal(){
  const m=document.getElementById('adminInviteHistoryModal');
  const list=document.getElementById('adminInviteHistoryList');
  if(m){ m.style.display='flex'; }
  if(list) list.innerHTML='<div class="small" style="font-weight:900;color:#6b7280">Memuat riwayat...</div>';
  try{
    const r=await fetch('?api=admin_invite_history&_='+Date.now(),{cache:'no-store',credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}});
    const j=await r.json();
    if(!j||!j.ok){ if(list)list.innerHTML='<div class="small" style="color:#991b1b;font-weight:900;background:#fef2f2;border:1px solid #fecaca;border-radius:5px;padding:12px">'+((j&&j.msg)||'Gagal memuat riwayat')+'</div>'; return; }
    const rows=Array.isArray(j.history)?j.history:[];
    if(!rows.length){ if(list)list.innerHTML='<div class="small" style="font-weight:900;color:#6b7280;background:#f8fafc;border:1px solid #e5e7eb;border-radius:5px;padding:12px">Belum ada riwayat undangan.</div>'; return; }
    if(list) list.innerHTML=rows.map(x=>`<div class="admin-item" style="align-items:flex-start;border-radius:5px"><div class="admin-left" style="width:100%"><div class="admin-code-row" style="justify-content:space-between"><div><div class="small" style="font-weight:900;color:#64748b">Kode Toko</div><div class="admin-code">${esc(x.target||'-')}</div></div><div style="text-align:right"><div class="small" style="font-weight:900;color:#64748b">Nominal Bayar</div><div style="font-weight:1000;color:#166534">${esc(x.amountText||adminInviteRupiah(x.amount||0))}</div></div></div><div class="small" style="margin-top:8px">Tanggal/Jam: <b>${esc(x.createdAtWib||adminInviteWib(x.createdAt))}</b></div><div class="small">PIN: <b>${esc(x.pin||'-')}</b></div><button class="btn-mini danger" style="width:100%;margin-top:9px;border-radius:5px" onclick="adminInviteDeleteHistory('${esc(x.target||'')}')">Hapus</button></div></div>`).join('');
  }catch(e){ if(list)list.innerHTML='<div class="small" style="color:#991b1b;font-weight:900;background:#fef2f2;border:1px solid #fecaca;border-radius:5px;padding:12px">Riwayat belum bisa dimuat. Coba refresh halaman admin, lalu buka Store NEW lagi.</div>'; }
}
async function adminInviteDeleteHistory(target){ if(!target||!confirm('Hapus riwayat toko '+target+'?')) return; try{ const r=await fetch('?api=admin_invite_history_delete',{method:'POST',cache:'no-store',credentials:'same-origin',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({target})}); const j=await r.json(); if(!j||!j.ok){ alert((j&&j.msg)||'Gagal hapus riwayat'); return; } openAdminInviteHistoryModal(); }catch(e){ alert('Koneksi gagal'); } }
async function adminInviteClearHistory(){ if(!confirm('Hapus semua riwayat Store NEW?')) return; try{ let r=await fetch('?api=admin_invite_history_delete',{method:'POST',cache:'no-store',credentials:'same-origin',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest','Accept':'application/json'},body:JSON.stringify({all:true})}); let txt=await r.text(); let j=null; try{j=JSON.parse(txt);}catch(_){ r=await fetch('proxy.php?api=admin_invite_history_delete',{method:'POST',cache:'no-store',credentials:'same-origin',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest','Accept':'application/json'},body:JSON.stringify({all:true})}); j=await r.json(); } if(!j||!j.ok){ alert((j&&j.msg)||'Gagal hapus riwayat'); return; } openAdminInviteHistoryModal(); }catch(e){ alert(e.message||'Koneksi gagal'); } }
function closeAdminInviteHistoryModal(){ const m=document.getElementById('adminInviteHistoryModal'); if(m) m.style.display='none'; }

function adminExpiryHistoryEscape(value){
  return String(value==null?'':value).replace(/[&<>"']/g,function(ch){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]||ch;});
}
function adminExpiryHistoryDateTime(ts, iso){
  const n=Number(ts||0);
  const d=n>0?new Date(n*1000):(iso?new Date(iso):null);
  if(!d||Number.isNaN(d.getTime())) return '-';
  try{return new Intl.DateTimeFormat('id-ID',{timeZone:'Asia/Jakarta',day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false}).format(d).replace(/\./g,':')+' WIB';}catch(e){return '-';}
}
function adminExpiryHistoryExpiryDate(ts){
  const n=Number(ts||0);
  if(n<=0) return 'Belum ada';
  try{return new Intl.DateTimeFormat('id-ID',{timeZone:'Asia/Jakarta',day:'2-digit',month:'long',year:'numeric'}).format(new Date(n*1000));}catch(e){return '-';}
}
function adminExpiryHistoryDuration(row){
  const months=Math.max(0,Number(row.addedMonths||0));
  const days=Math.max(0,Number(row.addedDays||0));
  const parts=[];
  if(months>0) parts.push(months+' bulan');
  if(days>0) parts.push(days+' hari');
  if(!parts.length) parts.push(Math.max(0,Number(row.addedTotalDays||0))+' hari');
  return parts.join(' ');
}
function adminExpiryHistorySource(source){
  const key=String(source||'system').toLowerCase();
  if(key==='admin') return 'Admin, durasi';
  if(key==='admin_calendar') return 'Admin, kalender';
  if(key.indexOf('payment')>=0||key.indexOf('qris')>=0) return 'Pembayaran';
  if(key.indexOf('point')>=0) return 'Penukaran poin';
  if(key.indexOf('promo')>=0) return 'Promo';
  if(key==='admin2') return 'Admin2';
  if(key==='newuser_key') return 'Key New User';
  if(key==='account_default') return 'Aktivasi akun';
  return 'Sistem';
}
function adminExpiryHistoryRow(row){
  const store=String(row.storeId||'-').toUpperCase();
  const name=store!=='-'?(adminGetStoreName(store)||'-'):'-';
  const id=String(row.id||'').replace(/[^a-zA-Z0-9_-]/g,'');
  const totalDays=Math.max(0,Number(row.addedTotalDays||0));
  const actor=String(row.actor||'').toUpperCase();
  return `<article class="expiry-history-row">
    <div class="expiry-history-row-head">
      <div><div class="expiry-history-label">Kode Toko</div><div class="expiry-history-store">${adminExpiryHistoryEscape(store)}</div><div class="expiry-history-name">${adminExpiryHistoryEscape(name)}</div></div>
      <span class="expiry-history-added">+ ${adminExpiryHistoryEscape(adminExpiryHistoryDuration(row))}</span>
    </div>
    <div class="expiry-history-grid">
      <div><span>Expired sebelumnya</span><b>${adminExpiryHistoryEscape(adminExpiryHistoryExpiryDate(row.oldExpiryTs))}</b></div>
      <div><span>Expired terbaru</span><b>${adminExpiryHistoryEscape(adminExpiryHistoryExpiryDate(row.newExpiryTs))}</b></div>
      <div><span>Waktu penambahan</span><b>${adminExpiryHistoryEscape(adminExpiryHistoryDateTime(row.createdTs,row.createdAt))}</b></div>
      <div><span>Sumber</span><b>${adminExpiryHistoryEscape(adminExpiryHistorySource(row.source))}${actor?' oleh '+adminExpiryHistoryEscape(actor):''}</b></div>
    </div>
    ${Number(row.addedMonths||0)>0&&totalDays>0?`<div class="expiry-history-total">Perubahan kalender setara sekitar ${totalDays} hari.</div>`:''}
    ${id?`<button type="button" class="expiry-history-delete-one" onclick="adminExpiryHistoryDelete('${id}')">Hapus data ini</button>`:''}
  </article>`;
}
async function openAdminExpiryHistoryModal(){
  if(typeof closeAdminToolsSlide==='function') closeAdminToolsSlide();
  const modal=document.getElementById('adminExpiryHistoryModal');
  const list=document.getElementById('adminExpiryHistoryList');
  const count=document.getElementById('adminExpiryHistoryCount');
  if(modal){modal.style.display='flex';modal.classList.add('show');}
  if(count) count.textContent='Memuat...';
  if(list) list.innerHTML='<div class="expiry-history-state">Memuat laporan perpanjangan expired...</div>';
  try{
    const res=await fetch('?api=admin_expiry_history&_='+Date.now(),{cache:'no-store',credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}});
    const text=await res.text();
    let j=null; try{j=JSON.parse(text);}catch(_e){}
    if(!res.ok||!j||!j.ok) throw new Error((j&&(j.msg||j.error))||'Laporan gagal dimuat');
    const rows=Array.isArray(j.history)?j.history:[];
    if(count) count.textContent=rows.length+' riwayat';
    if(list) list.innerHTML=rows.length?rows.map(adminExpiryHistoryRow).join(''):'<div class="expiry-history-state">Belum ada penambahan expired yang tercatat.</div>';
  }catch(e){
    if(count) count.textContent='Gagal memuat';
    if(list) list.innerHTML='<div class="expiry-history-state is-error">'+adminExpiryHistoryEscape(e.message||'Koneksi gagal')+'</div>';
  }
}
function closeAdminExpiryHistoryModal(){
  const modal=document.getElementById('adminExpiryHistoryModal');
  if(modal){modal.style.display='none';modal.classList.remove('show');}
}
async function adminExpiryHistoryDelete(id){
  id=String(id||'').replace(/[^a-zA-Z0-9_-]/g,'');
  if(!id||!confirm('Hapus data riwayat expired ini?')) return;
  try{
    const res=await fetch('?api=admin_expiry_history_delete',{method:'POST',cache:'no-store',credentials:'same-origin',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest','Accept':'application/json'},body:JSON.stringify({id:id})});
    const j=await res.json().catch(()=>null);
    if(!res.ok||!j||!j.ok) throw new Error((j&&j.msg)||'Gagal menghapus riwayat');
    await openAdminExpiryHistoryModal();
  }catch(e){alert(e.message||'Koneksi gagal');}
}
async function adminExpiryHistoryClear(){
  if(!confirm('Hapus seluruh laporan riwayat penambahan expired? Data yang dihapus tidak dapat dikembalikan.')) return;
  try{
    const res=await fetch('?api=admin_expiry_history_delete',{method:'POST',cache:'no-store',credentials:'same-origin',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest','Accept':'application/json'},body:JSON.stringify({all:true})});
    const j=await res.json().catch(()=>null);
    if(!res.ok||!j||!j.ok) throw new Error((j&&j.msg)||'Gagal menghapus riwayat');
    await openAdminExpiryHistoryModal();
  }catch(e){alert(e.message||'Koneksi gagal');}
}

function showAdminAddResult(type,msg,title){
  const m=document.getElementById('adminAddResultModal');
  const icon=document.getElementById('adminAddResultIcon');
  const t=document.getElementById('adminAddResultTitle');
  const body=document.getElementById('adminAddResultMsg');
  const ok=type==='ok';
  if(icon){icon.className='admin-add-anim-icon '+(ok?'ok':'bad'); icon.textContent=ok?'✓':'×';}
  if(t)t.textContent=title || (ok?'Berhasil':'Toko sudah ada');
  if(body)body.textContent=msg || (ok?'Kode toko berhasil ditambahkan.':'Kode toko tersebut sudah terdaftar.');
  if(m){m.style.display='flex';m.classList.add('is-open');}
}
function closeAdminAddResultModal(){const m=document.getElementById('adminAddResultModal'); if(m){m.style.display='none';m.classList.remove('is-open');}}

async function adminAdd(){
  const inp = document.getElementById("adminAddInput");
  const code = ((inp && inp.value) || "").trim().toUpperCase().replace(/[^A-Z0-9]/g,'');
  if(!code){ showAdminAddResult('bad','Kode toko kosong.','Gagal'); return; }
  try{
    const res = await fetch("?api=admin_add", {
      method:"POST",
      credentials:"same-origin",
      headers:{ "Content-Type":"application/json", "X-Requested-With":"XMLHttpRequest" },
      body: JSON.stringify({ storeId: code })
    });
    const j = await res.json().catch(()=>null);
    if(!res.ok || !j || !j.ok){
      const msg=(j && (j.msg||j.message)) || "Kode toko sudah ada / gagal ditambahkan";
      const exists=/ada|exist|terdaftar/i.test(msg);
      showAdminAddResult('bad', msg, exists ? 'Toko sudah ada' : 'Gagal');
      return;
    }
    if(inp) inp.value = "";
    ADMIN_STORES = Array.isArray(j.stores) ? j.stores.slice() : ADMIN_STORES;
    (()=>{const c=document.getElementById("adminCount"); if(c)c.textContent = `${ADMIN_STORES.length} toko`; const o=document.getElementById("adminOnlineCount"); if(o)o.textContent = `${ADMIN_STORES.filter(st=>getPresenceMeta(st).online).length} online`;})();
    adminRenderFiltered();
    showAdminAddResult('ok', `Kode toko ${code} berhasil ditambahkan.`, 'Berhasil');
  }catch(e){ showAdminAddResult('bad','Koneksi gagal. Coba lagi.','Gagal'); }
}
async function adminDelete(code){
  restoreAdminAfterChildModal();
  if(!confirm(`Hapus kode toko ${code}?`)) return;
  try{
    const res = await fetch("?api=admin_delete", {
      method:"POST",
      headers:{ "Content-Type":"application/json" },
      body: JSON.stringify({ storeId: code })
    });
    const j = await res.json();
    if(!j.ok){ alert(j.msg || "Gagal hapus"); return; }
    ADMIN_STORES = Array.isArray(j.stores) ? j.stores.slice() : ADMIN_STORES;
    (()=>{const c=document.getElementById("adminCount"); if(c)c.textContent = `${ADMIN_STORES.length} toko`; const o=document.getElementById("adminOnlineCount"); if(o)o.textContent = `${ADMIN_STORES.filter(st=>getPresenceMeta(st).online).length} online`;})();
    adminRenderFiltered();
    alert(`Sukses menghapus kode toko ${code}.`);
  }catch(e){ alert("Gagal hapus"); }
}

JS
      );
      break;


    case 'sogrand-taskforce':
      js_out(<<<'JS'
(function(){
  const root = document.currentScript;
  const storeId = String((root && root.dataset && root.dataset.storeId) || '').trim();
  const els = {store: document.getElementById('sgStoreId'),date: document.getElementById('sgDate'),rackWrap: document.getElementById('sgRackWrap'),totalItems: document.getElementById('sgTotalItems'),totalAmount: document.getElementById('sgTotalAmount'),state: document.getElementById('sgState'),tableWrap: document.getElementById('sgTableWrap'),tbody: document.getElementById('sgTbody'),search: document.getElementById('sgSearch'),btnProcess: document.getElementById('sgBtnProcess'),btnReset: document.getElementById('sgBtnReset'),btnDownload: document.getElementById('sgBtnDownload'),btnClearRack: document.getElementById('sgBtnClearRack'),btnPreviewRefresh: document.getElementById('sgBtnPreviewRefresh'),btnShowOH: document.getElementById('sgBtnShowOH'),countdown: document.getElementById('sgKeyCountdown'),countdownText: document.getElementById('sgKeyCountdownText'),exportModal: document.getElementById('sgExportModal'),exportExcel: document.getElementById('sgExportExcel'),exportPdf: document.getElementById('sgExportPdf'),exportClose: document.getElementById('sgExportClose'),selectAll: document.getElementById('sgSelectAllRack')};
  try{ localStorage.removeItem('sg_tf_show_oh_' + (storeId || 'UNKNOWN')); }catch(e){}
  const state = { rows: [], filtered: [], racks: [], selectedRacks: new Set(), showOH: false };
  const STORE_KEY = storeId || 'UNKNOWN';
  if(els.store){ els.store.value = storeId; els.store.readOnly = true; els.store.setAttribute('readonly','readonly'); els.store.addEventListener('input',()=>{ els.store.value = storeId; }); els.store.addEventListener('change',()=>{ els.store.value = storeId; }); }
  function todayISO(){ const d = new Date(); const tz = d.getTimezoneOffset() * 60000; return new Date(d.getTime() - tz).toISOString().slice(0,10); }
  function storageKey(iso){ return 'sg_tf_selected_racks_' + STORE_KEY + '_' + String(iso || todayISO()); }
  function saveSelectedRacks(){ try{ const iso = ((els.date && els.date.value) || todayISO()); localStorage.setItem(storageKey(iso), JSON.stringify(Array.from(state.selectedRacks))); }catch(e){} }
  function restoreSelectedRacks(iso, availableRacks){ const available = new Set((availableRacks || []).map(r => String(r || ''))); try{ const raw = localStorage.getItem(storageKey(iso)); if(raw){ const arr = JSON.parse(raw); if(Array.isArray(arr)){ const restored = arr.map(r => String(r || '')).filter(r => available.has(r)); return new Set(restored); } } }catch(e){} return new Set(availableRacks || []); }
  function formatDMY(iso){ const d = new Date(iso + 'T00:00:00'); return String(d.getDate()).padStart(2,'0') + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + d.getFullYear(); }
  function formatMoney(n){ const num = Number(n || 0); return new Intl.NumberFormat('id-ID', {minimumFractionDigits:2, maximumFractionDigits:2}).format(num); }
  function escapeHtml(s){ return String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }
  let sgCountdownTimer = null;
  function fmtRemaining(sec){ sec=Math.max(0, Math.floor(Number(sec||0))); const d=Math.floor(sec/86400); sec%=86400; const h=Math.floor(sec/3600); sec%=3600; const m=Math.floor(sec/60); const x=sec%60; return (d>0?d+' hari ':'')+String(h).padStart(2,'0')+':'+String(m).padStart(2,'0')+':'+String(x).padStart(2,'0'); }
  async function initKeyCountdown(){
    if(!els.countdown || !els.countdownText) return;
    try{
      const res = await fetch('?api=sogrand_user_status', {cache:'no-store', credentials:'same-origin'});
      const j = await res.json().catch(()=>null);
      if(!j || !j.ok || !j.isSogrand || !j.expiresTs){ els.countdown.style.display='none'; return; }
      let remain = Number(j.remainingSec || 0);
      els.countdown.style.display = 'block';
      const tick = () => { els.countdownText.textContent = fmtRemaining(remain); if(remain<=0){ els.countdownText.textContent='Expired'; clearInterval(sgCountdownTimer); } remain--; };
      tick(); sgCountdownTimer = setInterval(tick, 1000);
    }catch(e){ if(els.countdown) els.countdown.style.display='none'; }
  }
  function setStateMessage(type, text){ if(!els.state) return; els.state.innerHTML = text ? `<div class="${type==='error' ? 'sg-error' : 'sg-empty'}">${escapeHtml(text)}</div>` : ''; }
  function syncAmountColor(totalAmount){ if(!els.totalAmount) return; els.totalAmount.classList.remove('money-pos','money-neg'); if(totalAmount > 0) els.totalAmount.classList.add('money-pos'); else if(totalAmount < 0) els.totalAmount.classList.add('money-neg'); }
  function syncSelectAll(){ if(!els.selectAll) return; const total=state.racks.length, selected=state.selectedRacks.size; els.selectAll.checked=total>0&&selected===total; els.selectAll.indeterminate=selected>0&&selected<total; }
  function renderRacks(){ if(!els.rackWrap) return; if(!state.racks.length){ els.rackWrap.innerHTML = '<div class="sg-empty" style="grid-column:1/-1">Tidak ada rack yang sedang SO.</div>'; syncSelectAll(); return; } els.rackWrap.innerHTML = state.racks.map(rack => { const checked = state.selectedRacks.has(rack) ? 'checked' : ''; return `<label class="sg-rack"><input type="checkbox" data-rack="${escapeHtml(rack)}" ${checked}><span>${escapeHtml(rack)}</span></label>`; }).join(''); els.rackWrap.querySelectorAll('input[data-rack]').forEach(el => { el.addEventListener('change', () => { const rack = String(el.getAttribute('data-rack') || ''); if(el.checked) state.selectedRacks.add(rack); else state.selectedRacks.delete(rack); saveSelectedRacks(); syncSelectAll(); applyFilters(); }); }); syncSelectAll(); }
  function syncOHColumns(){ document.querySelectorAll('.sg-oh-col,.sg-col-oh,.sg-col-selisih').forEach(el => { el.hidden = !state.showOH; }); if(els.btnShowOH){ els.btnShowOH.textContent = state.showOH ? 'Sembunyikan OH' : 'Munculkan OH'; } try{ localStorage.setItem('sg_tf_show_oh_' + (storeId || 'UNKNOWN'), state.showOH ? '1' : '0'); }catch(e){} }
  function setBusy(btn, busy, text){ if(!btn) return; if(busy){ btn.dataset.oldText = btn.textContent; btn.disabled = true; btn.innerHTML = '<span class="sg-loading-inline">'+escapeHtml(text||'Memproses...')+'</span>'; } else { btn.disabled = false; if(btn.dataset.oldText) btn.textContent = btn.dataset.oldText; } }
  function openExportModal(){ if(!els.exportModal) return; els.exportModal.hidden=false; els.exportModal.setAttribute('aria-hidden','false'); }
  function closeExportModal(){ if(!els.exportModal) return; els.exportModal.hidden=true; els.exportModal.setAttribute('aria-hidden','true'); }
  function renderTable(rows){ if(!els.tableWrap || !els.tbody) return; syncOHColumns(); if(!rows.length){ els.tableWrap.style.display = 'none'; setStateMessage('empty', state.rows.length ? 'Tidak ada data yang cocok dengan filter.' : 'Tidak ada data SO untuk tanggal ini.'); return; } setStateMessage('', ''); els.tableWrap.style.display = 'block'; const hideOH = state.showOH ? '' : ' hidden'; els.tbody.innerHTML = rows.map(row => { const moneyClass = Number(row.selisihVal || 0) >= 0 ? 'money-pos' : 'money-neg'; const qtyClass = Number(row.selisihQtyVal || 0) >= 0 ? 'money-pos' : 'money-neg'; return `<tr><td class="sg-cell-strong sg-td-plu">${escapeHtml(row.plu)}</td><td class="sg-cell-strong sg-td-name">${escapeHtml(row.nama)}</td><td class="sg-td-rack"><span class="rack-pill">${escapeHtml(row.rack || '-')}</span></td><td class="num sg-cell-strong sg-td-fisik">${escapeHtml(String(row.stock))}</td><td class="num sg-cell-strong sg-oh-col sg-td-oh"${hideOH}>${escapeHtml(String(row.oh || '-'))}</td><td class="num sg-oh-col sg-td-selisih ${qtyClass}"${hideOH}>${escapeHtml(String(row.selisihQty || '-'))}</td><td class="num sg-td-rupiah ${moneyClass}">${escapeHtml(row.selisih)}</td></tr>`; }).join(''); }
  function applyFilters(){ const keyword = ((els.search && els.search.value) || '').trim().toLowerCase(); let rows = state.rows.slice(); if(!state.racks.length){ rows = []; } else if(state.selectedRacks.size === 0){ rows = []; } else { rows = rows.filter(r => state.selectedRacks.has(String(r.rack || ''))); } if(keyword){ rows = rows.filter(r => String(r.plu || '').toLowerCase().includes(keyword) || String(r.nama || '').toLowerCase().includes(keyword) || String(r.rack || '').toLowerCase().includes(keyword)); } state.filtered = rows; const totalAmount = rows.reduce((sum, r) => sum + Number(r.selisihVal || 0), 0); if(els.totalItems) els.totalItems.textContent = String(rows.length); if(els.totalAmount) els.totalAmount.textContent = (totalAmount < 0 ? '-' : '') + 'Rp' + formatMoney(Math.abs(totalAmount)); syncAmountColor(totalAmount); renderTable(rows); }
  async function loadData(){ const iso = ((els.date && els.date.value) || todayISO()); if(els.date) els.date.value = iso; const oldRows = state.rows.slice(); const oldRacks = state.racks.slice(); const oldSelected = new Set(state.selectedRacks); setBusy(els.btnProcess, true, state.showOH ? 'Memuat OH...' : 'Memuat...'); if(els.btnDownload) els.btnDownload.disabled = true; if(!oldRows.length){ if(els.tableWrap) els.tableWrap.style.display = 'none'; setStateMessage('empty', state.showOH ? 'Mengambil OH, mohon tunggu...' : 'Memuat data...'); } else { setStateMessage('empty', state.showOH ? 'Mengambil OH di belakang layar, data lama tetap ditampilkan...' : 'Memuat ulang data...'); } try{ const qs = new URLSearchParams(); qs.set('api','sogrand_so_data'); qs.set('dateSo', formatDMY(iso)); qs.set('includeOH', state.showOH ? '1' : '0'); const racks = Array.from(state.selectedRacks).map(v=>String(v||'').trim()).filter(Boolean); if(state.showOH && racks.length && state.racks.length){ qs.set('racks', racks.join(',')); } const res = await fetch(`?${qs.toString()}`, {cache:'default', credentials:'same-origin'}); const data = await res.json().catch(() => null); if(!res.ok || !data || !data.status) throw new Error((data && (data.message || data.error)) || 'Gagal memuat data'); state.rows = Array.isArray(data.rows) ? data.rows : []; state.racks = Array.isArray(data.racks) ? data.racks : []; state.selectedRacks = (oldSelected.size && state.showOH) ? new Set(state.racks.filter(r=>oldSelected.has(r))) : restoreSelectedRacks(iso, state.racks); renderRacks(); applyFilters(); if(!state.rows.length){ if(els.totalItems) els.totalItems.textContent = '0'; if(els.totalAmount) els.totalAmount.textContent = 'Rp0,00'; syncAmountColor(0); setStateMessage('empty', data.message || 'Tidak ada data SO untuk tanggal ini.'); } if(els.btnDownload) els.btnDownload.disabled = !state.rows.length; }catch(err){ if(oldRows.length){ state.rows = oldRows; state.racks = oldRacks; state.selectedRacks = oldSelected; renderRacks(); applyFilters(); setStateMessage('error', (err && err.message ? err.message : 'Terjadi kesalahan.') + ' Data sebelumnya tetap ditampilkan.'); } else { state.rows = []; state.filtered = []; state.racks = []; state.selectedRacks = new Set(); renderRacks(); if(els.totalItems) els.totalItems.textContent = '0'; if(els.totalAmount) els.totalAmount.textContent = 'Rp0,00'; syncAmountColor(0); setStateMessage('error', err && err.message ? err.message : 'Terjadi kesalahan.'); } }finally{ setBusy(els.btnProcess, false); } }
  function resetFilters(){ if(els.search) els.search.value = ''; state.selectedRacks = new Set(state.racks); saveSelectedRacks(); renderRacks(); applyFilters(); }
  function downloadCetak(fmt){ const iso = ((els.date && els.date.value) || todayISO()); const racks = Array.from(state.selectedRacks).map(v => String(v || '').trim()).filter(Boolean); const qs = new URLSearchParams(); qs.set('api', fmt==='pdf' ? 'sogrand_so_pdf' : 'sogrand_so_xlsx'); qs.set('dateSo', formatDMY(iso)); if(racks.length){ qs.set('racks', racks.join(',')); } if(state.showOH){ qs.set('includeOH','1'); } closeExportModal(); window.location.href = `?${qs.toString()}`; }
  if(els.date) els.date.value = todayISO();
  syncAmountColor(0);
  syncOHColumns();
  if(els.btnProcess) els.btnProcess.addEventListener('click', loadData);
  if(els.btnReset) els.btnReset.addEventListener('click', resetFilters);
  if(els.btnDownload) els.btnDownload.addEventListener('click', openExportModal); if(els.exportExcel) els.exportExcel.addEventListener('click', () => downloadCetak('xlsx')); if(els.exportPdf) els.exportPdf.addEventListener('click', () => downloadCetak('pdf')); if(els.exportClose) els.exportClose.addEventListener('click', closeExportModal); if(els.exportModal) els.exportModal.addEventListener('click', e => { if(e.target===els.exportModal) closeExportModal(); });
  if(els.btnShowOH) els.btnShowOH.addEventListener('click', async () => { state.showOH = !state.showOH; syncOHColumns(); if(state.showOH){ setBusy(els.btnShowOH, true, 'Memunculkan OH...'); try{ await loadData(); } finally { setBusy(els.btnShowOH, false); syncOHColumns(); } } else { state.rows = state.rows.map(r => ({...r, oh:'', selisihQty:'', selisihQtyVal:0})); applyFilters(); } });
  if(els.selectAll) els.selectAll.addEventListener('change', () => { state.selectedRacks = els.selectAll.checked ? new Set(state.racks) : new Set(); saveSelectedRacks(); renderRacks(); applyFilters(); });
  if(els.btnClearRack) els.btnClearRack.addEventListener('click', () => { state.selectedRacks = new Set(); saveSelectedRacks(); renderRacks(); applyFilters(); });
  if(els.btnPreviewRefresh){ els.btnPreviewRefresh.addEventListener('click', () => loadData()); }
  const backBtn = document.getElementById('sgBtnBack');
  if(backBtn){ backBtn.addEventListener('click', async () => { let target = location.pathname; try{ const r = await fetch('?api=sogrand_user_status', {cache:'no-store', credentials:'same-origin'}); const j = await r.json().catch(()=>null); if(j && j.isSogrand){ target = location.pathname + '?logout=1'; } }catch(e){} location.href = target; }); }
  if(els.search) els.search.addEventListener('input', applyFilters);
  initKeyCountdown();
  loadData();
})();
JS
      );
      break;

  }
  json_out(['ok'=>false,'msg'=>'Unknown js asset'], 404);
}

ensure_global_expiry_cleanup();

if(isset($_GET['css'])){
  serve_css_asset($_GET['css']);
}
if(isset($_GET['js'])){
  serve_js_asset($_GET['js']);
}

function sogrand_http_get($url, &$httpCode = 0, &$curlError = ''){
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>35,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Accept: */*','User-Agent: Mozilla/5.0 (Linux; Android 15) AppleWebKit/537.36 Chrome/120 Safari/537.36']]);
  $body = curl_exec($ch);
  $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $curlError = (string)curl_error($ch);
  curl_close($ch);
  return $body === false ? '' : (string)$body;
}
function sogrand_to_float_id($v){ if($v===null) return 0.0; $s=trim((string)$v); if($s==='') return 0.0; $s=str_ireplace(['rp',' '],'',$s); if(preg_match('/^\-?\d{1,3}(\.\d{3})*(,\d+)?$/',$s)){ $s=str_replace('.','',$s); $s=str_replace(',','.',$s); } else { $s=preg_replace('/[^0-9\.\-]/','',$s); } return (float)$s; }
function sogrand_format_money($n){ $n=(float)$n; $sign=$n<0?'-':''; $n=abs($n); return $sign . number_format($n,2,',','.'); }
function sogrand_escape_xml($s){ return htmlspecialchars((string)$s, ENT_XML1 | ENT_COMPAT, 'UTF-8'); }
function sogrand_qty_to_float($v){
  if($v===null) return 0.0;
  $s=trim(strip_tags((string)$v));
  if($s==='') return 0.0;
  $s=str_replace([chr(194).chr(160), '&nbsp;', ' '], '', $s);
  if(preg_match('/^-?\d{1,3}([\.,]\d{3})+$/', $s)) $s=preg_replace('/[\.,]/','',$s);
  else { $s=str_replace(',', '.', $s); $s=preg_replace('/[^0-9\.\-]/','',$s); }
  return (float)$s;
}
function sogrand_format_qty($n){
  $n=(float)$n;
  if(abs($n - round($n)) < 0.0000001) return (string)((int)round($n));
  return rtrim(rtrim(number_format($n, 2, ',', '.'), '0'), ',');
}
function sogrand_date_to_iso($dateSo){
  $dateSo = preg_replace('/[^0-9\-]/','', (string)$dateSo);
  if(preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateSo)) return $dateSo;
  if(preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $dateSo, $m)) return $m[3].'-'.$m[2].'-'.$m[1];
  return date('Y-m-d');
}
function sogrand_fetch_oh_saldo($storeId, $dateSo, $plu){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  $plu = preg_replace('/[^0-9]/','', (string)$plu);
  if($storeId==='' || $plu==='') return '';
  $iso = sogrand_date_to_iso($dateSo);
  $url = ALFA_PRD_API_BASE . '/rpt/laporan/laporan_posisi_mutasi_per_plu?' . http_build_query([
    'storeId'=>$storeId,
    'periode1'=>$iso,
    'periode2'=>$iso,
    'plu'=>$plu
  ]);
  $httpCode=0; $curlError=''; $raw=sogrand_http_get($url,$httpCode,$curlError);
  if($raw==='' || $httpCode>=400) return '';
  $rows=[];
  if(function_exists('oh_rt_decode_json_any')){
    $json=oh_rt_decode_json_any($raw);
    if(is_array($json)) oh_rt_collect_rows($json, $rows);
    if(!$rows) $rows=oh_rt_collect_html_rows($raw, $plu);
    $clean=oh_rt_clean_rows($rows, $plu);
    if($clean && isset($clean[0]['saldo'])) return (string)$clean[0]['saldo'];
  }
  if(preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $raw, $trs)){
    foreach($trs[1] as $tr){
      if(strpos(strip_tags($tr), $plu) === false) continue;
      preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/is', $tr, $m);
      $cells = array_map(function($x){ return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($x), ENT_QUOTES, 'UTF-8'))); }, $m[1] ?? []);
      if(function_exists('oh_rt_saldo_from_posisi_mutasi_cells')){
        $saldo = oh_rt_saldo_from_posisi_mutasi_cells($cells, $plu);
        if($saldo !== '') return $saldo;
      }
    }
  }
  // FORCE OH fallback: kalau endpoint laporan posisi mutasi tidak mengembalikan saldo,
  // pakai mesin Cek OH ( Sedang SO ) yang sudah lebih lengkap agar kolom OH cetak selisih tetap muncul.
  if(function_exists('oh_rt_fetch_rows')){
    $err = null; $usedDate = null;
    $rtRows = oh_rt_fetch_rows($storeId, $plu, $err, $usedDate);
    if(is_array($rtRows)){
      foreach($rtRows as $rr){
        if(!is_array($rr)) continue;
        $rplu = preg_replace('/[^0-9]/','', (string)($rr['plu'] ?? $rr['PLU'] ?? ''));
        if($rplu !== $plu) continue;
        $saldo = $rr['stok'] ?? $rr['saldo'] ?? $rr['on_hand'] ?? $rr['oh'] ?? '';
        if($saldo !== '') return (string)$saldo;
      }
    }
  }
  return '';
}
function sogrand_normalize_row($r){ $plu=$r['plu'] ?? $r['PLU'] ?? $r['kode'] ?? ''; $nama=$r['namaBarang'] ?? $r['nama_barang'] ?? $r['Nama Barang'] ?? $r['nama'] ?? $r['descp'] ?? ''; $rack=$r['rack'] ?? $r['Rack'] ?? $r['rak'] ?? $r['RAK'] ?? ''; $stock=$r['stockFisik'] ?? $r['stock_fisik'] ?? $r['Stock Fisik'] ?? $r['stock'] ?? $r['stock_fisik_so'] ?? ''; $sel=$r['selisihRupiah'] ?? $r['selisih_rupiah'] ?? $r['Selisih Rupiah'] ?? $r['selisih'] ?? 0; $selVal=sogrand_to_float_id($sel); return ['plu'=>(string)$plu,'nama'=>trim((string)$nama),'rack'=>strtoupper(trim((string)$rack)),'stock'=>(string)$stock,'oh'=>'','selisihQty'=>'','selisihQtyVal'=>0,'selisihVal'=>(float)$selVal,'selisih'=>sogrand_format_money($selVal)]; }
function sogrand_fetch_dataset($storeId, $dateSo, $includeOH=false, $rackFilter=null){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  $dateSo = preg_replace('/[^0-9\-]/','', (string)$dateSo);
  if($storeId==='' || $dateSo==='') return ['status'=>false,'message'=>'Parameter storeId/dateSo wajib diisi'];
  $url = ALFA_PRD_API_BASE . '/rpt/laporan_so/prosentase_so?storeId=' . urlencode($storeId) . '&dateSo=' . urlencode($dateSo);
  $httpCode=0; $curlError=''; $raw=sogrand_http_get($url,$httpCode,$curlError);
  if($raw==='') return ['status'=>false,'message'=>($curlError!==''?('Gagal request: '.$curlError):('Gagal ambil data. HTTP: '.$httpCode))];
  if($httpCode!==200) return ['status'=>false,'message'=>'Gagal ambil data. HTTP: '.$httpCode];
  $rows=[]; $trim=ltrim($raw); $isJson=($trim!=='' && ($trim[0]==='{' || $trim[0]==='['));
  if($isJson){
    $j=json_decode($raw,true); if(!is_array($j)) return ['status'=>false,'message'=>'Response JSON tidak valid'];
    $data=null; if(isset($j['data'])&&is_array($j['data'])) $data=$j['data']; else if(isset($j['rows'])&&is_array($j['rows'])) $data=$j['rows']; else if(array_keys($j)===range(0,count($j)-1)) $data=$j;
    if(!is_array($data)) return ['status'=>false,'message'=>'Struktur data JSON tidak ditemukan'];
    foreach($data as $r){ $row=sogrand_normalize_row(is_array($r)?$r:[]); if(abs((float)$row['selisihVal'])<0.0000001) continue; if($row['rack']==='') continue; $rows[]=$row; }
  } else {
    libxml_use_internal_errors(true); $dom=new DOMDocument(); $html=$raw; if(stripos($html,'<meta charset=')===false){ $html='<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'.$html; } $dom->loadHTML($html); $xp=new DOMXPath($dom); $trs=$xp->query('//tr'); if(!$trs||$trs->length===0) return ['status'=>false,'message'=>'Tabel HTML tidak terbaca']; $headerFound=false;
    foreach($trs as $tr){ $ths=$xp->query('th',$tr); $tds=$xp->query('td',$tr); if($ths&&$ths->length>=1){ $txt=trim(preg_replace('/\s+/',' ',$tr->textContent)); if(stripos($txt,'PLU')!==false && stripos($txt,'Selisih')!==false) $headerFound=true; continue; } if(!$headerFound||!$tds||$tds->length<8) continue; $cols=[]; foreach($tds as $td) $cols[]=trim(preg_replace('/\s+/',' ',$td->textContent)); if(count($cols)<8) continue; $row=sogrand_normalize_row(['plu'=>$cols[0]??'','nama_barang'=>$cols[1]??'','rack'=>$cols[5]??'','stock_fisik'=>$cols[6]??'','selisih_rupiah'=>$cols[7]??0]); if(abs((float)$row['selisihVal'])<0.0000001) continue; if($row['rack']==='') continue; $rows[]=$row; }
  }

  if(is_array($rackFilter) && count($rackFilter)){
    $allowed=[];
    foreach($rackFilter as $rk){ $rk=strtoupper(trim((string)$rk)); if($rk!=='') $allowed[$rk]=true; }
    if($allowed){
      $rows = array_values(array_filter($rows, function($row) use ($allowed){ return isset($allowed[strtoupper(trim((string)($row['rack'] ?? '')))]); }));
    }
  }

  if($includeOH){
    $ohCache=[];
    foreach($rows as $i=>$row){
      $pluKey = preg_replace('/[^0-9]/','', (string)($row['plu'] ?? ''));
      if($pluKey === '') continue;
      if(!array_key_exists($pluKey, $ohCache)) $ohCache[$pluKey] = sogrand_fetch_oh_saldo($storeId, $dateSo, $pluKey);
      $oh = (string)$ohCache[$pluKey];
      $isSedangSo = (stripos($oh, 'sedang') !== false && stripos($oh, 'so') !== false);
      if($isSedangSo){ $rows[$i]['oh'] = '-'; $rows[$i]['selisihQtyVal'] = 0; $rows[$i]['selisihQty'] = '-'; continue; }
      $stockVal = sogrand_qty_to_float($row['stock'] ?? 0);
      $ohVal = ($oh === '') ? 0.0 : sogrand_qty_to_float($oh);
      $diff = $stockVal - $ohVal;
      // Paksa kolom OH tetap terisi. Jika API OH benar-benar tidak memberi angka, tampilkan 0
      // supaya kolom tidak hilang/kosong di halaman dan XLSX cetak selisih.
      if($oh === '') $oh = '-';
      $ohVal = ($oh === '-') ? 0.0 : sogrand_qty_to_float($oh);
      $diff = ($oh === '-') ? 0.0 : ($stockVal - $ohVal);
      $rows[$i]['oh'] = $oh;
      $rows[$i]['selisihQtyVal'] = $diff;
      $rows[$i]['selisihQty'] = ($oh === '-') ? '-' : sogrand_format_qty($diff);
    }
  }

  usort($rows, function($a,$b){ $aa=abs((float)$a['selisihVal']); $bb=abs((float)$b['selisihVal']); if($aa===$bb) return ((float)$b['selisihVal']) <=> ((float)$a['selisihVal']); return $bb <=> $aa; });
  $racks=[]; $seen=[]; $total=0.0; foreach($rows as $row){ $rk=(string)($row['rack']??''); if($rk!==''&&!isset($seen[$rk])){ $seen[$rk]=true; $racks[]=$rk; } $total+=(float)($row['selisihVal']??0); } natcasesort($racks); $racks=array_values($racks);
  return ['status'=>true,'storeId'=>$storeId,'dateSo'=>$dateSo,'rows'=>$rows,'racks'=>$racks,'totalItemSelisih'=>count($rows),'totalNilaiSelisih'=>$total,'message'=>count($rows)?'':'Tidak ada data SO untuk tanggal ini.'];
}

function sogrand_pdf_cell($text, $width){
  $text = trim(preg_replace('/\s+/u', ' ', (string)$text));
  if(function_exists('mb_strlen')){
    if(mb_strlen($text, 'UTF-8') > $width) $text = mb_substr($text, 0, max(0,$width-1), 'UTF-8') . '…';
    while(mb_strlen($text, 'UTF-8') < $width) $text .= ' ';
    return $text;
  }
  if(strlen($text) > $width) $text = substr($text, 0, max(0,$width-1)) . '~';
  return str_pad($text, $width, ' ');
}
function sogrand_pdf_table_line($cols, $widths){
  $out=[];
  foreach($widths as $i=>$w){ $out[] = sogrand_pdf_cell($cols[$i] ?? '', $w); }
  return '| ' . implode(' | ', $out) . ' |';
}
function sogrand_make_pdf_binary($rows, $includeOH=true, $title='Cetak Selisih'){
  $widths = $includeOH ? [8,34,8,8,8,9,15] : [8,42,8,10,17];
  $headers = $includeOH ? ['PLU','Nama Barang','Rack','Fisik','OH','Selisih','Selisih Rupiah'] : ['PLU','Nama Barang','Rack','Fisik','Selisih Rupiah'];
  $tableWidth = array_sum($widths) + (count($widths) * 3) + 1;
  $sep = '+' . str_repeat('-', max(10, $tableWidth-2)) . '+';
  $all=[];
  $all[] = strtoupper($title);
  $all[] = 'Dibuat: ' . date('d/m/Y H:i') . ' WIB';
  $all[] = $sep;
  $all[] = sogrand_pdf_table_line($headers, $widths);
  $all[] = $sep;
  $total = 0.0;
  foreach((array)$rows as $row){
    $total += (float)($row['selisihVal'] ?? 0);
    $cols = $includeOH
      ? [(string)($row['plu']??''),(string)($row['nama']??''),(string)($row['rack']??''),(string)($row['stock']??''),(string)($row['oh']??'-'),(string)($row['selisihQty']??'-'),(string)($row['selisih']??'')]
      : [(string)($row['plu']??''),(string)($row['nama']??''),(string)($row['rack']??''),(string)($row['stock']??''),(string)($row['selisih']??'')];
    $all[] = sogrand_pdf_table_line($cols, $widths);
  }
  if(!count($rows)) $all[] = sogrand_pdf_table_line(['Tidak ada data.'], [$tableWidth-4]);
  $all[] = $sep;
  $all[] = 'Total item: ' . count((array)$rows) . '   Total selisih rupiah: ' . sogrand_format_money($total);
  $perPage=42; $chunks=array_chunk($all, $perPage); if(!$chunks) $chunks=[['Tidak ada data.']];
  $objects=[]; $pageIds=[]; $objNum=3;
  foreach($chunks as $pi=>$lines){
    $pageId=$objNum++; $contentId=$objNum++; $pageIds[]=$pageId;
    $content="BT /F1 7.2 Tf 24 560 Td 9.8 TL "; $first=true;
    foreach($lines as $line){
      $line = str_replace(["\\","(",")", "\r"], ["\\\\","\\(","\\)", ""], (string)$line);
      if(!$first) $content .= "T* ";
      $content .= "(".$line.") Tj "; $first=false;
    }
    $content.="ET";
    $objects[$pageId]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 '.($objNum).' 0 R >> >> /Contents '.$contentId.' 0 R >>';
    $objects[$contentId]='<< /Length '.strlen($content).' >>' . "\nstream\n".$content."\nendstream";
  }
  $fontId=$objNum++;
  foreach($pageIds as $pageId){ $objects[$pageId]=preg_replace('/\/F1 \d+ 0 R/', '/F1 '.$fontId.' 0 R', $objects[$pageId]); }
  $objects[1]='<< /Type /Catalog /Pages 2 0 R >>';
  $objects[2]='<< /Type /Pages /Kids ['.implode(' ', array_map(fn($id)=>$id.' 0 R', $pageIds)).'] /Count '.count($pageIds).' >>';
  $objects[$fontId]='<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>';
  ksort($objects);
  $pdf="%PDF-1.4\n"; $offsets=[0]; $max=max(array_keys($objects));
  for($i=1;$i<=$max;$i++){ if(!isset($objects[$i])) continue; $offsets[$i]=strlen($pdf); $pdf.=$i." 0 obj\n".$objects[$i]."\nendobj\n"; }
  $xref=strlen($pdf); $pdf.="xref\n0 ".($max+1)."\n0000000000 65535 f \n";
  for($i=1;$i<=$max;$i++){ $pdf.=isset($offsets[$i]) ? sprintf("%010d 00000 n \n", $offsets[$i]) : "0000000000 65535 f \n"; }
  $pdf.="trailer << /Size ".($max+1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";
  return $pdf;
}

function sogrand_make_xlsx_binary($rows, $includeOH=true){
  if(!class_exists('ZipArchive')) return false; $tmp=tempnam(sys_get_temp_dir(),'sgx_'); if($tmp===false) return false; @unlink($tmp); $xlsx=$tmp.'.xlsx'; $zip=new ZipArchive(); if($zip->open($xlsx, ZipArchive::CREATE)!==true) return false;
  $sheetRows = $includeOH ? [['PLU','Nama Barang','Rack','Stock Fisik','OH','Selisih','Selisih Rupiah']] : [['PLU','Nama Barang','Rack','Stock Fisik','Selisih Rupiah']]; foreach((array)$rows as $row){ if($includeOH){ $sheetRows[]=[(string)($row['plu']??''),(string)($row['nama']??''),(string)($row['rack']??''),(string)($row['stock']??''),(string)($row['oh']??''),(string)($row['selisihQty']??''),(string)($row['selisih']??'')]; } else { $sheetRows[]=[(string)($row['plu']??''),(string)($row['nama']??''),(string)($row['rack']??''),(string)($row['stock']??''),(string)($row['selisih']??'')]; } }
  $sheetXml='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
  foreach($sheetRows as $rIdx=>$cols){ $rowNum=$rIdx+1; $sheetXml.='<row r="'.$rowNum.'">'; foreach(array_values($cols) as $cIdx=>$val){ $cellRef=chr(65+$cIdx).$rowNum; $sheetXml.='<c r="'.$cellRef.'" t="inlineStr"><is><t>'.sogrand_escape_xml($val).'</t></is></c>'; } $sheetXml.='</row>'; }
  $sheetXml.='</sheetData></worksheet>';
  $zip->addFromString('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>');
  $zip->addFromString('_rels/.rels','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>');
  $zip->addFromString('docProps/core.xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:creator>OpenAI</dc:creator><cp:lastModifiedBy>OpenAI</cp:lastModifiedBy><dcterms:created xsi:type="dcterms:W3CDTF">'.gmdate('Y-m-d\TH:i:s\Z').'</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">'.gmdate('Y-m-d\TH:i:s\Z').'</dcterms:modified></cp:coreProperties>');
  $zip->addFromString('docProps/app.xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>Microsoft Excel</Application></Properties>');
  $zip->addFromString('xl/workbook.xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="SO Grand" sheetId="1" r:id="rId1"/></sheets></workbook>');
  $zip->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
  $zip->addFromString('xl/styles.xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts><fills count="1"><fill><patternFill patternType="none"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs></styleSheet>');
  $zip->addFromString('xl/worksheets/sheet1.xml',$sheetXml); $zip->close(); $bin=@file_get_contents($xlsx); @unlink($xlsx); return $bin;
}

function oh_rt_http_get($url, &$err=null, &$httpCode=null){
  $err = null; $raw = null; $httpCode = 0;
  if(function_exists('curl_init')){
    $ch = curl_init();
    curl_setopt_array($ch, [
      CURLOPT_URL=>$url,
      CURLOPT_RETURNTRANSFER=>true,
      CURLOPT_FOLLOWLOCATION=>true,
      CURLOPT_CONNECTTIMEOUT=>6,
      CURLOPT_TIMEOUT=>20,
      CURLOPT_SSL_VERIFYPEER=>false,
      CURLOPT_SSL_VERIFYHOST=>0,
      CURLOPT_ENCODING=>'',
      CURLOPT_HTTPHEADER=>[
        'Accept: application/json, text/javascript, text/html, text/plain, */*',
        'X-Requested-With: XMLHttpRequest',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) ALFASTORE/1.0'
      ]
    ]);
    $raw = curl_exec($ch); $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $cerr = curl_error($ch); curl_close($ch);
    if($raw === false || $raw === null){ $err = $cerr ?: 'Gagal mengambil data API'; return null; }
    if($httpCode >= 400){ $err = 'HTTP '.$httpCode; return null; }
  }else{
    $ctx = stream_context_create(['http'=>['method'=>'GET','timeout'=>20,'header'=>"Accept: application/json, text/javascript, text/html, text/plain, */*\r\nX-Requested-With: XMLHttpRequest\r\nUser-Agent: Mozilla/5.0 ALFASTORE/1.0\r\n"], 'ssl'=>['verify_peer'=>false,'verify_peer_name'=>false]]);
    $raw = @file_get_contents($url, false, $ctx); if($raw === false){ $err = 'Gagal mengambil data API'; return null; }
    if(isset($http_response_header) && is_array($http_response_header)){
      foreach($http_response_header as $h){ if(preg_match('/^HTTP\/\S+\s+(\d+)/', $h, $m)){ $httpCode=(int)$m[1]; break; } }
    }
  }
  return (string)$raw;
}
function oh_rt_norm_key($key){ return strtolower(preg_replace('/[^a-z0-9]/','', (string)$key)); }
function oh_rt_pick($row, $keys){
  $want = array_flip((array)$keys);
  foreach((array)$row as $rk=>$rv){
    $norm = oh_rt_norm_key($rk);
    if(isset($want[$norm]) && $rv !== null && $rv !== '') return $rv;
  }
  return '';
}

function oh_rt_saldo_score($key){
  $k = oh_rt_norm_key($key);
  if($k === '' || strpos($k, 'awal') !== false || strpos($k, 'begin') !== false || strpos($k, 'opening') !== false) return -100;
  $priority = [
    'saldo'=>200,
    'saldoakhir'=>100,'saldoakhr'=>99,'sldakhir'=>98,'saldoend'=>97,'endingbalance'=>96,
    'qtyakhir'=>95,'akhir'=>94,'endqty'=>93,'qtyend'=>92,
    'onhand'=>91,'onhandqty'=>90,'oh'=>89,'ohqty'=>88,'soh'=>87,
    'stockonhand'=>86,'stokakhir'=>85,'stockakhir'=>84,'stok'=>83,'stock'=>82,
    'saldoqtyakhir'=>81,'saldoqty'=>80,'qtysaldo'=>79,'quantity'=>70,'qty'=>69
  ];
  if(isset($priority[$k])) return $priority[$k];
  if(strpos($k,'akhir') !== false || strpos($k,'onhand') !== false || $k === 'oh' || strpos($k,'stock') !== false || strpos($k,'stok') !== false) return 50;
  return -1;
}
function oh_rt_pick_saldo($row){
  foreach((array)$row as $rk=>$rv){ if(oh_rt_norm_key($rk) === 'saldo' && $rv !== null && $rv !== '') return $rv; }
  $bestScore = -1; $best = '';
  foreach((array)$row as $rk=>$rv){
    if($rv === null || $rv === '') continue;
    $score = oh_rt_saldo_score($rk);
    if($score > $bestScore){ $bestScore = $score; $best = $rv; }
  }
  return $bestScore >= 0 ? $best : '';
}
function oh_rt_extract_int_tokens($text){
  $text = trim((string)$text);
  if($text === '') return [];
  if(!preg_match_all('/-?\d+/', $text, $m)) return [];
  return $m[0];
}
function oh_rt_norm_saldo_value($val){
  $raw = trim(strip_tags((string)$val));
  if($raw === '') return '';
  $raw = str_replace([chr(194).chr(160), '&nbsp;'], ' ', $raw);
  $raw = trim(preg_replace('/\s+/', ' ', $raw));

  // Ambil angka saldo tanpa menghilangkan angka ribuan sebelum koma.
  // Contoh: "2,151" tetap "2,151", "2.151" jadi "2,151", "0 117" jadi "117".
  $negative = (strpos($raw, '-') !== false);
  if(!preg_match_all('/\d+/', $raw, $m)) return $raw;
  $digits = implode('', $m[0]);
  if($digits === '') return $raw;
  $num = (int)$digits;
  if($negative && $num > 0) $num = -$num;
  return number_format($num, 0, '.', ',');
}
function oh_rt_saldo_from_posisi_mutasi_cells($cells, $plu){
  $plu = preg_replace("/[^0-9]/", "", (string)$plu);
  $cells = array_values((array)$cells);
  if($plu === "" || !count($cells)) return "";

  // Format laporan posisi mutasi per PLU:
  // Tgl | PLU | Nama Product | Saldo Awal | Receipt | Return | Sales | Koreksi | Saldo | Selisi
  // Ambil kolom Saldo, bukan Selisi. Contoh Saldo "2,151" harus tetap menjadi "2,151", bukan "151".
  foreach($cells as $i=>$c){
    if(preg_replace("/[^0-9]/","",(string)$c) === $plu){
      if(isset($cells[$i+7])){
        $candidate = trim((string)$cells[$i+7]);
        if($candidate !== "") return $candidate;
      }
      if(isset($cells[8])){
        $candidate = trim((string)$cells[8]);
        if($candidate !== "") return $candidate;
      }
    }
  }

  $txt = trim(preg_replace("/\\s+/", " ", implode(" ", $cells)));
  if($txt === "") return "";
  if(!preg_match("/(^|\\D)".preg_quote($plu,"/")."($|\\D)/", $txt)) return "";
  $pos = strpos($txt, $plu);
  if($pos !== false){ $txt = substr($txt, $pos + strlen($plu)); }

  // Fallback teks bebas: pertahankan angka ribuan berkoma/bertitik sebagai 1 angka.
  if(preg_match_all("/-?\\d{1,3}(?:[,.]\\d{3})+|-?\\d+/", $txt, $m)){
    $nums = $m[0];
    if(count($nums) >= 7){
      $tail = array_slice($nums, -7);
      return (string)$tail[5];
    }
    if(count($nums) >= 2) return (string)$nums[count($nums)-2];
  }
  return "";
}
function oh_rt_decode_json_any($raw){
  $raw = (string)$raw;
  $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
  $txt = trim($raw);
  if($txt === '') return null;
  $json = json_decode($txt, true);
  if(is_array($json)) return $json;
  if(preg_match('/^[a-zA-Z0-9_\.]+\s*\((.*)\)\s*;?$/s', $txt, $m)){
    $json = json_decode(trim($m[1]), true);
    if(is_array($json)) return $json;
  }
  $firstObj = strpos($txt, '{'); $firstArr = strpos($txt, '[');
  $first = false;
  if($firstObj !== false && $firstArr !== false) $first = min($firstObj, $firstArr);
  else if($firstObj !== false) $first = $firstObj;
  else if($firstArr !== false) $first = $firstArr;
  if($first !== false){
    $lastObj = strrpos($txt, '}'); $lastArr = strrpos($txt, ']');
    $last = max($lastObj === false ? -1 : $lastObj, $lastArr === false ? -1 : $lastArr);
    if($last > $first){
      $slice = substr($txt, $first, $last-$first+1);
      $json = json_decode($slice, true);
      if(is_array($json)) return $json;
    }
  }
  return null;
}
function oh_rt_collect_rows($node, &$out){
  if(!is_array($node)) return;
  $isList = array_keys($node) === range(0, count($node)-1);
  if(!$isList){
    $plu = oh_rt_pick($node, ['plu','kodeplu','kodeproduk','prdcd','prcd','productcode','itemcode','plucode','kodebarang']);
    $nama = oh_rt_pick($node, ['namabarang','nama','deskripsi','description','desc','descp','productname','namaproduk','longdesc','shortdesc','namaartikel','artikel']);
    $saldo = oh_rt_pick_saldo($node);
    if($plu !== '' && ($nama !== '' || $saldo !== '')) $out[] = ['plu'=>preg_replace('/[^0-9]/','',(string)$plu), 'nama_barang'=>(string)$nama, 'saldo'=>(string)$saldo];
  }
  foreach($node as $v){ if(is_array($v)) oh_rt_collect_rows($v, $out); }
}
function oh_rt_collect_html_rows($raw, $plu){
  $rows = [];
  $html = (string)$raw;
  if(trim($html)==='') return $rows;
  libxml_use_internal_errors(true);
  $dom = new DOMDocument();
  $load = $html;
  if(stripos($load,'<meta charset=')===false){ $load='<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'.$load; }
  @$dom->loadHTML($load);
  $xp = new DOMXPath($dom);
  $trs = $xp->query('//tr');
  if($trs && $trs->length){
    $headers = [];
    foreach($trs as $tr){
      $cells = [];
      $ths = $xp->query('th', $tr);
      $tds = $xp->query('td', $tr);
      if($ths){ foreach($ths as $cell){ $cells[] = trim(preg_replace('/\s+/',' ', $cell->textContent)); } }
      if($tds){ foreach($tds as $cell){ $cells[] = trim(preg_replace('/\s+/',' ', $cell->textContent)); } }
      if(!$cells) continue;
      $joined = implode(' ', $cells);
      if(($ths && $ths->length) || (stripos($joined,'PLU')!==false && (stripos($joined,'saldo')!==false || stripos($joined,'nama')!==false))){
        $headers = array_map('oh_rt_norm_key', $cells);
        continue;
      }
      $pIdx=$nIdx=$sIdx=null; $sBest=-1;
      if($headers){
        foreach($headers as $i=>$h){
          if($pIdx===null && in_array($h, ['plu','kodeplu','prdcd','prcd','kodeproduk','kodebarang'], true)) $pIdx=$i;
          if($nIdx===null && in_array($h, ['namabarang','nama','description','deskripsi','descp','namaproduk','productname'], true)) $nIdx=$i;
          $sc = oh_rt_saldo_score($h);
          if($sc >= 0 && ($sIdx===null || $sc > $sBest)){ $sIdx=$i; $sBest=$sc; }
        }
      }
      if($pIdx===null){
        foreach($cells as $i=>$c){ if(preg_replace('/[^0-9]/','',$c)===$plu){ $pIdx=$i; break; } }
      }
      if($pIdx===null) continue;
      $p = preg_replace('/[^0-9]/','', $cells[$pIdx] ?? '');
      if($p !== $plu) continue;
      $nama = $nIdx!==null ? ($cells[$nIdx] ?? '') : '';
      $saldo = $sIdx!==null ? ($cells[$sIdx] ?? '') : '';
      if($nama==='') $nama = $cells[$pIdx+1] ?? '';

      // Khusus laporan posisi mutasi per PLU: ambil kolom Saldo, bukan Selisi.
      $saldoMutasi = oh_rt_saldo_from_posisi_mutasi_cells($cells, $plu);
      if($saldoMutasi !== '') $saldo = $saldoMutasi;

      if($saldo===''){
        for($i=count($cells)-1;$i>=0;$i--){
          $c = trim($cells[$i]);
          if($i!==$pIdx && preg_match('/^-?[0-9][0-9\.,]*$/', str_replace(' ', '', $c))){ $saldo=$c; break; }
        }
      }
      $saldo = oh_rt_norm_saldo_value($saldo);
      $rows[] = ['plu'=>$p, 'nama_barang'=>$nama, 'saldo'=>$saldo, 'stok'=>$saldo];
    }
  }
  return $rows;
}
function oh_rt_clean_rows($rows, $plu){
  $clean=[]; $seen=[];
  foreach((array)$rows as $r){
    $p = preg_replace('/[^0-9]/','', (string)($r['plu'] ?? ''));
    if($p === '' || $p !== $plu) continue;
    $nama = trim(strip_tags((string)($r['nama_barang'] ?? '')));
    $saldo = oh_rt_norm_saldo_value($r['saldo'] ?? '');
    $key = $p.'|'.$nama.'|'.$saldo; if(isset($seen[$key])) continue; $seen[$key]=true;
    $clean[] = ['plu'=>$p, 'nama_barang'=>$nama, 'saldo'=>$saldo, 'stok'=>$saldo];
  }
  return $clean;
}
function oh_rt_fetch_detail_fallback($storeId, $plu){
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  $plu = preg_replace('/[^0-9]/','', (string)$plu);
  if($storeId === '' || $plu === '') return [];

  // Fallback penting: halaman Cek OH ( Sedang SO ) dulu hanya membaca laporan posisi mutasi.
  // Saat laporan belum tersedia / format tanggal API berubah, hasilnya kosong dan UI menampilkan
  // "Data tidak ditemukan". Endpoint detail produk tetap mengembalikan OH terkini per PLU.
  $url = ALFA_TO_API_BASE . '/cex/get_product_detail/?' . http_build_query(['storeId'=>$storeId, 'plu'=>$plu]);
  $httpCode = 0; $lastErr = null;
  $raw = oh_rt_http_get($url, $lastErr, $httpCode);
  if($raw === null || trim($raw) === '') return [];

  $json = oh_rt_decode_json_any($raw);
  if(!is_array($json)) return [];
  $rows = [];
  oh_rt_collect_rows($json, $rows);
  $clean = oh_rt_clean_rows($rows, $plu);
  if($clean) return $clean;

  // Bentuk umum API CEX: [{plu, descp, onhand}]
  $list = (array_keys($json) === range(0, count($json)-1)) ? $json : [$json];
  foreach($list as $row){
    if(!is_array($row)) continue;
    $p = preg_replace('/[^0-9]/','', (string)($row['plu'] ?? $row['PLU'] ?? $plu));
    if($p !== $plu) continue;
    $nama = trim((string)($row['descp'] ?? $row['desc'] ?? $row['description'] ?? $row['nama'] ?? $row['nama_barang'] ?? '-'));
    $saldo = $row['onhand'] ?? $row['on_hand'] ?? $row['oh'] ?? $row['saldo'] ?? $row['stok'] ?? null;
    if($saldo === null || $saldo === '') $saldo = '0';
    $saldo = oh_rt_norm_saldo_value($saldo);
    return [['plu'=>$p, 'nama_barang'=>($nama !== '' ? $nama : '-'), 'saldo'=>$saldo, 'stok'=>$saldo]];
  }
  return [];
}

function oh_rt_fetch_rows($storeId, $plu, &$err=null, &$usedDate=null){
  $err = null; $usedDate = null;
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  $plu = preg_replace('/[^0-9]/','', (string)$plu);
  if($storeId === ''){ $err='Kode toko login tidak ditemukan'; return []; }
  if($plu === ''){ $err='PLU wajib diisi angka'; return []; }

  $lastErr = '';
  $formats = ['d-m-Y', 'Y-m-d'];
  for($i=0; $i<=2; $i++){
    $ts = strtotime('-'.$i.' day');
    foreach($formats as $fmt){
      $periode = date($fmt, $ts);
      $url = ALFA_PRD_API_BASE . '/rpt/laporan/laporan_posisi_mutasi_per_plu?' . http_build_query([
        'storeId'=>$storeId,
        'periode1'=>$periode,
        'periode2'=>$periode,
        'plu'=>$plu
      ]);
      $httpCode = 0;
      $raw = oh_rt_http_get($url, $lastErr, $httpCode);
      if($raw === null || trim($raw) === '') continue;

      $rows = [];
      $json = oh_rt_decode_json_any($raw);
      if(is_array($json)){ oh_rt_collect_rows($json, $rows); }
      if(!$rows){ $rows = oh_rt_collect_html_rows($raw, $plu); }
      $clean = oh_rt_clean_rows($rows, $plu);
      if($clean){ $usedDate = $periode; return $clean; }
    }
  }

  $fallback = oh_rt_fetch_detail_fallback($storeId, $plu);
  if($fallback){ $usedDate = 'real-time'; return $fallback; }

  $err = '';
  return [];
}


if(isset($_GET['page']) && $_GET['page']==='register_dokumen_toko'){
  $me = cookie_read_session();
  if(!$me){
    cibili_render_session_expired('index.php');
  }
  $storeId = preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['storeId'] ?? $me));
  if($storeId === '') $storeId = (string)$me;
  $todayIso = date('Y-m-d');
  $periode2Iso = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['periode2'] ?? '')) ? (string)$_GET['periode2'] : $todayIso;
  $periode1Iso = date('Y-m-01', strtotime($periode2Iso));
  ?>
<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"><title>Register Dokumen Toko</title>
<style>*{box-sizing:border-box}body{margin:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827}.wrap{padding:12px;min-height:100vh}.panel{background:#fff;border:1px solid #d1d5db;border-radius:5px;box-shadow:0 10px 24px rgba(15,23,42,.08);overflow:hidden}.head{padding:14px 14px 8px;border-bottom:1px solid #d1d5db}h2{font-size:18px;margin:0 0 4px}.sub{font-size:12px;color:#6b7280;font-weight:700}.filters{display:grid;grid-template-columns:1fr 1fr 1.1fr auto;gap:10px;padding:12px 14px;align-items:end;border-bottom:1px solid #d1d5db}label{display:block;font-size:12px;font-weight:900;margin-bottom:5px;color:#374151}input,select,button{width:100%;border:1px solid #d1d5db;border-radius:12px;padding:10px 11px;font-size:14px;background:#fff;color:#111827}input{font-weight:800}button{border:none;background:#0f766e;color:#fff;font-weight:900;cursor:pointer;min-width:105px}.status{padding:8px 14px;font-size:12px;color:#6b7280;font-weight:800}.frameBox{height:calc(100vh - 190px);min-height:420px;background:#fff}.frameBox iframe{width:100%;height:100%;border:0;background:#fff}@media(max-width:720px){.filters{grid-template-columns:1fr 1fr}.filters .wide{grid-column:1/-1}.frameBox{height:calc(100vh - 245px)}}</style>
</head><body><div class="wrap"><div class="panel"><div class="head"><h2>Register Dokumen Toko</h2><div class="sub">Store: <b><?php echo htmlspecialchars((string)$storeId, ENT_QUOTES, 'UTF-8'); ?></b> • Pilih Tanggal 1 dan Tanggal 2 sesuai periode laporan</div></div><div class="filters"><div><label>Tanggal 1</label><input type="date" id="periode1" value="<?php echo htmlspecialchars($periode1Iso, ENT_QUOTES, 'UTF-8'); ?>"></div><div><label>Tanggal 2</label><input type="date" id="periode2" value="<?php echo htmlspecialchars($periode2Iso, ENT_QUOTES, 'UTF-8'); ?>"></div><div class="wide"><label>Filter</label><select id="jenisFilter"><option value="ALL">ALL</option><option value="ADJUST SO">ADJUST SO</option><option value="OTOMATIS">OTOMATIS</option><option value="PEMUSNAHAN">PEMUSNAHAN</option></select></div><div><button type="button" id="btnLoad">Tampilkan</button></div></div><div class="status" id="statusText">Menyiapkan laporan...</div><div class="frameBox"><iframe id="reportFrame" title="Register Dokumen Toko"></iframe></div></div></div>
<script>window.REGISTER_STORE_ID=<?php echo json_encode((string)$storeId); ?>;eval(atob("CihmdW5jdGlvbigpewogICd1c2Ugc3RyaWN0JzsKICB2YXIgQVBJX1VSTCA9IGxvY2F0aW9uLnBhdGhuYW1lOwogIHZhciBTVE9SRV9JRCA9IHdpbmRvdy5SRUdJU1RFUl9TVE9SRV9JRCB8fCAnJzsKICB2YXIgcDEgPSBkb2N1bWVudC5nZXRFbGVtZW50QnlJZCgncGVyaW9kZTEnKTsKICB2YXIgcDIgPSBkb2N1bWVudC5nZXRFbGVtZW50QnlJZCgncGVyaW9kZTInKTsKICB2YXIgZmlsdGVyID0gZG9jdW1lbnQuZ2V0RWxlbWVudEJ5SWQoJ2plbmlzRmlsdGVyJyk7CiAgdmFyIHN0YXR1c1RleHQgPSBkb2N1bWVudC5nZXRFbGVtZW50QnlJZCgnc3RhdHVzVGV4dCcpOwogIHZhciBmcmFtZSA9IGRvY3VtZW50LmdldEVsZW1lbnRCeUlkKCdyZXBvcnRGcmFtZScpOwogIHZhciBidG4gPSBkb2N1bWVudC5nZXRFbGVtZW50QnlJZCgnYnRuTG9hZCcpOwogIGZ1bmN0aW9uIHBhZDIobil7IHJldHVybiBTdHJpbmcobikucGFkU3RhcnQoMiwnMCcpOyB9CiAgZnVuY3Rpb24gZG15KGlzbyl7CiAgICB2YXIgbSA9IFN0cmluZyhpc28gfHwgJycpLm1hdGNoKC9eKFxkezR9KS0oXGR7Mn0pLShcZHsyfSkkLyk7CiAgICBpZighbSkgcmV0dXJuICcnOwogICAgcmV0dXJuIG1bM10gKyAnLScgKyBtWzJdICsgJy0nICsgbVsxXTsKICB9CiAgZnVuY3Rpb24gZXNjKHMpewogICAgcmV0dXJuIFN0cmluZyhzID09IG51bGwgPyAnJyA6IHMpLnJlcGxhY2UoL1smPD4iJ10vZywgZnVuY3Rpb24oY2gpewogICAgICByZXR1cm4geycmJzonJmFtcDsnLCc8JzonJmx0OycsJz4nOicmZ3Q7JywnIic6JyZxdW90OycsIiciOicmIzM5Oyd9W2NoXTsKICAgIH0pOwogIH0KICBmdW5jdGlvbiBtYWtlSHRtbChib2R5KXsKICAgIHZhciBjc3MgPSAnYm9keXtmb250LWZhbWlseTpBcmlhbCxzYW5zLXNlcmlmO21hcmdpbjoxMHB4O2NvbG9yOiMxMTE4Mjc7YmFja2dyb3VuZDojZmZmfScgKwogICAgICAndGFibGV7Ym9yZGVyLWNvbGxhcHNlOmNvbGxhcHNlO3dpZHRoOjEwMCU7Zm9udC1zaXplOjEycHh9JyArCiAgICAgICd0aCx0ZHtib3JkZXI6MXB4IHNvbGlkICNkMWQ1ZGI7cGFkZGluZzo3cHg7dGV4dC1hbGlnbjpsZWZ0O3ZlcnRpY2FsLWFsaWduOnRvcH0nICsKICAgICAgJ3Roe2JhY2tncm91bmQ6IzBmNzY2ZTtjb2xvcjojZmZmO3Bvc2l0aW9uOnN0aWNreTt0b3A6MDt6LWluZGV4OjJ9JyArCiAgICAgICd0cjpudGgtY2hpbGQoZXZlbil7YmFja2dyb3VuZDojZjlmYWZifS5lbXB0eXtwYWRkaW5nOjE4cHg7dGV4dC1hbGlnbjpjZW50ZXI7Zm9udC13ZWlnaHQ6ODAwO2NvbG9yOiM2YjcyODB9JyArCiAgICAgICdwcmV7d2hpdGUtc3BhY2U6cHJlLXdyYXA7bWFyZ2luOjA7Zm9udC1mYW1pbHk6QXJpYWwsc2Fucy1zZXJpZn0nOwogICAgcmV0dXJuICc8IWRvY3R5cGUgaHRtbD48aHRtbD48aGVhZD48bWV0YSBjaGFyc2V0PSJ1dGYtOCI+PG1ldGEgbmFtZT0idmlld3BvcnQiIGNvbnRlbnQ9IndpZHRoPWRldmljZS13aWR0aCxpbml0aWFsLXNjYWxlPTEiPjxzdHlsZT4nICsgY3NzICsgJzwvc3R5bGU+PC9oZWFkPjxib2R5PicgKyBib2R5ICsgJzwvYm9keT48L2h0bWw+JzsKICB9CiAgZnVuY3Rpb24gcGlja1Jvd3Mob2JqKXsKICAgIGlmKEFycmF5LmlzQXJyYXkob2JqKSkgcmV0dXJuIG9iajsKICAgIGlmKG9iaiAmJiBBcnJheS5pc0FycmF5KG9iai5kYXRhKSkgcmV0dXJuIG9iai5kYXRhOwogICAgaWYob2JqICYmIEFycmF5LmlzQXJyYXkob2JqLnJvd3MpKSByZXR1cm4gb2JqLnJvd3M7CiAgICBpZihvYmogJiYgQXJyYXkuaXNBcnJheShvYmoucmVzdWx0KSkgcmV0dXJuIG9iai5yZXN1bHQ7CiAgICBpZihvYmogJiYgb2JqLmRhdGEgJiYgQXJyYXkuaXNBcnJheShvYmouZGF0YS5yb3dzKSkgcmV0dXJuIG9iai5kYXRhLnJvd3M7CiAgICBpZihvYmogJiYgdHlwZW9mIG9iaiA9PT0gJ29iamVjdCcpIHJldHVybiBbb2JqXTsKICAgIHJldHVybiBbXTsKICB9CiAgZnVuY3Rpb24ganNvblRvVGFibGUob2JqKXsKICAgIHZhciByb3dzID0gcGlja1Jvd3Mob2JqKTsKICAgIGlmKCFyb3dzLmxlbmd0aCkgcmV0dXJuIG1ha2VIdG1sKCc8ZGl2IGNsYXNzPSJlbXB0eSI+RGF0YSBrb3Nvbmc8L2Rpdj4nKTsKICAgIHZhciBrZXlzID0gW107CiAgICByb3dzLmZvckVhY2goZnVuY3Rpb24ocil7CiAgICAgIGlmKHIgJiYgdHlwZW9mIHIgPT09ICdvYmplY3QnICYmICFBcnJheS5pc0FycmF5KHIpKXsKICAgICAgICBPYmplY3Qua2V5cyhyKS5mb3JFYWNoKGZ1bmN0aW9uKGspeyBpZihrZXlzLmluZGV4T2YoaykgPCAwKSBrZXlzLnB1c2goayk7IH0pOwogICAgICB9IGVsc2UgaWYoa2V5cy5pbmRleE9mKCd2YWx1ZScpIDwgMCkga2V5cy5wdXNoKCd2YWx1ZScpOwogICAgfSk7CiAgICB2YXIgdGhlYWQgPSBrZXlzLm1hcChmdW5jdGlvbihrKXsgcmV0dXJuICc8dGg+JyArIGVzYyhrKSArICc8L3RoPic7IH0pLmpvaW4oJycpOwogICAgdmFyIHRib2R5ID0gcm93cy5tYXAoZnVuY3Rpb24ocil7CiAgICAgIHJldHVybiAnPHRyPicgKyBrZXlzLm1hcChmdW5jdGlvbihrKXsKICAgICAgICB2YXIgdiA9IChyICYmIHR5cGVvZiByID09PSAnb2JqZWN0JyAmJiAhQXJyYXkuaXNBcnJheShyKSkgPyByW2tdIDogcjsKICAgICAgICByZXR1cm4gJzx0ZD4nICsgZXNjKHYpICsgJzwvdGQ+JzsKICAgICAgfSkuam9pbignJykgKyAnPC90cj4nOwogICAgfSkuam9pbignJyk7CiAgICByZXR1cm4gbWFrZUh0bWwoJzx0YWJsZT48dGhlYWQ+PHRyPicgKyB0aGVhZCArICc8L3RyPjwvdGhlYWQ+PHRib2R5PicgKyB0Ym9keSArICc8L3Rib2R5PjwvdGFibGU+Jyk7CiAgfQogIGZ1bmN0aW9uIGxvb2tzTGlrZUh0bWwocyl7IHJldHVybiAvPChodG1sfGJvZHl8dGFibGV8dGhlYWR8dGJvZHl8dHJ8dGR8dGh8ZGl2fHNwYW58cHJlfCFkb2N0eXBlKVxiL2kudGVzdChTdHJpbmcocyB8fCAnJykpOyB9CiAgZnVuY3Rpb24gbm9ybWFsaXplSHRtbChpbnB1dCl7CiAgICB2YXIgcmF3ID0gU3RyaW5nKGlucHV0ID09IG51bGwgPyAnJyA6IGlucHV0KTsKICAgIGlmKCFyYXcudHJpbSgpKSByZXR1cm4gbWFrZUh0bWwoJzxkaXYgY2xhc3M9ImVtcHR5Ij5EYXRhIGtvc29uZzwvZGl2PicpOwogICAgdHJ5IHsgcmV0dXJuIGpzb25Ub1RhYmxlKEpTT04ucGFyc2UocmF3KSk7IH0gY2F0Y2goZSkge30KICAgIGlmKGxvb2tzTGlrZUh0bWwocmF3KSkgcmV0dXJuIHJhdzsKICAgIHJldHVybiBtYWtlSHRtbCgnPHByZT4nICsgZXNjKHJhdykgKyAnPC9wcmU+Jyk7CiAgfQogIGZ1bmN0aW9uIHNldEZyYW1lKGh0bWwsIGNiKXsKICAgIGZyYW1lLm9ubG9hZCA9IGZ1bmN0aW9uKCl7IGlmKGNiKSB3aW5kb3cuc2V0VGltZW91dChjYiwgMTAwKTsgfTsKICAgIGZyYW1lLnNldEF0dHJpYnV0ZSgnc2FuZGJveCcsJ2FsbG93LXNhbWUtb3JpZ2luIGFsbG93LXNjcmlwdHMgYWxsb3ctZm9ybXMgYWxsb3ctcG9wdXBzJyk7CiAgICBmcmFtZS5zcmNkb2MgPSBodG1sOwogIH0KICBmdW5jdGlvbiBhcHBseUZpbHRlcigpewogICAgdmFyIGRvYyA9IGZyYW1lLmNvbnRlbnREb2N1bWVudCB8fCAoZnJhbWUuY29udGVudFdpbmRvdyAmJiBmcmFtZS5jb250ZW50V2luZG93LmRvY3VtZW50KTsKICAgIGlmKCFkb2MgfHwgIWRvYy5ib2R5KSByZXR1cm47CiAgICB2YXIgdmFsID0gU3RyaW5nKChmaWx0ZXIgJiYgZmlsdGVyLnZhbHVlKSB8fCAnQUxMJykudG9VcHBlckNhc2UoKTsKICAgIHZhciBtYXAgPSB7CiAgICAgICdBTEwnOiBbXSwKICAgICAgJ0FESlVTVCBTTyc6IFsnQURKVVNUIFNPJywnQURKVVNUJywnQURKIFNPJywnQURKJywnS09SRUtTSScsJ1NPJ10sCiAgICAgICdPVE9NQVRJUyc6IFsnT1RPTUFUSVMnLCdBVVRPJywnQVVUT01BVElDJ10sCiAgICAgICdQRU1VU05BSEFOJzogWydQRU1VU05BSEFOJywnTVVTTkFIJ10KICAgIH07CiAgICB2YXIgdGVybXMgPSBtYXBbdmFsXSB8fCBbdmFsXTsKICAgIHZhciByb3dzID0gQXJyYXkucHJvdG90eXBlLnNsaWNlLmNhbGwoZG9jLnF1ZXJ5U2VsZWN0b3JBbGwoJ3RhYmxlIHRyJykpLmZpbHRlcihmdW5jdGlvbih0cil7IHJldHVybiB0ci5xdWVyeVNlbGVjdG9yQWxsKCd0ZCcpLmxlbmd0aDsgfSk7CiAgICB2YXIgc2hvd24gPSAwOwogICAgcm93cy5mb3JFYWNoKGZ1bmN0aW9uKHRyKXsKICAgICAgdmFyIHR4dCA9IFN0cmluZyh0ci50ZXh0Q29udGVudCB8fCAnJykudG9VcHBlckNhc2UoKS5yZXBsYWNlKC9ccysvZywnICcpOwogICAgICB2YXIgb2sgPSAhdGVybXMubGVuZ3RoIHx8IHRlcm1zLnNvbWUoZnVuY3Rpb24odCl7IHJldHVybiB0eHQuaW5kZXhPZih0KSA+PSAwOyB9KTsKICAgICAgdHIuc3R5bGUuZGlzcGxheSA9IG9rID8gJycgOiAnbm9uZSc7CiAgICAgIGlmKG9rKSBzaG93bisrOwogICAgfSk7CiAgICB2YXIgYm94ID0gZG9jLmdldEVsZW1lbnRCeUlkKCdmaWx0ZXJFbXB0eUluZm8nKTsKICAgIGlmKCFib3gpewogICAgICBib3ggPSBkb2MuY3JlYXRlRWxlbWVudCgnZGl2Jyk7CiAgICAgIGJveC5pZCA9ICdmaWx0ZXJFbXB0eUluZm8nOwogICAgICBib3guc3R5bGUuY3NzVGV4dCA9ICdkaXNwbGF5Om5vbmU7cGFkZGluZzoxOHB4O3RleHQtYWxpZ246Y2VudGVyO2ZvbnQtZmFtaWx5OkFyaWFsLHNhbnMtc2VyaWY7Zm9udC13ZWlnaHQ6ODAwO2NvbG9yOiM2YjcyODAnOwogICAgICBib3gudGV4dENvbnRlbnQgPSAnVGlkYWsgYWRhIGRhdGEgdW50dWsgZmlsdGVyIGluaS4nOwogICAgICBkb2MuYm9keS5hcHBlbmRDaGlsZChib3gpOwogICAgfQogICAgYm94LnN0eWxlLmRpc3BsYXkgPSAocm93cy5sZW5ndGggJiYgc2hvd24gPT09IDApID8gJ2Jsb2NrJyA6ICdub25lJzsKICAgIHN0YXR1c1RleHQuaW5uZXJIVE1MID0gJ1BlcmlvZGU6IDxiPicgKyBlc2MoZG15KHAxLnZhbHVlKSkgKyAnPC9iPiBzL2QgPGI+JyArIGVzYyhkbXkocDIudmFsdWUpKSArICc8L2I+ICZidWxsOyBGaWx0ZXI6IDxiPicgKyBlc2MoZmlsdGVyLnZhbHVlKSArICc8L2I+ICZidWxsOyBCYXJpcyB0YW1waWw6IDxiPicgKyBzaG93biArICc8L2I+JzsKICB9CiAgYXN5bmMgZnVuY3Rpb24gbG9hZFJlcG9ydCgpewogICAgaWYoIXAxLnZhbHVlKXsgYWxlcnQoJ1BpbGloIHRhbmdnYWwgMSB0ZXJsZWJpaCBkYWh1bHUnKTsgcmV0dXJuOyB9CiAgICBpZighcDIudmFsdWUpeyBhbGVydCgnUGlsaWggdGFuZ2dhbCAyIHRlcmxlYmloIGRhaHVsdScpOyByZXR1cm47IH0KICAgIGlmKHAxLnZhbHVlID4gcDIudmFsdWUpeyBhbGVydCgnVGFuZ2dhbCAxIHRpZGFrIGJvbGVoIGxlYmloIGJlc2FyIGRhcmkgVGFuZ2dhbCAyJyk7IHJldHVybjsgfQogICAgc3RhdHVzVGV4dC50ZXh0Q29udGVudCA9ICdNZW5nYW1iaWwgZGF0YSBsYXBvcmFuLi4uJzsKICAgIHNldEZyYW1lKG1ha2VIdG1sKCc8ZGl2IGNsYXNzPSJlbXB0eSI+TG9hZGluZy4uLjwvZGl2PicpKTsKICAgIHZhciBxcyA9IG5ldyBVUkxTZWFyY2hQYXJhbXMoKTsKICAgIHFzLnNldCgnYXBpJywncmVnaXN0ZXJfZG9rdW1lbl90b2tvJyk7CiAgICBxcy5zZXQoJ3N0b3JlSWQnLFNUT1JFX0lEKTsKICAgIHFzLnNldCgncGVyaW9kZTEnLGRteShwMS52YWx1ZSkpOwogICAgcXMuc2V0KCdwZXJpb2RlMicsZG15KHAyLnZhbHVlKSk7CiAgICB0cnl7CiAgICAgIHZhciByZXMgPSBhd2FpdCBmZXRjaChBUElfVVJMICsgJz8nICsgcXMudG9TdHJpbmcoKSwge2NhY2hlOiduby1zdG9yZScsIGNyZWRlbnRpYWxzOidzYW1lLW9yaWdpbid9KTsKICAgICAgdmFyIHRleHQgPSBhd2FpdCByZXMudGV4dCgpOwogICAgICB2YXIgcGF5bG9hZCA9IG51bGw7CiAgICAgIHRyeSB7IHBheWxvYWQgPSBKU09OLnBhcnNlKHRleHQpOyB9IGNhdGNoKGUpIHt9CiAgICAgIGlmKCFyZXMub2spIHRocm93IG5ldyBFcnJvcigocGF5bG9hZCAmJiAocGF5bG9hZC5lcnJvciB8fCBwYXlsb2FkLm1lc3NhZ2UgfHwgcGF5bG9hZC5tc2cpKSB8fCAoJ0hUVFAgJyArIHJlcy5zdGF0dXMpKTsKICAgICAgdmFyIGNvbnRlbnQgPSB0ZXh0OwogICAgICBpZihwYXlsb2FkICYmIHR5cGVvZiBwYXlsb2FkID09PSAnb2JqZWN0Jyl7CiAgICAgICAgaWYocGF5bG9hZC5vayA9PT0gZmFsc2UpIHRocm93IG5ldyBFcnJvcihwYXlsb2FkLmVycm9yIHx8IHBheWxvYWQubWVzc2FnZSB8fCBwYXlsb2FkLm1zZyB8fCAnR2FnYWwgYW1iaWwgZGF0YScpOwogICAgICAgIGNvbnRlbnQgPSBwYXlsb2FkLmJvZHkgfHwgcGF5bG9hZC5odG1sIHx8IHBheWxvYWQuZGF0YSB8fCBwYXlsb2FkLnJvd3MgfHwgdGV4dDsKICAgICAgfQogICAgICBpZih0eXBlb2YgY29udGVudCAhPT0gJ3N0cmluZycpIGNvbnRlbnQgPSBKU09OLnN0cmluZ2lmeShjb250ZW50KTsKICAgICAgc2V0RnJhbWUobm9ybWFsaXplSHRtbChjb250ZW50KSwgYXBwbHlGaWx0ZXIpOwogICAgICB3aW5kb3cuc2V0VGltZW91dChhcHBseUZpbHRlciwgNDAwKTsKICAgIH1jYXRjaChlcnIpewogICAgICBzZXRGcmFtZShtYWtlSHRtbCgnPGRpdiBzdHlsZT0iY29sb3I6Izk5MWIxYiI+PGI+R2FnYWwgbWVuYW1waWxrYW4gbGFwb3Jhbi48L2I+PGJyPicgKyBlc2MoZXJyICYmIGVyci5tZXNzYWdlID8gZXJyLm1lc3NhZ2UgOiBlcnIpICsgJzwvZGl2PicpKTsKICAgICAgc3RhdHVzVGV4dC50ZXh0Q29udGVudCA9ICdHYWdhbCBtZW5nYW1iaWwgZGF0YS4nOwogICAgfQogIH0KICBpZihidG4pIGJ0bi5hZGRFdmVudExpc3RlbmVyKCdjbGljaycsIGxvYWRSZXBvcnQpOwogIGlmKHAxKSBwMS5hZGRFdmVudExpc3RlbmVyKCdjaGFuZ2UnLCBsb2FkUmVwb3J0KTsKICBpZihwMikgcDIuYWRkRXZlbnRMaXN0ZW5lcignY2hhbmdlJywgbG9hZFJlcG9ydCk7CiAgaWYoZmlsdGVyKSBmaWx0ZXIuYWRkRXZlbnRMaXN0ZW5lcignY2hhbmdlJywgYXBwbHlGaWx0ZXIpOwogIGxvYWRSZXBvcnQoKTsKfSkoKTsK"));</script></body></html><?php
  exit;
}


if(isset($_GET['page']) && $_GET['page']==='register_dokumen_toko_nr'){
  $me = cookie_read_session();
  if(!$me){ cibili_render_session_expired('index.php'); }
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)($_GET['storeId'] ?? $me)));
  if($storeId === '') $storeId = strtoupper((string)$me);
  $todayIso = date('Y-m-d');
  $yesterdayIso = date('Y-m-d', strtotime('-1 day'));
  $periode1Iso = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['periode1'] ?? '')) ? (string)$_GET['periode1'] : $yesterdayIso;
  $periode2Iso = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['periode2'] ?? '')) ? (string)$_GET['periode2'] : $todayIso;
  ?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=5,user-scalable=yes,viewport-fit=cover">
<title>Register Dokumen NR</title>
<style>
:root{--teal:#18b6b0;--ink:#202124;--muted:#8b8f96;--line:#e7e8ea;--bg:#fff}
*{box-sizing:border-box}
html,body{margin:0;min-height:100%;background:var(--bg);font-family:Arial,Helvetica,sans-serif;color:var(--ink)}
body{overflow-x:hidden}
.nr-page{min-height:100vh;background:#fff}
.nr-header{height:98px;display:grid;grid-template-columns:56px 1fr 56px;align-items:center;padding:18px 20px 12px;border-bottom:1px solid #ededed;box-shadow:0 3px 10px rgba(15,23,42,.08);position:sticky;top:0;z-index:30;background:#fff}
.nr-back,.nr-doc{border:0;background:transparent;padding:0;display:grid;place-items:center;cursor:pointer;color:#202124}
.nr-back{width:44px;height:44px;font-size:42px;line-height:1;font-weight:300}
.nr-doc{width:48px;height:48px;color:var(--teal);justify-self:end}
.nr-doc svg{width:45px;height:45px;fill:currentColor}
.nr-title{font-size:31px;line-height:1.1;font-weight:900;letter-spacing:-.7px;margin:0;padding-left:7px}
.date-section{padding:23px 20px 22px;border-bottom:1px solid #ececec;box-shadow:0 4px 11px rgba(15,23,42,.08);background:#fff;position:relative;z-index:20}
.date-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;max-width:1000px;margin:0 auto}
.date-grid.single{grid-template-columns:1fr}
.date-card{min-height:116px;border:1px solid #dfe3e8;background:#fff;padding:20px 22px;display:grid;grid-template-columns:42px minmax(0,1fr);align-items:center;gap:10px;cursor:pointer;position:relative;border-radius:22px;box-shadow:0 6px 16px rgba(15,23,42,.07);overflow:hidden}
.date-card,.date-card:first-child,.date-card:last-child,.date-grid.single .date-card{border-radius:22px}
.date-card:focus-within{border-color:var(--teal);box-shadow:0 0 0 3px rgba(24,182,176,.12)}
.date-icon{color:var(--teal);display:grid;place-items:center}.date-icon svg{width:31px;height:31px;stroke:currentColor;fill:none;stroke-width:2}
.date-copy{min-width:0}.date-label{display:block;font-size:21px;color:#96999e;margin-bottom:4px}.date-value{display:block;font-size:25px;font-weight:900;white-space:nowrap}
.date-input{position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer}
.report-shell{position:relative;background:#fff;min-height:calc(100vh - 260px);overflow:auto;-webkit-overflow-scrolling:touch;touch-action:pan-x pan-y pinch-zoom}
.report-stage{width:100%;min-width:760px;transform-origin:top left;background:#fff}
.report-frame{display:block;width:100%;height:calc(100vh - 260px);min-height:650px;border:0;background:#fff}
.loading-layer{position:absolute;inset:0;display:grid;place-items:center;background:rgba(255,255,255,.88);z-index:5;font-size:14px;font-weight:800;color:#6b7280}.loading-layer.hide{display:none}
.zoom-hint{position:fixed;right:14px;bottom:14px;z-index:40;background:rgba(17,24,39,.82);color:#fff;border-radius:999px;padding:8px 12px;font-size:11px;font-weight:800;pointer-events:none;opacity:0;transition:.2s}.zoom-hint.show{opacity:1}
@media(max-width:720px){
 .nr-header{height:92px;padding:16px 15px 10px;grid-template-columns:54px 1fr 50px}.nr-title{font-size:27px;padding-left:2px}.nr-back{font-size:39px}.nr-doc svg{width:42px;height:42px}
 .date-section{padding:22px 20px}.date-card{min-height:116px;padding:18px 20px;grid-template-columns:42px 1fr}.date-label{font-size:20px}.date-value{font-size:24px}
 .report-shell{min-height:calc(100vh - 254px)}.report-frame{height:calc(100vh - 254px);min-height:620px}
}
@media(max-width:470px){.nr-title{font-size:25px}.date-section{padding:20px 14px}.date-grid{gap:10px}.date-card{padding:16px 12px;grid-template-columns:32px 1fr;border-radius:20px}.date-icon svg{width:27px;height:27px}.date-label{font-size:16px}.date-value{font-size:19px}}
</style>
</head>
<body>
<div class="nr-page">
  <header class="nr-header">
    <button class="nr-back" type="button" id="nrBack" aria-label="Kembali">&#8592;</button>
    <h1 class="nr-title">Register Dokumen NR</h1>
    <button class="nr-doc" type="button" id="nrDoc" aria-label="Muat ulang laporan" title="Muat ulang laporan">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 2h8l5 5v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm7 1.8V8h4.2L13 3.8z"/></svg>
    </button>
  </header>
  <section class="date-section">
    <div class="date-grid" id="dateGrid">
      <label class="date-card" id="fromCard">
        <span class="date-icon"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M7 14h2M11 14h2M15 14h2M7 18h2M11 18h2"/></svg></span>
        <span class="date-copy"><span class="date-label">Dari Tanggal</span><strong class="date-value" id="fromText"></strong></span>
        <input class="date-input" type="date" id="periode1" value="<?php echo htmlspecialchars($periode1Iso, ENT_QUOTES, 'UTF-8'); ?>" aria-label="Dari tanggal">
      </label>
      <label class="date-card" id="toCard">
        <span class="date-icon"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18M7 14h2M11 14h2M15 14h2M7 18h2M11 18h2"/></svg></span>
        <span class="date-copy"><span class="date-label">Sampai</span><strong class="date-value" id="toText"></strong></span>
        <input class="date-input" type="date" id="periode2" value="<?php echo htmlspecialchars($periode2Iso, ENT_QUOTES, 'UTF-8'); ?>" aria-label="Sampai tanggal">
      </label>
    </div>
  </section>
  <main class="report-shell" id="reportShell">
    <div class="loading-layer" id="loadingLayer">Memuat Register Dokumen NR...</div>
    <div class="report-stage" id="reportStage"><iframe class="report-frame" id="nrFrame" title="Register Dokumen NR"></iframe></div>
  </main>
</div>
<div class="zoom-hint" id="zoomHint">Gunakan dua jari untuk memperbesar laporan</div>
<script>
(function(){
 const STORE_ID=<?php echo json_encode($storeId); ?>;
 const p1=document.getElementById('periode1'),p2=document.getElementById('periode2');
 const fromText=document.getElementById('fromText'),toText=document.getElementById('toText');
 const grid=document.getElementById('dateGrid'),fromCard=document.getElementById('fromCard'),toCard=document.getElementById('toCard');
 const frame=document.getElementById('nrFrame'),loading=document.getElementById('loadingLayer'),hint=document.getElementById('zoomHint');
 function dmy(iso){const m=String(iso||'').match(/^(\d{4})-(\d{2})-(\d{2})$/);return m?(m[3]+'-'+m[2]+'-'+m[1]):'';}
 function pretty(iso){const m=String(iso||'').match(/^(\d{4})-(\d{2})-(\d{2})$/);if(!m)return '-';const mon=['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];return Number(m[3])+' '+mon[Number(m[2])-1]+' '+m[1];}
 function updateCards(){
   fromText.textContent=pretty(p1.value);toText.textContent=pretty(p2.value);
   grid.classList.remove('single');fromCard.style.display='grid';toCard.style.display='grid';
 }
 async function load(){
   if(!p1.value||!p2.value)return;
   if(p1.value>p2.value){p2.value=p1.value;}
   updateCards();loading.textContent='Menyiapkan laporan...';loading.classList.remove('hide');
   const periode1=dmy(p1.value),periode2=dmy(p2.value);
   const qs=new URLSearchParams({api:'register_dokumen_toko_nr',storeId:STORE_ID,periode1:periode1,periode2:periode2,_t:String(Date.now())});
   try{
     const controller=new AbortController();const timeout=setTimeout(function(){controller.abort();},45000);
     const res=await fetch('proxy.php?'+qs.toString(),{cache:'no-store',credentials:'same-origin',signal:controller.signal,headers:{'X-Requested-With':'XMLHttpRequest'}});clearTimeout(timeout);
     const text=await res.text();
     let payload=null;try{payload=JSON.parse(text);}catch(e){}
     if(!res.ok || !payload || payload.ok!==true) throw new Error((payload&&(payload.error||payload.message||payload.msg))||('HTTP '+res.status));
     let html=String(payload.body||'');
     if(!html.trim()) html='<!doctype html><html><body style="font-family:Arial;padding:18px">Data laporan kosong.</body></html>';
     frame.onload=function(){loading.classList.add('hide');hint.classList.add('show');setTimeout(()=>hint.classList.remove('show'),2200);};
     frame.srcdoc=html;
   }catch(err){
     loading.classList.add('hide');
     frame.srcdoc='<!doctype html><html><body style="font-family:Arial;padding:18px;color:#991b1b"><b>Gagal menampilkan laporan.</b><br>'+String(err&&err.message?err.message:err).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];})+'</body></html>';
   }
   const u=new URL(location.href);u.searchParams.set('periode1',p1.value);u.searchParams.set('periode2',p2.value);history.replaceState(null,'',u.toString());
 }
 let timer=0;function changed(){clearTimeout(timer);timer=setTimeout(load,180);}
 p1.addEventListener('change',changed);p2.addEventListener('change',changed);
 document.getElementById('nrBack').addEventListener('click',function(){if(history.length>1)history.back();else location.href=location.pathname;});
 document.getElementById('nrDoc').addEventListener('click',load);
 updateCards();load();
})();
</script>
</body></html><?php
  exit;
}


if(isset($_GET['page']) && $_GET['page'] === 'oh_realtime'){
  $me = cookie_read_session(); if($me) presence_touch($me, false);
  if(!$me){ cibili_render_session_expired('index.php'); }
  $store = strtoupper(substr(preg_replace('/[^A-Z0-9]/','', (string)$me), 0, 4));
  ?><!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Cek OH ( Sedang SO )</title>
<style>
:root{font-family:Inter,Arial,sans-serif;color:#111827;background:#f6f7fb}body{margin:0;padding:10px;box-sizing:border-box;overflow-x:hidden}*{box-sizing:border-box}.card{width:100%;max-width:none;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:18px;box-shadow:0 12px 30px rgba(15,23,42,.08);padding:14px}.head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start;flex-wrap:wrap}.title{font-weight:1000;font-size:22px}.store{font-weight:900;color:#2563eb}.form{display:grid;grid-template-columns:minmax(110px,180px) minmax(170px,1fr) auto;gap:8px;margin-top:12px}.field{display:grid;gap:5px}.field label{font-size:11px;font-weight:1000;color:#475569}.field input{width:100%;min-width:0;border:1px solid #d1d5db;border-radius:8px;padding:12px 14px;font-size:16px;font-weight:900;text-transform:uppercase;outline:none}.field input:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.12)}button{align-self:end;border:0;border-radius:8px;padding:12px 18px;min-height:47px;background:#2563eb;color:#fff;font-weight:1000;cursor:pointer}.msg{margin-top:10px;color:#64748b;font-weight:800;line-height:1.45}.table-wrap{overflow-x:hidden;overflow-y:auto;margin-top:14px;width:100%;border:1px solid #e5e7eb;border-radius:8px}table{width:100%;border-collapse:collapse;background:#fff;table-layout:fixed}th,td{padding:12px;border-bottom:1px solid #e5e7eb;text-align:left;white-space:normal;word-break:break-word}th:first-child,td:first-child{width:24%;white-space:nowrap}th:nth-child(2),td:nth-child(2){width:52%}th:nth-child(3),td:nth-child(3){width:24%;white-space:nowrap}th{background:#eef2ff;color:#1e3a8a;font-weight:1000}td{font-weight:800}.empty{text-align:center;color:#64748b;padding:18px}.saldo{text-align:right}.badge{display:inline-block;border-radius:999px;background:#eff6ff;color:#1e40af;padding:6px 10px;font-weight:1000}@media(max-width:600px){.form{grid-template-columns:1fr 1fr}.form button{grid-column:1/-1;width:100%}.title{font-size:20px}th,td{padding:10px 7px;font-size:13px}}
</style></head><body><div class="card"><div class="head"><div><div class="title">Cek OH ( Sedang SO )</div><div class="msg">Kode toko tujuan sekarang dapat diisi bebas, maksimal 4 huruf/angka.</div></div></div>
<form class="form" id="f"><div class="field"><label for="store">Kode toko</label><input id="store" value="<?php echo htmlspecialchars($store, ENT_QUOTES, 'UTF-8'); ?>" maxlength="4" autocomplete="off" placeholder="M604"></div><div class="field"><label for="plu">PLU</label><input id="plu" inputmode="numeric" autocomplete="off" placeholder="Search PLU angka..." maxlength="20"></div><button id="btn" type="submit">Cari</button></form><div class="msg" id="msg">Belum ada pencarian.</div><div class="table-wrap"><table><thead><tr><th>PLU</th><th>Nama Barang</th><th class="saldo">Stok</th></tr></thead><tbody id="tbody"><tr><td colspan="3" class="empty">Isi kode toko dan PLU lalu klik Cari.</td></tr></tbody></table></div></div>
<script>
const form=document.getElementById('f'),storeInput=document.getElementById('store'),input=document.getElementById('plu'),msg=document.getElementById('msg'),tbody=document.getElementById('tbody'),btn=document.getElementById('btn');
function esc(s){return String(s??'').replace(/[&<>\'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));}
storeInput.addEventListener('input',()=>{storeInput.value=storeInput.value.toUpperCase().replace(/[^A-Z0-9]/g,'').slice(0,4);});
input.addEventListener('input',()=>{input.value=input.value.replace(/[^0-9]/g,'');});
form.addEventListener('submit',async e=>{e.preventDefault();const store=storeInput.value.toUpperCase().replace(/[^A-Z0-9]/g,'').slice(0,4),plu=input.value.replace(/[^0-9]/g,'');if(!store){msg.textContent='Kode toko wajib diisi, maksimal 4 huruf/angka.';storeInput.focus();return;}if(!plu){msg.textContent='PLU wajib angka.';input.focus();return;}btn.disabled=true;msg.textContent='Mengambil data toko '+store+'...';tbody.innerHTML='<tr><td colspan="3" class="empty">Memuat...</td></tr>';try{const r=await fetch('?api=oh_realtime_data&storeId='+encodeURIComponent(store)+'&plu='+encodeURIComponent(plu),{cache:'no-store',credentials:'same-origin'});const j=await r.json();if(!j.ok)throw new Error(j.msg||'Gagal mengambil data');const rows=Array.isArray(j.rows)?j.rows:[];msg.textContent=rows.length?('Toko '+store+' · ditemukan '+rows.length+' baris'):'Data tidak ditemukan untuk PLU '+plu+' di toko '+store;tbody.innerHTML=rows.length?rows.map(x=>`<tr><td>${esc(x.plu)}</td><td>${esc(x.nama_barang)}</td><td class="saldo">${esc(x.stok ?? x.saldo ?? '')}</td></tr>`).join(''):'<tr><td colspan="3" class="empty">Data tidak ditemukan.</td></tr>';}catch(err){msg.textContent=err.message||'Koneksi gagal';tbody.innerHTML='<tr><td colspan="3" class="empty">Gagal memuat data.</td></tr>';}finally{btn.disabled=false;}});
</script></body></html><?php
  exit;
}

/* =========================
   PO KIRIMAN PROXY (gabungan dari proxy.php)
========================= */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
  header('Access-Control-Allow-Origin: *');
  header('Access-Control-Allow-Methods: POST, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
  http_response_code(204);
  exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !isset($_GET['api'])) {
  header('Content-Type: application/json; charset=utf-8');
  header('Access-Control-Allow-Origin: *');
  header('Access-Control-Allow-Methods: POST, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');

  // SECURITY FIX 2026-05-15:
  // Semua proxy POST wajib punya session login valid. Sebelumnya endpoint ini
  // menerima storeId dari body sehingga data kadang bisa dibuka tanpa login.
  $mePost = function_exists('cookie_read_session') ? cookie_read_session() : '';
  if(!$mePost){ po_fail_json('Silakan login ulang.', 401); }
  if(function_exists('presence_touch')) presence_touch($mePost, false);

  $raw = file_get_contents('php://input');
  $input = [];
  if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $input = $decoded;
  }
  if (!$input) $input = $_POST;

  // Fallback Url_Api: kalau kiriman frontend/config kosong, tetap pakai PRD Alfastore.
  $inputBase = trim((string)($input['Url_Api'] ?? $input['url_api'] ?? $input['baseUrl'] ?? $input['base'] ?? ''));
  if($inputBase === '' || !preg_match('~^https?://~i', $inputBase)) $inputBase = 'https://app.alfastore.co.id/prd';
  $base = rtrim($inputBase, '/') . '/';
  $mode = trim($input['mode'] ?? '');
  $storeId = strtoupper(trim($input['storeId'] ?? $input['store'] ?? $input['kodetoko'] ?? $input['kode_store'] ?? ''));
  $faktur = trim($input['faktur'] ?? $input['noFaktur'] ?? $input['inSuratJalan'] ?? '');
  $plu = trim($input['plu'] ?? '');
  $region = trim($input['region'] ?? '1');
  $container = trim($input['container'] ?? $input['kontainer'] ?? '');
  $statCon = trim($input['statCon'] ?? '');
  $nik = trim($input['nik'] ?? $input['UserId'] ?? $mePost ?? '');
  $storeDate = trim($input['storeDate'] ?? date('Y-m-d'));
  $filter = trim($input['filter'] ?? 'kiriman');

  if ($storeId === '') po_fail_json('Kode toko wajib diisi.', 400);
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$storeId));
  $mePostClean = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$mePost));
  if($mePostClean !== ADMIN_STORE_ID && !hash_equals($mePostClean, $storeId)){
    po_fail_json('Akses ditolak. Kode toko tidak sesuai session login.', 403);
  }
  $url = '';
  switch ($mode) {
    case 'faktur': $url = $base . 'api/lpb/tablet/lpb/get_faktur/?storeId=' . rawurlencode($storeId) . '&filter=' . rawurlencode($filter); break;
    case 'total': if ($faktur === '') po_fail_json('Nomor faktur wajib diisi.', 400); $url = $base . 'api/lpb/tablet/lpb/TotalFaktur/?storeId=' . rawurlencode($storeId) . '&faktur=' . rawurlencode($faktur); break;
    case 'plu': if ($plu === '') po_fail_json('PLU wajib diisi.', 400); $url = $base . 'api/lpb/tablet/lpb/GetPlu/?storeId=' . rawurlencode($storeId) . '&plu=' . rawurlencode($plu) . '&region=' . rawurlencode($region); break;
    case 'detail': case 'qtykirim': case 'get_det_faktur': if ($faktur === '') po_fail_json('Nomor faktur wajib diisi.', 400); $url = $base . 'api/lpb/tablet/lpb/get_det_faktur/?storeId=' . rawurlencode($storeId) . '&faktur=' . rawurlencode($faktur); break;
    case 'detail_all': if ($faktur === '') po_fail_json('Nomor faktur wajib diisi.', 400); echo po_get_detail_all($base, $storeId, $faktur, $plu, $region); exit;
    case 'lov_container': if ($faktur === '') po_fail_json('Nomor faktur wajib diisi.', 400); $url = $base . 'api/lpb/tablet/lpb/GetLovContainer/?storeId=' . rawurlencode($storeId) . '&faktur=' . rawurlencode($faktur); if ($statCon !== '') $url .= '&statCon=' . rawurlencode($statCon); break;
    case 'compare_container': $url = $base . 'api/lpb/tablet/lpb/CompareContainer/?storeId=' . rawurlencode($storeId); if ($faktur !== '') $url .= '&faktur=' . rawurlencode($faktur); if ($container !== '') $url .= '&container=' . rawurlencode($container); break;
    case 'get_container': if ($faktur === '') po_fail_json('Nomor faktur wajib diisi.', 400); $url = $base . 'api/lpb/tablet/lpb/GetLovContainer/?storeId=' . rawurlencode($storeId) . '&faktur=' . rawurlencode($faktur); if ($statCon !== '') $url .= '&statCon=' . rawurlencode($statCon); break;
    case 'detail_container': $url = $base . 'api/lpb/tablet/lpb/CompareContainer/?storeId=' . rawurlencode($storeId); if ($faktur !== '') $url .= '&faktur=' . rawurlencode($faktur); if ($container !== '') $url .= '&container=' . rawurlencode($container); break;
    case 'detail_unchecking': if ($faktur === '') po_fail_json('Nomor faktur wajib diisi.', 400); $url = $base . 'api/lpb/tablet/lpb/detail_faktur_unchecking/?storeId=' . rawurlencode($storeId) . '&faktur=' . rawurlencode($faktur); break;
    case 'items': case 'get_items': if ($faktur === '') po_fail_json('Nomor faktur wajib diisi.', 400); $url = $base . 'api/sis/transaksi/lpb/get_items?storeId=' . rawurlencode($storeId) . '&lpbType=REGULER&noFaktur=' . rawurlencode($faktur) . '&storeDate=' . rawurlencode($storeDate); if ($plu !== '') $url .= '&plu=' . rawurlencode($plu); break;
    case 'show_sji': if ($faktur === '') po_fail_json('Nomor faktur wajib diisi.', 400); $url = $base . 'api/lpb/tablet/lpb/ShowSji/?storeId=' . rawurlencode($storeId) . '&faktur=' . rawurlencode($faktur); break;
    case 'show_cnt': if ($faktur === '') po_fail_json('Nomor faktur wajib diisi.', 400); $url = $base . 'api/lpb/tablet/lpb/ShowCnt/?storeId=' . rawurlencode($storeId) . '&faktur=' . rawurlencode($faktur); break;
    case 'show_bta': if ($faktur === '') po_fail_json('Nomor faktur wajib diisi.', 400); $url = $base . 'api/lpb/tablet/lpb/ShowBta/?storeId=' . rawurlencode($storeId) . '&faktur=' . rawurlencode($faktur) . '&nik=' . rawurlencode($nik); break;
    case 'show_bkc': if ($faktur === '') po_fail_json('Nomor faktur wajib diisi.', 400); $url = $base . 'api/lpb/tablet/lpb/ShowBkc/?storeId=' . rawurlencode($storeId) . '&faktur=' . rawurlencode($faktur); break;
    case 'show_bk': if ($faktur === '') po_fail_json('Nomor faktur wajib diisi.', 400); $url = $base . 'api/lpb/tablet/lpb/ShowBk/?storeId=' . rawurlencode($storeId) . '&faktur=' . rawurlencode($faktur); break;
    case 'final': if ($container === '') po_fail_json('Kontainer wajib diisi.', 400); $url = $base . 'api/lpb/tablet/lpb/Final/?storeId=' . rawurlencode($storeId) . '&kontainer=' . rawurlencode($container) . '&nik=' . rawurlencode($nik); echo po_http_post_json($url, []); exit;
    default: po_fail_json('Mode API tidak dikenal.', 400);
  }
  $body = po_http_get_json($url);
  $json = json_decode($body, true);
  if($mode === 'faktur' && is_array($json) && empty($json['status']) && stripos((string)($json['message'] ?? ''), 'Respon kosong') !== false){
    echo json_encode(['status'=>true, 'message'=>'TIDAK ADA FAKTUR', 'data'=>[]], JSON_UNESCAPED_UNICODE);
    exit;
  }
  if(is_array($json)){
    $rows = po_normalize_rows($json);
    // Khusus mode daftar/detail PO, tambahkan field data ter-normalisasi tanpa membuang response asli.
    // Ini menjaga kompatibilitas lama sekaligus mencegah faktur hilang karena format API nested/berubah.
    if($rows){
      if(!isset($json['data']) || !is_array($json['data'])) $json['data'] = $rows;
      $json['normalized_rows'] = count($rows);
      echo json_encode($json, JSON_UNESCAPED_UNICODE);
      exit;
    }
  }
  echo $body;
  exit;
}

function po_get_detail_all($base, $storeId, $faktur, $plu = '', $region = '1') {
  $urls = [
    'get_det_faktur' => $base . 'api/lpb/tablet/lpb/get_det_faktur/?storeId=' . rawurlencode($storeId) . '&faktur=' . rawurlencode($faktur),
    'detail_faktur_unchecking' => $base . 'api/lpb/tablet/lpb/detail_faktur_unchecking/?storeId=' . rawurlencode($storeId) . '&faktur=' . rawurlencode($faktur),
    'ShowSji' => $base . 'api/lpb/tablet/lpb/ShowSji/?storeId=' . rawurlencode($storeId) . '&faktur=' . rawurlencode($faktur),
    'ShowCnt' => $base . 'api/lpb/tablet/lpb/ShowCnt/?storeId=' . rawurlencode($storeId) . '&faktur=' . rawurlencode($faktur),
    'ShowBta' => $base . 'api/lpb/tablet/lpb/ShowBta/?storeId=' . rawurlencode($storeId) . '&faktur=' . rawurlencode($faktur) . '&nik=' . rawurlencode($storeId),
    'ShowBkc' => $base . 'api/lpb/tablet/lpb/ShowBkc/?storeId=' . rawurlencode($storeId) . '&faktur=' . rawurlencode($faktur),
    'ShowBk' => $base . 'api/lpb/tablet/lpb/ShowBk/?storeId=' . rawurlencode($storeId) . '&faktur=' . rawurlencode($faktur),
    'get_items' => $base . 'api/sis/transaksi/lpb/get_items?storeId=' . rawurlencode($storeId) . '&lpbType=REGULER&noFaktur=' . rawurlencode($faktur) . '&storeDate=' . rawurlencode(date('Y-m-d')),
    'GetLovContainer' => $base . 'api/lpb/tablet/lpb/GetLovContainer/?storeId=' . rawurlencode($storeId) . '&faktur=' . rawurlencode($faktur),
  ];
  $all = []; $debug = [];
  $responses = po_http_get_multi_json_raw($urls);
  foreach ($responses as $name => $body) {
    $debug[$name] = ['http_code' => $body['http_code'], 'empty' => $body['body'] === ''];
    if ($body['body'] === '') continue;
    $json = json_decode($body['body'], true);
    if ($json === null) continue;
    foreach (po_normalize_rows($json) as $r) { if (is_array($r)) { $r['__source'] = $name; $all[] = $r; } }
  }
  return json_encode(['status' => true, 'data' => $all, 'debug' => $debug], JSON_UNESCAPED_UNICODE);
}
function po_normalize_rows($d) {
  if (is_array($d) && array_keys($d) === range(0, count($d)-1)) return $d;
  if (!is_array($d)) return [];
  $keys = ['data','result','rows','items','detail','details','list','lists','data_detail','detil','detail_faktur','faktur_detail','dataDetil','dataDetail'];
  foreach ($keys as $k) { if (isset($d[$k]) && is_array($d[$k])) { $r = po_normalize_rows($d[$k]); if ($r) return $r; } }
  $best = [];
  foreach ($d as $v) { if (is_array($v)) { $r = po_normalize_rows($v); if (count($r) > count($best)) $best = $r; } }
  return $best;
}
function po_http_get_multi_json_raw($urls) {
  $out = [];
  if(!is_array($urls) || !$urls) return $out;
  if(!function_exists('curl_multi_init') || !function_exists('curl_init')){
    foreach($urls as $name=>$url) $out[$name] = po_http_get_json_raw($url);
    return $out;
  }
  $headers = ['Accept: application/json, text/plain, */*','Accept-Encoding: gzip, deflate, br','Connection: keep-alive','User-Agent: Mozilla/5.0 (Linux; Android 10) AppleWebKit/537.36 Chrome Safari/537.36','Referer: https://app.alfastore.co.id/','Origin: https://app.alfastore.co.id'];
  $mh = curl_multi_init();
  $handles = [];
  foreach($urls as $name=>$url){
    $u = $url . (strpos($url, '?') === false ? '?' : '&') . '_ts=' . microtime(true) . mt_rand(1000,9999);
    $ch = curl_init($u);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_CONNECTTIMEOUT=>3, CURLOPT_TIMEOUT=>9, CURLOPT_HTTPHEADER=>$headers, CURLOPT_ENCODING=>'', CURLOPT_SSL_VERIFYPEER=>false, CURLOPT_SSL_VERIFYHOST=>false, CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4]);
    curl_multi_add_handle($mh, $ch);
    $handles[$name] = $ch;
  }
  $running = null;
  do {
    $status = curl_multi_exec($mh, $running);
    if($running) curl_multi_select($mh, 0.8);
  } while($running && $status == CURLM_OK);
  foreach($handles as $name=>$ch){
    $body = curl_multi_getcontent($ch);
    $out[$name] = ['body'=>is_string($body)?trim($body):'', 'http_code'=>(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'error'=>curl_error($ch)];
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
  }
  curl_multi_close($mh);
  return $out;
}

function po_http_post_json($url, $payload = []) {
  $headers = ['Accept: application/json, text/plain, */*','Content-Type: application/json','User-Agent: Mozilla/5.0 (Linux; Android 10) AppleWebKit/537.36 Chrome Safari/537.36','Referer: https://app.alfastore.co.id/','Origin: https://app.alfastore.co.id'];
  if(function_exists('curl_init')){
    $ch = curl_init($url . (strpos($url, '?') === false ? '?' : '&') . '_ts=' . microtime(true) . mt_rand(1000,9999));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_CONNECTTIMEOUT=>4, CURLOPT_TIMEOUT=>15, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode($payload), CURLOPT_HTTPHEADER=>$headers, CURLOPT_ENCODING=>'', CURLOPT_SSL_VERIFYPEER=>false, CURLOPT_SSL_VERIFYHOST=>false, CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4]);
    $body = curl_exec($ch);
    $res = ['body'=>is_string($body)?trim($body):'', 'http_code'=>(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'error'=>curl_error($ch)];
    curl_close($ch);
  } else {
    $context = stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n", $headers),'content'=>json_encode($payload),'timeout'=>15,'ignore_errors'=>true], 'ssl'=>['verify_peer'=>false,'verify_peer_name'=>false]]);
    $body = @file_get_contents($url . (strpos($url, '?') === false ? '?' : '&') . '_ts=' . microtime(true), false, $context);
    $res = ['body'=>is_string($body)?trim($body):'', 'http_code'=>0, 'error'=>''];
  }
  if($res['body'] === '') return json_encode(['status'=>false,'message'=>'Respon kosong dari API','http_code'=>$res['http_code'],'error'=>$res['error']], JSON_UNESCAPED_UNICODE);
  json_decode($res['body'], true);
  if(json_last_error() !== JSON_ERROR_NONE) return json_encode(['status'=>true,'raw'=>$res['body']], JSON_UNESCAPED_UNICODE);
  return $res['body'];
}

function po_http_get_json_raw($url) {
  $headers = ['Accept: application/json, text/plain, */*','Accept-Encoding: gzip, deflate, br','Connection: keep-alive','User-Agent: Mozilla/5.0 (Linux; Android 10) AppleWebKit/537.36 Chrome Safari/537.36','Referer: https://app.alfastore.co.id/','Origin: https://app.alfastore.co.id'];
  $last = ['body'=>'', 'http_code'=>0, 'error'=>''];
  if (function_exists('curl_init')) {
    for ($i=0; $i<2; $i++) {
      $ch = curl_init($url . (strpos($url, '?') === false ? '?' : '&') . '_ts=' . microtime(true) . mt_rand(1000,9999));
      curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_CONNECTTIMEOUT=>4, CURLOPT_TIMEOUT=>12, CURLOPT_HTTPHEADER=>$headers, CURLOPT_ENCODING=>'', CURLOPT_SSL_VERIFYPEER=>false, CURLOPT_SSL_VERIFYHOST=>false, CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4]);
      $body = curl_exec($ch);
      $last = ['body'=>is_string($body)?trim($body):'', 'http_code'=>(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'error'=>curl_error($ch)];
      curl_close($ch);
      if ($last['body'] !== '' && $last['http_code'] !== 204) return $last;
    }
  } else {
    $context = stream_context_create(['http'=>['method'=>'GET','header'=>implode("\r\n", $headers),'timeout'=>12,'ignore_errors'=>true], 'ssl'=>['verify_peer'=>false,'verify_peer_name'=>false]]);
    $body = @file_get_contents($url . (strpos($url, '?') === false ? '?' : '&') . '_ts=' . microtime(true), false, $context);
    $last = ['body'=>is_string($body)?trim($body):'', 'http_code'=>0, 'error'=>''];
  }
  return $last;
}
function po_fail_json($message, $code = 400, $extra = []) { http_response_code($code); echo json_encode(array_merge(['status' => false, 'message' => $message], $extra)); exit; }
function po_http_get_json($url) {
  $res = po_http_get_json_raw($url);
  if ($res['body'] === '') return json_encode(['status'=>false,'message'=>'Respon kosong dari API','http_code'=>$res['http_code'],'error'=>$res['error']], JSON_UNESCAPED_UNICODE);
  json_decode($res['body'], true);
  if (json_last_error() !== JSON_ERROR_NONE) return json_encode(['status'=>true,'raw'=>$res['body']], JSON_UNESCAPED_UNICODE);
  return $res['body'];
}


/* =========================
   LABEL PRICE (merged from index1.php)
   - UI is served by index.php?page=label_price
   - Cloud API requests remain in proxy.php
   - Store ID always follows the authenticated login session
========================= */
if(!defined('LP_APP_VERSION')) define('LP_APP_VERSION', '2023.10.24.30');
if(!defined('LP_DEFAULT_STORE_ID')) define('LP_DEFAULT_STORE_ID', 'M604');
if(!defined('LP_DEFAULT_REGION')) define('LP_DEFAULT_REGION', '1');
if(!defined('LP_CLOUD_ROOT')) define('LP_CLOUD_ROOT', 'https://app.alfastore.co.id/prd');
function lp_respond_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function lp_clean_store($value): string
{
    $value = strtoupper(trim((string) $value));
    return preg_replace('/[^A-Z0-9]/', '', $value) ?: '';
}

function lp_clean_code($value, int $max = 50): string
{
    $value = trim((string) $value);
    $value = preg_replace('/[^A-Za-z0-9._-]/', '', $value) ?: '';
    return substr($value, 0, $max);
}

function lp_clean_rack($value, int $max = 50): string
{
    $value = trim((string) $value);
    $value = preg_replace('/^(?:rack|rak|lorong|shelf)\s*[:#-]?\s*/i', '', $value) ?: '';
    $value = preg_replace('/[^A-Za-z0-9 ._\/-]/', '', $value) ?: '';
    $value = preg_replace('/\s+/', ' ', $value) ?: '';
    return substr(trim($value), 0, $max);
}

function lp_clean_delete_rack($value, int $max = 50): string
{
    $rack = lp_clean_rack($value, $max);
    if ($rack === '' || preg_match('/^(?:-|--|null|none|n\/a|undefined|tidak ada|tanpa rak|no rack)$/i', $rack)) {
        return '';
    }
    return $rack;
}

function lp_clean_region($value): string
{
    $value = preg_replace('/[^0-9]/', '', (string) $value) ?: '';
    return $value === '' ? LP_DEFAULT_REGION : substr($value, 0, 3);
}

/**
 * Menyamakan perilaku pemindaian dengan APK Price Tag V2:
 * - hilangkan AIM/symbology prefix scanner seperti ]C1 atau ]E0
 * - pertahankan barcode sebagai string agar tidak berubah karena angka besar
 * - coba juga bentuk numerik tanpa nol di depan
 */
function lp_barcode_candidates($value): array
{
    $raw = trim((string) $value);
    $rawWithoutAim = preg_replace('/^\][A-Za-z][0-9]/', '', $raw) ?? $raw;
    $candidates = [];

    $append = static function ($candidate) use (&$candidates): void {
        $candidate = lp_clean_code($candidate, 50);
        if ($candidate !== '' && !isset($candidates[$candidate])) $candidates[$candidate] = $candidate;
    };

    $append($rawWithoutAim);
    if ($rawWithoutAim === $raw) $append($raw);

    $digitsOnly = preg_replace('/[^0-9]/', '', $rawWithoutAim) ?: '';
    if ($digitsOnly !== '') {
        $append($digitsOnly);
        $withoutLeadingZeroes = ltrim($digitsOnly, '0');
        $append($withoutLeadingZeroes === '' ? '0' : $withoutLeadingZeroes);
    }

    foreach (array_values($candidates) as $candidate) {
        if (preg_match('/^[0-9]+$/', $candidate)) {
            $withoutLeadingZeroes = ltrim($candidate, '0');
            $append($withoutLeadingZeroes === '' ? '0' : $withoutLeadingZeroes);
        }
    }

    return array_values($candidates);
}

function lp_read_json_input(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return $_POST ?: [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ($_POST ?: []);
}

function lp_decode_remote_json(string $body)
{
    $current = trim(preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body);
    $current = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $current) ?? $current;
    if ($current === '') return null;

    for ($i = 0; $i < 4; $i++) {
        if (!is_string($current)) break;
        $decoded = json_decode(trim($current), true);
        if (json_last_error() !== JSON_ERROR_NONE) return $i === 0 ? null : $current;
        $current = $decoded;
    }

    return lp_expand_json_strings($current);
}

function lp_expand_json_strings($value, int $depth = 0)
{
    if ($depth > 10) return $value;

    if (is_string($value)) {
        $text = trim($value);
        if ($text !== '' && (($text[0] ?? '') === '{' || ($text[0] ?? '') === '[' || ($text[0] ?? '') === '"')) {
            $decoded = json_decode($text, true);
            if (json_last_error() === JSON_ERROR_NONE && $decoded !== $value) {
                return lp_expand_json_strings($decoded, $depth + 1);
            }
        }
        return $value;
    }

    if (!is_array($value)) return $value;
    foreach ($value as $key => $child) $value[$key] = lp_expand_json_strings($child, $depth + 1);
    return $value;
}

function lp_remote_request(string $method, string $url, $jsonBody = null, array $transport = []): array
{
    if (!function_exists('curl_init')) {
        return [
            'ok' => false,
            'status' => 0,
            'body' => '',
            'json' => null,
            'error' => 'Ekstensi PHP cURL belum aktif pada hosting.',
            'content_type' => '',
        ];
    }

    $method = strtoupper($method);
    $apkMode = !empty($transport['apk_mode']);
    // Mode APK menghindari header browser (Origin/Referer/X-Requested-With).
    // Price Tag V2 hanya mengirim Accept dan Content-Type application/json.
    $headers = $apkMode ? [
        'Accept: application/json',
    ] : [
        'Accept: application/json, text/plain, */*',
        'Accept-Language: id-ID,id;q=0.9,en;q=0.7',
        'Cache-Control: no-cache',
        'Pragma: no-cache',
        'Origin: https://app.alfastore.co.id',
        'Referer: https://app.alfastore.co.id/',
        'X-Requested-With: XMLHttpRequest',
    ];

    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_ENCODING => '',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    ];
    if (!$apkMode) $options[CURLOPT_USERAGENT] = 'PriceTagV2/' . LP_APP_VERSION . ' PHP-Proxy';

    if (array_key_exists('raw_body', $transport)) {
        $payload = (string) $transport['raw_body'];
        $contentType = trim((string) ($transport['content_type'] ?? 'application/octet-stream'));
        if ($contentType !== '') $headers[] = 'Content-Type: ' . $contentType;
        $headers[] = 'Content-Length: ' . strlen($payload);
        $options[CURLOPT_HTTPHEADER] = $headers;
        $options[CURLOPT_POSTFIELDS] = $payload;
    } elseif ($jsonBody !== null) {
        $payload = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Content-Length: ' . strlen((string) $payload);
        $options[CURLOPT_HTTPHEADER] = $headers;
        $options[CURLOPT_POSTFIELDS] = $payload;
    } elseif ($method === 'POST') {
        $options[CURLOPT_POSTFIELDS] = '';
        if ($apkMode) $headers[] = 'Content-Type: application/json';
        $headers[] = 'Content-Length: 0';
        $options[CURLOPT_HTTPHEADER] = $headers;
    }

    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    $bodyText = is_string($body) ? trim($body) : '';

    return [
        'ok' => $body !== false && $error === '' && $status >= 200 && $status < 400,
        'status' => $status,
        'body' => $bodyText,
        'json' => lp_decode_remote_json($bodyText),
        'error' => $error,
        'content_type' => $contentType,
    ];
}

function lp_normalize_key($key): string
{
    return strtolower(preg_replace('/[^a-zA-Z0-9]/', '', (string) $key) ?: '');
}

function lp_is_list_array(array $array): bool
{
    if (function_exists('array_is_list')) return array_is_list($array);
    $index = 0;
    foreach ($array as $key => $_) {
        if ($key !== $index++) return false;
    }
    return true;
}

function lp_collect_objects($value, array &$result, int $depth = 0): void
{
    if ($depth > 12) return;
    if (is_string($value)) $value = lp_expand_json_strings($value, $depth);
    if (!is_array($value)) return;
    if (!lp_is_list_array($value)) $result[] = $value;
    foreach ($value as $child) lp_collect_objects($child, $result, $depth + 1);
}

function lp_collect_scalar_lists($value, array &$result, int $depth = 0): void
{
    if ($depth > 12 || !is_array($value)) return;
    if (lp_is_list_array($value) && count($value) >= 3) {
        $allScalar = true;
        foreach ($value as $child) {
            if (!is_scalar($child) && $child !== null) { $allScalar = false; break; }
        }
        if ($allScalar) $result[] = $value;
    }
    foreach ($value as $child) if (is_array($child)) lp_collect_scalar_lists($child, $result, $depth + 1);
}

function lp_meaningful_scalar($value): ?string
{
    if (!is_scalar($value)) return null;
    $text = trim((string) $value);
    if ($text === '' || preg_match('/^(?:null|none|n\/a|undefined|-)$/i', $text)) return null;
    return $text;
}

function lp_find_scalar(array $row, array $keys): ?string
{
    $wanted = [];
    foreach ($keys as $key) $wanted[lp_normalize_key($key)] = true;

    foreach ($row as $key => $value) {
        if (!isset($wanted[lp_normalize_key($key)])) continue;
        $text = lp_meaningful_scalar($value);
        if ($text !== null) return $text;
        if (is_array($value)) {
            foreach ($value as $child) {
                $text = lp_meaningful_scalar($child);
                if ($text !== null) return $text;
            }
        }
    }
    return null;
}

function lp_find_scalar_values($value, array $keys, array &$output, int $depth = 0): void
{
    if ($depth > 12 || !is_array($value)) return;
    $wanted = [];
    foreach ($keys as $key) $wanted[lp_normalize_key($key)] = true;

    foreach ($value as $key => $child) {
        if (isset($wanted[lp_normalize_key($key)])) {
            if (is_array($child)) {
                foreach ($child as $item) {
                    $text = lp_meaningful_scalar($item);
                    if ($text !== null) $output[] = $text;
                    elseif (is_array($item)) {
                        $candidate = lp_find_scalar($item, array_merge($keys, ['value', 'code', 'name', 'label']));
                        if ($candidate !== null) $output[] = $candidate;
                    }
                }
            } else {
                $text = lp_meaningful_scalar($child);
                if ($text !== null) $output[] = $text;
            }
        }
        if (is_array($child)) lp_find_scalar_values($child, $keys, $output, $depth + 1);
    }
}

function lp_remote_message($payload, string $fallback = ''): string
{
    if (is_array($payload)) {
        $objects = [];
        lp_collect_objects($payload, $objects);
        foreach ($objects as $object) {
            $message = lp_find_scalar($object, [
                'message', 'msg', 'errMsg', 'error', 'detail', 'keterangan',
                'statusMessage', 'responseMessage', 'description'
            ]);
            if ($message !== null) return $message;
        }
    }
    return $fallback;
}

function lp_normalize_rack_values(array $values): array
{
    $racks = [];
    foreach ($values as $value) {
        if (is_array($value)) continue;
        $text = trim((string) $value);
        if ($text === '') continue;

        $decoded = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            foreach ($decoded as $part) {
                $clean = lp_clean_rack($part);
                if ($clean !== '') $racks[] = $clean;
            }
            continue;
        }

        $parts = preg_split('/\s*(?:\||;|,|\\r?\\n)\s*/', $text) ?: [$text];
        foreach ($parts as $part) {
            $clean = lp_clean_rack($part);
            if ($clean !== '') $racks[] = $clean;
        }
    }
    return array_values(array_unique($racks));
}

function lp_product_rows($payload, string $input = ''): array
{
    if (!is_array($payload)) return [];

    $pluKeys = [
        'plu', 'prdcd', 'productCode', 'product_code', 'kodePlu', 'kode_plu',
        'itemCode', 'item_code', 'kode', 'sku', 'pluNo', 'plu_no', 'productId',
        'product_id', 'itemNo', 'item_no', 'kodeProduk', 'kode_produk'
    ];
    $barcodeKeys = [
        'barcode', 'barCode', 'bar_code', 'bcode', 'ean', 'ean13', 'upc',
        'kodeBarcode', 'kode_barcode', 'barcodeNo', 'barcode_no'
    ];
    $descriptionKeys = [
        'descp', 'descp1', 'description', 'description1', 'desc', 'desc1',
        'deskripsi', 'namaBarang', 'nama_barang', 'namaProduk', 'nama_produk',
        'nama', 'name', 'productName', 'product_name', 'productDescription',
        'product_description', 'prdName', 'prdDesc', 'prd_desc', 'longDesc'
    ];
    // APK menyimpan field "rack" sebagai kode rak. Field "rackname" hanya
    // keterangan tampilan dan tidak boleh ikut dikirim sebagai PLU|RAK.
    $rackCodeKeys = [
        'rack', 'rak', 'rh', 'homeRack', 'home_rack', 'shelf', 'slv',
        'lokasi', 'location', 'bin', 'lorong', 'rackNo', 'rack_no',
        'rackNumber', 'rack_number', 'rackCode', 'rack_code', 'noRak',
        'no_rak', 'nomorRak', 'nomor_rak', 'kodeRak', 'kode_rak', 'rackId',
        'rack_id', 'shelfNo', 'shelf_no', 'displayRack', 'display_rack',
        'rakDisplay', 'rak_display', 'loc', 'locCode', 'loc_code'
    ];
    $rackNameKeys = [
        'rackName', 'rack_name', 'rackname', 'rakName', 'rak_name', 'rakname',
        'rackDescription', 'rack_description', 'namaRak', 'nama_rak'
    ];

    $objects = [];
    lp_collect_objects($payload, $objects);
    $rows = [];
    $seen = [];

    foreach ($objects as $object) {
        $plu = lp_find_scalar($object, $pluKeys);
        $barcode = lp_find_scalar($object, $barcodeKeys);
        $description = lp_find_scalar($object, $descriptionKeys);

        $rackCandidates = [];
        lp_find_scalar_values($object, $rackCodeKeys, $rackCandidates);

        // Fallback generik hanya menerima field kode, bukan nama/deskripsi rak.
        if (!$rackCandidates) {
            foreach ($object as $key => $value) {
                $normalizedKey = lp_normalize_key($key);
                if (!preg_match('/(?:rack|rak|lorong|shelf|location|lokasi)/i', (string) $key)) continue;
                if (preg_match('/(?:name|nama|desc|description)/i', $normalizedKey)) continue;
                if (is_array($value)) {
                    foreach ($value as $item) {
                        $text = lp_meaningful_scalar($item);
                        if ($text !== null) $rackCandidates[] = $text;
                    }
                } else {
                    $text = lp_meaningful_scalar($value);
                    if ($text !== null) $rackCandidates[] = $text;
                }
            }
        }

        // APK resmi selalu memakai field "rack" sebagai kode yang dikirim
        // kembali ke insert_lprice. rackName hanya label tampilan dan tidak
        // boleh diperlakukan sebagai kode rak.
        $racks = lp_normalize_rack_values($rackCandidates);

        $hasIdentity = ($plu !== null || $barcode !== null);
        if (!$hasIdentity && ($description === null || !$racks)) continue;

        $plu = $plu ?: ($barcode ?: $input);
        $barcode = $barcode ?: $input;
        $description = $description ?: 'Produk ditemukan';
        if (!$racks) $racks = [''];

        foreach ($racks as $rack) {
            $signature = strtoupper($plu . '|' . $rack . '|' . $description);
            if (isset($seen[$signature])) continue;
            $seen[$signature] = true;
            $rows[] = [
                'plu' => $plu,
                'barcode' => $barcode,
                'descp' => $description,
                'rack' => $rack,
            ];
        }
    }

    if (!$rows) {
        $lists = [];
        lp_collect_scalar_lists($payload, $lists);
        foreach ($lists as $list) {
            $values = array_values(array_filter(array_map(static function ($value) {
                return lp_meaningful_scalar($value);
            }, $list), static function ($value) { return $value !== null; }));
            if (count($values) < 3) continue;

            $description = null;
            foreach ($values as $value) {
                if (preg_match('/[A-Za-z]{3}/', $value) && strlen($value) > 4) { $description = $value; break; }
            }
            if ($description === null) continue;

            $codes = array_values(array_filter($values, static function ($value) use ($description) {
                return $value !== $description && preg_match('/^[A-Za-z0-9._\/-]+$/', $value);
            }));
            if (count($codes) < 2) continue;
            $plu = $codes[0];
            $rackOptions = array_slice($codes, 1);
            usort($rackOptions, static function ($a, $b) { return strlen((string) $a) <=> strlen((string) $b); });
            $rack = lp_clean_rack($rackOptions[0] ?? '');
            $barcode = $input !== '' ? $input : $plu;
            foreach ($codes as $candidateCode) {
                if (preg_match('/^[0-9]{8,14}$/', $candidateCode)) $barcode = $candidateCode;
            }
            if ($rack === '') continue;
            $signature = strtoupper($plu . '|' . $rack . '|' . $description);
            if (isset($seen[$signature])) continue;
            $seen[$signature] = true;
            $rows[] = ['plu' => $plu, 'barcode' => $barcode, 'descp' => $description, 'rack' => $rack];
        }
    }

    return $rows;
}

function lp_endpoint_url(string $action, array $params): string
{
    $store = lp_clean_store($params['store'] ?? LP_DEFAULT_STORE_ID);
    if ($store === '') $store = LP_DEFAULT_STORE_ID;

    switch ($action) {
        case 'status_store':
            return LP_CLOUD_ROOT . '/api/sis/master/status_toko/?' . http_build_query(['storeId' => $store]);

        case 'scan':
            return LP_CLOUD_ROOT . '/api/mob/tablet/pricetag/check_scan/?' . http_build_query([
                'storeid' => $store,
                'barcode' => lp_clean_code($params['barcode'] ?? '', 50),
                'region' => lp_clean_region($params['region'] ?? LP_DEFAULT_REGION),
            ]);

        case 'list':
            return LP_CLOUD_ROOT . '/api/mob/tablet/pricetag/get_lprice/?' . http_build_query(['storeId' => $store]);

        case 'clear':
            return LP_CLOUD_ROOT . '/api/mob/tablet/pricetag/clear_lprice/?' . http_build_query(['storeid' => $store]);

        case 'delete':
            $query = [
                'storeid' => $store,
                'plu' => lp_clean_code($params['plu'] ?? '', 50),
            ];
            $rack = lp_clean_delete_rack($params['rack'] ?? '', 50);
            // Jika rak tidak tersedia, parameter rack benar-benar dihilangkan
            // supaya endpoint memproses penghapusan berdasarkan PLU.
            if ($rack !== '') $query['rack'] = $rack;
            return LP_CLOUD_ROOT . '/api/mob/tablet/pricetag/delete_plu/?' . http_build_query($query);

        case 'insert':
            return LP_CLOUD_ROOT . '/api/mob/tablet/pricetag/insert_lprice/?' . http_build_query(['storeid' => $store]);
    }

    return LP_CLOUD_ROOT;
}


function lp_canonical_plu($value): string
{
    $plu = strtoupper(lp_clean_code($value, 50));
    if ($plu !== '' && preg_match('/^[0-9]+$/', $plu)) {
        $plu = ltrim($plu, '0');
        if ($plu === '') $plu = '0';
    }
    return $plu;
}

function lp_canonical_rack($value): string
{
    $rack = strtoupper(lp_clean_rack($value, 50));
    $rack = preg_replace('/\s+/', '', $rack) ?: '';
    if ($rack !== '' && preg_match('/^[0-9]+$/', $rack)) {
        $rack = ltrim($rack, '0');
        if ($rack === '') $rack = '0';
    }
    return $rack;
}

function lp_row_pair_key(array $row): string
{
    $plu = lp_canonical_plu($row['plu'] ?? '');
    $rack = lp_canonical_rack($row['rack'] ?? '');
    return ($plu !== '' && $rack !== '') ? $plu . '|' . $rack : '';
}

function lp_rack_aliases($value): array
{
    $raw = strtoupper(lp_clean_rack($value, 80));
    $aliases = [];
    $append = static function ($candidate) use (&$aliases): void {
        $candidate = lp_canonical_rack($candidate);
        if ($candidate !== '') $aliases[$candidate] = true;
    };

    $append($raw);
    $firstToken = preg_split('/[\s\(\[\{]+/', $raw, 2);
    if (is_array($firstToken) && isset($firstToken[0])) $append($firstToken[0]);

    if (preg_match('/^([A-Z0-9._\/-]{1,24})\s*[-:]\s+/', $raw, $match)) {
        $append($match[1]);
    }
    return array_keys($aliases);
}

function lp_racks_equivalent($left, $right): bool
{
    $leftAliases = lp_rack_aliases($left);
    $rightAliases = lp_rack_aliases($right);
    if (!$leftAliases || !$rightAliases) return false;
    return (bool) array_intersect($leftAliases, $rightAliases);
}

function lp_row_is_present(array $rows, string $plu, string $rack): bool
{
    $wantedPlu = lp_canonical_plu($plu);
    if ($wantedPlu === '' || lp_canonical_rack($rack) === '') return false;
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        if (lp_canonical_plu($row['plu'] ?? '') !== $wantedPlu) continue;
        if (lp_racks_equivalent($row['rack'] ?? '', $rack)) return true;
    }
    return false;
}

function lp_row_plu_is_present(array $rows, string $plu): bool
{
    $wanted = lp_canonical_plu($plu);
    if ($wanted === '') return false;
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        if (lp_canonical_plu($row['plu'] ?? '') === $wanted) return true;
    }
    return false;
}

function lp_delete_target_is_present(array $rows, string $plu, string $rack): bool
{
    return $rack !== '' ? lp_row_is_present($rows, $plu, $rack) : lp_row_plu_is_present($rows, $plu);
}

function lp_fetch_lprice_remote(string $store): array
{
    $url = lp_endpoint_url('list', ['store' => $store]) . '&_rt=' . rawurlencode((string) round(microtime(true) * 1000));
    $remote = lp_remote_request('GET', $url);
    return [
        'ok' => $remote['ok'] && is_array($remote['json']),
        'status' => $remote['status'],
        'rows' => lp_product_rows($remote['json']),
        'remote' => $remote,
    ];
}

function lp_delete_compat_url(string $store, string $plu, string $rackMode = 'omit', string $rack = '', string $storeKey = 'storeid'): string
{
    $store = lp_clean_store($store);
    if ($store === '') $store = LP_DEFAULT_STORE_ID;

    $query = [
        $storeKey === 'storeId' ? 'storeId' : 'storeid' => $store,
        'plu' => lp_clean_code($plu, 50),
    ];

    if ($rackMode === 'value') {
        $query['rack'] = lp_clean_delete_rack($rack, 50);
    } elseif ($rackMode === 'blank') {
        $query['rack'] = '';
    } elseif ($rackMode === 'all') {
        $query['rack'] = 'ALL';
    }

    return LP_CLOUD_ROOT . '/api/mob/tablet/pricetag/delete_plu/?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

function lp_delete_plu_candidates(string $plu): array
{
    $candidates = [];
    foreach ([$plu, strtoupper($plu), strtolower($plu), lp_canonical_plu($plu)] as $candidate) {
        $candidate = lp_clean_code($candidate, 50);
        if ($candidate !== '' && !in_array($candidate, $candidates, true)) $candidates[] = $candidate;
    }
    return $candidates;
}

function lp_delete_rack_candidates(string $rack): array
{
    $candidates = [];
    foreach ([$rack, lp_clean_rack($rack), strtoupper($rack), strtolower($rack), lp_canonical_rack($rack)] as $candidate) {
        $candidate = lp_clean_delete_rack($candidate, 50);
        if ($candidate !== '' && !in_array($candidate, $candidates, true)) $candidates[] = $candidate;
    }
    return $candidates;
}

function lp_matching_server_targets(array $rows, string $plu): array
{
    $wanted = lp_canonical_plu($plu);
    $targets = [];
    $seen = [];
    if ($wanted === '') return [];

    foreach ($rows as $row) {
        if (!is_array($row) || lp_canonical_plu($row['plu'] ?? '') !== $wanted) continue;
        $serverPlu = lp_clean_code($row['plu'] ?? $plu, 50);
        $serverRack = lp_clean_delete_rack($row['rack'] ?? '', 50);
        if ($serverPlu === '' || $serverRack === '') continue;
        $key = strtoupper($serverPlu . '|' . lp_canonical_rack($serverRack));
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $targets[] = ['plu' => $serverPlu, 'rack' => $serverRack];
    }
    return $targets;
}

function lp_append_delete_attempt(array &$attempts, string $method, string $url, string $mode, string $plu, string $rack = '', string $variant = ''): array
{
    $remote = lp_remote_request($method, $url);
    $attempts[] = [
        'try' => count($attempts) + 1,
        'mode' => $mode,
        'method' => strtoupper($method),
        'variant' => $variant,
        'plu' => $plu,
        'rack' => $rack,
        'status' => $remote['status'],
        'http_ok' => $remote['ok'],
        'message' => lp_remote_message($remote['json'], ''),
        'error' => $remote['error'],
    ];
    return $remote;
}

function lp_verify_delete_remote(string $store, string $plu, string $rack, int $checks = 4): array
{
    $delays = [250000, 500000, 900000, 1500000, 2300000, 3200000];
    $latest = ['ok' => false, 'status' => 0, 'rows' => [], 'remote' => []];

    for ($i = 0; $i < max(1, min($checks, count($delays))); $i++) {
        usleep($delays[$i]);
        $latest = lp_fetch_lprice_remote($store);
        if ($latest['ok'] && !lp_delete_target_is_present($latest['rows'], $plu, $rack)) {
            $latest['deleted'] = true;
            return $latest;
        }
    }

    $latest['deleted'] = $latest['ok'] && !lp_delete_target_is_present($latest['rows'], $plu, $rack);
    return $latest;
}

function lp_delete_row_remote(string $store, string $plu, string $rack): array
{
    $store = lp_clean_store($store);
    if ($store === '') $store = LP_DEFAULT_STORE_ID;
    $plu = lp_clean_code($plu, 50);
    $rack = lp_clean_delete_rack($rack, 50);
    $mode = $rack === '' ? 'plu' : 'rack';
    $before = lp_fetch_lprice_remote($store);

    if ($before['ok'] && !lp_delete_target_is_present($before['rows'], $plu, $rack)) {
        return [
            'ok' => true,
            'verified' => true,
            'mode' => $mode,
            'status' => 200,
            'message' => $mode === 'rack'
                ? 'PLU pada rak tersebut memang sudah tidak ada di server.'
                : 'PLU memang sudah tidak ada di server.',
            'rows' => $before['rows'],
            'remote' => $before['remote'],
            'attempts' => [],
        ];
    }

    $attempts = [];
    $lastRemote = ['ok' => false, 'status' => 0, 'json' => null, 'body' => '', 'error' => '', 'content_type' => ''];
    $latestRows = $before['rows'] ?? [];
    $pluCandidates = lp_delete_plu_candidates($plu);

    if ($mode === 'rack') {
        $rackCandidates = lp_delete_rack_candidates($rack);

        // Jalur utama sesuai APK: DELETE dengan storeid, plu, dan rack.
        foreach ($pluCandidates as $tryPlu) {
            foreach ($rackCandidates as $tryRack) {
                $lastRemote = lp_append_delete_attempt(
                    $attempts,
                    'DELETE',
                    lp_delete_compat_url($store, $tryPlu, 'value', $tryRack, 'storeid'),
                    'rack',
                    $tryPlu,
                    $tryRack,
                    'delete-storeid-rack'
                );
            }
        }

        $verification = lp_verify_delete_remote($store, $plu, $rack, 4);
        if ($verification['ok']) $latestRows = $verification['rows'];
        if (!empty($verification['deleted'])) {
            return [
                'ok' => true,
                'verified' => true,
                'mode' => 'rack',
                'status' => $lastRemote['status'],
                'message' => 'PLU berhasil dihapus dari rak dan sudah terverifikasi dari server.',
                'rows' => $latestRows,
                'remote' => $lastRemote,
                'attempts' => $attempts,
            ];
        }

        // Beberapa versi gateway menerima POST atau nama parameter storeId.
        foreach ($pluCandidates as $tryPlu) {
            foreach ($rackCandidates as $tryRack) {
                foreach ([['POST', 'storeid'], ['DELETE', 'storeId'], ['POST', 'storeId']] as $variant) {
                    [$method, $storeKey] = $variant;
                    $lastRemote = lp_append_delete_attempt(
                        $attempts,
                        $method,
                        lp_delete_compat_url($store, $tryPlu, 'value', $tryRack, $storeKey),
                        'rack_compat',
                        $tryPlu,
                        $tryRack,
                        strtolower($method) . '-' . $storeKey . '-rack'
                    );
                }
            }
        }

        $verification = lp_verify_delete_remote($store, $plu, $rack, 5);
        if ($verification['ok']) $latestRows = $verification['rows'];
        if (!empty($verification['deleted'])) {
            return [
                'ok' => true,
                'verified' => true,
                'mode' => 'rack',
                'status' => $lastRemote['status'],
                'message' => 'PLU berhasil dihapus dari rak dan sudah terverifikasi dari server.',
                'rows' => $latestRows,
                'remote' => $lastRemote,
                'attempts' => $attempts,
            ];
        }
    } else {
        // Tanpa rak di UI: ambil seluruh pasangan PLU+rak yang sebenarnya
        // tersimpan di server, lalu hapus satu per satu. Ini jalur paling pasti.
        $serverTargets = lp_matching_server_targets($latestRows, $plu);
        foreach (array_slice($serverTargets, 0, 100) as $target) {
            $lastRemote = lp_append_delete_attempt(
                $attempts,
                'DELETE',
                lp_delete_compat_url($store, $target['plu'], 'value', $target['rack'], 'storeid'),
                'plu_each_rack',
                $target['plu'],
                $target['rack'],
                'delete-known-server-rack'
            );
        }

        if ($serverTargets) {
            $verification = lp_verify_delete_remote($store, $plu, '', 4);
            if ($verification['ok']) $latestRows = $verification['rows'];
            if (!empty($verification['deleted'])) {
                return [
                    'ok' => true,
                    'verified' => true,
                    'mode' => 'plu',
                    'status' => $lastRemote['status'],
                    'message' => 'Semua data dengan PLU tersebut berhasil dihapus dan sudah terverifikasi dari server.',
                    'rows' => $latestRows,
                    'remote' => $lastRemote,
                    'attempts' => $attempts,
                ];
            }

            // Ulangi target yang masih tersisa memakai POST untuk kompatibilitas gateway.
            foreach (array_slice(lp_matching_server_targets($latestRows, $plu), 0, 100) as $target) {
                $lastRemote = lp_append_delete_attempt(
                    $attempts,
                    'POST',
                    lp_delete_compat_url($store, $target['plu'], 'value', $target['rack'], 'storeid'),
                    'plu_each_rack_compat',
                    $target['plu'],
                    $target['rack'],
                    'post-known-server-rack'
                );
            }
        }

        // Fallback PLU murni: tanpa rack, rack kosong, dan rack=ALL.
        // HTTP 204 tidak langsung dianggap berhasil; hasil tetap wajib hilang dari get_lprice.
        $variants = [
            ['DELETE', 'storeid', 'omit'],
            ['DELETE', 'storeid', 'blank'],
            ['DELETE', 'storeid', 'all'],
            ['POST', 'storeid', 'omit'],
            ['POST', 'storeid', 'blank'],
            ['POST', 'storeid', 'all'],
            ['DELETE', 'storeId', 'omit'],
            ['DELETE', 'storeId', 'blank'],
            ['POST', 'storeId', 'omit'],
        ];

        foreach ($pluCandidates as $tryPlu) {
            foreach ($variants as $variant) {
                [$method, $storeKey, $rackMode] = $variant;
                $lastRemote = lp_append_delete_attempt(
                    $attempts,
                    $method,
                    lp_delete_compat_url($store, $tryPlu, $rackMode, '', $storeKey),
                    'plu_compat',
                    $tryPlu,
                    $rackMode === 'all' ? 'ALL' : '',
                    strtolower($method) . '-' . $storeKey . '-' . $rackMode
                );
            }
        }

        $verification = lp_verify_delete_remote($store, $plu, '', 6);
        if ($verification['ok']) $latestRows = $verification['rows'];
        if (!empty($verification['deleted'])) {
            return [
                'ok' => true,
                'verified' => true,
                'mode' => 'plu',
                'status' => $lastRemote['status'],
                'message' => 'Semua data dengan PLU tersebut berhasil dihapus dan sudah terverifikasi dari server.',
                'rows' => $latestRows,
                'remote' => $lastRemote,
                'attempts' => $attempts,
            ];
        }
    }

    return [
        'ok' => false,
        'verified' => false,
        'mode' => $mode,
        'status' => $lastRemote['status'],
        'message' => $mode === 'rack'
            ? 'Server menerima permintaan, tetapi PLU masih terdeteksi pada rak tersebut setelah verifikasi ulang.'
            : 'Server menerima permintaan, tetapi PLU masih terdeteksi setelah seluruh metode penghapusan dan verifikasi ulang.',
        'rows' => $latestRows,
        'remote' => $lastRemote,
        'attempts' => $attempts,
    ];
}

function lp_insert_targets_present(array $serverRows, array $targetRows): bool
{
    $hasTarget = false;
    foreach ($targetRows as $target) {
        if (!is_array($target)) continue;
        $plu = lp_clean_code($target['plu'] ?? '', 50);
        $rack = lp_clean_rack($target['rack'] ?? '', 50);
        if ($plu === '' || $rack === '') continue;
        $hasTarget = true;
        if (!lp_row_is_present($serverRows, $plu, $rack)) return false;
    }
    return $hasTarget;
}

function lp_verify_insert_remote(string $store, array $targetRows, int $checks = 6): array
{
    // get_lprice kadang baru berubah beberapa detik setelah insert_lprice 200.
    // Cek langsung, lalu gunakan backoff agar tidak salah menyatakan gagal.
    $delays = [0, 300000, 700000, 1300000, 2200000, 3500000];
    $limit = max(1, min($checks, count($delays)));
    $latest = ['ok'=>false,'status'=>0,'rows'=>[],'remote'=>[],'saved'=>false];

    for ($i = 0; $i < $limit; $i++) {
        if ($delays[$i] > 0) usleep($delays[$i]);
        $latest = lp_fetch_lprice_remote($store);
        if ($latest['ok'] && lp_insert_targets_present($latest['rows'], $targetRows)) {
            $latest['saved'] = true;
            return $latest;
        }
    }

    $latest['saved'] = $latest['ok'] && lp_insert_targets_present($latest['rows'], $targetRows);
    return $latest;
}

function lp_insert_rows_remote(string $store, array $rows): array
{
    if (function_exists('set_time_limit')) @set_time_limit(90);

    $params = [];
    $targets = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $plu = lp_clean_code($row['plu'] ?? '', 50);
        $rack = lp_clean_rack($row['rack'] ?? '', 50);
        if ($plu === '' || $rack === '') continue;

        $pair = lp_canonical_plu($plu) . '|' . lp_canonical_rack($rack);
        if ($pair === '|') continue;

        // Bentuk payload resmi APK Price Tag V2 adalah array object, bukan
        // array string "PLU|RAK": {"params":[{"plu":"...","rack":"..."}]}
        $params[$pair] = ['plu'=>$plu, 'rack'=>$rack];
        $targets[$pair] = ['plu'=>$plu, 'rack'=>$rack];
    }

    $params = array_values($params);
    $targets = array_values($targets);
    if (!$params) {
        return [
            'attempted'=>false,'ok'=>false,'accepted'=>false,'verified'=>false,'status'=>0,
            'message'=>'Kode rak belum terbaca dari field rack API resmi.',
            'remote'=>null,'attempts'=>[],'rows'=>[]
        ];
    }

    // Hindari insert ulang bila pasangan PLU+rak sudah terlihat di daftar server.
    $before = lp_fetch_lprice_remote($store);
    if ($before['ok'] && lp_insert_targets_present($before['rows'], $targets)) {
        return [
            'attempted'=>true,'ok'=>true,'accepted'=>true,'verified'=>true,'status'=>200,
            'message'=>'LPRICE sudah tersimpan dan terlihat pada daftar server.',
            'remote'=>$before['remote'],'attempts'=>[],'rows'=>$before['rows']
        ];
    }

    $url = lp_endpoint_url('insert', ['store'=>$store]);
    $officialBody = ['params'=>$params];
    $officialRaw = json_encode($officialBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($officialRaw)) {
        return [
            'attempted'=>false,'ok'=>false,'accepted'=>false,'verified'=>false,'status'=>0,
            'message'=>'Payload LPRICE tidak dapat dibentuk.',
            'remote'=>null,'attempts'=>[],'rows'=>is_array($before['rows'] ?? null) ? $before['rows'] : []
        ];
    }

    // Sama seperti APK: satu PUT, raw UTF-8 JSON, Content-Type dan Accept
    // application/json. Jangan kirim ulang dengan format lain karena request
    // HTTP 200 dapat diterima tetapi isi lama (array string) diabaikan backend.
    $remote = lp_remote_request('PUT', $url, null, [
        'raw_body'=>$officialRaw,
        'content_type'=>'application/json',
        'apk_mode'=>true,
    ]);

    $accepted = ($remote['status'] === 200 && $remote['error'] === '' && $remote['json'] !== null);
    $attempts = [[
        'try'=>1,
        'variant'=>'put-apk-object-official',
        'method'=>'PUT',
        'status'=>$remote['status'],
        'http_ok'=>$remote['ok'],
        'accepted'=>$accepted,
        'payload_count'=>count($params),
        'message'=>lp_remote_message($remote['json'], ''),
        'error'=>$remote['error'],
    ]];

    $latestRows = is_array($before['rows'] ?? null) ? $before['rows'] : [];
    if ($accepted) {
        $verification = lp_verify_insert_remote($store, $targets, 6);
        if ($verification['ok']) $latestRows = $verification['rows'];
        if (!empty($verification['saved'])) {
            return [
                'attempted'=>true,'ok'=>true,'accepted'=>true,'verified'=>true,
                'status'=>200,
                'message'=>'LPRICE berhasil disimpan dengan format resmi APK dan sudah terlihat pada daftar server.',
                'remote'=>$remote,'attempts'=>$attempts,'rows'=>$latestRows,
            ];
        }

        // APK resmi menyatakan sukses berdasarkan HTTP 200 + respons JSON.
        // get_lprice dapat terlambat tersinkron sehingga tidak boleh mengubah
        // request yang sudah diterima menjadi popup BELUM TERSIMPAN.
        return [
            'attempted'=>true,'ok'=>true,'accepted'=>true,'verified'=>false,
            'status'=>200,
            'message'=>'LPRICE telah diterima server dengan format resmi APK. Daftar server sedang melakukan sinkronisasi.',
            'remote'=>$remote,'attempts'=>$attempts,'rows'=>$latestRows,
        ];
    }

    $fallback = lp_remote_message($remote['json'], 'Server menolak penyimpanan LPRICE.');
    if ($remote['status'] === 200 && $remote['json'] === null) {
        $fallback = 'HTTP 200 diterima, tetapi respons server bukan JSON seperti yang diwajibkan APK.';
    } elseif ($remote['status'] === 204) {
        $fallback = 'Server mengembalikan HTTP 204 (tidak ada data), sehingga insert belum dinyatakan berhasil.';
    } elseif ($remote['error'] !== '') {
        $fallback = 'Koneksi ke insert_lprice gagal: ' . $remote['error'];
    }

    return [
        'attempted'=>true,'ok'=>false,'accepted'=>false,'verified'=>false,
        'status'=>$remote['status'],
        'message'=>$fallback,
        'remote'=>$remote,'attempts'=>$attempts,'rows'=>$latestRows,
    ];
}

function lp_handle_action($action, $store){
  $action = lp_clean_code($action, 30);
  $input = lp_read_json_input();
  $store = lp_clean_store($store);
  if($store === '') lp_respond_json(['ok'=>false,'message'=>'Sesi toko tidak valid. Silakan login ulang.'], 401);
if ($action === 'status_store') {
        $remote = lp_remote_request('GET', lp_endpoint_url('status_store', ['store' => $store]));
        lp_respond_json([
            'ok' => $remote['ok'],
            'message' => lp_remote_message($remote['json'], $remote['ok'] ? 'Status toko berhasil diambil.' : 'Status toko gagal diambil.'),
            'status' => $remote['status'],
            'data' => $remote['json'],
            'raw' => $remote['json'] === null ? substr($remote['body'], 0, 1200) : null,
            'error' => $remote['error'],
        ], $remote['ok'] ? 200 : 502);
    }

    if ($action === 'login') {
        $userId = lp_clean_code($input['userId'] ?? '', 50);
        $password = (string) ($input['password'] ?? '');
        $storeDate = trim((string) ($input['storeDate'] ?? date('d/m/Y')));
        $timeTx = trim((string) ($input['timeTx'] ?? date('H:i:s')));

        if ($userId === '' || $password === '') {
            lp_respond_json(['ok' => false, 'message' => 'User ID dan password wajib diisi.'], 422);
        }

        $body = [
            'timeTx' => $timeTx,
            'userId' => $userId,
            'storeId' => $store,
            'password' => base64_encode($password),
            'storeDate' => $storeDate,
        ];
        $remote = lp_remote_request('POST', LP_CLOUD_ROOT . '/api/sis/login/', $body);

        lp_respond_json([
            'ok' => $remote['ok'],
            'message' => lp_remote_message($remote['json'], $remote['ok'] ? 'Login berhasil diproses.' : 'Login ditolak atau server tidak tersedia.'),
            'status' => $remote['status'],
            'data' => $remote['json'],
            'raw' => $remote['json'] === null ? substr($remote['body'], 0, 1200) : null,
            'error' => $remote['error'],
        ], $remote['ok'] ? 200 : 502);
    }

    if ($action === 'scan') {
        if (function_exists('set_time_limit')) @set_time_limit(120);

        $barcodeInput = trim((string)($input['barcode'] ?? ''));
        $region = lp_clean_region($input['region'] ?? LP_DEFAULT_REGION);
        $barcodeCandidates = lp_barcode_candidates($barcodeInput);
        if (!$barcodeCandidates) lp_respond_json(['ok' => false, 'message' => 'Barcode atau PLU wajib diisi.'], 422);

        $attempts = [];
        $rows = [];
        $selectedBarcode = $barcodeCandidates[0];
        $remote = ['ok'=>false,'status'=>0,'json'=>null,'body'=>'','error'=>''];

        foreach ($barcodeCandidates as $candidate) {
            $scanUrl = lp_endpoint_url('scan', [
                'store' => $store,
                'barcode' => $candidate,
                'region' => $region,
            ]);

            foreach (['POST', 'GET'] as $method) {
                $current = lp_remote_request($method, $scanUrl);
                $currentRows = lp_product_rows($current['json'], $candidate);
                $attempts[] = [
                    'phase'=>'scan',
                    'barcode'=>$candidate,
                    'method'=>$method,
                    'status'=>$current['status'],
                    'found'=>count($currentRows) > 0,
                    'error'=>$current['error'],
                ];

                $remote = $current;
                if ($currentRows) {
                    $rows = $currentRows;
                    $selectedBarcode = $candidate;
                    break 2;
                }
            }
        }

        $success = count($rows) > 0;
        $save = ['attempted' => false, 'ok' => false, 'verified'=>false, 'status' => 0, 'message' => '', 'rows'=>[], 'attempts'=>[]];
        if ($success) $save = lp_insert_rows_remote($store, $rows);

        if ($success && !empty($save['ok']) && !empty($save['verified'])) {
            $message = 'PLU, deskripsi, dan kode rak ditemukan lalu tersimpan di server.';
        } elseif ($success && !empty($save['ok']) && !empty($save['accepted'])) {
            $message = (string)($save['message'] ?? 'LPRICE diterima server dan sedang disinkronkan.');
        } elseif ($success && empty($save['attempted'])) {
            $message = 'Produk ditemukan, tetapi kode rack resmi belum terbaca. Data belum dikirim ke server.';
        } elseif ($success) {
            $message = (string)($save['message'] ?? 'Produk ditemukan, tetapi server menolak penyimpanan.');
        } else {
            $fallback = $remote['ok']
                ? 'Barcode sudah diperiksa dalam bentuk asli dan tanpa nol di depan, tetapi produk tidak ditemukan.'
                : 'API check_scan gagal diakses.';
            $message = lp_remote_message($remote['json'], $fallback);
        }

        $savedOk = $success && !empty($save['ok']);
        lp_respond_json([
            'ok' => $savedOk,
            'found' => $success,
            'message' => $message,
            'status' => $remote['status'],
            'input_barcode' => $barcodeInput,
            'scan_barcode' => $selectedBarcode,
            'barcode_candidates' => $barcodeCandidates,
            'rows' => $rows,
            'server_rows' => is_array($save['rows'] ?? null) ? $save['rows'] : [],
            'saved' => $savedOk,
            'accepted' => !empty($save['accepted']),
            'verified' => !empty($save['verified']),
            'save_status' => (int) ($save['status'] ?? 0),
            'save_message' => (string) ($save['message'] ?? ''),
            'attempts' => array_merge($attempts, is_array($save['attempts'] ?? null) ? $save['attempts'] : []),
            'data' => $remote['json'],
            'raw' => $remote['json'] === null ? substr((string)$remote['body'], 0, 1200) : null,
            'error' => $remote['error'],
        ], $savedOk ? 200 : ($success ? 409 : 502));
    }

    if ($action === 'list') {
        $remote = lp_remote_request('GET', lp_endpoint_url('list', ['store' => $store]));
        $rows = lp_product_rows($remote['json']);
        lp_respond_json([
            'ok' => $remote['ok'],
            'message' => lp_remote_message($remote['json'], $remote['ok'] ? 'Daftar LPRICE berhasil dimuat.' : 'Daftar LPRICE gagal dimuat.'),
            'status' => $remote['status'],
            'rows' => $rows,
            'data' => $remote['json'],
            'raw' => $remote['json'] === null ? substr($remote['body'], 0, 1200) : null,
            'error' => $remote['error'],
        ], $remote['ok'] ? 200 : 502);
    }

    if ($action === 'insert') {
        $items = $input['items'] ?? [];
        if (!is_array($items)) $items = [];
        $validRows = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $plu = lp_clean_code($item['plu'] ?? '', 50);
            $rack = lp_clean_rack($item['rack'] ?? '', 50);
            if ($plu !== '' && $rack !== '') $validRows[] = ['plu'=>$plu,'rack'=>$rack];
        }
        if (!$validRows) lp_respond_json(['ok' => false, 'message' => 'PLU dan rak wajib diisi sebelum disimpan.'], 422);

        $save = lp_insert_rows_remote($store, $validRows);
        $remote = is_array($save['remote'] ?? null) ? $save['remote'] : [];
        lp_respond_json([
            'ok' => !empty($save['ok']),
            'accepted' => !empty($save['accepted']),
            'verified' => !empty($save['verified']),
            'message' => (string)($save['message'] ?? 'Gagal menyimpan LPRICE.'),
            'status' => (int)($save['status'] ?? 0),
            'rows' => is_array($save['rows'] ?? null) ? $save['rows'] : [],
            'attempts' => is_array($save['attempts'] ?? null) ? $save['attempts'] : [],
            'data' => $remote['json'] ?? null,
            'raw' => (($remote['json'] ?? null) === null) ? substr((string)($remote['body'] ?? ''), 0, 1200) : null,
            'error' => (string)($remote['error'] ?? ''),
        ], !empty($save['ok']) ? 200 : 409);
    }

    if ($action === 'delete') {
        if (function_exists('set_time_limit')) @set_time_limit(90);
        $plu = lp_clean_code($input['plu'] ?? '', 50);
        $rack = lp_clean_delete_rack($input['rack'] ?? '', 50);
        if ($plu === '') lp_respond_json(['ok' => false, 'message' => 'PLU wajib diisi.'], 422);

        $result = lp_delete_row_remote($store, $plu, $rack);
        $remote = $result['remote'];
        lp_respond_json([
            'ok' => $result['ok'],
            'verified' => $result['verified'],
            'mode' => $result['mode'],
            'message' => $result['message'],
            'status' => $result['status'],
            'rows' => $result['rows'],
            'attempts' => $result['attempts'],
            'data' => $remote['json'] ?? null,
            'raw' => (($remote['json'] ?? null) === null) ? substr((string) ($remote['body'] ?? ''), 0, 1200) : null,
            'error' => $remote['error'] ?? '',
        ], $result['ok'] ? 200 : 409);
    }

    if ($action === 'clear') {
        $remote = lp_remote_request('DELETE', lp_endpoint_url('clear', ['store' => $store]));
        lp_respond_json([
            'ok' => $remote['ok'],
            'message' => lp_remote_message($remote['json'], $remote['ok'] ? 'Semua LPRICE berhasil dihapus.' : 'Gagal menghapus semua LPRICE.'),
            'status' => $remote['status'],
            'data' => $remote['json'],
            'raw' => $remote['json'] === null ? substr($remote['body'], 0, 1200) : null,
            'error' => $remote['error'],
        ], $remote['ok'] ? 200 : 502);
    }

    lp_respond_json(['ok' => false, 'message' => 'Aksi tidak dikenal.'], 404);
}


if(isset($_GET['api'])){
  header("Access-Control-Allow-Origin: *");
  header("Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-CIBILI-Page-Visible, X-CIBILI-Session-Recover");
  $api = (string)$_GET['api'];
  $allowVisiblePageRecovery = (
    in_array($api, ['session_heartbeat','presence_ping'], true) &&
    ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' &&
    (string)($_SERVER['HTTP_X_CIBILI_PAGE_VISIBLE'] ?? '') === '1' &&
    (string)($_SERVER['HTTP_X_CIBILI_SESSION_RECOVER'] ?? '') === '1'
  );
  $me = cookie_read_session($allowVisiblePageRecovery);

  /* Status gangguan server M604: endpoint polling + switch developer. */
  if($api === 'm604_server_status'){
    $payload = m604_server_status_payload();
    $payload['appliesToCurrentSession'] = m604_server_block_applies($me);
    $payload['storeId'] = (string)$me;
    $payload['isDeveloper'] = ($me === ADMIN_STORE_ID && function_exists('m604_is_developer_session') && m604_is_developer_session());
    json_out($payload);
  }

  if($api === 'm604_server_locked_page'){
    if(m604_server_block_applies($me)) m604_server_render_locked_page();
    if(!headers_sent()) header('Location: ' . (string)($_SERVER['PHP_SELF'] ?? 'index.php'), true, 302);
    exit;
  }

  if($api === 'admin_m604_server_status_set'){
    if(($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') json_out(['ok'=>false,'msg'=>'Metode tidak diizinkan.'],405);
    if($me !== ADMIN_STORE_ID || !function_exists('m604_is_developer_session') || !m604_is_developer_session()){
      json_out(['ok'=>false,'msg'=>'Forbidden'],403);
    }
    $body = json_decode((string)file_get_contents('php://input'), true);
    if(!is_array($body) || !array_key_exists('enabled',$body)) json_out(['ok'=>false,'msg'=>'Status switch tidak valid.'],400);
    list($saved,$row) = m604_server_status_write(!empty($body['enabled']), $me);
    if(!$saved) json_out(['ok'=>false,'msg'=>'Gagal menyimpan status server. Periksa izin tulis folder.'],500);
    $payload = m604_server_status_payload();
    $payload['msg'] = !empty($payload['enabled']) ? 'Server M604 dinonaktifkan untuk user PIN 0000.' : 'Server M604 kembali normal.';
    json_out($payload);
  }

  /*
   * Penegakan sisi server: semua API user M604 non-developer ditolak saat
   * switch kanan. Endpoint status, sesi, dan logout tetap tersedia agar
   * polling real-time serta keluar akun tetap bekerja.
   */
  if(m604_server_block_applies($me)){
    $m604AllowedDuringLock = [
      'm604_server_status','m604_server_locked_page','login','logout','me','session_heartbeat',
      'session_close','presence_ping'
    ];
    if(!in_array($api, $m604AllowedDuringLock, true)){
      $payload = m604_server_status_payload();
      json_out([
        'ok'=>false,
        'server_blocked'=>true,
        'code'=>$payload['code'],
        'message'=>$payload['message'],
        'msg'=>$payload['message'],
        'serverTs'=>$payload['serverTs'],
      ],503);
    }
  }


  /* Clerek - baca struk dari ZIP/SQLite di server (port dari handler Node.js multer/AdmZip/sqlite3). */
  if($api === 'clerek_receipts'){
    if(($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') json_out(['success'=>false,'error'=>'Method not allowed'],405);
    if(!$me) json_out(['success'=>false,'error'=>'Silakan login ulang.'],401);
    if(!class_exists('ZipArchive')) json_out(['success'=>false,'error'=>'Ekstensi PHP ZipArchive belum aktif di hosting.'],500);
    if(!class_exists('SQLite3')) json_out(['success'=>false,'error'=>'Ekstensi PHP SQLite3 belum aktif di hosting.'],500);
    if(empty($_FILES['zipfile']) || !is_array($_FILES['zipfile'])) json_out(['success'=>false,'error'=>'ZIP wajib diupload'],400);
    $upload = $_FILES['zipfile'];
    $uploadErr = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if($uploadErr !== UPLOAD_ERR_OK){
      $uploadMessages = [
        UPLOAD_ERR_INI_SIZE=>'Ukuran ZIP melebihi upload_max_filesize hosting.',
        UPLOAD_ERR_FORM_SIZE=>'Ukuran ZIP melebihi batas form.',
        UPLOAD_ERR_PARTIAL=>'Upload ZIP tidak lengkap.',
        UPLOAD_ERR_NO_FILE=>'ZIP wajib diupload.',
        UPLOAD_ERR_NO_TMP_DIR=>'Folder temporary hosting tidak tersedia.',
        UPLOAD_ERR_CANT_WRITE=>'Hosting gagal menulis file upload.',
        UPLOAD_ERR_EXTENSION=>'Upload dihentikan ekstensi PHP.'
      ];
      json_out(['success'=>false,'error'=>$uploadMessages[$uploadErr] ?? ('Upload gagal. Kode '.$uploadErr)],400);
    }
    $tmpZip = (string)($upload['tmp_name'] ?? '');
    if($tmpZip==='' || !is_uploaded_file($tmpZip)) json_out(['success'=>false,'error'=>'File upload tidak valid.'],400);
    $originalName = basename((string)($upload['name'] ?? 'clerek.zip'));
    if(!preg_match('/\.zip$/i',$originalName)) json_out(['success'=>false,'error'=>'File harus berformat ZIP.'],400);

    $zip = new ZipArchive();
    $opened = $zip->open($tmpZip);
    if($opened !== true) json_out(['success'=>false,'error'=>'ZIP tidak dapat dibuka atau rusak.'],400);
    $dbEntry = '';
    for($i=0; $i<$zip->numFiles; $i++){
      $stat = $zip->statIndex($i);
      $name = is_array($stat) ? (string)($stat['name'] ?? '') : '';
      if($name==='' || substr($name,-1)==='/') continue;
      if(preg_match('/\.(?:db|sqlite|sqlite3)$/i',$name)){ $dbEntry=$name; break; }
    }
    if($dbEntry===''){ $zip->close(); json_out(['success'=>false,'error'=>'Database tidak ditemukan di dalam ZIP.'],400); }

    $dbTmp = tempnam(sys_get_temp_dir(),'clerek_db_');
    if($dbTmp===false){ $zip->close(); json_out(['success'=>false,'error'=>'Gagal membuat file temporary database.'],500); }
    $in = $zip->getStream($dbEntry);
    $out = @fopen($dbTmp,'wb');
    if(!$in || !$out){ if(is_resource($in)) fclose($in); if(is_resource($out)) fclose($out); $zip->close(); @unlink($dbTmp); json_out(['success'=>false,'error'=>'Gagal mengekstrak database dari ZIP.'],500); }
    stream_copy_to_stream($in,$out);
    fclose($in); fclose($out); $zip->close();

    $db = null;
    try{
      $flags = defined('SQLITE3_OPEN_READONLY') ? SQLITE3_OPEN_READONLY : 1;
      $db = new SQLite3($dbTmp,$flags);
      if(method_exists($db,'busyTimeout')) @$db->busyTimeout(3000);

      $tableExists = function($table) use ($db){
        $stmt = @$db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=:name LIMIT 1");
        if(!$stmt) return false;
        $stmt->bindValue(':name',(string)$table,SQLITE3_TEXT);
        $res=@$stmt->execute();
        $ok=($res && $res->fetchArray(SQLITE3_NUM));
        if($res) $res->finalize();
        return (bool)$ok;
      };
      if(!$tableExists('tx_tsale')) throw new Exception('Tabel tx_tsale tidak ditemukan.');

      $rowAssoc = function($sql) use ($db){
        $res=@$db->query($sql);
        if(!$res) return null;
        $row=$res->fetchArray(SQLITE3_ASSOC);
        $res->finalize();
        return is_array($row)?$row:null;
      };
      $allAssoc = function($sql) use ($db){
        $res=@$db->query($sql);
        if(!$res) return [];
        $rows=[];
        while($row=$res->fetchArray(SQLITE3_ASSOC)){ $rows[]=$row; }
        $res->finalize();
        return $rows;
      };
      $cleanText = function($value){
        if($value===null) return null;
        if(is_int($value)||is_float($value)) return $value;
        $text=(string)$value;
        $text=str_replace("\0",'', $text);
        if(function_exists('mb_check_encoding') && !@mb_check_encoding($text,'UTF-8')){
          $converted=@mb_convert_encoding($text,'UTF-8','UTF-8, ISO-8859-1, Windows-1252');
          if(is_string($converted)) $text=$converted;
        }
        return $text;
      };

      $info = $rowAssoc("SELECT store_id, user_id, date_tx FROM tx_tsale ORDER BY date_tx DESC LIMIT 1");
      $hasilRow = $rowAssoc("SELECT SUM(cash) cash, SUM(change_pay) change_pay FROM tx_tsale WHERE date_tx=(SELECT MAX(date_tx) FROM tx_tsale)");
      $receipts=[];
      if($tableExists('log_receipt_prn')){
        $receipts = $allAssoc("SELECT l.bill_no, l.date_tx, t.user_id, t.cust_id, t.phone, t.cash, t.change_pay, l.header, l.body1, l.body2, l.body3, l.addtl1, l.addtl2, l.addtl3, l.footer FROM log_receipt_prn l LEFT JOIN tx_tsale t ON CAST(l.bill_no AS TEXT)=substr(t.faktur,-4) ORDER BY l.date_tx DESC");
      }
      foreach($receipts as &$receipt){ foreach($receipt as $k=>$v){ $receipt[$k]=$cleanText($v); } } unset($receipt);
      $cash = (float)($hasilRow['cash'] ?? 0);
      $changePay = (float)($hasilRow['change_pay'] ?? 0);
      if($db){ @$db->close(); $db=null; }
      @unlink($dbTmp);
      json_out([
        'success'=>true,
        'file_name'=>$originalName,
        'db_file'=>basename($dbEntry),
        'store_id'=>$cleanText($info['store_id'] ?? '-'),
        'user_id'=>$cleanText($info['user_id'] ?? '-'),
        'tanggal'=>$cleanText($info['date_tx'] ?? '-'),
        'hasil'=>$cash-$changePay,
        'total_receipt'=>count($receipts),
        'receipts'=>$receipts
      ]);
    }catch(Throwable $e){
      if($db){ try{@$db->close();}catch(Throwable $ignore){} }
      @unlink($dbTmp);
      json_out(['success'=>false,'error'=>$e->getMessage()],500);
    }
  }


  /* Label Price: semua aksi menggunakan store ID dari sesi login, bukan input browser. */
  if(strpos($api, 'label_price_') === 0){
    if(!$me) lp_respond_json(['ok'=>false,'message'=>'Silakan login ulang.'], 401);
    $labelAction = substr($api, strlen('label_price_'));
    $allowedLabelActions = ['status_store','login','scan','list','insert','delete','clear'];
    if(!in_array($labelAction, $allowedLabelActions, true)) lp_respond_json(['ok'=>false,'message'=>'Aksi Label Price tidak dikenal.'], 404);
    lp_handle_action($labelAction, $me);
  }

  /* API publik untuk daftar.html */
  if($api === 'manual_registration_settings'){
    $settings = manual_registration_settings_read();
    json_out(['ok'=>true,'promo2Enabled'=>!empty($settings['promo2Enabled']),'updatedAt'=>$settings['updatedAt']]);
  }

  if($api === 'manual_registration_create'){
    if(($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') json_out(['ok'=>false,'msg'=>'Metode tidak diizinkan.'],405);
    $body = json_decode((string)file_get_contents('php://input'), true);
    if(!is_array($body)) $body = [];
    $storeId = strtoupper(substr(preg_replace('/[^A-Z0-9]/','',(string)($body['storeId'] ?? '')),0,4));
    $pin = substr(preg_replace('/[^0-9]/','',(string)($body['pin'] ?? '')),0,4);
    $plan = strtolower(preg_replace('/[^a-z0-9]/','',(string)($body['plan'] ?? '1m')));
    if(strlen($storeId) !== 4) json_out(['ok'=>false,'msg'=>'Kode toko wajib tepat 4 angka/huruf.'],400);
    if(strlen($pin) !== 4) json_out(['ok'=>false,'msg'=>'PIN wajib tepat 4 angka.'],400);
    if(!in_array($plan,['1m','2m'],true)) json_out(['ok'=>false,'msg'=>'Paket tidak valid.'],400);
    if($plan === '2m' && !manual_registration_promo_enabled()) json_out(['ok'=>false,'msg'=>'Promo 2 bulan sedang tidak aktif. Pilih paket 1 bulan.'],400);
    $months = $plan === '2m' ? 2 : 1;
    $label = $months === 2 ? '2 Bulan | 50K | PROMO' : '1 Bulan | 50K';
    $now = date('c');
    $result = manual_registration_with_lock(function(&$data) use ($storeId,$pin,$plan,$months,$label,$now){
      $index = -1;
      foreach($data['items'] as $i=>$row){
        if(!is_array($row)) continue;
        if(strtoupper((string)($row['storeId'] ?? '')) === $storeId && strtolower((string)($row['status'] ?? 'pending')) === 'pending'){
          $index = (int)$i; break;
        }
      }
      if($index >= 0){
        $row = $data['items'][$index];
        $row['pin']=$pin; $row['plan']=$plan; $row['months']=$months; $row['planLabel']=$label; $row['amount']=50000; $row['updatedAt']=$now;
        $data['items'][$index]=$row;
        return ['ok'=>true,'request'=>manual_registration_public_row($row),'reused'=>true];
      }
      $row = [
        'id'=>manual_registration_new_id(),'storeId'=>$storeId,'pin'=>$pin,'plan'=>$plan,'planLabel'=>$label,
        'months'=>$months,'amount'=>50000,'status'=>'pending','createdAt'=>$now,'updatedAt'=>$now,
        'source'=>'daftar.html','clientIp'=>substr((string)($_SERVER['REMOTE_ADDR'] ?? ''),0,64)
      ];
      $data['items'][]=$row;
      if(count($data['items'])>2000) $data['items']=array_slice($data['items'],-2000);
      return ['ok'=>true,'request'=>manual_registration_public_row($row),'reused'=>false];
    });
    if(!empty($result['ok']) && is_array($result['request'] ?? null)){
      $req = $result['request'];
      $reqStore = strtoupper(preg_replace('/[^A-Z0-9]/','',(string)($req['storeId'] ?? $storeId)));
      $reqPlan = trim((string)($req['planLabel'] ?? $label));
      $notifTitle = !empty($result['reused']) ? 'Approval Pendaftaran Diperbarui' : 'Approval Pendaftaran Baru';
      notif_add_message($notifTitle, 'Toko '.$reqStore.' mengirim permintaan '.$reqPlan.'. Buka Admin > Approval untuk memproses.', notif_developer_target());
    }
    json_out($result, !empty($result['ok']) ? 200 : 500);
  }

  /* API approval khusus developer */
  if($api === 'admin_manual_registration_list'){
    if($me !== ADMIN_STORE_ID || !m604_is_developer_session()) json_out(['ok'=>false,'msg'=>'Forbidden'],403);
    $data = manual_registration_read_all();
    $items = [];
    foreach((array)$data['items'] as $row){
      if(!is_array($row) || strtolower((string)($row['status'] ?? 'pending')) !== 'pending') continue;
      $items[] = manual_registration_public_row($row);
    }
    usort($items,function($a,$b){
      return strcmp((string)($b['createdAt']??''),(string)($a['createdAt']??''));
    });
    $settings = manual_registration_settings_read();
    json_out(['ok'=>true,'items'=>array_slice($items,0,500),'promo2Enabled'=>!empty($settings['promo2Enabled']),'updatedAt'=>(string)($data['updatedAt']??'')]);
  }

  if($api === 'admin_manual_registration_promo_set'){
    if($me !== ADMIN_STORE_ID || !m604_is_developer_session()) json_out(['ok'=>false,'msg'=>'Forbidden'],403);
    $body = json_decode((string)file_get_contents('php://input'), true); if(!is_array($body)) $body=[];
    $saved = manual_registration_settings_write(!empty($body['enabled']));
    if($saved === false) json_out(['ok'=>false,'msg'=>'Gagal menyimpan switch promo.'],500);
    json_out(['ok'=>true,'promo2Enabled'=>!empty($saved['promo2Enabled']),'updatedAt'=>$saved['updatedAt']]);
  }

  if($api === 'admin_manual_registration_action'){
    if($me !== ADMIN_STORE_ID || !m604_is_developer_session()) json_out(['ok'=>false,'msg'=>'Forbidden'],403);
    if(($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') json_out(['ok'=>false,'msg'=>'Metode tidak diizinkan.'],405);
    $body = json_decode((string)file_get_contents('php://input'), true); if(!is_array($body)) $body=[];
    $id = preg_replace('/[^A-Za-z0-9_-]/','',(string)($body['id'] ?? ''));
    $action = strtolower(preg_replace('/[^a-z]/','',(string)($body['action'] ?? '')));
    if($id==='' || !in_array($action,['approve','reject'],true)) json_out(['ok'=>false,'msg'=>'Permintaan/action tidak valid.'],400);
    $result = manual_registration_with_lock(function(&$data) use ($id,$action,$me){
      $idx=-1;
      foreach($data['items'] as $i=>$row){ if(is_array($row) && (string)($row['id']??'')===$id){$idx=(int)$i;break;} }
      if($idx<0) return ['ok'=>false,'msg'=>'Permintaan tidak ditemukan.'];
      $row=$data['items'][$idx];
      $status=strtolower((string)($row['status']??'pending'));
      if($status!=='pending') return ['ok'=>false,'msg'=>'Permintaan sudah diproses sebelumnya.','request'=>manual_registration_public_row($row)];
      if($action==='reject'){
        array_splice($data['items'],$idx,1);
        return ['ok'=>true,'message'=>'Permintaan ditolak dan riwayat dihapus.'];
      }
      $storeId=strtoupper(preg_replace('/[^A-Z0-9]/','',(string)($row['storeId']??'')));
      $pin=preg_replace('/[^0-9]/','',(string)($row['pin']??''));
      $months=max(1,min(2,(int)($row['months']??1)));
      if(strlen($storeId)!==4 || strlen($pin)!==4) return ['ok'=>false,'msg'=>'Data kode toko/PIN pada permintaan tidak valid.'];
      if($storeId===ADMIN_STORE_ID) return ['ok'=>false,'msg'=>'Kode developer tidak dapat didaftarkan melalui approval.'];
      $db=read_store_db();
      $existing=in_array($storeId,(array)($db['stores']??[]),true);
      if($existing){
        $savedPin=(string)pin_get($storeId);
        if(strlen($savedPin)!==4 || !hash_equals($savedPin,$pin)){
          array_splice($data['items'],$idx,1);
          return ['ok'=>true,'message'=>'PIN tidak sama. Expired tidak ditambahkan dan riwayat dihapus.','pinMismatch'=>true];
        }
      }
      if(!$existing){ $stores=(array)($db['stores']??[]); $stores[]=$storeId; write_store_db($stores); }
      pin_set($storeId,$pin);
      premium_set($storeId,true);
      $oldTs=(int)expiry_get_ts($storeId);
      $newTs=manual_registration_extend_months_ts($storeId,$months);
      expiry_set_ts($storeId,$newTs,['source'=>'manual_registration','actor'=>$me,'months'=>$months]);
      if(function_exists('notif_add_message')){
        $title=$existing?'Perpanjangan Disetujui':'Pendaftaran Disetujui';
        $msg='Admin menyetujui paket '.$months.' bulan. Masa aktif akun sampai '.date('d/m/Y H:i',$newTs).' WIB.';
        notif_add_message($title,$msg,$storeId);
      }
      array_splice($data['items'],$idx,1);
      return ['ok'=>true,'message'=>$existing?'Perpanjangan berhasil ditambahkan dan riwayat dihapus.':'User baru berhasil diaktifkan dan riwayat dihapus.'];
    });
    json_out($result, !empty($result['ok']) ? 200 : 400);
  }

  if($api === 'popup_order_get'){
    $orders = [];
    if(is_file(POPUP_ORDER_FILE)){
      $tmp = json_decode((string)@file_get_contents(POPUP_ORDER_FILE), true);
      if(is_array($tmp)) $orders = $tmp['orders'] ?? [];
    }
    json_out(['ok'=>true,'orders'=>is_array($orders)?$orders:[]]);
  }

  if($api === 'popup_order_save'){
    if($me !== ADMIN_STORE_ID || !m604_is_developer_session()) json_out(['ok'=>false,'msg'=>'Forbidden'],403);
    $body=json_decode((string)file_get_contents('php://input'),true); if(!is_array($body))$body=[];
    $popup=preg_replace('/[^A-Za-z0-9_\-]/','',(string)($body['popup']??''));
    $order=$body['order']??[];
    if($popup===''||!is_array($order)) json_out(['ok'=>false,'msg'=>'Data urutan tidak valid'],400);
    $order=array_values(array_unique(array_slice(array_map(fn($v)=>substr(trim((string)$v),0,180),$order),0,100)));
    $data=['orders'=>[],'updatedAt'=>date('c')];
    if(is_file(POPUP_ORDER_FILE)){ $old=json_decode((string)@file_get_contents(POPUP_ORDER_FILE),true); if(is_array($old))$data=$old; }
    if(!isset($data['orders'])||!is_array($data['orders']))$data['orders']=[];
    $data['orders'][$popup]=$order; $data['updatedAt']=date('c');
    $tmp=POPUP_ORDER_FILE.'.tmp'; $ok=@file_put_contents($tmp,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),LOCK_EX);
    if($ok===false||!@rename($tmp,POPUP_ORDER_FILE)){@unlink($tmp);json_out(['ok'=>false,'msg'=>'Gagal menyimpan urutan'],500);} json_out(['ok'=>true]);
  }

  if($api === 'laporan_penjualan_per_nomor_bon'){
    $storeId=strtoupper(preg_replace('/[^A-Z0-9]/','',(string)($_GET['storeId']??$me??'')));
    $dateTx=preg_replace('/[^0-9\-]/','',(string)($_GET['dateTx']??''));
    $userId=preg_replace('/[^A-Za-z0-9]/','',(string)($_GET['userId']??''));
    if($storeId===''||$dateTx===''||$userId==='')json_out(['ok'=>false,'msg'=>'storeId, dateTx, dan userId wajib diisi'],400);
    if(!preg_match('/^\d{2}-\d{2}-\d{4}$/',$dateTx))json_out(['ok'=>false,'msg'=>'Format tanggal harus DD-MM-YYYY'],400);
    $url='https://app.alfastore.co.id/prd/api/rpt/laporan/laporan_penjualan_per_nomor_bon?'.http_build_query(['storeId'=>$storeId,'dateTx'=>$dateTx,'userId'=>$userId]);
    if(!function_exists('curl_init'))json_out(['ok'=>false,'msg'=>'cURL tidak tersedia'],500);
    $raw=false;$err='';$http=0;
    for($attempt=0;$attempt<2;$attempt++){
      $ch=curl_init($url.'&_ts='.rawurlencode((string)microtime(true)).mt_rand(100,999));
      curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>6,CURLOPT_TIMEOUT=>35,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_SSL_VERIFYHOST=>false,CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4,CURLOPT_ENCODING=>'',CURLOPT_HTTPHEADER=>['Accept: application/json,text/html,*/*','Referer: https://app.alfastore.co.id/','Origin: https://app.alfastore.co.id','User-Agent: Mozilla/5.0 (Linux; Android 15) AppleWebKit/537.36 Chrome/120 Safari/537.36']]);
      $raw=curl_exec($ch);$err=(string)curl_error($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
      if($raw!==false&&trim((string)$raw)!==''&&$http>=200&&$http<400)break;
    }
    if($raw===false||$http<200||$http>=400)json_out(['ok'=>false,'msg'=>$err?:('API upstream HTTP '.$http)],502);
    $json=json_decode((string)$raw,true);
    if(json_last_error()!==JSON_ERROR_NONE){
      $clean=(string)$raw;
      if(trim($clean)==='') json_out(['ok'=>true,'data'=>[],'html'=>'']);
      json_out(['ok'=>true,'data'=>[],'html'=>$clean,'contentType'=>'text/html']);
    }
    json_out(['ok'=>true,'data'=>$json]);
  }


  ensure_global_expiry_cleanup();
  $storeDb = read_store_db();
  $me = cookie_read_session();
  $storeDb = read_store_db();
  if($me) presence_touch($me, false);

  if($api === 'profile_avatar'){
    $meAvatar = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)cookie_read_session()));
    if(!$meAvatar) json_out(['ok'=>false,'msg'=>'Silakan login ulang.'], 401);

    $avatarStorageDir = function(){
      $candidates = [
        __DIR__ . '/profile_avatars',
        __DIR__ . '/data/profile_avatars',
        rtrim((string)sys_get_temp_dir(), '/\\') . '/alfastore_profile_avatars'
      ];
      foreach($candidates as $dir){
        if(!is_dir($dir)) @mkdir($dir, 0775, true);
        if(is_dir($dir) && is_writable($dir)){
          $ht = rtrim($dir, '/\\') . '/.htaccess';
          if(!is_file($ht)) @file_put_contents($ht, "Deny from all\n");
          return rtrim($dir, '/\\');
        }
      }
      return '';
    };
    $avatarFileFor = function($store) use ($avatarStorageDir){
      $dir = $avatarStorageDir();
      return $dir ? ($dir . '/avatar_' . preg_replace('/[^A-Z0-9]/','', (string)$store) . '.json') : '';
    };
    $safeWriteJson = function($file, $payload){
      if(!$file) return false;
      $dir = dirname($file);
      if(!is_dir($dir)) @mkdir($dir, 0775, true);
      if(!is_dir($dir) || !is_writable($dir)) return false;
      $json = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
      if($json === false || $json === '') return false;
      $tmp = $file . '.tmp.' . getmypid() . '.' . mt_rand(1000,9999);
      $ok = (@file_put_contents($tmp, $json, LOCK_EX) !== false);
      if($ok) $ok = @rename($tmp, $file);
      if(!$ok){ @unlink($tmp); $ok = (@file_put_contents($file, $json, LOCK_EX) !== false); }
      return $ok && is_file($file) && filesize($file) > 20;
    };

    // Foto profil dibuat GLOBAL: hanya M604 yang boleh mengganti,
    // tetapi semua toko selalu membaca file/avatar milik M604.
    $globalAvatarStore = 'M604';
    $avatarFile = $avatarFileFor($globalAvatarStore);
    $sessionKey = 'profile_avatar_' . $globalAvatarStore;
    $canEditAvatar = ($meAvatar === $globalAvatarStore);
    if($_SERVER['REQUEST_METHOD'] === 'POST'){
      if(!$canEditAvatar) json_out(['ok'=>false,'msg'=>'Gambar profil hanya bisa diganti oleh toko M604.','canEdit'=>false], 403);
      $raw = (string)file_get_contents('php://input');
      $j = json_decode($raw, true);
      $img = is_array($j) ? (string)($j['image'] ?? '') : '';
      $img = preg_replace('/\s+/', '', $img);
      if(!preg_match('~^data:image/(png|jpe?g|webp|gif);base64,[A-Za-z0-9+/=]+$~i', $img)) json_out(['ok'=>false,'msg'=>'Format gambar tidak valid. Pilih JPG, PNG, WEBP, atau GIF.'], 400);
      if(strlen($img) > 2600000) json_out(['ok'=>false,'msg'=>'Ukuran gambar terlalu besar. Pilih gambar lebih kecil.'], 413);
      $payload = ['image'=>$img,'updatedAt'=>date('c'),'updatedBy'=>$meAvatar,'owner'=>$globalAvatarStore];
      $saved = $safeWriteJson($avatarFile, $payload);
      if(!$saved){
        // Fallback agar gambar tetap berubah di browser meskipun hosting menolak write folder.
        $_SESSION[$sessionKey] = $payload;
        json_out(['ok'=>true,'image'=>$img,'updatedAt'=>$payload['updatedAt'],'storage'=>'session','msg'=>'Gambar profil berhasil diganti.'], 200);
      }
      $_SESSION[$sessionKey] = $payload;
      json_out(['ok'=>true,'image'=>$img,'updatedAt'=>$payload['updatedAt'],'storage'=>'file','msg'=>'Gambar profil berhasil diganti.'], 200);
    }

    $img = '';
    if($avatarFile && is_file($avatarFile)){
      $j = json_decode((string)@file_get_contents($avatarFile), true);
      if(is_array($j)) $img = (string)($j['image'] ?? '');
    }
    if($img === '' && isset($_SESSION[$sessionKey]) && is_array($_SESSION[$sessionKey])) $img = (string)($_SESSION[$sessionKey]['image'] ?? '');
    // Migrasi data lama: baca avatar M604 dari format per-toko lama, lalu file global versi lama.
    if($img === ''){
      $oldM604 = $avatarFileFor('M604');
      if($oldM604 && is_file($oldM604)){
        $j = json_decode((string)@file_get_contents($oldM604), true);
        if(is_array($j)) $img = (string)($j['image'] ?? '');
      }
    }
    if($img === ''){
      $legacy = __DIR__ . '/.alfastore_profile_avatar.json';
      if(is_file($legacy)){
        $j = json_decode((string)@file_get_contents($legacy), true);
        if(is_array($j)) $img = (string)($j['image'] ?? '');
      }
    }
    json_out(['ok'=>true,'image'=>$img,'canEdit'=>$canEditAvatar], 200);
  }

  if($api === 'planogram_rack_list'){
    $me = cookie_read_session();
    if(!$me) json_out(['ok'=>false,'msg'=>'Silakan login ulang.'], 401);
    $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$me));
    $url = ALFA_PRD_API_BASE . '/mob/tablet/productinfo/GetRack/Reguler/?storeId=' . rawurlencode($storeId);
    $raw = false; $http = 0; $err = '';
    if(function_exists('curl_init')){
      $ch = curl_init($url);
      curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true,
        CURLOPT_CONNECTTIMEOUT=>6, CURLOPT_TIMEOUT=>25,
        CURLOPT_SSL_VERIFYPEER=>false, CURLOPT_SSL_VERIFYHOST=>0,
        CURLOPT_ENCODING=>'',
        CURLOPT_HTTPHEADER=>['Accept: application/json,text/plain,*/*','User-Agent: Mozilla/5.0 CIBILI-RackList/1.0']
      ]);
      $raw = curl_exec($ch); $err = (string)curl_error($ch); $http = (int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    }else{
      $ctx = stream_context_create(['http'=>['method'=>'GET','timeout'=>25,'header'=>"Accept: application/json,text/plain,*/*\r\nUser-Agent: Mozilla/5.0 CIBILI-RackList/1.0\r\n"]]);
      $raw = @file_get_contents($url,false,$ctx); $http = $raw===false ? 0 : 200;
    }
    if($raw===false || $raw===null || $http<200 || $http>=400) json_out(['ok'=>false,'msg'=>$err ?: ('Gagal mengambil rack. HTTP '.$http)],502);
    $decoded = json_decode((string)$raw,true);
    if(json_last_error() !== JSON_ERROR_NONE) json_out(['ok'=>false,'msg'=>'Format data rack tidak valid.'],502);
    $racks=[];
    $walk=function($node) use (&$walk,&$racks){
      if(!is_array($node)) return;
      foreach($node as $k=>$v){
        $nk=strtolower(preg_replace('/[^a-z0-9]/i','',(string)$k));
        if(is_scalar($v) && in_array($nk,['rack','rak','rackid','rackcode','rackno','racknumber','nomorrak','koderak','namerack','rackname'],true)){
          $r=strtoupper(preg_replace('/[^A-Z0-9_-]/i','',trim((string)$v)));
          if($r!=='' && strlen($r)<=30) $racks[]=$r;
        }elseif(is_array($v)) $walk($v);
      }
      if(array_keys($node)===range(0,count($node)-1)){
        foreach($node as $v){
          if(is_scalar($v)){
            $r=strtoupper(preg_replace('/[^A-Z0-9_-]/i','',trim((string)$v)));
            if($r!=='' && strlen($r)<=30 && preg_match('/[A-Z]/',$r) && preg_match('/[0-9]/',$r)) $racks[]=$r;
          }
        }
      }
    };
    $walk($decoded);
    $racks=array_values(array_unique(array_filter($racks)));
    natcasesort($racks); $racks=array_values($racks);
    presence_touch($me,false,['pageKey'=>'api:plano','pageTitle'=>'Planogram + OH']);
    json_out(['ok'=>true,'storeId'=>$storeId,'racks'=>$racks,'count'=>count($racks),'serverTs'=>time()]);
  }

  if($api === 'planogram_stok_simple'){
    $me = cookie_read_session();
    if($me) presence_touch($me, false);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    if(!$me){
      http_response_code(401);
      echo json_encode(['ok'=>false,'msg'=>'Silakan login ulang.'], JSON_UNESCAPED_UNICODE);
      exit;
    }
    // storeId dipaksa mengikuti user login, bukan input manual.
    $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$me));
    // Lepas kunci sesi sebelum request eksternal agar seluruh rack dapat diproses paralel.
    if(function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) @session_write_close();
    $rack = strtoupper(preg_replace('/[^A-Z0-9_-]/','', (string)($_GET['rack'] ?? 'AA1')));
    if($rack === '') $rack = 'AA1';
  
    $pick = function($row, $keys){
      if(!is_array($row)) return '';
      $lower = [];
      foreach($row as $k=>$v){ $lower[strtolower((string)$k)] = $v; }
      foreach($keys as $k){
        $lk = strtolower((string)$k);
        if(array_key_exists($lk, $lower) && $lower[$lk] !== null && $lower[$lk] !== '') return $lower[$lk];
      }
      foreach($row as $k=>$v){
        $nk = strtolower(preg_replace('/[^a-zA-Z0-9]/','',(string)$k));
        foreach($keys as $kk){
          $need = strtolower(preg_replace('/[^a-zA-Z0-9]/','',(string)$kk));
          if($need !== '' && strpos($nk, $need) !== false && $v !== null && $v !== '') return $v;
        }
      }
      return '';
    };
    $findRows = function($node) use (&$findRows){
      if(is_array($node)){
        $isList = array_keys($node) === range(0, count($node)-1);
        if($isList){
          foreach($node as $item){
            if(is_array($item)){
              $keys = array_map('strtolower', array_keys($item));
              if(count(array_intersect($keys, ['slv','plu','descp','desc','deskripsi','barcode','prdcd','productname'])) > 0) return $node;
            }
          }
        }
        foreach(['data','result','items','rows','list','produk','products','detail','details'] as $k){
          if(isset($node[$k])){ $r = $findRows($node[$k]); if(is_array($r) && count($r)) return $r; }
        }
        foreach($node as $v){ if(is_array($v)){ $r = $findRows($v); if(is_array($r) && count($r)) return $r; } }
      }
      return [];
    };
    $findProduct = function($node) use (&$findProduct){
      if(is_array($node)){
        $keys = array_map('strtolower', array_keys($node));
        if(count(array_intersect($keys, ['onhand','on_hand','oh','stok','stock','qty','rh','barcode','descp','deskripsi','description'])) > 0) return $node;
        foreach($node as $v){ if(is_array($v)){ $r = $findProduct($v); if(is_array($r) && count($r)) return $r; } }
      }
      return [];
    };
    $numVal = function($v){
      if($v === null || $v === '') return '';
      if(is_numeric($v)) return (string)(0 + $v);
      $s = preg_replace('/[^0-9\-]/','', (string)$v);
      return $s === '' ? '' : (string)(int)$s;
    };
  
    $url = 'https://mobile-crun-svc-2jwb2b2p3a-et.a.run.app/tablet/productinfo/CheckPerRack/?storeId=' . urlencode($storeId) . '&rack=' . urlencode($rack);
    if(function_exists('planogram_curl_get')){ [$ok, $body] = planogram_curl_get($url, 20); }
    else{
      $ctx = stream_context_create(['http'=>['timeout'=>20,'ignore_errors'=>true,'header'=>"Accept: application/json\r\nUser-Agent: AlfastorePlanogram/3.0\r\n"], 'ssl'=>['verify_peer'=>false,'verify_peer_name'=>false]]);
      $body = @file_get_contents($url, false, $ctx); $ok = is_string($body) && $body !== '';
      if(!$ok) $body = 'Gagal mengambil data';
    }
    if(!$ok){ http_response_code(502); echo json_encode(['ok'=>false,'msg'=>'Gagal mengambil data planogram: '.$body,'rows'=>[]], JSON_UNESCAPED_UNICODE); exit; }
    $decoded = json_decode((string)$body, true);
    if(json_last_error() !== JSON_ERROR_NONE){ http_response_code(502); echo json_encode(['ok'=>false,'msg'=>'Response planogram bukan JSON valid','raw'=>(string)$body,'rows'=>[]], JSON_UNESCAPED_UNICODE); exit; }
  
    $rowsRaw = $findRows($decoded);
    if(!$rowsRaw && is_array($decoded) && array_keys($decoded) === range(0, count($decoded)-1)) $rowsRaw = $decoded;
    $rows = [];
    foreach($rowsRaw as $r){
      if(!is_array($r)) continue;
      $slv = $pick($r, ['slv','SLV','shelf','shelfLevel','shelving','seq','sequence']);
      $plu = preg_replace('/[^0-9]/','', (string)$pick($r, ['plu','PLU','kodePlu','kode_plu','prdcd','PRDCD','productCode','kode']));
      if($plu === '') continue;
      $rows[] = [
        'slv'=>(string)$slv,
        'plu'=>$plu,
        'barcode'=>(string)$pick($r, ['barcode','BARCODE','barCode','bcode','BAR_CODE','kodeBarcode','kode_barcode']),
        'descp'=>(string)$pick($r, ['descp','DESCP','desc','DESC','deskripsi','DESKRIPSI','description','DESCRIPTION','nama','NAMA','namaBarang','productName','prdName']),
        'oh'=>'', 'rh'=>(string)$pick($r, ['rh','RH','rakHome','rak_home','homeRack'])
      ];
    }
  
    // Ambil detail OH secara paralel agar planogram besar tidak menunggu request satu per satu.
    $detailBodies = [];
    if(function_exists('curl_multi_init') && count($rows) > 1){
      $mh = curl_multi_init(); $handles = []; $queue = array_keys($rows); $activeMap = []; $limit = 10;
      $addHandle = function($idx) use (&$handles,&$activeMap,$mh,$rows,$storeId){
        $plu = $rows[$idx]['plu'];
        $durl = 'https://app.alfastore.co.id/prd/api/mob/tablet/cekexpired/get_product_detail/?storeId=' . urlencode($storeId) . '&plu=' . urlencode($plu);
        $ch = curl_init($durl);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_SSL_VERIFYHOST=>0,CURLOPT_HTTPHEADER=>['Accept: application/json','Cache-Control: no-cache','Pragma: no-cache','User-Agent: AlfastorePlanogram/4.0']]);
        curl_multi_add_handle($mh,$ch); $id=(int)$ch; $handles[$id]=$ch; $activeMap[$id]=$idx;
      };
      while($queue && count($handles)<$limit) $addHandle(array_shift($queue));
      do{
        do{$status=curl_multi_exec($mh,$running);}while($status===CURLM_CALL_MULTI_PERFORM);
        while($info=curl_multi_info_read($mh)){
          $ch=$info['handle']; $id=(int)$ch; $idx=$activeMap[$id]??null;
          if($idx!==null && $info['result']===CURLE_OK) $detailBodies[$idx]=(string)curl_multi_getcontent($ch);
          curl_multi_remove_handle($mh,$ch); curl_close($ch); unset($handles[$id],$activeMap[$id]);
          if($queue) $addHandle(array_shift($queue));
        }
        if($running) curl_multi_select($mh,0.20);
      }while($running || $handles);
      curl_multi_close($mh);
    }else{
      foreach($rows as $i=>$row){
        $plu=$row['plu']; $durl='https://app.alfastore.co.id/prd/api/mob/tablet/cekexpired/get_product_detail/?storeId='.urlencode($storeId).'&plu='.urlencode($plu);
        if(function_exists('planogram_curl_get')){[$dok,$dbody]=planogram_curl_get($durl,8);}else{$dbody=@file_get_contents($durl);$dok=is_string($dbody)&&$dbody!=='';}
        if($dok) $detailBodies[$i]=(string)$dbody;
      }
    }
    foreach($detailBodies as $i=>$dbody){
      $dj=json_decode((string)$dbody,true);
      if(!is_array($dj) || !isset($rows[$i])) continue;
      $prod=$findProduct($dj); if(!is_array($prod)||!count($prod)) continue;
      $oh=$numVal($pick($prod,['onhand','on_hand','OH','oh','stok','stock','qty','saldo']));
      $rh=$pick($prod,['rh','RH','rakHome','rak_home','homeRack','rak']);
      $bc=$pick($prod,['barcode','BARCODE','barCode','bcode','BAR_CODE','kodeBarcode','kode_barcode']);
      $ds=$pick($prod,['descp','DESCP','desc','DESC','deskripsi','DESKRIPSI','description','DESCRIPTION','nama','NAMA','namaBarang','productName','prdName']);
      if($oh!=='') $rows[$i]['oh']=$oh; if($rh!=='') $rows[$i]['rh']=(string)$rh;
      if($bc!=='' && $rows[$i]['barcode']==='') $rows[$i]['barcode']=(string)$bc;
      if($ds!=='' && $rows[$i]['descp']==='') $rows[$i]['descp']=(string)$ds;
    }

    usort($rows, function($a,$b){
      $sa=(string)($a['slv']??''); $sb=(string)($b['slv']??'');
      if(is_numeric($sa) && is_numeric($sb)) return (int)$sa <=> (int)$sb;
      $c = strnatcasecmp($sa,$sb); if($c!==0) return $c;
      return strnatcasecmp((string)($a['plu']??''),(string)($b['plu']??''));
    });
    $totalOh = 0; foreach($rows as $r){ if(is_numeric($r['oh'])) $totalOh += (int)$r['oh']; }
    echo json_encode(['ok'=>true,'status'=>true,'storeId'=>$storeId,'rack'=>$rack,'rows'=>$rows,'data'=>$rows,'totalItem'=>count($rows),'totalOh'=>$totalOh], JSON_UNESCAPED_UNICODE);
    exit;
  }
  
  if($api === 'oh_realtime_data'){
    if(!$me) json_out(["ok"=>false,"msg"=>"Silakan login ulang"], 401);
    $targetStore = strtoupper(substr(preg_replace('/[^A-Z0-9]/','', (string)($_GET['storeId'] ?? $me)), 0, 4));
    $plu = preg_replace('/[^0-9]/','', (string)($_GET['plu'] ?? ''));
    if($targetStore === '') json_out(["ok"=>false,"msg"=>"Kode toko wajib diisi, maksimal 4 huruf/angka.","rows"=>[]], 422);
    if($plu === '') json_out(["ok"=>false,"msg"=>"PLU wajib angka.","rows"=>[]], 422);
    $err = null; $usedDate = null;
    $rows = oh_rt_fetch_rows($targetStore, $plu, $err, $usedDate);
    if($err) json_out(["ok"=>false,"msg"=>$err,"storeId"=>$targetStore,"rows"=>[]], 400);
    json_out(["ok"=>true,"storeId"=>$targetStore,"plu"=>$plu,"periode"=>$usedDate,"rows"=>$rows]);
  }

  if($api === 'register_dokumen_toko_nr'){
    if(!$me) json_out(["ok"=>false,"error"=>"Not logged in"], 401);
    $storeId = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)($_GET['storeId'] ?? $me)));
    if($storeId === '') $storeId = strtoupper((string)$me);
    $periode1 = preg_replace('/[^0-9-]/', '', (string)($_GET['periode1'] ?? date('d-m-Y', strtotime('-1 day'))));
    $periode2 = preg_replace('/[^0-9-]/', '', (string)($_GET['periode2'] ?? date('d-m-Y')));
    if(!preg_match('/^\d{2}-\d{2}-\d{4}$/', $periode1)) $periode1 = date('d-m-Y', strtotime('-1 day'));
    if(!preg_match('/^\d{2}-\d{2}-\d{4}$/', $periode2)) $periode2 = date('d-m-Y');
    $url = ALFA_PRD_API_BASE . '/rpt/laporan/register_dokumen_toko_NR?' . http_build_query([
      'storeId'=>$storeId,'periode1'=>$periode1,'periode2'=>$periode2
    ]);
    $raw=false; $code=0; $err='';
    if(function_exists('curl_init')){
      $ch=curl_init($url);
      curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>12,CURLOPT_TIMEOUT=>45,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_SSL_VERIFYHOST=>0,CURLOPT_HTTPHEADER=>['Accept: text/html,application/json,text/plain,*/*','User-Agent: Mozilla/5.0 (Linux; Android 15) AppleWebKit/537.36 Chrome/120 Safari/537.36']]);
      $raw=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=(string)curl_error($ch); curl_close($ch);
    }else{
      $ctx=stream_context_create(['http'=>['method'=>'GET','timeout'=>45,'header'=>"Accept: text/html,application/json,*/*\r\nUser-Agent: Mozilla/5.0\r\n"]]);
      $raw=@file_get_contents($url,false,$ctx); $code=$raw===false?0:200;
    }
    if($raw===false || $raw===null || $code>=400) json_out(["ok"=>false,"error"=>($err?:('HTTP '.$code))],502);
    json_out(["ok"=>true,"storeId"=>$storeId,"periode1"=>$periode1,"periode2"=>$periode2,"body"=>(string)$raw]);
  }

  if($api === 'register_dokumen_toko'){
    if(!$me) json_out(["ok"=>false,"error"=>"Not logged in"], 401);
    $storeId = preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['storeId'] ?? $me));
    if($storeId === '') $storeId = (string)$me;
    $periode1 = preg_replace('/[^0-9-]/', '', (string)($_GET['periode1'] ?? date('01-m-Y')));
    $periode2 = preg_replace('/[^0-9-]/', '', (string)($_GET['periode2'] ?? date('d-m-Y')));
    if(!preg_match('/^\d{2}-\d{2}-\d{4}$/', $periode1)) $periode1 = date('01-m-Y');
    if(!preg_match('/^\d{2}-\d{2}-\d{4}$/', $periode2)) $periode2 = date('d-m-Y');

    $url = ALFA_PRD_API_BASE . '/rpt/laporan/register_dokumen_toko_koreksi?' . http_build_query([
      'storeId' => $storeId,
      'periode1' => $periode1,
      'periode2' => $periode2,
    ]);

    $raw = false; $code = 0; $err = '';
    if(function_exists('curl_init')){
      $ch = curl_init($url);
      curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 35,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTPHEADER => [
          'Accept: text/html,application/json,text/plain,*/*',
          'User-Agent: Mozilla/5.0 (Linux; Android 15) AppleWebKit/537.36 Chrome/120 Safari/537.36',
        ],
      ]);
      $raw = curl_exec($ch);
      $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $err = (string)curl_error($ch);
      curl_close($ch);
    }else{
      $ctx = stream_context_create(['http'=>['method'=>'GET','timeout'=>35,'header'=>"Accept: text/html,application/json,*/*\r\nUser-Agent: Mozilla/5.0\r\n"]]);
      $raw = @file_get_contents($url, false, $ctx);
      $code = $raw === false ? 0 : 200;
    }
    if($raw === false || $raw === null || $code >= 400){
      json_out(["ok"=>false,"error"=>($err ?: ('HTTP '.$code)),"url"=>$url], 502);
    }
    json_out(["ok"=>true,"storeId"=>$storeId,"periode1"=>$periode1,"periode2"=>$periode2,"url"=>$url,"body"=>(string)$raw]);
  }

  // Planogram rack strict: ambil HTML planogram asli, lalu filter rak di dalam iframe tanpa merusak tabel.
  if($api === 'planogram_rack_html'){
    if(!$me){ http_response_code(401); echo '<!doctype html><meta charset="utf-8"><body>Not logged in</body>'; exit; }
    $storeId = preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['storeId'] ?? $me));
    $rack = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($_GET['rack'] ?? '')));
    if($storeId === '') $storeId = $me;
    if($rack === ''){ http_response_code(400); echo '<!doctype html><meta charset="utf-8"><body>Rack wajib diisi.</body>'; exit; }
    $url = ALFA_PRD_API_BASE . '/rpt/laporan/planogram?storeId=' . urlencode($storeId);

    $raw = false; $err = ''; $http = 0;
    if(function_exists('curl_init')){
      $ch = curl_init($url);
      curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8','User-Agent: Mozilla/5.0 PlanogramRackFilter/2.0']]);
      $raw = curl_exec($ch); $err = (string)curl_error($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    }else{
      $ctx = stream_context_create(['http'=>['method'=>'GET','timeout'=>30,'header'=>"Accept: text/html,application/xhtml+xml,*/*\r\nUser-Agent: Mozilla/5.0 PlanogramRackFilter/2.0\r\n"]]);
      $raw = @file_get_contents($url, false, $ctx); $http = $raw === false ? 0 : 200;
    }

    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    if($raw === false || $raw === null || $http < 200 || $http >= 400){
      echo '<!doctype html><meta charset="utf-8"><body style="font-family:Arial;padding:16px"><b>Gagal mengambil planogram.</b><br>' . htmlspecialchars($err ?: ('HTTP '.$http), ENT_QUOTES, 'UTF-8') . '</body>'; exit;
    }

    $html = (string)$raw;
    $jsonRack = json_encode($rack);
    $inject = '<style id="planogramRackFilterStyle">html,body{background:#fff!important}.pg-rack-info{font-family:Arial,Helvetica,sans-serif;font-weight:900;background:#ecfdf5;color:#312e81;border:1px solid #99f6e4;border-radius:10px;padding:10px 12px;margin:8px 0 12px;position:sticky;top:0;z-index:999999;box-shadow:0 4px 14px rgba(0,0,0,.08)}.pg-rack-miss{font-family:Arial,Helvetica,sans-serif;background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;border-radius:10px;padding:12px;margin:8px 0;font-weight:800}.pg-rack-hide{display:none!important;visibility:hidden!important;height:0!important;max-height:0!important;overflow:hidden!important;padding:0!important;margin:0!important;border:0!important}table{border-collapse:collapse!important;max-width:100%}th,td{border:1px solid #d1d5db!important;padding:6px 8px!important;vertical-align:top!important}th{background:#111827!important;color:#fff!important;font-weight:900!important}</style>';
    $script = '<script>(function(){var TARGET='.$jsonRack.';function norm(v){return String(v||"").replace(/\u00a0/g," ").replace(/\s+/g," ").trim().toUpperCase();}function escRe(v){return String(v||"").replace(/[.*+?^${}()|[\]\\]/g,"\\$&");}function exact(t){return new RegExp("(^|[^A-Z0-9_-])"+escRe(TARGET)+"([^A-Z0-9_-]|$)","i").test(norm(t));}function rackFrom(t){t=norm(t);var m=t.match(/NOMOR\s*RAK\s*[:：]?\s*([A-Z0-9_-]+)/i);if(m&&m[1])return norm(m[1]);m=t.match(/\bRAK\s*[:：]?\s*([A-Z0-9_-]+)\b/i);if(m&&m[1])return norm(m[1]);return "";}function isColumnHeader(txt){return /\b(PLU|KODE|BARCODE|NAMA|DESKRIPSI|QTY|FACING|SHELF|KETERANGAN|DISPLAY)\b/i.test(txt)&&!/NOMOR\s*RAK/i.test(txt);}function addInfo(shown){var old=document.getElementById("planogramRackInfo");if(!old){old=document.createElement("div");old.id="planogramRackInfo";old.className="pg-rack-info";document.body.insertBefore(old,document.body.firstChild);}old.textContent="Nomor Rak : "+TARGET+(shown?" · Tabel rak ditampilkan":"");}function showMiss(){var m=document.getElementById("planogramRackMiss");if(!m){m=document.createElement("div");m.id="planogramRackMiss";m.className="pg-rack-miss";document.body.insertBefore(m,document.body.children[1]||null);}m.textContent="Rak "+TARGET+" tidak ditemukan pada data planogram toko ini.";}function filter(){if(!document.body)return;var totalShown=0,foundTarget=false;addInfo(0);Array.from(document.querySelectorAll("table")).forEach(function(tbl){var rows=Array.from(tbl.querySelectorAll("tr"));var activeRack="",tableFound=false,sectionHasTarget=false;rows.forEach(function(tr){var txt=norm(tr.textContent||"");var rr=rackFrom(txt);if(rr){activeRack=rr;tableFound=true;sectionHasTarget=(rr===TARGET);}var cells=Array.from(tr.children).map(function(c){return norm(c.textContent||"");});var rowHasTarget=exact(txt)||cells.some(function(c){return c===TARGET||c.indexOf("RAK "+TARGET)>=0||c.indexOf("NOMOR RAK : "+TARGET)>=0||c.indexOf("NOMOR RAK "+TARGET)>=0;});if(rowHasTarget){foundTarget=true;if(!rr)sectionHasTarget=true;}var header=isColumnHeader(txt);var keep=false;if(tableFound){keep=(activeRack===TARGET)||sectionHasTarget||(header&&sectionHasTarget);}else{keep=header||rowHasTarget;}if(keep){tr.classList.remove("pg-rack-hide");tr.style.removeProperty("display");if(!header&&txt){totalShown++;}}else{tr.classList.add("pg-rack-hide");}});var hasVisible=Array.from(tbl.querySelectorAll("tr")).some(function(tr){return !tr.classList.contains("pg-rack-hide");});if(hasVisible){tbl.classList.remove("pg-rack-hide");tbl.style.removeProperty("display");}else{tbl.classList.add("pg-rack-hide");}});var activeBlock="";Array.from(document.body.children).forEach(function(el){if(el.id==="planogramRackInfo"||el.id==="planogramRackMiss"||el.tagName==="SCRIPT"||el.tagName==="STYLE")return;if(el.querySelector&&el.querySelector("table")){var vis=Array.from(el.querySelectorAll("table")).some(function(t){return !t.classList.contains("pg-rack-hide");});if(vis){el.classList.remove("pg-rack-hide");el.style.removeProperty("display");}else{el.classList.add("pg-rack-hide");}return;}var txt=norm(el.textContent||"");var rr=rackFrom(txt);if(rr){activeBlock=rr;}var keep=(rr===TARGET)||(activeBlock===TARGET)||exact(txt);if(keep){foundTarget=true;el.classList.remove("pg-rack-hide");el.style.removeProperty("display");}else if(/NOMOR\s*RAK/i.test(txt)||activeBlock){el.classList.add("pg-rack-hide");}});addInfo(totalShown);if(!foundTarget&&totalShown===0)showMiss();else{var miss=document.getElementById("planogramRackMiss");if(miss)miss.remove();}}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",filter);}else{filter();}setTimeout(filter,250);setTimeout(filter,800);setTimeout(filter,1600);})();</script>';
    if(stripos($html, '</head>') !== false){ $html = preg_replace('~</head>~i', $inject . '</head>', $html, 1); }
    else { $html = '<!doctype html><html><head><meta charset="utf-8">' . $inject . '</head><body>' . $html . '</body></html>'; }
    if(stripos($html, '</body>') !== false){ $html = preg_replace('~</body>~i', $script . '</body>', $html, 1); }
    else { $html .= $script; }
    echo $html;
    exit;
  }


  function laporan_harian_flatten_rows($x, &$out){
    if(is_string($x)){
      $t = trim($x);
      if($t === '') return;
      if($t[0] === '{' || $t[0] === '['){
        $j = json_decode($t, true);
        if(is_array($j)) laporan_harian_flatten_rows($j, $out);
      }
      $parsed = laporan_harian_parse_text_report($t);
      foreach($parsed as $r) $out[] = $r;
      return;
    }
    if(is_array($x)){
      $isList = array_keys($x) === range(0, count($x)-1);
      if($isList){
        foreach($x as $v){
          if(is_array($v)){
            $assoc = array_keys($v) !== range(0, count($v)-1);
            if($assoc) $out[] = $v;
          }
          laporan_harian_flatten_rows($v, $out);
        }
      }else{
        $out[] = $x;
        foreach($x as $v) laporan_harian_flatten_rows($v, $out);
      }
    }
  }
  function laporan_harian_norm_key($k){ return strtolower(preg_replace('/[\s_\-.\/()]+/', '', (string)$k)); }
  function laporan_harian_flat_obj($o, &$out){
    if(!is_array($o)) return;
    foreach($o as $k=>$v){
      if(is_array($v)) laporan_harian_flat_obj($v, $out);
      else { $out[laporan_harian_norm_key($k)] = $v; }
    }
  }
  function laporan_harian_pick($o, $keys){
    $f=[]; laporan_harian_flat_obj($o, $f);
    foreach($keys as $k){ $nk=laporan_harian_norm_key($k); if(isset($f[$nk]) && trim((string)$f[$nk]) !== '') return $f[$nk]; }
    foreach($f as $k=>$v){ foreach($keys as $want){ if(strpos($k, laporan_harian_norm_key($want)) !== false && trim((string)$v) !== '') return $v; } }
    return '';
  }

  function laporan_harian_num($v){
    $t = trim((string)$v);
    $t = preg_replace('/[^0-9,.-]/', '', $t);
    if($t === '' || $t === '-' || $t === '.' || $t === ',') return 0;
    // Format Indonesia: 1.234,56 -> 1234.56; angka pcs biasanya bulat.
    if(strpos($t, ',') !== false){
      $t = str_replace('.', '', $t);
      $t = str_replace(',', '.', $t);
    }
    return is_numeric($t) ? (float)$t : 0;
  }
  function laporan_harian_parse_text_report($txt){
    $txt = (string)$txt;
    if($txt === '') return [];
    // HTML dari API sering berupa text/pre; ubah pemisah baris sebelum strip tag.
    $txt = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $txt);
    $txt = preg_replace('/<\s*\/\s*(tr|div|p|li|h\d)\s*>/i', "\n", $txt);
    $txt = html_entity_decode(strip_tags($txt), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $txt = str_replace(["\xc2\xa0", "&nbsp;"], ' ', $txt);
    $lines = preg_split('/\R+/', $txt);
    $out=[]; $seen=[];
    foreach($lines as $line){
      $line = trim(preg_replace('/\s+/', ' ', (string)$line));
      if($line === '') continue;
      // Format API: No. PLU Nama Product T_TRS TQTY(pcs)
      // Contoh: 1. 244 GG SURYA 12 3 5
      if(preg_match('/^\d+\.?\s+(\d{2,})\s+(.+?)\s+(-?\d+(?:[\.,]\d+)?)\s+(-?\d+(?:[\.,]\d+)?)\s*$/u', $line, $m)){
        $plu = preg_replace('/\D+/', '', $m[1]);
        $nama = trim($m[2]);
        $qty = laporan_harian_num($m[4]); // kolom terakhir = TQTY(pcs)
        if($plu !== '' && $nama !== ''){
          $key = $plu.'|'.strtoupper($nama).'|'.$qty;
          if(!isset($seen[$key])){ $seen[$key]=1; $out[]=['plu'=>$plu,'nama'=>$nama,'qty'=>$qty]; }
        }
      }
    }
    return $out;
  }

  function laporan_harian_normalized($raw){
    if(is_string($raw)){
      $textRows = laporan_harian_parse_text_report($raw);
      if(count($textRows) > 0) return $textRows;
    }
    $rows=[]; laporan_harian_flatten_rows($raw, $rows);
    $out=[]; $seen=[];
    $pluKeys=['PLU','plu','PRDCD','prdcd','KODE PLU','KODEPLU','KODE_BARANG','kodeBarang','KODE','SKU','barcode','productId','itemCode'];
    $namaKeys=['NAMA BARANG','NAMA_BARANG','nama_barang','namaBarang','NAMA','nama','DESKRIPSI','deskripsi','DESC','desc','DESCP','descp','long_description','productName','product_name','itemName','namaProduk','nama_produk','barang'];
    $qtyKeys=['TQTY','tqty','TQty','TQTY(PCS)','TQTY PCS','TQTY_PCS','TOTAL QTY','TOTAL_QTY','totalQty','qty','QTY','quantity','PCS','pcs','qtyPcs','QTY_PCS','SALES_QTY','salesQty'];
    foreach($rows as $r){
      if(!is_array($r)) continue;
      $plu = preg_replace('/[^0-9A-Za-z]/', '', (string)laporan_harian_pick($r,$pluKeys));
      $nama = trim(preg_replace('/\s+/', ' ', (string)laporan_harian_pick($r,$namaKeys)));
      $qraw = (string)laporan_harian_pick($r,$qtyKeys);
      $qty = laporan_harian_num($qraw);
      if($plu==='' && $nama==='') continue;
      $key = strtoupper($plu.'|'.$nama.'|'.$qty);
      if(isset($seen[$key])) continue; $seen[$key]=1;
      $out[]=['plu'=>$plu,'nama'=>$nama,'qty'=>$qty];
    }
    return $out;
  }

  if($api === 'laporan_harian_penjualan_toko'){
    if(!$me) json_out(["ok"=>false,"error"=>"Not logged in"], 401);
    $storeId = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$me));
    if($storeId === '') json_out(["ok"=>false,"error"=>"Store login tidak valid"], 400);
    $periode1 = preg_replace('/[^0-9\-]/', '', (string)($_GET['periode1'] ?? date('d-m-Y')));
    if(!preg_match('/^\d{2}-\d{2}-\d{4}$/', $periode1)) $periode1 = date('d-m-Y');

    $url = ALFA_PRD_API_BASE . '/rpt/laporan/laporan_harian_penjualan_toko?' . http_build_query([
      'storeId' => $storeId,
      'periode1' => $periode1,
    ]);

    $raw = false; $code = 0; $err = '';
    if(function_exists('curl_init')){
      $ch = curl_init($url);
      curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 35,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
          'Accept: application/json,text/html,*/*',
          'User-Agent: Mozilla/5.0 (Linux; Android 15) AppleWebKit/537.36 Chrome/120 Safari/537.36',
        ],
      ]);
      $raw = curl_exec($ch);
      $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $err = (string)curl_error($ch);
      curl_close($ch);
    }else{
      $ctx = stream_context_create(['http'=>['method'=>'GET','timeout'=>35,'header'=>"Accept: application/json,text/html,*/*\r\nUser-Agent: Mozilla/5.0\r\n"]]);
      $raw = @file_get_contents($url, false, $ctx);
      $code = $raw === false ? 0 : 200;
    }
    if($raw === false || $raw === null || $code >= 400){
      json_out(["ok"=>false,"error"=>($err ?: ('HTTP '.$code)),"url"=>$url], 502);
    }
    $decoded = json_decode((string)$raw, true);
    $source = is_array($decoded) ? $decoded : (string)$raw;
    $norm = laporan_harian_normalized($source);
    // Fallback: bila API membungkus laporan sebagai string di field tertentu, parse juga raw body penuh.
    if(count($norm) === 0){
      $norm = laporan_harian_normalized((string)$raw);
    }
    json_out(["ok"=>true,"storeId"=>$storeId,"periode1"=>$periode1,"url"=>$url,"normalized"=>$norm,"data"=>(is_array($decoded)?$decoded:null),"body"=>(string)$raw,"raw"=>(string)$raw]);
  }

  // IKT dirender melalui reverse proxy same-origin. Ini memperbaiki kondisi
  // "menolak untuk terhubung" ketika host IKT melarang tampil langsung di iframe.
  if($api === 'ikt_dashboard'){
    if(!$me) json_out(["ok"=>false,"error"=>"Not logged in"], 401);
    cibili_ikt_proxy_handle(CIBILI_IKT_DASHBOARD_URL);
  }
  if($api === 'ikt_proxy'){
    if(!$me) json_out(["ok"=>false,"error"=>"Not logged in"], 401);
    $target = (string)($_GET['u'] ?? '');
    if($target === '') $target = CIBILI_IKT_DASHBOARD_URL;
    cibili_ikt_proxy_handle($target);
  }

  // Seluruh endpoint tambahan Laporan SIS berada di proxy.php. Frontend hanya
  // mengirim kode laporan serta tanggal; storeId selalu mengikuti sesi login.
  if($api === 'sis_report'){
    if(!$me) json_out(["ok"=>false,"error"=>"Not logged in"], 401);

    $storeId = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$me));
    if($storeId === '') json_out(["ok"=>false,"error"=>"Store login tidak valid"], 400);

    $report = strtolower(preg_replace('/[^a-z0-9_]/i', '', (string)($_GET['report'] ?? '')));
    $definitions = [
      'daily_online'       => '/rpt/laporan/daily_report_online',
      'item_retur'         => '/rpt/laporan/item_harus_retur',
      'barang_hilang_sort' => '/rpt/laporan/laporan_barang_hilang_per_item',
      'overstock'          => '/rpt/laporan/rpt_overstock',
      'top_100'            => '/rpt/laporan/rpt_100_top_item',
      'flop_100'           => '/rpt/laporan/rpt_100_flop_item',
      'kkp'                => '/rpt/laporan/kertas_kerja_pkm',
      'barang_hilang'      => '/rpt/laporan/laporan_barang_hilang_per_item',
      'git'                => '/rpt/laporan/laporan_git',
      'rak_detail'         => '/rpt/laporan/laporan_perform_per_rak_detail',
      'rak_total'          => '/rpt/laporan/laporan_perform_per_rak_total',
      'plu_tidak_terjual'  => '/rpt/laporan/laporan_plu_yang_tidak_terjual',
      'jual_harian'        => '/rpt/laporan/perkembangan_jual_harian',
      'lpmp_rupiah'        => '/rpt/laporan/posisi_LPMP_rupiah_per_dept',
      'mutasi_tanggal'     => '/rpt/laporan/posisi_mutasi_per_tanggal',
      'koreksi_rtd_rte'    => '/rpt/laporan/register_koreksi_rtd_rte',
      'tenant_cards'       => '/rpt/laporan/tenant_cards',
    ];
    if(!isset($definitions[$report])) json_out(["ok"=>false,"error"=>"Laporan tidak tersedia"], 404);

    $validIso = static function($value, $fallback){
      $value = trim((string)$value);
      if(!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) return $fallback;
      if(!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return $fallback;
      return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    };
    $toDmy = static function($iso){
      $parts = explode('-', (string)$iso);
      return count($parts) === 3 ? ($parts[2].'-'.$parts[1].'-'.$parts[0]) : (string)$iso;
    };

    $todayIso = date('Y-m-d');
    $yesterdayIso = date('Y-m-d', strtotime('-1 day'));
    $singleDate = $validIso($_GET['date'] ?? '', $todayIso);
    $date1 = $validIso($_GET['date1'] ?? '', $yesterdayIso);
    $date2 = $validIso($_GET['date2'] ?? '', $todayIso);
    if(strcmp($date1, $date2) > 0){ $swap = $date1; $date1 = $date2; $date2 = $swap; }

    $dmy1 = $toDmy($date1);
    $dmy2 = $toDmy($date2);
    $dmySingle = $toDmy($singleDate);
    $userId = CIBILI_REPORT_USER_ID;
    $params = ['storeId'=>$storeId];

    switch($report){
      case 'daily_online':
        $params += ['userId'=>$userId,'periode1'=>$dmy1,'periode2'=>$dmy2];
        break;
      case 'item_retur':
        $params += ['userId'=>$userId];
        break;
      case 'barang_hilang_sort':
        $params += ['storeDate'=>$dmy2,'periode1'=>$dmy1,'periode2'=>$dmy2,'tag'=>'ALL','sort'=>'Sel Qty'];
        break;
      case 'overstock':
        $params += ['date_1'=>$dmy1,'date_2'=>$dmy2];
        break;
      case 'top_100':
      case 'flop_100':
        $params += ['date_sys'=>$dmySingle];
        break;
      case 'kkp':
      case 'barang_hilang':
      case 'plu_tidak_terjual':
      case 'jual_harian':
      case 'lpmp_rupiah':
      case 'mutasi_tanggal':
        $params += ['userId'=>$userId,'periode1'=>$dmy1,'periode2'=>$dmy2];
        break;
      case 'git':
        $params += ['userId'=>$userId];
        break;
      case 'rak_detail':
      case 'rak_total':
        $params += ['userId'=>$userId,'periode1'=>$dmy1,'periode2'=>$dmy2,'rak'=>'ALL'];
        break;
      case 'koreksi_rtd_rte':
        $params += ['userId'=>$userId,'periode1'=>$date1,'periode2'=>$date2];
        break;
      case 'tenant_cards':
        $params += ['userId'=>$userId,'dateTx'=>$singleDate];
        break;
    }

    $url = ALFA_PRD_API_BASE . $definitions[$report] . '?' . http_build_query($params);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Location: ' . $url, true, 302);
    exit;
  }

  // Redirect helper: bangun URL PRD tanpa mengekspos base URL di index.php
  // Contoh pemakaian: ?api=go_prd&path=/rpt/laporan/daily_performance&storeId=KODE&periode1=01-01-2026
  if($api === 'go_prd'){
    if(!$me) json_out(["ok"=>false,"error"=>"Not logged in"], 401);

    $path = (string)($_GET['path'] ?? '');
    // hardening: hanya path relatif dan whitelist prefix
    if($path === '' || $path[0] !== '/') json_out(["ok"=>false,"error"=>"path invalid"], 400);
    if(!(str_starts_with($path, '/rpt/') || str_starts_with($path, '/so/'))){
      json_out(["ok"=>false,"error"=>"path not allowed"], 403);
    }

    // Ambil query param selain api & path
    $params = $_GET;
    unset($params['api'], $params['path']);
    $qs = http_build_query($params);
    $url = ALFA_PRD_API_BASE . $path . ($qs ? ('?' . $qs) : '');

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Location: ' . $url, true, 302);
    exit;
  }

  if($api === 'po_kiriman'){ json_out(['ok'=>false,'error'=>'Cek Kiriman sudah dinonaktifkan.'], 404); }


  if($api === 'home_stats'){
    if(!$me) json_out(["ok"=>false,"msg"=>"Silakan login ulang."], 401);
    clearstatcache();
    $db = read_store_db();
    $stores = is_array($db['stores'] ?? null) ? $db['stores'] : [];
    $presence = presence_get_status_map($stores);
    $online = 0;
    foreach((array)$presence as $sid=>$row){
      if(is_array($row) && !empty($row['online'])) $online++;
    }
    json_out([
      "ok"=>true,
      "totalToko"=>count($stores),
      "onlineToko"=>$online,
      "updatedAt"=>($db['updatedAt'] ?? null),
      "serverTs"=>time()
    ]);
  }

  if($api === 'me'){
    $expTs = $me ? expiry_get_ts($me) : 0;
    json_out(["ok"=>true, "storeId"=>$me ?: null, "isAdmin"=>(($me===ADMIN_STORE_ID) && function_exists('m604_is_developer_session') && m604_is_developer_session()), "isAdmin2"=>($me ? admin2_get($me) : false), "isImpersonating"=>impersonation_is_active(), "impersonationAdmin"=>impersonation_admin_store(), "expiryTs"=>$expTs, "expired"=>($me ? is_store_expired($me) : false)]);
  }

  if($api === 'session_heartbeat'){
    if(!$me) json_out(["ok"=>false, "msg"=>"Silakan login ulang"], 401);
    active_session_touch($me, (string)($_SESSION['active_token'] ?? ''));
    $body = json_decode((string)file_get_contents('php://input'), true);
    $activity = is_array($body) ? [
      'pageKey'=>($body['pageKey'] ?? ''),
      'pageTitle'=>($body['pageTitle'] ?? ''),
    ] : [];
    $row = presence_touch($me, false, $activity);
    json_out([
      "ok"=>true,
      "storeId"=>$me,
      "active"=>true,
      "lastSeenTs"=>(int)($row['lastSeenTs'] ?? time()),
      "activityTitle"=>presence_activity_text($row['activityTitle'] ?? '', 80),
      "activityUpdatedTs"=>(int)($row['activityUpdatedTs'] ?? 0),
      "serverTs"=>time(),
      "timeoutSec"=>active_session_timeout_for_store($me)
    ]);
  }

  if($api === 'session_close'){
    if($me) presence_set_offline($me);
    cookie_clear_session();
    json_out(["ok"=>true, "closed"=>true]);
  }

  if($api === 'presence_ping'){
    if(!$me) json_out(["ok"=>false, "msg"=>"Forbidden"], 403);
    active_session_touch($me, (string)($_SESSION['active_token'] ?? ''));
    $body = json_decode((string)file_get_contents('php://input'), true);
    $activity = is_array($body) ? [
      'pageKey'=>($body['pageKey'] ?? ''),
      'pageTitle'=>($body['pageTitle'] ?? ''),
    ] : [];
    $row = presence_touch($me, false, $activity);
    json_out(["ok"=>true, "storeId"=>$me, "online"=>true, "lastSeenTs"=>(int)($row['lastSeenTs'] ?? time()), "lastLoginTs"=>(int)($row['lastLoginTs'] ?? 0), "activityTitle"=>presence_activity_text($row['activityTitle'] ?? '', 80), "activityUpdatedTs"=>(int)($row['activityUpdatedTs'] ?? 0), "serverTs"=>time()]);
  }

  if($api === 'chat_list'){
    if(!$me) json_out(["ok"=>false, "msg"=>"Forbidden"], 403);
    $all = chat_read_all();
    $isDeveloperChat = ($me === ADMIN_STORE_ID && function_exists('m604_is_developer_session') && m604_is_developer_session());
    $messages = chat_enrich_messages(is_array($all['messages'] ?? null) ? $all['messages'] : [], $isDeveloperChat);
    json_out([
      "ok"=>true,
      "storeId"=>$me,
      "isDeveloper"=>$isDeveloperChat,
      "myName"=>chat_display_name($me, $isDeveloperChat),
      "messages"=>$messages,
      "updatedAt"=>($all['updatedAt'] ?? null),
    ]);
  }

  if($api === 'chat_send'){
    if(!$me) json_out(["ok"=>false, "msg"=>"Forbidden"], 403);
    $body = json_decode(file_get_contents('php://input'), true);
    $message = is_array($body) ? trim((string)($body['message'] ?? '')) : '';
    $message = preg_replace('/\s+/u', ' ', $message ?? '');
    if($message === '') json_out(["ok"=>false, "msg"=>"Pesan kosong"], 400);
    if(chat_u_strlen($message) > 500) json_out(["ok"=>false, "msg"=>"Pesan maksimal 500 karakter"], 400);
    $isDeveloperChat = ($me === ADMIN_STORE_ID && function_exists('m604_is_developer_session') && m604_is_developer_session());
    $saved = chat_add_message($me, $message, $isDeveloperChat);
    if(!$saved) json_out(["ok"=>false, "msg"=>"Gagal menyimpan chat"], 500);
    json_out(["ok"=>true, "storeId"=>$me, "isDeveloper"=>$isDeveloperChat, "myName"=>chat_display_name($me, $isDeveloperChat), "messages"=>chat_enrich_messages($saved['messages'] ?? [], $isDeveloperChat)]);
  }

  if($api === 'chat_delete'){
    if(!$me) json_out(["ok"=>false, "msg"=>"Forbidden"], 403);
    if($me !== ADMIN_STORE_ID || !function_exists('m604_is_developer_session') || !m604_is_developer_session()) json_out(["ok"=>false, "msg"=>"Hanya Developer yang bisa hapus chat"], 403);
    $body = json_decode(file_get_contents('php://input'), true);
    $messageId = is_array($body) ? (string)($body['id'] ?? '') : '';
    $saved = chat_delete_message($messageId);
    if(!$saved) json_out(["ok"=>false, "msg"=>"Chat tidak ditemukan atau gagal dihapus"], 400);
    json_out(["ok"=>true, "messages"=>chat_enrich_messages($saved['messages'] ?? [], true)]);
  }

  if($api === 'chat_delete_all'){
    if(!$me) json_out(["ok"=>false, "msg"=>"Forbidden"], 403);
    if($me !== ADMIN_STORE_ID || !function_exists('m604_is_developer_session') || !m604_is_developer_session()) json_out(["ok"=>false, "msg"=>"Hanya Developer yang bisa hapus semua chat"], 403);
    $saved = chat_delete_all_messages();
    json_out(["ok"=>true, "messages"=>chat_enrich_messages($saved['messages'] ?? [], true)]);
  }

  if($api === 'admin_pass_status'){
    $until = (int)($_SESSION['admin_ok_until'] ?? 0);
    $ok = !empty($_SESSION['admin_ok']) && $until > time();
    if(!$ok){
      unset($_SESSION['admin_ok'], $_SESSION['admin_ok_ts'], $_SESSION['admin_ok_until']);
      json_out(["ok"=>true, "authenticated"=>false, "untilTs"=>0]);
    }
    json_out(["ok"=>true, "authenticated"=>true, "untilTs"=>$until, "validForSec"=>max(0,$until-time())]);
  }

  if($api === 'admin_pass_check'){
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    $pass = is_array($body) ? (string)($body['pass'] ?? '') : '';
    if($pass === '' || !hash_equals((string)admin_login_password(), (string)$pass)){
      unset($_SESSION['admin_ok'], $_SESSION['admin_ok_ts'], $_SESSION['admin_ok_until']);
      json_out(["ok"=>false, "msg"=>"Password salah"], 401);
    }
    $adminUntil = time() + 86400;
    $_SESSION['admin_ok'] = true;
    $_SESSION['admin_ok_ts'] = time();
    $_SESSION['admin_ok_until'] = $adminUntil;
    /* Pertahankan cookie sesi PHP selama 1 hari khusus setelah password admin benar. */
    if(!headers_sent() && session_id() !== ''){
      $secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off');
      $path = ini_get('session.cookie_path') ?: '/';
      @setcookie(session_name(), session_id(), [
        'expires'=>$adminUntil,
        'path'=>$path,
        'secure'=>$secure,
        'httponly'=>true,
        'samesite'=>'Lax'
      ]);
    }
    json_out(["ok"=>true, "untilTs"=>$adminUntil, "validForSec"=>86400]);
  }

  if($api === 'admin_report_pass_check'){
    $me = cookie_read_session();
    if($me !== ADMIN_STORE_ID || !m604_is_developer_session()) json_out(["ok"=>false,"msg"=>"Forbidden"], 403);
    if(($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') json_out(["ok"=>false,"msg"=>"Metode tidak diizinkan"], 405);
    $body = json_decode(file_get_contents('php://input'), true);
    if(!is_array($body)) $body = [];
    $pass = is_scalar($body['pass'] ?? null) ? trim((string)$body['pass']) : '';
    if($pass === '' || !hash_equals((string)admin_report_pin(), $pass)){
      unset($_SESSION['admin_report_ok_until']);
      json_out(["ok"=>false,"msg"=>"Password laporan salah"], 401);
    }
    $_SESSION['admin_report_ok_until'] = time() + 600;
    json_out(["ok"=>true,"validForSec"=>600]);
  }

  if($api === 'admin_credentials_update'){
    $me = cookie_read_session();
    if($me !== ADMIN_STORE_ID || !m604_is_developer_session()) json_out(["ok"=>false,"msg"=>"Forbidden"], 403);
    if(($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') json_out(["ok"=>false,"msg"=>"Metode tidak diizinkan"], 405);
    $body = json_decode(file_get_contents('php://input'), true);
    if(!is_array($body)) $body = [];
    list($saved, $message) = admin_credentials_update(
      $body['developerPin'] ?? '',
      $body['adminPassword'] ?? '',
      $body['reportPin'] ?? '',
      $me
    );
    if(!$saved) json_out(["ok"=>false,"msg"=>$message], 400);
    unset($_SESSION['admin_report_ok_until']);
    json_out(["ok"=>true,"msg"=>$message]);
  }

  if($api === 'admin_session_config_get'){
    $me = cookie_read_session();
    if($me !== ADMIN_STORE_ID || !m604_is_developer_session()) json_out(["ok"=>false,"msg"=>"Forbidden"], 403);
    $config = session_config_read();
    json_out(array_merge(["ok"=>true], $config, [
      "maxSeconds"=>SESSION_MAX_TIMEOUT_SEC,
      "maxValues"=>["minute"=>525600,"hour"=>8760,"day"=>365]
    ]));
  }

  if($api === 'admin_session_config_save'){
    $me = cookie_read_session();
    if($me !== ADMIN_STORE_ID || !m604_is_developer_session()) json_out(["ok"=>false,"msg"=>"Forbidden"], 403);
    if(($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') json_out(["ok"=>false,"msg"=>"Metode tidak diizinkan"], 405);
    $body = json_decode(file_get_contents('php://input'), true);
    if(!is_array($body)) $body = [];
    $value = filter_var($body['value'] ?? null, FILTER_VALIDATE_INT);
    $unit = session_duration_unit($body['unit'] ?? '');
    if($value === false || (int)$value < 1 || $unit === ''){
      json_out(["ok"=>false,"msg"=>"Isi durasi dan pilih satuan menit, jam, atau hari"], 400);
    }
    if(session_duration_seconds((int)$value, $unit) <= 0){
      json_out(["ok"=>false,"msg"=>"Durasi maksimal adalah 365 hari"], 400);
    }
    // Sentuh sesi admin menggunakan aturan lama sebelum batas baru diterapkan.
    active_session_touch($me, (string)($_SESSION['active_token'] ?? ''));
    $saved = session_config_write((int)$value, $unit, $me);
    if(!is_array($saved)) json_out(["ok"=>false,"msg"=>"Pengaturan sesi gagal disimpan"], 500);
    json_out(array_merge(["ok"=>true], $saved, [
      "maxSeconds"=>SESSION_MAX_TIMEOUT_SEC,
      "maxValues"=>["minute"=>525600,"hour"=>8760,"day"=>365]
    ]));
  }

  if($api === 'proxy_product'){
    $storeId = isset($_GET['storeId']) ? trim((string)$_GET['storeId']) : '';
    $plu     = isset($_GET['plu']) ? trim((string)$_GET['plu']) : '';

    if($storeId === '' || $plu === '') json_out(["ok"=>false,"error"=>"storeId dan plu wajib diisi."], 400);
    if(!preg_match('/^[A-Za-z0-9]{2,10}$/', $storeId)) json_out(["ok"=>false,"error"=>"storeId tidak valid."], 400);
    if(!preg_match('/^\d+$/', $plu)) json_out(["ok"=>false,"error"=>"plu harus angka."], 400);

    $url = "https://app.alfastore.co.id/to/api/cex/get_product_detail/"
         . "?storeId=" . urlencode($storeId)
         . "&plu=" . urlencode($plu);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_TIMEOUT => 20,
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_HTTPHEADER => [
        "Accept: application/json",
        "User-Agent: FinanceUI-Proxy/1.0"
      ],
    ]);

    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if($raw === false) json_out(["ok"=>false,"error"=>"Gagal request: ".$err], 502);

    $data = json_decode($raw, true);
    if($data === null && json_last_error() !== JSON_ERROR_NONE){
      json_out(["ok"=>false,"error"=>"Response bukan JSON valid.","raw"=>mb_substr($raw,0,500)], 502);
    }

    if($http < 200 || $http >= 300){
      json_out(["ok"=>false,"error"=>"HTTP ".$http." dari server.","data"=>$data], $http);
    }

    // Ambil nama toko dari endpoint status_toko (header2)
    $header2 = null;
    $statusUrl = "https://app.alfastore.co.id/prd/api/sis/master/status_toko/?storeId=" . urlencode($storeId);

    $ch2 = curl_init($statusUrl);
    curl_setopt_array($ch2, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_CONNECTTIMEOUT => 8,
      CURLOPT_TIMEOUT => 15,
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_SSL_VERIFYHOST => 2,
      CURLOPT_HTTPHEADER => [
        "Accept: application/json",
        "User-Agent: FinanceUI-StatusProxy/1.0"
      ],
    ]);
    $raw2 = curl_exec($ch2);
    $http2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);

    if($raw2 !== false && $http2 >= 200 && $http2 < 300){
      $st = json_decode($raw2, true);
      if(is_array($st)){
        if(isset($st['header2'])) $header2 = $st['header2'];
        else if(isset($st[0]) && is_array($st[0]) && isset($st[0]['header2'])) $header2 = $st[0]['header2'];
        else if(isset($st['data']) && is_array($st['data']) && isset($st['data']['header2'])) $header2 = $st['data']['header2'];
      }
    }

    // Bungkus agar JS bisa baca root.header2 + root.data[]
    json_out(["ok"=>true,"data"=>["header2"=>$header2, "data"=>$data]], 200);
  }



  if($api === 'proxy_planogram'){
    $storeId = isset($_GET['storeId']) ? trim((string)$_GET['storeId']) : '';
    if($storeId === '') json_out(["ok"=>false,"error"=>"storeId wajib diisi."], 400);
    if(!preg_match('/^[A-Za-z0-9]{2,10}$/', $storeId)) json_out(["ok"=>false,"error"=>"storeId tidak valid."], 400);

    $url = "https://app.alfastore.co.id/to/api/cex/get_product_list/"
         . "?storeId=" . urlencode($storeId);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_TIMEOUT => 25,
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_SSL_VERIFYHOST => 2,
      CURLOPT_HTTPHEADER => [
        "Accept: application/json",
        "User-Agent: FinanceUI-PlanogramProxy/1.0"
      ],
    ]);

    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if($raw === false) json_out(["ok"=>false,"error"=>"Gagal request: ".$err], 502);

    $data = json_decode($raw, true);
    if($data === null && json_last_error() !== JSON_ERROR_NONE){
      json_out(["ok"=>false,"error"=>"Response bukan JSON valid.","raw"=>mb_substr($raw,0,500)], 502);
    }

    if($http < 200 || $http >= 300){
      json_out(["ok"=>false,"error"=>"HTTP ".$http." dari server.","data"=>$data], $http);
    }

    json_out(["ok"=>true,"data"=>$data], 200);
  }



/* =========================
   API: OH PARSIAL (multi PLU + simpan list)
========================= */
if($api === 'ohp_onhand'){
  $storeId = isset($_GET['storeId']) ? strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$_GET['storeId'])) : '';
  $plusRaw = isset($_GET['plus']) ? (string)$_GET['plus'] : '';
  $plusArr = normalize_plus_ohp($plusRaw);

  if($storeId==='' || empty($plusArr)) json_out(["ok"=>false,"error"=>"Parameter tidak lengkap (storeId/plus)"], 400);

  $out = [];
  foreach($plusArr as $plu){
    $plu = trim((string)$plu);
    if($plu==='') continue;
    $url = "https://app.alfastore.co.id/to/api/cex/get_product_detail/?storeId=" . urlencode($storeId) . "&plu=" . urlencode($plu);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_TIMEOUT => 20,
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_SSL_VERIFYHOST => 2,
      CURLOPT_HTTPHEADER => [
        "Accept: application/json",
        "User-Agent: FinanceUI-OHP/1.0"
      ],
    ]);
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $nama = "Tidak ditemukan";
    $onhand = 0;

    if($raw !== false && $http>=200 && $http<300){
      $j = json_decode($raw, true);
      $obj = is_array($j) ? ($j[0] ?? null) : null;
      if(is_array($obj)){
        $nama = (string)($obj['descp'] ?? $obj['nama'] ?? 'Tidak ditemukan');
        $onhand = (int)($obj['onhand'] ?? 0);
      }
    }

    $out[] = ["plu"=>$plu, "nama"=>$nama, "on_hand"=>$onhand];
  }

  json_out(["ok"=>true,"data"=>$out]);
}

if($api === 'ohp_list'){
  $st = load_onhand_storage();
  $names = array_keys($st["lists"] ?? []);
  sort($names, SORT_NATURAL|SORT_FLAG_CASE);
  json_out(["ok"=>true,"data"=>$names]);
}

if($api === 'ohp_save'){
  $name = trim((string)($_POST["name"] ?? ""));
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)($_POST["storeId"] ?? "")));
  $plusArr = normalize_plus_ohp($_POST["plus"] ?? "");

  if($name==='' || $storeId==='' || empty($plusArr)) json_out(["ok"=>false,"error"=>"Data tidak lengkap"], 400);

  $st = load_onhand_storage();
  if(!isset($st["lists"]) || !is_array($st["lists"])) $st["lists"] = [];
  $st["lists"][$name] = ["storeId"=>$storeId, "plus"=>$plusArr, "updatedAt"=>date('c')];
  save_onhand_storage($st);

  json_out(["ok"=>true]);
}

if($api === 'ohp_get'){
  $name = (string)($_POST["name"] ?? "");
  $st = load_onhand_storage();
  $data = $st["lists"][$name] ?? null;
  json_out(["ok"=>true,"data"=>$data]);
}

if($api === 'ohp_delete'){
  $name = (string)($_POST["name"] ?? "");
  $st = load_onhand_storage();
  if($name!=='' && isset($st["lists"][$name])){
    unset($st["lists"][$name]);
    save_onhand_storage($st);
  }
  json_out(["ok"=>true]);
}

if($api === 'sogrand_so_data'){
  if(!$me) json_out(['status'=>false,'message'=>'Silakan login dulu'], 401);
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$me));
  $dateSo = preg_replace('/[^0-9\-]/','', (string)($_GET['dateSo'] ?? ''));
  if($dateSo==='') $dateSo = date('d-m-Y');
  $includeOH = isset($_GET['includeOH']) && (string)$_GET['includeOH'] === '1';
  $racksParam = trim((string)($_GET['racks'] ?? ''));
  $rackFilter = $racksParam !== '' ? array_filter(array_map('trim', explode(',', $racksParam))) : null;
  $data = sogrand_fetch_dataset($storeId, $dateSo, $includeOH, $rackFilter);
  if(empty($data['status'])) json_out($data, 502);
  json_out($data);
}

if($api === 'sogrand_so_pdf'){
  if(!$me) json_out(['status'=>false,'message'=>'Silakan login dulu'], 401);
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$me));
  $dateSo = preg_replace('/[^0-9\-]/','', (string)($_GET['dateSo'] ?? ''));
  if($dateSo==='') $dateSo = date('d-m-Y');
  $includeOH = isset($_GET['includeOH']) && (string)$_GET['includeOH'] === '1';
  $racksParam = trim((string)($_GET['racks'] ?? ''));
  $rackFilter = $racksParam !== '' ? array_filter(array_map('trim', explode(',', $racksParam))) : null;
  $data = sogrand_fetch_dataset($storeId, $dateSo, $includeOH, $rackFilter);
  if(empty($data['status'])) json_out($data, 502);
  $rows = array_values(is_array($data['rows'] ?? null) ? $data['rows'] : []);
  $bin = sogrand_make_pdf_binary($rows, $includeOH, 'Cetak Selisih '.$storeId.' '.$dateSo);
  $filename = 'cetak-selisih-rupiah-' . strtolower($storeId) . '-' . str_replace('-', '', $dateSo) . '.pdf';
  header('Content-Type: application/pdf');
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  header('Content-Length: ' . strlen($bin));
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  echo $bin;
  exit;
}

if($api === 'sogrand_so_xlsx'){
  if(!$me) json_out(['status'=>false,'message'=>'Silakan login dulu'], 401);
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$me));
  $dateSo = preg_replace('/[^0-9\-]/','', (string)($_GET['dateSo'] ?? ''));
  if($dateSo==='') $dateSo = date('d-m-Y');
  $includeOH = isset($_GET['includeOH']) && (string)$_GET['includeOH'] === '1';
  $racksParam = trim((string)($_GET['racks'] ?? ''));
  $rackFilter = $racksParam !== '' ? array_filter(array_map('trim', explode(',', $racksParam))) : null;
  $data = sogrand_fetch_dataset($storeId, $dateSo, $includeOH, $rackFilter);
  if(empty($data['status'])) json_out($data, 502);
  $rows = array_values(is_array($data['rows'] ?? null) ? $data['rows'] : []);
  $racksParam = trim((string)($_GET['racks'] ?? ''));
  if($racksParam !== ''){
    $allowed = [];
    foreach(explode(',', $racksParam) as $rack){
      $rack = trim((string)$rack);
      if($rack === '') continue;
      $allowed[strtoupper($rack)] = true;
    }
    if(!empty($allowed)){
      $rows = array_values(array_filter($rows, function($row) use ($allowed){
        $rack = strtoupper(trim((string)($row['rack'] ?? '')));
        return isset($allowed[$rack]);
      }));
    } else {
      $rows = [];
    }
  }
  $bin = sogrand_make_xlsx_binary($rows, $includeOH);
  if($bin === false) json_out(['status'=>false,'message'=>'ZipArchive tidak tersedia untuk membuat XLSX'], 500);
  $filename = 'cetak-selisih-rupiah-' . strtolower($storeId) . '-' . str_replace('-', '', $dateSo) . '.xlsx';
  header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  header('Content-Length: ' . strlen($bin));
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  echo $bin;
  exit;
}

/* =========================
   API: OH Custom CONFIG
========================= */
if($api === 'oh979_get_config'){
  if(!$me) json_out(["status"=>false, "message"=>"Silakan login dulu"], 401);
  $type = oh979_norm_type($_GET['type'] ?? 'reguler');
  $row = oh979_get_type($type);
  if(!$row) json_out(["status"=>false, "message"=>"OH Custom belum ditambahkan admin untuk " . oh979_type_label($type) . "."], 404);
  json_out([
    "status"=>true,
    "type"=>$row['type'],
    "label"=>$row['label'],
    "plus"=>$row['plus'],
    "plusArr"=>$row['plusArr'],
    "updatedAt"=>$row['updatedAt']
  ]);
}
if($api === 'oh979_data'){
  $type = oh979_norm_type($_GET['type'] ?? 'reguler');
  if(!$me) json_out(["status"=>false, "message"=>"Silakan login dulu"], 401);
  // Kode toko selalu mengikuti sesi login. Nilai storeId pada URL diabaikan.
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$me));
  if($storeId==='') json_out(["status"=>false, "message"=>"Sesi kode toko tidak valid"], 401);
  $row = oh979_get_type($type);
  if(!$row) json_out(["status"=>false, "message"=>"OH Custom belum ditambahkan admin untuk " . oh979_type_label($type) . "."], 404);
  // Rack 000 ikut dimuat bersama rack lain. Lepas kunci sesi sebelum mengambil detail PLU.
  if(function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) @session_write_close();
  $flat = [];
  foreach((array)$row['plusArr'] as $plu){ $flat[] = oh979_fetch_product_detail($storeId, $plu); }
  json_out([
    "status"=>true,
    "storeId"=>$storeId,
    "type"=>$row['type'],
    "label"=>$row['label'],
    "mode"=>"global",
    "plus"=>$row['plus'],
    "updatedAt"=>$row['updatedAt'],
    "data"=>$flat,
    "raks"=>[]
  ]);
}
if($api === 'oh979_save_config'){
  if($me !== ADMIN_STORE_ID) json_out(["status"=>false, "message"=>"Forbidden"], 403);
  $body = json_decode(file_get_contents('php://input'), true);
  $type = oh979_norm_type($body['type'] ?? 'reguler');
  $plus = $body['plus'] ?? '';
  $row = oh979_set_type($type, $plus);
  if(!$row) json_out(["status"=>false, "message"=>"PLU 979 tidak valid. Isi dengan angka PLU, bisa dipisah koma, spasi, atau baris baru."], 400);
  json_out([
    "status"=>true,
    "message"=>"Data 979 berhasil disimpan",
    "type"=>$row['type'],
    "label"=>$row['label'],
    "plus"=>$row['plus'],
    "plusArr"=>$row['plusArr'],
    "updatedAt"=>$row['updatedAt']
  ]);
}
if($api === 'oh979_delete_config'){
  if($me !== ADMIN_STORE_ID) json_out(["status"=>false, "message"=>"Forbidden"], 403);
  $body = json_decode(file_get_contents('php://input'), true);
  $type = oh979_norm_type($body['type'] ?? 'reguler');
  oh979_delete_type($type);
  json_out(["status"=>true, "message"=>"Data 979 berhasil dihapus", "type"=>$type, "label"=>oh979_type_label($type)]);
}
if($api === 'oh979_custom_list'){
  $storeId = isset($_GET['storeId']) ? strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$_GET['storeId'])) : '';
  $type = oh979_norm_type($_GET['type'] ?? 'reguler');
  if($storeId==='') json_out(["status"=>false, "message"=>"storeId wajib diisi"], 400);
  if(!$me) json_out(["status"=>false, "message"=>"Silakan login dulu"], 401);
  if($me !== ADMIN_STORE_ID && $me !== $storeId) json_out(["status"=>false, "message"=>"Forbidden"], 403);
  json_out(["status"=>true, "storeId"=>$storeId, "type"=>$type, "data"=>oh979_custom_list($storeId, $type)]);
}
if($api === 'oh979_custom_save'){
  $body = json_decode(file_get_contents('php://input'), true);
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)($body['storeId'] ?? '')));
  $type = oh979_norm_type($body['type'] ?? 'reguler');
  if($storeId==='') json_out(["status"=>false, "message"=>"storeId wajib diisi"], 400);
  if(!$me) json_out(["status"=>false, "message"=>"Silakan login dulu"], 401);
  if($me !== ADMIN_STORE_ID && $me !== $storeId) json_out(["status"=>false, "message"=>"Forbidden"], 403);
  $row = oh979_custom_save($storeId, $type, $body['id'] ?? '', $body['name'] ?? '', $body['plus'] ?? '');
  if(!$row) json_out(["status"=>false, "message"=>"Nama rak atau PLU tidak valid"], 400);
  json_out(["status"=>true, "message"=>"Rak berhasil disimpan", "data"=>$row]);
}
if($api === 'oh979_custom_delete'){
  $body = json_decode(file_get_contents('php://input'), true);
  $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)($body['storeId'] ?? '')));
  $type = oh979_norm_type($body['type'] ?? 'reguler');
  $id = (string)($body['id'] ?? '');
  if($storeId==='') json_out(["status"=>false, "message"=>"storeId wajib diisi"], 400);
  if(!$me) json_out(["status"=>false, "message"=>"Silakan login dulu"], 401);
  if($me !== ADMIN_STORE_ID && $me !== $storeId) json_out(["status"=>false, "message"=>"Forbidden"], 403);
  oh979_custom_delete($storeId, $type, $id);
  json_out(["status"=>true, "message"=>"Rak berhasil dihapus"]);
}
if($api === 'oh979_custom_data'){
  $storeId = isset($_GET['storeId']) ? strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$_GET['storeId'])) : '';
  $type = oh979_norm_type($_GET['type'] ?? 'reguler');
  $id = preg_replace('/[^A-Za-z0-9_\-]/','', (string)($_GET['id'] ?? ''));
  $plusRaw = (string)($_GET['plus'] ?? '');
  if($storeId==='') json_out(["status"=>false, "message"=>"storeId wajib diisi"], 400);
  if(!$me) json_out(["status"=>false, "message"=>"Silakan login dulu"], 401);
  if($me !== ADMIN_STORE_ID && $me !== $storeId) json_out(["status"=>false, "message"=>"Forbidden"], 403);
  if($id !== ''){
    foreach(oh979_custom_list($storeId, $type) as $rak){ if(($rak['id'] ?? '') === $id){ $plusRaw = $rak['plus'] ?? ''; break; } }
  }
  $plusArr = normalize_plus_ohp($plusRaw);
  if(!$plusArr) json_out(["status"=>false, "message"=>"PLU rak kosong atau tidak valid"], 400);
  $flat = [];
  foreach($plusArr as $plu){ $flat[] = oh979_fetch_product_detail($storeId, $plu); }
  json_out(["status"=>true, "storeId"=>$storeId, "type"=>$type, "id"=>$id, "plus"=>implode(',', $plusArr), "data"=>$flat]);
}
  

/* =========================
   API: PLANOGRAM SAVED RAK (simpan rak -> PLU list)
========================= */
if($api === 'plano_saved_list'){
  $storeId = isset($_GET['storeId']) ? strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$_GET['storeId'])) : '';
  if($storeId==='') json_out(["ok"=>false,"error"=>"storeId wajib"], 400);
  $names = plano_list_raks($storeId);
  json_out(["ok"=>true,"data"=>$names]);
}
if($api === 'plano_saved_get'){
  $storeId = isset($_POST['storeId']) ? strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$_POST['storeId'])) : '';
  $rak = isset($_POST['rak']) ? strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$_POST['rak'])) : '';
  if($storeId==='' || $rak==='') json_out(["ok"=>false,"error"=>"storeId/rak wajib"], 400);
  $data = plano_get_rak($storeId, $rak);
  json_out(["ok"=>true,"data"=>$data]);
}
if($api === 'plano_saved_set'){
  $storeId = isset($_POST['storeId']) ? strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$_POST['storeId'])) : '';
  $rak = isset($_POST['rak']) ? strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$_POST['rak'])) : '';
  $plusArr = normalize_plus_ohp($_POST['plus'] ?? '');
  if($storeId==='' || $rak==='' || empty($plusArr)) json_out(["ok"=>false,"error"=>"storeId/rak/plus wajib"], 400);
  plano_set_rak($storeId, $rak, $plusArr);
  oh979_sync_from_planogram($storeId);
  json_out(["ok"=>true]);
}
if($api === 'plano_saved_delete'){
  $storeId = isset($_POST['storeId']) ? strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$_POST['storeId'])) : '';
  $rak = isset($_POST['rak']) ? strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$_POST['rak'])) : '';
  if($storeId==='' || $rak==='') json_out(["ok"=>false,"error"=>"storeId/rak wajib"], 400);
  plano_delete_rak($storeId, $rak);
  oh979_sync_from_planogram($storeId);
  json_out(["ok"=>true]);
}


if($api === 'admin_get_merchant_balance'){
    $me = cookie_read_session();
    if($me !== ADMIN_STORE_ID) json_out(['ok'=>false,'msg'=>'Akses ditolak'], 403);
    $bal = qrispy_get_merchant_balance();
    if(empty($bal['ok'])) json_out(['ok'=>false,'msg'=>$bal['msg'] ?? 'Gagal memuat balance merchant','debug_http_code'=>$bal['debug_http_code'] ?? 0], 502);
    json_out(['ok'=>true,'status'=>'success','data'=>$bal['data']]);
}

if($api === 'register_pricing'){
    $cfg = qris_settings_read();
    json_out(['ok'=>true,'amount'=>(int)($cfg['registration_amount'] ?? REGISTRATION_AMOUNT),'updatedAt'=>$cfg['updatedAt'] ?? null]);
}
if($api === 'admin_get_qris_amount'){
    $me = cookie_read_session();
    if($me !== ADMIN_STORE_ID) json_out(['ok'=>false,'msg'=>'Akses ditolak'], 403);
    $cfg = qris_settings_read();
    json_out(['ok'=>true,'amount'=>(int)($cfg['registration_amount'] ?? REGISTRATION_AMOUNT),'updatedAt'=>$cfg['updatedAt'] ?? null]);
}
if($api === 'admin_set_qris_amount'){
    $me = cookie_read_session();
    if($me !== ADMIN_STORE_ID) json_out(['ok'=>false,'msg'=>'Akses ditolak'], 403);
    $body = json_decode(file_get_contents('php://input'), true);
    $amount = (int)preg_replace('/[^0-9]/','', (string)($body['amount'] ?? '0'));
    if($amount <= 0) json_out(['ok'=>false,'msg'=>'Nominal harus lebih dari 0'], 400);
    $cfg = qris_settings_write_amount($amount);
    json_out(['ok'=>true,'amount'=>(int)$cfg['registration_amount'],'updatedAt'=>$cfg['updatedAt'] ?? null]);
}

if($api === 'admin_get_ui_config'){
    $me = cookie_read_session();
    if($me !== ADMIN_STORE_ID) json_out(['ok'=>false,'msg'=>'Akses ditolak'], 403);
    $cfg = ui_config_read();
    json_out(['ok'=>true,'show_register_button'=>!empty($cfg['show_register_button']),'updatedAt'=>$cfg['updatedAt'] ?? null]);
}
if($api === 'admin_set_ui_config'){
    $me = cookie_read_session();
    if($me !== ADMIN_STORE_ID) json_out(['ok'=>false,'msg'=>'Akses ditolak'], 403);
    $body = json_decode(file_get_contents('php://input'), true);
    $cfg = ui_config_write(['show_register_button'=>!empty($body['show_register_button'])]);
    json_out(['ok'=>true,'show_register_button'=>!empty($cfg['show_register_button']),'updatedAt'=>$cfg['updatedAt'] ?? null]);
}


if($api === 'admin_promo_list'){
    $me = cookie_read_session();
    if($me !== ADMIN_STORE_ID) json_out(['ok'=>false,'msg'=>'Akses ditolak'], 403);
    $all = promo_read_all();
    $items = array_values($all['items'] ?? []);
    usort($items, function($a,$b){ return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')); });
    json_out(['ok'=>true,'items'=>$items,'updatedAt'=>$all['updatedAt'] ?? null]);
}
if($api === 'admin_promo_create'){
    $me = cookie_read_session();
    if($me !== ADMIN_STORE_ID) json_out(['ok'=>false,'msg'=>'Akses ditolak'], 403);
    $body = json_decode(file_get_contents('php://input'), true);
    $code = (string)($body['code'] ?? '');
    $type = (string)($body['type'] ?? 'fixed');
    $value = (int)preg_replace('/[^0-9]/','', (string)($body['value'] ?? '0'));
    $mk = promo_admin_create($code, $type, $value);
    if(empty($mk['ok'])) json_out($mk, 400);
    json_out($mk);
}
if($api === 'admin_promo_delete'){
    $me = cookie_read_session();
    if($me !== ADMIN_STORE_ID) json_out(['ok'=>false,'msg'=>'Akses ditolak'], 403);
    $body = json_decode(file_get_contents('php://input'), true);
    $code = strtoupper(preg_replace('/[^A-Z0-9_-]/','', (string)($body['code'] ?? '')));
    if($code==='') json_out(['ok'=>false,'msg'=>'Kode promo kosong'], 400);
    $all = promo_read_all(); $items = $all['items'] ?? [];
    if(!isset($items[$code])) json_out(['ok'=>false,'msg'=>'Promo tidak ditemukan'], 404);
    unset($items[$code]); promo_write_all($items);
    json_out(['ok'=>true]);
}

if($api === 'register_promo_status'){
    $body = json_decode(file_get_contents('php://input'), true);
    $code = strtoupper(preg_replace('/[^A-Z0-9_-]/','', (string)($body['code'] ?? '')));
    $currentQrisId = trim((string)($body['current_qris_id'] ?? ''));
    if($code==='') json_out(['ok'=>false,'msg'=>'Kode promo kosong'], 400);
    $all = promo_read_all();
    $items = $all['items'] ?? [];
    if(!isset($items[$code])){
      json_out(['ok'=>true,'exists'=>false,'available'=>false,'used'=>false,'reserved'=>false,'current_reservation'=>false,'msg'=>'Kode promo tidak ditemukan']);
    }
    $item = $items[$code];
    $type = strtolower((string)($item['type'] ?? 'active30_once'));
    if($type === 'free3d') $type = 'free3d_once';
    if($type === 'fixed') $type = 'active30_once';
    $isReusable = ($type === 'free3d_multi');
    $used = $isReusable ? false : ((int)($item['used_count'] ?? 0) > 0);
    $reservedBy = trim((string)($item['reserved_qris_id'] ?? ''));
    $currentReservation = ($currentQrisId !== '' && $reservedBy !== '' && $reservedBy === $currentQrisId);
    $reserved = (!$used && $reservedBy !== '');
    $message = $isReusable ? 'Belum dipakai (bisa berkali-kali)' : 'Belum dipakai';
    if($used){
      $message = 'Sudah dipakai';
    }elseif($reserved && !$currentReservation){
      $message = 'Belum dipakai, tetapi sedang dipakai transaksi lain';
    }elseif($currentReservation){
      $message = 'Kode promo ini sudah terpasang di QRIS Anda';
    }
    json_out([
      'ok'=>true,
      'exists'=>true,
      'code'=>$code,
      'type'=>$type,
      'value'=>(int)($item['value'] ?? 0),
      'used'=>$used,
      'used_count'=>(int)($item['used_count'] ?? 0),
      'reserved'=>$reserved,
      'current_reservation'=>$currentReservation,
      'msg'=>$message
    ]);
}

if($api === 'register_store_status'){
    $body = json_decode(file_get_contents('php://input'), true);
    $code = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)($body['storeId'] ?? '')));
    $pinIn = preg_replace('/[^0-9]/','', (string)($body['pin'] ?? ''));
    if($code==='') json_out(['ok'=>false,'msg'=>'Kode toko kosong'], 400);
    if(strlen($code) > 4) json_out(['ok'=>false,'msg'=>'Kode toko maksimal 4 karakter'], 400);
    $exists = in_array($code, $storeDb['stores'], true);
    $expiryTs = (int)expiry_get_ts($code);
    $expired = $exists ? is_store_expired($code) : false;
    $pinValid = $exists ? (strlen($pinIn)===4 && pin_get($code) === $pinIn) : false;
    json_out([
      'ok'=>true,
      'exists'=>$exists,
      'storeId'=>$code,
      'pin_valid'=>$pinValid,
      'expired'=>$expired,
      'remaining_days'=>$exists ? expiry_remaining_days($code) : 0,
      'expiry_ts'=>$expiryTs,
      'expiry_at'=>$expiryTs > 0 ? date('c', $expiryTs) : null,
      'msg'=>$exists ? 'Kode toko telah terdaftar' : 'Kode toko belum terdaftar'
    ]);
}
if($api === 'register_prepare_payment'){
    $body = json_decode(file_get_contents('php://input'), true);
    $code = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)($body['storeId'] ?? '')));
    $pinIn = preg_replace('/[^0-9]/','', (string)($body['pin'] ?? ''));
    $mode = strtolower(trim((string)($body['mode'] ?? 'register')));
    if($mode !== 'renew') $mode = 'register';
    if($code==='') json_out(['ok'=>false,'msg'=>'Kode toko kosong'], 400);
    if(strlen($code) > 4) json_out(['ok'=>false,'msg'=>'Kode toko maksimal 4 karakter'], 400);
    if(strlen($pinIn)!==4) json_out(['ok'=>false,'msg'=>'PIN harus 4 angka'], 400);
    $exists = in_array($code, $storeDb['stores'], true);
    $pinValid = $exists ? (pin_get($code) === $pinIn) : false;
    if($mode === 'renew'){
      if(!$exists) json_out(['ok'=>false,'msg'=>'Kode toko belum terdaftar'], 404);
      if(!$pinValid) json_out(['ok'=>false,'msg'=>'PIN toko tidak sesuai untuk perpanjang'], 403);
    }else{
      if($exists){
        json_out(['ok'=>false,'exists'=>true,'storeId'=>$code,'pin_valid'=>$pinValid,'expired'=>is_store_expired($code),'remaining_days'=>expiry_remaining_days($code),'expiry_ts'=>(int)expiry_get_ts($code),'expiry_at'=>((int)expiry_get_ts($code)>0?date('c',(int)expiry_get_ts($code)):null),'msg'=>'Kode toko sudah terdaftar'], 409);
      }
    }
    $baseAmount = qris_registration_amount();
    $promoCode = strtoupper(preg_replace('/[^A-Z0-9_-]/','', (string)($body['promo_code'] ?? '')));
    $promoCheck = null;
    $discountAmount = 0;
    $amount = $baseAmount;
    if($promoCode !== ''){
      $promoCheck = promo_validate_code($promoCode, $baseAmount);
      if(empty($promoCheck['ok'])) json_out(['ok'=>false,'msg'=>$promoCheck['msg'] ?? 'Kode promo tidak valid'], 400);
      $discountAmount = (int)($promoCheck['discount_amount'] ?? 0);
      $amount = max(0, (int)($promoCheck['final_amount'] ?? $baseAmount));
      if(!empty($promoCheck['bypass_payment'])){
        $freeDays = max(1, (int)($promoCheck['free_days'] ?? 3));
        promo_consume_code($promoCode);
        if($mode === 'renew'){
          $newTs = expiry_extend_days_ts($code, $freeDays);
          expiry_set_ts($code, $newTs, ['source'=>'promo', 'actor'=>$code, 'days'=>$freeDays]); premium_set($code, true);
          json_out([
            'ok'=>true,
            'instant_success'=>true,
            'skip_qris'=>true,
            'mode'=>'renew',
            'promo_code'=>$promoCode,
            'discount_amount'=>$discountAmount,
            'base_amount'=>$baseAmount,
            'amount'=>0,
            'requested_amount'=>0,
            'expiryTs'=>$newTs,
            'expiryAt'=>date('c',$newTs),
            'message'=>'Kode promo berhasil dipakai. Masa aktif toko bertambah ' . $freeDays . ' hari dari sisa expired saat ini tanpa bayar QRIS.',
            'data'=>[
              'promo_code'=>$promoCode,
              'amount'=>0,
              'requested_amount'=>0,
              'discount_amount'=>$discountAmount,
              'base_amount'=>$baseAmount,
              'mode'=>'renew',
              'expiry_at'=>date('c',$newTs),
              'activation_source'=>'promo'
            ]
          ]);
        }
        $stores = $storeDb['stores']; if(!in_array($code, $stores, true)) $stores[] = $code; $newDb = write_store_db($stores);
        $expiryTs = registration_promo_expiry_ts($freeDays);
        pin_set($code, $pinIn); expiry_set_ts($code, $expiryTs, ['source'=>'promo', 'actor'=>$code, 'days'=>$freeDays]); premium_set($code, true);
        json_out([
          'ok'=>true,
          'instant_success'=>true,
          'skip_qris'=>true,
          'mode'=>'register',
          'storeId'=>$code,
          'stores'=>$newDb['stores'],
          'promo_code'=>$promoCode,
          'discount_amount'=>$discountAmount,
          'base_amount'=>$baseAmount,
          'amount'=>0,
          'requested_amount'=>0,
          'expiryTs'=>$expiryTs,
          'expiryAt'=>date('c',$expiryTs),
          'message'=>'Kode promo berhasil dipakai. Toko langsung aktif ' . $freeDays . ' hari tanpa bayar QRIS.',
          'data'=>[
            'promo_code'=>$promoCode,
            'amount'=>0,
            'requested_amount'=>0,
            'discount_amount'=>$discountAmount,
            'base_amount'=>$baseAmount,
            'mode'=>'register',
            'expiry_at'=>date('c',$expiryTs),
            'activation_source'=>'promo'
          ]
        ]);
      }
      $amount = max(1, $amount);
    }
    $pay = qrispy_generate_registration_payment($code, $amount);
    $json = $pay['json'] ?? null;
    $statusText = strtolower(trim((string)($json['status'] ?? '')));
    if(!$pay['ok'] || !is_array($json) || $statusText !== 'success' || !is_array($json['data'] ?? null)){
      $msg = is_array($json) ? (($json['message'] ?? $json['msg'] ?? 'Gagal membuat QRIS')) : 'Gagal membuat QRIS';
      json_out(['ok'=>false,'msg'=>$msg,'debug_http_code'=>$pay['http_code'] ?? 0], 502);
    }
    $data = qrispy_enrich_generated_payment($json['data']);
    $qrisId = trim((string)($data['qris_id'] ?? $data['id'] ?? ''));
    $hasVisual = !empty($data['qris_image_base64']) || !empty($data['qris_image_url']);
    if(!$hasVisual && $qrisId !== ''){
      $fallbackUrl = rtrim(QRISPY_API_URL, '/') . '/api/public/qris/' . rawurlencode($qrisId) . '.png';
      $data['qris_image_url'] = $fallbackUrl;
      $hasVisual = true;
    }
    if($promoCode !== '' && $qrisId !== '') promo_reserve_code($promoCode, $qrisId);
    if(empty($data['amount'])) $data['amount'] = $amount;
    if(empty($data['requested_amount'])) $data['requested_amount'] = $amount;
    $data['promo_code'] = $promoCode;
    $data['discount_amount'] = $discountAmount;
    $data['base_amount'] = $baseAmount;
    json_out([
      'ok'=>true,
      'amount'=>(int)($data['amount'] ?? $amount),
      'requested_amount'=>(int)($data['requested_amount'] ?? $amount),
      'promo_code'=>$promoCode,
      'discount_amount'=>$discountAmount,
      'base_amount'=>$baseAmount,
      'data'=>$data,
      'has_visual'=>$hasVisual,
      'checkout_url'=>(string)($data['checkout_url'] ?? ''),
      'mode'=>$mode,
    ]);
}
if($api === 'register_check_payment'){
    $body = json_decode(file_get_contents('php://input'), true);
    $qrisId = trim((string)($body['qris_id'] ?? ''));
    $expectedAmount = (int)preg_replace('/[^0-9]/','', (string)($body['amount'] ?? '0'));
    if($qrisId==='') json_out(['ok'=>false,'msg'=>'qris_id wajib'], 400);
    if($expectedAmount <= 0) $expectedAmount = qris_registration_amount();
    $row = qrispy_find_payment($qrisId, 900);
    $cached = qrispy_payment_cache_get($qrisId);
    if(!$row && is_array($cached['row'] ?? null)) $row = $cached['row'];
    if(!$row) json_out(['ok'=>false,'msg'=>'Status pembayaran belum ditemukan, mencoba lagi...'], 404);
    $row = qrispy_normalize_payment_row($row);
    $status = qrispy_status_normalize($row['payment_status'] ?? $row['status'] ?? 'pending');
    $paidAmount = qrispy_amount_from_row($row, ['paid_amount','amount_paid','paid','amount','gross_amount','total_amount']);
    $requestedAmount = qrispy_amount_from_row($row, ['requested_amount','request_amount','gross_amount','total_amount','nominal','price']);
    if(($status === '' || $status === 'pending') && qrispy_payment_is_success($row, $expectedAmount)) $status = 'paid';
    if(in_array($status, ['expired','cancelled','failed'], true)) promo_release_qris($qrisId);
    if($status === 'paid') qrispy_payment_cache_mark_success($qrisId, $row);
    json_out(['ok'=>true,'payment_status'=>$status ?: 'pending','paid_amount'=>$paidAmount,'requested_amount'=>$requestedAmount,'data'=>$row,'cached_success'=>($status==='paid')]);
}
if($api === 'register_resume_status'){
    $body = json_decode(file_get_contents('php://input'), true);
    $qrisId = trim((string)($body['qris_id'] ?? ''));
    $code = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)($body['storeId'] ?? '')));
    $pinIn = preg_replace('/[^0-9]/','', (string)($body['pin'] ?? ''));
    $mode = strtolower(trim((string)($body['mode'] ?? 'register')));
    if($mode !== 'renew') $mode = 'register';
    if($code==='') json_out(['ok'=>false,'msg'=>'Kode toko kosong'], 400);
    if(strlen($pinIn)!==4) json_out(['ok'=>false,'msg'=>'PIN harus 4 angka'], 400);

    $exists = in_array($code, $storeDb['stores'], true);
    $pinValid = $exists ? (pin_get($code) === $pinIn) : false;

    if($mode === 'register' && $exists && $pinValid){
      json_out(['ok'=>true,'success'=>false,'already_registered'=>true,'storeId'=>$code,'msg'=>'Toko sudah terdaftar. Lanjutkan pembayaran untuk menambah masa aktif 30 hari setelah pembayaran selesai']);
    }

    if($qrisId !== ''){
      $row = qrispy_find_payment($qrisId, 900);
      $cached = qrispy_payment_cache_get($qrisId);
      if(!$row && is_array($cached['row'] ?? null)) $row = $cached['row'];
      if($row && qrispy_payment_is_expired($row)) { promo_release_qris($qrisId); json_out(['ok'=>true,'success'=>false,'expired'=>true,'msg'=>'Pembayaran QRIS sudah melewati batas 15 menit. Pendaftaran toko dibatalkan.']); }
      if($row && qrispy_payment_is_success($row, qris_registration_amount())){
        qrispy_payment_cache_mark_success($qrisId, $row);
        promo_consume_reserved_by_qris($qrisId);
        $applied = qris_apply_log_get($qrisId);
        if($mode === 'renew'){
          if(!$exists || !$pinValid) json_out(['ok'=>true,'success'=>false,'msg'=>'Kode toko atau PIN perpanjang tidak valid']);
          if(is_array($applied) && (($applied['mode'] ?? '') === 'renew') && (($applied['storeId'] ?? '') === $code)) {
            $doneTs = (int)($applied['expiryTs'] ?? expiry_get_ts($code));
            json_out(['ok'=>true,'success'=>true,'already_applied'=>true,'storeId'=>$code,'expiryTs'=>$doneTs,'expiryAt'=>($doneTs>0?date('c',$doneTs):null),'msg'=>'Pembayaran sudah pernah diproses. Masa aktif toko bertambah 30 hari sekali saja setelah pembayaran selesai']);
          }
          $newTs = expiry_extend_days_ts($code, 30);
          expiry_set_ts($code, $newTs, ['source'=>'qris_payment', 'actor'=>$code, 'days'=>30]); premium_set($code, true);
          qris_apply_log_set($qrisId, ['mode'=>'renew','storeId'=>$code,'expiryTs'=>$newTs]);
          json_out(['ok'=>true,'success'=>true,'storeId'=>$code,'expiryTs'=>$newTs,'expiryAt'=>date('c',$newTs),'msg'=>'Pembayaran sudah berhasil. Masa aktif toko bertambah 30 hari setelah pembayaran selesai']);
        }
        if(is_array($applied) && (($applied['mode'] ?? '') === 'register') && (($applied['storeId'] ?? '') === $code)) {
          $doneTs = (int)($applied['expiryTs'] ?? expiry_get_ts($code));
          json_out(['ok'=>true,'success'=>true,'already_applied'=>true,'storeId'=>$code,'stores'=>$storeDb['stores'],'expiryTs'=>$doneTs,'expiryAt'=>($doneTs>0?date('c',$doneTs):null),'msg'=>'Pembayaran sudah pernah diproses. Akun premium tetap aktif 30 hari']);
        }
        $stores = $storeDb['stores']; if(!in_array($code, $stores, true)) $stores[] = $code; $newDb = write_store_db($stores);
        $newTs = registration_premium_expiry_ts();
        pin_set($code, $pinIn); expiry_set_ts($code, $newTs, ['source'=>'qris_payment', 'actor'=>$code, 'days'=>30]); premium_set($code, true);
        qris_apply_log_set($qrisId, ['mode'=>'register','storeId'=>$code,'expiryTs'=>$newTs]);
        json_out(['ok'=>true,'success'=>true,'storeId'=>$code,'stores'=>$newDb['stores'],'expiryTs'=>$newTs,'expiryAt'=>date('c',$newTs),'msg'=>'Pembayaran sudah berhasil. Akun premium aktif selama 30 hari dan pendaftaran dipulihkan']);
      }
    }
    json_out(['ok'=>true,'success'=>false,'msg'=>'Status pendaftaran belum berhasil']);
}
if($api === 'register_finalize'){
    $body = json_decode(file_get_contents('php://input'), true);
    $qrisId = trim((string)($body['qris_id'] ?? ''));
    $code = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)($body['storeId'] ?? '')));
    $pinIn = preg_replace('/[^0-9]/','', (string)($body['pin'] ?? ''));
    $mode = strtolower(trim((string)($body['mode'] ?? 'register')));
    if($mode !== 'renew') $mode = 'register';
    if($qrisId==='') json_out(['ok'=>false,'msg'=>'qris_id wajib'], 400);
    if($code==='') json_out(['ok'=>false,'msg'=>'Kode toko kosong'], 400);
    if(strlen($code) > 4) json_out(['ok'=>false,'msg'=>'Kode toko maksimal 4 karakter'], 400);
    if(strlen($pinIn)!==4) json_out(['ok'=>false,'msg'=>'PIN harus 4 angka'], 400);

    $exists = in_array($code, $storeDb['stores'], true);
    $pinValid = $exists ? (pin_get($code) === $pinIn) : false;

    if($mode === 'renew'){
      if(!$exists) json_out(['ok'=>false,'msg'=>'Kode toko belum terdaftar'], 404);
      if(!$pinValid) json_out(['ok'=>false,'msg'=>'PIN toko tidak sesuai untuk perpanjang'], 403);
    }else{
      if($exists){
        if($pinValid){
          json_out(['ok'=>true,'storeId'=>$code,'stores'=>$storeDb['stores'],'already_registered'=>true,'message'=>'Toko sudah terdaftar dan bisa langsung login']);
        }
        json_out(['ok'=>false,'msg'=>'Kode toko sudah terdaftar'], 409);
      }
    }

    $row = qrispy_find_payment($qrisId, 1200);
    $cached = qrispy_payment_cache_get($qrisId);
    if(!$row && is_array($cached['row'] ?? null)) $row = $cached['row'];
    if(!$row) json_out(['ok'=>false,'msg'=>'Pembayaran tidak ditemukan'], 404);
    if(qrispy_payment_is_expired($row)) { promo_release_qris($qrisId); json_out(['ok'=>false,'msg'=>'Pembayaran QRIS sudah melewati batas 15 menit. Pendaftaran toko dibatalkan.'], 400); }
    $expectedAmount = qris_registration_amount();
    if(!qrispy_payment_is_success($row, $expectedAmount)) json_out(['ok'=>false,'msg'=>'Pembayaran belum berhasil atau nominal tidak sesuai'], 400);
    qrispy_payment_cache_mark_success($qrisId, $row);
    promo_consume_reserved_by_qris($qrisId);

    $applied = qris_apply_log_get($qrisId);
    if($mode === 'renew'){
      if(is_array($applied) && (($applied['mode'] ?? '') === 'renew') && (($applied['storeId'] ?? '') === $code)) {
        $doneTs = (int)($applied['expiryTs'] ?? expiry_get_ts($code));
        json_out(['ok'=>true,'mode'=>'renew','storeId'=>$code,'premium'=>true,'already_applied'=>true,'expiryTs'=>$doneTs,'expiryAt'=>($doneTs>0?date('c',$doneTs):null),'message'=>'Pembayaran ini sudah pernah diproses. Masa aktif toko hanya bertambah 30 hari setelah pembayaran selesai']);
      }
      $newTs = expiry_extend_days_ts($code, 30);
      expiry_set_ts($code, $newTs, ['source'=>'qris_payment', 'actor'=>$code, 'days'=>30]); premium_set($code, true);
      qris_apply_log_set($qrisId, ['mode'=>'renew','storeId'=>$code,'expiryTs'=>$newTs]);
      json_out(['ok'=>true,'mode'=>'renew','storeId'=>$code,'premium'=>true,'expiryTs'=>$newTs,'expiryAt'=>date('c',$newTs),'message'=>'Perpanjang berhasil. Masa aktif toko bertambah 30 hari setelah pembayaran selesai']);
    }

    if(is_array($applied) && (($applied['mode'] ?? '') === 'register') && (($applied['storeId'] ?? '') === $code)) {
      $doneTs = (int)($applied['expiryTs'] ?? expiry_get_ts($code));
      json_out(['ok'=>true,'mode'=>'register','storeId'=>$code,'stores'=>$storeDb['stores'],'premium'=>true,'already_applied'=>true,'expiryTs'=>$doneTs,'expiryAt'=>($doneTs>0?date('c',$doneTs):null),'message'=>'Pembayaran ini sudah pernah diproses. Akun premium tetap aktif 30 hari']);
    }
    $stores = $storeDb['stores']; if(!in_array($code, $stores, true)) $stores[] = $code; $newDb = write_store_db($stores);
    $expiryTs = registration_premium_expiry_ts();
    pin_set($code, $pinIn); expiry_set_ts($code, $expiryTs, ['source'=>'qris_payment', 'actor'=>$code, 'days'=>30]); premium_set($code, true);
    qris_apply_log_set($qrisId, ['mode'=>'register','storeId'=>$code,'expiryTs'=>$expiryTs]);
    json_out(['ok'=>true,'mode'=>'register','storeId'=>$code,'stores'=>$newDb['stores'],'premium'=>true,'expiryTs'=>$expiryTs,'message'=>'Pendaftaran berhasil. Akun premium aktif selama 30 hari']);
}



  if($api === 'admin_newuser_key_generate'){
    if($me !== ADMIN_STORE_ID || !m604_is_developer_session()) json_out(["ok"=>false,"msg"=>"Forbidden"], 403);
    $body = json_decode(file_get_contents('php://input'), true);
    $duration = strtolower(trim((string)($body['duration'] ?? '2d')));
    $opt = newuser_key_duration_row($duration);
    newuser_keys_cleanup();
    $row = newuser_key_generate($duration);
    json_out(["ok"=>true,"storeId"=>"","key"=>$row['key'],"duration"=>$duration,"durationLabel"=>$opt['label'],"durationDays"=>(int)$opt['days'],"expiresTs"=>(int)$row['expiresTs'],"expiresAt"=>date('c',(int)$row['expiresTs'])]);
  }

  if($api === 'admin_newuser_key_list'){
    if($me !== ADMIN_STORE_ID || !m604_is_developer_session()) json_out(["ok"=>false,"msg"=>"Forbidden"], 403);
    $all = newuser_keys_cleanup();
    $items = array_values((array)($all['items'] ?? []));
    usort($items, function($a,$b){ return (int)($b['createdTs'] ?? 0) <=> (int)($a['createdTs'] ?? 0); });
    json_out(["ok"=>true,"items"=>$items,"serverTs"=>time()]);
  }

  if($api === 'admin_newuser_key_clear'){
    if($me !== ADMIN_STORE_ID || !m604_is_developer_session()) json_out(["ok"=>false,"msg"=>"Forbidden"], 403);
    newuser_keys_write_all([]);
    json_out(["ok"=>true,"items"=>[],"serverTs"=>time(),"msg"=>"Riwayat key NEW berhasil dihapus"]);
  }

if($api === 'admin_sogrand_key_generate'){
    if($me !== ADMIN_STORE_ID || !m604_is_developer_session()) json_out(["ok"=>false,"msg"=>"Forbidden"], 403);
    sogrand_cleanup_all();
    $row = sogrand_key_generate();
    json_out(["ok"=>true,"storeId"=>"","key"=>$row['key'],"expiresTs"=>(int)$row['expiresTs'],"expiresAt"=>date('c',(int)$row['expiresTs']),"redirect"=>(string)($_SERVER['PHP_SELF'] ?? 'index.php') . '?page=sogrand_taskforce']);
  }

  if($api === 'admin_sogrand_key_list'){
    if($me !== ADMIN_STORE_ID || !m604_is_developer_session()) json_out(["ok"=>false,"msg"=>"Forbidden"], 403);
    sogrand_cleanup_all();
    $all = sogrand_keys_cleanup();
    $items = array_values((array)($all['items'] ?? []));
    usort($items, function($a,$b){ return (int)($b['createdTs'] ?? 0) <=> (int)($a['createdTs'] ?? 0); });
    json_out(["ok"=>true,"items"=>$items,"serverTs"=>time()]);
  }

  if($api === 'admin_sogrand_key_clear'){
    if($me !== ADMIN_STORE_ID || !m604_is_developer_session()) json_out(["ok"=>false,"msg"=>"Forbidden"], 403);
    sogrand_keys_write_all([]);
    json_out(["ok"=>true,"items"=>[],"serverTs"=>time(),"msg"=>"Riwayat key berhasil dihapus"]);
  }

  if($api === 'admin_sogrand_user_list'){
    if($me !== ADMIN_STORE_ID || !m604_is_developer_session()) json_out(["ok"=>false,"msg"=>"Forbidden"], 403);
    $all = sogrand_users_cleanup();
    $items = array_values((array)($all['users'] ?? []));
    usort($items, function($a,$b){ return strcmp((string)($a['storeId'] ?? ''), (string)($b['storeId'] ?? '')); });
    json_out(["ok"=>true,"items"=>$items,"serverTs"=>time()]);
  }

  if($api === 'sogrand_user_status'){
    $code = (string)($me ?: '');
    $u = sogrand_user_get($code);
    json_out(["ok"=>true,"isSogrand"=>(bool)$u,"storeId"=>$code,"serverTs"=>time(),"expiresTs"=>(int)($u['expiresTs'] ?? 0),"remainingSec"=>sogrand_user_remaining($code)]);
  }

  if($api === 'login'){
    cleanup_all_expired_stores();
    clearstatcache();
    $storeDb = read_store_db();
    $body = json_decode(file_get_contents('php://input'), true);
    $code = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)($body['storeId'] ?? '')));
    $pinIn = preg_replace('/[^0-9]/','', (string)($body['pin'] ?? ''));
    $otpIn = preg_replace('/[^A-Za-z0-9]/','', (string)($body['otp'] ?? ''));
    // Login admin sekarang memakai OTP/password dari konfigurasi proxy.
    // Tidak bergantung pada kode toko user lain.
    if($otpIn !== '' && hash_equals((string)admin_login_password(), (string)$otpIn)){
      if(!cookie_set_session(ADMIN_STORE_ID, false, 'developer')) json_out(["ok"=>false,"msg"=>"Sesi admin gagal dibuat. Periksa izin tulis folder aplikasi."], 500);
      cookie_set_last_store(ADMIN_STORE_ID);
      $_SESSION['admin_ok'] = true;
      $_SESSION['admin_ok_ts'] = time();
      $_SESSION['admin_ok_until'] = time() + ADMIN_SESSION_LIFETIME_SEC;
      json_out(["ok"=>true,"storeId"=>ADMIN_STORE_ID,"isAdmin"=>true,"isAdmin2"=>false,"openAdmin"=>false]);
    }
    if($code==='' && $pinIn==='' && $otpIn !== ''){
      json_out(["ok"=>false,"msg"=>"OTP admin salah"], 401);
    }
    if($code==='') json_out(["ok"=>false,"msg"=>"Kode toko kosong"], 400);
    if(strlen($code) > 4) json_out(["ok"=>false,"msg"=>"Kode toko maksimal 4 karakter"], 400);
    if($otpIn !== '' && strpos(strtoupper($otpIn), 'NEW') === 0){
      $newPin = ($pinIn === '') ? DEFAULT_PIN : $pinIn;
      if(strlen($newPin)!==4) json_out(["ok"=>false,"msg"=>"PIN harus 4 angka, atau kosongkan untuk PIN default 0000"], 400);
      newuser_keys_cleanup();
      list($okNew, $newMsg, $newRow) = newuser_key_use($code, $otpIn);
      if(!$okNew) json_out(["ok"=>false,"msg"=>$newMsg], 403);
      $stores = $storeDb['stores'];
      if(!in_array($code, $stores, true)) $stores[] = $code;
      write_store_db($stores);
      pin_set($code, $newPin);
      premium_set($code, true);
      $expiryTs = (int)($newRow['expiresTs'] ?? (time() + (int)(newuser_key_duration_row($newRow['duration'] ?? '2d')['seconds'])));
      $newUserDays = max(1, (int)ceil(max(0, $expiryTs - time()) / ONE_DAY_SEC));
      expiry_set_ts($code, $expiryTs, ['source'=>'newuser_key', 'actor'=>$code, 'days'=>$newUserDays]);
      if(!cookie_set_session($code, false)) json_out(["ok"=>false,"msg"=>"Sesi login gagal dibuat. Periksa izin tulis folder aplikasi."], 500);
      cookie_set_last_store($code);
      presence_touch($code, true);
      json_out(["ok"=>true,"storeId"=>$code,"isAdmin"=>false,"isAdmin2"=>false,"expiryTs"=>$expiryTs,"remainingDays"=>(int)ceil(max(0,$expiryTs-time())/ONE_DAY_SEC),"pinSaved"=>$newPin]);
    }
    if(is_store_expired($code) && !($pinIn === '' && $otpIn !== '')){
      enforce_expiry_cleanup($code);
      $storeDb = read_store_db();
      json_out(["ok"=>false,"msg"=>"Kode toko sudah expired dan otomatis dihapus."], 403);
    }
    if($pinIn === '' && $otpIn !== ''){
      sogrand_cleanup_all();
      list($okKey, $keyMsg, $keyRow) = sogrand_key_use($code, $otpIn);
      if(!$okKey) json_out(["ok"=>false,"msg"=>$keyMsg], 403);
      // User Key Grand disimpan di JSON terpisah agar tidak merusak user Admin dengan kode toko sama.
      $keyExpiry = (int)($keyRow['expiresTs'] ?? (time()+SOGRAND_KEY_TTL_SEC));
      sogrand_user_set($code, (string)($keyRow['key'] ?? $otpIn), $keyExpiry);
      if(!cookie_set_session($code, false)) json_out(["ok"=>false,"msg"=>"Sesi login gagal dibuat. Periksa izin tulis folder aplikasi."], 500);
      cookie_set_last_store($code);
      presence_touch($code, true);
      json_out(["ok"=>true,"storeId"=>$code,"isAdmin"=>false,"isAdmin2"=>false,"redirect"=>((string)($_SERVER['PHP_SELF'] ?? 'index.php') . '?page=sogrand_taskforce'),"sograndRemainingSec"=>sogrand_user_remaining($code)]);
    }
    if(strlen($pinIn)!==4) json_out(["ok"=>false,"msg"=>"PIN harus 4 angka"], 400);
    if($code === ADMIN_STORE_ID){
      // PIN 2727 = Developer/Admin.
      // PIN 0000 dan PIN lain untuk M604 diproses sebagai user umum di bawah.
      if(hash_equals((string)admin_developer_pin(), (string)$pinIn)){
        if(!cookie_set_session(ADMIN_STORE_ID, false, 'developer')) json_out(["ok"=>false,"msg"=>"Sesi admin gagal dibuat. Periksa izin tulis folder aplikasi."], 500);
        cookie_set_last_store(ADMIN_STORE_ID);
        $_SESSION['admin_ok'] = true;
        $_SESSION['admin_ok_ts'] = time();
        $_SESSION['admin_ok_until'] = time() + ADMIN_SESSION_LIFETIME_SEC;
        json_out(["ok"=>true,"storeId"=>ADMIN_STORE_ID,"isAdmin"=>true,"isAdmin2"=>false,"openAdmin"=>false,"m604Role"=>"developer"]);
      }
      // Lanjut validasi normal: M604 PIN 0000 menjadi user biasa.
    }
    sogrand_cleanup_all();
    $sgUser = sogrand_user_get($code);
    if($sgUser && hash_equals((string)($sgUser['pin'] ?? SOGRAND_PIN), $pinIn)){
      sogrand_user_touch($code);
      if(!cookie_set_session($code, false)) json_out(["ok"=>false,"msg"=>"Sesi login gagal dibuat. Periksa izin tulis folder aplikasi."], 500);
      cookie_set_last_store($code);
      presence_touch($code, true);
      json_out(["ok"=>true,"storeId"=>$code,"isAdmin"=>false,"isAdmin2"=>false,"redirect"=>((string)($_SERVER['PHP_SELF'] ?? 'index.php') . '?page=sogrand_taskforce'),"sograndRemainingSec"=>sogrand_user_remaining($code)]);
    }
    if(!in_array($code, $storeDb['stores'], true)) json_out(["ok"=>false,"msg"=>"Kode toko tidak terdaftar"], 403);
    $pinTrue = pin_get($code);
    if($pinIn !== $pinTrue) json_out(["ok"=>false,"msg"=>"PIN salah"], 403);
    // M604 PIN 0000 adalah user biasa dan wajib memiliki masa expired seperti user lain.
    if($code === ADMIN_STORE_ID && !hash_equals((string)admin_developer_pin(), (string)$pinIn) && (int)expiry_get_ts($code) <= 0){
      expiry_set_ts($code, expiry_days_from_now_ts(30), ['source'=>'account_default', 'actor'=>$code, 'days'=>30]);
    }
    if(!cookie_set_session($code, false)) json_out(["ok"=>false,"msg"=>"Sesi login gagal dibuat. Periksa izin tulis folder aplikasi."], 500);
    cookie_set_last_store($code);
    presence_touch($code, true);
    $expiryWarn = expiry_warning_payload($code);
    json_out(["ok"=>true,"storeId"=>$code,"isAdmin"=>false, "isAdmin2"=>admin2_get($code), "expiryWarning"=>$expiryWarn, "remainingDays"=>(int)expiry_remaining_days($code), "m604Role"=>($code===ADMIN_STORE_ID?"user":"")]);
  }

  if($api === 'logout'){
    if($me) presence_set_offline($me);
    cookie_clear_session();
    json_out(["ok"=>true]);
  }

  if($api === 'profile_change_pin'){
    if(!$me) json_out(["ok"=>false,"msg"=>"Silakan login ulang."], 401);
    $body = json_decode(file_get_contents('php://input'), true);
    $oldPin = preg_replace('/[^0-9]/','', (string)($body['oldPin'] ?? ''));
    $newPin = preg_replace('/[^0-9]/','', (string)($body['newPin'] ?? ''));
    if(strlen($oldPin)!==4 || strlen($newPin)!==4) json_out(["ok"=>false,"msg"=>"PIN wajib 4 angka."], 400);
    if($me === ADMIN_STORE_ID && m604_is_developer_session()) json_out(["ok"=>false,"msg"=>"PIN developer hanya bisa diganti dari halaman admin."], 403);
    if(!in_array($me, $storeDb['stores'], true)) json_out(["ok"=>false,"msg"=>"Kode toko tidak terdaftar."], 403);
    $pinTrue = pin_get($me);
    if(!hash_equals((string)$pinTrue, (string)$oldPin)) json_out(["ok"=>false,"msg"=>"PIN lama salah."], 403);
    pin_set($me, $newPin);
    json_out(["ok"=>true,"storeId"=>$me,"msg"=>"PIN berhasil diganti."]);
  }

  // ADMIN ONLY
  if($api === 'admin_impersonate'){
    if($me !== ADMIN_STORE_ID || !m604_is_developer_session()) json_out(["ok"=>false,"msg"=>"Forbidden"], 403);
    $body = json_decode(file_get_contents('php://input'), true);
    $code = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)($body['storeId'] ?? '')));
    if($code==='') json_out(["ok"=>false,"msg"=>"Kode toko wajib"], 400);
    if($code===ADMIN_STORE_ID) json_out(["ok"=>false,"msg"=>"Admin sudah berada di akun admin"], 400);
    if(is_store_expired($code)){
      enforce_expiry_cleanup($code);
      json_out(["ok"=>false,"msg"=>"Kode toko sudah expired dan otomatis dihapus."], 403);
    }
    if(!in_array($code, $storeDb['stores'], true)) json_out(["ok"=>false,"msg"=>"Kode toko tidak terdaftar"], 404);
    presence_set_offline($me);
    if(!impersonation_start($code)) json_out(["ok"=>false,"msg"=>"Gagal masuk sebagai user"], 500);
    presence_touch($code, true);
    json_out(["ok"=>true,"storeId"=>$code,"redirect"=>(string)($_SERVER['PHP_SELF'] ?? 'index.php')]);
  }
  if($api === 'admin_impersonation_exit'){
    if(!impersonation_is_active()) json_out(["ok"=>false,"msg"=>"Mode admin tidak aktif"], 400);
    $target = $me;
    presence_set_offline($target);
    if(!impersonation_stop()) json_out(["ok"=>false,"msg"=>"Gagal kembali ke admin"], 500);
    $_SESSION['admin_ok'] = true;
    $_SESSION['admin_ok_ts'] = time();
    $_SESSION['admin_ok_until'] = time() + ADMIN_SESSION_LIFETIME_SEC;
    json_out(["ok"=>true,"storeId"=>ADMIN_STORE_ID,"redirect"=>((string)($_SERVER['PHP_SELF'] ?? 'index.php') . '?open_admin=1')]);
  }
  if($api === 'admin_list'){
    if($me !== ADMIN_STORE_ID || !m604_is_developer_session()) json_out(["ok"=>false,"msg"=>"Forbidden"], 403);
    // FIX: halaman admin hanya membaca daftar user; jangan lakukan cleanup agresif di refresh realtime
    // karena bisa membuat user tampak hilang/muncul ketika file JSON sedang bergantian ditulis.
    clearstatcache();
    $storeDb = read_store_db();
    $ex = expiry_read_all();
    $pn = pin_read_all();
      $pr = premium_read_all();
    $ad2 = admin2_read_all();
    $inviteSaldo = invite_points_read_all();
    $presence = presence_get_status_map($storeDb['stores']);
    json_out(["ok"=>true, "stores"=>$storeDb['stores'], "updatedAt"=>$storeDb['updatedAt'] ?? null, "createdMap"=>($storeDb['createdMap'] ?? []), "expiryMap"=>($ex["stores"] ?? []), "pinMap"=>($pn["stores"] ?? []), "premiumMap"=>($pr["stores"] ?? []), "pointMap"=>(is_array($inviteSaldo["points"] ?? null) ? $inviteSaldo["points"] : []), "invitePendingCount"=>count(array_filter((array)($inviteSaldo["pending"] ?? []), function($r){ return is_array($r) && (($r["status"] ?? "pending") === "pending"); })), "admin2Map"=>($ad2["stores"] ?? []), "presenceMap"=>$presence, "storeNameMap"=>[], "serverTs"=>time()]);
  }
  if($api === 'admin_user_activity'){
    if($me !== ADMIN_STORE_ID || !m604_is_developer_session()) json_out(["ok"=>false,"msg"=>"Forbidden"], 403);
    $code = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)($_GET['storeId'] ?? '')));
    if($code === '') json_out(["ok"=>false,"msg"=>"Kode toko tidak valid"], 400);
    $dbNow = read_store_db();
    if(!in_array($code, (array)($dbNow['stores'] ?? []), true)) json_out(["ok"=>false,"msg"=>"User tidak ditemukan"], 404);
    $statusMap = presence_get_status_map([$code]);
    $presenceRow = is_array($statusMap[$code] ?? null) ? $statusMap[$code] : [];
    $online = !empty($presenceRow['online']);
    $activityTitle = presence_activity_text($presenceRow['activityTitle'] ?? '', 80);
    if($online && $activityTitle === '') $activityTitle = 'Beranda';
    json_out([
      "ok"=>true,
      "storeId"=>$code,
      "online"=>$online,
      "activityTitle"=>$activityTitle,
      "activityKey"=>presence_activity_text($presenceRow['activityKey'] ?? '', 60),
      "activityUpdatedTs"=>(int)($presenceRow['activityUpdatedTs'] ?? 0),
      "lastSeenTs"=>(int)($presenceRow['lastSeenTs'] ?? 0),
      "serverTs"=>time(),
    ]);
  }
  if($api === 'admin_top_online'){
    if($me !== ADMIN_STORE_ID || !m604_is_developer_session()) json_out(["ok"=>false,"msg"=>"Forbidden"], 403);
    json_out(array_merge(["ok"=>true], top_online_admin_list(5), ["serverTs"=>time()]));
  }
  if($api === 'admin_store_names_batch'){
    if($me !== ADMIN_STORE_ID || !m604_is_developer_session()) json_out(["ok"=>false,"msg"=>"Forbidden"], 403);
    $body = json_decode(file_get_contents('php://input'), true);
    $stores = is_array($body) ? ($body['stores'] ?? []) : [];
    json_out(["ok"=>true, "storeNameMap"=>store_names_fetch_batch($stores), "serverTs"=>time()]);
  }
  if($api === 'admin_add'){
    if($me !== ADMIN_STORE_ID || !m604_is_developer_session()) json_out(["ok"=>false,"msg"=>"Forbidden"], 403);
    $body = json_decode(file_get_contents('php://input'), true);
    $code = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)($body['storeId'] ?? '')));
    if(strlen($code) < 2 || strlen($code) > 6) json_out(["ok"=>false,"msg"=>"Kode toko tidak valid"], 400);
    $stores = $storeDb['stores'];
    $isNew = !in_array($code, $stores, true);
    if(!$isNew) json_out(["ok"=>false,"msg"=>"Kode toko telah ada"], 400);
    if($isNew) $stores[] = $code;
    $newDb = write_store_db($stores);
    if($isNew){ premium_set($code, true); }
    expiry_set_ts($code, 0);
    // default pin: 0000 (boleh diganti di admin)
    pin_set($code, DEFAULT_PIN);
    json_out(["ok"=>true, "stores"=>$newDb['stores']]);
  }
  if($api === 'admin_delete'){
    if($me !== ADMIN_STORE_ID || !m604_is_developer_session()) json_out(["ok"=>false,"msg"=>"Forbidden"], 403);
    $body = json_decode(file_get_contents('php://input'), true);
    $code = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)($body['storeId'] ?? '')));
    if($code === ADMIN_STORE_ID) json_out(["ok"=>false,"msg"=>"Admin tidak boleh dihapus"], 400);
    $stores = array_values(array_filter($storeDb['stores'], fn($s)=>$s!==$code));
    $newDb = write_store_db($stores);
    expiry_set_ts($code, 0);
    pin_delete($code);
    premium_delete($code);
    admin2_delete($code);
    oh979_delete($code);
    plano_delete_store($code);
    presence_set_offline($code);
    json_out(["ok"=>true, "stores"=>$newDb['stores']]);
  }

  if($api === 'admin2_list'){
    if($me !== ADMIN_STORE_ID || !m604_is_developer_session()) json_out(["ok"=>false,"msg"=>"Forbidden"], 403);
    $ad2 = admin2_read_all();
    json_out(["ok"=>true, "stores"=>array_keys($ad2["stores"] ?? [])]);
  }
  if($api === 'admin2_set'){
    if($me !== ADMIN_STORE_ID || !m604_is_developer_session()) json_out(["ok"=>false,"msg"=>"Forbidden"], 403);
    $body = json_decode(file_get_contents('php://input'), true);
    $code = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)($body['storeId'] ?? '')));
    $val  = isset($body['admin2']) ? (bool)$body['admin2'] : false;
    if($code==='') json_out(["ok"=>false,"msg"=>"Kode toko wajib"], 400);
    if($code===ADMIN_STORE_ID) json_out(["ok"=>false,"msg"=>"Admin utama tidak perlu Admin2"], 400);
    if(!in_array($code, $storeDb['stores'], true)) json_out(["ok"=>false,"msg"=>"Kode toko tidak terdaftar"], 400);
    admin2_set($code, $val);
    $ad2 = admin2_read_all();
    json_out(["ok"=>true, "storeId"=>$code, "admin2"=>$val, "admin2Map"=>($ad2["stores"] ?? [])]);
  }
  if($api === 'admin2_add_user'){
    if(!$me || !admin2_get($me)) json_out(["ok"=>false,"msg"=>"Forbidden"], 403);
    $body = json_decode(file_get_contents('php://input'), true);
    $code = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)($body['storeId'] ?? '')));
    $code = substr($code, 0, 4);
    $pin = preg_replace('/[^0-9]/','', (string)($body['pin'] ?? ''));
    $pin = substr($pin, 0, 4);
    if($code === '' || strlen($code) > 4) json_out(["ok"=>false,"msg"=>"Kode toko wajib maksimal 4 angka / huruf"], 400);
    if(strlen($pin) !== 4) json_out(["ok"=>false,"msg"=>"PIN wajib 4 angka"], 400);
    if(in_array($code, $storeDb['stores'], true)) json_out(["ok"=>false,"msg"=>"Kode toko telah ada"], 400);
    $stores = $storeDb['stores'];
    $stores[] = $code;
    $newDb = write_store_db($stores);
    premium_set($code, true);
    pin_set($code, $pin);
    $ts = expiry_days_from_now_ts(2);
    expiry_set_ts($code, $ts, ['source'=>'admin2', 'actor'=>$me, 'days'=>2]);
    json_out(["ok"=>true, "stores"=>$newDb['stores'], "storeId"=>$code, "expiryTs"=>$ts, "expiryDays"=>2, "premium"=>true, "pin"=>$pin]);
  }

  if($api === 'admin_get_pin'){
    if($me !== ADMIN_STORE_ID || !m604_is_developer_session()) json_out(["ok"=>false,"msg"=>"Forbidden"], 403);
    $storeId = isset($_GET['storeId']) ? strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$_GET['storeId'])) : '';
    if($storeId==='') json_out(["ok"=>false,"msg"=>"storeId wajib"], 400);
    $pin = pin_get($storeId);
    json_out(["ok"=>true, "storeId"=>$storeId, "pin"=>$pin]);
  }
  if($api === 'admin_set_pin'){
    if($me !== ADMIN_STORE_ID || !m604_is_developer_session()) json_out(["ok"=>false,"msg"=>"Forbidden"], 403);
    $body = json_decode(file_get_contents('php://input'), true);
    $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)($body['storeId'] ?? '')));
    $pin = preg_replace('/[^0-9]/','', (string)($body['pin'] ?? ''));
    if($storeId==='') json_out(["ok"=>false,"msg"=>"storeId wajib"], 400);
    if(strlen($pin)!==4) json_out(["ok"=>false,"msg"=>"PIN harus 4 angka"], 400);
    if(!in_array($storeId, $storeDb['stores'], true)) json_out(["ok"=>false,"msg"=>"Kode toko tidak terdaftar"], 400);
    pin_set($storeId, $pin);
    json_out(["ok"=>true, "storeId"=>$storeId, "pin"=>$pin]);
  }
  if($api === 'admin_set_premium'){
    if($me !== ADMIN_STORE_ID || !m604_is_developer_session()) json_out(["ok"=>false,"msg"=>"Forbidden"], 403);
    $body = json_decode(file_get_contents('php://input'), true);
    $code = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)($body['storeId'] ?? '')));
    $val  = isset($body['premium']) ? (bool)$body['premium'] : false;
    if($code==='') json_out(["ok"=>false,"msg"=>"Kode toko wajib"], 400);
    premium_set($code, $val);
    json_out(["ok"=>true, "storeId"=>$code, "premium"=>$val]);
  }



  if($api === 'admin_get_expiry'){
    if($me !== ADMIN_STORE_ID || !m604_is_developer_session()) json_out(["ok"=>false,"msg"=>"Forbidden"], 403);
    $storeId = isset($_GET['storeId']) ? strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$_GET['storeId'])) : '';
    if($storeId==='') json_out(["ok"=>false,"msg"=>"storeId wajib"], 400);
    $ts = expiry_get_ts($storeId);
    json_out(["ok"=>true, "storeId"=>$storeId, "expiryTs"=>$ts]);
  }

  if($api === 'admin_set_expiry'){
    if($me !== ADMIN_STORE_ID || !m604_is_developer_session()) json_out(["ok"=>false,"msg"=>"Forbidden"], 403);
    $body = json_decode(file_get_contents('php://input'), true);
    if(!is_array($body)) $body = [];
    $storeId = strtoupper(preg_replace('/[^A-Z0-9]/','', (string)($body['storeId'] ?? '')));
    // M604 memiliki dua mode. Sesi developer tetap unlimited melalui role sesi,
    // sedangkan akun M604 PIN 0000 memakai data expired seperti user biasa.
    // Karena itu kode toko M604 tetap boleh disimpan atau diubah expired-nya.
    $dateStr = trim((string)($body['date'] ?? '')); // kompatibilitas lama: YYYY-MM-DD (akhir hari)
    $daysIn = isset($body['days']) ? (int)$body['days'] : null;
    $monthsIn = isset($body['months']) ? (int)$body['months'] : null;
    if($storeId==='') json_out(["ok"=>false,"msg"=>"storeId wajib"], 400);

    if($daysIn !== null || $monthsIn !== null){
      $days = max(0, min(3660, (int)$daysIn));
      $months = max(0, min(120, (int)$monthsIn));
      if($days <= 0 && $months <= 0){
        expiry_set_ts($storeId, 0);
        json_out(["ok"=>true, "storeId"=>$storeId, "expiryTs"=>0]);
      }
      $tz = new DateTimeZone('Asia/Jakarta');
      // Tambahkan durasi dari expired saat ini jika masih aktif; jika belum ada/sudah lewat, mulai dari sekarang.
      $currentTs = expiry_get_ts($storeId);
      $baseTs = ($currentTs > time()) ? $currentTs : time();
      $dt = new DateTime('@' . $baseTs);
      $dt->setTimezone($tz);
      if($months > 0) $dt->modify('+' . $months . ' months');
      if($days > 0) $dt->modify('+' . $days . ' days');
      $dt->setTime(23, 59, 59);
      $ts = $dt->getTimestamp();
      expiry_set_ts($storeId, $ts, ['source'=>'admin', 'actor'=>$me, 'days'=>$days, 'months'=>$months]);
      notif_add_message('Expired Ditambahkan', 'Admin menambahkan expired akun Anda sampai '.date('d/m/Y H:i',$ts).' WIB.', $storeId);
      json_out(["ok"=>true, "storeId"=>$storeId, "expiryTs"=>$ts, "days"=>$days, "months"=>$months]);
    }

    if($dateStr === '' || $dateStr === '0'){
      expiry_set_ts($storeId, 0);
      json_out(["ok"=>true, "storeId"=>$storeId, "expiryTs"=>0]);
    }

    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) json_out(["ok"=>false,"msg"=>"Isi jumlah hari/bulan atau format tanggal YYYY-MM-DD"], 400);

    // set ke akhir hari Asia/Jakarta
    $ts = expiry_ts_from_date_end($dateStr);
    if($ts===false) json_out(["ok"=>false,"msg"=>"Tanggal tidak valid"], 400);

    expiry_set_ts($storeId, $ts, ['source'=>'admin_calendar', 'actor'=>$me]);
    notif_add_message('Expired Diperbarui', 'Admin memperbarui expired akun Anda sampai '.date('d/m/Y H:i',$ts).' WIB.', $storeId);
    json_out(["ok"=>true, "storeId"=>$storeId, "expiryTs"=>$ts]);
  }

  if($api === 'admin_expiry_history'){
    $me = cookie_read_session();
    if($me !== ADMIN_STORE_ID || !m604_is_developer_session()) json_out(["ok"=>false,"msg"=>"Forbidden"], 403);
    if(!admin_report_session_unlocked()) json_out(["ok"=>false,"msg"=>"Masukkan password laporan terlebih dahulu"], 401);
    $all = expiry_history_read_all();
    $items = is_array($all['items'] ?? null) ? $all['items'] : [];
    usort($items, function($a, $b){
      return (int)($b['createdTs'] ?? 0) <=> (int)($a['createdTs'] ?? 0);
    });
    $items = array_slice($items, 0, 1000);
    json_out([
      "ok"=>true,
      "history"=>array_values($items),
      "items"=>array_values($items),
      "count"=>count($items),
      "updatedAt"=>(string)($all['updatedAt'] ?? '')
    ]);
  }

  if($api === 'admin_expiry_history_delete'){
    $me = cookie_read_session();
    if($me !== ADMIN_STORE_ID || !m604_is_developer_session()) json_out(["ok"=>false,"msg"=>"Forbidden"], 403);
    if(!admin_report_session_unlocked()) json_out(["ok"=>false,"msg"=>"Masukkan password laporan terlebih dahulu"], 401);
    $body = json_decode(file_get_contents('php://input'), true);
    if(!is_array($body)) $body = [];
    $deleteAll = !empty($body['all']);
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', is_scalar($body['id'] ?? null) ? (string)$body['id'] : '');
    if(!$deleteAll && $id === '') json_out(["ok"=>false,"msg"=>"ID riwayat wajib"], 400);
    $saved = expiry_history_delete($deleteAll ? '' : $id);
    $remaining = is_array($saved['items'] ?? null) ? count($saved['items']) : 0;
    json_out(["ok"=>true, "deletedAll"=>$deleteAll, "deletedId"=>$deleteAll ? null : $id, "remaining"=>$remaining]);
  }


  if($api === 'admin_adjust_point'){
    if($me !== ADMIN_STORE_ID) json_out(["ok"=>false,"msg"=>"Forbidden / hanya admin yang boleh mengubah point."], 403);
    $body = json_decode(file_get_contents('php://input'), true);
    if(!is_array($body)) $body = [];
    $storeId = invite_norm_store($body['storeId'] ?? '');
    $amount = max(0, (int)($body['amount'] ?? 0));
    $mode = strtolower((string)($body['mode'] ?? 'add'));
    if($storeId === '' || strlen($storeId) > 4) json_out(["ok"=>false,"msg"=>"Kode toko tidak valid."], 400);
    if($amount <= 0) json_out(["ok"=>false,"msg"=>"Jumlah point wajib lebih dari 0."], 400);
    if($amount > 100000) json_out(["ok"=>false,"msg"=>"Jumlah point terlalu besar."], 400);
    if($mode !== 'add' && $mode !== 'subtract' && $mode !== 'kurang') json_out(["ok"=>false,"msg"=>"Mode tidak valid."], 400);
    $all = invite_points_read_all();
    $current = max(0, (int)($all['points'][$storeId] ?? 0));
    $delta = ($mode === 'add') ? $amount : -$amount;
    $newPoints = max(0, $current + $delta);
    $all['points'][$storeId] = $newPoints;
    $all['logs'][] = [
      'type'=>'admin_point_adjust',
      'admin'=>invite_norm_store($me),
      'storeId'=>$storeId,
      'mode'=>($delta >= 0 ? 'add' : 'subtract'),
      'amount'=>$amount,
      'oldPoints'=>$current,
      'newPoints'=>$newPoints,
      'createdAt'=>date('c')
    ];
    invite_points_write_all($all);
    json_out(["ok"=>true,"storeId"=>$storeId,"points"=>$newPoints,"oldPoints"=>$current,"amount"=>$amount,"mode"=>($delta >= 0 ? 'add' : 'subtract')]);
  }

  if($api === 'invite_points_status'){
    if(!$me) json_out(["ok"=>false,"msg"=>"Silakan login ulang"], 401);
    $payload = invite_points_status_payload($me);
    json_out(["ok"=>true,"storeId"=>$me,"points"=>$payload['points'],"referrals"=>$payload['referrals']]);
  }

  if($api === 'invite_referral_save'){
    if(!$me) json_out(["ok"=>false,"msg"=>"Silakan login ulang"], 401);
    $body = json_decode(file_get_contents('php://input'), true);
    if(!is_array($body)) $body = [];
    $target = invite_norm_store($body['target'] ?? '');
    $pin = invite_norm_pin($body['pin'] ?? '');
    if($target==='' || strlen($target) > 4) json_out(["ok"=>false,"msg"=>"Toko saya undang wajib diisi maksimal 4 huruf/angka."], 400);
    if($pin==='' || !preg_match('/^\d{1,4}$/', $pin)) json_out(["ok"=>false,"msg"=>"PIN wajib angka maksimal 4 digit."], 400);
    [$ok,$msg] = invite_referral_register($me, $target, $pin, $expectedAmount, $qrisId, (string)($row['payment_reference'] ?? $row['reference'] ?? ''), (string)($row['paid_at'] ?? $row['paidAt'] ?? $row['payment_time'] ?? $row['settlement_time'] ?? date('c')));
    $payload = invite_points_status_payload($me);
    json_out(["ok"=>$ok,"msg"=>$msg,"storeId"=>$me,"target"=>$target,"points"=>$payload['points'],"referrals"=>$payload['referrals']], $ok ? 200 : 400);
  }


  if($api === 'invite_store_check'){
    if(!$me) json_out(["ok"=>false,"msg"=>"Silakan login ulang"], 401);
    $target = invite_norm_store($_GET['target'] ?? '');
    if($target==='' || strlen($target) > 4) json_out(["ok"=>false,"msg"=>"Kode toko wajib diisi maksimal 4 huruf/angka."], 400);
    $db = read_store_db();
    $stores = array_values((array)($db['stores'] ?? []));
    $registered = in_array($target, $stores, true);
    $detail = function_exists('store_detail_fetch_cached') ? store_detail_fetch_cached($target, 86400) : null;
    $name = '';
    if(is_array($detail)){
      $name = trim((string)($detail['header2'] ?? ''));
      if($name==='') $name = trim((string)($detail['header5'] ?? ''));
    }
    json_out([
      "ok"=>true,
      "target"=>$target,
      "name"=>$name !== '' ? $name : ('TOKO '.$target),
      "registered"=>$registered,
      "status"=>$registered ? "sudah terdaftar" : "belum terdaftar",
      "msg"=>$registered ? "Kode toko sudah terdaftar di JSON." : "Kode toko belum terdaftar di JSON."
    ]);
  }


  if($api === 'invite_generate_qris'){
    if(!$me) json_out(["ok"=>false,"msg"=>"Silakan login ulang"], 401);
    $body = json_decode(file_get_contents('php://input'), true);
    if(!is_array($body)) $body = [];
    $target = invite_norm_store($body['target'] ?? '');
    $pin = invite_norm_pin($body['pin'] ?? '');
    if($target==='' || strlen($target) > 4) json_out(["ok"=>false,"msg"=>"Kode toko wajib diisi maksimal 4 huruf/angka."], 400);
    if($target === invite_norm_store($me)) json_out(["ok"=>false,"msg"=>"Toko undangan tidak boleh sama dengan toko saya."], 400);
    if($pin==='' || !preg_match('/^\d{1,4}$/', $pin)) json_out(["ok"=>false,"msg"=>"PIN wajib angka maksimal 4 digit."], 400);
    $db = read_store_db();
    if(in_array($target, array_values((array)($db['stores'] ?? [])), true)) json_out(["ok"=>false,"msg"=>"Kode toko yang diundang sudah terdaftar di JSON."], 400);
    $amount = qris_registration_amount();
    $paymentRef = 'INVITE-' . invite_norm_store($me) . '-' . $target . '-' . date('YmdHis');
    $returnUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?');
    $res = qrispy_request('POST', '/api/payment/qris/generate', [
      'amount' => $amount,
      'payment_reference' => $paymentRef,
      'return_url' => $returnUrl,
    ]);
    $json = $res['json'] ?? null;
    $data = is_array($json['data'] ?? null) ? $json['data'] : (is_array($json) ? $json : []);
    if(empty($data['qris_image_url']) && empty($data['qris_image_base64'])){
      json_out(["ok"=>false,"msg"=>"Gagal membuat QRIS.","debug_http_code"=>(int)($res['http_code'] ?? 0),"response"=>$json], 502);
    }
    $data['amount'] = (int)($data['amount'] ?? $amount);
    $data['payment_reference'] = (string)($data['payment_reference'] ?? $paymentRef);
    $data['target'] = $target;
    $data['pin'] = $pin;
    if(empty($data['qris_id']) && !empty($data['id'])) $data['qris_id'] = (string)$data['id'];
    if(empty($data['expires_at']) && empty($data['expired_at'])) $data['expires_at'] = date('c', time() + 900);
    $data['expiresAt'] = (strtotime((string)($data['expires_at'] ?? $data['expired_at'] ?? '')) ?: (time()+900)) * 1000;
    // Simpan QRIS yang baru dibuat agar Store NEW langsung punya riwayat/pending dan tidak kosong.
    $qidForHistory = (string)($data['qris_id'] ?? '');
    if($qidForHistory !== ''){
      $hist = invite_points_read_all();
      if(!isset($hist['pending']) || !is_array($hist['pending'])) $hist['pending'] = [];
      $hist['pending'][$qidForHistory] = [
        'id'=>$qidForHistory,
        'target'=>$target,
        'inviter'=>invite_norm_store($me),
        'pin'=>$pin,
        'status'=>'pending',
        'amount'=>(int)($data['amount'] ?? $amount),
        'qrisId'=>$qidForHistory,
        'paymentReference'=>(string)($data['payment_reference'] ?? $paymentRef),
        'createdAt'=>date('c'),
        'expiredAt'=>(string)($data['expires_at'] ?? $data['expired_at'] ?? ''),
      ];
      invite_points_write_all($hist);
    }
    json_out(["ok"=>true,"status"=>"success","data"=>$data]);
  }


  if($api === 'invite_check_qris'){
    if(!$me) json_out(["ok"=>false,"msg"=>"Silakan login ulang"], 401);
    $body = json_decode(file_get_contents('php://input'), true);
    if(!is_array($body)) $body = [];
    $qrisId = trim((string)($body['qris_id'] ?? ''));
    $target = invite_norm_store($body['target'] ?? '');
    $pin = invite_norm_pin($body['pin'] ?? '');
    $expectedAmount = (int)preg_replace('/[^0-9]/','', (string)($body['amount'] ?? (string)qris_registration_amount()));
    if($expectedAmount <= 0) $expectedAmount = qris_registration_amount();
    if($qrisId==='') json_out(["ok"=>false,"msg"=>"qris_id wajib"], 400);
    if($target==='' || strlen($target) > 4) json_out(["ok"=>false,"msg"=>"Kode toko wajib diisi maksimal 4 huruf/angka."], 400);
    if($target === invite_norm_store($me)) json_out(["ok"=>false,"msg"=>"Toko undangan tidak boleh sama dengan toko saya."], 400);
    if($pin==='' || !preg_match('/^\d{1,4}$/', $pin)) json_out(["ok"=>false,"msg"=>"PIN wajib angka maksimal 4 digit."], 400);

    $db = read_store_db();
    if(in_array($target, array_values((array)($db['stores'] ?? [])), true)){
      json_out(["ok"=>true,"success"=>true,"already_registered"=>true,"storeId"=>$target,"msg"=>"Kode toko sudah terdaftar, silahkan login."]);
    }

    $row = qrispy_find_payment($qrisId, 900);
    $cached = qrispy_payment_cache_get($qrisId);
    if(!$row && is_array($cached['row'] ?? null)) $row = $cached['row'];
    if(!$row) json_out(["ok"=>true,"success"=>false,"payment_status"=>"pending","msg"=>"Menunggu pembayaran..."]);
    $row = qrispy_normalize_payment_row($row);
    if(qrispy_payment_is_expired($row)) json_out(["ok"=>true,"success"=>false,"expired"=>true,"payment_status"=>"expired","msg"=>"Waktu QRIS habis. Silakan buat pembayaran baru."]);

    if(qrispy_payment_is_success($row, $expectedAmount)){
      qrispy_payment_cache_mark_success($qrisId, $row);
      $applied = qris_apply_log_get($qrisId);
      if(is_array($applied) && (($applied['mode'] ?? '') === 'invite_register') && (($applied['storeId'] ?? '') === $target)){
        $doneTs = (int)($applied['expiryTs'] ?? expiry_get_ts($target));
        json_out(["ok"=>true,"success"=>true,"already_applied"=>true,"storeId"=>$target,"expiryTs"=>$doneTs,"expiryAt"=>($doneTs>0?date('c',$doneTs):null),"msg"=>"Pembayaran berhasil. Kode toko telah berhasil daftar, silahkan login."]);
      }
      $stores = array_values((array)($db['stores'] ?? []));
      if(!in_array($target, $stores, true)) $stores[] = $target;
      $newDb = write_store_db($stores);
      $expiryTs = registration_premium_expiry_ts();
      pin_set($target, $pin);
      expiry_set_ts($target, $expiryTs, ['source'=>'qris_invite', 'actor'=>$me, 'days'=>30]);
      premium_set($target, true);
      invite_referral_register($me, $target, $pin, $expectedAmount, $qrisId, (string)($row['payment_reference'] ?? $row['reference'] ?? ''), (string)($row['paid_at'] ?? $row['paidAt'] ?? $row['payment_time'] ?? $row['settlement_time'] ?? date('c')));
      $histDone = invite_points_read_all();
      if(isset($histDone['pending'][$qrisId])){ unset($histDone['pending'][$qrisId]); invite_points_write_all($histDone); }
      qris_apply_log_set($qrisId, ['mode'=>'invite_register','storeId'=>$target,'inviter'=>invite_norm_store($me),'expiryTs'=>$expiryTs]);
      json_out([
        "ok"=>true,
        "success"=>true,
        "storeId"=>$target,
        "stores"=>$newDb['stores'],
        "premium"=>true,
        "expiryTs"=>$expiryTs,
        "expiryAt"=>date('c',$expiryTs),
        "msg"=>"Pembayaran berhasil. Kode toko telah berhasil daftar, silahkan login."
      ]);
    }
    $status = qrispy_status_normalize($row['payment_status'] ?? $row['status'] ?? 'pending');
    json_out(["ok"=>true,"success"=>false,"payment_status"=>($status ?: 'pending'),"msg"=>"Menunggu pembayaran..."]);
  }


  if($api === 'invite_register_paid'){
    if(!$me) json_out(["ok"=>false,"msg"=>"Silakan login ulang"], 401);
    $body = json_decode(file_get_contents('php://input'), true);
    if(!is_array($body)) $body = [];
    $target = invite_norm_store($body['target'] ?? '');
    $pin = invite_norm_pin($body['pin'] ?? '');
    if($target==='' || strlen($target) > 4) json_out(["ok"=>false,"msg"=>"Toko saya undang wajib diisi maksimal 4 huruf/angka."], 400);
    if($target === invite_norm_store($me)) json_out(["ok"=>false,"msg"=>"Toko undangan tidak boleh sama dengan toko saya."], 400);
    if($pin==='' || !preg_match('/^\d{1,4}$/', $pin)) json_out(["ok"=>false,"msg"=>"PIN wajib angka maksimal 4 digit."], 400);
    $db = read_store_db();
    if(in_array($target, array_values((array)($db['stores'] ?? [])), true)) json_out(["ok"=>false,"msg"=>"Kode toko yang diundang sudah terdaftar di JSON."], 400);
    [$ok,$msg] = invite_referral_register($me, $target, $pin, $expectedAmount, $qrisId, (string)($row['payment_reference'] ?? $row['reference'] ?? ''), (string)($row['paid_at'] ?? $row['paidAt'] ?? $row['payment_time'] ?? $row['settlement_time'] ?? date('c')));
    if(!$ok) json_out(["ok"=>false,"msg"=>$msg], 400);
    $payload = invite_points_status_payload($me);
    json_out([
      "ok"=>true,
      "msg"=>$msg,
      "storeId"=>$me,
      "target"=>$target,
      "pin"=>$pin,
      "points"=>$payload['points'],
      "referrals"=>$payload['referrals']
    ]);
  }

  if($api === 'admin_invite_history'){
    $me = function_exists('cookie_read_session') ? cookie_read_session() : ($me ?? '');
    if($me !== ADMIN_STORE_ID) json_out(["ok"=>false,"msg"=>"Akses ditolak. Login admin ulang lalu buka Store NEW."], 403);
    $all = invite_points_read_all();
    $rows = [];
    foreach((array)($all['invites'] ?? []) as $target=>$row){
      if(!is_array($row)) continue;
      $createdAt = (string)($row['paidAt'] ?? '');
      if($createdAt === '') $createdAt = (string)($row['createdAt'] ?? '');
      $amount = invite_payment_amount($row['paidAmount'] ?? ($row['amount'] ?? qris_registration_amount()));
      if($amount <= 0) $amount = qris_registration_amount();
      $rows[] = [
        'target'=>invite_norm_store($target ?: ($row['target'] ?? '')),
        'inviter'=>invite_norm_store($row['inviter'] ?? ''),
        'pin'=>invite_norm_pin($row['pin'] ?? ''),
        'createdAt'=>$createdAt,
        'createdAtWib'=>invite_wib_text($createdAt),
        'awardedAt'=>(string)($row['awardedAt'] ?? ''),
        'amount'=>$amount,
        'amountText'=>invite_rupiah_text($amount),
        'qrisId'=>(string)($row['qrisId'] ?? ''),
        'points'=>invite_point_value($row['points'] ?? 0),
        'status'=>'success',
        'payment_status'=>'paid',
        'statusText'=>'Pembayaran sukses'
      ];
    }
    usort($rows, function($a,$b){ return strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? '')); });
    json_out(["ok"=>true,"history"=>array_slice($rows,0,200),"items"=>array_slice($rows,0,200),"count"=>count($rows)]);
  }

  if($api === 'admin_invite_history_delete'){
    $me = function_exists('cookie_read_session') ? cookie_read_session() : ($me ?? '');
    if($me !== ADMIN_STORE_ID) json_out(["ok"=>false,"msg"=>"Akses ditolak. Login admin ulang lalu buka Store NEW."], 403);
    $body = json_decode(file_get_contents('php://input'), true);
    if(!is_array($body)) $body = [];
    $allFlag = !empty($body['all']);
    $target = invite_norm_store($body['target'] ?? '');
    $data = invite_points_read_all();
    if(!isset($data['invites']) || !is_array($data['invites'])) $data['invites'] = [];
    $deleted = 0;
    if(!isset($data['pending']) || !is_array($data['pending'])) $data['pending'] = [];
    if($allFlag){
      $deleted = count($data['invites']) + count($data['pending']);
      $data['invites'] = [];
      $data['pending'] = [];
    }else{
      if($target === '') json_out(["ok"=>false,"msg"=>"Kode toko wajib diisi"], 400);
      foreach(array_keys($data['invites']) as $k){
        if(invite_norm_store($k) === $target || invite_norm_store($data['invites'][$k]['target'] ?? '') === $target){ unset($data['invites'][$k]); $deleted++; }
      }
      foreach(array_keys($data['pending']) as $k){
        if(invite_norm_store($data['pending'][$k]['target'] ?? '') === $target){ unset($data['pending'][$k]); $deleted++; }
      }
    }
    $data['updatedAt'] = date('c');
    invite_points_write_all($data);
    json_out(["ok"=>true,"deleted"=>$deleted,"count"=>count($data['invites'])]);
  }


  if($api === 'invite_redeem_points'){
    if(!$me) json_out(["ok"=>false,"msg"=>"Silakan login ulang"], 401);
    $body = json_decode(file_get_contents('php://input'), true);
    if(!is_array($body)) $body = [];
    $redeem = max(0, (int)($body['points'] ?? 0));
    if($redeem < 10 || ($redeem % 10) !== 0) json_out(["ok"=>false,"msg"=>"Jumlah point minimal 10 dan harus kelipatan 10."], 400);
    $storeId = invite_norm_store($me);
    $all = invite_points_read_all();
    $current = max(0, (int)($all['points'][$storeId] ?? 0));
    if($current < $redeem) json_out(["ok"=>false,"msg"=>"Point tidak cukup."], 400);
    $days = $redeem; // 10 point = 10 hari, 20 point = 20 hari, dst.
    $now = function_exists('jakarta_now_ts') ? jakarta_now_ts() : time();
    $oldExpiry = (int)expiry_get_ts($storeId);
    $base = $oldExpiry > $now ? $oldExpiry : $now;
    $newExpiry = $base + ($days * 86400);
    $all['points'][$storeId] = $current - $redeem;
    $all['logs'][] = ['type'=>'point_redeem','storeId'=>$storeId,'redeemPoints'=>$redeem,'days'=>$days,'oldExpiryTs'=>$oldExpiry,'newExpiryTs'=>$newExpiry,'createdAt'=>date('c')];
    invite_points_write_all($all);
    expiry_set_ts($storeId, $newExpiry, ['source'=>'point_redeem', 'actor'=>$storeId, 'days'=>$days]);
    premium_set($storeId, true);
    json_out(["ok"=>true,"storeId"=>$storeId,"redeemed"=>$redeem,"days"=>$days,"points"=>max(0,(int)$all['points'][$storeId]),"oldExpiryTs"=>$oldExpiry,"expiryTs"=>$newExpiry,"expiryAt"=>date('c',$newExpiry)]);
  }

/* =========================
   BANNER (ADMIN UPLOAD + PUBLIC GET)
   - upload hanya admin
   - ditampilkan di placeholder (iframe pertama kali login)
========================= */


  // DETAIL TOKO (per storeId) - ambil dari API status_toko + cache lokal agar admin tidak delay
  if($api === 'store_detail'){
    if(!$me) json_out(["ok"=>false,"msg"=>"Forbidden"], 403);
    $storeId = isset($_GET['storeId']) ? strtoupper(preg_replace('/[^A-Z0-9]/','', (string)$_GET['storeId'])) : '';
    if($storeId==='') json_out(["ok"=>false,"msg"=>"storeId wajib"], 400);
    if($storeId === ADMIN_STORE_ID && function_exists('m604_is_developer_session') && m604_is_developer_session()){
      $out = ['storeId'=>ADMIN_STORE_ID,'header2'=>'DEVELOPER','header5'=>'','city'=>'','dcId'=>'DEV','cached'=>true];
    } else {
      $out = store_detail_fetch_cached($storeId, 86400);
    }
    if(!$out) json_out(["ok"=>false,"msg"=>"Gagal ambil detail toko"], 502);
    $db = read_store_db();
    $joinDate = (string)(($db["createdMap"] ?? [])[$storeId] ?? ($db["updatedAt"] ?? date("c")));
    json_out(["ok"=>true, "joinDate"=>$joinDate, "createdAt"=>$joinDate] + $out);
  }

if($api === 'gift_code_request_get'){
  if(!$me)json_out(['ok'=>false,'msg'=>'Silakan login ulang'],401);
  json_out(['ok'=>true,'items'=>gift_wheel_request_for_store($me),'remaining_days'=>expiry_remaining_days($me),'cost_days'=>7]);
}
if($api === 'gift_code_request_create'){
  if(!$me)json_out(['ok'=>false,'msg'=>'Silakan login ulang'],401);
  if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')json_out(['ok'=>false,'msg'=>'Metode tidak diizinkan'],405);
  $r=gift_wheel_request_create($me);json_out($r,$r['ok']?200:400);
}
if($api === 'gift_code_check'){
  if(!$me) json_out(['ok'=>false,'msg'=>'Silakan login ulang'],401); $b=json_decode(file_get_contents('php://input'),true); if(!is_array($b))$b=[]; [$d,$i,$it]=gift_wheel_find($b['code']??''); if($i<0)json_out(['ok'=>false,'msg'=>'Kode roda tidak ditemukan'],404); if(!empty($it['used']))json_out(['ok'=>false,'msg'=>'Kode ini sudah pernah digunakan'],409); json_out(['ok'=>true,'code'=>$it['code'],'prizes'=>array_values($it['prizes']??gift_wheel_allowed_prizes())]);
}
if($api === 'gift_code_spin'){
  if(!$me) json_out(['ok'=>false,'msg'=>'Silakan login ulang'],401); $b=json_decode(file_get_contents('php://input'),true); if(!is_array($b))$b=[]; $r=gift_wheel_spin_once($b['code']??'',$me); json_out($r,$r['ok']?200:409);
}
if($api === 'gift_code_phone_save'){
  if(!$me)json_out(['ok'=>false,'msg'=>'Silakan login ulang'],401);
  if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')json_out(['ok'=>false,'msg'=>'Metode tidak diizinkan'],405);
  $b=json_decode(file_get_contents('php://input'),true);if(!is_array($b))$b=[];
  $r=gift_wheel_save_phone($b['code']??'',$me,$b['phone']??'',$b['wallet']??'');json_out($r,$r['ok']?200:400);
}
if($api === 'admin_gift_form_get'){
  if($me !== ADMIN_STORE_ID || !m604_is_developer_session())json_out(['ok'=>false,'msg'=>'Forbidden'],403);
  json_out(['ok'=>true]+gift_wheel_form_read());
}
if($api === 'admin_gift_form_save'){
  if($me !== ADMIN_STORE_ID || !m604_is_developer_session())json_out(['ok'=>false,'msg'=>'Forbidden'],403);
  $b=json_decode(file_get_contents('php://input'),true);if(!is_array($b))$b=[];
  $r=gift_wheel_form_save($b['winner']??'',$b['prizes']??'');json_out($r,$r['ok']?200:500);
}
if($api === 'admin_gift_code_create'){
  if($me !== ADMIN_STORE_ID || !m604_is_developer_session())json_out(['ok'=>false,'msg'=>'Forbidden'],403);$b=json_decode(file_get_contents('php://input'),true);if(!is_array($b))$b=[];$r=gift_wheel_create($b['code']??'',$b['winner']??'',$b['prizes']??[]);json_out($r,$r['ok']?200:400);
}
if($api === 'admin_gift_request_list'){
  if($me !== ADMIN_STORE_ID || !m604_is_developer_session())json_out(['ok'=>false,'msg'=>'Forbidden'],403);
  $d=gift_wheel_request_read();$items=[];foreach($d['items'] as $row)$items[]=gift_wheel_request_public($row,true);
  json_out(['ok'=>true,'items'=>$items,'prizes'=>gift_wheel_allowed_prizes()]);
}
if($api === 'admin_gift_request_decide'){
  if($me !== ADMIN_STORE_ID || !m604_is_developer_session())json_out(['ok'=>false,'msg'=>'Forbidden'],403);
  if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')json_out(['ok'=>false,'msg'=>'Metode tidak diizinkan'],405);
  $b=json_decode(file_get_contents('php://input'),true);if(!is_array($b))$b=[];
  $r=gift_wheel_request_decide($b['id']??'',$b['decision']??'',$me,$b['winner']??'');json_out($r,$r['ok']?200:400);
}
if($api === 'admin_gift_request_clear_history'){
  if($me !== ADMIN_STORE_ID || !m604_is_developer_session())json_out(['ok'=>false,'msg'=>'Forbidden'],403);
  if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')json_out(['ok'=>false,'msg'=>'Metode tidak diizinkan'],405);
  $r=gift_wheel_request_clear_history();json_out($r,$r['ok']?200:500);
}
if($api === 'admin_gift_code_list'){
  if($me !== ADMIN_STORE_ID || !m604_is_developer_session())json_out(['ok'=>false,'msg'=>'Forbidden'],403);$d=gift_wheel_read();json_out(['ok'=>true,'items'=>$d['items']]);
}
if($api === 'admin_gift_code_clear_all'){
  if($me !== ADMIN_STORE_ID || !m604_is_developer_session())json_out(['ok'=>false,'msg'=>'Forbidden'],403);
  if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')json_out(['ok'=>false,'msg'=>'Metode tidak diizinkan'],405);
  $d=gift_wheel_read();$deleted=count($d['items']??[]);$d['items']=[];$d['updated_at']=date('c');
  if(!json_file_write_array_safe(GIFT_WHEEL_FILE,$d))json_out(['ok'=>false,'msg'=>'Gagal menghapus semua riwayat kode dan nomor hadiah'],500);
  json_out(['ok'=>true,'deleted'=>$deleted]);
}
if($api === 'admin_gift_code_delete'){
  if($me !== ADMIN_STORE_ID || !m604_is_developer_session())json_out(['ok'=>false,'msg'=>'Forbidden'],403);$b=json_decode(file_get_contents('php://input'),true);if(!is_array($b))$b=[];$code=gift_wheel_normalize_code($b['code']??'');$d=gift_wheel_read();$d['items']=array_values(array_filter($d['items'],fn($it)=>strtoupper((string)($it['code']??''))!==$code));$d['updated_at']=date('c');if(!json_file_write_array_safe(GIFT_WHEEL_FILE,$d))json_out(['ok'=>false,'msg'=>'Gagal menghapus kode'],500);json_out(['ok'=>true]);
}

if($api === 'alert_get'){
  $a = alert_read_meta();
  json_out(["ok"=>true] + $a);
}

if($api === 'home_info_get'){
  json_out(['ok'=>true] + home_info_read());
}

if($api === 'admin_home_info_save'){
  if($me !== ADMIN_STORE_ID || !m604_is_developer_session()) json_out(['ok'=>false,'msg'=>'Forbidden'], 403);
  $body = json_decode(file_get_contents('php://input'), true);
  if(!is_array($body)) $body = [];
  $message = (string)($body['message'] ?? '');
  if(trim($message) === '') json_out(['ok'=>false,'msg'=>'Pesan wajib diisi'], 400);
  $saved = home_info_write($message);
  if($saved === false) json_out(['ok'=>false,'msg'=>'Gagal menyimpan file pesan. Periksa izin folder server.'], 500);
  json_out(['ok'=>true] + $saved);
}

if($api === 'notif_get'){
  $mark = !empty($_GET['read']);
  $n = notif_for_store($me ?: 'guest', $mark);
  json_out(["ok"=>true] + $n);
}

if($api === 'notif_delete'){
  if(!$me) json_out(["ok"=>false,"msg"=>"Silakan login ulang"], 401);
  if(($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') json_out(["ok"=>false,"msg"=>"Metode tidak diizinkan"], 405);
  $body = json_decode(file_get_contents('php://input'), true);
  if(!is_array($body)) $body = [];
  $id = (string)($body['id'] ?? '');
  if(!notif_delete_for_store($me, $id)) json_out(["ok"=>false,"msg"=>"Notifikasi tidak ditemukan"], 404);
  $n = notif_for_store($me, false);
  json_out(["ok"=>true] + $n);
}

if($api === 'notif_delete_all'){
  if(!$me)json_out(['ok'=>false,'msg'=>'Silakan login ulang'],401);
  if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')json_out(['ok'=>false,'msg'=>'Metode tidak diizinkan'],405);
  $r=notif_delete_all_for_store($me);
  json_out(['ok'=>true,'items'=>[],'unread'=>0,'deleted_count'=>(int)($r['count']??0)]);
}

if($api === 'admin_notif_send'){
  if($me !== ADMIN_STORE_ID || !m604_is_developer_session()) json_out(["ok"=>false,"msg"=>"Forbidden"], 403);
  $body = json_decode(file_get_contents('php://input'), true);
  if(!is_array($body)) $body = [];
  $title = trim((string)($body['title'] ?? 'Notifikasi'));
  $message = trim((string)($body['message'] ?? ''));
  if($title === '') $title = 'Notifikasi';
  if($message === '') json_out(["ok"=>false,"msg"=>"Pesan wajib diisi"], 400);
  if(function_exists('mb_substr')){ $title = mb_substr($title,0,80); $message = mb_substr($message,0,700); }
  else { $title = substr($title,0,80); $message = substr($message,0,700); }
  $data = notif_add_message($title, $message);
  json_out(["ok"=>true, "count"=>count($data['items'] ?? [])]);
}

if($api === 'admin_notif_clear'){
  if($me !== ADMIN_STORE_ID || !m604_is_developer_session()) json_out(["ok"=>false,"msg"=>"Forbidden"], 403);
  $data = notif_clear_all();
  json_out(["ok"=>true, "count"=>0, "updatedAt"=>$data['updatedAt'] ?? date('c')]);
}

if($api === 'alert_save'){
  if($me !== ADMIN_STORE_ID || !m604_is_developer_session()) json_out(["ok"=>false,"msg"=>"Forbidden"], 403);
  $body = json_decode(file_get_contents('php://input'), true);
  if(!is_array($body)) $body = [];
  $title = trim((string)($body['title'] ?? ''));
  $message = trim((string)($body['message'] ?? ''));
  $buttonText = trim((string)($body['buttonText'] ?? ''));
  $buttonUrl = trim((string)($body['buttonUrl'] ?? ''));
  if($title === '' || $message === '') json_out(["ok"=>false,"msg"=>"Judul dan isi alert wajib diisi"], 400);
  if($buttonUrl !== '' && !preg_match('~^https?://~i', $buttonUrl)) json_out(["ok"=>false,"msg"=>"Link tombol harus diawali http:// atau https://"], 400);
  $a = alert_write_meta($title, $message, $buttonText, $buttonUrl);
  json_out(["ok"=>true] + $a);
}

if($api === 'alert_delete'){
  if($me !== ADMIN_STORE_ID || !m604_is_developer_session()) json_out(["ok"=>false,"msg"=>"Forbidden"], 403);
  if(file_exists(ALERT_META_FILE)) @unlink(ALERT_META_FILE);
  json_out(["ok"=>true]);
}

if($api === 'banner_image'){
  $m = banner_read_meta();
  if(empty($m['file'])){ http_response_code(404); exit; }
  $file = basename((string)$m['file']);
  $abs = rtrim(BANNER_DIR, '/\\') . DIRECTORY_SEPARATOR . $file;
  if(!is_file($abs)){ http_response_code(404); exit; }
  $mime = function_exists('mime_content_type') ? @mime_content_type($abs) : '';
  if(!$mime || strpos($mime, 'image/') !== 0){
    $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
    $mime = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif'][$ext] ?? 'application/octet-stream';
  }
  header('Content-Type: '.$mime);
  header('Content-Length: '.filesize($abs));
  header('Cache-Control: public, max-age=31536000, immutable');
  readfile($abs);
  exit;
}

if($api === 'banner_get'){
  $m = banner_read_meta();
  $url = null;
  if(!empty($m["file"])){
    $abs = rtrim(BANNER_DIR, '/\\') . DIRECTORY_SEPARATOR . basename((string)$m["file"]);
    if(is_file($abs)){
      $url = "proxy.php?api=banner_image&v=" . rawurlencode((string)($m["updatedAt"] ?? filemtime($abs)));
    }
  }
  json_out(["ok"=>true, "url"=>$url, "updatedAt"=>$m["updatedAt"] ?? null]);
}

if($api === 'banner_upload'){
  if($me !== ADMIN_STORE_ID || !m604_is_developer_session()) json_out(["ok"=>false,"msg"=>"Forbidden"], 403);

  if(empty($_FILES['banner']) || !is_uploaded_file($_FILES['banner']['tmp_name'])){
    json_out(["ok"=>false,"msg"=>"File banner tidak ada."], 400);
  }
  $f = $_FILES['banner'];
  if(!empty($f['error']) && $f['error'] !== UPLOAD_ERR_OK){
    json_out(["ok"=>false,"msg"=>"Upload error: ".$f['error']], 400);
  }
  // Validasi tipe file (jpg/png/webp/gif)
  $tmp = $f['tmp_name'];
  $info = @getimagesize($tmp);
  if($info===false){
    json_out(["ok"=>false,"msg"=>"File bukan gambar valid."], 400);
  }
  $mime = $info['mime'] ?? '';
  $ext = match($mime){
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
    default => ''
  };
  if($ext==='') json_out(["ok"=>false,"msg"=>"Format gambar tidak didukung (gunakan JPG/PNG/WEBP/GIF)."], 400);

  if(!is_dir(BANNER_DIR)) @mkdir(BANNER_DIR, 0755, true);

  $name = "banner_" . date('Ymd_His') . "_" . bin2hex(random_bytes(3)) . "." . $ext;
  $dest = rtrim(BANNER_DIR,'/') . "/" . $name;

  if(!@move_uploaded_file($tmp, $dest)){
    json_out(["ok"=>false,"msg"=>"Gagal menyimpan file."], 500);
  }

  // Hapus banner lama (opsional)
  $old = banner_read_meta();
  if(!empty($old["file"])){
    $oldPath = rtrim(BANNER_DIR,'/') . "/" . basename((string)$old["file"]);
    if(is_file($oldPath) && basename($oldPath)!==basename($dest)) @unlink($oldPath);
  }

  $meta = banner_write_meta($name);
  json_out(["ok"=>true, "url"=>"proxy.php?api=banner_image&v=".rawurlencode((string)$meta["updatedAt"])]);
}

  
if($api === 'banner_delete'){
  if($me !== ADMIN_STORE_ID || !m604_is_developer_session()) json_out(["ok"=>false,"msg"=>"Forbidden"], 403);
  $old = banner_read_meta();
  // hapus file
  if(!empty($old["file"])){
    $oldPath = rtrim(BANNER_DIR,'/') . "/" . basename((string)$old["file"]);
    if(is_file($oldPath)) @unlink($oldPath);
  }
  // hapus meta
  if(file_exists(BANNER_META_FILE)) @unlink(BANNER_META_FILE);
  json_out(["ok"=>true]);
}


  if($api === 'plano_onhand_batch'){
    $storeId = isset($_GET['storeId']) ? trim((string)$_GET['storeId']) : '';
    $plus    = isset($_GET['plus']) ? trim((string)$_GET['plus']) : '';

    if($storeId === '' || $plus === '') json_out(["ok"=>false,"error"=>"storeId dan plus wajib diisi."], 400);
    if(!preg_match('/^[A-Za-z0-9]{2,10}$/', $storeId)) json_out(["ok"=>false,"error"=>"storeId tidak valid."], 400);

    $plus = preg_replace('/[^0-9,]/', '', $plus);
    $pluList = array_values(array_filter(array_map('trim', explode(',', $plus))));
    if(count($pluList) === 0) json_out(["ok"=>false,"error"=>"plus tidak valid."], 400);
    if(count($pluList) > 200) json_out(["ok"=>false,"error"=>"Maksimal 200 PLU per request"], 413);

    $result = [];
    foreach($pluList as $plu){
      $plu = preg_replace('/[^0-9]/', '', $plu);
      if($plu === '') continue;

      $url = "https://app.alfastore.co.id/to/api/cex/get_product_detail/"
           . "?storeId=" . urlencode($storeId)
           . "&plu=" . urlencode($plu);

      if(!function_exists('curl_init')){
        $result[] = ["plu"=>$plu, "on_hand"=>null];
        continue;
      }

      $ch = curl_init($url);
      curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
          "Accept: application/json",
          "User-Agent: FinanceUI-PlanogramBatch/1.0"
        ],
      ]);

      $raw  = curl_exec($ch);
      $err  = curl_error($ch);
      $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);

      if($raw === false || $http < 200 || $http >= 300){
        $result[] = ["plu"=>$plu, "on_hand"=>null];
        continue;
      }

      $json = json_decode($raw, true);
      if(is_array($json) && isset($json[0]) && is_array($json[0])){
        $row = $json[0];
        $result[] = [
          "plu"     => (string)($row['plu'] ?? $plu),
          "on_hand" => isset($row['onhand']) ? (int)$row['onhand'] : null
        ];
      }else{
        $result[] = ["plu"=>$plu, "on_hand"=>null];
      }
    }

    json_out(["ok"=>true, "status"=>true, "data"=>$result], 200);
  }


json_out(["ok"=>false,"msg"=>"Unknown api"], 404);
}




?>

<?php
/* Compatibility marker 2026-07-15: frontend now loads Inventory SIS OH through
   the existing batched type=onhand endpoint. Existing routes remain unchanged. */
?>

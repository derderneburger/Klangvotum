<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function sv_last_code_change(): ?string {
  static $cached = null;
  if ($cached !== null) {
    return $cached;
  }

  $root = realpath(__DIR__ . '/..');
  if (!$root) {
    $cached = null;
    return null;
  }

  $cacheFile = $root . '/backups/.last_code_change_cache.json';
  $now = time();

  if (is_file($cacheFile)) {
    $raw = @file_get_contents($cacheFile);
    $data = json_decode((string)$raw, true);
    if (
      is_array($data) &&
      !empty($data['value']) &&
      !empty($data['generated_at']) &&
      (($now - (int)$data['generated_at']) < 300)
    ) {
      $cached = (string)$data['value'];
      return $cached;
    }
  }

  $latest = 0;

  $it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
  );

  foreach ($it as $file) {
    if (!$file->isFile()) continue;

    $path = $file->getPathname();

    if (sv_str_contains($path, DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR)) {
      continue;
    }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($ext, ['php', 'css', 'js'], true)) {
      continue;
    }

    $mtime = $file->getMTime();
    if ($mtime > $latest) {
      $latest = $mtime;
    }
  }

  $cached = $latest ? date('d.m.Y H:i', $latest) : null;

  $payload = json_encode([
    'generated_at' => $now,
    'value' => $cached,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

  if ($payload !== false) {
    @file_put_contents($cacheFile, $payload);
  }

  return $cached;
}

function sv_header(?string $title=null, ?array $user=null): void {
  $cfg = sv_config();
  $brand = $cfg['branding'] ?? [];
  $appName  = sv_setting_get('app_name',  $brand['app_name']  ?? 'KlangVotum');
  $orgName  = sv_setting_get('org_name',  $brand['org_name']  ?? 'Musikschule Hildesheim');
  $logoSetting = sv_setting_get('logo_path', '');
  $logoPath = ($logoSetting !== '' && $logoSetting !== '__none__') ? $logoSetting : ($logoSetting === '__none__' ? '' : ($brand['logo_path'] ?? 'assets/logo.svg'));

  $base = sv_base_url();
  $u = $user ?? sv_current_user();
  $flashSuccess = sv_flash_get('success');
  $flashWarning = sv_flash_get('warning');
  $flashError = sv_flash_get('error');

  $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
  $path = rtrim($path, '/');
  $isActive = function(string $needle) use ($path): bool {
    if ($needle === '') return false;
    return sv_str_ends_with($path, $needle) || sv_str_contains($path.'/', rtrim($needle,'/').'/');
  };

  $pageTitle = ($title ? $title . " – " : "") . $appName;

  $logoUrl = ($logoPath !== '' && is_file(__DIR__ . "/../" . $logoPath)) ? ($base . "/" . ltrim($logoPath, "/")) : null;

  echo "<!doctype html><html lang='de'><head><meta charset='utf-8'><meta name='viewport' content='width=device-width, initial-scale=1'>";
  echo "<title>" . h($pageTitle) . "</title>";
  echo "<link rel='stylesheet' href='{$base}/assets/app.css?v=20'>";
  $primary   = sv_setting_get('color_primary',   '#c1090f');
  $secondary = sv_setting_get('color_secondary', '#7a8c0a');
  $primaryHover = sv_color_darken($primary, 0.15);
  [$secLight, $secMid] = sv_color_variants($secondary);
  $greenOn = sv_color_contrast($secondary);
  echo "<style>:root{--accent:{$primary};--accent-hover:{$primaryHover};--green:{$secondary};--green-light:{$secLight};--green-mid:{$secMid};--green-on:{$greenOn}}</style>";
  echo "</head><body>";

  echo "<header class='topbar'><div class='container'>";
  echo "<div class='brand-wrap'>";
  echo "<div class='brand-badge'>";

  if ($logoUrl) {
    echo "<img class='brand-logo' src='" . h($logoUrl) . "' alt='" . h($orgName) . "'>";
  }

  echo "<div class='brand-text'>";
  echo "<div class='brand-app'>" . h($appName) . "</div>";
  echo "<div class='brand-org'>" . h($orgName) . "</div>";
  echo "</div>";

  echo "</div>";
  echo "</div>";

  if ($u) {
    echo "<button class='navtoggle' type='button' aria-label='Menü' aria-expanded='false' aria-controls='topnav'>☰</button>";
    echo "<nav id='topnav' class='topnav' aria-label='Navigation'>";
    echo "<a class='navbtn" . ($isActive('/index.php') ? " active" : "") . "' href='{$base}/index.php'><span class='navicon'>🎵</span>Abstimmen</a>";
    echo "<a class='navbtn" . ($isActive('/account.php') ? " active" : "") . "' href='{$base}/account.php'><span class='navicon'>👤</span>Mein Konto</a>";
    if (sv_is_leitung($u)) {
      echo "<a class='navbtn" . ($isActive('/admin/results.php') ? " active" : "") . "' href='{$base}/admin/results.php'><span class='navicon'>📊</span>Ergebnisse</a>";
    } else {
      echo "<a class='navbtn" . ($isActive('/ergebnisse.php') ? " active" : "") . "' href='{$base}/ergebnisse.php'><span class='navicon'>📊</span>Ergebnisse</a>";
    }
    $verwaltungActive = sv_str_contains($path, '/admin') && !$isActive('/admin/results.php') && !$isActive('/ergebnisse.php');
    echo "<a class='navbtn" . ($verwaltungActive ? " active" : "") . "' href='{$base}/admin/'><span class='navicon'>⚙️</span>Verwaltung</a>";
    echo "<a class='navbtn danger' href='{$base}/logout.php'><span class='navicon'>🚪</span>Logout (" . h($u['display_name']) . ")</a>";
    echo "</nav>";
  } else {
    echo "<button class='navtoggle' type='button' aria-label='Menü' aria-expanded='false' aria-controls='topnav'>☰</button>";
    echo "<nav id='topnav' class='topnav' aria-label='Navigation'><a class='navbtn' href='{$base}/login.php'><span class='navicon'>🔑</span>Login</a></nav>";
  }

  echo "</div></header>";

  if ($flashSuccess) {
    echo "<div id='global-toast-success' class='toast'><div class='notice success msg'>" . h($flashSuccess) . "</div></div>";
  }
  if (!empty($flashWarning)) {
    echo "<div id='global-toast-warning' class='toast'><div class='notice warning msg'>" . h($flashWarning) . "</div></div>";
  }
  if ($flashError) {
    echo "<div id='global-toast-error' class='toast'><div class='notice error msg'>" . h($flashError) . "</div></div>";
  }

  echo "<main class='container' id='sv-main-content'>";

  if (!empty($cfg['dev_mode'])) {
    echo "<style>
    body { border: 8px solid #e30613; box-sizing:border-box; }
    .dev-banner {
      position:fixed;
      top:0;
      left:0;
      right:0;
      background:#e30613;
      color:white;
      text-align:center;
      font-weight:bold;
      padding:6px;
      z-index:9999;
    }
    </style>";

    echo "<div class='dev-banner'>⚠ Entwicklungsumgebung – chatgpt.kroppbox.de</div>";
  }
}

function sv_footer(): void {
  $lastChange = sv_last_code_change();

  $base = sv_base_url();
  $cfg = sv_config();
  $brand = $cfg['branding'] ?? [];
  $footerName = sv_setting_get('app_name', $brand['app_name'] ?? 'KlangVotum');
  echo "<button id='backToTop' aria-label='Nach oben' title='Nach oben'>↑</button>";
  echo "</main><footer class='footer'><div class='container'><small>" . h($footerName);
  if ($lastChange) {
    echo " – Letzte Code-Änderung: " . h($lastChange);
  }
  echo " &nbsp;·&nbsp; <a href='{$base}/impressum.php'>Impressum</a>";
  echo " &nbsp;·&nbsp; <a href='{$base}/datenschutz.php'>Datenschutz</a>";
  echo "</small></div></footer>";

  echo "<script>
(()=>{
  const btn=document.querySelector('.navtoggle');
  const nav=document.getElementById('topnav');
  if(!btn||!nav) return;
  const close=()=>{nav.classList.remove('open');btn.setAttribute('aria-expanded','false');};
  btn.addEventListener('click',()=>{
    const open=nav.classList.toggle('open');
    btn.setAttribute('aria-expanded', open?'true':'false');
  });
  document.addEventListener('click',(e)=>{
    if(!nav.classList.contains('open')) return;
    if(e.target===btn||btn.contains(e.target)||nav.contains(e.target)) return;
    close();
  });
  window.addEventListener('resize',()=>{ if(window.innerWidth>760) close(); });
})();
</script>

<script>
(function svGlobalToastInit(){
  function fadeToast(el){
    if(!el) return;
    el.style.transition = 'opacity .35s ease, transform .35s ease';
    setTimeout(function(){
      el.style.opacity = '0';
      el.style.transform = 'translateY(-6px)';
    }, 2600);
    setTimeout(function(){
      if(el && el.parentNode) el.parentNode.removeChild(el);
    }, 3200);
  }
  fadeToast(document.getElementById('global-toast-success'));
  fadeToast(document.getElementById('global-toast-error'));
  fadeToast(document.getElementById('global-toast-warning'), 6000);
})();
</script>
<script>
(()=>{
  const btn=document.getElementById('backToTop');
  if(!btn) return;
  window.addEventListener('scroll',()=>{ btn.classList.toggle('visible', window.scrollY > 300); },{passive:true});
  btn.addEventListener('click',()=>{ window.scrollTo({top:0,behavior:'smooth'}); });
})();
</script>
<script>
(function(){
  // Horizontal scroll with mousewheel over table-scroll containers
  document.querySelectorAll('.table-scroll').forEach(function(el){
    el.addEventListener('wheel', function(e){
      // If the container can scroll horizontally and user scrolls vertically
      var canScrollH = el.scrollWidth > el.clientWidth;
      if (!canScrollH) return;
      // If shift is held OR the table is wider than container, scroll horizontally
      if (e.shiftKey || Math.abs(e.deltaX) > Math.abs(e.deltaY)) return; // let browser handle
      if (canScrollH && Math.abs(e.deltaY) > 0) {
        e.preventDefault();
        el.scrollLeft += e.deltaY;
      }
    }, {passive:false});
    // Drag to scroll
    var isDown = false, startX, scrollLeft;
    el.addEventListener('mousedown', function(e){
      if (e.target.closest('a,button,input,select')) return;
      isDown = true;
      el.style.cursor = 'grabbing';
      startX = e.pageX - el.offsetLeft;
      scrollLeft = el.scrollLeft;
    });
    el.addEventListener('mouseleave', function(){ isDown = false; el.style.cursor = ''; });
    el.addEventListener('mouseup',    function(){ isDown = false; el.style.cursor = ''; });
    el.addEventListener('mousemove',  function(e){
      if (!isDown) return;
      e.preventDefault();
      var x = e.pageX - el.offsetLeft;
      el.scrollLeft = scrollLeft - (x - startX);
    });
  });
})();
</script>
</body></html>";
  echo "<script>
// Move all dialogs to body so inert on main doesn't affect them
document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('dialog').forEach(function(d) {
    document.body.appendChild(d);
  });
});

function sv_anyDialogOpen() {
  return Array.from(document.querySelectorAll('dialog')).some(function(d){ return d.open; });
}
function sv_lockScroll() {
  var main = document.getElementById('sv-main-content');
  if (main) main.inert = true;
}
function sv_unlockScroll() {
  var main = document.getElementById('sv-main-content');
  if (main) main.inert = false;
}

// Hook showModal
var _svOrigShowModal = HTMLDialogElement.prototype.showModal;
HTMLDialogElement.prototype.showModal = function() {
  _svOrigShowModal.call(this);
  sv_lockScroll();
};

// Hook close - use 'close' event which fires reliably (ESC, .close(), backdrop)
document.addEventListener('close', function(e) {
  if (e.target && e.target.tagName === 'DIALOG') {
    setTimeout(function() {
      if (!sv_anyDialogOpen()) sv_unlockScroll();
    }, 0);
  }
}, true);
</script>";
}
<?php
/**
 * admin/dashboard.php
 * ---------------------------------------------------------------------------
 * Admin Dashboard — Rajagiri College Grievance Redressal Portal
 *
 * Sidebar behavior:
 *   • Collapsed (w-20) → clicking a parent section navigates to its landing page
 *   • Expanded  (w-64) → clicking a parent section toggles its sub-menu
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// 1. SESSION START
// ---------------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

// ---------------------------------------------------------------------------
// 2. AUTH GUARD
// ---------------------------------------------------------------------------
$sessionRole = isset($_SESSION['role']) ? strtoupper((string) $_SESSION['role']) : '';

if (empty($_SESSION['user_id']) || $sessionRole !== 'ADMIN') {
    header('Location: ../login.php?role=admin');
    exit;
}

// ---------------------------------------------------------------------------
// 3. DATABASE CONNECTION
// ---------------------------------------------------------------------------
$dbFile = __DIR__ . '/../db_connect.php';

if (!file_exists($dbFile)) {
    die('Database configuration file (db_connect.php) not found.');
}

require_once $dbFile;

$dbError = null;

if (!isset($conn) || !($conn instanceof mysqli)) {
    $conn = @new mysqli('localhost', 'root', '', 'grievance_db');

    if ($conn->connect_error) {
        $dbError = 'Database connection failed: ' . $conn->connect_error;
        $conn    = null;
    } else {
        $conn->set_charset('utf8mb4');
    }
}

if ($conn && $conn->connect_errno) {
    $dbError = 'Database connection failed: ' . $conn->connect_error;
    $conn    = null;
}

// ---------------------------------------------------------------------------
// 4. FETCH ADMIN PROFILE
// ---------------------------------------------------------------------------
$adminData = [
    'username'        => 'Admin',
    'name'            => '',
    'email'           => 'admin@rajagiri.edu',
    'profile_picture' => '',
];

if ($conn !== null) {
    try {
        $sql = "SELECT  u.id,
                        u.username,
                        u.role,
                        u.status,
                        ap.name            AS name,
                        ap.email           AS email,
                        ap.profile_picture AS profile_picture
                FROM users u
                LEFT JOIN admin_profiles ap ON ap.user_id = u.id
                WHERE u.id = ?
                LIMIT 1";

        $stmt = $conn->prepare($sql);

        if ($stmt) {
            $userId = (int) $_SESSION['user_id'];
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();

            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();

                $adminData['username']        = $row['username']        ?? 'Admin';
                $adminData['name']            = $row['name']            ?? '';
                $adminData['email']           = $row['email']           ?? 'admin@rajagiri.edu';
                $adminData['profile_picture'] = $row['profile_picture'] ?? '';
            }

            $stmt->close();
        }
    } catch (Throwable $ex) {
        error_log('[Admin Profile Fetch] ' . $ex->getMessage());
    }
}

$displayName = !empty($adminData['name']) ? $adminData['name'] : $adminData['username'];
$adminEmail  = !empty($adminData['email']) ? $adminData['email'] : 'admin@rajagiri.edu';

// ---------------------------------------------------------------------------
// 5. PROFILE PICTURE
// ---------------------------------------------------------------------------
$hasProfilePicture = false;
$profilePictureUrl = '';

if (!empty($adminData['profile_picture'])) {
    $relativeFromAdmin = '../' . ltrim((string) $adminData['profile_picture'], '/');

    if (file_exists(__DIR__ . '/../' . ltrim((string) $adminData['profile_picture'], '/'))) {
        $hasProfilePicture = true;
        $profilePictureUrl = $relativeFromAdmin;
    }
}

// ---------------------------------------------------------------------------
// 6. METRICS
// ---------------------------------------------------------------------------
$totalGrievances   = 0;
$pendingGrievances = 0;
$closedGrievances  = 0;

if ($conn !== null) {
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM grievances");
        if ($stmt) {
            $stmt->execute();
            $totalGrievances = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();
        }

        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total FROM grievances
             WHERE status IN ('Pending', 'In Progress', 'Reopened')"
        );
        if ($stmt) {
            $stmt->execute();
            $pendingGrievances = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();
        }

        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total FROM grievances
             WHERE status IN ('Closed', 'Disposed')"
        );
        if ($stmt) {
            $stmt->execute();
            $closedGrievances = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
            $stmt->close();
        }
    } catch (Throwable $ex) {
        error_log('[Admin Dashboard Metrics] ' . $ex->getMessage());
    }
}

// ---------------------------------------------------------------------------
// 7. SIDEBAR MENU CONFIG (with landing pages for collapsed state)
// ---------------------------------------------------------------------------
$sidebarMenu = [
    [
        'id'            => 'settings',
        'title'         => 'Settings',
        'icon'          => 'settings',
        'landing_href'  => 'settings.php',
        'items'         => [
            ['title' => 'Class',       'href' => 'classes.php',      'icon' => 'layers'],
            ['title' => 'Course',      'href' => 'courses.php',      'icon' => 'book-open'],
            ['title' => 'Designation', 'href' => 'designations.php', 'icon' => 'badge-check'],
            ['title' => 'Department',  'href' => 'departments.php',  'icon' => 'building-2'],
        ],
    ],
    [
        'id'            => 'members',
        'title'         => 'Members',
        'icon'          => 'users',
        'landing_href'  => 'members.php',
        'items'         => [
            ['title' => 'Grievance Type',    'href' => 'cell_members.php',     'icon' => 'user-check'],
            ['title' => 'New Registrations', 'href' => 'new_registration.php', 'icon' => 'user-plus'],
            ['title' => 'Terminations',      'href' => 'termination_year.php', 'icon' => 'user-minus'],
        ],
    ],
    [
        'id'            => 'grievance',
        'title'         => 'Grievance',
        'icon'          => 'clipboard-list',
        'landing_href'  => 'grievances.php',
        'items'         => [
            ['title' => 'Complaint Details', 'href' => 'grievance_details.php', 'icon' => 'file-text'],
            ['title' => 'Grievance Type',    'href' => 'grievance_types.php',   'icon' => 'tag'],
        ],
    ],
    [
        'id'            => 'reports',
        'title'         => 'Grievance Reports',
        'icon'          => 'bar-chart-3',
        'landing_href'  => 'grievance_report.php',
        'items'         => [
            ['title' => 'Complaint Report',    'href' => 'complaint_report.php',    'icon' => 'file-bar-chart'],
            ['title' => 'Cell Members Report', 'href' => 'cell_members_report.php', 'icon' => 'users-2'],
            ['title' => 'Consolidated Report', 'href' => 'consolidated_report.php', 'icon' => 'file-stack'],
            ['title' => 'Summary Report',      'href' => 'summary_report.php',      'icon' => 'pie-chart'],
        ],
    ],
];

// ---------------------------------------------------------------------------
// 8. NAV CARDS (top grid)
// ---------------------------------------------------------------------------
$navCards = [
    ['title' => 'Settings',          'icon' => 'settings',       'href' => 'settings.php'],
    ['title' => 'Members',           'icon' => 'users',          'href' => 'members.php'],
    ['title' => 'Grievance',         'icon' => 'clipboard-list', 'href' => 'grievances.php'],
    ['title' => 'Grievance Reports', 'icon' => 'bar-chart-3',    'href' => 'grievance_report.php'],
    ['title' => 'Mail Log',          'icon' => 'mail',           'href' => 'mail_log.php'],
];

// ---------------------------------------------------------------------------
// 9. HELPER
// ---------------------------------------------------------------------------
function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin Dashboard — Rajagiri College Grievance Portal</title>
  <link rel="icon" type="image/svg+xml" href="../public/favicon.svg" />

  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/lucide@latest"></script>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">

  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            teal: {
              50:'#EAF4F4',100:'#CFE6E7',200:'#9FCDCF',300:'#6FB4B7',400:'#3F9B9F',
              500:'#128287',600:'#006E74',700:'#005A5F',800:'#00454A',900:'#003134'
            }
          },
          fontFamily: {
            display: ['Coolvetica', 'Poppins', 'sans-serif'],
            sans: ['Coolvetica', 'Poppins', 'sans-serif']
          },
          keyframes: {
            modalFadeIn: {
              '0%':   { opacity: '0', transform: 'scale(0.96)' },
              '100%': { opacity: '1', transform: 'scale(1)' }
            },
            confirmShake: {
              '0%, 100%': { transform: 'translateX(0)' },
              '20%':      { transform: 'translateX(-6px)' },
              '40%':      { transform: 'translateX(6px)' },
              '60%':      { transform: 'translateX(-4px)' },
              '80%':      { transform: 'translateX(4px)' }
            },
            dropdownFade: {
              '0%':   { opacity: '0', transform: 'translateY(-8px) scale(0.98)' },
              '100%': { opacity: '1', transform: 'translateY(0) scale(1)' }
            },
            submenuFade: {
              '0%':   { opacity: '0', maxHeight: '0' },
              '100%': { opacity: '1', maxHeight: '500px' }
            },
            fadeUp: {
              '0%':   { opacity: '0', transform: 'translateY(14px)' },
              '100%': { opacity: '1', transform: 'translateY(0)' }
            },
            shimmer: {
              '0%':   { backgroundPosition: '-200% 0' },
              '100%': { backgroundPosition: '200% 0' }
            },
            pulseRing: {
              '0%':   { boxShadow: '0 0 0 0 rgba(0,110,116,0.45)' },
              '70%':  { boxShadow: '0 0 0 12px rgba(0,110,116,0)' },
              '100%': { boxShadow: '0 0 0 0 rgba(0,110,116,0)' }
            },
            gradientShift: {
              '0%,100%': { backgroundPosition: '0% 50%' },
              '50%':     { backgroundPosition: '100% 50%' }
            },
            floatY: {
              '0%,100%': { transform: 'translateY(0)' },
              '50%':     { transform: 'translateY(-6px)' }
            }
          },
          animation: {
            'modal-in':      'modalFadeIn 0.25s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'confirm-shake': 'confirmShake 0.5s cubic-bezier(0.16, 1, 0.3, 1)',
            'dropdown':      'dropdownFade 0.2s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'submenu':       'submenuFade 0.25s ease-out forwards',
            'fade-up':       'fadeUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'shimmer':       'shimmer 3s linear infinite',
            'pulse-ring':    'pulseRing 2s ease-out infinite',
            'gradient':      'gradientShift 6s ease infinite',
            'float-y':       'floatY 3s ease-in-out infinite'
          }
        }
      }
    };
  </script>

  <style>
    /* ============================================================
       FONT FACES
       ============================================================ */
    @font-face {
      font-family: 'Coolvetica';
      src: url('../assets/fonts/coolvetica-rg.woff2') format('woff2'),
           url('../assets/fonts/coolvetica-rg.woff') format('woff');
      font-weight: 400; font-display: swap;
    }
    @font-face {
      font-family: 'Coolvetica';
      src: url('../assets/fonts/coolvetica-bold.woff2') format('woff2'),
           url('../assets/fonts/coolvetica-bold.woff') format('woff');
      font-weight: 700; font-display: swap;
    }
    html { scroll-behavior: smooth; }
    body { font-family: 'Coolvetica', 'Poppins', sans-serif; }

    /* ============================================================
       AMBIENT BACKGROUND MESH
       (fixed attachment only on desktop — causes jank on mobile)
       ============================================================ */
    body {
      background-color: #EAF4F4;
      background-image:
        radial-gradient(at 12% 8%, rgba(0,110,116,0.08) 0px, transparent 50%),
        radial-gradient(at 88% 4%, rgba(63,155,159,0.10) 0px, transparent 50%),
        radial-gradient(at 78% 92%, rgba(0,110,116,0.07) 0px, transparent 50%),
        radial-gradient(at 4% 88%, rgba(63,155,159,0.08) 0px, transparent 50%);
    }
    @media (min-width: 1024px) and (hover: hover) {
      body { background-attachment: fixed; }
    }

    /* ============================================================
       CUSTOM SCROLLBAR (desktop only — mobile uses native)
       ============================================================ */
    @media (hover: hover) and (pointer: fine) {
      ::-webkit-scrollbar { width: 10px; height: 10px; }
      ::-webkit-scrollbar-track { background: transparent; }
      ::-webkit-scrollbar-thumb {
        background: linear-gradient(180deg, #128287, #006E74);
        border-radius: 8px;
        border: 2px solid #EAF4F4;
      }
      ::-webkit-scrollbar-thumb:hover { background: #005A5F; }
    }

    /* Selection */
    ::selection { background: #9FCDCF; color: #003134; }

    /* ============================================================
       DECORATIVE PATTERNS
       ============================================================ */
    .hero-dots {
      background-image: radial-gradient(rgba(255,255,255,0.35) 1.5px, transparent 1.5px);
      background-size: 22px 22px;
    }
    .roofline {
      height: 14px;
      background-image: linear-gradient(45deg, transparent 33.33%, #006E74 33.33%, #006E74 66.66%, transparent 66.66%),
                        linear-gradient(-45deg, transparent 33.33%, #006E74 33.33%, #006E74 66.66%, transparent 66.66%);
      background-size: 20px 14px; background-repeat: repeat-x;
      position: relative;
      overflow: hidden;
    }
    .roofline::after {
      content: '';
      position: absolute; inset: 0;
      background: linear-gradient(90deg, transparent, rgba(255,255,255,.35), transparent);
      transform: translateX(-100%);
      animation: shimmer 4s linear infinite;
    }
    .logo-divider { width: 1px; background-color: #CFE6E7; }

    /* Sidebar scrollbar (thin, works everywhere) */
    #sidebarNav::-webkit-scrollbar { width: 6px; }
    #sidebarNav::-webkit-scrollbar-track { background: transparent; }
    #sidebarNav::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 3px; }
    #sidebarNav::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.35); }

    /* ============================================================
       REVEAL ON SCROLL (softer on mobile)
       ============================================================ */
    .reveal {
      opacity: 0;
      transform: translateY(16px);
      transition: opacity .6s ease, transform .6s cubic-bezier(.16,1,.3,1);
    }
    .reveal.is-visible { opacity: 1; transform: none; }

    @media (max-width: 640px) {
      .reveal {
        transform: translateY(10px);
        transition-duration: .4s;
      }
    }

    /* ============================================================
       CARD HOVER LIFT (GPU layer only on hover-capable devices)
       ============================================================ */
    .lift {
      transition: transform .35s cubic-bezier(.16,1,.3,1), box-shadow .35s ease, border-color .25s ease;
    }
    @media (hover: hover) and (pointer: fine) {
      .lift { will-change: transform; }
      .lift:hover { transform: translateY(-4px); }
    }

    /* ============================================================
       GLOW BORDER ON NAV CARDS
       ============================================================ */
    .glow-card { position: relative; isolation: isolate; }
    .glow-card::before {
      content: '';
      position: absolute; inset: -2px;
      border-radius: 1rem;
      background: linear-gradient(135deg, #006E74, #3F9B9F, #006E74);
      background-size: 200% 200%;
      opacity: 0;
      transition: opacity .35s ease;
      z-index: -1;
      animation: gradientShift 4s ease infinite;
    }
    @media (hover: hover) and (pointer: fine) {
      .glow-card:hover::before { opacity: 1; }
    }

    /* ============================================================
       SIDEBAR ACTIVE GLOW
       ============================================================ */
    .sidebar-active-glow {
      box-shadow: 0 0 0 1px rgba(255,255,255,.3),
                  0 6px 20px -6px rgba(0,0,0,.35),
                  inset 0 0 20px rgba(255,255,255,.06);
    }

    /* ============================================================
       SIDEBAR LINK — HOVER SLIDE ACCENT
       ============================================================ */
    .sidebar-link { position: relative; overflow: hidden; }
    .sidebar-link::after {
      content: '';
      position: absolute; left: 0; top: 0; bottom: 0;
      width: 3px; background: #fff;
      transform: translateX(-4px);
      opacity: 0;
      transition: all .25s ease;
      border-radius: 0 4px 4px 0;
    }
    @media (hover: hover) and (pointer: fine) {
      .sidebar-link:hover::after { transform: translateX(0); opacity: 1; }
    }

    /* ============================================================
       HEADER FROSTED-ON-SCROLL
       (blur only on capable devices — fallback is solid translucent)
       ============================================================ */
    .header-scrolled {
      background: rgba(255,255,255,.92) !important;
      box-shadow: 0 4px 24px -8px rgba(0,69,74,.15);
    }
    @supports (backdrop-filter: blur(1px)) or (-webkit-backdrop-filter: blur(1px)) {
      @media (hover: hover) and (min-width: 1024px) {
        .header-scrolled {
          background: rgba(255,255,255,.85) !important;
          backdrop-filter: saturate(180%) blur(14px);
          -webkit-backdrop-filter: saturate(180%) blur(14px);
        }
      }
    }

    /* ============================================================
       METRIC CARD ICON PULSE (desktop hover only)
       ============================================================ */
    .metric-card .metric-icon {
      transition: background .3s ease, color .3s ease, transform .3s ease;
    }
    @media (hover: hover) and (pointer: fine) {
      .metric-card:hover .metric-icon {
        animation: pulseRing 1.4s ease-out;
        background: #006E74;
        color: #fff;
      }
      .metric-card:hover .metric-accent { opacity: 1; }
    }
    .metric-card .metric-accent { opacity: 0; transition: opacity .3s ease; }

    /* ============================================================
       CTA GRADIENT SWEEP (desktop hover only)
       ============================================================ */
    .cta-sweep { position: relative; overflow: hidden; }
    .cta-sweep::before {
      content: '';
      position: absolute; inset: 0;
      background: linear-gradient(120deg, transparent 30%, rgba(255,255,255,.22) 50%, transparent 70%);
      background-size: 200% 100%;
      transform: translateX(-100%);
      transition: transform .8s cubic-bezier(.16,1,.3,1);
      pointer-events: none;
    }
    @media (hover: hover) and (pointer: fine) {
      .cta-sweep:hover::before { transform: translateX(100%); }
    }

    /* ============================================================
       HEADING UNDERLINE
       ============================================================ */
    .heading-underline { position: relative; display: inline-block; }
    .heading-underline::after {
      content: '';
      position: absolute; left: 0; bottom: -6px;
      height: 3px; width: 100%;
      background: linear-gradient(90deg, #006E74, #3F9B9F 60%, transparent);
      border-radius: 2px;
      transform: scaleX(0);
      transform-origin: left;
      animation: underlineIn .8s .3s cubic-bezier(.16,1,.3,1) forwards;
    }
    @keyframes underlineIn { to { transform: scaleX(1); } }

    /* ============================================================
       BACK TO TOP
       ============================================================ */
    #backToTop {
      transition: opacity .3s ease, transform .3s cubic-bezier(.16,1,.3,1), background-color .25s ease;
      opacity: 0;
      transform: translateY(10px) scale(.9);
      pointer-events: none;
    }
    #backToTop.show {
      opacity: 1;
      transform: translateY(0) scale(1);
      pointer-events: auto;
    }

    /* ============================================================
       FOCUS RING
       ============================================================ */
    :focus-visible {
      outline: 2px solid #3F9B9F;
      outline-offset: 3px;
      border-radius: 6px;
    }

    /* ============================================================
       LOW-END DEVICE STRIP-OUT (very small phones)
       Removes heaviest effects to guarantee smooth scroll
       ============================================================ */
    @media (max-width: 480px) {
      .glow-card::before { display: none; }
      .cta-sweep::before { display: none; }
      .roofline::after { animation: none; }
      .heading-underline::after { animation: none; transform: scaleX(1); }
      .metric-card:hover .metric-icon { animation: none; }
      .animate-pulse-ring { animation: none; }
    }

    /* ============================================================
       REDUCED MOTION
       ============================================================ */
    @media (prefers-reduced-motion: reduce) {
      *, *::before, *::after {
        animation-duration: .001ms !important;
        transition-duration: .001ms !important;
      }
      .reveal { opacity: 1 !important; transform: none !important; }
      html { scroll-behavior: auto; }
    }
  </style>
</head>

<body class="min-h-screen text-teal-900 antialiased flex flex-col">

  <div class="flex min-h-screen flex-1">

    <!-- ============================================================
         SIDEBAR
         ============================================================ -->
    <aside id="adminSidebar"
           class="w-20 bg-teal-800
                  flex flex-col py-4 shadow-xl fixed inset-y-0 left-0 z-40
                  transition-all duration-300 ease-in-out overflow-hidden">

      <!-- Brand row: "grievance" text + toggle button (logo removed) -->
      <div class="flex items-center gap-2 px-3 mb-6 flex-shrink-0">
        <a href="dashboard.php"
           class="sidebar-brand inline-flex items-center gap-1 flex-1 min-w-0 overflow-hidden">
          <span class="sidebar-label text-white font-bold text-lg whitespace-nowrap opacity-0 w-0 overflow-hidden transition-all duration-200">
            grievance
          </span>
          <span class="sidebar-label w-1.5 h-1.5 rounded-full bg-white mb-1 flex-shrink-0 opacity-0 w-0 overflow-hidden transition-all duration-200"></span>
        </a>
        <button id="sidebarToggle"
                class="text-white/80 hover:text-white p-2.5 rounded-xl hover:bg-white/10
                       transition-colors flex items-center justify-center flex-shrink-0"
                aria-label="Toggle sidebar">
          <i data-lucide="menu" class="w-6 h-6 flex-shrink-0"></i>
        </button>
      </div>

      <!-- Scrollable nav -->
      <nav id="sidebarNav" class="flex flex-col space-y-1 flex-1 w-full px-3 pt-1 overflow-y-auto overflow-x-hidden">

        <!-- Dashboard (main) -->
        <a href="dashboard.php"
           class="sidebar-link sidebar-active-glow group relative w-full h-12 rounded-xl bg-white text-teal-800 shadow-md flex items-center px-3 flex-shrink-0 transition-all">
          <i data-lucide="home" class="w-6 h-6 flex-shrink-0"></i>
          <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap opacity-0 w-0 overflow-hidden transition-all duration-200">Dashboard</span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-teal-900 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Dashboard</span>
        </a>

        <!-- Dropdown Sections -->
        <?php foreach ($sidebarMenu as $section): ?>
          <div class="sidebar-group flex-shrink-0" data-section="<?= e($section['id']) ?>">

            <!-- Parent button -->
            <button type="button"
                    data-submenu-toggle="<?= e($section['id']) ?>"
                    data-landing-href="<?= e($section['landing_href']) ?>"
                    class="sidebar-link group relative w-full h-12 rounded-xl hover:bg-white/10 flex items-center text-white transition-all px-3">
              <i data-lucide="<?= e($section['icon']) ?>" class="w-6 h-6 flex-shrink-0"></i>
              <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap opacity-0 w-0 overflow-hidden transition-all duration-200">
                <?= e($section['title']) ?>
              </span>
              <i data-lucide="chevron-down"
                 class="sidebar-label submenu-chevron ml-auto w-4 h-4 flex-shrink-0 transition-transform duration-300 opacity-0 w-0 overflow-hidden"></i>
              <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-teal-900 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">
                <?= e($section['title']) ?>
              </span>
            </button>

            <!-- Submenu -->
            <div id="submenu-<?= e($section['id']) ?>"
                 class="submenu hidden ml-2 mt-1 space-y-1 pl-3 border-l border-white/20">

              <?php foreach ($section['items'] as $item): ?>
                <a href="<?= e($item['href']) ?>"
                   class="group flex items-center gap-2 px-2 py-2 rounded-lg text-white/80 hover:text-white
                          hover:bg-white/10 hover:translate-x-0.5 transition-all text-xs">
                  <i data-lucide="<?= e($item['icon']) ?>" class="w-4 h-4 flex-shrink-0 text-white/70 group-hover:text-white"></i>
                  <span class="font-medium whitespace-nowrap"><?= e($item['title']) ?></span>
                </a>
              <?php endforeach; ?>

            </div>
          </div>
        <?php endforeach; ?>

        <!-- My Profile (main) -->
        <a href="profile.php"
           class="sidebar-link group relative w-full h-12 rounded-xl hover:bg-white/10 flex items-center text-white transition-all px-3 flex-shrink-0">
          <i data-lucide="user" class="w-6 h-6 flex-shrink-0"></i>
          <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap opacity-0 w-0 overflow-hidden transition-all duration-200">My Profile</span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-teal-900 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">My Profile</span>
        </a>

        <!-- Change Password (main) -->
        <a href="change_password.php"
           class="sidebar-link group relative w-full h-12 rounded-xl hover:bg-white/10 flex items-center text-white transition-all px-3 flex-shrink-0">
          <i data-lucide="key" class="w-6 h-6 flex-shrink-0"></i>
          <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap opacity-0 w-0 overflow-hidden transition-all duration-200">Change Password</span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-teal-900 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Change Password</span>
        </a>

      </nav>

      <!-- Logout -->
      <a href="#" data-logout-trigger="1" id="sidebarLogoutBtn"
         class="sidebar-link group relative w-full h-12 rounded-xl hover:bg-red-500/30 flex items-center text-white transition-all mx-3 px-3 flex-shrink-0"
         style="width: calc(100% - 1.5rem);" title="Logout">
        <i data-lucide="log-out" class="w-6 h-6 flex-shrink-0"></i>
        <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap opacity-0 w-0 overflow-hidden transition-all duration-200">Logout</span>
        <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-red-600 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Logout</span>
      </a>
    </aside>

    <!-- ============================================================
         MAIN CONTENT WRAPPER
         ============================================================ -->
    <div id="adminMain" class="flex-1 ml-20 flex flex-col min-h-screen transition-all duration-300">

      <!-- TOP HEADER -->
      <header class="bg-white border-b-2 border-teal-600 shadow-sm sticky top-0 z-30 transition-all duration-300">
        <div class="flex items-center justify-between px-4 sm:px-6 py-3">

          <div class="flex items-center gap-3 md:gap-4">
            <a href="dashboard.php" class="flex items-center group">
              <img src="../public/rcss-logo.webp" alt="RCSS Logo" class="h-9 md:h-10 w-auto" />
            </a>
            <span class="hidden sm:block logo-divider h-8"></span>
            <span class="hidden sm:flex items-baseline gap-1">
              <span class="text-lg md:text-xl font-bold text-teal-600 tracking-tight">grievance</span>
              <span class="w-1.5 h-1.5 rounded-full bg-teal-600 mb-1"></span>
            </span>
          </div>

          <div class="relative" id="admin-dropdown-container">
            <button id="admin-dropdown-btn"
                    type="button"
                    aria-haspopup="true"
                    aria-expanded="false"
                    class="flex items-center gap-3 px-2 py-1.5 rounded-lg hover:bg-teal-50 transition-colors">

              <?php if ($hasProfilePicture): ?>
                <img src="<?= e($profilePictureUrl) ?>" alt="Admin Profile"
                     class="w-9 h-9 rounded-full object-cover border-2 border-teal-600" />
              <?php else: ?>
                <div class="w-9 h-9 rounded-full bg-teal-600 flex items-center justify-center text-white">
                  <i data-lucide="user" class="w-5 h-5 text-white"></i>
                </div>
              <?php endif; ?>

              <span class="hidden sm:block text-sm font-semibold text-teal-900 max-w-[10rem] truncate">
                <?= e($displayName) ?>
              </span>

              <i data-lucide="chevron-down" id="admin-chevron" class="w-4 h-4 text-teal-600 transition-transform duration-300"></i>
            </button>

            <div id="admin-dropdown-menu"
                 class="hidden absolute right-0 mt-2 w-64 bg-white rounded-xl shadow-xl border border-teal-100 py-2 z-50 overflow-hidden">

              <div class="px-4 py-3 border-b border-teal-100 bg-teal-50/60">
                <div class="flex items-center gap-3">
                  <?php if ($hasProfilePicture): ?>
                    <img src="<?= e($profilePictureUrl) ?>" alt="Admin Profile"
                         class="w-12 h-12 rounded-full object-cover border-2 border-teal-600" />
                  <?php else: ?>
                    <div class="w-12 h-12 rounded-full bg-teal-600 flex items-center justify-center text-white">
                      <i data-lucide="user" class="w-6 h-6 text-white"></i>
                    </div>
                  <?php endif; ?>
                  <div class="min-w-0 flex-1">
                    <p class="text-sm font-bold text-teal-900 truncate"><?= e($displayName) ?></p>
                    <p class="text-xs text-teal-900/60 truncate"><?= e($adminEmail) ?></p>
                  </div>
                </div>
              </div>

              <a href="dashboard.php" class="flex items-center px-4 py-2.5 text-sm text-teal-900 hover:bg-teal-50 transition-all group">
                <i data-lucide="layout-dashboard" class="w-4 h-4 mr-3 text-teal-600"></i>
                <span class="font-medium">Dashboard</span>
                <i data-lucide="arrow-right" class="w-4 h-4 ml-auto opacity-0 group-hover:opacity-100 text-teal-600 transition-opacity"></i>
              </a>

              <a href="profile.php" class="flex items-center px-4 py-2.5 text-sm text-teal-900 hover:bg-teal-50 transition-all group">
                <i data-lucide="user" class="w-4 h-4 mr-3 text-teal-600"></i>
                <span class="font-medium">My Profile</span>
                <i data-lucide="arrow-right" class="w-4 h-4 ml-auto opacity-0 group-hover:opacity-100 text-teal-600 transition-opacity"></i>
              </a>

              <a href="change_password.php" class="flex items-center px-4 py-2.5 text-sm text-teal-900 hover:bg-teal-50 transition-all group">
                <i data-lucide="key" class="w-4 h-4 mr-3 text-teal-600"></i>
                <span class="font-medium">Change Password</span>
                <i data-lucide="arrow-right" class="w-4 h-4 ml-auto opacity-0 group-hover:opacity-100 text-teal-600 transition-opacity"></i>
              </a>

              <div class="border-t border-teal-100 mt-1 pt-1">
                <a href="#" data-logout-trigger="1" id="dropdownLogoutBtn"
                   class="flex items-center px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 transition-all group">
                  <i data-lucide="log-out" class="w-4 h-4 mr-3"></i>
                  <span class="font-medium">Logout</span>
                </a>
              </div>
            </div>
          </div>

        </div>
      </header>

      <!-- PAGE CONTENT -->
      <main class="flex-1 px-4 sm:px-6 py-6 sm:py-8">

        <?php if ($dbError): ?>
          <div class="max-w-6xl mx-auto mb-6 rounded-xl border-2 border-amber-200 bg-amber-50 px-4 py-3 flex items-start gap-2">
            <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-amber-700"><?= e($dbError) ?></p>
          </div>
        <?php endif; ?>

        <!-- Page heading -->
        <div class="max-w-6xl mx-auto mb-8 animate-fade-up">
          <div class="flex items-center gap-2 mb-2">
            <span class="inline-flex items-center justify-center w-2 h-2 rounded-full bg-teal-600 animate-pulse-ring"></span>
            <p class="text-xs font-semibold text-teal-600 uppercase tracking-wider">Admin Console</p>
          </div>
          <h1 class="text-2xl md:text-3xl lg:text-4xl font-bold text-teal-900 mb-3">
            <span class="heading-underline">Welcome back, <?= e($displayName) ?></span>
          </h1>
          <p class="text-sm md:text-base text-teal-900/60">
            Manage settings, members, and grievances from one place.
          </p>
        </div>

        <!-- NAV CARDS -->
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4 max-w-6xl mx-auto mb-10">
          <?php foreach ($navCards as $i => $card): ?>
            <a href="<?= e($card['href']) ?>"
               class="reveal lift glow-card group flex flex-col p-5 rounded-2xl border-2 border-teal-100 bg-white
                      hover:border-teal-600 hover:shadow-xl transition-all duration-300"
               style="transition-delay: <?= $i * 60 ?>ms">
              <div class="inline-flex items-center justify-center w-11 h-11 rounded-xl bg-teal-50 text-teal-600 mb-4
                          group-hover:bg-teal-600 group-hover:text-white group-hover:scale-110
                          transition-all duration-300 ease-out">
                <i data-lucide="<?= e($card['icon']) ?>" class="w-5 h-5"></i>
              </div>
              <p class="text-sm font-bold text-teal-900 leading-tight mb-2"><?= e($card['title']) ?></p>
              <span class="mt-auto inline-flex items-center gap-1.5 text-teal-600 font-semibold text-xs">
                Open
                <i data-lucide="arrow-right" class="w-3.5 h-3.5 group-hover:translate-x-1 transition-transform duration-300"></i>
              </span>
            </a>
          <?php endforeach; ?>
        </div>

        <!-- METRICS -->
        <div class="max-w-6xl mx-auto mb-10">
          <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg md:text-xl font-bold text-teal-900">Overview</h2>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <?php
            $metrics = [
              ['icon' => 'inbox',        'value' => $totalGrievances,   'label' => 'Total Grievances',   'delay' => 0],
              ['icon' => 'clock',        'value' => $pendingGrievances, 'label' => 'Pending Grievances', 'delay' => 80],
              ['icon' => 'check-circle', 'value' => $closedGrievances,  'label' => 'Closed Grievances',  'delay' => 160],
            ];
            foreach ($metrics as $m): ?>
              <div class="reveal lift metric-card relative overflow-hidden flex items-center gap-4 bg-white rounded-2xl border-2 border-teal-100 p-5
                          hover:border-teal-600 hover:shadow-xl transition-all duration-300"
                   style="transition-delay: <?= $m['delay'] ?>ms">
                <span class="metric-accent absolute top-0 left-0 h-1 w-full bg-gradient-to-r from-teal-400 via-teal-600 to-teal-400"></span>
                <div class="metric-icon shrink-0 inline-flex items-center justify-center w-14 h-14 rounded-xl bg-teal-50 text-teal-600">
                  <i data-lucide="<?= e($m['icon']) ?>" class="w-7 h-7"></i>
                </div>
                <div class="min-w-0">
                  <span class="metric-value block text-2xl md:text-3xl font-bold text-teal-900 leading-none"
                        data-count="<?= (int) $m['value'] ?>">0</span>
                  <span class="block text-xs md:text-sm font-semibold text-teal-900/60 mt-1.5"><?= e($m['label']) ?></span>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- SUMMARY CALLOUT -->
        <div class="max-w-6xl mx-auto">
          <a href="summary_details.php"
             class="reveal cta-sweep group flex flex-col sm:flex-row items-start sm:items-center gap-5
                    bg-gradient-to-br from-teal-600 via-teal-600 to-teal-700 hover:from-teal-700 hover:to-teal-800
                    text-white rounded-2xl p-6 shadow-md hover:shadow-2xl transition-all duration-300
                    hover:-translate-y-0.5">

            <div class="shrink-0 inline-flex items-center justify-center w-14 h-14 rounded-xl bg-white/15 border border-white/30
                        group-hover:scale-110 group-hover:rotate-3 transition-transform duration-300">
              <i data-lucide="clipboard-list" class="w-7 h-7 text-white"></i>
            </div>

            <div class="flex-1">
              <h3 class="text-lg md:text-xl font-bold text-white mb-1">Grievance Summary</h3>
              <p class="text-sm text-teal-50 leading-relaxed">
                View a consolidated snapshot of all grievance activity across the portal.
              </p>
            </div>

            <span class="inline-flex items-center gap-2 text-white font-semibold text-sm shrink-0">
              Open summary
              <i data-lucide="arrow-right" class="w-4 h-4 group-hover:translate-x-1 transition-transform duration-300"></i>
            </span>
          </a>
        </div>

      </main>

      <!-- FOOTER -->
      <footer class="bg-teal-900 text-white mt-auto">
        <div class="roofline"></div>
        <div class="px-4 sm:px-6 py-8">
          <div class="max-w-7xl mx-auto">

            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 mb-6">

              <div>
                <div class="flex items-center gap-3 mb-3 bg-white rounded-lg px-3 py-2 w-fit">
                  <img src="../public/rcss-logo.webp" alt="RCSS Logo" class="h-8 w-auto" />
                </div>
                <p class="text-teal-100/80 text-sm leading-relaxed max-w-xs">
                  Committed to fairness, transparency and prompt grievance redressal.
                </p>
              </div>

              <div>
                <h4 class="font-bold text-sm uppercase tracking-wide text-teal-200 mb-3">Quick Links</h4>
                <ul class="space-y-2 text-sm">
                  <li>
                    <a href="dashboard.php" class="text-teal-100/80 hover:text-white transition-colors inline-flex items-center gap-1.5 group">
                      <i data-lucide="arrow-right" class="w-3 h-3 group-hover:translate-x-0.5 transition-transform"></i>
                      <span>Dashboard</span>
                    </a>
                  </li>
                  <li>
                    <a href="profile.php" class="text-teal-100/80 hover:text-white transition-colors inline-flex items-center gap-1.5 group">
                      <i data-lucide="arrow-right" class="w-3 h-3 group-hover:translate-x-0.5 transition-transform"></i>
                      <span>My Profile</span>
                    </a>
                  </li>
                  <li>
                    <a href="change_password.php" class="text-teal-100/80 hover:text-white transition-colors inline-flex items-center gap-1.5 group">
                      <i data-lucide="arrow-right" class="w-3 h-3 group-hover:translate-x-0.5 transition-transform"></i>
                      <span>Change Password</span>
                    </a>
                  </li>
                </ul>
              </div>

              <div>
                <h4 class="font-bold text-sm uppercase tracking-wide text-teal-200 mb-3">Contact Support</h4>
                <ul class="space-y-2 text-sm text-teal-100/80">
                  <li class="flex items-center gap-2">
                    <i data-lucide="mail" class="w-4 h-4 text-teal-300"></i>
                    <span>admin.support@rajagiri.edu</span>
                  </li>
                  <li class="flex items-center gap-2">
                    <i data-lucide="phone" class="w-4 h-4 text-teal-300"></i>
                    <span>+91 484 XXX XXXX</span>
                  </li>
                  <li class="flex items-center gap-2">
                    <i data-lucide="map-pin" class="w-4 h-4 text-teal-300"></i>
                    <span>Kalamassery, Kochi, Kerala</span>
                  </li>
                </ul>
              </div>

            </div>

            <div class="border-t border-white/10 pt-4">
              <div class="flex flex-col sm:flex-row items-center justify-between gap-2">
                <p class="text-xs text-teal-200/70 text-center sm:text-left">
                  &copy; <?= date('Y') ?>
                  <span class="font-bold text-white">Rajagiri College of Social Sciences</span>.
                  All rights reserved.
                </p>
                <p class="text-xs text-teal-200/70">
                  Powered by <span class="font-bold text-white">RLabZ</span>
                </p>
              </div>
            </div>

          </div>
        </div>
      </footer>

    </div>

  </div>

  <!-- BACK TO TOP -->
  <button id="backToTop" aria-label="Back to top"
          class="fixed bottom-6 right-6 z-50 w-12 h-12 rounded-full bg-teal-600 hover:bg-teal-700
                 text-white shadow-lg hover:shadow-xl flex items-center justify-center
                 hover:scale-110 transition-all duration-300">
    <i data-lucide="arrow-up" class="w-5 h-5"></i>
  </button>

  <!-- LOGOUT MODAL -->
  <div id="logoutConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeLogoutModal()"></div>

    <div id="logoutConfirmPanel" class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-teal-600"></div>

      <div class="px-6 pt-6 pb-2 flex flex-col items-center text-center">
        <div class="w-16 h-16 rounded-full flex items-center justify-center mb-4 bg-red-50 ring-4 ring-red-100/60">
          <i data-lucide="log-out" class="w-8 h-8 text-red-500"></i>
        </div>

        <h3 class="text-xl font-bold text-teal-900 mb-2">Log Out?</h3>

        <p class="text-sm text-teal-900/70 leading-relaxed">
          You are about to log out of <span class="font-bold text-teal-700 break-words"><?= e($displayName) ?></span>.
          Any unsaved changes will be lost.
        </p>

        <p class="text-xs text-teal-900/50 font-medium mt-3 flex items-center gap-1.5">
          <i data-lucide="info" class="w-3.5 h-3.5"></i> You can log back in anytime.
        </p>
      </div>

      <div class="px-6 py-5 mt-2 flex flex-col-reverse sm:flex-row gap-3">
        <button type="button" onclick="closeLogoutModal()"
                class="flex-1 px-5 py-3 rounded-lg font-semibold text-teal-900 bg-white border-2 border-teal-200
                       hover:border-teal-600 hover:bg-teal-50 transition-all duration-200">
          Cancel
        </button>
        <button type="button" id="confirmLogoutBtn"
                class="flex-1 px-5 py-3 rounded-lg font-bold text-white bg-teal-600 hover:bg-teal-700
                       shadow-sm hover:shadow-md transition-all duration-200
                       flex items-center justify-center gap-2">
          <i data-lucide="log-out" class="w-4 h-4"></i>
          <span>Log Out</span>
        </button>
      </div>
    </div>
  </div>

  <!-- SCRIPTS -->
  <script>
    document.addEventListener('DOMContentLoaded', function () {

      if (typeof lucide !== 'undefined') {
        lucide.createIcons();
      }

      const sidebar = document.getElementById('adminSidebar');
      const main    = document.getElementById('adminMain');

      /* ---------------------------------------------------------
         Sidebar expand/collapse
         --------------------------------------------------------- */
      (function () {
        const toggleBtn = document.getElementById('sidebarToggle');
        if (!toggleBtn || !sidebar || !main) return;

        const labels   = sidebar.querySelectorAll('.sidebar-label');
        const tooltips = sidebar.querySelectorAll('.sidebar-tooltip');
        const chevrons = sidebar.querySelectorAll('.submenu-chevron');
        const submenus = sidebar.querySelectorAll('.submenu');

        let expanded = false;

        toggleBtn.addEventListener('click', function () {
          expanded = !expanded;

          if (expanded) {
            sidebar.classList.remove('w-20'); sidebar.classList.add('w-64');
            main.classList.remove('ml-20');   main.classList.add('ml-64');
            labels.forEach(function (el) { el.classList.remove('opacity-0','w-0'); el.classList.add('opacity-100','w-auto'); });
            tooltips.forEach(function (el) { el.classList.add('hidden'); });
          } else {
            sidebar.classList.add('w-20');    sidebar.classList.remove('w-64');
            main.classList.add('ml-20');      main.classList.remove('ml-64');
            labels.forEach(function (el) { el.classList.add('opacity-0','w-0'); el.classList.remove('opacity-100','w-auto'); });
            tooltips.forEach(function (el) { el.classList.remove('hidden'); });

            submenus.forEach(function (sm) { sm.classList.add('hidden'); });
            chevrons.forEach(function (ch) { ch.classList.remove('rotate-180'); });
          }

          setTimeout(function () {
            if (typeof lucide !== 'undefined') lucide.createIcons();
          }, 250);
        });
      })();

      /* ---------------------------------------------------------
         Sub-menu toggles / navigation
         - Collapsed (w-20) → navigate to landing page
         - Expanded  (w-64) → toggle submenu
         --------------------------------------------------------- */
      (function () {
        const toggles = document.querySelectorAll('[data-submenu-toggle]');
        if (!toggles.length) return;

        toggles.forEach(function (btn) {
          btn.addEventListener('click', function (e) {
            const sidebarEl = document.getElementById('adminSidebar');
            const isCollapsed = sidebarEl && sidebarEl.classList.contains('w-20');

            if (isCollapsed) {
              const href = btn.getAttribute('data-landing-href');
              if (href) {
                window.location.href = href;
              }
              return;
            }

            e.preventDefault();
            e.stopPropagation();

            const sectionId = btn.getAttribute('data-submenu-toggle');
            const submenu   = document.getElementById('submenu-' + sectionId);
            const chevron   = btn.querySelector('.submenu-chevron');

            if (!submenu) return;

            const isOpen = !submenu.classList.contains('hidden');

            document.querySelectorAll('.submenu').forEach(function (sm) {
              if (sm !== submenu) sm.classList.add('hidden');
            });
            document.querySelectorAll('.submenu-chevron').forEach(function (ch) {
              if (ch !== chevron) ch.classList.remove('rotate-180');
            });

            if (isOpen) {
              submenu.classList.add('hidden');
              if (chevron) chevron.classList.remove('rotate-180');
            } else {
              submenu.classList.remove('hidden');
              if (chevron) chevron.classList.add('rotate-180');
            }
          });
        });
      })();

      /* ---------------------------------------------------------
         Admin Profile Dropdown
         --------------------------------------------------------- */
      (function () {
        const btn       = document.getElementById('admin-dropdown-btn');
        const menu      = document.getElementById('admin-dropdown-menu');
        const chevron   = document.getElementById('admin-chevron');
        const container = document.getElementById('admin-dropdown-container');
        if (!btn || !menu || !container) return;

        btn.addEventListener('click', function (e) {
          e.stopPropagation();
          const isOpen = !menu.classList.contains('hidden');
          if (isOpen) {
            menu.classList.add('hidden');
            menu.classList.remove('animate-dropdown');
            if (chevron) chevron.classList.remove('rotate-180');
            btn.setAttribute('aria-expanded', 'false');
          } else {
            menu.classList.remove('hidden');
            menu.classList.add('animate-dropdown');
            if (chevron) chevron.classList.add('rotate-180');
            btn.setAttribute('aria-expanded', 'true');
          }
        });

        document.addEventListener('click', function (e) {
          if (!container.contains(e.target)) {
            menu.classList.add('hidden');
            menu.classList.remove('animate-dropdown');
            if (chevron) chevron.classList.remove('rotate-180');
            btn.setAttribute('aria-expanded', 'false');
          }
        });

        document.addEventListener('keydown', function (e) {
          if (e.key === 'Escape') {
            menu.classList.add('hidden');
            menu.classList.remove('animate-dropdown');
            if (chevron) chevron.classList.remove('rotate-180');
            btn.setAttribute('aria-expanded', 'false');
          }
        });
      })();

      /* ---------------------------------------------------------
         Logout Confirmation Modal
         --------------------------------------------------------- */
      (function () {
        const logoutConfirmModal = document.getElementById('logoutConfirmModal');
        const logoutConfirmPanel = document.getElementById('logoutConfirmPanel');
        const confirmLogoutBtn   = document.getElementById('confirmLogoutBtn');
        const LOGOUT_URL         = '../logout.php?role=admin';

        if (!logoutConfirmModal) return;

        window.openLogoutModal = function () {
          logoutConfirmModal.classList.remove('hidden');
          document.body.classList.add('overflow-hidden');
          if (logoutConfirmPanel) {
            logoutConfirmPanel.classList.remove('animate-confirm-shake');
            void logoutConfirmPanel.offsetWidth;
            logoutConfirmPanel.classList.add('animate-confirm-shake');
          }
          setTimeout(function () { if (confirmLogoutBtn) confirmLogoutBtn.focus(); }, 80);
          if (typeof lucide !== 'undefined') lucide.createIcons();
        };

        window.closeLogoutModal = function () {
          logoutConfirmModal.classList.add('hidden');
          document.body.classList.remove('overflow-hidden');
        };

        [document.getElementById('sidebarLogoutBtn'), document.getElementById('dropdownLogoutBtn')].forEach(function (btn) {
          if (!btn) return;
          btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            window.openLogoutModal();
          });
        });

        if (confirmLogoutBtn) {
          confirmLogoutBtn.addEventListener('click', function () {
            confirmLogoutBtn.classList.add('opacity-50', 'pointer-events-none');
            window.location.href = LOGOUT_URL;
          });
        }

        document.addEventListener('keydown', function (e) {
          if (e.key === 'Escape' && !logoutConfirmModal.classList.contains('hidden')) {
            window.closeLogoutModal();
          }
        });
      })();

      /* ---------------------------------------------------------
         Scroll Reveal (IntersectionObserver)
         --------------------------------------------------------- */
      (function () {
        const items = document.querySelectorAll('.reveal');
        if (!items.length) return;

        if (!('IntersectionObserver' in window)) {
          items.forEach(function (el) { el.classList.add('is-visible'); });
          return;
        }

        const io = new IntersectionObserver(function (entries) {
          entries.forEach(function (entry) {
            if (entry.isIntersecting) {
              entry.target.classList.add('is-visible');
              io.unobserve(entry.target);
            }
          });
        }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });

        items.forEach(function (el) { io.observe(el); });
      })();

      /* ---------------------------------------------------------
         Count-up animation for metrics
         (shorter duration on mobile, integer output for perf)
         --------------------------------------------------------- */
      (function () {
        const counters = document.querySelectorAll('.metric-value');
        if (!counters.length) return;

        const isMobile = window.matchMedia('(max-width: 640px)').matches;

        const runCounter = function (el) {
          const target = parseInt(el.dataset.count || '0', 10);
          const duration = isMobile ? 700 : 1200;
          const start = performance.now();

          const tick = function (now) {
            const t = Math.min((now - start) / duration, 1);
            const eased = 1 - Math.pow(1 - t, 3); // easeOutCubic
            el.textContent = Math.round(eased * target).toString();
            if (t < 1) requestAnimationFrame(tick);
          };
          requestAnimationFrame(tick);
        };

        if (!('IntersectionObserver' in window)) {
          counters.forEach(runCounter);
          return;
        }

        const io = new IntersectionObserver(function (entries) {
          entries.forEach(function (entry) {
            if (entry.isIntersecting) {
              runCounter(entry.target);
              io.unobserve(entry.target);
            }
          });
        }, { threshold: 0.4 });

        counters.forEach(function (el) { io.observe(el); });
      })();

      /* ---------------------------------------------------------
         Header frosted-on-scroll
         --------------------------------------------------------- */
      (function () {
        const header = document.querySelector('header');
        if (!header) return;

        const onScroll = function () {
          if (window.scrollY > 10) header.classList.add('header-scrolled');
          else header.classList.remove('header-scrolled');
        };
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
      })();

      /* ---------------------------------------------------------
         Back-to-top
         --------------------------------------------------------- */
      (function () {
        const btn = document.getElementById('backToTop');
        if (!btn) return;

        const onScroll = function () {
          if (window.scrollY > 400) btn.classList.add('show');
          else btn.classList.remove('show');
        };
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();

        btn.addEventListener('click', function () {
          window.scrollTo({ top: 0, behavior: 'smooth' });
        });
      })();

      /* ---------------------------------------------------------
         Subtle 3D tilt on cards (desktop-only, rAF throttled)
         --------------------------------------------------------- */
      (function () {
        if (!window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        if (window.innerWidth < 1024) return;

        let rafId = null;
        document.querySelectorAll('.lift').forEach(function (card) {
          card.addEventListener('mousemove', function (e) {
            if (rafId) cancelAnimationFrame(rafId);
            rafId = requestAnimationFrame(function () {
              const r = card.getBoundingClientRect();
              const x = ((e.clientX - r.left) / r.width - 0.5) * 5;
              const y = ((e.clientY - r.top) / r.height - 0.5) * -5;
              card.style.transform = 'translateY(-4px) perspective(800px) rotateX(' + y + 'deg) rotateY(' + x + 'deg)';
            });
          });
          card.addEventListener('mouseleave', function () {
            if (rafId) cancelAnimationFrame(rafId);
            card.style.transform = '';
          });
        });
      })();

    });
  </script>

  <script src="../assets/js/index.js"></script>
</body>
</html>
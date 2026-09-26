<?php
/**
 * admin/grievance_details.php
 * ---------------------------------------------------------------------------
 * Admin — Grievance Details (list all filed grievances)
 * Rajagiri College Grievance Redressal Portal
 *
 * Features:
 *   • Lists all grievances with joins across users / grievance_types / role tables
 *   • Live search + entries-per-page dropdown (server-side pagination)
 *   • Dynamic status badge colours
 *   • Eye icon  → opens View modal (with attachments + reply attachment)
 *   • List icon → opens Action Summary modal (pulls from grievance_actions)
 *   • Custom themed logout confirmation modal
 *   • Flash messages auto-dismiss after 3 seconds
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

// AUTH GUARD
$sessionRole = isset($_SESSION['role']) ? strtoupper((string) $_SESSION['role']) : '';

if (empty($_SESSION['user_id']) || ($sessionRole !== 'ADMIN' && $sessionRole !== 'MANAGEMENT' && $sessionRole !== 'TEACHER')) {
    header('Location: ../login.php?role=admin');
    exit;
}

$userId = (int) $_SESSION['user_id'];

// DATABASE CONNECTION
$dbFile = __DIR__ . '/../db_connect.php';
$dbError = null;
$conn    = null;

if (!file_exists($dbFile)) {
    $dbError = 'Database configuration file (db_connect.php) not found.';
} else {
    require_once $dbFile;

    if (!isset($conn) || !($conn instanceof mysqli)) {
        $conn = @new mysqli('localhost', 'root', '', 'grievance_db');
        if ($conn->connect_error) {
            $dbError = 'Database connection failed.';
            $conn    = null;
        } else {
            $conn->set_charset('utf8mb4');
        }
    }

    if ($conn && $conn->connect_errno) {
        $dbError = 'Database connection failed.';
        $conn    = null;
    }
}

function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// FETCH ADMIN PROFILE
$adminData = [
    'username'        => $_SESSION['username'] ?? 'Admin',
    'name'            => '',
    'email'           => '',
    'profile_picture' => '',
];

if ($conn instanceof mysqli) {
    try {
        $sql = "SELECT  u.username, ap.name, ap.email, ap.profile_picture
                FROM users u
                LEFT JOIN admin_profiles ap ON ap.user_id = u.id
                WHERE u.id = ?
                LIMIT 1";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();

            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();
                $adminData['username']        = $row['username']        ?? $adminData['username'];
                $adminData['name']            = $row['name']            ?? '';
                $adminData['email']           = $row['email']           ?? '';
                $adminData['profile_picture'] = $row['profile_picture'] ?? '';
            }
            $stmt->close();
        }
    } catch (Throwable $ex) {
        error_log('[Grievance Details Admin Profile] ' . $ex->getMessage());
    }
}

$displayName  = !empty($adminData['name']) ? $adminData['name'] : $adminData['username'];
$displayEmail = !empty($adminData['email']) ? $adminData['email'] : 'admin@rajagiri.edu';

$hasProfilePicture = false;
$profilePictureUrl = '';
if (!empty($adminData['profile_picture'])) {
    $relativeFromAdmin = '../' . ltrim((string) $adminData['profile_picture'], '/');
    if (file_exists(__DIR__ . '/../' . ltrim((string) $adminData['profile_picture'], '/'))) {
        $hasProfilePicture = true;
        $profilePictureUrl = $relativeFromAdmin;
    }
}

// FLASH
$flashSuccess = '';
$flashError   = '';

if (!empty($_SESSION['flash_success'])) {
    $flashSuccess = (string) $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    $flashError = (string) $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// QUERY PARAMS
$search  = trim((string) ($_GET['q']       ?? ''));
$entries = (int) ($_GET['entries']          ?? 10);
$page    = (int) ($_GET['page']             ?? 1);

if (!in_array($entries, [10, 25, 50, 100], true)) $entries = 10;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $entries;

// FETCH GRIEVANCES
$grievances = [];
$totalRows  = 0;
$totalPages = 1;

if ($conn instanceof mysqli) {
    try {
        $whereSql = " WHERE 1=1";
        $params   = [];
        $types    = '';

        if ($search !== '') {
            $whereSql .= " AND (
                g.grievance_number LIKE ?
                OR g.subject LIKE ?
                OR gt.type_name LIKE ?
                OR COALESCE(s.name, p.name, cm.name, ap.name) LIKE ?
            )";
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $types   .= 'ssss';
        }

        $countSql = "SELECT COUNT(*) AS c
                     FROM grievances g
                     LEFT JOIN grievance_types gt ON g.grievance_type_id   = gt.id
                     LEFT JOIN users u            ON g.complainant_user_id = u.id
                     LEFT JOIN students s         ON u.id = s.user_id
                     LEFT JOIN parents p          ON u.id = p.user_id
                     LEFT JOIN cell_members cm    ON u.id = cm.user_id
                     LEFT JOIN admin_profiles ap  ON u.id = ap.user_id
                     $whereSql";

        $stmt = $conn->prepare($countSql);
        if ($stmt) {
            if (!empty($params)) $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res       = $stmt->get_result();
            $totalRows = (int) ($res ? ($res->fetch_assoc()['c'] ?? 0) : 0);
            $stmt->close();
        }

        $totalPages = max(1, (int) ceil($totalRows / $entries));
        if ($page > $totalPages) {
            $page   = $totalPages;
            $offset = ($page - 1) * $entries;
        }

        $dataSql = "SELECT  g.id, g.grievance_number, g.subject, g.description, g.status,
                            g.attachment_path, g.reply_details, g.reply_attachment_path,
                            g.feedback_details, g.created_at, g.updated_at, g.attended_by,
                            gt.type_name,
                            u.role AS complainant_role,
                            COALESCE(s.name, p.name, cm.name, ap.name, u.username) AS complainant_name,
                            COALESCE(s.email, p.email, cm.email, ap.email) AS complainant_email,
                            att_cm.name AS attendee_name
                    FROM grievances g
                    LEFT JOIN grievance_types gt ON g.grievance_type_id   = gt.id
                    LEFT JOIN users u            ON g.complainant_user_id = u.id
                    LEFT JOIN students s         ON u.id = s.user_id
                    LEFT JOIN parents p          ON u.id = p.user_id
                    LEFT JOIN cell_members cm    ON u.id = cm.user_id
                    LEFT JOIN admin_profiles ap  ON u.id = ap.user_id
                    LEFT JOIN cell_members att_cm ON g.attended_by = att_cm.id
                    $whereSql
                    ORDER BY g.id DESC
                    LIMIT ? OFFSET ?";

        $dataParams   = $params;
        $dataTypes    = $types . 'ii';
        $dataParams[] = $entries;
        $dataParams[] = $offset;

        $stmt = $conn->prepare($dataSql);
        if ($stmt) {
            $stmt->bind_param($dataTypes, ...$dataParams);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $grievances[] = $row;
            }
            $stmt->close();
        }
    } catch (Throwable $ex) {
        error_log('[Fetch Grievances] ' . $ex->getMessage());
    }
}

// FETCH ACTION SUMMARIES
$actionsByGrievance = [];

if ($conn instanceof mysqli && !empty($grievances)) {
    try {
        $ids = array_map(function ($g) { return (int) $g['id']; }, $grievances);
        $ids = array_values(array_unique(array_filter($ids)));

        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            $sql = "SELECT  ga.id, ga.grievance_id, ga.action_date, ga.attendee_id,
                            ga.summary, ga.action_taken, ga.created_by, ga.created_at,
                            cm.name AS attendee_name,
                            d.designation_name AS attendee_designation,
                            u.username AS created_by_username
                    FROM grievance_actions ga
                    LEFT JOIN cell_members cm ON cm.id = ga.attendee_id
                    LEFT JOIN designations d  ON d.id  = cm.designation_id
                    LEFT JOIN users u         ON u.id  = ga.created_by
                    WHERE ga.grievance_id IN ($placeholders)
                    ORDER BY ga.action_date ASC, ga.id ASC";

            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $types = str_repeat('i', count($ids));
                $stmt->bind_param($types, ...$ids);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) {
                    $gid = (int) $r['grievance_id'];
                    if (!isset($actionsByGrievance[$gid])) $actionsByGrievance[$gid] = [];
                    $actionsByGrievance[$gid][] = $r;
                }
                $stmt->close();
            }
        }
    } catch (Throwable $ex) {
        error_log('[Action Summary Fetch] ' . $ex->getMessage());
    }
}

function statusBadgeClass(string $status): string
{
    return match (strtolower($status)) {
        'pending'     => 'bg-amber-100 text-amber-800 border-amber-200',
        'in progress' => 'bg-sky-100 text-sky-800 border-sky-200',
        'disposed'    => 'bg-emerald-100 text-emerald-800 border-emerald-200',
        'closed'      => 'bg-slate-100 text-slate-700 border-slate-200',
        'reopened'    => 'bg-pink-100 text-pink-800 border-pink-200',
        default       => 'bg-slate-100 text-slate-700 border-slate-200',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Grievance Details — Admin | Rajagiri College Grievance Portal</title>
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
            fadeInUp: { '0%': { opacity: '0', transform: 'translateY(12px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
            dropdownFade: { '0%': { opacity: '0', transform: 'translateY(-8px) scale(0.98)' }, '100%': { opacity: '1', transform: 'translateY(0) scale(1)' } },
            modalFadeIn: { '0%': { opacity: '0', transform: 'scale(0.96)' }, '100%': { opacity: '1', transform: 'scale(1)' } },
            confirmShake: { '0%, 100%': { transform: 'translateX(0)' }, '20%': { transform: 'translateX(-6px)' }, '40%': { transform: 'translateX(6px)' }, '60%': { transform: 'translateX(-4px)' }, '80%': { transform: 'translateX(4px)' } },
            flashIn: { '0%': { opacity: '0', transform: 'translateY(-10px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
            flashOut: { '0%': { opacity: '1', transform: 'translateY(0)', maxHeight: '200px' }, '100%': { opacity: '0', transform: 'translateY(-10px)', maxHeight: '0px' } },
            lightboxIn: { '0%': { opacity: '0' }, '100%': { opacity: '1' } }
          },
          animation: {
            'fade-in-up': 'fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'dropdown':   'dropdownFade 0.2s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'modal-in':   'modalFadeIn 0.25s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'confirm-shake': 'confirmShake 0.5s cubic-bezier(0.16, 1, 0.3, 1)',
            'flash-in':   'flashIn 0.35s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'flash-out':  'flashOut 0.45s cubic-bezier(0.4, 0, 1, 1) forwards',
            'lightbox-in':'lightboxIn 0.25s ease-out forwards'
          }
        }
      }
    };
  </script>

  <style>
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
    .hero-dots {
      background-image: radial-gradient(rgba(255,255,255,0.35) 1.5px, transparent 1.5px);
      background-size: 22px 22px;
    }
    .roofline {
      height: 14px;
      background-image: linear-gradient(45deg, transparent 33.33%, #006E74 33.33%, #006E74 66.66%, transparent 66.66%),
                        linear-gradient(-45deg, transparent 33.33%, #006E74 33.33%, #006E74 66.66%, transparent 66.66%);
      background-size: 20px 14px; background-repeat: repeat-x;
    }
    .logo-divider { width: 1px; background-color: #CFE6E7; }
    #sidebarNav::-webkit-scrollbar { width: 6px; }
    #sidebarNav::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 3px; }
  </style>
</head>

<body class="min-h-screen bg-teal-50/40 text-teal-900 antialiased selection:bg-teal-100 selection:text-teal-700 flex flex-col">

  <div class="flex min-h-screen flex-1">

    <!-- SIDEBAR -->
    <aside id="adminSidebar"
           class="w-20 bg-teal-800 flex flex-col py-4 shadow-xl fixed inset-y-0 left-0 z-40
                  transition-all duration-300 ease-in-out overflow-hidden">

      <div class="flex items-center justify-between px-3 mb-6 flex-shrink-0">
        <a href="dashboard.php" class="flex items-center gap-3 overflow-hidden">
          <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-white shrink-0 shadow-sm">
            <img src="../public/rcss-logo.webp" alt="RCSS" class="h-6 w-auto" onerror="this.style.display='none'" />
          </span>
          <span class="sidebar-label text-white font-bold text-lg whitespace-nowrap opacity-0 w-0 overflow-hidden transition-all duration-200">grievance</span>
        </a>
        <button id="sidebarToggle"
                class="text-white/70 hover:text-white p-2 rounded-lg hover:bg-white/10 transition-colors
                       flex items-center justify-center flex-shrink-0" aria-label="Toggle sidebar">
          <i data-lucide="menu" class="w-5 h-5 flex-shrink-0"></i>
        </button>
      </div>

      <nav id="sidebarNav" class="flex flex-col space-y-1 flex-1 w-full px-3 pt-1 overflow-y-auto overflow-x-hidden">

        <a href="dashboard.php" class="group relative w-full h-12 rounded-xl hover:bg-white/10 flex items-center text-white transition-all px-3 flex-shrink-0">
          <i data-lucide="home" class="w-6 h-6 flex-shrink-0"></i>
          <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap opacity-0 w-0 overflow-hidden transition-all duration-200">Dashboard</span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-teal-900 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Dashboard</span>
        </a>

        <a href="profile.php" class="group relative w-full h-12 rounded-xl hover:bg-white/10 flex items-center text-white transition-all px-3 flex-shrink-0">
          <i data-lucide="user" class="w-6 h-6 flex-shrink-0"></i>
          <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap opacity-0 w-0 overflow-hidden transition-all duration-200">My Profile</span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-teal-900 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">My Profile</span>
        </a>

        <a href="grievances.php" class="group relative w-full h-12 rounded-xl bg-white text-teal-800 shadow-md flex items-center px-3 flex-shrink-0 transition-all">
          <i data-lucide="clipboard-list" class="w-6 h-6 flex-shrink-0"></i>
          <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap opacity-0 w-0 overflow-hidden transition-all duration-200">Grievance</span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-teal-900 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Grievance</span>
        </a>

        <a href="settings.php" class="group relative w-full h-12 rounded-xl hover:bg-white/10 flex items-center text-white transition-all px-3 flex-shrink-0">
          <i data-lucide="settings" class="w-6 h-6 flex-shrink-0"></i>
          <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap opacity-0 w-0 overflow-hidden transition-all duration-200">Settings</span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-teal-900 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Settings</span>
        </a>

        <a href="change_password.php" class="group relative w-full h-12 rounded-xl hover:bg-white/10 flex items-center text-white transition-all px-3 flex-shrink-0">
          <i data-lucide="key" class="w-6 h-6 flex-shrink-0"></i>
          <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap opacity-0 w-0 overflow-hidden transition-all duration-200">Change Password</span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-teal-900 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Change Password</span>
        </a>

      </nav>

      <a href="#" data-logout-trigger="1" id="sidebarLogoutBtn"
         class="group relative w-full h-12 rounded-xl hover:bg-red-500/30 flex items-center text-white transition-all mx-3 px-3 flex-shrink-0"
         style="width: calc(100% - 1.5rem);" title="Logout">
        <i data-lucide="log-out" class="w-6 h-6 flex-shrink-0"></i>
        <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap opacity-0 w-0 overflow-hidden transition-all duration-200">Logout</span>
        <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-red-600 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Logout</span>
      </a>
    </aside>

    <!-- MAIN CONTENT -->
    <div id="adminMain" class="flex-1 ml-20 flex flex-col min-h-screen transition-all duration-300">

      <header class="bg-white border-b-2 border-teal-600 shadow-sm sticky top-0 z-30">
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
            <button id="admin-dropdown-btn" type="button" aria-haspopup="true" aria-expanded="false"
                    class="flex items-center gap-3 px-2 py-1.5 rounded-lg hover:bg-teal-50 transition-colors">

              <?php if ($hasProfilePicture): ?>
                <img src="<?= e($profilePictureUrl) ?>" alt="<?= e($displayName) ?>"
                     class="w-9 h-9 rounded-full object-cover border-2 border-teal-600" />
              <?php else: ?>
                <div class="w-9 h-9 rounded-full bg-teal-600 flex items-center justify-center text-white">
                  <i data-lucide="user" class="w-5 h-5 text-white"></i>
                </div>
              <?php endif; ?>

              <span class="hidden sm:block text-sm font-semibold text-teal-900 max-w-[10rem] truncate"><?= e($displayName) ?></span>
              <i data-lucide="chevron-down" id="admin-chevron" class="w-4 h-4 text-teal-600 transition-transform duration-300"></i>
            </button>

            <div id="admin-dropdown-menu"
                 class="hidden absolute right-0 mt-2 w-64 bg-white rounded-xl shadow-xl border border-teal-100 py-2 z-50 overflow-hidden">

              <div class="px-4 py-3 border-b border-teal-100 bg-teal-50/60">
                <div class="flex items-center gap-3">
                  <?php if ($hasProfilePicture): ?>
                    <img src="<?= e($profilePictureUrl) ?>" alt="<?= e($displayName) ?>"
                         class="w-12 h-12 rounded-full object-cover border-2 border-teal-600" />
                  <?php else: ?>
                    <div class="w-12 h-12 rounded-full bg-teal-600 flex items-center justify-center text-white">
                      <i data-lucide="user" class="w-6 h-6 text-white"></i>
                    </div>
                  <?php endif; ?>
                  <div class="min-w-0 flex-1">
                    <p class="text-sm font-bold text-teal-900 truncate"><?= e($displayName) ?></p>
                    <p class="text-xs text-teal-900/60 truncate"><?= e($displayEmail) ?></p>
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

      <main class="flex-1 px-4 sm:px-6 py-6 sm:py-8">

        <div class="max-w-6xl mx-auto mb-6 animate-fade-in-up">
          <div class="flex items-center gap-2 mb-2">
            <span class="inline-flex items-center justify-center w-2 h-2 rounded-full bg-teal-600"></span>
            <p class="text-xs font-semibold text-teal-600 uppercase tracking-wider">Grievance · Details</p>
          </div>
          <h1 class="text-2xl md:text-3xl font-bold text-teal-900 mb-2">Grievance Details</h1>
          <nav class="flex flex-wrap items-center gap-2 text-sm text-teal-900/60">
            <a href="dashboard.php" class="inline-flex items-center gap-1 hover:text-teal-600 transition-colors">
              <i data-lucide="layout-dashboard" class="w-4 h-4"></i>
              Dashboard
            </a>
            <span class="text-teal-900/30">/</span>
            <a href="grievances.php" class="hover:text-teal-600 transition-colors">Grievance</a>
            <span class="text-teal-900/30">/</span>
            <span class="text-teal-600 font-semibold">Grievance Details</span>
          </nav>
        </div>

        <?php if ($flashSuccess !== ''): ?>
          <div id="flashSuccessBox" class="max-w-6xl mx-auto mb-6 rounded-xl border-2 border-emerald-200 bg-emerald-50 px-4 py-3 flex items-start gap-2 animate-flash-in overflow-hidden">
            <i data-lucide="check-circle" class="w-5 h-5 text-emerald-600 flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-emerald-800 font-medium"><?= e($flashSuccess) ?></p>
          </div>
        <?php endif; ?>

        <?php if ($flashError !== ''): ?>
          <div id="flashErrorBox" class="max-w-6xl mx-auto mb-6 rounded-xl border-2 border-red-200 bg-red-50 px-4 py-3 flex items-start gap-2 animate-flash-in overflow-hidden">
            <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-red-700 font-medium"><?= e($flashError) ?></p>
          </div>
        <?php endif; ?>

        <div class="max-w-6xl mx-auto mb-5 animate-fade-in-up" style="animation-delay: 60ms;">
          <form method="GET" action="grievance_details.php" id="filterForm" class="bg-white rounded-xl border-2 border-teal-100 px-5 py-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
              <div class="flex items-center gap-3">
                <span class="text-sm text-teal-900/70">Show</span>
                <select name="entries" id="entriesPerPage"
                        class="px-3 py-1.5 border-2 border-teal-100 rounded-lg text-sm font-medium text-teal-900
                               focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10
                               hover:border-teal-200 transition-colors bg-white">
                  <option value="10"  <?= $entries === 10  ? 'selected' : '' ?>>10</option>
                  <option value="25"  <?= $entries === 25  ? 'selected' : '' ?>>25</option>
                  <option value="50"  <?= $entries === 50  ? 'selected' : '' ?>>50</option>
                  <option value="100" <?= $entries === 100 ? 'selected' : '' ?>>100</option>
                </select>
                <span class="text-sm text-teal-900/70">entries</span>
              </div>

              <div class="relative w-full sm:w-80">
                <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-teal-900/40"></i>
                <input type="text" name="q" id="searchInput" value="<?= e($search) ?>" placeholder="Search..." autocomplete="off"
                       class="w-full pl-10 pr-4 py-2 border-2 border-teal-100 rounded-lg text-sm bg-white text-teal-900 placeholder-teal-900/40
                              focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10
                              hover:border-teal-200 transition-all" />
              </div>
            </div>
          </form>
        </div>

        <div class="max-w-6xl mx-auto animate-fade-in-up" style="animation-delay: 100ms;">
          <div class="bg-white rounded-2xl border-2 border-teal-100 overflow-hidden">
            <div class="overflow-x-auto">
              <table class="w-full" id="grievancesTable">
                <thead>
                  <tr class="bg-teal-600 text-white">
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Sl.No.</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Grievance Number</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Grievance Type</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Name</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Date</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Subject</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Status</th>
                    <th class="px-6 py-4 text-center text-xs font-bold uppercase tracking-wider">Actions</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Remainder</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-teal-100" id="grievancesTableBody">

                  <?php if (empty($grievances)): ?>
                    <tr>
                      <td colspan="9" class="px-6 py-16 text-center text-teal-900/60">
                        <div class="flex flex-col items-center justify-center">
                          <div class="w-16 h-16 bg-teal-50 rounded-2xl flex items-center justify-center mb-4">
                            <i data-lucide="inbox" class="w-8 h-8 text-teal-600"></i>
                          </div>
                          <p class="text-lg font-semibold text-teal-900">No grievances found</p>
                          <p class="text-sm text-teal-900/60 mt-1 mb-4">
                            <?= $search !== '' ? 'Try adjusting your search.' : 'No grievances have been filed yet.' ?>
                          </p>
                        </div>
                      </td>
                    </tr>
                  <?php else: ?>

                    <?php foreach ($grievances as $index => $gr): ?>
                      <?php
                        $grId         = (int) $gr['id'];
                        $grNumber     = (string) ($gr['grievance_number']  ?? '');
                        $grType       = (string) ($gr['type_name']         ?? 'N/A');
                        $grName       = (string) ($gr['complainant_name']  ?? 'N/A');
                        $grEmail      = (string) ($gr['complainant_email'] ?? '');
                        $grRole       = (string) ($gr['complainant_role']  ?? '');
                        $grDate       = (string) ($gr['created_at']        ?? '');
                        $grUpdated    = (string) ($gr['updated_at']        ?? '');
                        $grSubject    = (string) ($gr['subject']           ?? '');
                        $grDesc       = (string) ($gr['description']       ?? '');
                        $grReply      = (string) ($gr['reply_details']     ?? '');
                        $grFeedback   = (string) ($gr['feedback_details']  ?? '');
                        $grStatus     = (string) ($gr['status']            ?? 'Pending');
                        $grAttendee   = (string) ($gr['attendee_name']     ?? '');
                        $grAttach     = (string) ($gr['attachment_path']   ?? '');
                        $grReplyAttach= (string) ($gr['reply_attachment_path'] ?? '');
                        $statusCls    = statusBadgeClass($grStatus);

                        $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

                        $attachUrl    = '';
                        $attachIsImg  = false;
                        $attachExt    = '';
                        if ($grAttach !== '') {
                            $rel = ltrim($grAttach, '/');
                            if (file_exists(__DIR__ . '/../' . $rel)) {
                                $attachUrl    = '../' . $rel;
                                $attachExt    = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
                                $attachIsImg  = in_array($attachExt, $imageExts, true);
                            }
                        }

                        $replyAttachUrl   = '';
                        $replyAttachIsImg = false;
                        $replyAttachExt   = '';
                        if ($grReplyAttach !== '') {
                            $rel2 = ltrim($grReplyAttach, '/');
                            if (file_exists(__DIR__ . '/../' . $rel2)) {
                                $replyAttachUrl   = '../' . $rel2;
                                $replyAttachExt   = strtolower(pathinfo($rel2, PATHINFO_EXTENSION));
                                $replyAttachIsImg = in_array($replyAttachExt, $imageExts, true);
                            }
                        }

                        $actionsForThis = $actionsByGrievance[$grId] ?? [];
                        $actionsJson = [];
                        foreach ($actionsForThis as $act) {
                            $actionsJson[] = [
                                'date'           => !empty($act['action_date']) ? date('d M Y', strtotime((string) $act['action_date'])) : '',
                                'attendee_name'  => (string) ($act['attendee_name'] ?? ''),
                                'attendee_desig' => (string) ($act['attendee_designation'] ?? ''),
                                'summary'        => (string) ($act['summary'] ?? ''),
                                'action_taken'   => (string) ($act['action_taken'] ?? ''),
                                'created_by'     => (string) ($act['created_by_username'] ?? ''),
                            ];
                        }

                        $formattedDate = '—';
                        if ($grDate !== '') {
                            $ts = strtotime($grDate);
                            if ($ts !== false) $formattedDate = date('Y-m-d', $ts);
                        }

                        $formattedUpdated = '—';
                        if ($grUpdated !== '') {
                            $ts2 = strtotime($grUpdated);
                            if ($ts2 !== false) $formattedUpdated = date('d M Y, h:i A', $ts2);
                        }

                        $globalIndex = $offset + $index + 1;
                      ?>
                      <tr class="hover:bg-teal-50/60 transition-colors">
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-teal-900/70"><?= $globalIndex ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-teal-700"><?= e($grNumber) ?></td>
                        <td class="px-6 py-4 text-sm text-teal-900/80 max-w-[220px]"><?= e($grType) ?></td>
                        <td class="px-6 py-4 text-sm text-teal-900 font-medium max-w-[180px]"><?= e($grName) ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-teal-900/70"><?= e($formattedDate) ?></td>
                        <td class="px-6 py-4 text-sm text-teal-900/80 max-w-[220px]"><?= e($grSubject !== '' ? $grSubject : '—') ?></td>
                        <td class="px-6 py-4 whitespace-nowrap">
                          <span class="inline-flex items-center px-3 py-1 text-xs font-semibold rounded-full border <?= $statusCls ?>">
                            <?= e($grStatus) ?>
                          </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                          <div class="flex items-center justify-center gap-2">

                            <button type="button" title="View grievance"
                                    onclick='openViewGrievanceModal(<?= htmlspecialchars(json_encode([
                                        "number"           => $grNumber,
                                        "type"             => $grType,
                                        "name"             => $grName,
                                        "email"            => $grEmail,
                                        "role"             => $grRole,
                                        "subject"          => $grSubject,
                                        "description"      => $grDesc,
                                        "status"           => $grStatus,
                                        "reply"            => $grReply,
                                        "feedback"         => $grFeedback,
                                        "date"             => $formattedDate,
                                        "updated"          => $formattedUpdated,
                                        "attendee"         => $grAttendee,
                                        "attachment_url"   => $attachUrl,
                                        "attachment_ext"   => $attachExt,
                                        "attachment_is_img"=> $attachIsImg,
                                        "reply_attach_url" => $replyAttachUrl,
                                        "reply_attach_ext" => $replyAttachExt,
                                        "reply_attach_is_img" => $replyAttachIsImg,
                                    ]), ENT_QUOTES, 'UTF-8') ?>)'
                                    class="w-9 h-9 rounded-lg bg-teal-50 hover:bg-teal-600 text-teal-600 hover:text-white
                                           flex items-center justify-center transition-colors duration-200">
                              <i data-lucide="eye" class="w-4 h-4"></i>
                            </button>

                            <button type="button" title="Action summary"
                                    onclick='openActionSummary(<?= htmlspecialchars(json_encode([
                                        "number"  => $grNumber,
                                        "subject" => $grSubject,
                                        "actions" => $actionsJson,
                                    ]), ENT_QUOTES, 'UTF-8') ?>)'
                                    class="w-9 h-9 rounded-lg bg-teal-50 hover:bg-teal-600 text-teal-600 hover:text-white
                                           flex items-center justify-center transition-colors duration-200">
                              <i data-lucide="list" class="w-4 h-4"></i>
                            </button>

                          </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                          <?php
                            $needsAttention = in_array(strtolower($grStatus), ['reopened', 'pending'], true);
                          ?>
                          <?php if ($needsAttention): ?>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 text-xs font-semibold rounded-full
                                         bg-pink-100 text-pink-800 border border-pink-200">
                              <i data-lucide="alert-circle" class="w-3.5 h-3.5"></i>
                              Needs Attention
                            </span>
                          <?php else: ?>
                            <span class="text-sm text-teal-900/40">—</span>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>

                  <?php endif; ?>

                </tbody>
              </table>
            </div>

            <?php if ($totalRows > 0): ?>
              <div class="px-6 py-4 bg-teal-50/50 border-t border-teal-100 flex flex-col sm:flex-row items-center justify-between gap-4">
                <p class="text-sm text-teal-900/70" id="tableInfo">
                  Showing
                  <span class="font-semibold text-teal-900"><?= $offset + 1 ?></span>
                  to
                  <span class="font-semibold text-teal-900"><?= min($offset + count($grievances), $totalRows) ?></span>
                  of
                  <span class="font-semibold text-teal-900"><?= $totalRows ?></span>
                  entries
                </p>

                <div class="flex items-center gap-2">
                  <?php
                    $qsBase = 'grievance_details.php?entries=' . $entries;
                    if ($search !== '') $qsBase .= '&q=' . urlencode($search);
                  ?>

                  <?php if ($page > 1): ?>
                    <a href="<?= e($qsBase . '&page=' . ($page - 1)) ?>" class="px-4 py-2 rounded-lg text-sm font-medium text-teal-900 bg-white hover:bg-teal-50 border-2 border-teal-200 transition-colors">Previous</a>
                  <?php else: ?>
                    <button type="button" class="px-4 py-2 rounded-lg text-sm font-medium text-teal-900/40 bg-teal-50 border-2 border-teal-100 cursor-not-allowed" disabled>Previous</button>
                  <?php endif; ?>

                  <?php
                    $pageWindow = 2;
                    $startPage  = max(1, $page - $pageWindow);
                    $endPage    = min($totalPages, $page + $pageWindow);

                    if ($startPage > 1) {
                        echo '<a href="' . e($qsBase . '&page=1') . '" class="px-3 py-2 rounded-lg text-sm font-medium text-teal-900 bg-white hover:bg-teal-50 border-2 border-teal-200 transition-colors">1</a>';
                        if ($startPage > 2) echo '<span class="px-2 text-teal-900/40">…</span>';
                    }

                    for ($p = $startPage; $p <= $endPage; $p++) {
                        if ($p === $page) {
                            echo '<span class="inline-flex items-center justify-center min-w-[36px] h-9 px-3 rounded-lg bg-teal-600 text-white text-sm font-bold shadow-sm">' . $p . '</span>';
                        } else {
                            echo '<a href="' . e($qsBase . '&page=' . $p) . '" class="px-3 py-2 rounded-lg text-sm font-medium text-teal-900 bg-white hover:bg-teal-50 border-2 border-teal-200 transition-colors">' . $p . '</a>';
                        }
                    }

                    if ($endPage < $totalPages) {
                        if ($endPage < $totalPages - 1) echo '<span class="px-2 text-teal-900/40">…</span>';
                        echo '<a href="' . e($qsBase . '&page=' . $totalPages) . '" class="px-3 py-2 rounded-lg text-sm font-medium text-teal-900 bg-white hover:bg-teal-50 border-2 border-teal-200 transition-colors">' . $totalPages . '</a>';
                    }
                  ?>

                  <?php if ($page < $totalPages): ?>
                    <a href="<?= e($qsBase . '&page=' . ($page + 1)) ?>" class="px-4 py-2 rounded-lg text-sm font-medium text-teal-900 bg-white hover:bg-teal-50 border-2 border-teal-200 transition-colors">Next</a>
                  <?php else: ?>
                    <button type="button" class="px-4 py-2 rounded-lg text-sm font-medium text-teal-900/40 bg-teal-50 border-2 border-teal-100 cursor-not-allowed" disabled>Next</button>
                  <?php endif; ?>
                </div>
              </div>
            <?php endif; ?>

          </div>
        </div>

      </main>

      <footer class="bg-teal-900 text-white mt-auto">
        <div class="roofline"></div>
        <div class="px-4 sm:px-6 py-6">
          <div class="max-w-7xl mx-auto text-center">
            <p class="text-xs text-teal-200/70">
              Copyright &copy; <?= date('Y') ?>
              <span class="font-bold text-white">Rajagiri College of Social Sciences</span>. All rights reserved.
            </p>
            <p class="text-xs text-teal-200/70 mt-1">
              Powered by <span class="font-bold text-white">RLabZ</span>
            </p>
          </div>
        </div>
      </footer>

    </div>
  </div>

  <!-- VIEW GRIEVANCE MODAL -->
  <div id="viewGrievanceModal" class="hidden fixed inset-0 z-[65] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeViewGrievanceModal()"></div>

    <div class="relative w-full max-w-3xl bg-white rounded-2xl shadow-2xl animate-modal-in
                overflow-hidden max-h-[92vh] flex flex-col">

      <div class="h-1.5 w-full bg-teal-600"></div>

      <div class="flex items-center justify-between px-6 py-4 border-b border-teal-100">
        <h3 class="text-lg md:text-xl font-bold text-teal-900">Grievance Details</h3>
        <button type="button" onclick="closeViewGrievanceModal()"
                class="w-8 h-8 rounded-lg hover:bg-teal-50 flex items-center justify-center text-teal-900/60 transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <div class="p-6 overflow-y-auto flex-1 space-y-5">

        <div class="flex items-start gap-4 pb-4 border-b border-teal-100">
          <div class="w-14 h-14 rounded-xl bg-teal-600 flex items-center justify-center text-white shadow-sm flex-shrink-0">
            <i data-lucide="file-text" class="w-7 h-7"></i>
          </div>
          <div class="min-w-0 flex-1">
            <p id="vgNumber" class="text-sm font-bold text-teal-700 break-words">—</p>
            <p id="vgSubject" class="text-base font-bold text-teal-900 break-words mt-0.5">—</p>
            <div class="mt-2 flex flex-wrap items-center gap-2">
              <span id="vgStatus"></span>
              <span class="text-xs text-teal-900/50">·</span>
              <span class="text-xs text-teal-900/60" id="vgDate">—</span>
            </div>
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div class="bg-teal-50/60 rounded-xl p-3 border border-teal-100">
            <p class="text-[10px] font-bold uppercase tracking-wider text-teal-900/50 mb-1">Grievance Type</p>
            <p id="vgType" class="text-sm font-semibold text-teal-900 break-words">—</p>
          </div>
          <div class="bg-teal-50/60 rounded-xl p-3 border border-teal-100">
            <p class="text-[10px] font-bold uppercase tracking-wider text-teal-900/50 mb-1">Complainant</p>
            <p id="vgComplainant" class="text-sm font-semibold text-teal-900 break-words">—</p>
            <p id="vgComplainantMeta" class="text-xs text-teal-900/60 break-all mt-0.5">—</p>
          </div>
          <div class="bg-teal-50/60 rounded-xl p-3 border border-teal-100">
            <p class="text-[10px] font-bold uppercase tracking-wider text-teal-900/50 mb-1">Submitted On</p>
            <p id="vgSubmitted" class="text-sm font-semibold text-teal-900">—</p>
          </div>
          <div class="bg-teal-50/60 rounded-xl p-3 border border-teal-100">
            <p class="text-[10px] font-bold uppercase tracking-wider text-teal-900/50 mb-1">Last Updated</p>
            <p id="vgUpdated" class="text-sm font-semibold text-teal-900">—</p>
          </div>
          <div class="bg-teal-50/60 rounded-xl p-3 border border-teal-100 sm:col-span-2">
            <p class="text-[10px] font-bold uppercase tracking-wider text-teal-900/50 mb-1">Attendee</p>
            <p id="vgAttendee" class="text-sm font-semibold text-teal-900 break-words">No Data</p>
          </div>
        </div>

        <div>
          <p class="text-[10px] font-bold uppercase tracking-wider text-teal-900/50 mb-1.5">Description</p>
          <div class="bg-teal-50/60 rounded-xl p-4 border border-teal-100">
            <p id="vgDescription" class="text-sm text-teal-900/80 leading-relaxed whitespace-pre-line break-words">—</p>
          </div>
        </div>

        <div id="vgAttachmentWrap" class="hidden">
          <p class="text-[10px] font-bold uppercase tracking-wider text-teal-900/50 mb-1.5">Original Attachment</p>
          <div class="bg-teal-50/60 rounded-xl p-4 border border-teal-100 flex items-center justify-between gap-3 flex-wrap">
            <div class="flex items-center min-w-0">
              <div class="w-9 h-9 rounded-lg bg-white flex items-center justify-center flex-shrink-0 border border-teal-100">
                <i data-lucide="paperclip" class="w-4 h-4 text-teal-600"></i>
              </div>
              <div class="min-w-0 ml-3">
                <p class="text-sm font-semibold text-teal-900 truncate" id="vgAttachmentName">attachment</p>
                <p class="text-[11px] text-teal-900/60" id="vgAttachmentMeta">—</p>
              </div>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
              <button type="button" id="vgAttachmentViewBtn"
                      class="inline-flex items-center px-3 py-2 rounded-lg
                             bg-teal-50 hover:bg-teal-600 text-teal-600 hover:text-white
                             text-xs font-bold transition-colors duration-200">
                <i data-lucide="eye" class="w-3.5 h-3.5 mr-1.5"></i> View
              </button>
              <a id="vgAttachmentDownloadBtn" href="#" download
                 class="inline-flex items-center px-3 py-2 rounded-lg
                        bg-emerald-50 hover:bg-emerald-600 text-emerald-700 hover:text-white
                        text-xs font-bold transition-colors duration-200">
                <i data-lucide="download" class="w-3.5 h-3.5 mr-1.5"></i> Download
              </a>
            </div>
          </div>
        </div>

        <div>
          <p class="text-[10px] font-bold uppercase tracking-wider text-teal-900/50 mb-1.5">Reply / Response</p>
          <div class="bg-emerald-50 rounded-xl p-4 border border-emerald-100">
            <p id="vgReply" class="text-sm text-emerald-800 leading-relaxed whitespace-pre-line break-words">—</p>
          </div>
        </div>

        <div id="vgReplyAttachmentWrap" class="hidden">
          <p class="text-[10px] font-bold uppercase tracking-wider text-teal-900/50 mb-1.5">Reply Attachment</p>
          <div class="bg-emerald-50/60 rounded-xl p-4 border border-emerald-200 flex items-center justify-between gap-3 flex-wrap">
            <div class="flex items-center min-w-0">
              <div class="w-9 h-9 rounded-lg bg-white flex items-center justify-center flex-shrink-0 border border-emerald-200">
                <i data-lucide="paperclip" class="w-4 h-4 text-emerald-700"></i>
              </div>
              <div class="min-w-0 ml-3">
                <p class="text-sm font-semibold text-teal-900 truncate" id="vgReplyAttachmentName">attachment</p>
                <p class="text-[11px] text-teal-900/60" id="vgReplyAttachmentMeta">—</p>
              </div>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
              <button type="button" id="vgReplyAttachmentViewBtn"
                      class="inline-flex items-center px-3 py-2 rounded-lg
                             bg-white hover:bg-emerald-600 text-emerald-700 hover:text-white
                             text-xs font-bold transition-colors duration-200">
                <i data-lucide="eye" class="w-3.5 h-3.5 mr-1.5"></i> View
              </button>
              <a id="vgReplyAttachmentDownloadBtn" href="#" download
                 class="inline-flex items-center px-3 py-2 rounded-lg
                        bg-emerald-50 hover:bg-emerald-600 text-emerald-700 hover:text-white
                        text-xs font-bold transition-colors duration-200">
                <i data-lucide="download" class="w-3.5 h-3.5 mr-1.5"></i> Download
              </a>
            </div>
          </div>
        </div>

        <div>
          <p class="text-[10px] font-bold uppercase tracking-wider text-teal-900/50 mb-1.5">Feedback</p>
          <div class="bg-amber-50 rounded-xl p-4 border border-amber-100">
            <p id="vgFeedback" class="text-sm text-amber-800 leading-relaxed whitespace-pre-line break-words">—</p>
          </div>
        </div>

      </div>

      <div class="px-6 py-4 bg-teal-50/60 border-t border-teal-100 flex justify-end">
        <button type="button" onclick="closeViewGrievanceModal()"
                class="px-5 py-2.5 rounded-lg font-semibold text-teal-900
                       bg-white hover:bg-teal-50 border-2 border-teal-200
                       transition-all duration-200">
          Close
        </button>
      </div>
    </div>
  </div>

  <!-- ACTION SUMMARY MODAL -->
  <div id="actionSummaryModal" class="hidden fixed inset-0 z-[66] flex items-start justify-center p-4 pt-16">
    <div class="absolute inset-0 bg-black/30 backdrop-blur-sm" onclick="closeActionSummary()"></div>

    <div class="relative w-full max-w-2xl max-h-[88vh] bg-white rounded-xl shadow-2xl animate-modal-in
                overflow-hidden flex flex-col">

      <div class="bg-teal-50 text-teal-900 px-5 py-2.5 border-b border-teal-100 flex-shrink-0">
        <p id="asTitle" class="text-sm font-semibold truncate">—</p>
      </div>

      <div class="flex items-center justify-between px-6 pt-5 pb-3 flex-shrink-0">
        <h3 class="text-xl font-bold text-teal-900">Action Summary</h3>
        <button type="button" onclick="closeActionSummary()"
                class="w-8 h-8 rounded-lg hover:bg-teal-50 flex items-center justify-center text-teal-900/60 transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <div class="px-6 pb-6 overflow-y-auto flex-1">
        <div id="asList" class="space-y-3"></div>

        <div id="asEmpty" class="hidden border-2 border-teal-100 rounded-lg p-8 text-center">
          <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-teal-50 mb-3">
            <i data-lucide="list-x" class="w-6 h-6 text-teal-600"></i>
          </div>
          <p class="text-sm font-semibold text-teal-900">No action summaries recorded</p>
          <p class="text-xs text-teal-900/60 mt-1">Nothing has been logged for this grievance yet.</p>
        </div>
      </div>

      <div class="px-6 py-4 bg-teal-50/60 border-t border-teal-100 flex justify-end flex-shrink-0">
        <button type="button" onclick="closeActionSummary()"
                class="px-5 py-2.5 rounded-lg font-semibold text-teal-900
                       bg-white hover:bg-teal-50 border-2 border-teal-200
                       transition-all duration-200">
          Close
        </button>
      </div>
    </div>
  </div>

  <!-- LIGHTBOX -->
  <div id="lightbox" class="hidden fixed inset-0 z-[100] bg-black/90 backdrop-blur-md animate-lightbox-in">
    <div id="lightboxToolbar"
         class="absolute top-0 left-0 right-0 px-4 py-3 flex items-center justify-between gap-3
                bg-gradient-to-b from-black/70 to-transparent z-10">
      <div class="flex items-center min-w-0 text-white">
        <i data-lucide="image" class="w-5 h-5 mr-2 flex-shrink-0"></i>
        <span class="text-sm font-semibold truncate" id="lightboxFilename">attachment</span>
      </div>
      <div class="flex items-center gap-2 flex-shrink-0">
        <a id="lightboxDownloadBtn" href="#" download
           class="inline-flex items-center px-4 py-2 rounded-lg
                  bg-white/10 hover:bg-white/20 text-white text-sm font-semibold
                  border border-white/20 transition-all duration-200">
          <i data-lucide="download" class="w-4 h-4 mr-2"></i> Download
        </a>
        <button type="button" data-lightbox-close="1"
                class="inline-flex items-center justify-center w-10 h-10 rounded-lg
                       bg-white/10 hover:bg-red-500/80 text-white
                       border border-white/20 transition-all duration-200
                       cursor-pointer" title="Close (Esc)" aria-label="Close">
          <i data-lucide="x" class="w-5 h-5 pointer-events-none"></i>
        </button>
      </div>
    </div>

    <div id="lightboxStage" class="absolute inset-0 pt-16 pb-4 px-4 flex items-center justify-center">
      <img id="lightboxImage" src="" alt="Attachment preview"
           class="max-w-full max-h-full object-contain rounded-lg shadow-2xl bg-white select-none" />
    </div>
  </div>

  <!-- LOGOUT MODAL -->
  <div id="logoutConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeLogoutModal()"></div>

    <div id="logoutConfirmPanel"
         class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-teal-600"></div>

      <div class="px-6 pt-6 pb-2 flex flex-col items-center text-center">
        <div class="w-16 h-16 rounded-full flex items-center justify-center mb-4
                    bg-red-50 ring-4 ring-red-100/60">
          <i data-lucide="log-out" class="w-8 h-8 text-red-500"></i>
        </div>
        <h3 class="text-xl font-bold text-teal-900 mb-2">Log Out?</h3>
        <p class="text-sm text-teal-900/70 leading-relaxed">
          You are about to log out of
          <span class="font-bold text-teal-700 break-words"><?= e($displayName) ?></span>.
          Any unsaved changes will be lost.
        </p>
        <p class="text-xs text-teal-900/50 font-medium mt-3 flex items-center gap-1.5">
          <i data-lucide="info" class="w-3.5 h-3.5"></i> You can log back in anytime.
        </p>
      </div>

      <div class="px-6 py-5 mt-2 flex flex-col-reverse sm:flex-row gap-3">
        <button type="button" onclick="closeLogoutModal()"
                class="flex-1 px-5 py-3 rounded-lg font-semibold text-teal-900
                       bg-white border-2 border-teal-200 hover:border-teal-600 hover:bg-teal-50
                       transition-all duration-200">
          Cancel
        </button>
        <button type="button" id="confirmLogoutBtn"
                class="flex-1 px-5 py-3 rounded-lg font-bold text-white
                       bg-teal-600 hover:bg-teal-700
                       shadow-sm hover:shadow-md
                       transition-all duration-200
                       flex items-center justify-center gap-2">
          <i data-lucide="log-out" class="w-4 h-4"></i>
          <span>Log Out</span>
        </button>
      </div>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function () {

      if (typeof lucide !== 'undefined') lucide.createIcons();

      const sidebar = document.getElementById('adminSidebar');
      const main    = document.getElementById('adminMain');

      // Sidebar expand/collapse
      (function () {
        const toggleBtn = document.getElementById('sidebarToggle');
        if (!toggleBtn || !sidebar || !main) return;

        const labels   = sidebar.querySelectorAll('.sidebar-label');
        const tooltips = sidebar.querySelectorAll('.sidebar-tooltip');
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
          }
          setTimeout(function () { if (typeof lucide !== 'undefined') lucide.createIcons(); }, 250);
        });
      })();

      /* Flash auto-dismiss */
      (function () {
        ['flashSuccessBox', 'flashErrorBox'].forEach(function (id) {
          const box = document.getElementById(id);
          if (!box) return;
          setTimeout(function () {
            box.classList.remove('animate-flash-in');
            box.classList.add('animate-flash-out');
            setTimeout(function () { if (box.parentNode) box.parentNode.removeChild(box); }, 500);
          }, 3000);
        });
      })();

      /* Admin dropdown */
      (function () {
        const btn = document.getElementById('admin-dropdown-btn');
        const menu = document.getElementById('admin-dropdown-menu');
        const chevron = document.getElementById('admin-chevron');
        const container = document.getElementById('admin-dropdown-container');
        if (!btn || !menu || !container) return;

        btn.addEventListener('click', function (e) {
          e.stopPropagation();
          const isOpen = !menu.classList.contains('hidden');
          if (isOpen) {
            menu.classList.add('hidden'); menu.classList.remove('animate-dropdown');
            if (chevron) chevron.classList.remove('rotate-180');
            btn.setAttribute('aria-expanded', 'false');
          } else {
            menu.classList.remove('hidden'); menu.classList.add('animate-dropdown');
            if (chevron) chevron.classList.add('rotate-180');
            btn.setAttribute('aria-expanded', 'true');
          }
        });

        document.addEventListener('click', function (e) {
          if (!container.contains(e.target)) {
            menu.classList.add('hidden'); menu.classList.remove('animate-dropdown');
            if (chevron) chevron.classList.remove('rotate-180');
            btn.setAttribute('aria-expanded', 'false');
          }
        });

        document.addEventListener('keydown', function (e) {
          if (e.key === 'Escape') {
            menu.classList.add('hidden'); menu.classList.remove('animate-dropdown');
            if (chevron) chevron.classList.remove('rotate-180');
            btn.setAttribute('aria-expanded', 'false');
          }
        });
      })();

      /* Helpers */
      function escHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
          return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
      }
      function filenameFromUrl(url) {
        if (!url) return 'attachment';
        try {
          const clean = url.split('?')[0].split('#')[0];
          const parts = clean.split('/');
          let name = parts[parts.length - 1] || 'attachment';
          name = name.replace(/^(reply|grv)_\d+_\d+_[a-f0-9]+_/i, '');
          return decodeURIComponent(name);
        } catch (e) { return 'attachment'; }
      }

      /* Lightbox */
      const lightbox = document.getElementById('lightbox');
      const lightboxImage = document.getElementById('lightboxImage');
      const lightboxFilename = document.getElementById('lightboxFilename');
      const lightboxDownloadBtn = document.getElementById('lightboxDownloadBtn');
      const lightboxStage = document.getElementById('lightboxStage');

      function openLightbox(url, filename) {
        if (!lightbox || !lightboxImage) return;
        lightboxImage.src = url || '';
        if (lightboxFilename) lightboxFilename.textContent = filename || 'attachment';
        if (lightboxDownloadBtn) {
          lightboxDownloadBtn.setAttribute('href', url || '#');
          lightboxDownloadBtn.setAttribute('download', filename || '');
        }
        lightbox.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        if (typeof lucide !== 'undefined') lucide.createIcons();
      }
      function closeLightbox() {
        if (!lightbox) return;
        lightbox.classList.add('hidden');
        if (lightboxImage) lightboxImage.src = '';
        const anyOpen = (viewGrievanceModal && !viewGrievanceModal.classList.contains('hidden'))
                     || (actionSummaryModal && !actionSummaryModal.classList.contains('hidden'))
                     || (logoutConfirmModal && !logoutConfirmModal.classList.contains('hidden'));
        if (!anyOpen) document.body.classList.remove('overflow-hidden');
      }

      document.addEventListener('click', function (e) {
        const closeEl = e.target.closest('[data-lightbox-close="1"]');
        if (closeEl && lightbox && !lightbox.classList.contains('hidden')) {
          e.preventDefault(); e.stopPropagation();
          closeLightbox();
        }
      });
      if (lightboxStage) lightboxStage.addEventListener('click', function (e) { if (e.target === lightboxStage) closeLightbox(); });
      if (lightbox) lightbox.addEventListener('click', function (e) { if (e.target === lightbox) closeLightbox(); });
      const lightboxToolbar = document.getElementById('lightboxToolbar');
      if (lightboxToolbar) {
        lightboxToolbar.addEventListener('click', function (e) {
          if (!e.target.closest('[data-lightbox-close="1"]') && !e.target.closest('#lightboxDownloadBtn')) e.stopPropagation();
        });
      }

      /* VIEW GRIEVANCE MODAL */
      const viewGrievanceModal = document.getElementById('viewGrievanceModal');

      function statusBadgeHtml(status) {
        const s = (status || '').trim();
        const map = {
          'Pending':     'bg-amber-100 text-amber-800 border-amber-200',
          'In Progress': 'bg-sky-100 text-sky-800 border-sky-200',
          'Disposed':    'bg-emerald-100 text-emerald-800 border-emerald-200',
          'Closed':      'bg-slate-100 text-slate-700 border-slate-200',
          'Reopened':    'bg-pink-100 text-pink-800 border-pink-200'
        };
        const cls = map[s] || 'bg-slate-100 text-slate-700 border-slate-200';
        return '<span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold uppercase tracking-wider border ' + cls + '">' + escHtml(s) + '</span>';
      }

      window.openViewGrievanceModal = function (data) {
        if (!viewGrievanceModal || !data) return;

        document.getElementById('vgNumber').textContent    = data.number || '—';
        document.getElementById('vgSubject').textContent   = data.subject || '—';
        document.getElementById('vgStatus').innerHTML      = statusBadgeHtml(data.status || 'Pending');
        document.getElementById('vgDate').textContent      = data.date || '—';
        document.getElementById('vgType').textContent      = data.type || '—';

        document.getElementById('vgComplainant').textContent = data.name || '—';
        const metaParts = [];
        if (data.role)  metaParts.push(data.role);
        if (data.email) metaParts.push(data.email);
        document.getElementById('vgComplainantMeta').textContent = metaParts.length ? metaParts.join(' · ') : '—';

        document.getElementById('vgSubmitted').textContent = data.date || '—';
        document.getElementById('vgUpdated').textContent   = data.updated || '—';
        document.getElementById('vgAttendee').textContent  = (data.attendee && data.attendee.trim() !== '') ? data.attendee : 'No Data';

        document.getElementById('vgDescription').textContent =
          (data.description && data.description.trim() !== '') ? data.description : 'No description provided.';
        document.getElementById('vgReply').textContent =
          (data.reply && data.reply.trim() !== '') ? data.reply : 'No reply yet from the committee.';
        document.getElementById('vgFeedback').textContent =
          (data.feedback && data.feedback.trim() !== '') ? data.feedback : 'No feedback recorded.';

        const attWrap = document.getElementById('vgAttachmentWrap');
        const attView = document.getElementById('vgAttachmentViewBtn');
        const attDl   = document.getElementById('vgAttachmentDownloadBtn');
        const attName = document.getElementById('vgAttachmentName');
        const attMeta = document.getElementById('vgAttachmentMeta');

        if (data.attachment_url && data.attachment_url.trim() !== '') {
          attWrap.classList.remove('hidden');
          const nm = filenameFromUrl(data.attachment_url);
          const ex = (data.attachment_ext || '').toUpperCase();
          attName.textContent = nm;
          attMeta.textContent = (ex ? ex + ' • ' : '') + (data.attachment_is_img ? 'Image' : 'Document');
          attDl.setAttribute('href', data.attachment_url);
          attDl.setAttribute('download', nm);

          const newBtn = attView.cloneNode(true);
          attView.parentNode.replaceChild(newBtn, attView);
          newBtn.addEventListener('click', function (e) {
            e.preventDefault(); e.stopPropagation();
            if (data.attachment_is_img) openLightbox(data.attachment_url, nm);
            else window.open(data.attachment_url, '_blank', 'noopener');
          });
        } else {
          attWrap.classList.add('hidden');
        }

        const repWrap = document.getElementById('vgReplyAttachmentWrap');
        const repView = document.getElementById('vgReplyAttachmentViewBtn');
        const repDl   = document.getElementById('vgReplyAttachmentDownloadBtn');
        const repName = document.getElementById('vgReplyAttachmentName');
        const repMeta = document.getElementById('vgReplyAttachmentMeta');

        if (data.reply_attach_url && data.reply_attach_url.trim() !== '') {
          repWrap.classList.remove('hidden');
          const nm = filenameFromUrl(data.reply_attach_url);
          const ex = (data.reply_attach_ext || '').toUpperCase();
          repName.textContent = nm;
          repMeta.textContent = (ex ? ex + ' • ' : '') + (data.reply_attach_is_img ? 'Image' : 'Document');
          repDl.setAttribute('href', data.reply_attach_url);
          repDl.setAttribute('download', nm);

          const newBtn = repView.cloneNode(true);
          repView.parentNode.replaceChild(newBtn, repView);
          newBtn.addEventListener('click', function (e) {
            e.preventDefault(); e.stopPropagation();
            if (data.reply_attach_is_img) openLightbox(data.reply_attach_url, nm);
            else window.open(data.reply_attach_url, '_blank', 'noopener');
          });
        } else {
          repWrap.classList.add('hidden');
        }

        viewGrievanceModal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        if (typeof lucide !== 'undefined') lucide.createIcons();
      }

      window.closeViewGrievanceModal = function () {
        if (!viewGrievanceModal) return;
        viewGrievanceModal.classList.add('hidden');
        const anyOpen = (actionSummaryModal && !actionSummaryModal.classList.contains('hidden'))
                     || (logoutConfirmModal && !logoutConfirmModal.classList.contains('hidden'))
                     || (lightbox && !lightbox.classList.contains('hidden'));
        if (!anyOpen) document.body.classList.remove('overflow-hidden');
      }

      /* ACTION SUMMARY MODAL */
      const actionSummaryModal = document.getElementById('actionSummaryModal');

      window.openActionSummary = function (data) {
        if (!actionSummaryModal || !data) return;

        const titleEl  = document.getElementById('asTitle');
        const listEl   = document.getElementById('asList');
        const emptyEl  = document.getElementById('asEmpty');

        const bannerText = (data.subject && data.subject.trim() !== '')
          ? data.number + ' | ' + data.subject
          : data.number;
        titleEl.textContent = bannerText || '—';

        const actions = Array.isArray(data.actions) ? data.actions : [];

        if (actions.length === 0) {
          listEl.innerHTML = '';
          emptyEl.classList.remove('hidden');
        } else {
          emptyEl.classList.add('hidden');

          let html = '';
          actions.forEach(function (act, idx) {
            const dt     = escHtml(act.date || '—');
            const aname  = escHtml(act.attendee_name || '—');
            const desig  = escHtml(act.attendee_desig || '');
            const summ   = escHtml(act.summary || '—');
            const taken  = escHtml(act.action_taken || '—');
            const by     = escHtml(act.created_by || '');

            html +=
              '<div class="rounded-xl border border-teal-200 bg-teal-50/40 p-4">' +
                '<div class="flex items-center justify-between gap-2 mb-3 flex-wrap">' +
                  '<div class="flex items-center gap-2">' +
                    '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider border border-teal-300 bg-white text-teal-700">Entry ' + (idx + 1) + '</span>' +
                    '<span class="text-[11px] text-teal-900/70 flex items-center">' +
                      '<i data-lucide="calendar" class="w-3.5 h-3.5 mr-1 text-teal-600"></i>' + dt +
                    '</span>' +
                  '</div>' +
                  '<span class="text-[11px] text-teal-900/70 flex items-center">' +
                    '<i data-lucide="user-check" class="w-3.5 h-3.5 mr-1 text-teal-600"></i>' +
                    aname + (desig ? ' — ' + desig : '') +
                  '</span>' +
                '</div>' +
                '<div class="mb-2">' +
                  '<p class="text-[10px] uppercase tracking-wider font-bold text-teal-900/60 mb-0.5">Grievance Summary</p>' +
                  '<p class="text-sm text-teal-900 leading-relaxed whitespace-pre-wrap break-words">' + summ + '</p>' +
                '</div>' +
                '<div class="pt-2 border-t border-teal-200/60">' +
                  '<p class="text-[10px] uppercase tracking-wider font-bold text-teal-900/60 mb-0.5">Action Taken</p>' +
                  '<p class="text-sm text-teal-900 leading-relaxed whitespace-pre-wrap break-words">' + taken + '</p>' +
                '</div>' +
                (by ? '<p class="text-[10px] text-teal-900/50 mt-2">Submitted by: ' + by + '</p>' : '') +
              '</div>';
          });
          listEl.innerHTML = html;
        }

        actionSummaryModal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        if (typeof lucide !== 'undefined') lucide.createIcons();
      }

      window.closeActionSummary = function () {
        if (!actionSummaryModal) return;
        actionSummaryModal.classList.add('hidden');
        const anyOpen = (viewGrievanceModal && !viewGrievanceModal.classList.contains('hidden'))
                     || (logoutConfirmModal && !logoutConfirmModal.classList.contains('hidden'))
                     || (lightbox && !lightbox.classList.contains('hidden'));
        if (!anyOpen) document.body.classList.remove('overflow-hidden');
      }

      /* LOGOUT MODAL */
      const logoutConfirmModal = document.getElementById('logoutConfirmModal');
      const logoutConfirmPanel = document.getElementById('logoutConfirmPanel');
      const confirmLogoutBtn   = document.getElementById('confirmLogoutBtn');
      const LOGOUT_URL = '../logout.php?role=admin';

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
      }
      window.closeLogoutModal = function () {
        logoutConfirmModal.classList.add('hidden');
        const anyOpen = (viewGrievanceModal && !viewGrievanceModal.classList.contains('hidden'))
                     || (actionSummaryModal && !actionSummaryModal.classList.contains('hidden'))
                     || (lightbox && !lightbox.classList.contains('hidden'));
        if (!anyOpen) document.body.classList.remove('overflow-hidden');
      }

      [document.getElementById('sidebarLogoutBtn'), document.getElementById('dropdownLogoutBtn')].forEach(function (btn) {
        if (!btn) return;
        btn.addEventListener('click', function (e) {
          e.preventDefault(); e.stopPropagation();
          window.openLogoutModal();
        });
      });

      if (confirmLogoutBtn) {
        confirmLogoutBtn.addEventListener('click', function () {
          confirmLogoutBtn.classList.add('opacity-50', 'pointer-events-none');
          window.location.href = LOGOUT_URL;
        });
      }

      /* Escape key */
      document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (lightbox && !lightbox.classList.contains('hidden')) { closeLightbox(); return; }
        if (viewGrievanceModal && !viewGrievanceModal.classList.contains('hidden')) window.closeViewGrievanceModal();
        if (actionSummaryModal && !actionSummaryModal.classList.contains('hidden')) window.closeActionSummary();
        if (logoutConfirmModal && !logoutConfirmModal.classList.contains('hidden')) window.closeLogoutModal();
      });

      /* Entries dropdown — auto-submit */
      (function () {
        const entriesSelect = document.getElementById('entriesPerPage');
        const filterForm    = document.getElementById('filterForm');
        if (!entriesSelect || !filterForm) return;
        entriesSelect.addEventListener('change', function () { filterForm.submit(); });
      })();

      /* Live client-side search + debounced server-side search */
      (function () {
        const searchInput = document.getElementById('searchInput');
        const tableBody   = document.getElementById('grievancesTableBody');
        if (!searchInput || !tableBody) return;

        let debounceTimer = null;
        searchInput.addEventListener('input', function () {
          const term = this.value.toLowerCase().trim();
          tableBody.querySelectorAll('tr').forEach(function (row) {
            row.style.display = (term === '' || row.textContent.toLowerCase().indexOf(term) !== -1) ? '' : 'none';
          });

          clearTimeout(debounceTimer);
          debounceTimer = setTimeout(function () {
            const form = document.getElementById('filterForm');
            if (form) form.submit();
          }, 600);
        });
      })();

    });
  </script>

  <script src="../assets/js/index.js"></script>
</body>
</html>
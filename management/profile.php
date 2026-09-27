<?php
/**
 * management/profile.php
 * ---------------------------------------------------------------------------
 * Management Member / Grievance Member Profile (View Only)
 * Rajagiri College Grievance Redressal Portal
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
$allowedRoles = ['MANAGEMENT', 'GRIEVANCE_MEMBER'];

if (empty($_SESSION['user_id']) || !in_array($sessionRole, $allowedRoles, true)) {
    header('Location: ../login.php?role=management');
    exit;
}

$userId = (int) $_SESSION['user_id'];

// DATABASE
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

// HELPER
function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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

// FETCH MEMBER PROFILE
$profile = [
    'username'         => $_SESSION['username'] ?? 'Member',
    'name'             => '',
    'address'          => '',
    'email'            => '',
    'contact_number'   => '',
    'profile_image'    => '',
    'member_type'      => '',
    'user_role'        => '',
    'designation_name' => '',
];

if ($conn instanceof mysqli) {
    try {
        $sql = "SELECT  u.username,
                        u.role              AS user_role,
                        cm.name             AS name,
                        cm.address          AS address,
                        cm.email            AS email,
                        cm.mobile_number    AS mobile_number,
                        cm.member_type      AS member_type,
                        cm.profile_image    AS profile_image,
                        d.designation_name  AS designation_name
                FROM users u
                LEFT JOIN cell_members cm  ON cm.user_id = u.id
                LEFT JOIN designations d   ON d.id = cm.designation_id
                WHERE u.id = ?
                LIMIT 1";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();

            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();

                $profile['username']         = $row['username']         ?? $profile['username'];
                $profile['name']             = $row['name']             ?? '';
                $profile['address']          = $row['address']          ?? '';
                $profile['email']            = $row['email']            ?? '';
                $profile['contact_number']   = $row['mobile_number']    ?? '';
                $profile['member_type']      = $row['member_type']      ?? '';
                $profile['user_role']        = $row['user_role']        ?? '';
                $profile['profile_image']    = $row['profile_image']    ?? '';
                $profile['designation_name'] = $row['designation_name'] ?? '';
            }
            $stmt->close();
        }
    } catch (Throwable $ex) {
        error_log('[Management Profile Fetch] ' . $ex->getMessage());
        $dbError = 'Unable to load profile data at this time.';
    }
}

// DISPLAY FALLBACKS
$displayName    = !empty($profile['name'])           ? $profile['name']           : $profile['username'];
$displayAddress = !empty($profile['address'])        ? $profile['address']        : 'Not provided';
$displayEmail   = !empty($profile['email'])          ? $profile['email']          : 'Not provided';
$displayMobile  = !empty($profile['contact_number']) ? $profile['contact_number'] : 'Not provided';

// ROLE CHIP
$memberType = strtoupper((string) $profile['member_type']);
$userRole   = strtoupper((string) $profile['user_role']);

if ($memberType === 'MANAGEMENT') {
    $roleLabel = 'Management Member';
    $roleIcon  = 'shield-check';
} elseif ($memberType === 'GRIEVANCE_MEMBER') {
    $roleLabel = 'Grievance Member';
    $roleIcon  = 'user-check';
} elseif ($memberType === 'TEACHING') {
    $roleLabel = 'Teaching Member';
    $roleIcon  = 'graduation-cap';
} elseif ($memberType === 'NON_TEACHING') {
    $roleLabel = 'Non-Teaching Member';
    $roleIcon  = 'briefcase';
} elseif ($memberType === 'PARENT') {
    $roleLabel = 'Parent Member';
    $roleIcon  = 'users';
} elseif ($memberType === 'STUDENT') {
    $roleLabel = 'Student Member';
    $roleIcon  = 'graduation-cap';
} elseif ($userRole === 'MANAGEMENT') {
    $roleLabel = 'Management Member';
    $roleIcon  = 'shield-check';
} elseif ($userRole === 'GRIEVANCE_MEMBER') {
    $roleLabel = 'Grievance Member';
    $roleIcon  = 'user-check';
} else {
    $roleLabel = 'Management Member';
    $roleIcon  = 'shield-check';
}

$designationLine = !empty($profile['designation_name']) ? $profile['designation_name'] : '';

// PROFILE PICTURE
$hasProfilePicture = false;
$profilePictureUrl = '';

if (!empty($profile['profile_image'])) {
    $relative     = ltrim((string) $profile['profile_image'], '/');
    $absolutePath = __DIR__ . '/../' . $relative;
    $browserPath  = '../' . $relative;

    if (file_exists($absolutePath) && is_file($absolutePath)) {
        $hasProfilePicture = true;
        $profilePictureUrl = $browserPath;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>User Details — <?= e($roleLabel) ?> | Rajagiri College Grievance Portal</title>
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
            fadeInUp:     { '0%':{opacity:'0',transform:'translateY(12px)'}, '100%':{opacity:'1',transform:'translateY(0)'} },
            dropdownFade: { '0%':{opacity:'0',transform:'translateY(-8px) scale(0.98)'}, '100%':{opacity:'1',transform:'translateY(0) scale(1)'} },
            modalFadeIn:  { '0%':{opacity:'0',transform:'scale(0.96)'}, '100%':{opacity:'1',transform:'scale(1)'} },
            confirmShake: { '0%, 100%':{transform:'translateX(0)'},'20%':{transform:'translateX(-6px)'},'40%':{transform:'translateX(6px)'},'60%':{transform:'translateX(-4px)'},'80%':{transform:'translateX(4px)'} },
            flashIn:      { '0%':{opacity:'0',transform:'translateY(-10px)'}, '100%':{opacity:'1',transform:'translateY(0)'} },
            flashOut:     { '0%':{opacity:'1',transform:'translateY(0)',maxHeight:'200px'}, '100%':{opacity:'0',transform:'translateY(-10px)',maxHeight:'0px'} },
            softFloat:    { '0%, 100%':{transform:'translateY(0px)'}, '50%':{transform:'translateY(-4px)'} }
          },
          animation: {
            'fade-in-up':    'fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'dropdown':      'dropdownFade 0.2s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'modal-in':      'modalFadeIn 0.25s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'confirm-shake': 'confirmShake 0.5s cubic-bezier(0.16, 1, 0.3, 1)',
            'flash-in':      'flashIn 0.35s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'flash-out':     'flashOut 0.45s cubic-bezier(0.4, 0, 1, 1) forwards',
            'soft-float':    'softFloat 4s ease-in-out infinite'
          }
        }
      }
    };
  </script>

  <style>
    @font-face { font-family:'Coolvetica'; src:url('../assets/fonts/coolvetica-rg.woff2') format('woff2'), url('../assets/fonts/coolvetica-rg.woff') format('woff'); font-weight:400; font-display:swap; }
    @font-face { font-family:'Coolvetica'; src:url('../assets/fonts/coolvetica-bold.woff2') format('woff2'), url('../assets/fonts/coolvetica-bold.woff') format('woff'); font-weight:700; font-display:swap; }
    html { scroll-behavior:smooth; }
    body { font-family:'Coolvetica','Poppins',sans-serif; }
    .roofline { height:14px;
      background-image: linear-gradient(45deg, transparent 33.33%, #006E74 33.33%, #006E74 66.66%, transparent 66.66%),
                        linear-gradient(-45deg, transparent 33.33%, #006E74 33.33%, #006E74 66.66%, transparent 66.66%);
      background-size:20px 14px; background-repeat:repeat-x; }
    .logo-divider { width:1px; background-color:#CFE6E7; }
    #managementNav::-webkit-scrollbar { width:6px; }
    #managementNav::-webkit-scrollbar-track { background:transparent; }
    #managementNav::-webkit-scrollbar-thumb { background:rgba(255,255,255,0.2); border-radius:3px; }
    #managementNav::-webkit-scrollbar-thumb:hover { background:rgba(255,255,255,0.35); }
  </style>
</head>

<body class="min-h-screen bg-teal-50/40 text-teal-900 antialiased selection:bg-teal-100 selection:text-teal-700 flex flex-col">

  <div class="flex min-h-screen flex-1">

    <!-- SIDEBAR (no RCSS logo per request) -->
    <aside id="managementSidebar"
           class="w-20 bg-teal-800 flex flex-col py-4 shadow-xl fixed inset-y-0 left-0 z-40
                  transition-all duration-300 ease-in-out overflow-hidden">

      <div class="flex items-center justify-center mb-6 flex-shrink-0">
        <button id="sidebarToggle"
                class="text-white/70 hover:text-white p-2 rounded-lg hover:bg-white/10 transition-colors
                       flex items-center justify-center flex-shrink-0" aria-label="Toggle sidebar">
          <i data-lucide="menu" class="w-6 h-6 flex-shrink-0"></i>
        </button>
      </div>

      <nav id="managementNav"
           class="flex flex-col space-y-1 flex-1 w-full px-3 pt-1 overflow-y-auto overflow-x-hidden">

        <a href="dashboard.php"
           class="group relative w-full h-12 rounded-xl hover:bg-white/10 flex items-center text-white transition-all px-3 flex-shrink-0">
          <i data-lucide="home" class="w-6 h-6 flex-shrink-0"></i>
          <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap opacity-0 w-0 overflow-hidden transition-all duration-200">Dashboard</span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-teal-900 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Dashboard</span>
        </a>

        <a href="grievances.php"
           class="group relative w-full h-12 rounded-xl hover:bg-white/10 flex items-center text-white transition-all px-3 flex-shrink-0">
          <i data-lucide="clipboard-list" class="w-6 h-6 flex-shrink-0"></i>
          <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap opacity-0 w-0 overflow-hidden transition-all duration-200">Grievance</span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-teal-900 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Grievance</span>
        </a>

        <div class="sidebar-group flex-shrink-0" data-section="reports">
          <button type="button"
                  id="reportsToggle"
                  data-submenu-toggle="reports"
                  data-landing-href="grievance_reports.php"
                  class="group relative w-full h-12 rounded-xl hover:bg-white/10 flex items-center text-white transition-all px-3">
            <i data-lucide="bar-chart-3" class="w-6 h-6 flex-shrink-0"></i>
            <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap opacity-0 w-0 overflow-hidden transition-all duration-200">Grievance Reports</span>
            <i data-lucide="chevron-down"
               class="sidebar-label submenu-chevron ml-auto w-4 h-4 flex-shrink-0 transition-transform duration-300 opacity-0 w-0 overflow-hidden"></i>
            <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-teal-900 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Grievance Reports</span>
          </button>

          <div id="submenu-reports"
               class="submenu hidden ml-2 mt-1 space-y-1 pl-3 border-l border-white/20">
            <a href="complaint_report.php"
               class="group flex items-center gap-2 px-2 py-2 rounded-lg text-white/80 hover:text-white hover:bg-white/10 transition-all text-xs">
              <i data-lucide="file-bar-chart" class="w-4 h-4 flex-shrink-0 text-white/70 group-hover:text-white"></i>
              <span class="font-medium whitespace-nowrap">Complaint Report</span>
            </a>
            <a href="cell_members_report.php"
               class="group flex items-center gap-2 px-2 py-2 rounded-lg text-white/80 hover:text-white hover:bg-white/10 transition-all text-xs">
              <i data-lucide="users-2" class="w-4 h-4 flex-shrink-0 text-white/70 group-hover:text-white"></i>
              <span class="font-medium whitespace-nowrap">Cell Member Report</span>
            </a>
          </div>
        </div>

        <a href="profile.php"
           class="group relative w-full h-12 rounded-xl bg-white text-teal-800 shadow-md flex items-center px-3 flex-shrink-0 transition-all">
          <i data-lucide="user" class="w-6 h-6 flex-shrink-0"></i>
          <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap opacity-0 w-0 overflow-hidden transition-all duration-200">My Profile</span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-teal-900 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">My Profile</span>
        </a>

        <a href="change_password.php"
           class="group relative w-full h-12 rounded-xl hover:bg-white/10 flex items-center text-white transition-all px-3 flex-shrink-0">
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

    <!-- MAIN CONTENT WRAPPER -->
    <div id="managementMain" class="flex-1 ml-20 flex flex-col min-h-screen transition-all duration-300">

      <!-- TOP HEADER -->
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

          <div class="relative" id="management-dropdown-container">
            <button id="management-dropdown-btn"
                    type="button"
                    aria-haspopup="true"
                    aria-expanded="false"
                    class="flex items-center gap-3 px-2 py-1.5 rounded-lg hover:bg-teal-50 transition-colors">

              <?php if ($hasProfilePicture): ?>
                <img src="<?= e($profilePictureUrl) ?>" alt="<?= e($displayName) ?>"
                     class="w-9 h-9 rounded-full object-cover border-2 border-teal-600" />
              <?php else: ?>
                <div class="w-9 h-9 rounded-full bg-teal-600 flex items-center justify-center text-white">
                  <i data-lucide="user" class="w-5 h-5 text-white"></i>
                </div>
              <?php endif; ?>

              <span class="hidden sm:block text-sm font-semibold text-teal-900 max-w-[10rem] truncate">
                <?= e($displayName) ?>
              </span>
              <i data-lucide="chevron-down" id="management-chevron"
                 class="w-4 h-4 text-teal-600 transition-transform duration-300"></i>
            </button>

            <div id="management-dropdown-menu"
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

              <a href="dashboard.php"
                 class="flex items-center px-4 py-2.5 text-sm text-teal-900 hover:bg-teal-50 transition-all group">
                <i data-lucide="layout-dashboard" class="w-4 h-4 mr-3 text-teal-600"></i>
                <span class="font-medium">Dashboard</span>
                <i data-lucide="arrow-right" class="w-4 h-4 ml-auto opacity-0 group-hover:opacity-100 text-teal-600 transition-opacity"></i>
              </a>

              <a href="profile.php"
                 class="flex items-center px-4 py-2.5 text-sm text-teal-900 hover:bg-teal-50 transition-all group">
                <i data-lucide="user" class="w-4 h-4 mr-3 text-teal-600"></i>
                <span class="font-medium">My Profile</span>
                <i data-lucide="arrow-right" class="w-4 h-4 ml-auto opacity-0 group-hover:opacity-100 text-teal-600 transition-opacity"></i>
              </a>

              <a href="change_password.php"
                 class="flex items-center px-4 py-2.5 text-sm text-teal-900 hover:bg-teal-50 transition-all group">
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

        <!-- Page heading -->
        <div class="max-w-5xl mx-auto mb-8 animate-fade-in-up">
          <div class="flex items-center gap-2 mb-2">
            <span class="inline-flex items-center justify-center w-2 h-2 rounded-full bg-teal-600"></span>
            <p class="text-xs font-semibold text-teal-600 uppercase tracking-wider">Management Console</p>
          </div>
          <h1 class="text-2xl md:text-3xl lg:text-4xl font-bold text-teal-900 mb-2">User Details</h1>
          <nav class="flex flex-wrap items-center gap-2 text-sm text-teal-900/60">
            <a href="dashboard.php" class="inline-flex items-center gap-1 hover:text-teal-600 transition-colors">
              <i data-lucide="layout-dashboard" class="w-4 h-4"></i> Dashboard
            </a>
            <span class="text-teal-900/30">/</span>
            <span class="text-teal-600 font-semibold">User Details</span>
          </nav>
        </div>

        <!-- Flash Messages -->
        <?php if ($flashSuccess !== ''): ?>
          <div id="flashSuccessBox"
               class="max-w-3xl mx-auto mb-6 rounded-xl border-2 border-emerald-200 bg-emerald-50 px-4 py-3
                      flex items-start gap-2 animate-flash-in overflow-hidden">
            <i data-lucide="check-circle" class="w-5 h-5 text-emerald-600 flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-emerald-800 font-medium"><?= e($flashSuccess) ?></p>
          </div>
        <?php endif; ?>

        <?php if ($flashError !== ''): ?>
          <div id="flashErrorBox"
               class="max-w-3xl mx-auto mb-6 rounded-xl border-2 border-red-200 bg-red-50 px-4 py-3
                      flex items-start gap-2 animate-flash-in overflow-hidden">
            <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-red-700 font-medium"><?= e($flashError) ?></p>
          </div>
        <?php endif; ?>

        <!-- DB Error Notice -->
        <?php if ($dbError): ?>
          <div class="max-w-3xl mx-auto mb-6 rounded-xl border-2 border-amber-200 bg-amber-50 px-4 py-3 flex items-start gap-2">
            <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-amber-700"><?= e($dbError) ?></p>
          </div>
        <?php endif; ?>

        <!-- PROFILE CARD -->
        <div class="max-w-3xl mx-auto animate-fade-in-up" style="animation-delay: 100ms;">

          <div class="bg-white rounded-2xl shadow-sm border-2 border-teal-100 overflow-hidden">

            <!-- Card header -->
            <div class="bg-teal-50/60 px-6 py-4 border-b border-teal-100">
              <h2 class="text-lg md:text-xl font-bold text-teal-900 flex items-center">
                <i data-lucide="id-card" class="w-5 h-5 mr-2 text-teal-600"></i>
                User Details
              </h2>
            </div>

            <!-- Avatar banner -->
            <div class="relative px-6 py-10 overflow-hidden">
              <div class="absolute inset-0 bg-gradient-to-br from-teal-50 via-white to-teal-50"></div>
              <div class="absolute -top-10 -right-10 w-40 h-40 bg-teal-100/60 rounded-full blur-2xl"></div>
              <div class="absolute -bottom-10 -left-10 w-40 h-40 bg-teal-100/60 rounded-full blur-2xl"></div>

              <div class="relative flex flex-col items-center justify-center">

                <!-- Avatar -->
                <div class="relative animate-soft-float">
                  <div class="absolute -inset-2 bg-teal-200/60 rounded-full blur-md"></div>

                  <div class="relative w-32 h-32 md:w-36 md:h-36 rounded-full bg-white p-1.5 shadow-lg ring-4 ring-teal-100">
                    <div class="w-full h-full rounded-full overflow-hidden">
                      <?php if ($hasProfilePicture): ?>
                        <img src="<?= e($profilePictureUrl) ?>" alt="<?= e($displayName) ?>" class="w-full h-full object-cover" />
                      <?php else: ?>
                        <div class="w-full h-full bg-teal-600 flex items-center justify-center text-white">
                          <i data-lucide="user" class="w-14 h-14 md:w-16 md:h-16 text-white"></i>
                        </div>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>

                <!-- Name -->
                <h3 class="mt-5 text-2xl md:text-3xl font-bold text-teal-900 tracking-tight">
                  <?= e($displayName) ?>
                </h3>

                <!-- Role chip -->
                <div class="mt-2 inline-flex items-center gap-1.5 bg-teal-50 border-2 border-teal-200 px-3 py-1 rounded-full">
                  <i data-lucide="<?= e($roleIcon) ?>" class="w-3.5 h-3.5 text-teal-600"></i>
                  <span class="text-xs font-bold text-teal-700 uppercase tracking-wider"><?= e($roleLabel) ?></span>
                </div>

                <?php if ($designationLine !== ''): ?>
                  <p class="mt-2 text-sm text-teal-900/70"><?= e($designationLine) ?></p>
                <?php endif; ?>
              </div>
            </div>

            <!-- Info table -->
            <div class="px-6 md:px-10 py-8 bg-teal-50/40 border-t border-teal-100">
              <div class="flex items-center mb-5">
                <i data-lucide="clipboard-list" class="w-4 h-4 text-teal-600 mr-2"></i>
                <h4 class="text-xs font-bold text-teal-900/70 uppercase tracking-wider">Personal Information</h4>
              </div>

              <div class="bg-white rounded-2xl border-2 border-teal-100 overflow-hidden">
                <table class="w-full">
                  <tbody class="divide-y divide-teal-50">

                    <!-- Name -->
                    <tr class="group/row hover:bg-teal-50/40 transition-all duration-200">
                      <td class="w-1/3 px-5 py-5 align-top">
                        <div class="flex items-center text-xs font-bold text-teal-900/60 uppercase tracking-wider group-hover/row:text-teal-700 transition-colors">
                          <div class="w-8 h-8 rounded-lg bg-teal-50 flex items-center justify-center mr-2.5 group-hover/row:scale-110 transition-transform">
                            <i data-lucide="user" class="w-4 h-4 text-teal-600"></i>
                          </div>
                          Name
                        </div>
                      </td>
                      <td class="px-5 py-5 align-top">
                        <p class="text-teal-900 font-semibold text-base"><?= e($displayName) ?></p>
                      </td>
                    </tr>

                    <!-- Address -->
                    <tr class="group/row hover:bg-teal-50/40 transition-all duration-200">
                      <td class="w-1/3 px-5 py-5 align-top">
                        <div class="flex items-center text-xs font-bold text-teal-900/60 uppercase tracking-wider group-hover/row:text-teal-700 transition-colors">
                          <div class="w-8 h-8 rounded-lg bg-teal-50 flex items-center justify-center mr-2.5 group-hover/row:scale-110 transition-transform">
                            <i data-lucide="map-pin" class="w-4 h-4 text-teal-600"></i>
                          </div>
                          Address
                        </div>
                      </td>
                      <td class="px-5 py-5 align-top">
                        <p class="text-teal-900/80 text-base leading-relaxed <?= $displayAddress === 'Not provided' ? 'italic text-teal-900/40' : '' ?>">
                          <?= e($displayAddress) ?>
                        </p>
                      </td>
                    </tr>

                    <!-- Email -->
                    <tr class="group/row hover:bg-teal-50/40 transition-all duration-200">
                      <td class="w-1/3 px-5 py-5 align-top">
                        <div class="flex items-center text-xs font-bold text-teal-900/60 uppercase tracking-wider group-hover/row:text-teal-700 transition-colors">
                          <div class="w-8 h-8 rounded-lg bg-teal-50 flex items-center justify-center mr-2.5 group-hover/row:scale-110 transition-transform">
                            <i data-lucide="mail" class="w-4 h-4 text-teal-600"></i>
                          </div>
                          Email
                        </div>
                      </td>
                      <td class="px-5 py-5 align-top">
                        <p class="text-teal-900/80 text-base break-all <?= $displayEmail === 'Not provided' ? 'italic text-teal-900/40' : '' ?>">
                          <?= e($displayEmail) ?>
                        </p>
                      </td>
                    </tr>

                    <!-- Contact Number -->
                    <tr class="group/row hover:bg-teal-50/40 transition-all duration-200">
                      <td class="w-1/3 px-5 py-5 align-top">
                        <div class="flex items-center text-xs font-bold text-teal-900/60 uppercase tracking-wider group-hover/row:text-teal-700 transition-colors">
                          <div class="w-8 h-8 rounded-lg bg-teal-50 flex items-center justify-center mr-2.5 group-hover/row:scale-110 transition-transform">
                            <i data-lucide="phone" class="w-4 h-4 text-teal-600"></i>
                          </div>
                          Contact Number
                        </div>
                      </td>
                      <td class="px-5 py-5 align-top">
                        <p class="text-teal-900/80 text-base <?= $displayMobile === 'Not provided' ? 'italic text-teal-900/40' : '' ?>">
                          <?= e($displayMobile) ?>
                        </p>
                      </td>
                    </tr>

                  </tbody>
                </table>
              </div>
            </div>

            <!-- Action footer -->
            <div class="px-6 md:px-10 py-6 bg-white border-t border-teal-100 flex flex-col sm:flex-row justify-between items-center gap-3">

              <a href="dashboard.php"
                 class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-6 py-3 rounded-lg
                        font-semibold text-teal-900 bg-white border-2 border-teal-200
                        hover:border-teal-600 hover:bg-teal-50
                        transition-all duration-200 active:scale-95">
                <i data-lucide="arrow-left" class="w-4 h-4"></i>
                <span>Back to Dashboard</span>
              </a>

              <a href="edit_profile.php"
                 class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-8 py-3 rounded-lg
                        font-bold text-white bg-teal-600 hover:bg-teal-700
                        shadow-sm hover:shadow-md
                        transition-all duration-200 active:scale-95">
                <i data-lucide="pencil" class="w-4 h-4"></i>
                <span>Edit</span>
                <i data-lucide="arrow-right" class="w-4 h-4"></i>
              </a>

            </div>

          </div>
        </div>

      </main>

      <!-- FOOTER -->
      <footer class="bg-teal-900 text-white mt-auto">
        <div class="roofline"></div>
        <div class="px-4 sm:px-6 py-6">
          <div class="max-w-7xl mx-auto text-center">
            <p class="text-xs text-teal-200/70">
              Copyright &copy; <?= date('Y') ?>
              <span class="font-bold text-white">Rajagiri College of Social Sciences</span>.
              All rights reserved.
            </p>
            <p class="text-xs text-teal-200/70 mt-1">
              Powered by <span class="font-bold text-white">RLabZ</span>
            </p>
          </div>
        </div>
      </footer>

    </div>
  </div>

  <!-- LOGOUT CONFIRMATION MODAL -->
  <div id="logoutConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeLogoutModal()"></div>

    <div id="logoutConfirmPanel"
         class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">

      <div class="h-1.5 w-full bg-teal-600"></div>

      <div class="px-6 pt-6 pb-2 flex flex-col items-center text-center">
        <div class="w-16 h-16 rounded-full flex items-center justify-center mb-4 bg-red-50 ring-4 ring-red-100/60">
          <i data-lucide="log-out" class="w-8 h-8 text-red-500"></i>
        </div>

        <h3 class="text-xl font-bold text-teal-900 mb-2">Log Out?</h3>

        <p class="text-sm text-teal-900/70 leading-relaxed">
          You are about to log out of
          <span class="font-bold text-teal-700 break-words"><?= e($displayName) ?></span>.
          Any unsaved changes will be lost.
        </p>

        <p class="text-xs text-teal-900/50 font-medium mt-3 flex items-center gap-1.5">
          <i data-lucide="info" class="w-3.5 h-3.5"></i>
          You can log back in anytime.
        </p>
      </div>

      <div class="px-6 py-5 mt-2 flex flex-col-reverse sm:flex-row gap-3">
        <button type="button"
                onclick="closeLogoutModal()"
                class="flex-1 px-5 py-3 rounded-lg font-semibold text-teal-900
                       bg-white border-2 border-teal-200 hover:border-teal-600 hover:bg-teal-50
                       transition-all duration-200">
          Cancel
        </button>

        <button type="button"
                id="confirmLogoutBtn"
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

      const sidebar = document.getElementById('managementSidebar');
      const main    = document.getElementById('managementMain');

      // Auto-dismiss flash
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

      // Sidebar expand / collapse
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

          setTimeout(function () { if (typeof lucide !== 'undefined') lucide.createIcons(); }, 250);
        });
      })();

      // Submenu toggle
      (function () {
        const toggles = document.querySelectorAll('[data-submenu-toggle]');
        if (!toggles.length) return;

        toggles.forEach(function (btn) {
          btn.addEventListener('click', function (e) {
            const sidebarEl = document.getElementById('managementSidebar');
            const isCollapsed = sidebarEl && sidebarEl.classList.contains('w-20');

            if (isCollapsed) {
              const href = btn.getAttribute('data-landing-href');
              if (href) window.location.href = href;
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

      // Profile dropdown
      (function () {
        const btn       = document.getElementById('management-dropdown-btn');
        const menu      = document.getElementById('management-dropdown-menu');
        const chevron   = document.getElementById('management-chevron');
        const container = document.getElementById('management-dropdown-container');

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

      // Logout modal
      (function () {
        const logoutConfirmModal = document.getElementById('logoutConfirmModal');
        const logoutConfirmPanel = document.getElementById('logoutConfirmPanel');
        const confirmLogoutBtn   = document.getElementById('confirmLogoutBtn');
        const LOGOUT_URL         = '../logout.php?role=management';
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
          btn.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); window.openLogoutModal(); });
        });
        if (confirmLogoutBtn) confirmLogoutBtn.addEventListener('click', function () {
          confirmLogoutBtn.classList.add('opacity-50', 'pointer-events-none');
          window.location.href = LOGOUT_URL;
        });
        document.addEventListener('keydown', function (e) {
          if (e.key === 'Escape' && !logoutConfirmModal.classList.contains('hidden')) window.closeLogoutModal();
        });
      })();
    });
  </script>

  <script src="../assets/js/index.js"></script>
</body>
</html>
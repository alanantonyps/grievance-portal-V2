<?php
/**
 * student/profile.php
 * ---------------------------------------------------------------------------
 * Student Profile (View Only) — Rajagiri College Grievance Redressal Portal
 *
 * Auth Check : case-insensitive role match against 'STUDENT'
 * Data Source: users LEFT JOIN students LEFT JOIN classes
 * Fields     : Name, Address, Email, Contact Number, Class
 *
 * Features:
 *   • Read-only view of student profile
 *   • Edit button → redirects to student/edit_profile.php
 *   • Back to Dashboard button
 *   • Class column shown from classes.class_name via students.class_id
 *   • Profile picture correctly resolved from ../uploads/profiles/
 *   • Flash success / error messages with auto-dismiss
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
// 2. AUTH GUARD (Student only)
// ---------------------------------------------------------------------------
$sessionRole = isset($_SESSION['role']) ? strtoupper((string) $_SESSION['role']) : '';

if (empty($_SESSION['user_id']) || $sessionRole !== 'STUDENT') {
    header('Location: ../login.php?role=student');
    exit;
}

$userId = (int) $_SESSION['user_id'];

// ---------------------------------------------------------------------------
// 3. DATABASE CONNECTION
// ---------------------------------------------------------------------------
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

// ---------------------------------------------------------------------------
// 4. HELPER — HTML ESCAPE
// ---------------------------------------------------------------------------
function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------------------------------------------------------------------------
// 5. FLASH MESSAGES
// ---------------------------------------------------------------------------
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

// ---------------------------------------------------------------------------
// 6. FETCH STUDENT PROFILE (users JOIN students JOIN classes)
// ---------------------------------------------------------------------------
$profile = [
    'username'       => $_SESSION['username'] ?? 'Student',
    'name'           => '',
    'address'        => '',
    'email'          => '',
    'contact_number' => '',
    'class_name'     => '',
    'profile_image'  => '',
];

if ($conn instanceof mysqli) {
    try {
        $sql = "SELECT  u.username,
                        s.name            AS name,
                        s.address         AS address,
                        s.email           AS email,
                        s.contact_number  AS contact_number,
                        s.profile_image   AS profile_image,
                        c.class_name      AS class_name
                FROM users u
                LEFT JOIN students s ON s.user_id = u.id
                LEFT JOIN classes  c ON c.id      = s.class_id
                WHERE u.id = ?
                LIMIT 1";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();

            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();

                $profile['username']       = $row['username']       ?? $profile['username'];
                $profile['name']           = $row['name']           ?? '';
                $profile['address']        = $row['address']        ?? '';
                $profile['email']          = $row['email']          ?? '';
                $profile['contact_number'] = $row['contact_number'] ?? '';
                $profile['class_name']     = $row['class_name']     ?? '';
                $profile['profile_image']  = $row['profile_image']  ?? '';
            }
            $stmt->close();
        }
    } catch (Throwable $ex) {
        error_log('[Student Profile Fetch] ' . $ex->getMessage());
        $dbError = 'Unable to load profile data at this time.';
    }
}

// ---------------------------------------------------------------------------
// 7. DISPLAY FALLBACKS
// ---------------------------------------------------------------------------
$displayName    = !empty($profile['name'])           ? $profile['name']           : $profile['username'];
$displayAddress = !empty($profile['address'])        ? $profile['address']        : 'Not provided';
$displayEmail   = !empty($profile['email'])          ? $profile['email']          : 'Not provided';
$displayMobile  = !empty($profile['contact_number']) ? $profile['contact_number'] : 'Not provided';
$displayClass   = !empty($profile['class_name'])     ? $profile['class_name']     : 'Not assigned';

// ---------------------------------------------------------------------------
// 8. PROFILE PICTURE RESOLUTION
//    Files are stored at  <project-root>/uploads/profiles/...
//    From student/ the browser URL is  ../uploads/profiles/...
//    Disk check uses  __DIR__ . '/../uploads/profiles/...'
// ---------------------------------------------------------------------------
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
  <title>User Details — Student | Rajagiri College Grievance Portal</title>
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
            flashIn: { '0%': { opacity: '0', transform: 'translateY(-10px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
            flashOut: { '0%': { opacity: '1', transform: 'translateY(0)', maxHeight: '200px' }, '100%': { opacity: '0', transform: 'translateY(-10px)', maxHeight: '0px' } },
            softFloat: { '0%, 100%': { transform: 'translateY(0px)' }, '50%': { transform: 'translateY(-4px)' } }
          },
          animation: {
            'fade-in-up': 'fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'dropdown':   'dropdownFade 0.2s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'flash-in':   'flashIn 0.35s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'flash-out':  'flashOut 0.45s cubic-bezier(0.4, 0, 1, 1) forwards',
            'soft-float': 'softFloat 4s ease-in-out infinite'
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
    .hero-dots { background-image: radial-gradient(rgba(255,255,255,0.35) 1.5px, transparent 1.5px); background-size: 22px 22px; }
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

    <!-- SIDEBAR (no brand logo) -->
    <aside id="studentSidebar"
           class="w-20 bg-teal-800 flex flex-col py-4 shadow-xl fixed inset-y-0 left-0 z-40
                  transition-all duration-300 ease-in-out overflow-hidden">

      <div class="flex items-center justify-end px-3 mb-6 flex-shrink-0">
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

        <a href="profile.php" class="group relative w-full h-12 rounded-xl bg-white text-teal-800 shadow-md flex items-center px-3 flex-shrink-0 transition-all">
          <i data-lucide="user" class="w-6 h-6 flex-shrink-0"></i>
          <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap opacity-0 w-0 overflow-hidden transition-all duration-200">My Profile</span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-teal-900 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">My Profile</span>
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
    <div id="studentMain" class="flex-1 ml-20 flex flex-col min-h-screen transition-all duration-300">

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

          <div class="relative" id="student-dropdown-container">
            <button id="student-dropdown-btn" type="button" aria-haspopup="true" aria-expanded="false"
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
              <i data-lucide="chevron-down" id="student-chevron" class="w-4 h-4 text-teal-600 transition-transform duration-300"></i>
            </button>

            <div id="student-dropdown-menu" class="hidden absolute right-0 mt-2 w-64 bg-white rounded-xl shadow-xl border border-teal-100 py-2 z-50 overflow-hidden">
              <div class="px-4 py-3 border-b border-teal-100 bg-teal-50/60">
                <div class="flex items-center gap-3">
                  <?php if ($hasProfilePicture): ?>
                    <img src="<?= e($profilePictureUrl) ?>" alt="<?= e($displayName) ?>" class="w-12 h-12 rounded-full object-cover border-2 border-teal-600" />
                  <?php else: ?>
                    <div class="w-12 h-12 rounded-full bg-teal-600 flex items-center justify-center text-white"><i data-lucide="user" class="w-6 h-6 text-white"></i></div>
                  <?php endif; ?>
                  <div class="min-w-0 flex-1">
                    <p class="text-sm font-bold text-teal-900 truncate"><?= e($displayName) ?></p>
                    <p class="text-xs text-teal-900/60 truncate"><?= e($displayEmail) ?></p>
                  </div>
                </div>
              </div>

              <a href="dashboard.php" class="flex items-center px-4 py-2.5 text-sm text-teal-900 hover:bg-teal-50 transition-all group">
                <i data-lucide="layout-dashboard" class="w-4 h-4 mr-3 text-teal-600"></i><span class="font-medium">Dashboard</span>
                <i data-lucide="arrow-right" class="w-4 h-4 ml-auto opacity-0 group-hover:opacity-100 text-teal-600 transition-opacity"></i>
              </a>
              <a href="profile.php" class="flex items-center px-4 py-2.5 text-sm text-teal-600 bg-teal-50 font-semibold">
                <i data-lucide="user" class="w-4 h-4 mr-3"></i><span>My Profile</span>
              </a>
              <a href="change_password.php" class="flex items-center px-4 py-2.5 text-sm text-teal-900 hover:bg-teal-50 transition-all group">
                <i data-lucide="key" class="w-4 h-4 mr-3 text-teal-600"></i><span class="font-medium">Change Password</span>
                <i data-lucide="arrow-right" class="w-4 h-4 ml-auto opacity-0 group-hover:opacity-100 text-teal-600 transition-opacity"></i>
              </a>
              <div class="border-t border-teal-100 mt-1 pt-1">
                <a href="#" data-logout-trigger="1" id="dropdownLogoutBtn" class="flex items-center px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 transition-all group">
                  <i data-lucide="log-out" class="w-4 h-4 mr-3"></i><span class="font-medium">Logout</span>
                </a>
              </div>
            </div>
          </div>
        </div>
      </header>

      <main class="flex-1 px-4 sm:px-6 py-6 sm:py-8">

        <div class="max-w-5xl mx-auto mb-6 animate-fade-in-up">
          <div class="flex items-center gap-2 mb-2">
            <span class="inline-flex items-center justify-center w-2 h-2 rounded-full bg-teal-600"></span>
            <p class="text-xs font-semibold text-teal-600 uppercase tracking-wider">Student Portal</p>
          </div>
          <h1 class="text-2xl md:text-3xl font-bold text-teal-900 mb-2">User Details</h1>
          <nav class="flex flex-wrap items-center gap-2 text-sm text-teal-900/60">
            <a href="dashboard.php" class="inline-flex items-center gap-1 hover:text-teal-600 transition-colors">
              <i data-lucide="layout-dashboard" class="w-4 h-4"></i>Dashboard
            </a>
            <span class="text-teal-900/30">/</span>
            <span class="text-teal-600 font-semibold">User Details</span>
          </nav>
        </div>

        <?php if ($flashSuccess !== ''): ?>
          <div id="flashSuccessBox" class="max-w-3xl mx-auto mb-6 rounded-xl border-2 border-emerald-200 bg-emerald-50 px-4 py-3 flex items-start gap-2 animate-flash-in overflow-hidden">
            <i data-lucide="check-circle" class="w-5 h-5 text-emerald-600 flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-emerald-800 font-medium"><?= e($flashSuccess) ?></p>
          </div>
        <?php endif; ?>

        <?php if ($flashError !== ''): ?>
          <div id="flashErrorBox" class="max-w-3xl mx-auto mb-6 rounded-xl border-2 border-red-200 bg-red-50 px-4 py-3 flex items-start gap-2 animate-flash-in overflow-hidden">
            <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-red-700 font-medium"><?= e($flashError) ?></p>
          </div>
        <?php endif; ?>

        <?php if ($dbError): ?>
          <div class="max-w-3xl mx-auto mb-6 rounded-xl border-2 border-amber-200 bg-amber-50 px-4 py-3 flex items-start gap-2">
            <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-amber-700"><?= e($dbError) ?></p>
          </div>
        <?php endif; ?>

        <div class="max-w-3xl mx-auto">
          <div class="bg-white rounded-2xl border-2 border-teal-100 overflow-hidden animate-fade-in-up">

            <div class="bg-teal-50/60 border-b border-teal-100 px-6 py-4">
              <h2 class="text-lg md:text-xl font-bold text-teal-900 flex items-center gap-2">
                <i data-lucide="id-card" class="w-5 h-5 text-teal-600"></i>
                User Details
              </h2>
            </div>

            <div class="px-6 py-8 bg-white">
              <div class="flex flex-col items-center justify-center">

                <div class="relative">
                  <?php if ($hasProfilePicture): ?>
                    <img src="<?= e($profilePictureUrl) ?>" alt="<?= e($displayName) ?>"
                         class="w-28 h-28 md:w-32 md:h-32 rounded-full object-cover border-4 border-teal-600 shadow-sm" />
                  <?php else: ?>
                    <div class="w-28 h-28 md:w-32 md:h-32 rounded-full bg-teal-600 flex items-center justify-center text-white shadow-sm">
                      <i data-lucide="user" class="w-14 h-14 md:w-16 md:h-16 text-white"></i>
                    </div>
                  <?php endif; ?>
                </div>

                <h3 class="mt-5 text-2xl md:text-3xl font-bold text-teal-900 tracking-tight">
                  <?= e($displayName) ?>
                </h3>

                <div class="mt-2 inline-flex items-center gap-1.5 bg-teal-50 border border-teal-200 px-3 py-1 rounded-full">
                  <i data-lucide="graduation-cap" class="w-3.5 h-3.5 text-teal-600"></i>
                  <span class="text-xs font-bold text-teal-700 uppercase tracking-wider">Student</span>
                </div>
              </div>
            </div>

            <div class="px-6 md:px-10 py-8 bg-teal-50/40 border-t border-teal-100">
              <div class="flex items-center mb-5 gap-2">
                <i data-lucide="clipboard-list" class="w-4 h-4 text-teal-600"></i>
                <h4 class="text-xs font-bold text-teal-900/60 uppercase tracking-wider">Personal Information</h4>
              </div>

              <div class="bg-white rounded-2xl border-2 border-teal-100 overflow-hidden">
                <table class="w-full">
                  <tbody class="divide-y divide-teal-100">
                    <tr class="hover:bg-teal-50/60 transition-colors">
                      <td class="w-1/3 px-5 py-5 align-top">
                        <div class="flex items-center text-xs font-bold text-teal-900/60 uppercase tracking-wider gap-2">
                          <div class="w-8 h-8 rounded-lg bg-teal-50 flex items-center justify-center">
                            <i data-lucide="user" class="w-4 h-4 text-teal-600"></i>
                          </div>
                          Name
                        </div>
                      </td>
                      <td class="px-5 py-5 align-top">
                        <p class="text-teal-900 font-semibold text-base"><?= e($displayName) ?></p>
                      </td>
                    </tr>

                    <tr class="hover:bg-teal-50/60 transition-colors">
                      <td class="w-1/3 px-5 py-5 align-top">
                        <div class="flex items-center text-xs font-bold text-teal-900/60 uppercase tracking-wider gap-2">
                          <div class="w-8 h-8 rounded-lg bg-teal-50 flex items-center justify-center">
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

                    <tr class="hover:bg-teal-50/60 transition-colors">
                      <td class="w-1/3 px-5 py-5 align-top">
                        <div class="flex items-center text-xs font-bold text-teal-900/60 uppercase tracking-wider gap-2">
                          <div class="w-8 h-8 rounded-lg bg-teal-50 flex items-center justify-center">
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

                    <tr class="hover:bg-teal-50/60 transition-colors">
                      <td class="w-1/3 px-5 py-5 align-top">
                        <div class="flex items-center text-xs font-bold text-teal-900/60 uppercase tracking-wider gap-2">
                          <div class="w-8 h-8 rounded-lg bg-teal-50 flex items-center justify-center">
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

                    <tr class="hover:bg-teal-50/60 transition-colors">
                      <td class="w-1/3 px-5 py-5 align-top">
                        <div class="flex items-center text-xs font-bold text-teal-900/60 uppercase tracking-wider gap-2">
                          <div class="w-8 h-8 rounded-lg bg-teal-50 flex items-center justify-center">
                            <i data-lucide="book-open" class="w-4 h-4 text-teal-600"></i>
                          </div>
                          Class
                        </div>
                      </td>
                      <td class="px-5 py-5 align-top">
                        <p class="text-teal-900/80 text-base <?= $displayClass === 'Not assigned' ? 'italic text-teal-900/40' : '' ?>">
                          <?= e($displayClass) ?>
                        </p>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <div class="px-6 md:px-10 py-6 bg-white border-t border-teal-100 flex flex-col sm:flex-row justify-between items-center gap-3">

              <a href="dashboard.php"
                 class="inline-flex items-center justify-center gap-2 px-6 py-3 rounded-lg font-semibold
                        text-teal-600 bg-white border-2 border-teal-200 hover:border-teal-600 hover:bg-teal-50
                        transition-all duration-200 w-full sm:w-auto">
                <i data-lucide="arrow-left" class="w-4 h-4"></i>
                <span>Back to Dashboard</span>
              </a>

              <a href="edit_profile.php"
                 class="group inline-flex items-center justify-center gap-2 px-8 py-3 rounded-lg font-semibold text-white
                        bg-teal-600 hover:bg-teal-700 shadow-sm hover:shadow-md
                        transition-all duration-200 w-full sm:w-auto">
                <i data-lucide="pencil" class="w-4 h-4"></i>
                <span>Edit</span>
                <i data-lucide="arrow-right" class="w-4 h-4 transition-transform group-hover:translate-x-1"></i>
              </a>

            </div>

          </div>
        </div>

      </main>

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
                    <a href="dashboard.php" class="text-teal-100/80 hover:text-white transition-colors inline-flex items-center gap-1.5">
                      <i data-lucide="arrow-right" class="w-3 h-3"></i><span>Dashboard</span>
                    </a>
                  </li>
                  <li>
                    <a href="profile.php" class="text-teal-100/80 hover:text-white transition-colors inline-flex items-center gap-1.5">
                      <i data-lucide="arrow-right" class="w-3 h-3"></i><span>My Profile</span>
                    </a>
                  </li>
                  <li>
                    <a href="change_password.php" class="text-teal-100/80 hover:text-white transition-colors inline-flex items-center gap-1.5">
                      <i data-lucide="arrow-right" class="w-3 h-3"></i><span>Change Password</span>
                    </a>
                  </li>
                </ul>
              </div>

              <div>
                <h4 class="font-bold text-sm uppercase tracking-wide text-teal-200 mb-3">Contact Support</h4>
                <ul class="space-y-2 text-sm text-teal-100/80">
                  <li class="flex items-center gap-2">
                    <i data-lucide="mail" class="w-4 h-4 text-teal-300"></i>
                    <span>student.grievance@rajagiri.edu</span>
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
                  <span class="font-bold text-white">Rajagiri College of Social Sciences</span>. All rights reserved.
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

  <!-- LOGOUT CONFIRMATION MODAL -->
  <div id="logoutConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeLogoutModal()"></div>

    <div class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl overflow-hidden">
      <div class="h-1.5 w-full bg-teal-600"></div>

      <div class="px-6 pt-6 pb-2 flex flex-col items-center text-center">
        <div class="w-16 h-16 rounded-full flex items-center justify-center mb-4 bg-red-50 ring-4 ring-red-100/60">
          <i data-lucide="log-out" class="w-8 h-8 text-red-500"></i>
        </div>

        <h3 class="text-xl font-bold text-teal-900 mb-2">Log Out?</h3>

        <p class="text-sm text-teal-900/70 leading-relaxed">
          You are about to log out of <span class="font-bold text-teal-700 break-words"><?= e($displayName) ?></span>.
        </p>

        <p class="text-xs text-teal-900/50 font-medium mt-3 flex items-center gap-1.5">
          <i data-lucide="info" class="w-3.5 h-3.5"></i> You can log back in anytime.
        </p>
      </div>

      <div class="px-6 py-5 mt-2 flex flex-col-reverse sm:flex-row gap-3">
        <button type="button" onclick="closeLogoutModal()"
                class="flex-1 px-5 py-3 rounded-lg font-semibold text-teal-900 bg-white border-2 border-teal-200 hover:border-teal-600 hover:bg-teal-50 transition-all duration-200">
          Cancel
        </button>
        <button type="button" id="confirmLogoutBtn"
                class="flex-1 px-5 py-3 rounded-lg font-bold text-white bg-teal-600 hover:bg-teal-700 shadow-sm hover:shadow-md transition-all duration-200 flex items-center justify-center gap-2">
          <i data-lucide="log-out" class="w-4 h-4"></i>
          <span>Log Out</span>
        </button>
      </div>
    </div>
  </div>

  <script>
    if (typeof lucide !== 'undefined') lucide.createIcons();

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

    // Sidebar toggle
    (function () {
      const toggleBtn = document.getElementById('sidebarToggle');
      const sidebar   = document.getElementById('studentSidebar');
      const main      = document.getElementById('studentMain');
      if (!toggleBtn || !sidebar || !main) return;
      const labels   = sidebar.querySelectorAll('.sidebar-label');
      const tooltips = sidebar.querySelectorAll('.sidebar-tooltip');
      let expanded = false;
      toggleBtn.addEventListener('click', function () {
        expanded = !expanded;
        if (expanded) {
          sidebar.classList.remove('w-20'); sidebar.classList.add('w-64');
          main.classList.remove('ml-20'); main.classList.add('ml-64');
          labels.forEach(function (el) { el.classList.remove('opacity-0','w-0'); el.classList.add('opacity-100','w-auto'); });
          tooltips.forEach(function (el) { el.classList.add('hidden'); });
        } else {
          sidebar.classList.add('w-20'); sidebar.classList.remove('w-64');
          main.classList.add('ml-20'); main.classList.remove('ml-64');
          labels.forEach(function (el) { el.classList.add('opacity-0','w-0'); el.classList.remove('opacity-100','w-auto'); });
          tooltips.forEach(function (el) { el.classList.remove('hidden'); });
        }
        setTimeout(function () { if (typeof lucide !== 'undefined') lucide.createIcons(); }, 250);
      });
    })();

    // Profile dropdown
    (function () {
      const btn = document.getElementById('student-dropdown-btn');
      const menu = document.getElementById('student-dropdown-menu');
      const chevron = document.getElementById('student-chevron');
      const container = document.getElementById('student-dropdown-container');
      if (!btn || !menu || !container) return;

      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        const isOpen = !menu.classList.contains('hidden');
        if (isOpen) {
          menu.classList.add('hidden');
          if (chevron) chevron.classList.remove('rotate-180');
          btn.setAttribute('aria-expanded','false');
        } else {
          menu.classList.remove('hidden');
          if (chevron) chevron.classList.add('rotate-180');
          btn.setAttribute('aria-expanded','true');
        }
      });
      document.addEventListener('click', function (e) {
        if (!container.contains(e.target)) {
          menu.classList.add('hidden');
          if (chevron) chevron.classList.remove('rotate-180');
          btn.setAttribute('aria-expanded','false');
        }
      });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
          menu.classList.add('hidden');
          if (chevron) chevron.classList.remove('rotate-180');
          btn.setAttribute('aria-expanded','false');
        }
      });
    })();

    // Logout modal
    const logoutConfirmModal = document.getElementById('logoutConfirmModal');
    const confirmLogoutBtn   = document.getElementById('confirmLogoutBtn');
    const LOGOUT_URL = '../logout.php?role=student';

    function openLogoutModal() {
      logoutConfirmModal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }
    function closeLogoutModal() {
      logoutConfirmModal.classList.add('hidden');
      document.body.classList.remove('overflow-hidden');
    }
    [document.getElementById('sidebarLogoutBtn'), document.getElementById('dropdownLogoutBtn')].forEach(function (btn) {
      if (!btn) return;
      btn.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); openLogoutModal(); });
    });
    if (confirmLogoutBtn) {
      confirmLogoutBtn.addEventListener('click', function () {
        confirmLogoutBtn.classList.add('opacity-50', 'pointer-events-none');
        window.location.href = LOGOUT_URL;
      });
    }
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && logoutConfirmModal && !logoutConfirmModal.classList.contains('hidden')) {
        closeLogoutModal();
      }
    });
  </script>

  <script src="../assets/js/index.js"></script>
</body>
</html>
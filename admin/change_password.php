<?php
/**
 * admin/change_password.php
 * ---------------------------------------------------------------------------
 * Admin — Change Password
 * Rajagiri College Grievance Redressal Portal
 *
 * Handles:
 *   • Auth guard (ADMIN only)
 *   • POST processing: verify current password → validate new → update BCRYPT hash
 *   • Auto-redirect to dashboard after successful password change
 *   • Success / error messaging
 *   • Themed logout confirmation modal
 *   • Full profile dropdown in header
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

if (empty($_SESSION['user_id']) || $sessionRole !== 'ADMIN') {
    header('Location: ../login.php?role=admin');
    exit;
}

// DATABASE CONNECTION
$dbFile = __DIR__ . '/../db_connect.php';

if (!file_exists($dbFile)) {
    die('Database configuration file (db_connect.php) not found.');
}

require_once $dbFile;

$dbError = null;

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

$userId = (int) $_SESSION['user_id'];

function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// FETCH ADMIN PROFILE (header display)
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
                WHERE u.id = ? LIMIT 1";

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
        error_log('[Change Password Admin Profile] ' . $ex->getMessage());
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

$successMessage  = '';
$formErrors      = [];
$passwordChanged = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword     = (string) ($_POST['new_password']     ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if ($currentPassword === '') {
        $formErrors[] = 'Current password is required.';
    }

    if ($newPassword === '') {
        $formErrors[] = 'New password is required.';
    } elseif (strlen($newPassword) < 6) {
        $formErrors[] = 'New password must be at least 6 characters.';
    }

    if ($confirmPassword === '') {
        $formErrors[] = 'Please confirm your new password.';
    } elseif ($newPassword !== $confirmPassword) {
        $formErrors[] = 'New password and Confirm password do not match.';
    }

    if (empty($formErrors) && $conn !== null) {
        try {
            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();

            if ($res && $res->num_rows > 0) {
                $user = $res->fetch_assoc();
                $currentHash = $user['password'] ?? '';

                if (!password_verify($currentPassword, $currentHash)) {
                    $formErrors[] = 'Current password is incorrect.';
                } elseif ($currentPassword === $newPassword) {
                    $formErrors[] = 'New password must be different from the current password.';
                } else {
                    $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);

                    $stmtUpd = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmtUpd->bind_param('si', $hashedPassword, $userId);

                    if ($stmtUpd->execute()) {
                        $successMessage  = 'Your password has been changed successfully.';
                        $passwordChanged = true;
                    } else {
                        $formErrors[] = 'Failed to update the password. Please try again.';
                    }
                    $stmtUpd->close();
                }
            } else {
                $formErrors[] = 'Unable to locate your account. Please try again.';
            }
            $stmt->close();

        } catch (Throwable $ex) {
            error_log('[Change Password] ' . $ex->getMessage());
            $formErrors[] = 'A system error occurred. Please try again.';
        }
    }

    if ($dbError !== null && empty($formErrors)) {
        $formErrors[] = $dbError;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Change Password — Admin | Rajagiri College Grievance Portal</title>
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
            countdown: { '0%': { width: '100%' }, '100%': { width: '0%' } },
            shimmer: { '0%': { backgroundPosition: '-200% 0' }, '100%': { backgroundPosition: '200% 0' } },
            pulseRing: { '0%': { boxShadow: '0 0 0 0 rgba(0,110,116,0.45)' }, '70%': { boxShadow: '0 0 0 12px rgba(0,110,116,0)' }, '100%': { boxShadow: '0 0 0 0 rgba(0,110,116,0)' } }
          },
          animation: {
            'fade-in-up': 'fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'dropdown':   'dropdownFade 0.2s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'modal-in':   'modalFadeIn 0.25s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'confirm-shake': 'confirmShake 0.5s cubic-bezier(0.16, 1, 0.3, 1)',
            'countdown':  'countdown 3s linear forwards',
            'shimmer':    'shimmer 3s linear infinite',
            'pulse-ring': 'pulseRing 2s ease-out infinite'
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
       AMBIENT BACKGROUND MESH (fixed only on desktop)
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
       CUSTOM SCROLLBAR (desktop only)
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

    #sidebarNav::-webkit-scrollbar { width: 6px; }
    #sidebarNav::-webkit-scrollbar-track { background: transparent; }
    #sidebarNav::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 3px; }
    #sidebarNav::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.35); }

    /* ============================================================
       FORM INPUTS
       ============================================================ */
    .form-input {
      width: 100%;
      padding: 0.875rem 1rem;
      border: 2px solid #CFE6E7;
      border-radius: 0.5rem;
      background-color: rgba(234, 244, 244, 0.4);
      color: #003134;
      font-size: 0.875rem;
      font-weight: 500;
      transition: all 0.2s ease;
    }
    .form-input::placeholder { color: rgba(0, 49, 52, 0.4); }
    .form-input:hover { border-color: #9FCDCF; }
    .form-input:focus {
      outline: none;
      border-color: #006E74;
      background-color: #fff;
      box-shadow: 0 0 0 4px rgba(0, 110, 116, 0.1);
    }
    .form-input.pl-12 { padding-left: 3rem; }
    .form-input.pr-12 { padding-right: 3rem; }

    /* ============================================================
       SIDEBAR ACTIVE GLOW + LINK HOVER SLIDE
       ============================================================ */
    .sidebar-active-glow {
      box-shadow: 0 0 0 1px rgba(255,255,255,.3),
                  0 6px 20px -6px rgba(0,0,0,.35),
                  inset 0 0 20px rgba(255,255,255,.06);
    }
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
       BUTTON SHEEN
       ============================================================ */
    .btn-sheen { position: relative; overflow: hidden; }
    .btn-sheen::before {
      content: '';
      position: absolute; inset: 0;
      background: linear-gradient(120deg, transparent 30%, rgba(255,255,255,.22) 50%, transparent 70%);
      background-size: 200% 100%;
      transform: translateX(-100%);
      transition: transform .8s cubic-bezier(.16,1,.3,1);
      pointer-events: none;
    }
    @media (hover: hover) and (pointer: fine) {
      .btn-sheen:hover::before { transform: translateX(100%); }
    }

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
       LOW-END DEVICE STRIP-OUT
       ============================================================ */
    @media (max-width: 480px) {
      .roofline::after { animation: none; }
      .heading-underline::after { animation: none; transform: scaleX(1); }
      .animate-pulse-ring { animation: none; }
      .btn-sheen::before { display: none; }
    }

    /* ============================================================
       REDUCED MOTION
       ============================================================ */
    @media (prefers-reduced-motion: reduce) {
      *, *::before, *::after {
        animation-duration: .001ms !important;
        transition-duration: .001ms !important;
      }
      .heading-underline::after { transform: scaleX(1); }
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
           class="w-20 bg-teal-800 flex flex-col py-4 shadow-xl fixed inset-y-0 left-0 z-40
                  transition-all duration-300 ease-in-out overflow-hidden">

      <!-- Brand row: "grievance" text + toggle (logo removed) -->
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

      <nav id="sidebarNav" class="flex flex-col space-y-1 flex-1 w-full px-3 pt-1 overflow-y-auto overflow-x-hidden">

        <a href="dashboard.php" class="sidebar-link group relative w-full h-12 rounded-xl hover:bg-white/10 flex items-center text-white transition-all px-3 flex-shrink-0">
          <i data-lucide="home" class="w-6 h-6 flex-shrink-0"></i>
          <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap opacity-0 w-0 overflow-hidden transition-all duration-200">Dashboard</span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-teal-900 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Dashboard</span>
        </a>

        <a href="profile.php" class="sidebar-link group relative w-full h-12 rounded-xl hover:bg-white/10 flex items-center text-white transition-all px-3 flex-shrink-0">
          <i data-lucide="user" class="w-6 h-6 flex-shrink-0"></i>
          <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap opacity-0 w-0 overflow-hidden transition-all duration-200">My Profile</span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-teal-900 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">My Profile</span>
        </a>

        <a href="settings.php" class="sidebar-link group relative w-full h-12 rounded-xl hover:bg-white/10 flex items-center text-white transition-all px-3 flex-shrink-0">
          <i data-lucide="settings" class="w-6 h-6 flex-shrink-0"></i>
          <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap opacity-0 w-0 overflow-hidden transition-all duration-200">Settings</span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-teal-900 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Settings</span>
        </a>

        <a href="change_password.php" class="sidebar-link sidebar-active-glow group relative w-full h-12 rounded-xl bg-white text-teal-800 shadow-md flex items-center px-3 flex-shrink-0 transition-all">
          <i data-lucide="key" class="w-6 h-6 flex-shrink-0"></i>
          <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap opacity-0 w-0 overflow-hidden transition-all duration-200">Change Password</span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-teal-900 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Change Password</span>
        </a>

      </nav>

      <a href="#" data-logout-trigger="1" id="sidebarLogoutBtn"
         class="sidebar-link group relative w-full h-12 rounded-xl hover:bg-red-500/30 flex items-center text-white transition-all mx-3 px-3 flex-shrink-0"
         style="width: calc(100% - 1.5rem);" title="Logout">
        <i data-lucide="log-out" class="w-6 h-6 flex-shrink-0"></i>
        <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap opacity-0 w-0 overflow-hidden transition-all duration-200">Logout</span>
        <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-red-600 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Logout</span>
      </a>
    </aside>

    <!-- ============================================================
         MAIN CONTENT
         ============================================================ -->
    <div id="adminMain" class="flex-1 ml-20 flex flex-col min-h-screen transition-all duration-300">

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

          <div class="flex items-center gap-3">

            <a href="dashboard.php"
               class="group hidden sm:inline-flex items-center gap-2 px-4 py-2 rounded-lg
                      text-teal-600 bg-white border-2 border-teal-200 hover:border-teal-600 hover:bg-teal-50
                      transition-all duration-200">
              <i data-lucide="arrow-left" class="w-4 h-4 transition-transform group-hover:-translate-x-1"></i>
              <span class="text-sm font-semibold">Back to Dashboard</span>
            </a>

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

                <a href="change_password.php" class="flex items-center px-4 py-2.5 text-sm text-teal-600 bg-teal-50 font-semibold">
                  <i data-lucide="key" class="w-4 h-4 mr-3"></i>
                  <span>Change Password</span>
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

        </div>
      </header>

      <main class="flex-1 px-4 sm:px-6 py-6 sm:py-8">

        <div class="max-w-5xl mx-auto mb-6 animate-fade-in-up">
          <div class="flex items-center gap-2 mb-2">
            <span class="inline-flex items-center justify-center w-2 h-2 rounded-full bg-teal-600 animate-pulse-ring"></span>
            <p class="text-xs font-semibold text-teal-600 uppercase tracking-wider">Admin Console</p>
          </div>
          <h1 class="text-2xl md:text-3xl font-bold text-teal-900 mb-3">
            <span class="heading-underline">Change Password</span>
          </h1>
          <nav class="flex flex-wrap items-center gap-2 text-sm text-teal-900/60">
            <a href="dashboard.php" class="inline-flex items-center gap-1 hover:text-teal-600 transition-colors">
              <i data-lucide="layout-dashboard" class="w-4 h-4"></i>
              Dashboard
            </a>
            <span class="text-teal-900/30">/</span>
            <span class="text-teal-600 font-semibold">Change Password</span>
          </nav>
        </div>

        <?php if ($passwordChanged && $successMessage !== ''): ?>
          <div id="success-banner"
               class="max-w-2xl mx-auto mb-6 rounded-2xl border-2 border-emerald-200 bg-emerald-50 px-5 py-4 flex items-start gap-3">
            <div class="w-10 h-10 rounded-full bg-emerald-500 flex items-center justify-center flex-shrink-0">
              <i data-lucide="check-circle" class="w-6 h-6 text-white"></i>
            </div>
            <div class="flex-1">
              <p class="text-sm font-bold text-emerald-800"><?= e($successMessage) ?></p>
              <p class="text-xs text-emerald-700 mt-1">
                Redirecting you to the dashboard in <span id="countdown-text">3</span> seconds…
              </p>
              <div class="h-1 bg-emerald-200 rounded-full overflow-hidden mt-3">
                <div class="h-full bg-emerald-500 animate-countdown"></div>
              </div>
            </div>
          </div>

          <script>
            (function () {
              var seconds = 3;
              var textEl = document.getElementById('countdown-text');

              var interval = setInterval(function () {
                seconds--;
                if (textEl) textEl.textContent = seconds > 0 ? seconds : 0;

                if (seconds <= 0) {
                  clearInterval(interval);
                  window.location.href = 'dashboard.php';
                }
              }, 1000);
            })();
          </script>
        <?php endif; ?>

        <?php if (!empty($formErrors)): ?>
          <div class="max-w-2xl mx-auto mb-6 rounded-xl border-2 border-red-200 bg-red-50 px-4 py-3 flex items-start gap-2">
            <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5"></i>
            <div class="text-sm text-red-700 space-y-1">
              <?php foreach ($formErrors as $err): ?>
                <p><?= e($err) ?></p>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <div class="max-w-2xl mx-auto animate-fade-in-up">
          <div class="bg-white rounded-2xl border-2 border-teal-100 overflow-hidden">

            <div class="bg-teal-600 px-6 py-5">
              <div class="flex items-center gap-3">
                <div class="w-12 h-12 bg-white/15 rounded-xl flex items-center justify-center border border-white/25">
                  <i data-lucide="shield" class="w-6 h-6 text-white"></i>
                </div>
                <div>
                  <h2 class="text-xl font-bold text-white">Update Your Password</h2>
                  <p class="text-sm text-teal-50 mt-1">Ensure your account security with a strong password</p>
                </div>
              </div>
            </div>

            <form action="change_password.php" method="POST" class="p-6 sm:p-8 space-y-6" novalidate>

              <div class="space-y-2">
                <label for="current_password" class="block text-sm font-semibold text-teal-900">
                  Current Password <span class="text-red-500">*</span>
                </label>
                <div class="relative">
                  <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                    <i data-lucide="lock" class="w-5 h-5 text-teal-600/60"></i>
                  </div>
                  <input type="password" id="current_password" name="current_password" required
                         autocomplete="current-password" placeholder="Enter your current password"
                         class="form-input pl-12 pr-12" />
                  <button type="button" onclick="togglePasswordVisibility('current_password', this)"
                          class="absolute inset-y-0 right-0 pr-4 flex items-center text-teal-600/60 hover:text-teal-600 transition-colors"
                          aria-label="Toggle password visibility">
                    <i data-lucide="eye" class="w-5 h-5"></i>
                  </button>
                </div>
              </div>

              <div class="space-y-2">
                <label for="new_password" class="block text-sm font-semibold text-teal-900">
                  New Password <span class="text-red-500">*</span>
                </label>
                <div class="relative">
                  <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                    <i data-lucide="key" class="w-5 h-5 text-teal-600/60"></i>
                  </div>
                  <input type="password" id="new_password" name="new_password" required minlength="6"
                         autocomplete="new-password" placeholder="Enter new password"
                         oninput="updatePasswordStrength(this.value)"
                         class="form-input pl-12 pr-12" />
                  <button type="button" onclick="togglePasswordVisibility('new_password', this)"
                          class="absolute inset-y-0 right-0 pr-4 flex items-center text-teal-600/60 hover:text-teal-600 transition-colors"
                          aria-label="Toggle password visibility">
                    <i data-lucide="eye" class="w-5 h-5"></i>
                  </button>
                </div>

                <div id="strength-wrapper" class="mt-3 space-y-2 hidden">
                  <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold text-teal-900/70">Password Strength</span>
                    <span id="strength-text" class="text-xs font-bold text-teal-900/60">Weak</span>
                  </div>
                  <div class="h-2 bg-teal-50 rounded-full overflow-hidden">
                    <div id="strength-bar" class="h-full transition-all duration-500 ease-out bg-red-500" style="width: 0%;"></div>
                  </div>
                  <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mt-3">
                    <div class="flex items-center gap-2 req-row" data-req="minLength">
                      <i data-lucide="alert-circle" class="w-4 h-4 text-teal-200 flex-shrink-0"></i>
                      <span class="text-xs text-teal-900/60">At least 8 characters</span>
                    </div>
                    <div class="flex items-center gap-2 req-row" data-req="hasUppercase">
                      <i data-lucide="alert-circle" class="w-4 h-4 text-teal-200 flex-shrink-0"></i>
                      <span class="text-xs text-teal-900/60">One uppercase letter</span>
                    </div>
                    <div class="flex items-center gap-2 req-row" data-req="hasLowercase">
                      <i data-lucide="alert-circle" class="w-4 h-4 text-teal-200 flex-shrink-0"></i>
                      <span class="text-xs text-teal-900/60">One lowercase letter</span>
                    </div>
                    <div class="flex items-center gap-2 req-row" data-req="hasNumber">
                      <i data-lucide="alert-circle" class="w-4 h-4 text-teal-200 flex-shrink-0"></i>
                      <span class="text-xs text-teal-900/60">One number</span>
                    </div>
                    <div class="flex items-center gap-2 req-row" data-req="hasSpecialChar">
                      <i data-lucide="alert-circle" class="w-4 h-4 text-teal-200 flex-shrink-0"></i>
                      <span class="text-xs text-teal-900/60">One special character</span>
                    </div>
                  </div>
                </div>
              </div>

              <div class="space-y-2">
                <label for="confirm_password" class="block text-sm font-semibold text-teal-900">
                  Confirm Password <span class="text-red-500">*</span>
                </label>
                <div class="relative">
                  <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                    <i data-lucide="lock" class="w-5 h-5 text-teal-600/60"></i>
                  </div>
                  <input type="password" id="confirm_password" name="confirm_password" required
                         autocomplete="new-password" placeholder="Re-enter new password"
                         oninput="checkPasswordMatch()"
                         class="form-input pl-12 pr-12" />
                  <button type="button" onclick="togglePasswordVisibility('confirm_password', this)"
                          class="absolute inset-y-0 right-0 pr-4 flex items-center text-teal-600/60 hover:text-teal-600 transition-colors"
                          aria-label="Toggle password visibility">
                    <i data-lucide="eye" class="w-5 h-5"></i>
                  </button>
                  <div id="confirm-check" class="absolute inset-y-0 right-12 flex items-center pointer-events-none hidden">
                    <i data-lucide="check-circle" class="w-5 h-5 text-emerald-500"></i>
                  </div>
                </div>
                <p id="match-error" class="text-sm text-red-500 flex items-center mt-1 hidden">
                  <i data-lucide="alert-circle" class="w-4 h-4 mr-1"></i>
                  Passwords do not match
                </p>
              </div>

              <div class="flex flex-col-reverse sm:flex-row justify-end items-center gap-3 pt-4 border-t border-teal-100">

                <a href="dashboard.php"
                   class="inline-flex items-center justify-center gap-2 w-full sm:w-auto px-6 py-3 rounded-lg font-semibold
                          text-teal-600 bg-white border-2 border-teal-200 hover:border-teal-600 hover:bg-teal-50
                          transition-all duration-200">
                  <i data-lucide="arrow-left" class="w-4 h-4"></i>
                  <span>Back to Dashboard</span>
                </a>

                <button type="submit"
                        class="btn-sheen inline-flex items-center justify-center gap-2 w-full sm:w-auto px-8 py-3 rounded-lg font-semibold text-white
                               bg-teal-600 hover:bg-teal-700 shadow-sm hover:shadow-md transition-all duration-200 cursor-pointer">
                  <i data-lucide="shield" class="w-5 h-5"></i>
                  <span>Change Password</span>
                </button>

              </div>

              <div class="mt-2 p-4 bg-teal-50 rounded-xl border-2 border-teal-100">
                <h3 class="text-sm font-bold text-teal-900 mb-2 flex items-center gap-2">
                  <i data-lucide="shield" class="w-4 h-4 text-teal-600"></i>
                  Security Tips
                </h3>
                <ul class="text-xs text-teal-900/80 space-y-1 list-disc list-inside">
                  <li>Never share your password with anyone</li>
                  <li>Use a unique password for each account</li>
                  <li>Change your password regularly (every 90 days)</li>
                  <li>Avoid using personal information in your password</li>
                </ul>
              </div>

            </form>

          </div>
        </div>

      </main>

      <footer class="bg-teal-900 text-white mt-auto">
        <div class="roofline"></div>
        <div class="px-4 sm:px-6 py-6">
          <div class="max-w-7xl mx-auto text-center">
            <p class="text-xs text-teal-200/70">
              &copy; <?= date('Y') ?>
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

  <!-- BACK TO TOP -->
  <button id="backToTop" aria-label="Back to top"
          class="fixed bottom-6 right-6 z-50 w-12 h-12 rounded-full bg-teal-600 hover:bg-teal-700
                 text-white shadow-lg hover:shadow-xl flex items-center justify-center
                 hover:scale-110 transition-all duration-300">
    <i data-lucide="arrow-up" class="w-5 h-5"></i>
  </button>

  <!-- ============================================================ -->
  <!-- LOGOUT CONFIRMATION MODAL                                    -->
  <!-- ============================================================ -->
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
                       shadow-sm hover:shadow-md transition-all duration-200 flex items-center justify-center gap-2">
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

      // Admin dropdown
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

      // Logout modal
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

        document.addEventListener('keydown', function (e) {
          if (e.key === 'Escape' && !logoutConfirmModal.classList.contains('hidden')) {
            window.closeLogoutModal();
          }
        });
      })();

      // Header frosted-on-scroll
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

      // Back-to-top
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

    });

    // Toggle Password Visibility
    function togglePasswordVisibility(inputId, btn) {
      const input = document.getElementById(inputId);
      if (!input) return;

      const isHidden = input.type === 'password';
      input.type = isHidden ? 'text' : 'password';

      const icon = btn.querySelector('i');
      if (icon && typeof lucide !== 'undefined') {
        icon.setAttribute('data-lucide', isHidden ? 'eye-off' : 'eye');
        lucide.createIcons({ targets: [icon] });
      }
    }

    // Password Strength Meter
    function updatePasswordStrength(password) {
      const wrapper = document.getElementById('strength-wrapper');
      const bar     = document.getElementById('strength-bar');
      const text    = document.getElementById('strength-text');

      if (!wrapper || !bar || !text) return;

      if (password === '') {
        wrapper.classList.add('hidden');
        return;
      }
      wrapper.classList.remove('hidden');

      const reqs = {
        minLength:      password.length >= 8,
        hasUppercase:   /[A-Z]/.test(password),
        hasLowercase:   /[a-z]/.test(password),
        hasNumber:      /[0-9]/.test(password),
        hasSpecialChar: /[!@#$%^&*(),.?":{}|<>]/.test(password),
      };

      const score = Object.values(reqs).filter(Boolean).length;
      const percent = (score / 5) * 100;
      bar.style.width = percent + '%';

      bar.classList.remove('bg-red-500', 'bg-yellow-500', 'bg-blue-500', 'bg-emerald-500');
      text.classList.remove('text-red-500', 'text-yellow-500', 'text-blue-500', 'text-emerald-500');

      if (score <= 2) {
        bar.classList.add('bg-red-500'); text.classList.add('text-red-500'); text.textContent = 'Weak';
      } else if (score <= 3) {
        bar.classList.add('bg-yellow-500'); text.classList.add('text-yellow-500'); text.textContent = 'Fair';
      } else if (score <= 4) {
        bar.classList.add('bg-blue-500'); text.classList.add('text-blue-500'); text.textContent = 'Good';
      } else {
        bar.classList.add('bg-emerald-500'); text.classList.add('text-emerald-500'); text.textContent = 'Strong';
      }

      document.querySelectorAll('.req-row').forEach(function (row) {
        const key = row.getAttribute('data-req');
        const icon = row.querySelector('i');
        const label = row.querySelector('span');
        if (!key || !icon) return;

        const met = !!reqs[key];
        icon.setAttribute('data-lucide', met ? 'check-circle' : 'alert-circle');
        icon.classList.toggle('text-emerald-500', met);
        icon.classList.toggle('text-teal-200', !met);

        if (label) {
          label.classList.toggle('text-emerald-700', met);
          label.classList.toggle('text-teal-900/60', !met);
        }
      });

      if (typeof lucide !== 'undefined') {
        lucide.createIcons({ targets: document.querySelectorAll('.req-row i') });
      }
    }

    // Confirm Password Match Check
    function checkPasswordMatch() {
      const newPwd     = document.getElementById('new_password').value;
      const confirmPwd = document.getElementById('confirm_password').value;
      const checkIcon  = document.getElementById('confirm-check');
      const errorMsg   = document.getElementById('match-error');
      const confirmInp = document.getElementById('confirm_password');

      if (confirmPwd === '') {
        checkIcon.classList.add('hidden');
        errorMsg.classList.add('hidden');
        confirmInp.classList.remove('border-emerald-300', 'border-red-300');
        return;
      }

      if (newPwd === confirmPwd) {
        checkIcon.classList.remove('hidden');
        errorMsg.classList.add('hidden');
        confirmInp.classList.add('border-emerald-300');
        confirmInp.classList.remove('border-red-300');
      } else {
        checkIcon.classList.add('hidden');
        errorMsg.classList.remove('hidden');
        confirmInp.classList.add('border-red-300');
        confirmInp.classList.remove('border-emerald-300');
      }
    }
  </script>

  <script src="../assets/js/index.js"></script>
</body>
</html>
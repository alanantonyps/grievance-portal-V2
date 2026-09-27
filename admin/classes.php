<?php
/**
 * admin/classes.php
 * ---------------------------------------------------------------------------
 * Admin — Class/Semester Management
 * Rajagiri College Grievance Redressal Portal
 *
 * Features:
 *   • View classes grouped by course
 *   • Create / Edit / Delete classes
 *   • Live student count per class
 *   • Click a class card → navigate to student list for that class
 *   • Flash messages auto-dismiss after 3 seconds
 *   • 3-column grid layout for class cards
 *   • Themed logout confirmation modal
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

// ---------------------------------------------------------------------------
// AUTH GUARD
// ---------------------------------------------------------------------------
$sessionRole = isset($_SESSION['role']) ? strtoupper((string) $_SESSION['role']) : '';

if (empty($_SESSION['user_id']) || $sessionRole !== 'ADMIN') {
    header('Location: ../login.php?role=admin');
    exit;
}

$userId = (int) $_SESSION['user_id'];

// ---------------------------------------------------------------------------
// DATABASE CONNECTION
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
// HELPER
// ---------------------------------------------------------------------------
function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------------------------------------------------------------------------
// FETCH ADMIN PROFILE
// ---------------------------------------------------------------------------
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
        error_log('[Classes Admin Profile] ' . $ex->getMessage());
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

// ---------------------------------------------------------------------------
// HANDLE FORM SUBMISSIONS
// ---------------------------------------------------------------------------
$flashSuccess = '';
$flashError   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $conn instanceof mysqli) {

    $action = $_POST['action'] ?? '';

    // -------- CREATE CLASS --------
    if ($action === 'create_class') {
        $courseId  = (int) ($_POST['course_id'] ?? 0);
        $className = trim((string) ($_POST['class_name'] ?? ''));

        if ($courseId <= 0) {
            $flashError = 'Please select a valid course.';
        } elseif ($className === '') {
            $flashError = 'Class name is required.';
        } else {
            try {
                $stmt = $conn->prepare("INSERT INTO classes (course_id, class_name) VALUES (?, ?)");
                $stmt->bind_param('is', $courseId, $className);
                if ($stmt->execute()) {
                    $flashSuccess = 'Class created successfully.';
                } else {
                    $flashError = 'Failed to create class.';
                }
                $stmt->close();
            } catch (Throwable $ex) {
                error_log('[Create Class] ' . $ex->getMessage());
                $flashError = 'A system error occurred while creating the class.';
            }
        }
    }

    // -------- EDIT CLASS --------
    if ($action === 'edit_class') {
        $classId   = (int) ($_POST['class_id'] ?? 0);
        $courseId  = (int) ($_POST['course_id'] ?? 0);
        $className = trim((string) ($_POST['class_name'] ?? ''));

        if ($classId <= 0 || $courseId <= 0) {
            $flashError = 'Invalid class or course selection.';
        } elseif ($className === '') {
            $flashError = 'Class name is required.';
        } else {
            try {
                $stmt = $conn->prepare("UPDATE classes SET class_name = ?, course_id = ? WHERE id = ?");
                $stmt->bind_param('sii', $className, $courseId, $classId);
                if ($stmt->execute()) {
                    $flashSuccess = 'Class updated successfully.';
                } else {
                    $flashError = 'Failed to update class.';
                }
                $stmt->close();
            } catch (Throwable $ex) {
                error_log('[Edit Class] ' . $ex->getMessage());
                $flashError = 'A system error occurred while updating the class.';
            }
        }
    }

    // -------- DELETE CLASS --------
    if ($action === 'delete_class') {
        $classId = (int) ($_POST['class_id'] ?? 0);
        if ($classId > 0) {
            try {
                $chk = $conn->prepare("SELECT COUNT(*) AS c FROM students WHERE class_id = ?");
                $chk->bind_param('i', $classId);
                $chk->execute();
                $cnt = (int) ($chk->get_result()->fetch_assoc()['c'] ?? 0);
                $chk->close();

                if ($cnt > 0) {
                    $flashError = "Cannot delete this class — {$cnt} student(s) are still assigned to it.";
                } else {
                    $stmt = $conn->prepare("DELETE FROM classes WHERE id = ?");
                    $stmt->bind_param('i', $classId);
                    if ($stmt->execute()) {
                        $flashSuccess = 'Class deleted successfully.';
                    } else {
                        $flashError = 'Failed to delete class.';
                    }
                    $stmt->close();
                }
            } catch (Throwable $ex) {
                error_log('[Delete Class] ' . $ex->getMessage());
                $flashError = 'A system error occurred while deleting the class.';
            }
        }
    }

    if ($flashSuccess !== '' || $flashError !== '') {
        $_SESSION['flash_success'] = $flashSuccess;
        $_SESSION['flash_error']   = $flashError;
        header('Location: classes.php');
        exit;
    }
}

if (!empty($_SESSION['flash_success'])) {
    $flashSuccess = (string) $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    $flashError = (string) $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// ---------------------------------------------------------------------------
// FETCH COURSES & GROUPED CLASSES
// ---------------------------------------------------------------------------
$courses = [];
$classesByCourse = [];

if ($conn instanceof mysqli) {
    try {
        $res = $conn->query("SELECT id, course_name FROM courses WHERE status = 'active' OR status IS NULL OR status = '' ORDER BY course_name ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $courses[] = $row;
            }
        }
    } catch (Throwable $ex) {
        error_log('[Fetch Courses] ' . $ex->getMessage());
    }

    try {
        $sql = "SELECT  cl.id, cl.class_name, cl.course_id, co.course_name,
                        COUNT(st.id) AS student_count
                FROM classes cl
                JOIN courses co ON cl.course_id = co.id
                LEFT JOIN students st ON cl.id = st.class_id
                GROUP BY cl.id
                ORDER BY co.course_name ASC, cl.id ASC";

        $res = $conn->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $courseName = $row['course_name'] ?? 'Unassigned';
                if (!isset($classesByCourse[$courseName])) {
                    $classesByCourse[$courseName] = [];
                }
                $classesByCourse[$courseName][] = $row;
            }
        }
    } catch (Throwable $ex) {
        error_log('[Fetch Classes] ' . $ex->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Class/Semester — Admin | Rajagiri College Grievance Portal</title>
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
            fadeInUp: {
              '0%':   { opacity: '0', transform: 'translateY(12px)' },
              '100%': { opacity: '1', transform: 'translateY(0)' }
            },
            dropdownFade: {
              '0%':   { opacity: '0', transform: 'translateY(-8px) scale(0.98)' },
              '100%': { opacity: '1', transform: 'translateY(0) scale(1)' }
            },
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
            flashIn: {
              '0%':   { opacity: '0', transform: 'translateY(-10px)' },
              '100%': { opacity: '1', transform: 'translateY(0)' }
            },
            flashOut: {
              '0%':   { opacity: '1', transform: 'translateY(0)', maxHeight: '200px' },
              '100%': { opacity: '0', transform: 'translateY(-10px)', maxHeight: '0px' }
            },
            shimmer: {
              '0%':   { backgroundPosition: '-200% 0' },
              '100%': { backgroundPosition: '200% 0' }
            },
            pulseRing: {
              '0%':   { boxShadow: '0 0 0 0 rgba(0,110,116,0.45)' },
              '70%':  { boxShadow: '0 0 0 12px rgba(0,110,116,0)' },
              '100%': { boxShadow: '0 0 0 0 rgba(0,110,116,0)' }
            }
          },
          animation: {
            'fade-in-up': 'fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'dropdown':   'dropdownFade 0.2s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'modal-in':   'modalFadeIn 0.25s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'confirm-shake': 'confirmShake 0.5s cubic-bezier(0.16, 1, 0.3, 1)',
            'flash-in':   'flashIn 0.35s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'flash-out':  'flashOut 0.45s cubic-bezier(0.4, 0, 1, 1) forwards',
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
       REVEAL ON SCROLL
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
       SIMPLE CARD HOVER — soft lift + shadow + border color
       ============================================================ */
    .card-soft {
      transition: transform .25s ease, box-shadow .25s ease, border-color .25s ease;
    }
    @media (hover: hover) and (pointer: fine) {
      .card-soft:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 24px -10px rgba(0, 69, 74, 0.18);
      }
    }

    /* Icon — simple background/color swap */
    .card-soft .card-icon {
      transition: background-color .25s ease, color .25s ease;
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
       COURSE SECTION DIVIDER
       ============================================================ */
    .course-divider {
      position: relative;
      height: 1px;
      background: linear-gradient(90deg, #CFE6E7 0%, #CFE6E7 60%, transparent 100%);
    }

    /* ============================================================
       ADD CLASS BUTTON — subtle sheen sweep
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
      .reveal { opacity: 1 !important; transform: none !important; }
      .card-soft:hover { transform: none !important; }
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

        <a href="settings.php" class="sidebar-link sidebar-active-glow group relative w-full h-12 rounded-xl bg-white text-teal-800 shadow-md flex items-center px-3 flex-shrink-0 transition-all">
          <i data-lucide="settings" class="w-6 h-6 flex-shrink-0"></i>
          <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap opacity-0 w-0 overflow-hidden transition-all duration-200">Settings</span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-teal-900 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Settings</span>
        </a>

        <a href="change_password.php" class="sidebar-link group relative w-full h-12 rounded-xl hover:bg-white/10 flex items-center text-white transition-all px-3 flex-shrink-0">
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

              <a href="settings.php" class="flex items-center px-4 py-2.5 text-sm text-teal-900 hover:bg-teal-50 transition-all group">
                <i data-lucide="settings" class="w-4 h-4 mr-3 text-teal-600"></i>
                <span class="font-medium">Settings</span>
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

        <!-- PAGE HEADING + ADD BUTTON -->
        <div class="max-w-6xl mx-auto mb-6 animate-fade-in-up">
          <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">

            <div>
              <div class="flex items-center gap-2 mb-2">
                <span class="inline-flex items-center justify-center w-2 h-2 rounded-full bg-teal-600 animate-pulse-ring"></span>
                <p class="text-xs font-semibold text-teal-600 uppercase tracking-wider">Settings · Class</p>
              </div>
              <h1 class="text-2xl md:text-3xl font-bold text-teal-900 mb-3">
                <span class="heading-underline">Class / Semester</span>
              </h1>
              <nav class="flex flex-wrap items-center gap-2 text-sm text-teal-900/60">
                <a href="dashboard.php" class="inline-flex items-center gap-1 hover:text-teal-600 transition-colors">
                  <i data-lucide="layout-dashboard" class="w-4 h-4"></i>
                  Dashboard
                </a>
                <span class="text-teal-900/30">/</span>
                <a href="settings.php" class="hover:text-teal-600 transition-colors">Settings</a>
                <span class="text-teal-900/30">/</span>
                <span class="text-teal-600 font-semibold">Class</span>
              </nav>
            </div>

            <button type="button" onclick="openClassModal('create')"
                    class="btn-sheen inline-flex items-center justify-center gap-2 px-5 py-3 rounded-lg font-semibold text-white
                           bg-teal-600 hover:bg-teal-700 shadow-sm hover:shadow-md
                           transition-all duration-200 cursor-pointer shrink-0">
              <i data-lucide="plus" class="w-5 h-5"></i>
              <span>Add Class</span>
            </button>

          </div>
        </div>

        <!-- FLASH MESSAGES -->
        <?php if ($flashSuccess !== ''): ?>
          <div id="flashSuccessBox"
               class="max-w-6xl mx-auto mb-6 rounded-xl border-2 border-emerald-200 bg-emerald-50 px-4 py-3 flex items-start gap-2 animate-flash-in overflow-hidden">
            <i data-lucide="check-circle" class="w-5 h-5 text-emerald-600 flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-emerald-800 font-medium"><?= e($flashSuccess) ?></p>
          </div>
        <?php endif; ?>

        <?php if ($flashError !== ''): ?>
          <div id="flashErrorBox"
               class="max-w-6xl mx-auto mb-6 rounded-xl border-2 border-red-200 bg-red-50 px-4 py-3 flex items-start gap-2 animate-flash-in overflow-hidden">
            <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-red-700 font-medium"><?= e($flashError) ?></p>
          </div>
        <?php endif; ?>

        <!-- INFO BANNER -->
        <div class="max-w-6xl mx-auto mb-6 animate-fade-in-up" style="animation-delay: 60ms;">
          <div class="flex items-start gap-3 bg-teal-50 border-2 border-teal-100 rounded-xl px-5 py-3.5">
            <i data-lucide="info" class="w-4 h-4 text-teal-600 flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-teal-900/80">
              Click on a class card to manage its students.
            </p>
          </div>
        </div>

        <div class="max-w-6xl mx-auto animate-fade-in-up" style="animation-delay: 100ms;">

          <?php if (empty($classesByCourse)): ?>

            <div class="bg-white rounded-2xl border-2 border-teal-100 p-12 text-center">
              <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-teal-50 mb-4">
                <i data-lucide="graduation-cap" class="w-8 h-8 text-teal-600"></i>
              </div>
              <h3 class="text-lg font-bold text-teal-900 mb-2">No classes yet</h3>
              <p class="text-sm text-teal-900/60 mb-6">
                Get started by creating your first class or semester.
              </p>
              <button type="button" onclick="openClassModal('create')"
                      class="btn-sheen inline-flex items-center gap-2 px-5 py-3 rounded-lg font-semibold text-white
                             bg-teal-600 hover:bg-teal-700 shadow-sm hover:shadow-md transition-all duration-200">
                <i data-lucide="plus" class="w-4 h-4"></i>
                <span>Add First Class</span>
              </button>
            </div>

          <?php else: ?>

            <?php foreach ($classesByCourse as $courseName => $classes): ?>

              <div class="mb-5 mt-8 first:mt-0">
                <h2 class="text-lg font-bold text-teal-900 flex items-center gap-3">
                  <span class="w-2 h-2 bg-teal-600 rounded-full"></span>
                  <?= e($courseName) ?>
                </h2>
                <div class="course-divider mt-3"></div>
              </div>

              <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-4">

                <?php foreach ($classes as $idx => $class): ?>
                  <?php
                    $studentCount = (int) ($class['student_count'] ?? 0);
                    $classId      = (int) $class['id'];
                    $classUrl     = 'students.php?class_id=' . $classId;
                  ?>
                  <a href="<?= e($classUrl) ?>"
                     title="Manage students of <?= e($class['class_name']) ?>"
                     class="reveal card-soft group flex flex-col p-5 rounded-2xl border-2 border-teal-100 bg-white
                            hover:border-teal-600 no-underline text-inherit"
                     style="transition-delay: <?= min($idx * 60, 300) ?>ms">

                    <div class="flex items-start justify-between gap-3 mb-4">
                      <div class="card-icon inline-flex items-center justify-center w-11 h-11 rounded-xl bg-teal-50 text-teal-600
                                  group-hover:bg-teal-600 group-hover:text-white shrink-0">
                        <i data-lucide="graduation-cap" class="w-5 h-5"></i>
                      </div>

                      <span class="inline-flex items-center gap-1.5 bg-teal-50 text-teal-600 px-2.5 py-1 rounded-full text-xs font-bold">
                        <i data-lucide="users" class="w-3.5 h-3.5"></i>
                        <?= $studentCount ?>
                      </span>
                    </div>

                    <p class="text-[10px] font-semibold text-teal-900/50 uppercase tracking-wider mb-1 truncate">
                      <?= e($class['course_name']) ?>
                    </p>
                    <p class="text-base font-bold text-teal-900 truncate mb-4">
                      <?= e($class['class_name']) ?>
                    </p>

                    <div class="flex items-center justify-between pt-4 border-t border-teal-100 mt-auto">
                      <span class="inline-flex items-center gap-1.5 text-teal-600 font-semibold text-xs">
                        Manage students
                        <i data-lucide="arrow-right" class="w-3.5 h-3.5 group-hover:translate-x-1 transition-transform duration-300"></i>
                      </span>

                      <div class="flex gap-1.5">
                        <button type="button"
                                title="Edit class"
                                onclick='event.preventDefault(); event.stopPropagation(); openClassModal("edit", <?= $classId ?>, <?= (int) $class["course_id"] ?>, <?= json_encode((string) $class["class_name"]) ?>)'
                                class="w-8 h-8 rounded-lg bg-teal-50 hover:bg-teal-600 text-teal-600 hover:text-white
                                       flex items-center justify-center transition-colors duration-200">
                          <i data-lucide="pencil" class="w-3.5 h-3.5"></i>
                        </button>

                        <button type="button"
                                title="Delete class"
                                onclick='event.preventDefault(); event.stopPropagation(); confirmDeleteClass(<?= $classId ?>, <?= json_encode((string) $class["class_name"]) ?>)'
                                class="w-8 h-8 rounded-lg bg-red-50 hover:bg-red-500 text-red-500 hover:text-white
                                       flex items-center justify-center transition-colors duration-200">
                          <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                        </button>
                      </div>
                    </div>

                  </a>
                <?php endforeach; ?>

              </div>

            <?php endforeach; ?>

          <?php endif; ?>

        </div>

      </main>

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

  <!-- BACK TO TOP -->
  <button id="backToTop" aria-label="Back to top"
          class="fixed bottom-6 right-6 z-50 w-12 h-12 rounded-full bg-teal-600 hover:bg-teal-700
                 text-white shadow-lg hover:shadow-xl flex items-center justify-center
                 hover:scale-110 transition-all duration-300">
    <i data-lucide="arrow-up" class="w-5 h-5"></i>
  </button>

  <!-- ============================================================ -->
  <!-- CREATE / EDIT CLASS MODAL                                    -->
  <!-- ============================================================ -->
  <div id="classModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeClassModal()"></div>

    <div class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">

      <div class="flex items-center justify-between px-6 py-4 border-b border-teal-100">
        <h3 id="classModalTitle" class="text-lg font-bold text-teal-900">Create Class</h3>
        <button type="button" onclick="closeClassModal()"
                class="w-8 h-8 rounded-lg hover:bg-teal-50 flex items-center justify-center text-teal-900/60 transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form id="classForm" method="POST" action="classes.php" class="p-6 space-y-5">
        <input type="hidden" name="action" id="formAction" value="create_class" />
        <input type="hidden" name="class_id" id="formClassId" value="" />

        <div class="space-y-2">
          <label for="course_id" class="block text-sm font-semibold text-teal-900">Course</label>
          <select name="course_id" id="course_id" required
                  class="w-full px-4 py-3 border-2 border-teal-100 rounded-lg appearance-none bg-teal-50/40 text-teal-900 font-medium text-sm
                         focus:outline-none focus:border-teal-600 focus:bg-white focus:ring-4 focus:ring-teal-600/10
                         hover:border-teal-200 transition-all">
            <option value="">-- Select Course --</option>
            <?php foreach ($courses as $course): ?>
              <option value="<?= (int) $course['id'] ?>">
                <?= e($course['course_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="space-y-2">
          <label for="class_name" class="block text-sm font-semibold text-teal-900">
            Class Name <span class="text-red-500">*</span>
          </label>
          <input type="text" name="class_name" id="class_name" required
                 placeholder="e.g. SEMESTER I"
                 class="w-full px-4 py-3 border-2 border-teal-100 rounded-lg bg-teal-50/40 text-teal-900 font-medium text-sm
                        placeholder-teal-900/40
                        focus:outline-none focus:border-teal-600 focus:bg-white focus:ring-4 focus:ring-teal-600/10
                        hover:border-teal-200 transition-all" />
        </div>

        <div class="flex justify-center pt-2">
          <button type="submit"
                  class="px-8 py-3 rounded-lg font-semibold text-white
                         bg-teal-600 hover:bg-teal-700 shadow-sm hover:shadow-md
                         transition-all duration-200 cursor-pointer">
            Submit
          </button>
        </div>
      </form>

    </div>
  </div>

  <!-- ============================================================ -->
  <!-- DELETE CONFIRMATION MODAL                                    -->
  <!-- ============================================================ -->
  <div id="deleteConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeDeleteModal()"></div>

    <div id="deleteConfirmPanel"
         class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">

      <div class="h-1.5 w-full bg-teal-600"></div>

      <div class="px-6 pt-6 pb-2 flex flex-col items-center text-center">
        <div class="w-16 h-16 rounded-full flex items-center justify-center mb-4 bg-red-50 ring-4 ring-red-100/60">
          <i data-lucide="trash-2" class="w-8 h-8 text-red-500"></i>
        </div>

        <h3 class="text-xl font-bold text-teal-900 mb-2">Delete Class?</h3>

        <p class="text-sm text-teal-900/70 leading-relaxed">
          You are about to permanently delete
          <span id="deleteClassNameDisplay" class="font-bold text-teal-700 break-words">this class</span>.
        </p>

        <p class="text-xs text-red-500 font-medium mt-3 flex items-center gap-1.5">
          <i data-lucide="alert-triangle" class="w-3.5 h-3.5"></i>
          This action cannot be undone.
        </p>
      </div>

      <div class="px-6 py-5 mt-2 flex flex-col-reverse sm:flex-row gap-3">
        <button type="button" onclick="closeDeleteModal()"
                class="flex-1 px-5 py-3 rounded-lg font-semibold text-teal-900 bg-white border-2 border-teal-200
                       hover:border-teal-600 hover:bg-teal-50 transition-all duration-200">
          Cancel
        </button>

        <button type="button" id="confirmDeleteBtn"
                class="flex-1 px-5 py-3 rounded-lg font-bold text-white bg-teal-600 hover:bg-teal-700
                       shadow-sm hover:shadow-md transition-all duration-200
                       flex items-center justify-center gap-2">
          <i data-lucide="trash-2" class="w-4 h-4"></i>
          <span>Delete</span>
        </button>
      </div>

    </div>
  </div>

  <!-- ============================================================ -->
  <!-- LOGOUT MODAL                                                 -->
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

  <!-- HIDDEN DELETE FORM -->
  <form id="deleteForm" method="POST" action="classes.php" class="hidden">
    <input type="hidden" name="action" value="delete_class" />
    <input type="hidden" name="class_id" id="deleteClassId" value="" />
  </form>

  <script>
    if (typeof lucide !== 'undefined') lucide.createIcons();

    // Auto-dismiss flash messages after 3 seconds
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

    // Sidebar expand/collapse
    (function () {
      const sidebar   = document.getElementById('adminSidebar');
      const main      = document.getElementById('adminMain');
      const toggleBtn = document.getElementById('sidebarToggle');
      if (!sidebar || !main || !toggleBtn) return;

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

    // Admin profile dropdown
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

    // Class Modal
    const classModal      = document.getElementById('classModal');
    const classModalTitle = document.getElementById('classModalTitle');
    const classForm       = document.getElementById('classForm');
    const formAction      = document.getElementById('formAction');
    const formClassId     = document.getElementById('formClassId');
    const courseSelect    = document.getElementById('course_id');
    const classNameInput  = document.getElementById('class_name');

    function openClassModal(mode, classId, courseId, className) {
      classModal.classList.remove('hidden');

      if (mode === 'edit') {
        classModalTitle.textContent = 'Edit Class';
        formAction.value = 'edit_class';
        formClassId.value = classId || '';
        if (courseSelect && courseId) courseSelect.value = String(courseId);
        if (classNameInput) classNameInput.value = className || '';
      } else {
        classModalTitle.textContent = 'Create Class';
        formAction.value = 'create_class';
        formClassId.value = '';
        if (classForm) classForm.reset();
      }

      setTimeout(() => {
        const focusTarget = courseSelect && courseSelect.value ? classNameInput : courseSelect;
        if (focusTarget) focusTarget.focus();
      }, 50);

      if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function closeClassModal() {
      classModal.classList.add('hidden');
      if (classForm) classForm.reset();
      formAction.value = 'create_class';
      formClassId.value = '';
    }

    // Delete Confirmation
    const deleteConfirmModal = document.getElementById('deleteConfirmModal');
    const deleteConfirmPanel = document.getElementById('deleteConfirmPanel');
    const deleteClassNameEl  = document.getElementById('deleteClassNameDisplay');
    const confirmDeleteBtn   = document.getElementById('confirmDeleteBtn');

    let pendingDeleteId = null;

    function confirmDeleteClass(classId, className) {
      pendingDeleteId = classId;
      if (deleteClassNameEl) deleteClassNameEl.textContent = '"' + className + '"';

      deleteConfirmModal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');

      if (deleteConfirmPanel) {
        deleteConfirmPanel.classList.remove('animate-confirm-shake');
        void deleteConfirmPanel.offsetWidth;
        deleteConfirmPanel.classList.add('animate-confirm-shake');
      }

      setTimeout(function () { if (confirmDeleteBtn) confirmDeleteBtn.focus(); }, 80);
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function closeDeleteModal() {
      deleteConfirmModal.classList.add('hidden');
      document.body.classList.remove('overflow-hidden');
      pendingDeleteId = null;
    }

    if (confirmDeleteBtn) {
      confirmDeleteBtn.addEventListener('click', function () {
        if (pendingDeleteId === null) { closeDeleteModal(); return; }
        const delIdInput = document.getElementById('deleteClassId');
        const delForm    = document.getElementById('deleteForm');
        if (delIdInput && delForm) {
          delIdInput.value = String(pendingDeleteId);
          delForm.submit();
        } else {
          closeDeleteModal();
        }
      });
    }

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

    // Escape key handler for class modal
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && classModal && !classModal.classList.contains('hidden')) {
        closeClassModal();
      }
      if (e.key === 'Escape' && deleteConfirmModal && !deleteConfirmModal.classList.contains('hidden')) {
        closeDeleteModal();
      }
    });

    // Scroll Reveal (IntersectionObserver)
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
  </script>

  <script src="../assets/js/index.js"></script>
</body>
</html>
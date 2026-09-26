<?php
/**
 * admin/complaint_report.php
 * ---------------------------------------------------------------------------
 * Admin — Complaint Report (Filtered Grievance Report)
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
$allowedRoles = ['ADMIN', 'MANAGEMENT', 'TEACHER'];

if (empty($_SESSION['user_id']) || !in_array($sessionRole, $allowedRoles, true)) {
    header('Location: ../login.php?role=admin');
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

// HELPERS
function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function isValidDate(string $d): bool
{
    if ($d === '') return false;
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
}

// ADMIN PROFILE
$adminData = [
    'username'        => $_SESSION['username'] ?? 'Admin',
    'name'            => '',
    'email'           => '',
    'profile_picture' => '',
];

if ($conn instanceof mysqli) {
    try {
        $stmt = $conn->prepare(
            "SELECT u.username, ap.name, ap.email, ap.profile_picture
             FROM users u
             LEFT JOIN admin_profiles ap ON ap.user_id = u.id
             WHERE u.id = ? LIMIT 1"
        );
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
        error_log('[Complaint Report Admin Profile] ' . $ex->getMessage());
    }
}

$displayName  = !empty($adminData['name']) ? $adminData['name'] : $adminData['username'];
$displayEmail = !empty($adminData['email']) ? $adminData['email'] : 'admin@rajagiri.edu';

$hasProfilePicture = false;
$profilePictureUrl = '';
if (!empty($adminData['profile_picture'])) {
    $rel = ltrim((string) $adminData['profile_picture'], '/');
    if (file_exists(__DIR__ . '/../' . $rel)) {
        $hasProfilePicture = true;
        $profilePictureUrl = '../' . $rel;
    }
}

// DROPDOWN OPTIONS
$grievanceTypes = [];
$cellMembers    = [];
$courses        = [];
$classes        = [];
$departments    = [];
$designations   = [];

if ($conn instanceof mysqli) {
    try {
        $res = $conn->query("SELECT id, type_name FROM grievance_types WHERE status = 'Active' ORDER BY type_name ASC");
        if ($res) while ($row = $res->fetch_assoc()) $grievanceTypes[] = $row;
    } catch (Throwable $ex) { error_log('[Fetch Grievance Types] ' . $ex->getMessage()); }

    try {
        $res = $conn->query("SELECT id, name FROM cell_members ORDER BY name ASC");
        if ($res) while ($row = $res->fetch_assoc()) $cellMembers[] = $row;
    } catch (Throwable $ex) { error_log('[Fetch Cell Members] ' . $ex->getMessage()); }

    try {
        $res = $conn->query("SELECT id, course_name FROM courses WHERE status = 'Active' ORDER BY course_name ASC");
        if ($res) while ($row = $res->fetch_assoc()) $courses[] = $row;
    } catch (Throwable $ex) { error_log('[Fetch Courses] ' . $ex->getMessage()); }

    try {
        $res = $conn->query("SELECT id, class_name FROM classes WHERE status = 'Active' ORDER BY class_name ASC");
        if ($res) while ($row = $res->fetch_assoc()) $classes[] = $row;
    } catch (Throwable $ex) { error_log('[Fetch Classes] ' . $ex->getMessage()); }

    try {
        $res = $conn->query("SELECT id, department_name FROM departments WHERE status = 'Active' ORDER BY department_name ASC");
        if ($res) while ($row = $res->fetch_assoc()) $departments[] = $row;
    } catch (Throwable $ex) { error_log('[Fetch Departments] ' . $ex->getMessage()); }

    try {
        $res = $conn->query("SELECT id, designation_name FROM designations WHERE status = 'Active' ORDER BY designation_name ASC");
        if ($res) while ($row = $res->fetch_assoc()) $designations[] = $row;
    } catch (Throwable $ex) { error_log('[Fetch Designations] ' . $ex->getMessage()); }
}

// FILTERS
$today       = date('Y-m-d');
$defaultFrom = date('Y-m-d', strtotime('-30 days'));

$filterFrom        = trim((string) ($_GET['from']                ?? $defaultFrom));
$filterTo          = trim((string) ($_GET['to']                  ?? $today));
$filterType        = (int) ($_GET['grievance_type_id']           ?? 0);
$filterMember      = (int) ($_GET['grievance_member_id']         ?? 0);
$filterCourse      = (int) ($_GET['course_id']                   ?? 0);
$filterClass       = (int) ($_GET['class_id']                    ?? 0);
$filterDepartment  = (int) ($_GET['department_id']               ?? 0);
$filterDesignation = (int) ($_GET['designation_id']              ?? 0);
$filterStatus      = trim((string) ($_GET['status']              ?? ''));
$filterSearch      = trim((string) ($_GET['q']                   ?? ''));

$validStatuses = ['', 'Pending', 'In Progress', 'Disposed', 'Closed', 'Reopened'];
if (!in_array($filterStatus, $validStatuses, true)) {
    $filterStatus = '';
}

if (!isValidDate($filterFrom)) $filterFrom = $defaultFrom;
if (!isValidDate($filterTo))   $filterTo   = $today;

if (strtotime($filterFrom) > strtotime($filterTo)) {
    [$filterFrom, $filterTo] = [$filterTo, $filterFrom];
}

$isSubmitted = isset($_GET['submit']) && (string) $_GET['submit'] === '1';

// REPORT QUERY
$rows = [];

if ($isSubmitted && $conn instanceof mysqli) {
    try {
        $sql = "SELECT  g.id,
                        g.grievance_number,
                        g.subject,
                        g.description,
                        g.status,
                        g.created_at,
                        g.updated_at,
                        g.reply_details,
                        g.feedback_details,
                        gt.type_name,
                        COALESCE(s.name, p.name, sf.name, cm.name, ap.name, u.username) AS complainant_name,
                        COALESCE(s.email, p.email, sf.email, cm.email, ap.email, u.username) AS complainant_email,
                        u.role AS complainant_role,
                        assign_cm.name AS attended_by_name
                FROM grievances g
                LEFT JOIN grievance_types gt   ON g.grievance_type_id    = gt.id
                LEFT JOIN users u              ON g.complainant_user_id  = u.id
                LEFT JOIN students s           ON u.id = s.user_id
                LEFT JOIN parents p            ON u.id = p.user_id
                LEFT JOIN staff sf             ON u.id = sf.user_id
                LEFT JOIN cell_members cm      ON u.id = cm.user_id
                LEFT JOIN admin_profiles ap    ON u.id = ap.user_id
                LEFT JOIN cell_members assign_cm ON g.attended_by = assign_cm.id
                WHERE DATE(g.created_at) BETWEEN ? AND ?";

        $params = [$filterFrom, $filterTo];
        $types  = 'ss';

        if ($filterType > 0) {
            $sql .= " AND g.grievance_type_id = ?";
            $params[] = $filterType;
            $types   .= 'i';
        }

        if ($filterMember > 0) {
            $sql .= " AND (g.attended_by = ? OR g.assigned_member_id = ?)";
            $params[] = $filterMember;
            $params[] = $filterMember;
            $types   .= 'ii';
        }

        if ($filterCourse > 0) {
            $sql .= " AND (s.class_id IS NULL OR s.class_id IN (SELECT id FROM classes WHERE course_id = ?))";
            $params[] = $filterCourse;
            $types   .= 'i';
        }

        if ($filterClass > 0) {
            $sql .= " AND (s.class_id IS NULL OR s.class_id = ?)";
            $params[] = $filterClass;
            $types   .= 'i';
        }

        if ($filterDepartment > 0) {
            $sql .= " AND assign_cm.department_id = ?";
            $params[] = $filterDepartment;
            $types   .= 'i';
        }

        if ($filterDesignation > 0) {
            $sql .= " AND assign_cm.designation_id = ?";
            $params[] = $filterDesignation;
            $types   .= 'i';
        }

        if ($filterStatus !== '') {
            $sql .= " AND g.status = ?";
            $params[] = $filterStatus;
            $types   .= 's';
        }

        if ($filterSearch !== '') {
            $sql .= " AND (
                        g.grievance_number LIKE ?
                        OR g.subject LIKE ?
                        OR g.description LIKE ?
                        OR gt.type_name LIKE ?
                        OR COALESCE(s.name, p.name, sf.name, cm.name, ap.name, u.username) LIKE ?
                        OR assign_cm.name LIKE ?
                      )";
            $like = '%' . $filterSearch . '%';
            for ($i = 0; $i < 6; $i++) { $params[] = $like; }
            $types .= 'ssssss';
        }

        $sql .= " ORDER BY g.id DESC";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
            $stmt->close();
        }
    } catch (Throwable $ex) {
        error_log('[Complaint Report] ' . $ex->getMessage());
    }
}

// LOOKUP HELPERS
function findName(array $list, int $id, string $key): string
{
    foreach ($list as $item) {
        if ((int) $item['id'] === $id) return (string) $item[$key];
    }
    return 'All';
}

$summaryType        = $filterType > 0 ? findName($grievanceTypes, $filterType, 'type_name') : 'All';
$summaryMember      = $filterMember > 0 ? findName($cellMembers, $filterMember, 'name') : 'All';
$summaryCourse      = $filterCourse > 0 ? findName($courses, $filterCourse, 'course_name') : 'All';
$summaryClass       = $filterClass > 0 ? findName($classes, $filterClass, 'class_name') : 'All';
$summaryDepartment  = $filterDepartment > 0 ? findName($departments, $filterDepartment, 'department_name') : 'All';
$summaryDesignation = $filterDesignation > 0 ? findName($designations, $filterDesignation, 'designation_name') : 'All';
$summaryStatus      = $filterStatus !== '' ? $filterStatus : 'All';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Complaint Report — Admin | Rajagiri College Grievance Portal</title>
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
          fontFamily: { display:['Coolvetica','Poppins','sans-serif'], sans:['Coolvetica','Poppins','sans-serif'] },
          keyframes: {
            fadeInUp:     { '0%':{opacity:'0',transform:'translateY(12px)'}, '100%':{opacity:'1',transform:'translateY(0)'} },
            dropdownFade: { '0%':{opacity:'0',transform:'translateY(-8px) scale(0.98)'}, '100%':{opacity:'1',transform:'translateY(0) scale(1)'} },
            modalFadeIn:  { '0%':{opacity:'0',transform:'scale(0.96)'}, '100%':{opacity:'1',transform:'scale(1)'} },
            confirmShake: { '0%, 100%':{transform:'translateX(0)'},'20%':{transform:'translateX(-6px)'},'40%':{transform:'translateX(6px)'},'60%':{transform:'translateX(-4px)'},'80%':{transform:'translateX(4px)'} }
          },
          animation: {
            'fade-in-up':    'fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'dropdown':      'dropdownFade 0.2s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'modal-in':      'modalFadeIn 0.25s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'confirm-shake': 'confirmShake 0.5s cubic-bezier(0.16, 1, 0.3, 1)'
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
    #sidebarNav::-webkit-scrollbar { width:6px; }
    #sidebarNav::-webkit-scrollbar-track { background:transparent; }
    #sidebarNav::-webkit-scrollbar-thumb { background:rgba(255,255,255,0.2); border-radius:3px; }
    #sidebarNav::-webkit-scrollbar-thumb:hover { background:rgba(255,255,255,0.35); }

    #print-area { display: none; }
    @media print {
      body > .screen-only,
      body > .screen-only * { display: none !important; }
      .no-print { display: none !important; }
      html, body {
        margin: 0 !important; padding: 0 !important;
        background: #fff !important; color: #000 !important;
        -webkit-print-color-adjust: exact; print-color-adjust: exact;
      }
      #print-area {
        display: block !important; position: static !important;
        width: 100% !important; margin: 0 !important; padding: 0 !important;
        font-family: Arial, Helvetica, sans-serif; font-size: 10px; color: #000;
      }
      @page { size: A4 landscape; margin: 10mm; }
    }
  </style>
</head>

<body class="min-h-screen bg-teal-50/40 text-teal-900 antialiased selection:bg-teal-100 selection:text-teal-700 flex flex-col">

  <div class="screen-only flex min-h-screen flex-1">

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

        <a href="grievance_report.php" class="group relative w-full h-12 rounded-xl bg-white text-teal-800 shadow-md flex items-center px-3 flex-shrink-0 transition-all">
          <i data-lucide="bar-chart-3" class="w-6 h-6 flex-shrink-0"></i>
          <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap opacity-0 w-0 overflow-hidden transition-all duration-200">Grievance Reports</span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-teal-900 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Grievance Reports</span>
        </a>

        <a href="members.php" class="group relative w-full h-12 rounded-xl hover:bg-white/10 flex items-center text-white transition-all px-3 flex-shrink-0">
          <i data-lucide="users" class="w-6 h-6 flex-shrink-0"></i>
          <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap opacity-0 w-0 overflow-hidden transition-all duration-200">Members</span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-teal-900 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Members</span>
        </a>

        <a href="grievances.php" class="group relative w-full h-12 rounded-xl hover:bg-white/10 flex items-center text-white transition-all px-3 flex-shrink-0">
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

    <!-- MAIN -->
    <div id="adminMain" class="flex-1 ml-20 flex flex-col min-h-screen transition-all duration-300">

      <header class="bg-white border-b-2 border-teal-600 shadow-sm sticky top-0 z-30 no-print">
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
                <img src="<?= e($profilePictureUrl) ?>" alt="<?= e($displayName) ?>" class="w-9 h-9 rounded-full object-cover border-2 border-teal-600" />
              <?php else: ?>
                <div class="w-9 h-9 rounded-full bg-teal-600 flex items-center justify-center text-white">
                  <i data-lucide="user" class="w-5 h-5 text-white"></i>
                </div>
              <?php endif; ?>
              <span class="hidden sm:block text-sm font-semibold text-teal-900 max-w-[10rem] truncate"><?= e($displayName) ?></span>
              <i data-lucide="chevron-down" id="admin-chevron" class="w-4 h-4 text-teal-600 transition-transform duration-300"></i>
            </button>

            <div id="admin-dropdown-menu" class="hidden absolute right-0 mt-2 w-64 bg-white rounded-xl shadow-xl border border-teal-100 py-2 z-50 overflow-hidden">
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
                <a href="#" data-logout-trigger="1" id="dropdownLogoutBtn" class="flex items-center px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 transition-all group">
                  <i data-lucide="log-out" class="w-4 h-4 mr-3"></i>
                  <span class="font-medium">Logout</span>
                </a>
              </div>
            </div>
          </div>
        </div>
      </header>

      <main class="flex-1 px-4 sm:px-6 py-6 sm:py-8">

        <!-- Page heading + Print -->
        <div class="max-w-6xl mx-auto mb-8 animate-fade-in-up no-print">
          <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
            <div>
              <div class="flex items-center gap-2 mb-2">
                <span class="inline-flex items-center justify-center w-2 h-2 rounded-full bg-teal-600"></span>
                <p class="text-xs font-semibold text-teal-600 uppercase tracking-wider">Admin Console</p>
              </div>
              <h1 class="text-2xl md:text-3xl lg:text-4xl font-bold text-teal-900 mb-2">Complaint Report</h1>
              <nav class="flex flex-wrap items-center gap-2 text-sm text-teal-900/60">
                <a href="dashboard.php" class="inline-flex items-center gap-1 hover:text-teal-600 transition-colors">
                  <i data-lucide="layout-dashboard" class="w-4 h-4"></i> Dashboard
                </a>
                <span class="text-teal-900/30">/</span>
                <a href="grievance_report.php" class="hover:text-teal-600 transition-colors">Grievance Reports</a>
                <span class="text-teal-900/30">/</span>
                <span class="text-teal-600 font-semibold">Complaint Report</span>
              </nav>
            </div>

            <?php if ($isSubmitted): ?>
              <button type="button" onclick="window.print();" title="Print Report"
                      class="inline-flex items-center justify-center w-11 h-11 rounded-xl bg-teal-600 hover:bg-teal-700
                             text-white shadow-sm hover:shadow-md transition-all duration-200 hover:-translate-y-0.5 active:scale-95">
                <i data-lucide="printer" class="w-5 h-5"></i>
              </button>
            <?php endif; ?>
          </div>
        </div>

        <!-- FILTER FORM -->
        <div class="max-w-6xl mx-auto mb-6 animate-fade-in-up no-print" style="animation-delay: 60ms;">
          <div class="bg-teal-50/60 border-2 border-teal-100 rounded-2xl px-6 py-6 shadow-sm">
            <form method="GET" action="complaint_report.php" class="space-y-5">
              <input type="hidden" name="submit" value="1" />

              <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                <div class="space-y-2">
                  <label for="from" class="block text-sm font-semibold text-teal-900">From Date</label>
                  <input type="date" name="from" id="from" value="<?= e($filterFrom) ?>"
                         class="w-full px-4 py-2.5 border-2 border-teal-100 rounded-lg text-sm bg-white text-teal-900 font-medium
                                focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10
                                hover:border-teal-400 transition-all" />
                </div>
                <div class="space-y-2">
                  <label for="to" class="block text-sm font-semibold text-teal-900">To Date</label>
                  <input type="date" name="to" id="to" value="<?= e($filterTo) ?>"
                         class="w-full px-4 py-2.5 border-2 border-teal-100 rounded-lg text-sm bg-white text-teal-900 font-medium
                                focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10
                                hover:border-teal-400 transition-all" />
                </div>
                <div class="space-y-2">
                  <label for="grievance_type_id" class="block text-sm font-semibold text-teal-900">Grievance Type</label>
                  <select name="grievance_type_id" id="grievance_type_id"
                          class="w-full px-4 py-2.5 border-2 border-teal-100 rounded-lg text-sm bg-white text-teal-900 font-medium
                                 focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10
                                 hover:border-teal-400 transition-all">
                    <option value="0">All</option>
                    <?php foreach ($grievanceTypes as $gt): ?>
                      <option value="<?= (int) $gt['id'] ?>" <?= $filterType === (int) $gt['id'] ? 'selected' : '' ?>>
                        <?= e($gt['type_name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                <div class="space-y-2">
                  <label for="grievance_member_id" class="block text-sm font-semibold text-teal-900">Grievance Member</label>
                  <select name="grievance_member_id" id="grievance_member_id"
                          class="w-full px-4 py-2.5 border-2 border-teal-100 rounded-lg text-sm bg-white text-teal-900 font-medium
                                 focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10
                                 hover:border-teal-400 transition-all">
                    <option value="0">All</option>
                    <?php foreach ($cellMembers as $cm): ?>
                      <option value="<?= (int) $cm['id'] ?>" <?= $filterMember === (int) $cm['id'] ? 'selected' : '' ?>>
                        <?= e($cm['name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="space-y-2">
                  <label for="course_id" class="block text-sm font-semibold text-teal-900">Course</label>
                  <select name="course_id" id="course_id"
                          class="w-full px-4 py-2.5 border-2 border-teal-100 rounded-lg text-sm bg-white text-teal-900 font-medium
                                 focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10
                                 hover:border-teal-400 transition-all">
                    <option value="0">All</option>
                    <?php foreach ($courses as $c): ?>
                      <option value="<?= (int) $c['id'] ?>" <?= $filterCourse === (int) $c['id'] ? 'selected' : '' ?>>
                        <?= e($c['course_name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="space-y-2">
                  <label for="department_id" class="block text-sm font-semibold text-teal-900">Department</label>
                  <select name="department_id" id="department_id"
                          class="w-full px-4 py-2.5 border-2 border-teal-100 rounded-lg text-sm bg-white text-teal-900 font-medium
                                 focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10
                                 hover:border-teal-400 transition-all">
                    <option value="0">All</option>
                    <?php foreach ($departments as $d): ?>
                      <option value="<?= (int) $d['id'] ?>" <?= $filterDepartment === (int) $d['id'] ? 'selected' : '' ?>>
                        <?= e($d['department_name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                <div class="space-y-2">
                  <label for="class_id" class="block text-sm font-semibold text-teal-900">Class</label>
                  <select name="class_id" id="class_id"
                          class="w-full px-4 py-2.5 border-2 border-teal-100 rounded-lg text-sm bg-white text-teal-900 font-medium
                                 focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10
                                 hover:border-teal-400 transition-all">
                    <option value="0">All</option>
                    <?php foreach ($classes as $cl): ?>
                      <option value="<?= (int) $cl['id'] ?>" <?= $filterClass === (int) $cl['id'] ? 'selected' : '' ?>>
                        <?= e($cl['class_name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="space-y-2">
                  <label for="designation_id" class="block text-sm font-semibold text-teal-900">Designation</label>
                  <select name="designation_id" id="designation_id"
                          class="w-full px-4 py-2.5 border-2 border-teal-100 rounded-lg text-sm bg-white text-teal-900 font-medium
                                 focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10
                                 hover:border-teal-400 transition-all">
                    <option value="0">All</option>
                    <?php foreach ($designations as $dg): ?>
                      <option value="<?= (int) $dg['id'] ?>" <?= $filterDesignation === (int) $dg['id'] ? 'selected' : '' ?>>
                        <?= e($dg['designation_name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="space-y-2">
                  <label for="status" class="block text-sm font-semibold text-teal-900">Status</label>
                  <select name="status" id="status"
                          class="w-full px-4 py-2.5 border-2 border-teal-100 rounded-lg text-sm bg-white text-teal-900 font-medium
                                 focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10
                                 hover:border-teal-400 transition-all">
                    <option value="">All</option>
                    <option value="Pending"     <?= $filterStatus === 'Pending'     ? 'selected' : '' ?>>Pending</option>
                    <option value="In Progress" <?= $filterStatus === 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                    <option value="Disposed"    <?= $filterStatus === 'Disposed'    ? 'selected' : '' ?>>Disposed</option>
                    <option value="Closed"      <?= $filterStatus === 'Closed'      ? 'selected' : '' ?>>Closed</option>
                    <option value="Reopened"    <?= $filterStatus === 'Reopened'    ? 'selected' : '' ?>>Reopened</option>
                  </select>
                </div>
              </div>

              <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                <div class="space-y-2 md:col-span-3">
                  <label for="q" class="block text-sm font-semibold text-teal-900">Search</label>
                  <div class="relative">
                    <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-teal-900/40"></i>
                    <input type="text" name="q" id="q" value="<?= e($filterSearch) ?>"
                           placeholder="Grievance number, subject, description, name…"
                           autocomplete="off"
                           class="w-full pl-10 pr-4 py-2.5 border-2 border-teal-100 rounded-lg text-sm bg-white text-teal-900 font-medium
                                  focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10
                                  hover:border-teal-400 transition-all" />
                  </div>
                </div>
              </div>

              <div class="flex flex-wrap justify-center gap-3 pt-3">
                <a href="complaint_report.php"
                   class="px-6 py-3 rounded-lg border-2 border-teal-200 text-teal-900 font-semibold
                          bg-white hover:bg-teal-50 hover:border-teal-600 transition-all duration-200 active:scale-95
                          inline-flex items-center justify-center">
                  Reset
                </a>
                <button type="submit"
                        class="px-10 py-3 rounded-lg
                               bg-teal-600 hover:bg-teal-700
                               text-white font-bold shadow-sm hover:shadow-md
                               transition-all duration-200 hover:-translate-y-0.5 active:scale-95">
                  Submit
                </button>
              </div>
            </form>
          </div>
        </div>

        <!-- ON-SCREEN REPORT -->
        <?php if ($isSubmitted): ?>
          <div class="max-w-6xl mx-auto animate-fade-in-up" style="animation-delay: 120ms;">
            <div class="bg-white border-2 border-teal-100 rounded-t-2xl px-6 py-5">
              <div class="flex items-center justify-between flex-wrap gap-4">
                <div class="flex items-center gap-4">
                  <img src="../public/rcss-logo.webp" alt="RCSS Logo" class="h-12 w-auto" onerror="this.style.display='none'" />
                  <div>
                    <p class="text-sm font-bold text-teal-700 uppercase tracking-wider">Rajagiri College of Social Sciences</p>
                    <p class="text-xs text-teal-900/60">Grievance Redressal Portal</p>
                  </div>
                </div>
                <div class="text-right">
                  <p class="text-sm font-semibold text-teal-900">Date: <?= date('d-m-Y') ?></p>
                </div>
              </div>

              <h2 class="text-xl md:text-2xl font-bold text-teal-900 mt-5 tracking-tight">Complaint Report</h2>

              <div class="mt-4 flex flex-wrap items-center gap-2 text-xs text-teal-900/70">
                <span class="font-semibold text-teal-900">Period:</span>
                <span class="px-2 py-0.5 bg-teal-50 rounded-md border border-teal-100">
                  <?= e(date('d/m/Y', strtotime($filterFrom))) ?> – <?= e(date('d/m/Y', strtotime($filterTo))) ?>
                </span>
                <span class="font-semibold text-teal-900 ml-2">Filters:</span>
                <span class="px-2 py-0.5 bg-teal-50 rounded-md border border-teal-100">Type: <?= e($summaryType) ?></span>
                <span class="px-2 py-0.5 bg-teal-50 rounded-md border border-teal-100">Member: <?= e($summaryMember) ?></span>
                <?php if ($filterCourse > 0): ?>
                  <span class="px-2 py-0.5 bg-teal-50 rounded-md border border-teal-100">Course: <?= e($summaryCourse) ?></span>
                <?php endif; ?>
                <?php if ($filterClass > 0): ?>
                  <span class="px-2 py-0.5 bg-teal-50 rounded-md border border-teal-100">Class: <?= e($summaryClass) ?></span>
                <?php endif; ?>
                <?php if ($filterDepartment > 0): ?>
                  <span class="px-2 py-0.5 bg-teal-50 rounded-md border border-teal-100">Department: <?= e($summaryDepartment) ?></span>
                <?php endif; ?>
                <?php if ($filterDesignation > 0): ?>
                  <span class="px-2 py-0.5 bg-teal-50 rounded-md border border-teal-100">Designation: <?= e($summaryDesignation) ?></span>
                <?php endif; ?>
                <span class="px-2 py-0.5 bg-teal-50 rounded-md border border-teal-100">Status: <?= e($summaryStatus) ?></span>
                <?php if ($filterSearch !== ''): ?>
                  <span class="px-2 py-0.5 bg-teal-50 rounded-md border border-teal-100">Search: <?= e($filterSearch) ?></span>
                <?php endif; ?>
              </div>
            </div>

            <div class="bg-white border-x-2 border-b-2 border-teal-100 rounded-b-2xl overflow-hidden">
              <div class="overflow-x-auto">
                <table class="w-full">
                  <thead>
                    <tr class="bg-teal-600 text-white">
                      <th class="px-3 py-3 text-left text-[11px] font-bold uppercase tracking-wider">Sl.No.</th>
                      <th class="px-3 py-3 text-left text-[11px] font-bold uppercase tracking-wider">Name</th>
                      <th class="px-3 py-3 text-left text-[11px] font-bold uppercase tracking-wider">Date Of Posting</th>
                      <th class="px-3 py-3 text-left text-[11px] font-bold uppercase tracking-wider">Grievance Type</th>
                      <th class="px-3 py-3 text-left text-[11px] font-bold uppercase tracking-wider">Subject</th>
                      <th class="px-3 py-3 text-left text-[11px] font-bold uppercase tracking-wider">Description</th>
                      <th class="px-3 py-3 text-left text-[11px] font-bold uppercase tracking-wider">Status</th>
                      <th class="px-3 py-3 text-left text-[11px] font-bold uppercase tracking-wider">Attended by</th>
                      <th class="px-3 py-3 text-left text-[11px] font-bold uppercase tracking-wider">Reply Details</th>
                      <th class="px-3 py-3 text-left text-[11px] font-bold uppercase tracking-wider">Feedback Details</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-teal-50 bg-white">
                    <?php if (empty($rows)): ?>
                      <tr>
                        <td colspan="10" class="px-4 py-12 text-center text-teal-900/60">No grievances match the selected filters.</td>
                      </tr>
                    <?php else: ?>
                      <?php foreach ($rows as $i => $r): ?>
                        <?php
                          $rDate = !empty($r['created_at']) ? date('Y-m-d', strtotime((string) $r['created_at'])) : '—';
                          $fullDesc     = (string) ($r['description'] ?? '');
                          $descPreview  = mb_strlen($fullDesc, 'UTF-8') > 120 ? mb_substr($fullDesc, 0, 120, 'UTF-8') . '…' : $fullDesc;
                          $replyFull    = (string) ($r['reply_details'] ?? '');
                          $replyPreview = mb_strlen($replyFull, 'UTF-8') > 120 ? mb_substr($replyFull, 0, 120, 'UTF-8') . '…' : $replyFull;
                          $fbFull       = (string) ($r['feedback_details'] ?? '');
                          $fbPreview    = mb_strlen($fbFull, 'UTF-8') > 120 ? mb_substr($fbFull, 0, 120, 'UTF-8') . '…' : $fbFull;
                          $statusCls = match (strtolower((string) ($r['status'] ?? ''))) {
                              'pending'     => 'bg-amber-100 text-amber-800 border-amber-200',
                              'in progress' => 'bg-sky-100 text-sky-800 border-sky-200',
                              'disposed'    => 'bg-emerald-100 text-emerald-800 border-emerald-200',
                              'closed'      => 'bg-slate-100 text-slate-700 border-slate-200',
                              'reopened'    => 'bg-pink-100 text-pink-800 border-pink-200',
                              default       => 'bg-slate-100 text-slate-700 border-slate-200',
                          };
                        ?>
                        <tr class="hover:bg-teal-50/40 transition-colors align-top">
                          <td class="px-3 py-3 text-xs text-teal-900"><?= $i + 1 ?></td>
                          <td class="px-3 py-3 text-xs font-medium text-teal-900 break-words"><?= e($r['complainant_name'] ?? 'N/A') ?></td>
                          <td class="px-3 py-3 text-xs text-teal-900/70 whitespace-nowrap"><?= e($rDate) ?></td>
                          <td class="px-3 py-3 text-xs text-teal-900/80 break-words"><?= e($r['type_name'] ?? '—') ?></td>
                          <td class="px-3 py-3 text-xs text-teal-900/80 break-words"><?= e($r['subject'] ?? '—') ?></td>
                          <td class="px-3 py-3 text-xs text-teal-900/70 break-words" title="<?= e($fullDesc) ?>"><?= e($descPreview !== '' ? $descPreview : '—') ?></td>
                          <td class="px-3 py-3 text-xs whitespace-nowrap">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider border <?= $statusCls ?>">
                              <?= e($r['status'] ?? '—') ?>
                            </span>
                          </td>
                          <td class="px-3 py-3 text-xs text-teal-900/80 break-words"><?= e(($r['attended_by_name'] ?? '') !== '' ? $r['attended_by_name'] : '—') ?></td>
                          <td class="px-3 py-3 text-xs text-teal-900/70 break-words" title="<?= e($replyFull) ?>"><?= e($replyPreview !== '' ? $replyPreview : '—') ?></td>
                          <td class="px-3 py-3 text-xs text-teal-900/70 break-words" title="<?= e($fbFull) ?>"><?= e($fbPreview !== '' ? $fbPreview : '—') ?></td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>

              <?php if (!empty($rows)): ?>
                <div class="px-6 py-4 bg-teal-50/40 border-t border-teal-100 text-xs text-teal-900/70">
                  <p>Total records: <span class="font-semibold text-teal-900"><?= count($rows) ?></span> · Generated on <?= date('d-m-Y H:i') ?></p>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php else: ?>
          <div class="max-w-6xl mx-auto animate-fade-in-up" style="animation-delay: 120ms;">
            <div class="bg-white border-2 border-teal-100 rounded-2xl px-6 py-12 text-center shadow-sm">
              <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-teal-50 mb-4">
                <i data-lucide="file-search" class="w-8 h-8 text-teal-600"></i>
              </div>
              <h3 class="text-lg font-bold text-teal-900">Apply filters to generate the report</h3>
              <p class="text-sm text-teal-900/60 mt-1 max-w-md mx-auto">
                Choose your date range and any additional filters above, then click <strong>Submit</strong> to view the complaint report.
              </p>
            </div>
          </div>
        <?php endif; ?>

      </main>

      <footer class="bg-teal-900 text-white mt-auto no-print">
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

  <!-- PRINT-ONLY AREA -->
  <?php if ($isSubmitted): ?>
  <div id="print-area">
    <table style="width:100%;border-collapse:collapse;margin-bottom:8px;">
      <tr>
        <td style="vertical-align:middle;width:60%;">
          <table style="border-collapse:collapse;">
            <tr>
              <td style="vertical-align:middle;padding-right:10px;">
                <img src="../public/rcss-logo.webp" alt="RCSS" style="height:44px;width:auto;" />
              </td>
              <td style="vertical-align:middle;">
                <div style="font-size:13px;font-weight:bold;color:#006837;text-transform:uppercase;letter-spacing:0.5px;">Rajagiri College of Social Sciences</div>
                <div style="font-size:10px;color:#555;padding-top:2px;">Grievance Redressal Portal</div>
              </td>
            </tr>
          </table>
        </td>
        <td style="vertical-align:middle;text-align:right;width:40%;font-size:11px;font-weight:bold;color:#333;">
          Date: <?= date('d-m-Y') ?>
        </td>
      </tr>
    </table>

    <div style="font-size:18px;font-weight:bold;color:#111;margin:0 0 8px 0;">Complaint Report</div>

    <table style="width:100%;border-collapse:collapse;font-size:10.5px;color:#333;margin-bottom:10px;">
      <tr>
        <td style="padding-bottom:6px;">
          <strong style="color:#111;">Period:</strong>
          <?= e(date('d/m/Y', strtotime($filterFrom))) ?> - <?= e(date('d/m/Y', strtotime($filterTo))) ?>
        </td>
        <td style="padding-bottom:6px;text-align:right;">
          <strong style="color:#111;">Filters:</strong>
          Type: <?= e($summaryType) ?>
          - Member: <?= e($summaryMember) ?>
          <?php if ($filterCourse > 0): ?>- Course: <?= e($summaryCourse) ?><?php endif; ?>
          <?php if ($filterClass > 0): ?>- Class: <?= e($summaryClass) ?><?php endif; ?>
          <?php if ($filterDepartment > 0): ?>- Dept: <?= e($summaryDepartment) ?><?php endif; ?>
          <?php if ($filterDesignation > 0): ?>- Desig: <?= e($summaryDesignation) ?><?php endif; ?>
          - Status: <?= e($summaryStatus) ?>
          <?php if ($filterSearch !== ''): ?>- Search: <?= e($filterSearch) ?><?php endif; ?>
        </td>
      </tr>
    </table>

    <table style="width:100%;border-collapse:collapse;table-layout:fixed;font-size:9px;color:#000;">
      <thead>
        <tr>
          <th style="border:1px solid #333;background:#eaeaea;padding:4px 5px;text-align:left;font-size:9px;font-weight:bold;width:4%;">Sl.No.</th>
          <th style="border:1px solid #333;background:#eaeaea;padding:4px 5px;text-align:left;font-size:9px;font-weight:bold;width:10%;">Name</th>
          <th style="border:1px solid #333;background:#eaeaea;padding:4px 5px;text-align:left;font-size:9px;font-weight:bold;width:8%;">Date Of Posting</th>
          <th style="border:1px solid #333;background:#eaeaea;padding:4px 5px;text-align:left;font-size:9px;font-weight:bold;width:11%;">Grievance Type</th>
          <th style="border:1px solid #333;background:#eaeaea;padding:4px 5px;text-align:left;font-size:9px;font-weight:bold;width:10%;">Subject</th>
          <th style="border:1px solid #333;background:#eaeaea;padding:4px 5px;text-align:left;font-size:9px;font-weight:bold;width:17%;">Description</th>
          <th style="border:1px solid #333;background:#eaeaea;padding:4px 5px;text-align:left;font-size:9px;font-weight:bold;width:7%;">Status</th>
          <th style="border:1px solid #333;background:#eaeaea;padding:4px 5px;text-align:left;font-size:9px;font-weight:bold;width:10%;">Attended by</th>
          <th style="border:1px solid #333;background:#eaeaea;padding:4px 5px;text-align:left;font-size:9px;font-weight:bold;width:11%;">Reply Details</th>
          <th style="border:1px solid #333;background:#eaeaea;padding:4px 5px;text-align:left;font-size:9px;font-weight:bold;width:12%;">Feedback Details</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr>
            <td colspan="10" style="border:1px solid #333;padding:10px;text-align:center;">
              No grievances match the selected filters.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($rows as $i => $r): ?>
            <?php
              $rDate = !empty($r['created_at']) ? date('Y-m-d', strtotime((string) $r['created_at'])) : '—';
              $printDesc  = trim(preg_replace('/\s+/', ' ', (string) ($r['description'] ?? '')));
              $printReply = trim(preg_replace('/\s+/', ' ', (string) ($r['reply_details'] ?? '')));
              $printFb    = trim(preg_replace('/\s+/', ' ', (string) ($r['feedback_details'] ?? '')));
            ?>
            <tr>
              <td style="border:1px solid #333;padding:4px 5px;vertical-align:top;text-align:center;"><?= $i + 1 ?></td>
              <td style="border:1px solid #333;padding:4px 5px;vertical-align:top;word-wrap:break-word;"><?= e($r['complainant_name'] ?? 'N/A') ?></td>
              <td style="border:1px solid #333;padding:4px 5px;vertical-align:top;white-space:nowrap;"><?= e($rDate) ?></td>
              <td style="border:1px solid #333;padding:4px 5px;vertical-align:top;word-wrap:break-word;"><?= e($r['type_name'] ?? '—') ?></td>
              <td style="border:1px solid #333;padding:4px 5px;vertical-align:top;word-wrap:break-word;"><?= e($r['subject'] ?? '—') ?></td>
              <td style="border:1px solid #333;padding:4px 5px;vertical-align:top;word-wrap:break-word;"><?= e($printDesc !== '' ? $printDesc : '—') ?></td>
              <td style="border:1px solid #333;padding:4px 5px;vertical-align:top;text-transform:uppercase;font-weight:bold;"><?= e($r['status'] ?? '—') ?></td>
              <td style="border:1px solid #333;padding:4px 5px;vertical-align:top;word-wrap:break-word;"><?= e(($r['attended_by_name'] ?? '') !== '' ? $r['attended_by_name'] : '—') ?></td>
              <td style="border:1px solid #333;padding:4px 5px;vertical-align:top;word-wrap:break-word;"><?= e($printReply !== '' ? $printReply : '—') ?></td>
              <td style="border:1px solid #333;padding:4px 5px;vertical-align:top;word-wrap:break-word;"><?= e($printFb !== '' ? $printFb : '—') ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>

    <div style="margin-top:10px;font-size:10px;color:#333;">
      <strong>Total records:</strong> <?= count($rows) ?>
      &nbsp;&nbsp;|&nbsp;&nbsp;
      <strong>Generated on:</strong> <?= date('d-m-Y H:i') ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- LOGOUT MODAL -->
  <div id="logoutConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4 no-print">
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
    document.addEventListener('DOMContentLoaded', function () {
      if (typeof lucide !== 'undefined') lucide.createIcons();

      const sidebar = document.getElementById('adminSidebar');
      const main    = document.getElementById('adminMain');

      // Sidebar toggle
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

      // Profile dropdown
      (function () {
        const btn = document.getElementById('admin-dropdown-btn');
        const menu = document.getElementById('admin-dropdown-menu');
        const chevron = document.getElementById('admin-chevron');
        const container = document.getElementById('admin-dropdown-container');
        if (!btn || !menu || !container) return;
        btn.addEventListener('click', function (e) {
          e.stopPropagation();
          const open = !menu.classList.contains('hidden');
          if (open) { menu.classList.add('hidden'); menu.classList.remove('animate-dropdown'); if (chevron) chevron.classList.remove('rotate-180'); }
          else { menu.classList.remove('hidden'); menu.classList.add('animate-dropdown'); if (chevron) chevron.classList.add('rotate-180'); }
        });
        document.addEventListener('click', function (e) { if (!container.contains(e.target)) { menu.classList.add('hidden'); menu.classList.remove('animate-dropdown'); if (chevron) chevron.classList.remove('rotate-180'); } });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { menu.classList.add('hidden'); menu.classList.remove('animate-dropdown'); if (chevron) chevron.classList.remove('rotate-180'); } });
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
          if (logoutConfirmPanel) { logoutConfirmPanel.classList.remove('animate-confirm-shake'); void logoutConfirmPanel.offsetWidth; logoutConfirmPanel.classList.add('animate-confirm-shake'); }
          setTimeout(function () { if (confirmLogoutBtn) confirmLogoutBtn.focus(); }, 80);
          if (typeof lucide !== 'undefined') lucide.createIcons();
        };
        window.closeLogoutModal = function () { logoutConfirmModal.classList.add('hidden'); document.body.classList.remove('overflow-hidden'); };
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
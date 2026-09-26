<?php
/**
 * login.php
 * ---------------------------------------------------------------------------
 * Unified Grievance Redressal Portal Login
 * Rajagiri College of Social Sciences
 *
 * Roles supported (URL param): admin | student | parent | staff | management
 * Usage:  login.php?role=admin
 *
 * DB role ENUM values (UPPERCASE):
 *   ADMIN | STUDENT | PARENT | TEACHER | NON_TEACHING | MANAGEMENT
 *
 * DB status ENUM values:
 *   Pending | Approved | Rejected | Terminated
 *
 * Registration is allowed only for:
 *   STUDENT | PARENT | TEACHER | NON_TEACHING
 *
 * NOTE: The "staff" URL key maps to BOTH the TEACHER and NON_TEACHING DB roles.
 *       Both staff types share a single login portal and dashboard.
 *
 * USERNAME MATCHING:
 *   Username comparison is CASE-SENSITIVE (uses BINARY). The user must enter
 *   the username exactly as it was registered — e.g. 'Ajay' ≠ 'ajay'.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// 1. SESSION
// ---------------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

// ---------------------------------------------------------------------------
// ROLE DASHBOARD MAP (single source of truth for redirection)
// ---------------------------------------------------------------------------
$roleDashboardMap = [
    'ADMIN'        => 'admin/dashboard.php',
    'STUDENT'      => 'student/dashboard.php',
    'PARENT'       => 'parent/dashboard.php',
    'TEACHER'      => 'staff/dashboard.php',
    'NON_TEACHING' => 'staff/dashboard.php',
    'MANAGEMENT'   => 'management/dashboard.php',
];

if (!empty($_SESSION['user_id']) && !empty($_SESSION['role'])) {
    $currentRole = strtoupper((string) $_SESSION['role']);
    $targetPath  = $roleDashboardMap[$currentRole] ?? 'student/dashboard.php';
    header('Location: ' . $targetPath);
    exit;
}

// ---------------------------------------------------------------------------
// 2. DATABASE CONNECTION (mysqli)
// ---------------------------------------------------------------------------
$dbError = null;
$conn    = null;

$dbFile = __DIR__ . '/db_connect.php';

if (!file_exists($dbFile)) {
    $dbError = 'Database configuration file (db_connect.php) is missing.';
} else {
    require_once $dbFile;

    if (!isset($conn) || !($conn instanceof mysqli)) {
        $conn = @new mysqli('localhost', 'root', '', 'grievance_db');
        if ($conn->connect_error) {
            $dbError = 'Unable to connect to the database.';
            $conn    = null;
        } else {
            $conn->set_charset('utf8mb4');
        }
    }

    if ($conn && $conn->connect_errno) {
        $dbError = 'Database connection failed: ' . $conn->connect_error;
        $conn    = null;
    }
}

// ---------------------------------------------------------------------------
// 3. ROLE CONFIG
// ---------------------------------------------------------------------------
$roleConfig = [
    'admin' => [
        'db_role'       => 'ADMIN',
        'title'         => 'Administrator Portal',
        'subtitle'      => 'Core Control & System Governance',
        'icon'          => 'shield-check',
        'placeholder'   => 'Enter your Admin Username',
        'label'         => 'Admin Username',
        'notice'        => 'System Administrator Access Only',
        'supportMail'   => 'admin.support@rajagiri.edu',
        'supportTag'    => 'Tech Support',
        'register_page' => null,
    ],
    'student' => [
        'db_role'       => 'STUDENT',
        'title'         => 'Student Portal',
        'subtitle'      => 'Secure access to your grievance dashboard',
        'icon'          => 'graduation-cap',
        'placeholder'   => 'Enter your Student Username',
        'label'         => 'Student Username',
        'notice'        => 'Enrolled Students Only',
        'supportMail'   => 'student.grievance@rajagiricss.edu',
        'supportTag'    => 'Helpdesk Email',
        'register_page' => 'student_register.php',
    ],
    'parent' => [
        'db_role'       => 'PARENT',
        'title'         => 'Parent Portal',
        'subtitle'      => "Monitor Your Ward's Grievances",
        'icon'          => 'users',
        'placeholder'   => 'Enter your Username',
        'label'         => 'Parent Username',
        'notice'        => 'For Registered Parents & Guardians',
        'supportMail'   => 'parent.help@rajagiricss.edu',
        'supportTag'    => 'Support Email',
        'register_page' => 'parent_register.php',
    ],
    'staff' => [
        'db_role'       => ['TEACHER', 'NON_TEACHING'],
        'title'         => 'Staff Portal',
        'subtitle'      => 'Teaching & Non-Teaching Staff Access',
        'icon'          => 'briefcase',
        'placeholder'   => 'Enter your Staff Username',
        'label'         => 'Staff Username',
        'notice'        => 'Verified Staff Members Only',
        'supportMail'   => 'staff.help@rajagiri.edu',
        'supportTag'    => 'Support Email',
        'register_page' => 'staff_register.php',
    ],
    'management' => [
        'db_role'       => 'MANAGEMENT',
        'title'         => 'Management & Grievance Portal',
        'subtitle'      => 'Committee Oversight & Resolution Management',
        'icon'          => 'layers',
        'placeholder'   => 'Enter your Management Username',
        'label'         => 'Management Username',
        'notice'        => 'Authorized Committee Members Only',
        'supportMail'   => 'grievance.committee@rajagiri.edu',
        'supportTag'    => 'Committee Helpdesk',
        'register_page' => null,
    ],
];

$registrationAllowedRoles = ['student', 'parent', 'staff'];

// ---------------------------------------------------------------------------
// 4. RESOLVE ROLE FROM QUERY STRING
// ---------------------------------------------------------------------------
$roleKey = isset($_GET['role']) ? strtolower(trim((string) $_GET['role'])) : 'student';
if (!array_key_exists($roleKey, $roleConfig)) {
    $roleKey = 'student';
}
$role = $roleConfig[$roleKey];

$canRegister = in_array($roleKey, $registrationAllowedRoles, true);

// ---------------------------------------------------------------------------
// 5. HANDLE POST SUBMISSION
// ---------------------------------------------------------------------------
$errors     = [];
$loginInput = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';
    $sessionToken   = $_SESSION['csrf_token'] ?? '';

    if ($submittedToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        $errors[] = 'Security token expired. Please refresh the page and try again.';
    }

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (isset($_POST['role']) && array_key_exists($_POST['role'], $roleConfig)) {
        $roleKey = $_POST['role'];
    }
    $role = $roleConfig[$roleKey];
    $canRegister = in_array($roleKey, $registrationAllowedRoles, true);

    $allowedDbRoles = is_array($role['db_role'])
        ? array_map('strtoupper', $role['db_role'])
        : [strtoupper((string) $role['db_role'])];

    $loginInput = $username;

    if ($username === '') {
        $errors[] = 'Please enter your username.';
    }
    if ($password === '') {
        $errors[] = 'Please enter your password.';
    }

    if (empty($errors)) {
        if ($conn === null) {
            $errors[] = $dbError ?: 'Database is unavailable. Please try again later.';
        } else {
            try {
                $sql = "SELECT id, username, password, role, status
                        FROM users
                        WHERE BINARY username = ?
                        LIMIT 1";

                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    throw new Exception('Query preparation failed: ' . $conn->error);
                }

                $stmt->bind_param('s', $username);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result === false || $result->num_rows === 0) {
                    $errors[] = 'Invalid username or password.';
                } else {
                    $user = $result->fetch_assoc();

                    $dbUserRole   = strtoupper((string) ($user['role']   ?? ''));
                    $dbUserStatus = ucfirst(strtolower((string) ($user['status'] ?? '')));

                    if (!in_array($dbUserRole, $allowedDbRoles, true)) {
                        $errors[] = 'Invalid username or password for this portal.';
                    } elseif ($dbUserStatus !== 'Approved') {
                        $errors[] = 'Your account status is ' . $dbUserStatus . '. Access is restricted until approved.';
                    } elseif (!password_verify($password, $user['password'])) {
                        $errors[] = 'Invalid username or password.';
                    } else {
                        session_regenerate_id(true);
                        $_SESSION['user_id']    = (int) $user['id'];
                        $_SESSION['username']   = $user['username'];
                        $_SESSION['role']       = $dbUserRole;
                        $_SESSION['logged_in']  = true;
                        $_SESSION['login_time'] = time();

                        $stmt->close();
                        $conn->close();

                        $targetPath = $roleDashboardMap[$dbUserRole] ?? 'student/dashboard.php';
                        header('Location: ' . $targetPath);
                        exit;
                    }
                }
                $stmt->close();
            } catch (Exception $ex) {
                error_log('[Login Error] ' . $ex->getMessage());
                $errors[] = 'A system error occurred while signing you in. Please try again.';
            }
        }
    }
}

// ---------------------------------------------------------------------------
// 6. CSRF TOKEN
// ---------------------------------------------------------------------------
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ---------------------------------------------------------------------------
// 7. HELPER
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
  <meta name="description" content="<?= e($role['title']) ?> — Rajagiri College of Social Sciences Grievance Redressal Portal.">
  <title><?= e($role['title']) ?> — Rajagiri College of Social Sciences</title>
  <link rel="icon" type="image/svg+xml" href="public/favicon.svg" />

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
          }
        }
      }
    }
  </script>

  <style>
    @font-face {
      font-family: 'Coolvetica';
      src: url('assets/fonts/coolvetica-rg.woff2') format('woff2'),
           url('assets/fonts/coolvetica-rg.woff') format('woff');
      font-weight: 400; font-display: swap;
    }
    @font-face {
      font-family: 'Coolvetica';
      src: url('assets/fonts/coolvetica-bold.woff2') format('woff2'),
           url('assets/fonts/coolvetica-bold.woff') format('woff');
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
  </style>
</head>
<body class="min-h-screen bg-white text-teal-900 antialiased selection:bg-teal-100 selection:text-teal-700">

  <!-- Header -->
  <header class="bg-white sticky top-0 z-50 border-b-2 border-teal-600">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex justify-between items-center h-16 md:h-20">
        <a href="index.php" class="flex items-center gap-3 md:gap-4 shrink-0">
          <img src="public/rcss-logo.webp" alt="Rajagiri College of Social Sciences" class="h-8 md:h-10 w-auto" />
          <span class="hidden sm:block logo-divider h-8 md:h-10"></span>
          <span class="hidden sm:flex items-baseline gap-1">
            <span class="text-xl md:text-2xl font-bold text-teal-600 tracking-tight">grievance</span>
            <span class="w-1.5 h-1.5 rounded-full bg-teal-600 mb-1"></span>
          </span>
        </a>

        <a href="index.php" class="group inline-flex items-center gap-2 text-teal-900 hover:text-teal-600 transition-colors text-sm font-medium">
          <i data-lucide="arrow-left" class="w-4 h-4 transition-transform group-hover:-translate-x-1"></i>
          <span class="hidden sm:inline">Back to Home</span>
          <span class="sm:hidden">Home</span>
        </a>
      </div>
    </div>
  </header>

  <!-- Main -->
  <main class="relative">
    <!-- Role strip / breadcrumb -->
    <div class="bg-teal-600 relative overflow-hidden">
      <div class="absolute inset-0 hero-dots opacity-30 pointer-events-none"></div>
      <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 md:py-14">
        <div class="flex flex-col sm:flex-row sm:items-center gap-4 sm:gap-6">
          <div class="inline-flex items-center justify-center w-14 h-14 md:w-16 md:h-16 rounded-2xl bg-white/15 backdrop-blur-sm border border-white/30 shrink-0">
            <i data-lucide="<?= e($role['icon']) ?>" class="w-7 h-7 md:w-8 md:h-8 text-white"></i>
          </div>
          <div>
            <p class="text-white/70 text-xs font-semibold uppercase tracking-wider mb-1">Grievance Portal</p>
            <h1 class="text-2xl md:text-3xl lg:text-4xl font-bold text-white leading-tight"><?= e($role['title']) ?></h1>
            <p class="text-teal-50 text-sm md:text-base mt-1"><?= e($role['subtitle']) ?></p>
          </div>
        </div>
      </div>
    </div>

    <!-- Login form section -->
    <section class="py-10 md:py-16 bg-white">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-1 lg:grid-cols-5 gap-8 lg:gap-12 items-start">

          <!-- LEFT: Form card -->
          <div class="lg:col-span-3 xl:col-span-3">
            <div class="bg-white rounded-2xl border-2 border-teal-100 shadow-sm p-6 sm:p-8 max-w-xl mx-auto lg:mx-0">

              <div class="mb-6">
                <h2 class="text-xl md:text-2xl font-bold text-teal-900 mb-1.5">Log in with your credentials</h2>
                <p class="text-sm text-teal-900/60">Enter the username and password issued to you.</p>
              </div>

              <!-- Error alerts -->
              <?php if (!empty($errors)): ?>
                <div class="mb-5 rounded-xl border-2 border-red-200 bg-red-50 px-4 py-3">
                  <div class="flex items-start gap-2">
                    <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5"></i>
                    <ul class="text-sm text-red-700 space-y-1">
                      <?php foreach ($errors as $err): ?>
                        <li><?= e($err) ?></li>
                      <?php endforeach; ?>
                    </ul>
                  </div>
                </div>
              <?php endif; ?>

              <!-- DB error -->
              <?php if ($dbError && empty($errors)): ?>
                <div class="mb-5 rounded-xl border-2 border-amber-200 bg-amber-50 px-4 py-3">
                  <div class="flex items-start gap-2">
                    <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5"></i>
                    <p class="text-sm text-amber-700"><?= e($dbError) ?></p>
                  </div>
                </div>
              <?php endif; ?>

              <form action="login.php?role=<?= e($roleKey) ?>" method="POST" class="space-y-5" novalidate autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />
                <input type="hidden" name="role" value="<?= e($roleKey) ?>" />

                <!-- Username -->
                <div class="space-y-2">
                  <label for="username" class="block text-sm font-semibold text-teal-900">
                    <?= e($role['label']) ?>
                  </label>
                  <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-teal-600/60">
                      <i data-lucide="user" class="w-5 h-5"></i>
                    </div>
                    <input
                      id="username"
                      name="username"
                      type="text"
                      value="<?= e($loginInput) ?>"
                      placeholder="<?= e($role['placeholder']) ?>"
                      required
                      autocomplete="off"
                      autocapitalize="none"
                      autocorrect="off"
                      spellcheck="false"
                      class="w-full pl-11 pr-4 py-3 border-2 border-teal-100 rounded-lg bg-teal-50/40 text-teal-900 placeholder-teal-900/40 text-sm font-medium
                             focus:outline-none focus:border-teal-600 focus:bg-white focus:ring-4 focus:ring-teal-600/10
                             hover:border-teal-200 transition-all duration-200"
                    />
                  </div>
                </div>

                <!-- Password -->
                <div class="space-y-2">
                  <label for="password" class="block text-sm font-semibold text-teal-900">Password</label>
                  <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-teal-600/60">
                      <i data-lucide="lock" class="w-5 h-5"></i>
                    </div>
                    <input
                      id="password"
                      name="password"
                      type="password"
                      placeholder="Enter your password"
                      required
                      autocomplete="current-password"
                      class="w-full pl-11 pr-12 py-3 border-2 border-teal-100 rounded-lg bg-teal-50/40 text-teal-900 placeholder-teal-900/40 text-sm font-medium
                             focus:outline-none focus:border-teal-600 focus:bg-white focus:ring-4 focus:ring-teal-600/10
                             hover:border-teal-200 transition-all duration-200"
                    />
                    <button
                      type="button"
                      id="togglePassword"
                      aria-label="Toggle password visibility"
                      class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-teal-600/60 hover:text-teal-600 transition-colors">
                      <i data-lucide="eye" class="w-5 h-5" id="eyeIcon"></i>
                    </button>
                  </div>
                </div>

                <!-- Forgot password -->
                <div class="flex justify-end">
                  <a href="forgot-password.php?role=<?= e($roleKey) ?>"
                     class="group/link inline-flex items-center gap-1 text-xs md:text-sm font-semibold text-teal-600 hover:text-teal-700 transition-colors">
                    <span>Forgotten password?</span>
                    <i data-lucide="arrow-right" class="w-3.5 h-3.5 transition-transform group-hover/link:translate-x-0.5"></i>
                  </a>
                </div>

                <!-- Submit -->
                <button type="submit"
                        class="w-full inline-flex items-center justify-center gap-2 bg-teal-600 text-white px-6 py-3.5 rounded-lg text-sm font-semibold
                               hover:bg-teal-700 transition-colors duration-200 cursor-pointer">
                  <span>Log in</span>
                  <i data-lucide="arrow-right" class="w-4 h-4"></i>
                </button>
              </form>

              <!-- Register CTA (only for eligible roles) -->
              <?php if ($canRegister && !empty($role['register_page'])): ?>
                <div class="mt-6 pt-6 border-t border-teal-100">
                  <p class="text-sm text-teal-900/60 text-center mb-3">Don't have an account yet?</p>
                  <a href="<?= e($role['register_page']) ?>?role=<?= e($roleKey) ?>"
                     class="group/register inline-flex w-full items-center justify-center gap-2 px-5 py-3 rounded-lg
                            bg-white border-2 border-teal-600 text-teal-600 font-semibold
                            hover:bg-teal-600 hover:text-white
                            transition-all duration-200">
                    <i data-lucide="user-plus" class="w-4 h-4"></i>
                    <span>Create an account</span>
                    <i data-lucide="arrow-right" class="w-4 h-4 transition-transform group-hover/register:translate-x-1"></i>
                  </a>
                  <p class="text-[11px] text-teal-900/50 mt-3 flex items-center justify-center gap-1.5">
                    <i data-lucide="shield-check" class="w-3 h-3"></i>
                    <span>Quick registration — approval within 24 hours</span>
                  </p>
                </div>
              <?php endif; ?>

              <!-- Notice -->
              <div class="mt-5 text-center">
                <p class="inline-flex items-center justify-center gap-2 text-xs md:text-sm text-teal-900/60">
                  <i data-lucide="key-round" class="w-4 h-4 text-teal-600"></i>
                  <span><?= e($role['notice']) ?></span>
                </p>
              </div>

            </div>
          </div>

          <!-- RIGHT: Info panel -->
          <aside class="lg:col-span-2 xl:col-span-2">
            <div class="bg-teal-50 rounded-2xl border-2 border-teal-100 p-6 sm:p-8">

              <h3 class="text-lg font-bold text-teal-900 mb-5">Need help signing in?</h3>

              <div class="space-y-5">
                <div class="flex gap-4">
                  <div class="shrink-0 inline-flex items-center justify-center w-11 h-11 rounded-xl bg-white text-teal-600">
                    <i data-lucide="mail" class="w-5 h-5"></i>
                  </div>
                  <div class="min-w-0">
                    <p class="text-xs text-teal-900/60 font-semibold uppercase tracking-wide mb-1"><?= e($role['supportTag']) ?></p>
                    <p class="text-sm font-semibold text-teal-900 break-all leading-tight"><?= e($role['supportMail']) ?></p>
                  </div>
                </div>

                <div class="flex gap-4">
                  <div class="shrink-0 inline-flex items-center justify-center w-11 h-11 rounded-xl bg-white text-teal-600">
                    <i data-lucide="phone" class="w-5 h-5"></i>
                  </div>
                  <div class="min-w-0">
                    <p class="text-xs text-teal-900/60 font-semibold uppercase tracking-wide mb-1">Helpline</p>
                    <p class="text-sm font-semibold text-teal-900">+91 484 XXX XXXX</p>
                    <p class="text-xs text-teal-900/60 mt-0.5">Mon–Fri, 9 AM – 5 PM</p>
                  </div>
                </div>

                <div class="flex gap-4">
                  <div class="shrink-0 inline-flex items-center justify-center w-11 h-11 rounded-xl bg-white text-teal-600">
                    <i data-lucide="shield-check" class="w-5 h-5"></i>
                  </div>
                  <div class="min-w-0">
                    <p class="text-xs text-teal-900/60 font-semibold uppercase tracking-wide mb-1">Privacy</p>
                    <p class="text-sm text-teal-900/80 leading-relaxed">Your credentials and identity are protected with encryption at every step.</p>
                  </div>
                </div>
              </div>

              <div class="mt-6 pt-6 border-t border-teal-200/60">
                <p class="text-xs text-teal-900/60 leading-relaxed">
                  Usernames are <strong class="text-teal-900">case-sensitive</strong>. Please enter yours exactly as it was registered.
                </p>
              </div>

            </div>
          </aside>

        </div>
      </div>
    </section>
  </main>

  <!-- Footer -->
  <footer class="bg-teal-900 text-white mt-8">
    <div class="roofline"></div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
      <div class="grid grid-cols-1 md:grid-cols-3 gap-8 mb-8">
        <div>
          <div class="flex items-center gap-3 mb-4 bg-white rounded-lg px-3 py-2 w-fit">
            <img src="public/rcss-logo.webp" alt="Rajagiri College of Social Sciences" class="h-8 w-auto" />
          </div>
          <p class="text-teal-100/80 text-sm leading-relaxed max-w-xs">
            Committed to fairness, transparency and prompt grievance redressal.
          </p>
        </div>
        <div>
          <h4 class="font-bold mb-4 text-sm uppercase tracking-wide text-teal-200">Quick links</h4>
          <ul class="space-y-2.5 text-sm">
            <li><a href="login.php?role=student" class="text-teal-100/80 hover:text-white transition-colors">Student</a></li>
            <li><a href="login.php?role=parent" class="text-teal-100/80 hover:text-white transition-colors">Parent</a></li>
            <li><a href="login.php?role=staff" class="text-teal-100/80 hover:text-white transition-colors">Staff</a></li>
            <li><a href="login.php?role=management" class="text-teal-100/80 hover:text-white transition-colors">Grievance Member</a></li>
          </ul>
        </div>
        <div>
          <h4 class="font-bold mb-4 text-sm uppercase tracking-wide text-teal-200">Contact</h4>
          <ul class="space-y-3 text-sm text-teal-100/80">
            <li class="flex items-center gap-2">
              <i data-lucide="mail" class="w-4 h-4 text-teal-300"></i>
              <span>grievance@rajagiricss.edu</span>
            </li>
            <li class="flex items-center gap-2">
              <i data-lucide="phone" class="w-4 h-4 text-teal-300"></i>
              <span>+91 484 XXX XXXX</span>
            </li>
            <li class="flex items-center gap-2">
              <i data-lucide="map-pin" class="w-4 h-4 text-teal-300"></i>
              <span>Aluva, Kochi, Kerala</span>
            </li>
          </ul>
        </div>
      </div>
      <div class="border-t border-white/10 pt-6 text-center text-xs text-teal-200/70">
        <p>&copy; <?php echo date('Y'); ?> Rajagiri College of Social Sciences. All rights reserved.</p>
      </div>
    </div>
  </footer>

  <script>
    if (typeof lucide !== 'undefined') lucide.createIcons();

    (function () {
      const toggleBtn = document.getElementById('togglePassword');
      const pwdInput  = document.getElementById('password');
      const eyeIcon   = document.getElementById('eyeIcon');
      if (!toggleBtn || !pwdInput || !eyeIcon) return;

      toggleBtn.addEventListener('click', function () {
        const isHidden = pwdInput.type === 'password';
        pwdInput.type = isHidden ? 'text' : 'password';
        eyeIcon.setAttribute('data-lucide', isHidden ? 'eye-off' : 'eye');
        if (typeof lucide !== 'undefined') lucide.createIcons({ targets: [eyeIcon] });
      });
    })();
  </script>
</body>
</html>
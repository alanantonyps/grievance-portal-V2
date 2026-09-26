<?php
/**
 * staff_register.php
 * ---------------------------------------------------------------------------
 * Staff Self-Registration — Rajagiri College Grievance Redressal Portal
 *
 * Flow:
 *   1. Fetch active departments & designations for the dropdowns
 *   2. Validate required fields + duplicate username/email/employee_id checks
 *   3. Insert into users (role = TEACHER or NON_TEACHING, status = Pending)
 *   4. Insert corresponding profile into staff table
 *   5. Redirect to login.php?role=staff&registered=success
 *
 * Database (grievance_db):
 *   users         : id, username, password, role, status
 *   staff         : id, user_id, name, gender, email, contact_number,
 *                   whatsapp_number, address, employee_id,
 *                   department_id, designation_id, staff_type, profile_image
 *   departments   : id, department_name, description, status
 *   designations  : id, designation_name, post_occupied (Teaching|Non Teaching)
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
// DATABASE CONNECTION
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
// HELPERS
// ---------------------------------------------------------------------------
function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------------------------------------------------------------------------
// CSRF TOKEN
// ---------------------------------------------------------------------------
if (empty($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Throwable $ex) {
        $_SESSION['csrf_token'] = md5(uniqid((string) mt_rand(), true));
    }
}
$csrfToken = (string) $_SESSION['csrf_token'];

// ---------------------------------------------------------------------------
// FETCH ACTIVE DEPARTMENTS & DESIGNATIONS
// ---------------------------------------------------------------------------
$departments  = [];
$designations = [];

if ($conn instanceof mysqli) {
    try {
        $res = $conn->query("SELECT id, department_name FROM departments WHERE status = 'Active' ORDER BY department_name ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) $departments[] = $row;
        }
    } catch (Throwable $ex) {
        error_log('[Fetch Departments] ' . $ex->getMessage());
    }

    try {
        $res = $conn->query("SELECT id, designation_name FROM designations WHERE status = 'Active' ORDER BY designation_name ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) $designations[] = $row;
        }
    } catch (Throwable $ex) {
        error_log('[Fetch Designations] ' . $ex->getMessage());
    }
}

// ---------------------------------------------------------------------------
// HANDLE FORM SUBMISSION
// ---------------------------------------------------------------------------
$errors   = [];
$formData = [
    'name'           => '',
    'gender'         => '',
    'department_id'  => '',
    'designation_id' => '',
    'email'          => '',
    'contact_number' => '',
    'employee_id'    => '',
    'password'       => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    if ($submittedToken === '' || !hash_equals($csrfToken, $submittedToken)) {
        $errors[] = 'Security token expired. Please refresh the page and try again.';
    }

    $formData['name']           = trim((string) ($_POST['name']           ?? ''));
    $formData['gender']         = trim((string) ($_POST['gender']         ?? ''));
    $formData['department_id']  = trim((string) ($_POST['department_id']  ?? ''));
    $formData['designation_id'] = trim((string) ($_POST['designation_id'] ?? ''));
    $formData['email']          = trim((string) ($_POST['email']          ?? ''));
    $formData['contact_number'] = trim((string) ($_POST['contact_number'] ?? ''));
    $formData['employee_id']    = trim((string) ($_POST['employee_id']    ?? ''));
    $formData['password']       = (string)       ($_POST['password']       ?? '');

    if ($formData['name'] === '') {
        $errors[] = 'Staff Name is required.';
    }
    if (!in_array($formData['gender'], ['Male', 'Female', 'Other'], true)) {
        $errors[] = 'Please select a valid Gender.';
    }
    if ($formData['department_id'] === '' || !ctype_digit($formData['department_id'])) {
        $errors[] = 'Please select a Department.';
    }
    if ($formData['designation_id'] === '' || !ctype_digit($formData['designation_id'])) {
        $errors[] = 'Please select a Designation.';
    }
    if ($formData['email'] === '') {
        $errors[] = 'Email is required.';
    } elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if ($formData['contact_number'] === '') {
        $errors[] = 'Contact Number is required.';
    } elseif (!preg_match('/^[0-9]{10}$/', $formData['contact_number'])) {
        $errors[] = 'Contact Number must be exactly 10 digits.';
    }
    if ($formData['password'] === '') {
        $errors[] = 'Password is required.';
    } elseif (strlen($formData['password']) < 6) {
        $errors[] = 'Password must be at least 6 characters long.';
    }

    if (empty($errors) && $conn instanceof mysqli) {
        try {
            $chk = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            if ($chk) {
                $chk->bind_param('s', $formData['name']);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0) {
                    $errors[] = 'This Staff Name is already registered as a username. Please use a different name or contact the administrator.';
                }
                $chk->close();
            }

            $chk2 = $conn->prepare("SELECT id FROM staff WHERE email = ? LIMIT 1");
            if ($chk2) {
                $chk2->bind_param('s', $formData['email']);
                $chk2->execute();
                if ($chk2->get_result()->num_rows > 0) {
                    $errors[] = 'This email is already in use by another staff member.';
                }
                $chk2->close();
            }

            if ($formData['employee_id'] !== '') {
                $chk3 = $conn->prepare("SELECT id FROM staff WHERE employee_id = ? LIMIT 1");
                if ($chk3) {
                    $chk3->bind_param('s', $formData['employee_id']);
                    $chk3->execute();
                    if ($chk3->get_result()->num_rows > 0) {
                        $errors[] = 'This Employee ID is already registered.';
                    }
                    $chk3->close();
                }
            }
        } catch (Throwable $ex) {
            error_log('[Staff Duplicate Check] ' . $ex->getMessage());
            $errors[] = 'A system error occurred while validating your details.';
        }
    }

    if (empty($errors) && $conn instanceof mysqli) {
        try {
            $conn->begin_transaction();

            $departmentId  = (int) $formData['department_id'];
            $designationId = (int) $formData['designation_id'];

            $chkDept = $conn->prepare("SELECT id FROM departments WHERE id = ? AND status = 'Active' LIMIT 1");
            if ($chkDept) {
                $chkDept->bind_param('i', $departmentId);
                $chkDept->execute();
                if ($chkDept->get_result()->num_rows === 0) {
                    $chkDept->close();
                    throw new Exception('The selected Department is not available.');
                }
                $chkDept->close();
            }

            $chkDesig = $conn->prepare("SELECT id, post_occupied FROM designations WHERE id = ? AND status = 'Active' LIMIT 1");
            if (!$chkDesig) throw new Exception('Unable to verify designation.');
            $chkDesig->bind_param('i', $designationId);
            $chkDesig->execute();
            $desigRes = $chkDesig->get_result();

            if ($desigRes->num_rows === 0) {
                $chkDesig->close();
                throw new Exception('The selected Designation is not available.');
            }

            $desigRow     = $desigRes->fetch_assoc();
            $postOccupied = (string) ($desigRow['post_occupied'] ?? 'Teaching');
            $chkDesig->close();

            if ($postOccupied === 'Teaching') {
                $userRole  = 'TEACHER';
                $staffType = 'TEACHING';
            } else {
                $userRole  = 'NON_TEACHING';
                $staffType = 'NON_TEACHING';
            }

            $hash = password_hash($formData['password'], PASSWORD_BCRYPT);

            $stmtU = $conn->prepare("INSERT INTO users (username, password, role, status) VALUES (?, ?, ?, 'Pending')");
            if (!$stmtU) throw new Exception('Failed to prepare user insert.');
            $stmtU->bind_param('sss', $formData['name'], $hash, $userRole);
            $stmtU->execute();
            $newUserId = (int) $conn->insert_id;
            $stmtU->close();

            $employeeId = ($formData['employee_id'] !== '') ? $formData['employee_id'] : null;

            $stmtS = $conn->prepare("INSERT INTO staff
                                        (user_id, name, gender, email, contact_number,
                                         employee_id, department_id, designation_id, staff_type)
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmtS) throw new Exception('Failed to prepare staff insert.');

            $stmtS->bind_param(
                'isssssiis',
                $newUserId,
                $formData['name'],
                $formData['gender'],
                $formData['email'],
                $formData['contact_number'],
                $employeeId,
                $departmentId,
                $designationId,
                $staffType
            );
            $stmtS->execute();
            $stmtS->close();

            $conn->commit();

            $_SESSION['flash_success'] = 'Your registration request has been submitted! Please wait for admin approval before logging in.';
            header('Location: login.php?role=staff&registered=success');
            exit;

        } catch (Throwable $ex) {
            if ($conn instanceof mysqli) $conn->rollback();
            error_log('[Staff Register] ' . $ex->getMessage());
            $errors[] = $ex->getMessage() ?: 'A system error occurred while creating your account. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Staff Registration — Rajagiri College of Social Sciences</title>
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

    .form-input,
    .form-select,
    .form-textarea {
      width: 100%;
      padding: 0.75rem 1rem;
      border: 2px solid #CFE6E7;
      border-radius: 0.5rem;
      background-color: rgba(234, 244, 244, 0.4);
      color: #003134;
      font-size: 0.875rem;
      font-weight: 500;
      transition: all 0.2s ease;
    }
    .form-input::placeholder,
    .form-textarea::placeholder { color: rgba(0, 49, 52, 0.4); }
    .form-input:hover,
    .form-select:hover,
    .form-textarea:hover { border-color: #9FCDCF; }
    .form-input:focus,
    .form-select:focus,
    .form-textarea:focus {
      outline: none;
      border-color: #006E74;
      background-color: #fff;
      box-shadow: 0 0 0 4px rgba(0, 110, 116, 0.1);
    }
    .form-textarea { resize: none; }
  </style>
</head>
<body class="min-h-screen bg-white text-teal-900 antialiased selection:bg-teal-100 selection:text-teal-700 flex flex-col">

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
        <a href="login.php?role=staff" class="group inline-flex items-center gap-2 text-teal-900 hover:text-teal-600 transition-colors text-sm font-medium">
          <i data-lucide="arrow-left" class="w-4 h-4 transition-transform group-hover:-translate-x-1"></i>
          <span class="hidden sm:inline">Back to Login</span>
          <span class="sm:hidden">Login</span>
        </a>
      </div>
    </div>
  </header>

  <!-- Hero strip -->
  <section class="relative overflow-hidden bg-teal-600">
    <div class="absolute inset-0 hero-dots opacity-30 pointer-events-none"></div>
    <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 md:py-14">
      <div class="flex flex-col sm:flex-row sm:items-center gap-4 sm:gap-6">
        <div class="inline-flex items-center justify-center w-14 h-14 md:w-16 md:h-16 rounded-2xl bg-white/15 backdrop-blur-sm border border-white/30 shrink-0">
          <i data-lucide="briefcase" class="w-7 h-7 md:w-8 md:h-8 text-white"></i>
        </div>
        <div>
          <p class="text-white/70 text-xs font-semibold uppercase tracking-wider mb-1">Create Account</p>
          <h1 class="text-2xl md:text-3xl lg:text-4xl font-bold text-white leading-tight">Staff Registration</h1>
          <p class="text-teal-50 text-sm md:text-base mt-1">For teaching & non-teaching staff members of the college.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- Form section -->
  <main class="flex-1 py-10 md:py-14 bg-white">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">

      <div class="bg-white rounded-2xl border-2 border-teal-100 shadow-sm p-6 sm:p-8 md:p-10">

        <!-- Intro -->
        <div class="mb-7">
          <h2 class="text-xl md:text-2xl font-bold text-teal-900 mb-1.5">Your details</h2>
          <p class="text-sm text-teal-900/60">All fields marked with <span class="text-red-500 font-semibold">*</span> are required. Your Staff Name will be used as your username.</p>
        </div>

        <!-- Errors -->
        <?php if (!empty($errors)): ?>
          <div class="mb-6 rounded-xl border-2 border-red-200 bg-red-50 px-4 py-3">
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

        <?php if ($dbError && empty($errors)): ?>
          <div class="mb-6 rounded-xl border-2 border-amber-200 bg-amber-50 px-4 py-3">
            <div class="flex items-start gap-2">
              <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5"></i>
              <p class="text-sm text-amber-700"><?= e($dbError) ?></p>
            </div>
          </div>
        <?php endif; ?>

        <form method="POST" action="staff_register.php" class="space-y-5" novalidate>
          <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />

          <!-- Row 1 -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div class="space-y-2">
              <label for="name" class="block text-sm font-semibold text-teal-900">
                Staff Name <span class="text-red-500">*</span>
              </label>
              <input type="text" name="name" id="name" required
                     value="<?= e($formData['name']) ?>"
                     placeholder="Full name as per records"
                     class="form-input" />
              <p class="text-xs text-teal-900/60 font-medium">
                <i data-lucide="info" class="inline w-3 h-3 -mt-0.5"></i>
                Staff Name will be used as your username
              </p>
            </div>

            <div class="space-y-2">
              <label for="gender" class="block text-sm font-semibold text-teal-900">
                Gender <span class="text-red-500">*</span>
              </label>
              <select name="gender" id="gender" required class="form-select">
                <option value="">Select gender</option>
                <option value="Male"   <?= $formData['gender'] === 'Male'   ? 'selected' : '' ?>>Male</option>
                <option value="Female" <?= $formData['gender'] === 'Female' ? 'selected' : '' ?>>Female</option>
                <option value="Other"  <?= $formData['gender'] === 'Other'  ? 'selected' : '' ?>>Other</option>
              </select>
            </div>
          </div>

          <!-- Row 2 -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div class="space-y-2">
              <label for="department_id" class="block text-sm font-semibold text-teal-900">
                Department <span class="text-red-500">*</span>
              </label>
              <select name="department_id" id="department_id" required class="form-select">
                <option value="">Select department</option>
                <?php foreach ($departments as $dept): ?>
                  <option value="<?= (int) $dept['id'] ?>" <?= (string) $formData['department_id'] === (string) $dept['id'] ? 'selected' : '' ?>>
                    <?= e($dept['department_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="space-y-2">
              <label for="designation_id" class="block text-sm font-semibold text-teal-900">
                Designation <span class="text-red-500">*</span>
              </label>
              <select name="designation_id" id="designation_id" required class="form-select">
                <option value="">Select designation</option>
                <?php foreach ($designations as $desig): ?>
                  <option value="<?= (int) $desig['id'] ?>" <?= (string) $formData['designation_id'] === (string) $desig['id'] ? 'selected' : '' ?>>
                    <?= e($desig['designation_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <!-- Row 3 -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div class="space-y-2">
              <label for="email" class="block text-sm font-semibold text-teal-900">
                Email <span class="text-red-500">*</span>
              </label>
              <input type="email" name="email" id="email" required
                     value="<?= e($formData['email']) ?>"
                     placeholder="name@example.com"
                     class="form-input" />
            </div>

            <div class="space-y-2">
              <label for="contact_number" class="block text-sm font-semibold text-teal-900">
                Contact Number <span class="text-red-500">*</span>
              </label>
              <input type="tel" name="contact_number" id="contact_number" required
                     inputmode="numeric" pattern="[0-9]{10}" minlength="10" maxlength="10"
                     value="<?= e($formData['contact_number']) ?>"
                     placeholder="10-digit mobile number"
                     class="form-input" />
            </div>
          </div>

          <!-- Row 4 -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div class="space-y-2">
              <label for="employee_id" class="block text-sm font-semibold text-teal-900">
                Employee ID <span class="text-teal-900/40 text-xs font-normal">(optional)</span>
              </label>
              <input type="text" name="employee_id" id="employee_id"
                     value="<?= e($formData['employee_id']) ?>"
                     placeholder="Employee ID"
                     class="form-input" />
            </div>

            <div class="space-y-2">
              <label for="password" class="block text-sm font-semibold text-teal-900">
                Password <span class="text-red-500">*</span>
              </label>
              <div class="relative">
                <input type="password" name="password" id="password" required
                       minlength="6"
                       placeholder="Minimum 6 characters"
                       class="form-input pr-12" />
                <button type="button"
                        id="togglePassword"
                        aria-label="Toggle password visibility"
                        class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-teal-600/60 hover:text-teal-600 transition-colors">
                  <i data-lucide="eye" class="w-5 h-5" id="eyeIcon"></i>
                </button>
              </div>
            </div>
          </div>

          <!-- Actions -->
          <div class="flex flex-col-reverse sm:flex-row items-stretch sm:items-center sm:justify-end gap-3 pt-5 border-t border-teal-100">
            <a href="login.php?role=staff"
               class="inline-flex items-center justify-center px-6 py-3 rounded-lg font-semibold
                      text-teal-600 bg-white border-2 border-teal-200 hover:border-teal-600 hover:bg-teal-50
                      transition-all duration-200">
              Cancel
            </a>
            <button type="submit"
                    class="inline-flex items-center justify-center gap-2 px-8 py-3 rounded-lg font-semibold text-white
                           bg-teal-600 hover:bg-teal-700 shadow-sm hover:shadow-md
                           transition-all duration-200 cursor-pointer">
              <span>Submit registration</span>
              <i data-lucide="arrow-right" class="w-4 h-4"></i>
            </button>
          </div>

        </form>
      </div>

      <!-- Helper strip -->
      <div class="mt-6 flex items-start gap-3 bg-teal-50 rounded-xl border-2 border-teal-100 p-4">
        <i data-lucide="shield-check" class="w-5 h-5 text-teal-600 flex-shrink-0 mt-0.5"></i>
        <p class="text-sm text-teal-900/80 leading-relaxed">
          Your account will be reviewed by an administrator before activation. You'll be able to log in once your status becomes <strong class="text-teal-900">Approved</strong>.
        </p>
      </div>

    </div>
  </main>

  <!-- Footer -->
  <footer class="bg-teal-900 text-white">
    <div class="roofline"></div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <div class="border-t border-white/10 pt-6 text-center text-xs text-teal-200/70">
        <p>&copy; <?php echo date('Y'); ?> Rajagiri College of Social Sciences. All rights reserved.</p>
      </div>
    </div>
  </footer>

  <script>
    if (typeof lucide !== 'undefined') lucide.createIcons();

    // Toggle password
    (function () {
      const btn     = document.getElementById('togglePassword');
      const pwd     = document.getElementById('password');
      const eyeIcon = document.getElementById('eyeIcon');
      if (!btn || !pwd || !eyeIcon) return;

      btn.addEventListener('click', function () {
        const isHidden = pwd.type === 'password';
        pwd.type = isHidden ? 'text' : 'password';
        eyeIcon.setAttribute('data-lucide', isHidden ? 'eye-off' : 'eye');
        if (typeof lucide !== 'undefined') lucide.createIcons({ targets: [eyeIcon] });
      });
    })();

    // Mobile number: only 10 digits
    (function () {
      const mobile = document.getElementById('contact_number');
      if (!mobile) return;
      mobile.addEventListener('input', function () {
        this.value = this.value.replace(/\D/g, '').slice(0, 10);
      });
    })();
  </script>
</body>
</html>
<?php
/**
 * parent_register.php
 * ---------------------------------------------------------------------------
 * Parent Registration — Rajagiri College Grievance Redressal Portal
 *
 * Flow:
 *   1. Parent enters the student's Admission Number and clicks "Check Student"
 *   2. AJAX fetches student + course + class details (embedded endpoint)
 *   3. Parent fills their own details (including username), then submits
 *   4. User row → parents row (with relation) → link to student → commit
 *
 * Database (grievance_db):
 *   users    : id, username, password (BCRYPT), role, status
 *   parents  : id, user_id, name, email, contact_number, relation
 *   students : id, admission_number, parent_id, class_id, name, email, contact_number
 *   classes  : id, course_id, class_name
 *   courses  : id, course_name
 *
 * REQUIRED MIGRATION (run once):
 *   ALTER TABLE `parents`
 *     ADD COLUMN `relation` VARCHAR(50) DEFAULT NULL AFTER `contact_number`;
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// SESSION START
// ---------------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

// ---------------------------------------------------------------------------
// If already logged in as PARENT, redirect to dashboard
// ---------------------------------------------------------------------------
if (!empty($_SESSION['user_id']) && isset($_SESSION['role']) && strtoupper((string) $_SESSION['role']) === 'PARENT') {
    header('Location: parent/dashboard.php');
    exit;
}

// ---------------------------------------------------------------------------
// DATABASE CONNECTION
// ---------------------------------------------------------------------------
$dbFile = __DIR__ . '/db_connect.php';

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
// HELPERS
// ---------------------------------------------------------------------------
function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function jsonResponse(array $payload): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
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
// ALLOWED RELATIONS (whitelist)
// ---------------------------------------------------------------------------
$allowedRelations = ['Father', 'Mother', 'Guardian'];

// ---------------------------------------------------------------------------
// EMBEDDED AJAX ENDPOINT — ?action=check_student
// ---------------------------------------------------------------------------
if (($_GET['action'] ?? '') === 'check_student') {

    if ($conn === null) {
        jsonResponse([
            'success' => false,
            'message' => $dbError ?: 'Database is unavailable.',
        ]);
    }

    $admissionNumber = trim((string) ($_POST['admission_number'] ?? $_GET['admission_number'] ?? ''));

    if ($admissionNumber === '') {
        jsonResponse([
            'success' => false,
            'message' => 'Please enter an Admission Number.',
        ]);
    }

    try {
        $sql = "SELECT  s.id            AS student_id,
                        s.name          AS student_name,
                        s.admission_number,
                        s.parent_id     AS existing_parent_id,
                        c.class_name    AS class_name,
                        co.course_name  AS course_name
                FROM students s
                LEFT JOIN classes c  ON c.id = s.class_id
                LEFT JOIN courses co ON co.id = c.course_id
                WHERE s.admission_number = ?
                LIMIT 1";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            throw new Exception('Query preparation failed: ' . $conn->error);
        }

        $stmt->bind_param('s', $admissionNumber);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows === 0) {
            $stmt->close();
            jsonResponse([
                'success' => false,
                'message' => 'Student not found with this Admission Number.',
            ]);
        }

        $student = $res->fetch_assoc();
        $stmt->close();

        if (!empty($student['existing_parent_id'])) {
            jsonResponse([
                'success' => false,
                'message' => 'A parent account is already linked to this student.',
            ]);
        }

        jsonResponse([
            'success'    => true,
            'student_id' => (int) $student['student_id'],
            'name'       => (string) ($student['student_name'] ?? ''),
            'course'     => (string) ($student['course_name']  ?? ''),
            'class'      => (string) ($student['class_name']   ?? ''),
        ]);

    } catch (Throwable $ex) {
        error_log('[Parent Reg - Check Student] ' . $ex->getMessage());
        jsonResponse([
            'success' => false,
            'message' => 'A system error occurred while looking up the student.',
        ]);
    }
}

// ---------------------------------------------------------------------------
// HANDLE FORM SUBMISSION (POST)
// ---------------------------------------------------------------------------
$formErrors  = [];
$formData    = [
    'admission_number' => '',
    'student_id'       => 0,
    'student_name'     => '',
    'course'           => '',
    'class'            => '',
    'name'             => '',
    'username'         => '',
    'email'            => '',
    'contact_number'   => '',
    'relation'         => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'register_parent') {

    // ---- CSRF check ----
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    if ($submittedToken === '' || !hash_equals($csrfToken, $submittedToken)) {
        $formErrors[] = 'Security token expired. Please refresh the page and try again.';
    }

    // ----- Collect -----
    $admissionNumber = trim((string) ($_POST['admission_number'] ?? ''));
    $studentId       = (int) ($_POST['student_id']       ?? 0);
    $name            = trim((string) ($_POST['name']             ?? ''));
    $username        = trim((string) ($_POST['username']         ?? ''));
    $email           = trim((string) ($_POST['email']            ?? ''));
    $contactNumber   = trim((string) ($_POST['contact_number']   ?? ''));
    $relation        = trim((string) ($_POST['relation']         ?? ''));
    $password        = (string) ($_POST['password']              ?? '');

    // ----- Preserve for redisplay -----
    $formData['admission_number'] = $admissionNumber;
    $formData['student_id']       = $studentId;
    $formData['name']             = $name;
    $formData['username']         = $username;
    $formData['email']            = $email;
    $formData['contact_number']   = $contactNumber;
    $formData['relation']         = $relation;

    // ----- Validation -----
    if ($admissionNumber === '') {
        $formErrors[] = 'Admission Number is required.';
    }
    if ($studentId <= 0) {
        $formErrors[] = 'Please verify the student by clicking "Check Student" before submitting.';
    }
    if ($name === '') {
        $formErrors[] = 'Parent name is required.';
    }
    if ($username === '') {
        $formErrors[] = 'Username is required.';
    } elseif (!preg_match('/^[A-Za-z0-9_.]{3,50}$/', $username)) {
        $formErrors[] = 'Username must be 3–50 characters and contain only letters, digits, underscore, or dot.';
    }
    if ($email === '') {
        $formErrors[] = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $formErrors[] = 'Please enter a valid email address.';
    }
    if ($contactNumber === '') {
        $formErrors[] = 'Contact Number is required.';
    } elseif (!preg_match('/^[0-9]{10}$/', $contactNumber)) {
        $formErrors[] = 'Contact Number must be exactly 10 digits.';
    }
    if ($relation === '' || !in_array($relation, $allowedRelations, true)) {
        $formErrors[] = 'Please select a valid Relation.';
    }
    if ($password === '') {
        $formErrors[] = 'Password is required.';
    } elseif (strlen($password) < 6) {
        $formErrors[] = 'Password must be at least 6 characters long.';
    }

    // ----- Sanity check: student exists, unassigned, matches admission -----
    if (empty($formErrors) && $conn !== null) {
        try {
            $chk = $conn->prepare(
                "SELECT id, name, parent_id
                 FROM students
                 WHERE id = ? AND admission_number = ?
                 LIMIT 1"
            );
            $chk->bind_param('is', $studentId, $admissionNumber);
            $chk->execute();
            $chkRes = $chk->get_result();

            if ($chkRes->num_rows === 0) {
                $formErrors[] = 'The verified student record was not found. Please re-check the Admission Number.';
            } else {
                $studentRow = $chkRes->fetch_assoc();
                $formData['student_name'] = (string) ($studentRow['name'] ?? '');

                if (!empty($studentRow['parent_id'])) {
                    $formErrors[] = 'A parent account is already linked to this student.';
                }
            }
            $chk->close();
        } catch (Throwable $ex) {
            error_log('[Parent Reg - Verify Student] ' . $ex->getMessage());
            $formErrors[] = 'Unable to verify the student record. Please try again.';
        }
    }

    // ----- Duplicate checks on username / email -----
    if (empty($formErrors) && $conn !== null) {
        try {
            $chkDup = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $chkDup->bind_param('s', $username);
            $chkDup->execute();
            if ($chkDup->get_result()->num_rows > 0) {
                $formErrors[] = 'This username is already taken. Please choose a different one.';
            }
            $chkDup->close();

            if (empty($formErrors)) {
                $chkDup2 = $conn->prepare("SELECT id FROM parents WHERE email = ? LIMIT 1");
                $chkDup2->bind_param('s', $email);
                $chkDup2->execute();
                if ($chkDup2->get_result()->num_rows > 0) {
                    $formErrors[] = 'This email is already registered as a parent.';
                }
                $chkDup2->close();
            }
        } catch (Throwable $ex) {
            error_log('[Parent Reg - Duplicate Check] ' . $ex->getMessage());
        }
    }

    // ----- Insert (Transaction) -----
    if (empty($formErrors) && $conn !== null) {
        $conn->begin_transaction();

        try {
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
            $role           = 'PARENT';
            $status         = 'Pending';

            $sqlUser = "INSERT INTO users (username, password, role, status, created_at)
                        VALUES (?, ?, ?, ?, NOW())";
            $stmtUser = $conn->prepare($sqlUser);
            if (!$stmtUser) {
                throw new Exception('User insert prepare failed: ' . $conn->error);
            }

            $stmtUser->bind_param('ssss', $username, $hashedPassword, $role, $status);
            if (!$stmtUser->execute()) {
                throw new Exception('User insert failed: ' . $stmtUser->error);
            }
            $newUserId = (int) $conn->insert_id;
            $stmtUser->close();

            $sqlParent = "INSERT INTO parents (user_id, name, email, contact_number, relation)
                          VALUES (?, ?, ?, ?, ?)";
            $stmtParent = $conn->prepare($sqlParent);
            if (!$stmtParent) {
                throw new Exception('Parent insert prepare failed: ' . $conn->error);
            }

            $stmtParent->bind_param('issss', $newUserId, $name, $email, $contactNumber, $relation);
            if (!$stmtParent->execute()) {
                throw new Exception('Parent insert failed: ' . $stmtParent->error);
            }
            $newParentId = (int) $conn->insert_id;
            $stmtParent->close();

            $sqlLink = "UPDATE students SET parent_id = ? WHERE id = ?";
            $stmtLink = $conn->prepare($sqlLink);
            if (!$stmtLink) {
                throw new Exception('Student update prepare failed: ' . $conn->error);
            }

            $stmtLink->bind_param('ii', $newParentId, $studentId);
            if (!$stmtLink->execute()) {
                throw new Exception('Student update failed: ' . $stmtLink->error);
            }
            $stmtLink->close();

            $conn->commit();

            $_SESSION['flash_success'] = 'Your registration request has been submitted! Please wait for admin approval before logging in.';
            header('Location: login.php?role=parent&registered=success');
            exit;

        } catch (Throwable $ex) {
            $conn->rollback();
            error_log('[Parent Reg - Save] ' . $ex->getMessage());
            $formErrors[] = $ex->getMessage() ?: 'Registration failed. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Parent Registration — Rajagiri College of Social Sciences</title>
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

    .form-input[readonly] {
      background-color: rgba(207, 230, 231, 0.35);
      color: rgba(0, 49, 52, 0.7);
      cursor: not-allowed;
    }
    .form-input[readonly]:hover { border-color: #CFE6E7; }
    .form-input[readonly]:focus { box-shadow: none; border-color: #CFE6E7; background-color: rgba(207, 230, 231, 0.35); }
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
        <a href="login.php?role=parent" class="group inline-flex items-center gap-2 text-teal-900 hover:text-teal-600 transition-colors text-sm font-medium">
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
          <i data-lucide="users" class="w-7 h-7 md:w-8 md:h-8 text-white"></i>
        </div>
        <div>
          <p class="text-white/70 text-xs font-semibold uppercase tracking-wider mb-1">Create Account</p>
          <h1 class="text-2xl md:text-3xl lg:text-4xl font-bold text-white leading-tight">Parent Registration</h1>
          <p class="text-teal-50 text-sm md:text-base mt-1">Link your account to your ward's student record.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- Form section -->
  <main class="flex-1 py-10 md:py-14 bg-white">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">

      <!-- Step 1 helper card -->
      <div class="mb-6 flex items-start gap-4 bg-teal-50 rounded-2xl border-2 border-teal-100 p-5">
        <div class="shrink-0 inline-flex items-center justify-center w-10 h-10 rounded-xl bg-white text-teal-600 font-bold text-sm">1</div>
        <div>
          <h3 class="text-sm font-bold text-teal-900 mb-1">Verify your ward first</h3>
          <p class="text-xs md:text-sm text-teal-900/70 leading-relaxed">
            Enter your ward's Admission Number and click <strong class="text-teal-900">Check Student</strong>. The form unlocks once we confirm the student record.
          </p>
        </div>
      </div>

      <div class="bg-white rounded-2xl border-2 border-teal-100 shadow-sm p-6 sm:p-8 md:p-10">

        <div class="mb-7">
          <h2 class="text-xl md:text-2xl font-bold text-teal-900 mb-1.5">Registration details</h2>
          <p class="text-sm text-teal-900/60">All fields marked with <span class="text-red-500 font-semibold">*</span> are required.</p>
        </div>

        <!-- Error alerts (server-side) -->
        <?php if (!empty($formErrors)): ?>
          <div class="mb-6 rounded-xl border-2 border-red-200 bg-red-50 px-4 py-3">
            <div class="flex items-start gap-2">
              <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5"></i>
              <ul class="text-sm text-red-700 space-y-1">
                <?php foreach ($formErrors as $err): ?>
                  <li><?= e($err) ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          </div>
        <?php endif; ?>

        <!-- Database error -->
        <?php if ($dbError && empty($formErrors)): ?>
          <div class="mb-6 rounded-xl border-2 border-amber-200 bg-amber-50 px-4 py-3">
            <div class="flex items-start gap-2">
              <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5"></i>
              <p class="text-sm text-amber-700"><?= e($dbError) ?></p>
            </div>
          </div>
        <?php endif; ?>

        <!-- AJAX result banner -->
        <div id="ajaxMessage" class="hidden mb-6 rounded-xl border-2 px-4 py-3">
          <div class="flex items-start gap-2">
            <i id="ajaxMessageIcon" data-lucide="alert-circle" class="w-5 h-5 flex-shrink-0 mt-0.5"></i>
            <p id="ajaxMessageText" class="text-sm font-medium"></p>
          </div>
        </div>

        <form id="parentRegistrationForm" method="POST" action="parent_register.php" class="space-y-5" novalidate>

          <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />
          <input type="hidden" name="action" value="register_parent" />
          <input type="hidden" name="student_id" id="student_id" value="<?= (int) $formData['student_id'] ?>" />

          <!-- Row 1: Admission Number + Check Student button -->
          <div class="space-y-2">
            <label for="admission_number" class="block text-sm font-semibold text-teal-900">
              Admission Number <span class="text-red-500">*</span>
            </label>
            <div class="flex flex-col sm:flex-row gap-3">
              <input type="text" name="admission_number" id="admission_number" required
                     value="<?= e($formData['admission_number']) ?>"
                     placeholder="Enter ward's admission number"
                     autocomplete="off"
                     class="form-input flex-1" />

              <button type="button" id="checkStudentBtn"
                      class="inline-flex items-center justify-center gap-2 px-6 py-3 rounded-lg font-semibold text-white text-sm
                             bg-teal-600 hover:bg-teal-700 shadow-sm hover:shadow-md
                             transition-all duration-200 whitespace-nowrap cursor-pointer shrink-0">
                <i data-lucide="search" class="w-4 h-4"></i>
                <span>Check Student</span>
              </button>
            </div>
          </div>

          <!-- Row 2: Student Name | Class -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div class="space-y-2">
              <label for="student_name" class="block text-sm font-semibold text-teal-900">Student Name</label>
              <input type="text" name="student_name" id="student_name" readonly
                     value="<?= e($formData['student_name']) ?>"
                     placeholder="Auto-filled after verification"
                     class="form-input" />
            </div>

            <div class="space-y-2">
              <label for="class" class="block text-sm font-semibold text-teal-900">Class</label>
              <input type="text" name="class" id="class" readonly
                     value="<?= e($formData['class'] ?? '') ?>"
                     placeholder="Auto-filled after verification"
                     class="form-input" />
            </div>
          </div>

          <!-- Row 3: Course | Relation -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div class="space-y-2">
              <label for="course" class="block text-sm font-semibold text-teal-900">Course</label>
              <input type="text" name="course" id="course" readonly
                     value="<?= e($formData['course'] ?? '') ?>"
                     placeholder="Auto-filled after verification"
                     class="form-input" />
            </div>

            <div class="space-y-2">
              <label for="relation" class="block text-sm font-semibold text-teal-900">
                Relation <span class="text-red-500">*</span>
              </label>
              <select name="relation" id="relation" required class="form-select">
                <option value="" disabled <?= $formData['relation'] === '' ? 'selected' : '' ?>>Select relation</option>
                <option value="Father"   <?= $formData['relation'] === 'Father'   ? 'selected' : '' ?>>Father</option>
                <option value="Mother"   <?= $formData['relation'] === 'Mother'   ? 'selected' : '' ?>>Mother</option>
                <option value="Guardian" <?= $formData['relation'] === 'Guardian' ? 'selected' : '' ?>>Guardian</option>
              </select>
            </div>
          </div>

          <!-- Divider -->
          <div class="pt-3 border-t border-teal-100">
            <p class="text-xs font-semibold text-teal-900/60 uppercase tracking-wide mb-4">Your details</p>
          </div>

          <!-- Row 4: Name | Username -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div class="space-y-2">
              <label for="name" class="block text-sm font-semibold text-teal-900">
                Your Name <span class="text-red-500">*</span>
              </label>
              <input type="text" name="name" id="name" required
                     value="<?= e($formData['name']) ?>"
                     placeholder="Full name"
                     class="form-input" />
            </div>

            <div class="space-y-2">
              <label for="username" class="block text-sm font-semibold text-teal-900">
                Username <span class="text-red-500">*</span>
              </label>
              <input type="text" name="username" id="username" required
                     minlength="3" maxlength="50"
                     pattern="[A-Za-z0-9_.]{3,50}"
                     autocomplete="username"
                     value="<?= e($formData['username']) ?>"
                     placeholder="3–50 chars, letters/digits/_/."
                     class="form-input" />
              <p class="text-xs text-teal-900/60 font-medium">
                <i data-lucide="info" class="inline w-3 h-3 -mt-0.5"></i>
                Used to sign in later.
              </p>
            </div>
          </div>

          <!-- Row 5: Email | Contact Number -->
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

          <!-- Row 6: Password -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div class="space-y-2">
              <label for="password" class="block text-sm font-semibold text-teal-900">
                Password <span class="text-red-500">*</span>
              </label>
              <div class="relative">
                <input type="password" name="password" id="password" required
                       minlength="6"
                       autocomplete="new-password"
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
            <a href="login.php?role=parent"
               class="inline-flex items-center justify-center px-6 py-3 rounded-lg font-semibold
                      text-teal-600 bg-white border-2 border-teal-200 hover:border-teal-600 hover:bg-teal-50
                      transition-all duration-200">
              Cancel
            </a>

            <button type="submit" id="submitBtn"
                    <?= ((int) $formData['student_id'] <= 0) ? 'disabled' : '' ?>
                    class="inline-flex items-center justify-center gap-2 px-8 py-3 rounded-lg font-semibold text-white
                           bg-teal-600 hover:bg-teal-700 shadow-sm hover:shadow-md
                           transition-all duration-200 cursor-pointer
                           disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-teal-600 disabled:hover:shadow-sm">
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

      <!-- Login link -->
      <p class="text-center text-sm text-teal-900/70 mt-6">
        Already have an account?
        <a href="login.php?role=parent" class="font-semibold text-teal-600 hover:text-teal-700 transition-colors">
          Log in here
        </a>
      </p>

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

    // ============================================================
    // Elements
    // ============================================================
    const admissionInput   = document.getElementById('admission_number');
    const checkBtn         = document.getElementById('checkStudentBtn');
    const studentIdInput   = document.getElementById('student_id');
    const studentNameInput = document.getElementById('student_name');
    const courseInput      = document.getElementById('course');
    const classInput       = document.getElementById('class');
    const submitBtn        = document.getElementById('submitBtn');
    const ajaxMsgBox       = document.getElementById('ajaxMessage');
    const ajaxMsgIcon      = document.getElementById('ajaxMessageIcon');
    const ajaxMsgText      = document.getElementById('ajaxMessageText');

    // ============================================================
    // Client-side message helper
    // ============================================================
    function showMessage(type, message) {
      ajaxMsgBox.classList.remove('hidden');
      ajaxMsgBox.classList.remove('border-emerald-200', 'bg-emerald-50', 'border-red-200', 'bg-red-50');

      if (type === 'success') {
        ajaxMsgBox.classList.add('border-emerald-200', 'bg-emerald-50');
        ajaxMsgText.classList.remove('text-red-700');
        ajaxMsgText.classList.add('text-emerald-800');
        ajaxMsgIcon.classList.remove('text-red-500');
        ajaxMsgIcon.classList.add('text-emerald-600');
        ajaxMsgIcon.setAttribute('data-lucide', 'check-circle');
      } else {
        ajaxMsgBox.classList.add('border-red-200', 'bg-red-50');
        ajaxMsgText.classList.remove('text-emerald-800');
        ajaxMsgText.classList.add('text-red-700');
        ajaxMsgIcon.classList.remove('text-emerald-600');
        ajaxMsgIcon.classList.add('text-red-500');
        ajaxMsgIcon.setAttribute('data-lucide', 'alert-circle');
      }

      ajaxMsgText.textContent = message;
      if (typeof lucide !== 'undefined') lucide.createIcons({ targets: [ajaxMsgIcon] });
    }

    function hideMessage() {
      ajaxMsgBox.classList.add('hidden');
      ajaxMsgText.textContent = '';
    }

    // ============================================================
    // "Check Student" — AJAX lookup
    // ============================================================
    async function checkStudent() {
      const admissionNumber = (admissionInput.value || '').trim();

      if (admissionNumber === '') {
        showMessage('error', 'Please enter an Admission Number first.');
        admissionInput.focus();
        return;
      }

      studentIdInput.value    = '0';
      studentNameInput.value  = '';
      courseInput.value       = '';
      classInput.value        = '';
      submitBtn.disabled      = true;

      const originalLabel = checkBtn.innerHTML;
      checkBtn.disabled = true;
      checkBtn.innerHTML = '<span class="inline-block animate-spin mr-2">⏳</span> Checking…';

      try {
        const body = new URLSearchParams();
        body.append('admission_number', admissionNumber);

        const response = await fetch('parent_register.php?action=check_student', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: body.toString()
        });

        const data = await response.json();

        if (data && data.success) {
          studentIdInput.value   = String(data.student_id || 0);
          studentNameInput.value = data.name   || '';
          courseInput.value      = data.course || '';
          classInput.value       = data.class  || '';
          submitBtn.disabled     = false;
          showMessage('success', 'Student verified! You can now complete the form.');
        } else {
          studentIdInput.value   = '0';
          studentNameInput.value = '';
          courseInput.value      = '';
          classInput.value       = '';
          submitBtn.disabled     = true;
          showMessage('error', data && data.message ? data.message : 'Student not found.');
        }
      } catch (err) {
        console.error(err);
        submitBtn.disabled = true;
        showMessage('error', 'Unable to reach the server. Please try again.');
      } finally {
        checkBtn.disabled = false;
        checkBtn.innerHTML = originalLabel;
        if (typeof lucide !== 'undefined') lucide.createIcons();
      }
    }

    checkBtn.addEventListener('click', checkStudent);
    admissionInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        checkStudent();
      }
    });

    admissionInput.addEventListener('input', function () {
      if (studentIdInput.value !== '0') {
        studentIdInput.value   = '0';
        studentNameInput.value = '';
        courseInput.value      = '';
        classInput.value       = '';
        submitBtn.disabled     = true;
        hideMessage();
      }
    });

    // ============================================================
    // Toggle password visibility
    // ============================================================
    (function () {
      const btn     = document.getElementById('togglePassword');
      const pwd     = document.getElementById('password');
      const eyeIcon = document.getElementById('eyeIcon');
      if (!btn || !pwd || !eyeIcon) return;

      btn.addEventListener('click', function () {
        const isHidden = pwd.type === 'password';
        pwd.type = isHidden ? 'text' : 'password';
        eyeIcon.setAttribute('data-lucide', isHidden ? 'eye-off' : 'eye');
        if (typeof lucide !== 'undefined') {
          lucide.createIcons({ targets: [eyeIcon] });
        }
      });
    })();

    // ============================================================
    // Mobile number: only 10 digits
    // ============================================================
    (function () {
      const mobile = document.getElementById('contact_number');
      if (!mobile) return;
      mobile.addEventListener('input', function () {
        this.value = this.value.replace(/\D/g, '').slice(0, 10);
      });
    })();

    // ============================================================
    // Username: block spaces/special chars
    // ============================================================
    (function () {
      const uname = document.getElementById('username');
      if (!uname) return;
      uname.addEventListener('input', function () {
        this.value = this.value.replace(/[^A-Za-z0-9_.]/g, '');
      });
    })();
  </script>
</body>
</html>
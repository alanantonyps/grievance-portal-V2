<?php
declare(strict_types=1);

/**
 * forgot-password.php
 * -----------------------------------------------------------
 * OTP-based password reset wizard (single page).
 *   STEP 1 → username + email  (identify)
 *   STEP 2 → enter 6-digit OTP (verify)
 *   STEP 3 → set new password
 *
 * Localhost  → OTP is shown ON THE PAGE (dev banner).
 * Real host  → OTP is emailed to the user.
 * -----------------------------------------------------------
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/mailer.php';

/* ===========================================================================
 *  HELPERS
 * =========================================================================== */
function e(?string $v): string {
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Detect if we're running on localhost or a real host. */
function is_localhost(): bool {
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
    $host = strtolower(explode(':', $host)[0]);
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

/** Return registered email for a user based on role. */
function resolve_user_email(mysqli $conn, int $userId, string $dbRole): ?string {
    $sqlMap = [
        'ADMIN'        => "SELECT email FROM admin_profiles WHERE user_id = ? LIMIT 1",
        'STUDENT'      => "SELECT email FROM students       WHERE user_id = ? LIMIT 1",
        'PARENT'       => "SELECT email FROM parents        WHERE user_id = ? LIMIT 1",
        'TEACHER'      => "SELECT email FROM staff          WHERE user_id = ? LIMIT 1",
        'NON_TEACHING' => "SELECT email FROM staff          WHERE user_id = ? LIMIT 1",
        'MANAGEMENT'   => "SELECT email FROM cell_members   WHERE user_id = ? LIMIT 1",
    ];
    if (!isset($sqlMap[$dbRole])) return null;
    $stmt = $conn->prepare($sqlMap[$dbRole]);
    if (!$stmt) return null;
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row['email'] ?? null;
}

/** Mask email for display, e.g. a***2@domain.com */
function mask_email(string $email): string {
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2) return $email;
    [$user, $domain] = $parts;
    $len = strlen($user);
    if ($len <= 2) $masked = substr($user, 0, 1) . str_repeat('*', max(1, $len - 1));
    else           $masked = $user[0] . str_repeat('*', $len - 2) . $user[$len - 1];
    return $masked . '@' . $domain;
}

/** Send OTP via email (production only). */
function send_otp_email(string $email, string $username, string $otp, mysqli $conn): bool {
    $safeName = e($username);
    $safeOtp  = e($otp);
    $html = "
      <div style='font-family:Poppins,Arial,sans-serif;color:#003134;max-width:560px;margin:auto'>
        <div style='background:#006E74;padding:24px;border-radius:12px 12px 0 0'>
          <h2 style='color:#fff;margin:0;font-size:20px'>Password Reset Verification</h2>
        </div>
        <div style='border:1px solid #CFE6E7;border-top:none;padding:24px;border-radius:0 0 12px 12px'>
          <p>Hi <strong>{$safeName}</strong>,</p>
          <p>Use this One-Time Password (OTP) to reset your Grievance Portal password:</p>
          <div style='text-align:center;margin:28px 0'>
            <div style='display:inline-block;background:#EAF4F4;border:2px dashed #006E74;
                        padding:16px 28px;border-radius:12px;letter-spacing:8px;
                        font-size:32px;font-weight:700;color:#006E74'>
              {$safeOtp}
            </div>
          </div>
          <p style='color:#666;font-size:13px'>This OTP is valid for <strong>10 minutes</strong>. Do not share it with anyone.</p>
          <p style='color:#666;font-size:13px'>If you didn't request this, you can safely ignore this email.</p>
          <hr style='border:none;border-top:1px solid #CFE6E7;margin:24px 0'>
          <p style='font-size:11px;color:#888'>Rajagiri College of Social Sciences — Grievance Redressal Portal</p>
        </div>
      </div>
    ";
    return send_mail($email, 'Your Password Reset OTP — Grievance Portal', $html, $conn);
}

/* ===========================================================================
 *  ROLE CONFIG
 * =========================================================================== */
$roleConfig = [
    'admin' => [
        'title' => 'Administrator Portal', 'subtitle' => 'Reset your Admin password',
        'icon'  => 'shield-check', 'label' => 'Admin Username',
        'supportMail' => 'admin.support@rajagiri.edu', 'supportTag' => 'Tech Support',
    ],
    'student' => [
        'title' => 'Student Portal', 'subtitle' => 'Reset your Student password',
        'icon'  => 'graduation-cap', 'label' => 'Student Username',
        'supportMail' => 'student.grievance@rajagiricss.edu', 'supportTag' => 'Helpdesk Email',
    ],
    'parent' => [
        'title' => 'Parent Portal', 'subtitle' => 'Reset your Parent password',
        'icon'  => 'users', 'label' => 'Parent Username',
        'supportMail' => 'parent.help@rajagiricss.edu', 'supportTag' => 'Support Email',
    ],
    'staff' => [
        'title' => 'Staff Portal', 'subtitle' => 'Reset your Staff password',
        'icon'  => 'briefcase', 'label' => 'Staff Username',
        'supportMail' => 'staff.help@rajagiri.edu', 'supportTag' => 'Support Email',
    ],
    'management' => [
        'title' => 'Management & Grievance Portal', 'subtitle' => 'Reset your Management password',
        'icon'  => 'layers', 'label' => 'Management Username',
        'supportMail' => 'grievance.committee@rajagiri.edu', 'supportTag' => 'Committee Helpdesk',
    ],
];

$roleDbMap = [
    'admin'      => ['ADMIN'],
    'student'    => ['STUDENT'],
    'parent'     => ['PARENT'],
    'staff'      => ['TEACHER', 'NON_TEACHING'],
    'management' => ['MANAGEMENT'],
];

$roleKey = isset($_GET['role']) ? strtolower(trim((string) $_GET['role'])) : 'student';
if (!array_key_exists($roleKey, $roleConfig)) $roleKey = 'student';
$role = $roleConfig[$roleKey];
$allowedDbRoles = $roleDbMap[$roleKey] ?? ['STUDENT'];

/* ===========================================================================
 *  DB CONNECTION
 * =========================================================================== */
$dbError = null;
$conn    = null;
$dbFile  = __DIR__ . '/db_connect.php';

if (!file_exists($dbFile)) {
    $dbError = 'Database configuration file (db_connect.php) is missing.';
} else {
    require_once $dbFile;
    if (!isset($conn) || !($conn instanceof mysqli)) {
        $conn = @new mysqli('localhost', 'root', '', 'grievance_db');
        if ($conn->connect_error) { $dbError = 'Unable to connect to the database.'; $conn = null; }
        else { $conn->set_charset('utf8mb4'); }
    }
    if ($conn && $conn->connect_errno) { $dbError = 'Database connection failed.'; $conn = null; }
}

/* ===========================================================================
 *  CSRF
 * =========================================================================== */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

/* ===========================================================================
 *  RESTART HANDLER
 * =========================================================================== */
if (isset($_GET['restart']) && $_GET['restart'] === '1') {
    unset(
        $_SESSION['fp_step'], $_SESSION['fp_user_id'], $_SESSION['fp_email'],
        $_SESSION['fp_username'], $_SESSION['fp_db_role'], $_SESSION['fp_otp_id'],
        $_SESSION['fp_otp_verified'], $_SESSION['fp_otp_sent_at'], $_SESSION['fp_errors'],
        $_SESSION['fp_old_username'], $_SESSION['fp_old_email'], $_SESSION['fp_debug_otp']
    );
    header('Location: forgot-password.php?role=' . urlencode($roleKey));
    exit;
}

/* ===========================================================================
 *  POST HANDLERS
 * =========================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* ---- CSRF check ---- */
    $submitted = $_POST['csrf_token'] ?? '';
    if ($submitted === '' || !hash_equals($_SESSION['csrf_token'], $submitted)) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['fp_errors']  = ['Security token expired. Please try again.'];
        $_SESSION['fp_step']    = 1;
        unset($_SESSION['fp_user_id'], $_SESSION['fp_email'], $_SESSION['fp_username'], $_SESSION['fp_db_role']);
        header('Location: forgot-password.php?role=' . urlencode($roleKey));
        exit;
    }

    $action = $_POST['action'] ?? '';

    /* ======================================================================
     *  ACTION 1: send_otp  (Step 1 → Step 2)
     * ====================================================================== */
    if ($action === 'send_otp') {
        $errors   = [];
        $username = trim((string) ($_POST['username'] ?? ''));
        $email    = trim((string) ($_POST['email'] ?? ''));

        if ($username === '') $errors[] = 'Please enter your username.';
        if ($email === '')    $errors[] = 'Please enter your registered email.';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';

        if (empty($errors) && $conn === null) {
            $errors[] = $dbError ?: 'Database unavailable. Try again later.';
        }

        if (empty($errors)) {
            try {
                $stmt = $conn->prepare(
                    "SELECT id, username, role, status FROM users WHERE BINARY username = ? LIMIT 1"
                );
                $stmt->bind_param('s', $username);
                $stmt->execute();
                $res  = $stmt->get_result();
                $user = $res ? $res->fetch_assoc() : null;
                $stmt->close();

                $genericErr = 'The username and email do not match our records.';

                if (!$user) {
                    $errors[] = $genericErr;
                } else {
                    $dbRole = strtoupper((string) $user['role']);
                    $status = ucfirst(strtolower((string) $user['status']));

                    if (!in_array($dbRole, $allowedDbRoles, true)) {
                        $errors[] = $genericErr;
                    } elseif ($status !== 'Approved') {
                        $errors[] = 'Your account is not active. Please contact support.';
                    } else {
                        $registeredEmail = resolve_user_email($conn, (int) $user['id'], $dbRole);

                        if (!$registeredEmail || strcasecmp($registeredEmail, $email) !== 0) {
                            $errors[] = $genericErr;
                        } else {
                            /* ---- Generate OTP ---- */
                            $otp       = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                            $otpHash   = hash('sha256', $otp);
                            $expiresAt = date('Y-m-d H:i:s', time() + 600);

                            /* ---- Invalidate previous OTPs ---- */
                            $inv = $conn->prepare("UPDATE password_otps SET used = 1 WHERE user_id = ? AND used = 0");
                            $inv->bind_param('i', $user['id']);
                            $inv->execute();
                            $inv->close();

                            /* ---- Insert new OTP ---- */
                            $ins = $conn->prepare(
                                "INSERT INTO password_otps (user_id, email, otp_hash, expires_at)
                                 VALUES (?, ?, ?, ?)"
                            );
                            $ins->bind_param('isss', $user['id'], $registeredEmail, $otpHash, $expiresAt);
                            $ins->execute();
                            $ins->close();

                            /* ---- Deliver OTP ---- */
                            if (is_localhost()) {
                                /* DEV: show on page */
                                $_SESSION['fp_debug_otp'] = $otp;
                            } else {
                                /* PROD: send email */
                                send_otp_email($registeredEmail, $user['username'], $otp, $conn);
                                unset($_SESSION['fp_debug_otp']);
                            }

                            /* ---- Advance to step 2 ---- */
                            $_SESSION['fp_step']        = 2;
                            $_SESSION['fp_user_id']     = (int) $user['id'];
                            $_SESSION['fp_email']       = $registeredEmail;
                            $_SESSION['fp_username']    = $user['username'];
                            $_SESSION['fp_db_role']     = $dbRole;
                            $_SESSION['fp_otp_sent_at'] = time();
                            unset($_SESSION['fp_errors']);

                            header('Location: forgot-password.php?role=' . urlencode($roleKey));
                            exit;
                        }
                    }
                }
            } catch (Exception $ex) {
                error_log('[Forgot Pwd / send_otp] ' . $ex->getMessage());
                $errors[] = 'A system error occurred. Please try again.';
            }
        }

        $_SESSION['fp_errors']       = $errors;
        $_SESSION['fp_old_username'] = $username;
        $_SESSION['fp_old_email']    = $email;
        header('Location: forgot-password.php?role=' . urlencode($roleKey));
        exit;
    }

    /* ======================================================================
     *  ACTION 2: verify_otp  (Step 2 → Step 3)
     * ====================================================================== */
    if ($action === 'verify_otp') {
        $errors = [];
        $otp    = trim((string) ($_POST['otp'] ?? ''));

        if (!preg_match('/^\d{6}$/', $otp)) {
            $errors[] = 'Please enter the 6-digit OTP.';
        }

        $userId = (int) ($_SESSION['fp_user_id'] ?? 0);
        $email  = (string) ($_SESSION['fp_email'] ?? '');

        if ($userId <= 0 || $email === '') {
            $errors[] = 'Your session expired. Please start again.';
        }

        if (empty($errors) && $conn !== null) {
            try {
                $otpHash = hash('sha256', $otp);

                $stmt = $conn->prepare(
                    "SELECT id, attempts, expires_at, used
                     FROM password_otps
                     WHERE user_id = ? AND email = ?
                     ORDER BY id DESC LIMIT 1"
                );
                $stmt->bind_param('is', $userId, $email);
                $stmt->execute();
                $res = $stmt->get_result();
                $row = $res ? $res->fetch_assoc() : null;
                $stmt->close();

                if (!$row) {
                    $errors[] = 'No OTP found for this account. Please start again.';
                } elseif ((int) $row['used'] === 1) {
                    $errors[] = 'This OTP has already been used. Please request a new one.';
                } elseif (strtotime($row['expires_at']) < time()) {
                    $errors[] = 'This OTP has expired. Please request a new one.';
                } elseif ((int) $row['attempts'] >= 5) {
                    $errors[] = 'Too many wrong attempts. Please request a new OTP.';
                } else {
                    $chk = $conn->prepare("SELECT id FROM password_otps WHERE id = ? AND otp_hash = ? LIMIT 1");
                    $chk->bind_param('is', $row['id'], $otpHash);
                    $chk->execute();
                    $chkRes = $chk->get_result();
                    $ok     = $chkRes && $chkRes->num_rows === 1;
                    $chk->close();

                    if (!$ok) {
                        $inc = $conn->prepare("UPDATE password_otps SET attempts = attempts + 1 WHERE id = ?");
                        $inc->bind_param('i', $row['id']);
                        $inc->execute();
                        $inc->close();

                        $remaining = 5 - ((int) $row['attempts'] + 1);
                        $errors[]  = 'Incorrect OTP.' . ($remaining > 0
                            ? " You have {$remaining} attempt" . ($remaining === 1 ? '' : 's') . " left."
                            : ' Please request a new OTP.');
                    } else {
                        $_SESSION['fp_otp_id']       = (int) $row['id'];
                        $_SESSION['fp_otp_verified'] = true;
                        $_SESSION['fp_step']         = 3;
                        unset($_SESSION['fp_errors'], $_SESSION['fp_debug_otp']);
                        header('Location: forgot-password.php?role=' . urlencode($roleKey));
                        exit;
                    }
                }
            } catch (Exception $ex) {
                error_log('[Forgot Pwd / verify_otp] ' . $ex->getMessage());
                $errors[] = 'A system error occurred. Please try again.';
            }
        }

        $_SESSION['fp_errors'] = $errors;
        header('Location: forgot-password.php?role=' . urlencode($roleKey));
        exit;
    }

    /* ======================================================================
     *  ACTION 3: reset_password  (Step 3 → Done)
     * ====================================================================== */
    if ($action === 'reset_password') {
        $errors = [];

        $userId   = (int) ($_SESSION['fp_user_id'] ?? 0);
        $otpId    = (int) ($_SESSION['fp_otp_id']  ?? 0);
        $verified = !empty($_SESSION['fp_otp_verified']);

        $password = (string) ($_POST['password'] ?? '');
        $confirm  = (string) ($_POST['confirm_password'] ?? '');

        if ($userId <= 0 || $otpId <= 0 || !$verified) {
            $errors[] = 'Your session expired. Please start again.';
        }

        if ($password === '' || $confirm === '') {
            $errors[] = 'Please fill in both password fields.';
        } else {
            if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters long.';
            if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
                $errors[] = 'Password must contain at least one letter and one number.';
            }
            if ($password !== $confirm) $errors[] = 'Passwords do not match.';
        }

        if (empty($errors) && $conn !== null) {
            try {
                $conn->begin_transaction();
                $hash = password_hash($password, PASSWORD_DEFAULT);

                $u = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $u->bind_param('si', $hash, $userId);
                $u->execute();
                $u->close();

                $m = $conn->prepare("UPDATE password_otps SET used = 1 WHERE id = ?");
                $m->bind_param('i', $otpId);
                $m->execute();
                $m->close();

                $o = $conn->prepare("UPDATE password_otps SET used = 1 WHERE user_id = ? AND used = 0");
                $o->bind_param('i', $userId);
                $o->execute();
                $o->close();

                $conn->commit();

                unset(
                    $_SESSION['fp_step'], $_SESSION['fp_user_id'], $_SESSION['fp_email'],
                    $_SESSION['fp_username'], $_SESSION['fp_db_role'], $_SESSION['fp_otp_id'],
                    $_SESSION['fp_otp_verified'], $_SESSION['fp_otp_sent_at'], $_SESSION['fp_errors'],
                    $_SESSION['fp_debug_otp']
                );
                $_SESSION['fp_success'] = true;

                header('Location: forgot-password.php?role=' . urlencode($roleKey));
                exit;
            } catch (Exception $ex) {
                if ($conn) $conn->rollback();
                error_log('[Forgot Pwd / reset_password] ' . $ex->getMessage());
                $errors[] = 'A system error occurred. Please try again.';
            }
        }

        $_SESSION['fp_errors'] = $errors;
        header('Location: forgot-password.php?role=' . urlencode($roleKey));
        exit;
    }

    /* ---- Unknown action ---- */
    header('Location: forgot-password.php?role=' . urlencode($roleKey));
    exit;
}

/* ===========================================================================
 *  GET — PREPARE RENDER VARIABLES
 * =========================================================================== */
$errors  = $_SESSION['fp_errors'] ?? [];
unset($_SESSION['fp_errors']);

$success = !empty($_SESSION['fp_success']);
if ($success) {
    unset($_SESSION['fp_success']);
    $step = 'done';
} else {
    $step = (int) ($_SESSION['fp_step'] ?? 1);

    if (($step === 2 || $step === 3) && (empty($_SESSION['fp_user_id']) || empty($_SESSION['fp_email']))) {
        $step = 1;
        $_SESSION['fp_step'] = 1;
        unset($_SESSION['fp_user_id'], $_SESSION['fp_email'], $_SESSION['fp_username'],
              $_SESSION['fp_db_role'], $_SESSION['fp_otp_id'], $_SESSION['fp_otp_verified']);
        $errors[] = 'Your session expired. Please start again.';
    }
    if ($step === 3 && empty($_SESSION['fp_otp_verified'])) {
        $step = 2;
        $_SESSION['fp_step'] = 2;
        $errors[] = 'Please verify your OTP first.';
    }
}

$oldUsername = $_SESSION['fp_old_username'] ?? '';
$oldEmail    = $_SESSION['fp_old_email']    ?? '';
unset($_SESSION['fp_old_username'], $_SESSION['fp_old_email']);

$maskedEmail = !empty($_SESSION['fp_email']) ? mask_email((string) $_SESSION['fp_email']) : '';
$displayName = $_SESSION['fp_username'] ?? '';
$debugOtp    = $_SESSION['fp_debug_otp'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Forgot Password — <?= e($role['title']) ?></title>
<link rel="icon" type="image/svg+xml" href="public/favicon.svg" />
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/lucide@latest"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script>
tailwind.config = {
  theme: { extend: {
    colors: { teal: {
      50:'#EAF4F4',100:'#CFE6E7',200:'#9FCDCF',300:'#6FB4B7',400:'#3F9B9F',
      500:'#128287',600:'#006E74',700:'#005A5F',800:'#00454A',900:'#003134'
    }},
    fontFamily: { display:['Coolvetica','Poppins','sans-serif'], sans:['Coolvetica','Poppins','sans-serif'] }
  }}
}
</script>
<style>
  @font-face { font-family:'Coolvetica'; src:url('assets/fonts/coolvetica-rg.woff2') format('woff2'), url('assets/fonts/coolvetica-rg.woff') format('woff'); font-weight:400; font-display:swap; }
  @font-face { font-family:'Coolvetica'; src:url('assets/fonts/coolvetica-bold.woff2') format('woff2'), url('assets/fonts/coolvetica-bold.woff') format('woff'); font-weight:700; font-display:swap; }
  body { font-family:'Coolvetica','Poppins',sans-serif; }
  .hero-dots { background-image: radial-gradient(rgba(255,255,255,0.35) 1.5px, transparent 1.5px); background-size: 22px 22px; }
  .otp-input::-webkit-outer-spin-button,
  .otp-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
  .step-dot { width: 32px; height: 32px; border-radius: 9999px; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:13px; }
  .step-line { flex:1; height:2px; background:#CFE6E7; }
  .step-line.active { background:#006E74; }
</style>
</head>
<body class="min-h-screen bg-white text-teal-900 antialiased">

<header class="bg-white sticky top-0 z-50 border-b-2 border-teal-600">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <div class="flex justify-between items-center h-16 md:h-20">
      <a href="index.php" class="flex items-center gap-3">
        <img src="public/rcss-logo.webp" alt="RCSS" class="h-8 md:h-10 w-auto" />
      </a>
      <a href="login.php?role=<?= e($roleKey) ?>" class="group inline-flex items-center gap-2 text-teal-900 hover:text-teal-600 text-sm font-medium">
        <i data-lucide="arrow-left" class="w-4 h-4 transition-transform group-hover:-translate-x-1"></i>
        <span class="hidden sm:inline">Back to Login</span>
        <span class="sm:hidden">Login</span>
      </a>
    </div>
  </div>
</header>

<main>
  <div class="bg-teal-600 relative overflow-hidden">
    <div class="absolute inset-0 hero-dots opacity-30 pointer-events-none"></div>
    <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 md:py-14">
      <div class="flex flex-col sm:flex-row sm:items-center gap-4 sm:gap-6">
        <div class="inline-flex items-center justify-center w-14 h-14 md:w-16 md:h-16 rounded-2xl bg-white/15 backdrop-blur-sm border border-white/30 shrink-0">
          <i data-lucide="key-round" class="w-7 h-7 md:w-8 md:h-8 text-white"></i>
        </div>
        <div>
          <p class="text-white/70 text-xs font-semibold uppercase tracking-wider mb-1">Password Recovery</p>
          <h1 class="text-2xl md:text-3xl lg:text-4xl font-bold text-white leading-tight">Forgot Password?</h1>
          <p class="text-teal-50 text-sm md:text-base mt-1"><?= e($role['subtitle']) ?></p>
        </div>
      </div>
    </div>
  </div>

  <section class="py-10 md:py-16 bg-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="grid grid-cols-1 lg:grid-cols-5 gap-8 lg:gap-12 items-start">

        <div class="lg:col-span-3">
          <div class="bg-white rounded-2xl border-2 border-teal-100 shadow-sm p-6 sm:p-8 max-w-xl mx-auto lg:mx-0">

            <?php if ($step !== 'done'): ?>
              <div class="flex items-center gap-2 mb-7">
                <?php
                  $steps = [1 => 'Identify', 2 => 'Verify OTP', 3 => 'New Password'];
                  $current = (int) $step;
                ?>
                <?php foreach ($steps as $n => $label): ?>
                  <?php if ($n > 1): ?>
                    <div class="step-line <?= $current >= $n ? 'active' : '' ?>"></div>
                  <?php endif; ?>
                  <div class="flex items-center gap-2 shrink-0">
                    <div class="step-dot <?= $current >= $n ? 'bg-teal-600 text-white' : 'bg-teal-50 text-teal-600/60 border border-teal-200' ?>">
                      <?php if ($current > $n): ?>
                        <i data-lucide="check" class="w-4 h-4"></i>
                      <?php else: ?>
                        <?= $n ?>
                      <?php endif; ?>
                    </div>
                    <span class="hidden sm:inline text-xs font-semibold <?= $current >= $n ? 'text-teal-900' : 'text-teal-900/50' ?>"><?= e($label) ?></span>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <?php if (!empty($errors) && $step !== 'done'): ?>
              <div class="mb-5 rounded-xl border-2 border-red-200 bg-red-50 px-4 py-3">
                <div class="flex items-start gap-2">
                  <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5"></i>
                  <ul class="text-sm text-red-700 space-y-1">
                    <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
                  </ul>
                </div>
              </div>
            <?php endif; ?>

            <?php if ($dbError && $step !== 'done'): ?>
              <div class="mb-5 rounded-xl border-2 border-amber-200 bg-amber-50 px-4 py-3">
                <div class="flex items-start gap-2">
                  <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5"></i>
                  <p class="text-sm text-amber-700"><?= e($dbError) ?></p>
                </div>
              </div>
            <?php endif; ?>

            <?php if ($step === 'done'): ?>
              <div class="text-center py-4">
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-teal-50 border-2 border-teal-200 mb-5">
                  <i data-lucide="check-circle-2" class="w-8 h-8 text-teal-600"></i>
                </div>
                <h2 class="text-xl md:text-2xl font-bold text-teal-900 mb-2">Password updated</h2>
                <p class="text-sm text-teal-900/70 max-w-md mx-auto mb-6">
                  Your password has been changed successfully. You can now sign in with your new password.
                </p>
                <a href="login.php?role=<?= e($roleKey) ?>"
                   class="inline-flex items-center gap-2 px-6 py-3 rounded-lg bg-teal-600 text-white text-sm font-semibold hover:bg-teal-700 transition-colors">
                  <i data-lucide="log-in" class="w-4 h-4"></i>
                  Go to Login
                </a>
              </div>

            <?php elseif ($step === 1): ?>
              <div class="mb-6">
                <h2 class="text-xl md:text-2xl font-bold text-teal-900 mb-1.5">Verify your identity</h2>
                <p class="text-sm text-teal-900/60">Enter your username and the email registered with your account.</p>
              </div>

              <form action="forgot-password.php?role=<?= e($roleKey) ?>" method="POST" class="space-y-5" novalidate autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />
                <input type="hidden" name="action" value="send_otp" />

                <div class="space-y-2">
                  <label for="username" class="block text-sm font-semibold text-teal-900"><?= e($role['label']) ?></label>
                  <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-teal-600/60">
                      <i data-lucide="user" class="w-5 h-5"></i>
                    </div>
                    <input id="username" name="username" type="text"
                      value="<?= e($oldUsername) ?>"
                      placeholder="Enter your username" required
                      autocomplete="off" autocapitalize="none" autocorrect="off" spellcheck="false"
                      class="w-full pl-11 pr-4 py-3 border-2 border-teal-100 rounded-lg bg-teal-50/40 text-teal-900 placeholder-teal-900/40 text-sm font-medium
                             focus:outline-none focus:border-teal-600 focus:bg-white focus:ring-4 focus:ring-teal-600/10
                             hover:border-teal-200 transition-all duration-200" />
                  </div>
                  <p class="text-[11px] text-teal-900/50 flex items-center gap-1.5">
                    <i data-lucide="info" class="w-3 h-3"></i>
                    Usernames are case-sensitive.
                  </p>
                </div>

                <div class="space-y-2">
                  <label for="email" class="block text-sm font-semibold text-teal-900">Registered Email</label>
                  <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-teal-600/60">
                      <i data-lucide="mail" class="w-5 h-5"></i>
                    </div>
                    <input id="email" name="email" type="email"
                      value="<?= e($oldEmail) ?>"
                      placeholder="you@example.com" required
                      autocomplete="off" autocapitalize="none" autocorrect="off" spellcheck="false"
                      class="w-full pl-11 pr-4 py-3 border-2 border-teal-100 rounded-lg bg-teal-50/40 text-teal-900 placeholder-teal-900/40 text-sm font-medium
                             focus:outline-none focus:border-teal-600 focus:bg-white focus:ring-4 focus:ring-teal-600/10
                             hover:border-teal-200 transition-all duration-200" />
                  </div>
                </div>

                <button type="submit"
                  class="w-full inline-flex items-center justify-center gap-2 bg-teal-600 text-white px-6 py-3.5 rounded-lg text-sm font-semibold
                         hover:bg-teal-700 transition-colors duration-200 cursor-pointer">
                  <i data-lucide="send" class="w-4 h-4"></i>
                  <span>Send OTP</span>
                </button>
              </form>

            <?php elseif ($step === 2): ?>
              <?php if ($debugOtp !== ''): ?>
                <div class="mb-5 rounded-xl border-2 border-amber-300 bg-amber-50 px-4 py-3">
                  <div class="flex items-start gap-3">
                    <i data-lucide="bug" class="w-5 h-5 text-amber-600 flex-shrink-0 mt-0.5"></i>
                    <div class="text-sm text-amber-800">
                      <p class="font-semibold mb-1">Dev Mode — OTP shown on page</p>
                      <p>Your OTP is:
                        <span class="font-mono font-bold text-lg tracking-widest text-amber-900 bg-white px-3 py-1 rounded ml-1 border border-amber-200"><?= e($debugOtp) ?></span>
                      </p>
                      <p class="text-xs text-amber-700/80 mt-1">This banner only appears on <code>localhost</code>. On a live server, the OTP is emailed to the user.</p>
                    </div>
                  </div>
                </div>
              <?php endif; ?>

              <div class="mb-6">
                <h2 class="text-xl md:text-2xl font-bold text-teal-900 mb-1.5">Enter the OTP</h2>
                <p class="text-sm text-teal-900/60">
                  We've sent a 6-digit OTP to <strong class="text-teal-900"><?= e($maskedEmail) ?></strong>.
                  It expires in 10 minutes.
                </p>
              </div>

              <form action="forgot-password.php?role=<?= e($roleKey) ?>" method="POST" id="otpForm" class="space-y-5" novalidate autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />
                <input type="hidden" name="action" value="verify_otp" />
                <input type="hidden" name="otp" id="otpHidden" />

                <div class="space-y-2">
                  <label class="block text-sm font-semibold text-teal-900">One-Time Password</label>
                  <div class="flex gap-2 sm:gap-3 justify-between" id="otpCells">
                    <?php for ($i = 0; $i < 6; $i++): ?>
                      <input type="text" inputmode="numeric" maxlength="1" pattern="\d"
                        class="otp-input h-14 sm:h-16 w-full text-center border-2 border-teal-100 rounded-lg bg-teal-50/40 text-teal-900 text-xl sm:text-2xl font-bold
                               focus:outline-none focus:border-teal-600 focus:bg-white focus:ring-4 focus:ring-teal-600/10
                               hover:border-teal-200 transition-all duration-200"
                        data-otp-cell="<?= $i ?>" aria-label="OTP digit <?= $i + 1 ?>" autocomplete="one-time-code" />
                    <?php endfor; ?>
                  </div>
                </div>

                <button type="submit" id="otpSubmitBtn"
                  class="w-full inline-flex items-center justify-center gap-2 bg-teal-600 text-white px-6 py-3.5 rounded-lg text-sm font-semibold
                         hover:bg-teal-700 transition-colors duration-200 cursor-pointer">
                  <i data-lucide="shield-check" class="w-4 h-4"></i>
                  <span>Verify OTP</span>
                </button>

                <div class="pt-2 border-t border-teal-100">
                  <a href="forgot-password.php?role=<?= e($roleKey) ?>&restart=1"
                     class="text-xs font-semibold text-teal-600 hover:text-teal-700 inline-flex items-center gap-1.5">
                    <i data-lucide="rotate-ccw" class="w-3.5 h-3.5"></i>
                    <span>Use a different username / email</span>
                  </a>
                </div>
              </form>

            <?php elseif ($step === 3): ?>
              <div class="mb-6">
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-teal-50 border border-teal-200 text-teal-700 text-xs font-semibold mb-3">
                  <i data-lucide="shield-check" class="w-3.5 h-3.5"></i>
                  <span>OTP Verified</span>
                </div>
                <h2 class="text-xl md:text-2xl font-bold text-teal-900 mb-1.5">
                  Hi <?= e($displayName) ?>, set your new password
                </h2>
                <p class="text-sm text-teal-900/60">At least 8 characters, with at least one letter and one number.</p>
              </div>

              <form action="forgot-password.php?role=<?= e($roleKey) ?>" method="POST" class="space-y-5" novalidate autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />
                <input type="hidden" name="action" value="reset_password" />

                <div class="space-y-2">
                  <label for="password" class="block text-sm font-semibold text-teal-900">New Password</label>
                  <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-teal-600/60">
                      <i data-lucide="lock" class="w-5 h-5"></i>
                    </div>
                    <input id="password" name="password" type="password" required autocomplete="new-password"
                      placeholder="Enter new password"
                      class="w-full pl-11 pr-12 py-3 border-2 border-teal-100 rounded-lg bg-teal-50/40 text-teal-900 placeholder-teal-900/40 text-sm font-medium
                             focus:outline-none focus:border-teal-600 focus:bg-white focus:ring-4 focus:ring-teal-600/10
                             hover:border-teal-200 transition-all duration-200" />
                    <button type="button" data-toggle-pw="password" aria-label="Toggle password visibility"
                      class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-teal-600/60 hover:text-teal-600 transition-colors">
                      <i data-lucide="eye" class="w-5 h-5"></i>
                    </button>
                  </div>
                </div>

                <div class="space-y-2">
                  <label for="confirm_password" class="block text-sm font-semibold text-teal-900">Confirm New Password</label>
                  <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-teal-600/60">
                      <i data-lucide="lock-keyhole" class="w-5 h-5"></i>
                    </div>
                    <input id="confirm_password" name="confirm_password" type="password" required autocomplete="new-password"
                      placeholder="Re-enter new password"
                      class="w-full pl-11 pr-12 py-3 border-2 border-teal-100 rounded-lg bg-teal-50/40 text-teal-900 placeholder-teal-900/40 text-sm font-medium
                             focus:outline-none focus:border-teal-600 focus:bg-white focus:ring-4 focus:ring-teal-600/10
                             hover:border-teal-200 transition-all duration-200" />
                    <button type="button" data-toggle-pw="confirm_password" aria-label="Toggle password visibility"
                      class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-teal-600/60 hover:text-teal-600 transition-colors">
                      <i data-lucide="eye" class="w-5 h-5"></i>
                    </button>
                  </div>
                </div>

                <button type="submit"
                  class="w-full inline-flex items-center justify-center gap-2 bg-teal-600 text-white px-6 py-3.5 rounded-lg text-sm font-semibold
                         hover:bg-teal-700 transition-colors duration-200 cursor-pointer">
                  <i data-lucide="save" class="w-4 h-4"></i>
                  <span>Update Password</span>
                </button>
              </form>
            <?php endif; ?>

          </div>
        </div>

        <aside class="lg:col-span-2">
          <div class="bg-teal-50 rounded-2xl border-2 border-teal-100 p-6 sm:p-8">
            <h3 class="text-lg font-bold text-teal-900 mb-5">How it works</h3>
            <ol class="space-y-4 text-sm text-teal-900/80">
              <li class="flex gap-3">
                <span class="shrink-0 w-7 h-7 rounded-full bg-white text-teal-600 font-bold flex items-center justify-center text-xs">1</span>
                <span>Enter your <strong>username</strong> and <strong>registered email</strong>.</span>
              </li>
              <li class="flex gap-3">
                <span class="shrink-0 w-7 h-7 rounded-full bg-white text-teal-600 font-bold flex items-center justify-center text-xs">2</span>
                <span>We email you a <strong>6-digit OTP</strong> valid for 10 minutes.</span>
              </li>
              <li class="flex gap-3">
                <span class="shrink-0 w-7 h-7 rounded-full bg-white text-teal-600 font-bold flex items-center justify-center text-xs">3</span>
                <span>Enter the OTP, then set your new password.</span>
              </li>
            </ol>

            <div class="mt-6 pt-6 border-t border-teal-200/60">
              <p class="text-xs text-teal-900/60 font-semibold uppercase tracking-wide mb-1"><?= e($role['supportTag']) ?></p>
              <p class="text-sm font-semibold text-teal-900 break-all"><?= e($role['supportMail']) ?></p>
            </div>

            <div class="mt-5 text-xs text-teal-900/60 flex items-start gap-2">
              <i data-lucide="shield-check" class="w-4 h-4 text-teal-600 shrink-0 mt-0.5"></i>
              <span>For your security, OTPs expire quickly and are single-use only.</span>
            </div>
          </div>
        </aside>

      </div>
    </div>
  </section>
</main>

<footer class="bg-teal-900 text-white">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 text-center text-xs text-teal-200/70">
    <p>&copy; <?= date('Y') ?> Rajagiri College of Social Sciences. All rights reserved.</p>
  </div>
</footer>

<script>
  if (typeof lucide !== 'undefined') lucide.createIcons();

  document.querySelectorAll('[data-toggle-pw]').forEach(btn => {
    btn.addEventListener('click', () => {
      const input = document.getElementById(btn.getAttribute('data-toggle-pw'));
      if (!input) return;
      const isHidden = input.type === 'password';
      input.type = isHidden ? 'text' : 'password';
      const icon = btn.querySelector('i');
      if (icon) {
        icon.setAttribute('data-lucide', isHidden ? 'eye-off' : 'eye');
        if (typeof lucide !== 'undefined') lucide.createIcons({ targets: [icon] });
      }
    });
  });

  (function () {
    const cells = Array.from(document.querySelectorAll('[data-otp-cell]'));
    if (!cells.length) return;
    const hidden = document.getElementById('otpHidden');
    const form   = document.getElementById('otpForm');

    function syncHidden() {
      hidden.value = cells.map(c => c.value || '').join('');
    }

    cells.forEach((cell, idx) => {
      cell.addEventListener('input', (ev) => {
        const v = (ev.target.value || '').replace(/\D/g, '');
        cell.value = v.slice(-1);
        if (v && idx < cells.length - 1) cells[idx + 1].focus();
        syncHidden();
        if (hidden.value.length === 6 && form) form.submit();
      });

      cell.addEventListener('keydown', (ev) => {
        if (ev.key === 'Backspace' && !cell.value && idx > 0) {
          cells[idx - 1].focus();
          cells[idx - 1].value = '';
          syncHidden();
          ev.preventDefault();
        } else if (ev.key === 'ArrowLeft' && idx > 0) {
          cells[idx - 1].focus();
        } else if (ev.key === 'ArrowRight' && idx < cells.length - 1) {
          cells[idx + 1].focus();
        }
      });

      cell.addEventListener('paste', (ev) => {
        ev.preventDefault();
        const text = (ev.clipboardData || window.clipboardData).getData('text') || '';
        const digits = text.replace(/\D/g, '').slice(0, 6).split('');
        digits.forEach((d, i) => { if (cells[i]) cells[i].value = d; });
        syncHidden();
        const next = cells[Math.min(digits.length, cells.length - 1)];
        if (next) next.focus();
        if (hidden.value.length === 6 && form) form.submit();
      });
    });

    cells[0].focus();

    if (form) {
      form.addEventListener('submit', (ev) => {
        syncHidden();
        if (hidden.value.length !== 6) {
          ev.preventDefault();
          cells.find(c => !c.value)?.focus();
        }
      });
    }
  })();
</script>
</body>
</html>
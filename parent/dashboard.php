<?php
/**
 * parent/dashboard.php
 * ---------------------------------------------------------------------------
 * Parent — Grievance Details Dashboard
 * Rajagiri College Grievance Redressal Portal
 *
 * Mirrors student/dashboard.php structure & feature set:
 *   • Auth guard (PARENT only)
 *   • Collapsible sidebar + profile dropdown
 *   • Create / Edit / Dispose / View / Reopen / Feedback
 *   • In-page image/PDF preview overlay
 *   • Live search + client-side pagination
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
// AUTH GUARD (Parent only)
// ---------------------------------------------------------------------------
$sessionRole = isset($_SESSION['role']) ? strtoupper((string) $_SESSION['role']) : '';

if (empty($_SESSION['user_id']) || $sessionRole !== 'PARENT') {
    header('Location: ../login.php?role=parent');
    exit;
}

$userId = (int) $_SESSION['user_id'];

// ---------------------------------------------------------------------------
// DATABASE
// ---------------------------------------------------------------------------
$dbFile  = __DIR__ . '/../db_connect.php';
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

function statusBadge(string $status): string
{
    $status = trim($status);

    $map = [
        'Pending'     => 'bg-amber-100 text-amber-800 border-amber-200',
        'In Progress' => 'bg-blue-100 text-blue-800 border-blue-200',
        'Disposed'    => 'bg-emerald-100 text-emerald-800 border-emerald-200',
        'Closed'      => 'bg-slate-200 text-slate-700 border-slate-300',
        'Reopened'    => 'bg-rose-100 text-rose-800 border-rose-200',
    ];

    $classes = $map[$status] ?? 'bg-slate-100 text-slate-700 border-slate-200';

    return '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider border '
        . $classes . '">' . e($status) . '</span>';
}

// ---------------------------------------------------------------------------
// CSRF
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
// FLASH
// ---------------------------------------------------------------------------
$flashSuccess = '';
$flashError   = '';

// ---------------------------------------------------------------------------
// POST HANDLERS
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $conn instanceof mysqli) {

    $action = (string) ($_POST['action'] ?? '');

    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrfToken, $postedToken)) {
        $_SESSION['flash_error'] = 'Invalid session token. Please refresh and try again.';
        header('Location: dashboard.php');
        exit;
    }

    // CREATE
    if ($action === 'create_grievance') {
        $targetPath = null;
        try {
            $grievanceTypeId = (int) ($_POST['grievance_type_id'] ?? 0);
            $subject         = trim((string) ($_POST['subject']       ?? ''));
            $description     = trim((string) ($_POST['description']   ?? ''));

            if ($grievanceTypeId <= 0) throw new Exception('Please select a valid Grievance Type.');
            if ($subject === '')       throw new Exception('Subject is required.');
            if (mb_strlen($subject, 'UTF-8') > 120)     throw new Exception('Subject cannot exceed 120 characters.');
            if (mb_strlen($description, 'UTF-8') > 420) throw new Exception('Description cannot exceed 420 characters.');

            $chkType = $conn->prepare("SELECT id FROM grievance_types WHERE id = ? AND status = 'Active' LIMIT 1");
            $chkType->bind_param('i', $grievanceTypeId);
            $chkType->execute();
            if ($chkType->get_result()->num_rows === 0) { $chkType->close(); throw new Exception('Selected Grievance Type is invalid or inactive.'); }
            $chkType->close();

            $yearPrefix      = 'GRV-' . date('Y') . '-';
            $grievanceNumber = '';
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $candidate = $yearPrefix . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
                $chkNum = $conn->prepare("SELECT id FROM grievances WHERE grievance_number = ? LIMIT 1");
                $chkNum->bind_param('s', $candidate);
                $chkNum->execute();
                if ($chkNum->get_result()->num_rows === 0) { $grievanceNumber = $candidate; $chkNum->close(); break; }
                $chkNum->close();
            }
            if ($grievanceNumber === '') throw new Exception('Unable to generate a unique grievance number.');

            $attachmentPath = null;

            if (!empty($_FILES['attachment']['name'])) {
                $file = $_FILES['attachment'];
                if ($file['error'] === UPLOAD_ERR_OK) {
                    $maxBytes = 5 * 1024 * 1024;
                    if ((int) $file['size'] > $maxBytes) throw new Exception('Attachment exceeds the maximum allowed size of 5 MB.');

                    $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
                    $ext        = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowedExt, true)) throw new Exception('Invalid file type. Allowed: PDF, JPG, JPEG, PNG, DOC, DOCX.');

                    $uploadDir = __DIR__ . '/../uploads/grievances/';
                    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);
                    if (!is_dir($uploadDir) || !is_writable($uploadDir)) throw new Exception('Upload directory is not writable. Please contact support.');

                    $safeBase = preg_replace('/[^A-Za-z0-9_\-]/', '_', pathinfo((string) $file['name'], PATHINFO_FILENAME));
                    if ($safeBase === '' || $safeBase === null) $safeBase = 'file';
                    $safeBase = substr($safeBase, 0, 60);

                    $newFileName = 'grv_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '_' . $safeBase . '.' . $ext;
                    $targetPath  = $uploadDir . $newFileName;

                    if (!move_uploaded_file($file['tmp_name'], $targetPath)) throw new Exception('Failed to save the uploaded attachment.');

                    $attachmentPath = 'uploads/grievances/' . $newFileName;
                } elseif ($file['error'] !== UPLOAD_ERR_NO_FILE) {
                    throw new Exception('File upload error (code ' . (int) $file['error'] . ').');
                }
            }

            $initialStatus = 'Pending';
            $sql = "INSERT INTO grievances
                        (grievance_number, grievance_type_id, complainant_user_id,
                         subject, description, attachment_path, status, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
            $stmt = $conn->prepare($sql);
            if (!$stmt) throw new Exception('Query preparation failed: ' . $conn->error);

            $stmt->bind_param('siissss',
                $grievanceNumber, $grievanceTypeId, $userId,
                $subject, $description, $attachmentPath, $initialStatus
            );
            if (!$stmt->execute()) throw new Exception('Failed to submit grievance: ' . $stmt->error);
            $stmt->close();

            $_SESSION['flash_success'] = 'Grievance submitted successfully. Your reference number is ' . $grievanceNumber . '.';

        } catch (Throwable $ex) {
            if (!empty($targetPath) && file_exists($targetPath)) @unlink($targetPath);
            error_log('[Parent Create Grievance] ' . $ex->getMessage());
            $_SESSION['flash_error'] = $ex->getMessage() ?: 'A system error occurred while submitting your grievance.';
        }

        header('Location: dashboard.php');
        exit;
    }

    // UPDATE
    if ($action === 'update_grievance') {
        try {
            $grievanceId     = (int) ($_POST['grievance_id']      ?? 0);
            $grievanceTypeId = (int) ($_POST['grievance_type_id'] ?? 0);
            $subject         = trim((string) ($_POST['subject']       ?? ''));
            $description     = trim((string) ($_POST['description']   ?? ''));

            if ($grievanceId <= 0) throw new Exception('Invalid grievance reference.');
            if ($grievanceTypeId <= 0) throw new Exception('Please select a valid Grievance Type.');
            if ($subject === '') throw new Exception('Subject is required.');
            if (mb_strlen($subject, 'UTF-8') > 120)     throw new Exception('Subject cannot exceed 120 characters.');
            if (mb_strlen($description, 'UTF-8') > 420) throw new Exception('Description cannot exceed 420 characters.');

            $chk = $conn->prepare("SELECT id, status FROM grievances WHERE id = ? AND complainant_user_id = ? LIMIT 1");
            $chk->bind_param('ii', $grievanceId, $userId);
            $chk->execute();
            $res = $chk->get_result();
            if ($res->num_rows === 0) { $chk->close(); throw new Exception('Grievance not found.'); }
            $cur = (string) ($res->fetch_assoc()['status'] ?? '');
            $chk->close();

            if (!in_array($cur, ['Pending', 'Reopened'], true)) {
                throw new Exception('This grievance can no longer be edited (current status: ' . $cur . ').');
            }

            $chkType = $conn->prepare("SELECT id FROM grievance_types WHERE id = ? AND status = 'Active' LIMIT 1");
            $chkType->bind_param('i', $grievanceTypeId);
            $chkType->execute();
            if ($chkType->get_result()->num_rows === 0) { $chkType->close(); throw new Exception('Invalid grievance type.'); }
            $chkType->close();

            $stmt = $conn->prepare("UPDATE grievances
                                    SET grievance_type_id = ?, subject = ?, description = ?, updated_at = NOW()
                                    WHERE id = ? AND complainant_user_id = ?");
            $stmt->bind_param('issii', $grievanceTypeId, $subject, $description, $grievanceId, $userId);
            if (!$stmt->execute()) throw new Exception('Failed to update grievance: ' . $stmt->error);
            $stmt->close();

            $_SESSION['flash_success'] = 'Grievance updated successfully.';

        } catch (Throwable $ex) {
            error_log('[Parent Update Grievance] ' . $ex->getMessage());
            $_SESSION['flash_error'] = $ex->getMessage() ?: 'A system error occurred.';
        }

        header('Location: dashboard.php');
        exit;
    }

    // DISPOSE
    if ($action === 'dispose_grievance') {
        try {
            $grievanceId = (int) ($_POST['grievance_id'] ?? 0);
            if ($grievanceId <= 0) throw new Exception('Invalid grievance reference.');

            $chk = $conn->prepare("SELECT id, status FROM grievances WHERE id = ? AND complainant_user_id = ? LIMIT 1");
            $chk->bind_param('ii', $grievanceId, $userId);
            $chk->execute();
            $res = $chk->get_result();
            if ($res->num_rows === 0) { $chk->close(); throw new Exception('Grievance not found.'); }
            $cur = (string) ($res->fetch_assoc()['status'] ?? '');
            $chk->close();

            if (!in_array($cur, ['Pending', 'In Progress', 'Reopened'], true)) {
                throw new Exception('This grievance cannot be disposed (current status: ' . $cur . ').');
            }

            $newStatus = 'Disposed';
            $stmt = $conn->prepare("UPDATE grievances SET status = ?, updated_at = NOW() WHERE id = ? AND complainant_user_id = ?");
            $stmt->bind_param('sii', $newStatus, $grievanceId, $userId);
            if (!$stmt->execute()) throw new Exception('Failed to dispose: ' . $stmt->error);
            $stmt->close();

            $_SESSION['flash_success'] = 'Grievance has been marked as Disposed.';

        } catch (Throwable $ex) {
            error_log('[Parent Dispose Grievance] ' . $ex->getMessage());
            $_SESSION['flash_error'] = $ex->getMessage() ?: 'A system error occurred.';
        }

        header('Location: dashboard.php');
        exit;
    }

    // REOPEN
    if ($action === 'reopen_grievance') {
        try {
            $grievanceId = (int) ($_POST['grievance_id'] ?? 0);
            $reason      = trim((string) ($_POST['reopen_reason'] ?? ''));

            if ($grievanceId <= 0) throw new Exception('Invalid grievance reference.');
            if ($reason === '') throw new Exception('Please describe why you are reopening this grievance.');
            if (mb_strlen($reason, 'UTF-8') > 120) throw new Exception('Reopen reason cannot exceed 120 characters.');

            $chk = $conn->prepare("SELECT id, status FROM grievances WHERE id = ? AND complainant_user_id = ? LIMIT 1");
            $chk->bind_param('ii', $grievanceId, $userId);
            $chk->execute();
            $res = $chk->get_result();
            if ($res->num_rows === 0) { $chk->close(); throw new Exception('Grievance not found.'); }
            $cur = (string) ($res->fetch_assoc()['status'] ?? '');
            $chk->close();

            if (!in_array($cur, ['Disposed', 'Closed'], true)) {
                throw new Exception('Only Disposed or Closed grievances can be reopened (current: ' . $cur . ').');
            }

            $newStatus = 'Reopened';
            $stmt = $conn->prepare("UPDATE grievances
                                    SET status        = ?,
                                        reopen_reason = ?,
                                        updated_at    = NOW()
                                    WHERE id = ? AND complainant_user_id = ?");
            $stmt->bind_param('ssii', $newStatus, $reason, $grievanceId, $userId);
            if (!$stmt->execute()) throw new Exception('Failed to reopen: ' . $stmt->error);
            $stmt->close();

            $_SESSION['flash_success'] = 'Grievance reopened successfully. The committee will review your reason.';

        } catch (Throwable $ex) {
            error_log('[Parent Reopen Grievance] ' . $ex->getMessage());
            $_SESSION['flash_error'] = $ex->getMessage() ?: 'A system error occurred.';
        }

        header('Location: dashboard.php');
        exit;
    }

    // FEEDBACK
    if ($action === 'feedback_grievance') {
        try {
            $grievanceId = (int) ($_POST['grievance_id'] ?? 0);
            $feedback    = trim((string) ($_POST['feedback'] ?? ''));

            if ($grievanceId <= 0) throw new Exception('Invalid grievance reference.');
            if ($feedback === '') throw new Exception('Feedback text is required.');
            if (mb_strlen($feedback, 'UTF-8') > 1000) throw new Exception('Feedback cannot exceed 1000 characters.');

            $chk = $conn->prepare("SELECT id, status FROM grievances WHERE id = ? AND complainant_user_id = ? LIMIT 1");
            $chk->bind_param('ii', $grievanceId, $userId);
            $chk->execute();
            $res = $chk->get_result();
            if ($res->num_rows === 0) { $chk->close(); throw new Exception('Grievance not found.'); }
            $cur = (string) ($res->fetch_assoc()['status'] ?? '');
            $chk->close();

            if ($cur !== 'Closed') {
                throw new Exception('Feedback can only be submitted for Closed grievances (current: ' . $cur . ').');
            }

            $stmt = $conn->prepare("UPDATE grievances SET feedback_details = ?, updated_at = NOW() WHERE id = ? AND complainant_user_id = ?");
            $stmt->bind_param('sii', $feedback, $grievanceId, $userId);
            if (!$stmt->execute()) throw new Exception('Failed to save feedback: ' . $stmt->error);
            $stmt->close();

            $_SESSION['flash_success'] = 'Thank you! Your feedback has been recorded.';

        } catch (Throwable $ex) {
            error_log('[Parent Feedback Grievance] ' . $ex->getMessage());
            $_SESSION['flash_error'] = $ex->getMessage() ?: 'A system error occurred.';
        }

        header('Location: dashboard.php');
        exit;
    }
}

// ---------------------------------------------------------------------------
// PICK UP FLASHES
// ---------------------------------------------------------------------------
if (!empty($_SESSION['flash_success'])) {
    $flashSuccess = (string) $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    $flashError = (string) $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// ---------------------------------------------------------------------------
// PARENT PROFILE
// ---------------------------------------------------------------------------
$parentData = [
    'username'      => $_SESSION['username'] ?? 'Parent',
    'name'          => '',
    'email'         => '',
    'profile_image' => '',
];

if ($conn instanceof mysqli) {
    try {
        $stmt = $conn->prepare(
            "SELECT u.username, p.name, p.email, p.profile_image
             FROM users u
             LEFT JOIN parents p ON p.user_id = u.id
             WHERE u.id = ? LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();
                $parentData['username']      = $row['username']      ?? $parentData['username'];
                $parentData['name']          = $row['name']          ?? '';
                $parentData['email']         = $row['email']         ?? '';
                $parentData['profile_image'] = $row['profile_image'] ?? '';
            }
            $stmt->close();
        }
    } catch (Throwable $ex) {
        error_log('[Parent Dashboard Profile] ' . $ex->getMessage());
    }
}

$displayName  = !empty($parentData['name']) ? $parentData['name'] : $parentData['username'];
$displayEmail = !empty($parentData['email']) ? $parentData['email'] : 'parent@rajagiri.edu';

$hasProfilePicture = false;
$profilePictureUrl = '';
if (!empty($parentData['profile_image'])) {
    $rel = ltrim((string) $parentData['profile_image'], '/');
    if (file_exists(__DIR__ . '/../' . $rel) && is_file(__DIR__ . '/../' . $rel)) {
        $hasProfilePicture = true;
        $profilePictureUrl = '../' . $rel;
    }
}

// ---------------------------------------------------------------------------
// GRIEVANCE TYPES
// ---------------------------------------------------------------------------
$grievanceTypes = [];
if ($conn instanceof mysqli) {
    try {
        $res = $conn->query("SELECT id, type_name FROM grievance_types WHERE status = 'Active' ORDER BY type_name ASC");
        if ($res) while ($row = $res->fetch_assoc()) $grievanceTypes[] = $row;
    } catch (Throwable $ex) {
        error_log('[Parent Dashboard Grievance Types] ' . $ex->getMessage());
    }
}

// ---------------------------------------------------------------------------
// FETCH GRIEVANCES
// ---------------------------------------------------------------------------
$grievances = [];

if ($conn instanceof mysqli) {
    try {
        $sql = "SELECT  g.id,
                        g.grievance_number,
                        g.subject,
                        g.description,
                        g.attachment_path,
                        g.reply_details,
                        g.reply_attachment_path,
                        g.feedback_details,
                        g.reopen_reason,
                        g.status,
                        g.created_at,
                        g.updated_at,
                        g.grievance_type_id,
                        gt.type_name
                FROM grievances g
                LEFT JOIN grievance_types gt ON gt.id = g.grievance_type_id
                WHERE g.complainant_user_id = ?
                ORDER BY g.created_at DESC";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) $grievances[] = $row;
            $stmt->close();
        }
    } catch (Throwable $ex) {
        error_log('[Parent Dashboard Grievances] ' . $ex->getMessage());
    }
}

$totalGrievances = count($grievances);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Grievance Details — Parent | Rajagiri College Grievance Portal</title>
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
            flashOut: { '0%': { opacity: '1', transform: 'translateY(0)', maxHeight: '200px' }, '100%': { opacity: '0', transform: 'translateY(-10px)', maxHeight: '0px' } }
          },
          animation: {
            'fade-in-up': 'fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'dropdown':   'dropdownFade 0.2s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'modal-in':   'modalFadeIn 0.25s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'confirm-shake': 'confirmShake 0.5s cubic-bezier(0.16, 1, 0.3, 1)',
            'flash-in':   'flashIn 0.35s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'flash-out':  'flashOut 0.45s cubic-bezier(0.4, 0, 1, 1) forwards'
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
    <aside id="parentSidebar"
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

        <a href="dashboard.php" class="group relative w-full h-12 rounded-xl bg-white text-teal-800 shadow-md flex items-center px-3 flex-shrink-0 transition-all">
          <i data-lucide="home" class="w-6 h-6 flex-shrink-0"></i>
          <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap opacity-0 w-0 overflow-hidden transition-all duration-200">Dashboard</span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-teal-900 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Dashboard</span>
        </a>

        <a href="profile.php" class="group relative w-full h-12 rounded-xl hover:bg-white/10 flex items-center text-white transition-all px-3 flex-shrink-0">
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

    <!-- MAIN -->
    <div id="parentMain" class="flex-1 ml-20 flex flex-col min-h-screen transition-all duration-300">

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

          <div class="relative" id="parent-dropdown-container">
            <button id="parent-dropdown-btn" type="button" aria-haspopup="true" aria-expanded="false"
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
              <i data-lucide="chevron-down" id="parent-chevron" class="w-4 h-4 text-teal-600 transition-transform duration-300"></i>
            </button>

            <div id="parent-dropdown-menu" class="hidden absolute right-0 mt-2 w-64 bg-white rounded-xl shadow-xl border border-teal-100 py-2 z-50 overflow-hidden">
              <div class="px-4 py-3 border-b border-teal-100 bg-teal-50/60">
                <div class="flex items-center gap-3">
                  <?php if ($hasProfilePicture): ?>
                    <img src="<?= e($profilePictureUrl) ?>" alt="<?= e($displayName) ?>" class="w-12 h-12 rounded-full object-cover border-2 border-teal-600" />
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
                <a href="#" data-logout-trigger="1" id="dropdownLogoutBtn" class="flex items-center px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 transition-all group">
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

        <?php if ($dbError): ?>
          <div class="max-w-7xl mx-auto mb-6 rounded-xl border-2 border-amber-200 bg-amber-50 px-4 py-3 flex items-start gap-2">
            <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-amber-700"><?= e($dbError) ?></p>
          </div>
        <?php endif; ?>

        <div class="max-w-7xl mx-auto mb-6 animate-fade-in-up">
          <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
              <div class="flex items-center gap-2 mb-2">
                <span class="inline-flex items-center justify-center w-2 h-2 rounded-full bg-teal-600"></span>
                <p class="text-xs font-semibold text-teal-600 uppercase tracking-wider">Parent Portal</p>
              </div>
              <h1 class="text-2xl md:text-3xl font-bold text-teal-900 mb-2">Grievance Details</h1>
              <nav class="flex flex-wrap items-center gap-2 text-sm text-teal-900/60">
                <a href="dashboard.php" class="inline-flex items-center gap-1 hover:text-teal-600 transition-colors">
                  <i data-lucide="layout-dashboard" class="w-4 h-4"></i> Dashboard
                </a>
                <span class="text-teal-900/30">/</span>
                <a href="dashboard.php" class="hover:text-teal-600 transition-colors">Grievance</a>
                <span class="text-teal-900/30">/</span>
                <span class="text-teal-600 font-semibold">Grievance Details</span>
              </nav>
            </div>

            <button type="button" onclick="openCreateGrievanceModal()"
                    class="inline-flex items-center justify-center gap-2 px-5 py-3 rounded-lg font-semibold text-white
                           bg-teal-600 hover:bg-teal-700 shadow-sm hover:shadow-md
                           transition-all duration-200 cursor-pointer shrink-0">
              <i data-lucide="plus" class="w-5 h-5"></i>
              <span>Add Grievance</span>
            </button>
          </div>
        </div>

        <?php if ($flashSuccess !== ''): ?>
          <div id="flashSuccessBox" class="max-w-7xl mx-auto mb-6 rounded-xl border-2 border-emerald-200 bg-emerald-50 px-4 py-3 flex items-start gap-2 animate-flash-in overflow-hidden">
            <i data-lucide="check-circle" class="w-5 h-5 text-emerald-600 flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-emerald-800 font-medium"><?= e($flashSuccess) ?></p>
          </div>
        <?php endif; ?>

        <?php if ($flashError !== ''): ?>
          <div id="flashErrorBox" class="max-w-7xl mx-auto mb-6 rounded-xl border-2 border-red-200 bg-red-50 px-4 py-3 flex items-start gap-2 animate-flash-in overflow-hidden">
            <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-red-700 font-medium"><?= e($flashError) ?></p>
          </div>
        <?php endif; ?>

        <!-- TABLE CONTROLS -->
        <div class="max-w-7xl mx-auto mb-5 animate-fade-in-up" style="animation-delay: 60ms;">
          <div class="bg-white rounded-xl border-2 border-teal-100 px-5 py-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
              <div class="flex items-center gap-3">
                <span class="text-sm text-teal-900/70">Show</span>
                <select id="entriesPerPage"
                        class="px-3 py-1.5 border-2 border-teal-100 rounded-lg text-sm font-medium text-teal-900
                               focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10
                               hover:border-teal-200 transition-colors bg-white">
                  <option value="10" selected>10</option>
                  <option value="25">25</option>
                  <option value="50">50</option>
                  <option value="100">100</option>
                </select>
                <span class="text-sm text-teal-900/70">entries</span>
              </div>

              <div class="relative w-full sm:w-80">
                <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-teal-900/40"></i>
                <input type="text" id="searchInput" placeholder="Search..." autocomplete="off"
                       class="w-full pl-10 pr-4 py-2 border-2 border-teal-100 rounded-lg text-sm bg-white text-teal-900 placeholder-teal-900/40
                              focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10
                              hover:border-teal-200 transition-all" />
              </div>
            </div>
          </div>
        </div>

        <!-- DATA TABLE -->
        <div class="max-w-7xl mx-auto animate-fade-in-up" style="animation-delay: 100ms;">
          <div class="bg-white rounded-2xl border-2 border-teal-100 overflow-hidden">

            <div class="overflow-x-auto">
              <table class="w-full table-fixed" id="grievancesTable">
                <colgroup>
                  <col style="width: 4%;">
                  <col style="width: 13%;">
                  <col style="width: 13%;">
                  <col style="width: 9%;">
                  <col style="width: 20%;">
                  <col style="width: 9%;">
                  <col style="width: 12%;">
                  <col style="width: 20%;">
                </colgroup>
                <thead>
                  <tr class="bg-teal-600 text-white">
                    <th class="px-2 py-3 text-left text-[10px] font-bold uppercase tracking-wider">Sl.No.</th>
                    <th class="px-2 py-3 text-left text-[10px] font-bold uppercase tracking-wider">Grievance Number</th>
                    <th class="px-2 py-3 text-left text-[10px] font-bold uppercase tracking-wider">Grievance Type</th>
                    <th class="px-2 py-3 text-left text-[10px] font-bold uppercase tracking-wider">Date</th>
                    <th class="px-2 py-3 text-left text-[10px] font-bold uppercase tracking-wider">Subject</th>
                    <th class="px-2 py-3 text-left text-[10px] font-bold uppercase tracking-wider">Status</th>
                    <th class="px-2 py-3 text-center text-[10px] font-bold uppercase tracking-wider">Actions</th>
                    <th class="px-2 py-3 text-center text-[10px] font-bold uppercase tracking-wider">Reopen</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-teal-100" id="grievancesTableBody">

                  <?php if (empty($grievances)): ?>
                    <tr>
                      <td colspan="8" class="px-4 py-16 text-center text-teal-900/60">
                        <div class="flex flex-col items-center justify-center">
                          <div class="w-16 h-16 bg-teal-50 rounded-2xl flex items-center justify-center mb-4">
                            <i data-lucide="inbox" class="w-8 h-8 text-teal-600"></i>
                          </div>
                          <p class="text-lg font-semibold text-teal-900">No data available in table</p>
                          <p class="text-sm text-teal-900/60 mt-1">Click the "+" button above to submit your first grievance.</p>
                        </div>
                      </td>
                    </tr>
                  <?php else: ?>

                    <?php foreach ($grievances as $index => $row): ?>
                      <?php
                        $gId          = (int) $row['id'];
                        $gTypeId      = (int) ($row['grievance_type_id'] ?? 0);
                        $gNumber      = (string) ($row['grievance_number'] ?? '—');
                        $gType        = (string) ($row['type_name']        ?? '—');
                        $gSubject     = (string) ($row['subject']          ?? '—');
                        $gDesc        = (string) ($row['description']      ?? '');
                        $gReply       = (string) ($row['reply_details']    ?? '');
                        $gFeedback    = (string) ($row['feedback_details'] ?? '');
                        $gReopen      = (string) ($row['reopen_reason']    ?? '');
                        $gAttach      = (string) ($row['attachment_path']  ?? '');
                        $gReplyAttach = (string) ($row['reply_attachment_path'] ?? '');
                        $gStatus      = (string) ($row['status']           ?? 'Pending');
                        $gCreatedRaw  = (string) ($row['created_at']       ?? '');
                        $gCreated     = !empty($gCreatedRaw) ? date('d M y', strtotime($gCreatedRaw)) : '—';

                        $canEdit     = in_array($gStatus, ['Pending', 'Reopened'], true);
                        $canDispose  = in_array($gStatus, ['Pending', 'In Progress', 'Reopened'], true);
                        $canFeedback = ($gStatus === 'Closed' && trim($gFeedback) === '');
                        $canReopen   = in_array($gStatus, ['Disposed', 'Closed'], true);

                        $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];

                        $attachUrl = ''; $attachExt = ''; $attachIsImg = false;
                        if ($gAttach !== '') {
                            $rel = ltrim($gAttach, '/');
                            if (file_exists(__DIR__ . '/../' . $rel)) {
                                $attachUrl   = '../' . $rel;
                                $attachExt   = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
                                $attachIsImg = in_array($attachExt, $imageExts, true);
                            }
                        }

                        $replyAttachUrl = ''; $replyAttachExt = ''; $replyAttachIsImg = false;
                        if ($gReplyAttach !== '') {
                            $rel2 = ltrim($gReplyAttach, '/');
                            if (file_exists(__DIR__ . '/../' . $rel2)) {
                                $replyAttachUrl   = '../' . $rel2;
                                $replyAttachExt   = strtolower(pathinfo($rel2, PATHINFO_EXTENSION));
                                $replyAttachIsImg = in_array($replyAttachExt, $imageExts, true);
                            }
                        }
                      ?>
                      <tr class="hover:bg-teal-50/60 transition-colors align-middle">

                        <td class="px-2 py-4 text-xs font-medium text-teal-900/70"><?= $index + 1 ?></td>
                        <td class="px-2 py-4 text-xs font-semibold text-teal-700 break-words"><?= e($gNumber) ?></td>
                        <td class="px-2 py-4 text-xs text-teal-900/80 break-words"><?= e($gType) ?></td>
                        <td class="px-2 py-4 text-xs text-teal-900/70 whitespace-nowrap"><?= e($gCreated) ?></td>
                        <td class="px-2 py-4 text-xs text-teal-900/80 break-words" title="<?= e($gSubject) ?>"><?= e($gSubject) ?></td>
                        <td class="px-2 py-4 whitespace-nowrap"><?= statusBadge($gStatus) ?></td>

                        <td class="px-2 py-4">
                          <div class="flex items-center justify-center gap-1">

                            <button type="button" title="View grievance"
                                    data-view-trigger="1"
                                    data-grievance='<?= e(json_encode([
                                        "number"              => $gNumber,
                                        "type"                => $gType,
                                        "subject"             => $gSubject,
                                        "description"         => $gDesc,
                                        "status"              => $gStatus,
                                        "reply"               => $gReply,
                                        "feedback"            => $gFeedback,
                                        "reopen_reason"       => $gReopen,
                                        "date"                => $gCreated,
                                        "attach_url"          => $attachUrl,
                                        "attach_ext"          => $attachExt,
                                        "attach_is_img"       => $attachIsImg,
                                        "reply_attach_url"    => $replyAttachUrl,
                                        "reply_attach_ext"    => $replyAttachExt,
                                        "reply_attach_is_img" => $replyAttachIsImg,
                                    ], JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'
                                    class="w-7 h-7 rounded-lg bg-teal-50 hover:bg-teal-600
                                           inline-flex items-center justify-center text-teal-600 hover:text-white
                                           transition-colors duration-200 flex-shrink-0">
                              <i data-lucide="eye" class="w-3.5 h-3.5 pointer-events-none"></i>
                            </button>

                            <?php if ($canEdit): ?>
                              <button type="button" title="Edit grievance"
                                      data-edit-trigger="1"
                                      data-grievance-id="<?= $gId ?>"
                                      data-grievance-type-id="<?= $gTypeId ?>"
                                      data-subject="<?= e($gSubject) ?>"
                                      data-description="<?= e($gDesc) ?>"
                                      class="w-7 h-7 rounded-lg bg-sky-50 hover:bg-sky-600
                                             inline-flex items-center justify-center text-sky-700 hover:text-white
                                             transition-colors duration-200 flex-shrink-0">
                                <i data-lucide="pencil" class="w-3.5 h-3.5 pointer-events-none"></i>
                              </button>
                            <?php endif; ?>

                            <?php if ($canDispose): ?>
                              <button type="button" title="Dispose grievance"
                                      data-dispose-trigger="1"
                                      data-grievance-id="<?= $gId ?>"
                                      data-grievance-number="<?= e($gNumber) ?>"
                                      class="w-7 h-7 rounded-lg bg-red-50 hover:bg-red-500
                                             inline-flex items-center justify-center text-red-500 hover:text-white
                                             transition-colors duration-200 flex-shrink-0">
                                <i data-lucide="x" class="w-3.5 h-3.5 pointer-events-none"></i>
                              </button>
                            <?php endif; ?>

                            <?php if ($canFeedback): ?>
                              <button type="button" title="Give Feedback"
                                      data-feedback-trigger="1"
                                      data-grievance-id="<?= $gId ?>"
                                      data-grievance-number="<?= e($gNumber) ?>"
                                      class="w-7 h-7 rounded-lg bg-emerald-50 hover:bg-emerald-600
                                             inline-flex items-center justify-center text-emerald-700 hover:text-white
                                             transition-colors duration-200 flex-shrink-0">
                                <i data-lucide="message-square" class="w-3.5 h-3.5 pointer-events-none"></i>
                              </button>
                            <?php endif; ?>

                          </div>
                        </td>

                        <td class="px-2 py-4 text-center">
                          <?php if ($canReopen): ?>
                            <button type="button"
                                    data-reopen-trigger="1"
                                    data-grievance-id="<?= $gId ?>"
                                    data-grievance-number="<?= e($gNumber) ?>"
                                    class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-[10px] font-bold
                                           bg-pink-50 hover:bg-pink-500 text-pink-700 hover:text-white
                                           border border-pink-200 hover:border-pink-500
                                           transition-colors duration-200 whitespace-nowrap">
                              <i data-lucide="rotate-ccw" class="w-3 h-3 pointer-events-none"></i>
                              <span class="pointer-events-none">Reopen</span>
                            </button>
                          <?php else: ?>
                            <span class="text-xs text-teal-900/40 italic">—</span>
                          <?php endif; ?>
                        </td>

                      </tr>
                    <?php endforeach; ?>

                  <?php endif; ?>

                </tbody>
              </table>
            </div>

            <div class="px-4 py-4 bg-teal-50/50 border-t border-teal-100 flex flex-col sm:flex-row items-center justify-between gap-4">
              <p class="text-sm text-teal-900/70">
                Showing
                <span class="font-semibold text-teal-900" id="infoStart"><?= $totalGrievances > 0 ? 1 : 0 ?></span>
                to
                <span class="font-semibold text-teal-900" id="infoEnd"><?= $totalGrievances ?></span>
                of
                <span class="font-semibold text-teal-900" id="infoTotal"><?= $totalGrievances ?></span>
                entries
              </p>

              <div class="flex items-center gap-2">
                <button type="button" id="prevPageBtn"
                        class="px-4 py-2 rounded-lg text-sm font-medium text-teal-900 bg-white
                               hover:bg-teal-50 border-2 border-teal-200
                               disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                        disabled>Previous</button>
                <span id="currentPageBadge"
                      class="inline-flex items-center justify-center w-9 h-9 rounded-lg
                             bg-teal-600 text-white text-sm font-bold shadow-sm">1</span>
                <button type="button" id="nextPageBtn"
                        class="px-4 py-2 rounded-lg text-sm font-medium text-teal-900 bg-white
                               hover:bg-teal-50 border-2 border-teal-200
                               disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                        disabled>Next</button>
              </div>
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
                    <i data-lucide="mail" class="w-4 h-4 text-teal-300"></i><span>parent.grievance@rajagiri.edu</span>
                  </li>
                  <li class="flex items-center gap-2">
                    <i data-lucide="phone" class="w-4 h-4 text-teal-300"></i><span>+91 484 XXX XXXX</span>
                  </li>
                  <li class="flex items-center gap-2">
                    <i data-lucide="map-pin" class="w-4 h-4 text-teal-300"></i><span>Kalamassery, Kochi, Kerala</span>
                  </li>
                </ul>
              </div>
            </div>

            <div class="border-t border-white/10 pt-4">
              <div class="flex flex-col sm:flex-row items-center justify-between gap-2">
                <p class="text-xs text-teal-200/70 text-center sm:text-left">
                  &copy; <?= date('Y') ?> <span class="font-bold text-white">Rajagiri College of Social Sciences</span>. All rights reserved.
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

  <!-- ============================================================ -->
  <!-- CREATE MODAL -->
  <!-- ============================================================ -->
  <div id="createGrievanceModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeCreateGrievanceModal()"></div>

    <div class="relative w-full max-w-2xl bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden max-h-[92vh] flex flex-col">
      <div class="h-1.5 w-full bg-teal-600"></div>

      <div class="flex items-center justify-between px-6 py-4 border-b border-teal-100">
        <h3 class="text-lg md:text-xl font-bold text-teal-900">Create Grievance</h3>
        <button type="button" onclick="closeCreateGrievanceModal()" class="w-8 h-8 rounded-lg hover:bg-teal-50 flex items-center justify-center text-teal-900/60 transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form id="createGrievanceForm" method="POST" action="dashboard.php" enctype="multipart/form-data" class="p-6 space-y-5 overflow-y-auto flex-1">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />
        <input type="hidden" name="action" value="create_grievance" />

        <div class="space-y-2">
          <label for="grievance_type_id" class="block text-sm font-semibold text-teal-900">Grievance Type <span class="text-red-500">*</span></label>
          <select id="grievance_type_id" name="grievance_type_id" required
                  class="w-full px-4 py-3 border-2 border-teal-100 rounded-lg bg-teal-50/40 text-teal-900 font-medium text-sm
                         focus:outline-none focus:border-teal-600 focus:bg-white focus:ring-4 focus:ring-teal-600/10
                         hover:border-teal-200 transition-all">
            <option value="" disabled selected>Select Grievance Type</option>
            <?php foreach ($grievanceTypes as $type): ?>
              <option value="<?= (int) $type['id'] ?>"><?= e((string) $type['type_name']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (empty($grievanceTypes)): ?>
            <p class="text-xs text-amber-600 font-medium mt-1">No active grievance types are configured. Please contact the administrator.</p>
          <?php endif; ?>
        </div>

        <div class="space-y-2">
          <label for="subject" class="block text-sm font-semibold text-teal-900">Subject <span class="text-red-500">*</span></label>
          <input type="text" id="subject" name="subject" required maxlength="120" placeholder="Enter a brief subject"
                 class="w-full px-4 py-3 border-2 border-teal-100 rounded-lg bg-teal-50/40 text-teal-900 font-medium text-sm placeholder-teal-900/40
                        focus:outline-none focus:border-teal-600 focus:bg-white focus:ring-4 focus:ring-teal-600/10
                        hover:border-teal-200 transition-all" />
          <p class="text-xs text-teal-900/60">(Maximum 120 character) · <span id="subjectCounter" class="font-semibold text-teal-900/80">0</span>/120</p>
        </div>

        <div class="space-y-2">
          <label for="description" class="block text-sm font-semibold text-teal-900">Description</label>
          <textarea id="description" name="description" rows="5" maxlength="420" placeholder="Describe your grievance in detail…"
                    class="w-full px-4 py-3 border-2 border-teal-100 rounded-lg bg-teal-50/40 text-teal-900 font-medium text-sm placeholder-teal-900/40 resize-none
                           focus:outline-none focus:border-teal-600 focus:bg-white focus:ring-4 focus:ring-teal-600/10
                           hover:border-teal-200 transition-all"></textarea>
          <p class="text-xs text-teal-900/60">(Maximum 420 character) · <span id="descriptionCounter" class="font-semibold text-teal-900/80">0</span>/420</p>
        </div>

        <div class="space-y-2">
          <label for="attachment" class="block text-sm font-semibold text-teal-900">Attachment</label>

          <div class="flex items-center gap-3 flex-wrap">
            <label for="attachment"
                   class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg border-2 border-teal-100
                          bg-teal-50 hover:bg-teal-100 text-teal-700 font-semibold text-sm
                          cursor-pointer transition-colors">
              <i data-lucide="upload" class="w-4 h-4"></i>
              <span>Choose file</span>
            </label>
            <span id="attachmentFileName" class="text-sm text-teal-900/60 truncate">No file chosen</span>
            <input type="file" id="attachment" name="attachment" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" class="hidden" />
          </div>
          <p class="text-xs text-teal-900/60">Allowed: PDF, JPG, JPEG, PNG, DOC, DOCX (max 5 MB)</p>
        </div>

        <div class="pt-2 flex justify-center">
          <button type="submit"
                  class="inline-flex items-center justify-center gap-2 px-8 py-3 rounded-lg font-semibold text-white
                         bg-teal-600 hover:bg-teal-700 shadow-sm hover:shadow-md
                         transition-all duration-200 cursor-pointer">
            <i data-lucide="send" class="w-4 h-4"></i>
            <span>Submit</span>
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- EDIT MODAL -->
  <div id="editGrievanceModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeEditGrievanceModal()"></div>

    <div class="relative w-full max-w-2xl bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden max-h-[92vh] flex flex-col">
      <div class="h-1.5 w-full bg-teal-600"></div>

      <div class="flex items-center justify-between px-6 py-4 border-b border-teal-100">
        <h3 class="text-lg md:text-xl font-bold text-teal-900">Edit Grievance</h3>
        <button type="button" onclick="closeEditGrievanceModal()" class="w-8 h-8 rounded-lg hover:bg-teal-50 flex items-center justify-center text-teal-900/60 transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form id="editGrievanceForm" method="POST" action="dashboard.php" class="p-6 space-y-5 overflow-y-auto flex-1">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />
        <input type="hidden" name="action" value="update_grievance" />
        <input type="hidden" name="grievance_id" id="editGrievanceId" value="" />

        <div class="space-y-2">
          <label for="edit_grievance_type_id" class="block text-sm font-semibold text-teal-900">Grievance Type <span class="text-red-500">*</span></label>
          <select id="edit_grievance_type_id" name="grievance_type_id" required
                  class="w-full px-4 py-3 border-2 border-teal-100 rounded-lg bg-teal-50/40 text-teal-900 font-medium text-sm
                         focus:outline-none focus:border-teal-600 focus:bg-white focus:ring-4 focus:ring-teal-600/10
                         hover:border-teal-200 transition-all">
            <option value="" disabled>Select Grievance Type</option>
            <?php foreach ($grievanceTypes as $type): ?>
              <option value="<?= (int) $type['id'] ?>"><?= e((string) $type['type_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="space-y-2">
          <label for="edit_subject" class="block text-sm font-semibold text-teal-900">Subject <span class="text-red-500">*</span></label>
          <input type="text" id="edit_subject" name="subject" required maxlength="120" placeholder="Enter a brief subject"
                 class="w-full px-4 py-3 border-2 border-teal-100 rounded-lg bg-teal-50/40 text-teal-900 font-medium text-sm placeholder-teal-900/40
                        focus:outline-none focus:border-teal-600 focus:bg-white focus:ring-4 focus:ring-teal-600/10
                        hover:border-teal-200 transition-all" />
          <p class="text-xs text-teal-900/60">(Maximum 120 character) · <span id="editSubjectCounter" class="font-semibold text-teal-900/80">0</span>/120</p>
        </div>

        <div class="space-y-2">
          <label for="edit_description" class="block text-sm font-semibold text-teal-900">Description</label>
          <textarea id="edit_description" name="description" rows="5" maxlength="420" placeholder="Describe your grievance in detail…"
                    class="w-full px-4 py-3 border-2 border-teal-100 rounded-lg bg-teal-50/40 text-teal-900 font-medium text-sm placeholder-teal-900/40 resize-none
                           focus:outline-none focus:border-teal-600 focus:bg-white focus:ring-4 focus:ring-teal-600/10
                           hover:border-teal-200 transition-all"></textarea>
          <p class="text-xs text-teal-900/60">(Maximum 420 character) · <span id="editDescriptionCounter" class="font-semibold text-teal-900/80">0</span>/420</p>
        </div>

        <div class="pt-2 flex flex-col-reverse sm:flex-row justify-center gap-3">
          <button type="button" onclick="closeEditGrievanceModal()"
                  class="px-6 py-3 rounded-lg font-semibold text-teal-900 bg-white border-2 border-teal-200 hover:border-teal-600 hover:bg-teal-50 transition-all duration-200">
            Cancel
          </button>
          <button type="submit"
                  class="inline-flex items-center justify-center gap-2 px-8 py-3 rounded-lg font-semibold text-white
                         bg-teal-600 hover:bg-teal-700 shadow-sm hover:shadow-md
                         transition-all duration-200 cursor-pointer">
            <i data-lucide="save" class="w-4 h-4"></i>
            <span>Save Changes</span>
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- DISPOSE MODAL -->
  <div id="disposeConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeDisposeModal()"></div>

    <div id="disposeConfirmPanel" class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-teal-600"></div>

      <div class="px-6 pt-6 pb-2 flex flex-col items-center text-center">
        <div class="w-16 h-16 rounded-full flex items-center justify-center mb-4 bg-red-50 ring-4 ring-red-100/60">
          <i data-lucide="x" class="w-8 h-8 text-red-500"></i>
        </div>

        <h3 class="text-xl font-bold text-teal-900 mb-2">Dispose Grievance?</h3>

        <p class="text-sm text-teal-900/70 leading-relaxed">
          You are about to mark
          <span id="disposeGrievanceNumber" class="font-bold text-teal-700 break-words">this grievance</span>
          as <strong class="text-red-600">Disposed</strong>.
        </p>

        <p class="text-xs text-amber-600 font-medium mt-3 flex items-center gap-1.5">
          <i data-lucide="alert-triangle" class="w-3.5 h-3.5"></i>
          The grievance committee will be notified.
        </p>
      </div>

      <form id="disposeForm" method="POST" action="dashboard.php" class="px-6 py-5 mt-2 flex flex-col-reverse sm:flex-row gap-3">
        <input type="hidden" name="grievance_id" id="disposeGrievanceId" value="" />
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />
        <input type="hidden" name="action" value="dispose_grievance" />

        <button type="button" onclick="closeDisposeModal()"
                class="flex-1 px-5 py-3 rounded-lg font-semibold text-teal-900 bg-white border-2 border-teal-200 hover:border-teal-600 hover:bg-teal-50 transition-all duration-200">
          Cancel
        </button>
        <button type="submit"
                class="flex-1 px-5 py-3 rounded-lg font-bold text-white bg-teal-600 hover:bg-teal-700 shadow-sm hover:shadow-md transition-all duration-200 flex items-center justify-center gap-2">
          <i data-lucide="x" class="w-4 h-4"></i>
          <span>Dispose</span>
        </button>
      </form>
    </div>
  </div>

  <!-- VIEW MODAL -->
  <div id="viewGrievanceModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeViewGrievanceModal()"></div>

    <div class="relative w-full max-w-2xl bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden max-h-[90vh] flex flex-col">
      <div class="h-1.5 w-full bg-teal-600"></div>

      <div class="flex items-center justify-between px-6 py-4 border-b border-teal-100">
        <h3 class="text-lg font-bold text-teal-900">Grievance Details</h3>
        <button type="button" onclick="closeViewGrievanceModal()" class="w-8 h-8 rounded-lg hover:bg-teal-50 flex items-center justify-center text-teal-900/60 transition-colors">
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
            <p class="text-[10px] font-bold uppercase tracking-wider text-teal-900/50 mb-1">Submitted On</p>
            <p id="vgDate2" class="text-sm font-semibold text-teal-900">—</p>
          </div>
        </div>

        <div>
          <p class="text-[10px] font-bold uppercase tracking-wider text-teal-900/50 mb-1.5">Description</p>
          <div class="bg-teal-50/60 rounded-xl p-4 border border-teal-100">
            <p id="vgDescription" class="text-sm text-teal-900/80 leading-relaxed whitespace-pre-line break-words">—</p>
          </div>
        </div>

        <div id="vgReopenWrapper" class="hidden">
          <p class="text-[10px] font-bold uppercase tracking-wider text-teal-900/50 mb-1.5">Your Reopen Reason</p>
          <div class="bg-pink-50 rounded-xl p-4 border border-pink-200">
            <p id="vgReopenReason" class="text-sm text-pink-800 leading-relaxed whitespace-pre-line break-words">—</p>
          </div>
        </div>

        <div id="vgAttachmentWrapper" class="hidden">
          <p class="text-[10px] font-bold uppercase tracking-wider text-teal-900/50 mb-1.5">Original Attachment</p>
          <div class="bg-teal-50/60 rounded-xl p-4 border border-teal-100 space-y-3">
            <div class="flex flex-wrap items-center gap-2">
              <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-teal-900">
                <i data-lucide="paperclip" class="w-3.5 h-3.5"></i>
                <span id="vgAttachmentName" class="break-all">attachment</span>
              </span>
            </div>
            <div class="flex flex-wrap items-center gap-2">
              <button type="button" id="vgPreviewBtn" onclick="openPreviewOverlay('original')"
                      class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-bold bg-teal-600 hover:bg-teal-700 text-white shadow-sm transition-colors duration-200">
                <i data-lucide="eye" class="w-3.5 h-3.5"></i>
                <span id="vgPreviewBtnLabel">View</span>
              </button>
              <a id="vgDownloadLink" href="#" target="_blank" rel="noopener"
                 class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-bold bg-white hover:bg-teal-50 text-teal-700 border-2 border-teal-200 hover:border-teal-600 transition-colors duration-200">
                <i data-lucide="download" class="w-3.5 h-3.5"></i>
                <span>Download</span>
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

        <div id="vgReplyAttachWrapper" class="hidden">
          <p class="text-[10px] font-bold uppercase tracking-wider text-teal-900/50 mb-1.5">Reply Attachment</p>
          <div class="bg-emerald-50 rounded-xl p-4 border border-emerald-200 space-y-3">
            <div class="flex flex-wrap items-center gap-2">
              <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-emerald-900">
                <i data-lucide="paperclip" class="w-3.5 h-3.5"></i>
                <span id="vgReplyAttachmentName" class="break-all">attachment</span>
              </span>
            </div>
            <div class="flex flex-wrap items-center gap-2">
              <button type="button" id="vgReplyPreviewBtn" onclick="openPreviewOverlay('reply')"
                      class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-bold bg-emerald-600 hover:bg-emerald-700 text-white shadow-sm transition-colors duration-200">
                <i data-lucide="eye" class="w-3.5 h-3.5"></i>
                <span id="vgReplyPreviewBtnLabel">View</span>
              </button>
              <a id="vgReplyDownloadLink" href="#" target="_blank" rel="noopener"
                 class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-bold bg-white hover:bg-emerald-50 text-emerald-700 border-2 border-emerald-200 hover:border-emerald-600 transition-colors duration-200">
                <i data-lucide="download" class="w-3.5 h-3.5"></i>
                <span>Download</span>
              </a>
            </div>
          </div>
        </div>

        <div id="vgFeedbackWrapper" class="hidden">
          <p class="text-[10px] font-bold uppercase tracking-wider text-teal-900/50 mb-1.5">Your Feedback</p>
          <div class="bg-amber-50 rounded-xl p-4 border border-amber-100">
            <p id="vgFeedback" class="text-sm text-amber-800 leading-relaxed whitespace-pre-line break-words">—</p>
          </div>
        </div>
      </div>

      <div class="px-6 py-4 bg-teal-50/60 border-t border-teal-100 flex justify-end">
        <button type="button" onclick="closeViewGrievanceModal()"
                class="px-5 py-2.5 rounded-lg font-semibold text-teal-900 bg-white border-2 border-teal-200 hover:border-teal-600 hover:bg-teal-50 transition-all duration-200">
          Close
        </button>
      </div>
    </div>
  </div>

  <!-- PREVIEW OVERLAY -->
  <div id="previewOverlay" class="hidden fixed inset-0 z-[80] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/80 backdrop-blur-md" onclick="closePreviewOverlay()"></div>

    <div class="relative w-full max-w-5xl max-h-[92vh] bg-slate-900 rounded-2xl shadow-2xl animate-modal-in overflow-hidden flex flex-col">
      <div class="flex items-center justify-between px-5 py-3 bg-slate-800 border-b border-slate-700">
        <div class="flex items-center gap-2 min-w-0">
          <i data-lucide="image" class="w-4 h-4 text-teal-300 flex-shrink-0"></i>
          <p id="previewFileName" class="text-sm font-semibold text-slate-100 truncate">Attachment</p>
        </div>
        <div class="flex items-center gap-2 flex-shrink-0">
          <a id="previewOpenNewTab" href="#" target="_blank" rel="noopener"
             class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold bg-slate-700 hover:bg-slate-600 text-slate-100 transition-colors">
            <i data-lucide="external-link" class="w-3.5 h-3.5"></i>
            <span>Open in new tab</span>
          </a>
          <button type="button" onclick="closePreviewOverlay()"
                  class="w-8 h-8 rounded-lg bg-slate-700 hover:bg-red-500 flex items-center justify-center text-slate-100 transition-colors" aria-label="Close preview">
            <i data-lucide="x" class="w-4 h-4"></i>
          </button>
        </div>
      </div>

      <div class="flex-1 overflow-auto bg-black/40 flex items-center justify-center p-4">
        <img id="previewImage" src="" alt="Attachment preview" class="max-w-full max-h-[80vh] object-contain rounded-lg shadow-2xl hidden" />
        <iframe id="previewFrame" src="" title="Attachment preview" class="w-full h-[80vh] rounded-lg bg-white hidden"></iframe>
      </div>
    </div>
  </div>

  <!-- FEEDBACK MODAL -->
  <div id="feedbackModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeFeedbackModal()"></div>

    <div id="feedbackPanel" class="relative w-full max-w-lg bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-teal-600"></div>

      <div class="flex items-center justify-between px-6 pt-6 pb-3">
        <div class="flex items-start gap-3">
          <div class="w-9 h-9 rounded-lg bg-emerald-50 flex items-center justify-center">
            <i data-lucide="message-square" class="w-5 h-5 text-emerald-600"></i>
          </div>
          <div>
            <h3 class="text-lg font-bold text-teal-900">Give Feedback</h3>
            <p class="text-xs text-teal-900/60 mt-0.5">
              Ref: <span class="font-semibold text-teal-700" id="feedbackGrievanceNumber">—</span>
            </p>
          </div>
        </div>
        <button type="button" onclick="closeFeedbackModal()" class="w-8 h-8 rounded-lg hover:bg-teal-50 flex items-center justify-center text-teal-900/60 transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form id="feedbackForm" method="POST" action="dashboard.php" class="px-6 pb-6 space-y-4">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />
        <input type="hidden" name="action" value="feedback_grievance" />
        <input type="hidden" name="grievance_id" id="feedbackGrievanceId" value="" />

        <div class="space-y-2">
          <label for="feedbackText" class="block text-sm font-semibold text-teal-900">
            Feedback <span class="text-red-500">*</span>
          </label>
          <textarea id="feedbackText" name="feedback" rows="5" maxlength="1000" required
                    placeholder="Share your feedback about how the grievance was handled…"
                    class="w-full px-4 py-3 border-2 border-teal-100 rounded-lg text-sm bg-teal-50/40 text-teal-900 placeholder-teal-900/40 resize-none
                           focus:outline-none focus:border-teal-600 focus:bg-white focus:ring-4 focus:ring-teal-600/10
                           hover:border-teal-200 transition-all"></textarea>
          <p class="text-xs text-teal-900/60">
            (Maximum 1000 character) — <span id="feedbackCharCount" class="font-semibold text-teal-700">0</span>/1000
          </p>
        </div>

        <div class="flex flex-col-reverse sm:flex-row justify-end gap-3">
          <button type="button" onclick="closeFeedbackModal()"
                  class="px-5 py-2.5 rounded-lg font-semibold text-teal-900 bg-white border-2 border-teal-200 hover:border-teal-600 hover:bg-teal-50 transition-all duration-200">
            Cancel
          </button>
          <button type="submit"
                  class="inline-flex items-center justify-center gap-2 px-7 py-2.5 rounded-lg font-semibold text-white
                         bg-teal-600 hover:bg-teal-700 shadow-sm hover:shadow-md
                         transition-all duration-200 cursor-pointer">
            <i data-lucide="send" class="w-4 h-4"></i>
            <span>Submit Feedback</span>
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- REOPEN MODAL -->
  <div id="reopenConfirmModal" class="hidden fixed inset-0 z-[75] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeReopenModal()"></div>

    <div id="reopenConfirmPanel" class="relative w-full max-w-2xl bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-teal-600"></div>

      <div class="flex items-start justify-between px-6 pt-6 pb-3">
        <h3 class="text-xl md:text-2xl font-bold text-teal-900">Reopen Grievance</h3>
        <button type="button" onclick="closeReopenModal()"
                class="text-teal-900/50 hover:text-teal-900 hover:bg-teal-50 rounded-lg p-1.5 transition-colors" aria-label="Close">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form id="reopenForm" method="POST" action="dashboard.php" class="px-6 pb-6 space-y-4">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />
        <input type="hidden" name="action" value="reopen_grievance" />
        <input type="hidden" name="grievance_id" id="reopenGrievanceId" value="" />

        <div>
          <label for="reopen_reason" class="block text-sm font-semibold text-teal-900 mb-2">
            Reopen (If you are not satisfied with the reply, you can reopen the grievance/complaint)
          </label>
          <textarea id="reopen_reason"
                    name="reopen_reason"
                    rows="2"
                    maxlength="120"
                    required
                    class="w-full px-4 py-3 border-2 border-teal-100 rounded-lg text-sm bg-teal-50/40 text-teal-900 placeholder-teal-900/40 resize-none
                           focus:outline-none focus:border-teal-600 focus:bg-white focus:ring-4 focus:ring-teal-600/10
                           hover:border-teal-200 transition-all"></textarea>
          <p class="text-xs text-teal-900/60 mt-1.5">(Maximum 120 character) — <span id="reopenCharCount" class="font-semibold text-teal-900/80">0</span>/120</p>
        </div>

        <div class="flex items-center justify-end gap-3 pt-2">
          <button type="submit"
                  class="inline-flex items-center justify-center px-7 py-2.5 rounded-lg
                         bg-teal-600 hover:bg-teal-700 text-white text-sm font-bold
                         shadow-sm hover:shadow-md
                         transition-all duration-200
                         disabled:opacity-50 disabled:cursor-not-allowed">
            <span>Submit</span>
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- LOGOUT MODAL -->
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
    // Placeholders so onclick="" always resolves
    window.openCreateGrievanceModal = function () {};
    window.closeCreateGrievanceModal = function () {};
    window.openEditGrievanceModal = function () {};
    window.closeEditGrievanceModal = function () {};
    window.openDisposeModal = function () {};
    window.closeDisposeModal = function () {};
    window.openFeedbackModal = function () {};
    window.closeFeedbackModal = function () {};
    window.openReopenModal = function () {};
    window.closeReopenModal = function () {};
    window.openViewGrievanceModal = function () {};
    window.closeViewGrievanceModal = function () {};
    window.openPreviewOverlay = function () {};
    window.closePreviewOverlay = function () {};
    window.openLogoutModal = function () {};
    window.closeLogoutModal = function () {};

    document.addEventListener('DOMContentLoaded', function () {
      if (typeof lucide !== 'undefined') lucide.createIcons();

      // Auto-dismiss flash
      ['flashSuccessBox', 'flashErrorBox'].forEach(function (id) {
        const box = document.getElementById(id);
        if (!box) return;
        setTimeout(function () {
          box.classList.remove('animate-flash-in');
          box.classList.add('animate-flash-out');
          setTimeout(function () { if (box.parentNode) box.parentNode.removeChild(box); }, 500);
        }, 3500);
      });

      // Sidebar toggle
      (function () {
        const toggleBtn = document.getElementById('sidebarToggle');
        const sidebar   = document.getElementById('parentSidebar');
        const main      = document.getElementById('parentMain');
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
        const btn = document.getElementById('parent-dropdown-btn');
        const menu = document.getElementById('parent-dropdown-menu');
        const chevron = document.getElementById('parent-chevron');
        const container = document.getElementById('parent-dropdown-container');
        if (!btn || !menu || !container) return;

        btn.addEventListener('click', function (e) {
          e.stopPropagation();
          const isOpen = !menu.classList.contains('hidden');
          if (isOpen) {
            menu.classList.add('hidden'); menu.classList.remove('animate-dropdown');
            if (chevron) chevron.classList.remove('rotate-180');
            btn.setAttribute('aria-expanded','false');
          } else {
            menu.classList.remove('hidden'); menu.classList.add('animate-dropdown');
            if (chevron) chevron.classList.add('rotate-180');
            btn.setAttribute('aria-expanded','true');
          }
        });
        document.addEventListener('click', function (e) {
          if (!container.contains(e.target)) {
            menu.classList.add('hidden'); menu.classList.remove('animate-dropdown');
            if (chevron) chevron.classList.remove('rotate-180');
            btn.setAttribute('aria-expanded','false');
          }
        });
      })();

      /* MODAL ELEMENTS */
      const createGrievanceModal = document.getElementById('createGrievanceModal');
      const editGrievanceModal   = document.getElementById('editGrievanceModal');
      const disposeConfirmModal  = document.getElementById('disposeConfirmModal');
      const disposeConfirmPanel  = document.getElementById('disposeConfirmPanel');
      const feedbackModal        = document.getElementById('feedbackModal');
      const reopenConfirmModal   = document.getElementById('reopenConfirmModal');
      const reopenConfirmPanel   = document.getElementById('reopenConfirmPanel');
      const viewGrievanceModal   = document.getElementById('viewGrievanceModal');
      const previewOverlay       = document.getElementById('previewOverlay');
      const logoutConfirmModal   = document.getElementById('logoutConfirmModal');
      const logoutConfirmPanel   = document.getElementById('logoutConfirmPanel');

      /* CREATE */
      const createGrievanceForm  = document.getElementById('createGrievanceForm');
      const subjectInput         = document.getElementById('subject');
      const descriptionInput     = document.getElementById('description');
      const subjectCounter       = document.getElementById('subjectCounter');
      const descriptionCounter   = document.getElementById('descriptionCounter');
      const attachmentInput      = document.getElementById('attachment');
      const attachmentFileName   = document.getElementById('attachmentFileName');

      window.openCreateGrievanceModal = function () {
        if (!createGrievanceModal) return;
        if (createGrievanceForm) createGrievanceForm.reset();
        if (subjectCounter) subjectCounter.textContent = '0';
        if (descriptionCounter) descriptionCounter.textContent = '0';
        if (attachmentFileName) attachmentFileName.textContent = 'No file chosen';
        createGrievanceModal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        setTimeout(function () { const f = document.getElementById('grievance_type_id'); if (f) f.focus(); }, 60);
        if (typeof lucide !== 'undefined') lucide.createIcons();
      };
      window.closeCreateGrievanceModal = function () {
        if (!createGrievanceModal) return;
        createGrievanceModal.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
      };

      if (subjectInput && subjectCounter) {
        subjectInput.addEventListener('input', function () {
          subjectCounter.textContent = String(this.value.length);
          subjectCounter.classList.toggle('text-red-600', this.value.length > 120);
        });
      }
      if (descriptionInput && descriptionCounter) {
        descriptionInput.addEventListener('input', function () {
          descriptionCounter.textContent = String(this.value.length);
          descriptionCounter.classList.toggle('text-red-600', this.value.length > 420);
        });
      }
      if (attachmentInput && attachmentFileName) {
        attachmentInput.addEventListener('change', function () {
          const file = this.files && this.files[0] ? this.files[0] : null;
          if (!file) { attachmentFileName.textContent = 'No file chosen'; attachmentFileName.classList.remove('text-red-600'); return; }
          const maxBytes = 5 * 1024 * 1024;
          if (file.size > maxBytes) {
            attachmentFileName.textContent = file.name + ' — exceeds 5 MB limit';
            attachmentFileName.classList.add('text-red-600');
            this.value = '';
            return;
          }
          attachmentFileName.classList.remove('text-red-600');
          attachmentFileName.textContent = file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
        });
      }

      /* EDIT */
      const editGrievanceId    = document.getElementById('editGrievanceId');
      const editTypeSelect     = document.getElementById('edit_grievance_type_id');
      const editSubjectInput   = document.getElementById('edit_subject');
      const editDescInput      = document.getElementById('edit_description');
      const editSubjectCounter = document.getElementById('editSubjectCounter');
      const editDescCounter    = document.getElementById('editDescriptionCounter');

      window.openEditGrievanceModal = function (id, typeId, subject, description) {
        if (!editGrievanceModal) return;
        editGrievanceId.value = String(id);
        editTypeSelect.value  = String(typeId);
        editSubjectInput.value = subject || '';
        editDescInput.value = description || '';
        editSubjectCounter.textContent = String((subject || '').length);
        editDescCounter.textContent = String((description || '').length);
        editSubjectCounter.classList.toggle('text-red-600', (subject || '').length > 120);
        editDescCounter.classList.toggle('text-red-600', (description || '').length > 420);
        editGrievanceModal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        setTimeout(function () { if (editSubjectInput) editSubjectInput.focus(); }, 60);
        if (typeof lucide !== 'undefined') lucide.createIcons();
      };
      window.closeEditGrievanceModal = function () {
        if (!editGrievanceModal) return;
        editGrievanceModal.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
        const f = document.getElementById('editGrievanceForm');
        if (f) f.reset();
      };
      if (editSubjectInput && editSubjectCounter) {
        editSubjectInput.addEventListener('input', function () {
          editSubjectCounter.textContent = String(this.value.length);
          editSubjectCounter.classList.toggle('text-red-600', this.value.length > 120);
        });
      }
      if (editDescInput && editDescCounter) {
        editDescInput.addEventListener('input', function () {
          editDescCounter.textContent = String(this.value.length);
          editDescCounter.classList.toggle('text-red-600', this.value.length > 420);
        });
      }

      /* DISPOSE */
      const disposeGrievanceId  = document.getElementById('disposeGrievanceId');
      const disposeGrievanceNum = document.getElementById('disposeGrievanceNumber');

      window.openDisposeModal = function (grievanceId, grievanceNumber) {
        if (!disposeConfirmModal) return;
        disposeGrievanceId.value = String(grievanceId);
        disposeGrievanceNum.textContent = '"' + (grievanceNumber || '') + '"';
        disposeConfirmModal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        if (disposeConfirmPanel) {
          disposeConfirmPanel.classList.remove('animate-confirm-shake');
          void disposeConfirmPanel.offsetWidth;
          disposeConfirmPanel.classList.add('animate-confirm-shake');
        }
        if (typeof lucide !== 'undefined') lucide.createIcons();
      };
      window.closeDisposeModal = function () {
        if (!disposeConfirmModal) return;
        disposeConfirmModal.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
      };

      /* FEEDBACK */
      const feedbackGrievanceId = document.getElementById('feedbackGrievanceId');
      const feedbackGrievanceNumber = document.getElementById('feedbackGrievanceNumber');
      const feedbackText = document.getElementById('feedbackText');
      const feedbackCharCount = document.getElementById('feedbackCharCount');

      window.openFeedbackModal = function (grievanceId, grievanceNumber) {
        if (!feedbackModal) return;
        feedbackGrievanceId.value = String(grievanceId);
        feedbackGrievanceNumber.textContent = grievanceNumber || '—';
        if (feedbackText) feedbackText.value = '';
        if (feedbackCharCount) feedbackCharCount.textContent = '0';
        feedbackModal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        setTimeout(function () { if (feedbackText) feedbackText.focus(); }, 60);
        if (typeof lucide !== 'undefined') lucide.createIcons();
      };
      window.closeFeedbackModal = function () {
        if (!feedbackModal) return;
        feedbackModal.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
      };
      if (feedbackText && feedbackCharCount) {
        feedbackText.addEventListener('input', function () {
          feedbackCharCount.textContent = String(this.value.length);
        });
      }
      (function () {
        const f = document.getElementById('feedbackForm');
        if (!f) return;
        f.addEventListener('submit', function (e) {
          const v = (feedbackText && feedbackText.value.trim()) || '';
          if (v === '') { e.preventDefault(); alert('Please enter your feedback.'); if (feedbackText) feedbackText.focus(); return; }
          const btn = f.querySelector('button[type="submit"]');
          if (btn) { btn.classList.add('opacity-50','pointer-events-none'); btn.innerHTML = '<span>Submitting…</span>'; }
        });
      })();

      /* REOPEN */
      const reopenGrievanceId  = document.getElementById('reopenGrievanceId');
      const reopenReason       = document.getElementById('reopen_reason');
      const reopenCharCount    = document.getElementById('reopenCharCount');

      window.openReopenModal = function (grievanceId, grievanceNumber) {
        if (!reopenConfirmModal) return;
        reopenGrievanceId.value = String(grievanceId);
        if (reopenReason) reopenReason.value = '';
        if (reopenCharCount) reopenCharCount.textContent = '0';
        reopenConfirmModal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        if (reopenConfirmPanel) {
          reopenConfirmPanel.classList.remove('animate-confirm-shake');
          void reopenConfirmPanel.offsetWidth;
          reopenConfirmPanel.classList.add('animate-confirm-shake');
        }
        setTimeout(function () { if (reopenReason) reopenReason.focus(); }, 80);
        if (typeof lucide !== 'undefined') lucide.createIcons();
      };
      window.closeReopenModal = function () {
        if (!reopenConfirmModal) return;
        reopenConfirmModal.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
      };
      if (reopenReason && reopenCharCount) {
        reopenReason.addEventListener('input', function () {
          reopenCharCount.textContent = String(this.value.length);
          reopenCharCount.classList.toggle('text-red-600', this.value.length > 120);
        });
      }
      (function () {
        const f = document.getElementById('reopenForm');
        if (!f) return;
        f.addEventListener('submit', function (e) {
          const v = (reopenReason && reopenReason.value.trim()) || '';
          if (v === '') {
            e.preventDefault();
            alert('Please enter a reason for reopening.');
            if (reopenReason) reopenReason.focus();
            return;
          }
          const btn = f.querySelector('button[type="submit"]');
          if (btn) { btn.classList.add('opacity-50','pointer-events-none'); btn.innerHTML = '<span>Submitting…</span>'; }
        });
      })();

      /* VIEW + PREVIEW */
      const previewImage       = document.getElementById('previewImage');
      const previewFrame       = document.getElementById('previewFrame');
      const previewFileName    = document.getElementById('previewFileName');
      const previewOpenNewTab  = document.getElementById('previewOpenNewTab');

      const attachmentData = {
        original: { url: '', ext: '', name: '' },
        reply:    { url: '', ext: '', name: '' },
      };

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
        return '<span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold uppercase tracking-wider border ' + cls + '">' + s + '</span>';
      }
      function isImageExt(ext) { return ['jpg','jpeg','png','gif','webp','bmp','svg'].indexOf(ext) !== -1; }
      function isPdfExt(ext)   { return ext === 'pdf'; }
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

      window.openViewGrievanceModal = function (data) {
        document.getElementById('vgNumber').textContent      = data.number || '—';
        document.getElementById('vgSubject').textContent     = data.subject || '—';
        document.getElementById('vgStatus').innerHTML        = statusBadgeHtml(data.status);
        document.getElementById('vgType').textContent        = data.type || '—';
        document.getElementById('vgDate').textContent        = data.date || '—';
        document.getElementById('vgDate2').textContent       = data.date || '—';
        document.getElementById('vgDescription').textContent = (data.description && data.description.trim() !== '') ? data.description : 'No description provided.';
        document.getElementById('vgReply').textContent       = (data.reply && data.reply.trim() !== '') ? data.reply : 'No response yet from the grievance committee.';

        const roWrap = document.getElementById('vgReopenWrapper');
        const roText = document.getElementById('vgReopenReason');
        if (data.reopen_reason && String(data.reopen_reason).trim() !== '') {
          roText.textContent = data.reopen_reason;
          roWrap.classList.remove('hidden');
        } else {
          roWrap.classList.add('hidden');
        }

        const origWrap = document.getElementById('vgAttachmentWrapper');
        const origName = document.getElementById('vgAttachmentName');
        const origPreview = document.getElementById('vgPreviewBtn');
        const origPreviewLbl = document.getElementById('vgPreviewBtnLabel');
        const origDownload = document.getElementById('vgDownloadLink');

        if (data.attach_url && data.attach_url.trim() !== '') {
          const name = filenameFromUrl(data.attach_url);
          const ext  = (data.attach_ext || '').toLowerCase();
          attachmentData.original = { url: data.attach_url, ext: ext, name: name };

          origName.textContent = name;
          origDownload.href = data.attach_url;

          if (isImageExt(ext)) { origPreviewLbl.textContent = 'View Image'; origPreview.classList.remove('hidden'); }
          else if (isPdfExt(ext)) { origPreviewLbl.textContent = 'View PDF'; origPreview.classList.remove('hidden'); }
          else { origPreview.classList.add('hidden'); }

          origWrap.classList.remove('hidden');
        } else {
          attachmentData.original = { url: '', ext: '', name: '' };
          origWrap.classList.add('hidden');
        }

        const repWrap = document.getElementById('vgReplyAttachWrapper');
        const repName = document.getElementById('vgReplyAttachmentName');
        const repPreview = document.getElementById('vgReplyPreviewBtn');
        const repPreviewLbl = document.getElementById('vgReplyPreviewBtnLabel');
        const repDownload = document.getElementById('vgReplyDownloadLink');

        if (data.reply_attach_url && data.reply_attach_url.trim() !== '') {
          const name = filenameFromUrl(data.reply_attach_url);
          const ext  = (data.reply_attach_ext || '').toLowerCase();
          attachmentData.reply = { url: data.reply_attach_url, ext: ext, name: name };

          repName.textContent = name;
          repDownload.href = data.reply_attach_url;

          if (isImageExt(ext)) { repPreviewLbl.textContent = 'View Image'; repPreview.classList.remove('hidden'); }
          else if (isPdfExt(ext)) { repPreviewLbl.textContent = 'View PDF'; repPreview.classList.remove('hidden'); }
          else { repPreview.classList.add('hidden'); }

          repWrap.classList.remove('hidden');
        } else {
          attachmentData.reply = { url: '', ext: '', name: '' };
          repWrap.classList.add('hidden');
        }

        const fbWrap = document.getElementById('vgFeedbackWrapper');
        const fbText = document.getElementById('vgFeedback');
        if (data.feedback && data.feedback.trim() !== '') {
          fbText.textContent = data.feedback;
          fbWrap.classList.remove('hidden');
        } else {
          fbWrap.classList.add('hidden');
        }

        viewGrievanceModal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        if (typeof lucide !== 'undefined') lucide.createIcons();
      };

      window.closeViewGrievanceModal = function () {
        if (previewOverlay && !previewOverlay.classList.contains('hidden')) window.closePreviewOverlay();
        viewGrievanceModal.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
      };

      window.openPreviewOverlay = function (slot) {
        const info = attachmentData[slot] || { url: '', ext: '', name: '' };
        if (!info.url) return;

        previewFileName.textContent = info.name || 'Attachment';
        previewOpenNewTab.href = info.url;

        if (isImageExt(info.ext)) {
          previewImage.src = info.url;
          previewImage.classList.remove('hidden');
          previewFrame.classList.add('hidden');
          previewFrame.src = '';
        } else if (isPdfExt(info.ext)) {
          previewFrame.src = info.url;
          previewFrame.classList.remove('hidden');
          previewImage.classList.add('hidden');
          previewImage.src = '';
        } else {
          window.open(info.url, '_blank', 'noopener');
          return;
        }

        previewOverlay.classList.remove('hidden');
        if (typeof lucide !== 'undefined') lucide.createIcons();
      };

      window.closePreviewOverlay = function () {
        if (!previewOverlay) return;
        previewOverlay.classList.add('hidden');
        if (previewImage) previewImage.src = '';
        if (previewFrame) previewFrame.src = '';
      };

      /* LOGOUT */
      const confirmLogoutBtn = document.getElementById('confirmLogoutBtn');
      const LOGOUT_URL = '../logout.php?role=parent';

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
      (function () {
        const triggers = [document.getElementById('sidebarLogoutBtn'), document.getElementById('dropdownLogoutBtn')];
        triggers.forEach(function (btn) {
          if (!btn) return;
          btn.addEventListener('click', function (e) {
            e.preventDefault(); e.stopPropagation();
            window.openLogoutModal();
          });
        });
      })();
      if (confirmLogoutBtn) {
        confirmLogoutBtn.addEventListener('click', function () {
          confirmLogoutBtn.classList.add('opacity-50', 'pointer-events-none');
          window.location.href = LOGOUT_URL;
        });
      }

      /* DELEGATED data-* handlers */
      document.addEventListener('click', function (e) {
        const viewBtn = e.target.closest('[data-view-trigger="1"]');
        if (viewBtn) {
          e.preventDefault();
          const raw = viewBtn.getAttribute('data-grievance');
          if (raw) {
            try { window.openViewGrievanceModal(JSON.parse(raw)); } catch (err) { console.error(err); }
          }
          return;
        }
        const editBtn = e.target.closest('[data-edit-trigger="1"]');
        if (editBtn) {
          e.preventDefault();
          const id   = parseInt(editBtn.getAttribute('data-grievance-id') || '0', 10);
          const type = parseInt(editBtn.getAttribute('data-grievance-type-id') || '0', 10);
          const subj = editBtn.getAttribute('data-subject') || '';
          const desc = editBtn.getAttribute('data-description') || '';
          window.openEditGrievanceModal(id, type, subj, desc);
          return;
        }
        const disposeBtn = e.target.closest('[data-dispose-trigger="1"]');
        if (disposeBtn) {
          e.preventDefault();
          const id  = parseInt(disposeBtn.getAttribute('data-grievance-id') || '0', 10);
          const num = disposeBtn.getAttribute('data-grievance-number') || '';
          window.openDisposeModal(id, num);
          return;
        }
        const feedbackBtn = e.target.closest('[data-feedback-trigger="1"]');
        if (feedbackBtn) {
          e.preventDefault();
          const id  = parseInt(feedbackBtn.getAttribute('data-grievance-id') || '0', 10);
          const num = feedbackBtn.getAttribute('data-grievance-number') || '';
          window.openFeedbackModal(id, num);
          return;
        }
        const reopenBtn = e.target.closest('[data-reopen-trigger="1"]');
        if (reopenBtn) {
          e.preventDefault();
          const id  = parseInt(reopenBtn.getAttribute('data-grievance-id') || '0', 10);
          const num = reopenBtn.getAttribute('data-grievance-number') || '';
          window.openReopenModal(id, num);
          return;
        }
      });

      /* Search + Entries pagination */
      (function () {
        const searchInput   = document.getElementById('searchInput');
        const entriesSelect = document.getElementById('entriesPerPage');
        const tableBody     = document.getElementById('grievancesTableBody');
        const infoStart     = document.getElementById('infoStart');
        const infoEnd       = document.getElementById('infoEnd');
        const infoTotal     = document.getElementById('infoTotal');
        const prevBtn       = document.getElementById('prevPageBtn');
        const nextBtn       = document.getElementById('nextPageBtn');
        const pageBadge     = document.getElementById('currentPageBadge');
        if (!tableBody) return;

        const allRows = Array.from(tableBody.querySelectorAll('tr')).filter(function (r) {
          return !r.querySelector('td[colspan]');
        });

        let pageSize = 10, currentPage = 1, searchTerm = '';

        function applyFilters() {
          const filtered = allRows.filter(function (row) {
            if (searchTerm === '') return true;
            return row.textContent.toLowerCase().indexOf(searchTerm) !== -1;
          });
          const totalPages = Math.max(1, Math.ceil(filtered.length / pageSize));
          if (currentPage > totalPages) currentPage = totalPages;
          allRows.forEach(function (r) { r.style.display = 'none'; });
          const startIdx = (currentPage - 1) * pageSize;
          const endIdx = Math.min(startIdx + pageSize, filtered.length);
          filtered.slice(startIdx, endIdx).forEach(function (r) { r.style.display = ''; });
          if (infoStart) infoStart.textContent = filtered.length === 0 ? 0 : startIdx + 1;
          if (infoEnd)   infoEnd.textContent = endIdx;
          if (infoTotal) infoTotal.textContent = filtered.length;
          if (prevBtn) prevBtn.disabled = (currentPage <= 1);
          if (nextBtn) nextBtn.disabled = (currentPage >= totalPages);
          if (pageBadge) pageBadge.textContent = currentPage;
        }
        if (searchInput) {
          let timer = null;
          searchInput.addEventListener('input', function () {
            clearTimeout(timer);
            const self = this;
            timer = setTimeout(function () {
              searchTerm = self.value.toLowerCase().trim();
              currentPage = 1; applyFilters();
            }, 200);
          });
        }
        if (entriesSelect) {
          entriesSelect.addEventListener('change', function () {
            pageSize = parseInt(this.value, 10) || 10;
            currentPage = 1; applyFilters();
          });
        }
        if (prevBtn) prevBtn.addEventListener('click', function () { if (currentPage > 1) { currentPage--; applyFilters(); } });
        if (nextBtn) nextBtn.addEventListener('click', function () { currentPage++; applyFilters(); });
        applyFilters();
      })();

      /* Escape key */
      document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (previewOverlay && !previewOverlay.classList.contains('hidden')) { window.closePreviewOverlay(); return; }
        if (createGrievanceModal && !createGrievanceModal.classList.contains('hidden')) window.closeCreateGrievanceModal();
        if (editGrievanceModal && !editGrievanceModal.classList.contains('hidden')) window.closeEditGrievanceModal();
        if (disposeConfirmModal && !disposeConfirmModal.classList.contains('hidden')) window.closeDisposeModal();
        if (feedbackModal && !feedbackModal.classList.contains('hidden')) window.closeFeedbackModal();
        if (reopenConfirmModal && !reopenConfirmModal.classList.contains('hidden')) window.closeReopenModal();
        if (viewGrievanceModal && !viewGrievanceModal.classList.contains('hidden')) window.closeViewGrievanceModal();
        if (logoutConfirmModal && !logoutConfirmModal.classList.contains('hidden')) window.closeLogoutModal();
      });
    });
  </script>

  <script src="../assets/js/index.js"></script>
</body>
</html>
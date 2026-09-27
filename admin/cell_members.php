<?php
/**
 * admin/cell_members.php
 * ---------------------------------------------------------------------------
 * Admin — Grievance Cell Member List (All non-admin users)
 * Rajagiri College Grievance Redressal Portal
 *
 * Lists every non-admin user in the system, joined with cell_members.
 *
 * Member Type column resolution (in priority order):
 *   1. cell_members.member_type  (when the user IS a cell member)
 *      • MANAGEMENT       → "MANAGEMENT"
 *      • anything else    → "GRIEVANCE MEMBER"
 *   2. users.role fallback (when the user is NOT a cell member):
 *      • TEACHER          → "TEACHING"
 *      • NON_TEACHING     → "NON TEACHING"
 *      • MANAGEMENT       → "MANAGEMENT"
 *      • PARENT           → "PARENT"
 *      • STUDENT          → "STUDENT"
 *
 * Actions per row:
 *   • Set Password (admin enters new + confirm password)
 *   • Edit (cell members only)
 *   • Deactivate
 *   • Delete
 *
 * Username rule (ADD MEMBER):
 *   → The "Name" field value is used AS-IS as the username.
 *   → Case is preserved exactly (e.g. "Anandakrishnan", "JACKY", "mary jane").
 *   → If the username already exists, a numeric suffix is appended
 *     automatically to keep it unique (Anandakrishnan1, Anandakrishnan2, …).
 *
 * Includes themed logout confirmation modal.
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
// 2. AUTH GUARD
// ---------------------------------------------------------------------------
$sessionRole = isset($_SESSION['role']) ? strtoupper((string) $_SESSION['role']) : '';

if (empty($_SESSION['user_id']) || $sessionRole !== 'ADMIN') {
    header('Location: ../login.php?role=admin');
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
// 4. HELPERS
// ---------------------------------------------------------------------------
function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function isValidMobile(string $mobile): bool
{
    return (bool) preg_match('/^[0-9]{10}$/', $mobile);
}

function resolveMemberTypeLabel(?string $cellMemberType, string $userRole): string
{
    if ($cellMemberType !== null && $cellMemberType !== '') {
        return $cellMemberType === 'MANAGEMENT' ? 'MANAGEMENT' : 'GRIEVANCE MEMBER';
    }

    $roleMap = [
        'TEACHER'      => 'TEACHING',
        'NON_TEACHING' => 'NON TEACHING',
        'MANAGEMENT'   => 'MANAGEMENT',
        'PARENT'       => 'PARENT',
        'STUDENT'      => 'STUDENT',
        'ADMIN'        => 'ADMIN',
    ];

    return $roleMap[strtoupper($userRole)] ?? strtoupper($userRole);
}

function isCellMember(?string $cellMemberType): bool
{
    return $cellMemberType !== null && $cellMemberType !== '';
}

/**
 * Generate a unique username from a display name, preserving the exact case
 * as entered. If the base name is already taken, append a numeric suffix.
 *
 * Examples:
 *   "Anandakrishnan"  → "Anandakrishnan"
 *   "Anandakrishnan"  (taken) → "Anandakrishnan1"
 *   "John Doe"        (taken) → "John Doe1"   ← spaces allowed, treated literally
 */
function generateUniqueUsername(mysqli $conn, string $baseName): string
{
    $base = trim($baseName);
    if ($base === '') {
        $base = 'Member';
    }

    // Trim to a reasonable length so we leave headroom for the suffix
    if (mb_strlen($base) > 80) {
        $base = mb_substr($base, 0, 80);
    }

    $candidate = $base;
    $suffix    = 1;

    while (true) {
        $chk = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        if (!$chk) {
            // On failure, return base (unlikely)
            return $candidate;
        }
        $chk->bind_param('s', $candidate);
        $chk->execute();
        $exists = $chk->get_result()->num_rows > 0;
        $chk->close();

        if (!$exists) {
            return $candidate;
        }

        $candidate = $base . $suffix;
        $suffix++;

        if ($suffix > 9999) {
            // Extremely unlikely; fall back to a timestamped suffix
            return $base . '_' . time();
        }
    }
}

// ---------------------------------------------------------------------------
// 5. FETCH ADMIN PROFILE
// ---------------------------------------------------------------------------
$adminData = [
    'username'        => $_SESSION['username'] ?? 'Admin',
    'name'            => '',
    'email'           => '',
    'profile_picture' => '',
];

if ($conn instanceof mysqli) {
    try {
        $sql = "SELECT  u.username,
                        ap.name,
                        ap.email,
                        ap.profile_picture
                FROM users u
                LEFT JOIN admin_profiles ap ON ap.user_id = u.id
                WHERE u.id = ?
                LIMIT 1";

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
        error_log('[Cell Members Admin Profile] ' . $ex->getMessage());
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
// 6. FETCH ACTIVE DROPDOWN OPTIONS
// ---------------------------------------------------------------------------
$designationOptions   = [];
$departmentOptions    = [];
$grievanceTypeOptions = [];

if ($conn instanceof mysqli) {
    try {
        $res = $conn->query("SELECT id, designation_name FROM designations WHERE status = 'Active' ORDER BY designation_name ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) $designationOptions[] = $row;
        }
    } catch (Throwable $ex) {
        error_log('[Fetch Designations] ' . $ex->getMessage());
    }

    try {
        $res = $conn->query("SELECT id, department_name FROM departments WHERE status = 'Active' ORDER BY department_name ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) $departmentOptions[] = $row;
        }
    } catch (Throwable $ex) {
        error_log('[Fetch Departments] ' . $ex->getMessage());
    }

    try {
        $res = $conn->query("SELECT id, type_name FROM grievance_types WHERE status = 'Active' ORDER BY type_name ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) $grievanceTypeOptions[] = $row;
        }
    } catch (Throwable $ex) {
        error_log('[Fetch Grievance Types] ' . $ex->getMessage());
    }
}

// ---------------------------------------------------------------------------
// 7. HANDLE FORM SUBMISSIONS
// ---------------------------------------------------------------------------
$flashSuccess = '';
$flashError   = '';

$roleMap = [
    'MANAGEMENT'       => 'MANAGEMENT',
    'GRIEVANCE_MEMBER' => 'MANAGEMENT',
    'TEACHING'         => 'TEACHER',
    'NON_TEACHING'     => 'NON_TEACHING',
    'PARENT'           => 'PARENT',
    'STUDENT'          => 'STUDENT',
];

$validMemberTypes = ['MANAGEMENT', 'GRIEVANCE_MEMBER', 'TEACHING', 'NON_TEACHING', 'PARENT', 'STUDENT'];
$validStatuses    = ['Approved', 'Pending', 'Rejected', 'Terminated'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $conn instanceof mysqli) {

    $action = $_POST['action'] ?? '';

    // -------- ADD MEMBER --------
    if ($action === 'add_member') {
        $name             = trim((string) ($_POST['name']              ?? ''));
        $email            = trim((string) ($_POST['email']             ?? ''));
        $mobileNumber     = trim((string) ($_POST['mobile_number']     ?? ''));
        $designationId    = (int) ($_POST['designation_id']            ?? 0);
        $grievanceTypeId  = (int) ($_POST['grievance_type_id']         ?? 0);
        $isManagement     = !empty($_POST['is_management']);

        $memberType = $isManagement ? 'MANAGEMENT' : 'GRIEVANCE_MEMBER';
        $grievanceTypeIdDb = ($grievanceTypeId > 0) ? $grievanceTypeId : null;

        if ($name === '' || $email === '' || $mobileNumber === '') {
            $flashError = 'Please fill in all required fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $flashError = 'Please enter a valid email address.';
        } elseif (!isValidMobile($mobileNumber)) {
            $flashError = 'Mobile number must be exactly 10 digits.';
        } elseif ($designationId <= 0) {
            $flashError = 'Please select a designation.';
        } elseif (!$isManagement && $grievanceTypeIdDb === null) {
            $flashError = 'Please select a Grievance Type (required for Grievance Members).';
        }

        if ($flashError === '') {
            try {
                $conn->begin_transaction();

                // Duplicate email check
                $chk2 = $conn->prepare("SELECT id FROM cell_members WHERE email = ? LIMIT 1");
                $chk2->bind_param('s', $email);
                $chk2->execute();
                if ($chk2->get_result()->num_rows > 0) {
                    $chk2->close();
                    throw new Exception('Email is already registered for another member.');
                }
                $chk2->close();

                // Username = Name exactly as entered (case preserved).
                // If it collides, auto-append a numeric suffix to keep it unique.
                $username = generateUniqueUsername($conn, $name);

                $plainPassword = 'Member@' . random_int(1000, 9999);
                $hash          = password_hash($plainPassword, PASSWORD_BCRYPT);
                $userRole      = $roleMap[$memberType] ?? 'MANAGEMENT';

                $stmtU = $conn->prepare("INSERT INTO users (username, password, role, status) VALUES (?, ?, ?, 'Approved')");
                $stmtU->bind_param('sss', $username, $hash, $userRole);
                $stmtU->execute();
                $newUserId = (int) $conn->insert_id;
                $stmtU->close();

                $stmtS = $conn->prepare(
                    "INSERT INTO cell_members
                        (user_id, designation_id, department_id, member_type, grievance_type_id, name, email, mobile_number)
                     VALUES (?, ?, NULL, ?, ?, ?, ?, ?)"
                );
                $stmtS->bind_param(
                    'iisssss',
                    $newUserId,
                    $designationId,
                    $memberType,
                    $grievanceTypeIdDb,
                    $name,
                    $email,
                    $mobileNumber
                );

                if (!$stmtS->execute()) {
                    throw new Exception('Failed to add member details: ' . $stmtS->error);
                }
                $stmtS->close();

                $conn->commit();

                $flashSuccess = 'Member added successfully. Login credentials — Username: '
                              . $username . '  |  Password: ' . $plainPassword;
            } catch (Throwable $ex) {
                if ($conn instanceof mysqli) $conn->rollback();
                error_log('[Add Member] ' . $ex->getMessage());
                $flashError = $ex->getMessage() ?: 'A system error occurred while adding the member.';
            }
        }
    }

    // -------- EDIT MEMBER (cell members only) --------
    if ($action === 'edit_member') {
        $targetUserId     = (int) ($_POST['user_id']              ?? 0);
        $name             = trim((string) ($_POST['name']             ?? ''));
        $email            = trim((string) ($_POST['email']            ?? ''));
        $mobileNumber     = trim((string) ($_POST['mobile_number']    ?? ''));
        $designationId    = (int) ($_POST['designation_id']           ?? 0);
        $grievanceTypeId  = (int) ($_POST['grievance_type_id']        ?? 0);
        $isManagement     = !empty($_POST['is_management']);
        $memberType       = $isManagement ? 'MANAGEMENT' : 'GRIEVANCE_MEMBER';
        $grievanceTypeIdDb = ($grievanceTypeId > 0) ? $grievanceTypeId : null;

        if ($targetUserId <= 0) {
            $flashError = 'Invalid user.';
        } elseif ($name === '' || $email === '' || $mobileNumber === '') {
            $flashError = 'Please fill in all required fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $flashError = 'Please enter a valid email address.';
        } elseif (!isValidMobile($mobileNumber)) {
            $flashError = 'Mobile number must be exactly 10 digits.';
        } elseif ($designationId <= 0) {
            $flashError = 'Please select a designation.';
        } elseif (!$isManagement && $grievanceTypeIdDb === null) {
            $flashError = 'Please select a Grievance Type (required for Grievance Members).';
        }

        if ($flashError === '') {
            try {
                $conn->begin_transaction();

                $chkExist = $conn->prepare("SELECT id FROM cell_members WHERE user_id = ? LIMIT 1");
                $chkExist->bind_param('i', $targetUserId);
                $chkExist->execute();
                $resExist = $chkExist->get_result();
                $existingCellMemberId = $resExist && $resExist->num_rows > 0
                    ? (int) ($resExist->fetch_assoc()['id'] ?? 0)
                    : 0;
                $chkExist->close();

                if ($existingCellMemberId === 0) {
                    throw new Exception('This user is not a cell member. Use the + button to add a new member.');
                }

                $chkEmail = $conn->prepare("SELECT id FROM cell_members WHERE email = ? AND user_id != ? LIMIT 1");
                $chkEmail->bind_param('si', $email, $targetUserId);
                $chkEmail->execute();
                if ($chkEmail->get_result()->num_rows > 0) {
                    $chkEmail->close();
                    throw new Exception('Email is already registered for another member.');
                }
                $chkEmail->close();

                $stmt = $conn->prepare(
                    "UPDATE cell_members
                     SET designation_id = ?, member_type = ?, grievance_type_id = ?,
                         name = ?, email = ?, mobile_number = ?
                     WHERE id = ?"
                );
                $stmt->bind_param(
                    'isissis',
                    $designationId,
                    $memberType,
                    $grievanceTypeIdDb,
                    $name,
                    $email,
                    $mobileNumber,
                    $existingCellMemberId
                );
                $stmt->execute();
                $stmt->close();

                $userRole = $roleMap[$memberType] ?? 'MANAGEMENT';
                $stmtU = $conn->prepare("UPDATE users SET role = ? WHERE id = ?");
                $stmtU->bind_param('si', $userRole, $targetUserId);
                $stmtU->execute();
                $stmtU->close();

                $conn->commit();
                $flashSuccess = 'Member updated successfully.';
            } catch (Throwable $ex) {
                if ($conn instanceof mysqli) $conn->rollback();
                error_log('[Edit Member] ' . $ex->getMessage());
                $flashError = $ex->getMessage() ?: 'A system error occurred while updating the member.';
            }
        }
    }

    // -------- SET PASSWORD --------
    if ($action === 'set_password') {
        $targetUserId    = (int) ($_POST['user_id'] ?? 0);
        $newPassword     = (string) ($_POST['new_password']     ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if ($targetUserId <= 0) {
            $flashError = 'Invalid user.';
        } elseif ($newPassword === '') {
            $flashError = 'Password is required.';
        } elseif (strlen($newPassword) < 6) {
            $flashError = 'Password must be at least 6 characters.';
        } elseif ($newPassword !== $confirmPassword) {
            $flashError = 'Password and Confirm Password do not match.';
        }

        if ($flashError === '') {
            try {
                $chkUser = $conn->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
                $chkUser->bind_param('i', $targetUserId);
                $chkUser->execute();
                if ($chkUser->get_result()->num_rows === 0) {
                    $chkUser->close();
                    throw new Exception('User account not found.');
                }
                $chkUser->close();

                $hash = password_hash($newPassword, PASSWORD_BCRYPT);

                $stmtU = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmtU->bind_param('si', $hash, $targetUserId);
                $stmtU->execute();
                $stmtU->close();

                $flashSuccess = 'Password updated successfully.';
            } catch (Throwable $ex) {
                error_log('[Set Password] ' . $ex->getMessage());
                $flashError = $ex->getMessage() ?: 'A system error occurred while updating the password.';
            }
        }
    }

    // -------- DELETE USER --------
    if ($action === 'delete_member') {
        $targetUserId = (int) ($_POST['user_id'] ?? 0);
        if ($targetUserId > 0) {
            if ($targetUserId === $userId) {
                $flashError = 'You cannot delete your own account.';
            } else {
                try {
                    $conn->begin_transaction();

                    $chkRole = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
                    $chkRole->bind_param('i', $targetUserId);
                    $chkRole->execute();
                    $resRole = $chkRole->get_result();
                    $targetRole = $resRole && $resRole->num_rows > 0
                        ? strtoupper((string) ($resRole->fetch_assoc()['role'] ?? ''))
                        : '';
                    $chkRole->close();

                    if ($targetRole === 'ADMIN') {
                        throw new Exception('Admin accounts cannot be deleted from this page.');
                    }

                    $stmtD1 = $conn->prepare("DELETE FROM cell_members WHERE user_id = ?");
                    $stmtD1->bind_param('i', $targetUserId);
                    $stmtD1->execute();
                    $stmtD1->close();

                    $stmtD2 = $conn->prepare("DELETE FROM users WHERE id = ?");
                    $stmtD2->bind_param('i', $targetUserId);
                    $stmtD2->execute();
                    $stmtD2->close();

                    $conn->commit();
                    $flashSuccess = 'User deleted successfully.';
                } catch (Throwable $ex) {
                    if ($conn instanceof mysqli) $conn->rollback();
                    error_log('[Delete User] ' . $ex->getMessage());
                    $flashError = $ex->getMessage() ?: 'A system error occurred while deleting the user.';
                }
            }
        }
    }

    // -------- DEACTIVATE --------
    if ($action === 'deactivate_member') {
        $targetUserId = (int) ($_POST['user_id'] ?? 0);
        if ($targetUserId > 0) {
            if ($targetUserId === $userId) {
                $flashError = 'You cannot deactivate your own account.';
            } else {
                try {
                    $stmtU = $conn->prepare("UPDATE users SET status = 'Terminated' WHERE id = ?");
                    $stmtU->bind_param('i', $targetUserId);
                    $stmtU->execute();
                    $stmtU->close();

                    $flashSuccess = 'User deactivated successfully.';
                } catch (Throwable $ex) {
                    error_log('[Deactivate User] ' . $ex->getMessage());
                    $flashError = 'A system error occurred while deactivating the user.';
                }
            }
        }
    }

    if ($flashSuccess !== '' || $flashError !== '') {
        $_SESSION['flash_success'] = $flashSuccess;
        $_SESSION['flash_error']   = $flashError;

        $qFilterMemberType = urlencode((string) ($_POST['filter_member_type'] ?? 'ALL'));
        $qFilterStatus     = urlencode((string) ($_POST['filter_status']      ?? 'All'));

        header('Location: cell_members.php?member_type=' . $qFilterMemberType . '&status=' . $qFilterStatus);
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
// 8. FILTERS
// ---------------------------------------------------------------------------
$filterMemberType = isset($_GET['member_type']) ? (string) $_GET['member_type'] : 'ALL';
$filterStatus     = isset($_GET['status'])      ? (string) $_GET['status']      : 'All';

if (!in_array($filterMemberType, array_merge(['ALL'], $validMemberTypes), true)) {
    $filterMemberType = 'ALL';
}
if (!in_array($filterStatus, array_merge(['All'], $validStatuses), true)) {
    $filterStatus = 'All';
}

// ---------------------------------------------------------------------------
// 9. FETCH ALL NON-ADMIN USERS (LEFT JOIN cell_members) + FILTERS
// ---------------------------------------------------------------------------
$users = [];

if ($conn instanceof mysqli) {
    try {
        $sql = "SELECT
                    u.id                AS user_id,
                    u.username,
                    u.role              AS user_role,
                    u.status            AS user_status,

                    cm.id               AS cell_member_id,
                    cm.designation_id,
                    cm.department_id,
                    cm.grievance_type_id,
                    cm.member_type,
                    cm.name             AS cell_name,
                    cm.email            AS cell_email,
                    cm.mobile_number    AS cell_mobile,

                    COALESCE(
                        cm.name,
                        s.name,
                        p.name,
                        st.name
                    )                   AS display_name,

                    COALESCE(
                        cm.email,
                        s.email,
                        p.email,
                        st.email
                    )                   AS display_email,

                    COALESCE(
                        cm.mobile_number,
                        p.contact_number,
                        s.contact_number,
                        st.contact_number
                    )                   AS display_mobile

                FROM users u
                LEFT JOIN cell_members cm  ON cm.user_id = u.id
                LEFT JOIN students     s   ON s.user_id  = u.id
                LEFT JOIN parents      p   ON p.user_id  = u.id
                LEFT JOIN staff        st  ON st.user_id = u.id
                WHERE u.role <> 'ADMIN'";

        $params = [];
        $types  = '';

        if ($filterMemberType !== 'ALL') {
            if ($filterMemberType === 'GRIEVANCE_MEMBER') {
                $sql .= " AND cm.member_type = 'GRIEVANCE_MEMBER'";
            } elseif ($filterMemberType === 'MANAGEMENT') {
                $sql .= " AND (cm.member_type = 'MANAGEMENT' OR (cm.id IS NULL AND u.role = 'MANAGEMENT'))";
            } elseif ($filterMemberType === 'TEACHING') {
                $sql .= " AND (cm.member_type = 'TEACHING' OR (cm.id IS NULL AND u.role = 'TEACHER'))";
            } elseif ($filterMemberType === 'NON_TEACHING') {
                $sql .= " AND (cm.member_type = 'NON_TEACHING' OR (cm.id IS NULL AND u.role = 'NON_TEACHING'))";
            } elseif ($filterMemberType === 'PARENT') {
                $sql .= " AND (cm.member_type = 'PARENT' OR (cm.id IS NULL AND u.role = 'PARENT'))";
            } elseif ($filterMemberType === 'STUDENT') {
                $sql .= " AND (cm.member_type = 'STUDENT' OR (cm.id IS NULL AND u.role = 'STUDENT'))";
            }
        }

        if ($filterStatus !== 'All') {
            $sql .= " AND u.status = ?";
            $params[] = $filterStatus;
            $types   .= 's';
        }

        $sql .= " ORDER BY u.id ASC";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $res = $stmt->get_result();

            while ($row = $res->fetch_assoc()) {
                $users[] = $row;
            }
            $stmt->close();
        }
    } catch (Throwable $ex) {
        error_log('[Fetch All Users] ' . $ex->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Grievance Cell Member List — Admin | Rajagiri College Grievance Portal</title>
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
            flashOut: { '0%': { opacity: '1', transform: 'translateY(0)', maxHeight: '200px' }, '100%': { opacity: '0', transform: 'translateY(-10px)', maxHeight: '0px' } },
            shimmer: { '0%': { backgroundPosition: '-200% 0' }, '100%': { backgroundPosition: '200% 0' } },
            pulseRing: { '0%': { boxShadow: '0 0 0 0 rgba(0,110,116,0.45)' }, '70%': { boxShadow: '0 0 0 12px rgba(0,110,116,0)' }, '100%': { boxShadow: '0 0 0 0 rgba(0,110,116,0)' } }
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
    .hero-dots { background-image: radial-gradient(rgba(255,255,255,0.35) 1.5px, transparent 1.5px); background-size: 22px 22px; }
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

        <a href="members.php" class="sidebar-link sidebar-active-glow group relative w-full h-12 rounded-xl bg-white text-teal-800 shadow-md flex items-center px-3 flex-shrink-0 transition-all">
          <i data-lucide="users" class="w-6 h-6 flex-shrink-0"></i>
          <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap opacity-0 w-0 overflow-hidden transition-all duration-200">Members</span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-teal-900 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Members</span>
        </a>

        <a href="grievances.php" class="sidebar-link group relative w-full h-12 rounded-xl hover:bg-white/10 flex items-center text-white transition-all px-3 flex-shrink-0">
          <i data-lucide="clipboard-list" class="w-6 h-6 flex-shrink-0"></i>
          <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap opacity-0 w-0 overflow-hidden transition-all duration-200">Grievance</span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-teal-900 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Grievance</span>
        </a>

        <a href="settings.php" class="sidebar-link group relative w-full h-12 rounded-xl hover:bg-white/10 flex items-center text-white transition-all px-3 flex-shrink-0">
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
                <img src="<?= e($profilePictureUrl) ?>" alt="<?= e($displayName) ?>" class="w-9 h-9 rounded-full object-cover border-2 border-teal-600" />
              <?php else: ?>
                <div class="w-9 h-9 rounded-full bg-teal-600 flex items-center justify-center text-white"><i data-lucide="user" class="w-5 h-5 text-white"></i></div>
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
                <i data-lucide="layout-dashboard" class="w-4 h-4 mr-3 text-teal-600"></i><span class="font-medium">Dashboard</span>
                <i data-lucide="arrow-right" class="w-4 h-4 ml-auto opacity-0 group-hover:opacity-100 text-teal-600 transition-opacity"></i>
              </a>
              <a href="profile.php" class="flex items-center px-4 py-2.5 text-sm text-teal-900 hover:bg-teal-50 transition-all group">
                <i data-lucide="user" class="w-4 h-4 mr-3 text-teal-600"></i><span class="font-medium">My Profile</span>
                <i data-lucide="arrow-right" class="w-4 h-4 ml-auto opacity-0 group-hover:opacity-100 text-teal-600 transition-opacity"></i>
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

        <div class="max-w-6xl mx-auto mb-6 animate-fade-in-up">
          <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
              <div class="flex items-center gap-2 mb-2">
                <span class="inline-flex items-center justify-center w-2 h-2 rounded-full bg-teal-600 animate-pulse-ring"></span>
                <p class="text-xs font-semibold text-teal-600 uppercase tracking-wider">Members · Cell Members</p>
              </div>
              <h1 class="text-2xl md:text-3xl font-bold text-teal-900 mb-3">
                <span class="heading-underline">Grievance Cell Member List</span>
              </h1>
              <nav class="flex flex-wrap items-center gap-2 text-sm text-teal-900/60">
                <a href="dashboard.php" class="inline-flex items-center gap-1 hover:text-teal-600 transition-colors">
                  <i data-lucide="layout-dashboard" class="w-4 h-4"></i>Dashboard
                </a>
                <span class="text-teal-900/30">/</span>
                <a href="members.php" class="hover:text-teal-600 transition-colors">Members</a>
                <span class="text-teal-900/30">/</span>
                <span class="text-teal-600 font-semibold">Cell Members</span>
              </nav>
            </div>

            <button type="button" onclick="openMemberModal('add')" title="Add Member"
                    class="btn-sheen inline-flex items-center justify-center gap-2 px-5 py-3 rounded-lg font-semibold text-white
                           bg-teal-600 hover:bg-teal-700 shadow-sm hover:shadow-md
                           transition-all duration-200 cursor-pointer shrink-0">
              <i data-lucide="plus" class="w-5 h-5"></i>
              <span>Add Member</span>
            </button>
          </div>
        </div>

        <?php if ($flashSuccess !== ''): ?>
          <div id="flashSuccessBox" class="max-w-6xl mx-auto mb-6 rounded-xl border-2 border-emerald-200 bg-emerald-50 px-4 py-3 flex items-start gap-2 animate-flash-in overflow-hidden">
            <i data-lucide="check-circle" class="w-5 h-5 text-emerald-600 flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-emerald-800 font-medium"><?= e($flashSuccess) ?></p>
          </div>
        <?php endif; ?>

        <?php if ($flashError !== ''): ?>
          <div id="flashErrorBox" class="max-w-6xl mx-auto mb-6 rounded-xl border-2 border-red-200 bg-red-50 px-4 py-3 flex items-start gap-2 animate-flash-in overflow-hidden">
            <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-red-700 font-medium"><?= e($flashError) ?></p>
          </div>
        <?php endif; ?>

        <div class="max-w-6xl mx-auto mb-5 animate-fade-in-up" style="animation-delay: 40ms;">
          <div class="bg-white rounded-xl border-2 border-teal-100 px-5 py-4">
            <form method="GET" action="cell_members.php" class="grid grid-cols-1 md:grid-cols-12 gap-4 items-end">
              <div class="md:col-span-4">
                <label for="member_type_filter" class="block text-sm font-semibold text-teal-900 mb-1.5">Member Type</label>
                <select name="member_type" id="member_type_filter" class="w-full px-4 py-2.5 border-2 border-teal-100 rounded-lg text-sm bg-white text-teal-900 font-medium focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10 hover:border-teal-200 transition-all">
                  <option value="ALL"              <?= $filterMemberType === 'ALL'              ? 'selected' : '' ?>>ALL</option>
                  <option value="GRIEVANCE_MEMBER" <?= $filterMemberType === 'GRIEVANCE_MEMBER' ? 'selected' : '' ?>>GRIEVANCE MEMBER</option>
                  <option value="MANAGEMENT"       <?= $filterMemberType === 'MANAGEMENT'       ? 'selected' : '' ?>>MANAGEMENT</option>
                  <option value="TEACHING"         <?= $filterMemberType === 'TEACHING'         ? 'selected' : '' ?>>TEACHING</option>
                  <option value="NON_TEACHING"     <?= $filterMemberType === 'NON_TEACHING'     ? 'selected' : '' ?>>NON TEACHING</option>
                  <option value="PARENT"           <?= $filterMemberType === 'PARENT'           ? 'selected' : '' ?>>PARENT</option>
                  <option value="STUDENT"          <?= $filterMemberType === 'STUDENT'          ? 'selected' : '' ?>>STUDENT</option>
                </select>
              </div>

              <div class="md:col-span-4">
                <label for="status_filter" class="block text-sm font-semibold text-teal-900 mb-1.5">Status</label>
                <select name="status" id="status_filter" class="w-full px-4 py-2.5 border-2 border-teal-100 rounded-lg text-sm bg-white text-teal-900 font-medium focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10 hover:border-teal-200 transition-all">
                  <option value="All"        <?= $filterStatus === 'All'        ? 'selected' : '' ?>>All</option>
                  <option value="Approved"   <?= $filterStatus === 'Approved'   ? 'selected' : '' ?>>Approved</option>
                  <option value="Pending"    <?= $filterStatus === 'Pending'    ? 'selected' : '' ?>>Pending</option>
                  <option value="Rejected"   <?= $filterStatus === 'Rejected'   ? 'selected' : '' ?>>Rejected</option>
                  <option value="Terminated" <?= $filterStatus === 'Terminated' ? 'selected' : '' ?>>Terminated</option>
                </select>
              </div>

              <div class="md:col-span-4 flex md:justify-end">
                <button type="submit" class="btn-sheen w-full md:w-auto px-8 py-2.5 rounded-lg bg-teal-600 hover:bg-teal-700 text-white font-semibold shadow-sm hover:shadow-md transition-all duration-200 active:scale-95">
                  Submit
                </button>
              </div>
            </form>
          </div>
        </div>

        <div class="max-w-6xl mx-auto mb-5 animate-fade-in-up" style="animation-delay: 80ms;">
          <div class="bg-white rounded-xl border-2 border-teal-100 px-5 py-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
              <div class="flex items-center gap-3">
                <span class="text-sm text-teal-900/70">Show</span>
                <select id="entriesPerPage" class="px-3 py-1.5 border-2 border-teal-100 rounded-lg text-sm font-medium text-teal-900 focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10 hover:border-teal-200 transition-colors bg-white">
                  <option value="10" selected>10</option>
                  <option value="25">25</option>
                  <option value="50">50</option>
                  <option value="100">100</option>
                </select>
                <span class="text-sm text-teal-900/70">entries</span>
              </div>

              <div class="relative w-full sm:w-80">
                <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-teal-900/40"></i>
                <input type="text" id="searchInput" placeholder="Search..." class="w-full pl-10 pr-4 py-2 border-2 border-teal-100 rounded-lg text-sm bg-white text-teal-900 placeholder-teal-900/40 focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10 hover:border-teal-200 transition-all" />
              </div>
            </div>
          </div>
        </div>

        <div class="max-w-6xl mx-auto animate-fade-in-up" style="animation-delay: 120ms;">
          <div class="bg-white rounded-2xl border-2 border-teal-100 overflow-hidden">
            <div class="overflow-x-auto">
              <table class="w-full" id="membersTable">
                <thead>
                  <tr class="bg-teal-600 text-white">
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Sl.No.</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Name</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Member Type</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Email Id</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Mobile No</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Status</th>
                    <th class="px-6 py-4 text-center text-xs font-bold uppercase tracking-wider">Actions</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-teal-100" id="membersTableBody">

                  <?php if (empty($users)): ?>
                    <tr>
                      <td colspan="7" class="px-6 py-16 text-center text-teal-900/60">
                        <div class="flex flex-col items-center justify-center">
                          <div class="w-16 h-16 bg-teal-50 rounded-2xl flex items-center justify-center mb-4">
                            <i data-lucide="users" class="w-8 h-8 text-teal-600"></i>
                          </div>
                          <p class="text-lg font-semibold text-teal-900">No users found</p>
                          <p class="text-sm text-teal-900/60 mt-1 mb-4">Try adjusting the filters, or add a new member with the "+" button.</p>
                        </div>
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($users as $index => $row): ?>
                      <?php
                        $rowUserId        = (int)    $row['user_id'];
                        $rowUsername      = (string) ($row['username']         ?? '');
                        $rowUserRole      = (string) ($row['user_role']        ?? '');
                        $rowUserStatus    = (string) ($row['user_status']      ?? 'Approved');

                        $rowMemberType    = $row['member_type'] !== null ? (string) $row['member_type'] : null;
                        $rowDesignationId = (int)    ($row['designation_id']   ?? 0);
                        $rowGrievanceType = (int)    ($row['grievance_type_id'] ?? 0);

                        $rowDisplayName   = (string) ($row['display_name']     ?? $rowUsername);
                        $rowDisplayEmail  = (string) ($row['display_email']    ?? '');
                        $rowDisplayMobile = (string) ($row['display_mobile']   ?? '');

                        $rowIsCellMember  = isCellMember($rowMemberType);
                        $rowIsManagement  = ($rowMemberType === 'MANAGEMENT');

                        $rowMemberTypeLbl = resolveMemberTypeLabel($rowMemberType, $rowUserRole);

                        $statusCls = match (strtolower($rowUserStatus)) {
                            'approved'   => 'bg-emerald-100 text-emerald-800 border-emerald-200',
                            'pending'    => 'bg-amber-100 text-amber-800 border-amber-200',
                            'rejected'   => 'bg-red-100 text-red-800 border-red-200',
                            'terminated' => 'bg-slate-200 text-slate-700 border-slate-300',
                            default      => 'bg-slate-100 text-slate-700 border-slate-200',
                        };
                      ?>
                      <tr class="hover:bg-teal-50/60 transition-colors">
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-teal-900/70"><?= $index + 1 ?></td>
                        <td class="px-6 py-4 text-sm font-semibold text-teal-900"><?= e($rowDisplayName) ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-teal-900/80"><?= e($rowMemberTypeLbl) ?></td>
                        <td class="px-6 py-4 text-sm text-teal-900/70 break-all"><?= e($rowDisplayEmail !== '' ? $rowDisplayEmail : '—') ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-teal-900/70"><?= e($rowDisplayMobile !== '' ? $rowDisplayMobile : '—') ?></td>
                        <td class="px-6 py-4 whitespace-nowrap">
                          <span class="inline-flex items-center px-3 py-1 text-xs font-semibold rounded-full border <?= $statusCls ?>"><?= e($rowUserStatus) ?></span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                          <div class="grid grid-cols-2 gap-1.5 w-fit mx-auto">
                            <button type="button" title="Set password"
                                    onclick='openSetPasswordModal(<?= $rowUserId ?>, <?= json_encode($rowDisplayName) ?>)'
                                    class="w-8 h-8 rounded-lg bg-teal-50 hover:bg-teal-600 flex items-center justify-center text-teal-600 hover:text-white transition-colors duration-200">
                              <i data-lucide="key-round" class="w-3.5 h-3.5"></i>
                            </button>

                            <?php if ($rowIsCellMember): ?>
                              <button type="button" title="Edit member"
                                      onclick='openMemberModal("edit", <?= $rowUserId ?>, <?= json_encode($rowDisplayName) ?>, <?= $rowIsManagement ? '1' : '0' ?>, <?= json_encode($rowDesignationId) ?>, <?= json_encode($rowGrievanceType) ?>, <?= json_encode($rowDisplayEmail) ?>, <?= json_encode($rowDisplayMobile) ?>)'
                                      class="w-8 h-8 rounded-lg bg-teal-50 hover:bg-teal-600 flex items-center justify-center text-teal-600 hover:text-white transition-colors duration-200">
                                <i data-lucide="pencil" class="w-3.5 h-3.5"></i>
                              </button>
                            <?php else: ?>
                              <button type="button" title="Edit unavailable (not a cell member)" disabled
                                      class="w-8 h-8 rounded-lg bg-slate-100 flex items-center justify-center text-slate-300 cursor-not-allowed">
                                <i data-lucide="pencil-off" class="w-3.5 h-3.5"></i>
                              </button>
                            <?php endif; ?>

                            <button type="button" title="Deactivate user" onclick='confirmDeactivate(<?= $rowUserId ?>, <?= json_encode($rowDisplayName) ?>)'
                                    class="w-8 h-8 rounded-lg bg-teal-50 hover:bg-teal-600 flex items-center justify-center text-teal-600 hover:text-white transition-colors duration-200">
                              <i data-lucide="user-x" class="w-3.5 h-3.5"></i>
                            </button>

                            <button type="button" title="Delete user" onclick='confirmDeleteMember(<?= $rowUserId ?>, <?= json_encode($rowDisplayName) ?>)'
                                    class="w-8 h-8 rounded-lg bg-red-50 hover:bg-red-500 flex items-center justify-center text-red-500 hover:text-white transition-colors duration-200">
                              <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                            </button>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>

                </tbody>
              </table>
            </div>

            <?php if (!empty($users)): ?>
              <div class="px-6 py-4 bg-teal-50/50 border-t border-teal-100 flex flex-col sm:flex-row items-center justify-between gap-4">
                <p class="text-sm text-teal-900/70" id="tableInfo">
                  Showing <span class="font-semibold text-teal-900">1</span> to
                  <span class="font-semibold text-teal-900"><?= count($users) ?></span> of
                  <span class="font-semibold text-teal-900"><?= count($users) ?></span> entries
                </p>
                <div class="flex items-center gap-2">
                  <button type="button" class="px-4 py-2 rounded-lg text-sm font-medium text-teal-900/40 bg-teal-50 border-2 border-teal-100 cursor-not-allowed" disabled>Previous</button>
                  <span class="inline-flex items-center justify-center w-9 h-9 rounded-lg bg-teal-600 text-white text-sm font-bold shadow-sm">1</span>
                  <button type="button" class="px-4 py-2 rounded-lg text-sm font-medium text-teal-900/40 bg-teal-50 border-2 border-teal-100 cursor-not-allowed" disabled>Next</button>
                </div>
              </div>
            <?php endif; ?>

          </div>
        </div>

      </main>

      <footer class="bg-teal-900 text-white mt-auto">
        <div class="roofline"></div>
        <div class="px-4 sm:px-6 py-6">
          <div class="max-w-7xl mx-auto text-center">
            <p class="text-xs text-teal-200/70">
              Copyright &copy; <?= date('Y') ?>
              <span class="font-bold text-white">Rajagiri College of Social Sciences</span>. All rights reserved.
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
  <!-- ADD / EDIT MEMBER MODAL                                      -->
  <!-- ============================================================ -->
  <div id="memberModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeMemberModal()"></div>

    <div class="relative w-full max-w-lg bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden max-h-[92vh] flex flex-col">
      <div class="h-1.5 w-full bg-teal-600"></div>

      <div class="flex items-center justify-between px-6 py-4 border-b border-teal-100">
        <h3 id="memberModalTitle" class="text-lg font-bold text-teal-900">Create Grievance Cell Member</h3>
        <button type="button" onclick="closeMemberModal()" class="w-8 h-8 rounded-lg hover:bg-teal-50 flex items-center justify-center text-teal-900/60 transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form id="memberForm" method="POST" action="cell_members.php" class="p-6 space-y-4 overflow-y-auto flex-1" novalidate>
        <input type="hidden" name="action" id="formAction" value="add_member" />
        <input type="hidden" name="user_id" id="formUserId" value="" />
        <input type="hidden" name="filter_member_type" value="<?= e($filterMemberType) ?>" />
        <input type="hidden" name="filter_status" value="<?= e($filterStatus) ?>" />

        <div class="space-y-2">
          <label for="name" class="block text-sm font-semibold text-teal-900">Name<span class="text-red-500">*</span></label>
          <input type="text" name="name" id="name" required placeholder="" class="w-full px-4 py-3 border-2 border-teal-100 rounded-lg bg-teal-50/40 text-teal-900 font-medium text-sm placeholder-teal-900/40 focus:outline-none focus:border-teal-600 focus:bg-white focus:ring-4 focus:ring-teal-600/10 hover:border-teal-200 transition-all" />
          <p class="text-[11px] text-teal-900/60 mt-1">
            This will also be used as the login <span class="font-semibold">username</span> (case-sensitive, exactly as typed).
          </p>
        </div>

        <div class="space-y-2">
          <label for="designation_id" class="block text-sm font-semibold text-teal-900">Designation<span class="text-red-500">*</span></label>
          <select name="designation_id" id="designation_id" required class="w-full px-4 py-3 border-2 border-teal-100 rounded-lg appearance-none bg-teal-50/40 text-teal-900 font-medium text-sm focus:outline-none focus:border-teal-600 focus:bg-white focus:ring-4 focus:ring-teal-600/10 hover:border-teal-200 transition-all">
            <option value="">Select</option>
            <?php foreach ($designationOptions as $opt): ?>
              <option value="<?= (int) $opt['id'] ?>"><?= e($opt['designation_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="space-y-2">
          <label class="block text-sm font-semibold text-teal-900">Management Member</label>
          <label class="inline-flex items-center cursor-pointer select-none">
            <input type="checkbox" name="is_management" id="is_management" value="1" class="w-5 h-5 rounded border-teal-300 text-teal-600 focus:ring-teal-600/30 cursor-pointer" onchange="toggleGrievanceType()" />
          </label>
        </div>

        <div class="space-y-2" id="grievanceTypeBlock">
          <label for="grievance_type_id" class="block text-sm font-semibold text-teal-900">
            Grievance Type<span class="text-red-500" id="grievanceTypeRequired">*</span>
          </label>
          <select name="grievance_type_id" id="grievance_type_id" class="w-full px-4 py-3 border-2 border-teal-100 rounded-lg appearance-none bg-teal-50/40 text-teal-900 font-medium text-sm focus:outline-none focus:border-teal-600 focus:bg-white focus:ring-4 focus:ring-teal-600/10 hover:border-teal-200 transition-all">
            <option value="">Select Some Options</option>
            <?php foreach ($grievanceTypeOptions as $opt): ?>
              <option value="<?= (int) $opt['id'] ?>"><?= e($opt['type_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="space-y-2">
          <label for="email" class="block text-sm font-semibold text-teal-900">Email Id<span class="text-red-500">*</span></label>
          <input type="email" name="email" id="email" required placeholder="" class="w-full px-4 py-3 border-2 border-teal-100 rounded-lg bg-teal-50/40 text-teal-900 font-medium text-sm placeholder-teal-900/40 focus:outline-none focus:border-teal-600 focus:bg-white focus:ring-4 focus:ring-teal-600/10 hover:border-teal-200 transition-all" />
        </div>

        <div class="space-y-2">
          <label for="mobile_number" class="block text-sm font-semibold text-teal-900">Mobile Number<span class="text-red-500">*</span></label>
          <input type="tel" name="mobile_number" id="mobile_number" required inputmode="numeric" pattern="[0-9]{10}" minlength="10" maxlength="10" title="Please enter exactly 10 digits" placeholder="" class="w-full px-4 py-3 border-2 border-teal-100 rounded-lg bg-teal-50/40 text-teal-900 font-medium text-sm placeholder-teal-900/40 focus:outline-none focus:border-teal-600 focus:bg-white focus:ring-4 focus:ring-teal-600/10 hover:border-teal-200 transition-all" />
        </div>

        <div class="pt-3 flex justify-center">
          <button type="submit" class="btn-sheen px-10 py-3 rounded-lg bg-teal-600 hover:bg-teal-700 text-white font-semibold shadow-sm hover:shadow-md transition-all duration-200 cursor-pointer">
            Submit
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- ============================================================ -->
  <!-- SET PASSWORD MODAL                                           -->
  <!-- ============================================================ -->
  <div id="setPasswordModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeSetPasswordModal()"></div>

    <div class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden max-h-[92vh] flex flex-col">
      <div class="h-1.5 w-full bg-teal-600"></div>

      <div class="flex items-center justify-between px-6 py-4 border-b border-teal-100">
        <h3 class="text-lg font-bold text-teal-900">Set Password</h3>
        <button type="button" onclick="closeSetPasswordModal()" class="w-8 h-8 rounded-lg hover:bg-teal-50 flex items-center justify-center text-teal-900/60 transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form id="setPasswordForm" method="POST" action="cell_members.php" class="p-6 space-y-4 overflow-y-auto flex-1" novalidate>
        <input type="hidden" name="action" value="set_password" />
        <input type="hidden" name="user_id" id="setPasswordUserId" value="" />
        <input type="hidden" name="filter_member_type" value="<?= e($filterMemberType) ?>" />
        <input type="hidden" name="filter_status" value="<?= e($filterStatus) ?>" />

        <p class="text-sm text-teal-900/70 leading-relaxed">
          Set a new password for
          <span id="setPasswordNameDisplay" class="font-bold text-teal-700 break-words">this user</span>.
        </p>

        <div class="space-y-2">
          <label for="new_password" class="block text-sm font-semibold text-teal-900">Password<span class="text-red-500">*</span></label>
          <div class="relative">
            <input type="password" name="new_password" id="new_password" required minlength="6" autocomplete="new-password" placeholder="Enter password" class="w-full pl-4 pr-12 py-3 border-2 border-teal-100 rounded-lg bg-teal-50/40 text-teal-900 font-medium text-sm placeholder-teal-900/40 focus:outline-none focus:border-teal-600 focus:bg-white focus:ring-4 focus:ring-teal-600/10 hover:border-teal-200 transition-all" />
            <button type="button" onclick="togglePwdVisibility('new_password', this)" tabindex="-1" class="absolute right-2 top-1/2 -translate-y-1/2 w-9 h-9 rounded-lg flex items-center justify-center text-teal-600/60 hover:text-teal-600 hover:bg-teal-50 transition-colors">
              <i data-lucide="eye" class="w-5 h-5"></i>
            </button>
          </div>
          <p class="text-xs text-teal-900/60">Minimum 6 characters.</p>
        </div>

        <div class="space-y-2">
          <label for="confirm_password" class="block text-sm font-semibold text-teal-900">Confirm Password<span class="text-red-500">*</span></label>
          <div class="relative">
            <input type="password" name="confirm_password" id="confirm_password" required minlength="6" autocomplete="new-password" placeholder="Re-enter password" class="w-full pl-4 pr-12 py-3 border-2 border-teal-100 rounded-lg bg-teal-50/40 text-teal-900 font-medium text-sm placeholder-teal-900/40 focus:outline-none focus:border-teal-600 focus:bg-white focus:ring-4 focus:ring-teal-600/10 hover:border-teal-200 transition-all" />
            <button type="button" onclick="togglePwdVisibility('confirm_password', this)" tabindex="-1" class="absolute right-2 top-1/2 -translate-y-1/2 w-9 h-9 rounded-lg flex items-center justify-center text-teal-600/60 hover:text-teal-600 hover:bg-teal-50 transition-colors">
              <i data-lucide="eye" class="w-5 h-5"></i>
            </button>
          </div>
          <p id="setPwdMatchMsg" class="text-xs text-teal-900/60">Passwords must match.</p>
        </div>

        <div class="pt-3 flex flex-col-reverse sm:flex-row justify-center gap-3">
          <button type="button" onclick="closeSetPasswordModal()" class="px-6 py-3 rounded-lg font-semibold text-teal-900 bg-white border-2 border-teal-200 hover:border-teal-600 hover:bg-teal-50 transition-all duration-200">
            Cancel
          </button>
          <button type="submit" class="btn-sheen px-8 py-3 rounded-lg bg-teal-600 hover:bg-teal-700 text-white font-semibold shadow-sm hover:shadow-md transition-all duration-200 cursor-pointer">
            Save Password
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- ============================================================ -->
  <!-- DELETE MODAL                                                 -->
  <!-- ============================================================ -->
  <div id="deleteConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeDeleteModal()"></div>
    <div id="deleteConfirmPanel" class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-teal-600"></div>
      <div class="px-6 pt-6 pb-2 flex flex-col items-center text-center">
        <div class="w-16 h-16 rounded-full flex items-center justify-center mb-4 bg-red-50 ring-4 ring-red-100/60">
          <i data-lucide="trash-2" class="w-8 h-8 text-red-500"></i>
        </div>
        <h3 class="text-xl font-bold text-teal-900 mb-2">Delete User?</h3>
        <p class="text-sm text-teal-900/70 leading-relaxed">You are about to permanently delete <span id="deleteMemberNameDisplay" class="font-bold text-teal-700 break-words">this user</span>.</p>
        <p class="text-xs text-red-500 font-medium mt-3 flex items-center gap-1.5"><i data-lucide="alert-triangle" class="w-3.5 h-3.5"></i>This action cannot be undone.</p>
      </div>
      <div class="px-6 py-5 mt-2 flex flex-col-reverse sm:flex-row gap-3">
        <button type="button" onclick="closeDeleteModal()" class="flex-1 px-5 py-3 rounded-lg font-semibold text-teal-900 bg-white border-2 border-teal-200 hover:border-teal-600 hover:bg-teal-50 transition-all duration-200">Cancel</button>
        <button type="button" id="confirmDeleteBtn" class="flex-1 px-5 py-3 rounded-lg font-bold text-white bg-teal-600 hover:bg-teal-700 shadow-sm hover:shadow-md transition-all duration-200 flex items-center justify-center gap-2">
          <i data-lucide="trash-2" class="w-4 h-4"></i><span>Delete</span>
        </button>
      </div>
    </div>
  </div>

  <!-- ============================================================ -->
  <!-- DEACTIVATE MODAL                                             -->
  <!-- ============================================================ -->
  <div id="deactivateConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeDeactivateModal()"></div>
    <div id="deactivateConfirmPanel" class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-teal-600"></div>
      <div class="px-6 pt-6 pb-2 flex flex-col items-center text-center">
        <div class="w-16 h-16 rounded-full flex items-center justify-center mb-4 bg-teal-50 ring-4 ring-teal-100/60">
          <i data-lucide="user-x" class="w-8 h-8 text-teal-600"></i>
        </div>
        <h3 class="text-xl font-bold text-teal-900 mb-2">Deactivate User?</h3>
        <p class="text-sm text-teal-900/70 leading-relaxed"><span id="deactivateMemberNameDisplay" class="font-bold text-teal-700 break-words">This user</span> will be marked as <span class="font-bold text-teal-700">Terminated</span> and will no longer be able to log in.</p>
      </div>
      <div class="px-6 py-5 mt-2 flex flex-col-reverse sm:flex-row gap-3">
        <button type="button" onclick="closeDeactivateModal()" class="flex-1 px-5 py-3 rounded-lg font-semibold text-teal-900 bg-white border-2 border-teal-200 hover:border-teal-600 hover:bg-teal-50 transition-all duration-200">Cancel</button>
        <button type="button" id="confirmDeactivateBtn" class="flex-1 px-5 py-3 rounded-lg font-bold text-white bg-teal-600 hover:bg-teal-700 shadow-sm hover:shadow-md transition-all duration-200 flex items-center justify-center gap-2">
          <i data-lucide="user-x" class="w-4 h-4"></i><span>Deactivate</span>
        </button>
      </div>
    </div>
  </div>

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
        <button type="button" onclick="closeLogoutModal()" class="flex-1 px-5 py-3 rounded-lg font-semibold text-teal-900 bg-white border-2 border-teal-200 hover:border-teal-600 hover:bg-teal-50 transition-all duration-200">
          Cancel
        </button>
        <button type="button" id="confirmLogoutBtn" class="flex-1 px-5 py-3 rounded-lg font-bold text-white bg-teal-600 hover:bg-teal-700 shadow-sm hover:shadow-md transition-all duration-200 flex items-center justify-center gap-2">
          <i data-lucide="log-out" class="w-4 h-4"></i><span>Log Out</span>
        </button>
      </div>
    </div>
  </div>

  <!-- HIDDEN FORMS -->
  <form id="deleteForm" method="POST" action="cell_members.php" class="hidden">
    <input type="hidden" name="action" value="delete_member" />
    <input type="hidden" name="user_id" id="deleteMemberId" value="" />
    <input type="hidden" name="filter_member_type" value="<?= e($filterMemberType) ?>" />
    <input type="hidden" name="filter_status" value="<?= e($filterStatus) ?>" />
  </form>

  <form id="deactivateForm" method="POST" action="cell_members.php" class="hidden">
    <input type="hidden" name="action" value="deactivate_member" />
    <input type="hidden" name="user_id" id="deactivateMemberId" value="" />
    <input type="hidden" name="filter_member_type" value="<?= e($filterMemberType) ?>" />
    <input type="hidden" name="filter_status" value="<?= e($filterStatus) ?>" />
  </form>

  <script>
    document.addEventListener('DOMContentLoaded', function () {

      if (typeof lucide !== 'undefined') { lucide.createIcons(); }

      const sidebar = document.getElementById('adminSidebar');
      const main    = document.getElementById('adminMain');

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

      (function () {
        const mobileInput = document.getElementById('mobile_number');
        if (!mobileInput) return;
        mobileInput.addEventListener('input', function () { this.value = this.value.replace(/\D/g, '').slice(0, 10); });
      })();

      window.toggleGrievanceType = function () {
        const isMgmt = document.getElementById('is_management');
        const gBlock = document.getElementById('grievanceTypeBlock');
        const gSelect = document.getElementById('grievance_type_id');
        const gReq = document.getElementById('grievanceTypeRequired');
        if (!isMgmt || !gBlock || !gSelect) return;
        if (isMgmt.checked) {
          gBlock.classList.add('opacity-60');
          gSelect.removeAttribute('required');
          if (gReq) gReq.classList.add('hidden');
          gSelect.value = '';
        } else {
          gBlock.classList.remove('opacity-60');
          gSelect.setAttribute('required', 'required');
          if (gReq) gReq.classList.remove('hidden');
        }
      };

      const memberModal      = document.getElementById('memberModal');
      const memberModalTitle = document.getElementById('memberModalTitle');
      const memberForm       = document.getElementById('memberForm');
      const formAction       = document.getElementById('formAction');
      const formUserId       = document.getElementById('formUserId');
      const nameInput        = document.getElementById('name');
      const designationInput = document.getElementById('designation_id');
      const emailInput       = document.getElementById('email');
      const mobileInput      = document.getElementById('mobile_number');
      const isMgmtInput      = document.getElementById('is_management');
      const gTypeInput       = document.getElementById('grievance_type_id');

      window.openMemberModal = function (mode, userId, name, isManagement, designationId, grievanceTypeId, email, mobile) {
        memberModal.classList.remove('hidden');
        if (mode === 'edit') {
          memberModalTitle.textContent = 'Edit Grievance Cell Member';
          formAction.value = 'edit_member';
          formUserId.value = userId || '';
          nameInput.value  = name || '';
          designationInput.value = designationId ? String(designationId) : '';
          emailInput.value  = email || '';
          mobileInput.value = mobile || '';
          isMgmtInput.checked = String(isManagement) === '1';
          gTypeInput.value   = grievanceTypeId ? String(grievanceTypeId) : '';
        } else {
          memberModalTitle.textContent = 'Create Grievance Cell Member';
          formAction.value = 'add_member';
          formUserId.value = '';
          memberForm.reset();
          if (isMgmtInput) isMgmtInput.checked = false;
          if (gTypeInput) gTypeInput.value = '';
        }
        window.toggleGrievanceType();
        setTimeout(() => nameInput && nameInput.focus(), 50);
        if (typeof lucide !== 'undefined') lucide.createIcons();
      };

      window.closeMemberModal = function () {
        memberModal.classList.add('hidden');
        memberForm.reset();
        formAction.value = 'add_member';
        formUserId.value = '';
        if (isMgmtInput) isMgmtInput.checked = false;
      };

      const setPasswordModal     = document.getElementById('setPasswordModal');
      const setPasswordForm      = document.getElementById('setPasswordForm');
      const setPasswordUserIdEl  = document.getElementById('setPasswordUserId');
      const setPasswordNameEl    = document.getElementById('setPasswordNameDisplay');
      const newPasswordInput     = document.getElementById('new_password');
      const confirmPasswordInput = document.getElementById('confirm_password');
      const setPwdMatchMsg       = document.getElementById('setPwdMatchMsg');

      window.openSetPasswordModal = function (userId, name) {
        setPasswordUserIdEl.value = userId || '';
        setPasswordNameEl.textContent = '"' + (name || '') + '"';
        setPasswordForm.reset();
        if (setPwdMatchMsg) {
          setPwdMatchMsg.textContent = 'Passwords must match.';
          setPwdMatchMsg.classList.remove('text-red-500', 'text-emerald-600');
          setPwdMatchMsg.classList.add('text-teal-900/60');
        }
        setPasswordModal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        setTimeout(() => newPasswordInput && newPasswordInput.focus(), 60);
        if (typeof lucide !== 'undefined') lucide.createIcons();
      };

      window.closeSetPasswordModal = function () {
        setPasswordModal.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
        setPasswordForm.reset();
      };

      function checkSetPwdMatch() {
        if (!newPasswordInput || !confirmPasswordInput || !setPwdMatchMsg) return;
        const a = newPasswordInput.value;
        const b = confirmPasswordInput.value;
        if (b === '') {
          setPwdMatchMsg.textContent = 'Passwords must match.';
          setPwdMatchMsg.classList.remove('text-red-500', 'text-emerald-600');
          setPwdMatchMsg.classList.add('text-teal-900/60');
          return;
        }
        if (a === b) {
          setPwdMatchMsg.textContent = 'Passwords match.';
          setPwdMatchMsg.classList.remove('text-red-500', 'text-teal-900/60');
          setPwdMatchMsg.classList.add('text-emerald-600');
        } else {
          setPwdMatchMsg.textContent = 'Passwords do not match.';
          setPwdMatchMsg.classList.remove('text-emerald-600', 'text-teal-900/60');
          setPwdMatchMsg.classList.add('text-red-500');
        }
      }
      if (newPasswordInput && confirmPasswordInput) {
        newPasswordInput.addEventListener('input', checkSetPwdMatch);
        confirmPasswordInput.addEventListener('input', checkSetPwdMatch);
      }

      window.togglePwdVisibility = function (inputId, btn) {
        const input = document.getElementById(inputId);
        if (!input) return;
        const isHidden = input.type === 'password';
        input.type = isHidden ? 'text' : 'password';
        const icon = btn.querySelector('i');
        if (icon && typeof lucide !== 'undefined') {
          icon.setAttribute('data-lucide', isHidden ? 'eye-off' : 'eye');
          lucide.createIcons({ targets: [icon] });
        }
      };

      const deleteConfirmModal = document.getElementById('deleteConfirmModal');
      const deleteConfirmPanel = document.getElementById('deleteConfirmPanel');
      const deleteMemberNameEl = document.getElementById('deleteMemberNameDisplay');
      const confirmDeleteBtn   = document.getElementById('confirmDeleteBtn');
      let pendingDeleteId = null;

      window.confirmDeleteMember = function (userId, name) {
        pendingDeleteId = userId;
        if (deleteMemberNameEl) deleteMemberNameEl.textContent = '"' + name + '"';
        deleteConfirmModal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        if (deleteConfirmPanel) { deleteConfirmPanel.classList.remove('animate-confirm-shake'); void deleteConfirmPanel.offsetWidth; deleteConfirmPanel.classList.add('animate-confirm-shake'); }
        setTimeout(function () { if (confirmDeleteBtn) confirmDeleteBtn.focus(); }, 80);
        if (typeof lucide !== 'undefined') lucide.createIcons();
      };
      window.closeDeleteModal = function () { deleteConfirmModal.classList.add('hidden'); document.body.classList.remove('overflow-hidden'); pendingDeleteId = null; };
      if (confirmDeleteBtn) confirmDeleteBtn.addEventListener('click', function () {
        if (pendingDeleteId === null) return window.closeDeleteModal();
        const input = document.getElementById('deleteMemberId');
        const form = document.getElementById('deleteForm');
        if (input && form) { input.value = String(pendingDeleteId); form.submit(); }
      });

      const deactivateConfirmModal = document.getElementById('deactivateConfirmModal');
      const deactivateConfirmPanel = document.getElementById('deactivateConfirmPanel');
      const deactivateMemberNameEl = document.getElementById('deactivateMemberNameDisplay');
      const confirmDeactivateBtn   = document.getElementById('confirmDeactivateBtn');
      let pendingDeactivateId = null;

      window.confirmDeactivate = function (userId, name) {
        pendingDeactivateId = userId;
        if (deactivateMemberNameEl) deactivateMemberNameEl.textContent = '"' + name + '"';
        deactivateConfirmModal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        if (deactivateConfirmPanel) { deactivateConfirmPanel.classList.remove('animate-confirm-shake'); void deactivateConfirmPanel.offsetWidth; deactivateConfirmPanel.classList.add('animate-confirm-shake'); }
        setTimeout(function () { if (confirmDeactivateBtn) confirmDeactivateBtn.focus(); }, 80);
        if (typeof lucide !== 'undefined') lucide.createIcons();
      };
      window.closeDeactivateModal = function () { deactivateConfirmModal.classList.add('hidden'); document.body.classList.remove('overflow-hidden'); pendingDeactivateId = null; };
      if (confirmDeactivateBtn) confirmDeactivateBtn.addEventListener('click', function () {
        if (pendingDeactivateId === null) return window.closeDeactivateModal();
        const input = document.getElementById('deactivateMemberId');
        const form = document.getElementById('deactivateForm');
        if (input && form) { input.value = String(pendingDeactivateId); form.submit(); }
      });

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
      })();

      (function () {
        const searchInput = document.getElementById('searchInput');
        const tableBody = document.getElementById('membersTableBody');
        if (!searchInput || !tableBody) return;
        searchInput.addEventListener('input', function () {
          const term = this.value.toLowerCase().trim();
          tableBody.querySelectorAll('tr').forEach(function (row) {
            row.style.display = (term === '' || row.textContent.toLowerCase().indexOf(term) !== -1) ? '' : 'none';
          });
        });
      })();

      document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (memberModal && !memberModal.classList.contains('hidden')) window.closeMemberModal();
        if (setPasswordModal && !setPasswordModal.classList.contains('hidden')) window.closeSetPasswordModal();
        if (deleteConfirmModal && !deleteConfirmModal.classList.contains('hidden')) window.closeDeleteModal();
        if (deactivateConfirmModal && !deactivateConfirmModal.classList.contains('hidden')) window.closeDeactivateModal();
        if (logoutConfirmModal && !logoutConfirmModal.classList.contains('hidden')) window.closeLogoutModal();
      });

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
  </script>

  <script src="../assets/js/index.js"></script>
</body>
</html>
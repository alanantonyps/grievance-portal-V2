<?php
/**
 * admin/summary_details.php
 * ---------------------------------------------------------------------------
 * Admin — Summary Details (Grievance Listing + Create + Bulk Upload + View + Delete)
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

function statusBadgeClass(string $status): string
{
    return match (strtolower($status)) {
        'pending'     => 'bg-amber-100 text-amber-800 border-amber-200',
        'in progress' => 'bg-sky-100 text-sky-800 border-sky-200',
        'disposed'    => 'bg-emerald-100 text-emerald-800 border-emerald-200',
        'closed'      => 'bg-slate-100 text-slate-700 border-slate-200',
        'reopened'    => 'bg-pink-100 text-pink-800 border-pink-200',
        default       => 'bg-slate-100 text-slate-700 border-slate-200',
    };
}

function roleLabel(string $role): string
{
    $map = [
        'STUDENT'      => 'STUDENT',
        'PARENT'       => 'PARENT',
        'TEACHER'      => 'TEACHER',
        'NON_TEACHING' => 'NON TEACHING',
        'MANAGEMENT'   => 'MANAGEMENT',
        'ADMIN'        => 'ADMIN',
    ];
    return $map[$role] ?? $role;
}

function resolveAttachmentUrl(?string $path): ?string
{
    if (empty($path)) return null;
    $rel = ltrim((string) $path, '/');
    if (file_exists(__DIR__ . '/../' . $rel)) {
        return '../' . $rel;
    }
    return null;
}

function generateGrievanceNumber(mysqli $conn): string
{
    $prefix = 'GRV-' . date('Y') . '-';
    do {
        $suffix = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $candidate = $prefix . $suffix;

        $chk = $conn->prepare("SELECT id FROM grievances WHERE grievance_number = ? LIMIT 1");
        if ($chk) {
            $chk->bind_param('s', $candidate);
            $chk->execute();
            $exists = $chk->get_result()->num_rows > 0;
            $chk->close();
        } else {
            $exists = false;
        }
    } while ($exists);

    return $candidate;
}

function resolveComplainantUser(mysqli $conn, string $role, string $name, string $cellNo, string $classOrDept): int
{
    $role = strtoupper($role);

    $validRoles = ['STUDENT', 'PARENT', 'TEACHER', 'NON_TEACHING', 'MANAGEMENT'];
    if (!in_array($role, $validRoles, true)) {
        $role = 'STUDENT';
    }

    $userRoleEnum = $role;

    $profileTable = '';
    switch ($role) {
        case 'STUDENT':      $profileTable = 'students';     break;
        case 'PARENT':       $profileTable = 'parents';      break;
        case 'TEACHER':      $profileTable = 'cell_members'; break;
        case 'NON_TEACHING': $profileTable = 'cell_members'; break;
        case 'MANAGEMENT':   $profileTable = 'cell_members'; break;
    }

    if ($profileTable !== '') {
        $sql = "SELECT u.id
                FROM users u
                INNER JOIN {$profileTable} p ON p.user_id = u.id
                WHERE u.role = ? AND p.name = ?
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('ss', $userRoleEnum, $name);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                $existingId = (int) $res->fetch_assoc()['id'];
                $stmt->close();
                return $existingId;
            }
            $stmt->close();
        }
    }

    $baseUsername = strtolower(preg_replace('/[^a-z0-9]+/i', '.', $name));
    $baseUsername = trim($baseUsername, '.');
    if ($baseUsername === '') $baseUsername = 'user';

    $username = $baseUsername;
    $i = 1;
    while (true) {
        $chk = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $chk->bind_param('s', $username);
        $chk->execute();
        if ($chk->get_result()->num_rows === 0) {
            $chk->close();
            break;
        }
        $chk->close();
        $username = $baseUsername . $i;
        $i++;
        if ($i > 1000) {
            $username = $baseUsername . '_' . bin2hex(random_bytes(3));
            break;
        }
    }

    $defaultPassword = password_hash('User@' . random_int(1000, 9999), PASSWORD_BCRYPT);

    $stmtU = $conn->prepare("INSERT INTO users (username, password, role, status) VALUES (?, ?, ?, 'Approved')");
    if (!$stmtU) throw new Exception('Failed to prepare user insert.');
    $stmtU->bind_param('sss', $username, $defaultPassword, $userRoleEnum);
    $stmtU->execute();
    $newUserId = (int) $conn->insert_id;
    $stmtU->close();

    switch ($role) {
        case 'STUDENT':
            $classId = null;
            if ($classOrDept !== '') {
                $chkC = $conn->prepare("SELECT id FROM classes WHERE class_name = ? LIMIT 1");
                $chkC->bind_param('s', $classOrDept);
                $chkC->execute();
                $resC = $chkC->get_result();
                if ($resC && $resC->num_rows > 0) {
                    $classId = (int) $resC->fetch_assoc()['id'];
                }
                $chkC->close();
            }
            if ($classId === null) {
                $resFirst = $conn->query("SELECT id FROM classes ORDER BY id ASC LIMIT 1");
                if ($resFirst && $resFirst->num_rows > 0) {
                    $classId = (int) $resFirst->fetch_assoc()['id'];
                }
            }
            if ($classId === null) {
                throw new Exception('Cannot create student: no classes exist. Please add a class first.');
            }
            $stmtS = $conn->prepare("INSERT INTO students (user_id, class_id, name, email, contact_number) VALUES (?, ?, ?, ?, ?)");
            if (!$stmtS) throw new Exception('Failed to prepare student insert.');
            $placeholderEmail = 'student_' . $newUserId . '@rajagiri.edu';
            $stmtS->bind_param('iisss', $newUserId, $classId, $name, $placeholderEmail, $cellNo);
            $stmtS->execute();
            $stmtS->close();
            break;

        case 'PARENT':
            $stmtP = $conn->prepare("INSERT INTO parents (user_id, name, email, contact_number) VALUES (?, ?, ?, ?)");
            if (!$stmtP) throw new Exception('Failed to prepare parent insert.');
            $placeholderEmail = 'parent_' . $newUserId . '@rajagiri.edu';
            $stmtP->bind_param('isss', $newUserId, $name, $placeholderEmail, $cellNo);
            $stmtP->execute();
            $stmtP->close();
            break;

        case 'TEACHER':
        case 'NON_TEACHING':
        case 'MANAGEMENT':
            $resD = $conn->query("SELECT id FROM designations WHERE status = 'Active' ORDER BY id ASC LIMIT 1");
            $designationId = 0;
            if ($resD && $resD->num_rows > 0) {
                $designationId = (int) $resD->fetch_assoc()['id'];
            }
            if ($designationId <= 0) {
                $defaultName = 'General Staff';
                $postOcc = 'Non Teaching';
                $stmtD = $conn->prepare("INSERT INTO designations (designation_name, post_occupied, status) VALUES (?, ?, 'Active')");
                if ($stmtD) {
                    $stmtD->bind_param('ss', $defaultName, $postOcc);
                    $stmtD->execute();
                    $designationId = (int) $conn->insert_id;
                    $stmtD->close();
                }
            }
            if ($designationId <= 0) {
                throw new Exception('Cannot create staff member: no designation available.');
            }

            $memberType = match ($role) {
                'TEACHER'      => 'TEACHING',
                'NON_TEACHING' => 'NON_TEACHING',
                'MANAGEMENT'   => 'MANAGEMENT',
                default        => 'TEACHING',
            };

            $stmtC = $conn->prepare("INSERT INTO cell_members (user_id, designation_id, member_type, name, email, mobile_number) VALUES (?, ?, ?, ?, ?, ?)");
            if (!$stmtC) throw new Exception('Failed to prepare cell member insert.');
            $placeholderEmail = 'staff_' . $newUserId . '@rajagiri.edu';
            $stmtC->bind_param('iissss', $newUserId, $designationId, $memberType, $name, $placeholderEmail, $cellNo);
            $stmtC->execute();
            $stmtC->close();
            break;
    }

    return $newUserId;
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
        error_log('[Summary Details Admin Profile] ' . $ex->getMessage());
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

// GRIEVANCE TYPES
$grievanceTypeOptions = [];
if ($conn instanceof mysqli) {
    try {
        $res = $conn->query("SELECT id, type_name FROM grievance_types WHERE status = 'Active' ORDER BY type_name ASC");
        if ($res) while ($row = $res->fetch_assoc()) $grievanceTypeOptions[] = $row;
    } catch (Throwable $ex) {
        error_log('[Fetch Grievance Types] ' . $ex->getMessage());
    }
}

// TEMPLATE CSV DOWNLOAD
if (isset($_GET['download_template']) && (string) $_GET['download_template'] === '1') {
    $filename = 'summary_template.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, [
        'role', 'name', 'academic_year', 'class_name', 'complaint',
        'grievance_type', 'action_taken', 'cell_no',
        'posted_date', 'reply_date', 'replied_by',
    ]);

    fputcsv($out, [
        'STUDENT', 'John Doe', '2025-2026', 'SEMESTER I',
        'Grievance regarding library timing',
        'Grievance related to Admission',
        'Investigated and resolved', '9876543210',
        '2026-01-15', '2026-01-20', 'Dr. Bindya Varghese',
    ]);

    fclose($out);
    exit;
}

// FLASH
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

// HANDLE POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $conn instanceof mysqli) {

    $action = $_POST['action'] ?? '';

    // CREATE
    if ($action === 'create_summary') {
        $role          = trim((string) ($_POST['role']            ?? 'STUDENT'));
        $name          = trim((string) ($_POST['name']            ?? ''));
        $academicYear  = trim((string) ($_POST['academic_year']   ?? ''));
        $className     = trim((string) ($_POST['class_name']      ?? ''));
        $complaint     = trim((string) ($_POST['complaint']       ?? ''));
        $grievanceType = (int) ($_POST['grievance_type_id']       ?? 0);
        $actionTaken   = trim((string) ($_POST['action_taken']    ?? ''));
        $cellNo        = trim((string) ($_POST['cell_no']         ?? ''));
        $postedDate    = trim((string) ($_POST['posted_date']     ?? date('Y-m-d')));
        $replyDate     = trim((string) ($_POST['reply_date']      ?? ''));
        $repliedBy     = trim((string) ($_POST['replied_by']      ?? ''));

        if ($name === '' || $complaint === '' || $grievanceType <= 0 || $actionTaken === '' || $cellNo === '' || $repliedBy === '') {
            $flashError = 'Please fill in all required fields.';
        } elseif (!in_array($role, ['STUDENT', 'PARENT', 'TEACHER', 'NON_TEACHING', 'MANAGEMENT'], true)) {
            $flashError = 'Invalid role selected.';
        } else {
            try {
                $conn->begin_transaction();
                $complainantUserId = resolveComplainantUser($conn, $role, $name, $cellNo, $className);
                $grievanceNumber   = generateGrievanceNumber($conn);

                $status = 'Disposed';
                $description = $complaint . ($academicYear !== '' ? "\n\nAcademic Year: " . $academicYear : '');

                $stmt = $conn->prepare("INSERT INTO grievances
                                            (grievance_number, grievance_type_id, complainant_user_id, subject,
                                             description, status, reply_details, created_at, updated_at)
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if (!$stmt) throw new Exception('Failed to prepare grievance insert.');

                $subject   = mb_substr($complaint, 0, 250, 'UTF-8');
                $createdAt = $postedDate !== '' ? $postedDate . ' 00:00:00' : date('Y-m-d H:i:s');
                $updatedAt = $replyDate  !== '' ? $replyDate  . ' 00:00:00' : $createdAt;

                $stmt->bind_param('siissssss',
                    $grievanceNumber, $grievanceType, $complainantUserId,
                    $subject, $description, $status, $actionTaken,
                    $createdAt, $updatedAt
                );
                $stmt->execute();
                $stmt->close();

                $conn->commit();
                $flashSuccess = 'Record created successfully! Grievance No: ' . $grievanceNumber;
            } catch (Throwable $ex) {
                if ($conn instanceof mysqli) $conn->rollback();
                error_log('[Create Summary] ' . $ex->getMessage());
                $flashError = $ex->getMessage() ?: 'A system error occurred while creating the record.';
            }
        }

        if ($flashSuccess !== '' || $flashError !== '') {
            $_SESSION['flash_success'] = $flashSuccess;
            $_SESSION['flash_error']   = $flashError;
            header('Location: summary_details.php');
            exit;
        }
    }

    // DELETE
    if ($action === 'delete_summary') {
        $grievanceId = (int) ($_POST['grievance_id'] ?? 0);
        if ($grievanceId > 0) {
            try {
                $stmt = $conn->prepare("DELETE FROM grievances WHERE id = ?");
                if (!$stmt) throw new Exception('Failed to prepare delete.');
                $stmt->bind_param('i', $grievanceId);
                $stmt->execute();
                $stmt->close();
                $flashSuccess = 'Grievance deleted successfully.';
            } catch (Throwable $ex) {
                error_log('[Delete Summary] ' . $ex->getMessage());
                $flashError = 'A system error occurred while deleting the record.';
            }
        }
        if ($flashSuccess !== '' || $flashError !== '') {
            $_SESSION['flash_success'] = $flashSuccess;
            $_SESSION['flash_error']   = $flashError;
            header('Location: summary_details.php');
            exit;
        }
    }

    // BULK UPLOAD
    if ($action === 'bulk_upload') {
        if (!isset($_FILES['csv_file']) || !is_array($_FILES['csv_file'])) {
            $flashError = 'Please select a CSV file to upload.';
        } elseif ((int) ($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $flashError = 'File upload failed. Please try again.';
        } else {
            $tmpPath  = (string) ($_FILES['csv_file']['tmp_name'] ?? '');
            $origName = (string) ($_FILES['csv_file']['name']     ?? '');
            $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

            if (!in_array($ext, ['csv', 'txt'], true)) {
                $flashError = 'Unsupported file type. Please upload a CSV file.';
            } elseif (!is_uploaded_file($tmpPath)) {
                $flashError = 'Invalid upload. Please try again.';
            } else {
                $handle = fopen($tmpPath, 'r');
                if ($handle === false) {
                    $flashError = 'Unable to read the uploaded file.';
                } else {
                    @set_time_limit(0);
                    $insertedCount = 0;
                    $skippedRows   = [];
                    $rowNumber     = 0;

                    try {
                        $conn->begin_transaction();

                        $typeLookup = [];
                        $resT = $conn->query("SELECT id, type_name FROM grievance_types");
                        if ($resT) {
                            while ($rowT = $resT->fetch_assoc()) {
                                $typeLookup[strtolower(trim((string) $rowT['type_name']))] = (int) $rowT['id'];
                            }
                        }

                        while (($row = fgetcsv($handle, 0, ',')) !== false) {
                            $rowNumber++;

                            if (count($row) === 1 && trim((string) $row[0]) === '') continue;

                            if ($rowNumber === 1) {
                                $firstCell = strtolower(trim((string) ($row[0] ?? '')));
                                if ($firstCell === 'role') continue;
                            }

                            if (count($row) < 11) {
                                $skippedRows[] = "Row {$rowNumber}: Not enough columns (expected 11).";
                                continue;
                            }

                            $rRole          = trim((string) ($row[0]  ?? ''));
                            $rName          = trim((string) ($row[1]  ?? ''));
                            $rAcademicYear  = trim((string) ($row[2]  ?? ''));
                            $rClassName     = trim((string) ($row[3]  ?? ''));
                            $rComplaint     = trim((string) ($row[4]  ?? ''));
                            $rGrievanceType = trim((string) ($row[5]  ?? ''));
                            $rActionTaken   = trim((string) ($row[6]  ?? ''));
                            $rCellNo        = trim((string) ($row[7]  ?? ''));
                            $rPostedDate    = trim((string) ($row[8]  ?? ''));
                            $rReplyDate     = trim((string) ($row[9]  ?? ''));
                            $rRepliedBy     = trim((string) ($row[10] ?? ''));

                            $rRole = preg_replace('/^\xEF\xBB\xBF/', '', $rRole) ?? $rRole;
                            $rRole = strtoupper($rRole);

                            if (!in_array($rRole, ['STUDENT', 'PARENT', 'TEACHER', 'NON_TEACHING', 'MANAGEMENT'], true)) {
                                $skippedRows[] = "Row {$rowNumber}: Invalid role '{$rRole}'.";
                                continue;
                            }

                            if ($rName === '' || $rComplaint === '' || $rGrievanceType === '' || $rActionTaken === '' || $rCellNo === '' || $rRepliedBy === '') {
                                $skippedRows[] = "Row {$rowNumber}: Missing required fields.";
                                continue;
                            }

                            $typeKey = strtolower($rGrievanceType);
                            if (!isset($typeLookup[$typeKey])) {
                                $skippedRows[] = "Row {$rowNumber}: Unknown grievance type '{$rGrievanceType}'.";
                                continue;
                            }
                            $typeId = $typeLookup[$typeKey];

                            $complainantUserId = resolveComplainantUser($conn, $rRole, $rName, $rCellNo, $rClassName);
                            $grievanceNumber = generateGrievanceNumber($conn);

                            $createdAt = ($rPostedDate !== '' && strtotime($rPostedDate) !== false)
                                ? date('Y-m-d H:i:s', strtotime($rPostedDate))
                                : date('Y-m-d H:i:s');
                            $updatedAt = ($rReplyDate !== '' && strtotime($rReplyDate) !== false)
                                ? date('Y-m-d H:i:s', strtotime($rReplyDate))
                                : $createdAt;

                            $status = 'Disposed';
                            $subject = mb_substr($rComplaint, 0, 250, 'UTF-8');
                            $description = $rComplaint . ($rAcademicYear !== '' ? "\n\nAcademic Year: " . $rAcademicYear : '');

                            $stmtI = $conn->prepare("INSERT INTO grievances
                                                        (grievance_number, grievance_type_id, complainant_user_id, subject,
                                                         description, status, reply_details, created_at, updated_at)
                                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            if (!$stmtI) throw new Exception("Row {$rowNumber}: Failed to prepare insert.");
                            $stmtI->bind_param('siissssss',
                                $grievanceNumber, $typeId, $complainantUserId,
                                $subject, $description, $status, $rActionTaken,
                                $createdAt, $updatedAt
                            );
                            $stmtI->execute();
                            $stmtI->close();

                            $insertedCount++;
                        }

                        if ($insertedCount === 0) {
                            throw new Exception('No valid rows were found in the uploaded file.');
                        }

                        $conn->commit();

                        $msg = 'CSV Imported: ' . $insertedCount . ' row(s) added successfully!';
                        if (!empty($skippedRows)) {
                            $msg .= ' Skipped ' . count($skippedRows) . ' row(s).';
                            $_SESSION['import_skipped_rows'] = $skippedRows;
                        }
                        $flashSuccess = $msg;
                    } catch (Throwable $ex) {
                        if ($conn instanceof mysqli) $conn->rollback();
                        error_log('[Bulk Upload] ' . $ex->getMessage());
                        $flashError = $ex->getMessage() ?: 'A system error occurred during the import.';
                    }

                    fclose($handle);
                }
            }
        }

        if ($flashSuccess !== '' || $flashError !== '') {
            $_SESSION['flash_success'] = $flashSuccess;
            $_SESSION['flash_error']   = $flashError;
            header('Location: summary_details.php');
            exit;
        }
    }
}

$importSkippedRows = [];
if (!empty($_SESSION['import_skipped_rows']) && is_array($_SESSION['import_skipped_rows'])) {
    $importSkippedRows = $_SESSION['import_skipped_rows'];
    unset($_SESSION['import_skipped_rows']);
}

// QUERY PARAMS
$search  = trim((string) ($_GET['q']       ?? ''));
$entries = (int) ($_GET['entries']          ?? 10);
$page    = (int) ($_GET['page']             ?? 1);

if (!in_array($entries, [10, 25, 50, 100], true)) $entries = 10;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $entries;

// FETCH GRIEVANCES
$rows       = [];
$totalRows  = 0;
$totalPages = 1;

if ($conn instanceof mysqli) {
    try {
        $whereSql = " WHERE 1=1";
        $params   = [];
        $types    = '';

        if ($search !== '') {
            $whereSql .= " AND (
                g.subject LIKE ?
                OR g.status LIKE ?
                OR g.grievance_number LIKE ?
                OR COALESCE(s.name, p.name, cm.name, ap.name, u.username) LIKE ?
            )";
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $types   .= 'ssss';
        }

        $countSql = "SELECT COUNT(*) AS c
                     FROM grievances g
                     LEFT JOIN users u            ON g.complainant_user_id = u.id
                     LEFT JOIN students s         ON u.id = s.user_id
                     LEFT JOIN parents p          ON u.id = p.user_id
                     LEFT JOIN cell_members cm    ON u.id = cm.user_id
                     LEFT JOIN admin_profiles ap  ON u.id = ap.user_id
                     $whereSql";

        $stmt = $conn->prepare($countSql);
        if ($stmt) {
            if (!empty($params)) $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res       = $stmt->get_result();
            $totalRows = (int) ($res ? ($res->fetch_assoc()['c'] ?? 0) : 0);
            $stmt->close();
        }

        $totalPages = max(1, (int) ceil($totalRows / $entries));
        if ($page > $totalPages) {
            $page   = $totalPages;
            $offset = ($page - 1) * $entries;
        }

        $dataSql = "SELECT  g.id,
                            g.grievance_number,
                            g.subject,
                            g.description,
                            g.status,
                            g.reply_details,
                            g.feedback_details,
                            g.attachment_path,
                            g.reply_attachment_path,
                            g.created_at,
                            g.updated_at,
                            gt.type_name,
                            u.role AS complainant_role,
                            COALESCE(s.name, p.name, cm.name, ap.name, u.username) AS complainant_name,
                            COALESCE(c.class_name, d.department_name, '—') AS class_or_department
                    FROM grievances g
                    LEFT JOIN grievance_types gt ON g.grievance_type_id = gt.id
                    LEFT JOIN users u            ON g.complainant_user_id = u.id
                    LEFT JOIN students s         ON u.id = s.user_id
                    LEFT JOIN classes c          ON s.class_id = c.id
                    LEFT JOIN parents p          ON u.id = p.user_id
                    LEFT JOIN cell_members cm    ON u.id = cm.user_id
                    LEFT JOIN departments d      ON cm.department_id = d.id
                    LEFT JOIN admin_profiles ap  ON u.id = ap.user_id
                    $whereSql
                    ORDER BY g.id DESC
                    LIMIT ? OFFSET ?";

        $dataParams = $params;
        $dataTypes  = $types . 'ii';
        $dataParams[] = $entries;
        $dataParams[] = $offset;

        $stmt = $conn->prepare($dataSql);
        if ($stmt) {
            $stmt->bind_param($dataTypes, ...$dataParams);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
            $stmt->close();
        }
    } catch (Throwable $ex) {
        error_log('[Fetch Summary Details] ' . $ex->getMessage());
    }
}

// FETCH GRIEVANCE ACTIONS FOR ALL VISIBLE ROWS
$actionsByGrievance = [];

if ($conn instanceof mysqli && !empty($rows)) {
    try {
        $grievanceIds = array_map(static fn($r) => (int) $r['id'], $rows);
        $grievanceIds = array_filter($grievanceIds, static fn($v) => $v > 0);

        if (!empty($grievanceIds)) {
            $placeholders = implode(',', array_fill(0, count($grievanceIds), '?'));
            $types        = str_repeat('i', count($grievanceIds));

            $sql = "SELECT ga.id,
                           ga.grievance_id,
                           ga.action_date,
                           ga.summary,
                           ga.action_taken,
                           ga.created_at,
                           ga.attendee_id,
                           COALESCE(cmm.name, u2.username, '—') AS attendee_name,
                           COALESCE(cmm.member_type, '')        AS attendee_type
                    FROM grievance_actions ga
                    LEFT JOIN cell_members cmm ON ga.attendee_id = cmm.id
                    LEFT JOIN users u2         ON cmm.user_id = u2.id
                    WHERE ga.grievance_id IN ($placeholders)
                    ORDER BY ga.grievance_id ASC, ga.action_date ASC, ga.id ASC";

            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param($types, ...$grievanceIds);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $gid = (int) $row['grievance_id'];
                    if (!isset($actionsByGrievance[$gid])) $actionsByGrievance[$gid] = [];
                    $gAdate = (string) ($row['action_date'] ?? '');
                    if ($gAdate !== '' && strtotime($gAdate) !== false) {
                        $gAdate = date('Y-m-d', strtotime($gAdate));
                    }
                    $actionsByGrievance[$gid][] = [
                        'action_date'   => $gAdate,
                        'summary'       => (string) ($row['summary']       ?? ''),
                        'action_taken'  => (string) ($row['action_taken']  ?? ''),
                        'attendee_name' => (string) ($row['attendee_name'] ?? '—'),
                        'attendee_type' => (string) ($row['attendee_type'] ?? ''),
                    ];
                }
                $stmt->close();
            }
        }
    } catch (Throwable $ex) {
        error_log('[Fetch Grievance Actions] ' . $ex->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Summary Details — Admin | Rajagiri College Grievance Portal</title>
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
            fadeInUp:     { '0%':{opacity:'0',transform:'translateY(12px)'}, '100%':{opacity:'1',transform:'translateY(0)'} },
            dropdownFade: { '0%':{opacity:'0',transform:'translateY(-8px) scale(0.98)'}, '100%':{opacity:'1',transform:'translateY(0) scale(1)'} },
            modalFadeIn:  { '0%':{opacity:'0',transform:'scale(0.96)'}, '100%':{opacity:'1',transform:'scale(1)'} },
            confirmShake: { '0%, 100%':{transform:'translateX(0)'},'20%':{transform:'translateX(-6px)'},'40%':{transform:'translateX(6px)'},'60%':{transform:'translateX(-4px)'},'80%':{transform:'translateX(4px)'} },
            flashIn:      { '0%':{opacity:'0',transform:'translateY(-10px)'}, '100%':{opacity:'1',transform:'translateY(0)'} },
            flashOut:     { '0%':{opacity:'1',transform:'translateY(0)',maxHeight:'200px'}, '100%':{opacity:'0',transform:'translateY(-10px)',maxHeight:'0px'} },
            shimmer:      { '0%':{backgroundPosition:'-200% 0'},'100%':{backgroundPosition:'200% 0'} },
            pulseRing:    { '0%':{boxShadow:'0 0 0 0 rgba(0,110,116,0.45)'},'70%':{boxShadow:'0 0 0 12px rgba(0,110,116,0)'},'100%':{boxShadow:'0 0 0 0 rgba(0,110,116,0)'} }
          },
          animation: {
            'fade-in-up':    'fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'dropdown':      'dropdownFade 0.2s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'modal-in':      'modalFadeIn 0.25s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'confirm-shake': 'confirmShake 0.5s cubic-bezier(0.16, 1, 0.3, 1)',
            'flash-in':      'flashIn 0.35s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'flash-out':     'flashOut 0.45s cubic-bezier(0.4, 0, 1, 1) forwards',
            'shimmer':       'shimmer 3s linear infinite',
            'pulse-ring':    'pulseRing 2s ease-out infinite'
          }
        }
      }
    };
  </script>

  <style>
    /* ============================================================
       FONT FACES
       ============================================================ */
    @font-face { font-family:'Coolvetica'; src:url('../assets/fonts/coolvetica-rg.woff2') format('woff2'), url('../assets/fonts/coolvetica-rg.woff') format('woff'); font-weight:400; font-display:swap; }
    @font-face { font-family:'Coolvetica'; src:url('../assets/fonts/coolvetica-bold.woff2') format('woff2'), url('../assets/fonts/coolvetica-bold.woff') format('woff'); font-weight:700; font-display:swap; }
    html { scroll-behavior:smooth; }
    body { font-family:'Coolvetica','Poppins',sans-serif; }

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
    .roofline { height:14px;
      background-image: linear-gradient(45deg, transparent 33.33%, #006E74 33.33%, #006E74 66.66%, transparent 66.66%),
                        linear-gradient(-45deg, transparent 33.33%, #006E74 33.33%, #006E74 66.66%, transparent 66.66%);
      background-size:20px 14px; background-repeat:repeat-x;
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
    .logo-divider { width:1px; background-color:#CFE6E7; }
    #sidebarNav::-webkit-scrollbar { width:6px; }
    #sidebarNav::-webkit-scrollbar-track { background:transparent; }
    #sidebarNav::-webkit-scrollbar-thumb { background:rgba(255,255,255,0.2); border-radius:3px; }
    #sidebarNav::-webkit-scrollbar-thumb:hover { background:rgba(255,255,255,0.35); }

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

    /* ============================================================
       PRINT STYLES (unchanged behavior, preserved exactly)
       ============================================================ */
    #print-area { display: none; }

    @media print {
      body > .screen-only,
      body > .screen-only * { display: none !important; }
      .no-print { display: none !important; }

      html, body {
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
        color: #000 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
      }

      #print-area {
        display: block !important;
        position: static !important;
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        font-family: Arial, Helvetica, sans-serif;
        font-size: 10px;
        color: #000;
      }

      @page {
        size: A4 landscape;
        margin: 10mm;
      }
    }
  </style>
</head>

<body class="min-h-screen text-teal-900 antialiased flex flex-col">

  <div class="screen-only flex min-h-screen flex-1">

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

        <a href="grievance_report.php" class="sidebar-link group relative w-full h-12 rounded-xl hover:bg-white/10 flex items-center text-white transition-all px-3 flex-shrink-0">
          <i data-lucide="bar-chart-3" class="w-6 h-6 flex-shrink-0"></i>
          <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap opacity-0 w-0 overflow-hidden transition-all duration-200">Grievance Reports</span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-teal-900 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Grievance Reports</span>
        </a>

        <a href="members.php" class="sidebar-link group relative w-full h-12 rounded-xl hover:bg-white/10 flex items-center text-white transition-all px-3 flex-shrink-0">
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
         MAIN
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

        <!-- Page heading + Action Buttons -->
        <div class="max-w-6xl mx-auto mb-8 animate-fade-in-up">
          <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
            <div>
              <div class="flex items-center gap-2 mb-2">
                <span class="inline-flex items-center justify-center w-2 h-2 rounded-full bg-teal-600 animate-pulse-ring"></span>
                <p class="text-xs font-semibold text-teal-600 uppercase tracking-wider">Admin Console</p>
              </div>
              <h1 class="text-2xl md:text-3xl lg:text-4xl font-bold text-teal-900 mb-3">
                <span class="heading-underline">Summary Details</span>
              </h1>
              <nav class="flex flex-wrap items-center gap-2 text-sm text-teal-900/60">
                <a href="dashboard.php" class="inline-flex items-center gap-1 hover:text-teal-600 transition-colors">
                  <i data-lucide="layout-dashboard" class="w-4 h-4"></i> Dashboard
                </a>
                <span class="text-teal-900/30">/</span>
                <span class="text-teal-600 font-semibold">Summary Details</span>
              </nav>
            </div>

            <div class="flex items-center gap-3">
              <button type="button" onclick="window.print();" title="Print Summary"
                      class="inline-flex items-center justify-center w-11 h-11 rounded-xl bg-teal-600 hover:bg-teal-700
                             text-white shadow-sm hover:shadow-md
                             transition-all duration-200 hover:-translate-y-0.5 active:scale-95">
                <i data-lucide="printer" class="w-5 h-5"></i>
              </button>

              <button type="button" onclick="openBulkUploadModal()" title="Bulk Upload Summary Details"
                      class="inline-flex items-center justify-center w-11 h-11 rounded-xl bg-teal-600 hover:bg-teal-700
                             text-white shadow-sm hover:shadow-md
                             transition-all duration-200 hover:-translate-y-0.5 active:scale-95">
                <i data-lucide="upload" class="w-5 h-5"></i>
              </button>

              <button type="button" onclick="openCreateModal()" title="Create Summary Details"
                      class="inline-flex items-center justify-center w-11 h-11 rounded-xl bg-teal-600 hover:bg-teal-700
                             text-white shadow-sm hover:shadow-md
                             transition-all duration-200 hover:-translate-y-0.5 active:scale-95">
                <i data-lucide="plus" class="w-5 h-5"></i>
              </button>
            </div>
          </div>
        </div>

        <!-- Flash -->
        <?php if ($flashSuccess !== ''): ?>
          <div id="flashSuccessBox" class="max-w-6xl mx-auto mb-6 rounded-xl border-2 border-emerald-200 bg-emerald-50 px-4 py-3 flex items-start space-x-2 animate-flash-in overflow-hidden">
            <i data-lucide="check-circle" class="w-5 h-5 text-emerald-600 flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-emerald-800 font-medium"><?= e($flashSuccess) ?></p>
          </div>
        <?php endif; ?>

        <?php if ($flashError !== ''): ?>
          <div id="flashErrorBox" class="max-w-6xl mx-auto mb-6 rounded-xl border-2 border-red-200 bg-red-50 px-4 py-3 flex items-start space-x-2 animate-flash-in overflow-hidden">
            <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-red-700 font-medium"><?= e($flashError) ?></p>
          </div>
        <?php endif; ?>

        <?php if (!empty($importSkippedRows)): ?>
          <div class="max-w-6xl mx-auto mb-6 rounded-xl border-2 border-amber-200 bg-amber-50 px-4 py-3 animate-fade-in-up">
            <div class="flex items-start space-x-2 mb-2">
              <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-600 flex-shrink-0 mt-0.5"></i>
              <p class="text-sm font-semibold text-amber-800">Some rows were skipped during import (<?= count($importSkippedRows) ?>):</p>
            </div>
            <ul class="text-xs text-amber-700 space-y-1 ml-7 list-disc">
              <?php foreach (array_slice($importSkippedRows, 0, 15) as $sk): ?>
                <li><?= e((string) $sk) ?></li>
              <?php endforeach; ?>
              <?php if (count($importSkippedRows) > 15): ?>
                <li class="italic">... and <?= count($importSkippedRows) - 15 ?> more</li>
              <?php endif; ?>
            </ul>
          </div>
        <?php endif; ?>

        <!-- TABLE CONTROLS -->
        <div class="max-w-6xl mx-auto mb-5 animate-fade-in-up" style="animation-delay: 60ms;">
          <form method="GET" action="summary_details.php" id="filterForm"
                class="bg-teal-50/60 rounded-2xl border-2 border-teal-100 px-5 py-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
              <div class="flex items-center gap-3">
                <span class="text-sm text-teal-900">Show</span>
                <select name="entries" id="entriesPerPage"
                        class="px-3 py-1.5 border-2 border-teal-100 rounded-lg text-sm font-medium text-teal-900
                               focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10
                               hover:border-teal-400 transition-colors bg-white">
                  <option value="10"  <?= $entries === 10  ? 'selected' : '' ?>>10</option>
                  <option value="25"  <?= $entries === 25  ? 'selected' : '' ?>>25</option>
                  <option value="50"  <?= $entries === 50  ? 'selected' : '' ?>>50</option>
                  <option value="100" <?= $entries === 100 ? 'selected' : '' ?>>100</option>
                </select>
                <span class="text-sm text-teal-900">entries</span>
              </div>

              <div class="relative w-full sm:w-80">
                <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-teal-900/40"></i>
                <input type="text" name="q" id="searchInput" value="<?= e($search) ?>" placeholder="Search.." autocomplete="off"
                       class="w-full pl-10 pr-4 py-2 border-2 border-teal-100 rounded-lg text-sm
                              focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10
                              hover:border-teal-400 transition-all bg-white" />
              </div>
            </div>
          </form>
        </div>

        <!-- DATA TABLE -->
        <div class="max-w-6xl mx-auto animate-fade-in-up" style="animation-delay: 100ms;">
          <div class="bg-white rounded-2xl shadow-sm border-2 border-teal-100 overflow-hidden">
            <div class="overflow-x-auto">
              <table class="w-full" id="summaryTable">
                <thead>
                  <tr class="bg-teal-600 text-white">
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Sl.No.</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Name</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Class/Department</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Complaint</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Posted Date</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Role</th>
                    <th class="px-6 py-4 text-center text-xs font-bold uppercase tracking-wider">Actions</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Status</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-teal-50" id="summaryTableBody">

                  <?php if (empty($rows)): ?>
                    <tr>
                      <td colspan="8" class="px-6 py-12 text-center text-teal-900/60 bg-teal-50/40">
                        <span class="text-sm font-medium">No data available in table</span>
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($rows as $index => $r): ?>
                      <?php
                        $gId        = (int) $r['id'];
                        $gNumber    = (string) ($r['grievance_number']    ?? '');
                        $gName      = (string) ($r['complainant_name']    ?? 'N/A');
                        $gClass     = (string) ($r['class_or_department'] ?? '—');
                        $gSubject   = (string) ($r['subject']             ?? '—');
                        $gDesc      = (string) ($r['description']         ?? '');
                        $gReply     = (string) ($r['reply_details']       ?? '');
                        $gFeedback  = (string) ($r['feedback_details']    ?? '');
                        $gType      = (string) ($r['type_name']           ?? '—');
                        $gDate      = (string) ($r['created_at']          ?? '');
                        $gUpd       = (string) ($r['updated_at']          ?? '');
                        $gRole      = (string) ($r['complainant_role']    ?? '');
                        $gStatus    = (string) ($r['status']              ?? 'Pending');

                        $gAttach    = resolveAttachmentUrl($r['attachment_path'] ?? null);
                        $gReplyAtt  = resolveAttachmentUrl($r['reply_attachment_path'] ?? null);

                        $formattedDate    = '—';
                        $formattedUpdated = '—';
                        if ($gDate !== '' && strtotime($gDate) !== false) $formattedDate    = date('Y-m-d', strtotime($gDate));
                        if ($gUpd  !== '' && strtotime($gUpd)  !== false) $formattedUpdated = date('Y-m-d', strtotime($gUpd));

                        $statusCls   = statusBadgeClass($gStatus);
                        $globalIndex = $offset + $index + 1;

                        $actionsList = $actionsByGrievance[$gId] ?? [];
                      ?>
                      <tr class="hover:bg-teal-50/40 transition-colors group">
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-teal-900"><?= $globalIndex ?></td>
                        <td class="px-6 py-4 text-sm font-semibold text-teal-900"><?= e($gName) ?></td>
                        <td class="px-6 py-4 text-sm text-teal-900/80 max-w-[200px]"><?= e($gClass) ?></td>
                        <td class="px-6 py-4 text-sm text-teal-900/80 max-w-[260px]"><?= e($gSubject) ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-teal-900/70"><?= e($formattedDate) ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-teal-900/80"><?= e(roleLabel($gRole)) ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                          <div class="inline-flex items-center gap-1.5">

                            <button type="button" title="View grievance"
                                    onclick='openViewModal(<?= json_encode([
                                        "grievance_number"      => $gNumber,
                                        "name"                  => $gName,
                                        "role"                  => roleLabel($gRole),
                                        "class_department"      => $gClass,
                                        "grievance_type"        => $gType,
                                        "subject"               => $gSubject,
                                        "description"           => $gDesc,
                                        "status"                => $gStatus,
                                        "posted_date"           => $formattedDate,
                                        "reply_date"            => $formattedUpdated,
                                        "reply_details"         => $gReply,
                                        "feedback_details"      => $gFeedback,
                                        "attachment_url"        => $gAttach,
                                        "reply_attachment_url"  => $gReplyAtt,
                                        "actions"               => $actionsList,
                                    ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                    class="inline-flex w-9 h-9 rounded-full bg-teal-50 hover:bg-teal-600
                                           items-center justify-center text-teal-600 hover:text-white
                                           transition-all duration-200 hover:scale-110">
                              <i data-lucide="eye" class="w-4 h-4"></i>
                            </button>

                            <button type="button" title="Delete grievance"
                                    onclick='confirmDelete(<?= $gId ?>, <?= json_encode($gNumber !== '' ? $gNumber : $gSubject, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                    class="inline-flex w-9 h-9 rounded-full bg-teal-50 hover:bg-red-500
                                           items-center justify-center text-teal-600 hover:text-white
                                           transition-all duration-200 hover:scale-110">
                              <i data-lucide="trash-2" class="w-4 h-4"></i>
                            </button>

                          </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                          <span class="inline-flex items-center px-3 py-1 text-xs font-semibold rounded-full border <?= $statusCls ?>">
                            <?= e($gStatus) ?>
                          </span>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>

                </tbody>
              </table>
            </div>

            <!-- Pagination -->
            <div class="px-6 py-4 bg-teal-50/40 border-t border-teal-100 flex flex-col sm:flex-row items-center justify-between gap-4">
              <p class="text-sm text-teal-900/70">
                Showing
                <span class="font-semibold text-teal-900"><?= $totalRows > 0 ? ($offset + 1) : 0 ?></span>
                to
                <span class="font-semibold text-teal-900"><?= $totalRows > 0 ? min($offset + count($rows), $totalRows) : 0 ?></span>
                of
                <span class="font-semibold text-teal-900"><?= $totalRows ?></span>
                entries
              </p>

              <div class="flex items-center space-x-2">
                <?php
                  $qsBase = 'summary_details.php?entries=' . $entries;
                  if ($search !== '') $qsBase .= '&q=' . urlencode($search);
                ?>

                <?php if ($page > 1): ?>
                  <a href="<?= e($qsBase . '&page=' . ($page - 1)) ?>"
                     class="px-4 py-2 rounded-lg text-sm font-semibold text-teal-900 bg-white hover:bg-teal-50 border-2 border-teal-200 hover:border-teal-600 transition-colors">Previous</a>
                <?php else: ?>
                  <button type="button" class="px-4 py-2 rounded-lg text-sm font-semibold text-teal-900/30 bg-teal-50/60 border-2 border-teal-100 cursor-not-allowed" disabled>Previous</button>
                <?php endif; ?>

                <span class="inline-flex items-center justify-center w-9 h-9 rounded-lg bg-teal-600 text-white text-sm font-bold shadow-sm">
                  <?= $page ?>
                </span>

                <?php if ($page < $totalPages): ?>
                  <a href="<?= e($qsBase . '&page=' . ($page + 1)) ?>"
                     class="px-4 py-2 rounded-lg text-sm font-semibold text-teal-900 bg-white hover:bg-teal-50 border-2 border-teal-200 hover:border-teal-600 transition-colors">Next</a>
                <?php else: ?>
                  <button type="button" class="px-4 py-2 rounded-lg text-sm font-semibold text-teal-900/30 bg-teal-50/60 border-2 border-teal-100 cursor-not-allowed" disabled>Next</button>
                <?php endif; ?>
              </div>
            </div>
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

  <!-- ============================================================ -->
  <!-- PRINT-ONLY AREA (unchanged)                                  -->
  <!-- ============================================================ -->
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
                <div style="font-size:13px;font-weight:bold;color:#006837;text-transform:uppercase;letter-spacing:0.5px;">
                  Rajagiri College of Social Sciences
                </div>
                <div style="font-size:10px;color:#555;padding-top:2px;">
                  Grievance Redressal Portal
                </div>
              </td>
            </tr>
          </table>
        </td>
        <td style="vertical-align:middle;text-align:right;width:40%;font-size:11px;font-weight:bold;color:#333;">
          Date: <?= date('d-m-Y') ?>
        </td>
      </tr>
    </table>

    <div style="font-size:18px;font-weight:bold;color:#111;margin:0 0 8px 0;">Summary Details</div>

    <table style="width:100%;border-collapse:collapse;font-size:10.5px;color:#333;margin-bottom:10px;">
      <tr>
        <td style="padding-bottom:6px;">
          <strong style="color:#111;">Total records:</strong> <?= $totalRows ?>
          &nbsp;&nbsp;|&nbsp;&nbsp;
          <strong style="color:#111;">Generated on:</strong> <?= date('d-m-Y H:i') ?>
        </td>
        <td style="padding-bottom:6px;text-align:right;">
          <?php if ($search !== ''): ?>
            <strong style="color:#111;">Search:</strong> <?= e($search) ?>
          <?php endif; ?>
        </td>
      </tr>
    </table>

    <table style="width:100%;border-collapse:collapse;table-layout:fixed;font-size:9px;color:#000;">
      <thead>
        <tr>
          <th style="border:1px solid #333;background:#eaeaea;padding:4px 5px;text-align:left;font-size:9px;font-weight:bold;width:4%;">Sl.No.</th>
          <th style="border:1px solid #333;background:#eaeaea;padding:4px 5px;text-align:left;font-size:9px;font-weight:bold;width:14%;">Name</th>
          <th style="border:1px solid #333;background:#eaeaea;padding:4px 5px;text-align:left;font-size:9px;font-weight:bold;width:14%;">Class/Department</th>
          <th style="border:1px solid #333;background:#eaeaea;padding:4px 5px;text-align:left;font-size:9px;font-weight:bold;width:26%;">Complaint</th>
          <th style="border:1px solid #333;background:#eaeaea;padding:4px 5px;text-align:left;font-size:9px;font-weight:bold;width:9%;">Posted Date</th>
          <th style="border:1px solid #333;background:#eaeaea;padding:4px 5px;text-align:left;font-size:9px;font-weight:bold;width:10%;">Role</th>
          <th style="border:1px solid #333;background:#eaeaea;padding:4px 5px;text-align:left;font-size:9px;font-weight:bold;width:10%;">Status</th>
          <th style="border:1px solid #333;background:#eaeaea;padding:4px 5px;text-align:left;font-size:9px;font-weight:bold;width:13%;">Action Taken</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr>
            <td colspan="8" style="border:1px solid #333;padding:10px;text-align:center;">
              No records available.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($rows as $i => $r): ?>
            <?php
              $rDate = !empty($r['created_at']) ? date('Y-m-d', strtotime((string) $r['created_at'])) : '—';
              $printDesc  = trim(preg_replace('/\s+/', ' ', (string) ($r['description'] ?? '')));
              $printReply = trim(preg_replace('/\s+/', ' ', (string) ($r['reply_details'] ?? '')));
            ?>
            <tr>
              <td style="border:1px solid #333;padding:4px 5px;vertical-align:top;text-align:center;"><?= $i + 1 ?></td>
              <td style="border:1px solid #333;padding:4px 5px;vertical-align:top;word-wrap:break-word;"><?= e($r['complainant_name'] ?? 'N/A') ?></td>
              <td style="border:1px solid #333;padding:4px 5px;vertical-align:top;word-wrap:break-word;"><?= e($r['class_or_department'] ?? '—') ?></td>
              <td style="border:1px solid #333;padding:4px 5px;vertical-align:top;word-wrap:break-word;"><?= e($printDesc !== '' ? $printDesc : '—') ?></td>
              <td style="border:1px solid #333;padding:4px 5px;vertical-align:top;white-space:nowrap;"><?= e($rDate) ?></td>
              <td style="border:1px solid #333;padding:4px 5px;vertical-align:top;text-transform:uppercase;"><?= e(roleLabel((string) ($r['complainant_role'] ?? ''))) ?></td>
              <td style="border:1px solid #333;padding:4px 5px;vertical-align:top;text-transform:uppercase;font-weight:bold;"><?= e($r['status'] ?? '—') ?></td>
              <td style="border:1px solid #333;padding:4px 5px;vertical-align:top;word-wrap:break-word;"><?= e($printReply !== '' ? $printReply : '—') ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>

    <div style="margin-top:10px;font-size:10px;color:#333;">
      <strong>Total records:</strong> <?= $totalRows ?>
      &nbsp;&nbsp;|&nbsp;&nbsp;
      <strong>Generated on:</strong> <?= date('d-m-Y H:i') ?>
    </div>
  </div>

  <!-- ============================================================ -->
  <!-- VIEW DETAILS MODAL                                           -->
  <!-- ============================================================ -->
  <div id="viewModal" class="hidden fixed inset-0 z-[65] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeViewModal()"></div>

    <div class="relative w-full max-w-3xl bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-teal-600"></div>

      <div class="flex items-center justify-between px-6 py-4 border-b border-teal-100">
        <div class="flex items-center gap-3">
          <div class="w-9 h-9 rounded-lg bg-teal-50 flex items-center justify-center">
            <i data-lucide="eye" class="w-5 h-5 text-teal-600"></i>
          </div>
          <h3 class="text-lg font-bold text-teal-900">Grievance Details</h3>
        </div>
        <button type="button" onclick="closeViewModal()"
                class="w-8 h-8 rounded-lg hover:bg-teal-50 flex items-center justify-center text-teal-900/60 transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <div class="p-6 space-y-5 max-h-[75vh] overflow-y-auto">

        <div class="flex items-center justify-between flex-wrap gap-4 pb-4 border-b border-teal-100">
          <div class="flex items-center gap-4">
            <div class="w-14 h-14 rounded-full bg-teal-600 flex items-center justify-center text-white shadow-sm">
              <i data-lucide="user" class="w-7 h-7"></i>
            </div>
            <div class="min-w-0">
              <p id="viewName" class="text-base font-bold text-teal-900 break-words">—</p>
              <p class="text-xs text-teal-900/60">
                <span id="viewRole">—</span> · <span id="viewClassDept">—</span>
              </p>
            </div>
          </div>
          <span id="viewStatus" class="inline-flex items-center px-3 py-1 text-xs font-semibold rounded-full border">—</span>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <p class="text-[10px] font-bold uppercase tracking-wider text-teal-900/40">Grievance Number</p>
            <p id="viewNumber" class="text-sm font-semibold text-teal-700 break-all">—</p>
          </div>
          <div>
            <p class="text-[10px] font-bold uppercase tracking-wider text-teal-900/40">Grievance Type</p>
            <p id="viewType" class="text-sm text-teal-900/80">—</p>
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <p class="text-[10px] font-bold uppercase tracking-wider text-teal-900/40">Posted Date</p>
            <p id="viewPostedDate" class="text-sm text-teal-900/80">—</p>
          </div>
          <div>
            <p class="text-[10px] font-bold uppercase tracking-wider text-teal-900/40">Reply Date</p>
            <p id="viewReplyDate" class="text-sm text-teal-900/80">—</p>
          </div>
        </div>

        <div>
          <p class="text-[10px] font-bold uppercase tracking-wider text-teal-900/40">Subject</p>
          <p id="viewSubject" class="text-sm text-teal-900 font-medium break-words">—</p>
        </div>

        <div>
          <p class="text-[10px] font-bold uppercase tracking-wider text-teal-900/40">Description</p>
          <p id="viewDescription" class="text-sm text-teal-900/80 leading-relaxed whitespace-pre-wrap break-words">—</p>
        </div>

        <div id="viewAttachmentWrapper">
          <p class="text-[10px] font-bold uppercase tracking-wider text-teal-900/40 mb-2">Complaint Attachment</p>
          <div id="viewAttachmentContent" class="text-sm text-teal-900/80">—</div>
        </div>

        <div>
          <p class="text-[10px] font-bold uppercase tracking-wider text-teal-900/40">Reply / Action Taken</p>
          <p id="viewReplyDetails" class="text-sm text-teal-900/80 leading-relaxed whitespace-pre-wrap break-words">—</p>
        </div>

        <div id="viewReplyAttachmentWrapper">
          <p class="text-[10px] font-bold uppercase tracking-wider text-teal-900/40 mb-2">Reply Attachment</p>
          <div id="viewReplyAttachmentContent" class="text-sm text-teal-900/80">—</div>
        </div>

        <div>
          <p class="text-[10px] font-bold uppercase tracking-wider text-teal-900/40">Feedback Details</p>
          <p id="viewFeedbackDetails" class="text-sm text-teal-900/80 leading-relaxed whitespace-pre-wrap break-words">—</p>
        </div>

        <div id="viewActionsWrapper">
          <div class="flex items-center justify-between mb-2">
            <p class="text-[10px] font-bold uppercase tracking-wider text-teal-900/40">Grievance Actions</p>
            <span id="viewActionsCount" class="text-[10px] font-bold text-teal-700 bg-teal-50 border border-teal-100 px-2 py-0.5 rounded-full">0</span>
          </div>
          <div id="viewActionsList" class="space-y-3">—</div>
        </div>

      </div>

      <div class="px-6 py-4 bg-teal-50/40 border-t border-teal-100 flex justify-end">
        <button type="button" onclick="closeViewModal()"
                class="px-5 py-2.5 rounded-lg font-semibold text-teal-900
                       bg-white border-2 border-teal-200 hover:border-teal-600 hover:bg-teal-50
                       transition-all duration-200">
          Close
        </button>
      </div>
    </div>
  </div>

  <!-- ============================================================ -->
  <!-- DELETE CONFIRMATION MODAL                                    -->
  <!-- ============================================================ -->
  <div id="deleteConfirmModal" class="hidden fixed inset-0 z-[67] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeDeleteModal()"></div>

    <div id="deleteConfirmPanel" class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-teal-600"></div>

      <div class="px-6 pt-6 pb-2 flex flex-col items-center text-center">
        <div class="w-16 h-16 rounded-full flex items-center justify-center mb-4 bg-red-50 ring-4 ring-red-100/60">
          <i data-lucide="trash-2" class="w-8 h-8 text-red-500"></i>
        </div>

        <h3 class="text-xl font-bold text-teal-900 mb-2">Delete Grievance?</h3>

        <p class="text-sm text-teal-900/70 leading-relaxed">
          You are about to permanently delete
          <span id="deleteNameDisplay" class="font-bold text-teal-700 break-words">this grievance</span>.
        </p>

        <p class="text-xs text-red-500 font-medium mt-3 flex items-center gap-1.5">
          <i data-lucide="alert-triangle" class="w-3.5 h-3.5"></i> This action cannot be undone.
        </p>
      </div>

      <div class="px-6 py-5 mt-2 flex flex-col-reverse sm:flex-row gap-3">
        <button type="button" onclick="closeDeleteModal()"
                class="flex-1 px-5 py-3 rounded-lg font-semibold text-teal-900 bg-white border-2 border-teal-200 hover:border-teal-600 hover:bg-teal-50 transition-all duration-200">
          Cancel
        </button>

        <button type="button" id="confirmDeleteBtn"
                class="flex-1 px-5 py-3 rounded-lg font-bold text-white bg-red-500 hover:bg-red-600 shadow-sm hover:shadow-md transition-all duration-200 flex items-center justify-center gap-2">
          <i data-lucide="trash-2" class="w-4 h-4"></i> <span>Delete</span>
        </button>
      </div>
    </div>
  </div>

  <!-- ============================================================ -->
  <!-- CREATE MODAL                                                 -->
  <!-- ============================================================ -->
  <div id="createSummaryModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeCreateModal()"></div>

    <div class="relative w-full max-w-4xl bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-teal-600"></div>

      <div class="flex items-center justify-between px-6 py-4 border-b border-teal-100">
        <h3 class="text-lg font-bold text-teal-900">Create Summary Details</h3>
        <button type="button" onclick="closeCreateModal()"
                class="w-8 h-8 rounded-lg hover:bg-teal-50 flex items-center justify-center text-teal-900/60 transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form id="createSummaryForm" method="POST" action="summary_details.php" class="p-6 space-y-5 max-h-[80vh] overflow-y-auto">
        <input type="hidden" name="action" value="create_summary" />

        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
          <div class="space-y-2">
            <label for="create_role" class="block text-sm font-semibold text-teal-900">Role <span class="text-red-500">*</span></label>
            <select name="role" id="create_role" required
                    class="w-full px-4 py-3 border-2 border-teal-100 rounded-lg appearance-none bg-white text-teal-900 font-medium
                           focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10
                           hover:border-teal-400 transition-all">
              <option value="">Select</option>
              <option value="STUDENT">STUDENT</option>
              <option value="PARENT">PARENT</option>
              <option value="TEACHER">TEACHER</option>
              <option value="NON_TEACHING">NON TEACHING</option>
              <option value="MANAGEMENT">MANAGEMENT</option>
            </select>
          </div>

          <div class="space-y-2">
            <label for="create_name" class="block text-sm font-semibold text-teal-900">Name <span class="text-red-500">*</span></label>
            <input type="text" name="name" id="create_name" required placeholder="StudentName"
                   class="w-full px-4 py-3 border-2 border-teal-100 rounded-lg bg-white text-teal-900 font-medium placeholder-teal-900/40
                          focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10
                          hover:border-teal-400 transition-all" />
          </div>

          <div class="space-y-2">
            <label for="create_academic_year" class="block text-sm font-semibold text-teal-900">Academic Year <span class="text-red-500">*</span></label>
            <input type="text" name="academic_year" id="create_academic_year" required placeholder="AcademicYear"
                   class="w-full px-4 py-3 border-2 border-teal-100 rounded-lg bg-white text-teal-900 font-medium placeholder-teal-900/40
                          focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10
                          hover:border-teal-400 transition-all" />
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
          <div class="space-y-2">
            <label for="create_complaint" class="block text-sm font-semibold text-teal-900">Complaint <span class="text-red-500">*</span></label>
            <textarea name="complaint" id="create_complaint" required rows="3" placeholder="Complaint"
                      class="w-full px-4 py-3 border-2 border-teal-100 rounded-lg bg-white text-teal-900 font-medium placeholder-teal-900/40 resize-none
                             focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10
                             hover:border-teal-400 transition-all"></textarea>
          </div>

          <div class="space-y-2">
            <label for="create_class_name" class="block text-sm font-semibold text-teal-900">Class Name</label>
            <input type="text" name="class_name" id="create_class_name" placeholder="ClassName"
                   class="w-full px-4 py-3 border-2 border-teal-100 rounded-lg bg-white text-teal-900 font-medium placeholder-teal-900/40
                          focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10
                          hover:border-teal-400 transition-all" />
          </div>

          <div class="space-y-2">
            <label for="create_grievance_type" class="block text-sm font-semibold text-teal-900">Grievance Type <span class="text-red-500">*</span></label>
            <select name="grievance_type_id" id="create_grievance_type" required
                    class="w-full px-4 py-3 border-2 border-teal-100 rounded-lg appearance-none bg-white text-teal-900 font-medium
                           focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10
                           hover:border-teal-400 transition-all">
              <option value="">GrievanceType</option>
              <?php foreach ($grievanceTypeOptions as $opt): ?>
                <option value="<?= (int) $opt['id'] ?>"><?= e($opt['type_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
          <div class="space-y-2">
            <label for="create_action_taken" class="block text-sm font-semibold text-teal-900">Action Taken <span class="text-red-500">*</span></label>
            <input type="text" name="action_taken" id="create_action_taken" required placeholder="ActionTaken"
                   class="w-full px-4 py-3 border-2 border-teal-100 rounded-lg bg-white text-teal-900 font-medium placeholder-teal-900/40
                          focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10
                          hover:border-teal-400 transition-all" />
          </div>

          <div class="space-y-2">
            <label for="create_cell_no" class="block text-sm font-semibold text-teal-900">Cell No <span class="text-red-500">*</span></label>
            <input type="tel" name="cell_no" id="create_cell_no" required
                   inputmode="numeric" pattern="[0-9]{10}" minlength="10" maxlength="10"
                   title="Please enter exactly 10 digits" placeholder="CellNo"
                   class="w-full px-4 py-3 border-2 border-teal-100 rounded-lg bg-white text-teal-900 font-medium placeholder-teal-900/40
                          focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10
                          hover:border-teal-400 transition-all" />
          </div>

          <div class="space-y-2">
            <label for="create_posted_date" class="block text-sm font-semibold text-teal-900">Posted Date</label>
            <input type="date" name="posted_date" id="create_posted_date" value="<?= date('Y-m-d') ?>"
                   class="w-full px-4 py-3 border-2 border-teal-100 rounded-lg bg-white text-teal-900 font-medium
                          focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10
                          hover:border-teal-400 transition-all" />
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
          <div class="space-y-2">
            <label for="create_reply_date" class="block text-sm font-semibold text-teal-900">Reply Date</label>
            <input type="date" name="reply_date" id="create_reply_date" value="<?= date('Y-m-d') ?>"
                   class="w-full px-4 py-3 border-2 border-teal-100 rounded-lg bg-white text-teal-900 font-medium
                          focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10
                          hover:border-teal-400 transition-all" />
          </div>

          <div class="space-y-2 md:col-span-2">
            <label for="create_replied_by" class="block text-sm font-semibold text-teal-900">Replied By <span class="text-red-500">*</span></label>
            <input type="text" name="replied_by" id="create_replied_by" required placeholder="Replied"
                   class="w-full px-4 py-3 border-2 border-teal-100 rounded-lg bg-white text-teal-900 font-medium placeholder-teal-900/40
                          focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10
                          hover:border-teal-400 transition-all" />
          </div>
        </div>

        <div class="flex justify-center pt-3">
          <button type="submit"
                  class="btn-sheen px-12 py-3 rounded-lg bg-teal-600 hover:bg-teal-700 text-white font-bold shadow-sm hover:shadow-md transition-all duration-200 hover:-translate-y-0.5 active:scale-95">
            Submit
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- ============================================================ -->
  <!-- BULK UPLOAD MODAL                                            -->
  <!-- ============================================================ -->
  <div id="bulkUploadModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeBulkUploadModal()"></div>

    <div class="relative w-full max-w-lg bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-teal-600"></div>

      <div class="flex items-center justify-between px-6 py-4 border-b border-teal-100">
        <div class="flex items-center gap-3">
          <div class="w-9 h-9 rounded-lg bg-teal-50 flex items-center justify-center">
            <i data-lucide="upload" class="w-5 h-5 text-teal-600"></i>
          </div>
          <h3 class="text-lg font-bold text-teal-900">Bulk Upload Summary Details</h3>
        </div>
        <button type="button" onclick="closeBulkUploadModal()"
                class="w-8 h-8 rounded-lg hover:bg-teal-50 flex items-center justify-center text-teal-900/60 transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form id="bulkUploadForm" method="POST" action="summary_details.php" enctype="multipart/form-data" class="p-6 space-y-5">
        <input type="hidden" name="action" value="bulk_upload" />

        <div class="rounded-xl border-2 border-teal-100 bg-teal-50/60 px-4 py-3">
          <p class="text-xs text-teal-900/80 leading-relaxed">
            <span class="font-bold text-teal-700">Column order required:</span>
            <code class="text-[11px] bg-white px-1.5 py-0.5 rounded border border-teal-100">role, name, academic_year, class_name, complaint, grievance_type, action_taken, cell_no, posted_date, reply_date, replied_by</code>
          </p>
        </div>

        <div class="space-y-2">
          <label for="csv_file" class="block text-sm font-semibold text-teal-900">Select CSV File <span class="text-red-500">*</span></label>
          <input type="file" name="csv_file" id="csv_file" required accept=".csv, .txt"
                 class="w-full text-sm text-teal-900
                        file:mr-3 file:py-2.5 file:px-4
                        file:rounded-lg file:border-0
                        file:text-sm file:font-semibold
                        file:bg-teal-600 file:text-white
                        hover:file:bg-teal-700
                        border-2 border-teal-100 rounded-lg
                        focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10
                        transition-all cursor-pointer" />
          <p class="text-xs text-teal-900/60">Accepted format: .csv</p>
        </div>

        <div class="flex items-center justify-between rounded-xl bg-teal-50/60 border-2 border-teal-100 px-4 py-3">
          <div class="flex items-start gap-3">
            <i data-lucide="file-spreadsheet" class="w-5 h-5 text-teal-600 mt-0.5 flex-shrink-0"></i>
            <div>
              <p class="text-sm font-semibold text-teal-900">Need the correct format?</p>
              <p class="text-xs text-teal-900/60">Download the sample CSV template.</p>
            </div>
          </div>
          <a href="summary_details.php?download_template=1"
             class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-white hover:bg-teal-100 border-2 border-teal-200 hover:border-teal-600 text-teal-700 font-semibold text-sm transition-all duration-200 hover:scale-105 active:scale-95 whitespace-nowrap">
            <i data-lucide="download" class="w-4 h-4"></i> <span>Sample Template</span>
          </a>
        </div>

        <div class="flex justify-center pt-2 gap-3">
          <button type="button" onclick="closeBulkUploadModal()"
                  class="px-6 py-3 rounded-lg font-semibold text-teal-900 bg-white border-2 border-teal-200 hover:border-teal-600 hover:bg-teal-50 transition-all duration-200">
            Cancel
          </button>
          <button type="submit"
                  class="btn-sheen px-8 py-3 rounded-lg bg-teal-600 hover:bg-teal-700 text-white font-bold shadow-sm hover:shadow-md transition-all duration-200 hover:-translate-y-0.5 active:scale-95 flex items-center gap-2">
            <i data-lucide="upload" class="w-4 h-4"></i> <span>Import</span>
          </button>
        </div>
      </form>
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
                class="flex-1 px-5 py-3 rounded-lg font-semibold text-teal-900 bg-white border-2 border-teal-200 hover:border-teal-600 hover:bg-teal-50 transition-all duration-200">
          Cancel
        </button>
        <button type="button" id="confirmLogoutBtn"
                class="flex-1 px-5 py-3 rounded-lg font-bold text-white bg-teal-600 hover:bg-teal-700 shadow-sm hover:shadow-md transition-all duration-200 flex items-center justify-center gap-2">
          <i data-lucide="log-out" class="w-4 h-4"></i> <span>Log Out</span>
        </button>
      </div>
    </div>
  </div>

  <!-- Hidden delete form -->
  <form id="deleteForm" method="POST" action="summary_details.php" class="hidden">
    <input type="hidden" name="action" value="delete_summary" />
    <input type="hidden" name="grievance_id" id="deleteGrievanceId" value="" />
  </form>

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

      // Helpers
      function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = (str === undefined || str === null) ? '' : String(str);
        return div.innerHTML;
      }
      function setTextOrDash(id, value) {
        const el = document.getElementById(id);
        if (!el) return;
        const v = (value === undefined || value === null) ? '' : String(value).trim();
        el.textContent = v !== '' ? v : '—';
      }

      // Attachment renderer
      function renderAttachment(containerId, url) {
        const container = document.getElementById(containerId);
        if (!container) return;

        if (!url) {
          container.innerHTML = '<span class="text-teal-900/40 italic text-sm">No attachment</span>';
          return;
        }

        const safeUrl  = String(url);
        const fileName = (safeUrl.split('/').pop() || 'attachment').split('?')[0];
        const isImage  = /\.(png|jpe?g|gif|webp|bmp|svg)$/i.test(safeUrl);

        const viewIcon =
          '<svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
            '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>' +
            '<circle cx="12" cy="12" r="3"></circle>' +
          '</svg>';

        const downloadIcon =
          '<svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
            '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>' +
            '<polyline points="7 10 12 15 17 10"></polyline>' +
            '<line x1="12" y1="15" x2="12" y2="3"></line>' +
          '</svg>';

        const fileIcon =
          '<svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-teal-600 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">' +
            '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>' +
            '<polyline points="14 2 14 8 20 8"></polyline>' +
          '</svg>';

        let imagePreview = '';
        if (isImage) {
          imagePreview =
            '<div class="mb-3">' +
              '<img src="' + escapeHtml(safeUrl) + '" alt="' + escapeHtml(fileName) + '" ' +
                   'class="max-h-48 rounded-lg border border-teal-100 shadow-sm" />' +
            '</div>';
        }

        container.innerHTML =
          '<div class="rounded-xl border-2 border-teal-100 bg-teal-50/60 p-3">' +
            imagePreview +
            '<div class="flex items-center gap-3 flex-wrap sm:flex-nowrap">' +
              (isImage ? '' : '<div class="flex-shrink-0">' + fileIcon + '</div>') +
              '<div class="min-w-0 flex-1">' +
                '<p class="text-xs font-semibold text-teal-900 truncate" title="' + escapeHtml(fileName) + '">' +
                  escapeHtml(fileName) +
                '</p>' +
                '<p class="text-[10px] text-teal-900/50 uppercase tracking-wider font-bold mt-0.5">' +
                  (isImage ? 'Image' : 'Document') +
                '</p>' +
              '</div>' +
              '<div class="flex items-center gap-2 flex-shrink-0">' +
                '<a href="' + escapeHtml(safeUrl) + '" target="_blank" rel="noopener" ' +
                   'title="View attachment" ' +
                   'class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-white hover:bg-teal-100 ' +
                          'border-2 border-teal-200 hover:border-teal-600 text-teal-900 hover:text-teal-700 ' +
                          'font-semibold text-xs transition-all duration-200 hover:scale-105 active:scale-95">' +
                  viewIcon + '<span>View</span>' +
                '</a>' +
                '<a href="' + escapeHtml(safeUrl) + '" download="' + escapeHtml(fileName) + '" ' +
                   'title="Download attachment" ' +
                   'class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-teal-600 hover:bg-teal-700 ' +
                          'text-white font-semibold text-xs shadow-sm hover:shadow-md ' +
                          'transition-all duration-200 hover:scale-105 active:scale-95">' +
                  downloadIcon + '<span>Download</span>' +
                '</a>' +
              '</div>' +
            '</div>' +
          '</div>';
      }

      function renderActions(actions) {
        const listEl  = document.getElementById('viewActionsList');
        const countEl = document.getElementById('viewActionsCount');
        if (!listEl) return;

        const arr = Array.isArray(actions) ? actions : [];

        if (countEl) countEl.textContent = String(arr.length);

        if (arr.length === 0) {
          listEl.innerHTML =
            '<div class="text-sm text-teal-900/40 italic bg-teal-50/60 border border-dashed border-teal-200 rounded-lg px-3 py-3 text-center">' +
              'No action records found for this grievance.' +
            '</div>';
          return;
        }

        let html = '';
        arr.forEach(function (a, idx) {
          const dateStr   = a.action_date && String(a.action_date).trim() !== '' ? String(a.action_date) : '—';
          const summary   = a.summary && String(a.summary).trim() !== '' ? String(a.summary) : '';
          const actionTxt = a.action_taken && String(a.action_taken).trim() !== '' ? String(a.action_taken) : '';
          const attendee  = a.attendee_name && String(a.attendee_name).trim() !== '' ? String(a.attendee_name) : '—';
          const attType   = a.attendee_type && String(a.attendee_type).trim() !== '' ? String(a.attendee_type) : '';

          html +=
            '<div class="rounded-xl border-2 border-teal-100 bg-teal-50/60 overflow-hidden">' +
              '<div class="flex items-center justify-between px-4 py-2 bg-white border-b border-teal-100">' +
                '<div class="flex items-center gap-2">' +
                  '<span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-teal-600 text-white text-[10px] font-bold">' +
                    (idx + 1) +
                  '</span>' +
                  '<span class="text-xs font-bold text-teal-900">' + escapeHtml(dateStr) + '</span>' +
                '</div>' +
                '<div class="text-right min-w-0">' +
                  '<p class="text-[10px] uppercase font-bold tracking-wider text-teal-900/40">Attendee</p>' +
                  '<p class="text-xs font-semibold text-teal-900 truncate">' +
                    escapeHtml(attendee) +
                    (attType ? ' <span class="text-[10px] text-teal-900/50 font-normal">(' + escapeHtml(attType) + ')</span>' : '') +
                  '</p>' +
                '</div>' +
              '</div>' +
              '<div class="px-4 py-3 space-y-2">' +
                '<div>' +
                  '<p class="text-[10px] font-bold uppercase tracking-wider text-teal-900/40 mb-1">Summary</p>' +
                  '<p class="text-sm text-teal-900/80 whitespace-pre-wrap break-words">' +
                    (summary !== '' ? escapeHtml(summary) : '<span class="text-teal-900/40 italic">—</span>') +
                  '</p>' +
                '</div>' +
                '<div>' +
                  '<p class="text-[10px] font-bold uppercase tracking-wider text-teal-900/40 mb-1">Action Taken</p>' +
                  '<p class="text-sm text-teal-900/80 whitespace-pre-wrap break-words">' +
                    (actionTxt !== '' ? escapeHtml(actionTxt) : '<span class="text-teal-900/40 italic">—</span>') +
                  '</p>' +
                '</div>' +
              '</div>' +
            '</div>';
        });

        listEl.innerHTML = html;
      }

      window.openViewModal = function (data) {
        if (!data) data = {};

        setTextOrDash('viewName',         data.name);
        setTextOrDash('viewRole',         data.role);
        setTextOrDash('viewClassDept',    data.class_department);
        setTextOrDash('viewNumber',       data.grievance_number);
        setTextOrDash('viewType',         data.grievance_type);
        setTextOrDash('viewPostedDate',   data.posted_date);
        setTextOrDash('viewReplyDate',    data.reply_date);
        setTextOrDash('viewSubject',      data.subject);
        setTextOrDash('viewDescription',  data.description);
        setTextOrDash('viewReplyDetails', data.reply_details);
        setTextOrDash('viewFeedbackDetails', data.feedback_details);

        renderAttachment('viewAttachmentContent',      data.attachment_url);
        renderAttachment('viewReplyAttachmentContent', data.reply_attachment_url);
        renderActions(data.actions);

        const statusEl = document.getElementById('viewStatus');
        if (statusEl) {
          const status = String(data.status || 'Pending');
          statusEl.textContent = status;
          statusEl.className = 'inline-flex items-center px-3 py-1 text-xs font-semibold rounded-full border';

          const key = status.toLowerCase();
          if (key === 'pending')          statusEl.classList.add('bg-amber-100', 'text-amber-800', 'border-amber-200');
          else if (key === 'in progress') statusEl.classList.add('bg-sky-100', 'text-sky-800', 'border-sky-200');
          else if (key === 'disposed')    statusEl.classList.add('bg-emerald-100', 'text-emerald-800', 'border-emerald-200');
          else if (key === 'closed')      statusEl.classList.add('bg-slate-100', 'text-slate-700', 'border-slate-200');
          else if (key === 'reopened')    statusEl.classList.add('bg-pink-100', 'text-pink-800', 'border-pink-200');
          else                            statusEl.classList.add('bg-slate-100', 'text-slate-700', 'border-slate-200');
        }

        const viewModal = document.getElementById('viewModal');
        viewModal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        if (typeof lucide !== 'undefined') lucide.createIcons();
      };
      window.closeViewModal = function () {
        document.getElementById('viewModal').classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
      };

      // Delete
      const deleteConfirmModal = document.getElementById('deleteConfirmModal');
      const deleteConfirmPanel = document.getElementById('deleteConfirmPanel');
      const deleteNameDisplay  = document.getElementById('deleteNameDisplay');
      const confirmDeleteBtn   = document.getElementById('confirmDeleteBtn');
      let pendingDeleteId = null;

      window.confirmDelete = function (grievanceId, label) {
        pendingDeleteId = grievanceId;
        if (deleteNameDisplay) deleteNameDisplay.textContent = '"' + label + '"';
        deleteConfirmModal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        if (deleteConfirmPanel) {
          deleteConfirmPanel.classList.remove('animate-confirm-shake');
          void deleteConfirmPanel.offsetWidth;
          deleteConfirmPanel.classList.add('animate-confirm-shake');
        }
        setTimeout(function () { if (confirmDeleteBtn) confirmDeleteBtn.focus(); }, 80);
        if (typeof lucide !== 'undefined') lucide.createIcons();
      };
      window.closeDeleteModal = function () {
        deleteConfirmModal.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
        pendingDeleteId = null;
      };
      if (confirmDeleteBtn) {
        confirmDeleteBtn.addEventListener('click', function () {
          if (pendingDeleteId === null) return window.closeDeleteModal();
          const input = document.getElementById('deleteGrievanceId');
          const form  = document.getElementById('deleteForm');
          if (input && form) {
            input.value = String(pendingDeleteId);
            form.submit();
          }
        });
      }

      // Create modal
      const createModal = document.getElementById('createSummaryModal');
      const createForm  = document.getElementById('createSummaryForm');
      window.openCreateModal = function () {
        createModal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        setTimeout(function () {
          const r = document.getElementById('create_role');
          if (r) r.focus();
        }, 80);
        if (typeof lucide !== 'undefined') lucide.createIcons();
      };
      window.closeCreateModal = function () {
        createModal.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
        if (createForm) createForm.reset();
      };

      // Bulk modal
      const bulkModal = document.getElementById('bulkUploadModal');
      const bulkForm  = document.getElementById('bulkUploadForm');
      window.openBulkUploadModal = function () {
        bulkModal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        setTimeout(function () {
          const f = document.getElementById('csv_file');
          if (f) f.focus();
        }, 80);
        if (typeof lucide !== 'undefined') lucide.createIcons();
      };
      window.closeBulkUploadModal = function () {
        bulkModal.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
        if (bulkForm) bulkForm.reset();
      };

      // Logout
      const logoutConfirmModal = document.getElementById('logoutConfirmModal');
      const logoutConfirmPanel = document.getElementById('logoutConfirmPanel');
      const confirmLogoutBtn   = document.getElementById('confirmLogoutBtn');
      const LOGOUT_URL         = '../logout.php?role=admin';

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

      // Entries + search
      (function () {
        const entriesSelect = document.getElementById('entriesPerPage');
        const filterForm    = document.getElementById('filterForm');
        if (!entriesSelect || !filterForm) return;
        entriesSelect.addEventListener('change', function () { filterForm.submit(); });
      })();

      (function () {
        const searchInput = document.getElementById('searchInput');
        const tableBody   = document.getElementById('summaryTableBody');
        if (!searchInput || !tableBody) return;

        let debounceTimer = null;
        searchInput.addEventListener('input', function () {
          const term = this.value.toLowerCase().trim();
          tableBody.querySelectorAll('tr').forEach(function (row) {
            row.style.display = (term === '' || row.textContent.toLowerCase().indexOf(term) !== -1) ? '' : 'none';
          });
          clearTimeout(debounceTimer);
          debounceTimer = setTimeout(function () {
            const form = document.getElementById('filterForm');
            if (form) form.submit();
          }, 700);
        });
      })();

      // Mobile: enforce 10 digits
      (function () {
        const cellInput = document.getElementById('create_cell_no');
        if (!cellInput) return;
        cellInput.addEventListener('input', function () {
          this.value = this.value.replace(/\D/g, '').slice(0, 10);
        });
      })();

      // Escape closes modals
      document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        const v = document.getElementById('viewModal');
        const d = document.getElementById('deleteConfirmModal');
        const c = document.getElementById('createSummaryModal');
        const b = document.getElementById('bulkUploadModal');
        const l = document.getElementById('logoutConfirmModal');
        if (v && !v.classList.contains('hidden')) window.closeViewModal();
        if (d && !d.classList.contains('hidden')) window.closeDeleteModal();
        if (c && !c.classList.contains('hidden')) window.closeCreateModal();
        if (b && !b.classList.contains('hidden')) window.closeBulkUploadModal();
        if (l && !l.classList.contains('hidden')) window.closeLogoutModal();
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
<?php
/**
 * admin/students.php
 * ---------------------------------------------------------------------------
 * Admin — Student Management (per class)
 * Rajagiri College Grievance Redressal Portal
 *
 * Features:
 *   • Lists students of a specific class (via ?class_id=X)
 *   • Add / Edit / View / Delete students (with admission_number)
 *   • Set password (custom password + confirm)
 *   • Bulk delete selected students
 *   • Bulk Excel/CSV import with sample template
 *   • Live search + entries-per-page dropdown
 *   • Themed delete / set-password / logout confirmation modals
 *   • Flash messages auto-dismiss after 3 seconds
 *   • Show/hide password toggles
 *   • Mobile number must be exactly 10 digits
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

// ---------------------------------------------------------------------------
// 5. VALIDATE CLASS_ID
// ---------------------------------------------------------------------------
$classId = isset($_GET['class_id']) ? (int) $_GET['class_id'] : 0;

if ($classId <= 0) {
    header('Location: classes.php');
    exit;
}

// ---------------------------------------------------------------------------
// 6. SAMPLE TEMPLATE DOWNLOAD (?download_template=1)
// ---------------------------------------------------------------------------
if (isset($_GET['download_template']) && (string) $_GET['download_template'] === '1') {
    $filename = 'students_import_template.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, ['admission_number', 'name', 'email', 'contact_number', 'address', 'username', 'password']);
    fputcsv($out, ['RCSS2025001', 'John Doe', 'john.doe@example.com', '9876543210', 'Kochi, Kerala', 'johndoe', 'Student@123']);
    fputcsv($out, ['RCSS2025002', 'Jane Smith', 'jane.smith@example.com', '9876543211', 'Thrissur, Kerala', 'janesmith', 'Student@456']);

    fclose($out);
    exit;
}

// ---------------------------------------------------------------------------
// 7. FETCH ADMIN PROFILE
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
        error_log('[Students Admin Profile] ' . $ex->getMessage());
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
// 8. FETCH CLASS + COURSE INFO
// ---------------------------------------------------------------------------
$className   = '';
$courseName  = '';
$classExists = false;

if ($conn instanceof mysqli) {
    try {
        $sql = "SELECT cl.id, cl.class_name, co.course_name
                FROM classes cl
                JOIN courses co ON cl.course_id = co.id
                WHERE cl.id = ?
                LIMIT 1";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $classId);
            $stmt->execute();
            $res = $stmt->get_result();

            if ($res && $res->num_rows > 0) {
                $row         = $res->fetch_assoc();
                $className   = (string) ($row['class_name']  ?? '');
                $courseName  = (string) ($row['course_name'] ?? '');
                $classExists = true;
            }
            $stmt->close();
        }
    } catch (Throwable $ex) {
        error_log('[Fetch Class Info] ' . $ex->getMessage());
    }
}

if (!$classExists) {
    header('Location: classes.php');
    exit;
}

// ---------------------------------------------------------------------------
// 9. HANDLE FORM SUBMISSIONS
// ---------------------------------------------------------------------------
$flashSuccess = '';
$flashError   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $conn instanceof mysqli) {

    $action = $_POST['action'] ?? '';

    // -------- ADD STUDENT --------
    if ($action === 'add_student') {
        $admissionNumber = trim((string) ($_POST['admission_number'] ?? ''));
        $name            = trim((string) ($_POST['name']             ?? ''));
        $email           = trim((string) ($_POST['email']            ?? ''));
        $mobileNumber    = trim((string) ($_POST['contact_number']   ?? ''));
        $address         = trim((string) ($_POST['address']          ?? ''));
        $username        = trim((string) ($_POST['username']         ?? ''));
        $password        = (string)       ($_POST['password']        ?? '');

        if ($admissionNumber === '' || $name === '' || $email === '' || $mobileNumber === '' || $username === '' || $password === '') {
            $flashError = 'Please fill in all required fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $flashError = 'Please enter a valid email address.';
        } elseif (!isValidMobile($mobileNumber)) {
            $flashError = 'Mobile number must be exactly 10 digits.';
        }

        if ($flashError === '') {
            try {
                $conn->begin_transaction();

                $chk = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
                $chk->bind_param('s', $username);
                $chk->execute();
                $dupUser = $chk->get_result()->num_rows > 0;
                $chk->close();

                $chk2 = $conn->prepare("SELECT id FROM students WHERE email = ? LIMIT 1");
                $chk2->bind_param('s', $email);
                $chk2->execute();
                $dupEmail = $chk2->get_result()->num_rows > 0;
                $chk2->close();

                $chk3 = $conn->prepare("SELECT id FROM students WHERE admission_number = ? LIMIT 1");
                $chk3->bind_param('s', $admissionNumber);
                $chk3->execute();
                $dupAdmission = $chk3->get_result()->num_rows > 0;
                $chk3->close();

                if ($dupUser)      throw new Exception('Username already exists.');
                if ($dupEmail)     throw new Exception('Email is already registered for another student.');
                if ($dupAdmission) throw new Exception('Admission number already exists.');

                $hash = password_hash($password, PASSWORD_BCRYPT);
                $role = 'STUDENT';

                $stmtU = $conn->prepare("INSERT INTO users (username, password, role, status) VALUES (?, ?, ?, 'Approved')");
                $stmtU->bind_param('sss', $username, $hash, $role);
                $stmtU->execute();
                $newUserId = (int) $conn->insert_id;
                $stmtU->close();

                $stmtS = $conn->prepare("INSERT INTO students (user_id, class_id, admission_number, name, email, contact_number, address) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmtS->bind_param('iisssss', $newUserId, $classId, $admissionNumber, $name, $email, $mobileNumber, $address);
                $stmtS->execute();
                $stmtS->close();

                $conn->commit();
                $flashSuccess = 'Student added successfully.';
            } catch (Throwable $ex) {
                if ($conn instanceof mysqli) $conn->rollback();
                error_log('[Add Student] ' . $ex->getMessage());
                $flashError = $ex->getMessage() ?: 'A system error occurred while adding the student.';
            }
        }
    }

    // -------- EDIT STUDENT --------
    if ($action === 'edit_student') {
        $studentId       = (int) ($_POST['student_id']      ?? 0);
        $admissionNumber = trim((string) ($_POST['admission_number'] ?? ''));
        $name            = trim((string) ($_POST['name']             ?? ''));
        $email           = trim((string) ($_POST['email']            ?? ''));
        $mobileNumber    = trim((string) ($_POST['contact_number']   ?? ''));
        $address         = trim((string) ($_POST['address']          ?? ''));
        $username        = trim((string) ($_POST['username']         ?? ''));

        if ($studentId <= 0) {
            $flashError = 'Invalid student.';
        } elseif ($admissionNumber === '' || $name === '' || $email === '' || $mobileNumber === '' || $username === '') {
            $flashError = 'Please fill in all required fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $flashError = 'Please enter a valid email address.';
        } elseif (!isValidMobile($mobileNumber)) {
            $flashError = 'Mobile number must be exactly 10 digits.';
        }

        if ($flashError === '') {
            try {
                $conn->begin_transaction();

                $stmtG = $conn->prepare("SELECT user_id FROM students WHERE id = ? LIMIT 1");
                $stmtG->bind_param('i', $studentId);
                $stmtG->execute();
                $resG = $stmtG->get_result();
                $linkedUserId = $resG && $resG->num_rows > 0 ? (int) ($resG->fetch_assoc()['user_id'] ?? 0) : 0;
                $stmtG->close();

                if ($linkedUserId <= 0) {
                    throw new Exception('Student record not found.');
                }

                $chk = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1");
                $chk->bind_param('si', $username, $linkedUserId);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0) {
                    $chk->close();
                    throw new Exception('Username already exists.');
                }
                $chk->close();

                $chk2 = $conn->prepare("SELECT id FROM students WHERE email = ? AND id != ? LIMIT 1");
                $chk2->bind_param('si', $email, $studentId);
                $chk2->execute();
                if ($chk2->get_result()->num_rows > 0) {
                    $chk2->close();
                    throw new Exception('Email is already registered for another student.');
                }
                $chk2->close();

                $chk3 = $conn->prepare("SELECT id FROM students WHERE admission_number = ? AND id != ? LIMIT 1");
                $chk3->bind_param('si', $admissionNumber, $studentId);
                $chk3->execute();
                if ($chk3->get_result()->num_rows > 0) {
                    $chk3->close();
                    throw new Exception('Admission number already exists.');
                }
                $chk3->close();

                $stmtU = $conn->prepare("UPDATE users SET username = ? WHERE id = ?");
                $stmtU->bind_param('si', $username, $linkedUserId);
                $stmtU->execute();
                $stmtU->close();

                $stmtS = $conn->prepare("UPDATE students SET admission_number = ?, name = ?, email = ?, contact_number = ?, address = ? WHERE id = ?");
                $stmtS->bind_param('sssssi', $admissionNumber, $name, $email, $mobileNumber, $address, $studentId);
                $stmtS->execute();
                $stmtS->close();

                $conn->commit();
                $flashSuccess = 'Student updated successfully.';
            } catch (Throwable $ex) {
                if ($conn instanceof mysqli) $conn->rollback();
                error_log('[Edit Student] ' . $ex->getMessage());
                $flashError = $ex->getMessage() ?: 'A system error occurred while updating the student.';
            }
        }
    }

    // -------- DELETE STUDENT --------
    if ($action === 'delete_student') {
        $studentId = (int) ($_POST['student_id'] ?? 0);
        if ($studentId > 0) {
            try {
                $conn->begin_transaction();

                $stmtG = $conn->prepare("SELECT user_id FROM students WHERE id = ? LIMIT 1");
                $stmtG->bind_param('i', $studentId);
                $stmtG->execute();
                $resG = $stmtG->get_result();
                $linkedUserId = $resG && $resG->num_rows > 0 ? (int) ($resG->fetch_assoc()['user_id'] ?? 0) : 0;
                $stmtG->close();

                $stmtD = $conn->prepare("DELETE FROM students WHERE id = ?");
                $stmtD->bind_param('i', $studentId);
                $stmtD->execute();
                $stmtD->close();

                if ($linkedUserId > 0) {
                    $stmtU = $conn->prepare("DELETE FROM users WHERE id = ?");
                    $stmtU->bind_param('i', $linkedUserId);
                    $stmtU->execute();
                    $stmtU->close();
                }

                $conn->commit();
                $flashSuccess = 'Student deleted successfully.';
            } catch (Throwable $ex) {
                if ($conn instanceof mysqli) $conn->rollback();
                error_log('[Delete Student] ' . $ex->getMessage());
                $flashError = 'A system error occurred while deleting the student.';
            }
        }
    }

    // -------- BULK DELETE --------
    if ($action === 'bulk_delete_students') {
        $ids = $_POST['student_ids'] ?? [];
        if (is_array($ids) && !empty($ids)) {
            $cleanIds = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));

            if (!empty($cleanIds)) {
                try {
                    $conn->begin_transaction();

                    $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
                    $types        = str_repeat('i', count($cleanIds));

                    $stmtG = $conn->prepare("SELECT user_id FROM students WHERE id IN ($placeholders)");
                    $stmtG->bind_param($types, ...$cleanIds);
                    $stmtG->execute();
                    $resG = $stmtG->get_result();
                    $userIds = [];
                    while ($row = $resG->fetch_assoc()) {
                        $uid = (int) ($row['user_id'] ?? 0);
                        if ($uid > 0) $userIds[] = $uid;
                    }
                    $stmtG->close();

                    $stmtD = $conn->prepare("DELETE FROM students WHERE id IN ($placeholders)");
                    $stmtD->bind_param($types, ...$cleanIds);
                    $stmtD->execute();
                    $stmtD->close();

                    if (!empty($userIds)) {
                        $uPlaceholders = implode(',', array_fill(0, count($userIds), '?'));
                        $uTypes        = str_repeat('i', count($userIds));
                        $stmtU = $conn->prepare("DELETE FROM users WHERE id IN ($uPlaceholders)");
                        $stmtU->bind_param($uTypes, ...$userIds);
                        $stmtU->execute();
                        $stmtU->close();
                    }

                    $conn->commit();
                    $flashSuccess = count($cleanIds) . ' student(s) deleted successfully.';
                } catch (Throwable $ex) {
                    if ($conn instanceof mysqli) $conn->rollback();
                    error_log('[Bulk Delete Students] ' . $ex->getMessage());
                    $flashError = 'A system error occurred while deleting the students.';
                }
            } else {
                $flashError = 'No valid students selected.';
            }
        } else {
            $flashError = 'Please select at least one student to delete.';
        }
    }

    // -------- BULK IMPORT (CSV) --------
    if ($action === 'import_students') {

        if (!isset($_FILES['import_file']) || !is_array($_FILES['import_file'])) {
            $flashError = 'Please select a file to upload.';
        } elseif ((int) ($_FILES['import_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $flashError = 'File upload failed. Please try again.';
        } else {
            $tmpPath  = (string) ($_FILES['import_file']['tmp_name'] ?? '');
            $origName = (string) ($_FILES['import_file']['name']     ?? '');
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

                    $rowNumber      = 0;
                    $insertedCount  = 0;
                    $skippedRows    = [];
                    $seenUsernames  = [];
                    $seenEmails     = [];
                    $seenAdmissions = [];

                    try {
                        $conn->begin_transaction();

                        $stmtU = $conn->prepare("INSERT INTO users (username, password, role, status) VALUES (?, ?, 'STUDENT', 'Approved')");
                        $stmtS = $conn->prepare("INSERT INTO students (user_id, class_id, admission_number, name, email, contact_number, address) VALUES (?, ?, ?, ?, ?, ?, ?)");

                        if (!$stmtU || !$stmtS) {
                            throw new Exception('Failed to prepare insert statements.');
                        }

                        $chkUser = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
                        $chkMail = $conn->prepare("SELECT id FROM students WHERE email = ? LIMIT 1");
                        $chkAdm  = $conn->prepare("SELECT id FROM students WHERE admission_number = ? LIMIT 1");

                        while (($row = fgetcsv($handle, 0, ',')) !== false) {
                            $rowNumber++;

                            if (count($row) === 1 && trim((string) $row[0]) === '') {
                                continue;
                            }

                            if ($rowNumber === 1) {
                                $firstCell = strtolower(trim((string) ($row[0] ?? '')));
                                if ($firstCell === 'admission_number' || $firstCell === 'admission no' || $firstCell === 'admissionno') {
                                    continue;
                                }
                            }

                            if (count($row) < 7) {
                                $skippedRows[] = "Row {$rowNumber}: Not enough columns (expected 7).";
                                continue;
                            }

                            $rAdmission = trim((string) ($row[0] ?? ''));
                            $rName      = trim((string) ($row[1] ?? ''));
                            $rEmail     = trim((string) ($row[2] ?? ''));
                            $rMobile    = trim((string) ($row[3] ?? ''));
                            $rAddress   = trim((string) ($row[4] ?? ''));
                            $rUsername  = trim((string) ($row[5] ?? ''));
                            $rPassword  = (string)       ($row[6] ?? '');

                            $rAdmission = preg_replace('/^\xEF\xBB\xBF/', '', $rAdmission) ?? $rAdmission;

                            if ($rAdmission === '' || $rName === '' || $rEmail === '' || $rMobile === '' || $rUsername === '' || $rPassword === '') {
                                $skippedRows[] = "Row {$rowNumber}: Missing required fields.";
                                continue;
                            }
                            if (!filter_var($rEmail, FILTER_VALIDATE_EMAIL)) {
                                $skippedRows[] = "Row {$rowNumber}: Invalid email.";
                                continue;
                            }
                            if (!isValidMobile($rMobile)) {
                                $skippedRows[] = "Row {$rowNumber}: Mobile number must be exactly 10 digits.";
                                continue;
                            }

                            $lowerUser  = strtolower($rUsername);
                            $lowerEmail = strtolower($rEmail);
                            $lowerAdm   = strtolower($rAdmission);

                            if (isset($seenUsernames[$lowerUser])) {
                                $skippedRows[] = "Row {$rowNumber}: Duplicate username within file ({$rUsername}).";
                                continue;
                            }
                            if (isset($seenEmails[$lowerEmail])) {
                                $skippedRows[] = "Row {$rowNumber}: Duplicate email within file ({$rEmail}).";
                                continue;
                            }
                            if (isset($seenAdmissions[$lowerAdm])) {
                                $skippedRows[] = "Row {$rowNumber}: Duplicate admission number within file ({$rAdmission}).";
                                continue;
                            }

                            $chkUser->bind_param('s', $rUsername);
                            $chkUser->execute();
                            $chkUser->store_result();
                            if ($chkUser->num_rows > 0) {
                                $chkUser->free_result();
                                $skippedRows[] = "Row {$rowNumber}: Username already exists ({$rUsername}).";
                                continue;
                            }
                            $chkUser->free_result();

                            $chkMail->bind_param('s', $rEmail);
                            $chkMail->execute();
                            $chkMail->store_result();
                            if ($chkMail->num_rows > 0) {
                                $chkMail->free_result();
                                $skippedRows[] = "Row {$rowNumber}: Email already registered ({$rEmail}).";
                                continue;
                            }
                            $chkMail->free_result();

                            $chkAdm->bind_param('s', $rAdmission);
                            $chkAdm->execute();
                            $chkAdm->store_result();
                            if ($chkAdm->num_rows > 0) {
                                $chkAdm->free_result();
                                $skippedRows[] = "Row {$rowNumber}: Admission number already exists ({$rAdmission}).";
                                continue;
                            }
                            $chkAdm->free_result();

                            $hash = password_hash($rPassword, PASSWORD_BCRYPT);
                            $stmtU->bind_param('ss', $rUsername, $hash);
                            if (!$stmtU->execute()) {
                                throw new Exception("Row {$rowNumber}: Failed to create user account.");
                            }
                            $newUserId = (int) $conn->insert_id;

                            $stmtS->bind_param('iisssss', $newUserId, $classId, $rAdmission, $rName, $rEmail, $rMobile, $rAddress);
                            if (!$stmtS->execute()) {
                                throw new Exception("Row {$rowNumber}: Failed to create student record.");
                            }

                            $seenUsernames[$lowerUser] = true;
                            $seenEmails[$lowerEmail]   = true;
                            $seenAdmissions[$lowerAdm] = true;
                            $insertedCount++;
                        }

                        $stmtU->close();
                        $stmtS->close();
                        $chkUser->close();
                        $chkMail->close();
                        $chkAdm->close();

                        if ($insertedCount === 0) {
                            throw new Exception('No valid rows were found in the uploaded file.');
                        }

                        $conn->commit();

                        $msg = "Successfully imported {$insertedCount} student(s) into {$className}.";
                        if (!empty($skippedRows)) {
                            $msg .= ' Skipped ' . count($skippedRows) . ' row(s).';
                        }
                        $flashSuccess = $msg;

                        if (!empty($skippedRows)) {
                            $_SESSION['import_skipped_rows'] = $skippedRows;
                        }

                    } catch (Throwable $ex) {
                        if ($conn instanceof mysqli) $conn->rollback();
                        error_log('[Bulk Import] ' . $ex->getMessage());
                        $flashError = $ex->getMessage() ?: 'A system error occurred during the import.';
                    }

                    fclose($handle);
                }
            }
        }
    }

    // -------- SET PASSWORD (replaces reset_password) --------
    if ($action === 'set_password') {
        $studentId       = (int) ($_POST['student_id']        ?? 0);
        $newPassword     = (string) ($_POST['new_password']    ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if ($studentId <= 0) {
            $flashError = 'Invalid student.';
        } elseif ($newPassword === '' || $confirmPassword === '') {
            $flashError = 'Please enter and confirm the new password.';
        } elseif (strlen($newPassword) < 6) {
            $flashError = 'Password must be at least 6 characters long.';
        } elseif ($newPassword !== $confirmPassword) {
            $flashError = 'Passwords do not match.';
        }

        if ($flashError === '') {
            try {
                $stmtG = $conn->prepare("SELECT user_id FROM students WHERE id = ? LIMIT 1");
                $stmtG->bind_param('i', $studentId);
                $stmtG->execute();
                $resG = $stmtG->get_result();
                $linkedUserId = $resG && $resG->num_rows > 0 ? (int) ($resG->fetch_assoc()['user_id'] ?? 0) : 0;
                $stmtG->close();

                if ($linkedUserId > 0) {
                    $hash = password_hash($newPassword, PASSWORD_BCRYPT);

                    $stmtU = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmtU->bind_param('si', $hash, $linkedUserId);
                    $stmtU->execute();
                    $stmtU->close();

                    $flashSuccess = 'Password updated successfully.';
                } else {
                    $flashError = 'Linked user account not found.';
                }
            } catch (Throwable $ex) {
                error_log('[Set Password] ' . $ex->getMessage());
                $flashError = 'A system error occurred while setting the password.';
            }
        }
    }

    if ($flashSuccess !== '' || $flashError !== '') {
        $_SESSION['flash_success'] = $flashSuccess;
        $_SESSION['flash_error']   = $flashError;
        header('Location: students.php?class_id=' . $classId);
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

$importSkippedRows = [];
if (!empty($_SESSION['import_skipped_rows']) && is_array($_SESSION['import_skipped_rows'])) {
    $importSkippedRows = $_SESSION['import_skipped_rows'];
    unset($_SESSION['import_skipped_rows']);
}

// ---------------------------------------------------------------------------
// 10. FETCH STUDENTS FOR THIS CLASS
// ---------------------------------------------------------------------------
$students = [];

if ($conn instanceof mysqli) {
    try {
        $sql = "SELECT  st.id, st.user_id, st.class_id,
                        st.admission_number, st.name, st.email,
                        st.contact_number, st.address,
                        u.username, u.status
                FROM students st
                LEFT JOIN users u ON st.user_id = u.id
                WHERE st.class_id = ?
                ORDER BY st.admission_number ASC, st.id ASC";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $classId);
            $stmt->execute();
            $res = $stmt->get_result();

            while ($row = $res->fetch_assoc()) {
                $students[] = $row;
            }
            $stmt->close();
        }
    } catch (Throwable $ex) {
        error_log('[Fetch Students] ' . $ex->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Student — Admin | Rajagiri College Grievance Portal</title>
  <link rel="icon" type="image/svg+xml" href="../public/favicon.svg" />

  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/lucide@latest"></script>

  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            brandPurple: '#4A154B',
            brandPink:   '#E5097F',
            brandGreen:  '#006837',
            brandGold:   '#C5A059'
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

  <link rel="stylesheet" href="../assets/css/index.css" />
</head>

<body class="min-h-screen bg-slate-50 text-slate-800 antialiased flex flex-col">

  <div class="flex min-h-screen flex-1">

    <!-- SIDEBAR -->
    <aside class="w-20 bg-gradient-to-b from-[#4A154B] via-[#5A1B5C] to-[#006837] flex flex-col items-center py-4 shadow-2xl fixed inset-y-0 left-0 z-40">

      <button class="text-white/80 hover:text-white mb-8 p-2 rounded-lg hover:bg-white/10 transition-colors" aria-label="Toggle sidebar">
        <i data-lucide="menu" class="w-6 h-6"></i>
      </button>

      <nav class="flex flex-col items-center space-y-6 flex-1">

        <a href="dashboard.php"
           class="group relative w-12 h-12 rounded-xl bg-white/10 hover:bg-white/20 flex items-center justify-center text-white transition-all hover:scale-110"
           title="Dashboard">
          <i data-lucide="home" class="w-6 h-6"></i>
          <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-[#4A154B] text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Dashboard</span>
        </a>

        <a href="profile.php"
           class="group relative w-12 h-12 rounded-xl bg-white/10 hover:bg-white/20 flex items-center justify-center text-white transition-all hover:scale-110"
           title="Profile">
          <i data-lucide="user" class="w-6 h-6"></i>
          <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-[#4A154B] text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Profile</span>
        </a>

        <a href="settings.php"
           class="group relative w-12 h-12 rounded-xl bg-white/10 hover:bg-white/20 flex items-center justify-center text-white transition-all hover:scale-110"
           title="Settings">
          <i data-lucide="settings" class="w-6 h-6 group-hover:rotate-90 transition-transform duration-500"></i>
          <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-[#4A154B] text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Settings</span>
        </a>

      </nav>

      <a href="#" data-logout-trigger="1"
         id="sidebarLogoutBtn"
         class="group relative w-12 h-12 rounded-xl bg-white/10 hover:bg-red-500/40 flex items-center justify-center text-white transition-all hover:scale-110"
         title="Logout">
        <i data-lucide="log-out" class="w-6 h-6 group-hover:translate-x-0.5 transition-transform"></i>
        <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-red-600 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Logout</span>
      </a>

    </aside>

    <!-- MAIN CONTENT -->
    <div class="flex-1 ml-20 flex flex-col min-h-screen">

      <header class="bg-white border-b border-slate-200 shadow-sm sticky top-0 z-30">
        <div class="flex items-center justify-between px-6 py-4">

          <div class="flex items-center space-x-4">
            <a href="dashboard.php" class="flex items-center group">
              <img src="../public/rcss-logo.png" alt="RCSS Logo"
                   class="h-10 md:h-11 w-auto transition-transform group-hover:scale-105" />
            </a>

            <div class="hidden sm:flex items-center h-10">
              <div class="w-px h-full bg-gradient-to-b from-transparent via-slate-300 to-transparent"></div>
            </div>

            <img src="../public/orel-grievance.png" alt="RLabZ"
                 class="hidden sm:block h-8 md:h-9 w-auto object-contain" />
          </div>

          <div class="relative" id="admin-dropdown-container">
            <button id="admin-dropdown-btn"
                    type="button"
                    aria-haspopup="true"
                    aria-expanded="false"
                    class="flex items-center space-x-3 px-3 py-2 rounded-xl hover:bg-slate-100 transition-colors cursor-pointer">

              <?php if ($hasProfilePicture): ?>
                <img src="<?= e($profilePictureUrl) ?>" alt="<?= e($displayName) ?>"
                     class="w-10 h-10 rounded-full object-cover border-2 border-[#C5A059] shadow-md ring-2 ring-purple-100" />
              <?php else: ?>
                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-[#4A154B] to-[#8B1E7E] flex items-center justify-center text-white shadow-md ring-2 ring-purple-100">
                  <i data-lucide="user" class="w-5 h-5"></i>
                </div>
              <?php endif; ?>

              <span class="hidden sm:block text-sm font-semibold text-slate-700"><?= e($displayName) ?></span>
              <i data-lucide="chevron-down" id="admin-chevron" class="w-4 h-4 text-slate-500 transition-transform duration-300"></i>
            </button>

            <div id="admin-dropdown-menu"
                 class="hidden absolute right-0 mt-2 w-64 bg-white rounded-xl shadow-2xl border border-slate-200 py-2 z-50 overflow-hidden">

              <div class="px-4 py-3 border-b border-slate-100 bg-gradient-to-r from-slate-50 to-white">
                <div class="flex items-center space-x-3">
                  <?php if ($hasProfilePicture): ?>
                    <img src="<?= e($profilePictureUrl) ?>" alt="<?= e($displayName) ?>"
                         class="w-12 h-12 rounded-full object-cover border-2 border-[#C5A059]" />
                  <?php else: ?>
                    <div class="w-12 h-12 rounded-full bg-gradient-to-br from-[#4A154B] to-[#8B1E7E] flex items-center justify-center text-white">
                      <i data-lucide="user" class="w-6 h-6 text-white"></i>
                    </div>
                  <?php endif; ?>
                  <div class="min-w-0 flex-1">
                    <p class="text-sm font-bold text-slate-800 truncate"><?= e($displayName) ?></p>
                    <p class="text-xs text-slate-500 truncate"><?= e($displayEmail) ?></p>
                  </div>
                </div>
              </div>

              <a href="dashboard.php" class="flex items-center px-4 py-2.5 text-sm text-slate-700 hover:bg-gradient-to-r hover:from-pink-50 hover:to-purple-50 hover:text-[#8B1E7E] transition-all duration-200 group/item">
                <i data-lucide="layout-dashboard" class="w-4 h-4 mr-3 text-[#8B1E7E] group-hover/item:scale-110 transition-transform"></i>
                <span class="font-medium">Dashboard</span>
                <i data-lucide="arrow-right" class="w-4 h-4 ml-auto opacity-0 group-hover/item:opacity-100 text-[#8B1E7E] transition-opacity"></i>
              </a>

              <a href="profile.php" class="flex items-center px-4 py-2.5 text-sm text-slate-700 hover:bg-gradient-to-r hover:from-pink-50 hover:to-purple-50 hover:text-[#8B1E7E] transition-all duration-200 group/item">
                <i data-lucide="user" class="w-4 h-4 mr-3 text-[#8B1E7E] group-hover/item:scale-110 transition-transform"></i>
                <span class="font-medium">My Profile</span>
                <i data-lucide="arrow-right" class="w-4 h-4 ml-auto opacity-0 group-hover/item:opacity-100 text-[#8B1E7E] transition-opacity"></i>
              </a>

              <a href="settings.php" class="flex items-center px-4 py-2.5 text-sm text-slate-700 hover:bg-gradient-to-r hover:from-pink-50 hover:to-purple-50 hover:text-[#8B1E7E] transition-all duration-200 group/item">
                <i data-lucide="settings" class="w-4 h-4 mr-3 text-[#8B1E7E] group-hover/item:scale-110 transition-transform"></i>
                <span class="font-medium">Settings</span>
                <i data-lucide="arrow-right" class="w-4 h-4 ml-auto opacity-0 group-hover/item:opacity-100 text-[#8B1E7E] transition-opacity"></i>
              </a>

              <a href="change_password.php" class="flex items-center px-4 py-2.5 text-sm text-slate-700 hover:bg-gradient-to-r hover:from-pink-50 hover:to-purple-50 hover:text-[#8B1E7E] transition-all duration-200 group/item">
                <i data-lucide="key" class="w-4 h-4 mr-3 text-[#8B1E7E] group-hover/item:scale-110 transition-transform"></i>
                <span class="font-medium">Change Password</span>
                <i data-lucide="arrow-right" class="w-4 h-4 ml-auto opacity-0 group-hover/item:opacity-100 text-[#8B1E7E] transition-opacity"></i>
              </a>

              <div class="border-t border-slate-100 mt-2 pt-2">
                <a href="#" data-logout-trigger="1"
                   id="dropdownLogoutBtn"
                   class="flex items-center px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 transition-all duration-200 group/item">
                  <i data-lucide="log-out" class="w-4 h-4 mr-3 group-hover/item:scale-110 transition-transform"></i>
                  <span class="font-medium">Logout</span>
                </a>
              </div>
            </div>
          </div>

        </div>
      </header>

      <main class="flex-1 px-6 py-8">

        <div class="max-w-6xl mx-auto mb-6 animate-fade-in-up">
          <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">

            <div>
              <h1 class="text-2xl md:text-3xl font-bold text-slate-800 mb-2 tracking-tight">Student</h1>
              <nav class="flex items-center space-x-2 text-sm text-slate-500">
                <a href="dashboard.php" class="flex items-center hover:text-[#8B1E7E] transition-colors">
                  <i data-lucide="layout-dashboard" class="w-4 h-4 mr-1"></i> Dashboard
                </a>
                <span class="text-slate-300">/</span>
                <a href="settings.php" class="hover:text-[#8B1E7E] transition-colors">Settings</a>
                <span class="text-slate-300">/</span>
                <a href="classes.php" class="hover:text-[#8B1E7E] transition-colors">Course/semester</a>
                <span class="text-slate-300">/</span>
                <span class="text-[#E5097F] font-semibold">Student</span>
              </nav>
            </div>

            <div class="flex items-center gap-2">

              <button type="button"
                      onclick="openImportModal()"
                      title="Import Students via Excel/CSV"
                      class="inline-flex items-center justify-center w-11 h-11 rounded-xl bg-[#4A154B] hover:bg-[#5A1B5C]
                             text-white shadow-lg shadow-purple-500/30 hover:shadow-purple-500/50
                             transition-all duration-300 hover:-translate-y-0.5 active:scale-95">
                <i data-lucide="file-up" class="w-5 h-5"></i>
              </button>

              <button type="button"
                      id="bulkDeleteBtn"
                      title="Delete selected"
                      class="inline-flex items-center justify-center w-11 h-11 rounded-xl bg-[#4A154B] hover:bg-[#5A1B5C]
                             text-white shadow-lg shadow-purple-500/30 hover:shadow-purple-500/50
                             transition-all duration-300 hover:-translate-y-0.5 active:scale-95">
                <i data-lucide="trash-2" class="w-5 h-5"></i>
              </button>

              <button type="button"
                      onclick="openStudentModal('add')"
                      title="Add Student"
                      class="inline-flex items-center justify-center w-11 h-11 rounded-xl bg-[#4A154B] hover:bg-[#5A1B5C]
                             text-white shadow-lg shadow-purple-500/30 hover:shadow-purple-500/50
                             transition-all duration-300 hover:-translate-y-0.5 active:scale-95">
                <i data-lucide="plus" class="w-5 h-5"></i>
              </button>

            </div>

          </div>
        </div>

        <div class="max-w-6xl mx-auto mb-6 text-center animate-fade-in-up" style="animation-delay: 40ms;">
          <h2 class="text-lg md:text-xl font-bold text-slate-800">
            Class Name : <span class="text-[#8B1E7E]"><?= e($className) ?></span>
          </h2>
        </div>

        <?php if ($flashSuccess !== ''): ?>
          <div id="flashSuccessBox"
               class="max-w-6xl mx-auto mb-6 rounded-xl border-2 border-emerald-200 bg-emerald-50 px-4 py-3 flex items-start space-x-2 animate-flash-in overflow-hidden">
            <i data-lucide="check-circle" class="w-5 h-5 text-[#006837] flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-emerald-800 font-medium"><?= e($flashSuccess) ?></p>
          </div>
        <?php endif; ?>

        <?php if ($flashError !== ''): ?>
          <div id="flashErrorBox"
               class="max-w-6xl mx-auto mb-6 rounded-xl border-2 border-red-200 bg-red-50 px-4 py-3 flex items-start space-x-2 animate-flash-in overflow-hidden">
            <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-red-700 font-medium"><?= e($flashError) ?></p>
          </div>
        <?php endif; ?>

        <?php if (!empty($importSkippedRows)): ?>
          <div class="max-w-6xl mx-auto mb-6 rounded-xl border-2 border-amber-200 bg-amber-50 px-4 py-3 animate-fade-in-up">
            <div class="flex items-start space-x-2 mb-2">
              <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-600 flex-shrink-0 mt-0.5"></i>
              <p class="text-sm font-semibold text-amber-800">
                Some rows were skipped during import (<?= count($importSkippedRows) ?>):
              </p>
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

        <div class="max-w-6xl mx-auto mb-5 animate-fade-in-up" style="animation-delay: 80ms;">
          <div class="bg-white rounded-xl shadow-sm border border-slate-200/70 px-5 py-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">

              <div class="flex items-center space-x-3">
                <span class="text-sm text-slate-600">Show</span>
                <select id="entriesPerPage"
                        class="px-3 py-1.5 border-2 border-slate-200 rounded-lg text-sm font-medium text-slate-700
                               focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                               hover:border-[#4A154B]/40 transition-colors bg-white">
                  <option value="10" selected>10</option>
                  <option value="25">25</option>
                  <option value="50">50</option>
                  <option value="100">100</option>
                </select>
                <span class="text-sm text-slate-600">entries</span>
              </div>

              <div class="relative w-full sm:w-80">
                <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                <input type="text" id="searchInput" placeholder="Search.."
                       class="w-full pl-10 pr-4 py-2 border-2 border-slate-200 rounded-lg text-sm
                              focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                              hover:border-[#4A154B]/40 transition-all bg-white" />
              </div>

            </div>
          </div>
        </div>

        <div class="max-w-6xl mx-auto animate-fade-in-up" style="animation-delay: 120ms;">
          <div class="bg-white rounded-2xl shadow-lg border border-slate-200/70 overflow-hidden">

            <form id="bulkForm" method="POST" action="students.php?class_id=<?= (int) $classId ?>">
              <input type="hidden" name="action" value="bulk_delete_students" />

              <div class="overflow-x-auto">
                <table class="w-full" id="studentsTable">
                  <thead>
                    <tr class="bg-[#4A154B] text-white">
                      <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Sl.No.</th>
                      <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Admission No.</th>
                      <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Name</th>
                      <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Address</th>
                      <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Email</th>
                      <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Mobile Number</th>
                      <th class="px-6 py-4 text-center text-xs font-bold uppercase tracking-wider">
                        <label class="inline-flex items-center gap-2 cursor-pointer">
                          <input type="checkbox" id="selectAllCheckbox"
                                 class="w-4 h-4 rounded border-slate-300 text-[#4A154B] focus:ring-[#4A154B]/30 cursor-pointer" />
                          <span>Select All</span>
                        </label>
                      </th>
                      <th class="px-6 py-4 text-center text-xs font-bold uppercase tracking-wider">Actions</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100" id="studentsTableBody">

                    <?php if (empty($students)): ?>
                      <tr>
                        <td colspan="8" class="px-6 py-16 text-center text-slate-500">
                          <div class="flex flex-col items-center justify-center">
                            <div class="w-16 h-16 bg-purple-50 rounded-full flex items-center justify-center mb-4">
                              <i data-lucide="users" class="w-8 h-8 text-[#8B1E7E]"></i>
                            </div>
                            <p class="text-lg font-semibold text-slate-700">No students yet</p>
                            <p class="text-sm text-slate-500 mt-1 mb-4">
                              Click "Add Student" to enroll the first student, or use the import button above.
                            </p>
                          </div>
                        </td>
                      </tr>
                    <?php else: ?>

                      <?php foreach ($students as $index => $student): ?>
                        <?php
                          $studentId        = (int) $student['id'];
                          $studentAdmission = (string) ($student['admission_number'] ?? '');
                          $studentName      = (string) ($student['name']             ?? '');
                          $studentEmail     = (string) ($student['email']            ?? '');
                          $studentMob       = (string) ($student['contact_number']   ?? '');
                          $studentAddr      = (string) ($student['address']          ?? '');
                          $studentUser      = (string) ($student['username']         ?? '');
                        ?>
                        <tr class="hover:bg-slate-50/80 transition-colors group">
                          <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-slate-900"><?= $index + 1 ?></td>
                          <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-[#4A154B]"><?= e($studentAdmission !== '' ? $studentAdmission : '—') ?></td>
                          <td class="px-6 py-4 text-sm font-semibold text-slate-800"><?= e($studentName) ?></td>
                          <td class="px-6 py-4 text-sm text-slate-600 max-w-[200px]"><?= e($studentAddr !== '' ? $studentAddr : '—') ?></td>
                          <td class="px-6 py-4 text-sm text-slate-600 break-all"><?= e($studentEmail) ?></td>
                          <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-600"><?= e($studentMob !== '' ? $studentMob : '—') ?></td>
                          <td class="px-6 py-4 whitespace-nowrap text-center">
                            <input type="checkbox"
                                   name="student_ids[]"
                                   value="<?= $studentId ?>"
                                   class="student-checkbox w-4 h-4 rounded border-slate-300 text-[#4A154B] focus:ring-[#4A154B]/30 cursor-pointer" />
                          </td>
                          <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center justify-center gap-1.5">

                              <button type="button"
                                      title="Edit student"
                                      onclick='openStudentModal("edit", <?= $studentId ?>, <?= json_encode($studentAdmission) ?>, <?= json_encode($studentName) ?>, <?= json_encode($studentEmail) ?>, <?= json_encode($studentMob) ?>, <?= json_encode($studentAddr) ?>, <?= json_encode($studentUser) ?>)'
                                      class="w-8 h-8 rounded-full bg-purple-50 hover:bg-[#4A154B]
                                             flex items-center justify-center text-[#4A154B] hover:text-white
                                             transition-all duration-200 hover:scale-110">
                                <i data-lucide="pencil" class="w-3.5 h-3.5"></i>
                              </button>

                              <button type="button"
                                      title="View details"
                                      onclick='openViewModal(<?= json_encode($studentAdmission) ?>, <?= json_encode($studentName) ?>, <?= json_encode($studentEmail) ?>, <?= json_encode($studentMob) ?>, <?= json_encode($studentAddr) ?>, <?= json_encode($studentUser) ?>)'
                                      class="w-8 h-8 rounded-full bg-purple-50 hover:bg-[#4A154B]
                                             flex items-center justify-center text-[#4A154B] hover:text-white
                                             transition-all duration-200 hover:scale-110">
                                <i data-lucide="eye" class="w-3.5 h-3.5"></i>
                              </button>

                              <!-- Set Password (replaces Reset Password) -->
                              <button type="button"
                                      title="Set password"
                                      onclick='openSetPasswordModal(<?= $studentId ?>, <?= json_encode($studentName) ?>)'
                                      class="w-8 h-8 rounded-full bg-purple-50 hover:bg-[#4A154B]
                                             flex items-center justify-center text-[#4A154B] hover:text-white
                                             transition-all duration-200 hover:scale-110">
                                <i data-lucide="lock" class="w-3.5 h-3.5"></i>
                              </button>

                              <button type="button"
                                      title="Delete student"
                                      onclick='confirmDeleteStudent(<?= $studentId ?>, <?= json_encode($studentName) ?>)'
                                      class="w-8 h-8 rounded-full bg-purple-50 hover:bg-red-500
                                             flex items-center justify-center text-[#4A154B] hover:text-white
                                             transition-all duration-200 hover:scale-110">
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
            </form>

            <?php if (!empty($students)): ?>
              <div class="px-6 py-4 bg-slate-50/50 border-t border-slate-200 flex flex-col sm:flex-row items-center justify-between gap-4">
                <p class="text-sm text-slate-600" id="tableInfo">
                  Showing <span class="font-semibold text-slate-900">1</span> to
                  <span class="font-semibold text-slate-900"><?= count($students) ?></span> of
                  <span class="font-semibold text-slate-900"><?= count($students) ?></span> entries
                </p>
                <div class="flex items-center space-x-2">
                  <button type="button" class="px-4 py-2 rounded-lg text-sm font-medium text-slate-500 hover:bg-slate-100 disabled:opacity-40 disabled:cursor-not-allowed transition-colors" disabled>Previous</button>
                  <span class="inline-flex items-center justify-center w-9 h-9 rounded-lg bg-[#4A154B] text-white text-sm font-bold shadow-md">1</span>
                  <button type="button" class="px-4 py-2 rounded-lg text-sm font-medium text-slate-500 hover:bg-slate-100 disabled:opacity-40 disabled:cursor-not-allowed transition-colors" disabled>Next</button>
                </div>
              </div>
            <?php endif; ?>

          </div>
        </div>

      </main>

      <footer class="bg-gradient-to-r from-purple-200 via-pink-100 to-purple-200 border-t border-purple-200/60 mt-auto">
        <div class="px-6 py-6">
          <div class="max-w-7xl mx-auto text-center">
            <p class="text-xs text-slate-700">
              Copyright &copy; <?= date('Y') ?>
              <span class="font-bold text-[#006837]">Rajagiri College of Social Sciences</span>.
              All rights reserved.
            </p>
            <p class="text-xs text-slate-700 mt-1">
              Powered by
              <span class="font-bold bg-gradient-to-r from-[#4A154B] to-[#E5097F] bg-clip-text text-transparent ml-1">Orell</span>
            </p>
          </div>
        </div>
      </footer>

    </div>
  </div>

  <!-- ============================================================ -->
  <!-- BULK IMPORT MODAL                                            -->
  <!-- ============================================================ -->
  <div id="importModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeImportModal()"></div>

    <div class="relative w-full max-w-lg bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]"></div>

      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
        <div class="flex items-center gap-3">
          <div class="w-9 h-9 rounded-lg bg-purple-50 flex items-center justify-center">
            <i data-lucide="file-up" class="w-5 h-5 text-[#8B1E7E]"></i>
          </div>
          <h3 class="text-lg font-bold text-slate-800">Import Students via Excel/CSV</h3>
        </div>
        <button type="button" onclick="closeImportModal()"
                class="w-8 h-8 rounded-lg hover:bg-slate-100 flex items-center justify-center text-slate-500 transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form id="importForm" method="POST" action="students.php?class_id=<?= (int) $classId ?>" enctype="multipart/form-data" class="p-6 space-y-5">
        <input type="hidden" name="action" value="import_students" />

        <div class="rounded-xl border border-purple-100 bg-purple-50/60 px-4 py-3">
          <p class="text-xs text-slate-700 leading-relaxed">
            <span class="font-bold text-[#8B1E7E]">Column order required:</span>
            <code class="text-[11px] bg-white px-1.5 py-0.5 rounded border border-purple-100">admission_number, name, email, contact_number, address, username, password</code>
          </p>
          <p class="text-xs text-slate-600 mt-1.5">
            All students will be enrolled into
            <span class="font-semibold text-[#8B1E7E]"><?= e($className) ?></span>.
          </p>
        </div>

        <div class="space-y-2">
          <label for="import_file" class="block text-sm font-semibold text-slate-700">
            Select File <span class="text-[#E5097F]">*</span>
          </label>
          <input type="file" name="import_file" id="import_file" required
                 accept=".csv, .xlsx, .xls"
                 class="w-full text-sm text-slate-700
                        file:mr-3 file:py-2.5 file:px-4
                        file:rounded-xl file:border-0
                        file:text-sm file:font-semibold
                        file:bg-[#4A154B] file:text-white
                        hover:file:bg-[#5A1B5C]
                        border-2 border-slate-200 rounded-xl
                        focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                        transition-all cursor-pointer" />
          <p class="text-xs text-slate-500 mt-1">
            Accepted formats: <span class="font-medium">.csv</span> (recommended).
            Excel files must be saved as CSV first.
          </p>
        </div>

        <div class="flex items-center justify-between rounded-xl bg-slate-50 border border-slate-200 px-4 py-3">
          <div class="flex items-start gap-3">
            <i data-lucide="file-spreadsheet" class="w-5 h-5 text-[#8B1E7E] mt-0.5 flex-shrink-0"></i>
            <div>
              <p class="text-sm font-semibold text-slate-700">Need the correct format?</p>
              <p class="text-xs text-slate-500">Download the sample CSV template with the exact columns.</p>
            </div>
          </div>
          <a href="students.php?class_id=<?= (int) $classId ?>&download_template=1"
             class="inline-flex items-center gap-2 px-4 py-2 rounded-xl
                    bg-white hover:bg-purple-50
                    border-2 border-purple-100 hover:border-[#8B1E7E]
                    text-[#8B1E7E] font-semibold text-sm
                    transition-all duration-200 hover:scale-105 active:scale-95 whitespace-nowrap">
            <i data-lucide="download" class="w-4 h-4"></i>
            <span>Sample Template</span>
          </a>
        </div>

        <div class="flex justify-center pt-2 gap-3">
          <button type="button" onclick="closeImportModal()"
                  class="px-6 py-3 rounded-xl font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 border border-slate-200 transition-all duration-200 active:scale-95">
            Cancel
          </button>
          <button type="submit"
                  class="px-8 py-3 rounded-xl bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A] hover:from-[#5A1C7A] hover:via-[#7B0E6E] hover:to-[#B42A6A] text-white font-bold shadow-lg shadow-purple-500/30 hover:shadow-purple-500/50 transition-all duration-300 hover:-translate-y-0.5 active:scale-95 flex items-center gap-2">
            <i data-lucide="upload" class="w-4 h-4"></i>
            <span>Import</span>
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- ADD / EDIT STUDENT MODAL -->
  <div id="studentModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeStudentModal()"></div>

    <div class="relative w-full max-w-2xl bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]"></div>

      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
        <h3 id="studentModalTitle" class="text-lg font-bold text-slate-800">Add Student</h3>
        <button type="button" onclick="closeStudentModal()"
                class="w-8 h-8 rounded-lg hover:bg-slate-100 flex items-center justify-center text-slate-500 transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form id="studentForm" method="POST" action="students.php?class_id=<?= (int) $classId ?>" class="p-6 space-y-4">
        <input type="hidden" name="action" id="formAction" value="add_student" />
        <input type="hidden" name="student_id" id="formStudentId" value="" />

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="space-y-2">
            <label for="admission_number" class="block text-sm font-semibold text-slate-700">Admission Number <span class="text-[#E5097F]">*</span></label>
            <input type="text" name="admission_number" id="admission_number" required placeholder="e.g. RCSS2025001"
                   class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl bg-white text-slate-800 font-medium placeholder-slate-400
                          focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                          hover:border-[#4A154B]/40 transition-all" />
          </div>

          <div class="space-y-2">
            <label for="name" class="block text-sm font-semibold text-slate-700">Full Name <span class="text-[#E5097F]">*</span></label>
            <input type="text" name="name" id="name" required placeholder="e.g. John Doe"
                   class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl bg-white text-slate-800 font-medium placeholder-slate-400
                          focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                          hover:border-[#4A154B]/40 transition-all" />
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="space-y-2">
            <label for="email" class="block text-sm font-semibold text-slate-700">Email <span class="text-[#E5097F]">*</span></label>
            <input type="email" name="email" id="email" required placeholder="e.g. student@rajagiri.edu"
                   class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl bg-white text-slate-800 font-medium placeholder-slate-400
                          focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                          hover:border-[#4A154B]/40 transition-all" />
          </div>

          <div class="space-y-2">
            <label for="contact_number" class="block text-sm font-semibold text-slate-700">Mobile Number <span class="text-[#E5097F]">*</span></label>
            <input type="tel" name="contact_number" id="contact_number" required
                   inputmode="numeric" pattern="[0-9]{10}" minlength="10" maxlength="10"
                   title="Please enter exactly 10 digits" placeholder="e.g. 9876543210"
                   class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl bg-white text-slate-800 font-medium placeholder-slate-400
                          focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                          hover:border-[#4A154B]/40 transition-all" />
            <p class="text-[11px] text-slate-500 mt-1">Enter exactly 10 digits (numbers only).</p>
          </div>
        </div>

        <div class="space-y-2">
          <label for="address" class="block text-sm font-semibold text-slate-700">Address</label>
          <textarea name="address" id="address" rows="2" placeholder="e.g. Kochi, Kerala"
                    class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl bg-white text-slate-800 font-medium placeholder-slate-400 resize-none
                           focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                           hover:border-[#4A154B]/40 transition-all"></textarea>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="space-y-2">
            <label for="username" class="block text-sm font-semibold text-slate-700">Username <span class="text-[#E5097F]">*</span></label>
            <input type="text" name="username" id="username" required placeholder="e.g. johndoe"
                   class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl bg-white text-slate-800 font-medium placeholder-slate-400
                          focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                          hover:border-[#4A154B]/40 transition-all" />
          </div>

          <div class="space-y-2" id="passwordFieldWrapper">
            <label for="password" class="block text-sm font-semibold text-slate-700">Password <span class="text-[#E5097F]">*</span></label>
            <div class="relative">
              <input type="password" name="password" id="password" placeholder="e.g. Student@123"
                     class="w-full pl-4 pr-12 py-3 border-2 border-slate-200 rounded-xl bg-white text-slate-800 font-medium placeholder-slate-400
                            focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                            hover:border-[#4A154B]/40 transition-all" />
              <button type="button" id="togglePasswordBtn" title="Show password" aria-label="Show password" tabindex="-1"
                      class="absolute right-2 top-1/2 -translate-y-1/2 w-9 h-9 rounded-lg flex items-center justify-center
                             text-slate-400 hover:text-[#8B1E7E] hover:bg-purple-50 transition-all duration-200 active:scale-95">
                <i data-lucide="eye" id="togglePasswordIcon" class="w-5 h-5"></i>
              </button>
            </div>
          </div>
        </div>

        <div class="flex justify-center pt-3 gap-3">
          <button type="button" onclick="closeStudentModal()"
                  class="px-6 py-3 rounded-xl font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 border border-slate-200 transition-all duration-200 active:scale-95">
            Cancel
          </button>
          <button type="submit"
                  class="px-8 py-3 rounded-xl bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A] hover:from-[#5A1C7A] hover:via-[#7B0E6E] hover:to-[#B42A6A] text-white font-bold shadow-lg shadow-purple-500/30 hover:shadow-purple-500/50 transition-all duration-300 hover:-translate-y-0.5 active:scale-95">
            Save
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- VIEW STUDENT MODAL -->
  <div id="viewModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeViewModal()"></div>

    <div class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]"></div>

      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
        <h3 class="text-lg font-bold text-slate-800">Student Details</h3>
        <button type="button" onclick="closeViewModal()"
                class="w-8 h-8 rounded-lg hover:bg-slate-100 flex items-center justify-center text-slate-500 transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <div class="p-6 space-y-4">
        <div class="flex items-center space-x-4 pb-4 border-b border-slate-100">
          <div class="w-14 h-14 rounded-full bg-gradient-to-br from-[#4A154B] to-[#8B1E7E] flex items-center justify-center text-white shadow-md">
            <i data-lucide="user" class="w-7 h-7"></i>
          </div>
          <div class="min-w-0">
            <p id="viewName" class="text-base font-bold text-slate-800 truncate">—</p>
            <p id="viewUsername" class="text-xs text-slate-500 truncate">@—</p>
          </div>
        </div>

        <div class="space-y-3">
          <div class="flex items-start gap-3">
            <i data-lucide="hash" class="w-4 h-4 text-[#8B1E7E] mt-1 flex-shrink-0"></i>
            <div class="min-w-0">
              <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Admission Number</p>
              <p id="viewAdmission" class="text-sm text-slate-700 break-all">—</p>
            </div>
          </div>
          <div class="flex items-start gap-3">
            <i data-lucide="mail" class="w-4 h-4 text-[#8B1E7E] mt-1 flex-shrink-0"></i>
            <div class="min-w-0">
              <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Email</p>
              <p id="viewEmail" class="text-sm text-slate-700 break-all">—</p>
            </div>
          </div>
          <div class="flex items-start gap-3">
            <i data-lucide="phone" class="w-4 h-4 text-[#8B1E7E] mt-1 flex-shrink-0"></i>
            <div class="min-w-0">
              <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Mobile Number</p>
              <p id="viewMobile" class="text-sm text-slate-700">—</p>
            </div>
          </div>
          <div class="flex items-start gap-3">
            <i data-lucide="map-pin" class="w-4 h-4 text-[#8B1E7E] mt-1 flex-shrink-0"></i>
            <div class="min-w-0">
              <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Address</p>
              <p id="viewAddress" class="text-sm text-slate-700 break-words">—</p>
            </div>
          </div>
        </div>
      </div>

      <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex justify-end">
        <button type="button" onclick="closeViewModal()"
                class="px-5 py-2.5 rounded-xl font-semibold text-slate-700 bg-white hover:bg-slate-100 border border-slate-200 transition-all duration-200 active:scale-95">
          Close
        </button>
      </div>
    </div>
  </div>

  <!-- DELETE CONFIRMATION MODAL -->
  <div id="deleteConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeDeleteModal()"></div>

    <div id="deleteConfirmPanel"
         class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]"></div>

      <div class="px-6 pt-6 pb-2 flex flex-col items-center text-center">
        <div class="w-16 h-16 rounded-full flex items-center justify-center mb-4 bg-gradient-to-br from-red-100 to-pink-100 ring-4 ring-red-50">
          <i data-lucide="trash-2" class="w-8 h-8 text-red-500"></i>
        </div>

        <h3 id="deleteModalTitle" class="text-xl font-bold text-slate-800 mb-2">Delete Student?</h3>

        <p id="deleteModalDescription" class="text-sm text-slate-500 leading-relaxed">
          You are about to permanently delete <span class="font-bold text-[#8B1E7E] break-words">this student</span>.
        </p>

        <p class="text-xs text-red-500 font-medium mt-3 flex items-center gap-1.5">
          <i data-lucide="alert-triangle" class="w-3.5 h-3.5"></i>
          This action cannot be undone.
        </p>
      </div>

      <div class="px-6 py-5 mt-2 flex flex-col-reverse sm:flex-row gap-3">
        <button type="button" onclick="closeDeleteModal()"
                class="flex-1 px-5 py-3 rounded-xl font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 border border-slate-200 transition-all duration-200 active:scale-95">
          Cancel
        </button>
        <button type="button" id="confirmDeleteBtn"
                class="flex-1 px-5 py-3 rounded-xl font-bold text-white bg-gradient-to-r from-red-500 via-red-600 to-rose-600 hover:from-red-600 hover:via-red-700 hover:to-rose-700 shadow-lg shadow-red-500/30 hover:shadow-red-500/50 transition-all duration-300 hover:-translate-y-0.5 active:scale-95 flex items-center justify-center gap-2">
          <i data-lucide="trash-2" class="w-4 h-4"></i>
          <span>Delete</span>
        </button>
      </div>
    </div>
  </div>

  <!-- ============================================================= -->
  <!-- SET PASSWORD MODAL (replaces Reset Password)                  -->
  <!-- ============================================================= -->
  <div id="setPasswordModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeSetPasswordModal()"></div>

    <div id="setPasswordPanel"
         class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">

      <div class="h-1.5 w-full bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]"></div>

      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
        <div class="flex items-center gap-3">
          <div class="w-9 h-9 rounded-lg bg-purple-50 flex items-center justify-center">
            <i data-lucide="lock" class="w-5 h-5 text-[#8B1E7E]"></i>
          </div>
          <h3 class="text-lg font-bold text-slate-800">Set Password</h3>
        </div>
        <button type="button" onclick="closeSetPasswordModal()"
                class="w-8 h-8 rounded-lg hover:bg-slate-100 flex items-center justify-center text-slate-500 transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form id="setPasswordForm" method="POST" action="students.php?class_id=<?= (int) $classId ?>" class="p-6 space-y-4">
        <input type="hidden" name="action" value="set_password" />
        <input type="hidden" name="student_id" id="setPasswordStudentId" value="" />

        <p class="text-sm text-slate-600">
          Set a new password for
          <span id="setPasswordStudentName" class="font-bold text-[#8B1E7E]">this student</span>.
        </p>

        <div class="space-y-2">
          <label for="new_password" class="block text-sm font-semibold text-slate-700">
            New Password <span class="text-[#E5097F]">*</span>
          </label>
          <div class="relative">
            <input type="password" name="new_password" id="new_password" required minlength="6"
                   placeholder="Enter new password"
                   class="w-full pl-4 pr-12 py-3 border-2 border-slate-200 rounded-xl bg-white text-slate-800 font-medium placeholder-slate-400
                          focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                          hover:border-[#4A154B]/40 transition-all" />
            <button type="button" id="toggleNewPasswordBtn" title="Show password" aria-label="Show password" tabindex="-1"
                    class="absolute right-2 top-1/2 -translate-y-1/2 w-9 h-9 rounded-lg flex items-center justify-center
                           text-slate-400 hover:text-[#8B1E7E] hover:bg-purple-50 transition-all duration-200 active:scale-95">
              <i data-lucide="eye" id="toggleNewPasswordIcon" class="w-5 h-5"></i>
            </button>
          </div>
          <p class="text-[11px] text-slate-500 mt-1">Minimum 6 characters.</p>
        </div>

        <div class="space-y-2">
          <label for="confirm_password" class="block text-sm font-semibold text-slate-700">
            Confirm Password <span class="text-[#E5097F]">*</span>
          </label>
          <div class="relative">
            <input type="password" name="confirm_password" id="confirm_password" required minlength="6"
                   placeholder="Re-enter new password"
                   class="w-full pl-4 pr-12 py-3 border-2 border-slate-200 rounded-xl bg-white text-slate-800 font-medium placeholder-slate-400
                          focus:outline-none focus:border-[#4A154B] focus:ring-4 focus:ring-[#4A154B]/10
                          hover:border-[#4A154B]/40 transition-all" />
            <button type="button" id="toggleConfirmPasswordBtn" title="Show password" aria-label="Show password" tabindex="-1"
                    class="absolute right-2 top-1/2 -translate-y-1/2 w-9 h-9 rounded-lg flex items-center justify-center
                           text-slate-400 hover:text-[#8B1E7E] hover:bg-purple-50 transition-all duration-200 active:scale-95">
              <i data-lucide="eye" id="toggleConfirmPasswordIcon" class="w-5 h-5"></i>
            </button>
          </div>
          <p id="setPasswordMatchHint" class="text-[11px] mt-1 hidden"></p>
        </div>

        <div class="flex justify-center pt-3 gap-3">
          <button type="button" onclick="closeSetPasswordModal()"
                  class="px-6 py-3 rounded-xl font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 border border-slate-200 transition-all duration-200 active:scale-95">
            Cancel
          </button>
          <button type="submit"
                  class="px-8 py-3 rounded-xl bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A] hover:from-[#5A1C7A] hover:via-[#7B0E6E] hover:to-[#B42A6A] text-white font-bold shadow-lg shadow-purple-500/30 hover:shadow-purple-500/50 transition-all duration-300 hover:-translate-y-0.5 active:scale-95 flex items-center gap-2">
            <i data-lucide="save" class="w-4 h-4"></i>
            <span>Save Password</span>
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- ============================================================= -->
  <!-- LOGOUT CONFIRMATION MODAL                                     -->
  <!-- ============================================================= -->
  <div id="logoutConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeLogoutModal()"></div>

    <div id="logoutConfirmPanel" class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-gradient-to-r from-[#6A2C8A] via-[#8B1E7E] to-[#C43A7A]"></div>

      <div class="px-6 pt-6 pb-2 flex flex-col items-center text-center">
        <div class="w-16 h-16 rounded-full flex items-center justify-center mb-4 bg-gradient-to-br from-red-100 to-pink-100 ring-4 ring-red-50">
          <i data-lucide="log-out" class="w-8 h-8 text-red-500"></i>
        </div>

        <h3 class="text-xl font-bold text-slate-800 mb-2">Log Out?</h3>

        <p class="text-sm text-slate-500 leading-relaxed">
          You are about to log out of <span class="font-bold text-[#8B1E7E] break-words"><?= e($displayName) ?></span>.
          Any unsaved changes will be lost.
        </p>

        <p class="text-xs text-slate-400 font-medium mt-3 flex items-center gap-1.5">
          <i data-lucide="info" class="w-3.5 h-3.5"></i> You can log back in anytime.
        </p>
      </div>

      <div class="px-6 py-5 mt-2 flex flex-col-reverse sm:flex-row gap-3">
        <button type="button" onclick="closeLogoutModal()"
                class="flex-1 px-5 py-3 rounded-xl font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 border border-slate-200 transition-all duration-200 active:scale-95">
          Cancel
        </button>
        <button type="button" id="confirmLogoutBtn"
                class="flex-1 px-5 py-3 rounded-xl font-bold text-white bg-gradient-to-r from-red-500 via-red-600 to-rose-600 hover:from-red-600 hover:via-red-700 hover:to-rose-700 shadow-lg shadow-red-500/30 hover:shadow-red-500/50 transition-all duration-300 hover:-translate-y-0.5 active:scale-95 flex items-center justify-center gap-2">
          <i data-lucide="log-out" class="w-4 h-4"></i>
          <span>Log Out</span>
        </button>
      </div>
    </div>
  </div>

  <!-- HIDDEN FORMS -->
  <form id="deleteForm" method="POST" action="students.php?class_id=<?= (int) $classId ?>" class="hidden">
    <input type="hidden" name="action" value="delete_student" />
    <input type="hidden" name="student_id" id="deleteStudentId" value="" />
  </form>

  <script>
    document.addEventListener('DOMContentLoaded', function () {

      if (typeof lucide !== 'undefined') {
        lucide.createIcons();
      }

      // ---- Auto-dismiss flash messages after 3 seconds ----
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

      // ---- Admin profile dropdown ----
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

      // ---- Import Modal ----
      const importModal = document.getElementById('importModal');
      const importForm  = document.getElementById('importForm');

      window.openImportModal = function () {
        importModal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        setTimeout(function () { const f = document.getElementById('import_file'); if (f) f.focus(); }, 80);
        if (typeof lucide !== 'undefined') lucide.createIcons();
      };
      window.closeImportModal = function () {
        importModal.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
        if (importForm) importForm.reset();
      };

      // ---- Student Add/Edit Modal ----
      const studentModal       = document.getElementById('studentModal');
      const studentModalTitle  = document.getElementById('studentModalTitle');
      const studentForm        = document.getElementById('studentForm');
      const formAction         = document.getElementById('formAction');
      const formStudentId      = document.getElementById('formStudentId');
      const admissionInput     = document.getElementById('admission_number');
      const nameInput          = document.getElementById('name');
      const emailInput         = document.getElementById('email');
      const mobileInput        = document.getElementById('contact_number');
      const addressInput       = document.getElementById('address');
      const usernameInput      = document.getElementById('username');
      const passwordInput      = document.getElementById('password');
      const passwordWrapper    = document.getElementById('passwordFieldWrapper');
      const togglePasswordBtn  = document.getElementById('togglePasswordBtn');
      const togglePasswordIcon = document.getElementById('togglePasswordIcon');

      // Mobile number: digits only
      if (mobileInput) {
        mobileInput.addEventListener('input', function () {
          this.value = this.value.replace(/\D/g, '').slice(0, 10);
        });
        mobileInput.addEventListener('keypress', function (e) {
          const charCode = e.which ? e.which : e.keyCode;
          if (charCode < 48 || charCode > 57) {
            if (![8, 9, 13, 27, 37, 38, 39, 40, 46].includes(charCode)) e.preventDefault();
          }
        });
      }

      // Password show/hide toggle helper
      function wireToggle(btnId, iconId, inputEl) {
        const btn = document.getElementById(btnId);
        const ic  = document.getElementById(iconId);
        if (!btn || !ic || !inputEl) return;

        btn.addEventListener('click', function () {
          const isHidden = (inputEl.type === 'password');
          inputEl.type = isHidden ? 'text' : 'password';
          ic.setAttribute('data-lucide', isHidden ? 'eye-off' : 'eye');
          btn.setAttribute('title', isHidden ? 'Hide password' : 'Show password');
          btn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
          if (typeof lucide !== 'undefined') lucide.createIcons();
          inputEl.focus();
        });
      }
      wireToggle('togglePasswordBtn', 'togglePasswordIcon', passwordInput);

      function resetPasswordToggleState() {
        if (!passwordInput || !togglePasswordBtn || !togglePasswordIcon) return;
        passwordInput.type = 'password';
        togglePasswordBtn.setAttribute('title', 'Show password');
        togglePasswordBtn.setAttribute('aria-label', 'Show password');
        togglePasswordIcon.setAttribute('data-lucide', 'eye');
        if (typeof lucide !== 'undefined') lucide.createIcons();
      }

      window.openStudentModal = function (mode, studentId, admission, name, email, mobile, address, username) {
        studentModal.classList.remove('hidden');

        if (mode === 'edit') {
          studentModalTitle.textContent = 'Edit Student';
          formAction.value     = 'edit_student';
          formStudentId.value  = studentId || '';
          admissionInput.value = admission || '';
          nameInput.value      = name      || '';
          emailInput.value     = email     || '';
          mobileInput.value    = mobile    || '';
          addressInput.value   = address   || '';
          usernameInput.value  = username  || '';

          if (passwordWrapper) passwordWrapper.style.display = 'none';
          if (passwordInput) { passwordInput.removeAttribute('required'); passwordInput.value = ''; }
        } else {
          studentModalTitle.textContent = 'Add Student';
          formAction.value    = 'add_student';
          formStudentId.value = '';
          studentForm.reset();

          if (passwordWrapper) passwordWrapper.style.display = '';
          if (passwordInput) passwordInput.setAttribute('required', 'required');
        }

        resetPasswordToggleState();
        setTimeout(() => admissionInput && admissionInput.focus(), 50);
        if (typeof lucide !== 'undefined') lucide.createIcons();
      };

      window.closeStudentModal = function () {
        studentModal.classList.add('hidden');
        studentForm.reset();
        formAction.value    = 'add_student';
        formStudentId.value = '';
        resetPasswordToggleState();
      };

      // ---- View Modal ----
      const viewModal = document.getElementById('viewModal');

      window.openViewModal = function (admission, name, email, mobile, address, username) {
        document.getElementById('viewAdmission').textContent = admission || '—';
        document.getElementById('viewName').textContent      = name      || '—';
        document.getElementById('viewUsername').textContent  = '@' + (username || '—');
        document.getElementById('viewEmail').textContent     = email     || '—';
        document.getElementById('viewMobile').textContent    = mobile    || '—';
        document.getElementById('viewAddress').textContent   = address   || '—';

        viewModal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        if (typeof lucide !== 'undefined') lucide.createIcons();
      };

      window.closeViewModal = function () {
        viewModal.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
      };

      // ---- Delete Confirmation Modal ----
      const deleteConfirmModal     = document.getElementById('deleteConfirmModal');
      const deleteConfirmPanel     = document.getElementById('deleteConfirmPanel');
      const deleteModalTitle       = document.getElementById('deleteModalTitle');
      const deleteModalDescription = document.getElementById('deleteModalDescription');
      const confirmDeleteBtn       = document.getElementById('confirmDeleteBtn');

      let pendingDeleteId = null;
      let bulkDeleteMode  = false;

      window.confirmDeleteStudent = function (studentId, studentName) {
        bulkDeleteMode  = false;
        pendingDeleteId = studentId;

        deleteModalTitle.textContent = 'Delete Student?';
        deleteModalDescription.innerHTML = 'You are about to permanently delete <span class="font-bold text-[#8B1E7E] break-words">"' + studentName + '"</span>.';
        confirmDeleteBtn.querySelector('span').textContent = 'Delete';

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
        bulkDeleteMode  = false;
      };

      if (confirmDeleteBtn) {
        confirmDeleteBtn.addEventListener('click', function () {
          if (bulkDeleteMode) {
            const bulkForm = document.getElementById('bulkForm');
            if (bulkForm) bulkForm.submit();
            return;
          }
          if (pendingDeleteId === null || pendingDeleteId === undefined) {
            window.closeDeleteModal();
            return;
          }
          const delIdInput = document.getElementById('deleteStudentId');
          const delForm    = document.getElementById('deleteForm');
          if (delIdInput && delForm) {
            delIdInput.value = String(pendingDeleteId);
            delForm.submit();
          } else {
            window.closeDeleteModal();
          }
        });
      }

      // ---- Bulk Delete ----
      const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
      if (bulkDeleteBtn) {
        bulkDeleteBtn.addEventListener('click', function () {
          const checked = document.querySelectorAll('.student-checkbox:checked');
          if (checked.length === 0) {
            alert('Please select at least one student to delete.');
            return;
          }

          bulkDeleteMode  = true;
          pendingDeleteId = null;

          deleteModalTitle.textContent = 'Delete Selected Students?';
          deleteModalDescription.innerHTML = 'You are about to permanently delete <span class="font-bold text-[#8B1E7E]">' + checked.length + ' student(s)</span>.';
          confirmDeleteBtn.querySelector('span').textContent = 'Delete All';

          deleteConfirmModal.classList.remove('hidden');
          document.body.classList.add('overflow-hidden');

          if (deleteConfirmPanel) {
            deleteConfirmPanel.classList.remove('animate-confirm-shake');
            void deleteConfirmPanel.offsetWidth;
            deleteConfirmPanel.classList.add('animate-confirm-shake');
          }
          setTimeout(function () { if (confirmDeleteBtn) confirmDeleteBtn.focus(); }, 80);
          if (typeof lucide !== 'undefined') lucide.createIcons();
        });
      }

      // ---- Select All ----
      (function () {
        const selectAll  = document.getElementById('selectAllCheckbox');
        const checkboxes = document.querySelectorAll('.student-checkbox');
        if (!selectAll) return;

        selectAll.addEventListener('change', function () {
          checkboxes.forEach(function (cb) { cb.checked = selectAll.checked; });
        });
        checkboxes.forEach(function (cb) {
          cb.addEventListener('change', function () {
            const allChecked = Array.from(checkboxes).every(c => c.checked);
            const anyChecked = Array.from(checkboxes).some(c => c.checked);
            selectAll.checked = allChecked;
            selectAll.indeterminate = anyChecked && !allChecked;
          });
        });
      })();

      // ---- Set Password Modal ----
      const setPasswordModal       = document.getElementById('setPasswordModal');
      const setPasswordPanel       = document.getElementById('setPasswordPanel');
      const setPasswordForm        = document.getElementById('setPasswordForm');
      const setPasswordStudentId   = document.getElementById('setPasswordStudentId');
      const setPasswordStudentName = document.getElementById('setPasswordStudentName');
      const newPasswordInput       = document.getElementById('new_password');
      const confirmPasswordInput   = document.getElementById('confirm_password');
      const matchHint              = document.getElementById('setPasswordMatchHint');

      window.openSetPasswordModal = function (studentId, studentName) {
        setPasswordStudentId.value = String(studentId);
        setPasswordStudentName.textContent = '"' + (studentName || '') + '"';
        if (setPasswordForm) setPasswordForm.reset();

        // Reset both toggles
        if (newPasswordInput) newPasswordInput.type = 'password';
        if (confirmPasswordInput) confirmPasswordInput.type = 'password';
        const ni = document.getElementById('toggleNewPasswordIcon');
        const ci = document.getElementById('toggleConfirmPasswordIcon');
        if (ni) ni.setAttribute('data-lucide', 'eye');
        if (ci) ci.setAttribute('data-lucide', 'eye');

        if (matchHint) { matchHint.classList.add('hidden'); matchHint.textContent = ''; }

        setPasswordModal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');

        if (setPasswordPanel) {
          setPasswordPanel.classList.remove('animate-confirm-shake');
          void setPasswordPanel.offsetWidth;
          setPasswordPanel.classList.add('animate-confirm-shake');
        }

        setTimeout(function () { if (newPasswordInput) newPasswordInput.focus(); }, 80);
        if (typeof lucide !== 'undefined') lucide.createIcons();
      };

      window.closeSetPasswordModal = function () {
        setPasswordModal.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
        if (setPasswordForm) setPasswordForm.reset();
      };

      // Wire toggles for the Set Password form
      wireToggle('toggleNewPasswordBtn', 'toggleNewPasswordIcon', newPasswordInput);
      wireToggle('toggleConfirmPasswordBtn', 'toggleConfirmPasswordIcon', confirmPasswordInput);

      // Live match feedback for the Set Password form
      function updateMatchHint() {
        if (!matchHint || !newPasswordInput || !confirmPasswordInput) return;
        const a = newPasswordInput.value;
        const b = confirmPasswordInput.value;

        if (b === '') {
          matchHint.classList.add('hidden');
          matchHint.textContent = '';
          return;
        }
        matchHint.classList.remove('hidden');
        if (a === b) {
          matchHint.textContent = 'Passwords match ✓';
          matchHint.className = 'text-[11px] mt-1 text-emerald-600 font-medium';
        } else {
          matchHint.textContent = 'Passwords do not match';
          matchHint.className = 'text-[11px] mt-1 text-red-600 font-medium';
        }
      }
      if (newPasswordInput) newPasswordInput.addEventListener('input', updateMatchHint);
      if (confirmPasswordInput) confirmPasswordInput.addEventListener('input', updateMatchHint);

      // Block form submit if passwords don't match
      if (setPasswordForm) {
        setPasswordForm.addEventListener('submit', function (e) {
          const a = newPasswordInput ? newPasswordInput.value : '';
          const b = confirmPasswordInput ? confirmPasswordInput.value : '';
          if (a.length < 6) {
            e.preventDefault();
            alert('Password must be at least 6 characters long.');
            if (newPasswordInput) newPasswordInput.focus();
            return;
          }
          if (a !== b) {
            e.preventDefault();
            alert('Passwords do not match. Please re-enter.');
            if (confirmPasswordInput) confirmPasswordInput.focus();
            return;
          }
          const btn = setPasswordForm.querySelector('button[type="submit"]');
          if (btn) { btn.classList.add('opacity-50', 'pointer-events-none'); btn.innerHTML = '<span>Saving…</span>'; }
        });
      }

      // ---- Logout Confirmation Modal ----
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
            e.preventDefault();
            e.stopPropagation();
            window.openLogoutModal();
          });
        });

        if (confirmLogoutBtn) {
          confirmLogoutBtn.addEventListener('click', function () {
            confirmLogoutBtn.classList.add('opacity-50', 'pointer-events-none');
            window.location.href = LOGOUT_URL;
          });
        }
      })();

      // ---- Escape key: close any open modal ----
      document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;

        [importModal, studentModal, viewModal, deleteConfirmModal, setPasswordModal, logoutConfirmModal].forEach(function (m) {
          if (m && !m.classList.contains('hidden')) {
            if (m === importModal)         window.closeImportModal();
            if (m === studentModal)        window.closeStudentModal();
            if (m === viewModal)           window.closeViewModal();
            if (m === deleteConfirmModal)  window.closeDeleteModal();
            if (m === setPasswordModal)    window.closeSetPasswordModal();
            if (m === logoutConfirmModal)  window.closeLogoutModal();
          }
        });
      });

      // ---- Live Search ----
      (function () {
        const searchInput = document.getElementById('searchInput');
        const tableBody   = document.getElementById('studentsTableBody');
        if (!searchInput || !tableBody) return;

        searchInput.addEventListener('input', function () {
          const term = this.value.toLowerCase().trim();
          tableBody.querySelectorAll('tr').forEach(function (row) {
            row.style.display = (term === '' || row.textContent.toLowerCase().indexOf(term) !== -1) ? '' : 'none';
          });
        });
      })();
    });
  </script>

  <script src="../assets/js/index.js"></script>
</body>
</html>
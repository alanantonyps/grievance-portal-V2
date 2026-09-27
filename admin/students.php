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
 *   • Teal theme, matching cell_members.php
 *   • Fully responsive table — no horizontal scroll
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

    // -------- SET PASSWORD --------
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
       RESPONSIVE TABLE
       ============================================================ */
    .students-table {
      width: 100%;
      table-layout: fixed;
      border-collapse: collapse;
    }
    .students-table th,
    .students-table td {
      word-wrap: break-word;
      overflow-wrap: break-word;
      word-break: break-word;
      white-space: normal;
      vertical-align: middle;
    }
    .students-table thead th {
      font-size: 0.72rem;
      padding: 0.85rem 0.6rem;
      line-height: 1.3;
    }
    .students-table tbody td {
      font-size: 0.85rem;
      padding: 0.85rem 0.6rem;
      line-height: 1.45;
    }
    @media (max-width: 1440px) {
      .students-table thead th { font-size: 0.68rem; padding: 0.75rem 0.5rem; }
      .students-table tbody td { font-size: 0.82rem; padding: 0.75rem 0.5rem; }
    }
    @media (max-width: 1280px) {
      .students-table thead th { font-size: 0.64rem; padding: 0.65rem 0.45rem; }
      .students-table tbody td { font-size: 0.78rem; padding: 0.65rem 0.45rem; }
    }
    @media (max-width: 1024px) {
      .students-table thead th { font-size: 0.6rem;  padding: 0.55rem 0.35rem; }
      .students-table tbody td { font-size: 0.72rem; padding: 0.55rem 0.35rem; }
    }
    @media (max-width: 640px) {
      .students-table thead th { font-size: 0.56rem; padding: 0.5rem 0.3rem; }
      .students-table tbody td { font-size: 0.68rem; padding: 0.5rem 0.3rem; }
    }

    .col-sno       { width: 5%;  }
    .col-admission { width: 13%; }
    .col-name      { width: 14%; }
    .col-address   { width: 17%; }
    .col-email     { width: 19%; }
    .col-mobile    { width: 11%; }
    .col-select    { width: 9%;  }
    .col-actions   { width: 12%; }

    /* ============================================================
       ACTION BUTTONS
       ============================================================ */
    .action-btn {
      width: 2rem;
      height: 2rem;
      border-radius: 0.5rem;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      transition: background-color .2s, color .2s;
      cursor: pointer;
    }
    .action-btn i { width: 0.875rem; height: 0.875rem; }

    .action-btn.action-edit,
    .action-btn.action-view,
    .action-btn.action-lock { background: #EAF4F4; color: #006E74; }
    .action-btn.action-edit:hover,
    .action-btn.action-view:hover,
    .action-btn.action-lock:hover { background: #006E74; color: #ffffff; }

    .action-btn.action-delete { background: #fef2f2; color: #dc2626; }
    .action-btn.action-delete:hover { background: #dc2626; color: #ffffff; }

    @media (max-width: 1280px) {
      .action-btn { width: 1.75rem; height: 1.75rem; }
      .action-btn i { width: 0.8rem; height: 0.8rem; }
    }
    @media (max-width: 1024px) {
      .action-btn { width: 1.6rem; height: 1.6rem; border-radius: 0.4rem; }
      .action-btn i { width: 0.75rem; height: 0.75rem; }
    }

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
       ICON BUTTON SHEEN (Add / Import / Bulk delete)
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
                <p class="text-xs font-semibold text-teal-600 uppercase tracking-wider">Settings · Student</p>
              </div>
              <h1 class="text-2xl md:text-3xl font-bold text-teal-900 mb-3">
                <span class="heading-underline">Student</span>
              </h1>
              <nav class="flex flex-wrap items-center gap-2 text-sm text-teal-900/60">
                <a href="dashboard.php" class="inline-flex items-center gap-1 hover:text-teal-600 transition-colors">
                  <i data-lucide="layout-dashboard" class="w-4 h-4"></i>Dashboard
                </a>
                <span class="text-teal-900/30">/</span>
                <a href="settings.php" class="hover:text-teal-600 transition-colors">Settings</a>
                <span class="text-teal-900/30">/</span>
                <a href="classes.php" class="hover:text-teal-600 transition-colors">Course/semester</a>
                <span class="text-teal-900/30">/</span>
                <span class="text-teal-600 font-semibold">Student</span>
              </nav>
            </div>

            <div class="flex items-center gap-2 shrink-0">
              <button type="button"
                      onclick="openImportModal()"
                      title="Import Students via Excel/CSV"
                      class="btn-sheen inline-flex items-center justify-center w-11 h-11 rounded-lg bg-teal-600 hover:bg-teal-700
                             text-white shadow-sm hover:shadow-md
                             transition-all duration-200 hover:-translate-y-0.5 active:scale-95">
                <i data-lucide="file-up" class="w-5 h-5"></i>
              </button>

              <button type="button"
                      id="bulkDeleteBtn"
                      title="Delete selected"
                      class="btn-sheen inline-flex items-center justify-center w-11 h-11 rounded-lg bg-teal-600 hover:bg-teal-700
                             text-white shadow-sm hover:shadow-md
                             transition-all duration-200 hover:-translate-y-0.5 active:scale-95">
                <i data-lucide="trash-2" class="w-5 h-5"></i>
              </button>

              <button type="button"
                      onclick="openStudentModal('add')"
                      title="Add Student"
                      class="btn-sheen inline-flex items-center justify-center w-11 h-11 rounded-lg bg-teal-600 hover:bg-teal-700
                             text-white shadow-sm hover:shadow-md
                             transition-all duration-200 hover:-translate-y-0.5 active:scale-95">
                <i data-lucide="plus" class="w-5 h-5"></i>
              </button>
            </div>
          </div>
        </div>

        <div class="max-w-6xl mx-auto mb-6 text-center animate-fade-in-up" style="animation-delay: 40ms;">
          <h2 class="text-lg md:text-xl font-bold text-teal-900">
            Class Name : <span class="text-teal-600"><?= e($className) ?></span>
          </h2>
          <?php if ($courseName !== ''): ?>
            <p class="text-sm text-teal-900/60 mt-0.5"><?= e($courseName) ?></p>
          <?php endif; ?>
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

        <?php if (!empty($importSkippedRows)): ?>
          <div class="max-w-6xl mx-auto mb-6 rounded-xl border-2 border-amber-200 bg-amber-50 px-4 py-3 animate-fade-in-up">
            <div class="flex items-start gap-2 mb-2">
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
                <input type="text" id="searchInput" placeholder="Search..." autocomplete="off" class="w-full pl-10 pr-4 py-2 border-2 border-teal-100 rounded-lg text-sm bg-white text-teal-900 placeholder-teal-900/40 focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10 hover:border-teal-200 transition-all" />
              </div>
            </div>
          </div>
        </div>

        <div class="max-w-6xl mx-auto animate-fade-in-up" style="animation-delay: 120ms;">
          <div class="bg-white rounded-2xl border-2 border-teal-100 overflow-hidden">

            <form id="bulkForm" method="POST" action="students.php?class_id=<?= (int) $classId ?>">
              <input type="hidden" name="action" value="bulk_delete_students" />

              <table class="students-table" id="studentsTable">
                <thead>
                  <tr class="bg-teal-600 text-white">
                    <th class="col-sno       text-left font-bold uppercase tracking-wider">Sl.No.</th>
                    <th class="col-admission text-left font-bold uppercase tracking-wider">Admission No.</th>
                    <th class="col-name      text-left font-bold uppercase tracking-wider">Name</th>
                    <th class="col-address   text-left font-bold uppercase tracking-wider">Address</th>
                    <th class="col-email     text-left font-bold uppercase tracking-wider">Email</th>
                    <th class="col-mobile    text-left font-bold uppercase tracking-wider">Mobile Number</th>
                    <th class="col-select    text-center font-bold uppercase tracking-wider">
                      <label class="inline-flex items-center justify-center gap-1.5 cursor-pointer">
                        <input type="checkbox" id="selectAllCheckbox"
                               class="w-4 h-4 rounded border-teal-300 text-teal-600 focus:ring-teal-600/30 cursor-pointer" />
                        <span>Select All</span>
                      </label>
                    </th>
                    <th class="col-actions   text-center font-bold uppercase tracking-wider">Actions</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-teal-100" id="studentsTableBody">

                  <?php if (empty($students)): ?>
                    <tr>
                      <td colspan="8" class="px-6 py-16 text-center text-teal-900/60">
                        <div class="flex flex-col items-center justify-center">
                          <div class="w-16 h-16 bg-teal-50 rounded-2xl flex items-center justify-center mb-4">
                            <i data-lucide="users" class="w-8 h-8 text-teal-600"></i>
                          </div>
                          <p class="text-lg font-semibold text-teal-900">No students yet</p>
                          <p class="text-sm text-teal-900/60 mt-1 mb-4">
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
                      <tr class="hover:bg-teal-50/60 transition-colors">
                        <td class="col-sno font-medium text-teal-900/70"><?= $index + 1 ?></td>
                        <td class="col-admission font-semibold text-teal-700 break-words"><?= e($studentAdmission !== '' ? $studentAdmission : '—') ?></td>
                        <td class="col-name text-teal-900 font-medium break-words"><?= e($studentName) ?></td>
                        <td class="col-address text-teal-900/80 break-words"><?= e($studentAddr !== '' ? $studentAddr : '—') ?></td>
                        <td class="col-email text-teal-900/80 break-words"><?= e($studentEmail) ?></td>
                        <td class="col-mobile text-teal-900/70 break-words"><?= e($studentMob !== '' ? $studentMob : '—') ?></td>
                        <td class="col-select text-center">
                          <input type="checkbox"
                                 name="student_ids[]"
                                 value="<?= $studentId ?>"
                                 class="student-checkbox w-4 h-4 rounded border-teal-300 text-teal-600 focus:ring-teal-600/30 cursor-pointer" />
                        </td>
                        <td class="col-actions">
                          <div class="flex items-center justify-center gap-1.5">

                            <button type="button"
                                    title="Edit student"
                                    onclick='openStudentModal("edit", <?= $studentId ?>, <?= json_encode($studentAdmission) ?>, <?= json_encode($studentName) ?>, <?= json_encode($studentEmail) ?>, <?= json_encode($studentMob) ?>, <?= json_encode($studentAddr) ?>, <?= json_encode($studentUser) ?>)'
                                    class="action-btn action-edit">
                              <i data-lucide="pencil"></i>
                            </button>

                            <button type="button"
                                    title="View details"
                                    onclick='openViewModal(<?= json_encode($studentAdmission) ?>, <?= json_encode($studentName) ?>, <?= json_encode($studentEmail) ?>, <?= json_encode($studentMob) ?>, <?= json_encode($studentAddr) ?>, <?= json_encode($studentUser) ?>)'
                                    class="action-btn action-view">
                              <i data-lucide="eye"></i>
                            </button>

                            <button type="button"
                                    title="Set password"
                                    onclick='openSetPasswordModal(<?= $studentId ?>, <?= json_encode($studentName) ?>)'
                                    class="action-btn action-lock">
                              <i data-lucide="lock"></i>
                            </button>

                            <button type="button"
                                    title="Delete student"
                                    onclick='confirmDeleteStudent(<?= $studentId ?>, <?= json_encode($studentName) ?>)'
                                    class="action-btn action-delete">
                              <i data-lucide="trash-2"></i>
                            </button>

                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>

                  <?php endif; ?>

                </tbody>
              </table>
            </form>

            <?php if (!empty($students)): ?>
              <div class="px-6 py-4 bg-teal-50/50 border-t border-teal-100 flex flex-col sm:flex-row items-center justify-between gap-4">
                <p class="text-sm text-teal-900/70" id="tableInfo">
                  Showing <span class="font-semibold text-teal-900">1</span> to
                  <span class="font-semibold text-teal-900"><?= count($students) ?></span> of
                  <span class="font-semibold text-teal-900"><?= count($students) ?></span> entries
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
  <!-- BULK IMPORT MODAL                                            -->
  <!-- ============================================================ -->
  <div id="importModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeImportModal()"></div>

    <div class="relative w-full max-w-lg bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-teal-600"></div>

      <div class="flex items-center justify-between px-6 py-4 border-b border-teal-100">
        <div class="flex items-center gap-3">
          <div class="w-9 h-9 rounded-lg bg-teal-50 flex items-center justify-center">
            <i data-lucide="file-up" class="w-5 h-5 text-teal-600"></i>
          </div>
          <h3 class="text-lg font-bold text-teal-900">Import Students via Excel/CSV</h3>
        </div>
        <button type="button" onclick="closeImportModal()" class="w-8 h-8 rounded-lg hover:bg-teal-50 flex items-center justify-center text-teal-900/60 transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form id="importForm" method="POST" action="students.php?class_id=<?= (int) $classId ?>" enctype="multipart/form-data" class="p-6 space-y-5">
        <input type="hidden" name="action" value="import_students" />

        <div class="rounded-xl border border-teal-100 bg-teal-50/60 px-4 py-3">
          <p class="text-xs text-teal-900/80 leading-relaxed">
            <span class="font-bold text-teal-700">Column order required:</span>
            <code class="text-[11px] bg-white px-1.5 py-0.5 rounded border border-teal-100">admission_number, name, email, contact_number, address, username, password</code>
          </p>
          <p class="text-xs text-teal-900/70 mt-1.5">
            All students will be enrolled into
            <span class="font-semibold text-teal-700"><?= e($className) ?></span>.
          </p>
        </div>

        <div class="space-y-2">
          <label for="import_file" class="block text-sm font-semibold text-teal-900">
            Select File <span class="text-red-500">*</span>
          </label>
          <input type="file" name="import_file" id="import_file" required
                 accept=".csv, .xlsx, .xls"
                 class="w-full text-sm text-teal-900
                        file:mr-3 file:py-2.5 file:px-4
                        file:rounded-lg file:border-0
                        file:text-sm file:font-semibold
                        file:bg-teal-600 file:text-white
                        hover:file:bg-teal-700
                        border-2 border-teal-100 rounded-lg
                        focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10
                        transition-all cursor-pointer" />
          <p class="text-xs text-teal-900/60 mt-1">
            Accepted formats: <span class="font-medium">.csv</span> (recommended).
            Excel files must be saved as CSV first.
          </p>
        </div>

        <div class="flex items-center justify-between rounded-xl bg-teal-50/60 border border-teal-100 px-4 py-3 flex-wrap gap-2">
          <div class="flex items-start gap-3 min-w-0">
            <i data-lucide="file-spreadsheet" class="w-5 h-5 text-teal-600 mt-0.5 flex-shrink-0"></i>
            <div class="min-w-0">
              <p class="text-sm font-semibold text-teal-900">Need the correct format?</p>
              <p class="text-xs text-teal-900/60">Download the sample CSV template with the exact columns.</p>
            </div>
          </div>
          <a href="students.php?class_id=<?= (int) $classId ?>&download_template=1"
             class="inline-flex items-center gap-2 px-4 py-2 rounded-lg
                    bg-white hover:bg-teal-50
                    border-2 border-teal-100 hover:border-teal-600
                    text-teal-700 font-semibold text-sm
                    transition-all duration-200 hover:scale-105 active:scale-95 whitespace-nowrap">
            <i data-lucide="download" class="w-4 h-4"></i>
            <span>Sample Template</span>
          </a>
        </div>

        <div class="flex justify-center pt-2 gap-3">
          <button type="button" onclick="closeImportModal()"
                  class="px-6 py-3 rounded-lg font-semibold text-teal-900 bg-white border-2 border-teal-200 hover:border-teal-600 hover:bg-teal-50 transition-all duration-200">
            Cancel
          </button>
          <button type="submit"
                  class="px-8 py-3 rounded-lg bg-teal-600 hover:bg-teal-700 text-white font-semibold shadow-sm hover:shadow-md transition-all duration-200 cursor-pointer flex items-center gap-2">
            <i data-lucide="upload" class="w-4 h-4"></i>
            <span>Import</span>
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- ============================================================ -->
  <!-- ADD / EDIT STUDENT MODAL                                     -->
  <!-- ============================================================ -->
  <div id="studentModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeStudentModal()"></div>

    <div class="relative w-full max-w-2xl bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden max-h-[92vh] flex flex-col">
      <div class="h-1.5 w-full bg-teal-600"></div>

      <div class="flex items-center justify-between px-6 py-4 border-b border-teal-100">
        <h3 id="studentModalTitle" class="text-lg font-bold text-teal-900">Add Student</h3>
        <button type="button" onclick="closeStudentModal()" class="w-8 h-8 rounded-lg hover:bg-teal-50 flex items-center justify-center text-teal-900/60 transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form id="studentForm" method="POST" action="students.php?class_id=<?= (int) $classId ?>" class="p-6 space-y-4 overflow-y-auto flex-1">
        <input type="hidden" name="action" id="formAction" value="add_student" />
        <input type="hidden" name="student_id" id="formStudentId" value="" />

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="space-y-2">
            <label for="admission_number" class="block text-sm font-semibold text-teal-900">Admission Number<span class="text-red-500">*</span></label>
            <input type="text" name="admission_number" id="admission_number" required placeholder="e.g. RCSS2025001"
                   class="w-full px-4 py-3 border-2 border-teal-100 rounded-lg bg-teal-50/40 text-teal-900 font-medium text-sm placeholder-teal-900/40
                          focus:outline-none focus:border-teal-600 focus:bg-white focus:ring-4 focus:ring-teal-600/10
                          hover:border-teal-200 transition-all" />
          </div>

          <div class="space-y-2">
            <label for="name" class="block text-sm font-semibold text-teal-900">Full Name<span class="text-red-500">*</span></label>
            <input type="text" name="name" id="name" required placeholder="e.g. John Doe"
                   class="w-full px-4 py-3 border-2 border-teal-100 rounded-lg bg-teal-50/40 text-teal-900 font-medium text-sm placeholder-teal-900/40
                          focus:outline-none focus:border-teal-600 focus:bg-white focus:ring-4 focus:ring-teal-600/10
                          hover:border-teal-200 transition-all" />
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="space-y-2">
            <label for="email" class="block text-sm font-semibold text-teal-900">Email<span class="text-red-500">*</span></label>
            <input type="email" name="email" id="email" required placeholder="e.g. student@rajagiri.edu"
                   class="w-full px-4 py-3 border-2 border-teal-100 rounded-lg bg-teal-50/40 text-teal-900 font-medium text-sm placeholder-teal-900/40
                          focus:outline-none focus:border-teal-600 focus:bg-white focus:ring-4 focus:ring-teal-600/10
                          hover:border-teal-200 transition-all" />
          </div>

          <div class="space-y-2">
            <label for="contact_number" class="block text-sm font-semibold text-teal-900">Mobile Number<span class="text-red-500">*</span></label>
            <input type="tel" name="contact_number" id="contact_number" required
                   inputmode="numeric" pattern="[0-9]{10}" minlength="10" maxlength="10"
                   title="Please enter exactly 10 digits" placeholder="e.g. 9876543210"
                   class="w-full px-4 py-3 border-2 border-teal-100 rounded-lg bg-teal-50/40 text-teal-900 font-medium text-sm placeholder-teal-900/40
                          focus:outline-none focus:border-teal-600 focus:bg-white focus:ring-4 focus:ring-teal-600/10
                          hover:border-teal-200 transition-all" />
            <p class="text-[11px] text-teal-900/60 mt-1">Enter exactly 10 digits (numbers only).</p>
          </div>
        </div>

        <div class="space-y-2">
          <label for="address" class="block text-sm font-semibold text-teal-900">Address</label>
          <textarea name="address" id="address" rows="2" placeholder="e.g. Kochi, Kerala"
                    class="w-full px-4 py-3 border-2 border-teal-100 rounded-lg bg-teal-50/40 text-teal-900 font-medium text-sm placeholder-teal-900/40 resize-none
                           focus:outline-none focus:border-teal-600 focus:bg-white focus:ring-4 focus:ring-teal-600/10
                           hover:border-teal-200 transition-all"></textarea>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="space-y-2">
            <label for="username" class="block text-sm font-semibold text-teal-900">Username<span class="text-red-500">*</span></label>
            <input type="text" name="username" id="username" required placeholder="e.g. johndoe"
                   class="w-full px-4 py-3 border-2 border-teal-100 rounded-lg bg-teal-50/40 text-teal-900 font-medium text-sm placeholder-teal-900/40
                          focus:outline-none focus:border-teal-600 focus:bg-white focus:ring-4 focus:ring-teal-600/10
                          hover:border-teal-200 transition-all" />
          </div>

          <div class="space-y-2" id="passwordFieldWrapper">
            <label for="password" class="block text-sm font-semibold text-teal-900">Password<span class="text-red-500">*</span></label>
            <div class="relative">
              <input type="password" name="password" id="password" placeholder="e.g. Student@123"
                     class="w-full pl-4 pr-12 py-3 border-2 border-teal-100 rounded-lg bg-teal-50/40 text-teal-900 font-medium text-sm placeholder-teal-900/40
                            focus:outline-none focus:border-teal-600 focus:bg-white focus:ring-4 focus:ring-teal-600/10
                            hover:border-teal-200 transition-all" />
              <button type="button" id="togglePasswordBtn" title="Show password" aria-label="Show password" tabindex="-1"
                      class="absolute right-2 top-1/2 -translate-y-1/2 w-9 h-9 rounded-lg flex items-center justify-center
                             text-teal-600/60 hover:text-teal-600 hover:bg-teal-50 transition-colors">
                <i data-lucide="eye" id="togglePasswordIcon" class="w-5 h-5"></i>
              </button>
            </div>
          </div>
        </div>

        <div class="pt-3 flex justify-center gap-3">
          <button type="button" onclick="closeStudentModal()"
                  class="px-6 py-3 rounded-lg font-semibold text-teal-900 bg-white border-2 border-teal-200 hover:border-teal-600 hover:bg-teal-50 transition-all duration-200">
            Cancel
          </button>
          <button type="submit"
                  class="px-8 py-3 rounded-lg bg-teal-600 hover:bg-teal-700 text-white font-semibold shadow-sm hover:shadow-md transition-all duration-200 cursor-pointer">
            Save
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- ============================================================ -->
  <!-- VIEW STUDENT MODAL                                           -->
  <!-- ============================================================ -->
  <div id="viewModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeViewModal()"></div>

    <div class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden max-h-[92vh] flex flex-col">
      <div class="h-1.5 w-full bg-teal-600"></div>

      <div class="flex items-center justify-between px-6 py-4 border-b border-teal-100">
        <h3 class="text-lg font-bold text-teal-900">Student Details</h3>
        <button type="button" onclick="closeViewModal()" class="w-8 h-8 rounded-lg hover:bg-teal-50 flex items-center justify-center text-teal-900/60 transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <div class="p-6 space-y-4 overflow-y-auto flex-1">
        <div class="flex items-center gap-4 pb-4 border-b border-teal-100">
          <div class="w-14 h-14 rounded-full bg-teal-600 flex items-center justify-center text-white shadow-md">
            <i data-lucide="user" class="w-7 h-7"></i>
          </div>
          <div class="min-w-0">
            <p id="viewName" class="text-base font-bold text-teal-900 truncate">—</p>
            <p id="viewUsername" class="text-xs text-teal-900/60 truncate">@—</p>
          </div>
        </div>

        <div class="space-y-3">
          <div class="flex items-start gap-3">
            <i data-lucide="hash" class="w-4 h-4 text-teal-600 mt-1 flex-shrink-0"></i>
            <div class="min-w-0">
              <p class="text-[10px] font-bold uppercase tracking-wider text-teal-900/50">Admission Number</p>
              <p id="viewAdmission" class="text-sm text-teal-900 break-all">—</p>
            </div>
          </div>
          <div class="flex items-start gap-3">
            <i data-lucide="mail" class="w-4 h-4 text-teal-600 mt-1 flex-shrink-0"></i>
            <div class="min-w-0">
              <p class="text-[10px] font-bold uppercase tracking-wider text-teal-900/50">Email</p>
              <p id="viewEmail" class="text-sm text-teal-900 break-all">—</p>
            </div>
          </div>
          <div class="flex items-start gap-3">
            <i data-lucide="phone" class="w-4 h-4 text-teal-600 mt-1 flex-shrink-0"></i>
            <div class="min-w-0">
              <p class="text-[10px] font-bold uppercase tracking-wider text-teal-900/50">Mobile Number</p>
              <p id="viewMobile" class="text-sm text-teal-900">—</p>
            </div>
          </div>
          <div class="flex items-start gap-3">
            <i data-lucide="map-pin" class="w-4 h-4 text-teal-600 mt-1 flex-shrink-0"></i>
            <div class="min-w-0">
              <p class="text-[10px] font-bold uppercase tracking-wider text-teal-900/50">Address</p>
              <p id="viewAddress" class="text-sm text-teal-900 break-words">—</p>
            </div>
          </div>
        </div>
      </div>

      <div class="px-6 py-4 bg-teal-50/60 border-t border-teal-100 flex justify-end">
        <button type="button" onclick="closeViewModal()"
                class="px-5 py-2.5 rounded-lg font-semibold text-teal-900 bg-white border-2 border-teal-200 hover:border-teal-600 hover:bg-teal-50 transition-all duration-200">
          Close
        </button>
      </div>
    </div>
  </div>

  <!-- ============================================================ -->
  <!-- DELETE CONFIRMATION MODAL                                    -->
  <!-- ============================================================ -->
  <div id="deleteConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeDeleteModal()"></div>

    <div id="deleteConfirmPanel" class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-teal-600"></div>

      <div class="px-6 pt-6 pb-2 flex flex-col items-center text-center">
        <div class="w-16 h-16 rounded-full flex items-center justify-center mb-4 bg-red-50 ring-4 ring-red-100/60">
          <i data-lucide="trash-2" class="w-8 h-8 text-red-500"></i>
        </div>

        <h3 id="deleteModalTitle" class="text-xl font-bold text-teal-900 mb-2">Delete Student?</h3>

        <p id="deleteModalDescription" class="text-sm text-teal-900/70 leading-relaxed">
          You are about to permanently delete <span class="font-bold text-teal-700 break-words">this student</span>.
        </p>

        <p class="text-xs text-red-500 font-medium mt-3 flex items-center gap-1.5">
          <i data-lucide="alert-triangle" class="w-3.5 h-3.5"></i>
          This action cannot be undone.
        </p>
      </div>

      <div class="px-6 py-5 mt-2 flex flex-col-reverse sm:flex-row gap-3">
        <button type="button" onclick="closeDeleteModal()"
                class="flex-1 px-5 py-3 rounded-lg font-semibold text-teal-900 bg-white border-2 border-teal-200 hover:border-teal-600 hover:bg-teal-50 transition-all duration-200">
          Cancel
        </button>
        <button type="button" id="confirmDeleteBtn"
                class="flex-1 px-5 py-3 rounded-lg font-bold text-white bg-red-500 hover:bg-red-600 shadow-sm hover:shadow-md transition-all duration-200 flex items-center justify-center gap-2">
          <i data-lucide="trash-2" class="w-4 h-4"></i>
          <span>Delete</span>
        </button>
      </div>
    </div>
  </div>

  <!-- ============================================================ -->
  <!-- SET PASSWORD MODAL                                           -->
  <!-- ============================================================ -->
  <div id="setPasswordModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeSetPasswordModal()"></div>

    <div id="setPasswordPanel" class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl animate-modal-in overflow-hidden max-h-[92vh] flex flex-col">
      <div class="h-1.5 w-full bg-teal-600"></div>

      <div class="flex items-center justify-between px-6 py-4 border-b border-teal-100">
        <div class="flex items-center gap-3">
          <div class="w-9 h-9 rounded-lg bg-teal-50 flex items-center justify-center">
            <i data-lucide="lock" class="w-5 h-5 text-teal-600"></i>
          </div>
          <h3 class="text-lg font-bold text-teal-900">Set Password</h3>
        </div>
        <button type="button" onclick="closeSetPasswordModal()" class="w-8 h-8 rounded-lg hover:bg-teal-50 flex items-center justify-center text-teal-900/60 transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form id="setPasswordForm" method="POST" action="students.php?class_id=<?= (int) $classId ?>" class="p-6 space-y-4 overflow-y-auto flex-1" novalidate>
        <input type="hidden" name="action" value="set_password" />
        <input type="hidden" name="student_id" id="setPasswordStudentId" value="" />

        <p class="text-sm text-teal-900/70 leading-relaxed">
          Set a new password for
          <span id="setPasswordStudentName" class="font-bold text-teal-700 break-words">this student</span>.
        </p>

        <div class="space-y-2">
          <label for="new_password" class="block text-sm font-semibold text-teal-900">
            New Password<span class="text-red-500">*</span>
          </label>
          <div class="relative">
            <input type="password" name="new_password" id="new_password" required minlength="6" autocomplete="new-password"
                   placeholder="Enter new password"
                   class="w-full pl-4 pr-12 py-3 border-2 border-teal-100 rounded-lg bg-teal-50/40 text-teal-900 font-medium text-sm placeholder-teal-900/40
                          focus:outline-none focus:border-teal-600 focus:bg-white focus:ring-4 focus:ring-teal-600/10
                          hover:border-teal-200 transition-all" />
            <button type="button" id="toggleNewPasswordBtn" title="Show password" aria-label="Show password" tabindex="-1"
                    class="absolute right-2 top-1/2 -translate-y-1/2 w-9 h-9 rounded-lg flex items-center justify-center
                           text-teal-600/60 hover:text-teal-600 hover:bg-teal-50 transition-colors">
              <i data-lucide="eye" id="toggleNewPasswordIcon" class="w-5 h-5"></i>
            </button>
          </div>
          <p class="text-[11px] text-teal-900/60 mt-1">Minimum 6 characters.</p>
        </div>

        <div class="space-y-2">
          <label for="confirm_password" class="block text-sm font-semibold text-teal-900">
            Confirm Password<span class="text-red-500">*</span>
          </label>
          <div class="relative">
            <input type="password" name="confirm_password" id="confirm_password" required minlength="6" autocomplete="new-password"
                   placeholder="Re-enter new password"
                   class="w-full pl-4 pr-12 py-3 border-2 border-teal-100 rounded-lg bg-teal-50/40 text-teal-900 font-medium text-sm placeholder-teal-900/40
                          focus:outline-none focus:border-teal-600 focus:bg-white focus:ring-4 focus:ring-teal-600/10
                          hover:border-teal-200 transition-all" />
            <button type="button" id="toggleConfirmPasswordBtn" title="Show password" aria-label="Show password" tabindex="-1"
                    class="absolute right-2 top-1/2 -translate-y-1/2 w-9 h-9 rounded-lg flex items-center justify-center
                           text-teal-600/60 hover:text-teal-600 hover:bg-teal-50 transition-colors">
              <i data-lucide="eye" id="toggleConfirmPasswordIcon" class="w-5 h-5"></i>
            </button>
          </div>
          <p id="setPasswordMatchHint" class="text-[11px] mt-1 hidden"></p>
        </div>

        <div class="pt-3 flex flex-col-reverse sm:flex-row justify-center gap-3">
          <button type="button" onclick="closeSetPasswordModal()"
                  class="px-6 py-3 rounded-lg font-semibold text-teal-900 bg-white border-2 border-teal-200 hover:border-teal-600 hover:bg-teal-50 transition-all duration-200">
            Cancel
          </button>
          <button type="submit"
                  class="px-8 py-3 rounded-lg bg-teal-600 hover:bg-teal-700 text-white font-semibold shadow-sm hover:shadow-md transition-all duration-200 cursor-pointer flex items-center gap-2">
            <i data-lucide="save" class="w-4 h-4"></i>
            <span>Save Password</span>
          </button>
        </div>
      </form>
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

  <!-- HIDDEN FORMS -->
  <form id="deleteForm" method="POST" action="students.php?class_id=<?= (int) $classId ?>" class="hidden">
    <input type="hidden" name="action" value="delete_student" />
    <input type="hidden" name="student_id" id="deleteStudentId" value="" />
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

      if (mobileInput) {
        mobileInput.addEventListener('input', function () { this.value = this.value.replace(/\D/g, '').slice(0, 10); });
        mobileInput.addEventListener('keypress', function (e) {
          const charCode = e.which ? e.which : e.keyCode;
          if (charCode < 48 || charCode > 57) { if (![8, 9, 13, 27, 37, 38, 39, 40, 46].includes(charCode)) e.preventDefault(); }
        });
      }

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
        document.body.classList.add('overflow-hidden');

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
        document.body.classList.remove('overflow-hidden');
        studentForm.reset();
        formAction.value    = 'add_student';
        formStudentId.value = '';
        resetPasswordToggleState();
      };

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
        deleteModalDescription.innerHTML = 'You are about to permanently delete <span class="font-bold text-teal-700 break-words">"' + studentName + '"</span>.';
        confirmDeleteBtn.querySelector('span').textContent = 'Delete';

        deleteConfirmModal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');

        if (deleteConfirmPanel) { deleteConfirmPanel.classList.remove('animate-confirm-shake'); void deleteConfirmPanel.offsetWidth; deleteConfirmPanel.classList.add('animate-confirm-shake'); }
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
          if (pendingDeleteId === null || pendingDeleteId === undefined) { window.closeDeleteModal(); return; }
          const delIdInput = document.getElementById('deleteStudentId');
          const delForm    = document.getElementById('deleteForm');
          if (delIdInput && delForm) { delIdInput.value = String(pendingDeleteId); delForm.submit(); }
          else { window.closeDeleteModal(); }
        });
      }

      const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
      if (bulkDeleteBtn) {
        bulkDeleteBtn.addEventListener('click', function () {
          const checked = document.querySelectorAll('.student-checkbox:checked');
          if (checked.length === 0) { alert('Please select at least one student to delete.'); return; }

          bulkDeleteMode  = true;
          pendingDeleteId = null;

          deleteModalTitle.textContent = 'Delete Selected Students?';
          deleteModalDescription.innerHTML = 'You are about to permanently delete <span class="font-bold text-teal-700">' + checked.length + ' student(s)</span>.';
          confirmDeleteBtn.querySelector('span').textContent = 'Delete All';

          deleteConfirmModal.classList.remove('hidden');
          document.body.classList.add('overflow-hidden');

          if (deleteConfirmPanel) { deleteConfirmPanel.classList.remove('animate-confirm-shake'); void deleteConfirmPanel.offsetWidth; deleteConfirmPanel.classList.add('animate-confirm-shake'); }
          setTimeout(function () { if (confirmDeleteBtn) confirmDeleteBtn.focus(); }, 80);
          if (typeof lucide !== 'undefined') lucide.createIcons();
        });
      }

      (function () {
        const selectAll  = document.getElementById('selectAllCheckbox');
        const checkboxes = document.querySelectorAll('.student-checkbox');
        if (!selectAll) return;
        selectAll.addEventListener('change', function () { checkboxes.forEach(function (cb) { cb.checked = selectAll.checked; }); });
        checkboxes.forEach(function (cb) {
          cb.addEventListener('change', function () {
            const allChecked = Array.from(checkboxes).every(c => c.checked);
            const anyChecked = Array.from(checkboxes).some(c => c.checked);
            selectAll.checked = allChecked;
            selectAll.indeterminate = anyChecked && !allChecked;
          });
        });
      })();

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

        if (newPasswordInput) newPasswordInput.type = 'password';
        if (confirmPasswordInput) confirmPasswordInput.type = 'password';
        const ni = document.getElementById('toggleNewPasswordIcon');
        const ci = document.getElementById('toggleConfirmPasswordIcon');
        if (ni) ni.setAttribute('data-lucide', 'eye');
        if (ci) ci.setAttribute('data-lucide', 'eye');

        if (matchHint) { matchHint.classList.add('hidden'); matchHint.textContent = ''; }

        setPasswordModal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');

        if (setPasswordPanel) { setPasswordPanel.classList.remove('animate-confirm-shake'); void setPasswordPanel.offsetWidth; setPasswordPanel.classList.add('animate-confirm-shake'); }
        setTimeout(function () { if (newPasswordInput) newPasswordInput.focus(); }, 80);
        if (typeof lucide !== 'undefined') lucide.createIcons();
      };

      window.closeSetPasswordModal = function () {
        setPasswordModal.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
        if (setPasswordForm) setPasswordForm.reset();
      };

      wireToggle('toggleNewPasswordBtn', 'toggleNewPasswordIcon', newPasswordInput);
      wireToggle('toggleConfirmPasswordBtn', 'toggleConfirmPasswordIcon', confirmPasswordInput);

      function updateMatchHint() {
        if (!matchHint || !newPasswordInput || !confirmPasswordInput) return;
        const a = newPasswordInput.value;
        const b = confirmPasswordInput.value;
        if (b === '') { matchHint.classList.add('hidden'); matchHint.textContent = ''; return; }
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

      if (setPasswordForm) {
        setPasswordForm.addEventListener('submit', function (e) {
          const a = newPasswordInput ? newPasswordInput.value : '';
          const b = confirmPasswordInput ? confirmPasswordInput.value : '';
          if (a.length < 6) { e.preventDefault(); alert('Password must be at least 6 characters long.'); if (newPasswordInput) newPasswordInput.focus(); return; }
          if (a !== b) { e.preventDefault(); alert('Passwords do not match. Please re-enter.'); if (confirmPasswordInput) confirmPasswordInput.focus(); return; }
          const btn = setPasswordForm.querySelector('button[type="submit"]');
          if (btn) { btn.classList.add('opacity-50', 'pointer-events-none'); btn.innerHTML = '<span>Saving…</span>'; }
        });
      }

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
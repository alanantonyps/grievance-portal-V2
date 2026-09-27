<?php
/**
 * management/grievances.php
 * ---------------------------------------------------------------------------
 * Management / Grievance Member — Grievance Details List
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

// ---------------------------------------------------------------------------
// AUTH GUARD
// ---------------------------------------------------------------------------
$sessionRole = isset($_SESSION['role']) ? strtoupper((string) $_SESSION['role']) : '';
$allowedRoles = ['MANAGEMENT', 'GRIEVANCE_MEMBER'];

if (empty($_SESSION['user_id']) || !in_array($sessionRole, $allowedRoles, true)) {
    header('Location: ../login.php?role=management');
    exit;
}

$userId = (int) $_SESSION['user_id'];

// ---------------------------------------------------------------------------
// DATABASE
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
// HELPERS
// ---------------------------------------------------------------------------
function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------------------------------------------------------------------------
// MEMBER PROFILE
// ---------------------------------------------------------------------------
$memberData = [
    'username'            => $_SESSION['username'] ?? 'Member',
    'name'                => '',
    'email'               => '',
    'profile_image'       => '',
    'member_type'         => '',
    'grievance_type_id'   => 0,
    'grievance_type_name' => '',
    'designation_name'    => '',
    'has_cell_row'        => false,
    'cell_member_id'      => 0,
];

if ($conn !== null) {
    try {
        $sql = "SELECT  u.username,
                        cm.id                 AS cell_member_id,
                        cm.name               AS name,
                        cm.email              AS email,
                        cm.profile_image      AS profile_image,
                        cm.member_type        AS member_type,
                        cm.grievance_type_id  AS grievance_type_id,
                        gt.type_name          AS grievance_type_name,
                        d.designation_name    AS designation_name
                FROM users u
                LEFT JOIN cell_members cm    ON cm.user_id = u.id
                LEFT JOIN designations d     ON d.id = cm.designation_id
                LEFT JOIN grievance_types gt ON gt.id = cm.grievance_type_id
                WHERE u.id = ?
                LIMIT 1";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();

            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();

                $memberData['username']            = $row['username']            ?? $memberData['username'];
                $memberData['cell_member_id']      = (int) ($row['cell_member_id'] ?? 0);
                $memberData['name']                = $row['name']                ?? '';
                $memberData['email']               = $row['email']               ?? '';
                $memberData['profile_image']       = $row['profile_image']       ?? '';
                $memberData['member_type']         = $row['member_type']         ?? '';
                $memberData['grievance_type_id']   = (int) ($row['grievance_type_id'] ?? 0);
                $memberData['grievance_type_name'] = $row['grievance_type_name'] ?? '';
                $memberData['designation_name']    = $row['designation_name']    ?? '';

                $memberData['has_cell_row'] = ($row['member_type'] !== null);
            }
            $stmt->close();
        }
    } catch (Throwable $ex) {
        error_log('[Grievances Member Profile] ' . $ex->getMessage());
    }
}

$displayName  = !empty($memberData['name']) ? $memberData['name'] : $memberData['username'];
$displayEmail = !empty($memberData['email']) ? $memberData['email'] : 'management@rajagiri.edu';

$memberTypeUpper         = strtoupper((string) $memberData['member_type']);
$memberGrievanceTypeId   = (int) $memberData['grievance_type_id'];
$memberGrievanceTypeName = (string) $memberData['grievance_type_name'];
$hasCellRow              = (bool) $memberData['has_cell_row'];
$myCellMemberId          = (int) $memberData['cell_member_id'];

$isManagement = ($memberTypeUpper === 'MANAGEMENT');
$hasTypeAssignment = (!$isManagement && $memberGrievanceTypeId > 0);
$canViewAnything = ($isManagement || $hasTypeAssignment);
$roleLabel = $isManagement ? 'Management Member' : 'Grievance Member';

// ===========================================================================
// SELF-POST HANDLERS
// ===========================================================================
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['form_action'])) {

    $formAction = (string) $_POST['form_action'];

    $redirectWith = function (string $msg, bool $isError = false): void {
        if ($msg !== '') {
            if ($isError) $_SESSION['flash_error']   = $msg;
            else          $_SESSION['flash_success'] = $msg;
        }
        header('Location: grievances.php');
        exit;
    };

    // ---------------------------------------------------------------------
    // HANDLER A: REPLY GRIEVANCE
    // ---------------------------------------------------------------------
    if ($formAction === 'reply_grievance') {

        if ($conn === null) {
            $redirectWith('Database connection failed.', true);
        }

        $grievanceId = isset($_POST['grievance_id']) ? (int) $_POST['grievance_id'] : 0;
        $replyText   = isset($_POST['reply'])        ? trim((string) $_POST['reply']) : '';

        if ($grievanceId <= 0) {
            $redirectWith('Invalid grievance reference.', true);
        }
        if ($replyText === '') {
            $redirectWith('Reply text is required.', true);
        }
        if (mb_strlen($replyText) > 120) {
            $redirectWith('Reply must not exceed 120 characters.', true);
        }

        $grievance = null;
        try {
            $stmt = $conn->prepare("SELECT id, grievance_type_id, reply_attachment_path
                                    FROM grievances WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $grievanceId);
                $stmt->execute();
                $res = $stmt->get_result();
                $grievance = $res ? $res->fetch_assoc() : null;
                $stmt->close();
            }
        } catch (Throwable $ex) {
            error_log('[Reply Handler — fetch grievance] ' . $ex->getMessage());
        }

        if (!$grievance) {
            $redirectWith('Grievance not found.', true);
        }

        if (!$isManagement) {
            if ($memberGrievanceTypeId <= 0 || $memberGrievanceTypeId !== (int) $grievance['grievance_type_id']) {
                $redirectWith('You are not authorized to reply to this grievance.', true);
            }
        }

        $newAttachmentRelPath = null;
        $uploadError          = null;

        if (!empty($_FILES['attachment']) && isset($_FILES['attachment']['error'])) {
            $file = $_FILES['attachment'];

            if ($file['error'] === UPLOAD_ERR_NO_FILE) {
                // nothing
            } elseif ($file['error'] !== UPLOAD_ERR_OK) {
                $uploadError = 'File upload failed (error code: ' . $file['error'] . ').';
            } else {
                $maxBytes = 5 * 1024 * 1024;
                if ((int) $file['size'] > $maxBytes) {
                    $uploadError = 'File is larger than 5 MB.';
                } else {
                    $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
                    $originalName = (string) $file['name'];
                    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

                    if (!in_array($ext, $allowedExt, true)) {
                        $uploadError = 'Only PDF, JPG, JPEG, PNG, DOC, DOCX files are allowed.';
                    } else {
                        $allowedMimes = [
                            'application/pdf',
                            'image/jpeg',
                            'image/png',
                            'application/msword',
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        ];
                        $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
                        $mime  = $finfo ? finfo_file($finfo, $file['tmp_name']) : '';
                        if ($finfo) finfo_close($finfo);

                        if ($mime !== '' && !in_array(strtolower($mime), $allowedMimes, true)) {
                            if (!($ext === 'docx' && strtolower($mime) === 'application/octet-stream')) {
                                $uploadError = 'Unsupported file type.';
                            }
                        }

                        if ($uploadError === null) {
                            $uploadDirAbs = __DIR__ . '/../uploads/replies/';
                            if (!is_dir($uploadDirAbs)) {
                                @mkdir($uploadDirAbs, 0775, true);
                            }

                            if (!is_dir($uploadDirAbs) || !is_writable($uploadDirAbs)) {
                                $uploadError = 'Upload folder is not writable. Contact administrator.';
                            } else {
                                $baseName = pathinfo($originalName, PATHINFO_FILENAME);
                                $baseName = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $baseName);
                                $baseName = trim((string) $baseName, '_');
                                if ($baseName === '') $baseName = 'file';

                                $random   = bin2hex(random_bytes(4));
                                $filename = 'reply_' . $grievanceId . '_' . time() . '_' . $random . '_' . $baseName . '.' . $ext;
                                $targetAbs = $uploadDirAbs . $filename;

                                if (!move_uploaded_file($file['tmp_name'], $targetAbs)) {
                                    $uploadError = 'Could not save the uploaded file.';
                                } else {
                                    @chmod($targetAbs, 0644);
                                    $newAttachmentRelPath = 'uploads/replies/' . $filename;
                                }
                            }
                        }
                    }
                }
            }
        }

        if ($uploadError !== null) {
            $redirectWith($uploadError, true);
        }

        $oldAttachment = (string) ($grievance['reply_attachment_path'] ?? '');
        $newStatus     = 'Closed';

        try {
            $conn->begin_transaction();

            if ($newAttachmentRelPath !== null) {
                if ($myCellMemberId > 0) {
                    $sql = "UPDATE grievances
                            SET reply_details = ?, reply_attachment_path = ?, status = ?,
                                attended_by = ?, updated_at = NOW()
                            WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    if (!$stmt) throw new RuntimeException('Prepare failed: ' . $conn->error);
                    $stmt->bind_param('sssii', $replyText, $newAttachmentRelPath, $newStatus, $myCellMemberId, $grievanceId);
                } else {
                    $sql = "UPDATE grievances
                            SET reply_details = ?, reply_attachment_path = ?, status = ?,
                                updated_at = NOW()
                            WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    if (!$stmt) throw new RuntimeException('Prepare failed: ' . $conn->error);
                    $stmt->bind_param('sssi', $replyText, $newAttachmentRelPath, $newStatus, $grievanceId);
                }
            } else {
                if ($myCellMemberId > 0) {
                    $sql = "UPDATE grievances
                            SET reply_details = ?, status = ?, attended_by = ?, updated_at = NOW()
                            WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    if (!$stmt) throw new RuntimeException('Prepare failed: ' . $conn->error);
                    $stmt->bind_param('ssii', $replyText, $newStatus, $myCellMemberId, $grievanceId);
                } else {
                    $sql = "UPDATE grievances
                            SET reply_details = ?, status = ?, updated_at = NOW()
                            WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    if (!$stmt) throw new RuntimeException('Prepare failed: ' . $conn->error);
                    $stmt->bind_param('ssi', $replyText, $newStatus, $grievanceId);
                }
            }

            if (!$stmt->execute()) {
                throw new RuntimeException('Update failed: ' . $stmt->error);
            }
            $stmt->close();

            $conn->commit();

            if ($newAttachmentRelPath !== null && $oldAttachment !== '') {
                $oldAbs = __DIR__ . '/../' . ltrim($oldAttachment, '/');
                if (is_file($oldAbs)) @unlink($oldAbs);
            }

        } catch (Throwable $ex) {
            if ($conn) @$conn->rollback();
            error_log('[Reply Handler — transaction] ' . $ex->getMessage());

            if ($newAttachmentRelPath !== null) {
                $abs = __DIR__ . '/../' . $newAttachmentRelPath;
                if (is_file($abs)) @unlink($abs);
            }

            $redirectWith('Could not save the reply. Please try again.', true);
        }

        $redirectWith('Reply submitted successfully. Grievance marked as Closed.', false);
    }

    // ---------------------------------------------------------------------
    // HANDLER B: ACTION SUMMARY
    // ---------------------------------------------------------------------
    if ($formAction === 'action_summary') {

        if ($conn === null) {
            $redirectWith('Database connection failed.', true);
        }

        $grievanceId = isset($_POST['grievance_id']) ? (int) $_POST['grievance_id'] : 0;
        $actionDate  = isset($_POST['action_date'])  ? trim((string) $_POST['action_date']) : '';
        $attendeeId  = isset($_POST['attendee'])     ? (int) $_POST['attendee'] : 0;

        $summaries = $_POST['action_summary'] ?? [];
        if (!is_array($summaries)) $summaries = [$summaries];

        $takenList = $_POST['action_taken'] ?? [];
        if (!is_array($takenList)) $takenList = [$takenList];

        $pairs = [];
        $max   = max(count($summaries), count($takenList));
        for ($i = 0; $i < $max; $i++) {
            $summ  = isset($summaries[$i]) ? trim((string) $summaries[$i]) : '';
            $taken = isset($takenList[$i]) ? trim((string) $takenList[$i]) : '';
            if ($summ === '') continue;
            $pairs[] = ['summary' => $summ, 'action_taken' => $taken];
        }

        if ($grievanceId <= 0) {
            $redirectWith('Invalid grievance reference.', true);
        }
        if ($actionDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $actionDate)) {
            $redirectWith('Please provide a valid date.', true);
        }
        $d = DateTime::createFromFormat('Y-m-d', $actionDate);
        if (!$d || $d->format('Y-m-d') !== $actionDate) {
            $redirectWith('Invalid date value.', true);
        }
        if ($attendeeId <= 0) {
            $redirectWith('Please select an attendee.', true);
        }
        if (empty($pairs)) {
            $redirectWith('At least one grievance summary is required.', true);
        }
        foreach ($pairs as $p) {
            if (mb_strlen($p['summary']) > 1000 || mb_strlen($p['action_taken']) > 1000) {
                $redirectWith('Each field must not exceed 1000 characters.', true);
            }
        }

        $grievance = null;
        try {
            $stmt = $conn->prepare("SELECT id, grievance_type_id FROM grievances WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $grievanceId);
                $stmt->execute();
                $res = $stmt->get_result();
                $grievance = $res ? $res->fetch_assoc() : null;
                $stmt->close();
            }
        } catch (Throwable $ex) {
            error_log('[Action Handler — fetch grievance] ' . $ex->getMessage());
        }
        if (!$grievance) {
            $redirectWith('Grievance not found.', true);
        }

        $attendeeOk = false;
        try {
            $stmt = $conn->prepare("SELECT id FROM cell_members WHERE id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('i', $attendeeId);
                $stmt->execute();
                $res = $stmt->get_result();
                $attendeeOk = $res && $res->num_rows > 0;
                $stmt->close();
            }
        } catch (Throwable $ex) {
            error_log('[Action Handler — verify attendee] ' . $ex->getMessage());
        }
        if (!$attendeeOk) {
            $redirectWith('Selected attendee is invalid.', true);
        }

        if (!$isManagement) {
            if ($memberGrievanceTypeId <= 0 || $memberGrievanceTypeId !== (int) $grievance['grievance_type_id']) {
                $redirectWith('You are not authorized for this grievance.', true);
            }
        }

        try {
            $conn->begin_transaction();

            $stmt = $conn->prepare("INSERT INTO grievance_actions
                                      (grievance_id, action_date, attendee_id, summary, action_taken, created_by, created_at)
                                    VALUES (?, ?, ?, ?, ?, ?, NOW())");
            if (!$stmt) throw new RuntimeException('Prepare failed: ' . $conn->error);

            foreach ($pairs as $p) {
                $stmt->bind_param(
                    'isissi',
                    $grievanceId,
                    $actionDate,
                    $attendeeId,
                    $p['summary'],
                    $p['action_taken'],
                    $userId
                );
                if (!$stmt->execute()) {
                    throw new RuntimeException('Insert failed: ' . $stmt->error);
                }
            }
            $stmt->close();

            $touch = $conn->prepare("UPDATE grievances SET updated_at = NOW() WHERE id = ?");
            if ($touch) {
                $touch->bind_param('i', $grievanceId);
                $touch->execute();
                $touch->close();
            }

            $conn->commit();

        } catch (Throwable $ex) {
            if ($conn) @$conn->rollback();
            error_log('[Action Handler — transaction] ' . $ex->getMessage());
            $redirectWith('Could not save the action summary. Please try again.', true);
        }

        $count = count($pairs);
        $redirectWith($count . ' action summar' . ($count === 1 ? 'y' : 'ies') . ' saved successfully.', false);
    }

    // NOTE: reopen_grievance handler removed — reopen is a student-only action.
}

// ---------------------------------------------------------------------------
// FLASH
// ---------------------------------------------------------------------------
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

// ---------------------------------------------------------------------------
// PROFILE PICTURE
// ---------------------------------------------------------------------------
$hasProfilePicture = false;
$profilePictureUrl = '';

if (!empty($memberData['profile_image'])) {
    $relative     = ltrim((string) $memberData['profile_image'], '/');
    $absolutePath = __DIR__ . '/../' . $relative;
    $browserPath  = '../' . $relative;

    if (file_exists($absolutePath) && is_file($absolutePath)) {
        $hasProfilePicture = true;
        $profilePictureUrl = $browserPath;
    }
}

// ---------------------------------------------------------------------------
// FETCH ALL CELL MEMBERS
// ---------------------------------------------------------------------------
$allCellMembers = [];

if ($conn !== null) {
    try {
        $sql = "SELECT  cm.id, cm.user_id, cm.name, cm.email, cm.member_type,
                        d.designation_name
                FROM cell_members cm
                LEFT JOIN designations d ON d.id = cm.designation_id
                ORDER BY cm.name ASC";
        $res = $conn->query($sql);
        if ($res) {
            while ($r = $res->fetch_assoc()) $allCellMembers[] = $r;
            $res->free();
        }
    } catch (Throwable $ex) {
        error_log('[Cell Members Fetch] ' . $ex->getMessage());
    }
}

// ---------------------------------------------------------------------------
// FETCH GRIEVANCES (now includes reopen_reason)
// ---------------------------------------------------------------------------
$grievances = [];

if ($conn !== null && $canViewAnything) {
    try {
        $baseSelect = "SELECT  g.id AS grievance_id,
                                g.grievance_number, g.grievance_type_id,
                                g.subject, g.description, g.status,
                                g.attachment_path, g.reply_details, g.reply_attachment_path,
                                g.reopen_reason,
                                g.assigned_member_id, g.attended_by,
                                g.created_at, g.updated_at,
                                gt.type_name AS grievance_type_name,
                                u.role AS complainant_role,
                                u.username AS complainant_username,
                                COALESCE(st.name, p.name, sf.name, ap.name, u.username) AS complainant_name,
                                COALESCE(st.email, p.email, sf.email, ap.email, u.username) AS complainant_email,
                                COALESCE(st.contact_number, p.contact_number, sf.contact_number) AS complainant_phone
                        FROM grievances g
                        INNER JOIN grievance_types gt ON g.grievance_type_id = gt.id
                        INNER JOIN users u            ON g.complainant_user_id = u.id
                        LEFT  JOIN students st        ON st.user_id = u.id
                        LEFT  JOIN parents p          ON p.user_id  = u.id
                        LEFT  JOIN staff sf           ON sf.user_id = u.id
                        LEFT  JOIN admin_profiles ap  ON ap.user_id = u.id";

        if ($isManagement) {
            $sql = $baseSelect . " ORDER BY g.created_at DESC";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) $grievances[] = $row;
                $stmt->close();
            }
        } elseif ($hasTypeAssignment) {
            $sql = $baseSelect . " WHERE g.grievance_type_id = ? ORDER BY g.created_at DESC";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('i', $memberGrievanceTypeId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) $grievances[] = $row;
                $stmt->close();
            }
        }
    } catch (Throwable $ex) {
        error_log('[Grievances Fetch] ' . $ex->getMessage());
        $dbError = 'Unable to load grievances at this time.';
    }
}

$totalGrievances = count($grievances);

// ---------------------------------------------------------------------------
// BULK-FETCH ACTION SUMMARIES
// ---------------------------------------------------------------------------
$actionsByGrievance = [];

if ($conn !== null && !empty($grievances)) {
    try {
        $ids = array_map(function ($g) { return (int) $g['grievance_id']; }, $grievances);
        $ids = array_values(array_unique(array_filter($ids)));

        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            $sql = "SELECT ga.id, ga.grievance_id, ga.action_date, ga.attendee_id,
                           ga.summary, ga.action_taken, ga.created_by, ga.created_at,
                           cm.name AS attendee_name,
                           d.designation_name AS attendee_designation,
                           u.username AS created_by_username
                    FROM grievance_actions ga
                    LEFT JOIN cell_members cm    ON cm.id = ga.attendee_id
                    LEFT JOIN designations d     ON d.id  = cm.designation_id
                    LEFT JOIN users u            ON u.id  = ga.created_by
                    WHERE ga.grievance_id IN ($placeholders)
                    ORDER BY ga.action_date ASC, ga.id ASC";

            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $types = str_repeat('i', count($ids));
                $stmt->bind_param($types, ...$ids);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) {
                    $gid = (int) $r['grievance_id'];
                    if (!isset($actionsByGrievance[$gid])) {
                        $actionsByGrievance[$gid] = [];
                    }
                    $actionsByGrievance[$gid][] = $r;
                }
                $stmt->close();
            }
        }
    } catch (Throwable $ex) {
        error_log('[Action Summary Bulk Fetch] ' . $ex->getMessage());
    }
}

// ---------------------------------------------------------------------------
// STATUS BADGE
// ---------------------------------------------------------------------------
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
    return '<span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold uppercase tracking-wider border '
        . $classes . '">' . e($status) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Grievance Details — <?= e($roleLabel) ?> | Rajagiri College Grievance Portal</title>
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
            lightboxIn:   { '0%':{opacity:'0'}, '100%':{opacity:'1'} },
            submenuFade:  { '0%':{opacity:'0',maxHeight:'0'}, '100%':{opacity:'1',maxHeight:'500px'} }
          },
          animation: {
            'fade-in-up':    'fadeInUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'dropdown':      'dropdownFade 0.2s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'modal-in':      'modalFadeIn 0.25s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'confirm-shake': 'confirmShake 0.5s cubic-bezier(0.16, 1, 0.3, 1)',
            'flash-in':      'flashIn 0.35s cubic-bezier(0.16, 1, 0.3, 1) forwards',
            'flash-out':     'flashOut 0.45s cubic-bezier(0.4, 0, 1, 1) forwards',
            'lightbox-in':   'lightboxIn 0.25s ease-out forwards',
            'submenu':       'submenuFade 0.25s ease-out forwards'
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
    #managementNav::-webkit-scrollbar { width:6px; }
    #managementNav::-webkit-scrollbar-track { background:transparent; }
    #managementNav::-webkit-scrollbar-thumb { background:rgba(255,255,255,0.2); border-radius:3px; }
    #managementNav::-webkit-scrollbar-thumb:hover { background:rgba(255,255,255,0.35); }
  </style>
</head>

<body class="min-h-screen bg-teal-50/40 text-teal-900 antialiased selection:bg-teal-100 selection:text-teal-700 flex flex-col">

  <div class="flex min-h-screen flex-1">

    <!-- SIDEBAR (no RCSS logo per request) -->
    <aside id="managementSidebar"
           class="w-20 bg-teal-800 flex flex-col py-4 shadow-xl fixed inset-y-0 left-0 z-40
                  transition-all duration-300 ease-in-out overflow-hidden">

      <div class="flex items-center justify-center mb-6 flex-shrink-0">
        <button id="sidebarToggle"
                class="text-white/70 hover:text-white p-2 rounded-lg hover:bg-white/10 transition-colors
                       flex items-center justify-center flex-shrink-0" aria-label="Toggle sidebar">
          <i data-lucide="menu" class="w-6 h-6 flex-shrink-0"></i>
        </button>
      </div>

      <nav id="managementNav"
           class="flex flex-col space-y-1 flex-1 w-full px-3 pt-1 overflow-y-auto overflow-x-hidden">

        <a href="dashboard.php"
           class="group relative w-full h-12 rounded-xl hover:bg-white/10 flex items-center text-white transition-all px-3 flex-shrink-0">
          <i data-lucide="home" class="w-6 h-6 flex-shrink-0"></i>
          <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap opacity-0 w-0 overflow-hidden transition-all duration-200">Dashboard</span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-teal-900 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Dashboard</span>
        </a>

        <a href="grievances.php"
           class="group relative w-full h-12 rounded-xl bg-white text-teal-800 shadow-md flex items-center px-3 flex-shrink-0 transition-all">
          <i data-lucide="clipboard-list" class="w-6 h-6 flex-shrink-0"></i>
          <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap opacity-0 w-0 overflow-hidden transition-all duration-200">Grievance</span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-teal-900 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Grievance</span>
        </a>

        <div class="sidebar-group flex-shrink-0" data-section="reports">
          <button type="button"
                  id="reportsToggle"
                  data-submenu-toggle="reports"
                  data-landing-href="grievance_reports.php"
                  class="group relative w-full h-12 rounded-xl hover:bg-white/10 flex items-center text-white transition-all px-3">
            <i data-lucide="bar-chart-3" class="w-6 h-6 flex-shrink-0"></i>
            <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap opacity-0 w-0 overflow-hidden transition-all duration-200">Grievance Reports</span>
            <i data-lucide="chevron-down"
               class="sidebar-label submenu-chevron ml-auto w-4 h-4 flex-shrink-0 transition-transform duration-300 opacity-0 w-0 overflow-hidden"></i>
            <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-teal-900 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">Grievance Reports</span>
          </button>

          <div id="submenu-reports"
               class="submenu hidden ml-2 mt-1 space-y-1 pl-3 border-l border-white/20">
            <a href="complaint_report.php"
               class="group flex items-center gap-2 px-2 py-2 rounded-lg text-white/80 hover:text-white hover:bg-white/10 transition-all text-xs">
              <i data-lucide="file-bar-chart" class="w-4 h-4 flex-shrink-0 text-white/70 group-hover:text-white"></i>
              <span class="font-medium whitespace-nowrap">Complaint Report</span>
            </a>
            <a href="cell_members_report.php"
               class="group flex items-center gap-2 px-2 py-2 rounded-lg text-white/80 hover:text-white hover:bg-white/10 transition-all text-xs">
              <i data-lucide="users-2" class="w-4 h-4 flex-shrink-0 text-white/70 group-hover:text-white"></i>
              <span class="font-medium whitespace-nowrap">Cell Member Report</span>
            </a>
          </div>
        </div>

        <a href="profile.php"
           class="group relative w-full h-12 rounded-xl hover:bg-white/10 flex items-center text-white transition-all px-3 flex-shrink-0">
          <i data-lucide="user" class="w-6 h-6 flex-shrink-0"></i>
          <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap opacity-0 w-0 overflow-hidden transition-all duration-200">My Profile</span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-teal-900 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg z-50">My Profile</span>
        </a>

        <a href="change_password.php"
           class="group relative w-full h-12 rounded-xl hover:bg-white/10 flex items-center text-white transition-all px-3 flex-shrink-0">
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

    <!-- MAIN CONTENT -->
    <div id="managementMain" class="flex-1 ml-20 flex flex-col min-h-screen transition-all duration-300">

      <!-- TOP HEADER -->
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

          <div class="relative" id="management-dropdown-container">
            <button id="management-dropdown-btn" type="button" aria-haspopup="true" aria-expanded="false"
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
              <i data-lucide="chevron-down" id="management-chevron" class="w-4 h-4 text-teal-600 transition-transform duration-300"></i>
            </button>

            <div id="management-dropdown-menu"
                 class="hidden absolute right-0 mt-2 w-64 bg-white rounded-xl shadow-xl border border-teal-100 py-2 z-50 overflow-hidden">
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

        <div class="max-w-7xl mx-auto mb-6 animate-fade-in-up">
          <div class="flex items-center gap-2 mb-2">
            <span class="inline-flex items-center justify-center w-2 h-2 rounded-full bg-teal-600"></span>
            <p class="text-xs font-semibold text-teal-600 uppercase tracking-wider"><?= e($roleLabel) ?></p>
          </div>
          <h1 class="text-2xl md:text-3xl font-bold text-teal-900 mb-2">Grievance Details</h1>
          <nav class="flex flex-wrap items-center gap-2 text-sm text-teal-900/60">
            <a href="dashboard.php" class="inline-flex items-center gap-1 hover:text-teal-600 transition-colors">
              <i data-lucide="layout-dashboard" class="w-4 h-4"></i> Dashboard
            </a>
            <span class="text-teal-900/30">/</span>
            <span class="text-teal-600 font-semibold">Grievance Details</span>
          </nav>
        </div>

        <?php if (!$hasCellRow): ?>
          <div class="max-w-7xl mx-auto mb-6 rounded-xl border-2 border-amber-200 bg-amber-50 px-4 py-3
                      flex items-start gap-2 animate-fade-in-up" style="animation-delay: 40ms;">
            <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-600 flex-shrink-0 mt-0.5"></i>
            <div class="text-sm text-amber-800">
              <p class="font-semibold">Your account is not linked to a committee member profile.</p>
              <p class="mt-0.5">Please contact the administrator to be added to the grievance cell.</p>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($flashSuccess !== ''): ?>
          <div id="flashSuccessBox"
               class="max-w-7xl mx-auto mb-6 rounded-xl border-2 border-emerald-200 bg-emerald-50 px-4 py-3
                      flex items-start gap-2 animate-flash-in overflow-hidden">
            <i data-lucide="check-circle" class="w-5 h-5 text-emerald-600 flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-emerald-800 font-medium"><?= e($flashSuccess) ?></p>
          </div>
        <?php endif; ?>

        <?php if ($flashError !== ''): ?>
          <div id="flashErrorBox"
               class="max-w-7xl mx-auto mb-6 rounded-xl border-2 border-red-200 bg-red-50 px-4 py-3
                      flex items-start gap-2 animate-flash-in overflow-hidden">
            <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-red-700 font-medium"><?= e($flashError) ?></p>
          </div>
        <?php endif; ?>

        <!-- TABLE CONTROLS -->
        <div class="max-w-7xl mx-auto mb-5 animate-fade-in-up" style="animation-delay: 60ms;">
          <div class="bg-teal-50/60 rounded-2xl border-2 border-teal-100 px-5 py-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
              <div class="flex items-center gap-3">
                <span class="text-sm text-teal-900">Show</span>
                <select id="entriesPerPage"
                        class="px-3 py-1.5 border-2 border-teal-100 rounded-lg text-sm font-medium text-teal-900
                               focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10
                               hover:border-teal-400 transition-colors bg-white">
                  <option value="10" selected>10</option>
                  <option value="25">25</option>
                  <option value="50">50</option>
                  <option value="100">100</option>
                </select>
                <span class="text-sm text-teal-900">entries</span>
              </div>

              <div class="relative w-full sm:w-80">
                <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-teal-900/40"></i>
                <input type="text" id="searchInput" placeholder="Search.." autocomplete="off"
                       class="w-full pl-10 pr-4 py-2 border-2 border-teal-100 rounded-lg text-sm
                              focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10
                              hover:border-teal-400 transition-all bg-white" />
              </div>
            </div>
          </div>
        </div>

        <!-- DATA TABLE -->
        <div class="max-w-7xl mx-auto animate-fade-in-up" style="animation-delay: 100ms;">
          <div class="bg-white rounded-2xl shadow-sm border-2 border-teal-100 overflow-hidden">
            <div class="overflow-x-auto">
              <table class="w-full" id="grievancesTable">
                <thead>
                  <tr class="bg-teal-600 text-white">
                    <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wider">Sl.No.</th>
                    <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wider">Grievance Number</th>
                    <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wider">Grievance Type</th>
                    <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wider">Name</th>
                    <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wider">Date</th>
                    <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wider">Subject</th>
                    <th class="px-4 py-4 text-left text-xs font-bold uppercase tracking-wider">Status</th>
                    <th class="px-4 py-4 text-center text-xs font-bold uppercase tracking-wider">Actions</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-teal-50" id="grievancesTableBody">

                  <?php if (empty($grievances)): ?>
                    <tr>
                      <td colspan="8" class="px-6 py-16 text-center text-teal-900/60">
                        <div class="flex flex-col items-center justify-center">
                          <div class="w-16 h-16 bg-teal-50 rounded-full flex items-center justify-center mb-4">
                            <i data-lucide="inbox" class="w-8 h-8 text-teal-600"></i>
                          </div>
                          <p class="text-lg font-semibold text-teal-900">No grievances found</p>
                          <p class="text-sm text-teal-900/60 mt-1">
                            <?php if ($isManagement): ?>
                              No grievances have been submitted yet.
                            <?php elseif (!$hasCellRow): ?>
                              Your account is not linked to a grievance cell member profile.
                            <?php elseif (!$hasTypeAssignment): ?>
                              Your account is not yet assigned a grievance type. Please contact the administrator.
                            <?php else: ?>
                              No grievances of type "<?= e($memberGrievanceTypeName) ?>" have been submitted yet.
                            <?php endif; ?>
                          </p>
                        </div>
                      </td>
                    </tr>
                  <?php else: ?>

                    <?php foreach ($grievances as $index => $row): ?>
                      <?php
                        $gId          = (int) $row['grievance_id'];
                        $gNumber      = (string) ($row['grievance_number'] ?? '—');
                        $gTypeName    = (string) ($row['grievance_type_name'] ?? '—');
                        $gSubject     = (string) ($row['subject'] ?? '—');
                        $gDescription = (string) ($row['description'] ?? '');
                        $gStatus      = (string) ($row['status'] ?? 'Pending');
                        $gName        = (string) ($row['complainant_name'] ?? '—');
                        $gEmail       = (string) ($row['complainant_email'] ?? '');
                        $gPhone       = (string) ($row['complainant_phone'] ?? '');
                        $gRole        = (string) ($row['complainant_role'] ?? '');
                        $gCreatedRaw  = (string) ($row['created_at'] ?? '');
                        $gUpdatedRaw  = (string) ($row['updated_at'] ?? '');
                        $gCreated     = !empty($gCreatedRaw) ? date('Y-m-d', strtotime($gCreatedRaw)) : '—';
                        $gCreatedFull = !empty($gCreatedRaw) ? date('d M Y, h:i A', strtotime($gCreatedRaw)) : '—';
                        $gAttachment  = (string) ($row['attachment_path'] ?? '');
                        $gReply       = (string) ($row['reply_details'] ?? '');
                        $gReplyAttach = (string) ($row['reply_attachment_path'] ?? '');
                        $gReopen      = (string) ($row['reopen_reason'] ?? '');

                        $isPending  = in_array($gStatus, ['Pending', 'In Progress', 'Reopened'], true);
                        $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

                        $attachmentUrl    = '';
                        $attachmentIsImg  = false;
                        $attachmentExt    = '';
                        if ($gAttachment !== '') {
                            $rel = ltrim($gAttachment, '/');
                            if (file_exists(__DIR__ . '/../' . $rel)) {
                                $attachmentUrl   = '../' . $rel;
                                $attachmentExt   = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
                                $attachmentIsImg = in_array($attachmentExt, $imageExts, true);
                            }
                        }

                        $replyAttachmentUrl   = '';
                        $replyAttachmentIsImg = false;
                        $replyAttachmentExt   = '';
                        if ($gReplyAttach !== '') {
                            $relR = ltrim($gReplyAttach, '/');
                            if (file_exists(__DIR__ . '/../' . $relR)) {
                                $replyAttachmentUrl   = '../' . $relR;
                                $replyAttachmentExt   = strtolower(pathinfo($relR, PATHINFO_EXTENSION));
                                $replyAttachmentIsImg = in_array($replyAttachmentExt, $imageExts, true);
                            }
                        }

                        $actionsForThis = $actionsByGrievance[$gId] ?? [];
                        $actionsJson = [];
                        foreach ($actionsForThis as $act) {
                            $actionsJson[] = [
                                'date'           => !empty($act['action_date']) ? date('d M Y', strtotime((string) $act['action_date'])) : '',
                                'attendee_name'  => (string) ($act['attendee_name'] ?? ''),
                                'attendee_desig' => (string) ($act['attendee_designation'] ?? ''),
                                'summary'        => (string) ($act['summary'] ?? ''),
                                'action_taken'   => (string) ($act['action_taken'] ?? ''),
                                'created_by'     => (string) ($act['created_by_username'] ?? ''),
                            ];
                        }
                      ?>
                      <tr class="hover:bg-teal-50/40 transition-colors align-top">

                        <td class="px-4 py-5 whitespace-nowrap text-sm text-teal-900"><?= $index + 1 ?></td>

                        <td class="px-4 py-5 text-sm text-teal-700 font-medium break-words"><?= e($gNumber) ?></td>

                        <td class="px-4 py-5 text-sm text-teal-900/80 break-words max-w-[220px]"><?= e($gTypeName) ?></td>

                        <td class="px-4 py-5 text-sm text-teal-900/80 break-words max-w-[180px]"><?= e($gName) ?></td>

                        <td class="px-4 py-5 whitespace-nowrap text-sm text-teal-900/80"><?= e($gCreated) ?></td>

                        <td class="px-4 py-5 text-sm text-teal-900/80 break-words max-w-[160px]"><?= e($gSubject) ?></td>

                        <td class="px-4 py-5 whitespace-nowrap"><?= statusBadge($gStatus) ?></td>

                        <td class="px-4 py-5">
                          <div class="flex items-center justify-center gap-2 flex-wrap">

                            <?php if ($isPending): ?>
                              <button type="button"
                                      data-reply-trigger="1"
                                      data-grievance-id="<?= $gId ?>"
                                      data-grievance-number="<?= e($gNumber) ?>"
                                      title="Reply to grievance"
                                      class="w-9 h-9 rounded-full bg-teal-50 hover:bg-teal-600
                                             inline-flex items-center justify-center text-teal-600 hover:text-white
                                             transition-all duration-200 hover:scale-110 flex-shrink-0">
                                <i data-lucide="reply" class="w-4 h-4"></i>
                              </button>
                            <?php endif; ?>

                            <button type="button"
                                    data-view-trigger="1"
                                    data-grievance-id="<?= $gId ?>"
                                    title="View grievance"
                                    class="w-9 h-9 rounded-full bg-teal-50 hover:bg-teal-600
                                           inline-flex items-center justify-center text-teal-600 hover:text-white
                                           transition-all duration-200 hover:scale-110 flex-shrink-0">
                              <i data-lucide="eye" class="w-4 h-4"></i>
                            </button>

                            <button type="button"
                                    data-action-trigger="1"
                                    data-grievance-id="<?= $gId ?>"
                                    data-grievance-number="<?= e($gNumber) ?>"
                                    title="Action summary"
                                    class="w-9 h-9 rounded-full bg-teal-50 hover:bg-teal-600
                                           inline-flex items-center justify-center text-teal-600 hover:text-white
                                           transition-all duration-200 hover:scale-110 flex-shrink-0">
                              <i data-lucide="list" class="w-4 h-4"></i>
                            </button>

                          </div>
                        </td>

                      </tr>

                      <script type="application/json" id="grievance-data-<?= $gId ?>">
                        <?= json_encode([
                            'id'                        => $gId,
                            'number'                    => $gNumber,
                            'type'                      => $gTypeName,
                            'subject'                   => $gSubject,
                            'description'               => $gDescription,
                            'status'                    => $gStatus,
                            'complainant'               => $gName,
                            'email'                     => $gEmail,
                            'phone'                     => $gPhone,
                            'role'                      => $gRole,
                            'created_at'                => $gCreatedFull,
                            'updated_at'                => !empty($gUpdatedRaw) ? date('d M Y, h:i A', strtotime($gUpdatedRaw)) : '',
                            'attachment_url'            => $attachmentUrl,
                            'attachment_is_image'       => $attachmentIsImg,
                            'attachment_ext'            => $attachmentExt,
                            'reply_details'             => $gReply,
                            'reply_attachment_url'      => $replyAttachmentUrl,
                            'reply_attachment_is_image' => $replyAttachmentIsImg,
                            'reply_attachment_ext'      => $replyAttachmentExt,
                            'reopen_reason'             => $gReopen,
                            'actions'                   => $actionsJson,
                        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
                      </script>

                    <?php endforeach; ?>

                  <?php endif; ?>

                </tbody>
              </table>
            </div>

            <div class="px-6 py-4 bg-teal-50/40 border-t border-teal-100
                        flex flex-col sm:flex-row items-center justify-between gap-4">
              <p class="text-sm text-teal-900/70" id="tableInfo">
                Showing
                <span class="font-semibold text-teal-900" id="infoStart"><?= $totalGrievances > 0 ? 1 : 0 ?></span>
                to
                <span class="font-semibold text-teal-900" id="infoEnd"><?= $totalGrievances ?></span>
                of
                <span class="font-semibold text-teal-900" id="infoTotal"><?= $totalGrievances ?></span>
                entries
              </p>

              <div class="flex items-center space-x-2">
                <button type="button" id="prevPageBtn"
                        class="px-4 py-2 rounded-lg text-sm font-semibold text-teal-900
                               bg-white hover:bg-teal-50 border-2 border-teal-200 hover:border-teal-600
                               disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                        disabled>Previous</button>

                <span id="currentPageBadge"
                      class="inline-flex items-center justify-center w-9 h-9 rounded-lg
                             bg-teal-600 text-white text-sm font-bold shadow-sm">1</span>

                <button type="button" id="nextPageBtn"
                        class="px-4 py-2 rounded-lg text-sm font-semibold text-teal-900
                               bg-white hover:bg-teal-50 border-2 border-teal-200 hover:border-teal-600
                               disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                        disabled>Next</button>
              </div>
            </div>

          </div>
        </div>

      </main>

      <!-- FOOTER -->
      <footer class="bg-teal-900 text-white mt-auto">
        <div class="roofline"></div>
        <div class="px-4 sm:px-6 py-6">
          <div class="max-w-7xl mx-auto text-center">
            <p class="text-xs text-teal-200/70">
              &copy; <?= date('Y') ?>
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
  <!-- REPLY MODAL -->
  <!-- ============================================================ -->
  <div id="replyModal" class="hidden fixed inset-0 z-[80] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" data-modal-close="reply"></div>

    <div class="relative w-full max-w-lg bg-white rounded-2xl shadow-2xl overflow-hidden animate-modal-in">
      <div class="h-1.5 w-full bg-teal-600"></div>

      <div class="flex items-start justify-between px-6 pt-6 pb-3">
        <div>
          <h3 class="text-xl md:text-2xl font-bold text-teal-900">Reply Grievance</h3>
          <p class="text-xs text-teal-900/60 mt-1">
            Ref: <span class="font-semibold text-teal-700" id="replyGrievanceNumber">—</span>
          </p>
        </div>
        <button type="button" data-modal-close="reply"
                class="text-teal-900/40 hover:text-teal-900 hover:bg-teal-50 rounded-lg p-1.5 transition-colors" aria-label="Close">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form id="replyForm" method="post" action="grievances.php" enctype="multipart/form-data" class="px-6 pb-6">
        <input type="hidden" name="form_action" value="reply_grievance" />
        <input type="hidden" name="grievance_id" id="replyGrievanceId" value="" />

        <div class="mb-4">
          <label for="replyText" class="block text-sm font-semibold text-teal-900 mb-2">Reply</label>
          <textarea id="replyText" name="reply" rows="4" maxlength="120" required
                    class="w-full px-4 py-3 border-2 border-teal-100 rounded-lg text-sm text-teal-900
                           focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10
                           hover:border-teal-400 transition-all resize-none bg-white"
                    placeholder="Type your reply here..."></textarea>
          <p class="text-xs text-teal-900/60 mt-1.5">
            (Maximum 120 character) — <span id="replyCharCount" class="font-semibold text-teal-700">0</span>/120
          </p>
        </div>

        <div class="mb-5">
          <input type="file" id="replyFile" name="attachment" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" class="hidden" />
          <div class="flex items-stretch border-2 border-teal-100 rounded-lg overflow-hidden hover:border-teal-400 transition-colors">
            <label for="replyFile" class="inline-flex items-center justify-center px-5 py-2.5
                          bg-teal-50 hover:bg-teal-100 cursor-pointer
                          text-sm font-semibold text-teal-900
                          border-r-2 border-teal-100 transition-colors flex-shrink-0">
              <i data-lucide="upload" class="w-4 h-4 mr-2"></i> Choose files
            </label>
            <span id="replyFileName" class="flex items-center px-4 py-2.5 text-sm text-teal-900/60 truncate flex-1 bg-white">
              No file chosen
            </span>
          </div>
          <p class="text-xs text-teal-900/60 mt-1.5">(Max 5 Mb)</p>
        </div>

        <div class="flex items-center justify-end">
          <button type="submit"
                  class="inline-flex items-center justify-center px-7 py-2.5 rounded-lg
                         bg-teal-600 hover:bg-teal-700 text-white text-sm font-bold
                         shadow-sm hover:shadow-md
                         transition-all duration-200 hover:-translate-y-0.5 active:scale-95
                         disabled:opacity-50 disabled:cursor-not-allowed">
            <span>Submit</span>
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- ============================================================ -->
  <!-- VIEW MODAL -->
  <!-- ============================================================ -->
  <div id="viewModal" class="hidden fixed inset-0 z-[80] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" data-modal-close="view"></div>

    <div class="relative w-full max-w-3xl max-h-[90vh] bg-white rounded-2xl shadow-2xl overflow-hidden
                flex flex-col animate-modal-in">

      <div class="h-1.5 w-full bg-teal-600 flex-shrink-0"></div>

      <div class="flex items-start justify-between px-6 pt-5 pb-4 border-b border-teal-100 flex-shrink-0">
        <div class="flex items-start gap-3 min-w-0">
          <div class="w-11 h-11 rounded-xl bg-teal-600
                      flex items-center justify-center text-white flex-shrink-0 shadow-sm">
            <i data-lucide="file-text" class="w-5 h-5"></i>
          </div>
          <div class="min-w-0">
            <h3 class="text-lg md:text-xl font-bold text-teal-900 truncate">Grievance Overview</h3>
            <p class="text-xs text-teal-900/60 mt-0.5">
              Ref: <span class="font-semibold text-teal-700" id="viewGrievanceNumber">—</span>
            </p>
          </div>
        </div>
        <button type="button" data-modal-close="view"
                class="text-teal-900/40 hover:text-teal-900 hover:bg-teal-50 rounded-lg p-1.5 transition-colors flex-shrink-0" aria-label="Close">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <div class="px-6 py-5 overflow-y-auto flex-1">

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-5">
          <div class="rounded-xl border-2 border-teal-100 bg-teal-50/60 p-4">
            <p class="text-[11px] uppercase tracking-wider font-bold text-teal-900/60 mb-1">Grievance Type</p>
            <p class="text-sm font-semibold text-teal-900 break-words" id="viewGrievanceType">—</p>
          </div>
          <div class="rounded-xl border-2 border-teal-100 bg-teal-50/60 p-4">
            <p class="text-[11px] uppercase tracking-wider font-bold text-teal-900/60 mb-1">Status</p>
            <div id="viewGrievanceStatus">—</div>
          </div>
        </div>

        <div class="mb-5">
          <h4 class="text-sm font-bold text-teal-900 uppercase tracking-wider mb-3 flex items-center">
            <i data-lucide="user-circle" class="w-4 h-4 mr-2 text-teal-600"></i> Complainant
          </h4>
          <div class="rounded-xl border-2 border-teal-100 bg-white p-4">
            <div class="flex items-start gap-3">
              <div class="w-11 h-11 rounded-full bg-teal-600
                          flex items-center justify-center text-white flex-shrink-0 font-bold text-sm">
                <span id="viewComplainantInitial">?</span>
              </div>
              <div class="min-w-0 flex-1 space-y-1">
                <p class="text-sm font-semibold text-teal-900 break-words" id="viewComplainantName">—</p>
                <p class="text-xs text-teal-900/60 flex items-center break-all">
                  <i data-lucide="mail" class="w-3.5 h-3.5 mr-1.5 flex-shrink-0"></i>
                  <span id="viewComplainantEmail">—</span>
                </p>
                <p class="text-xs text-teal-900/60 flex items-center" id="viewComplainantPhoneWrap">
                  <i data-lucide="phone" class="w-3.5 h-3.5 mr-1.5 flex-shrink-0"></i>
                  <span id="viewComplainantPhone">—</span>
                </p>
              </div>
            </div>
          </div>
        </div>

        <div class="mb-5">
          <h4 class="text-sm font-bold text-teal-900 uppercase tracking-wider mb-3 flex items-center">
            <i data-lucide="align-left" class="w-4 h-4 mr-2 text-teal-600"></i> Grievance Details
          </h4>
          <div class="rounded-xl border-2 border-teal-100 bg-white p-4 space-y-3">
            <div>
              <p class="text-[11px] uppercase tracking-wider font-bold text-teal-900/60 mb-1">Subject</p>
              <p class="text-sm font-semibold text-teal-900 break-words" id="viewGrievanceSubject">—</p>
            </div>
            <div class="pt-3 border-t border-teal-100">
              <p class="text-[11px] uppercase tracking-wider font-bold text-teal-900/60 mb-1">Description</p>
              <p class="text-sm text-teal-900/80 leading-relaxed whitespace-pre-wrap break-words" id="viewGrievanceDescription">—</p>
            </div>
          </div>
        </div>

        <div class="mb-5 hidden" id="viewReopenSection">
          <h4 class="text-sm font-bold text-teal-900 uppercase tracking-wider mb-3 flex items-center">
            <i data-lucide="rotate-ccw" class="w-4 h-4 mr-2 text-rose-500"></i> Reopen Reason
          </h4>
          <div class="rounded-xl border-2 border-rose-200 bg-rose-50 p-4">
            <div class="flex items-start gap-3">
              <div class="w-9 h-9 rounded-full bg-rose-500
                          flex items-center justify-center text-white flex-shrink-0 shadow-sm">
                <i data-lucide="rotate-ccw" class="w-4 h-4"></i>
              </div>
              <div class="min-w-0 flex-1">
                <p class="text-[11px] uppercase tracking-wider font-bold text-rose-700 mb-1.5">
                  Reason given by complainant for reopening
                </p>
                <p class="text-sm text-rose-900 leading-relaxed whitespace-pre-wrap break-words" id="viewReopenReason">—</p>
              </div>
            </div>
          </div>
        </div>

        <div class="mb-5 hidden" id="viewReplySection">
          <h4 class="text-sm font-bold text-teal-900 uppercase tracking-wider mb-3 flex items-center">
            <i data-lucide="message-square-reply" class="w-4 h-4 mr-2 text-teal-600"></i> Reply
          </h4>
          <div class="rounded-xl border-2 border-teal-200 bg-teal-50/60 p-4">
            <div class="flex items-start gap-3">
              <div class="w-9 h-9 rounded-full bg-teal-600
                          flex items-center justify-center text-white flex-shrink-0 shadow-sm">
                <i data-lucide="message-square-reply" class="w-4 h-4"></i>
              </div>
              <div class="min-w-0 flex-1">
                <p class="text-[11px] uppercase tracking-wider font-bold text-teal-700 mb-1.5">Reply from Grievance Cell</p>
                <p class="text-sm text-teal-900 leading-relaxed whitespace-pre-wrap break-words" id="viewGrievanceReply">—</p>
              </div>
            </div>
          </div>
        </div>

        <div class="mb-5 hidden" id="viewActionSection">
          <h4 class="text-sm font-bold text-teal-900 uppercase tracking-wider mb-3 flex items-center">
            <i data-lucide="list-checks" class="w-4 h-4 mr-2 text-teal-600"></i> Action Summary
          </h4>
          <div id="viewActionList" class="space-y-3"></div>
        </div>

        <div class="mb-5 hidden" id="viewAttachmentSection">
          <h4 class="text-sm font-bold text-teal-900 uppercase tracking-wider mb-3 flex items-center">
            <i data-lucide="paperclip" class="w-4 h-4 mr-2 text-teal-600"></i> Original Attachment
          </h4>
          <div class="rounded-xl border-2 border-teal-100 bg-white p-4">
            <div class="flex items-center justify-between gap-3 flex-wrap">
              <div class="flex items-center min-w-0">
                <div class="w-9 h-9 rounded-lg bg-teal-50 flex items-center justify-center flex-shrink-0">
                  <i data-lucide="file" class="w-4 h-4 text-teal-600"></i>
                </div>
                <div class="min-w-0 ml-3">
                  <p class="text-sm font-semibold text-teal-900 truncate" id="viewAttachmentName">attachment</p>
                  <p class="text-[11px] text-teal-900/60" id="viewAttachmentMeta">—</p>
                </div>
              </div>
              <div class="flex items-center gap-2 flex-shrink-0">
                <button type="button" id="viewAttachmentViewBtn"
                        class="inline-flex items-center px-3 py-2 rounded-lg
                               bg-white hover:bg-teal-600 text-teal-700 hover:text-white
                               border-2 border-teal-200 hover:border-teal-600
                               text-xs font-bold transition-all duration-200 hover:-translate-y-0.5 active:scale-95">
                  <i data-lucide="eye" class="w-3.5 h-3.5 mr-1.5"></i> View
                </button>
                <a id="viewAttachmentDownloadBtn" href="#" download
                   class="inline-flex items-center px-3 py-2 rounded-lg
                          bg-teal-600 hover:bg-teal-700 text-white
                          text-xs font-bold transition-all duration-200 hover:-translate-y-0.5 active:scale-95">
                  <i data-lucide="download" class="w-3.5 h-3.5 mr-1.5"></i> Download
                </a>
              </div>
            </div>
          </div>
        </div>

        <div class="mb-5 hidden" id="viewReplyAttachmentSection">
          <h4 class="text-sm font-bold text-teal-900 uppercase tracking-wider mb-3 flex items-center">
            <i data-lucide="paperclip" class="w-4 h-4 mr-2 text-teal-600"></i> Reply Attachment
          </h4>
          <div class="rounded-xl border-2 border-teal-200 bg-teal-50/60 p-4">
            <div class="flex items-center justify-between gap-3 flex-wrap">
              <div class="flex items-center min-w-0">
                <div class="w-9 h-9 rounded-lg bg-white flex items-center justify-center flex-shrink-0">
                  <i data-lucide="file" class="w-4 h-4 text-teal-600"></i>
                </div>
                <div class="min-w-0 ml-3">
                  <p class="text-sm font-semibold text-teal-900 truncate" id="viewReplyAttachmentName">attachment</p>
                  <p class="text-[11px] text-teal-900/60" id="viewReplyAttachmentMeta">—</p>
                </div>
              </div>
              <div class="flex items-center gap-2 flex-shrink-0">
                <button type="button" id="viewReplyAttachmentViewBtn"
                        class="inline-flex items-center px-3 py-2 rounded-lg
                               bg-white hover:bg-teal-600 text-teal-700 hover:text-white
                               border-2 border-teal-200 hover:border-teal-600
                               text-xs font-bold transition-all duration-200 hover:-translate-y-0.5 active:scale-95">
                  <i data-lucide="eye" class="w-3.5 h-3.5 mr-1.5"></i> View
                </button>
                <a id="viewReplyAttachmentDownloadBtn" href="#" download
                   class="inline-flex items-center px-3 py-2 rounded-lg
                          bg-teal-600 hover:bg-teal-700 text-white
                          text-xs font-bold transition-all duration-200 hover:-translate-y-0.5 active:scale-95">
                  <i data-lucide="download" class="w-3.5 h-3.5 mr-1.5"></i> Download
                </a>
              </div>
            </div>
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div class="rounded-xl border-2 border-teal-100 bg-teal-50/60 p-4">
            <p class="text-[11px] uppercase tracking-wider font-bold text-teal-900/60 mb-1">Submitted On</p>
            <p class="text-sm font-semibold text-teal-900" id="viewCreatedAt">—</p>
          </div>
          <div class="rounded-xl border-2 border-teal-100 bg-teal-50/60 p-4" id="viewUpdatedWrap">
            <p class="text-[11px] uppercase tracking-wider font-bold text-teal-900/60 mb-1">Last Updated</p>
            <p class="text-sm font-semibold text-teal-900" id="viewUpdatedAt">—</p>
          </div>
        </div>

      </div>

      <div class="px-6 py-4 border-t border-teal-100 bg-teal-50/40 flex items-center justify-end flex-shrink-0">
        <button type="button" data-modal-close="view"
                class="px-5 py-2.5 rounded-lg text-sm font-semibold text-teal-900
                       bg-white border-2 border-teal-200 hover:border-teal-600 hover:bg-teal-50
                       transition-all duration-200 active:scale-95">
          Close
        </button>
      </div>

    </div>
  </div>

  <!-- ============================================================ -->
  <!-- LIGHTBOX -->
  <!-- ============================================================ -->
  <div id="lightbox" class="hidden fixed inset-0 z-[100] bg-black/90 backdrop-blur-md animate-lightbox-in">
    <div id="lightboxToolbar"
         class="absolute top-0 left-0 right-0 px-4 py-3 flex items-center justify-between gap-3
                bg-gradient-to-b from-black/70 to-transparent z-10">
      <div class="flex items-center min-w-0 text-white">
        <i data-lucide="image" class="w-5 h-5 mr-2 flex-shrink-0"></i>
        <span class="text-sm font-semibold truncate" id="lightboxFilename">attachment</span>
      </div>
      <div class="flex items-center gap-2 flex-shrink-0">
        <a id="lightboxDownloadBtn" href="#" download
           class="inline-flex items-center px-4 py-2 rounded-lg
                  bg-white/10 hover:bg-white/20 text-white text-sm font-semibold
                  border border-white/20 transition-all duration-200 hover:-translate-y-0.5">
          <i data-lucide="download" class="w-4 h-4 mr-2"></i> Download
        </a>
        <button type="button" data-lightbox-close="1"
                class="inline-flex items-center justify-center w-10 h-10 rounded-lg
                       bg-white/10 hover:bg-red-500/80 text-white
                       border border-white/20 transition-all duration-200
                       active:scale-95 cursor-pointer" title="Close (Esc)" aria-label="Close">
          <i data-lucide="x" class="w-5 h-5 pointer-events-none"></i>
        </button>
      </div>
    </div>

    <div id="lightboxStage" class="absolute inset-0 pt-16 pb-4 px-4 flex items-center justify-center">
      <img id="lightboxImage" src="" alt="Attachment preview"
           class="max-w-full max-h-full object-contain rounded-lg shadow-2xl bg-white select-none" />
    </div>
  </div>

  <!-- ============================================================ -->
  <!-- ACTION SUMMARY MODAL -->
  <!-- ============================================================ -->
  <div id="actionModal" class="hidden fixed inset-0 z-[80] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" data-modal-close="action"></div>

    <div class="relative w-full max-w-xl max-h-[90vh] bg-white rounded-2xl shadow-2xl overflow-hidden
                flex flex-col animate-modal-in">

      <div class="h-1.5 w-full bg-teal-600 flex-shrink-0"></div>

      <div class="flex items-start justify-between px-6 pt-5 pb-4 flex-shrink-0">
        <div>
          <h3 class="text-xl md:text-2xl font-bold text-teal-900">Action Summary</h3>
          <p class="text-xs text-teal-900/60 mt-1">
            Ref: <span class="font-semibold text-teal-700" id="actionGrievanceNumber">—</span>
          </p>
        </div>
        <button type="button" data-modal-close="action"
                class="text-teal-900/40 hover:text-teal-900 hover:bg-teal-50 rounded-lg p-1.5 transition-colors" aria-label="Close">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form id="actionForm" method="post" action="grievances.php" class="px-6 pb-6 overflow-y-auto flex-1">
        <input type="hidden" name="form_action" value="action_summary" />
        <input type="hidden" name="grievance_id" id="actionGrievanceId" value="" />

        <div class="mb-4">
          <label for="actionDate" class="block text-sm font-semibold text-teal-900 mb-2">Date</label>
          <input type="date" id="actionDate" name="action_date" required
                 class="w-full px-4 py-2.5 border-2 border-teal-100 rounded-lg text-sm text-teal-900
                        focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10
                        hover:border-teal-400 transition-all bg-white" />
        </div>

        <div class="mb-4">
          <label for="actionAttendee" class="block text-sm font-semibold text-teal-900 mb-2">Attendee</label>
          <select id="actionAttendee" name="attendee" required
                  class="w-full px-4 py-2.5 border-2 border-teal-100 rounded-lg text-sm text-teal-900
                         focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10
                         hover:border-teal-400 transition-all bg-white">
            <option value="">Select Some Options</option>
            <?php foreach ($allCellMembers as $cm): ?>
              <?php
                $cmLabel = $cm['name'];
                if (!empty($cm['designation_name'])) {
                    $cmLabel .= ' — ' . $cm['designation_name'];
                }
              ?>
              <option value="<?= (int) $cm['id'] ?>"><?= e($cmLabel) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div id="actionPairRows" class="space-y-4"></div>

        <div class="mt-4 mb-6">
          <button type="button" id="actionAddRowBtn" title="Add another summary"
                  class="inline-flex items-center gap-2 px-4 py-2 rounded-lg
                         bg-teal-600 hover:bg-teal-700 text-white text-sm font-bold
                         shadow-sm hover:shadow-md
                         transition-all duration-200 hover:-translate-y-0.5 active:scale-95">
            <i data-lucide="plus" class="w-4 h-4"></i>
            <span>Add</span>
          </button>
        </div>

        <div class="flex items-center justify-end gap-3">
          <button type="submit"
                  class="inline-flex items-center justify-center px-6 py-2.5 rounded-lg
                         bg-teal-600 hover:bg-teal-700 text-white text-sm font-bold
                         shadow-sm hover:shadow-md
                         transition-all duration-200 hover:-translate-y-0.5 active:scale-95
                         disabled:opacity-50 disabled:cursor-not-allowed">
            <span>Submit</span>
          </button>

          <button type="button" data-modal-close="action"
                  class="inline-flex items-center justify-center px-6 py-2.5 rounded-lg
                         bg-white border-2 border-teal-200 hover:border-teal-600 hover:bg-teal-50
                         text-teal-900 text-sm font-bold
                         transition-all duration-200 active:scale-95">
            <span>Cancel</span>
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- ============================================================ -->
  <!-- LOGOUT MODAL -->
  <!-- ============================================================ -->
  <div id="logoutConfirmModal" class="hidden fixed inset-0 z-[90] flex items-center justify-center p-4">
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
        </p>

        <p class="text-xs text-teal-900/50 font-medium mt-3 flex items-center gap-1.5">
          <i data-lucide="info" class="w-3.5 h-3.5"></i> You can log back in anytime.
        </p>
      </div>

      <div class="px-6 py-5 mt-2 flex flex-col-reverse sm:flex-row gap-3">
        <button type="button" onclick="closeLogoutModal()"
                class="flex-1 px-5 py-3 rounded-lg font-semibold text-teal-900
                       bg-white border-2 border-teal-200 hover:border-teal-600 hover:bg-teal-50
                       transition-all duration-200">
          Cancel
        </button>

        <button type="button" id="confirmLogoutBtn"
                class="flex-1 px-5 py-3 rounded-lg font-bold text-white
                       bg-teal-600 hover:bg-teal-700
                       shadow-sm hover:shadow-md
                       transition-all duration-200
                       flex items-center justify-center gap-2">
          <i data-lucide="log-out" class="w-4 h-4"></i> <span>Log Out</span>
        </button>
      </div>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      if (typeof lucide !== 'undefined') { lucide.createIcons(); }

      const sidebar = document.getElementById('managementSidebar');
      const main    = document.getElementById('managementMain');

      /* Flash auto-hide */
      (function () {
        ['flashSuccessBox', 'flashErrorBox'].forEach(function (id) {
          const box = document.getElementById(id);
          if (!box) return;
          setTimeout(function () {
            box.classList.remove('animate-flash-in');
            box.classList.add('animate-flash-out');
            setTimeout(function () { if (box.parentNode) box.parentNode.removeChild(box); }, 500);
          }, 3500);
        });
      })();

      /* Sidebar toggle */
      (function () {
        const toggleBtn = document.getElementById('sidebarToggle');
        if (!toggleBtn || !sidebar || !main) return;

        const labels   = sidebar.querySelectorAll('.sidebar-label');
        const tooltips = sidebar.querySelectorAll('.sidebar-tooltip');
        const chevrons = sidebar.querySelectorAll('.submenu-chevron');
        const submenus = sidebar.querySelectorAll('.submenu');
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
            submenus.forEach(function (sm) { sm.classList.add('hidden'); });
            chevrons.forEach(function (ch) { ch.classList.remove('rotate-180'); });
          }
          setTimeout(function () { if (typeof lucide !== 'undefined') lucide.createIcons(); }, 250);
        });
      })();

      /* Submenu toggle */
      (function () {
        const toggles = document.querySelectorAll('[data-submenu-toggle]');
        if (!toggles.length) return;

        toggles.forEach(function (btn) {
          btn.addEventListener('click', function (e) {
            const sidebarEl = document.getElementById('managementSidebar');
            const isCollapsed = sidebarEl && sidebarEl.classList.contains('w-20');

            if (isCollapsed) {
              const href = btn.getAttribute('data-landing-href');
              if (href) window.location.href = href;
              return;
            }

            e.preventDefault(); e.stopPropagation();

            const sectionId = btn.getAttribute('data-submenu-toggle');
            const submenu   = document.getElementById('submenu-' + sectionId);
            const chevron   = btn.querySelector('.submenu-chevron');
            if (!submenu) return;

            const isOpen = !submenu.classList.contains('hidden');

            document.querySelectorAll('.submenu').forEach(function (sm) { if (sm !== submenu) sm.classList.add('hidden'); });
            document.querySelectorAll('.submenu-chevron').forEach(function (ch) { if (ch !== chevron) ch.classList.remove('rotate-180'); });

            if (isOpen) {
              submenu.classList.add('hidden');
              if (chevron) chevron.classList.remove('rotate-180');
            } else {
              submenu.classList.remove('hidden');
              if (chevron) chevron.classList.add('rotate-180');
            }
          });
        });
      })();

      /* Profile dropdown */
      (function () {
        const btn       = document.getElementById('management-dropdown-btn');
        const menu      = document.getElementById('management-dropdown-menu');
        const chevron   = document.getElementById('management-chevron');
        const container = document.getElementById('management-dropdown-container');
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

      /* Logout modal */
      const logoutConfirmModal = document.getElementById('logoutConfirmModal');
      const logoutConfirmPanel = document.getElementById('logoutConfirmPanel');
      const confirmLogoutBtn   = document.getElementById('confirmLogoutBtn');
      const LOGOUT_URL = '../logout.php?role=management';

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
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && logoutConfirmModal && !logoutConfirmModal.classList.contains('hidden')) window.closeLogoutModal();
      });

      /* Table search / pagination */
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
          return !r.querySelector('td[colspan]') && !r.querySelector('script');
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
          if (infoEnd)   infoEnd.textContent   = endIdx;
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

      /* Modals + Lightbox */
      const replyModal  = document.getElementById('replyModal');
      const viewModal   = document.getElementById('viewModal');
      const actionModal = document.getElementById('actionModal');
      const lightbox    = document.getElementById('lightbox');

      function openModal(modal) {
        if (!modal) return;
        modal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        if (typeof lucide !== 'undefined') lucide.createIcons();
      }
      function closeModal(modal) {
        if (!modal) return;
        modal.classList.add('hidden');
        const anyOpen = !replyModal.classList.contains('hidden')
                     || !viewModal.classList.contains('hidden')
                     || !actionModal.classList.contains('hidden')
                     || (lightbox && !lightbox.classList.contains('hidden'))
                     || (logoutConfirmModal && !logoutConfirmModal.classList.contains('hidden'));
        if (!anyOpen) document.body.classList.remove('overflow-hidden');
      }

      document.querySelectorAll('[data-modal-close="reply"]').forEach(function (el) {
        el.addEventListener('click', function () { closeModal(replyModal); });
      });
      document.querySelectorAll('[data-modal-close="view"]').forEach(function (el) {
        el.addEventListener('click', function () { closeModal(viewModal); });
      });
      document.querySelectorAll('[data-modal-close="action"]').forEach(function (el) {
        el.addEventListener('click', function () { closeModal(actionModal); });
      });

      /* Lightbox */
      const lightboxImage       = document.getElementById('lightboxImage');
      const lightboxFilename    = document.getElementById('lightboxFilename');
      const lightboxDownloadBtn = document.getElementById('lightboxDownloadBtn');
      const lightboxStage       = document.getElementById('lightboxStage');

      function openLightbox(url, filename) {
        if (!lightbox || !lightboxImage) return;
        lightboxImage.src = url || '';
        if (lightboxFilename) lightboxFilename.textContent = filename || 'attachment';
        if (lightboxDownloadBtn) {
          lightboxDownloadBtn.setAttribute('href', url || '#');
          lightboxDownloadBtn.setAttribute('download', filename || '');
        }
        lightbox.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        if (typeof lucide !== 'undefined') lucide.createIcons();
      }
      function closeLightbox() {
        if (!lightbox) return;
        lightbox.classList.add('hidden');
        if (lightboxImage) lightboxImage.src = '';
        const anyOpen = !replyModal.classList.contains('hidden')
                     || !viewModal.classList.contains('hidden')
                     || !actionModal.classList.contains('hidden')
                     || (logoutConfirmModal && !logoutConfirmModal.classList.contains('hidden'));
        if (!anyOpen) document.body.classList.remove('overflow-hidden');
      }

      document.addEventListener('click', function (e) {
        const closeEl = e.target.closest('[data-lightbox-close="1"]');
        if (closeEl && lightbox && !lightbox.classList.contains('hidden')) {
          e.preventDefault(); e.stopPropagation(); closeLightbox();
        }
      });
      if (lightboxStage) lightboxStage.addEventListener('click', function (e) { if (e.target === lightboxStage) closeLightbox(); });
      if (lightbox) lightbox.addEventListener('click', function (e) { if (e.target === lightbox) closeLightbox(); });

      const lightboxToolbar = document.getElementById('lightboxToolbar');
      if (lightboxToolbar) {
        lightboxToolbar.addEventListener('click', function (e) {
          if (!e.target.closest('[data-lightbox-close="1"]') && !e.target.closest('#lightboxDownloadBtn')) e.stopPropagation();
        });
      }
      document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (lightbox && !lightbox.classList.contains('hidden')) { closeLightbox(); return; }
        if (replyModal  && !replyModal.classList.contains('hidden'))  closeModal(replyModal);
        else if (viewModal   && !viewModal.classList.contains('hidden'))   closeModal(viewModal);
        else if (actionModal && !actionModal.classList.contains('hidden')) closeModal(actionModal);
      });

      /* Reply modal */
      const replyForm            = document.getElementById('replyForm');
      const replyGrievanceId     = document.getElementById('replyGrievanceId');
      const replyGrievanceNumber = document.getElementById('replyGrievanceNumber');
      const replyText            = document.getElementById('replyText');
      const replyCharCount       = document.getElementById('replyCharCount');
      const replyFile            = document.getElementById('replyFile');
      const replyFileName        = document.getElementById('replyFileName');

      document.querySelectorAll('[data-reply-trigger="1"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          const id  = btn.getAttribute('data-grievance-id') || '';
          const num = btn.getAttribute('data-grievance-number') || '—';
          if (replyGrievanceId)     replyGrievanceId.value = id;
          if (replyGrievanceNumber) replyGrievanceNumber.textContent = num;
          if (replyText)            replyText.value = '';
          if (replyCharCount)       replyCharCount.textContent = '0';
          if (replyFile)            replyFile.value = '';
          if (replyFileName)        replyFileName.textContent = 'No file chosen';
          openModal(replyModal);
          setTimeout(function () { if (replyText) replyText.focus(); }, 150);
        });
      });

      if (replyText && replyCharCount) {
        replyText.addEventListener('input', function () { replyCharCount.textContent = replyText.value.length; });
      }
      if (replyFile && replyFileName) {
        replyFile.addEventListener('change', function () {
          if (replyFile.files && replyFile.files.length > 0) {
            const f = replyFile.files[0];
            if (f.size > 5 * 1024 * 1024) {
              alert('File is larger than 5 MB. Please choose a smaller file.');
              replyFile.value = ''; replyFileName.textContent = 'No file chosen'; return;
            }
            replyFileName.textContent = f.name;
          } else {
            replyFileName.textContent = 'No file chosen';
          }
        });
      }
      if (replyForm) {
        replyForm.addEventListener('submit', function (e) {
          const txt = (replyText && replyText.value.trim()) || '';
          if (txt === '') {
            e.preventDefault(); alert('Please enter a reply.');
            if (replyText) replyText.focus(); return;
          }
          const submitBtn = replyForm.querySelector('button[type="submit"]');
          if (submitBtn) {
            submitBtn.classList.add('opacity-50', 'pointer-events-none');
            submitBtn.innerHTML = '<span>Submitting...</span>';
          }
        });
      }

      /* View modal refs */
      const viewGrievanceNumber      = document.getElementById('viewGrievanceNumber');
      const viewGrievanceType        = document.getElementById('viewGrievanceType');
      const viewGrievanceStatus      = document.getElementById('viewGrievanceStatus');
      const viewComplainantInitial   = document.getElementById('viewComplainantInitial');
      const viewComplainantName      = document.getElementById('viewComplainantName');
      const viewComplainantEmail     = document.getElementById('viewComplainantEmail');
      const viewComplainantPhone     = document.getElementById('viewComplainantPhone');
      const viewComplainantPhoneWrap = document.getElementById('viewComplainantPhoneWrap');
      const viewGrievanceSubject     = document.getElementById('viewGrievanceSubject');
      const viewGrievanceDescription = document.getElementById('viewGrievanceDescription');
      const viewReplySection         = document.getElementById('viewReplySection');
      const viewGrievanceReply       = document.getElementById('viewGrievanceReply');
      const viewCreatedAt            = document.getElementById('viewCreatedAt');
      const viewUpdatedAt            = document.getElementById('viewUpdatedAt');
      const viewReopenSection        = document.getElementById('viewReopenSection');
      const viewReopenReason         = document.getElementById('viewReopenReason');
      const viewActionSection        = document.getElementById('viewActionSection');
      const viewActionList           = document.getElementById('viewActionList');
      const viewAttachmentSection       = document.getElementById('viewAttachmentSection');
      const viewAttachmentName          = document.getElementById('viewAttachmentName');
      const viewAttachmentMeta          = document.getElementById('viewAttachmentMeta');
      const viewAttachmentViewBtn       = document.getElementById('viewAttachmentViewBtn');
      const viewAttachmentDownloadBtn   = document.getElementById('viewAttachmentDownloadBtn');
      const viewReplyAttachmentSection     = document.getElementById('viewReplyAttachmentSection');
      const viewReplyAttachmentName        = document.getElementById('viewReplyAttachmentName');
      const viewReplyAttachmentMeta        = document.getElementById('viewReplyAttachmentMeta');
      const viewReplyAttachmentViewBtn     = document.getElementById('viewReplyAttachmentViewBtn');
      const viewReplyAttachmentDownloadBtn = document.getElementById('viewReplyAttachmentDownloadBtn');

      function buildStatusBadgeClient(status) {
        const s = (status || '').trim();
        const map = {
          'Pending':     'bg-amber-100 text-amber-800 border-amber-200',
          'In Progress': 'bg-sky-100 text-sky-800 border-sky-200',
          'Disposed':    'bg-emerald-100 text-emerald-800 border-emerald-200',
          'Closed':      'bg-slate-200 text-slate-700 border-slate-300',
          'Reopened':    'bg-rose-100 text-rose-800 border-rose-200'
        };
        const cls = map[s] || 'bg-slate-100 text-slate-700 border-slate-200';
        const safe = s.replace(/[&<>"']/g, function (c) {
          return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
        return '<span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold uppercase tracking-wider border ' + cls + '">' + safe + '</span>';
      }
      function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
          return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
      }
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
      function handleAttachmentView(url, filename, isImage) {
        if (!url || url === '#') { alert('No attachment available.'); return; }
        if (isImage) openLightbox(url, filename);
        else window.open(url, '_blank', 'noopener');
      }

      document.querySelectorAll('[data-view-trigger="1"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          const id  = btn.getAttribute('data-grievance-id');
          const tag = document.getElementById('grievance-data-' + id);
          if (!tag) return;
          let data = {};
          try { data = JSON.parse(tag.textContent); } catch (e) { return; }

          if (viewGrievanceNumber) viewGrievanceNumber.textContent = data.number || '—';
          if (viewGrievanceType)   viewGrievanceType.textContent   = data.type   || '—';
          if (viewGrievanceStatus) viewGrievanceStatus.innerHTML = buildStatusBadgeClient(data.status || 'Pending');

          const name = data.complainant || '—';
          if (viewComplainantName)  viewComplainantName.textContent  = name;
          if (viewComplainantEmail) viewComplainantEmail.textContent = data.email || '—';
          if (viewComplainantInitial) viewComplainantInitial.textContent = (name.trim().charAt(0) || '?').toUpperCase();

          if (viewComplainantPhoneWrap && viewComplainantPhone) {
            if (data.phone) { viewComplainantPhone.textContent = data.phone; viewComplainantPhoneWrap.style.display = ''; }
            else { viewComplainantPhoneWrap.style.display = 'none'; }
          }

          if (viewGrievanceSubject)     viewGrievanceSubject.textContent     = data.subject     || '—';
          if (viewGrievanceDescription) viewGrievanceDescription.textContent = data.description || '—';

          if (viewReopenSection && viewReopenReason) {
            const rr = (data.reopen_reason == null ? '' : String(data.reopen_reason)).trim();
            if (rr !== '') { viewReopenReason.textContent = rr; viewReopenSection.classList.remove('hidden'); }
            else { viewReopenSection.classList.add('hidden'); viewReopenReason.textContent = '—'; }
          }

          if (viewReplySection && viewGrievanceReply) {
            const reply = (data.reply_details == null ? '' : String(data.reply_details)).trim();
            if (reply !== '') { viewGrievanceReply.textContent = reply; viewReplySection.classList.remove('hidden'); }
            else { viewReplySection.classList.add('hidden'); viewGrievanceReply.textContent = '—'; }
          }

          if (viewActionSection && viewActionList) {
            const actions = Array.isArray(data.actions) ? data.actions : [];
            if (actions.length > 0) {
              let html = '';
              actions.forEach(function (act, idx) {
                const dt = escapeHtml(act.date || '—');
                const aname = escapeHtml(act.attendee_name || '—');
                const desig = escapeHtml(act.attendee_desig || '');
                const summ = escapeHtml(act.summary || '—');
                const taken = escapeHtml(act.action_taken || '—');
                const by = escapeHtml(act.created_by || '');

                html +=
                  '<div class="rounded-xl border-2 border-teal-100 bg-teal-50/40 p-4">' +
                    '<div class="flex items-center justify-between gap-2 mb-2 flex-wrap">' +
                      '<div class="flex items-center gap-2">' +
                        '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider border border-teal-200 bg-white text-teal-700">Entry ' + (idx + 1) + '</span>' +
                        '<span class="text-[11px] text-teal-900/70 flex items-center">' +
                          '<i data-lucide="calendar" class="w-3.5 h-3.5 mr-1 text-teal-600"></i>' + dt +
                        '</span>' +
                      '</div>' +
                      '<span class="text-[11px] text-teal-900/70 flex items-center">' +
                        '<i data-lucide="user-check" class="w-3.5 h-3.5 mr-1 text-teal-600"></i>' +
                        aname + (desig ? ' — ' + desig : '') +
                      '</span>' +
                    '</div>' +
                    '<div class="mb-2">' +
                      '<p class="text-[10px] uppercase tracking-wider font-bold text-teal-900/60 mb-0.5">Grievance Summary</p>' +
                      '<p class="text-sm text-teal-900 leading-relaxed whitespace-pre-wrap break-words">' + summ + '</p>' +
                    '</div>' +
                    '<div class="pt-2 border-t border-teal-100">' +
                      '<p class="text-[10px] uppercase tracking-wider font-bold text-teal-900/60 mb-0.5">Action Taken</p>' +
                      '<p class="text-sm text-teal-900 leading-relaxed whitespace-pre-wrap break-words">' + taken + '</p>' +
                    '</div>' +
                    (by ? '<p class="text-[10px] text-teal-900/60 mt-2">Submitted by: ' + by + '</p>' : '') +
                  '</div>';
              });
              viewActionList.innerHTML = html;
              viewActionSection.classList.remove('hidden');
            } else {
              viewActionSection.classList.add('hidden');
              viewActionList.innerHTML = '';
            }
          }

          if (viewAttachmentSection) {
            const hasOrig = data.attachment_url && data.attachment_url.trim() !== '';
            if (hasOrig) {
              viewAttachmentSection.classList.remove('hidden');
              const origName = filenameFromUrl(data.attachment_url);
              const origExt = (data.attachment_ext || '').toUpperCase();
              if (viewAttachmentName) viewAttachmentName.textContent = origName;
              if (viewAttachmentMeta) viewAttachmentMeta.textContent =
                (origExt ? origExt + ' • ' : '') + (data.attachment_is_image ? 'Image' : 'Document');

              if (viewAttachmentDownloadBtn) {
                viewAttachmentDownloadBtn.setAttribute('href', data.attachment_url);
                viewAttachmentDownloadBtn.setAttribute('download', origName);
              }
              if (viewAttachmentViewBtn) {
                const newBtn = viewAttachmentViewBtn.cloneNode(true);
                viewAttachmentViewBtn.parentNode.replaceChild(newBtn, viewAttachmentViewBtn);
                newBtn.addEventListener('click', function (e) {
                  e.preventDefault(); e.stopPropagation();
                  handleAttachmentView(data.attachment_url, origName, !!data.attachment_is_image);
                });
              }
            } else {
              viewAttachmentSection.classList.add('hidden');
            }
          }

          if (viewReplyAttachmentSection) {
            const hasReply = data.reply_attachment_url && data.reply_attachment_url.trim() !== '';
            if (hasReply) {
              viewReplyAttachmentSection.classList.remove('hidden');
              const repName = filenameFromUrl(data.reply_attachment_url);
              const repExt = (data.reply_attachment_ext || '').toUpperCase();
              if (viewReplyAttachmentName) viewReplyAttachmentName.textContent = repName;
              if (viewReplyAttachmentMeta) viewReplyAttachmentMeta.textContent =
                (repExt ? repExt + ' • ' : '') + (data.reply_attachment_is_image ? 'Image' : 'Document');

              if (viewReplyAttachmentDownloadBtn) {
                viewReplyAttachmentDownloadBtn.setAttribute('href', data.reply_attachment_url);
                viewReplyAttachmentDownloadBtn.setAttribute('download', repName);
              }
              if (viewReplyAttachmentViewBtn) {
                const newBtn = viewReplyAttachmentViewBtn.cloneNode(true);
                viewReplyAttachmentViewBtn.parentNode.replaceChild(newBtn, viewReplyAttachmentViewBtn);
                newBtn.addEventListener('click', function (e) {
                  e.preventDefault(); e.stopPropagation();
                  handleAttachmentView(data.reply_attachment_url, repName, !!data.reply_attachment_is_image);
                });
              }
            } else {
              viewReplyAttachmentSection.classList.add('hidden');
            }
          }

          if (viewCreatedAt) viewCreatedAt.textContent = data.created_at || '—';
          if (viewUpdatedAt) viewUpdatedAt.textContent = data.updated_at || '—';

          openModal(viewModal);
        });
      });

      /* Action Summary modal */
      const actionForm            = document.getElementById('actionForm');
      const actionGrievanceId     = document.getElementById('actionGrievanceId');
      const actionGrievanceNumber = document.getElementById('actionGrievanceNumber');
      const actionDate            = document.getElementById('actionDate');
      const actionAttendee        = document.getElementById('actionAttendee');
      const actionPairRows        = document.getElementById('actionPairRows');
      const actionAddRowBtn       = document.getElementById('actionAddRowBtn');

      function todayISO() {
        const d = new Date();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return d.getFullYear() + '-' + m + '-' + day;
      }

      function addPairRow(isFirst) {
        if (!actionPairRows) return;

        const wrap = document.createElement('div');
        wrap.className = 'action-pair-row rounded-xl border-2 border-teal-100 bg-teal-50/40 p-3';

        wrap.innerHTML =
          '<div class="mb-3">' +
            '<label class="block text-sm font-semibold text-teal-900 mb-2">Grievance summary</label>' +
            '<textarea name="action_summary[]" rows="2" required' +
            ' class="w-full px-4 py-2.5 border-2 border-teal-100 rounded-lg text-sm text-teal-900 bg-white' +
            ' focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10' +
            ' hover:border-teal-400 transition-all resize-none"' +
            ' placeholder=""></textarea>' +
          '</div>' +
          '<div class="flex items-end gap-2">' +
            '<div class="flex-1">' +
              '<label class="block text-sm font-semibold text-teal-900 mb-2">Action taken</label>' +
              '<textarea name="action_taken[]" rows="2"' +
              ' class="w-full px-4 py-2.5 border-2 border-teal-100 rounded-lg text-sm text-teal-900 bg-white' +
              ' focus:outline-none focus:border-teal-600 focus:ring-4 focus:ring-teal-600/10' +
              ' hover:border-teal-400 transition-all resize-none"' +
              ' placeholder=""></textarea>' +
            '</div>' +
            '<button type="button" class="action-remove-pair inline-flex items-center justify-center w-10 h-10 rounded-lg' +
            ' bg-rose-100 hover:bg-rose-200 text-rose-700 transition-colors flex-shrink-0 mb-[2px]"' +
            ' title="Remove this row">' +
            '<i data-lucide="x" class="w-4 h-4"></i>' +
            '</button>' +
          '</div>';

        actionPairRows.appendChild(wrap);

        if (typeof lucide !== 'undefined') lucide.createIcons();

        const removeBtn = wrap.querySelector('.action-remove-pair');
        if (removeBtn) {
          removeBtn.addEventListener('click', function () {
            if (actionPairRows.querySelectorAll('.action-pair-row').length > 1) {
              wrap.remove();
            } else {
              alert('At least one row is required.');
            }
          });
        }
      }

      function resetPairRows() {
        if (!actionPairRows) return;
        actionPairRows.innerHTML = '';
        addPairRow(true);
      }

      if (actionAddRowBtn) {
        actionAddRowBtn.addEventListener('click', function () { addPairRow(false); });
      }

      document.querySelectorAll('[data-action-trigger="1"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          const id  = btn.getAttribute('data-grievance-id') || '';
          const num = btn.getAttribute('data-grievance-number') || '—';
          if (actionGrievanceId)     actionGrievanceId.value = id;
          if (actionGrievanceNumber) actionGrievanceNumber.textContent = num;
          if (actionDate)            actionDate.value = todayISO();
          if (actionAttendee)        actionAttendee.value = '';
          resetPairRows();
          openModal(actionModal);
          setTimeout(function () { if (actionDate) actionDate.focus(); }, 150);
        });
      });

      if (actionForm) {
        actionForm.addEventListener('submit', function (e) {
          const dateVal = (actionDate && actionDate.value) || '';
          const attVal  = (actionAttendee && actionAttendee.value) || '';
          if (dateVal === '') { e.preventDefault(); alert('Please select a date.'); if (actionDate) actionDate.focus(); return; }
          if (attVal === '')  { e.preventDefault(); alert('Please select an attendee.'); if (actionAttendee) actionAttendee.focus(); return; }

          const summaries = actionForm.querySelectorAll('textarea[name="action_summary[]"]');
          let hasAny = false;
          summaries.forEach(function (t) { if (t.value.trim() !== '') hasAny = true; });
          if (!hasAny) {
            e.preventDefault();
            alert('Please enter at least one grievance summary.');
            if (summaries[0]) summaries[0].focus();
            return;
          }

          const submitBtn = actionForm.querySelector('button[type="submit"]');
          if (submitBtn) {
            submitBtn.classList.add('opacity-50', 'pointer-events-none');
            submitBtn.innerHTML = '<span>Submitting...</span>';
          }
        });
      }
    });
  </script>

  <script src="../assets/js/index.js"></script>
</body>
</html>
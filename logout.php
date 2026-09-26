<?php
/**
 * logout.php
 * ---------------------------------------------------------------------------
 * Session Terminator — Rajagiri College Grievance Redressal Portal
 *
 * Destroys the current session and redirects to the appropriate login page.
 * Optionally accepts ?role=xxx to redirect to a specific login portal.
 *
 * Supported ?role= keys (match the keys used in login.php):
 *   admin | student | parent | staff | management
 *
 * Notes:
 *   • 'staff' maps internally to the TEACHER + NON_TEACHING roles in login.php,
 *     so we allow both 'staff' (portal key) and the raw DB role names here to
 *     keep old bookmarks / in-flight links working.
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// 1. START / RESUME SESSION
// ---------------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---------------------------------------------------------------------------
// 2. CAPTURE ROLE BEFORE CLEARING (for redirect decision)
// ---------------------------------------------------------------------------
$role = isset($_GET['role']) ? strtolower(trim((string) $_GET['role'])) : '';

if ($role === '' && !empty($_SESSION['role'])) {
    $role = strtolower((string) $_SESSION['role']);
}

// ---------------------------------------------------------------------------
// 3. NORMALIZE ROLE ALIASES
//    Map raw DB role names → login.php portal keys
// ---------------------------------------------------------------------------
$roleAliases = [
    'teacher'      => 'staff',
    'non_teaching' => 'staff',
    'non-teaching' => 'staff',
];

if (isset($roleAliases[$role])) {
    $role = $roleAliases[$role];
}

// ---------------------------------------------------------------------------
// 4. CLEAR ALL SESSION DATA
// ---------------------------------------------------------------------------
$_SESSION = [];

// Destroy the session cookie in the browser
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        [
            'expires'  => time() - 42000,
            'path'     => $params['path']     ?? '/',
            'domain'   => $params['domain']   ?? '',
            'secure'   => $params['secure']   ?? false,
            'httponly' => $params['httponly'] ?? true,
            'samesite' => $params['samesite'] ?? 'Lax',
        ]
    );
}

// Destroy server-side session
session_unset();
session_destroy();

// ---------------------------------------------------------------------------
// 5. REDIRECT TO APPROPRIATE LOGIN PAGE
//    These keys must match the URL keys used in login.php.
// ---------------------------------------------------------------------------
$allowedRoles = ['admin', 'student', 'parent', 'staff', 'management'];

if (in_array($role, $allowedRoles, true)) {
    header('Location: login.php?role=' . $role);
} else {
    // Default to the main login page
    header('Location: login.php');
}
exit;
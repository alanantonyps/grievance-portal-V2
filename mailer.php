<?php
declare(strict_types=1);

/**
 * mailer.php
 * -----------------------------------------------------------
 * Simple mail helper for the Grievance Portal.
 * Uses PHP's built-in mail() and logs to `mail_logs` table.
 * -----------------------------------------------------------
 */
function send_mail(string $to, string $subject, string $htmlBody, ?mysqli $conn = null): bool
{
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: Grievance Portal <no-reply@rajagiricss.edu>\r\n";
    $headers .= "Reply-To: grievance@rajagiricss.edu\r\n";

    $ok = @mail($to, $subject, $htmlBody, $headers);

    if ($conn instanceof mysqli) {
        $status = $ok ? 'SUCCESS' : 'FAILED';
        $stmt = $conn->prepare("INSERT INTO mail_logs (mail_id, subject, is_send) VALUES (?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param('sss', $to, $subject, $status);
            $stmt->execute();
            $stmt->close();
        }
    }
    return $ok;
}
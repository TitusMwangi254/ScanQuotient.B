<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require 'C:/Users/1/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/* --- DB Connection --- */
$servername = '127.0.0.1';
$dbname = 'scanquotient.a1';
$dbuser = 'root';
$dbpass = '';

$conn = new mysqli($servername, $dbuser, $dbpass, $dbname);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'DB connection failed']);
    exit;
}

/* --- Read JSON payload --- */
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON payload']);
    $conn->close();
    exit;
}

$action = trim((string) ($data['action'] ?? ''));
$unique_id = trim((string) ($data['unique_id'] ?? ''));

if ($action === '' || $unique_id === '') {
    echo json_encode(['status' => 'error', 'message' => 'Missing action or unique_id']);
    $conn->close();
    exit;
}

/* --- Ensure session user_id --- */
$user_id = isset($_SESSION['user_id']) ? trim((string) $_SESSION['user_id']) : null;
$user_role = isset($_SESSION['role']) ? trim((string) $_SESSION['role']) : 'user';
if (!$user_id) {
    echo json_encode(['status' => 'error', 'message' => 'User not logged in']);
    $conn->close();
    exit;
}

/* --- Helper: check ticket exists --- */
function ticket_exists($conn, $unique_id)
{
    $stmt = $conn->prepare("SELECT id FROM support_tickets WHERE unique_id = ? LIMIT 1");
    if (!$stmt)
        return false;
    $stmt->bind_param("s", $unique_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = ($res && $res->num_rows > 0);
    $stmt->close();
    return $exists;
}

/* --- Helper: send email --- */
function send_ticket_email($conn, $unique_id, $action_type, $action_message = '')
{
    $stmt = $conn->prepare("SELECT name, email FROM support_tickets WHERE unique_id = ? LIMIT 1");
    $stmt->bind_param("s", $unique_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $ticket = $res->fetch_assoc();
    $stmt->close();

    if (!$ticket || empty($ticket['email'])) {
        error_log("Email not sent: No email found for ticket {$unique_id}");
        return false;
    }

    $recipientEmail = $ticket['email'];
    $recipientName = $ticket['name'] ?? 'User';

    // Build email content based on action type
    $subject = "Update on Your Support Ticket #{$unique_id}";

    $body = "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #f8fafc; padding: 20px;'>
        <div style='background: linear-gradient(135deg, #2563eb, #7c3aed); padding: 30px; border-radius: 10px 10px 0 0; text-align: center;'>
            <h1 style='color: white; margin: 0; font-size: 24px;'>ScanQuotient Support</h1>
        </div>
        
        <div style='background: white; padding: 30px; border-radius: 0 0 10px 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);'>
            <p style='font-size: 16px; color: #1a202c;'>Hello <strong>" . htmlspecialchars($recipientName) . "</strong>,</p>
            
            <p style='color: #4a5568; line-height: 1.6;'>Your support ticket <strong>#{$unique_id}</strong> has been updated:</p>
            
            <div style='background: #f0f4f8; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #2563eb;'>
                <p style='margin: 0 0 10px 0; color: #4a5568;'><strong>Action:</strong> " . htmlspecialchars($action_type) . "</p>";

    if (!empty($action_message)) {
        $body .= "<p style='margin: 0; color: #4a5568;'><strong>Details:</strong> " . nl2br(htmlspecialchars($action_message)) . "</p>";
    }

    $body .= "
            </div>
            
            <p style='color: #4a5568;'>If you have any questions, please reply to this email or contact our support team.</p>
            
            <hr style='border: none; border-top: 1px solid #e2e8f0; margin: 25px 0;'>
            
            <p style='color: #94a3b8; font-size: 12px;'>
                This is an automated message from ScanQuotient Support.<br>
                Please do not reply directly to this email.
            </p>
        </div>
    </div>";

    $altBody = "Hello {$recipientName},\n\n"
        . "Your support ticket #{$unique_id} has been updated:\n"
        . "Action: {$action_type}\n";
    if (!empty($action_message)) {
        $altBody .= "Details: {$action_message}\n";
    }
    $altBody .= "\nBest regards,\nScanQuotient Support Team";

    $mail = new PHPMailer(true);
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'scanquotient@gmail.com';
        $mail->Password = 'vnht iefe anwl xynb';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        // Enable debug output for troubleshooting (remove in production)
        // $mail->SMTPDebug = 2;

        // Recipients
        $mail->setFrom('scanquotient@gmail.com', 'ScanQuotient Support');
        $mail->addAddress($recipientEmail, $recipientName);
        $mail->addReplyTo('scanquotient@gmail.com', 'ScanQuotient Support');

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = $altBody;

        // Send email
        $mail->send();
        error_log("Email sent successfully to {$recipientEmail} for ticket {$unique_id}");
        return true;

    } catch (Exception $e) {
        error_log("PHPMailer Error for ticket {$unique_id}: " . $mail->ErrorInfo);
        return false;
    }
}

if (!ticket_exists($conn, $unique_id)) {
    echo json_encode(['status' => 'error', 'message' => 'Ticket not found']);
    $conn->close();
    exit;
}

$allowed_statuses = ['open', 'in_progress', 'resolved', 'closed'];

/* --- Dispatch actions --- */
$email_sent = false;
$response_message = '';

switch ($action) {

    // ---------------- RESOLUTION ----------------
    case 'resolution':
        $resolution = trim((string) ($data['resolution'] ?? ''));
        if ($resolution === '') {
            echo json_encode(['status' => 'error', 'message' => 'Resolution cannot be empty']);
            break;
        }

        $stmt = $conn->prepare("UPDATE support_tickets SET answer = ?, resolver_id = ?, updated_at = NOW() WHERE unique_id = ?");
        $stmt->bind_param("sss", $resolution, $user_id, $unique_id);
        if ($stmt->execute()) {
            $email_sent = send_ticket_email($conn, $unique_id, 'Resolution Added', $resolution);
            $response_message = 'Resolution saved' . ($email_sent ? ' and email sent' : ' (email failed)');
            echo json_encode(['status' => 'ok', 'message' => $response_message, 'email_sent' => $email_sent]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to save resolution']);
        }
        $stmt->close();
        break;

    // ---------------- ADMIN REPLY ----------------
    case 'reply':
        $newReply = trim((string) ($data['reply'] ?? ''));
        if ($newReply === '') {
            echo json_encode(['status' => 'error', 'message' => 'Reply cannot be empty']);
            break;
        }

        $stmt = $conn->prepare("SELECT admin_reply FROM support_tickets WHERE unique_id = ? LIMIT 1");
        $stmt->bind_param("s", $unique_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();

        $existing = isset($row['admin_reply']) ? trim((string) $row['admin_reply']) : '';
        $finalReply = ($existing === '' || $existing === null || $existing === '—') ? $newReply : $existing . "\n\n" . $newReply;

        $stmt = $conn->prepare("UPDATE support_tickets SET admin_reply = ?, updated_at = NOW() WHERE unique_id = ?");
        $stmt->bind_param("ss", $finalReply, $unique_id);
        if ($stmt->execute()) {
            $email_sent = send_ticket_email($conn, $unique_id, 'Admin Reply', $newReply);
            $response_message = 'Admin reply saved' . ($email_sent ? ' and email sent' : ' (email failed)');
            echo json_encode(['status' => 'ok', 'message' => $response_message, 'admin_reply' => $finalReply, 'email_sent' => $email_sent]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to save reply']);
        }
        $stmt->close();
        break;

    // ---------------- CHANGE STATUS ----------------
    case 'status':
        $status = trim((string) ($data['status'] ?? ''));
        if ($status === '') {
            echo json_encode(['status' => 'error', 'message' => 'Status cannot be empty']);
            break;
        }
        if (!in_array($status, $allowed_statuses, true)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid status']);
            break;
        }

        if ($status === 'closed') {
            $closed_by = ($user_role === 'admin') ? 'admin' : $user_id;
            $stmt = $conn->prepare("UPDATE support_tickets SET status = ?, closed_by = ?, updated_at = NOW() WHERE unique_id = ?");
            $stmt->bind_param("sss", $status, $closed_by, $unique_id);
        } else {
            $stmt = $conn->prepare("UPDATE support_tickets SET status = ?, updated_at = NOW() WHERE unique_id = ?");
            $stmt->bind_param("ss", $status, $unique_id);
        }

        if ($stmt->execute()) {
            $email_sent = send_ticket_email($conn, $unique_id, 'Status Updated', "New status: " . ucfirst($status));
            $response_message = 'Status updated' . ($email_sent ? ' and email sent' : ' (email failed)');
            echo json_encode(['status' => 'ok', 'message' => $response_message, 'email_sent' => $email_sent]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to update status']);
        }
        $stmt->close();
        break;

    // ---------------- DELETE TICKET (soft delete) ----------------
    case 'delete':
        $stmt = $conn->prepare("UPDATE support_tickets SET deleted_at = NOW(), deleted_by = ? WHERE unique_id = ?");
        $stmt->bind_param("ss", $user_id, $unique_id);
        if ($stmt->execute()) {
            // Optional: Send email for deletion too
            // $email_sent = send_ticket_email($conn, $unique_id, 'Ticket Closed', 'Your ticket has been closed.');
            echo json_encode(['status' => 'ok', 'message' => 'Ticket marked as deleted']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to delete ticket']);
        }
        $stmt->close();
        break;

    // ---------------- RESTORE TICKET ----------------
    case 'restore':
        $stmt = $conn->prepare("UPDATE support_tickets SET deleted_at = NULL, deleted_by = NULL, updated_at = NOW() WHERE unique_id = ? AND deleted_at IS NOT NULL");
        $stmt->bind_param("s", $unique_id);
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                echo json_encode(['status' => 'ok', 'message' => 'Ticket restored successfully']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Ticket is not in deleted state']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to restore ticket']);
        }
        $stmt->close();
        break;

    // ---------------- PERMANENT DELETE (only from deleted tickets) ----------------
    case 'permanent_delete':
        $stmt = $conn->prepare("DELETE FROM support_tickets WHERE unique_id = ? AND deleted_at IS NOT NULL");
        $stmt->bind_param("s", $unique_id);
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                echo json_encode(['status' => 'ok', 'message' => 'Ticket permanently deleted']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Ticket must be in deleted state before permanent delete']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Failed to permanently delete ticket']);
        }
        $stmt->close();
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
        break;
}

$conn->close();
exit;
?>
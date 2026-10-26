
<?php
ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
    'path' => '/'
]);
session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/config/db.php';

$token = $_POST['csrf_token'] ?? '';
$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

if (
    $_SERVER['REQUEST_METHOD'] !== 'POST' ||
    !isset($_SESSION['customer_csrf']) ||
    !is_string($token) ||
    !hash_equals($_SESSION['customer_csrf'], $token) ||
    !$id || $id < 1
) {
    http_response_code(403);
    exit('Request अवैध आहे. पुन्हा प्रयत्न करा.');
}

try {
    $stmt = $pdo->prepare("DELETE FROM customers WHERE id = ?");
    $stmt->execute([$id]);

    $_SESSION['customer_flash'] = [
        'type' => 'success',
        'message' => $stmt->rowCount()
            ? 'ग्राहक Delete केला.'
            : 'ग्राहक सापडला नाही.'
    ];
} catch (PDOException $e) {
    error_log($e->getMessage());

    $_SESSION['customer_flash'] = [
        'type' => 'error',
        'message' => 'हा ग्राहक Delete करता आला नाही. संबंधित कामांची नोंद तपासा.'
    ];
}

header('Location: customers.php');
exit;

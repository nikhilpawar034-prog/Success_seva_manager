
<?php
ini_set('session.use_strict_mode', '1');

session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS'])
        && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
    'path' => '/'
]);

session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

$token = $_POST['csrf_token'] ?? '';

if (
    !isset($_SESSION['admin_id'], $_SESSION['logout_csrf']) ||
    !is_string($token) ||
    !hash_equals($_SESSION['logout_csrf'], $token)
) {
    http_response_code(403);
    exit('Invalid request. कृपया पुन्हा प्रयत्न करा.');
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'] ?? 'Lax'
    ]);
}

session_destroy();

header('Location: login.php');
exit;

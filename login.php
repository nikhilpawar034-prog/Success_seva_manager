
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
require_once __DIR__ . '/config/db.php';

if (isset($_SESSION['admin_id'])) {
    header('Location: dashboard.php');
    exit;
}

if (empty($_SESSION['login_csrf'])) {
    $_SESSION['login_csrf'] = bin2hex(random_bytes(32));
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['login_csrf'], $token)
    ) {
        $error = 'Request अवैध आहे. पेज Refresh करून पुन्हा प्रयत्न करा.';
    } elseif (!is_string($password) || strlen($password) > 72) {
        $error = 'Username किंवा Password चुकीचा आहे.';
    } else {
        $stmt = $pdo->prepare(
            "SELECT id, name, username, password
             FROM users WHERE username = ? LIMIT 1"
        );
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password'])) {
            session_regenerate_id(true);

            $_SESSION['admin_id'] = (int) $admin['id'];
            $_SESSION['admin_name'] = $admin['name'];
            $_SESSION['login_csrf'] = bin2hex(random_bytes(32));

            header('Location: dashboard.php');
            exit;
        }

        $error = 'Username किंवा Password चुकीचा आहे.';
    }
}
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin Login | Success Online Service And Management System</title>
<style>
* { box-sizing: border-box; }
body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #edf2f7;
    min-height: 100vh;
    display: grid;
    place-items: center;
    padding: 20px;
}
.card {
    background: white;
    width: 100%;
    max-width: 400px;
    padding: 30px;
    border-radius: 14px;
    box-shadow: 0 8px 30px #00000012;
}
h1 { color: #173b70; font-size: 25px; }
p { color: #64748b; }
label { display: block; margin-top: 16px; }
input {
    width: 100%;
    padding: 12px;
    margin-top: 7px;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
}
button {
    width: 100%;
    padding: 12px;
    margin-top: 22px;
    border: 0;
    border-radius: 7px;
    color: white;
    background: #173b70;
    cursor: pointer;
}
.error { color: #b91c1c; }
.success { color: #15803d; }
</style>
</head>
<body>
<div class="card">
    <h1>Success Online Service And Management System</h1>
    <p>Admin Login Portal</p>

    <?php if (isset($_GET['setup']) && $_GET['setup'] === 'success'): ?>
        <p class="success">Admin account तयार झाले. आता Login करा.</p>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <p class="error">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </p>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf_token"
               value="<?= htmlspecialchars($_SESSION['login_csrf'], ENT_QUOTES, 'UTF-8') ?>">

        <label for="username">Username</label>
        <input id="username" type="text" name="username"
               maxlength="50" autocomplete="username" required>

        <label for="password">Password</label>
        <input id="password" type="password" name="password"
               maxlength="72" autocomplete="current-password" required>

        <button type="submit">Login</button>
    </form>
</div>
</body>
</html>

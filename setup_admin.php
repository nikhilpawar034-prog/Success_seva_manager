
<?php
session_start();
require_once __DIR__ . '/config/db.php';

if (!isset($_SESSION['setup_csrf'])) {
    $_SESSION['setup_csrf'] = bin2hex(random_bytes(32));
}

$count = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

if ($count > 0) {
    exit("Admin account आधीच तयार आहे. Setup बंद केले आहे.");
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) ||
        !hash_equals($_SESSION['setup_csrf'], $token)) {
        $error = 'Invalid request. पुन्हा प्रयत्न करा.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (
            $name === '' ||
            !preg_match('/^[a-zA-Z0-9_.-]{4,50}$/', $username) ||
            !is_string($password) ||
            strlen($password) < 12 ||
            strlen($password) > 72
        ) {
            $error = 'नाव भरा, Username 4-50 अक्षरांचा ठेवा आणि Password 12-72 bytes चा ठेवा.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            try {
                $stmt = $pdo->prepare(
                    "INSERT INTO users (name, username, password)
                     VALUES (?, ?, ?)"
                );
                $stmt->execute([$name, $username, $hash]);

                unset($_SESSION['setup_csrf']);
                header('Location: login.php?setup=success');
                exit;
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $error = 'हे Username आधीच वापरले आहे.';
                } else {
                    error_log($e->getMessage());
                    $error = 'Account तयार करता आले नाही.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin Setup | Success Seva Manager</title>
</head>
<body>
<h2>Create Admin Account</h2>

<?php if ($error !== ''): ?>
<p style="color:red">
    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
</p>
<?php endif; ?>

<form method="post">
    <input type="hidden" name="csrf_token"
           value="<?= htmlspecialchars($_SESSION['setup_csrf'], ENT_QUOTES, 'UTF-8') ?>">

    <label>Full Name</label><br>
    <input type="text" name="name" maxlength="100" required><br><br>

    <label>Username</label><br>
    <input type="text" name="username" minlength="4"
           maxlength="50" pattern="[a-zA-Z0-9_.-]{4,50}"
           autocomplete="username" required><br><br>

    <label>Password (किमान 12 अक्षरे)</label><br>
    <input type="password" name="password" minlength="12"
           maxlength="72" autocomplete="new-password" required><br><br>

    <button type="submit">Create Admin</button>
</form>
</body>
</html>


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

if (empty($_SESSION['customer_csrf'])) {
    $_SESSION['customer_csrf'] = bin2hex(random_bytes(32));
}
if (empty($_SESSION['logout_csrf'])) {
    $_SESSION['logout_csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$name = '';
$mobile = '';
$address = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $name = trim($_POST['name'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $address = trim($_POST['address'] ?? '');

    if (!is_string($token) ||
        !hash_equals($_SESSION['customer_csrf'], $token)) {
        $error = 'Request अवैध आहे. पेज Refresh करून पुन्हा प्रयत्न करा.';
    } elseif (
        $name === '' || strlen($name) > 150 ||
        !preg_match('/^\+?[0-9]{10,13}$/', $mobile) ||
        strlen($address) > 5000
    ) {
        $error = 'नाव, मोबाइल नंबर आणि पत्ता तपासा. मोबाइल 10 ते 13 अंकांचा असावा.';
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO customers (name, mobile, address)
             VALUES (?, ?, ?)"
        );
        $stmt->execute([$name, $mobile, $address ?: null]);

        $_SESSION['customer_flash'] = [
            'type' => 'success',
            'message' => 'नवीन ग्राहक यशस्वीपणे नोंदवला.'
        ];

        header('Location: customers.php');
        exit;
    }
}

$activePage = 'customers';
$pageTitle = 'Add Customer';
$pageSubtitle = 'नवीन ग्राहकाची माहिती भरा';

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Add Customer | Success Seva Manager</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="app-shell">
<?php include __DIR__ . '/includes/sidebar.php'; ?>
<div class="main-area">
<?php include __DIR__ . '/includes/header.php'; ?>

<div class="form-card">
    <div class="panel-header">
        <h2 class="panel-title">Customer Information</h2>
        <a class="btn btn-light" href="customers.php">← Back</a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['customer_csrf']) ?>">

        <div class="form-grid">
            <div class="form-group full">
                <label class="form-label" for="name">Customer Full Name *</label>
                <input class="form-control" id="name" name="name"
                    maxlength="150" value="<?= h($name) ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label" for="mobile">Mobile Number *</label>
                <input class="form-control" id="mobile" name="mobile"
                    type="tel" maxlength="14" value="<?= h($mobile) ?>"
                    placeholder="उदा. 9876543210" required>
                <span class="form-help">10 ते 13 अंक; सुरुवातीला + वापरू शकता.</span>
            </div>

            <div class="form-group full">
                <label class="form-label" for="address">Full Address</label>
                <textarea class="form-control" id="address" name="address"
                    rows="4" maxlength="5000"><?= h($address) ?></textarea>
            </div>
        </div>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Save Customer</button>
            <a class="btn btn-light" href="customers.php">Cancel</a>
        </div>
    </form>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

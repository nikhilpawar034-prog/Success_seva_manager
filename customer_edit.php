
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

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    http_response_code(400);
    exit('अवैध Customer ID.');
}

$stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$id]);
$customer = $stmt->fetch();

if (!$customer) {
    http_response_code(404);
    exit('ग्राहक सापडला नाही.');
}

$name = $customer['name'];
$mobile = $customer['mobile'];
$address = $customer['address'] ?? '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $name = trim($_POST['name'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $address = trim($_POST['address'] ?? '');

    if (!is_string($token) ||
        !hash_equals($_SESSION['customer_csrf'], $token)) {
        $error = 'Request अवैध आहे. पुन्हा प्रयत्न करा.';
    } elseif (
        $name === '' || strlen($name) > 150 ||
        !preg_match('/^\+?[0-9]{10,13}$/', $mobile) ||
        strlen($address) > 5000
    ) {
        $error = 'नाव, मोबाइल नंबर किंवा पत्ता तपासा.';
    } else {
        $stmt = $pdo->prepare(
            "UPDATE customers SET name = ?, mobile = ?, address = ?
             WHERE id = ?"
        );
        $stmt->execute([$name, $mobile, $address ?: null, $id]);

        $_SESSION['customer_flash'] = [
            'type' => 'success',
            'message' => 'ग्राहकाची माहिती अपडेट झाली.'
        ];

        header('Location: customers.php');
        exit;
    }
}

$activePage = 'customers';
$pageTitle = 'Edit Customer';
$pageSubtitle = 'ग्राहकाची माहिती अपडेट करा';
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Edit Customer | Success Seva Manager</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="app-shell">
<?php include __DIR__ . '/includes/sidebar.php'; ?>
<div class="main-area">
<?php include __DIR__ . '/includes/header.php'; ?>

<div class="form-card">
    <div class="panel-header">
        <h2 class="panel-title">Edit Customer #<?= (int)$id ?></h2>
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
                    type="tel" maxlength="14" value="<?= h($mobile) ?>" required>
            </div>

            <div class="form-group full">
                <label class="form-label" for="address">Full Address</label>
                <textarea class="form-control" id="address" name="address"
                    rows="4" maxlength="5000"><?= h($address) ?></textarea>
            </div>
        </div>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Update Customer</button>
            <a class="btn btn-light" href="customers.php">Cancel</a>
        </div>
    </form>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

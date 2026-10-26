
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

/* CSRF Tokens */
if (empty($_SESSION['customer_csrf'])) {
    $_SESSION['customer_csrf'] = bin2hex(random_bytes(32));
}

if (empty($_SESSION['logout_csrf'])) {
    $_SESSION['logout_csrf'] = bin2hex(random_bytes(32));
}

/* Customer Search */
$search = trim($_GET['search'] ?? '');

if (strlen($search) > 100) {
    $search = substr($search, 0, 100);
}

if ($search !== '') {
    $stmt = $pdo->prepare("
        SELECT id, name, mobile, address, created_at
        FROM customers
        WHERE name LIKE ? OR mobile LIKE ?
        ORDER BY id DESC
    ");

    $term = '%' . $search . '%';
    $stmt->execute([$term, $term]);

    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $customers = $pdo->query("
        SELECT id, name, mobile, address, created_at
        FROM customers
        ORDER BY id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

/* Page Settings */
$activePage = 'customers';
$pageTitle = 'Customer Management';
$pageSubtitle = 'ग्राहकांची माहिती व्यवस्थापित करा';

/* Flash Message */
$flash = $_SESSION['customer_flash'] ?? null;
unset($_SESSION['customer_flash']);

/* Safe Output */
function h($value): string {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}
?>

<?php include __DIR__ . '/includes/sidebar.php'; ?>

<?php include __DIR__ . '/includes/header.php'; ?>


<!-- =========================
     CUSTOMER MANAGEMENT
========================= -->

<?php if ($flash): ?>

    <div class="alert <?= ($flash['type'] ?? '') === 'success'
        ? 'alert-success'
        : 'alert-error' ?>">

        <?= h($flash['message'] ?? '') ?>

    </div>

<?php endif; ?>


<div class="panel">

    <!-- Panel Header -->
    <div class="panel-header">

        <div>
            <h2 class="panel-title">All Customers</h2>

            <p class="page-subtitle">
                एकूण मिळालेले ग्राहक: <?= count($customers) ?>
            </p>
        </div>

        <a class="btn btn-primary" href="customer_add.php">
            ＋ Add Customer
        </a>

    </div>


    <!-- Search Toolbar -->
    <form class="toolbar" method="get" action="customers.php">

        <input
            class="search-input"
            type="search"
            name="search"
            value="<?= h($search) ?>"
            placeholder="नाव किंवा मोबाइल नंबर शोधा..."
            maxlength="100"
        >

        <button class="btn btn-primary" type="submit">
            Search
        </button>

        <?php if ($search !== ''): ?>

            <a class="btn btn-light" href="customers.php">
                Clear
            </a>

        <?php endif; ?>

    </form>


    <!-- Customers Table -->
    <div class="table-wrap">

        <table class="data-table">

            <thead>
                <tr>
                    <th>ID</th>
                    <th>Customer Name</th>
                    <th>Mobile Number</th>
                    <th>Address</th>
                    <th>Registered</th>
                    <th>Actions</th>
                </tr>
            </thead>

            <tbody>

            <?php if (empty($customers)): ?>

                <tr>
                    <td colspan="6" class="empty-state">
                        ग्राहक सापडले नाहीत.
                        Add Customer वापरून नवीन नोंद करा.
                    </td>
                </tr>

            <?php else: ?>

                <?php foreach ($customers as $c): ?>

                    <tr>

                        <td>
                            #<?= (int) $c['id'] ?>
                        </td>

                        <td>
                            <span class="customer-name">
                                <?= h($c['name']) ?>
                            </span>
                        </td>

                        <td>
                            <?= h($c['mobile']) ?>
                        </td>

                        <td>
                            <?= h($c['address'] ?: '—') ?>
                        </td>

                        <td>
                            <?= !empty($c['created_at'])
                                ? date(
                                    'd-m-Y',
                                    strtotime($c['created_at'])
                                )
                                : '—' ?>
                        </td>

                        <td>

                            <div class="customer-actions">

                                <a
                                    class="btn btn-light"
                                    href="customer_edit.php?id=<?= (int) $c['id'] ?>"
                                >
                                    Edit
                                </a>

                                <form
                                    method="post"
                                    action="customer_delete.php"
                                    onsubmit="return confirm('हा ग्राहक Delete करायचा आहे का?');"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= h($_SESSION['customer_csrf']) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="id"
                                        value="<?= (int) $c['id'] ?>"
                                    >

                                    <button
                                        class="btn btn-danger"
                                        type="submit"
                                    >
                                        Delete
                                    </button>

                                </form>

                            </div>

                        </td>

                    </tr>

                <?php endforeach; ?>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>


<?php include __DIR__ . '/includes/footer.php'; ?>

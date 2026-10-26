```php
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

if (empty($_SESSION['logout_csrf'])) {
    $_SESSION['logout_csrf'] = bin2hex(random_bytes(32));
}

/* =========================
   DASHBOARD SUMMARY
========================= */

// Total Customers
$totalCustomers = (int) $pdo
    ->query("SELECT COUNT(*) FROM customers")
    ->fetchColumn();

// Today's Work
$todayWork = (int) $pdo
    ->query("
        SELECT COUNT(*)
        FROM work_records
        WHERE work_date = CURRENT_DATE()
    ")
    ->fetchColumn();

// Today's Income
$todayIncome = (float) $pdo
    ->query("
        SELECT COALESCE(SUM(amount), 0)
        FROM payments
        WHERE payment_date = CURRENT_DATE()
    ")
    ->fetchColumn();

// Pending Payments
$pendingPayments = (float) $pdo
    ->query("
        SELECT COALESCE(
            SUM(GREATEST(total_fee - paid_amount, 0)), 0
        )
        FROM work_records
        WHERE paid_amount < total_fee
    ")
    ->fetchColumn();

// Today's Expenses
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0)
    FROM expenses
    WHERE expense_date = CURRENT_DATE()
");
$stmt->execute();
$todayExpenses = (float) $stmt->fetchColumn();

// Current Month Income
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0)
    FROM payments
    WHERE payment_date >= DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01')
      AND payment_date < DATE_ADD(
          LAST_DAY(CURRENT_DATE()), INTERVAL 1 DAY
      )
");
$stmt->execute();
$monthIncome = (float) $stmt->fetchColumn();

// Current Month Expenses
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0)
    FROM expenses
    WHERE expense_date >= DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01')
      AND expense_date < DATE_ADD(
          LAST_DAY(CURRENT_DATE()), INTERVAL 1 DAY
      )
");
$stmt->execute();
$monthExpenses = (float) $stmt->fetchColumn();

// Monthly Net Balance
$monthNetBalance = $monthIncome - $monthExpenses;

// Recent Customers
$recentCustomers = $pdo->query("
    SELECT id, name, mobile, created_at
    FROM customers
    ORDER BY id DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// Currency Format
function moneyFormat(float $amount): string {
    return '₹' . number_format($amount, 2);
}

// Page Settings
$activePage = 'dashboard';
$pageTitle = 'Dashboard';
$pageSubtitle = 'तुमच्या सेवा केंद्राचा आजचा आढावा';

?>

<?php include __DIR__ . '/includes/sidebar.php'; ?>

<?php include __DIR__ . '/includes/header.php'; ?>

<!-- =========================
     MAIN SUMMARY CARDS
========================= -->

<div class="stats-grid">

    <!-- Total Customers -->
    <div class="stat-card">
        <div class="stat-top">
            <span class="stat-label">TOTAL CUSTOMERS</span>
            <span class="stat-icon">♙</span>
        </div>

        <div class="stat-value">
            <?= number_format($totalCustomers) ?>
        </div>

        <div class="stat-note">नोंदणीकृत ग्राहक</div>
    </div>

    <!-- Today's Work -->
    <div class="stat-card">
        <div class="stat-top">
            <span class="stat-label">TODAY'S WORK</span>
            <span class="stat-icon">✓</span>
        </div>

        <div class="stat-value">
            <?= number_format($todayWork) ?>
        </div>

        <div class="stat-note">आज नोंदवलेली कामे</div>
    </div>

    <!-- Today's Income -->
    <div class="stat-card">
        <div class="stat-top">
            <span class="stat-label">TODAY'S INCOME</span>
            <span class="stat-icon">₹</span>
        </div>

        <div class="stat-value">
            <?= moneyFormat($todayIncome) ?>
        </div>

        <div class="stat-note">
            आज प्रत्यक्ष जमा झालेली रक्कम
        </div>
    </div>

    <!-- Pending Payments -->
    <div class="stat-card">
        <div class="stat-top">
            <span class="stat-label">PENDING PAYMENTS</span>
            <span class="stat-icon">◷</span>
        </div>

        <div class="stat-value">
            <?= moneyFormat($pendingPayments) ?>
        </div>

        <div class="stat-note">
            ग्राहकांकडून येणे बाकी
        </div>
    </div>

</div>


<!-- =========================
     RECENT CUSTOMERS
========================= -->

<div class="panel">

    <div class="panel-header">

        <div>
            <h2 class="panel-title">Recent Customers</h2>
            <p class="page-subtitle">
                नुकतेच नोंदवलेले ग्राहक
            </p>
        </div>

        <a class="btn btn-primary" href="customer_add.php">
            ＋ Add Customer
        </a>

    </div>

    <div class="table-wrap">

        <table class="data-table">

            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Mobile</th>
                    <th>Registered On</th>
                </tr>
            </thead>

            <tbody>

            <?php if (empty($recentCustomers)): ?>

                <tr>
                    <td colspan="3" class="empty-state">
                        अद्याप ग्राहक नोंदवलेले नाहीत.
                        Add Customer वर क्लिक करून सुरुवात करा.
                    </td>
                </tr>

            <?php else: ?>

                <?php foreach ($recentCustomers as $customer): ?>

                    <tr>
                        <td>
                            <span class="customer-name">
                                <?= htmlspecialchars(
                                    $customer['name'] ?? '',
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </span>
                        </td>

                        <td>
                            <?= htmlspecialchars(
                                $customer['mobile'] ?? '',
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </td>

                        <td>
                            <?= !empty($customer['created_at'])
                                ? date(
                                    'd-m-Y',
                                    strtotime($customer['created_at'])
                                )
                                : '-' ?>
                        </td>
                    </tr>

                <?php endforeach; ?>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>


<!-- =========================
     FINANCIAL SUMMARY CARDS
========================= -->

<div class="stats-grid financial-stats">

    <!-- Today's Expense -->
    <div class="stat-card">
        <div class="stat-top">
            <span class="stat-label">TODAY'S EXPENSE</span>
            <span class="stat-icon">−₹</span>
        </div>

        <div class="stat-value">
            <?= moneyFormat($todayExpenses) ?>
        </div>

        <div class="stat-note">आज झालेला खर्च</div>
    </div>

    <!-- Monthly Income -->
    <div class="stat-card">
        <div class="stat-top">
            <span class="stat-label">MONTHLY INCOME</span>
            <span class="stat-icon">₹</span>
        </div>

        <div class="stat-value">
            <?= moneyFormat($monthIncome) ?>
        </div>

        <div class="stat-note">
            या महिन्यात जमा झालेली रक्कम
        </div>
    </div>

    <!-- Monthly Expense -->
    <div class="stat-card">
        <div class="stat-top">
            <span class="stat-label">MONTHLY EXPENSE</span>
            <span class="stat-icon">−₹</span>
        </div>

        <div class="stat-value">
            <?= moneyFormat($monthExpenses) ?>
        </div>

        <div class="stat-note">
            या महिन्याचा एकूण खर्च
        </div>
    </div>

    <!-- Monthly Net Balance -->
    <div class="stat-card">
        <div class="stat-top">
            <span class="stat-label">MONTHLY NET BALANCE</span>
            <span class="stat-icon">₹</span>
        </div>

        <div class="stat-value">
            <?= moneyFormat($monthNetBalance) ?>
        </div>

        <div class="stat-note">
            उत्पन्न वजा खर्च
        </div>
    </div>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
```
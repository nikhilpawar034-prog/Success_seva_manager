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

/* Helper Functions */
function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function moneyFormat(float $amount): string {
    return '₹' . number_format($amount, 2);
}

/* Selected Month */
$selectedMonth = $_GET['month'] ?? date('Y-m');

if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}

$startDate = $selectedMonth . '-01';
$nextMonth = date('Y-m-d', strtotime($startDate . ' +1 month'));

/* Monthly Income */
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0)
    FROM payments
    WHERE payment_date >= ? AND payment_date < ?
");
$stmt->execute([$startDate, $nextMonth]);
$totalIncome = (float)$stmt->fetchColumn();

/* Monthly Expenses */
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0)
    FROM expenses
    WHERE expense_date >= ? AND expense_date < ?
");
$stmt->execute([$startDate, $nextMonth]);
$totalExpenses = (float)$stmt->fetchColumn();

$netBalance = $totalIncome - $totalExpenses;

/* Income Details */
$stmt = $pdo->prepare("
    SELECT
        p.id,
        p.payment_date,
        p.amount,
        p.payment_method,
        p.notes,
        c.name AS customer_name,
        w.application_no
    FROM payments p
    LEFT JOIN work_records w ON p.work_id = w.id
    LEFT JOIN customers c ON w.customer_id = c.id
    WHERE p.payment_date >= ? AND p.payment_date < ?
    ORDER BY p.payment_date DESC, p.id DESC
");
$stmt->execute([$startDate, $nextMonth]);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Expense Details */
$stmt = $pdo->prepare("
    SELECT id, expense_title, category, amount, expense_date, notes
    FROM expenses
    WHERE expense_date >= ? AND expense_date < ?
    ORDER BY expense_date DESC, id DESC
");
$stmt->execute([$startDate, $nextMonth]);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* CSV Export */
if (($_GET['export'] ?? '') === 'csv') {

    header('Content-Type: text/csv; charset=UTF-8');

    header(
        'Content-Disposition: attachment; filename="monthly-report-' .
        $selectedMonth . '.csv"'
    );

    $out = fopen('php://output', 'w');

    // UTF-8 BOM for Excel
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, ['Monthly Report', $selectedMonth]);
    fputcsv($out, []);
    fputcsv($out, ['Income', number_format($totalIncome, 2, '.', '')]);
    fputcsv($out, ['Expenses', number_format($totalExpenses, 2, '.', '')]);
    fputcsv($out, ['Net Balance', number_format($netBalance, 2, '.', '')]);

    fputcsv($out, []);

    fputcsv($out, [
        'Income Date',
        'Customer',
        'Application No',
        'Payment Method',
        'Amount',
        'Notes'
    ]);

    foreach ($payments as $p) {
        fputcsv($out, [
            $p['payment_date'],
            $p['customer_name'] ?? 'N/A',
            $p['application_no'] ?? '',
            $p['payment_method'] ?? '',
            $p['amount'],
            $p['notes'] ?? ''
        ]);
    }

    fputcsv($out, []);

    fputcsv($out, [
        'Expense Date',
        'Expense Title',
        'Category',
        'Amount',
        'Notes'
    ]);

    foreach ($expenses as $e) {
        fputcsv($out, [
            $e['expense_date'],
            $e['expense_title'],
            $e['category'],
            $e['amount'],
            $e['notes'] ?? ''
        ]);
    }

    fclose($out);
    exit;
}

/* Page Settings */
$activePage = 'monthly_report';
$pageTitle = 'Monthly Report';
$pageSubtitle = 'महिन्यानुसार उत्पन्न आणि खर्चाचा अहवाल';
?>

<?php include __DIR__ . '/includes/sidebar.php'; ?>

<?php include __DIR__ . '/includes/header.php'; ?>

<style>
.report-filter {
    display: flex;
    align-items: end;
    gap: 12px;
    flex-wrap: wrap;
}

.report-filter label {
    display: block;
    margin-bottom: 6px;
    font-weight: 600;
}

.report-filter input {
    padding: 10px;
    border: 1px solid #d5dbea;
    border-radius: 8px;
    min-height: 42px;
    max-width: 100%;
}

.report-cards {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
    margin: 20px 0;
}

.report-card {
    background: #fff;
    border: 1px solid #e3e8f2;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 3px 12px rgba(20, 40, 80, .05);
    min-width: 0;
}

.report-card small {
    display: block;
    color: #58657a;
    margin-bottom: 10px;
}

.report-card strong {
    display: block;
    font-size: clamp(18px, 2.5vw, 26px);
    overflow-wrap: anywhere;
}

.report-section {
    margin-top: 24px;
}

.report-section h2 {
    font-size: 18px;
    margin: 0 0 14px;
}

.report-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 16px;
}

.report-filter input {
    box-sizing: border-box;
}

@media (max-width: 760px) {
    .report-cards {
        grid-template-columns: 1fr;
    }

    .report-filter {
        align-items: stretch;
    }

    .report-filter > div,
    .report-filter input {
        width: 100%;
    }
}

@media print {
    .sidebar,
    .topbar,
    .report-filter,
    .report-actions,
    .footer,
    .mobile-menu {
        display: none !important;
    }

    .app-shell {
        display: block !important;
    }

    .main-area {
        margin: 0 !important;
        width: 100% !important;
    }

    .content {
        padding: 0 !important;
        max-width: none !important;
    }

    .report-card,
    .panel {
        box-shadow: none !important;
        break-inside: avoid;
    }
}
</style>

<!-- Report Filter -->
<div class="panel">

    <div class="panel-header">
        <div>
            <h2 class="panel-title">Monthly Financial Report</h2>
            <p class="page-subtitle">
                निवडलेल्या महिन्याचा आर्थिक आढावा
            </p>
        </div>
    </div>

    <div class="panel-body">

        <form method="get" action="monthly_report.php" class="report-filter">

            <div>
                <label for="month">महिना निवडा</label>

                <input
                    type="month"
                    id="month"
                    name="month"
                    value="<?= h($selectedMonth) ?>"
                    required
                >
            </div>

            <button class="btn btn-primary" type="submit">
                View Report
            </button>

        </form>

        <div class="report-actions">

            <a
                class="btn btn-primary"
                href="monthly_report.php?month=<?= h($selectedMonth) ?>&amp;export=csv"
            >
                Download CSV
            </a>

            <button
                class="btn btn-light"
                type="button"
                onclick="window.print()"
            >
                Print Report
            </button>

        </div>

    </div>
</div>

<!-- Summary Cards -->
<div class="report-cards">

    <div class="report-card">
        <small>एकूण उत्पन्न</small>
        <strong><?= moneyFormat($totalIncome) ?></strong>
    </div>

    <div class="report-card">
        <small>एकूण खर्च</small>
        <strong><?= moneyFormat($totalExpenses) ?></strong>
    </div>

    <div class="report-card">
        <small>निव्वळ शिल्लक (उत्पन्न − खर्च)</small>
        <strong><?= moneyFormat($netBalance) ?></strong>
    </div>

</div>

<!-- Income Details -->
<div class="panel report-section">

    <div class="panel-body">
        <h2>Income Details — उत्पन्नाचा तपशील</h2>

        <div class="table-wrap">
            <table class="data-table">

                <thead>
                    <tr>
                        <th>तारीख</th>
                        <th>ग्राहक</th>
                        <th>Application No.</th>
                        <th>Payment Method</th>
                        <th>रक्कम</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (empty($payments)): ?>

                    <tr>
                        <td colspan="5" class="empty-state">
                            या महिन्यात उत्पन्नाची नोंद नाही.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td>
                                <?= h(date(
                                    'd-m-Y',
                                    strtotime($p['payment_date'])
                                )) ?>
                            </td>

                            <td>
                                <?= h($p['customer_name'] ?? 'N/A') ?>
                            </td>

                            <td>
                                <?= h($p['application_no'] ?? '-') ?>
                            </td>

                            <td>
                                <?= h($p['payment_method'] ?? '-') ?>
                            </td>

                            <td>
                                <?= moneyFormat((float)$p['amount']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Expense Details -->
<div class="panel report-section">

    <div class="panel-body">
        <h2>Expense Details — खर्चाचा तपशील</h2>

        <div class="table-wrap">
            <table class="data-table">

                <thead>
                    <tr>
                        <th>तारीख</th>
                        <th>खर्चाचे नाव</th>
                        <th>वर्ग</th>
                        <th>रक्कम</th>
                        <th>नोंद</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (empty($expenses)): ?>

                    <tr>
                        <td colspan="5" class="empty-state">
                            या महिन्यात खर्चाची नोंद नाही.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($expenses as $e): ?>
                        <tr>
                            <td>
                                <?= h(date(
                                    'd-m-Y',
                                    strtotime($e['expense_date'])
                                )) ?>
                            </td>

                            <td>
                                <?= h($e['expense_title']) ?>
                            </td>

                            <td>
                                <?= h($e['category']) ?>
                            </td>

                            <td>
                                <?= moneyFormat((float)$e['amount']) ?>
                            </td>

                            <td>
                                <?= h($e['notes'] ?? '-') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

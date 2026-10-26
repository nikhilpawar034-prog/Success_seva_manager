
<?php
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/db.php';

$pageTitle = 'Reports';
$pageSubtitle = 'Income, expenses and business performance';
$activePage = 'reports';

$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

$report = [
    'income' => 0,
    'expenses' => 0,
    'payments' => 0,
    'transactions' => 0,
    'customers' => 0,
    'work_count' => 0
];

$monthlyReports = [];
$recentTransactions = [];
$error = '';

try {
    // Income from paid transactions
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM transactions
        WHERE payment_status = 'Paid'
        AND transaction_date BETWEEN ? AND ?
    ");
    $stmt->execute([$startDate, $endDate]);
    $report['transactions'] = (float)$stmt->fetchColumn();

    // Payments recorded in payments table
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM payments
        WHERE payment_date BETWEEN ? AND ?
    ");
    $stmt->execute([$startDate, $endDate]);
    $report['payments'] = (float)$stmt->fetchColumn();

    // Expenses
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM expenses
        WHERE expense_date BETWEEN ? AND ?
    ");
    $stmt->execute([$startDate, $endDate]);
    $report['expenses'] = (float)$stmt->fetchColumn();

    // Work records
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM work_records
        WHERE work_date BETWEEN ? AND ?
    ");
    $stmt->execute([$startDate, $endDate]);
    $report['work_count'] = (int)$stmt->fetchColumn();

    // New customers in selected period
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM customers
        WHERE DATE(created_at) BETWEEN ? AND ?
    ");
    $stmt->execute([$startDate, $endDate]);
    $report['customers'] = (int)$stmt->fetchColumn();

    // Monthly income and expenses for selected period
    $stmt = $pdo->prepare("
        SELECT
            month_data.month AS report_month,
            COALESCE(month_data.income, 0) AS income,
            COALESCE(expense_data.expenses, 0) AS expenses
        FROM (
            SELECT
                DATE_FORMAT(transaction_date, '%Y-%m') AS month,
                SUM(amount) AS income
            FROM transactions
            WHERE payment_status = 'Paid'
            AND transaction_date BETWEEN ? AND ?
            GROUP BY DATE_FORMAT(transaction_date, '%Y-%m')
        ) AS month_data
        LEFT JOIN (
            SELECT
                DATE_FORMAT(expense_date, '%Y-%m') AS month,
                SUM(amount) AS expenses
            FROM expenses
            WHERE expense_date BETWEEN ? AND ?
            GROUP BY DATE_FORMAT(expense_date, '%Y-%m')
        ) AS expense_data
        ON month_data.month = expense_data.month
        ORDER BY month_data.month DESC
    ");
    $stmt->execute([$startDate, $endDate, $startDate, $endDate]);
    $monthlyReports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Recent paid transactions
    $stmt = $pdo->prepare("
        SELECT
            t.id,
            t.transaction_date,
            t.amount,
            t.payment_method,
            c.name AS customer_name,
            s.service_name
        FROM transactions t
        LEFT JOIN customers c ON c.id = t.customer_id
        LEFT JOIN services s ON s.id = t.service_id
        WHERE t.payment_status = 'Paid'
        AND t.transaction_date BETWEEN ? AND ?
        ORDER BY t.transaction_date DESC, t.id DESC
        LIMIT 20
    ");
    $stmt->execute([$startDate, $endDate]);
    $recentTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $report['income'] = $report['transactions'];
} catch (PDOException $e) {
    $error = 'Report तयार करता आला नाही. Database table आणि column names तपासा.';
}

$netProfit = $report['income'] - $report['expenses'];

require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/header.php';
?>

<style>
.report-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
    margin-bottom: 22px;
}
.report-stat {
    background: #fff;
    border: 1px solid #e4eaf3;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 4px 14px rgba(20,45,90,.05);
}
.report-label {
    color: #64748b;
    font-size: 13px;
    margin-bottom: 8px;
}
.report-value {
    color: #1746a2;
    font-size: 24px;
    font-weight: 700;
}
.report-card {
    background: #fff;
    border: 1px solid #e4eaf3;
    border-radius: 12px;
    padding: 22px;
    margin-bottom: 22px;
}
.report-card h3 {
    color: #183b78;
    margin: 0 0 18px;
}
.report-filter {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    align-items: end;
}
.report-filter .field {
    display: flex;
    flex-direction: column;
    gap: 7px;
}
.report-filter label {
    color: #334155;
    font-size: 13px;
    font-weight: 600;
}
.report-filter input {
    padding: 10px 12px;
    border: 1px solid #d6dfeb;
    border-radius: 8px;
}
.report-btn {
    padding: 11px 17px;
    background: #1746a2;
    color: white;
    border: 0;
    border-radius: 8px;
    cursor: pointer;
    text-decoration: none;
    font-weight: 600;
}
.report-table-wrap { overflow-x: auto; }
.report-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 650px;
}
.report-table th, .report-table td {
    padding: 13px 12px;
    border-bottom: 1px solid #e8edf5;
    text-align: left;
    font-size: 13px;
}
.report-table th {
    background: #f3f6fb;
    color: #334155;
}
.report-table td { color: #475569; }
.report-positive { color: #15803d !important; font-weight: 700; }
.report-negative { color: #dc2626 !important; font-weight: 700; }
.report-alert {
    background: #fee2e2;
    color: #991b1b;
    padding: 12px 15px;
    border-radius: 8px;
    margin-bottom: 16px;
}
.report-empty {
    text-align: center !important;
    padding: 25px !important;
    color: #64748b !important;
}
@media print {
    .report-filter, .no-print, .sidebar, .topbar {
        display: none !important;
    }
    .main-area, .content {
        margin: 0 !important;
        padding: 0 !important;
    }
    .report-card, .report-stat {
        box-shadow: none;
        break-inside: avoid;
    }
}
@media(max-width:600px) {
    .report-card { padding: 15px; }
    .report-value { font-size: 21px; }
}
</style>

<?php if ($error !== ''): ?>
    <div class="report-alert">
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<div class="report-card no-print">
    <h3>Filter Report</h3>

    <form method="GET" action="reports.php" class="report-filter">
        <div class="field">
            <label for="start_date">From Date</label>
            <input type="date" name="start_date" id="start_date"
                   value="<?= htmlspecialchars($startDate, ENT_QUOTES, 'UTF-8') ?>"
                   required>
        </div>

        <div class="field">
            <label for="end_date">To Date</label>
            <input type="date" name="end_date" id="end_date"
                   value="<?= htmlspecialchars($endDate, ENT_QUOTES, 'UTF-8') ?>"
                   required>
        </div>

        <button type="submit" class="report-btn">Generate Report</button>
        <button type="button" class="report-btn" onclick="window.print()">
            Print Report
        </button>
        <a href="reports.php" class="report-btn">This Month</a>
    </form>
</div>

<div class="report-stats">
    <div class="report-stat">
        <div class="report-label">Total Income</div>
        <div class="report-value">
            ₹<?= number_format($report['income'], 2) ?>
        </div>
    </div>

    <div class="report-stat">
        <div class="report-label">Total Expenses</div>
        <div class="report-value" style="color:#dc2626">
            ₹<?= number_format($report['expenses'], 2) ?>
        </div>
    </div>

    <div class="report-stat">
        <div class="report-label">Net Balance</div>
        <div class="report-value"
             style="color:<?= $netProfit >= 0 ? '#15803d' : '#dc2626' ?>">
            ₹<?= number_format($netProfit, 2) ?>
        </div>
    </div>

    <div class="report-stat">
        <div class="report-label">New Customers</div>
        <div class="report-value"><?= number_format($report['customers']) ?></div>
    </div>

    <div class="report-stat">
        <div class="report-label">Work Records</div>
        <div class="report-value"><?= number_format($report['work_count']) ?></div>
    </div>
</div>

<div class="report-card">
    <h3>Monthly Income & Expense Report</h3>

    <div class="report-table-wrap">
        <table class="report-table">
            <thead>
                <tr>
                    <th>Month</th>
                    <th>Income</th>
                    <th>Expenses</th>
                    <th>Net Balance</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($monthlyReports)): ?>
                <?php foreach ($monthlyReports as $row): ?>
                    <?php $monthBalance = (float)$row['income'] - (float)$row['expenses']; ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars(
                                date('F Y', strtotime($row['report_month'] . '-01')),
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </td>
                        <td class="report-positive">
                            ₹<?= number_format((float)$row['income'], 2) ?>
                        </td>
                        <td>
                            ₹<?= number_format((float)$row['expenses'], 2) ?>
                        </td>
                        <td class="<?= $monthBalance >= 0 ? 'report-positive' : 'report-negative' ?>">
                            ₹<?= number_format($monthBalance, 2) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="4" class="report-empty">
                        या कालावधीसाठी मासिक रिपोर्ट उपलब्ध नाही.
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="report-card">
    <h3>Recent Paid Transactions</h3>

    <div class="report-table-wrap">
        <table class="report-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Service</th>
                    <th>Payment Method</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!empty($recentTransactions)): ?>
                <?php foreach ($recentTransactions as $transaction): ?>
                    <tr>
                        <td><?= (int)$transaction['id'] ?></td>
                        <td><?= htmlspecialchars($transaction['transaction_date'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($transaction['customer_name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($transaction['service_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($transaction['payment_method'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="report-positive">
                            ₹<?= number_format((float)$transaction['amount'], 2) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="6" class="report-empty">
                        या कालावधीमध्ये Paid transactions नाहीत.
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

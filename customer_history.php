
<?php
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/db.php';

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function moneyFormat($amount) {
    return '₹' . number_format((float)$amount, 2);
}

$search = trim($_GET['search'] ?? '');
$customerId = filter_input(INPUT_GET, 'customer_id', FILTER_VALIDATE_INT);

$customers = [];
$customer = null;
$works = [];
$totalFee = 0;
$totalPaid = 0;
$totalBalance = 0;
$error = '';

try {
    // ग्राहक शोधा
    $sql = "SELECT id, name, mobile
        FROM customers";

    $params = [];

    if ($search !== '') {
    $sql .= " WHERE (name LIKE :search OR mobile LIKE :search)";
    $params['search'] = '%' . $search . '%';
   }

    $sql .= " ORDER BY name ASC LIMIT 200";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // निवडलेल्या ग्राहकाची माहिती
    if ($customerId) {
        $stmt = $pdo->prepare(
    "SELECT id, name, mobile
     FROM customers
     WHERE id = :id"
    );
        $stmt->execute(['id' => $customerId]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($customer) {
            // ग्राहकाची सर्व कामे
            $stmt = $pdo->prepare(
                "SELECT
                    wr.id,
                    wr.application_no,
                    wr.work_date,
                    wr.total_fee,
                    wr.paid_amount,
                    wr.status,
                    wr.notes,
                    s.service_name
                 FROM work_records wr
                 LEFT JOIN services s ON s.id = wr.service_id
                 WHERE wr.customer_id = :customer_id
                 ORDER BY wr.work_date DESC, wr.id DESC"
            );

            $stmt->execute(['customer_id' => $customerId]);
            $works = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($works as $work) {
                $fee = (float)$work['total_fee'];
                $paid = (float)$work['paid_amount'];

                $totalFee += $fee;
                $totalPaid += $paid;
                $totalBalance += max(0, $fee - $paid);
            }
        }
    }
} catch (PDOException $e) {
    error_log($e->getMessage());
    echo '<pre>';
    echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    echo '</pre>';
    exit;
}
?>

<?php
$activePage = 'customer_history';
$pageTitle = 'Customer History Report';

require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/header.php';
?>

<style>

.history-container {
    width: 100%;
    max-width: 100%;
    min-width: 0;
    padding: 20px 0;
    box-sizing: border-box;
    color: #1e293b;
}

.history-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 20px;
}

.history-heading h2 {
    margin: 0;
    color: #1746a2;
}

.history-card {
    width: 100%;
    max-width: 100%;
    min-width: 0;
    box-sizing: border-box;
}

.history-search {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    width: 100%;
    box-sizing: border-box;
}

.history-search input {
    flex: 1 1 200px;
    min-width: 0;
    box-sizing: border-box;
}

.history-table-wrap {
    width: 100%;
    max-width: 100%;
    overflow-x: auto;
}

@media (max-width: 760px) {
    .history-container {
        padding: 15px 0;
    }

    .history-card {
        padding: 14px;
    }

    .history-heading {
        align-items: flex-start;
    }
}

.history-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 750px;
}

.history-table th,
.history-table td {
    padding: 12px;
    border-bottom: 1px solid #e2e8f0;
    text-align: left;
    font-size: 14px;
}

.history-table th {
    background: #eff6ff;
    color: #1746a2;
}

.history-status {
    display: inline-block;
    padding: 5px 9px;
    background: #e2e8f0;
    border-radius: 20px;
    font-size: 12px;
}

.history-error {
    padding: 12px;
    background: #fee2e2;
    color: #991b1b;
    border-radius: 8px;
}

@media print {
    .sidebar, .history-search, .no-print,
    header, nav, .history-heading a {
        display: none !important;
    }

    .history-container {
        padding: 0;
    }

    .history-card, .history-stat {
        box-shadow: none;
        break-inside: avoid;
    }

    body {
        background: white !important;
    }
}
</style>

<div class="history-container">

    <div class="history-heading">
        <div>
            <h2>Customer History Report</h2>
            <p>ग्राहकाची सर्व कामे, फी आणि बाकी रक्कम</p>
        </div>

        <button class="history-btn secondary no-print"
                onclick="window.print()">
            Print Report
        </button>
    </div>

    <?php if ($error !== ''): ?>
        <div class="history-error">
            <?= h($error) ?>
        </div>
    <?php endif; ?>

    <div class="history-card no-print">
        <h3>ग्राहक शोधा</h3>

        <form method="GET" class="history-search">
            <input
                type="text"
                name="search"
                placeholder="ग्राहकाचे नाव टाका..."
                value="<?= h($search) ?>"
            >

            <button class="history-btn" type="submit">
                Search
            </button>

            <a href="customer_history.php"
               class="history-btn secondary">
                Reset
            </a>
        </form>
    </div>

    <?php if ($customer): ?>

        <div class="history-card">
            <h3>ग्राहक: <?= h($customer['name']) ?></h3>
            <p>Customer ID: <?= (int)$customer['id'] ?></p>
            <p>मोबाईल: <?= h($customer['mobile']) ?></p>

            <a href="customer_history.php?search=<?= urlencode($search) ?>"
               class="history-btn secondary no-print">
                ग्राहक यादीकडे परत जा
            </a>
        </div>

        <div class="history-summary">
            <div class="history-stat">
                <p>एकूण कामांची संख्या</p>
                <h3><?= count($works) ?></h3>
            </div>

            <div class="history-stat">
                <p>एकूण फी</p>
                <h3><?= moneyFormat($totalFee) ?></h3>
            </div>

            <div class="history-stat">
                <p>एकूण भरलेली रक्कम</p>
                <h3><?= moneyFormat($totalPaid) ?></h3>
            </div>

            <div class="history-stat">
                <p>एकूण बाकी रक्कम</p>
                <h3><?= moneyFormat($totalBalance) ?></h3>
            </div>
        </div>

        <div class="history-card">
            <h3>कामांचा तपशील</h3>

            <div class="history-table-wrap">
                <table class="history-table">
                    <thead>
                        <tr>
                            <th>अ.क्र.</th>
                            <th>तारीख</th>
                            <th>सेवा</th>
                            <th>अर्ज क्रमांक</th>
                            <th>स्टेटस</th>
                            <th>एकूण फी</th>
                            <th>भरलेली</th>
                            <th>बाकी</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if (empty($works)): ?>
                        <tr>
                            <td colspan="8">
                                या ग्राहकाची कोणतीही कामे सापडली नाहीत.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($works as $index => $work): ?>
                            <?php
                            $fee = (float)$work['total_fee'];
                            $paid = (float)$work['paid_amount'];
                            $balance = max(0, $fee - $paid);
                            ?>
                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td><?= h($work['work_date']) ?></td>
                                <td><?= h($work['service_name'] ?? '-') ?></td>
                                <td><?= h($work['application_no'] ?: '-') ?></td>
                                <td>
                                    <span class="history-status">
                                        <?= h($work['status']) ?>
                                    </span>
                                </td>
                                <td><?= moneyFormat($fee) ?></td>
                                <td><?= moneyFormat($paid) ?></td>
                                <td><?= moneyFormat($balance) ?></td>
                            </tr>

                            <?php if (!empty($work['notes'])): ?>
                                <tr>
                                    <td colspan="8">
                                        <strong>नोंद:</strong>
                                        <?= h($work['notes']) ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php elseif ($customerId): ?>

        <div class="history-card">
            निवडलेला ग्राहक सापडला नाही.
        </div>

    <?php else: ?>

        <div class="history-card">
            <h3>ग्राहकांची यादी</h3>

            <div class="history-table-wrap">
                <table class="history-table">
                    <thead>
                        <tr>
                            <th>अ.क्र.</th>
                            <th>ग्राहकाचे नाव</th>
                            <th>मोबाईल नंबर</th>
                            <th>Customer ID</th>
                            <th>इतिहास</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if (empty($customers)): ?>
                        <tr>
                            <td colspan="4">ग्राहक सापडले नाहीत.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($customers as $index => $c): ?>
                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td><?= h($c['name']) ?></td>
                                <td><?= h($c['mobile']) ?></td>
                                <td><?= (int)$c['id'] ?></td>
                                <td>
                                    <a class="history-btn"
                                       href="customer_history.php?customer_id=<?= (int)$c['id'] ?>&search=<?= urlencode($search) ?>">
                                        View History
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

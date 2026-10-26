
<?php
session_start();

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/config/db.php';

$pageTitle = 'Payments';
$pageSubtitle = 'Manage customer payments and payment history';
$activePage = 'payments';

$success = '';
$error = '';

/* CSRF Token */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* Delete payment */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_payment'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $paymentId = (int)($_POST['payment_id'] ?? 0);

        try {
            $stmt = $pdo->prepare("DELETE FROM payments WHERE id = ?");
            $stmt->execute([$paymentId]);
            $success = 'Payment deleted successfully.';
        } catch (PDOException $e) {
            $error = 'Payment delete करता आले नाही.';
        }
    }
}

/* Add payment */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_payment'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $paymentMethod = trim($_POST['payment_method'] ?? 'Cash');
        $paymentDate = $_POST['payment_date'] ?? date('Y-m-d');
        $notes = trim($_POST['notes'] ?? '');

        $allowedMethods = ['Cash', 'UPI', 'Bank Transfer', 'Card', 'Other'];

        if ($customerId <= 0 || $amount <= 0) {
            $error = 'कृपया ग्राहक निवडा आणि योग्य रक्कम भरा.';
        } elseif (!in_array($paymentMethod, $allowedMethods, true)) {
            $error = 'कृपया योग्य पेमेंट पद्धत निवडा.';
        } else {
            try {
                $check = $pdo->prepare("SELECT id FROM customers WHERE id = ?");
                $check->execute([$customerId]);

                if (!$check->fetch()) {
                    $error = 'निवडलेला ग्राहक सापडला नाही.';
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO payments
                        (customer_id, amount, payment_method, payment_date, notes)
                        VALUES (?, ?, ?, ?, ?)
                    ");

                    $stmt->execute([
                        $customerId,
                        $amount,
                        $paymentMethod,
                        $paymentDate,
                        $notes
                    ]);

                    header('Location: payments.php?added=1');
                    exit;
                }
            } catch (PDOException $e) {
                $error = 'पेमेंट सेव्ह करता आले नाही. payments टेबलचे कॉलम तपासा.';
            }
        }
    }
}

if (isset($_GET['added'])) {
    $success = 'Payment added successfully.';
}

/* Search */
$search = trim($_GET['search'] ?? '');

try {
    $customersStmt = $pdo->query("
        SELECT id, name, mobile
        FROM customers
        ORDER BY name ASC
    ");
    $customers = $customersStmt->fetchAll(PDO::FETCH_ASSOC);

    $sql = "
        SELECT p.*, c.name AS customer_name, c.mobile
        FROM payments p
        LEFT JOIN customers c ON c.id = p.customer_id
    ";

    $params = [];

    if ($search !== '') {
        $sql .= " WHERE c.name LIKE ? OR c.mobile LIKE ? OR p.payment_method LIKE ? OR p.notes LIKE ?";
        $term = '%' . $search . '%';
        $params = [$term, $term, $term, $term];
    }

    $sql .= " ORDER BY p.payment_date DESC, p.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalStmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM payments");
    $totalPayments = (float)$totalStmt->fetchColumn();

    $todayStmt = $pdo->query("
        SELECT COALESCE(SUM(amount), 0)
        FROM payments
        WHERE payment_date = CURDATE()
    ");
    $todayPayments = (float)$todayStmt->fetchColumn();

    $countStmt = $pdo->query("SELECT COUNT(*) FROM payments");
    $paymentCount = (int)$countStmt->fetchColumn();

} catch (PDOException $e) {
    $customers = [];
    $payments = [];
    $totalPayments = 0;
    $todayPayments = 0;
    $paymentCount = 0;
    $error = 'डेटाबेसमधील payments टेबल किंवा कॉलम तपासा.';
}

require_once __DIR__ . '/includes/sidebar.php';
require_once __DIR__ . '/includes/header.php';
?>

<style>
.payment-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
    margin-bottom: 22px;
}
.payment-stat {
    background: #fff;
    border: 1px solid #e4eaf3;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 4px 14px rgba(20, 45, 90, .05);
}
.payment-stat .stat-label {
    color: #64748b;
    font-size: 13px;
    margin-bottom: 8px;
}
.payment-stat .stat-value {
    color: #1746a2;
    font-size: 25px;
    font-weight: 700;
}
.payment-card {
    background: #fff;
    border: 1px solid #e4eaf3;
    border-radius: 12px;
    padding: 22px;
    margin-bottom: 22px;
    box-shadow: 0 4px 14px rgba(20, 45, 90, .04);
}
.payment-card h3 {
    margin: 0 0 18px;
    color: #183b78;
}
.payment-form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}
.payment-field {
    display: flex;
    flex-direction: column;
    gap: 7px;
}
.payment-field.full {
    grid-column: 1 / -1;
}
.payment-field label {
    font-size: 13px;
    font-weight: 600;
    color: #334155;
}
.payment-field input,
.payment-field select,
.payment-field textarea,
.payment-search input {
    width: 100%;
    box-sizing: border-box;
    padding: 11px 12px;
    border: 1px solid #d6dfeb;
    border-radius: 8px;
    background: #fff;
    color: #1e293b;
    font: inherit;
}
.payment-field input:focus,
.payment-field select:focus,
.payment-field textarea:focus,
.payment-search input:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, .10);
}
.payment-btn {
    border: none;
    border-radius: 8px;
    padding: 11px 18px;
    background: #1746a2;
    color: #fff;
    font-weight: 600;
    cursor: pointer;
}
.payment-btn:hover { background: #10357e; }
.payment-search {
    display: flex;
    gap: 10px;
    margin-bottom: 16px;
}
.payment-table-wrap { overflow-x: auto; }
.payment-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 750px;
}
.payment-table th,
.payment-table td {
    padding: 13px 12px;
    border-bottom: 1px solid #e8edf5;
    text-align: left;
    font-size: 13px;
}
.payment-table th {
    color: #334155;
    background: #f3f6fb;
    font-weight: 700;
}
.payment-table td { color: #475569; }
.payment-amount { color: #15803d !important; font-weight: 700; }
.payment-method {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    background: #eaf1ff;
    color: #1746a2;
    font-size: 12px;
    font-weight: 600;
}
.payment-delete {
    background: #fff1f2;
    color: #be123c;
    border: 1px solid #fecdd3;
    padding: 7px 10px;
    border-radius: 6px;
    cursor: pointer;
}
.payment-alert {
    padding: 12px 15px;
    border-radius: 8px;
    margin-bottom: 16px;
}
.payment-alert.success {
    color: #166534;
    background: #dcfce7;
}
.payment-alert.error {
    color: #991b1b;
    background: #fee2e2;
}
.payment-empty {
    text-align: center !important;
    padding: 30px !important;
    color: #64748b !important;
}
@media (max-width: 650px) {
    .payment-form-grid { grid-template-columns: 1fr; }
    .payment-field.full { grid-column: auto; }
    .payment-card { padding: 15px; }
    .payment-search { flex-direction: column; }
}
</style>

<?php if ($success !== ''): ?>
    <div class="payment-alert success">
        <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="payment-alert error">
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<div class="payment-stats">
    <div class="payment-stat">
        <div class="stat-label">Total Payments</div>
        <div class="stat-value">₹<?= number_format($totalPayments, 2) ?></div>
    </div>
    <div class="payment-stat">
        <div class="stat-label">Today's Collection</div>
        <div class="stat-value">₹<?= number_format($todayPayments, 2) ?></div>
    </div>
    <div class="payment-stat">
        <div class="stat-label">Payment Entries</div>
        <div class="stat-value"><?= number_format($paymentCount) ?></div>
    </div>
</div>

<div class="payment-card">
    <h3>+ Add New Payment</h3>

    <form method="POST" action="payments.php">
        <input type="hidden" name="csrf_token"
               value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

        <div class="payment-form-grid">
            <div class="payment-field">
                <label for="customer_id">Select Customer *</label>
                <select name="customer_id" id="customer_id" required>
                    <option value="">-- Select Customer --</option>
                    <?php foreach ($customers as $customer): ?>
                        <option value="<?= (int)$customer['id'] ?>">
                            <?= htmlspecialchars($customer['name'], ENT_QUOTES, 'UTF-8') ?>
                            <?= !empty($customer['mobile']) ? ' - ' . htmlspecialchars($customer['mobile'], ENT_QUOTES, 'UTF-8') : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="payment-field">
                <label for="amount">Amount (₹) *</label>
                <input type="number" name="amount" id="amount"
                       min="0.01" step="0.01" placeholder="Enter amount" required>
            </div>

            <div class="payment-field">
                <label for="payment_method">Payment Method *</label>
                <select name="payment_method" id="payment_method" required>
                    <option value="Cash">Cash</option>
                    <option value="UPI">UPI</option>
                    <option value="Bank Transfer">Bank Transfer</option>
                    <option value="Card">Card</option>
                    <option value="Other">Other</option>
                </select>
            </div>

            <div class="payment-field">
                <label for="payment_date">Payment Date *</label>
                <input type="date" name="payment_date" id="payment_date"
                       value="<?= date('Y-m-d') ?>" required>
            </div>

            <div class="payment-field full">
                <label for="notes">Notes (Optional)</label>
                <textarea name="notes" id="notes" rows="2"
                          placeholder="Enter payment details"></textarea>
            </div>

            <div class="payment-field full">
                <button type="submit" name="add_payment" class="payment-btn">
                    Save Payment
                </button>
            </div>
        </div>
    </form>
</div>

<div class="payment-card">
    <h3>Payment History</h3>

    <form method="GET" action="payments.php" class="payment-search">
        <input type="text" name="search"
               placeholder="Search customer, mobile or payment method..."
               value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" class="payment-btn">Search</button>
        <?php if ($search !== ''): ?>
            <a href="payments.php" class="payment-btn"
               style="text-decoration:none; display:inline-flex; align-items:center;">
                Clear
            </a>
        <?php endif; ?>
    </form>

    <div class="payment-table-wrap">
        <table class="payment-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Mobile</th>
                    <th>Method</th>
                    <th>Amount</th>
                    <th>Notes</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if (count($payments) > 0): ?>
                <?php foreach ($payments as $index => $payment): ?>
                    <tr>
                        <td><?= $index + 1 ?></td>
                        <td><?= htmlspecialchars($payment['payment_date'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($payment['customer_name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($payment['mobile'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <span class="payment-method">
                                <?= htmlspecialchars($payment['payment_method'] ?? '-', ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </td>
                        <td class="payment-amount">
                            ₹<?= number_format((float)$payment['amount'], 2) ?>
                        </td>
                        <td><?= htmlspecialchars($payment['notes'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <form method="POST" action="payments.php"
                                  onsubmit="return confirm('Delete this payment?');">
                                <input type="hidden" name="csrf_token"
                                       value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="payment_id"
                                       value="<?= (int)$payment['id'] ?>">
                                <button type="submit" name="delete_payment"
                                        class="payment-delete">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="8" class="payment-empty">
                        No payment records found.
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

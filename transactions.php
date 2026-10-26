
<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/config/db.php';

if (empty($_SESSION['transactions_csrf'])) {
    $_SESSION['transactions_csrf'] = bin2hex(random_bytes(32));
}

if (empty($_SESSION['logout_csrf'])) {
    $_SESSION['logout_csrf'] = bin2hex(random_bytes(32));
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$error = '';
$editTransaction = null;
$customers = [];
$services = [];
$transactions = [];

$totals = [
    'total_count' => 0,
    'paid_total' => 0,
    'pending_total' => 0
];

$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = (string)($_GET['status'] ?? '');

$paymentMethods = ['Cash', 'UPI', 'Bank Transfer', 'Card'];
$paymentStatuses = ['Paid', 'Pending'];

try {
    /*
     * SAVE / UPDATE / DELETE
     */
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';

        if (
            !is_string($token) ||
            !hash_equals($_SESSION['transactions_csrf'], $token)
        ) {
            throw new RuntimeException(
                'Security token चुकीचा आहे. पेज Refresh करून पुन्हा प्रयत्न करा.'
            );
        }

        $action = $_POST['action'] ?? '';

        if ($action === 'save') {
            $id = filter_var(
                $_POST['id'] ?? '0',
                FILTER_VALIDATE_INT
            );

            $customerId = filter_var(
                $_POST['customer_id'] ?? '',
                FILTER_VALIDATE_INT
            );

            $serviceId = filter_var(
                $_POST['service_id'] ?? '',
                FILTER_VALIDATE_INT
            );

            $amountText = trim((string)($_POST['amount'] ?? ''));
            $method = (string)($_POST['payment_method'] ?? '');
            $status = (string)($_POST['payment_status'] ?? '');
            $date = (string)($_POST['transaction_date'] ?? '');
            $notes = trim((string)($_POST['notes'] ?? ''));

            if ($id === false || $id < 0) {
                throw new RuntimeException('Transaction ID चुकीचा आहे.');
            }

            if ($customerId === false || $customerId < 1) {
                throw new RuntimeException('कृपया ग्राहक निवडा.');
            }

            if ($serviceId === false || $serviceId < 1) {
                throw new RuntimeException('कृपया सेवा निवडा.');
            }

            if (
                $amountText === '' ||
                !is_numeric($amountText) ||
                !is_finite((float)$amountText) ||
                (float)$amountText < 0 ||
                (float)$amountText > 99999999.99
            ) {
                throw new RuntimeException('व्यवहाराची योग्य रक्कम भरा.');
            }

            if (!in_array($method, $paymentMethods, true)) {
                throw new RuntimeException('योग्य Payment Method निवडा.');
            }

            if (!in_array($status, $paymentStatuses, true)) {
                throw new RuntimeException('योग्य Payment Status निवडा.');
            }

            $dateObject = DateTime::createFromFormat('!Y-m-d', $date);

            if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
                throw new RuntimeException('व्यवहाराची योग्य तारीख निवडा.');
            }

            if (mb_strlen($notes) > 5000) {
                throw new RuntimeException(
                    'Notes जास्तीत जास्त 5000 अक्षरांचे असावेत.'
                );
            }

            // ग्राहक अस्तित्वात आहे का ते तपासा.
            $stmt = $pdo->prepare(
                'SELECT id FROM customers WHERE id = ?'
            );
            $stmt->execute([$customerId]);

            if (!$stmt->fetchColumn()) {
                throw new RuntimeException('निवडलेला ग्राहक सापडला नाही.');
            }

            // सेवा अस्तित्वात आहे का ते तपासा.
            $stmt = $pdo->prepare(
                'SELECT id FROM services WHERE id = ?'
            );
            $stmt->execute([$serviceId]);

            if (!$stmt->fetchColumn()) {
                throw new RuntimeException('निवडलेली सेवा सापडली नाही.');
            }

            $amount = number_format((float)$amountText, 2, '.', '');

            if ($id > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE transactions
                     SET customer_id = ?,
                         service_id = ?,
                         amount = ?,
                         payment_method = ?,
                         payment_status = ?,
                         transaction_date = ?,
                         notes = ?
                     WHERE id = ?'
                );

                $stmt->execute([
                    $customerId,
                    $serviceId,
                    $amount,
                    $method,
                    $status,
                    $date,
                    $notes !== '' ? $notes : null,
                    $id
                ]);

                $_SESSION['transactions_flash'] =
                    'व्यवहाराची माहिती अपडेट झाली.';
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO transactions
                     (
                         customer_id,
                         service_id,
                         amount,
                         payment_method,
                         payment_status,
                         transaction_date,
                         notes
                     )
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );

                $stmt->execute([
                    $customerId,
                    $serviceId,
                    $amount,
                    $method,
                    $status,
                    $date,
                    $notes !== '' ? $notes : null
                ]);

                $_SESSION['transactions_flash'] =
                    'नवीन व्यवहार यशस्वीपणे नोंदवला.';
            }

            header('Location: transactions.php');
            exit;
        }

        if ($action === 'delete') {
            $id = filter_var(
                $_POST['id'] ?? '',
                FILTER_VALIDATE_INT
            );

            if ($id === false || $id < 1) {
                throw new RuntimeException('Transaction ID चुकीचा आहे.');
            }

            $stmt = $pdo->prepare(
                'DELETE FROM transactions WHERE id = ?'
            );
            $stmt->execute([$id]);

            $_SESSION['transactions_flash'] =
                $stmt->rowCount() > 0
                    ? 'व्यवहार Delete झाला.'
                    : 'व्यवहार सापडला नाही.';

            header('Location: transactions.php');
            exit;
        }

        throw new RuntimeException('अवैध कृती.');
    }

    /*
     * EDIT TRANSACTION
     */
    if (isset($_GET['edit'])) {
        $editId = filter_var(
            $_GET['edit'],
            FILTER_VALIDATE_INT
        );

        if ($editId === false || $editId < 1) {
            throw new RuntimeException('Transaction ID चुकीचा आहे.');
        }

        $stmt = $pdo->prepare(
            'SELECT *
             FROM transactions
             WHERE id = ?'
        );
        $stmt->execute([$editId]);

        $editTransaction = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$editTransaction) {
            $error = 'Edit करण्यासाठी व्यवहार सापडला नाही.';
        }
    }

    /*
     * CUSTOMER DROPDOWN
     */
    $customers = $pdo->query(
        'SELECT id, name, mobile
         FROM customers
         ORDER BY name ASC'
    )->fetchAll(PDO::FETCH_ASSOC);

    /*
     * SERVICE DROPDOWN
     */
    $services = $pdo->query(
        'SELECT id, service_name, default_fee
         FROM services
         ORDER BY service_name ASC'
    )->fetchAll(PDO::FETCH_ASSOC);

    /*
     * SEARCH AND STATUS FILTER
     */
    if (!in_array($statusFilter, $paymentStatuses, true)) {
        $statusFilter = '';
    }

    $sql = '
        SELECT
            t.id,
            t.customer_id,
            t.service_id,
            t.amount,
            t.payment_method,
            t.payment_status,
            t.transaction_date,
            t.notes,
            c.name AS customer_name,
            c.mobile AS customer_mobile,
            s.service_name
        FROM transactions t
        INNER JOIN customers c ON c.id = t.customer_id
        INNER JOIN services s ON s.id = t.service_id
        WHERE 1 = 1
    ';

    $params = [];

    if ($search !== '') {
        $sql .= '
            AND (
                c.name LIKE ?
                OR c.mobile LIKE ?
                OR s.service_name LIKE ?
            )
        ';

        $term = '%' . $search . '%';

        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
    }

    if ($statusFilter !== '') {
        $sql .= ' AND t.payment_status = ?';
        $params[] = $statusFilter;
    }

    $sql .= ' ORDER BY t.transaction_date DESC, t.id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /*
     * SUMMARY TOTALS
     */
    $totals = $pdo->query(
        "SELECT
            COUNT(*) AS total_count,
            COALESCE(
                SUM(
                    CASE
                        WHEN payment_status = 'Paid' THEN amount
                        ELSE 0
                    END
                ), 0
            ) AS paid_total,
            COALESCE(
                SUM(
                    CASE
                        WHEN payment_status = 'Pending' THEN amount
                        ELSE 0
                    END
                ), 0
            ) AS pending_total
         FROM transactions"
    )->fetch(PDO::FETCH_ASSOC);

} catch (RuntimeException $e) {
    $error = $e->getMessage();

} catch (PDOException $e) {
    error_log($e->getMessage());
    $error = 'Database त्रुटी आली. transactions, customers आणि services टेबल तपासा.';
}

$flash = $_SESSION['transactions_flash'] ?? null;
unset($_SESSION['transactions_flash']);

$activePage = 'transactions';
$pageTitle = 'Transactions Management';
$pageSubtitle = 'व्यवहार, जमा रक्कम आणि बाकी रक्कम';
?>

<?php include __DIR__ . '/includes/sidebar.php'; ?>
<?php include __DIR__ . '/includes/header.php'; ?>

<?php if ($flash): ?>
    <div class="alert"><?= h($flash) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert"><?= h($error) ?></div>
<?php endif; ?>

<!-- SUMMARY CARDS -->
<div class="stats-grid">

    <div class="stat-card">
        <div class="stat-label">एकूण व्यवहार</div>
        <div class="stat-value">
            <?= (int)($totals['total_count'] ?? 0) ?>
        </div>
        <div class="stat-note">नोंदवलेले व्यवहार</div>
    </div>

    <div class="stat-card">
        <div class="stat-label">जमा रक्कम</div>
        <div class="stat-value">
            ₹<?= number_format((float)($totals['paid_total'] ?? 0), 2) ?>
        </div>
        <div class="stat-note">Paid व्यवहार</div>
    </div>

    <div class="stat-card">
        <div class="stat-label">बाकी रक्कम</div>
        <div class="stat-value">
            ₹<?= number_format((float)($totals['pending_total'] ?? 0), 2) ?>
        </div>
        <div class="stat-note">Pending व्यवहार</div>
    </div>

</div>

<!-- ADD / EDIT TRANSACTION -->
<div class="panel">
    <div class="panel-header">
        <div>
            <h2>
                <?= $editTransaction
                    ? 'व्यवहार Edit करा'
                    : 'नवीन व्यवहार नोंदवा' ?>
            </h2>
            <p>ग्राहक, सेवा, रक्कम आणि पेमेंटची माहिती भरा.</p>
        </div>
    </div>

    <div class="panel-body">
        <form method="post" action="transactions.php" class="form-grid">

            <input type="hidden"
                   name="csrf_token"
                   value="<?= h($_SESSION['transactions_csrf']) ?>">

            <input type="hidden" name="action" value="save">

            <input type="hidden"
                   name="id"
                   value="<?= h($editTransaction['id'] ?? 0) ?>">

            <div class="form-group">
                <label for="customer_id">ग्राहक *</label>

                <select class="form-control"
                        name="customer_id"
                        id="customer_id"
                        required>
                    <option value="">ग्राहक निवडा</option>

                    <?php foreach ($customers as $customer): ?>
                        <option
                            value="<?= (int)$customer['id'] ?>"
                            <?= (int)($editTransaction['customer_id'] ?? 0)
                                === (int)$customer['id'] ? 'selected' : '' ?>>
                            <?= h($customer['name']) ?>
                            (<?= h($customer['mobile']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>

                <?php if (!$customers): ?>
                    <small>प्रथम Customers विभागात ग्राहक जोडा.</small>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="service_id">सेवा *</label>

                <select class="form-control"
                        name="service_id"
                        id="service_id"
                        required>
                    <option value="">सेवा निवडा</option>

                    <?php foreach ($services as $service): ?>
                        <option
                            value="<?= (int)$service['id'] ?>"
                            data-fee="<?= h($service['default_fee']) ?>"
                            <?= (int)($editTransaction['service_id'] ?? 0)
                                === (int)$service['id'] ? 'selected' : '' ?>>
                            <?= h($service['service_name']) ?>
                            — ₹<?= number_format((float)$service['default_fee'], 2) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <?php if (!$services): ?>
                    <small>प्रथम Services विभागात सेवा जोडा.</small>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="amount">व्यवहाराची रक्कम (₹) *</label>
                <input class="form-control"
                       type="number"
                       id="amount"
                       name="amount"
                       min="0"
                       max="99999999.99"
                       step="0.01"
                       required
                       value="<?= h($editTransaction['amount'] ?? '') ?>"
                       placeholder="उदा. 100">
            </div>

            <div class="form-group">
                <label for="payment_method">Payment Method *</label>

                <select class="form-control"
                        id="payment_method"
                        name="payment_method"
                        required>
                    <?php foreach ($paymentMethods as $method): ?>
                        <option
                            value="<?= h($method) ?>"
                            <?= ($editTransaction['payment_method'] ?? 'Cash') === $method
                                ? 'selected' : '' ?>>
                            <?= h($method) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="payment_status">Payment Status *</label>

                <select class="form-control"
                        id="payment_status"
                        name="payment_status"
                        required>
                    <?php foreach ($paymentStatuses as $status): ?>
                        <option
                            value="<?= h($status) ?>"
                            <?= ($editTransaction['payment_status'] ?? 'Pending') === $status
                                ? 'selected' : '' ?>>
                            <?= $status === 'Paid'
                                ? 'Paid - जमा'
                                : 'Pending - बाकी' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="transaction_date">व्यवहाराची तारीख *</label>
                <input class="form-control"
                       type="date"
                       id="transaction_date"
                       name="transaction_date"
                       required
                       value="<?= h(
                           $editTransaction['transaction_date']
                           ?? date('Y-m-d')
                       ) ?>">
            </div>

            <div class="form-group">
                <label for="notes">Notes (ऐच्छिक)</label>
                <textarea class="form-control"
                          id="notes"
                          name="notes"
                          rows="3"
                          maxlength="5000"
                          placeholder="व्यवहाराबद्दल अतिरिक्त माहिती"><?= h($editTransaction['notes'] ?? '') ?></textarea>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">
                    <?= $editTransaction
                        ? 'Update Transaction'
                        : 'Save Transaction' ?>
                </button>

                <?php if ($editTransaction): ?>
                    <a class="btn btn-secondary"
                       href="transactions.php">
                        Cancel
                    </a>
                <?php endif; ?>
            </div>

        </form>
    </div>
</div>

<!-- TRANSACTIONS LIST -->
<div class="panel">
    <div class="panel-header">
        <div>
            <h2>व्यवहारांची यादी</h2>
            <p>व्यवहार शोधा, Edit करा किंवा Delete करा.</p>
        </div>
    </div>

    <div class="panel-body">

        <form method="get"
              action="transactions.php"
              class="search-form"
              style="margin-bottom:20px">

            <input class="form-control"
                   type="search"
                   name="search"
                   value="<?= h($search) ?>"
                   placeholder="ग्राहक, मोबाइल किंवा सेवा शोधा...">

            <select class="form-control" name="status">
                <option value="">सर्व Status</option>
                <option value="Paid"
                    <?= $statusFilter === 'Paid' ? 'selected' : '' ?>>
                    Paid
                </option>
                <option value="Pending"
                    <?= $statusFilter === 'Pending' ? 'selected' : '' ?>>
                    Pending
                </option>
            </select>

            <button class="btn btn-primary" type="submit">
                Search
            </button>

            <a class="btn btn-secondary" href="transactions.php">
                Reset
            </a>
        </form>

        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>तारीख</th>
                        <th>ग्राहक</th>
                        <th>सेवा</th>
                        <th>रक्कम</th>
                        <th>Payment</th>
                        <th>Status</th>
                        <th>कृती</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (!$transactions): ?>
                    <tr>
                        <td colspan="8">व्यवहार सापडला नाही.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($transactions as $transaction): ?>
                        <tr>
                            <td><?= (int)$transaction['id'] ?></td>

                            <td><?= h($transaction['transaction_date']) ?></td>

                            <td>
                                <?= h($transaction['customer_name']) ?>
                                <br>
                                <small><?= h($transaction['customer_mobile']) ?></small>
                            </td>

                            <td><?= h($transaction['service_name']) ?></td>

                            <td>
                                ₹<?= number_format((float)$transaction['amount'], 2) ?>
                            </td>

                            <td><?= h($transaction['payment_method']) ?></td>

                            <td>
                                <?= $transaction['payment_status'] === 'Paid'
                                    ? 'Paid - जमा'
                                    : 'Pending - बाकी' ?>
                            </td>

                            <td>
                                <div class="customer-actions">
                                    <a class="btn btn-secondary"
                                       href="transactions.php?edit=<?= (int)$transaction['id'] ?>">
                                        Edit
                                    </a>

                                    <form method="post"
                                          action="transactions.php"
                                          onsubmit="return confirm('हा व्यवहार Delete करायचा आहे का?');">

                                        <input type="hidden"
                                               name="csrf_token"
                                               value="<?= h($_SESSION['transactions_csrf']) ?>">

                                        <input type="hidden"
                                               name="action"
                                               value="delete">

                                        <input type="hidden"
                                               name="id"
                                               value="<?= (int)$transaction['id'] ?>">

                                        <button type="submit"
                                                class="btn btn-danger">
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
</div>

<script>
// निवडलेल्या सेवेची Default Fee आपोआप भरणे.
const serviceSelect = document.getElementById('service_id');
const amountInput = document.getElementById('amount');

if (serviceSelect && amountInput) {
    serviceSelect.addEventListener('change', function () {
        const option = this.options[this.selectedIndex];

        if (option && option.dataset.fee !== undefined) {
            amountInput.value = option.dataset.fee;
        }
    });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>

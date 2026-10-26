
<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/config/db.php';

if (empty($_SESSION['receipts_csrf'])) {
    $_SESSION['receipts_csrf'] = bin2hex(random_bytes(32));
}

if (empty($_SESSION['logout_csrf'])) {
    $_SESSION['logout_csrf'] = bin2hex(random_bytes(32));
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$error = '';
$search = trim((string)($_GET['search'] ?? ''));
$availableTransactions = [];
$receipts = [];

try {
    // CREATE RECEIPT
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';

        if (
            !is_string($token) ||
            !hash_equals($_SESSION['receipts_csrf'], $token)
        ) {
            throw new RuntimeException(
                'Security token चुकीचा आहे. पेज Refresh करून पुन्हा प्रयत्न करा.'
            );
        }

        $transactionId = filter_var(
            $_POST['transaction_id'] ?? '',
            FILTER_VALIDATE_INT
        );

        if (!$transactionId || $transactionId < 1) {
            throw new RuntimeException('योग्य व्यवहार निवडा.');
        }

        $stmt = $pdo->prepare(
            "SELECT id, payment_status
             FROM transactions
             WHERE id = ?"
        );
        $stmt->execute([$transactionId]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$transaction) {
            throw new RuntimeException('व्यवहार सापडला नाही.');
        }

        if ($transaction['payment_status'] !== 'Paid') {
            throw new RuntimeException(
                'फक्त Paid व्यवहारासाठी पावती तयार करता येईल.'
            );
        }

        // Check for an existing receipt
        $stmt = $pdo->prepare(
            'SELECT id FROM receipts WHERE transaction_id = ?'
        );
        $stmt->execute([$transactionId]);

        if ($stmt->fetchColumn()) {
            throw new RuntimeException(
                'या व्यवहाराची पावती आधीच तयार झाली आहे.'
            );
        }

        $receiptNo = 'SSM-' . date('Ymd') . '-T' . $transactionId;

        $stmt = $pdo->prepare(
            'INSERT INTO receipts (receipt_no, transaction_id)
             VALUES (?, ?)'
        );
        $stmt->execute([$receiptNo, $transactionId]);

        $receiptId = (int)$pdo->lastInsertId();

        header('Location: print_receipt.php?id=' . $receiptId);
        exit;
    }

    // AVAILABLE PAID TRANSACTIONS
    $sql = "
        SELECT
            t.id,
            t.transaction_date,
            t.amount,
            c.name AS customer_name,
            c.mobile AS customer_mobile,
            s.service_name
        FROM transactions t
        INNER JOIN customers c ON c.id = t.customer_id
        INNER JOIN services s ON s.id = t.service_id
        LEFT JOIN receipts r ON r.transaction_id = t.id
        WHERE t.payment_status = 'Paid'
          AND r.id IS NULL
    ";

    $params = [];

    if ($search !== '') {
        $sql .= "
            AND (
                c.name LIKE ?
                OR c.mobile LIKE ?
                OR s.service_name LIKE ?
                OR CAST(t.id AS CHAR) LIKE ?
            )
        ";

        $term = '%' . $search . '%';
        $params = [$term, $term, $term, $term];
    }

    $sql .= ' ORDER BY t.id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $availableTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // EXISTING RECEIPTS
    $receiptSql = "
        SELECT
            r.id AS receipt_id,
            r.receipt_no,
            r.issued_at,
            t.id AS transaction_id,
            t.transaction_date,
            t.amount,
            c.name AS customer_name,
            c.mobile AS customer_mobile,
            s.service_name
        FROM receipts r
        INNER JOIN transactions t ON t.id = r.transaction_id
        INNER JOIN customers c ON c.id = t.customer_id
        INNER JOIN services s ON s.id = t.service_id
    ";

    $receiptParams = [];

    if ($search !== '') {
        $receiptSql .= "
            WHERE r.receipt_no LIKE ?
               OR c.name LIKE ?
               OR c.mobile LIKE ?
               OR s.service_name LIKE ?
               OR CAST(t.id AS CHAR) LIKE ?
        ";

        $term = '%' . $search . '%';
        $receiptParams = [
            $term,
            $term,
            $term,
            $term,
            $term
        ];
    }

    $receiptSql .= ' ORDER BY r.id DESC';

    $stmt = $pdo->prepare($receiptSql);
    $stmt->execute($receiptParams);
    $receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (RuntimeException $e) {
    $error = $e->getMessage();

} catch (PDOException $e) {
    error_log($e->getMessage());
    $error = 'Database त्रुटी आली. transactions आणि receipts टेबल तपासा.';
}

$activePage = 'receipts';
$pageTitle = 'Receipt Management';
$pageSubtitle = 'पावती तयार करणे आणि प्रिंट करणे';
?>

<?php include __DIR__ . '/includes/sidebar.php'; ?>
<?php include __DIR__ . '/includes/header.php'; ?>

<?php if ($error !== ''): ?>
    <div class="alert">
        <?= h($error) ?>
    </div>
<?php endif; ?>

<!-- SEARCH -->
<section class="panel">
    <div class="panel-header">
        <div>
            <h2>पावती शोधा</h2>
            <p>ग्राहक, मोबाइल, सेवा किंवा व्यवहार क्रमांक वापरा.</p>
        </div>
    </div>

    <div class="panel-body">
        <form method="get" action="receipts.php" class="search-form">
            <input
                class="form-control"
                type="search"
                name="search"
                value="<?= h($search) ?>"
                placeholder="पावती किंवा ग्राहक शोधा..."
            >

            <button class="btn btn-primary" type="submit">
                Search
            </button>

            <a class="btn btn-secondary" href="receipts.php">
                Reset
            </a>
        </form>
    </div>
</section>

<!-- CREATE RECEIPT -->
<section class="panel">
    <div class="panel-header">
        <div>
            <h2>नवीन पावती तयार करा</h2>
            <p>फक्त Paid व्यवहारांची पावती तयार करता येईल.</p>
        </div>
    </div>

    <div class="panel-body">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>व्यवहार ID</th>
                        <th>तारीख</th>
                        <th>ग्राहक</th>
                        <th>सेवा</th>
                        <th>रक्कम</th>
                        <th>कृती</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (empty($availableTransactions)): ?>
                    <tr>
                        <td colspan="6">
                            पावतीसाठी नवीन Paid व्यवहार उपलब्ध नाहीत.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($availableTransactions as $t): ?>
                        <tr>
                            <td><?= (int)$t['id'] ?></td>

                            <td><?= h($t['transaction_date']) ?></td>

                            <td>
                                <?= h($t['customer_name']) ?>
                                <br>
                                <small><?= h($t['customer_mobile']) ?></small>
                            </td>

                            <td><?= h($t['service_name']) ?></td>

                            <td>
                                ₹<?= number_format((float)$t['amount'], 2) ?>
                            </td>

                            <td>
                                <form
                                    method="post"
                                    action="receipts.php"
                                    onsubmit="return confirm('या व्यवहाराची पावती तयार करायची आहे का?');"
                                >
                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= h($_SESSION['receipts_csrf']) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="transaction_id"
                                        value="<?= (int)$t['id'] ?>"
                                    >

                                    <button
                                        type="submit"
                                        class="btn btn-primary"
                                    >
                                        Create Receipt
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<!-- EXISTING RECEIPTS -->
<section class="panel">
    <div class="panel-header">
        <div>
            <h2>तयार केलेल्या पावत्या</h2>
            <p>पावती पुन्हा पाहा किंवा प्रिंट करा.</p>
        </div>
    </div>

    <div class="panel-body">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>पावती क्रमांक</th>
                        <th>तारीख</th>
                        <th>ग्राहक</th>
                        <th>सेवा</th>
                        <th>रक्कम</th>
                        <th>कृती</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (empty($receipts)): ?>
                    <tr>
                        <td colspan="6">
                            अजून पावती तयार केलेली नाही.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($receipts as $r): ?>
                        <tr>
                            <td><?= h($r['receipt_no']) ?></td>

                            <td><?= h($r['transaction_date']) ?></td>

                            <td>
                                <?= h($r['customer_name']) ?>
                                <br>
                                <small><?= h($r['customer_mobile']) ?></small>
                            </td>

                            <td><?= h($r['service_name']) ?></td>

                            <td>
                                ₹<?= number_format((float)$r['amount'], 2) ?>
                            </td>

                            <td>
                                <a
                                    class="btn btn-primary"
                                    href="print_receipt.php?id=<?= (int)$r['receipt_id'] ?>"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    View / Print
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>

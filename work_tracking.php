
<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/config/db.php';

if (empty($_SESSION['work_tracking_csrf'])) {
    $_SESSION['work_tracking_csrf'] = bin2hex(random_bytes(32));
}

if (empty($_SESSION['logout_csrf'])) {
    $_SESSION['logout_csrf'] = bin2hex(random_bytes(32));
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$error = '';
$success = '';
$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$editWork = null;
$workRecords = [];
$customers = [];
$services = [];

$statuses = [
    'Pending',
    'In Progress',
    'Completed',
    'Rejected'
];

$form = [
    'id' => '',
    'customer_id' => '',
    'service_id' => '',
    'application_no' => '',
    'work_date' => date('Y-m-d'),
    'total_fee' => '',
    'paid_amount' => '0',
    'status' => 'Pending',
    'notes' => ''
];

try {
    // ADD, UPDATE OR DELETE WORK RECORD
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';

        if (
            !is_string($token) ||
            !hash_equals($_SESSION['work_tracking_csrf'], $token)
        ) {
            throw new RuntimeException(
                'Security token चुकीचा आहे. पेज Refresh करून पुन्हा प्रयत्न करा.'
            );
        }

        $action = (string)($_POST['action'] ?? '');

        if ($action === 'delete') {
            $id = filter_var(
                $_POST['id'] ?? '',
                FILTER_VALIDATE_INT
            );

            if (!$id || $id < 1) {
                throw new RuntimeException('योग्य Work Record निवडा.');
            }

            $stmt = $pdo->prepare(
                'DELETE FROM work_records WHERE id = ?'
            );
            $stmt->execute([$id]);

            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('कामाचा रेकॉर्ड सापडला नाही.');
            }

            header('Location: work_tracking.php?deleted=1');
            exit;
        }

        if ($action === 'save') {
            $id = filter_var(
                $_POST['id'] ?? '',
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

            $applicationNo = trim(
                (string)($_POST['application_no'] ?? '')
            );

            $workDate = trim(
                (string)($_POST['work_date'] ?? '')
            );

            $totalFeeInput = trim(
                (string)($_POST['total_fee'] ?? '')
            );

            $paidAmountInput = trim(
                (string)($_POST['paid_amount'] ?? '0')
            );

            $status = trim(
                (string)($_POST['status'] ?? 'Pending')
            );

            $notes = trim(
                (string)($_POST['notes'] ?? '')
            );

            $form = [
                'id' => $id ?: '',
                'customer_id' => $customerId ?: '',
                'service_id' => $serviceId ?: '',
                'application_no' => $applicationNo,
                'work_date' => $workDate,
                'total_fee' => $totalFeeInput,
                'paid_amount' => $paidAmountInput,
                'status' => $status,
                'notes' => $notes
            ];

            if (!$customerId || $customerId < 1) {
                throw new RuntimeException('ग्राहक निवडा.');
            }

            if (!$serviceId || $serviceId < 1) {
                throw new RuntimeException('सेवा निवडा.');
            }

            if (
                $id === false ||
                ($id !== null && $id !== false && $id < 1)
            ) {
                throw new RuntimeException('Work Record ID चुकीचा आहे.');
            }

            if ($id === null && isset($_POST['id']) && $_POST['id'] !== '') {
                throw new RuntimeException('Work Record ID चुकीचा आहे.');
            }

            if ($applicationNo === '') {
                throw new RuntimeException('अर्ज क्रमांक टाका.');
            }

            $dateObject = DateTime::createFromFormat('!Y-m-d', $workDate);

            if (
                !$dateObject ||
                $dateObject->format('Y-m-d') !== $workDate
            ) {
                throw new RuntimeException('योग्य कामाची तारीख निवडा.');
            }

            if (
                $totalFeeInput === '' ||
                !is_numeric($totalFeeInput) ||
                (float)$totalFeeInput < 0
            ) {
                throw new RuntimeException('योग्य एकूण फी टाका.');
            }

            if (
                $paidAmountInput === '' ||
                !is_numeric($paidAmountInput) ||
                (float)$paidAmountInput < 0
            ) {
                throw new RuntimeException('योग्य भरलेली रक्कम टाका.');
            }

            $totalFee = round((float)$totalFeeInput, 2);
            $paidAmount = round((float)$paidAmountInput, 2);

            if ($paidAmount > $totalFee) {
                throw new RuntimeException(
                    'भरलेली रक्कम एकूण फीपेक्षा जास्त असू शकत नाही.'
                );
            }

            if (!in_array($status, $statuses, true)) {
                throw new RuntimeException('योग्य Status निवडा.');
            }

            // Confirm customer exists
            $stmt = $pdo->prepare(
                'SELECT id FROM customers WHERE id = ?'
            );
            $stmt->execute([$customerId]);

            if (!$stmt->fetchColumn()) {
                throw new RuntimeException('निवडलेला ग्राहक सापडला नाही.');
            }

            // Confirm service exists
            $stmt = $pdo->prepare(
                'SELECT id FROM services WHERE id = ?'
            );
            $stmt->execute([$serviceId]);

            if (!$stmt->fetchColumn()) {
                throw new RuntimeException('निवडलेली सेवा सापडली नाही.');
            }

            if ($id) {
                $stmt = $pdo->prepare(
                    'SELECT id FROM work_records WHERE id = ?'
                );
                $stmt->execute([$id]);

                if (!$stmt->fetchColumn()) {
                    throw new RuntimeException('Edit करण्यासाठी काम सापडले नाही.');
                }

                $stmt = $pdo->prepare(
                    "UPDATE work_records
                     SET customer_id = ?,
                         service_id = ?,
                         application_no = ?,
                         work_date = ?,
                         total_fee = ?,
                         paid_amount = ?,
                         status = ?,
                         notes = ?
                     WHERE id = ?"
                );

                $stmt->execute([
                    $customerId,
                    $serviceId,
                    $applicationNo,
                    $workDate,
                    $totalFee,
                    $paidAmount,
                    $status,
                    $notes,
                    $id
                ]);

                header('Location: work_tracking.php?updated=1');
                exit;
            }

            $stmt = $pdo->prepare(
                "INSERT INTO work_records
                    (customer_id, service_id, application_no,
                     work_date, total_fee, paid_amount, status, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );

            $stmt->execute([
                $customerId,
                $serviceId,
                $applicationNo,
                $workDate,
                $totalFee,
                $paidAmount,
                $status,
                $notes
            ]);

            header('Location: work_tracking.php?created=1');
            exit;
        }

        throw new RuntimeException('अवैध विनंती आहे.');
    }

    // LOAD CUSTOMERS
    $stmt = $pdo->query(
        'SELECT id, name, mobile FROM customers ORDER BY name ASC'
    );
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // LOAD SERVICES
    $stmt = $pdo->query(
        'SELECT id, service_name, default_fee
         FROM services
         ORDER BY service_name ASC'
    );
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // LOAD EDIT RECORD
    if (isset($_GET['edit'])) {
        $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);

        if (!$editId || $editId < 1) {
            throw new RuntimeException('Edit ID चुकीचा आहे.');
        }

        $stmt = $pdo->prepare(
            'SELECT * FROM work_records WHERE id = ?'
        );
        $stmt->execute([$editId]);
        $editWork = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$editWork) {
            throw new RuntimeException('कामाचा रेकॉर्ड सापडला नाही.');
        }

        $form = [
            'id' => $editWork['id'],
            'customer_id' => $editWork['customer_id'],
            'service_id' => $editWork['service_id'],
            'application_no' => $editWork['application_no'],
            'work_date' => $editWork['work_date'],
            'total_fee' => $editWork['total_fee'],
            'paid_amount' => $editWork['paid_amount'],
            'status' => $editWork['status'],
            'notes' => $editWork['notes'] ?? ''
        ];
    }

    // LOAD WORK RECORDS
    $sql = "
        SELECT
            w.id,
            w.customer_id,
            w.service_id,
            w.application_no,
            w.work_date,
            w.total_fee,
            w.paid_amount,
            w.status,
            w.notes,
            c.name AS customer_name,
            c.mobile AS customer_mobile,
            s.service_name
        FROM work_records w
        INNER JOIN customers c ON c.id = w.customer_id
        INNER JOIN services s ON s.id = w.service_id
        WHERE 1 = 1
    ";

    $params = [];

    if ($search !== '') {
        $sql .= "
            AND (
                c.name LIKE ?
                OR c.mobile LIKE ?
                OR w.application_no LIKE ?
                OR s.service_name LIKE ?
                OR CAST(w.id AS CHAR) LIKE ?
            )
        ";

        $term = '%' . $search . '%';
        $params = [$term, $term, $term, $term, $term];
    }

    if ($statusFilter !== '' && in_array($statusFilter, $statuses, true)) {
        $sql .= ' AND w.status = ?';
        $params[] = $statusFilter;
    }

    $sql .= ' ORDER BY w.id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $workRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (RuntimeException $e) {
    $error = $e->getMessage();

} catch (PDOException $e) {
    error_log($e->getMessage());
    $error = 'Database त्रुटी आली. work_records, customers आणि services टेबल तपासा.';
}

if (isset($_GET['created'])) {
    $success = 'नवीन काम यशस्वीपणे नोंदवले.';
} elseif (isset($_GET['updated'])) {
    $success = 'कामाची माहिती अपडेट झाली.';
} elseif (isset($_GET['deleted'])) {
    $success = 'कामाचा रेकॉर्ड डिलीट झाला.';
}

$activePage = 'work_tracking';
$pageTitle = 'Work Tracking';
$pageSubtitle = 'ग्राहकांची कामे, अर्ज आणि Status व्यवस्थापन';
?>

<?php include __DIR__ . '/includes/sidebar.php'; ?>
<?php include __DIR__ . '/includes/header.php'; ?>

<?php if ($error !== ''): ?>
    <div class="alert"><?= h($error) ?></div>
<?php endif; ?>

<?php if ($success !== ''): ?>
    <div class="alert"><?= h($success) ?></div>
<?php endif; ?>

<!-- WORK FORM -->
<section class="panel">
    <div class="panel-header">
        <div>
            <h2><?= !empty($form['id']) ? 'कामाची माहिती Edit करा' : 'नवीन काम नोंदवा' ?></h2>
            <p>ग्राहक, सेवा, अर्ज क्रमांक, फी आणि कामाचा Status भरा.</p>
        </div>
    </div>

    <div class="panel-body">
        <form method="post" action="work_tracking.php" class="form-grid">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= h($_SESSION['work_tracking_csrf']) ?>"
            >

            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= h($form['id']) ?>">

            <div class="form-group">
                <label for="customer_id">ग्राहक *</label>
                <select
                    class="form-control"
                    id="customer_id"
                    name="customer_id"
                    required
                >
                    <option value="">ग्राहक निवडा</option>
                    <?php foreach ($customers as $customer): ?>
                        <option
                            value="<?= (int)$customer['id'] ?>"
                            <?= (string)$form['customer_id'] === (string)$customer['id'] ? 'selected' : '' ?>
                        >
                            <?= h($customer['name']) ?>
                            <?= !empty($customer['mobile']) ? ' - ' . h($customer['mobile']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="service_id">सेवा *</label>
                <select
                    class="form-control"
                    id="service_id"
                    name="service_id"
                    required
                >
                    <option value="">सेवा निवडा</option>
                    <?php foreach ($services as $service): ?>
                        <option
                            value="<?= (int)$service['id'] ?>"
                            data-fee="<?= h($service['default_fee']) ?>"
                            <?= (string)$form['service_id'] === (string)$service['id'] ? 'selected' : '' ?>
                        >
                            <?= h($service['service_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="application_no">अर्ज क्रमांक *</label>
                <input
                    class="form-control"
                    type="text"
                    id="application_no"
                    name="application_no"
                    value="<?= h($form['application_no']) ?>"
                    maxlength="100"
                    required
                >
            </div>

            <div class="form-group">
                <label for="work_date">कामाची तारीख *</label>
                <input
                    class="form-control"
                    type="date"
                    id="work_date"
                    name="work_date"
                    value="<?= h($form['work_date']) ?>"
                    required
                >
            </div>

            <div class="form-group">
                <label for="total_fee">एकूण फी (₹) *</label>
                <input
                    class="form-control"
                    type="number"
                    id="total_fee"
                    name="total_fee"
                    min="0"
                    step="0.01"
                    value="<?= h($form['total_fee']) ?>"
                    required
                >
            </div>

            <div class="form-group">
                <label for="paid_amount">भरलेली रक्कम (₹)</label>
                <input
                    class="form-control"
                    type="number"
                    id="paid_amount"
                    name="paid_amount"
                    min="0"
                    step="0.01"
                    value="<?= h($form['paid_amount']) ?>"
                    required
                >
            </div>

            <div class="form-group">
                <label for="status">कामाचा Status *</label>
                <select class="form-control" id="status" name="status" required>
                    <?php foreach ($statuses as $status): ?>
                        <option
                            value="<?= h($status) ?>"
                            <?= $form['status'] === $status ? 'selected' : '' ?>
                        >
                            <?= h($status) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="notes">नोंदी</label>
                <textarea
                    class="form-control"
                    id="notes"
                    name="notes"
                    rows="3"
                    maxlength="5000"
                ><?= h($form['notes']) ?></textarea>
            </div>

            <div class="form-group form-actions">
                <button class="btn btn-primary" type="submit">
                    <?= !empty($form['id']) ? 'Update Work' : 'Save Work' ?>
                </button>

                <?php if (!empty($form['id'])): ?>
                    <a class="btn btn-secondary" href="work_tracking.php">
                        Cancel
                    </a>
                <?php else: ?>
                    <button class="btn btn-secondary" type="reset">
                        Reset Form
                    </button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</section>

<!-- SEARCH AND FILTER -->
<section class="panel">
    <div class="panel-header">
        <div>
            <h2>कामाचे रेकॉर्ड</h2>
            <p>ग्राहक, मोबाइल, सेवा किंवा अर्ज क्रमांकाने शोधा.</p>
        </div>
    </div>

    <div class="panel-body">
        <form method="get" action="work_tracking.php" class="search-form">
            <input
                class="form-control"
                type="search"
                name="search"
                value="<?= h($search) ?>"
                placeholder="काम किंवा ग्राहक शोधा..."
            >

            <select class="form-control" name="status">
                <option value="">सर्व Status</option>
                <?php foreach ($statuses as $status): ?>
                    <option
                        value="<?= h($status) ?>"
                        <?= $statusFilter === $status ? 'selected' : '' ?>
                    >
                        <?= h($status) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button class="btn btn-primary" type="submit">Search</button>
            <a class="btn btn-secondary" href="work_tracking.php">Reset</a>
        </form>
    </div>
</section>

<!-- WORK TABLE -->
<section class="panel">
    <div class="panel-body">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>तारीख</th>
                        <th>ग्राहक</th>
                        <th>सेवा / अर्ज क्रमांक</th>
                        <th>एकूण फी</th>
                        <th>भरलेली रक्कम</th>
                        <th>बाकी</th>
                        <th>Status</th>
                        <th>कृती</th>
                    </tr>
                </thead>

                <tbody>
                <?php if (empty($workRecords)): ?>
                    <tr>
                        <td colspan="9">कामाचे रेकॉर्ड उपलब्ध नाहीत.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($workRecords as $work): ?>
                        <?php
                            $balance = max(
                                0,
                                (float)$work['total_fee'] - (float)$work['paid_amount']
                            );
                        ?>
                        <tr>
                            <td><?= (int)$work['id'] ?></td>
                            <td><?= h($work['work_date']) ?></td>
                            <td>
                                <?= h($work['customer_name']) ?>
                                <br>
                                <small><?= h($work['customer_mobile']) ?></small>
                            </td>
                            <td>
                                <strong><?= h($work['service_name']) ?></strong>
                                <br>
                                <small>अर्ज: <?= h($work['application_no']) ?></small>
                            </td>
                            <td>₹<?= number_format((float)$work['total_fee'], 2) ?></td>
                            <td>₹<?= number_format((float)$work['paid_amount'], 2) ?></td>
                            <td>₹<?= number_format($balance, 2) ?></td>
                            <td>
                                <span class="status-badge">
                                    <?= h($work['status']) ?>
                                </span>
                            </td>
                            <td>
                                <div class="customer-actions">
                                    <a
                                        class="btn btn-secondary"
                                        href="work_tracking.php?edit=<?= (int)$work['id'] ?>"
                                    >
                                        Edit
                                    </a>

                                    <form
                                        method="post"
                                        action="work_tracking.php"
                                        onsubmit="return confirm('हा कामाचा रेकॉर्ड डिलीट करायचा आहे का?');"
                                    >
                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= h($_SESSION['work_tracking_csrf']) ?>"
                                        >
                                        <input type="hidden" name="action" value="delete">
                                        <input
                                            type="hidden"
                                            name="id"
                                            value="<?= (int)$work['id'] ?>"
                                        >
                                        <button class="btn btn-danger" type="submit">
                                            Delete
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>

                        <?php if (!empty($work['notes'])): ?>
                            <tr>
                                <td colspan="9">
                                    <small>
                                        <strong>नोंदी:</strong>
                                        <?= nl2br(h($work['notes'])) ?>
                                    </small>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const serviceSelect = document.getElementById('service_id');
    const feeInput = document.getElementById('total_fee');

    if (serviceSelect && feeInput) {
        serviceSelect.addEventListener('change', function () {
            const option = serviceSelect.options[serviceSelect.selectedIndex];
            const fee = option ? option.getAttribute('data-fee') : null;

            if (fee !== null && fee !== '' && !feeInput.value) {
                feeInput.value = fee;
            }
        });
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>

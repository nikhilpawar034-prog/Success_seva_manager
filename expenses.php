
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

if (empty($_SESSION['expenses_csrf'])) {
    $_SESSION['expenses_csrf'] = bin2hex(random_bytes(32));
}

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$categories = [
    'Rent',
    'Electricity',
    'Internet',
    'Stationery',
    'Travel',
    'Salary',
    'Maintenance',
    'Other'
];

$error = '';
$editExpense = null;
$expenses = [];
$totalIncome = 0;
$totalExpenses = 0;
$netProfit = 0;

$fromDate = date('Y-m-01');
$toDate = date('Y-m-d');

/* ADD / UPDATE / DELETE */
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';

        if (
            !is_string($token) ||
            !hash_equals($_SESSION['expenses_csrf'], $token)
        ) {
            throw new RuntimeException(
                'Security token चुकीचा आहे. Refresh करून पुन्हा प्रयत्न करा.'
            );
        }

        $action = $_POST['action'] ?? '';

        /* SAVE OR UPDATE */
        if ($action === 'save') {
            $id = filter_var(
                $_POST['id'] ?? '0',
                FILTER_VALIDATE_INT
            );

            $title = trim($_POST['expense_title'] ?? '');
            $category = $_POST['category'] ?? '';
            $amountText = trim($_POST['amount'] ?? '');
            $date = $_POST['expense_date'] ?? '';
            $notes = trim($_POST['notes'] ?? '');

            if ($id === false || $id < 0) {
                throw new RuntimeException('Expense ID चुकीचा आहे.');
            }

            if ($title === '' || mb_strlen($title) > 150) {
                throw new RuntimeException(
                    'खर्चाचे नाव भरा (कमाल 150 अक्षरे).'
                );
            }

            if (!in_array($category, $categories, true)) {
                throw new RuntimeException('योग्य खर्चाचा प्रकार निवडा.');
            }

            if (
                $amountText === '' ||
                !is_numeric($amountText) ||
                !is_finite((float)$amountText) ||
                (float)$amountText <= 0 ||
                (float)$amountText > 99999999.99
            ) {
                throw new RuntimeException('खर्चाची योग्य रक्कम भरा.');
            }

            $dateObject = DateTime::createFromFormat('!Y-m-d', $date);

            if (
                !$dateObject ||
                $dateObject->format('Y-m-d') !== $date
            ) {
                throw new RuntimeException('खर्चाची योग्य तारीख निवडा.');
            }

            if (mb_strlen($notes) > 5000) {
                throw new RuntimeException(
                    'Notes जास्तीत जास्त 5000 अक्षरांचे असावेत.'
                );
            }

            $amount = number_format((float)$amountText, 2, '.', '');
            $notesValue = $notes !== '' ? $notes : null;

            if ($id > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE expenses
                     SET expense_title = ?,
                         category = ?,
                         amount = ?,
                         expense_date = ?,
                         notes = ?
                     WHERE id = ?'
                );

                $stmt->execute([
                    $title,
                    $category,
                    $amount,
                    $date,
                    $notesValue,
                    $id
                ]);

                $_SESSION['expenses_flash'] =
                    $stmt->rowCount() >= 0
                    ? 'खर्चाची माहिती अपडेट झाली.'
                    : 'खर्च अपडेट करता आला नाही.';
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO expenses
                     (expense_title, category, amount, expense_date, notes)
                     VALUES (?, ?, ?, ?, ?)'
                );

                $stmt->execute([
                    $title,
                    $category,
                    $amount,
                    $date,
                    $notesValue
                ]);

                $_SESSION['expenses_flash'] = 'नवीन खर्च नोंदवला.';
            }

            header('Location: expenses.php');
            exit;
        }

        /* DELETE */
        if ($action === 'delete') {
            $id = filter_var(
                $_POST['id'] ?? '',
                FILTER_VALIDATE_INT
            );

            if (!$id || $id < 1) {
                throw new RuntimeException('Expense ID चुकीचा आहे.');
            }

            $stmt = $pdo->prepare(
                'DELETE FROM expenses WHERE id = ?'
            );
            $stmt->execute([$id]);

            $_SESSION['expenses_flash'] = $stmt->rowCount() > 0
                ? 'खर्च Delete झाला.'
                : 'खर्च सापडला नाही.';

            header('Location: expenses.php');
            exit;
        }
    }

    /* DATE FILTER */
    $fromDate = $_GET['from_date'] ?? date('Y-m-01');
    $toDate = $_GET['to_date'] ?? date('Y-m-d');

    $fromObject = DateTime::createFromFormat('!Y-m-d', $fromDate);
    $toObject = DateTime::createFromFormat('!Y-m-d', $toDate);

    if (
        !$fromObject ||
        $fromObject->format('Y-m-d') !== $fromDate ||
        !$toObject ||
        $toObject->format('Y-m-d') !== $toDate ||
        $fromDate > $toDate
    ) {
        $fromDate = date('Y-m-01');
        $toDate = date('Y-m-d');

        $error = 'तारीख चुकीची होती. चालू महिन्याचा कालावधी दाखवत आहोत.';
    }

    /* EDIT */
    if (isset($_GET['edit'])) {
        $editId = filter_var(
            $_GET['edit'],
            FILTER_VALIDATE_INT
        );

        if ($editId && $editId > 0) {
            $stmt = $pdo->prepare(
                'SELECT * FROM expenses WHERE id = ?'
            );
            $stmt->execute([$editId]);
            $editExpense = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$editExpense) {
                $error = 'Edit करण्यासाठी खर्च सापडला नाही.';
            }
        }
    }

    /* INCOME: PAID TRANSACTIONS */
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(amount), 0)
         FROM transactions
         WHERE payment_status = 'Paid'
           AND transaction_date >= ?
           AND transaction_date < DATE_ADD(?, INTERVAL 1 DAY)"
    );

    $stmt->execute([$fromDate, $toDate]);
    $totalIncome = (float)$stmt->fetchColumn();

    /* EXPENSE TOTAL */
    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(amount), 0)
         FROM expenses
         WHERE expense_date >= ?
           AND expense_date < DATE_ADD(?, INTERVAL 1 DAY)'
    );

    $stmt->execute([$fromDate, $toDate]);
    $totalExpenses = (float)$stmt->fetchColumn();

    $netProfit = $totalIncome - $totalExpenses;

    /* EXPENSE LIST */
    $stmt = $pdo->prepare(
        'SELECT *
         FROM expenses
         WHERE expense_date >= ?
           AND expense_date < DATE_ADD(?, INTERVAL 1 DAY)
         ORDER BY expense_date DESC, id DESC'
    );

    $stmt->execute([$fromDate, $toDate]);
    $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (RuntimeException $e) {
    $error = $e->getMessage();

} catch (PDOException $e) {
    error_log($e->getMessage());

    $error = 'Database त्रुटी आली. transactions आणि expenses टेबलमधील कॉलम तपासा.';
}

/* FLASH MESSAGE */
$flash = $_SESSION['expenses_flash'] ?? null;
unset($_SESSION['expenses_flash']);

/* PAGE SETTINGS */
$activePage = 'expenses';
$pageTitle = 'Income & Expense Management';
$pageSubtitle = 'उत्पन्न, खर्च आणि नफ्याचा हिशोब';
?>

<?php include __DIR__ . '/includes/sidebar.php'; ?>

<?php include __DIR__ . '/includes/header.php'; ?>

<style>
.expense-summary {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
    margin: 20px 0;
}

.expense-summary .stat-card {
    min-width: 0;
}

.expense-summary .stat-value {
    overflow-wrap: anywhere;
}

.expense-table-wrap {
    width: 100%;
    overflow-x: auto;
}

.expense-table-wrap table {
    min-width: 750px;
    width: 100%;
}

.expense-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    align-items: center;
}

.expense-actions form {
    margin: 0;
}

.expense-form {
    margin: 0;
}

@media (max-width: 760px) {
    .expense-summary {
        grid-template-columns: 1fr;
    }
}

@media print {
    .sidebar,
    .topbar,
    .footer,
    .expense-form,
    .form-actions,
    .expense-actions,
    .mobile-menu {
        display: none !important;
    }

    .main-area {
        margin: 0 !important;
        width: 100% !important;
    }

    .content {
        padding: 0 !important;
    }

    .panel,
    .stat-card {
        box-shadow: none !important;
        break-inside: avoid;
    }
}
</style>

<!-- FLASH MESSAGE -->
<?php if ($flash): ?>
    <div class="alert">
        <?= h($flash) ?>
    </div>
<?php endif; ?>

<!-- ERROR MESSAGE -->
<?php if ($error !== ''): ?>
    <div class="alert">
        <?= h($error) ?>
    </div>
<?php endif; ?>

<!-- DATE FILTER -->
<div class="panel">

    <div class="panel-header">
        <div>
            <h2 class="panel-title">हिशोबाचा कालावधी</h2>
            <p class="page-subtitle">
                दैनिक किंवा मासिक उत्पन्न व खर्च पाहा.
            </p>
        </div>
    </div>

    <div class="panel-body">

        <form method="get"
              action="expenses.php"
              class="form-grid expense-form">

            <div class="form-group">
                <label for="from_date">पासून तारीख</label>

                <input
                    class="form-control"
                    type="date"
                    id="from_date"
                    name="from_date"
                    required
                    value="<?= h($fromDate) ?>"
                >
            </div>

            <div class="form-group">
                <label for="to_date">पर्यंत तारीख</label>

                <input
                    class="form-control"
                    type="date"
                    id="to_date"
                    name="to_date"
                    required
                    value="<?= h($toDate) ?>"
                >
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">
                    हिशोब पाहा
                </button>

                <a class="btn btn-secondary" href="expenses.php">
                    चालू महिना
                </a>
            </div>

        </form>

    </div>
</div>

<!-- SUMMARY CARDS -->
<div class="stats-grid expense-summary">

    <div class="stat-card">
        <div class="stat-label">एकूण उत्पन्न</div>

        <div class="stat-value">
            ₹<?= number_format($totalIncome, 2) ?>
        </div>

        <div class="stat-note">Paid व्यवहार</div>
    </div>

    <div class="stat-card">
        <div class="stat-label">एकूण खर्च</div>

        <div class="stat-value">
            ₹<?= number_format($totalExpenses, 2) ?>
        </div>

        <div class="stat-note">नोंदवलेले खर्च</div>
    </div>

    <div class="stat-card">
        <div class="stat-label">निव्वळ शिल्लक</div>

        <div class="stat-value">
            ₹<?= number_format($netProfit, 2) ?>
        </div>

        <div class="stat-note">उत्पन्न वजा खर्च</div>
    </div>

</div>

<!-- ADD / EDIT EXPENSE -->
<div class="panel">

    <div class="panel-header">
        <div>
            <h2 class="panel-title">
                <?= $editExpense ? 'खर्च Edit करा' : 'नवीन खर्च नोंदवा' ?>
            </h2>

            <p class="page-subtitle">
                भाडे, वीज, इंटरनेट व इतर खर्चांची नोंद करा.
            </p>
        </div>
    </div>

    <div class="panel-body">

        <form method="post"
              action="expenses.php"
              class="form-grid expense-form">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= h($_SESSION['expenses_csrf']) ?>"
            >

            <input type="hidden" name="action" value="save">

            <input
                type="hidden"
                name="id"
                value="<?= h($editExpense['id'] ?? 0) ?>"
            >

            <div class="form-group">
                <label for="expense_title">खर्चाचे नाव *</label>

                <input
                    class="form-control"
                    type="text"
                    id="expense_title"
                    name="expense_title"
                    maxlength="150"
                    required
                    value="<?= h($editExpense['expense_title'] ?? '') ?>"
                    placeholder="उदा. दुकानाचे भाडे"
                >
            </div>

            <div class="form-group">
                <label for="category">खर्चाचा प्रकार *</label>

                <select
                    class="form-control"
                    id="category"
                    name="category"
                    required
                >
                    <option value="">प्रकार निवडा</option>

                    <?php foreach ($categories as $category): ?>
                        <option
                            value="<?= h($category) ?>"
                            <?= ($editExpense['category'] ?? '') === $category
                                ? 'selected'
                                : '' ?>
                        >
                            <?= h($category) ?>
                        </option>
                    <?php endforeach; ?>

                </select>
            </div>

            <div class="form-group">
                <label for="amount">रक्कम (₹) *</label>

                <input
                    class="form-control"
                    type="number"
                    id="amount"
                    name="amount"
                    min="0.01"
                    max="99999999.99"
                    step="0.01"
                    required
                    value="<?= h($editExpense['amount'] ?? '') ?>"
                    placeholder="उदा. 1500"
                >
            </div>

            <div class="form-group">
                <label for="expense_date">खर्चाची तारीख *</label>

                <input
                    class="form-control"
                    type="date"
                    id="expense_date"
                    name="expense_date"
                    required
                    value="<?= h($editExpense['expense_date'] ?? date('Y-m-d')) ?>"
                >
            </div>

            <div class="form-group">
                <label for="notes">अधिक माहिती (ऐच्छिक)</label>

                <textarea
                    class="form-control"
                    id="notes"
                    name="notes"
                    rows="3"
                    maxlength="5000"
                    placeholder="खर्चाबद्दल माहिती"
                ><?= h($editExpense['notes'] ?? '') ?></textarea>
            </div>

            <div class="form-actions">

                <button class="btn btn-primary" type="submit">
                    <?= $editExpense ? 'Update Expense' : 'Save Expense' ?>
                </button>

                <?php if ($editExpense): ?>
                    <a class="btn btn-secondary" href="expenses.php">
                        Cancel
                    </a>
                <?php endif; ?>

            </div>

        </form>

    </div>
</div>

<!-- EXPENSE LIST -->
<div class="panel">

    <div class="panel-header">
        <div>
            <h2 class="panel-title">खर्चाची यादी</h2>

            <p class="page-subtitle">
                <?= h($fromDate) ?> ते <?= h($toDate) ?>
            </p>
        </div>
    </div>

    <div class="panel-body">

        <div class="expense-table-wrap">

            <table class="data-table">

                <thead>
                    <tr>
                        <th>ID</th>
                        <th>तारीख</th>
                        <th>खर्चाचे नाव</th>
                        <th>प्रकार</th>
                        <th>रक्कम</th>
                        <th>Notes</th>
                        <th>कृती</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (empty($expenses)): ?>

                    <tr>
                        <td colspan="7" class="empty-state">
                            या कालावधीत खर्च नोंदवलेला नाही.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($expenses as $expense): ?>

                        <tr>
                            <td><?= (int)$expense['id'] ?></td>

                            <td>
                                <?= h(date(
                                    'd-m-Y',
                                    strtotime($expense['expense_date'])
                                )) ?>
                            </td>

                            <td><?= h($expense['expense_title']) ?></td>

                            <td><?= h($expense['category']) ?></td>

                            <td>
                                ₹<?= number_format(
                                    (float)$expense['amount'],
                                    2
                                ) ?>
                            </td>

                            <td><?= h($expense['notes'] ?? '') ?></td>

                            <td>
                                <div class="expense-actions">

                                    <a
                                        class="btn btn-secondary"
                                        href="expenses.php?edit=<?= (int)$expense['id'] ?>&amp;from_date=<?= h($fromDate) ?>&amp;to_date=<?= h($toDate) ?>"
                                    >
                                        Edit
                                    </a>

                                    <form
                                        method="post"
                                        action="expenses.php"
                                        onsubmit="return confirm('हा खर्च Delete करायचा आहे का?');"
                                    >
                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= h($_SESSION['expenses_csrf']) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="delete"
                                        >

                                        <input
                                            type="hidden"
                                            name="id"
                                            value="<?= (int)$expense['id'] ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="btn btn-danger"
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
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
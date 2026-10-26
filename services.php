
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

/* CSRF Token */
if (empty($_SESSION['services_csrf'])) {
    $_SESSION['services_csrf'] = bin2hex(random_bytes(32));
}

/* Safe Output */
function h($value): string {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

$error = '';
$editService = null;

/* =========================
   ADD / UPDATE / DELETE
========================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['services_csrf'], $token)
    ) {
        $error = 'Security token चुकीचा आहे. पेज Refresh करून पुन्हा प्रयत्न करा.';
    } else {

        $action = $_POST['action'] ?? '';

        try {

            /* SAVE SERVICE */
            if ($action === 'save') {

                $id = filter_var(
                    $_POST['id'] ?? '0',
                    FILTER_VALIDATE_INT
                );

                $name = trim($_POST['name'] ?? '');
                $feeText = trim($_POST['fee'] ?? '');

                if (
                    $name === '' ||
                    (function_exists('mb_strlen')
                        ? mb_strlen($name, 'UTF-8')
                        : strlen($name)) > 150
                ) {
                    throw new RuntimeException(
                        'सेवेचे नाव भरा. नाव 150 अक्षरांपेक्षा मोठे नसावे.'
                    );
                }

                if (
                    $feeText === '' ||
                    !is_numeric($feeText) ||
                    !is_finite((float) $feeText) ||
                    (float) $feeText < 0 ||
                    (float) $feeText > 99999999.99
                ) {
                    throw new RuntimeException(
                        'सेवेची योग्य फी भरा.'
                    );
                }

                if ($id === false || $id < 0) {
                    throw new RuntimeException(
                        'सेवेचा ID चुकीचा आहे.'
                    );
                }

                $fee = number_format(
                    (float) $feeText,
                    2,
                    '.',
                    ''
                );

                if ($id > 0) {

                    $stmt = $pdo->prepare("
                        UPDATE services
                        SET service_name = ?, default_fee = ?
                        WHERE id = ?
                    ");

                    $stmt->execute([$name, $fee, $id]);

                    $_SESSION['services_flash'] =
                        'सेवेची माहिती अपडेट झाली.';

                } else {

                    $stmt = $pdo->prepare("
                        INSERT INTO services
                            (service_name, default_fee)
                        VALUES (?, ?)
                    ");

                    $stmt->execute([$name, $fee]);

                    $_SESSION['services_flash'] =
                        'नवीन सेवा यशस्वीपणे जोडली.';
                }

                header('Location: services.php');
                exit;
            }

            /* DELETE SERVICE */
            if ($action === 'delete') {

                $id = filter_var(
                    $_POST['id'] ?? '',
                    FILTER_VALIDATE_INT
                );

                if (!$id || $id < 1) {
                    throw new RuntimeException(
                        'सेवेचा ID चुकीचा आहे.'
                    );
                }

                $stmt = $pdo->prepare(
                    'DELETE FROM services WHERE id = ?'
                );

                $stmt->execute([$id]);

                $_SESSION['services_flash'] =
                    $stmt->rowCount() > 0
                    ? 'सेवा Delete झाली.'
                    : 'ही सेवा सापडली नाही.';

                header('Location: services.php');
                exit;
            }

            throw new RuntimeException(
                'अवैध कृती. कृपया पुन्हा प्रयत्न करा.'
            );

        } catch (RuntimeException $e) {

            $error = $e->getMessage();

        } catch (PDOException $e) {

            error_log($e->getMessage());

            $error = 'Database त्रुटी आली. सेवा वापरात असल्यास ती Delete करता येणार नाही.';
        }
    }
}

/* =========================
   EDIT SERVICE
========================= */

if (isset($_GET['edit'])) {

    $id = filter_var(
        $_GET['edit'],
        FILTER_VALIDATE_INT
    );

    if ($id && $id > 0) {

        $stmt = $pdo->prepare("
            SELECT id, service_name, default_fee
            FROM services
            WHERE id = ?
        ");

        $stmt->execute([$id]);

        $editService = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$editService) {
            $error = 'Edit करण्यासाठी सेवा सापडली नाही.';
        }

    } else {
        $error = 'सेवेचा ID चुकीचा आहे.';
    }
}

/* =========================
   SEARCH SERVICES
========================= */

$search = trim($_GET['search'] ?? '');

if (strlen($search) > 100) {
    $search = substr($search, 0, 100);
}

if ($search !== '') {

    $stmt = $pdo->prepare("
        SELECT id, service_name, default_fee, created_at
        FROM services
        WHERE service_name LIKE ?
        ORDER BY id DESC
    ");

    $stmt->execute(['%' . $search . '%']);

    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);

} else {

    $services = $pdo->query("
        SELECT id, service_name, default_fee, created_at
        FROM services
        ORDER BY id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

/* Flash Message */
$flash = $_SESSION['services_flash'] ?? null;
unset($_SESSION['services_flash']);

/* Page Settings */
$activePage = 'services';
$pageTitle = 'Services Management';
$pageSubtitle = 'सेवांची नोंद, फी आणि व्यवस्थापन';

?>

<?php include __DIR__ . '/includes/sidebar.php'; ?>

<?php include __DIR__ . '/includes/header.php'; ?>


<!-- =========================
     MESSAGES
========================= -->

<?php if ($flash): ?>

    <div class="alert alert-success">
        <?= h($flash) ?>
    </div>

<?php endif; ?>

<?php if ($error !== ''): ?>

    <div class="alert alert-error">
        <?= h($error) ?>
    </div>

<?php endif; ?>


<!-- =========================
     SERVICE SUMMARY
========================= -->

<div class="stats-grid">

    <div class="stat-card">

        <div class="stat-top">
            <span class="stat-label">TOTAL SERVICES</span>
            <span class="stat-icon">▤</span>
        </div>

        <div class="stat-value">
            <?= number_format(count($services)) ?>
        </div>

        <div class="stat-note">
            <?= $search !== ''
                ? 'शोधामध्ये मिळालेल्या सेवा'
                : 'सध्याच्या यादीतील सेवा' ?>
        </div>

    </div>

</div>


<!-- =========================
     ADD / EDIT SERVICE FORM
========================= -->

<div class="panel">

    <div class="panel-header">

        <div>
            <h2 class="panel-title">
                <?= $editService
                    ? 'सेवा Edit करा'
                    : 'नवीन सेवा जोडा' ?>
            </h2>

            <p class="page-subtitle">
                सेवेचे नाव आणि फी नोंदवा.
            </p>
        </div>

    </div>

    <div class="panel-body">

        <form
            method="post"
            action="services.php"
            class="form-grid"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= h($_SESSION['services_csrf']) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="save"
            >

            <input
                type="hidden"
                name="id"
                value="<?= h($editService['id'] ?? 0) ?>"
            >

            <div class="form-group">

                <label for="name">
                    सेवेचे नाव *
                </label>

                <input
                    class="form-control"
                    id="name"
                    name="name"
                    type="text"
                    maxlength="150"
                    required
                    value="<?= h($editService['service_name'] ?? '') ?>"
                    placeholder="उदा. उत्पन्न प्रमाणपत्र"
                >

            </div>

            <div class="form-group">

                <label for="fee">
                    सेवेची फी (₹) *
                </label>

                <input
                    class="form-control"
                    id="fee"
                    name="fee"
                    type="number"
                    min="0"
                    max="99999999.99"
                    step="0.01"
                    required
                    value="<?= h($editService['default_fee'] ?? '0.00') ?>"
                    placeholder="उदा. 100"
                >

            </div>

            <div class="form-actions">

                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    <?= $editService
                        ? 'Update Service'
                        : 'Save Service' ?>
                </button>

                <?php if ($editService): ?>

                    <a
                        class="btn btn-light"
                        href="services.php"
                    >
                        Cancel
                    </a>

                <?php endif; ?>

            </div>

        </form>

    </div>

</div>


<!-- =========================
     SERVICES LIST
========================= -->

<div class="panel">

    <div class="panel-header">

        <div>
            <h2 class="panel-title">
                सेवांची यादी
            </h2>

            <p class="page-subtitle">
                नोंदवलेल्या सेवांचे व्यवस्थापन करा.
                एकूण: <?= count($services) ?>
            </p>
        </div>

    </div>

    <div class="panel-body">

        <!-- Search -->
        <form
            method="get"
            action="services.php"
            class="toolbar"
        >

            <input
                class="search-input"
                type="search"
                name="search"
                maxlength="100"
                value="<?= h($search) ?>"
                placeholder="सेवेचे नाव शोधा..."
            >

            <button
                class="btn btn-primary"
                type="submit"
            >
                Search
            </button>

            <?php if ($search !== ''): ?>

                <a
                    class="btn btn-light"
                    href="services.php"
                >
                    Reset
                </a>

            <?php endif; ?>

        </form>


        <!-- Table -->
        <div class="table-wrap">

            <table class="data-table">

                <thead>
                    <tr>
                        <th>ID</th>
                        <th>सेवेचे नाव</th>
                        <th>फी</th>
                        <th>तारीख</th>
                        <th>कृती</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (empty($services)): ?>

                    <tr>
                        <td colspan="5" class="empty-state">
                            सेवा सापडली नाही किंवा अजून कोणतीही सेवा नोंदवलेली नाही.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($services as $service): ?>

                        <tr>

                            <td>
                                #<?= (int) $service['id'] ?>
                            </td>

                            <td>
                                <span class="customer-name">
                                    <?= h($service['service_name']) ?>
                                </span>
                            </td>

                            <td>
                                ₹<?= number_format(
                                    (float) $service['default_fee'],
                                    2
                                ) ?>
                            </td>

                            <td>
                                <?= !empty($service['created_at'])
                                    ? date(
                                        'd-m-Y',
                                        strtotime($service['created_at'])
                                    )
                                    : '—' ?>
                            </td>

                            <td>

                                <div class="customer-actions">

                                    <a
                                        class="btn btn-light"
                                        href="services.php?edit=<?= (int) $service['id'] ?>"
                                    >
                                        Edit
                                    </a>

                                    <form
                                        method="post"
                                        action="services.php"
                                        onsubmit="return confirm('ही सेवा Delete करायची आहे का?');"
                                    >

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= h($_SESSION['services_csrf']) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="delete"
                                        >

                                        <input
                                            type="hidden"
                                            name="id"
                                            value="<?= (int) $service['id'] ?>"
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

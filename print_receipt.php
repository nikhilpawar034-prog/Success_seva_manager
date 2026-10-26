
<?php
session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/config/db.php';

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    http_response_code(400);
    exit('अवैध पावती क्रमांक.');
}

$stmt = $pdo->prepare(
    "SELECT
        r.id AS receipt_id,
        r.receipt_no,
        r.issued_at,
        t.id AS transaction_id,
        t.transaction_date,
        t.amount,
        t.payment_method,
        t.payment_status,
        t.notes,
        c.name AS customer_name,
        c.mobile AS customer_mobile,
        c.address AS customer_address,
        s.service_name
     FROM receipts r
     INNER JOIN transactions t ON t.id = r.transaction_id
     INNER JOIN customers c ON c.id = t.customer_id
     INNER JOIN services s ON s.id = t.service_id
     WHERE r.id = ?"
);

$stmt->execute([$id]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$r) {
    http_response_code(404);
    exit('पावती सापडली नाही.');
}

if ($r['payment_status'] !== 'Paid') {
    http_response_code(403);
    exit('हा व्यवहार Paid नसल्यामुळे पावती उपलब्ध नाही.');
}
?>
<!DOCTYPE html>
<html lang="mr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt <?= h($r['receipt_no']) ?></title>

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 24px;
            background: #f1f4f9;
            color: #202b3c;
            font-family: Arial, "Noto Sans Devanagari", sans-serif;
        }

        .receipt {
            max-width: 760px;
            margin: 0 auto;
            padding: 34px;
            background: #fff;
            border: 1px solid #dbe2ea;
            border-top: 6px solid #1746a2;
        }

        .header {
            text-align: center;
            border-bottom: 2px solid #1746a2;
            padding-bottom: 18px;
            margin-bottom: 22px;
        }

        .header h1 {
            margin: 0 0 8px;
            color: #1746a2;
            font-size: 28px;
        }

        .header p { margin: 4px 0; }

        .receipt-title {
            text-align: center;
            font-size: 21px;
            font-weight: bold;
            margin: 22px 0;
        }

        .meta {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 22px;
        }

        .details {
            width: 100%;
            border-collapse: collapse;
            margin: 18px 0;
        }

        .details th,
        .details td {
            border: 1px solid #d5dce5;
            padding: 12px;
            text-align: left;
        }

        .details th {
            width: 35%;
            background: #f4f7fb;
        }

        .amount {
            font-size: 20px;
            font-weight: bold;
            color: #1746a2;
        }

        .footer {
            margin-top: 38px;
            display: flex;
            justify-content: space-between;
            gap: 20px;
        }

        .signature {
            min-width: 180px;
            padding-top: 35px;
            text-align: center;
            border-bottom: 1px solid #777;
        }

        .actions {
            max-width: 760px;
            margin: 0 auto 16px;
            display: flex;
            gap: 10px;
        }

        .btn {
            border: 0;
            padding: 11px 18px;
            cursor: pointer;
            text-decoration: none;
            background: #1746a2;
            color: #fff;
            border-radius: 5px;
            font-size: 14px;
        }

        .btn-secondary { background: #596579; }

        @media print {
            body {
                padding: 0;
                background: #fff;
            }

            .actions { display: none; }

            .receipt {
                max-width: none;
                border: 1px solid #bbb;
                border-top: 5px solid #1746a2;
                margin: 0;
                padding: 24px;
            }

            @page {
                size: A4;
                margin: 12mm;
            }
        }
    </style>
</head>

<body>

<div class="actions">
    <button class="btn" onclick="window.print()">Print Receipt</button>
    <button class="btn btn-secondary" onclick="window.close()">Close</button>
</div>

<div class="receipt">

    <div class="header">
        <h1>Success Online Service And Management System </h1>
        <p>ग्राहक सेवा केंद्र</p>
        <p>व्यवहाराची पावती</p>
    </div>

    <div class="receipt-title">PAYMENT RECEIPT / पावती</div>

    <div class="meta">
        <div>
            <strong>पावती क्रमांक:</strong>
            <?= h($r['receipt_no']) ?>
        </div>

        <div>
            <strong>व्यवहार क्रमांक:</strong>
            <?= (int)$r['transaction_id'] ?>
        </div>

        <div>
            <strong>तारीख:</strong>
            <?= h($r['transaction_date']) ?>
        </div>
    </div>

    <table class="details">
        <tr>
            <th>ग्राहकाचे नाव</th>
            <td><?= h($r['customer_name']) ?></td>
        </tr>

        <tr>
            <th>मोबाइल क्रमांक</th>
            <td><?= h($r['customer_mobile']) ?></td>
        </tr>

        <tr>
            <th>पत्ता</th>
            <td><?= nl2br(h($r['customer_address'] ?? '')) ?></td>
        </tr>

        <tr>
            <th>सेवेचे नाव</th>
            <td><?= h($r['service_name']) ?></td>
        </tr>

        <tr>
            <th>पेमेंट पद्धत</th>
            <td><?= h($r['payment_method']) ?></td>
        </tr>

        <tr>
            <th>पेमेंट स्थिती</th>
            <td>PAID - रक्कम जमा</td>
        </tr>

        <tr>
            <th>रक्कम</th>
            <td class="amount">
                ₹<?= number_format((float)$r['amount'], 2) ?>
            </td>
        </tr>

        <?php if (!empty($r['notes'])): ?>
        <tr>
            <th>शेरा</th>
            <td><?= nl2br(h($r['notes'])) ?></td>
        </tr>
        <?php endif; ?>
    </table>

    <p>
        वरील व्यवहाराची रक्कम प्राप्त झाल्याची ही पावती आहे.
    </p>

    <div class="footer">
        <div>
            <strong>पावती तयार केल्याची वेळ:</strong><br>
            <?= h($r['issued_at']) ?>
        </div>

        <div class="signature">
            अधिकृत सही / शिक्का
        </div>
    </div>

</div>

</body>
</html>

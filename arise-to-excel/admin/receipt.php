<?php
$pageTitle = 'Receipt';
require_once __DIR__ . '/../includes/auth.php';

$receiptId = (int) ($_GET['id'] ?? 0);
$statement = $pdo->prepare(
    "SELECT
        fees.*,
        students.registration_no,
        students.full_name,
        students.class_level,
        COALESCE(fee_balances.balance, fees.balance_after_payment) AS current_balance
     FROM fees
     JOIN students ON students.id = fees.student_id
     LEFT JOIN fee_balances
       ON fee_balances.student_id = students.id
      AND fee_balances.academic_year = fees.year
      AND fee_balances.term = fees.term
     WHERE fees.id = :id"
);
$statement->execute(['id' => $receiptId]);
$receipt = $statement->fetch();

if (!$receipt) {
    redirect('admin/fees.php');
}

function receipt_pdf_escape(string $text): string
{
    $text = preg_replace('/[^\x20-\x7E]/', ' ', $text);
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
}

function receipt_pdf_text(float $x, float $y, string $text, float $size = 10, string $font = 'F1', string $color = '0 0 0'): string
{
    return "BT /{$font} {$size} Tf {$color} rg {$x} {$y} Td (" . receipt_pdf_escape($text) . ") Tj ET\n";
}

function receipt_pdf_rect(float $x, float $y, float $w, float $h, string $fill, string $stroke = ''): string
{
    $command = "{$fill} rg {$x} {$y} {$w} {$h} re f\n";
    if ($stroke !== '') {
        $command .= "{$stroke} RG {$x} {$y} {$w} {$h} re S\n";
    }
    return $command;
}

function receipt_pdf_document(string $stream, ?array $image): string
{
    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
    ];
    $imageId = 0;
    if ($image) {
        $imageId = 6;
        $objects[$imageId] = "<< /Type /XObject /Subtype /Image /Width {$image['width']} /Height {$image['height']} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($image['data']) . " >>\nstream\n{$image['data']}\nendstream";
    }
    $xObject = $imageId ? " /XObject << /Im1 {$imageId} 0 R >>" : '';
    $objects[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R >>{$xObject} >> /Contents 7 0 R >>";
    $objects[7] = "<< /Length " . strlen($stream) . " >>\nstream\n{$stream}endstream";
    ksort($objects);

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $id => $object) {
        $offsets[$id] = strlen($pdf);
        $pdf .= "{$id} 0 obj\n{$object}\nendobj\n";
    }
    $xrefOffset = strlen($pdf);
    $maxId = max(array_keys($objects));
    $pdf .= "xref\n0 " . ($maxId + 1) . "\n0000000000 65535 f \n";
    for ($id = 1; $id <= $maxId; $id++) {
        $pdf .= isset($offsets[$id]) ? str_pad((string) $offsets[$id], 10, '0', STR_PAD_LEFT) . " 00000 n \n" : "0000000000 65535 f \n";
    }
    $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";
    return $pdf;
}

function build_receipt_pdf(array $receipt): string
{
    $logoPath = __DIR__ . '/../assets/images/school-logo.jpg';
    $image = null;
    if (is_file($logoPath) && ($size = @getimagesize($logoPath))) {
        $image = ['data' => file_get_contents($logoPath), 'width' => $size[0], 'height' => $size[1]];
    }

    $adminName = $_SESSION['admin_name'] ?? 'Admin';
    $stream = '';
    $stream .= receipt_pdf_rect(0, 0, 595, 842, '1 1 1');
    $stream .= receipt_pdf_rect(0, 742, 595, 100, '0.96 0.98 1');
    if ($image) {
        $stream .= "q 58 0 0 58 42 760 cm /Im1 Do Q\n";
    }
    $stream .= receipt_pdf_text(116, 804, SCHOOL_NAME, 18, 'F2', '0.06 0.09 0.16');
    $stream .= receipt_pdf_text(116, 785, SCHOOL_ADDRESS, 9, 'F1', '0.39 0.45 0.55');
    $stream .= receipt_pdf_text(116, 770, SCHOOL_PHONE . ' | ' . SCHOOL_EMAIL, 9, 'F1', '0.39 0.45 0.55');
    $stream .= receipt_pdf_text(410, 804, 'OFFICIAL RECEIPT', 14, 'F2', '0.10 0.22 0.57');
    $stream .= receipt_pdf_text(410, 784, $receipt['receipt_no'], 10, 'F2', '0.06 0.09 0.16');
    $stream .= receipt_pdf_text(410, 768, 'Generated: ' . date('Y-m-d H:i'), 8, 'F1', '0.39 0.45 0.55');

    $items = [
        ['Student Name', $receipt['full_name']],
        ['Admission Number', $receipt['registration_no']],
        ['Class Level', $receipt['class_level']],
        ['Term', $receipt['term'] . ' - ' . $receipt['year']],
        ['Amount Paid', money((float) $receipt['amount_paid'])],
        ['M-PESA Code', $receipt['mpesa_code']],
        ['Payment Method', 'M-PESA'],
        ['Remaining Balance', money((float) $receipt['current_balance'])],
        ['Payment Date', date('M d, Y', strtotime($receipt['payment_date']))],
        ['Admin Name', $adminName],
    ];

    $x = 54;
    $y = 678;
    foreach ($items as $index => $item) {
        $stream .= receipt_pdf_rect($x, $y, 230, 50, $index % 2 === 0 ? '0.97 0.98 1' : '1 1 1', '0.88 0.91 0.95');
        $stream .= receipt_pdf_text($x + 14, $y + 31, $item[0], 8, 'F1', '0.39 0.45 0.55');
        $stream .= receipt_pdf_text($x + 14, $y + 13, (string) $item[1], 11, 'F2', '0.06 0.09 0.16');
        if ($x > 250) {
            $x = 54;
            $y -= 62;
        } else {
            $x = 310;
        }
    }

    $stream .= receipt_pdf_rect(54, 242, 486, 72, '0.94 0.97 1', '0.76 0.84 0.96');
    $stream .= receipt_pdf_text(72, 286, 'Amount Received', 9, 'F1', '0.39 0.45 0.55');
    $stream .= receipt_pdf_text(72, 260, money((float) $receipt['amount_paid']), 20, 'F2', '0.10 0.22 0.57');
    $stream .= receipt_pdf_text(54, 166, 'Received By', 9, 'F1', '0.39 0.45 0.55');
    $stream .= receipt_pdf_text(54, 132, '____________________________', 10, 'F1', '0.06 0.09 0.16');
    $stream .= receipt_pdf_text(342, 166, 'School Stamp', 9, 'F1', '0.39 0.45 0.55');
    $stream .= receipt_pdf_text(342, 132, '____________________________', 10, 'F1', '0.06 0.09 0.16');
    $stream .= receipt_pdf_text(54, 62, SCHOOL_NAME . ' | This receipt was generated by Arise To Excel Fees.', 8, 'F1', '0.39 0.45 0.55');

    return receipt_pdf_document($stream, $image);
}

if (($_GET['format'] ?? '') === 'pdf') {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="receipt_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $receipt['receipt_no']) . '.pdf"');
    echo build_receipt_pdf($receipt);
    exit;
}

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title no-print">
    <div>
        <p class="eyebrow">Receipt</p>
        <h1><?= h($receipt['receipt_no']) ?></h1>
    </div>
    <div class="action-row">
        <a class="btn btn-outline-primary" href="<?= url('admin/fees.php') ?>">Back to Fees</a>
        <a class="btn btn-outline-primary" href="<?= url('admin/receipt.php?id=' . (int) $receipt['id'] . '&format=pdf') ?>">Download PDF</a>
        <button class="btn btn-primary" onclick="window.print()">Print Receipt</button>
    </div>
</div>

<section class="receipt-paper">
    <div class="receipt-header">
        <div>
            <h2>Arise To Excel Academy</h2>
            <p>Fee Payment Receipt</p>
        </div>
        <div class="receipt-number">
            <span>Receipt No</span>
            <strong><?= h($receipt['receipt_no']) ?></strong>
        </div>
    </div>

    <div class="receipt-grid">
        <div>
            <span>Student Name</span>
            <strong><?= h($receipt['full_name']) ?></strong>
        </div>
        <div>
            <span>Reg Number</span>
            <strong><?= h($receipt['registration_no']) ?></strong>
        </div>
        <div>
            <span>Class Level</span>
            <strong><?= h($receipt['class_level']) ?></strong>
        </div>
        <div>
            <span>Term and Year</span>
            <strong><?= h($receipt['term'] . ' - ' . $receipt['year']) ?></strong>
        </div>
        <div>
            <span>Amount Paid</span>
            <strong><?= money((float) $receipt['amount_paid']) ?></strong>
        </div>
        <div>
            <span>Balance Remaining</span>
            <strong><?= money((float) $receipt['current_balance']) ?></strong>
        </div>
        <div>
            <span>Date</span>
            <strong><?= date('M d, Y', strtotime($receipt['payment_date'])) ?></strong>
        </div>
        <div>
            <span>M-PESA Code</span>
            <strong><?= h($receipt['mpesa_code']) ?></strong>
        </div>
    </div>

    <?php if ($receipt['mpesa_reference_text']): ?>
        <p class="receipt-notes"><strong>M-PESA Reference:</strong> <?= nl2br(h($receipt['mpesa_reference_text'])) ?></p>
    <?php endif; ?>

    <div class="signature-row">
        <div>Received By</div>
        <div>School Stamp</div>
    </div>
</section>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>

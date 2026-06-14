<?php
// expects variables:
// $receipt_title, $receipt_no, $receipt_date, $receipt_status,
// $payer_name, $payer_meta (array of key=>value),
// $items (array of ['description','size'?, 'quantity', 'unit_price','line_total']),
// $totals (array with keys subtotal, discount, grand_total, amount_paid, balance),
// $payment_method, $mpesa_code, $served_by
?>
<div class="receipt-container">
    <!-- Action Buttons -->
    <div class="action-row">
        <?php if (!empty($back_href)): ?>
            <a class="btn-receipt btn-back" href="<?= h($back_href) ?>">← Back</a>
        <?php endif; ?>
        <?php if (!empty($pdf_href)): ?>
            <a class="btn-receipt btn-pdf" href="<?= h($pdf_href) ?>">⬇ Download PDF</a>
        <?php endif; ?>
        <button class="btn-receipt btn-print" onclick="window.print()">🖨 Print Receipt</button>
    </div>

    <!-- Header Section -->
    <div class="receipt-header">
        <div class="receipt-header-logo">
            <img src="<?= asset('images/school-logo.jpg') ?>" alt="School Logo">
        </div>
        <div class="receipt-header-details">
            <h2><?= defined('SCHOOL_NAME') ? h(SCHOOL_NAME) : 'School' ?></h2>
            <div class="school-address">
                <?= defined('SCHOOL_ADDRESS') ? h(SCHOOL_ADDRESS) : '' ?>
            </div>
            <div class="school-contact">
                <?php if (defined('SCHOOL_PHONE')): ?><?= h(SCHOOL_PHONE) ?> |<?php endif; ?>
                <?= defined('SCHOOL_EMAIL') ? h(SCHOOL_EMAIL) : '' ?>
            </div>
        </div>
    </div>

    <!-- Receipt Title -->
    <div class="receipt-title"><?= h($receipt_title ?? 'PAYMENT RECEIPT') ?></div>

    <!-- Receipt Information Card -->
    <div class="receipt-info-card">
        <div class="receipt-info-item">
            <span class="receipt-info-label">Receipt Number</span>
            <span class="receipt-info-value"><?= h($receipt_no ?? '') ?></span>
        </div>
        <div class="receipt-info-item">
            <span class="receipt-info-label">Receipt Type</span>
            <span class="receipt-info-value" style="color: #1D4ED8; font-weight: 600;"><?= h($receipt_type ?? 'PAYMENT') ?></span>
        </div>
        <div class="receipt-info-item">
            <span class="receipt-info-label">Date</span>
            <span class="receipt-info-value"><?= h($receipt_date ?? '') ?></span>
        </div>
        <div class="receipt-info-item">
            <span class="receipt-info-label">Status</span>
            <span class="payment-status-badge <?= strtolower($receipt_status ?? 'partial') ?>">
                <?= h(strtoupper($receipt_status ?? 'PARTIAL')) ?>
            </span>
        </div>
    </div>

    <!-- Student Details Section -->
    <h3 class="receipt-section-title">Student Information</h3>
    <table class="receipt-details-table">
        <tbody>
            <tr>
                <td>Student Name</td>
                <td><?= h($payer_name ?? '') ?></td>
            </tr>
            <?php if (!empty($payer_meta) && is_array($payer_meta)): ?>
                <?php foreach ($payer_meta as $k => $v): ?>
                    <tr>
                        <td><?= h($k) ?></td>
                        <td><?= h($v) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Payment Items Section -->
    <?php if (!empty($items) && is_array($items)): ?>
        <h3 class="receipt-section-title">Payment Details</h3>
        <table class="receipt-items-table">
            <thead>
                <tr>
                    <th>Description</th>
                    <?php if (!empty($items) && is_array($items) && isset($items[0]['size'])): ?>
                        <th>Size</th>
                    <?php endif; ?>
                    <th style="text-align: right;">Qty</th>
                    <th style="text-align: right;">Unit Price</th>
                    <th style="text-align: right;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $it): ?>
                    <tr>
                        <td><?= h($it['description'] ?? ($it['uniform_name'] ?? '')) ?></td>
                        <?php if (isset($it['size'])): ?>
                            <td><?= h($it['size']) ?></td>
                        <?php endif; ?>
                        <td style="text-align: right;"><?= (int)($it['quantity'] ?? 1) ?></td>
                        <td style="text-align: right;"><?= money((float)($it['unit_price'] ?? 0)) ?></td>
                        <td style="text-align: right;"><?= money((float)($it['line_total'] ?? ($it['amount'] ?? 0))) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <!-- Totals Section -->
    <div class="receipt-totals">
        <div class="totals-column">
            <div class="totals-row">
                <span class="totals-label">Subtotal:</span>
                <span class="totals-value"><?= money((float)($totals['subtotal'] ?? 0)) ?></span>
            </div>
            <?php if (!empty($totals['discount'])): ?>
                <div class="totals-row">
                    <span class="totals-label">Discount:</span>
                    <span class="totals-value">-<?= money((float)($totals['discount'] ?? 0)) ?></span>
                </div>
            <?php endif; ?>
            <div class="totals-row total">
                <span class="totals-label">Grand Total:</span>
                <span class="totals-value"><?= money((float)($totals['grand_total'] ?? 0)) ?></span>
            </div>
            <div class="totals-row">
                <span class="totals-label">Amount Paid:</span>
                <span class="totals-value"><?= money((float)($totals['amount_paid'] ?? 0)) ?></span>
            </div>
            <div class="totals-row balance <?= ((float)($totals['balance'] ?? 0) <= 0.005) ? 'cleared' : '' ?>">
                <span class="totals-label">Balance:</span>
                <span class="totals-value"><?= money((float)($totals['balance'] ?? 0)) ?></span>
            </div>
        </div>
    </div>

    <!-- Payment Method Info -->
    <?php if (!empty($payment_method) || !empty($mpesa_code)): ?>
        <div class="payment-info">
            <?php if (!empty($payment_method)): ?>
                <div class="payment-info-item">
                    <span class="payment-info-label">Payment Method:</span>
                    <?= h($payment_method) ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($mpesa_code)): ?>
                <div class="payment-info-item">
                    <span class="payment-info-label">M-PESA Code:</span>
                    <?= h($mpesa_code) ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Footer -->
    <div class="receipt-footer">
        <div class="footer-section">
            <div class="footer-label">Received By</div>
            <div class="footer-line"></div>
            <div class="footer-value"><?= h($served_by ?? 'Admin') ?></div>
        </div>
        <div class="footer-section">
            <div class="footer-label">Authorized Signature</div>
            <div class="footer-line"></div>
        </div>
        <div class="footer-section">
            <div class="footer-label">School Stamp</div>
            <div class="footer-line"></div>
        </div>
        <div class="receipt-footer-text">
            <strong>Thank you for your payment.</strong> This receipt is computer-generated and is valid without a signature.
            Please retain this receipt for your records. For inquiries, contact <?= defined('SCHOOL_EMAIL') ? h(SCHOOL_EMAIL) : 'the school office' ?>.
        </div>
    </div>
</div>

<?php
$pageTitle = 'Sell Uniform';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

// Module access
if (!current_admin_has_permission($pdo, 'school_uniform.access')) {
    flash('error', 'You do not have permission to access Uniform sales.');
    redirect('admin/dashboard.php');
}

$canSellUniform = current_admin_has_permission($pdo, 'school_uniform.sell');
$canViewUniform = current_admin_has_permission($pdo, 'school_uniform.view');

$currentContext = current_academic_context($pdo);

$students = $pdo->query("SELECT id, registration_no, full_name, gender, class_level FROM students ORDER BY full_name ASC")->fetchAll();
$uniforms = $pdo->query("SELECT * FROM uniforms WHERE LOWER(TRIM(status)) = 'active' ORDER BY uniform_name ASC")->fetchAll();
// Recent sales (so receipts can be retrieved anytime)
$ctx = $currentContext;
$salesQuery = "SELECT us.*, s.full_name, s.registration_no FROM uniform_sales us LEFT JOIN students s ON s.id = us.student_id";
if (!empty($ctx['start_date']) && !empty($ctx['end_date'])) {
    $salesQuery .= " WHERE us.payment_date BETWEEN :start_date AND :end_date";
    $salesStmt = $pdo->prepare($salesQuery . " ORDER BY us.payment_date DESC");
    $salesStmt->execute(['start_date' => $ctx['start_date'], 'end_date' => $ctx['end_date']]);
    $sales = $salesStmt->fetchAll();
} else {
    $salesStmt = $pdo->prepare($salesQuery . " WHERE YEAR(us.payment_date) = :year ORDER BY us.payment_date DESC");
    $salesStmt->execute(['year' => $ctx['academic_year']]);
    $sales = $salesStmt->fetchAll();
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studentId = (int) ($_POST['student_id'] ?? 0);
    $items = $_POST['items'] ?? [];
    // posted items come as a JSON string from the client; decode if necessary
    if (is_string($items)) {
        $decoded = json_decode($items, true);
        $items = is_array($decoded) ? $decoded : [];
    }
    // discount removed from UI; keep stored value 0.0
    $discount = 0.0;
    $amountPaid = (float) ($_POST['amount_paid'] ?? 0);
    $paymentMethod = trim($_POST['payment_method'] ?? 'Cash');
    $mpesaCode = normalize_payment_code(trim($_POST['mpesa_code'] ?? ''));

    if ($studentId <= 0) {
        $errors[] = 'Select a student.';
    }
    if (!$items || !is_array($items)) {
        $errors[] = 'Add at least one uniform item.';
    }

    // verify student exists
    if ($studentId > 0) {
        $sStmt = $pdo->prepare('SELECT id FROM students WHERE id = :id LIMIT 1');
        $sStmt->execute(['id' => $studentId]);
        if (!$sStmt->fetch()) {
            $errors[] = 'Selected student not found.';
        }
    }

    $subtotal = 0.0;
    $validatedItems = [];
    foreach ($items as $it) {
        $uid = (int) ($it['uniform_id'] ?? 0);
        $qty = (int) ($it['quantity'] ?? 0);
        $size = trim($it['size'] ?? '');
        if ($uid <= 0 || $qty <= 0) continue;
        $uStmt = $pdo->prepare('SELECT * FROM uniforms WHERE id = :id LIMIT 1');
        $uStmt->execute(['id' => $uid]);
        $u = $uStmt->fetch();
        if (!$u) { $errors[] = 'Uniform item not found.'; break; }
        $unitPrice = isset($u['selling_price']) ? (float)$u['selling_price'] : 0.0;
        $lineTotal = $qty * $unitPrice;
        $subtotal += $lineTotal;
        $validatedItems[] = ['uniform' => $u, 'quantity' => $qty, 'size' => $size, 'line_total' => $lineTotal, 'unit_price' => $unitPrice];
    }

    if (empty($validatedItems)) {
        $errors[] = 'No valid items to sell.';
    }

    $grandTotal = max($subtotal - $discount, 0);
    $balance = max($grandTotal - $amountPaid, 0);

    // prevent overpayment
    if ($amountPaid > $grandTotal + 0.005) {
        $errors[] = 'Amount paid cannot exceed grand total.';
    }
    if ($paymentMethod === 'Mpesa' && $mpesaCode !== '' && payment_code_duplicate_exists($pdo, $mpesaCode, 'uniform_mpesa_code')) {
        $errors[] = 'This M-PESA code has already been used. Please use a unique code.';
    }
    $status = ($amountPaid >= $grandTotal) ? 'PAID' : 'PARTIAL';

    if (!$errors) {
        if (!$canSellUniform) {
            flash('error', 'You do not have permission to sell uniforms.');
            redirect('admin/uniform_sales.php');
        }
        try {
            $pdo->beginTransaction();
            $receiptNo = 'US-' . date('Ymd-His') . '-' . strtoupper(substr(bin2hex(random_bytes(3)),0,6));
            $insert = $pdo->prepare('INSERT INTO uniform_sales (student_id, receipt_no, subtotal, discount, grand_total, amount_paid, balance, payment_method, mpesa_code, served_by, payment_date) VALUES (:student_id, :receipt_no, :subtotal, :discount, :grand_total, :amount_paid, :balance, :payment_method, :mpesa_code, :served_by, :payment_date)');
            $insert->execute([
                'student_id' => $studentId,
                'receipt_no' => $receiptNo,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'grand_total' => $grandTotal,
                'amount_paid' => $amountPaid,
                'balance' => $balance,
                'payment_method' => $paymentMethod,
                'mpesa_code' => $mpesaCode,
                'served_by' => $_SESSION['admin_id'] ?? null,
                'payment_date' => date('Y-m-d H:i:s'),
            ]);
            $saleId = (int) $pdo->lastInsertId();

            $itemInsert = $pdo->prepare('INSERT INTO uniform_sale_items (sale_id, uniform_id, size, quantity, unit_price, line_total) VALUES (:sale_id, :uniform_id, :size, :quantity, :unit_price, :line_total)');
            $stockInsert = $pdo->prepare('INSERT INTO uniform_stock_movements (uniform_id, movement_type, quantity, reference_id, note) VALUES (:uniform_id, :movement_type, :quantity, :reference_id, :note)');

                foreach ($validatedItems as $vi) {
                $itemInsert->execute([
                    'sale_id' => $saleId,
                    'uniform_id' => $vi['uniform']['id'],
                    'size' => $vi['size'],
                    'quantity' => $vi['quantity'],
                        'unit_price' => $vi['unit_price'],
                        'line_total' => $vi['line_total'],
                ]);

                // record negative quantity for sale
                $stockInsert->execute([
                    'uniform_id' => $vi['uniform']['id'],
                    'movement_type' => 'Sale',
                    'quantity' => -1 * $vi['quantity'],
                    'reference_id' => $saleId,
                    'note' => 'Sold via uniform sales',
                ]);
            }

            $pdo->commit();
            redirect('admin/uniform_receipt.php?id=' . $saleId);
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Unable to record sale: ' . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Sales</p>
        <h1>Sell Uniform</h1>
        <p class="mb-0 text-muted">Create a uniform sale and record payment.</p>
    </div>
    <div class="action-row">
        <a class="btn btn-outline-primary" href="<?= url('admin/uniforms.php') ?>">Catalog</a>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $err): ?><div><?= h($err) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-5">
        <section class="panel">
            <h2>Student</h2>
            <form id="sale-form" method="post">
                <div class="mb-3">
                    <label class="form-label">Student</label>
                    <select class="form-select" name="student_id" id="student-select" required>
                        <option value="">Choose student...</option>
                        <?php foreach ($students as $s): ?>
                            <option value="<?= (int)$s['id'] ?>" data-gender="<?= h($s['gender']) ?>"><?= h($s['registration_no'] . ' - ' . $s['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="student-info" style="display:none">
                    <div><strong id="si-name"></strong></div>
                    <div id="si-class"></div>
                    <hr>
                </div>

                <h3>Items</h3>
                <div class="mb-2">
                    <label class="form-label">Uniform</label>
                    <select id="uniform-select" class="form-select">
                        <option value="">Choose item...</option>
                        <?php foreach ($uniforms as $u): ?>
                            <option value="<?= $u['id'] ?>" data-price="<?= $u['selling_price'] ?>" data-gender="<?= h($u['gender']) ?>"><?= h($u['uniform_name'] . ' | ' . $u['size'] . ' | ' . money((float)$u['selling_price'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-2 row g-2">
                    <div class="col-5"><input id="item-size" class="form-control" placeholder="Size"></div>
                    <div class="col-4"><input id="item-qty" type="number" min="1" class="form-control" value="1"></div>
                    <div class="col-3"><button type="button" id="add-item" class="btn btn-primary w-100">Add</button></div>
                </div>

                <div>
                    <table id="items-table" class="table table-sm">
                        <thead><tr><th>Item</th><th>Size</th><th>Qty</th><th>Unit</th><th>Total</th><th></th></tr></thead>
                        <tbody></tbody>
                    </table>
                </div>

                <!-- Discount removed by request -->
                <div class="mb-3">
                    <label class="form-label">Amount Paid</label>
                    <input class="form-control" type="number" step="0.01" name="amount_paid" id="amount_paid" value="0">
                </div>
                <div class="mb-3">
                    <label class="form-label">Payment Method</label>
                    <select class="form-select" name="payment_method">
                        <option>Cash</option>
                        <option>Mpesa</option>
                        <option>Bank</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">M-PESA Code</label>
                    <input class="form-control" name="mpesa_code">
                </div>

                <input type="hidden" name="items" id="items-input">
                <?php if ($canSellUniform): ?>
                    <button class="btn btn-primary" type="submit">Save Sale</button>
                <?php else: ?>
                    <button class="btn btn-primary" type="button" disabled>Save Sale</button>
                <?php endif; ?>
            </form>
        </section>
    </div>
    <div class="col-lg-7">
        <section class="panel">
            <h2>Summary</h2>
            <div class="p-3">
                <div>Subtotal: <span id="summary-subtotal">KES 0.00</span></div>
                <div><strong>Grand Total: <span id="summary-grand">KES 0.00</span></strong></div>
                <div>Amount Paid: <span id="summary-paid">KES 0.00</span></div>
                <div><strong>Balance: <span id="summary-balance">KES 0.00</span></strong></div>
            </div>
        </section>
    </div>
</div>

    <section class="panel mt-4">
        <h2>Sales Records</h2>
        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <thead><tr><th>Receipt</th><th>Student</th><th>Date</th><th>Grand Total</th><th>Paid</th><th>Balance</th><th>Action</th></tr></thead>
                <tbody>
                    <?php foreach ($sales as $s): ?>
                        <tr>
                            <td><?= h($s['receipt_no']) ?></td>
                            <td><?= h($s['full_name']) ?><br><small><?= h($s['registration_no']) ?></small></td>
                            <td><?= h($s['payment_date']) ?></td>
                            <td><?= money((float)$s['grand_total']) ?></td>
                            <td><?= money((float)$s['amount_paid']) ?></td>
                            <td><?= money((float)$s['balance']) ?></td>
                            <td>
                                <a class="btn btn-sm btn-outline-primary" href="<?= url('admin/uniform_receipt.php?id=' . (int)$s['id']) ?>">View Receipt</a>
                                <?php if ((float)$s['balance'] > 0.005): ?>
                                    <a class="btn btn-sm btn-outline-success" href="<?= url('admin/uniform_receipt.php?id=' . (int)$s['id']) ?>#pay-balance">Pay Balance</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$sales): ?><tr><td colspan="7">No sales recorded.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

<script>
const uniforms = Array.from(document.querySelectorAll('#uniform-select option')).slice(1).map(o=>({id:o.value, text:o.textContent, price:parseFloat(o.dataset.price||0), gender:o.dataset.gender}));
const studentSelect = document.getElementById('student-select');
const si = document.getElementById('student-info');
const siName = document.getElementById('si-name');
const siClass = document.getElementById('si-class');
studentSelect.addEventListener('change', ()=>{
    const opt = studentSelect.selectedOptions[0];
    if (!opt || !opt.value) { si.style.display='none'; return; }
    si.style.display='block';
    siName.textContent = opt.textContent;
    siClass.textContent = 'Gender: ' + (opt.dataset.gender||'');
    // filter uniforms by gender
    const gender = (opt.dataset.gender || '').trim().toLowerCase();
    const us = document.getElementById('uniform-select');
    for (let i=0;i<us.options.length;i++){
        const o = us.options[i];
        if (!o.value) continue;
        const itemGender = (o.dataset.gender || '').trim().toLowerCase();
        if (itemGender && itemGender !== gender && itemGender !== 'unisex') {
            o.style.display = 'none';
        } else {
            o.style.display = 'block';
        }
    }
});

const items = [];
const itemsTable = document.querySelector('#items-table tbody');
function renderItems(){
    itemsTable.innerHTML='';
    let subtotal=0;
    items.forEach((it,idx)=>{
        const tr=document.createElement('tr');
        tr.innerHTML = `<td>${it.text}</td><td>${it.size}</td><td>${it.qty}</td><td>${it.price.toFixed(2)}</td><td>${(it.qty*it.price).toFixed(2)}</td><td><button type="button" data-idx="${idx}" class="btn btn-sm btn-outline-danger remove">Remove</button></td>`;
        itemsTable.appendChild(tr);
        subtotal += it.qty*it.price;
    });
    document.getElementById('summary-subtotal').textContent = 'KES ' + subtotal.toFixed(2);
    const grand = Math.max(subtotal,0);
    document.getElementById('summary-grand').textContent = 'KES ' + grand.toFixed(2);
    const paid = parseFloat(document.getElementById('amount_paid').value||0);
    document.getElementById('summary-paid').textContent = 'KES ' + paid.toFixed(2);
    document.getElementById('summary-balance').textContent = 'KES ' + Math.max(grand - paid,0).toFixed(2);
    document.getElementById('items-input').value = JSON.stringify(items.map(i=>({uniform_id:i.id,quantity:i.qty,size:i.size})));
}

document.getElementById('add-item').addEventListener('click', ()=>{
    const sel = document.getElementById('uniform-select');
    if (!sel.value) return alert('Choose uniform item');
    const opt = sel.selectedOptions[0];
    const id = opt.value; const text = opt.textContent; const price = parseFloat(opt.dataset.price||0);
    const qty = parseInt(document.getElementById('item-qty').value||1);
    const size = document.getElementById('item-size').value||opt.textContent.split('|')[1]||'';
    items.push({id, text, qty, size, price}); renderItems();
});

document.getElementById('items-table').addEventListener('click', (e)=>{ if (e.target.classList.contains('remove')){ items.splice(parseInt(e.target.dataset.idx),1); renderItems(); } });
document.getElementById('amount_paid').addEventListener('input', renderItems);

document.getElementById('sale-form').addEventListener('submit', function(e){ if (items.length===0){ e.preventDefault(); alert('Add items before saving'); } else { document.getElementById('items-input').value = JSON.stringify(items.map(i=>({uniform_id:i.id,quantity:i.qty,size:i.size}))); }});

renderItems();
</script>

<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>

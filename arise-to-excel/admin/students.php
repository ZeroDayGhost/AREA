<?php
$pageTitle = 'Students';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studentId = (int) ($_POST['student_id'] ?? 0);

    if ($studentId > 0) {
        $statement = $pdo->prepare("DELETE FROM students WHERE id = :id");
        $statement->execute(['id' => $studentId]);
        flash('success', 'Student deleted successfully.');
    }

    redirect('admin/students.php');
}

$classLevels = class_level_options();
$currentContext = current_academic_context($pdo);
sync_current_term_fee_balances($pdo);
$search = trim($_GET['search'] ?? '');
$filterClass = in_array(($_GET['class_level'] ?? ''), $classLevels, true) ? $_GET['class_level'] : '';
$params = [
    'balance_academic_year' => $currentContext['academic_year'],
    'balance_term' => $currentContext['term'],
];
$where = [];

if ($search !== '') {
    $where[] = "(students.registration_no LIKE :search OR students.full_name LIKE :search OR students.class_level LIKE :search OR students.parent_name LIKE :search)";
    $params['search'] = '%' . $search . '%';
}
if ($filterClass !== '') {
    $where[] = 'students.class_level = :class_level';
    $params['class_level'] = $filterClass;
}

$sql = "SELECT
            students.*,
            COALESCE(current_balances.required_amount, 0) AS required_amount,
            COALESCE(current_balances.paid_amount, 0) AS paid_amount,
            COALESCE(current_balances.balance, 0) AS balance
        FROM students
        LEFT JOIN fee_balances AS current_balances
          ON current_balances.student_id = students.id
         AND current_balances.academic_year = :balance_academic_year
         AND current_balances.term = :balance_term";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= " ORDER BY students.class_level ASC, students.full_name ASC";
$statement = $pdo->prepare($sql);
$statement->execute($params);
$students = $statement->fetchAll();
$studentsByClass = [];
foreach ($students as $student) {
    $studentsByClass[$student['class_level'] ?: 'Unassigned'][] = $student;
}

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Student Registration</p>
        <h1>Students</h1>
    </div>
    <div class="action-row">
        <a class="btn btn-outline-primary" href="<?= url('admin/export.php?type=students') ?>">Export Students Excel</a>
        <a class="btn btn-outline-primary" href="<?= url('admin/import_students.php') ?>">Import Students Excel</a>
        <a class="btn btn-primary" href="<?= url('admin/student_form.php') ?>">Add Student</a>
    </div>
</div>

<?php if ($message = flash('success')): ?>
    <div class="alert alert-success"><?= h($message) ?></div>
<?php endif; ?>

<section class="panel">
    <form class="row g-3 mb-4" method="get">
        <div class="col-lg-7 col-md-6">
            <label class="form-label" for="search">Search</label>
            <input class="form-control" name="search" value="<?= h($search) ?>" placeholder="Search by registration number, student, parent, or class">
        </div>
        <div class="col-lg-3 col-md-4">
            <label class="form-label" for="class_level">Class Level</label>
            <select class="form-select" id="class_level" name="class_level">
                <option value="">All classes</option>
                <?php foreach ($classLevels as $classLevel): ?>
                    <option value="<?= h($classLevel) ?>" <?= $filterClass === $classLevel ? 'selected' : '' ?>><?= h($classLevel) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 col-md-2 d-flex align-items-end">
            <button class="btn btn-outline-primary w-100" type="submit">Search</button>
        </div>
    </form>
</section>
<?php foreach ($studentsByClass as $classLevel => $classStudents): ?>
    <section class="panel mt-4">
        <div class="panel-heading">
            <h2><?= h($classLevel) ?></h2>
            <strong><?= count($classStudents) ?> student(s)</strong>
        </div>
        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <thead>
                    <tr>
                        <th>Reg No</th>
                        <th>Name</th>
                        <th>Gender</th>
                        <th>Parent Name</th>
                        <th>Parent Phone</th>
                        <th>Academic Year</th>
                        <th>Term</th>
                        <th>Required Amount</th>
                        <th>Paid Amount</th>
                        <th>Balance</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($classStudents as $student): ?>
                        <tr>
                            <td><?= h($student['registration_no']) ?></td>
                            <td><?= h($student['full_name']) ?></td>
                            <td><?= h($student['gender']) ?></td>
                            <td><?= h($student['parent_name']) ?></td>
                            <td><?= h($student['guardian_phone']) ?></td>
                            <td><?= h($currentContext['academic_year']) ?></td>
                            <td><?= h($currentContext['term']) ?></td>
                            <td><?= money((float) $student['required_amount']) ?></td>
                            <td><?= money((float) $student['paid_amount']) ?></td>
                            <td><?= money((float) $student['balance']) ?></td>
                            <td>
                                <div class="action-row">
                                    <a class="btn btn-sm btn-outline-primary" href="<?= url('admin/student_form.php?id=' . $student['id']) ?>">Edit</a>
                                    <a class="btn btn-sm btn-primary" href="<?= url('admin/fees.php?student_id=' . $student['id']) ?>">Pay</a>
                                    <form method="post" onsubmit="return confirm('Delete this student and all related fee records?');">
                                        <input type="hidden" name="student_id" value="<?= (int) $student['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endforeach; ?>
<?php if (!$studentsByClass): ?>
    <section class="panel mt-4">No students found.</section>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>

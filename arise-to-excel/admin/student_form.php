<?php
$pageTitle = 'Student Form';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

$studentId = (int) ($_GET['id'] ?? 0);
$student = [
    'registration_no' => generate_registration_no($pdo),
    'full_name' => '',
    'gender' => '',
    'class_level' => '',
    'student_type' => 'Normal Student',
    'parent_name' => '',
    'guardian_phone' => '',
];
$errors = [];
$classLevels = class_level_options();
$genderOptions = gender_options();
$studentTypeOptions = student_type_options();
$currentContext = current_academic_context($pdo);
$academicYear = $currentContext['academic_year'];

function student_form_discount_rows(PDO $pdo, int $studentId, ?array $structure, string $academicYear, array $postedDiscountPercentages = [], array $postedDiscountedFees = []): array
{
    $rows = [];

    foreach (term_options() as $term) {
        $originalFee = $structure ? fee_required_amount_for_term($structure, $term) : 0.0;
        $savedDiscount = $studentId > 0 ? fetch_student_fee_discount($pdo, $studentId, $academicYear, $term) : null;
        $discountPercentage = array_key_exists($term, $postedDiscountPercentages)
            ? (string) $postedDiscountPercentages[$term]
            : (string) ($savedDiscount['discount_percentage'] ?? '50');
        $discountedFee = array_key_exists($term, $postedDiscountedFees)
            ? (string) $postedDiscountedFees[$term]
            : (string) ($savedDiscount['discounted_fee'] ?? default_discounted_fee($originalFee, (float) $discountPercentage));

        $rows[$term] = [
            'term' => $term,
            'original_fee' => $originalFee,
            'discount_percentage' => $discountPercentage,
            'discounted_fee' => $discountedFee,
        ];
    }

    return $rows;
}

if ($studentId > 0) {
    $statement = $pdo->prepare("SELECT * FROM students WHERE id = :id");
    $statement->execute(['id' => $studentId]);
    $student = $statement->fetch() ?: $student;
}

$selectedClassLevel = $_POST['class_level'] ?? ($student['class_level'] ?? '');
$selectedStudentType = $_POST['student_type'] ?? ($student['student_type'] ?? 'Normal Student');
$selectedStructure = in_array($selectedClassLevel, $classLevels, true) ? get_fee_structure($pdo, $selectedClassLevel, $academicYear) : null;
$discountRows = student_form_discount_rows(
    $pdo,
    $studentId,
    $selectedStructure,
    $academicYear,
    $_POST['discount_percentage'] ?? [],
    $_POST['discounted_fee'] ?? []
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'full_name' => trim($_POST['full_name'] ?? ''),
        'gender' => trim($_POST['gender'] ?? ''),
        'class_level' => trim($_POST['class_level'] ?? ''),
        'student_type' => trim($_POST['student_type'] ?? ''),
        'parent_name' => trim($_POST['parent_name'] ?? ''),
        'guardian_phone' => trim($_POST['guardian_phone'] ?? ''),
    ];

    if ($data['full_name'] === '') {
        $errors[] = 'Student name is required.';
    }
    if (!in_array($data['gender'], $genderOptions, true)) {
        $errors[] = 'Please choose a valid gender.';
    }
    if ($data['class_level'] === '') {
        $errors[] = 'Class level is required.';
    }
    if ($data['class_level'] !== '' && !in_array($data['class_level'], $classLevels, true)) {
        $errors[] = 'Please choose a valid class level.';
    }
    if (!in_array($data['student_type'], $studentTypeOptions, true)) {
        $errors[] = 'Please choose a valid student type.';
    }
    if ($data['parent_name'] === '') {
        $errors[] = 'Parent name is required.';
    }

    $feeStructure = !$errors ? get_fee_structure($pdo, $data['class_level'], $academicYear) : null;
    if (!$errors && !$feeStructure) {
        $errors[] = "Set up the {$academicYear} fee structure for {$data['class_level']} before adding this student.";
    }

    $discountInputs = [];
    if (!$errors && is_teacher_child_type($data['student_type'])) {
        foreach (term_options() as $term) {
            $discountValue = trim((string) ($_POST['discount_percentage'][$term] ?? '50'));
            $discountedValue = trim((string) ($_POST['discounted_fee'][$term] ?? ''));
            $originalFee = fee_required_amount_for_term($feeStructure, $term);

            if (!valid_money_value($discountValue) || (float) $discountValue > 100) {
                $errors[] = "{$term} discount must be between 0 and 100.";
                continue;
            }

            $discountPercentage = (float) $discountValue;
            if ($discountedValue === '') {
                $discountedFee = default_discounted_fee($originalFee, $discountPercentage);
            } elseif (!valid_money_value($discountedValue)) {
                $errors[] = "{$term} discounted fee must be zero or more.";
                continue;
            } else {
                $discountedFee = (float) $discountedValue;
            }

            if ($discountedFee > $originalFee + 0.005) {
                $errors[] = "{$term} discounted fee cannot exceed the normal fee.";
                continue;
            }

            $discountInputs[$term] = [
                'original_fee' => $originalFee,
                'discount_percentage' => $discountPercentage,
                'discounted_fee' => $discountedFee,
            ];
        }
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            if ($studentId > 0) {
                $data['id'] = $studentId;
                $statement = $pdo->prepare(
                    "UPDATE students
                     SET full_name = :full_name,
                         gender = :gender,
                         class_level = :class_level,
                         student_type = :student_type,
                         parent_name = :parent_name,
                         guardian_phone = :guardian_phone
                     WHERE id = :id"
                );
                $statement->execute($data);
                $savedStudentId = $studentId;
                flash('success', 'Student updated successfully.');
            } else {
                $data['registration_no'] = generate_registration_no($pdo, $academicYear);
                $statement = $pdo->prepare(
                    "INSERT INTO students (registration_no, full_name, gender, class_level, student_type, parent_name, guardian_phone)
                     VALUES (:registration_no, :full_name, :gender, :class_level, :student_type, :parent_name, :guardian_phone)"
                );
                $statement->execute($data);
                $savedStudentId = (int) $pdo->lastInsertId();
                flash('success', 'Student registered successfully. Current term fee balance was created automatically.');
            }

            if (is_teacher_child_type($data['student_type'])) {
                foreach ($discountInputs as $term => $discountInput) {
                    save_student_fee_discount(
                        $pdo,
                        $savedStudentId,
                        $academicYear,
                        $term,
                        $discountInput['original_fee'],
                        $discountInput['discount_percentage'],
                        $discountInput['discounted_fee']
                    );
                }
            } else {
                remove_student_fee_discounts($pdo, $savedStudentId, $academicYear);
            }

            sync_fee_balance_for_student($pdo, $savedStudentId, $data['class_level'], $academicYear, $currentContext['term']);
            $pdo->commit();
            redirect('admin/students.php');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            $errors[] = 'Unable to save student: ' . $exception->getMessage();
        }
    }

    $student = array_merge($student, $data);
    $discountRows = student_form_discount_rows(
        $pdo,
        $studentId,
        $feeStructure,
        $academicYear,
        $_POST['discount_percentage'] ?? [],
        $_POST['discounted_fee'] ?? []
    );
}

$feeStructureMap = [];
$structureRows = $pdo->prepare("SELECT * FROM fee_structures WHERE academic_year = :academic_year");
$structureRows->execute(['academic_year' => $academicYear]);
foreach ($structureRows->fetchAll() as $structureRow) {
    $feeStructureMap[$structureRow['class_level']] = [
        'Term 1' => (float) $structureRow['term1_total'],
        'Term 2' => (float) $structureRow['term2_total'],
        'Term 3' => (float) $structureRow['term3_total'],
    ];
}

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Student Registration</p>
        <h1><?= $studentId > 0 ? 'Edit Student' : 'Add Student' ?></h1>
    </div>
    <div class="action-row">
        <?php if ($studentId === 0): ?>
            <a class="btn btn-outline-primary" href="#import-students">Import Students Excel</a>
        <?php endif; ?>
        <a class="btn btn-outline-primary" href="<?= url('admin/students.php') ?>">Back to Students</a>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $error): ?>
            <div><?= h($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<section class="panel">
    <div class="panel-heading">
        <h2>Student Details</h2>
        <span class="text-muted">Balances use <?= h($academicYear . ' ' . $currentContext['term']) ?></span>
    </div>
    <form class="row g-3" method="post">
        <div class="col-md-4">
            <label class="form-label" for="registration_no">Registration Number</label>
            <input class="form-control" id="registration_no" value="<?= h((string) $student['registration_no']) ?>" readonly>
        </div>
        <div class="col-md-5">
            <label class="form-label" for="full_name">Student Name</label>
            <input class="form-control" id="full_name" name="full_name" value="<?= h((string) $student['full_name']) ?>" required>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="gender">Gender</label>
            <select class="form-select" id="gender" name="gender" required>
                <option value="">Choose gender...</option>
                <?php foreach ($genderOptions as $gender): ?>
                    <option value="<?= h($gender) ?>" <?= ((string) ($student['gender'] ?? '') === $gender) ? 'selected' : '' ?>><?= h($gender) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="class_level">Class Level</label>
            <select class="form-select" id="class_level" name="class_level" required>
                <option value="">Choose class...</option>
                <?php foreach ($classLevels as $classLevel): ?>
                    <option value="<?= h($classLevel) ?>" <?= ((string) $student['class_level'] === $classLevel) ? 'selected' : '' ?>><?= h($classLevel) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="student_type">Student Type</label>
            <select class="form-select" id="student_type" name="student_type" required>
                <?php foreach ($studentTypeOptions as $studentType): ?>
                    <option value="<?= h($studentType) ?>" <?= ((string) ($student['student_type'] ?? 'Normal Student') === $studentType) ? 'selected' : '' ?>><?= h($studentType) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="parent_name">Parent Name</label>
            <input class="form-control" id="parent_name" name="parent_name" value="<?= h((string) $student['parent_name']) ?>" >
        </div>
        <div class="col-md-4">
            <label class="form-label" for="guardian_phone">Parent Phone</label>
            <input class="form-control" id="guardian_phone" name="guardian_phone" value="<?= h((string) $student['guardian_phone']) ?>">
        </div>
        <div class="col-12" id="teacher_child_fee_panel">
            <div class="table-responsive">
                <table class="table table-striped align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Term</th>
                            <th>Original Fee</th>
                            <th>Discount %</th>
                            <th>Discounted Fee</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($discountRows as $term => $discountRow): ?>
                            <tr>
                                <td><?= h($term) ?></td>
                                <td>
                                    <input class="form-control original-fee-input" value="<?= h(number_format((float) $discountRow['original_fee'], 2, '.', '')) ?>" data-term="<?= h($term) ?>" readonly>
                                </td>
                                <td>
                                    <input class="form-control discount-percentage-input" type="number" min="0" max="100" step="0.01" name="discount_percentage[<?= h($term) ?>]" value="<?= h((string) $discountRow['discount_percentage']) ?>" data-term="<?= h($term) ?>">
                                </td>
                                <td>
                                    <input class="form-control discounted-fee-input" type="number" min="0" step="0.01" name="discounted_fee[<?= h($term) ?>]" value="<?= h((string) $discountRow['discounted_fee']) ?>" data-term="<?= h($term) ?>">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="col-12">
            <button class="btn btn-primary" type="submit">Save Student</button>
        </div>
    </form>
</section>
<script>
window.studentFeeStructures = <?= json_encode($feeStructureMap) ?>;
document.addEventListener('DOMContentLoaded', function () {
    const typeSelect = document.getElementById('student_type');
    const classSelect = document.getElementById('class_level');
    const panel = document.getElementById('teacher_child_fee_panel');
    const normalType = 'Normal Student';
    const teacherType = 'Teacher Child';

    function formatMoneyValue(value) {
        return Number(value || 0).toFixed(2);
    }

    function refreshDiscountRows(recalculateDiscounted) {
        const fees = window.studentFeeStructures[classSelect.value] || {};
        document.querySelectorAll('.original-fee-input').forEach(function (input) {
            const term = input.dataset.term;
            const originalFee = Number(fees[term] || input.value || 0);
            input.value = formatMoneyValue(originalFee);

            if (recalculateDiscounted) {
                const discountInput = document.querySelector('.discount-percentage-input[data-term="' + term + '"]');
                const discountedInput = document.querySelector('.discounted-fee-input[data-term="' + term + '"]');
                const discount = Number(discountInput ? discountInput.value : 50);
                if (discountedInput) {
                    discountedInput.value = formatMoneyValue(Math.max(originalFee * ((100 - discount) / 100), 0));
                }
            }
        });
    }

    function refreshPanel() {
        const isTeacherChild = typeSelect.value === teacherType;
        panel.style.display = isTeacherChild ? '' : 'none';
        panel.querySelectorAll('input').forEach(function (input) {
            input.disabled = !isTeacherChild;
        });
        if (isTeacherChild) {
            refreshDiscountRows(false);
        }
    }

    typeSelect.addEventListener('change', function () {
        refreshPanel();
        if (typeSelect.value === teacherType) {
            refreshDiscountRows(true);
        }
    });
    classSelect.addEventListener('change', function () {
        refreshDiscountRows(true);
    });
    document.querySelectorAll('.discount-percentage-input').forEach(function (input) {
        input.addEventListener('change', function () {
            refreshDiscountRows(true);
        });
    });
    if (!typeSelect.value) {
        typeSelect.value = normalType;
    }
    refreshPanel();
});
</script>
<?php if ($studentId === 0): ?>
    <section class="panel mt-4" id="import-students">
        <div class="panel-heading">
            <h2>Import Students Excel</h2>
            <a class="btn btn-sm btn-outline-primary" href="<?= url('admin/import_students.php?download_template=1') ?>">Download Sample Template</a>
        </div>
        <form class="row g-3 align-items-end" method="post" action="<?= url('admin/import_students.php') ?>" enctype="multipart/form-data">
            <div class="col-lg-8">
                <label class="form-label" for="students_file">Excel File (.xlsx)</label>
                <input class="form-control" type="file" id="students_file" name="students_file" accept=".xlsx" required>
            </div>
            <div class="col-lg-4">
                <button class="btn btn-primary w-100" type="submit">Import Students</button>
            </div>
        </form>
    </section>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>

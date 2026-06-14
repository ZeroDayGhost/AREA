<?php
$pageTitle = 'Import Students';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';
require_once __DIR__ . '/../includes/xlsx_reader.php';

// Permission: import students
if (!current_admin_has_permission($pdo, 'students.import')) {
    flash('error', 'You do not have permission to import students.');
    redirect('admin/students.php');
}

$errors = [];
$classLevels = class_level_options();
$genderOptions = gender_options();
$currentContext = current_academic_context($pdo);
$academicYear = $currentContext['academic_year'];

function normalize_import_header(string $header): string
{
    $header = strtolower(trim($header));
    $header = preg_replace('/[^a-z0-9]+/', '_', $header);
    $header = trim((string) $header, '_');

    $aliases = [
        'registration_no' => 'regno',
        'registration_number' => 'regno',
        'reg_no' => 'regno',
        'student' => 'student_name',
        'name' => 'student_name',
        'full_name' => 'student_name',
        'parent' => 'parent_name',
        'guardian_name' => 'parent_name',
        'guardian_phone' => 'parent_phone',
        'phone' => 'parent_phone',
    ];

    return $aliases[$header] ?? $header;
}

function normalize_import_gender(string $gender): string
{
    $gender = strtolower(trim($gender));

    if (in_array($gender, ['m', 'male'], true)) {
        return 'Male';
    }
    if (in_array($gender, ['f', 'female'], true)) {
        return 'Female';
    }

    return '';
}

function import_row_is_blank(array $row): bool
{
    foreach ($row as $value) {
        if (trim((string) $value) !== '') {
            return false;
        }
    }

    return true;
}

function next_import_registration_no(PDO $pdo, string $academicYear, array $reservedRegistrationNumbers): string
{
    $candidate = generate_registration_no($pdo, $academicYear);
    $sequence = 1;
    if (preg_match('/^' . preg_quote($academicYear, '/') . '-(\d+)$/', $candidate, $matches)) {
        $sequence = (int) $matches[1];
    }

    do {
        $candidate = $academicYear . '-' . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
        $registrationKey = strtolower($candidate);
        $sequence++;
    } while (isset($reservedRegistrationNumbers[$registrationKey]) || registration_no_exists($pdo, $candidate));

    return $candidate;
}

function xlsx_cell(string $reference, string $value): string
{
    return '<c r="' . h($reference) . '" t="inlineStr"><is><t>' . htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></is></c>';
}

function send_student_import_template(): void
{
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        echo 'The PHP Zip extension is required to generate the Excel template.';
        exit;
    }

    $headers = ['regno', 'student_name', 'gender', 'parent_name', 'class_level', 'parent_phone'];
    $sample = ['26/001', 'Jane Wanjiku', 'Female', 'Mary Wanjiku', 'Grade 1', '0712345678'];
    $sheetRows = [];
    foreach ([$headers, $sample] as $rowIndex => $row) {
        $cells = [];
        foreach ($row as $columnIndex => $value) {
            $cells[] = xlsx_cell(chr(65 + $columnIndex) . ($rowIndex + 1), $value);
        }
        $sheetRows[] = '<row r="' . ($rowIndex + 1) . '">' . implode('', $cells) . '</row>';
    }

    $temporaryFile = tempnam(sys_get_temp_dir(), 'student_template_');
    $zip = new ZipArchive();
    $zip->open($temporaryFile, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Students" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . implode('', $sheetRows) . '</sheetData></worksheet>');
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="student_import_template.xlsx"');
    header('Content-Length: ' . filesize($temporaryFile));
    readfile($temporaryFile);
    unlink($temporaryFile);
    exit;
}

if (isset($_GET['download_template'])) {
    send_student_import_template();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['students_file']) || $_FILES['students_file']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Choose a valid .xlsx file to import.';
    } else {
        $file = $_FILES['students_file'];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if ($extension !== 'xlsx') {
            $errors[] = 'Only .xlsx files are accepted.';
        }
        if ((int) $file['size'] <= 0 || (int) $file['size'] > 5 * 1024 * 1024) {
            $errors[] = 'The uploaded file must be between 1 byte and 5 MB.';
        }
    }

    $records = [];

    if (!$errors) {
        try {
            $rows = xlsx_read_rows($file['tmp_name']);
            $headerRowIndex = null;
            $headers = [];

            foreach ($rows as $index => $row) {
                if (!import_row_is_blank($row)) {
                    $headerRowIndex = $index;
                    foreach ($row as $columnIndex => $header) {
                        $normalizedHeader = normalize_import_header((string) $header);
                        if ($normalizedHeader !== '') {
                            $headers[$normalizedHeader] = $columnIndex;
                        }
                    }
                    break;
                }
            }

            $requiredHeaders = ['regno', 'student_name', 'gender', 'parent_name', 'class_level', 'parent_phone'];
            foreach ($requiredHeaders as $requiredHeader) {
                if (!array_key_exists($requiredHeader, $headers)) {
                    $errors[] = "Missing Excel column: {$requiredHeader}.";
                }
            }

            if ($headerRowIndex === null) {
                $errors[] = 'The uploaded Excel file does not contain student rows.';
            }

            $seenRegistrationNumbers = [];

            if (!$errors) {
                foreach (array_slice($rows, $headerRowIndex + 1, null, true) as $rowIndex => $row) {
                    if (import_row_is_blank($row)) {
                        continue;
                    }

                    $rowNumber = $rowIndex + 1;
                    $registrationNo = trim((string) ($row[$headers['regno']] ?? ''));
                    $studentName = trim((string) ($row[$headers['student_name']] ?? ''));
                    $gender = normalize_import_gender((string) ($row[$headers['gender']] ?? ''));
                    $parentName = trim((string) ($row[$headers['parent_name']] ?? ''));
                    $classLevel = trim((string) ($row[$headers['class_level']] ?? ''));
                    $parentPhone = trim((string) ($row[$headers['parent_phone']] ?? ''));

                    if ($registrationNo === '') {
                        $registrationNo = next_import_registration_no($pdo, $academicYear, $seenRegistrationNumbers);
                        $registrationKey = strtolower($registrationNo);
                        $seenRegistrationNumbers[$registrationKey] = true;
                    } else {
                        $registrationKey = strtolower($registrationNo);
                        if (isset($seenRegistrationNumbers[$registrationKey])) {
                            $errors[] = "Row {$rowNumber}: duplicate registration number in the Excel file.";
                        }
                        if (registration_no_exists($pdo, $registrationNo)) {
                            $errors[] = "Row {$rowNumber}: registration number already exists.";
                        }
                        if (strlen($registrationNo) > 40) {
                            $errors[] = "Row {$rowNumber}: registration number is too long.";
                        }
                        $seenRegistrationNumbers[$registrationKey] = true;
                    }
                    if ($studentName === '') {
                        $errors[] = "Row {$rowNumber}: student_name is required.";
                    }
                    if (!in_array($gender, $genderOptions, true)) {
                        $errors[] = "Row {$rowNumber}: gender must be Male or Female.";
                    }
                    if ($parentName === '') {
                        $errors[] = "Row {$rowNumber}: parent_name is required.";
                    }
                    if (!in_array($classLevel, $classLevels, true)) {
                        $errors[] = "Row {$rowNumber}: class_level is not valid.";
                    }
                    if ($parentPhone === '') {
                        $errors[] = "Row {$rowNumber}: parent_phone is required.";
                    }
                    if (strlen($parentPhone) > 40) {
                        $errors[] = "Row {$rowNumber}: parent_phone is too long.";
                    }
                    if (in_array($classLevel, $classLevels, true) && !get_fee_structure($pdo, $classLevel, $academicYear)) {
                        $errors[] = "Row {$rowNumber}: no {$academicYear} fee structure exists for {$classLevel}.";
                    }

                    $records[] = [
                        'registration_no' => $registrationNo,
                        'full_name' => $studentName,
                        'gender' => $gender,
                        'class_level' => $classLevel,
                        'parent_name' => $parentName,
                        'guardian_phone' => $parentPhone,
                    ];
                }
            }

            if (!$errors && !$records) {
                $errors[] = 'No student rows were found to import.';
            }
        } catch (Throwable $exception) {
            $errors[] = 'Unable to read Excel file: ' . $exception->getMessage();
        }
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare(
                "INSERT INTO students (registration_no, full_name, gender, class_level, parent_name, guardian_phone)
                 VALUES (:registration_no, :full_name, :gender, :class_level, :parent_name, :guardian_phone)"
            );

            foreach ($records as $record) {
                $insert->execute($record);
                sync_fee_balance_for_student($pdo, (int) $pdo->lastInsertId(), $record['class_level'], $academicYear, $currentContext['term']);
            }

            $pdo->commit();
            flash('success', count($records) . ' student(s) imported successfully. Current term fee balances were created automatically.');
            redirect('admin/import_students.php');
        } catch (Throwable $exception) {
            $pdo->rollBack();
            $errors[] = 'Import failed: ' . $exception->getMessage();
        }
    }
}

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Student Registration</p>
        <h1>Import Students</h1>
        <p class="mb-0 text-muted"><?= h($academicYear . ' - ' . $currentContext['term']) ?></p>
    </div>
    <div class="action-row">
        <a class="btn btn-outline-primary" href="<?= url('admin/students.php') ?>">Back to Students</a>
        <a class="btn btn-primary" href="<?= url('admin/student_form.php') ?>">Add Student</a>
    </div>
</div>

<?php if ($message = flash('success')): ?>
    <div class="alert alert-success"><?= h($message) ?></div>
<?php endif; ?>
<?php if ($errors): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $error): ?>
            <div><?= h($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-5">
        <section class="panel">
            <h2>Upload Excel File</h2>
            <form class="row g-3" method="post" enctype="multipart/form-data">
                <div class="col-12">
                    <label class="form-label" for="students_file">Excel File</label>
                    <input class="form-control" type="file" id="students_file" name="students_file" accept=".xlsx" required>
                </div>
                <div class="col-12">
                    <button class="btn btn-primary w-100" type="submit">Import Students</button>
                </div>
                <div class="col-12">
                    <a class="btn btn-outline-primary w-100" href="<?= url('admin/import_students.php?download_template=1') ?>">Download Sample Excel Template</a>
                </div>
            </form>
        </section>
    </div>
    <div class="col-lg-7">
        <section class="panel">
            <h2>Accepted Columns</h2>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                        <tr>
                            <th>Column</th>
                            <th>Required</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (['regno', 'student_name', 'gender', 'parent_name', 'class_level', 'parent_phone'] as $column): ?>
                            <tr>
                                <td><?= h($column) ?></td>
                                <td>Yes</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>

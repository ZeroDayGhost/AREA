<?php
$pageTitle = 'Academic Settings';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

// Check permission
if (!current_admin_has_permission($pdo, 'academic_calendar.manage')) {
    flash('error', 'You do not have permission to access Academic Calendar.');
    redirect('admin/dashboard.php');
}

$terms = term_options();
$errors = [];
$editingId = (int) ($_GET['edit'] ?? ($_POST['calendar_id'] ?? 0));
$currentContext = current_academic_context($pdo);
$form = [
    'id' => '',
    'academic_year' => $currentContext['academic_year'],
    'term_name' => $currentContext['term'],
    'start_date' => $currentContext['start_date'] ?: date('Y-m-d'),
    'end_date' => $currentContext['end_date'] ?: date('Y-m-d'),
];

function academic_calendar_by_id(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare("SELECT * FROM academic_calendar WHERE id = :id");
    $statement->execute(['id' => $id]);
    $calendar = $statement->fetch();

    return $calendar ?: null;
}

function academic_calendar_duplicate_exists(PDO $pdo, string $academicYear, string $termName, int $ignoreId = 0): bool
{
    $sql = "SELECT COUNT(*)
            FROM academic_calendar
            WHERE academic_year = :academic_year
              AND term_name = :term_name";
    $params = [
        'academic_year' => $academicYear,
        'term_name' => $termName,
    ];

    if ($ignoreId > 0) {
        $sql .= " AND id <> :id";
        $params['id'] = $ignoreId;
    }

    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return (int) $statement->fetchColumn() > 0;
}

function academic_calendar_adjacent_context(PDO $pdo, string $academicYear, string $termName, int $direction): ?array
{
    $rows = $pdo->query(
        "SELECT academic_year, term_name
         FROM academic_calendar
         ORDER BY academic_year ASC, FIELD(term_name, 'Term 1', 'Term 2', 'Term 3')"
    )->fetchAll();

    foreach ($rows as $index => $row) {
        if ($row['academic_year'] === $academicYear && $row['term_name'] === $termName) {
            $targetIndex = $index + $direction;
            if (isset($rows[$targetIndex])) {
                return [
                    'academic_year' => $rows[$targetIndex]['academic_year'],
                    'term' => $rows[$targetIndex]['term_name'],
                ];
            }

            return null;
        }
    }

    return null;
}

function academic_calendar_replacement_context(PDO $pdo, string $academicYear, string $termName): ?array
{
    $terms = term_options();
    $deletedOrder = array_search($termName, $terms, true);
    $deletedOrder = $deletedOrder === false ? 0 : $deletedOrder;

    $statement = $pdo->prepare(
        "SELECT academic_year, term_name
         FROM academic_calendar
         WHERE academic_year = :academic_year
         ORDER BY FIELD(term_name, 'Term 1', 'Term 2', 'Term 3')"
    );
    $statement->execute(['academic_year' => $academicYear]);
    $sameYearRows = $statement->fetchAll();

    foreach ($sameYearRows as $row) {
        $rowOrder = array_search($row['term_name'], $terms, true);
        if ($rowOrder !== false && $rowOrder > $deletedOrder) {
            return ['academic_year' => $row['academic_year'], 'term' => $row['term_name']];
        }
    }

    for ($index = count($sameYearRows) - 1; $index >= 0; $index--) {
        $row = $sameYearRows[$index];
        $rowOrder = array_search($row['term_name'], $terms, true);
        if ($rowOrder !== false && $rowOrder < $deletedOrder) {
            return ['academic_year' => $row['academic_year'], 'term' => $row['term_name']];
        }
    }

    $fallback = $pdo->query(
        "SELECT academic_year, term_name
         FROM academic_calendar
         ORDER BY academic_year DESC, FIELD(term_name, 'Term 1', 'Term 2', 'Term 3')
         LIMIT 1"
    )->fetch();

    return $fallback ? ['academic_year' => $fallback['academic_year'], 'term' => $fallback['term_name']] : null;
}

function purge_academic_term(PDO $pdo, string $academicYear, string $termName, int $calendarId): array
{
    $counts = [];
    $deleteStatements = [
        'fee payments' => "DELETE FROM fees WHERE year = :academic_year AND term = :term",
        'fee balances' => "DELETE FROM fee_balances WHERE academic_year = :academic_year AND term = :term",
        'feeding records' => "DELETE FROM feeding_subscriptions WHERE academic_year = :academic_year AND term = :term",
        'transport records' => "DELETE FROM transport_accounts WHERE academic_year = :academic_year AND term = :term",
    ];

    foreach ($deleteStatements as $label => $sql) {
        $statement = $pdo->prepare($sql);
        $statement->execute([
            'academic_year' => $academicYear,
            'term' => $termName,
        ]);
        $counts[$label] = $statement->rowCount();
    }

    $deleteCalendar = $pdo->prepare(
        "DELETE FROM academic_calendar
         WHERE id = :id
           AND academic_year = :academic_year
           AND term_name = :term"
    );
    $deleteCalendar->execute([
        'id' => $calendarId,
        'academic_year' => $academicYear,
        'term' => $termName,
    ]);
    $counts['calendar records'] = $deleteCalendar->rowCount();

    return $counts;
}

if ($editingId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $editing = academic_calendar_by_id($pdo, $editingId);
    if ($editing) {
        $form = array_merge($form, $editing);
    } else {
        flash('error', 'Academic calendar record was not found.');
        redirect('admin/academic_calendar.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';

    if ($action === 'set_active') {
        $activeYear = trim($_POST['active_academic_year'] ?? '');
        $activeTerm = trim($_POST['active_term'] ?? '');

        if (!preg_match('/^\d{4}$/', $activeYear)) {
            $errors[] = 'Choose a valid four-digit academic year.';
        }
        if (!in_array($activeTerm, $terms, true)) {
            $errors[] = 'Choose a valid term.';
        }

        if (!$errors) {
            try {
                set_current_academic_context($pdo, $activeYear, $activeTerm);
                sync_current_term_fee_balances($pdo);
                // Remove transport accounts for the newly activated term that have no payments yet,
                // so the transport account listing starts empty for the new term.
                $purgeTransportStmt = $pdo->prepare(
                    "DELETE ta FROM transport_accounts ta
                     LEFT JOIN transport_payments tp ON tp.transport_account_id = ta.id
                     WHERE ta.academic_year = :academic_year
                       AND ta.term = :term
                       AND tp.id IS NULL"
                );
                $purgeTransportStmt->execute([
                    'academic_year' => $activeYear,
                    'term' => $activeTerm,
                ]);
                // mark session so listing pages ignore any lingering ?year/?term filters
                if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
                $_SESSION['academic_context_switched'] = true;
                flash('success', "Active academic period switched to {$activeYear} {$activeTerm}.");
                redirect('admin/academic_calendar.php');
            } catch (Throwable $exception) {
                $errors[] = $exception->getMessage();
            }
        }
    } elseif ($action === 'generate_year') {
        $newYear = trim($_POST['new_academic_year'] ?? '');

        if (!preg_match('/^\d{4}$/', $newYear)) {
            $errors[] = 'Enter a valid four-digit academic year.';
        }

        if (!$errors) {
            ensure_academic_calendar_for_year($pdo, $newYear);
            flash('success', "Default term calendar created for {$newYear}.");
            redirect('admin/academic_calendar.php?year=' . urlencode($newYear));
        }
    } else {
        $calendarId = (int) ($_POST['calendar_id'] ?? 0);
        $form = [
            'id' => $calendarId,
            'academic_year' => trim($_POST['academic_year'] ?? ''),
            'term_name' => trim($_POST['term_name'] ?? ''),
            'start_date' => trim($_POST['start_date'] ?? ''),
            'end_date' => trim($_POST['end_date'] ?? ''),
        ];

        if (!preg_match('/^\d{4}$/', $form['academic_year'])) {
            $errors[] = 'Academic year must be four digits.';
        }
        if (!in_array($form['term_name'], $terms, true)) {
            $errors[] = 'Choose a valid term.';
        }
        if (!valid_date_value($form['start_date'])) {
            $errors[] = 'Start date must be valid.';
        }
        if (!valid_date_value($form['end_date'])) {
            $errors[] = 'End date must be valid.';
        }
        if (!$errors && $form['start_date'] > $form['end_date']) {
            $errors[] = 'Start date must be before or equal to end date.';
        }
        if (!$errors && academic_calendar_duplicate_exists($pdo, $form['academic_year'], $form['term_name'], $action === 'update' ? $calendarId : 0)) {
            $errors[] = 'This academic year and term already exists.';
        }
        if ($action === 'update' && $calendarId <= 0) {
            $errors[] = 'Choose a calendar record to update.';
        }
        if (!in_array($action, ['save', 'update'], true)) {
            $errors[] = 'Choose a valid action.';
        }

        if (!$errors) {
            $params = [
                'academic_year' => $form['academic_year'],
                'term_name' => $form['term_name'],
                'start_date' => $form['start_date'],
                'end_date' => $form['end_date'],
            ];

            if ($action === 'update') {
                $params['id'] = $calendarId;
                $statement = $pdo->prepare(
                    "UPDATE academic_calendar
                     SET academic_year = :academic_year,
                         term_name = :term_name,
                         start_date = :start_date,
                         end_date = :end_date
                     WHERE id = :id"
                );
                $statement->execute($params);
                flash('success', 'Academic calendar updated successfully.');
            } else {
                $statement = $pdo->prepare(
                    "INSERT INTO academic_calendar (academic_year, term_name, start_date, end_date)
                     VALUES (:academic_year, :term_name, :start_date, :end_date)"
                );
                $statement->execute($params);
                flash('success', 'Academic calendar record saved successfully.');
            }

            redirect('admin/academic_calendar.php');
        }
    }
}

$filterYear = preg_match('/^\d{4}$/', ($_GET['year'] ?? '')) ? $_GET['year'] : '';
$params = [];
$sql = "SELECT * FROM academic_calendar";
if ($filterYear !== '') {
    $sql .= " WHERE academic_year = :academic_year";
    $params['academic_year'] = $filterYear;
}
$sql .= " ORDER BY academic_year DESC, FIELD(term_name, 'Term 1', 'Term 2', 'Term 3'), start_date ASC";
$statement = $pdo->prepare($sql);
$statement->execute($params);
$calendarRows = $statement->fetchAll();
$years = $pdo->query("SELECT DISTINCT academic_year FROM academic_calendar ORDER BY academic_year DESC")->fetchAll();
$yearOptions = array_column($years, 'academic_year');
if (!in_array($currentContext['academic_year'], $yearOptions, true)) {
    $yearOptions[] = $currentContext['academic_year'];
    rsort($yearOptions);
}
$previousContext = academic_calendar_adjacent_context($pdo, $currentContext['academic_year'], $currentContext['term'], -1);
$nextContext = academic_calendar_adjacent_context($pdo, $currentContext['academic_year'], $currentContext['term'], 1);

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">System Settings</p>
        <h1>Academic Calendar</h1>
    </div>
    <a class="btn btn-outline-primary" href="<?= url('admin/dashboard.php') ?>">Dashboard</a>
</div>

<?php if ($message = flash('success')): ?><div class="alert alert-success"><?= h($message) ?></div><?php endif; ?>
<?php if ($message = flash('error')): ?><div class="alert alert-danger"><?= h($message) ?></div><?php endif; ?>
<?php if ($errors): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $error): ?><div><?= h($error) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-xl-4 col-lg-5">
        <section class="panel">
            <h2>Active Term</h2>
            <div class="row g-3">
                <div class="col-sm-6">
                    <div class="mini-stat">
                        <span>Academic Year</span>
                        <strong><?= h($currentContext['academic_year']) ?></strong>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="mini-stat">
                        <span>Current Term</span>
                        <strong><?= h($currentContext['term']) ?></strong>
                    </div>
                </div>
                <div class="col-12">
                    <div class="mini-stat">
                        <span>Computer Date</span>
                        <strong><?= h($currentContext['today']) ?></strong>
                    </div>
                </div>
            </div>
            <div class="action-row mt-3">
                <?php if ($previousContext): ?>
                    <form method="post">
                        <input type="hidden" name="active_academic_year" value="<?= h($previousContext['academic_year']) ?>">
                        <input type="hidden" name="active_term" value="<?= h($previousContext['term']) ?>">
                        <button class="btn btn-sm btn-outline-primary" name="action" value="set_active" type="submit">Previous Term</button>
                    </form>
                <?php endif; ?>
                <?php if ($nextContext): ?>
                    <form method="post">
                        <input type="hidden" name="active_academic_year" value="<?= h($nextContext['academic_year']) ?>">
                        <input type="hidden" name="active_term" value="<?= h($nextContext['term']) ?>">
                        <button class="btn btn-sm btn-outline-primary" name="action" value="set_active" type="submit">Next Term</button>
                    </form>
                <?php endif; ?>
            </div>
            <form class="row g-3 mt-1" method="post">
                <div class="col-sm-6">
                    <label class="form-label" for="active_academic_year">Switch Year</label>
                    <select class="form-select" id="active_academic_year" name="active_academic_year" required>
                        <?php foreach ($yearOptions as $year): ?>
                            <option value="<?= h($year) ?>" <?= $currentContext['academic_year'] === $year ? 'selected' : '' ?>><?= h($year) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="active_term">Switch Term</label>
                    <select class="form-select" id="active_term" name="active_term" required>
                        <?php foreach ($terms as $term): ?>
                            <option value="<?= h($term) ?>" <?= $currentContext['term'] === $term ? 'selected' : '' ?>><?= h($term) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <button class="btn btn-primary w-100" name="action" value="set_active" type="submit">Switch Active Term</button>
                </div>
            </form>
        </section>

        <section class="panel mt-4">
            <h2>Create Academic Year</h2>
            <form class="row g-3" method="post">
                <div class="col-12">
                    <label class="form-label" for="new_academic_year">Academic Year</label>
                    <input class="form-control" id="new_academic_year" name="new_academic_year" maxlength="4" inputmode="numeric" value="<?= h($currentContext['academic_year']) ?>" required>
                </div>
                <div class="col-12">
                    <button class="btn btn-outline-primary w-100" name="action" value="generate_year" type="submit">Create Default Terms</button>
                </div>
            </form>
        </section>

        <section class="panel mt-4">
            <h2><?= (int) $form['id'] > 0 ? 'Edit Term Range' : 'New Term Range' ?></h2>
            <form class="row g-3" method="post">
                <input type="hidden" name="calendar_id" value="<?= (int) $form['id'] ?>">
                <div class="col-sm-6">
                    <label class="form-label" for="academic_year">Academic Year</label>
                    <input class="form-control" id="academic_year" name="academic_year" maxlength="4" inputmode="numeric" value="<?= h((string) $form['academic_year']) ?>" required>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="term_name">Term</label>
                    <select class="form-select" id="term_name" name="term_name" required>
                        <?php foreach ($terms as $term): ?>
                            <option value="<?= h($term) ?>" <?= $form['term_name'] === $term ? 'selected' : '' ?>><?= h($term) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="start_date">Start Date</label>
                    <input class="form-control" type="date" id="start_date" name="start_date" value="<?= h((string) $form['start_date']) ?>" required>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="end_date">End Date</label>
                    <input class="form-control" type="date" id="end_date" name="end_date" value="<?= h((string) $form['end_date']) ?>" required>
                </div>
                <div class="col-12">
                    <div class="action-row">
                        <button class="btn btn-primary" name="action" value="save" type="submit">Save</button>
                        <button class="btn btn-outline-primary" name="action" value="update" type="submit" <?= (int) $form['id'] > 0 ? '' : 'disabled' ?>>Update</button>
                    </div>
                </div>
            </form>
        </section>
    </div>

    <div class="col-xl-8 col-lg-7">
        <section class="panel">
            <div class="panel-heading">
                <h2>Term Ranges</h2>
            </div>
            <form class="row g-3 mb-4" method="get">
                <div class="col-sm-8">
                    <select class="form-select" name="year">
                        <option value="">All academic years</option>
                        <?php foreach ($years as $year): ?>
                            <option value="<?= h($year['academic_year']) ?>" <?= $filterYear === $year['academic_year'] ? 'selected' : '' ?>><?= h($year['academic_year']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-4">
                    <button class="btn btn-outline-primary w-100" type="submit">Filter</button>
                </div>
            </form>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                        <tr><th>Year</th><th>Term</th><th>Start</th><th>End</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($calendarRows as $row): ?>
                            <?php $isActiveRow = $row['academic_year'] === $currentContext['academic_year'] && $row['term_name'] === $currentContext['term']; ?>
                            <tr class="<?= $isActiveRow ? 'table-primary' : '' ?>">
                                <td><?= h($row['academic_year']) ?></td>
                                <td><?= h($row['term_name']) ?></td>
                                <td><?= h($row['start_date']) ?></td>
                                <td><?= h($row['end_date']) ?></td>
                                <td>
                                    <div class="action-row">
                                        <?php if ($isActiveRow): ?>
                                            <span class="badge bg-primary">Active</span>
                                        <?php else: ?>
                                            <form method="post">
                                                <input type="hidden" name="active_academic_year" value="<?= h($row['academic_year']) ?>">
                                                <input type="hidden" name="active_term" value="<?= h($row['term_name']) ?>">
                                                <button class="btn btn-sm btn-primary" name="action" value="set_active" type="submit">Set Active</button>
                                            </form>
                                        <?php endif; ?>
                                        <a class="btn btn-sm btn-outline-primary" href="<?= url('admin/academic_calendar.php?edit=' . $row['id']) ?>">Edit</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$calendarRows): ?><tr><td colspan="5">No calendar records found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>

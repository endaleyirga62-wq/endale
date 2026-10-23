<?php
require_once __DIR__ . '/auth.php';

$role = $_SESSION['role'] ?? '';
$menus = [
    'admin' => [
        'dashboard' => 'Dashboard', 'users' => 'User Management', 'roles' => 'Role & Permissions',
        'academic' => 'Academic Year Management', 'settings' => 'System Settings', 'audit' => 'Audit Logs',
        'backup' => 'Backup & Security',
    ],
    'registrar' => [
        'dashboard' => 'Dashboard', 'import' => 'Import Student Data', 'students' => 'Student Records',
        'gpa' => 'GPA & Ranking Management', 'placement_config' => 'Placement Configuration',
        'run' => 'Placement Algorithm Runner', 'review' => 'Placement Review & Approval',
        'publish' => 'Publish Placement Results', 'reports' => 'Official Placement Reports',
        'appeals' => 'Appeals Processing',
    ],
    'department_head' => [
        'dashboard' => 'Dashboard', 'department' => 'Department Information', 'criteria' => 'Special Criteria Settings',
        'capacity' => 'Capacity Proposal', 'applicants' => 'Applicants List', 'assigned' => 'Assigned Students',
        'announcements' => 'Department Announcements', 'department_reports' => 'Department Reports',
    ],
    'student' => [
        'dashboard' => 'Dashboard', 'departments' => 'Available Departments', 'choices' => 'Department Preference / Selection',
        'submitted' => 'My Submitted Choices', 'placement_result' => 'Placement Result',
        'profile' => 'My Profile', 'transcript' => 'My Transcript', 'appeal_submit' => 'Complaints / Appeals',
        'notifications' => 'Notifications',
    ],
    'college_dean' => [
        'dashboard' => 'Dashboard', 'college' => 'College Information', 'college_departments' => 'College Departments',
        'capacity_approval' => 'Department Capacity Approval', 'staff' => 'Coordinator & Department Head Management',
        'analytics' => 'College Placement Analytics', 'appeals' => 'Appeals Review', 'reports' => 'College Reports',
    ],
    'coordinator' => [
        'dashboard' => 'Dashboard', 'choices_monitor' => 'Student Choice Monitoring',
        'follow_up' => 'Pending Choice Follow-up', 'eligibility' => 'Student Eligibility Monitoring',
        'appeals' => 'Appeal / Dispute Handling', 'placement_reports' => 'Placement Preparation Reports',
        'notifications' => 'Notifications',
    ],
];

if (!isset($menus[$role])) {
    header('Location: login.php');
    exit;
}

$accountCheck = $pdo->prepare('SELECT role, is_active FROM users WHERE id = ?');
$accountCheck->execute([$_SESSION['user_id']]);
$account = $accountCheck->fetch();
if (!$account || !(int) $account['is_active'] || $account['role'] !== $role) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

$view = $_GET['view'] ?? 'dashboard';
if (!isset($menus[$role][$view])) {
    http_response_code(403);
    $view = 'dashboard';
    $notice = 'You do not have permission to access that section.';
}

$notice = $notice ?? '';
$error = '';
$generatedCredentials = [];
$currentUserId = (int) $_SESSION['user_id'];

$getCurrentAcademicYear = function () use ($pdo): ?array {
    $stmt = $pdo->query('SELECT * FROM academic_years WHERE is_current = 1 ORDER BY id DESC');
    $year = $stmt->fetch();
    return $year ?: null;
};

$studentStmt = $pdo->prepare('SELECT * FROM students WHERE user_id = ?');
$studentStmt->execute([$currentUserId]);
$currentStudent = $studentStmt->fetch() ?: null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sdss_verify_csrf()) {
        http_response_code(400);
        $error = 'Your form session expired. Reload the page and submit again.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'create_user' && $role === 'admin') {
                $name = trim((string) ($_POST['full_name'] ?? ''));
                $username = trim((string) ($_POST['username'] ?? ''));
                $password = (string) ($_POST['password'] ?? '');
                $newRole = (string) ($_POST['role'] ?? '');
                if ($name === '' || $username === '' || strlen($password) < 8 || !isset($menus[$newRole])) {
                    throw new InvalidArgumentException('Enter a name, username, valid role, and password of at least 8 characters.');
                }
                $studentCode = trim((string) ($_POST['student_code'] ?? ''));
                $gpa = filter_var($_POST['gpa'] ?? null, FILTER_VALIDATE_FLOAT);
                if ($newRole === 'student' && ($studentCode === '' || $gpa === false || $gpa < 0 || $gpa > 4)) {
                    throw new InvalidArgumentException('Student accounts require a unique student code and GPA from 0 to 4.');
                }
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('INSERT INTO users (full_name, email, password, role, is_active) VALUES (?, ?, ?, ?, 1)');
                $stmt->execute([$name, $username, password_hash($password, PASSWORD_DEFAULT), $newRole]);
                $newUserId = (int) $pdo->lastInsertId();
                if ($newRole === 'student') {
                    $year = $getCurrentAcademicYear();
                    $pdo->prepare('INSERT INTO students (user_id, full_name, student_code, email, gpa, academic_year_id) VALUES (?, ?, ?, ?, ?, ?)')
                        ->execute([$newUserId, $name, $studentCode, $username, $gpa, $year['id'] ?? null]);
                }
                $pdo->commit();
                sdss_audit($pdo, 'user.created', 'user', $newUserId, $newRole);
                $notice = 'Account created successfully.';
            } elseif ($action === 'toggle_user' && $role === 'admin') {
                $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$userId || $userId === $currentUserId) {
                    throw new InvalidArgumentException('You cannot deactivate your own account.');
                }
                $pdo->prepare('UPDATE users SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = ?')
                    ->execute([$userId]);
                sdss_audit($pdo, 'user.status_changed', 'user', $userId);
                $notice = 'User account status updated.';
            } elseif ($action === 'create_academic_year' && in_array($role, ['admin', 'registrar'], true)) {
                $name = trim((string) ($_POST['name'] ?? ''));
                $open = trim((string) ($_POST['selection_opens_at'] ?? ''));
                $close = trim((string) ($_POST['selection_closes_at'] ?? ''));
                $appealClose = trim((string) ($_POST['appeals_close_at'] ?? ''));
                if ($name === '') {
                    throw new InvalidArgumentException('Academic year name is required.');
                }
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE academic_years SET is_current = 0')->execute();
                $pdo->prepare(
                    "INSERT INTO academic_years (name, selection_opens_at, selection_closes_at, appeals_close_at, status, is_current) VALUES (?, ?, ?, ?, 'open', 1)"
                )->execute([
                    $name,
                    $open !== '' ? str_replace('T', ' ', $open) : null,
                    $close !== '' ? str_replace('T', ' ', $close) : null,
                    $appealClose !== '' ? str_replace('T', ' ', $appealClose) : null,
                ]);
                $pdo->commit();
                sdss_audit($pdo, 'academic_year.created', 'academic_year', (int) $pdo->lastInsertId(), $name);
                $notice = 'Academic year created and set as current.';
            } elseif ($action === 'update_placement_settings' && $role === 'registrar') {
                $open = trim((string) ($_POST['selection_opens_at'] ?? ''));
                $close = trim((string) ($_POST['selection_closes_at'] ?? ''));
                $appealClose = trim((string) ($_POST['appeals_close_at'] ?? ''));
                $status = (string) ($_POST['status'] ?? '');
                if (!in_array($status, ['open', 'closed'], true)) {
                    throw new InvalidArgumentException('Choose whether selection is open or closed.');
                }
                $year = $getCurrentAcademicYear();
                if (!$year) {
                    throw new RuntimeException('Create an academic year before configuring placement.');
                }
                $pdo->prepare('UPDATE academic_years SET selection_opens_at = ?, selection_closes_at = ?, appeals_close_at = ?, status = ? WHERE id = ?')
                    ->execute([
                        $open !== '' ? str_replace('T', ' ', $open) : null,
                        $close !== '' ? str_replace('T', ' ', $close) : null,
                        $appealClose !== '' ? str_replace('T', ' ', $appealClose) : null,
                        $status,
                        $year['id'],
                    ]);
                sdss_audit($pdo, 'placement.settings_updated', 'academic_year', (int) $year['id']);
                $notice = 'Placement selection and appeal dates updated.';
            } elseif ($action === 'update_gpa' && $role === 'registrar') {
                $studentId = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);
                $gpa = filter_var($_POST['gpa'] ?? null, FILTER_VALIDATE_FLOAT);
                if (!$studentId || $gpa === false || $gpa < 0 || $gpa > 4) {
                    throw new InvalidArgumentException('Choose a student and enter a GPA between 0 and 4.');
                }
                $pdo->prepare('UPDATE students SET gpa = ? WHERE id = ?')->execute([$gpa, $studentId]);
                sdss_audit($pdo, 'student.gpa_updated', 'student', $studentId);
                $notice = 'GPA updated.';
            } elseif ($action === 'update_eligibility' && $role === 'registrar') {
                $studentId = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);
                $eligible = filter_var($_POST['is_eligible'] ?? null, FILTER_VALIDATE_INT);
                if (!$studentId || !in_array($eligible, [0, 1], true)) {
                    throw new InvalidArgumentException('Choose a student and a valid eligibility status.');
                }
                $pdo->prepare('UPDATE students SET is_eligible = ? WHERE id = ?')->execute([$eligible, $studentId]);
                sdss_audit($pdo, 'student.eligibility_updated', 'student', $studentId, $eligible ? 'eligible' : 'ineligible');
                $notice = 'Student eligibility updated.';
            } elseif ($action === 'import_csv' && $role === 'registrar') {
                if (!isset($_FILES['student_csv']) || $_FILES['student_csv']['error'] !== UPLOAD_ERR_OK) {
                    throw new InvalidArgumentException('Upload a readable CSV file.');
                }
                if ((int) $_FILES['student_csv']['size'] > 5 * 1024 * 1024
                    || strtolower(pathinfo((string) $_FILES['student_csv']['name'], PATHINFO_EXTENSION)) !== 'csv') {
                    throw new InvalidArgumentException('Upload a CSV file no larger than 5 MB.');
                }
                $handle = fopen($_FILES['student_csv']['tmp_name'], 'rb');
                if ($handle === false) {
                    throw new RuntimeException('Unable to read the uploaded CSV.');
                }
                $headers = fgetcsv($handle);
                $expected = ['student_code', 'full_name', 'username', 'gpa'];
                if ($headers === false || array_map('strtolower', $headers) !== $expected) {
                    fclose($handle);
                    throw new InvalidArgumentException('CSV columns must be: student_code,full_name,username,gpa');
                }
                $pdo->beginTransaction();
                $line = 1;
                $imported = 0;
                $year = $getCurrentAcademicYear();
                while (($row = fgetcsv($handle)) !== false) {
                    $line++;
                    if (count($row) !== 4) {
                        $error .= "Row {$line}: expected four columns. ";
                        continue;
                    }
                    [$studentCode, $name, $username, $rawGpa] = array_map('trim', $row);
                    $gpa = filter_var($rawGpa, FILTER_VALIDATE_FLOAT);
                    if ($studentCode === '' || $name === '' || $username === '' || $gpa === false || $gpa < 0 || $gpa > 4) {
                        $error .= "Row {$line}: missing fields or GPA outside 0-4. ";
                        continue;
                    }
                    $userQuery = $pdo->prepare("SELECT id FROM users WHERE email = ? AND role = 'student'");
                    $userQuery->execute([$username]);
                    $userId = $userQuery->fetchColumn();
                    if ($userId === false) {
                        $error .= "Row {$line}: create the student login account first. ";
                        continue;
                    }
                    $existing = $pdo->prepare('SELECT id FROM students WHERE student_code = ? OR user_id = ?');
                    $existing->execute([$studentCode, $userId]);
                    $existingId = $existing->fetchColumn();
                    if ($existingId !== false) {
                        $pdo->prepare('UPDATE students SET full_name = ?, email = ?, gpa = ?, academic_year_id = ? WHERE id = ?')
                            ->execute([$name, $username, $gpa, $year['id'] ?? null, $existingId]);
                    } else {
                        $pdo->prepare('INSERT INTO students (user_id, full_name, student_code, email, gpa, academic_year_id) VALUES (?, ?, ?, ?, ?, ?)')
                            ->execute([$userId, $name, $studentCode, $username, $gpa, $year['id'] ?? null]);
                    }
                    $imported++;
                }
                fclose($handle);
                $pdo->commit();
                sdss_audit($pdo, 'students.csv_imported', 'student', null, "Imported or updated {$imported} records");
                $notice = "{$imported} student records imported or updated.";
            } elseif ($action === 'propose_capacity' && $role === 'department_head') {
                $departmentId = filter_var($_POST['department_id'] ?? null, FILTER_VALIDATE_INT);
                $capacity = filter_var($_POST['capacity'] ?? null, FILTER_VALIDATE_INT);
                $eligibilityGpa = filter_var($_POST['eligibility_gpa'] ?? null, FILTER_VALIDATE_FLOAT);
                if (!$departmentId || $capacity === false || $capacity < 0 || $eligibilityGpa === false || $eligibilityGpa < 0 || $eligibilityGpa > 4) {
                    throw new InvalidArgumentException('Select your department, provide a non-negative capacity, and minimum GPA from 0 to 4.');
                }
                $owned = $pdo->prepare('SELECT id FROM departments WHERE id = ? AND head_user_id = ?');
                $owned->execute([$departmentId, $currentUserId]);
                if ($owned->fetchColumn() === false) {
                    throw new RuntimeException('You can only propose capacity for your assigned department.');
                }
                $pdo->prepare('INSERT INTO capacity_proposals (department_id, proposed_by, proposed_capacity, proposed_eligibility_gpa, criteria) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$departmentId, $currentUserId, $capacity, $eligibilityGpa, trim((string) ($_POST['criteria'] ?? ''))]);
                sdss_audit($pdo, 'capacity.proposed', 'department', $departmentId);
                $notice = 'Capacity proposal sent for review.';
            } elseif ($action === 'review_capacity' && in_array($role, ['college_dean', 'registrar'], true)) {
                $proposalId = filter_var($_POST['proposal_id'] ?? null, FILTER_VALIDATE_INT);
                $decision = (string) ($_POST['decision'] ?? '');
                $note = trim((string) ($_POST['decision_note'] ?? ''));
                if (!$proposalId || !in_array($decision, ['approved', 'rejected'], true) || $note === '') {
                    throw new InvalidArgumentException('Choose a decision and provide an explanation.');
                }
                $pdo->beginTransaction();
                $proposalSql = "SELECT cp.* FROM capacity_proposals cp JOIN departments d ON d.id = cp.department_id WHERE cp.id = ? AND cp.status = 'pending'";
                $proposalParams = [$proposalId];
                if ($role === 'college_dean') {
                    $proposalSql .= ' AND d.college_id IN (SELECT id FROM colleges WHERE dean_user_id = ?)';
                    $proposalParams[] = $currentUserId;
                }
                $proposalStmt = $pdo->prepare($proposalSql);
                $proposalStmt->execute($proposalParams);
                $proposal = $proposalStmt->fetch();
                if (!$proposal) {
                    throw new RuntimeException('This proposal is no longer pending.');
                }
                if ($decision === 'approved') {
                    $pdo->prepare('UPDATE departments SET capacity = ?, eligibility_gpa = COALESCE(?, eligibility_gpa) WHERE id = ?')
                        ->execute([$proposal['proposed_capacity'], $proposal['proposed_eligibility_gpa'], $proposal['department_id']]);
                }
                $pdo->prepare('UPDATE capacity_proposals SET status = ?, reviewed_by = ?, decision_note = ?, reviewed_at = CURRENT_TIMESTAMP WHERE id = ?')
                    ->execute([$decision, $currentUserId, $note, $proposalId]);
                $pdo->commit();
                sdss_audit($pdo, 'capacity.' . $decision, 'capacity_proposal', $proposalId);
                $notice = 'Capacity proposal reviewed.';
            } elseif ($action === 'run_placement' && $role === 'registrar') {
                $year = $getCurrentAcademicYear();
                if (!$year) {
                    throw new RuntimeException('Create an academic year before running placement.');
                }
                $publishedQuery = $pdo->prepare(
                    'SELECT COUNT(*) FROM assignments a JOIN placement_runs r ON r.id = a.placement_run_id WHERE a.is_published = 1 AND r.academic_year_id = ?'
                );
                $publishedQuery->execute([$year['id']]);
                $published = (int) $publishedQuery->fetchColumn();
                if ($published > 0) {
                    throw new RuntimeException('Placement results for this academic year have already been published.');
                }
                $pdo->beginTransaction();
                $pdo->exec('DELETE FROM assignments WHERE is_published = 0');
                $pdo->prepare("INSERT INTO placement_runs (academic_year_id, started_by, status) VALUES (?, ?, 'review')")
                    ->execute([$year['id'] ?? null, $currentUserId]);
                $runId = (int) $pdo->lastInsertId();
                $studentsQuery = $pdo->prepare(
                    'SELECT s.* FROM students s WHERE s.is_eligible = 1 AND s.academic_year_id = ? AND NOT EXISTS (SELECT 1 FROM assignments a WHERE a.student_id = s.id AND a.is_published = 1) ORDER BY s.gpa DESC, s.student_code ASC'
                );
                $studentsQuery->execute([$year['id']]);
                $students = $studentsQuery->fetchAll();
                $departments = $pdo->query('SELECT id, capacity, eligibility_gpa FROM departments WHERE is_active = 1 ORDER BY id')->fetchAll();
                $capacityByDepartment = [];
                foreach ($departments as $department) {
                    $capacityByDepartment[(int) $department['id']] = [
                        'remaining' => (int) $department['capacity'],
                        'minimum_gpa' => (float) $department['eligibility_gpa'],
                    ];
                }
                $preferencesQuery = $pdo->prepare('SELECT department_id FROM department_preferences WHERE student_id = ? ORDER BY priority');
                $legacyChoicesQuery = $pdo->prepare('SELECT choice1, choice2, choice3 FROM choices WHERE student_id = ?');
                $insertAssignment = $pdo->prepare('INSERT INTO assignments (student_id, department_id, placement_run_id, is_published) VALUES (?, ?, ?, 0)');
                $assigned = 0;
                foreach ($students as $studentRow) {
                    $preferencesQuery->execute([$studentRow['id']]);
                    $preferences = array_map('intval', array_column($preferencesQuery->fetchAll(), 'department_id'));
                    if (!$preferences) {
                        $legacyChoicesQuery->execute([$studentRow['id']]);
                        $legacy = $legacyChoicesQuery->fetch();
                        $preferences = $legacy ? [(int) $legacy['choice1'], (int) $legacy['choice2'], (int) $legacy['choice3']] : [];
                    }
                    foreach ($preferences as $departmentId) {
                        if (!isset($capacityByDepartment[$departmentId])
                            || $capacityByDepartment[$departmentId]['remaining'] < 1
                            || (float) $studentRow['gpa'] < $capacityByDepartment[$departmentId]['minimum_gpa']) {
                            continue;
                        }
                        $insertAssignment->execute([$studentRow['id'], $departmentId, $runId]);
                        $capacityByDepartment[$departmentId]['remaining']--;
                        $assigned++;
                        break;
                    }
                }
                $summary = "Assigned {$assigned} of " . count($students) . ' eligible students.';
                $pdo->prepare('UPDATE placement_runs SET summary = ? WHERE id = ?')->execute([$summary, $runId]);
                $pdo->commit();
                sdss_audit($pdo, 'placement.run', 'placement_run', $runId, $summary);
                $notice = "Draft placement completed. {$summary} Review before publishing.";
            } elseif ($action === 'approve_placement' && $role === 'registrar') {
                $run = $pdo->query("SELECT id FROM placement_runs WHERE status = 'review' ORDER BY id DESC LIMIT 1")->fetch();
                if (!$run) {
                    throw new RuntimeException('There is no placement run awaiting review.');
                }
                $pdo->prepare("UPDATE placement_runs SET status = 'approved' WHERE id = ?")->execute([$run['id']]);
                sdss_audit($pdo, 'placement.approved', 'placement_run', (int) $run['id']);
                $notice = 'Placement run approved for publication.';
            } elseif ($action === 'publish_placement' && $role === 'registrar') {
                $pdo->beginTransaction();
                $run = $pdo->query("SELECT id FROM placement_runs WHERE status = 'approved' ORDER BY id DESC LIMIT 1")->fetch();
                if (!$run) {
                    throw new RuntimeException('Approve a reviewed placement run before publishing.');
                }
                $pdo->prepare('UPDATE assignments SET is_published = 1 WHERE placement_run_id = ?')->execute([$run['id']]);
                $pdo->prepare("UPDATE placement_runs SET status = 'published', published_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$run['id']]);
                $pdo->commit();
                sdss_audit($pdo, 'placement.published', 'placement_run', (int) $run['id']);
                $notice = 'Placement results are now published.';
            } elseif ($action === 'save_preferences' && $role === 'student' && $currentStudent) {
                $year = $getCurrentAcademicYear();
                if (!$year || $year['status'] !== 'open') {
                    throw new RuntimeException('Preference selection is currently closed.');
                }
                if ((int) ($currentStudent['academic_year_id'] ?? 0) !== (int) $year['id']) {
                    throw new RuntimeException('Your account is not assigned to the current academic year.');
                }
                $openAt = $year['selection_opens_at'] ?? null;
                if ($openAt && strtotime($openAt) > time()) {
                    throw new RuntimeException('Preference selection has not opened yet.');
                }
                $closeAt = $year['selection_closes_at'] ?? null;
                if ($closeAt && strtotime($closeAt) < time()) {
                    throw new RuntimeException('The preference submission deadline has passed.');
                }
                if (!(int) $currentStudent['is_eligible']) {
                    throw new RuntimeException('Your account is not currently marked eligible to submit choices.');
                }
                $departmentIds = array_map('intval', $_POST['departments'] ?? []);
                $departmentIds = array_values(array_filter($departmentIds, static fn($id) => $id > 0));
                if (count($departmentIds) !== 3 || count(array_unique($departmentIds)) !== 3) {
                    throw new InvalidArgumentException('Select exactly three different departments in preference order.');
                }
                $available = $pdo->query('SELECT id FROM departments WHERE is_active = 1')->fetchAll(PDO::FETCH_COLUMN);
                if (array_diff($departmentIds, array_map('intval', $available))) {
                    throw new InvalidArgumentException('One or more selected departments are unavailable.');
                }
                $pdo->beginTransaction();
                $choice = $pdo->prepare('SELECT id FROM choices WHERE student_id = ?');
                $choice->execute([$currentStudent['id']]);
                if ($choice->fetchColumn() !== false) {
                    $pdo->prepare('UPDATE choices SET choice1 = ?, choice2 = ?, choice3 = ?, submitted_at = CURRENT_TIMESTAMP WHERE student_id = ?')
                        ->execute([$departmentIds[0], $departmentIds[1], $departmentIds[2], $currentStudent['id']]);
                } else {
                    $pdo->prepare('INSERT INTO choices (student_id, choice1, choice2, choice3, submitted_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)')
                        ->execute([$currentStudent['id'], $departmentIds[0], $departmentIds[1], $departmentIds[2]]);
                }
                $pdo->prepare('DELETE FROM department_preferences WHERE student_id = ?')->execute([$currentStudent['id']]);
                $preferenceInsert = $pdo->prepare('INSERT INTO department_preferences (student_id, department_id, priority) VALUES (?, ?, ?)');
                foreach ($departmentIds as $index => $departmentId) {
                    $preferenceInsert->execute([$currentStudent['id'], $departmentId, $index + 1]);
                }
                $pdo->commit();
                sdss_audit($pdo, 'student.preferences_submitted', 'student', (int) $currentStudent['id']);
                $notice = 'Your preferences were submitted and timestamped.';
            } elseif ($action === 'submit_appeal' && $role === 'student' && $currentStudent) {
                $category = trim((string) ($_POST['category'] ?? ''));
                $message = trim((string) ($_POST['message'] ?? ''));
                if (!in_array($category, ['eligibility', 'gpa', 'placement', 'other'], true) || strlen($message) < 10) {
                    throw new InvalidArgumentException('Select an appeal category and provide at least 10 characters of detail.');
                }
                $year = $getCurrentAcademicYear();
                if (!$year || empty($year['appeals_close_at']) || strtotime($year['appeals_close_at']) < time()) {
                    throw new RuntimeException('The appeal period is not currently open.');
                }
                $assignment = $pdo->prepare('SELECT id FROM assignments WHERE student_id = ? AND is_published = 1');
                $assignment->execute([$currentStudent['id']]);
                $assignmentId = $assignment->fetchColumn();
                $pdo->prepare('INSERT INTO appeals (student_id, assignment_id, category, message) VALUES (?, ?, ?, ?)')
                    ->execute([$currentStudent['id'], $assignmentId ?: null, $category, $message]);
                sdss_audit($pdo, 'appeal.submitted', 'student', (int) $currentStudent['id']);
                $notice = 'Your appeal has been submitted.';
            } elseif ($action === 'review_appeal' && in_array($role, ['coordinator', 'registrar', 'college_dean'], true)) {
                $appealId = filter_var($_POST['appeal_id'] ?? null, FILTER_VALIDATE_INT);
                $decision = (string) ($_POST['decision'] ?? '');
                $note = trim((string) ($_POST['decision_note'] ?? ''));
                if (!$appealId || !in_array($decision, ['under_review', 'forwarded', 'resolved', 'rejected'], true) || $note === '') {
                    throw new InvalidArgumentException('Choose an appeal status and provide a decision note.');
                }
                $appealSql = 'UPDATE appeals SET status = ?, reviewed_by = ?, decision_note = ?, reviewed_at = CURRENT_TIMESTAMP WHERE id = ?';
                $appealParams = [$decision, $currentUserId, $note, $appealId];
                if ($role === 'college_dean') {
                    $appealSql .= ' AND student_id IN (SELECT s.id FROM students s WHERE s.college_id IN (SELECT id FROM colleges WHERE dean_user_id = ?))';
                    $appealParams[] = $currentUserId;
                }
                $appealUpdate = $pdo->prepare($appealSql);
                $appealUpdate->execute($appealParams);
                if ($appealUpdate->rowCount() !== 1) {
                    throw new RuntimeException('Appeal not found or outside your college access.');
                }
                sdss_audit($pdo, 'appeal.' . $decision, 'appeal', $appealId);
                $notice = 'Appeal status updated.';
            } elseif ($action === 'create_announcement' && in_array($role, ['admin', 'department_head', 'coordinator'], true)) {
                $title = trim((string) ($_POST['title'] ?? ''));
                $body = trim((string) ($_POST['body'] ?? ''));
                if ($title === '' || $body === '') {
                    throw new InvalidArgumentException('Announcement title and message are required.');
                }
                $departmentId = null;
                if ($role === 'department_head') {
                    $departmentQuery = $pdo->prepare('SELECT id FROM departments WHERE head_user_id = ? LIMIT 1');
                    $departmentQuery->execute([$currentUserId]);
                    $departmentId = $departmentQuery->fetchColumn();
                    if ($departmentId === false) {
                        throw new RuntimeException('No department is assigned to your account.');
                    }
                }
                $pdo->prepare('INSERT INTO announcements (created_by, department_id, title, body, audience) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$currentUserId, $departmentId ?: null, $title, $body, $departmentId ? 'department' : 'all']);
                sdss_audit($pdo, 'announcement.created', 'announcement', (int) $pdo->lastInsertId());
                $notice = 'Announcement published.';
            } elseif ($action === 'send_reminder' && $role === 'coordinator') {
                $title = trim((string) ($_POST['title'] ?? ''));
                $message = trim((string) ($_POST['message'] ?? ''));
                if ($title === '' || $message === '') {
                    throw new InvalidArgumentException('Reminder title and message are required.');
                }
                $recipients = $pdo->query(
                    "SELECT u.id FROM users u JOIN students s ON s.user_id = u.id LEFT JOIN choices c ON c.student_id = s.id WHERE c.id IS NULL AND u.is_active = 1"
                )->fetchAll(PDO::FETCH_COLUMN);
                $insertNotification = $pdo->prepare('INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)');
                foreach ($recipients as $recipientId) {
                    $insertNotification->execute([$recipientId, $title, $message]);
                }
                sdss_audit($pdo, 'coordinator.reminder_sent', 'notification', null, 'Recipients: ' . count($recipients));
                $notice = 'Reminder sent to ' . count($recipients) . ' students without submitted choices.';
            } elseif ($action === 'save_department_criteria' && $role === 'department_head') {
                $departmentId = filter_var($_POST['department_id'] ?? null, FILTER_VALIDATE_INT);
                $minimumGpa = filter_var($_POST['eligibility_gpa'] ?? null, FILTER_VALIDATE_FLOAT);
                $criteria = trim((string) ($_POST['criteria'] ?? ''));
                if (!$departmentId || $minimumGpa === false || $minimumGpa < 0 || $minimumGpa > 4 || $criteria === '') {
                    throw new InvalidArgumentException('Select your department, provide minimum GPA from 0 to 4, and explain the criteria.');
                }
                $owned = $pdo->prepare('SELECT id, capacity FROM departments WHERE id = ? AND head_user_id = ?');
                $owned->execute([$departmentId, $currentUserId]);
                $department = $owned->fetch();
                if (!$department) {
                    throw new RuntimeException('You can only propose criteria for your assigned department.');
                }
                $pdo->prepare('INSERT INTO capacity_proposals (department_id, proposed_by, proposed_capacity, proposed_eligibility_gpa, criteria) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$departmentId, $currentUserId, $department['capacity'], $minimumGpa, $criteria]);
                sdss_audit($pdo, 'department.criteria_proposed', 'department', $departmentId);
                $notice = 'Eligibility criteria sent for approval.';
            } elseif ($action === 'save_setting' && $role === 'admin') {
            } elseif ($action === 'save_setting' && $role === 'admin') {
                $key = trim((string) ($_POST['setting_key'] ?? ''));
                $value = trim((string) ($_POST['setting_value'] ?? ''));
                if (!preg_match('/^[a-z][a-z0-9_]{1,99}$/', $key) || $value === '') {
                    throw new InvalidArgumentException('Provide a valid setting key and non-empty value.');
                }
                $setting = $pdo->prepare('SELECT setting_key FROM system_settings WHERE setting_key = ?');
                $setting->execute([$key]);
                if ($setting->fetchColumn() === false) {
                    $pdo->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)')->execute([$key, $value]);
                } else {
                    $pdo->prepare('UPDATE system_settings SET setting_value = ? WHERE setting_key = ?')->execute([$value, $key]);
                }
                sdss_audit($pdo, 'setting.updated', 'system_setting', null, $key);
                $notice = 'System setting saved.';
            } elseif ($action === 'mark_notification' && isset($menus[$role]['notifications'])) {
                $notificationId = filter_var($_POST['notification_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$notificationId) {
                    throw new InvalidArgumentException('Invalid notification.');
                }
                $pdo->prepare('UPDATE notifications SET read_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?')
                    ->execute([$notificationId, $currentUserId]);
                $notice = 'Notification marked as read.';
            } else {
                throw new RuntimeException('This action is not available for your role.');
            }
        } catch (InvalidArgumentException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $exception->getMessage();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('SDSS workflow database error: ' . $exception->getMessage());
            $error = 'The request could not be saved. Check for duplicate records or contact an administrator.';
        } catch (RuntimeException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $exception->getMessage();
        }
    }
}

$year = $getCurrentAcademicYear();
$collegeStmt = $pdo->prepare('SELECT id FROM colleges WHERE dean_user_id = ?');
$collegeStmt->execute([$currentUserId]);
$currentCollegeId = $collegeStmt->fetchColumn();
$departmentFilterSql = '';
$departmentFilterParams = [];
if ($role === 'college_dean') {
    $departmentFilterSql = ' WHERE college_id = ?';
    $departmentFilterParams[] = $currentCollegeId ?: 0;
} elseif ($role === 'department_head') {
    $departmentFilterSql = ' WHERE head_user_id = ?';
    $departmentFilterParams[] = $currentUserId;
}
$countQuery = static function (PDO $pdo, string $sql): int {
    return (int) $pdo->query($sql)->fetchColumn();
};
$stats = [
    'Students' => $countQuery($pdo, 'SELECT COUNT(*) FROM students'),
    'Eligible students' => $countQuery($pdo, 'SELECT COUNT(*) FROM students WHERE is_eligible = 1'),
    'Submitted choices' => $countQuery($pdo, 'SELECT COUNT(*) FROM choices'),
    'Pending choices' => $countQuery($pdo, 'SELECT COUNT(*) FROM students s LEFT JOIN choices c ON c.student_id = s.id WHERE c.id IS NULL'),
    'Departments' => $countQuery($pdo, 'SELECT COUNT(*) FROM departments WHERE is_active = 1'),
    'Total capacity' => $countQuery($pdo, 'SELECT COALESCE(SUM(capacity), 0) FROM departments WHERE is_active = 1'),
    'Published placements' => $countQuery($pdo, 'SELECT COUNT(*) FROM assignments WHERE is_published = 1'),
    'Pending appeals' => $countQuery($pdo, "SELECT COUNT(*) FROM appeals WHERE status IN ('submitted', 'under_review', 'forwarded')"),
];
if ($role === 'student' && $currentStudent) {
    $ownChoice = $pdo->prepare('SELECT COUNT(*) FROM choices WHERE student_id = ?');
    $ownChoice->execute([$currentStudent['id']]);
    $ownAssignment = $pdo->prepare('SELECT COUNT(*) FROM assignments WHERE student_id = ? AND is_published = 1');
    $ownAssignment->execute([$currentStudent['id']]);
    $ownAppeals = $pdo->prepare('SELECT COUNT(*) FROM appeals WHERE student_id = ?');
    $ownAppeals->execute([$currentStudent['id']]);
    $stats = [
        'My GPA' => number_format((float) $currentStudent['gpa'], 2),
        'Eligibility' => (int) $currentStudent['is_eligible'] ? 'Eligible' : 'Ineligible',
        'Choices submitted' => (int) $ownChoice->fetchColumn() ? 'Yes' : 'No',
        'Published placement' => (int) $ownAssignment->fetchColumn() ? 'Published' : 'Pending',
        'My appeals' => (int) $ownAppeals->fetchColumn(),
        'Available departments' => $countQuery($pdo, 'SELECT COUNT(*) FROM departments WHERE is_active = 1'),
    ];
} elseif ($role === 'college_dean') {
    $collegeStats = $pdo->prepare(
        'SELECT COUNT(DISTINCT s.id) AS students, COUNT(DISTINCT CASE WHEN c.id IS NOT NULL THEN s.id END) AS submitted, COUNT(DISTINCT d.id) AS departments, COALESCE(SUM(DISTINCT d.capacity), 0) AS capacity FROM colleges co LEFT JOIN departments d ON d.college_id = co.id LEFT JOIN students s ON s.college_id = co.id LEFT JOIN choices c ON c.student_id = s.id WHERE co.dean_user_id = ?'
    );
    $collegeStats->execute([$currentUserId]);
    $collegeRow = $collegeStats->fetch() ?: [];
    $stats = [
        'College students' => (int) ($collegeRow['students'] ?? 0),
        'Submitted choices' => (int) ($collegeRow['submitted'] ?? 0),
        'Departments' => (int) ($collegeRow['departments'] ?? 0),
        'Total capacity' => (int) ($collegeRow['capacity'] ?? 0),
    ];
}

$departmentsQuery = $pdo->prepare('SELECT id, dept_name, capacity, description, eligibility_gpa, is_active FROM departments' . $departmentFilterSql . ' ORDER BY dept_name');
$departmentsQuery->execute($departmentFilterParams);
$departments = $departmentsQuery->fetchAll();
$studentList = $pdo->query(
    'SELECT s.id, s.full_name, s.student_code, s.email, s.gpa, s.is_eligible, u.is_active FROM students s JOIN users u ON u.id = s.user_id ORDER BY s.gpa DESC, s.student_code'
)->fetchAll();
$proposalSql = 'SELECT cp.id, d.dept_name, cp.proposed_capacity, cp.proposed_eligibility_gpa, cp.criteria, cp.status, cp.created_at FROM capacity_proposals cp JOIN departments d ON d.id = cp.department_id';
$proposalParams = [];
if ($role === 'department_head') {
    $proposalSql .= ' WHERE d.head_user_id = ?';
    $proposalParams[] = $currentUserId;
} elseif ($role === 'college_dean') {
    $proposalSql .= ' WHERE d.college_id IN (SELECT id FROM colleges WHERE dean_user_id = ?)';
    $proposalParams[] = $currentUserId;
}
$proposalSql .= ' ORDER BY cp.created_at DESC';
$proposalQuery = $pdo->prepare($proposalSql);
$proposalQuery->execute($proposalParams);
$proposals = $proposalQuery->fetchAll();
$appealSql = 'SELECT a.id, s.full_name, s.student_code, a.category, a.message, a.status, a.submitted_at, a.decision_note FROM appeals a JOIN students s ON s.id = a.student_id';
$appealParams = [];
if ($role === 'college_dean') {
    $appealSql .= ' WHERE s.college_id IN (SELECT id FROM colleges WHERE dean_user_id = ?)';
    $appealParams[] = $currentUserId;
} elseif ($role === 'student') {
    $appealSql .= ' WHERE s.user_id = ?';
    $appealParams[] = $currentUserId;
}
$appealSql .= ' ORDER BY a.submitted_at DESC';
$appealQuery = $pdo->prepare($appealSql);
$appealQuery->execute($appealParams);
$appeals = $appealQuery->fetchAll();
if ($role === 'department_head') {
    $announcementQuery = $pdo->prepare(
        'SELECT title, body, audience, created_at FROM announcements WHERE department_id IS NULL OR department_id IN (SELECT id FROM departments WHERE head_user_id = ?) ORDER BY created_at DESC LIMIT 50'
    );
    $announcementQuery->execute([$currentUserId]);
    $announcementList = $announcementQuery->fetchAll();
} else {
    $announcementList = $pdo->query('SELECT title, body, audience, created_at FROM announcements ORDER BY created_at DESC LIMIT 50')->fetchAll();
}
$assignmentSql = 'SELECT s.full_name, s.student_code, s.gpa, d.dept_name, a.is_published, a.assigned_at FROM assignments a JOIN students s ON s.id = a.student_id JOIN departments d ON d.id = a.department_id';
$assignmentWhere = [];
$assignmentParams = [];
if ($role === 'student') {
    $assignmentWhere[] = 'a.is_published = 1 AND s.user_id = ?';
    $assignmentParams[] = $currentUserId;
} elseif ($role === 'department_head') {
    $assignmentWhere[] = 'a.is_published = 1 AND d.head_user_id = ?';
    $assignmentParams[] = $currentUserId;
} elseif ($role === 'college_dean') {
    $assignmentWhere[] = 'a.is_published = 1 AND d.college_id IN (SELECT id FROM colleges WHERE dean_user_id = ?)';
    $assignmentParams[] = $currentUserId;
} elseif (!in_array($role, ['admin', 'registrar'], true)) {
    $assignmentWhere[] = 'a.is_published = 1';
} elseif (!in_array($view, ['review', 'publish'], true)) {
    $assignmentWhere[] = 'a.is_published = 1';
}
if ($assignmentWhere) {
    $assignmentSql .= ' WHERE ' . implode(' AND ', $assignmentWhere);
}
$assignmentSql .= ' ORDER BY s.gpa DESC';
$assignmentQuery = $pdo->prepare($assignmentSql);
$assignmentQuery->execute($assignmentParams);
$assignmentRows = $assignmentQuery->fetchAll();

if (($_GET['export'] ?? '') === 'placements') {
    if (!in_array($role, ['admin', 'registrar', 'department_head', 'college_dean', 'coordinator'], true)) {
        http_response_code(403);
        exit('You are not allowed to export placement reports.');
    }
    if (in_array($role, ['admin', 'registrar'], true)) {
        $publishedRows = $pdo->query(
            'SELECT s.full_name, s.student_code, s.gpa, d.dept_name, a.assigned_at FROM assignments a JOIN students s ON s.id = a.student_id JOIN departments d ON d.id = a.department_id WHERE a.is_published = 1 ORDER BY s.gpa DESC'
        )->fetchAll();
    } else {
        $publishedRows = $assignmentRows;
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sdss-placement-report.csv"');
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        http_response_code(500);
        exit('Unable to create the placement report.');
    }
    fputcsv($output, ['Student name', 'Student code', 'GPA', 'Department', 'Assigned at']);
    $csvValue = static function ($value): string {
        $value = (string) $value;
        return preg_match('/^[\t\r\n ]*[=+\-@]/', $value) ? "'" . $value : $value;
    };
    foreach ($publishedRows as $row) {
        fputcsv($output, [
            $csvValue($row['full_name']),
            $csvValue($row['student_code']),
            $csvValue($row['gpa']),
            $csvValue($row['dept_name']),
            $csvValue($row['assigned_at']),
        ]);
    }
    fclose($output);
    exit;
}

$viewTitle = $menus[$role][$view];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo sdss_escape($viewTitle); ?> - SDSS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../frontend/assets/style.css">
</head>
<body class="bg-slate-100 text-slate-800 min-h-screen">
    <div class="min-h-screen md:flex">
        <aside class="bg-slate-900 text-slate-200 md:w-64 md:min-h-screen p-5">
            <a href="workflow.php" class="text-white font-bold text-xl">SDSS Portal</a>
            <p class="text-xs text-slate-400 mt-2"><?php echo sdss_escape(ucwords(str_replace('_', ' ', $role))); ?></p>
            <nav class="mt-8 space-y-1" aria-label="Main navigation">
                <?php foreach ($menus[$role] as $key => $label): ?>
                    <a href="workflow.php?view=<?php echo rawurlencode($key); ?>" class="block rounded-lg px-3 py-2 text-sm <?php echo $view === $key ? 'bg-indigo-600 text-white' : 'text-slate-300 hover:bg-slate-800 hover:text-white'; ?>">
                        <?php echo sdss_escape($label); ?>
                    </a>
                <?php endforeach; ?>
                <a href="../frontend/pages/home.html" class="block rounded-lg px-3 py-2 text-sm text-slate-300 hover:bg-slate-800 hover:text-white">Public home</a>
                <a href="logout.php" class="block rounded-lg px-3 py-2 text-sm text-slate-300 hover:bg-slate-800 hover:text-white">Logout</a>
            </nav>
        </aside>

        <div class="flex-1 min-w-0">
            <header class="bg-white border-b border-slate-200 px-6 py-4 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-xs text-slate-400">SDSS / <?php echo sdss_escape($viewTitle); ?></p>
                    <h1 class="text-xl font-bold text-slate-900"><?php echo sdss_escape($viewTitle); ?></h1>
                </div>
                <div class="text-right text-sm">
                    <p class="font-semibold"><?php echo sdss_escape($_SESSION['full_name'] ?? 'User'); ?></p>
                    <p class="text-slate-500"><?php echo sdss_escape($year['name'] ?? 'No academic year'); ?></p>
                </div>
            </header>

            <main class="p-6 max-w-7xl mx-auto">
                <?php if ($notice !== ''): ?>
                    <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 p-4 rounded-xl mb-5" role="status"><?php echo sdss_escape($notice); ?></div>
                <?php endif; ?>
                <?php if ($error !== ''): ?>
                    <div class="bg-rose-50 border border-rose-200 text-rose-800 p-4 rounded-xl mb-5" role="alert"><?php echo sdss_escape($error); ?></div>
                <?php endif; ?>

                <?php if ($view === 'dashboard'): ?>
                    <div class="grid sm:grid-cols-2 xl:grid-cols-4 gap-4">
                        <?php foreach ($stats as $label => $value): ?>
                            <section class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm">
                                <p class="text-sm text-slate-500"><?php echo sdss_escape($label); ?></p>
                                <p class="text-3xl font-bold mt-2 text-slate-900"><?php echo is_numeric($value) ? number_format((float) $value, strpos((string) $value, '.') !== false ? 2 : 0) : sdss_escape($value); ?></p>
                            </section>
                        <?php endforeach; ?>
                    </div>
                    <section class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm mt-6">
                        <h2 class="font-bold text-lg">Current placement cycle</h2>
                        <p class="text-sm text-slate-600 mt-2">Academic year: <?php echo sdss_escape($year['name'] ?? 'Not configured'); ?>.
                            Submission status: <?php echo sdss_escape($year['status'] ?? 'not configured'); ?>.
                        </p>
                        <?php if ($role === 'registrar'): ?>
                            <a class="inline-block mt-4 bg-indigo-600 text-white px-4 py-2 rounded-lg" href="workflow.php?view=run">Open placement runner</a>
                        <?php elseif ($role === 'student'): ?>
                            <a class="inline-block mt-4 bg-indigo-600 text-white px-4 py-2 rounded-lg" href="workflow.php?view=choices">Submit preferences</a>
                        <?php endif; ?>
                    </section>
                <?php elseif ($view === 'users'): ?>
                    <div class="grid lg:grid-cols-2 gap-6">
                        <section class="bg-white rounded-xl p-5 border border-slate-200">
                            <h2 class="font-bold mb-4">Create account</h2>
                            <form method="post" class="space-y-3">
                                <input type="hidden" name="csrf_token" value="<?php echo sdss_escape(sdss_csrf_token()); ?>">
                                <input type="hidden" name="action" value="create_user">
                                <label class="block text-sm">Full name<input name="full_name" required maxlength="255" class="mt-1 w-full border rounded-lg p-2"></label>
                                <label class="block text-sm">Username<input name="username" required maxlength="255" class="mt-1 w-full border rounded-lg p-2"></label>
                                <label class="block text-sm">Initial password<input name="password" type="password" required minlength="8" class="mt-1 w-full border rounded-lg p-2"></label>
                                <label class="block text-sm">Role<select name="role" required class="mt-1 w-full border rounded-lg p-2">
                                    <?php foreach ($menus as $key => $menu): ?><option value="<?php echo sdss_escape($key); ?>"><?php echo sdss_escape(ucwords(str_replace('_', ' ', $key))); ?></option><?php endforeach; ?>
                                </select></label>
                                <div class="grid grid-cols-2 gap-3">
                                    <label class="block text-sm">Student code (student only)<input name="student_code" class="mt-1 w-full border rounded-lg p-2"></label>
                                    <label class="block text-sm">GPA (student only)<input name="gpa" type="number" min="0" max="4" step="0.01" class="mt-1 w-full border rounded-lg p-2"></label>
                                </div>
                                <button class="bg-indigo-600 text-white rounded-lg px-4 py-2">Create account</button>
                            </form>
                        </section>
                        <section class="bg-white rounded-xl p-5 border border-slate-200 overflow-x-auto">
                            <h2 class="font-bold mb-4">Accounts and role definitions</h2>
                            <?php $users = $pdo->query('SELECT id, full_name, email, role, is_active FROM users ORDER BY full_name')->fetchAll(); ?>
                            <table class="w-full text-sm">
                                <thead><tr class="text-left border-b"><th class="p-2">Name</th><th class="p-2">Username</th><th class="p-2">Role</th><th class="p-2">Status</th><th></th></tr></thead>
                                <tbody><?php foreach ($users as $user): ?><tr class="border-b">
                                    <td class="p-2"><?php echo sdss_escape($user['full_name']); ?></td><td class="p-2"><?php echo sdss_escape($user['email']); ?></td><td class="p-2"><?php echo sdss_escape($user['role']); ?></td><td class="p-2"><?php echo (int) $user['is_active'] ? 'Active' : 'Inactive'; ?></td>
                                    <td class="p-2"><form method="post"><input type="hidden" name="csrf_token" value="<?php echo sdss_escape(sdss_csrf_token()); ?>"><input type="hidden" name="action" value="toggle_user"><input type="hidden" name="user_id" value="<?php echo (int) $user['id']; ?>"><button class="text-indigo-700 underline" <?php echo (int) $user['id'] === $currentUserId ? 'disabled' : ''; ?>>Toggle</button></form></td>
                                </tr><?php endforeach; ?></tbody>
                            </table>
                        </section>
                    </div>
                <?php elseif ($view === 'roles'): ?>
                    <section class="bg-white rounded-xl p-5 border border-slate-200 overflow-x-auto">
                        <h2 class="font-bold mb-4">Role permissions</h2>
                        <table class="w-full text-sm"><thead><tr class="text-left border-b"><th class="p-2">Role</th><th class="p-2">Permission</th></tr></thead><tbody>
                            <?php foreach ($pdo->query('SELECT role_slug, permission FROM role_permissions ORDER BY role_slug, permission')->fetchAll() as $permission): ?>
                                <tr class="border-b"><td class="p-2"><?php echo sdss_escape($permission['role_slug']); ?></td><td class="p-2"><?php echo sdss_escape($permission['permission']); ?></td></tr>
                            <?php endforeach; ?>
                        </tbody></table>
                    </section>
                <?php elseif ($view === 'academic'): ?>
                    <div class="grid lg:grid-cols-2 gap-6">
                        <form method="post" class="bg-white rounded-xl p-5 border border-slate-200 space-y-3">
                            <h2 class="font-bold">Create/open academic year</h2>
                            <input type="hidden" name="csrf_token" value="<?php echo sdss_escape(sdss_csrf_token()); ?>"><input type="hidden" name="action" value="create_academic_year">
                            <label class="block text-sm">Year label<input name="name" required placeholder="2026/2027" class="mt-1 w-full border rounded-lg p-2"></label>
                            <label class="block text-sm">Selection opens<input name="selection_opens_at" type="datetime-local" class="mt-1 w-full border rounded-lg p-2"></label>
                            <label class="block text-sm">Selection closes<input name="selection_closes_at" type="datetime-local" class="mt-1 w-full border rounded-lg p-2"></label>
                            <label class="block text-sm">Appeals close<input name="appeals_close_at" type="datetime-local" class="mt-1 w-full border rounded-lg p-2"></label>
                            <button class="bg-indigo-600 text-white rounded-lg px-4 py-2">Set current year</button>
                        </form>
                        <section class="bg-white rounded-xl p-5 border border-slate-200">
                            <h2 class="font-bold mb-3">Academic years</h2>
                            <?php foreach ($pdo->query('SELECT * FROM academic_years ORDER BY id DESC')->fetchAll() as $academicYear): ?>
                                <p class="border-b py-3"><?php echo sdss_escape($academicYear['name']); ?> — <?php echo sdss_escape($academicYear['status']); ?><?php echo (int) $academicYear['is_current'] ? ' (current)' : ''; ?></p>
                            <?php endforeach; ?>
                        </section>
                    </div>
                <?php elseif ($view === 'students' || $view === 'gpa' || $view === 'eligibility'): ?>
                    <section class="bg-white rounded-xl p-5 border border-slate-200 overflow-x-auto">
                        <h2 class="font-bold mb-4">Student records</h2>
                        <table class="w-full text-sm"><thead><tr class="text-left border-b"><th class="p-2">Student</th><th class="p-2">Code</th><th class="p-2">Username</th><th class="p-2">GPA</th><th class="p-2">Eligible</th><?php if ($view === 'gpa' && $role === 'registrar'): ?><th class="p-2">Update GPA</th><?php endif; ?><?php if ($view === 'eligibility' && $role === 'registrar'): ?><th class="p-2">Eligibility</th><?php endif; ?></tr></thead><tbody>
                        <?php foreach ($studentList as $studentRow): ?><tr class="border-b">
                            <td class="p-2"><?php echo sdss_escape($studentRow['full_name']); ?></td><td class="p-2"><?php echo sdss_escape($studentRow['student_code']); ?></td><td class="p-2"><?php echo sdss_escape($studentRow['email']); ?></td><td class="p-2"><?php echo number_format((float) $studentRow['gpa'], 2); ?></td><td class="p-2"><?php echo (int) $studentRow['is_eligible'] ? 'Yes' : 'No'; ?></td>
                            <?php if ($view === 'gpa' && $role === 'registrar'): ?><td class="p-2"><form method="post" class="flex gap-2"><input type="hidden" name="csrf_token" value="<?php echo sdss_escape(sdss_csrf_token()); ?>"><input type="hidden" name="action" value="update_gpa"><input type="hidden" name="student_id" value="<?php echo (int) $studentRow['id']; ?>"><input type="number" name="gpa" min="0" max="4" step="0.01" value="<?php echo sdss_escape($studentRow['gpa']); ?>" class="w-20 border rounded p-1"><button class="text-indigo-700 underline">Save</button></form></td><?php endif; ?>
                            <?php if ($view === 'eligibility' && $role === 'registrar'): ?><td class="p-2"><form method="post" class="flex gap-2"><input type="hidden" name="csrf_token" value="<?php echo sdss_escape(sdss_csrf_token()); ?>"><input type="hidden" name="action" value="update_eligibility"><input type="hidden" name="student_id" value="<?php echo (int) $studentRow['id']; ?>"><select name="is_eligible" class="border rounded p-1"><option value="1" <?php echo (int) $studentRow['is_eligible'] ? 'selected' : ''; ?>>Eligible</option><option value="0" <?php echo !(int) $studentRow['is_eligible'] ? 'selected' : ''; ?>>Ineligible</option></select><button class="text-indigo-700 underline">Save</button></form></td><?php endif; ?>
                        </tr><?php endforeach; ?>
                        </tbody></table>
                    </section>
                <?php elseif ($view === 'import'): ?>
                    <section class="bg-white rounded-xl p-5 border border-slate-200 max-w-2xl">
                        <h2 class="font-bold mb-3">Import student records (CSV)</h2>
                        <p class="text-sm text-slate-600 mb-4">Use exact headers: <code>student_code,full_name,username,gpa</code>. Student login accounts must already exist.</p>
                        <form method="post" enctype="multipart/form-data" class="space-y-3">
                            <input type="hidden" name="csrf_token" value="<?php echo sdss_escape(sdss_csrf_token()); ?>"><input type="hidden" name="action" value="import_csv">
                            <input type="file" name="student_csv" accept=".csv,text/csv" required class="block w-full border rounded-lg p-2">
                            <button class="bg-indigo-600 text-white rounded-lg px-4 py-2">Validate and import CSV</button>
                        </form>
                    </section>
                <?php elseif ($view === 'choices' && $role === 'student'): ?>
                    <?php
                    $submitted = null;
                    if ($currentStudent) {
                        $submittedStmt = $pdo->prepare('SELECT * FROM choices WHERE student_id = ?');
                        $submittedStmt->execute([$currentStudent['id']]);
                        $submitted = $submittedStmt->fetch() ?: null;
                    }
                    ?>
                    <div class="grid lg:grid-cols-2 gap-6">
                        <form method="post" class="bg-white rounded-xl p-5 border border-slate-200 space-y-3">
                            <h2 class="font-bold">Choose three distinct departments</h2>
                            <p class="text-sm text-slate-500">The order of the selections is the preference priority.</p>
                            <input type="hidden" name="csrf_token" value="<?php echo sdss_escape(sdss_csrf_token()); ?>"><input type="hidden" name="action" value="save_preferences">
                            <?php for ($priority = 1; $priority <= 3; $priority++): ?>
                                <label class="block text-sm"><?php echo $priority; ?><?php echo $priority === 1 ? 'st' : ($priority === 2 ? 'nd' : 'rd'); ?> preference
                                    <select name="departments[]" required class="mt-1 w-full border rounded-lg p-2">
                                        <option value="">Select department</option>
                                        <?php foreach ($departments as $department): ?>
                                            <?php $selected = $submitted && (int) $submitted['choice' . $priority] === (int) $department['id']; ?>
                                            <option value="<?php echo (int) $department['id']; ?>" <?php echo $selected ? 'selected' : ''; ?>><?php echo sdss_escape($department['dept_name']); ?> (capacity <?php echo (int) $department['capacity']; ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            <?php endfor; ?>
                            <button class="bg-indigo-600 text-white rounded-lg px-4 py-2">Submit preferences</button>
                        </form>
                        <section class="bg-white rounded-xl p-5 border border-slate-200">
                            <h2 class="font-bold">Eligibility and selection period</h2>
                            <p class="mt-3">Eligibility: <?php echo $currentStudent && (int) $currentStudent['is_eligible'] ? 'Eligible' : 'Not eligible'; ?></p>
                            <p class="mt-2">Academic year: <?php echo sdss_escape($year['name'] ?? 'Not configured'); ?> (<?php echo sdss_escape($year['status'] ?? 'closed'); ?>)</p>
                            <p class="mt-2 text-sm text-slate-500">Preference records cannot change official GPA or placement decisions.</p>
                        </section>
                    </div>
                <?php elseif ($view === 'submitted' && $role === 'student'): ?>
                    <section class="bg-white rounded-xl p-5 border border-slate-200">
                        <h2 class="font-bold mb-3">Submitted preference list</h2>
                        <?php if (!$currentStudent): ?>
                            <p>No preferences submitted.</p>
                        <?php else: ?>
                            <?php $submitted = $pdo->prepare('SELECT c.*, d1.dept_name AS first_name, d2.dept_name AS second_name, d3.dept_name AS third_name FROM choices c JOIN departments d1 ON d1.id=c.choice1 JOIN departments d2 ON d2.id=c.choice2 JOIN departments d3 ON d3.id=c.choice3 WHERE c.student_id=?'); $submitted->execute([$currentStudent['id']]); $choiceRow = $submitted->fetch(); ?>
                            <?php if ($choiceRow): ?><ol class="list-decimal ml-5 space-y-2"><li><?php echo sdss_escape($choiceRow['first_name']); ?></li><li><?php echo sdss_escape($choiceRow['second_name']); ?></li><li><?php echo sdss_escape($choiceRow['third_name']); ?></li></ol><p class="text-sm text-slate-500 mt-4">Submitted: <?php echo sdss_escape($choiceRow['submitted_at'] ?? 'Not timestamped'); ?></p><?php else: ?><p>No preferences submitted.</p><?php endif; ?>
                        <?php endif; ?>
                    </section>
                <?php elseif (in_array($view, ['departments', 'department', 'college_departments'], true)): ?>
                    <section class="bg-white rounded-xl p-5 border border-slate-200 overflow-x-auto">
                        <h2 class="font-bold mb-4">Departments and capacity</h2>
                        <table class="w-full text-sm"><thead><tr class="text-left border-b"><th class="p-2">Department</th><th class="p-2">Description</th><th class="p-2">Capacity</th><th class="p-2">Minimum GPA</th><th class="p-2">Status</th></tr></thead><tbody>
                            <?php foreach ($departments as $department): ?><tr class="border-b"><td class="p-2"><?php echo sdss_escape($department['dept_name']); ?></td><td class="p-2"><?php echo sdss_escape($department['description'] ?? ''); ?></td><td class="p-2"><?php echo (int) $department['capacity']; ?></td><td class="p-2"><?php echo number_format((float) $department['eligibility_gpa'], 2); ?></td><td class="p-2"><?php echo (int) $department['is_active'] ? 'Open' : 'Closed'; ?></td></tr><?php endforeach; ?>
                        </tbody></table>
                    </section>
                <?php elseif ($view === 'capacity' && $role === 'department_head'): ?>
                    <?php $ownedDepartments = $pdo->prepare('SELECT id, dept_name FROM departments WHERE head_user_id = ?'); $ownedDepartments->execute([$currentUserId]); $ownedDepartmentRows = $ownedDepartments->fetchAll(); ?>
                    <section class="bg-white rounded-xl p-5 border border-slate-200 max-w-2xl">
                        <h2 class="font-bold mb-4">Propose capacity for your department</h2>
                        <form method="post" class="space-y-3"><input type="hidden" name="csrf_token" value="<?php echo sdss_escape(sdss_csrf_token()); ?>"><input type="hidden" name="action" value="propose_capacity">
                            <label class="block text-sm">Department<select name="department_id" required class="mt-1 w-full border rounded-lg p-2"><option value="">Select department</option><?php foreach ($ownedDepartmentRows as $department): ?><option value="<?php echo (int) $department['id']; ?>"><?php echo sdss_escape($department['dept_name']); ?></option><?php endforeach; ?></select></label>
                            <label class="block text-sm">Proposed capacity<input type="number" name="capacity" min="0" required class="mt-1 w-full border rounded-lg p-2"></label>
                            <label class="block text-sm">Minimum GPA<input type="number" name="eligibility_gpa" min="0" max="4" step="0.01" required class="mt-1 w-full border rounded-lg p-2"></label>
                            <label class="block text-sm">Eligibility / rationale<textarea name="criteria" rows="4" class="mt-1 w-full border rounded-lg p-2"></textarea></label>
                            <button class="bg-indigo-600 text-white rounded-lg px-4 py-2">Submit proposal</button>
                        </form>
                    </section>
                <?php elseif ($view === 'capacity_approval' || $view === 'placement_config'): ?>
                    <section class="bg-white rounded-xl p-5 border border-slate-200">
                        <?php if ($view === 'placement_config' && $role === 'registrar'): ?>
                            <h2 class="font-bold mb-4">Selection period configuration — <?php echo sdss_escape($year['name'] ?? 'No current academic year'); ?></h2>
                            <form method="post" class="grid md:grid-cols-2 gap-3 mb-6"><input type="hidden" name="csrf_token" value="<?php echo sdss_escape(sdss_csrf_token()); ?>"><input type="hidden" name="action" value="update_placement_settings">
                                <label class="block text-sm">Opens<input type="datetime-local" name="selection_opens_at" value="<?php echo sdss_escape(isset($year['selection_opens_at']) ? substr(str_replace(' ', 'T', $year['selection_opens_at']), 0, 16) : ''); ?>" class="mt-1 w-full border rounded-lg p-2"></label>
                                <label class="block text-sm">Closes<input type="datetime-local" name="selection_closes_at" value="<?php echo sdss_escape(isset($year['selection_closes_at']) ? substr(str_replace(' ', 'T', $year['selection_closes_at']), 0, 16) : ''); ?>" class="mt-1 w-full border rounded-lg p-2"></label>
                                <label class="block text-sm">Appeals close<input type="datetime-local" name="appeals_close_at" value="<?php echo sdss_escape(isset($year['appeals_close_at']) ? substr(str_replace(' ', 'T', $year['appeals_close_at']), 0, 16) : ''); ?>" class="mt-1 w-full border rounded-lg p-2"></label>
                                <label class="block text-sm">Selection status<select name="status" class="mt-1 w-full border rounded-lg p-2"><option value="open" <?php echo ($year['status'] ?? '') === 'open' ? 'selected' : ''; ?>>Open</option><option value="closed" <?php echo ($year['status'] ?? '') === 'closed' ? 'selected' : ''; ?>>Closed</option></select></label>
                                <button class="bg-indigo-600 text-white rounded-lg px-4 py-2 md:col-span-2">Save placement settings</button>
                            </form>
                        <?php endif; ?>
                        <h2 class="font-bold mb-4">Capacity proposals</h2>
                        <?php foreach ($proposals as $proposal): ?><article class="border-b py-4">
                            <p class="font-semibold"><?php echo sdss_escape($proposal['dept_name']); ?> — proposed capacity <?php echo (int) $proposal['proposed_capacity']; ?> · minimum GPA <?php echo isset($proposal['proposed_eligibility_gpa']) ? number_format((float) $proposal['proposed_eligibility_gpa'], 2) : 'unchanged'; ?></p><p class="text-sm text-slate-600"><?php echo sdss_escape($proposal['criteria'] ?? ''); ?> · <?php echo sdss_escape($proposal['status']); ?></p>
                            <?php if ($proposal['status'] === 'pending' && in_array($role, ['college_dean', 'registrar'], true)): ?>
                                <form method="post" class="mt-3 flex flex-wrap gap-2"><input type="hidden" name="csrf_token" value="<?php echo sdss_escape(sdss_csrf_token()); ?>"><input type="hidden" name="action" value="review_capacity"><input type="hidden" name="proposal_id" value="<?php echo (int) $proposal['id']; ?>"><input name="decision_note" required placeholder="Decision explanation" class="border rounded p-2 flex-1"><button name="decision" value="approved" class="bg-emerald-700 text-white rounded px-3 py-2">Approve</button><button name="decision" value="rejected" class="bg-rose-700 text-white rounded px-3 py-2">Reject</button></form>
                            <?php endif; ?>
                        </article><?php endforeach; ?>
                        <?php if (!$proposals): ?><p class="text-slate-500">No capacity proposals yet.</p><?php endif; ?>
                    </section>
                <?php elseif ($view === 'run' && $role === 'registrar'): ?>
                    <section class="bg-white rounded-xl p-5 border border-slate-200 max-w-2xl">
                        <h2 class="font-bold">Run merit-based draft placement</h2>
                        <p class="text-sm text-slate-600 mt-2">Eligible students are ranked by GPA and placed in preference order subject to department capacity and minimum GPA.</p>
                        <form method="post" class="mt-4"><input type="hidden" name="csrf_token" value="<?php echo sdss_escape(sdss_csrf_token()); ?>"><input type="hidden" name="action" value="run_placement"><button class="bg-indigo-600 text-white rounded-lg px-4 py-2">Run placement</button></form>
                    </section>
                <?php elseif (in_array($view, ['review', 'publish', 'assigned', 'placement_result', 'reports', 'analytics', 'department_reports', 'placement_reports'], true)): ?>
                    <section class="bg-white rounded-xl p-5 border border-slate-200 overflow-x-auto">
                        <div class="flex items-center justify-between gap-3 mb-4">
                            <h2 class="font-bold">Placement assignments</h2>
                            <?php if (in_array($role, ['admin', 'registrar', 'department_head', 'college_dean', 'coordinator'], true) && in_array($view, ['reports', 'analytics', 'department_reports', 'placement_reports'], true)): ?>
                                <a class="bg-indigo-600 text-white rounded-lg px-4 py-2 text-sm" href="workflow.php?export=placements">Export published CSV</a>
                            <?php endif; ?>
                        </div>
                        <?php if ($view === 'review' && $role === 'registrar'): ?><form method="post" class="mb-4"><input type="hidden" name="csrf_token" value="<?php echo sdss_escape(sdss_csrf_token()); ?>"><input type="hidden" name="action" value="approve_placement"><button class="bg-indigo-700 text-white rounded-lg px-4 py-2">Approve reviewed placement</button></form><?php endif; ?>
                        <?php if ($view === 'publish' && $role === 'registrar'): ?><form method="post" class="mb-4"><input type="hidden" name="csrf_token" value="<?php echo sdss_escape(sdss_csrf_token()); ?>"><input type="hidden" name="action" value="publish_placement"><button class="bg-emerald-700 text-white rounded-lg px-4 py-2">Publish approved placement</button></form><?php endif; ?>
                        <table class="w-full text-sm"><thead><tr class="text-left border-b"><th class="p-2">Student</th><th class="p-2">Code</th><th class="p-2">GPA</th><th class="p-2">Department</th><th class="p-2">Status</th></tr></thead><tbody>
                        <?php foreach ($assignmentRows as $assignment): ?><tr class="border-b"><td class="p-2"><?php echo sdss_escape($assignment['full_name']); ?></td><td class="p-2"><?php echo sdss_escape($assignment['student_code']); ?></td><td class="p-2"><?php echo number_format((float) $assignment['gpa'], 2); ?></td><td class="p-2"><?php echo sdss_escape($assignment['dept_name']); ?></td><td class="p-2"><?php echo (int) $assignment['is_published'] ? 'Published' : 'Draft'; ?></td></tr><?php endforeach; ?>
                        </tbody></table>
                        <?php if (!$assignmentRows): ?><p class="text-slate-500">No assignment records found.</p><?php endif; ?>
                    </section>
                <?php elseif (in_array($view, ['appeals', 'appeal_submit'], true)): ?>
                    <?php if ($view === 'appeal_submit' && $role === 'student'): ?>
                        <section class="bg-white rounded-xl p-5 border border-slate-200 max-w-2xl"><h2 class="font-bold mb-4">Submit an appeal</h2>
                            <form method="post" class="space-y-3"><input type="hidden" name="csrf_token" value="<?php echo sdss_escape(sdss_csrf_token()); ?>"><input type="hidden" name="action" value="submit_appeal">
                                <label class="block text-sm">Category<select name="category" required class="mt-1 w-full border rounded-lg p-2"><option value="">Select</option><option value="eligibility">Eligibility</option><option value="gpa">GPA record</option><option value="placement">Placement result</option><option value="other">Other</option></select></label>
                                <label class="block text-sm">Details<textarea name="message" required minlength="10" rows="5" class="mt-1 w-full border rounded-lg p-2"></textarea></label>
                                <button class="bg-indigo-600 text-white rounded-lg px-4 py-2">Submit appeal</button>
                            </form>
                        </section>
                        <section class="space-y-3 mt-5">
                            <h2 class="font-bold text-lg">My appeal status</h2>
                            <?php foreach ($appeals as $appeal): ?>
                                <article class="bg-white rounded-xl p-5 border border-slate-200">
                                    <p class="font-semibold"><?php echo sdss_escape(ucfirst($appeal['category'])); ?> · <?php echo sdss_escape($appeal['status']); ?></p>
                                    <p class="text-sm mt-2"><?php echo sdss_escape($appeal['message']); ?></p>
                                    <p class="text-xs text-slate-500 mt-2">Submitted: <?php echo sdss_escape($appeal['submitted_at']); ?></p>
                                    <?php if (!empty($appeal['decision_note'])): ?><p class="text-sm mt-2">Decision: <?php echo sdss_escape($appeal['decision_note']); ?></p><?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                            <?php if (!$appeals): ?><p class="bg-white rounded-xl p-5 border border-slate-200 text-slate-500">No appeals have been submitted.</p><?php endif; ?>
                        </section>
                    <?php else: ?>
                        <section class="space-y-4"><?php foreach ($appeals as $appeal): ?><article class="bg-white rounded-xl p-5 border border-slate-200">
                            <h2 class="font-bold"><?php echo sdss_escape($appeal['full_name']); ?> (<?php echo sdss_escape($appeal['student_code']); ?>) — <?php echo sdss_escape($appeal['category']); ?></h2><p class="text-sm mt-2"><?php echo sdss_escape($appeal['message']); ?></p><p class="text-xs text-slate-500 mt-2">Status: <?php echo sdss_escape($appeal['status']); ?> · <?php echo sdss_escape($appeal['submitted_at']); ?></p>
                            <?php if (in_array($role, ['coordinator', 'registrar', 'college_dean'], true)): ?><form method="post" class="mt-3 flex flex-wrap gap-2"><input type="hidden" name="csrf_token" value="<?php echo sdss_escape(sdss_csrf_token()); ?>"><input type="hidden" name="action" value="review_appeal"><input type="hidden" name="appeal_id" value="<?php echo (int) $appeal['id']; ?>"><select name="decision" class="border rounded p-2"><option value="under_review">Under review</option><option value="forwarded">Forward</option><option value="resolved">Resolve</option><option value="rejected">Reject</option></select><input name="decision_note" required placeholder="Decision explanation" class="border rounded p-2 flex-1"><button class="bg-indigo-600 text-white rounded px-3 py-2">Save decision</button></form><?php endif; ?>
                        </article><?php endforeach; ?><?php if (!$appeals): ?><p class="bg-white rounded-xl p-5 border border-slate-200 text-slate-500">No appeals found.</p><?php endif; ?></section>
                    <?php endif; ?>
                <?php elseif ($view === 'announcements'): ?>
                    <?php if (in_array($role, ['admin', 'department_head', 'coordinator'], true)): ?><section class="bg-white rounded-xl p-5 border border-slate-200 mb-5 max-w-2xl"><h2 class="font-bold mb-3">Publish announcement</h2><form method="post" class="space-y-3"><input type="hidden" name="csrf_token" value="<?php echo sdss_escape(sdss_csrf_token()); ?>"><input type="hidden" name="action" value="create_announcement"><input name="title" required placeholder="Title" class="w-full border rounded-lg p-2"><textarea name="body" required rows="4" placeholder="Announcement" class="w-full border rounded-lg p-2"></textarea><button class="bg-indigo-600 text-white rounded-lg px-4 py-2">Publish</button></form></section><?php endif; ?>
                    <section class="space-y-3"><?php foreach ($announcementList as $announcement): ?><article class="bg-white rounded-xl p-5 border border-slate-200"><h2 class="font-bold"><?php echo sdss_escape($announcement['title']); ?></h2><p class="text-sm mt-2"><?php echo sdss_escape($announcement['body']); ?></p></article><?php endforeach; ?></section>
                <?php elseif ($view === 'notifications'): ?>
                    <?php $notificationQuery = $pdo->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC'); $notificationQuery->execute([$currentUserId]); ?>
                    <section class="space-y-3"><?php foreach ($notificationQuery->fetchAll() as $notification): ?><article class="bg-white rounded-xl p-5 border border-slate-200"><h2 class="font-bold"><?php echo sdss_escape($notification['title']); ?></h2><p class="mt-2 text-sm"><?php echo sdss_escape($notification['message']); ?></p><p class="text-xs text-slate-500 mt-2"><?php echo sdss_escape($notification['created_at']); ?> · <?php echo $notification['read_at'] ? 'Read' : 'Unread'; ?></p><?php if (!$notification['read_at']): ?><form method="post" class="mt-3"><input type="hidden" name="csrf_token" value="<?php echo sdss_escape(sdss_csrf_token()); ?>"><input type="hidden" name="action" value="mark_notification"><input type="hidden" name="notification_id" value="<?php echo (int) $notification['id']; ?>"><button class="text-indigo-700 underline">Mark read</button></form><?php endif; ?></article><?php endforeach; ?></section>
                <?php elseif (in_array($view, ['follow_up', 'choices_monitor', 'applicants'], true)): ?>
                    <?php if ($view === 'follow_up' && $role === 'coordinator'): ?><section class="bg-white rounded-xl p-5 border border-slate-200 mb-5 max-w-2xl"><h2 class="font-bold mb-3">Send pending-choice reminder</h2><form method="post" class="space-y-3"><input type="hidden" name="csrf_token" value="<?php echo sdss_escape(sdss_csrf_token()); ?>"><input type="hidden" name="action" value="send_reminder"><input name="title" required placeholder="Reminder title" class="w-full border rounded-lg p-2"><textarea name="message" required rows="3" placeholder="Reminder message" class="w-full border rounded-lg p-2"></textarea><button class="bg-indigo-600 text-white rounded-lg px-4 py-2">Send to pending students</button></form></section><?php endif; ?>
                    <section class="bg-white rounded-xl p-5 border border-slate-200 overflow-x-auto"><h2 class="font-bold mb-4"><?php echo $view === 'applicants' ? 'Applicants and preference status' : 'Preference submission monitoring'; ?></h2><table class="w-full text-sm"><thead><tr class="text-left border-b"><th class="p-2">Student</th><th class="p-2">Student code</th><th class="p-2">GPA</th><th class="p-2">Submitted</th></tr></thead><tbody><?php
                    if ($view === 'applicants' && $role === 'department_head') {
                        $monitorQuery = $pdo->prepare('SELECT DISTINCT s.full_name, s.student_code, s.gpa, c.submitted_at FROM students s JOIN choices c ON c.student_id = s.id JOIN departments d ON d.id IN (c.choice1, c.choice2, c.choice3) WHERE d.head_user_id = ? ORDER BY s.gpa DESC');
                        $monitorQuery->execute([$currentUserId]);
                        $monitor = $monitorQuery->fetchAll();
                    } else {
                        $monitor = $pdo->query('SELECT s.full_name, s.student_code, s.gpa, c.submitted_at FROM students s LEFT JOIN choices c ON c.student_id=s.id ORDER BY c.submitted_at IS NULL DESC, s.gpa DESC')->fetchAll();
                    }
                    foreach ($monitor as $studentRow): ?><tr class="border-b"><td class="p-2"><?php echo sdss_escape($studentRow['full_name']); ?></td><td class="p-2"><?php echo sdss_escape($studentRow['student_code']); ?></td><td class="p-2"><?php echo number_format((float) $studentRow['gpa'], 2); ?></td><td class="p-2"><?php echo sdss_escape($studentRow['submitted_at'] ?: 'Pending'); ?></td></tr><?php endforeach; ?>
                    </tbody></table></section>
                <?php elseif ($view === 'profile' && $role === 'student'): ?>
                    <section class="bg-white rounded-xl p-5 border border-slate-200"><h2 class="font-bold">My profile</h2><?php if ($currentStudent): ?><p class="mt-3">Name: <?php echo sdss_escape($currentStudent['full_name']); ?></p><p class="mt-2">Student ID: <?php echo sdss_escape($currentStudent['student_code']); ?></p><p class="mt-2">Verified GPA: <?php echo number_format((float) $currentStudent['gpa'], 2); ?></p><p class="mt-2">Eligibility: <?php echo (int) $currentStudent['is_eligible'] ? 'Eligible' : 'Ineligible'; ?></p><?php endif; ?></section>
                <?php elseif ($view === 'transcript' && $role === 'student'): ?>
                    <?php $transcript = $pdo->prepare('SELECT course_code, course_name, credits, grade, grade_points FROM student_transcripts WHERE student_id = ? ORDER BY course_code'); $transcript->execute([$currentStudent['id'] ?? 0]); ?>
                    <section class="bg-white rounded-xl p-5 border border-slate-200"><h2 class="font-bold mb-3">My transcript</h2><table class="w-full text-sm"><thead><tr class="text-left border-b"><th class="p-2">Course</th><th class="p-2">Name</th><th class="p-2">Credits</th><th class="p-2">Grade</th><th class="p-2">Points</th></tr></thead><tbody><?php foreach ($transcript->fetchAll() as $course): ?><tr class="border-b"><td class="p-2"><?php echo sdss_escape($course['course_code']); ?></td><td class="p-2"><?php echo sdss_escape($course['course_name']); ?></td><td class="p-2"><?php echo sdss_escape($course['credits']); ?></td><td class="p-2"><?php echo sdss_escape($course['grade']); ?></td><td class="p-2"><?php echo sdss_escape($course['grade_points']); ?></td></tr><?php endforeach; ?></tbody></table></section>
                <?php elseif ($view === 'settings' && $role === 'admin'): ?>
                    <div class="grid lg:grid-cols-2 gap-6"><form method="post" class="bg-white rounded-xl p-5 border border-slate-200 space-y-3"><h2 class="font-bold">Save system setting</h2><input type="hidden" name="csrf_token" value="<?php echo sdss_escape(sdss_csrf_token()); ?>"><input type="hidden" name="action" value="save_setting"><label class="block text-sm">Setting key<input name="setting_key" pattern="[a-z][a-z0-9_]{1,99}" required class="mt-1 w-full border rounded-lg p-2"></label><label class="block text-sm">Value<input name="setting_value" required class="mt-1 w-full border rounded-lg p-2"></label><button class="bg-indigo-600 text-white rounded-lg px-4 py-2">Save setting</button></form><section class="bg-white rounded-xl p-5 border border-slate-200"><h2 class="font-bold mb-3">Current settings</h2><?php foreach ($pdo->query('SELECT setting_key, setting_value FROM system_settings ORDER BY setting_key')->fetchAll() as $setting): ?><p class="border-b py-2"><strong><?php echo sdss_escape($setting['setting_key']); ?>:</strong> <?php echo sdss_escape($setting['setting_value']); ?></p><?php endforeach; ?></section></div>
                <?php elseif ($view === 'audit' && $role === 'admin'): ?>
                    <section class="bg-white rounded-xl p-5 border border-slate-200 overflow-x-auto"><h2 class="font-bold mb-4">Recent audit activity</h2><table class="w-full text-sm"><thead><tr class="text-left border-b"><th class="p-2">When</th><th class="p-2">Actor</th><th class="p-2">Action</th><th class="p-2">Details</th></tr></thead><tbody><?php foreach ($pdo->query('SELECT a.created_at, u.full_name, a.action, a.details FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id ORDER BY a.id DESC LIMIT 200')->fetchAll() as $log): ?><tr class="border-b"><td class="p-2"><?php echo sdss_escape($log['created_at']); ?></td><td class="p-2"><?php echo sdss_escape($log['full_name'] ?? 'System'); ?></td><td class="p-2"><?php echo sdss_escape($log['action']); ?></td><td class="p-2"><?php echo sdss_escape($log['details'] ?? ''); ?></td></tr><?php endforeach; ?></tbody></table></section>
                <?php elseif ($view === 'criteria' && $role === 'department_head'): ?>
                    <?php $ownedCriteria = $pdo->prepare('SELECT id, dept_name, capacity FROM departments WHERE head_user_id = ?'); $ownedCriteria->execute([$currentUserId]); ?>
                    <section class="bg-white rounded-xl p-5 border border-slate-200 max-w-2xl"><h2 class="font-bold mb-4">Propose eligibility criteria</h2><form method="post" class="space-y-3"><input type="hidden" name="csrf_token" value="<?php echo sdss_escape(sdss_csrf_token()); ?>"><input type="hidden" name="action" value="save_department_criteria"><label class="block text-sm">Department<select name="department_id" required class="mt-1 w-full border rounded-lg p-2"><option value="">Select department</option><?php foreach ($ownedCriteria->fetchAll() as $department): ?><option value="<?php echo (int) $department['id']; ?>"><?php echo sdss_escape($department['dept_name']); ?></option><?php endforeach; ?></select></label><label class="block text-sm">Minimum GPA<input type="number" name="eligibility_gpa" min="0" max="4" step="0.01" required class="mt-1 w-full border rounded-lg p-2"></label><label class="block text-sm">Rationale<textarea name="criteria" required rows="4" class="mt-1 w-full border rounded-lg p-2"></textarea></label><button class="bg-indigo-600 text-white rounded-lg px-4 py-2">Submit criteria proposal</button></form></section>
                <?php elseif ($view === 'college' && $role === 'college_dean'): ?>
                    <section class="bg-white rounded-xl p-5 border border-slate-200"><h2 class="font-bold">College information</h2><?php $collegeInfo = $pdo->prepare('SELECT name FROM colleges WHERE dean_user_id = ?'); $collegeInfo->execute([$currentUserId]); ?><p class="mt-3"><?php echo sdss_escape($collegeInfo->fetchColumn() ?: 'No college assigned to this dean account.'); ?></p></section>
                <?php elseif ($view === 'staff' && $role === 'college_dean'): ?>
                    <?php $collegeStaff = $pdo->prepare("SELECT DISTINCT u.full_name, u.email, u.role, u.is_active FROM users u LEFT JOIN departments d ON d.head_user_id = u.id WHERE u.role IN ('department_head', 'coordinator') AND (d.college_id IN (SELECT id FROM colleges WHERE dean_user_id = ?) OR u.role = 'coordinator') ORDER BY u.role, u.full_name"); $collegeStaff->execute([$currentUserId]); ?>
                    <section class="bg-white rounded-xl p-5 border border-slate-200"><h2 class="font-bold mb-4">College staff</h2><?php foreach ($collegeStaff->fetchAll() as $staffMember): ?><p class="border-b py-3"><?php echo sdss_escape($staffMember['full_name']); ?> — <?php echo sdss_escape($staffMember['role']); ?> · <?php echo (int) $staffMember['is_active'] ? 'Active' : 'Inactive'; ?></p><?php endforeach; ?></section>
                <?php elseif ($view === 'backup' && $role === 'admin'): ?>
                    <section class="bg-white rounded-xl p-5 border border-slate-200"><h2 class="font-bold">Backup and security</h2><p class="text-sm text-slate-600 mt-2">Use your database provider’s automated backup tooling. SQLite deployments should securely copy the database and its WAL files while the application is stopped.</p><p class="text-sm text-slate-600 mt-2">Session cookies are HttpOnly, SameSite=Lax, and marked Secure over HTTPS.</p></section>
                <?php else: ?>
                    <section class="bg-white rounded-xl p-5 border border-slate-200"><h2 class="font-bold"><?php echo sdss_escape($viewTitle); ?></h2><p class="text-sm text-slate-600 mt-2">No records are available for this section yet.</p></section>
                <?php endif; ?>
            </main>
        </div>
    </div>
</body>
</html>

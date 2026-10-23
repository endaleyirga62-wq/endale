<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    $isSecureRequest = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $isSecureRequest,
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

function sdss_db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $driver = strtolower(getenv('SDSS_DB_DRIVER') ?: getenv('DB_DRIVER') ?: 'sqlite');

    $isMysql = $driver === 'mysql' || getenv('SDSS_DB_HOST') || getenv('DB_HOST');

    if ($isMysql) {
        $host = getenv('SDSS_DB_HOST') ?: getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('SDSS_DB_PORT') ?: getenv('DB_PORT') ?: '3306';
        $db   = getenv('SDSS_DB_NAME') ?: getenv('DB_NAME') ?: 'sdss';
        $user = getenv('SDSS_DB_USER') ?: getenv('DB_USER') ?: 'root';
        $pass = getenv('SDSS_DB_PASS') ?: getenv('DB_PASS') ?: '';

        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } else {
        $dbPath = __DIR__ . '/../storage/sdss.sqlite';
        if (!is_dir(dirname($dbPath))) {
            if (!mkdir(dirname($dbPath), 0770, true) && !is_dir(dirname($dbPath))) {
                throw new RuntimeException('Unable to create the SDSS database storage directory.');
            }
        }

        $pdo = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
    }

    $idColumn = $isMysql
        ? 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY'
        : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $referenceIdColumn = $isMysql ? 'BIGINT UNSIGNED' : 'INTEGER';
    $textColumn = $isMysql ? 'VARCHAR(255)' : 'TEXT';
    $gpaColumn = $isMysql ? 'DECIMAL(4, 2)' : 'REAL';
    $assignedAtColumn = $isMysql ? 'DATETIME' : 'TEXT';
    $tableSuffix = $isMysql ? ' ENGINE=InnoDB' : '';

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS roles (
            slug VARCHAR(50) PRIMARY KEY,
            name {$textColumn} NOT NULL UNIQUE
        ){$tableSuffix}
    ");

    $roles = [
        'admin' => 'System Administrator',
        'registrar' => 'Registrar',
        'department_head' => 'Department Head',
        'student' => 'Student',
        'college_dean' => 'College Dean',
        'coordinator' => 'Freshman / Placement Coordinator',
    ];
    $findRole = $pdo->prepare('SELECT slug FROM roles WHERE slug = ?');
    $insertRole = $pdo->prepare('INSERT INTO roles (slug, name) VALUES (?, ?)');
    foreach ($roles as $slug => $name) {
        $findRole->execute([$slug]);
        if ($findRole->fetchColumn() === false) {
            $insertRole->execute([$slug, $name]);
        }
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS role_permissions (
            role_slug VARCHAR(50) NOT NULL,
            permission VARCHAR(100) NOT NULL,
            PRIMARY KEY (role_slug, permission),
            FOREIGN KEY (role_slug) REFERENCES roles(slug) ON DELETE CASCADE
        ){$tableSuffix}
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS colleges (
            id {$idColumn},
            name {$textColumn} NOT NULL UNIQUE,
            dean_user_id {$referenceIdColumn} NULL
        ){$tableSuffix}
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS academic_years (
            id {$idColumn},
            name {$textColumn} NOT NULL UNIQUE,
            starts_on DATE NULL,
            ends_on DATE NULL,
            selection_opens_at {$assignedAtColumn} NULL,
            selection_closes_at {$assignedAtColumn} NULL,
            appeals_close_at {$assignedAtColumn} NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'draft',
            is_current INTEGER NOT NULL DEFAULT 0
        ){$tableSuffix}
    ");

    $currentAcademicYear = date('Y') . '/' . (date('Y') + 1);
    $findAcademicYear = $pdo->prepare('SELECT id FROM academic_years WHERE name = ?');
    $findAcademicYear->execute([$currentAcademicYear]);
    if ($findAcademicYear->fetchColumn() === false) {
        $pdo->prepare(
            'INSERT INTO academic_years (name, starts_on, ends_on, selection_opens_at, appeals_close_at, status, is_current) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $currentAcademicYear,
            date('Y-09-01'),
            (date('Y') + 1) . '-08-31',
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s', strtotime('+30 days')),
            'open',
            1,
        ]);
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id {$idColumn},
            full_name {$textColumn} NOT NULL,
            email {$textColumn} NOT NULL UNIQUE,
            password {$textColumn} NOT NULL,
            role VARCHAR(50) NOT NULL DEFAULT 'student',
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at {$assignedAtColumn} NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (role) REFERENCES roles(slug)
        ){$tableSuffix}
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS students (
            id {$idColumn},
            user_id {$referenceIdColumn} NOT NULL UNIQUE,
            full_name {$textColumn} NOT NULL,
            student_code {$textColumn} NOT NULL UNIQUE,
            email {$textColumn} NOT NULL UNIQUE,
            gpa {$gpaColumn} NOT NULL DEFAULT 0,
            is_eligible INTEGER NOT NULL DEFAULT 1,
            eligibility_note {$textColumn} NULL,
            college_id {$referenceIdColumn} NULL,
            academic_year_id {$referenceIdColumn} NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ){$tableSuffix}
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS departments (
            id {$idColumn},
            dept_name {$textColumn} NOT NULL UNIQUE,
            capacity INTEGER NOT NULL DEFAULT 0,
            college_id {$referenceIdColumn} NULL,
            head_user_id {$referenceIdColumn} NULL,
            description {$textColumn} NULL,
            eligibility_gpa {$gpaColumn} NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1
        ){$tableSuffix}
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS student_transcripts (
            id {$idColumn},
            student_id {$referenceIdColumn} NOT NULL,
            academic_year_id {$referenceIdColumn} NULL,
            course_code {$textColumn} NOT NULL,
            course_name {$textColumn} NOT NULL,
            credits DECIMAL(6,2) NOT NULL DEFAULT 0,
            grade {$textColumn} NULL,
            grade_points {$gpaColumn} NULL,
            FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
        ){$tableSuffix}
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS choices (
            id {$idColumn},
            student_id {$referenceIdColumn} NOT NULL UNIQUE,
            choice1 {$referenceIdColumn} NOT NULL,
            choice2 {$referenceIdColumn} NOT NULL,
            choice3 {$referenceIdColumn} NOT NULL,
            submitted_at {$assignedAtColumn} NULL,
            confirmed_at {$assignedAtColumn} NULL,
            FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
            FOREIGN KEY (choice1) REFERENCES departments(id),
            FOREIGN KEY (choice2) REFERENCES departments(id),
            FOREIGN KEY (choice3) REFERENCES departments(id)
        ){$tableSuffix}
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS department_preferences (
            id {$idColumn},
            student_id {$referenceIdColumn} NOT NULL,
            department_id {$referenceIdColumn} NOT NULL,
            priority INTEGER NOT NULL,
            created_at {$assignedAtColumn} NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (student_id, priority),
            UNIQUE (student_id, department_id),
            FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
            FOREIGN KEY (department_id) REFERENCES departments(id)
        ){$tableSuffix}
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS capacity_proposals (
            id {$idColumn},
            department_id {$referenceIdColumn} NOT NULL,
            proposed_by {$referenceIdColumn} NOT NULL,
            proposed_capacity INTEGER NOT NULL,
            proposed_eligibility_gpa {$gpaColumn} NULL,
            criteria {$textColumn} NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            reviewed_by {$referenceIdColumn} NULL,
            decision_note {$textColumn} NULL,
            created_at {$assignedAtColumn} NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reviewed_at {$assignedAtColumn} NULL,
            FOREIGN KEY (department_id) REFERENCES departments(id),
            FOREIGN KEY (proposed_by) REFERENCES users(id),
            FOREIGN KEY (reviewed_by) REFERENCES users(id)
        ){$tableSuffix}
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS placement_runs (
            id {$idColumn},
            academic_year_id {$referenceIdColumn} NULL,
            started_by {$referenceIdColumn} NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'draft',
            summary {$textColumn} NULL,
            created_at {$assignedAtColumn} NOT NULL DEFAULT CURRENT_TIMESTAMP,
            published_at {$assignedAtColumn} NULL,
            FOREIGN KEY (started_by) REFERENCES users(id)
        ){$tableSuffix}
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS assignments (
            id {$idColumn},
            student_id {$referenceIdColumn} NOT NULL UNIQUE,
            department_id {$referenceIdColumn} NOT NULL,
            assigned_at {$assignedAtColumn} NOT NULL DEFAULT CURRENT_TIMESTAMP,
            placement_run_id {$referenceIdColumn} NULL,
            is_published INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
            FOREIGN KEY (department_id) REFERENCES departments(id),
            FOREIGN KEY (placement_run_id) REFERENCES placement_runs(id)
        ){$tableSuffix}
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS appeals (
            id {$idColumn},
            student_id {$referenceIdColumn} NOT NULL,
            assignment_id {$referenceIdColumn} NULL,
            category VARCHAR(60) NOT NULL,
            message {$textColumn} NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'submitted',
            submitted_at {$assignedAtColumn} NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reviewed_by {$referenceIdColumn} NULL,
            decision_note {$textColumn} NULL,
            reviewed_at {$assignedAtColumn} NULL,
            FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
            FOREIGN KEY (assignment_id) REFERENCES assignments(id),
            FOREIGN KEY (reviewed_by) REFERENCES users(id)
        ){$tableSuffix}
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS notifications (
            id {$idColumn},
            user_id {$referenceIdColumn} NOT NULL,
            title {$textColumn} NOT NULL,
            message {$textColumn} NOT NULL,
            read_at {$assignedAtColumn} NULL,
            created_at {$assignedAtColumn} NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ){$tableSuffix}
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS announcements (
            id {$idColumn},
            created_by {$referenceIdColumn} NOT NULL,
            department_id {$referenceIdColumn} NULL,
            title {$textColumn} NOT NULL,
            body {$textColumn} NOT NULL,
            audience VARCHAR(30) NOT NULL DEFAULT 'all',
            created_at {$assignedAtColumn} NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (created_by) REFERENCES users(id),
            FOREIGN KEY (department_id) REFERENCES departments(id)
        ){$tableSuffix}
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS system_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value {$textColumn} NOT NULL
        ){$tableSuffix}
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS audit_logs (
            id {$idColumn},
            actor_user_id {$referenceIdColumn} NULL,
            action VARCHAR(100) NOT NULL,
            entity_type VARCHAR(100) NULL,
            entity_id {$referenceIdColumn} NULL,
            details {$textColumn} NULL,
            ip_address VARCHAR(45) NULL,
            created_at {$assignedAtColumn} NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
        ){$tableSuffix}
    ");

    $columnExists = function (string $table, string $column) use ($pdo, $isMysql): bool {
        if ($isMysql) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
            );
            $stmt->execute([$table, $column]);
            return (int) $stmt->fetchColumn() > 0;
        }

        foreach ($pdo->query("PRAGMA table_info({$table})")->fetchAll() as $definition) {
            if ($definition['name'] === $column) {
                return true;
            }
        }
        return false;
    };

    $migrateColumn = function (string $table, string $column, string $definition) use ($pdo, $columnExists): void {
        if (!$columnExists($table, $column)) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    };

    $migrationColumns = [
        'users' => [
            'is_active' => 'INTEGER NOT NULL DEFAULT 1',
            'created_at' => "{$assignedAtColumn} NULL",
        ],
        'students' => [
            'is_eligible' => 'INTEGER NOT NULL DEFAULT 1',
            'eligibility_note' => "{$textColumn} NULL",
            'college_id' => "{$referenceIdColumn} NULL",
            'academic_year_id' => "{$referenceIdColumn} NULL",
        ],
        'departments' => [
            'college_id' => "{$referenceIdColumn} NULL",
            'head_user_id' => "{$referenceIdColumn} NULL",
            'description' => "{$textColumn} NULL",
            'eligibility_gpa' => "{$gpaColumn} NOT NULL DEFAULT 0",
            'is_active' => 'INTEGER NOT NULL DEFAULT 1',
        ],
        'choices' => [
            'submitted_at' => "{$assignedAtColumn} NULL",
            'confirmed_at' => "{$assignedAtColumn} NULL",
        ],
        'assignments' => [
            'placement_run_id' => "{$referenceIdColumn} NULL",
            'is_published' => 'INTEGER NOT NULL DEFAULT 0',
        ],
        'capacity_proposals' => [
            'proposed_eligibility_gpa' => "{$gpaColumn} NULL",
        ],
    ];
    foreach ($migrationColumns as $table => $columns) {
        foreach ($columns as $column => $definition) {
            $migrateColumn($table, $column, $definition);
        }
    }

    $indexes = [
        'idx_students_gpa' => ['students', 'gpa'],
        'idx_appeals_status' => ['appeals', 'status'],
        'idx_assignments_published' => ['assignments', 'is_published'],
    ];
    foreach ($indexes as $index => [$table, $column]) {
        if ($isMysql) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
            );
            $stmt->execute([$table, $index]);
            $indexExists = (int) $stmt->fetchColumn() > 0;
        } else {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM sqlite_master WHERE type = ? AND name = ?');
            $stmt->execute(['index', $index]);
            $indexExists = (int) $stmt->fetchColumn() > 0;
        }
        if (!$indexExists) {
            $pdo->exec("CREATE INDEX {$index} ON {$table} ({$column})");
        }
    }

    $permissionMap = [
        'admin' => ['users.manage', 'roles.manage', 'settings.manage', 'audit.view', 'backups.manage'],
        'registrar' => ['students.import', 'students.manage', 'gpa.manage', 'placement.configure', 'placement.run', 'placement.approve', 'placement.publish', 'reports.view', 'appeals.process'],
        'department_head' => ['department.view', 'capacity.propose', 'applicants.view', 'announcements.manage', 'department_reports.view'],
        'student' => ['departments.view', 'choices.manage', 'placement.view_own', 'appeals.submit', 'profile.view_own', 'transcript.view_own'],
        'college_dean' => ['college.view', 'capacity.approve', 'staff.manage_college', 'analytics.view_college', 'appeals.review_college', 'reports.view_college'],
        'coordinator' => ['choices.monitor', 'students.follow_up', 'eligibility.monitor', 'appeals.triage', 'placement_reports.prepare', 'notifications.send'],
    ];
    $findPermission = $pdo->prepare('SELECT permission FROM role_permissions WHERE role_slug = ? AND permission = ?');
    $addPermission = $pdo->prepare('INSERT INTO role_permissions (role_slug, permission) VALUES (?, ?)');
    foreach ($permissionMap as $role => $permissions) {
        foreach ($permissions as $permission) {
            $findPermission->execute([$role, $permission]);
            if ($findPermission->fetchColumn() === false) {
                $addPermission->execute([$role, $permission]);
            }
        }
    }

    $seedAccounts = [
        ['System Administrator', 'MAU@123-admin', 'mau@123-admin', 'admin'],
        ['Registrar', 'MAU@123-registrar', 'mau@123-registrar', 'registrar'],
        ['Coordinator', 'MAU@123-coordinator', 'mau@123-coordinator', 'coordinator'],
        ['Department Head', 'MAU@123-department-head', 'mau@123-department-head', 'department_head'],
        ['College Dean', 'MAU@123-college-dean', 'mau@123-college-dean', 'college_dean'],
        ['Demo Student', 'MAU@123-student', 'mau@123-student', 'student'],
    ];

    $findAccount = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $insertAccount = $pdo->prepare(
        'INSERT INTO users (full_name, email, password, role) VALUES (?, ?, ?, ?)'
    );

    foreach ($seedAccounts as [$fullName, $username, $legacyUsername, $role]) {
        $findAccount->execute([$legacyUsername]);
        $userId = $findAccount->fetchColumn();

        if ($userId !== false) {
            $pdo->prepare('UPDATE users SET email = ?, password = ? WHERE id = ?')->execute([
                $username,
                password_hash('12345678', PASSWORD_DEFAULT),
                $userId,
            ]);
        } else {
            $findAccount->execute([$username]);
            $userId = $findAccount->fetchColumn();
        }

        if ($userId === false) {
            $insertAccount->execute([
                $fullName,
                $username,
                password_hash('12345678', PASSWORD_DEFAULT),
                $role,
            ]);
            $userId = $pdo->lastInsertId();
        }

        if ($role === 'student' && $userId !== false) {
            $pdo->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$username, $userId]);
            $pdo->prepare('UPDATE students SET email = ? WHERE user_id = ?')->execute([$username, $userId]);
        }

        if ($role === 'student') {
            $findStudent = $pdo->prepare('SELECT id FROM students WHERE user_id = ?');
            $findStudent->execute([$userId]);

            if ($findStudent->fetchColumn() === false) {
                $studentCode = 'SDSS-' . $userId;
                $pdo->prepare(
                    'INSERT INTO students (user_id, full_name, student_code, email, gpa) VALUES (?, ?, ?, ?, ?)'
                )->execute([$userId, $fullName, $studentCode, $username, 3.85]);
            }
        }
    }

    $departments = ['Computer Science', 'Software Engineering', 'Information Systems', 'Electrical Engineering'];
    foreach ($departments as $name) {
        $findDepartment = $pdo->prepare('SELECT id FROM departments WHERE dept_name = ?');
        $findDepartment->execute([$name]);
        if ($findDepartment->fetchColumn() === false) {
            $pdo->prepare('INSERT INTO departments (dept_name, capacity) VALUES (?, ?)')->execute([$name, 50]);
        }
    }

    $findCollege = $pdo->prepare('SELECT id FROM colleges WHERE name = ?');
    $findCollege->execute(['Main College']);
    $collegeId = $findCollege->fetchColumn();
    if ($collegeId === false) {
        $pdo->prepare('INSERT INTO colleges (name) VALUES (?)')->execute(['Main College']);
        $collegeId = $pdo->lastInsertId();
    }

    $registrarAccount = $pdo->prepare("SELECT id FROM users WHERE role = 'college_dean' ORDER BY id LIMIT 1");
    $deanUserId = $registrarAccount->execute() ? $registrarAccount->fetchColumn() : false;
    if ($deanUserId !== false) {
        $pdo->prepare('UPDATE colleges SET dean_user_id = ? WHERE id = ? AND dean_user_id IS NULL')
            ->execute([$deanUserId, $collegeId]);
    }

    $headAccount = $pdo->prepare("SELECT id FROM users WHERE role = 'department_head' ORDER BY id LIMIT 1");
    $headUserId = $headAccount->execute() ? $headAccount->fetchColumn() : false;
    $firstDepartment = $pdo->query('SELECT id FROM departments ORDER BY id LIMIT 1')->fetchColumn();
    if ($firstDepartment !== false) {
        $pdo->prepare('UPDATE departments SET college_id = ? WHERE college_id IS NULL')->execute([$collegeId]);
        if ($headUserId !== false) {
            $pdo->prepare('UPDATE departments SET head_user_id = ? WHERE id = ? AND head_user_id IS NULL')
                ->execute([$headUserId, $firstDepartment]);
        }
    }

    $academicYearStmt = $pdo->prepare('SELECT id FROM academic_years WHERE is_current = 1 ORDER BY id DESC');
    $academicYearStmt->execute();
    $academicYearId = $academicYearStmt->fetchColumn();
    if ($academicYearId !== false) {
        $pdo->prepare('UPDATE students SET academic_year_id = ? WHERE academic_year_id IS NULL')->execute([$academicYearId]);
        $pdo->prepare('UPDATE students SET college_id = ? WHERE college_id IS NULL')->execute([$collegeId]);
    }

    return $pdo;
}

$pdo = sdss_db();

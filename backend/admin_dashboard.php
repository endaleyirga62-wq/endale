<?php
require_once __DIR__ . '/config.php';

$staffRoles = ['admin', 'registrar', 'coordinator', 'department_head', 'college_dean'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $staffRoles, true)) {
    header('Location: login.php');
    exit;
}

header('Location: workflow.php?view=dashboard');
exit;

$roleLabels = [
    'admin' => 'Administrator',
    'registrar' => 'Registrar',
    'coordinator' => 'Coordinator',
    'department_head' => 'Department Head',
    'college_dean' => 'College Dean',
];
$roleLabel = $roleLabels[$_SESSION['role']];

$usersCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$studentsCount = (int) $pdo->query('SELECT COUNT(*) FROM students')->fetchColumn();
$departmentsCount = (int) $pdo->query('SELECT COUNT(*) FROM departments')->fetchColumn();
$assignmentsCount = (int) $pdo->query('SELECT COUNT(*) FROM assignments')->fetchColumn();

$recentStudents = $pdo->query(
    'SELECT full_name, student_code, gpa FROM students ORDER BY gpa DESC, full_name ASC LIMIT 5'
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="am">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SDSS - Admin Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../frontend/assets/style.css">
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen flex flex-col justify-between">
    <header class="bg-indigo-700 text-white shadow-md py-4 px-8 flex justify-between items-center">
        <h1 class="text-xl font-bold tracking-wide">
            <a href="../frontend/pages/home.html" class="hover:text-indigo-100 transition">SDSS Portal</a>
        </h1>
        <nav class="flex gap-6 items-center text-sm font-medium">
            <a href="../frontend/pages/home.html" class="text-indigo-100 hover:text-white transition">Home</a>
            <a href="../frontend/pages/announcement.html" class="text-indigo-100 hover:text-white transition">Announcements</a>
            <a href="../frontend/pages/FAQ.html" class="text-indigo-100 hover:text-white transition">FAQ</a>
            <a href="../frontend/pages/contact.html" class="text-indigo-100 hover:text-white transition">Contact</a>
            <a href="placement.php" class="text-indigo-100 hover:text-white transition">Placement Results</a>
            <a href="admin_dashboard.php" class="text-white border-b-2 border-white pb-1 font-semibold">Admin Dashboard</a>
            <a href="logout.php" class="bg-white text-indigo-700 hover:bg-indigo-50 font-semibold px-4 py-2 rounded-lg transition shadow-sm">
                Logout
            </a>
        </nav>
    </header>

    <main class="max-w-6xl mx-auto px-6 py-10 flex-grow w-full">
        <div class="mb-8 bg-white rounded-2xl border border-slate-200 shadow-sm p-6 md:p-8">
            <span class="bg-indigo-50 text-indigo-700 text-xs font-semibold px-3 py-1.5 rounded-full border border-indigo-100">
                <?php echo htmlspecialchars($roleLabel); ?> Panel
            </span>
            <h2 class="text-2xl md:text-3xl font-bold text-slate-900 mt-3">Welcome, <?php echo htmlspecialchars($_SESSION['full_name'] ?? $roleLabel); ?></h2>
            <p class="text-slate-500 text-sm mt-2">Manage the department selection process, monitor records, and review placements.</p>
        </div>

        <div class="grid md:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm">
                <p class="text-xs uppercase tracking-wide text-slate-400">Total Users</p>
                <p class="text-3xl font-extrabold text-slate-900 mt-2"><?php echo $usersCount; ?></p>
            </div>
            <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm">
                <p class="text-xs uppercase tracking-wide text-slate-400">Students</p>
                <p class="text-3xl font-extrabold text-slate-900 mt-2"><?php echo $studentsCount; ?></p>
            </div>
            <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm">
                <p class="text-xs uppercase tracking-wide text-slate-400">Departments</p>
                <p class="text-3xl font-extrabold text-slate-900 mt-2"><?php echo $departmentsCount; ?></p>
            </div>
            <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm">
                <p class="text-xs uppercase tracking-wide text-slate-400">Assignments</p>
                <p class="text-3xl font-extrabold text-slate-900 mt-2"><?php echo $assignmentsCount; ?></p>
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 md:p-8">
            <h3 class="text-xl font-bold text-slate-900 mb-5">Top Student GPA</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-100 text-xs uppercase tracking-wider text-slate-600">
                            <th class="py-3 px-4">Student</th>
                            <th class="py-3 px-4">Student Code</th>
                            <th class="py-3 px-4">CGPA</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 text-sm">
                        <?php foreach ($recentStudents as $student): ?>
                            <tr>
                                <td class="py-3 px-4 font-medium text-slate-800"><?php echo htmlspecialchars($student['full_name']); ?></td>
                                <td class="py-3 px-4 text-slate-600"><?php echo htmlspecialchars($student['student_code']); ?></td>
                                <td class="py-3 px-4 font-semibold text-indigo-700"><?php echo number_format((float) $student['gpa'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <footer class="bg-slate-800 text-slate-400 py-6 text-sm">
        <div class="max-w-5xl mx-auto px-6 flex flex-col md:flex-row justify-between items-center gap-4">
            <p>&copy; SDSS - Student Department Selection System. All rights reserved.</p>
            <div class="flex gap-4 text-xs">
                <a href="../frontend/pages/FAQ.html" class="hover:text-white transition">FAQ</a>
                <span>•</span>
                <a href="../frontend/pages/contact.html" class="hover:text-white transition">Contact Us</a>
                <span>•</span>
                <a href="placement.php" class="hover:text-white transition">Placement Results</a>
            </div>
        </div>
    </footer>
</body>
</html>

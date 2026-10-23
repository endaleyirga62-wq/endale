<?php
require_once __DIR__ . '/config.php';

// Check if user is logged in
$is_logged_in = isset($_SESSION['user_id']);
$user_role = $is_logged_in ? $_SESSION['role'] : null;

// Fetch placement results joined with student and department tables
$results = $pdo->query("
    SELECT s.full_name, s.gpa, d.dept_name 
    FROM assignments a 
    JOIN students s ON a.student_id = s.id 
    JOIN departments d ON a.department_id = d.id 
    WHERE a.is_published = 1
    ORDER BY s.gpa DESC
")->fetchAll();

$publishedCount = count($results);
?>

<!DOCTYPE html>
<html lang="am">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SDSS - Placement Results</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Custom Stylesheet -->
    <link rel="stylesheet" href="../frontend/assets/style.css">
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen flex flex-col justify-between">

    <!-- Header / Navigation Bar -->
    <header class="bg-indigo-700 text-white shadow-md py-4 px-8 flex justify-between items-center">
        <h1 class="text-xl font-bold tracking-wide">
            <a href="../frontend/pages/home.html" class="hover:text-indigo-100 transition">SDSS Portal</a>
        </h1>
        <nav class="flex gap-6 items-center text-sm font-medium">
            <a href="../frontend/pages/home.html" class="text-indigo-100 hover:text-white transition">Home</a>
            <a href="../frontend/pages/announcement.html" class="text-indigo-100 hover:text-white transition">Announcements</a>
            <a href="../frontend/pages/FAQ.html" class="text-indigo-100 hover:text-white transition">FAQ</a>
            <a href="../frontend/pages/contact.html" class="text-indigo-100 hover:text-white transition">Contact</a>
            <a href="placement.php" class="text-white border-b-2 border-white pb-1 font-semibold">Placement Results</a>
            
            <?php if ($is_logged_in): ?>
                <?php if ($user_role !== 'student'): ?>
                    <a href="admin_dashboard.php" class="bg-indigo-800 hover:bg-indigo-900 text-white px-3 py-1.5 rounded-lg transition">
                        Staff Dashboard
                    </a>
                <?php else: ?>
                    <a href="student_dashboard.php" class="bg-indigo-800 hover:bg-indigo-900 text-white px-3 py-1.5 rounded-lg transition">
                        My Dashboard
                    </a>
                <?php endif; ?>
                <a href="logout.php" class="bg-white text-indigo-700 hover:bg-indigo-50 font-semibold px-4 py-2 rounded-lg transition shadow-sm">
                    Logout
                </a>
            <?php else: ?>
                <a href="login.php" class="bg-white text-indigo-700 hover:bg-indigo-50 font-semibold px-4 py-2 rounded-lg transition shadow-sm">
                    Login
                </a>
            <?php endif; ?>
        </nav>
    </header>

    <!-- Main Content -->
    <main class="max-w-5xl mx-auto px-6 py-10 flex-grow w-full">
        
        <!-- Header Title Banner -->
        <div class="mb-8 text-center md:text-left flex flex-col md:flex-row justify-between items-center gap-4 bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
            <div>
                <h2 class="text-2xl font-bold text-slate-900">የምደባ ውጤት (Final Placement Results)</h2>
                <p class="text-slate-500 text-sm mt-1">ተማሪዎች በ Cumulative GPA ቅደም-ተከተል መሠረት የተመደቡበት ይፋዊ ውጤት።</p>
            </div>
            <span class="bg-emerald-50 text-emerald-700 border border-emerald-200 text-xs font-semibold px-3 py-1.5 rounded-full">
                Status: <?php echo $publishedCount > 0 ? 'Published' : 'Not published'; ?>
            </span>
        </div>

        <!-- Results Table Card -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-100 border-b border-slate-200 text-xs uppercase text-slate-600 tracking-wider">
                            <th class="py-4 px-6 font-semibold">#</th>
                            <th class="py-4 px-6 font-semibold">የተማሪ ስም (Student Name)</th>
                            <th class="py-4 px-6 font-semibold">CGPA</th>
                            <th class="py-4 px-6 font-semibold">የተመደቡበት ዲፓርትመንት (Assigned Department)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 text-sm">
                        <?php if (count($results) > 0): ?>
                            <?php $rank = 1; ?>
                            <?php foreach ($results as $r): ?>
                                <tr class="hover:bg-slate-50 transition">
                                    <td class="py-4 px-6 font-semibold text-slate-400"><?php echo $rank++; ?></td>
                                    <td class="py-4 px-6 font-medium text-slate-900"><?php echo htmlspecialchars($r['full_name']); ?></td>
                                    <td class="py-4 px-6 font-bold text-slate-700"><?php echo number_format($r['gpa'], 2); ?></td>
                                    <td class="py-4 px-6">
                                        <span class="px-3 py-1 rounded-full text-xs font-medium bg-indigo-50 text-indigo-700 border border-indigo-100 inline-block">
                                            <?php echo htmlspecialchars($r['dept_name']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colSpan="4" class="text-center py-12 text-slate-400">
                                    <p class="text-base font-medium text-slate-500">ምንም አይነት የምደባ ውጤት እስካሁን አልወጣም።</p>
                                    <p class="text-xs text-slate-400 mt-1">አድሚኑ ምደባውን (Placement Algorithm) ሲያካሂድ ውጤቱ እዚህ ይፋ ይሆናል።</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <!-- Footer Section -->
    <footer class="bg-slate-800 text-slate-400 py-6 text-sm mt-12">
        <div class="max-w-5xl mx-auto px-6 flex flex-col md:flex-row justify-between items-center gap-4">
            <p>&copy; SDSS - Student Department Selection System. All rights reserved.</p>
            <div class="flex gap-4 text-xs">
                <a href="../frontend/pages/announcement.html" class="hover:text-white transition">Announcements</a>
                <span>•</span>
                <a href="../frontend/pages/FAQ.html" class="hover:text-white transition">FAQ</a>
                <span>•</span>
                <a href="../frontend/pages/contact.html" class="hover:text-white transition">Contact Us</a>
                <span>•</span>
                <a href="login.php" class="hover:text-white transition">System Login</a>
            </div>
        </div>
    </footer>

    <!-- Custom Script -->
    <script src="../frontend/assets/script.js"></script>
</body>
</html>
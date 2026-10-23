<?php
require_once __DIR__ . '/config.php';

$search_query = trim($_GET['search'] ?? '');
$placement_result = null;
$searched = false;

if (!empty($search_query)) {
    $searched = true;
    $stmt = $pdo->prepare("
        SELECT s.full_name, s.student_code, s.gpa, d.dept_name, a.assigned_at
        FROM assignments a
        JOIN students s ON a.student_id = s.id
        JOIN departments d ON a.department_id = d.id
        WHERE (s.student_code = ? OR s.email = ?) AND a.is_published = 1
    ");
    $stmt->execute([$search_query, $search_query]);
    $placement_result = $stmt->fetch(PDO::FETCH_ASSOC);
}
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
            <a href="results.php" class="text-white border-b-2 border-white pb-1 font-semibold">Placement Results</a>
            <a href="login.php" class="bg-white text-indigo-700 hover:bg-indigo-50 font-semibold px-4 py-2 rounded-lg transition shadow-sm">
                Login
            </a>
        </nav>
    </header>

    <!-- Main Content -->
    <main class="max-w-3xl mx-auto px-6 py-10 flex-grow w-full">

        <!-- Search Header -->
        <div class="bg-white rounded-2xl p-6 md:p-8 border border-slate-200 shadow-sm mb-8 text-center">
            <span class="bg-indigo-50 text-indigo-700 text-xs font-semibold px-3 py-1.5 rounded-full border border-indigo-100">
                Department Placement Check
            </span>
            <h2 class="text-2xl font-bold text-slate-900 mt-3">የምደባ ውጤት መፈለጊያ (Check Your Placement)</h2>
            <p class="text-slate-500 text-sm mt-1">የተመደቡበትን ዲፓርትመንት ለማወቅ የተማሪ መታወቂያ ቁጥርዎን (Student ID) ያስገቡ።</p>

            <form action="results.php" method="GET" class="mt-6 flex flex-col sm:flex-row gap-3 max-w-lg mx-auto">
                <input type="text" name="search" value="<?php echo htmlspecialchars($search_query); ?>" required placeholder="ለምሳሌ፡ ETS0001/14" class="flex-grow border border-slate-300 rounded-xl p-3 focus:ring-2 focus:ring-indigo-500 focus:outline-none text-sm">
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-6 py-3 rounded-xl transition shadow-md text-sm">
                    ፈልግ (Search)
                </button>
            </form>
        </div>

        <!-- Result Section -->
        <?php if ($searched): ?>
            <?php if ($placement_result): ?>
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 md:p-8 relative overflow-hidden">
                    <div class="bg-emerald-500 h-2 absolute top-0 left-0 right-0"></div>
                    
                    <div class="flex justify-between items-start mb-6 border-b border-slate-100 pb-4">
                        <div>
                            <span class="text-xs font-bold text-emerald-600 bg-emerald-50 px-2.5 py-1 rounded-md border border-emerald-100 uppercase">
                                Assignment Confirmed
                            </span>
                            <h3 class="text-xl font-bold text-slate-900 mt-2"><?php echo htmlspecialchars($placement_result['full_name']); ?></h3>
                            <p class="text-xs text-slate-400">ID: <?php echo htmlspecialchars($placement_result['student_code']); ?></p>
                        </div>
                        <button onclick="window.print()" class="no-print bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold px-4 py-2 rounded-lg text-xs transition border border-slate-200">
                            🖨️ አትም (Print Slip)
                        </button>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm bg-slate-50 p-4 rounded-xl border border-slate-100">
                        <div>
                            <p class="text-xs text-slate-400">የተመደቡበት ዲፓርትመንት (Assigned Department)</p>
                            <p class="text-lg font-extrabold text-indigo-700 mt-0.5"><?php echo htmlspecialchars($placement_result['dept_name']); ?></p>
                        </div>
                        <div>
                            <p class="text-xs text-slate-400">የተማሪው Cumulative GPA</p>
                            <p class="text-base font-bold text-slate-800 mt-0.5"><?php echo htmlspecialchars($placement_result['gpa']); ?></p>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="bg-rose-50 border border-rose-200 text-rose-800 rounded-2xl p-6 text-center">
                    <p class="font-bold text-base">ምንም አይነት የምደባ ውጤት አልተገኘም!</p>
                    <p class="text-xs text-rose-600 mt-1">አስገብተው የፈለጉት መታወቂያ ቁጥር አልተመደበም ወይም በሲስተሙ ውስጥ አልተገኘም።</p>
                </div>
            <?php endif; ?>
        <?php endif; ?>

    </main>

    <!-- Footer Section -->
    <footer class="bg-slate-800 text-slate-400 py-6 text-sm mt-12">
        <div class="max-w-4xl mx-auto px-6 flex flex-col md:flex-row justify-between items-center gap-4">
            <p>&copy; SDSS - Student Department Selection System. All rights reserved.</p>
            <div class="flex gap-4 text-xs">
                <a href="../frontend/pages/announcement.html" class="hover:text-white transition">Announcements</a>
                <span>•</span>
                <a href="../frontend/pages/FAQ.html" class="hover:text-white transition">FAQ</a>
                <span>•</span>
                <a href="../frontend/pages/contact.html" class="hover:text-white transition">Contact Us</a>
                <span>•</span>
                <a href="login.php" class="hover:text-white transition">Login</a>
            </div>
        </div>
    </footer>

    <script src="../frontend/assets/script.js"></script>
</body>
</html>
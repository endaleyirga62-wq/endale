<?php
require_once __DIR__ . '/config.php';

// Check if user is logged in and is a student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit;
}

header('Location: workflow.php?view=dashboard');
exit;

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Fetch student details
$stmt = $pdo->prepare("SELECT * FROM students WHERE user_id = ?");
$stmt->execute([$user_id]);
$student = $stmt->fetch();

if (!$student) {
    die("Student profile not found. Please contact admin.");
}

$student_id = $student['id'];

// Fetch all available departments
$departments = $pdo->query("SELECT * FROM departments ORDER BY dept_name ASC")->fetchAll();

// Fetch existing choice if already submitted
$stmtChoice = $pdo->prepare("SELECT * FROM choices WHERE student_id = ?");
$stmtChoice->execute([$student_id]);
$existing_choice = $stmtChoice->fetch();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $c1 = $_POST['choice1'] ?? null;
    $c2 = $_POST['choice2'] ?? null;
    $c3 = $_POST['choice3'] ?? null;

    // Server-side validation
    if (!$c1 || !$c2 || !$c3) {
        $error = "እባክዎን ሶስቱንም የዲፓርትመንት ምርጫዎች ይሙሉ!";
    } elseif ($c1 === $c2 || $c1 === $c3 || $c2 === $c3) {
        $error = "ተመሳሳይ ዲፓርትመንት ከሁለት ጊዜ በላይ መምረጥ አይችሉም!";
    } else {
        if ($existing_choice) {
            // Update existing choices
            $updateStmt = $pdo->prepare("UPDATE choices SET choice1 = ?, choice2 = ?, choice3 = ? WHERE student_id = ?");
            $updateStmt->execute([$c1, $c2, $c3, $student_id]);
            $message = "ምርጫዎ በጥሩ ሁኔታ ተሻሽሏል (Choices updated successfully!)";
        } else {
            // Insert new choices
            $insertStmt = $pdo->prepare("INSERT INTO choices (student_id, choice1, choice2, choice3) VALUES (?, ?, ?, ?)");
            $insertStmt->execute([$student_id, $c1, $c2, $c3]);
            $message = "ምርጫዎ በጥሩ ሁኔታ ተመዝግቧል (Choices submitted successfully!)";
        }

        // Refresh existing choices in state
        $stmtChoice->execute([$student_id]);
        $existing_choice = $stmtChoice->fetch();
    }
}
?>

<!DOCTYPE html>
<html lang="am">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SDSS - Student Dashboard</title>
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
            <a href="placement.php" class="text-indigo-100 hover:text-white transition">Placement Results</a>
            <a href="student_dashboard.php" class="text-white border-b-2 border-white pb-1 font-semibold">My Dashboard</a>
            <a href="logout.php" class="bg-white text-indigo-700 hover:bg-indigo-50 font-semibold px-4 py-2 rounded-lg transition shadow-sm">
                Logout
            </a>
        </nav>
    </header>

    <!-- Main Content -->
    <main class="max-w-4xl mx-auto px-6 py-10 flex-grow w-full">

        <!-- Welcome Profile Card -->
        <div class="bg-white rounded-2xl p-6 md:p-8 border border-slate-200 shadow-sm mb-8 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
            <div>
                <span class="text-xs font-semibold uppercase tracking-wider text-indigo-600 bg-indigo-50 px-3 py-1 rounded-full border border-indigo-100">
                    Student Profile
                </span>
                <h2 class="text-2xl font-bold text-slate-900 mt-2">
                    እንኳን ደህና መጡ፣ <?php echo htmlspecialchars($student['full_name']); ?>!
                </h2>
                <p class="text-slate-500 text-sm mt-1">የዲፓርትመንት ምርጫዎችዎን ከታች ባለው ፎርም ይሙሉ ወይም ያስተካክሉ።</p>
            </div>
            <div class="bg-slate-50 p-4 rounded-xl border border-slate-200 text-right min-w-[160px]">
                <span class="block text-xs text-slate-500 font-medium">Cumulative GPA</span>
                <span class="text-2xl font-extrabold text-indigo-700"><?php echo number_format($student['gpa'], 2); ?></span>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if ($message): ?>
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 p-4 rounded-xl mb-6 alert-fade flex justify-between items-center text-sm font-medium">
                <span><?php echo $message; ?></span>
                <button onclick="this.parentElement.remove()" class="text-emerald-600 hover:text-emerald-900 font-bold">&times;</button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="bg-rose-50 border border-rose-200 text-rose-800 p-4 rounded-xl mb-6 alert-fade flex justify-between items-center text-sm font-medium">
                <span><?php echo $error; ?></span>
                <button onclick="this.parentElement.remove()" class="text-rose-600 hover:text-rose-900 font-bold">&times;</button>
            </div>
        <?php endif; ?>

        <!-- Selection Form Card -->
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6 md:p-8">
            <h3 class="text-lg font-bold text-slate-900 mb-2">የዲፓርትመንት ምርጫ ፎርም (Department Preference Form)</h3>
            <p class="text-slate-500 text-sm mb-6">እባክዎን 3 የዲፓርትመንት ምርጫዎችን በቅደም ተከተል ይምረጡ። ሶስቱም ምርጫዎች የተለያዩ መሆን አለባቸው።</p>

            <form action="student_dashboard.php" method="POST" class="space-y-6">
                
                <!-- Choice 1 -->
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-2">1ኛ ምርጫ (1st Choice) <span class="text-rose-500">*</span></label>
                    <select name="choice1" required class="w-full border border-slate-300 rounded-xl p-3 focus:ring-2 focus:ring-indigo-500 focus:outline-none text-sm bg-white">
                        <option value="">-- ዲፓርትመንት ይምረጡ --</option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?php echo $d['id']; ?>" <?php echo ($existing_choice && $existing_choice['choice1'] == $d['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($d['dept_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Choice 2 -->
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-2">2ኛ ምርጫ (2nd Choice) <span class="text-rose-500">*</span></label>
                    <select name="choice2" required class="w-full border border-slate-300 rounded-xl p-3 focus:ring-2 focus:ring-indigo-500 focus:outline-none text-sm bg-white">
                        <option value="">-- ዲፓርትመንት ይምረጡ --</option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?php echo $d['id']; ?>" <?php echo ($existing_choice && $existing_choice['choice2'] == $d['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($d['dept_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Choice 3 -->
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-2">3ኛ ምርጫ (3rd Choice) <span class="text-rose-500">*</span></label>
                    <select name="choice3" required class="w-full border border-slate-300 rounded-xl p-3 focus:ring-2 focus:ring-indigo-500 focus:outline-none text-sm bg-white">
                        <option value="">-- ዲፓርትመንት ይምረጡ --</option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?php echo $d['id']; ?>" <?php echo ($existing_choice && $existing_choice['choice3'] == $d['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($d['dept_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="pt-4 border-t border-slate-100 flex flex-col md:flex-row justify-between items-center gap-4">
                    <button type="submit" class="w-full md:w-auto bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-8 py-3 rounded-xl transition shadow-md">
                        <?php echo $existing_choice ? 'ምርጫን አስተካክል (Update Preference)' : 'ምርጫን መዝግብ (Submit Preference)'; ?>
                    </button>

                    <a href="placement.php" class="text-sm font-semibold text-indigo-600 hover:text-indigo-800 transition">
                        የምደባ ውጤት ለመመልከት እዚህ ይጫኑ →
                    </a>
                </div>

            </form>
        </div>

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
                <a href="placement.php" class="hover:text-white transition">Placement Results</a>
            </div>
        </div>
    </footer>

    <!-- Custom Client-side Script -->
    <script src="../frontend/assets/script.js"></script>
</body>
</html>
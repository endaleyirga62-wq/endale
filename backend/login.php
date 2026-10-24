<?php
require_once __DIR__ . '/auth.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$error = '';
$sharedUsername = 'mau@123';
$sharedPassword = '12345678';
$chooseRole = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sdss_verify_csrf()) {
        http_response_code(400);
        $error = 'Your login form expired. Reload the page and try again.';
    } else {
        $usernameInput = $_POST['username'] ?? '';
        $passwordInput = $_POST['password'] ?? '';
        $username = is_string($usernameInput) ? trim($usernameInput) : '';
        $password = is_string($passwordInput) ? $passwordInput : '';

        $adminUser = $pdo->prepare('SELECT id, full_name, email, password, role, is_active FROM users WHERE role = ? AND is_active = 1 ORDER BY id LIMIT 1');
        $adminUser->execute(['admin']);
        $user = $adminUser->fetch();

        $isValidDemoLogin = strtolower($username) === strtolower($sharedUsername)
            && $password === $sharedPassword
            && $user
            && (int) $user['is_active'] === 1
            && password_verify($sharedPassword, $user['password']);

        if ($isValidDemoLogin) {
            session_regenerate_id(true);
            unset($_SESSION['user_id'], $_SESSION['role'], $_SESSION['full_name'], $_SESSION['pending_demo_login']);
            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['full_name'] = $user['full_name'];
            header('Location: workflow.php?view=dashboard');
            exit;
        }

        $error = 'Incorrect username or password. Use ' . $sharedUsername . ' and the default password ' . $sharedPassword . '.';
    }
}
?>

<!DOCTYPE html>
<html lang="am">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SDSS - System Login</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Custom Stylesheet -->
    <link rel="stylesheet" href="../frontend/assets/style.css">
</head>
<body class="bg-gradient-to-br from-slate-950 via-blue-950 to-slate-900 text-slate-100 min-h-screen flex flex-col justify-between">

    <!-- Header / Navigation Bar -->
    <header class="bg-slate-950/80 text-white shadow-md py-4 px-8 flex justify-between items-center">
        <h1 class="text-xl font-bold tracking-wide">
            <a href="../frontend/pages/home.html" class="hover:text-indigo-100 transition">SDSS Portal</a>
        </h1>
        <nav class="flex gap-6 items-center text-sm font-medium">
            <a href="../frontend/pages/home.html" class="text-indigo-100 hover:text-white transition">Home</a>
            <a href="../frontend/pages/announcement.html" class="text-indigo-100 hover:text-white transition">Announcements</a>
            <a href="../frontend/pages/FAQ.html" class="text-indigo-100 hover:text-white transition">FAQ</a>
            <a href="../frontend/pages/contact.html" class="text-indigo-100 hover:text-white transition">Contact</a>
            <a href="placement.php" class="text-indigo-100 hover:text-white transition">Placement Results</a>
            <a href="login.php" class="text-white border-b-2 border-white pb-1 font-semibold">Login</a>
        </nav>
    </header>

    <!-- Main Login Content -->
    <main class="max-w-md mx-auto px-6 py-12 w-full flex-grow flex flex-col justify-center">

        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-8">
            <div class="text-center mb-6">
                <span class="bg-indigo-50 text-indigo-700 text-xs font-semibold px-3 py-1.5 rounded-full border border-indigo-100">
                    System Authentication
                </span>
                <h2 class="text-2xl font-bold text-slate-900 mt-3"><?php echo $chooseRole ? 'Choose your SDSS dashboard' : 'ወደ ሲስተሙ ይግቡ (Login)'; ?></h2>
                <p class="text-slate-500 text-xs mt-1"><?php echo $chooseRole ? 'Your shared demo login is verified. Select which role dashboard to open.' : 'Enter the shared demo username and password to sign in.'; ?></p>
            </div>

            <!-- Error Message Alert -->
            <?php if ($error): ?>
                <div class="bg-rose-50 border border-rose-200 text-rose-800 p-3.5 rounded-xl mb-5 text-xs font-medium flex justify-between items-center">
                    <span><?php echo htmlspecialchars($error); ?></span>
                    <button onclick="this.parentElement.remove()" class="text-rose-600 font-bold ml-2">&times;</button>
                </div>
            <?php endif; ?>

            <form id="loginForm" action="login.php" method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(sdss_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                <label for="username" class="block text-xs font-semibold text-slate-700 mb-1">Username <span class="text-rose-500">*</span></label>
                <input id="username" type="text" name="username" autocomplete="username" required maxlength="255" value="<?php echo htmlspecialchars(is_string($_POST['username'] ?? null) ? $_POST['username'] : $sharedUsername, ENT_QUOTES, 'UTF-8'); ?>" placeholder="mau@123" class="w-full border border-slate-300 rounded-xl p-3 focus:ring-2 focus:ring-indigo-500 focus:outline-none text-sm">

                <label for="password" class="block text-xs font-semibold text-slate-700 mb-1">Password <span class="text-rose-500">*</span></label>
                <input id="password" type="password" name="password" autocomplete="current-password" required minlength="8" maxlength="255" placeholder="12345678" class="w-full border border-slate-300 rounded-xl p-3 focus:ring-2 focus:ring-indigo-500 focus:outline-none text-sm">
                <p class="text-xs text-slate-500">Default demo credentials: <strong><?php echo htmlspecialchars($sharedUsername); ?></strong> / <strong><?php echo htmlspecialchars($sharedPassword); ?></strong></p>

                <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-3 rounded-xl transition shadow-md text-sm mt-2">
                    Sign In
                </button>
            </form>
        </div>

    </main>

    <!-- Footer Section -->
    <footer class="bg-slate-950 text-slate-400 py-6 text-sm mt-12">
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

    <script src="../frontend/assets/script.js"></script>
</body>
</html>
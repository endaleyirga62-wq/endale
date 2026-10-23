<?php
require_once __DIR__ . '/auth.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$error = '';
$roles = [
    'admin' => 'Admin',
    'registrar' => 'Registrar',
    'coordinator' => 'Coordinator',
    'department_head' => 'Department Head',
    'college_dean' => 'College Dean',
    'student' => 'Student',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sdss_verify_csrf()) {
        http_response_code(400);
        $error = 'Your login form expired. Reload the page and try again.';
    } else {
        if (($_POST['action'] ?? 'login') === 'select_role') {
            $selectedRole = $_POST['role'] ?? '';
            if (empty($_SESSION['pending_demo_login']) || !is_string($selectedRole) || !isset($roles[$selectedRole])) {
                $error = 'Your login session expired. Sign in again.';
                unset($_SESSION['pending_demo_login']);
            } else {
                $accountUsernames = [
                    'admin' => 'MAU@123-admin',
                    'registrar' => 'MAU@123-registrar',
                    'coordinator' => 'MAU@123-coordinator',
                    'department_head' => 'MAU@123-department-head',
                    'college_dean' => 'MAU@123-college-dean',
                    'student' => 'MAU@123-student',
                ];
                $stmt = $pdo->prepare('SELECT id, full_name, role, is_active FROM users WHERE LOWER(email) = LOWER(?)');
                $stmt->execute([$accountUsernames[$selectedRole]]);
                $user = $stmt->fetch();
                if (!$user || (int) $user['is_active'] !== 1 || $user['role'] !== $selectedRole) {
                    $error = 'That demo account is unavailable. Choose another role or contact an administrator.';
                } else {
                    session_regenerate_id(true);
                    unset($_SESSION['pending_demo_login']);
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['full_name'] = $user['full_name'];
                    header('Location: workflow.php?view=dashboard');
                    exit;
                }
            }
        } else {
            $usernameInput = $_POST['username'] ?? '';
            $passwordInput = $_POST['password'] ?? '';
            $username = is_string($usernameInput) ? trim($usernameInput) : '';
            $password = is_string($passwordInput) ? $passwordInput : '';
            $stmt = $pdo->prepare("SELECT id, password, is_active FROM users WHERE LOWER(email) = LOWER('MAU@123-admin') AND role = 'admin'");
            $stmt->execute();
            $user = $stmt->fetch();

            if (strtolower($username) === 'mau@123'
                && strlen($password) >= 8
                && strlen($password) <= 255
                && $user
                && (int) $user['is_active'] === 1
                && password_verify($password, $user['password'])) {
                session_regenerate_id(true);
                unset($_SESSION['user_id'], $_SESSION['role'], $_SESSION['full_name']);
                $_SESSION['pending_demo_login'] = true;
                header('Location: login.php?choose_role=1');
                exit;
            }
            $error = 'Incorrect username or password. Use mau@123 and the default password 12345678.';
        }
    }
}

$chooseRole = !empty($_SESSION['pending_demo_login']);
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

            <?php if ($chooseRole): ?>
                <div class="grid gap-3">
                    <?php foreach ($roles as $role => $label): ?>
                        <form action="login.php" method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(sdss_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="action" value="select_role">
                            <input type="hidden" name="role" value="<?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-3 rounded-xl transition shadow-sm text-sm">
                                Continue as <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                            </button>
                        </form>
                    <?php endforeach; ?>
                    <a href="logout.php" class="text-center text-sm text-indigo-700 underline">Cancel and sign out</a>
                </div>
            <?php else: ?>
                <form id="loginForm" action="login.php" method="POST" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(sdss_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                    <label for="username" class="block text-xs font-semibold text-slate-700 mb-1">Username <span class="text-rose-500">*</span></label>
                    <input id="username" type="text" name="username" autocomplete="username" required maxlength="255" value="<?php echo htmlspecialchars(is_string($_POST['username'] ?? null) ? $_POST['username'] : 'mau@123', ENT_QUOTES, 'UTF-8'); ?>" placeholder="mau@123" class="w-full border border-slate-300 rounded-xl p-3 focus:ring-2 focus:ring-indigo-500 focus:outline-none text-sm">

                    <label for="password" class="block text-xs font-semibold text-slate-700 mb-1">ፓስወርድ (Password) <span class="text-rose-500">*</span></label>
                    <input id="password" type="password" name="password" autocomplete="current-password" required minlength="8" maxlength="255" placeholder="12345678" class="w-full border border-slate-300 rounded-xl p-3 focus:ring-2 focus:ring-indigo-500 focus:outline-none text-sm">
                    <p class="text-xs text-slate-500">Default demo password: <strong>12345678</strong></p>

                    <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-3 rounded-xl transition shadow-md text-sm mt-2">
                        ይግቡ (Sign In)
                    </button>
                </form>
            <?php endif; ?>
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

    <script src="../frontend/assets/script.js"></script>
</body>
</html>
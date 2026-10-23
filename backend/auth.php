<?php
require_once __DIR__ . '/config.php';

function sdss_escape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function sdss_require_roles(array $allowedRoles): void
{
    if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', $allowedRoles, true)) {
        header('Location: login.php');
        exit;
    }
}

function sdss_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function sdss_verify_csrf(): bool
{
    $token = $_POST['csrf_token'] ?? '';
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function sdss_audit(PDO $pdo, string $action, ?string $entityType = null, ?int $entityId = null, ?string $details = null): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $_SESSION['user_id'] ?? null,
        $action,
        $entityType,
        $entityId,
        $details,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}

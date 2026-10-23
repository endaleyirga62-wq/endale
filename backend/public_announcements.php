<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $announcements = $pdo->query(
        "SELECT title, body, created_at FROM announcements WHERE audience = 'all' AND department_id IS NULL ORDER BY created_at DESC LIMIT 100"
    )->fetchAll();
    echo json_encode($announcements, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (PDOException $exception) {
    error_log('SDSS public announcements database error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Announcements are temporarily unavailable.'], JSON_UNESCAPED_UNICODE);
} catch (JsonException $exception) {
    error_log('SDSS public announcements JSON error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Announcements could not be displayed.'], JSON_UNESCAPED_UNICODE);
}

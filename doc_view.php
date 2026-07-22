<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'manager', 'frontdesk']);

$file = basename($_GET['f'] ?? '');
if ($file === '' || !preg_match('/^doc_[A-Za-z0-9_]+\.(jpg|jpeg|png|webp|pdf)$/i', $file)) {
    http_response_code(404);
    exit('Not found');
}
$path = UPLOAD_DOCS_PATH . '/' . $file;
if (!is_file($path)) {
    http_response_code(404);
    exit('Not found');
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];
header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, no-store');
readfile($path);

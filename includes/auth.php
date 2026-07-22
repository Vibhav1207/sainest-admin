<?php
require_once __DIR__ . '/functions.php';

function requireLogin(): void {
    if (empty($_SESSION['user_id'])) {
        redirect('login.php');
    }
}

function requireRole(array $roles): void {
    requireLogin();
    if (!in_array($_SESSION['role'] ?? '', $roles, true)) {
        http_response_code(403);
        require __DIR__ . '/../pages/403.php';
        exit;
    }
}

function hasRole(array $roles): bool {
    return !empty($_SESSION['role']) && in_array($_SESSION['role'], $roles, true);
}

function currentUser(): ?array {
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    static $user = null;
    if ($user === null) {
        $stmt = db()->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $_SESSION['user_id']]);
        $user = $stmt->fetch() ?: null;
    }
    return $user;
}

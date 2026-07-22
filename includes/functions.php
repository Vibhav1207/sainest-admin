<?php
require_once __DIR__ . '/db.php';

/* ============================================================
 *  SETTINGS
 * ============================================================ */
function getSetting(string $key, string $default = ''): string {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            $stmt = db()->query("SELECT setting_key, setting_value FROM settings");
            foreach ($stmt->fetchAll() as $row) {
                $cache[$row['setting_key']] = $row['setting_value'];
            }
        } catch (PDOException $e) {
            // Table missing / DB not fully migrated yet — fall back to defaults
            // instead of taking down every page on the site with a fatal error.
            $cache = [];
        }
    }
    return $cache[$key] ?? $default;
}

function setSetting(string $key, string $value): void {
    $stmt = db()->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v)
                            ON DUPLICATE KEY UPDATE setting_value = :v2");
    $stmt->execute(['k' => $key, 'v' => $value, 'v2' => $value]);
}

/* ============================================================
 *  GENERAL HELPERS
 * ============================================================ */
function e(?string $str): string {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): void {
    header('Location: ' . BASE_URL . '/' . ltrim($path, '/'));
    exit;
}

function money(float $amount): string {
    return getSetting('currency_symbol', '₹') . number_format($amount, 2);
}

function flash(string $type, string $message): void {
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function getFlashes(): array {
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfCheck(): bool {
    $token = $_POST['csrf_token'] ?? '';
    return !empty($token) && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

/* ============================================================
 *  CODE GENERATORS
 * ============================================================ */
function generateBookingCode(): string {
    $prefix = getSetting('booking_prefix', 'SN');
    $stmt = db()->query("SELECT COUNT(*) AS c FROM bookings WHERE YEAR(created_at) = YEAR(CURDATE())");
    $count = (int)$stmt->fetch()['c'] + 1;
    return $prefix . date('y') . '-' . str_pad((string)$count, 4, '0', STR_PAD_LEFT);
}

function generateInvoiceNumber(): string {
    $prefix = getSetting('invoice_prefix', 'SNI');
    $stmt = db()->query("SELECT COUNT(*) AS c FROM invoices WHERE YEAR(created_at) = YEAR(CURDATE())");
    $count = (int)$stmt->fetch()['c'] + 1;
    return $prefix . date('y') . '-' . str_pad((string)$count, 4, '0', STR_PAD_LEFT);
}

/* ============================================================
 *  ACTIVITY LOG
 * ============================================================ */
function logActivity(string $action, string $details = ''): void {
    $stmt = db()->prepare("INSERT INTO activity_log (user_id, action, details) VALUES (:u, :a, :d)");
    $stmt->execute([
        'u' => $_SESSION['user_id'] ?? null,
        'a' => $action,
        'd' => $details,
    ]);
}

/* ============================================================
 *  FILE UPLOAD (ID PROOF DOCUMENTS)
 * ============================================================ */
function handleDocumentUpload(string $inputName): ?string {
    if (empty($_FILES[$inputName]) || $_FILES[$inputName]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $file = $_FILES[$inputName];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload error for ' . $inputName . ' (code ' . $file['error'] . ')');
    }
    if ($file['size'] > MAX_DOC_UPLOAD_SIZE) {
        throw new RuntimeException('File too large. Maximum size is 5 MB.');
    }
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Only JPG, PNG, WEBP or PDF files are allowed.');
    }
    if (!is_dir(UPLOAD_DOCS_PATH)) {
        mkdir(UPLOAD_DOCS_PATH, 0755, true);
    }
    $ext      = $allowed[$mime];
    $filename = 'doc_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $dest     = UPLOAD_DOCS_PATH . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Failed to save uploaded file.');
    }
    return $filename;
}

function deleteDocumentFile(?string $filename): void {
    if ($filename) {
        $path = UPLOAD_DOCS_PATH . '/' . basename($filename);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

/* ============================================================
 *  BOOKING / ROOM CALCULATIONS
 * ============================================================ */
function getActiveReservationsToday(): array {
    $today = date('Y-m-d');
    $stmt = db()->prepare("
        SELECT DISTINCT room_id 
        FROM bookings 
        WHERE status = 'reserved' 
          AND DATE(checkin_datetime) <= :today1 
          AND expected_checkout_date > :today2
    ");
    $stmt->execute(['today1' => $today, 'today2' => $today]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function getRoomCurrentStatus(array $room, array $activeReservedRoomIds): string {
    if (in_array($room['status'], ['occupied', 'dirty', 'maintenance'], true)) {
        return $room['status'];
    }
    if (in_array((int)$room['id'], $activeReservedRoomIds, true)) {
        return 'reserved';
    }
    return 'available';
}

function getRoomBookingConflict(int $roomId, string $checkinDate, string $checkoutDate, ?int $excludeBookingId = null): ?array {
    $pdo = db();
    $sql = "
        SELECT DISTINCT b.*, r.room_number 
        FROM bookings b
        JOIN rooms r ON r.id = :room_id_target
        LEFT JOIN booking_rooms br ON br.booking_id = b.id
        WHERE (b.room_id = :room_id1 OR br.room_id = :room_id2)
          AND b.status IN ('checked_in', 'reserved')
          AND DATE(:new_checkin) < b.expected_checkout_date
          AND DATE(:new_checkout) > DATE(b.checkin_datetime)
    ";
    if ($excludeBookingId !== null) {
        $sql .= " AND b.id != :exclude_id";
    }
    $sql .= " LIMIT 1";
    
    $params = [
        'room_id_target' => $roomId,
        'room_id1'       => $roomId,
        'room_id2'       => $roomId,
        'new_checkin'    => $checkinDate,
        'new_checkout'   => $checkoutDate,
    ];
    if ($excludeBookingId !== null) {
        $params['exclude_id'] = $excludeBookingId;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

function nightsBetween(string $checkinDateTime, string $checkoutDate): int {
    $in  = new DateTime(date('Y-m-d', strtotime($checkinDateTime)));
    $out = new DateTime($checkoutDate);
    $diff = $in->diff($out)->days;
    return max(1, $diff);
}

function roomStatusBadge(string $status): string {
    $map = [
        'available'   => ['label' => 'Available',   'class' => 'badge-green'],
        'occupied'    => ['label' => 'Occupied',     'class' => 'badge-gold'],
        'dirty'       => ['label' => 'Needs Cleaning','class' => 'badge-red'],
        'maintenance' => ['label' => 'Maintenance',  'class' => 'badge-gray'],
        'reserved'    => ['label' => 'Reserved',     'class' => 'badge-blue'],
    ];
    $info = $map[$status] ?? ['label' => ucfirst($status), 'class' => 'badge-gray'];
    return '<span class="badge ' . $info['class'] . '">' . $info['label'] . '</span>';
}

function groupAndSortRooms(array $rooms): array {
    $grouped = [];
    foreach ($rooms as $room) {
        $roomNumber = $room['room_number'];
        $num = (int)preg_replace('/[^0-9]/', '', $roomNumber);
        $floor = $num >= 100 ? (int)($num / 100) : 1;
        $grouped[$floor][] = $room;
    }
    
    // Sort floors numerically
    ksort($grouped);
    
    // Sort rooms within each floor numerically by room number
    foreach ($grouped as $floor => &$floorRooms) {
        usort($floorRooms, function($a, $b) {
            $numA = (int)preg_replace('/[^0-9]/', '', $a['room_number']);
            $numB = (int)preg_replace('/[^0-9]/', '', $b['room_number']);
            return $numA <=> $numB;
        });
    }
    unset($floorRooms);
    
    return $grouped;
}

function renderRoomCard(array $r): void {
    ?>
    <div class="room-tile st-<?= e($r['status']) ?>"
         id="roomTile-<?= $r['id'] ?>"
         data-room-id="<?= $r['id'] ?>"
         data-room-number="<?= e($r['room_number']) ?>"
         data-status="<?= e($r['status']) ?>"
         onclick="handleRoomTileClick(this)">
      <div class="rn">
        🚪 <?= e($r['room_number']) ?>
        <?php if (in_array($_SESSION['role'] ?? '', ['admin', 'manager'], true)): ?>
          <span class="edit-room-link" onclick="openEditRoomModal(event, <?= (int)$r['id'] ?>, '<?= e($r['room_number']) ?>', <?= (int)$r['room_type_id'] ?>, '<?= e($r['status']) ?>')" title="Edit Room Type" style="float:right; font-size:0.9rem; cursor:pointer; opacity:0.6; transition: opacity 0.2s;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.6'">✏️</span>
        <?php endif; ?>
      </div>
      <div class="rt"><?= e($r['type_name']) ?> · <?= money($r['base_rate']) ?>/night</div>
      <span class="badge-slot"><?= roomStatusBadge($r['status']) ?></span>
    </div>
    <?php
}

function bookingStatusBadge(string $status): string {
    $map = [
        'checked_in'  => ['label' => 'Checked In',  'class' => 'badge-gold'],
        'checked_out' => ['label' => 'Checked Out', 'class' => 'badge-green'],
        'cancelled'   => ['label' => 'Cancelled',   'class' => 'badge-red'],
        'reserved'    => ['label' => 'Reserved',    'class' => 'badge-blue'],
    ];
    $info = $map[$status] ?? ['label' => ucfirst($status), 'class' => 'badge-gray'];
    return '<span class="badge ' . $info['class'] . '">' . $info['label'] . '</span>';
}

/* ============================================================
 *  ROLE / PERMISSION LABELS
 * ============================================================ */
function roleLabel(string $role): string {
    $map = [
        'admin'        => 'Administrator',
        'manager'      => 'Manager',
        'frontdesk'    => 'Front Desk',
        'housekeeping' => 'Housekeeping',
    ];
    return $map[$role] ?? ucfirst($role);
}

/**
 * Whether the current logged-in user is allowed to see commission
 * figures (internal earnings info that must never reach the guest bill).
 */
function canViewCommission(): bool {
    return in_array($_SESSION['role'] ?? '', ['admin', 'manager'], true);
}

function canManageUsers(): bool {
    return ($_SESSION['role'] ?? '') === 'admin';
}

/* ============================================================
 *  ROOM TYPES HELPER & AUTOMATIC MIGRATION
 * ============================================================ */
/**
 * Automatically checks and updates room_types table if legacy room types
 * ('Standard', 'Deluxe', 'Family Suite', 'Suite') exist in an active DB.
 */
function ensureRoomTypesMigrated(): void {
    static $migrated = false;
    if ($migrated) return;
    $migrated = true;

    ensureExtraAmountColumnExists();
    ensureFinalRoomTypeMapping();

    try {
        $pdo = db();
        $stmt = $pdo->query("SELECT COUNT(*) AS c FROM room_types WHERE name IN ('Standard', 'Deluxe', 'Family Suite', 'Suite')");
        $count = (int) ($stmt->fetch()['c'] ?? 0);
        if ($count > 0) {
            $pdo->exec("
                INSERT INTO room_types (name, base_rate, max_guests, description)
                VALUES ('Standard Double Bed', 1200.00, 2, 'Comfortable AC room with standard double bed')
                ON DUPLICATE KEY UPDATE name = name;

                INSERT INTO room_types (name, base_rate, max_guests, description)
                VALUES ('Standard Deluxe', 1800.00, 3, 'Spacious standard deluxe room with premium amenities')
                ON DUPLICATE KEY UPDATE name = name;

                INSERT INTO room_types (name, base_rate, max_guests, description)
                VALUES ('Standard Three Bed', 2800.00, 5, 'Large room with three beds ideal for families and groups')
                ON DUPLICATE KEY UPDATE name = name;

                UPDATE rooms r
                JOIN room_types rt_old ON r.room_type_id = rt_old.id
                JOIN room_types rt_new ON rt_new.name = 'Standard Double Bed'
                SET r.room_type_id = rt_new.id
                WHERE rt_old.name = 'Standard';

                UPDATE rooms r
                JOIN room_types rt_old ON r.room_type_id = rt_old.id
                JOIN room_types rt_new ON rt_new.name = 'Standard Deluxe'
                SET r.room_type_id = rt_new.id
                WHERE rt_old.name IN ('Deluxe', 'Suite');

                UPDATE rooms r
                JOIN room_types rt_old ON r.room_type_id = rt_old.id
                JOIN room_types rt_new ON rt_new.name = 'Standard Three Bed'
                SET r.room_type_id = rt_new.id
                WHERE rt_old.name = 'Family Suite';

                DELETE FROM room_types WHERE name IN ('Standard', 'Deluxe', 'Family Suite', 'Suite');
            ");
        }
    } catch (Throwable $e) {
        // Silently ignore if DB is not initialized yet
    }
}

/**
 * Automatically checks and adds extra_amount column to bookings table if missing.
 */
function ensureExtraAmountColumnExists(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    ensureBookingRoomsTableExists();
    ensureCorporateBookingColumnsExist();
    ensureUpdatedAtColumnExists();
    ensureBookingExtraChargesTableExists();
    ensureNullableRoomId();

    try {
        $pdo = db();
        $stmt = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'extra_amount'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE bookings ADD COLUMN extra_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER advance_amount");
        }
    } catch (Throwable $e) {
        // Silently ignore if DB is not initialized yet
    }
}

/**
 * Ensures bookings.room_id and booking_rooms.room_id can be NULL to support unassigned room reservations.
 */
function ensureNullableRoomId(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $pdo = db();
        $col = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'room_id'")->fetch();
        if ($col && strtolower($col['Null']) === 'no') {
            try {
                $pdo->exec("ALTER TABLE bookings MODIFY COLUMN room_id INT UNSIGNED NULL DEFAULT NULL");
            } catch (Throwable $e) {
                try {
                    $pdo->exec("ALTER TABLE bookings DROP FOREIGN KEY fk_booking_room");
                } catch (Throwable $e2) {}
                $pdo->exec("ALTER TABLE bookings MODIFY COLUMN room_id INT UNSIGNED NULL DEFAULT NULL");
                try {
                    $pdo->exec("ALTER TABLE bookings ADD CONSTRAINT fk_booking_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL");
                } catch (Throwable $e2) {}
            }
        }
        $brCol = $pdo->query("SHOW COLUMNS FROM booking_rooms LIKE 'room_id'")->fetch();
        if ($brCol && strtolower($brCol['Null']) === 'no') {
            try {
                $pdo->exec("ALTER TABLE booking_rooms MODIFY COLUMN room_id INT UNSIGNED NULL DEFAULT NULL");
            } catch (Throwable $e) {
                try {
                    $pdo->exec("ALTER TABLE booking_rooms DROP FOREIGN KEY fk_br_room");
                } catch (Throwable $e2) {}
                $pdo->exec("ALTER TABLE booking_rooms MODIFY COLUMN room_id INT UNSIGNED NULL DEFAULT NULL");
                try {
                    $pdo->exec("ALTER TABLE booking_rooms ADD CONSTRAINT fk_br_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL");
                } catch (Throwable $e2) {}
            }
        }
    } catch (Throwable $e) {
        // Silently ignore if DB is not initialized yet
    }
}

/**
 * Adds updated_at column to bookings table if it doesn't exist.
 */
function ensureUpdatedAtColumnExists(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $pdo = db();
        $stmt = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'updated_at'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE bookings ADD COLUMN updated_at DATETIME DEFAULT NULL AFTER created_at");
        }
    } catch (Throwable $e) {
        // Silently ignore if DB is not initialized yet
    }
}

/**
 * Automatically migrate existing room types to new finalized mappings
 */
function ensureFinalRoomTypeMapping(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $pdo = db();
        $stmt = $pdo->query("SELECT COUNT(*) AS c FROM room_types WHERE name IN ('Standard Double Bed', 'Standard Deluxe', 'Standard Three Bed')");
        $count = (int) ($stmt->fetch()['c'] ?? 0);
        
        if ($count > 0) {
            $pdo->exec("
                UPDATE room_types SET name = 'Standard 2 Bed' WHERE name = 'Standard Double Bed';
                UPDATE room_types SET name = 'Deluxe Suite 2 Bed' WHERE name = 'Standard Deluxe';
                UPDATE room_types SET name = 'Standard 3 Bed' WHERE name = 'Standard Three Bed';
                
                UPDATE rooms r JOIN room_types rt ON rt.name = 'Standard 2 Bed'
                SET r.room_type_id = rt.id
                WHERE r.room_number IN ('101', '102', '103', '105');

                UPDATE rooms r JOIN room_types rt ON rt.name = 'Deluxe Suite 2 Bed'
                SET r.room_type_id = rt.id
                WHERE r.room_number IN ('104', '205', '305', '401', '402', '403', '404', '405', '406');

                UPDATE rooms r JOIN room_types rt ON rt.name = 'Standard 3 Bed'
                SET r.room_type_id = rt.id
                WHERE r.room_number IN ('201', '202', '203', '206', '208', '301', '303', '306', '308');
            ");
        }
    } catch (Throwable $e) {
        // Silently ignore if DB is not initialized yet
    }
}

function getPredefinedRoomTypeMapping(): array {
    return [
        'Standard 2 Bed' => ['101', '102', '103', '105'],
        'Deluxe Suite 2 Bed' => ['104', '205', '305', '401', '402', '403', '404', '405', '406'],
        'Standard 3 Bed' => ['201', '202', '203', '206', '208', '301', '303', '306', '308'],
    ];
}

function getExpectedRoomTypeName(string $roomNumber): ?string {
    $mapping = getPredefinedRoomTypeMapping();
    foreach ($mapping as $typeName => $rooms) {
        if (in_array($roomNumber, $rooms, true)) {
            return $typeName;
        }
    }
    return null;
}

/**
 * Automatically checks and adds corporate columns to bookings table if missing.
 */
function ensureCorporateBookingColumnsExist(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $pdo = db();
        $col = $pdo->query("SHOW COLUMNS FROM bookings LIKE 'booking_type'")->fetch();
        if (!$col) {
            $pdo->exec("
                ALTER TABLE bookings
                  ADD COLUMN booking_type ENUM('regular','corporate') NOT NULL DEFAULT 'regular' AFTER booking_code,
                  ADD COLUMN company_name VARCHAR(150) DEFAULT NULL AFTER booking_type,
                  ADD COLUMN company_gst_number VARCHAR(30) DEFAULT NULL AFTER company_name,
                  ADD COLUMN company_address TEXT DEFAULT NULL AFTER company_gst_number,
                  ADD COLUMN company_contact_person VARCHAR(100) DEFAULT NULL AFTER company_address,
                  ADD COLUMN company_phone VARCHAR(30) DEFAULT NULL AFTER company_contact_person
            ");
        }
    } catch (Throwable $e) {
        // Silently ignore if DB not initialized
    }
}

/**
 * Automatically creates booking_rooms table if missing and populates
 * existing bookings for 100% backward compatibility.
 */
function ensureBookingRoomsTableExists(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $pdo = db();
        $tableExists = (bool) $pdo->query("SHOW TABLES LIKE 'booking_rooms'")->fetch();
        if (!$tableExists) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `booking_rooms` (
                  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `booking_id` INT UNSIGNED NOT NULL,
                  `room_id` INT UNSIGNED NOT NULL,
                  `rate_per_night` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                  PRIMARY KEY (`id`),
                  KEY `fk_br_booking` (`booking_id`),
                  KEY `fk_br_room` (`room_id`),
                  CONSTRAINT `fk_br_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
                  CONSTRAINT `fk_br_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

                INSERT INTO `booking_rooms` (`booking_id`, `room_id`, `rate_per_night`)
                SELECT `id`, `room_id`, `rate_per_night` FROM `bookings`;
            ");
        }
    } catch (Throwable $e) {
        // Silently ignore if DB is not initialized
    }
}

/**
 * Returns all rooms associated with a booking (multi-room supported).
 */
function getBookingRooms(int $bookingId): array {
    ensureBookingRoomsTableExists();
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT br.room_id, br.rate_per_night, 
               COALESCE(r.room_number, 'Unassigned') AS room_number, 
               COALESCE(r.floor, '—') AS floor, 
               COALESCE(r.status, 'available') AS room_status, 
               COALESCE(rt.name, 'Standard') AS room_type_name, 
               COALESCE(rt.base_rate, br.rate_per_night) AS base_rate, 
               COALESCE(rt.max_guests, 2) AS max_guests,
               r.room_type_id AS room_type_id
        FROM booking_rooms br
        LEFT JOIN rooms r ON r.id = br.room_id
        LEFT JOIN room_types rt ON rt.id = r.room_type_id
        WHERE br.booking_id = :id
        ORDER BY CAST(r.room_number AS UNSIGNED) ASC
    ");
    $stmt->execute(['id' => $bookingId]);
    $rooms = $stmt->fetchAll();

    // Fallback for legacy database rows if booking_rooms row was not created yet
    if (!$rooms) {
        $stmtLegacy = $pdo->prepare("
            SELECT b.room_id, b.rate_per_night, 
                   COALESCE(r.room_number, 'Unassigned') AS room_number, 
                   COALESCE(r.floor, '—') AS floor, 
                   COALESCE(r.status, 'available') AS room_status, 
                   COALESCE(rt.name, 'Standard') AS room_type_name, 
                   COALESCE(rt.base_rate, b.rate_per_night) AS base_rate, 
                   COALESCE(rt.max_guests, 2) AS max_guests
            FROM bookings b
            LEFT JOIN rooms r ON r.id = b.room_id
            LEFT JOIN room_types rt ON rt.id = r.room_type_id
            WHERE b.id = :id
        ");
        $stmtLegacy->execute(['id' => $bookingId]);
        $rooms = $stmtLegacy->fetchAll();
    }
    return $rooms;
}

/**
 * Automatically creates booking_extra_charges table if missing.
 */
function ensureBookingExtraChargesTableExists(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $pdo = db();
        $tableExists = (bool) $pdo->query("SHOW TABLES LIKE 'booking_extra_charges'")->fetch();
        if (!$tableExists) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `booking_extra_charges` (
                  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `booking_id` INT UNSIGNED NOT NULL,
                  `charge_name` VARCHAR(100) NOT NULL,
                  `qty` DECIMAL(10,2) NOT NULL DEFAULT 1.00,
                  `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                  `total_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                  `remarks` TEXT DEFAULT NULL,
                  `created_by` INT UNSIGNED DEFAULT NULL,
                  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `fk_bec_booking` (`booking_id`),
                  CONSTRAINT `fk_bec_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
        }
    } catch (Throwable $e) {
        // Silently ignore if DB is not initialized
    }
}

/**
 * Returns all itemized extra charges recorded for a booking.
 */
function getBookingExtraCharges(int $bookingId): array {
    ensureBookingExtraChargesTableExists();
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT bec.*, u.full_name AS created_by_name
        FROM booking_extra_charges bec
        LEFT JOIN users u ON u.id = bec.created_by
        WHERE bec.booking_id = :id
        ORDER BY bec.created_at ASC, bec.id ASC
    ");
    $stmt->execute(['id' => $bookingId]);
    return $stmt->fetchAll();
}

/**
 * Adds an extra charge to a booking and updates bookings.extra_amount total.
 */
function addBookingExtraCharge(int $bookingId, string $chargeName, float $qty, float $unitPrice, ?string $remarks = null, ?int $createdBy = null): int {
    ensureBookingExtraChargesTableExists();
    $pdo = db();
    $totalAmount = round($qty * $unitPrice, 2);

    $stmt = $pdo->prepare("
        INSERT INTO booking_extra_charges (booking_id, charge_name, qty, unit_price, total_amount, remarks, created_by, created_at)
        VALUES (:b, :name, :qty, :price, :total, :remarks, :u, NOW())
    ");
    $stmt->execute([
        'b'       => $bookingId,
        'name'    => trim($chargeName),
        'qty'     => $qty,
        'price'   => $unitPrice,
        'total'   => $totalAmount,
        'remarks' => trim($remarks ?: '') ?: null,
        'u'       => $createdBy,
    ]);
    $chargeId = (int) $pdo->lastInsertId();

    // Recalculate total extra charges for this booking and sync into bookings.extra_amount
    $sumStmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM booking_extra_charges WHERE booking_id = :b");
    $sumStmt->execute(['b' => $bookingId]);
    $newTotalExtra = (float) $sumStmt->fetchColumn();

    $updStmt = $pdo->prepare("UPDATE bookings SET extra_amount = :extra WHERE id = :b");
    $updStmt->execute(['extra' => $newTotalExtra, 'b' => $bookingId]);

    return $chargeId;
}

/**
 * Returns all room types from database, ensuring legacy room types are migrated.
 */
function getRoomTypes(): array {
    ensureRoomTypesMigrated();
    return db()->query("SELECT * FROM room_types ORDER BY base_rate ASC")->fetchAll();
}

// Automatically ensure schema migrations run on application initialization
ensureExtraAmountColumnExists();



<?php
// config.php - إعدادات عامة + اتصال قاعدة البيانات + دوال مساعدة
// يدعم SQLite (افتراضي) أو MySQL عبر PDO.
// ملاحظة: هذا الملف يتم تضمينه في كل الصفحات

declare(strict_types=1);

session_start();

// ===== إعدادات عامة =====
define('STORE_NAME', 'متجر قطع الغيار');
// غيّر العملة حسب بلدك
// مثال: SDG, SAR, EGP...
define('CURRENCY', '');

define('DEFAULT_MIN_QTY', 5);

// ===== إعدادات قاعدة البيانات =====
// اختر: sqlite أو mysql
// - SQLite مناسب للتشغيل على جهاز واحد/Offline + تحويله لبرنامج EXE لاحقاً.
// - MySQL مناسب للتشغيل على سيرفر/شبكة داخل المحل.
define('DB_DRIVER', 'sqlite');

// --- SQLite ---
define('DB_PATH', __DIR__ . '/data/store.db');

// --- MySQL (فعّل DB_DRIVER=mysql ثم عدل القيم) ---
define('MYSQL_HOST', '127.0.0.1');
define('MYSQL_PORT', '3306');
define('MYSQL_DB', 'parts_store');
define('MYSQL_USER', 'root');
define('MYSQL_PASS', '');

// ===== إعدادات الدفع =====
define('PAYMENT_TYPES', ['cash','electronic','mixed']);
// الطرق الإلكترونية (حسب طلب الواجهة): بنكك / فوري / كاش
// ملاحظة: "كاش" هنا مقصود بها طريقة إلكترونية/محفظة (وليست الدفع النقدي العادي).
define('ELECTRONIC_METHODS', [
  'bankak' => 'بنكك',
  'cash_fawry' => 'فوري',
  'e_cash' => 'كاش',
]);

date_default_timezone_set('Africa/Khartoum');

// ===== أدوات مساعدة عامة =====
function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now_ts(): string {
  // تخزين الوقت بصيغة ثابتة (محلية) لتسهيل القراءة والتصفية
  return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
}

function money_round($n): float {
  return round((float)$n, 2);
}

function money_fmt($n): string {
  $v = (float)$n;
  $s = number_format($v, 2, '.', ',');
  return CURRENCY !== '' ? ($s . ' ' . CURRENCY) : $s;
}

function payment_type_label(?string $t): string {
  $t = (string)($t ?? '');
  if ($t === 'electronic') return 'إلكتروني';
  if ($t === 'mixed') return 'مختلط (كاش + إلكتروني)';
  return 'كاش';
}

function electronic_method_label(?string $m): string {
  $m = (string)($m ?? '');
  // دعم قيم قديمة إن وُجدت في فواتير سابقة
  if ($m === 'bank_transfer') return 'تحويل بنكي';
  $map = (array)ELECTRONIC_METHODS;
  return $map[$m] ?? '';
}

// ===== حماية CSRF بسيطة =====
function csrf_token(): string {
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
  }
  return (string)$_SESSION['csrf_token'];
}

function csrf_field(): string {
  return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function csrf_verify(): void {
  $tok = (string)($_POST['csrf_token'] ?? '');
  if ($tok === '' || empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $tok)) {
    http_response_code(400);
    echo "<meta charset='utf-8'><div style='font-family:Tahoma,Arial;direction:rtl'>طلب غير صالح (CSRF).</div>";
    exit;
  }
}

// ===== رسائل Flash =====
function flash_set(string $type, string $msg): void {
  $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function flash_get_all(): array {
  $msgs = $_SESSION['flash'] ?? [];
  unset($_SESSION['flash']);
  return $msgs;
}

// ===== اتصال قاعدة البيانات + ترقية المخطط =====
function db_fail(string $title, string $details): void {
  http_response_code(500);
  echo "<meta charset='utf-8'>";
  echo "<div style='font-family:Tahoma,Arial; direction:rtl; padding:16px; max-width:920px; margin:0 auto'>";
  echo "<h2 style='margin:0 0 8px 0'>" . h($title) . "</h2>";
  echo "<p style='color:#555; line-height:1.7'>" . nl2br(h($details)) . "</p>";
  echo "<hr>";
  if (DB_DRIVER === 'mysql') {
    echo "<div style='color:#666; font-size:13px'>تأكد من إعدادات MySQL في <code>config.php</code> وأن امتداد <code>pdo_mysql</code> مُفعّل.</div>";
  } else {
    echo "<div style='color:#666; font-size:13px'>تأكد من تفعيل إضافات SQLite في PHP: <code>pdo_sqlite</code> و <code>sqlite3</code>.</div>";
  }
  echo "</div>";
  exit;
}

function db_connect(): PDO {
  try {
    if (DB_DRIVER === 'mysql') {
      $dsn = 'mysql:host=' . MYSQL_HOST . ';port=' . MYSQL_PORT . ';dbname=' . MYSQL_DB . ';charset=utf8mb4';
      $pdo = new PDO($dsn, MYSQL_USER, MYSQL_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
      ]);
      return $pdo;
    }

    // SQLite (افتراضي)
    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) {
      @mkdir($dir, 0777, true);
    }
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON;');
    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA synchronous = NORMAL;');
    return $pdo;
  } catch (Throwable $e) {
    db_fail('تعذر الاتصال بقاعدة البيانات', "خطأ: " . $e->getMessage());
  }
}

function db_driver(PDO $pdo): string {
  try {
    return (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
  } catch (Throwable $e) {
    return DB_DRIVER;
  }
}

function table_exists(PDO $pdo, string $table): bool {
  $driver = db_driver($pdo);

  if ($driver === 'mysql') {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return ((int)$stmt->fetchColumn()) > 0;
  }

  // sqlite
  $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
  $stmt->execute([$table]);
  return (bool)$stmt->fetchColumn();
}

function column_exists(PDO $pdo, string $table, string $column): bool {
  if (!table_exists($pdo, $table)) return false;
  $driver = db_driver($pdo);

  if ($driver === 'mysql') {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return ((int)$stmt->fetchColumn()) > 0;
  }

  // sqlite
  $stmt = $pdo->prepare("PRAGMA table_info($table)");
  $stmt->execute();
  $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
  foreach ($cols as $c) {
    if (($c['name'] ?? '') === $column) return true;
  }
  return false;
}

function index_exists_mysql(PDO $pdo, string $table, string $index_name): bool {
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
  $stmt->execute([$table, $index_name]);
  return ((int)$stmt->fetchColumn()) > 0;
}

function ensure_index(PDO $pdo, string $index_name, string $table, string $cols_sql): void {
  // $cols_sql مثال: "`product_no`" أو "`product_id`, `created_at`"
  try {
    $driver = db_driver($pdo);

    if ($driver === 'mysql') {
      if (index_exists_mysql($pdo, $table, $index_name)) return;
      $pdo->exec("CREATE INDEX `$index_name` ON `$table` ($cols_sql)");
      return;
    }

    // sqlite
    $pdo->exec("CREATE INDEX IF NOT EXISTS $index_name ON $table ($cols_sql)");
  } catch (Throwable $e) {
    // تجاهل (تحسين اختياري)
  }
}

function ensure_schema(PDO $pdo): void {
  $driver = db_driver($pdo);
  $is_mysql = ($driver === 'mysql');

  // ===== إنشاء الجداول =====
  if ($is_mysql) {
    // users
    $pdo->exec(
      "CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(190) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        role VARCHAR(50) NOT NULL DEFAULT 'كاشير',
        is_active TINYINT NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
    );

    // products
    $pdo->exec(
      "CREATE TABLE IF NOT EXISTS products (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        product_no VARCHAR(190) NOT NULL UNIQUE,
        car_type VARCHAR(190) NOT NULL,
        car_brand VARCHAR(190) NOT NULL,
        quantity INT NOT NULL DEFAULT 0,
        cost_price DECIMAL(12,2) NOT NULL DEFAULT 0,
        sell_price DECIMAL(12,2) NOT NULL DEFAULT 0,
        min_qty INT NOT NULL DEFAULT " . (int)DEFAULT_MIN_QTY . ",
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
    );

    // legacy sales (للتوافق فقط)
    $pdo->exec(
      "CREATE TABLE IF NOT EXISTS sales (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        qty INT NOT NULL,
        note TEXT,
        sold_at DATETIME NOT NULL,
        sold_by INT NULL,
        CONSTRAINT fk_sales_product FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE RESTRICT,
        CONSTRAINT fk_sales_user FOREIGN KEY(sold_by) REFERENCES users(id) ON DELETE SET NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
    );

    // invoices
    $pdo->exec(
      "CREATE TABLE IF NOT EXISTS invoices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_no VARCHAR(60) NOT NULL UNIQUE,
        customer_name VARCHAR(190) NULL,
        subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
        discount DECIMAL(12,2) NOT NULL DEFAULT 0,
        tax DECIMAL(12,2) NOT NULL DEFAULT 0,
        total DECIMAL(12,2) NOT NULL DEFAULT 0,

        payment_type VARCHAR(20) NOT NULL DEFAULT 'cash',
        electronic_method VARCHAR(20) NULL,
        paid_cash DECIMAL(12,2) NOT NULL DEFAULT 0,
        paid_electronic DECIMAL(12,2) NOT NULL DEFAULT 0,

        status VARCHAR(20) NOT NULL DEFAULT 'posted',
        created_at DATETIME NOT NULL,
        created_by INT NULL,
        canceled_at DATETIME NULL,
        canceled_by INT NULL,
        cancel_note TEXT NULL,
        CONSTRAINT fk_invoices_created_by FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
        CONSTRAINT fk_invoices_canceled_by FOREIGN KEY(canceled_by) REFERENCES users(id) ON DELETE SET NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
    );

    // invoice_items
    $pdo->exec(
      "CREATE TABLE IF NOT EXISTS invoice_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_id INT NOT NULL,
        product_id INT NOT NULL,
        part_no_snapshot VARCHAR(190) NOT NULL,
        name_snapshot VARCHAR(255) NOT NULL,
        car_type_snapshot VARCHAR(190) NOT NULL DEFAULT '',
        car_brand_snapshot VARCHAR(190) NOT NULL DEFAULT '',
        unit_price_snapshot DECIMAL(12,2) NOT NULL,
        qty INT NOT NULL,
        line_total DECIMAL(12,2) NOT NULL,
        CONSTRAINT fk_items_invoice FOREIGN KEY(invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
        CONSTRAINT fk_items_product FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE RESTRICT
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
    );

    // inventory movements (Audit)
    $pdo->exec(
      "CREATE TABLE IF NOT EXISTS inventory_movements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        movement_type VARCHAR(20) NOT NULL,
        qty_change INT NOT NULL,
        qty_before INT NOT NULL,
        qty_after INT NOT NULL,
        note TEXT NULL,
        created_at DATETIME NOT NULL,
        created_by INT NULL,
        ref_invoice_id INT NULL,
        ref_invoice_no VARCHAR(60) NULL,
        CONSTRAINT fk_mov_product FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE RESTRICT,
        CONSTRAINT fk_mov_user FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
        CONSTRAINT fk_mov_invoice FOREIGN KEY(ref_invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
    );
  } else {
    // ===== SQLite =====
    // users
    $pdo->exec(
      "CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT 'كاشير',
        is_active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
      );"
    );

    // products
    $pdo->exec(
      "CREATE TABLE IF NOT EXISTS products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        product_no TEXT NOT NULL UNIQUE,
        car_type TEXT NOT NULL,
        car_brand TEXT NOT NULL,
        quantity INTEGER NOT NULL DEFAULT 0,
        cost_price REAL NOT NULL DEFAULT 0,
        sell_price REAL NOT NULL DEFAULT 0,
        min_qty INTEGER NOT NULL DEFAULT " . (int)DEFAULT_MIN_QTY . ",
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
      );"
    );

    // legacy sales (للتوافق فقط)
    $pdo->exec(
      "CREATE TABLE IF NOT EXISTS sales (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        qty INTEGER NOT NULL,
        note TEXT,
        sold_at TEXT NOT NULL,
        sold_by INTEGER,
        FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE RESTRICT,
        FOREIGN KEY(sold_by) REFERENCES users(id) ON DELETE SET NULL
      );"
    );

    // invoices
    $pdo->exec(
      "CREATE TABLE IF NOT EXISTS invoices (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        invoice_no TEXT NOT NULL UNIQUE,
        customer_name TEXT,
        subtotal REAL NOT NULL DEFAULT 0,
        discount REAL NOT NULL DEFAULT 0,
        tax REAL NOT NULL DEFAULT 0,
        total REAL NOT NULL DEFAULT 0,

        payment_type TEXT NOT NULL DEFAULT 'cash',
        electronic_method TEXT,
        paid_cash REAL NOT NULL DEFAULT 0,
        paid_electronic REAL NOT NULL DEFAULT 0,

        status TEXT NOT NULL DEFAULT 'posted',
        created_at TEXT NOT NULL,
        created_by INTEGER,
        canceled_at TEXT,
        canceled_by INTEGER,
        cancel_note TEXT,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY(canceled_by) REFERENCES users(id) ON DELETE SET NULL
      );"
    );

    // invoice_items
    $pdo->exec(
      "CREATE TABLE IF NOT EXISTS invoice_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        invoice_id INTEGER NOT NULL,
        product_id INTEGER NOT NULL,
        part_no_snapshot TEXT NOT NULL,
        name_snapshot TEXT NOT NULL,
        car_type_snapshot TEXT NOT NULL DEFAULT '',
        car_brand_snapshot TEXT NOT NULL DEFAULT '',
        unit_price_snapshot REAL NOT NULL,
        qty INTEGER NOT NULL,
        line_total REAL NOT NULL,
        FOREIGN KEY(invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
        FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE RESTRICT
      );"
    );

    // inventory movements (Audit)
    $pdo->exec(
      "CREATE TABLE IF NOT EXISTS inventory_movements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        movement_type TEXT NOT NULL,
        qty_change INTEGER NOT NULL,
        qty_before INTEGER NOT NULL,
        qty_after INTEGER NOT NULL,
        note TEXT,
        created_at TEXT NOT NULL,
        created_by INTEGER,
        ref_invoice_id INTEGER,
        ref_invoice_no TEXT,
        FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE RESTRICT,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY(ref_invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
      );"
    );
  }

  // ===== ترقيات للجداول القديمة (إن وجدت) =====
  $now = now_ts();

  // users: add columns if missing
  if (!column_exists($pdo, 'users', 'is_active')) {
    $pdo->exec($is_mysql
      ? "ALTER TABLE users ADD COLUMN is_active TINYINT NOT NULL DEFAULT 1"
      : "ALTER TABLE users ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1");
  }
  if (!column_exists($pdo, 'users', 'created_at')) {
    $pdo->exec($is_mysql
      ? "ALTER TABLE users ADD COLUMN created_at DATETIME NOT NULL DEFAULT '$now'"
      : "ALTER TABLE users ADD COLUMN created_at TEXT NOT NULL DEFAULT '$now'");
  }
  if (!column_exists($pdo, 'users', 'updated_at')) {
    $pdo->exec($is_mysql
      ? "ALTER TABLE users ADD COLUMN updated_at DATETIME NOT NULL DEFAULT '$now'"
      : "ALTER TABLE users ADD COLUMN updated_at TEXT NOT NULL DEFAULT '$now'");
  }
  $pdo->exec("UPDATE users SET created_at = COALESCE(created_at,'$now')");
  $pdo->exec("UPDATE users SET updated_at = COALESCE(updated_at,'$now')");

  // products: add columns if missing
  if (!column_exists($pdo, 'products', 'quantity')) {
    $pdo->exec("ALTER TABLE products ADD COLUMN quantity " . ($is_mysql ? "INT" : "INTEGER") . " NOT NULL DEFAULT 0");
  }
  if (!column_exists($pdo, 'products', 'car_type')) {
    $pdo->exec("ALTER TABLE products ADD COLUMN car_type " . ($is_mysql ? "VARCHAR(190)" : "TEXT") . " NOT NULL DEFAULT ''");
  }
  if (!column_exists($pdo, 'products', 'car_brand')) {
    $pdo->exec("ALTER TABLE products ADD COLUMN car_brand " . ($is_mysql ? "VARCHAR(190)" : "TEXT") . " NOT NULL DEFAULT ''");
  }
  if (!column_exists($pdo, 'products', 'created_at')) {
    $pdo->exec("ALTER TABLE products ADD COLUMN created_at " . ($is_mysql ? "DATETIME" : "TEXT") . " NOT NULL DEFAULT '$now'");
  }
  if (!column_exists($pdo, 'products', 'updated_at')) {
    $pdo->exec("ALTER TABLE products ADD COLUMN updated_at " . ($is_mysql ? "DATETIME" : "TEXT") . " NOT NULL DEFAULT '$now'");
  }
  if (!column_exists($pdo, 'products', 'cost_price')) {
    $pdo->exec("ALTER TABLE products ADD COLUMN cost_price " . ($is_mysql ? "DECIMAL(12,2)" : "REAL") . " NOT NULL DEFAULT 0");
  }
  if (!column_exists($pdo, 'products', 'sell_price')) {
    $pdo->exec("ALTER TABLE products ADD COLUMN sell_price " . ($is_mysql ? "DECIMAL(12,2)" : "REAL") . " NOT NULL DEFAULT 0");
  }
  if (!column_exists($pdo, 'products', 'min_qty')) {
    $pdo->exec("ALTER TABLE products ADD COLUMN min_qty " . ($is_mysql ? "INT" : "INTEGER") . " NOT NULL DEFAULT " . (int)DEFAULT_MIN_QTY);
  }

  // sales: sold_by
  if (table_exists($pdo, 'sales') && !column_exists($pdo, 'sales', 'sold_by')) {
    $pdo->exec("ALTER TABLE sales ADD COLUMN sold_by " . ($is_mysql ? "INT" : "INTEGER") . " NULL");
  }

  // invoices: payment columns
  if (!column_exists($pdo, 'invoices', 'payment_type')) {
    $pdo->exec("ALTER TABLE invoices ADD COLUMN payment_type " . ($is_mysql ? "VARCHAR(20)" : "TEXT") . " NOT NULL DEFAULT 'cash'");
  }
  if (!column_exists($pdo, 'invoices', 'electronic_method')) {
    $pdo->exec("ALTER TABLE invoices ADD COLUMN electronic_method " . ($is_mysql ? "VARCHAR(20)" : "TEXT") . " NULL");
  }
  if (!column_exists($pdo, 'invoices', 'paid_cash')) {
    $pdo->exec("ALTER TABLE invoices ADD COLUMN paid_cash " . ($is_mysql ? "DECIMAL(12,2)" : "REAL") . " NOT NULL DEFAULT 0");
  }
  if (!column_exists($pdo, 'invoices', 'paid_electronic')) {
    $pdo->exec("ALTER TABLE invoices ADD COLUMN paid_electronic " . ($is_mysql ? "DECIMAL(12,2)" : "REAL") . " NOT NULL DEFAULT 0");
  }

  // invoice_items: snapshot type/brand
  if (!column_exists($pdo, 'invoice_items', 'car_type_snapshot')) {
    $pdo->exec("ALTER TABLE invoice_items ADD COLUMN car_type_snapshot " . ($is_mysql ? "VARCHAR(190)" : "TEXT") . " NOT NULL DEFAULT ''");
  }
  if (!column_exists($pdo, 'invoice_items', 'car_brand_snapshot')) {
    $pdo->exec("ALTER TABLE invoice_items ADD COLUMN car_brand_snapshot " . ($is_mysql ? "VARCHAR(190)" : "TEXT") . " NOT NULL DEFAULT ''");
  }

  // تحسين/إصلاح بيانات قديمة: لو ما في تفاصيل دفع، اعتبرها كاش كامل
  try {
    $pdo->exec("UPDATE invoices
               SET payment_type = CASE
                 WHEN payment_type IS NULL OR payment_type='' THEN 'cash'
                 ELSE payment_type
               END");
  } catch (Throwable $e) {}

  try {
    // إذا كانت الفاتورة القديمة ما فيها أي مدفوعات، اعتبر المدفوع كاش = الإجمالي
    $pdo->exec("UPDATE invoices
               SET paid_cash = total
               WHERE COALESCE(paid_cash,0)=0 AND COALESCE(paid_electronic,0)=0 AND total>0");
  } catch (Throwable $e) {}

  try {
    // تعبئة snapshot للنوع/الماركة للفواتير القديمة (من جدول المنتجات)
    $pdo->exec("UPDATE invoice_items
               SET car_type_snapshot = COALESCE(NULLIF(car_type_snapshot,''),(SELECT car_type FROM products p WHERE p.id = invoice_items.product_id)),
                   car_brand_snapshot = COALESCE(NULLIF(car_brand_snapshot,''),(SELECT car_brand FROM products p WHERE p.id = invoice_items.product_id))");
  } catch (Throwable $e) {}

  // ===== Indexes لتحسين البحث =====
  if ($is_mysql) {
    ensure_index($pdo, 'idx_products_no', 'products', '`product_no`');
    ensure_index($pdo, 'idx_products_name', 'products', '`name`');
    ensure_index($pdo, 'idx_invoices_created_at', 'invoices', '`created_at`');
    ensure_index($pdo, 'idx_invoice_items_invoice', 'invoice_items', '`invoice_id`');
    ensure_index($pdo, 'idx_movements_product_created_at', 'inventory_movements', '`product_id`, `created_at`');
  } else {
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_products_no ON products(product_no)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_products_name ON products(name)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_invoices_created_at ON invoices(created_at)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_invoice_items_invoice ON invoice_items(invoice_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_movements_product_created_at ON inventory_movements(product_id, created_at)");
  }
}

$pdo = db_connect();
ensure_schema($pdo);

// ===== Authentication & Authorization =====
function current_user(PDO $pdo): ?array {
  if (empty($_SESSION['user_id'])) return null;
  $stmt = $pdo->prepare('SELECT id, username, role, is_active FROM users WHERE id = ?');
  $stmt->execute([(int)$_SESSION['user_id']]);
  $u = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$u) return null;
  if ((int)($u['is_active'] ?? 1) !== 1) {
    // حساب موقوف
    session_destroy();
    session_start();
    flash_set('warning', 'تم تعطيل حسابك.');
    header('Location: login.php');
    exit;
  }
  return $u;
}

function require_login(PDO $pdo): void {
  if (!current_user($pdo)) {
    header('Location: login.php');
    exit;
  }
}

function require_role(PDO $pdo, array $roles): void {
  $u = current_user($pdo);
  if (!$u) {
    header('Location: login.php');
    exit;
  }
  if (!in_array((string)$u['role'], $roles, true)) {
    http_response_code(403);
    echo "<meta charset='utf-8'><div style='font-family:Tahoma,Arial;direction:rtl;padding:16px'>ليس لديك صلاحية للوصول لهذه الصفحة.</div>";
    exit;
  }
}

function user_id(PDO $pdo): ?int {
  $u = current_user($pdo);
  return $u ? (int)$u['id'] : null;
}

function is_manager(PDO $pdo): bool {
  $u = current_user($pdo);
  return $u && ($u['role'] === 'مدير');
}

function is_cashier(PDO $pdo): bool {
  $u = current_user($pdo);
  return $u && ($u['role'] === 'كاشير');
}

function is_stock(PDO $pdo): bool {
  $u = current_user($pdo);
  return $u && ($u['role'] === 'مخزن');
}

// ===== مساعدات مخزون =====
function add_movement(PDO $pdo, int $product_id, string $type, int $qty_change, int $qty_before, int $qty_after, ?string $note, ?int $created_by, ?int $ref_invoice_id = null, ?string $ref_invoice_no = null): void {
  $stmt = $pdo->prepare("INSERT INTO inventory_movements (product_id, movement_type, qty_change, qty_before, qty_after, note, created_at, created_by, ref_invoice_id, ref_invoice_no)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
  $stmt->execute([
    $product_id,
    $type,
    $qty_change,
    $qty_before,
    $qty_after,
    $note,
    now_ts(),
    $created_by,
    $ref_invoice_id,
    $ref_invoice_no,
  ]);
}

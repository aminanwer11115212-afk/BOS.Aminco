<?php
// header.php
require_once __DIR__ . '/config.php';
$user = current_user($pdo);

// حساب عدد الأصناف تحت الحد (لإظهار تنبيه بسيط في القائمة)
$low_stock_count = 0;
if ($user) {
  $low_stock_count = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE quantity <= min_qty")->fetchColumn();
}
?>
<!doctype html>
<html lang="ar" dir="rtl" data-bs-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title ?? ''); ?> | <?= h(STORE_NAME); ?></title>

  <!-- set theme early to avoid flash -->
  <script>
    (function() {
      try {
        const stored = localStorage.getItem('theme');
        const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        const theme = stored || (prefersDark ? 'dark' : 'light');
        document.documentElement.setAttribute('data-bs-theme', theme);
      } catch (e) {}
    })();
  </script>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="assets/styles.css">
</head>
<body class="bg-body-tertiary">

<nav class="navbar navbar-expand-lg bg-body border-bottom sticky-top">
  <div class="container">
    <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="index.php">
      <span class="brand-mark">⚙️</span>
      <span><?= h(STORE_NAME); ?></span>
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="nav">
      <?php if ($user): ?>
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item"><a class="nav-link" href="index.php">الرئيسية</a></li>

        <?php if (in_array($user['role'], ['مدير','مخزن'], true)): ?>
          <li class="nav-item">
            <a class="nav-link" href="products.php">
              المخزن
              <?php if ($low_stock_count > 0): ?>
                <span class="badge text-bg-warning ms-1" title="أصناف تحت الحد"><?= (int)$low_stock_count; ?></span>
              <?php endif; ?>
            </a>
          </li>
        <?php endif; ?>

        <?php if (in_array($user['role'], ['مدير','كاشير'], true)): ?>
          <li class="nav-item"><a class="nav-link" href="sales.php">فاتورة جديدة</a></li>
          <li class="nav-item"><a class="nav-link" href="invoices.php">إدارة الفواتير</a></li>
        <?php endif; ?>

        <?php if (in_array($user['role'], ['مدير','مخزن'], true)): ?>
          <li class="nav-item"><a class="nav-link" href="movements.php">حركة المخزون</a></li>
        <?php endif; ?>

        <?php if (in_array($user['role'], ['مدير'], true)): ?>
          <li class="nav-item"><a class="nav-link" href="reports.php">التقارير</a></li>
          <li class="nav-item"><a class="nav-link" href="users.php">المستخدمون</a></li>
        <?php endif; ?>
      </ul>
      <?php endif; ?>

      <div class="d-flex align-items-center gap-2 ms-auto">
        <button class="btn btn-outline-secondary btn-sm" id="themeToggle" type="button" title="وضع مظلم/فاتح">
          <i class="bi bi-moon-stars"></i>
        </button>

        <?php if ($user): ?>
          <span class="text-secondary small d-none d-lg-inline">
            المستخدم: <strong><?= h($user['username']); ?></strong> (<?= h($user['role']); ?>)
          </span>
          <a class="btn btn-outline-secondary btn-sm" href="logout.php">تسجيل الخروج</a>
        <?php else: ?>
          <a class="btn btn-outline-primary btn-sm" href="login.php">تسجيل الدخول</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</nav>

<main class="container py-4">
<?php foreach (flash_get_all() as $f): ?>
  <div class="alert alert-<?= h($f['type']); ?> alert-dismissible fade show" role="alert">
    <?= h($f['msg']); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endforeach; ?>

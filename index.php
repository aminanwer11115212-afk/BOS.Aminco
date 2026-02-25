<?php
require_once __DIR__ . '/config.php';
require_login($pdo);

$title = 'الرئيسية';
$user = current_user($pdo);

// مؤشرات سريعة
$stats = [
  'products' => 0,
  'low_stock' => 0,
  'today_invoices' => 0,
  'today_revenue' => 0,
];

try {
  $stats['products'] = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
  $stats['low_stock'] = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE quantity <= min_qty")->fetchColumn();
  $today = (new DateTimeImmutable('now'))->format('Y-m-d');
  $stmt = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(total),0) FROM invoices WHERE status='posted' AND created_at >= ? AND created_at <= ?");
  $stmt->execute([$today.' 00:00:00', $today.' 23:59:59']);
  $row = $stmt->fetch(PDO::FETCH_NUM) ?: [0,0];
  $stats['today_invoices'] = (int)($row[0] ?? 0);
  $stats['today_revenue'] = (float)($row[1] ?? 0);
} catch (Throwable $e) {
  // تجاهل لو DB جديد
}

include __DIR__ . '/header.php';
?>

<div class="card shadow-sm">
  <div class="card-body">
    <h1 class="h4 mb-3">لوحة التحكم</h1>

    <div class="row g-3">
      <div class="col-12 col-md-4">
        <div class="p-3 bg-body rounded border">
          <div class="text-secondary small">المستخدم</div>
          <div class="fw-bold"><?= h($user['username'] ?? ''); ?></div>
          <div class="text-secondary small">الدور: <?= h($user['role'] ?? ''); ?></div>
        </div>
      </div>

      <div class="col-12 col-md-4">
        <div class="p-3 bg-body rounded border">
          <div class="text-secondary small">القطع</div>
          <div class="fw-bold"><?= (int)$stats['products']; ?></div>
          <div class="text-secondary small">تحت الحد: <?= (int)$stats['low_stock']; ?></div>
        </div>
      </div>

      <div class="col-12 col-md-4">
        <div class="p-3 bg-body rounded border">
          <div class="text-secondary small">مبيعات اليوم</div>
          <div class="fw-bold"><?= (int)$stats['today_invoices']; ?> فاتورة</div>
          <div class="text-secondary small">الإيراد: <?= money_fmt($stats['today_revenue']); ?></div>
        </div>
      </div>
    </div>

    <div class="mt-4 d-flex flex-wrap gap-2">
      <?php if (in_array((string)$user['role'], ['مدير','مخزن'], true)): ?>
        <a class="btn btn-outline-primary" href="products.php"><i class="bi bi-box-seam"></i> المخزن</a>
        <a class="btn btn-outline-secondary" href="movements.php"><i class="bi bi-arrow-left-right"></i> حركة المخزون</a>
      <?php endif; ?>

      <?php if (in_array((string)$user['role'], ['مدير','كاشير'], true)): ?>
        <a class="btn btn-outline-success" href="sales.php"><i class="bi bi-receipt"></i> فاتورة جديدة</a>
        <a class="btn btn-outline-dark" href="invoices.php"><i class="bi bi-archive"></i> إدارة الفواتير</a>
      <?php endif; ?>

      <?php if ((string)$user['role'] === 'مدير'): ?>
        <a class="btn btn-outline-warning" href="reports.php"><i class="bi bi-graph-up"></i> التقارير</a>
        <a class="btn btn-outline-info" href="users.php"><i class="bi bi-people"></i> المستخدمون</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>

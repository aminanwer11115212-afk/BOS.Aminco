<?php
require_once __DIR__ . '/config.php';
require_login($pdo);
require_role($pdo, ['مدير']);

$title = 'التقارير';

$start = trim($_GET['start'] ?? '');
$end = trim($_GET['end'] ?? '');

function parse_date(string $s): ?DateTimeImmutable {
  try {
    if ($s === '') return null;
    return new DateTimeImmutable($s);
  } catch (Exception $e) {
    return null;
  }
}

$start_dt = parse_date($start) ?: (new DateTimeImmutable('now'))->modify('-30 days');
$end_dt = parse_date($end) ?: (new DateTimeImmutable('now'));

if ($end_dt < $start_dt) {
  $tmp = $start_dt; $start_dt = $end_dt; $end_dt = $tmp;
}

$start_iso = $start_dt->format('Y-m-d');
$end_iso = $end_dt->format('Y-m-d');

$start_ts = $start_dt->setTime(0,0,0)->format('Y-m-d H:i:s');
$end_ts = $end_dt->setTime(23,59,59)->format('Y-m-d H:i:s');

// ===== ملخص المخزن =====
$inv_summary = [
  'products_count' => (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn(),
  'total_qty' => (int)$pdo->query("SELECT COALESCE(SUM(quantity),0) FROM products")->fetchColumn(),
];

$low_stock = $pdo->query("SELECT id, name, product_no, quantity, min_qty FROM products WHERE quantity <= min_qty ORDER BY quantity ASC, name ASC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);

// ===== تقرير الفواتير (مبيعات) =====
$stmt = $pdo->prepare("SELECT COUNT(*) AS cnt, COALESCE(SUM(total),0) AS revenue, COALESCE(SUM(discount),0) AS discount_sum
                       FROM invoices
                       WHERE status='posted' AND created_at >= ? AND created_at <= ?");
$stmt->execute([$start_ts, $end_ts]);
$invTotals = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['cnt'=>0,'revenue'=>0,'discount_sum'=>0];

// ===== ملخص الدفع =====
$stmt = $pdo->prepare("SELECT COALESCE(SUM(paid_cash),0) AS cash_sum,
                              COALESCE(SUM(paid_electronic),0) AS electronic_sum
                       FROM invoices
                       WHERE status='posted' AND created_at >= ? AND created_at <= ?");
$stmt->execute([$start_ts, $end_ts]);
$payTotals = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['cash_sum'=>0,'electronic_sum'=>0];

$stmt = $pdo->prepare("SELECT COALESCE(NULLIF(electronic_method,''), 'غير محدد') AS method,
                              COUNT(*) AS cnt,
                              COALESCE(SUM(paid_electronic),0) AS sum_elec
                       FROM invoices
                       WHERE status='posted' AND created_at >= ? AND created_at <= ? AND COALESCE(paid_electronic,0) > 0
                       GROUP BY method
                       ORDER BY sum_elec DESC");
$stmt->execute([$start_ts, $end_ts]);
$electronic_breakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT COALESCE(SUM(ii.qty),0) AS sold_qty
                       FROM invoice_items ii
                       JOIN invoices i ON i.id = ii.invoice_id
                       WHERE i.status='posted' AND i.created_at >= ? AND i.created_at <= ?");
$stmt->execute([$start_ts, $end_ts]);
$sold_qty = (int)$stmt->fetchColumn();

// آخر 50 فاتورة
$stmt = $pdo->prepare("SELECT id, invoice_no, customer_name, total, created_at
                       FROM invoices
                       WHERE status='posted' AND created_at >= ? AND created_at <= ?
                       ORDER BY created_at DESC
                       LIMIT 50");
$stmt->execute([$start_ts, $end_ts]);
$recent_invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// أفضل 10 أصناف مبيعاً
$stmt = $pdo->prepare("SELECT ii.part_no_snapshot AS product_no, ii.name_snapshot AS name, SUM(ii.qty) AS qty_sum
                       FROM invoice_items ii
                       JOIN invoices i ON i.id = ii.invoice_id
                       WHERE i.status='posted' AND i.created_at >= ? AND i.created_at <= ?
                       GROUP BY ii.part_no_snapshot, ii.name_snapshot
                       ORDER BY qty_sum DESC
                       LIMIT 10");
$stmt->execute([$start_ts, $end_ts]);
$top_products = $stmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/header.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
  <h1 class="h4 m-0">التقارير</h1>
  <form class="d-flex gap-2" method="get">
    <input class="form-control form-control-sm" type="date" name="start" value="<?= h($start_iso); ?>">
    <input class="form-control form-control-sm" type="date" name="end" value="<?= h($end_iso); ?>">
    <button class="btn btn-sm btn-outline-primary"><i class="bi bi-calendar2-week"></i> تطبيق</button>
  </form>
  <a class="btn btn-sm btn-dark" target="_blank" rel="noopener" href="reports_print.php?start=<?= h($start_iso); ?>&amp;end=<?= h($end_iso); ?>"><i class="bi bi-printer"></i> طباعة التقرير (A4)</a>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-4">
    <div class="card shadow-sm">
      <div class="card-body">
        <h2 class="h6 mb-3">ملخص المخزن</h2>

        <div class="p-3 bg-body rounded border mb-2">
          <div class="text-secondary small">عدد القطع</div>
          <div class="fw-bold"><?= (int)$inv_summary['products_count']; ?></div>
        </div>

        <div class="p-3 bg-body rounded border">
          <div class="text-secondary small">إجمالي الكميات</div>
          <div class="fw-bold"><?= (int)$inv_summary['total_qty']; ?></div>
        </div>

        <hr>

        <h3 class="h6">قطع تحت حد التنبيه</h3>
        <?php if ($low_stock): ?>
          <ul class="list-group">
            <?php foreach ($low_stock as $p): ?>
            <li class="list-group-item d-flex justify-content-between align-items-center">
              <span><?= h($p['name']); ?> <span class="text-secondary small">(<?= h($p['product_no']); ?>)</span></span>
              <span class="badge text-bg-warning"><?= (int)$p['quantity']; ?> / <?= (int)$p['min_qty']; ?></span>
            </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <div class="text-secondary">لا يوجد.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-8">
    <div class="card shadow-sm">
      <div class="card-body">
        <h2 class="h6 mb-3">تقرير المبيعات (<?= h($start_iso); ?> → <?= h($end_iso); ?>)</h2>

        <div class="row g-2 mb-3">
          <div class="col-6 col-md-3">
            <div class="p-3 bg-body border rounded">
              <div class="text-secondary small">عدد الفواتير</div>
              <div class="fw-bold"><?= (int)($invTotals['cnt'] ?? 0); ?></div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="p-3 bg-body border rounded">
              <div class="text-secondary small">إجمالي الكميات المباعة</div>
              <div class="fw-bold"><?= (int)$sold_qty; ?></div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="p-3 bg-body border rounded">
              <div class="text-secondary small">الإيراد (صافي)</div>
              <div class="fw-bold"><?= money_fmt($invTotals['revenue'] ?? 0); ?></div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="p-3 bg-body border rounded">
              <div class="text-secondary small">إجمالي الخصومات</div>
              <div class="fw-bold"><?= money_fmt($invTotals['discount_sum'] ?? 0); ?></div>
            </div>
          </div>
        
        <div class="row g-2 mb-3">
          <div class="col-6 col-md-3">
            <div class="p-3 bg-body border rounded">
              <div class="text-secondary small">مدفوع كاش</div>
              <div class="fw-bold"><?= money_fmt($payTotals['cash_sum'] ?? 0); ?></div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="p-3 bg-body border rounded">
              <div class="text-secondary small">مدفوع إلكتروني</div>
              <div class="fw-bold"><?= money_fmt($payTotals['electronic_sum'] ?? 0); ?></div>
            </div>
          </div>
        </div>
</div>

        <div class="row g-3">
          <div class="col-12 col-lg-6">
            <h3 class="h6">أفضل القطع مبيعاً</h3>
            <?php if ($top_products): ?>
              <div class="table-responsive">
                <table class="table table-sm align-middle">
                  <thead><tr><th>القطعة</th><th class="text-center">الكمية</th></tr></thead>
                  <tbody>
                    <?php foreach ($top_products as $tp): ?>
                    <tr>
                      <td>
                        <div class="fw-semibold"><?= h($tp['name']); ?></div>
                        <div class="text-secondary small"><code><?= h($tp['product_no']); ?></code></div>
                      </td>
                      <td class="text-center"><span class="badge text-bg-dark"><?= (int)$tp['qty_sum']; ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php else: ?>
              <div class="text-secondary">لا يوجد بيانات.</div>
            <?php endif; ?>
          </div>

          <div class="col-12 col-lg-6">
            <h3 class="h6">آخر الفواتير</h3>
            <?php if ($recent_invoices): ?>
              <div class="table-responsive">
                <table class="table table-sm align-middle">
                  <thead><tr><th>رقم</th><th>التاريخ</th><th class="text-end">الإجمالي</th></tr></thead>
                  <tbody>
                    <?php foreach ($recent_invoices as $inv): ?>
                    <tr>
                      <td><a href="invoice_print.php?id=<?= (int)$inv['id']; ?>"><code><?= h($inv['invoice_no']); ?></code></a></td>
                      <td class="text-secondary small"><?= h($inv['created_at']); ?></td>
                      <td class="text-end fw-bold"><?= money_fmt($inv['total']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php else: ?>
              <div class="text-secondary">لا توجد فواتير في هذه الفترة.</div>
            <?php endif; ?>
          </div>

        <hr class="mt-4">

        <h3 class="h6">تفصيل المدفوعات الإلكترونية</h3>
        <?php if (!empty($electronic_breakdown)): ?>
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead>
                <tr>
                  <th>الطريقة</th>
                  <th class="text-center">عدد الفواتير</th>
                  <th class="text-end">الإجمالي</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($electronic_breakdown as $row): ?>
                <?php $lbl = electronic_method_label((string)$row['method']); ?>
                <tr>
                  <td class="fw-semibold"><?= h($lbl !== '' ? $lbl : (string)$row['method']); ?></td>
                  <td class="text-center"><span class="badge text-bg-dark"><?= (int)$row['cnt']; ?></span></td>
                  <td class="text-end fw-bold"><?= money_fmt($row['sum_elec']); ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="text-secondary">لا توجد مدفوعات إلكترونية في هذه الفترة.</div>
        <?php endif; ?>


        </div>

      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>

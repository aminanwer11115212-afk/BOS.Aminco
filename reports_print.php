<?php
require_once __DIR__ . '/config.php';
require_login($pdo);
require_role($pdo, ['مدير']);

$title = 'طباعة التقرير';

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

$low_stock = $pdo->query("SELECT id, name, product_no, quantity, min_qty FROM products WHERE quantity <= min_qty ORDER BY quantity ASC, name ASC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);

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

// آخر 200 فاتورة (للطباعة)
$stmt = $pdo->prepare("SELECT id, invoice_no, customer_name, total, created_at
                       FROM invoices
                       WHERE status='posted' AND created_at >= ? AND created_at <= ?
                       ORDER BY created_at DESC
                       LIMIT 200");
$stmt->execute([$start_ts, $end_ts]);
$recent_invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// أفضل 30 صنف مبيعاً (للطباعة)
$stmt = $pdo->prepare("SELECT ii.part_no_snapshot AS product_no, ii.name_snapshot AS name, SUM(ii.qty) AS qty_sum
                       FROM invoice_items ii
                       JOIN invoices i ON i.id = ii.invoice_id
                       WHERE i.status='posted' AND i.created_at >= ? AND i.created_at <= ?
                       GROUP BY ii.part_no_snapshot, ii.name_snapshot
                       ORDER BY qty_sum DESC
                       LIMIT 30");
$stmt->execute([$start_ts, $end_ts]);
$top_products = $stmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/header.php';
?>

<style>
  /* تثبيت مقاس A4 للطباعة + تحسين الجداول */
  @media print{
    @page{ size: A4; margin: 10mm; }
    .container{ max-width: none !important; }
    .card{ border: 1px solid rgba(0,0,0,0.18) !important; }
    table{ page-break-inside: auto; }
    thead{ display: table-header-group; }
    tfoot{ display: table-footer-group; }
    tr{ page-break-inside: avoid; page-break-after: auto; }
  }
</style>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3 no-print">
  <div>
    <h1 class="h5 m-0">طباعة التقرير</h1>
    <div class="text-secondary small">الفترة: <?= h($start_iso); ?> → <?= h($end_iso); ?></div>
  </div>
  <div class="d-flex gap-2">
    <button class="btn btn-dark" type="button" onclick="window.print()"><i class="bi bi-printer"></i> طباعة (A4)</button>
    <a class="btn btn-outline-secondary" href="reports.php?start=<?= h($start_iso); ?>&amp;end=<?= h($end_iso); ?>"><i class="bi bi-arrow-return-right"></i> رجوع</a>
  </div>
</div>

<div class="text-center mb-3">
  <div class="fw-bold"><?= h(STORE_NAME); ?></div>
  <div class="text-secondary">تقرير الفترة: <?= h($start_iso); ?> → <?= h($end_iso); ?></div>
  <div class="text-secondary small">تاريخ الطباعة: <?= h(now_ts()); ?></div>
</div>

<div class="row g-3">

  <!-- ملخص المخزن -->
  <div class="col-12 col-lg-4">
    <div class="card shadow-sm">
      <div class="card-body">
        <h2 class="h6 mb-3">ملخص المخزن</h2>

        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <tbody>
              <tr>
                <td class="text-secondary">عدد القطع</td>
                <td class="text-end fw-bold"><?= (int)$inv_summary['products_count']; ?></td>
              </tr>
              <tr>
                <td class="text-secondary">إجمالي الكميات</td>
                <td class="text-end fw-bold"><?= (int)$inv_summary['total_qty']; ?></td>
              </tr>
            </tbody>
          </table>
        </div>

        <hr>

        <h3 class="h6 mb-2">قطع تحت حد التنبيه</h3>
        <?php if ($low_stock): ?>
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead>
                <tr>
                  <th>القطعة</th>
                  <th class="text-center">الكمية</th>
                  <th class="text-center">حد التنبيه</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($low_stock as $p): ?>
                <tr>
                  <td>
                    <div class="fw-semibold"><?= h($p['name']); ?></div>
                    <div class="text-secondary small"><code><?= h($p['product_no']); ?></code></div>
                  </td>
                  <td class="text-center fw-bold"><?= (int)$p['quantity']; ?></td>
                  <td class="text-center text-secondary"><?= (int)$p['min_qty']; ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="text-secondary">لا يوجد.</div>
        <?php endif; ?>

      </div>
    </div>
  </div>

  <!-- تقرير المبيعات -->
  <div class="col-12 col-lg-8">
    <div class="card shadow-sm">
      <div class="card-body">
        <h2 class="h6 mb-3">تقرير المبيعات (<?= h($start_iso); ?> → <?= h($end_iso); ?>)</h2>

        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <tbody>
              <tr>
                <td class="text-secondary">عدد الفواتير</td>
                <td class="text-end fw-bold"><?= (int)($invTotals['cnt'] ?? 0); ?></td>
              </tr>
              <tr>
                <td class="text-secondary">إجمالي الكميات المباعة</td>
                <td class="text-end fw-bold"><?= (int)$sold_qty; ?></td>
              </tr>
              <tr>
                <td class="text-secondary">الإيراد (صافي)</td>
                <td class="text-end fw-bold"><?= money_fmt($invTotals['revenue'] ?? 0); ?></td>
              </tr>
              <tr>
                <td class="text-secondary">إجمالي الخصومات</td>
                <td class="text-end fw-bold"><?= money_fmt($invTotals['discount_sum'] ?? 0); ?></td>
              </tr>
              <tr>
                <td class="text-secondary">مدفوع كاش</td>
                <td class="text-end fw-bold"><?= money_fmt($payTotals['cash_sum'] ?? 0); ?></td>
              </tr>
              <tr>
                <td class="text-secondary">مدفوع إلكتروني</td>
                <td class="text-end fw-bold"><?= money_fmt($payTotals['electronic_sum'] ?? 0); ?></td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="row g-3 mt-1">

          <div class="col-12 col-lg-6">
            <h3 class="h6">أفضل القطع مبيعاً</h3>
            <?php if ($top_products): ?>
              <div class="table-responsive">
                <table class="table table-sm align-middle">
                  <thead>
                    <tr>
                      <th>القطعة</th>
                      <th class="text-center">الكمية</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($top_products as $tp): ?>
                    <tr>
                      <td>
                        <div class="fw-semibold"><?= h($tp['name']); ?></div>
                        <div class="text-secondary small"><code><?= h($tp['product_no']); ?></code></div>
                      </td>
                      <td class="text-center fw-bold"><?= (int)$tp['qty_sum']; ?></td>
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
                  <thead>
                    <tr>
                      <th>رقم</th>
                      <th>التاريخ</th>
                      <th class="text-end">الإجمالي</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($recent_invoices as $inv): ?>
                    <tr>
                      <td><code><?= h($inv['invoice_no']); ?></code></td>
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

        </div>

        <hr class="mt-3">

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
                  <td class="text-center fw-bold"><?= (int)$row['cnt']; ?></td>
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

<?php include __DIR__ . '/footer.php'; ?>

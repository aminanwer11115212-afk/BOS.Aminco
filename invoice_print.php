<?php
require_once __DIR__ . '/config.php';
require_login($pdo);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  flash_set('warning', 'رقم فاتورة غير صحيح.');
  header('Location: invoices.php');
  exit;
}

$stmt = $pdo->prepare(
  "SELECT i.*, u.username AS created_by_name, c.username AS canceled_by_name
   FROM invoices i
   LEFT JOIN users u ON u.id = i.created_by
   LEFT JOIN users c ON c.id = i.canceled_by
   WHERE i.id = ?"
);
$stmt->execute([$id]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$invoice) {
  flash_set('danger', 'الفاتورة غير موجودة.');
  header('Location: invoices.php');
  exit;
}

$itemStmt = $pdo->prepare(
  "SELECT ii.part_no_snapshot, ii.name_snapshot,
          COALESCE(NULLIF(ii.car_type_snapshot,''), p.car_type) AS car_type_snapshot,
          COALESCE(NULLIF(ii.car_brand_snapshot,''), p.car_brand) AS car_brand_snapshot,
          ii.unit_price_snapshot, ii.qty, ii.line_total
   FROM invoice_items ii
   LEFT JOIN products p ON p.id = ii.product_id
   WHERE ii.invoice_id = ?
   ORDER BY ii.id ASC"
);
$itemStmt->execute([$id]);
$items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

$title = 'طباعة فاتورة ' . ($invoice['invoice_no'] ?? '');
include __DIR__ . '/header.php';

$status = (string)($invoice['status'] ?? 'posted');
$is_canceled = ($status === 'canceled');

// عند إنشاء فاتورة جديدة من شاشة البيع، نفتح نافذة اختيار مقاس الطباعة تلقائياً
$is_new = (isset($_GET['new']) && (string)($_GET['new'] ?? '') !== '' && (string)($_GET['new'] ?? '') !== '0');

// حسابات الدفع (مفيدة لعرضها في أكثر من قالب)
$total_c = (int)round(((float)($invoice['total'] ?? 0)) * 100);
$cash_c = (int)round(((float)($invoice['paid_cash'] ?? 0)) * 100);
$elec_c = (int)round(((float)($invoice['paid_electronic'] ?? 0)) * 100);
$remain = ($total_c - $cash_c - $elec_c) / 100;
$ptype = (string)($invoice['payment_type'] ?? 'cash');
$emethod = (string)($invoice['electronic_method'] ?? '');
?>

<!-- Dynamic @page size for receipt printing -->
<style id="pageSizeStyle"></style>

<?php if ($is_new): ?>
  <!-- Print Size Modal (shown automatically after saving a new invoice) -->
  <div class="modal fade no-print" id="printSizeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">اختيار مقاس الطباعة</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="d-grid gap-2">
            <button type="button" class="btn btn-dark" data-print-size="71.2" data-print-height="210">طباعة إيصال 210×71.2mm</button>
            <button type="button" class="btn btn-dark" data-print-size="80" data-print-height="210">طباعة إيصال 210×80mm</button>
            <button type="button" class="btn btn-dark" data-print-size="90" data-print-height="210">طباعة إيصال 210×90mm</button>
            <button type="button" class="btn btn-outline-primary" data-print-size="A4">طباعة A4</button>
          </div>
          <div class="text-secondary small mt-2">يمكنك الطباعة لاحقاً من نفس الصفحة.</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">لاحقاً</button>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="invoice-watermark <?= $is_canceled ? 'show' : ''; ?>">ملغاة</div>

<!-- القالب العادي (للعرض على الشاشة + طباعة عادية) -->
<div class="invoice-box invoice-standard">
  <div class="card shadow-sm">
    <div class="card-body invoice-content">
      <div class="invoice-header">
        <div>
          <h1 class="h4 m-0">فاتورة</h1>
          <div class="text-secondary"><?= h(STORE_NAME); ?></div>
          <?php if (!empty($invoice['customer_name'])): ?>
            <div class="mt-2"><span class="text-secondary">العميل:</span> <strong><?= h((string)$invoice['customer_name']); ?></strong></div>
          <?php endif; ?>
          <div class="text-secondary small mt-1">أنشأها: <?= h((string)($invoice['created_by_name'] ?? '')); ?></div>
        </div>

        <div class="invoice-meta">
          <div><span class="text-secondary">رقم الفاتورة:</span> <code><?= h((string)$invoice['invoice_no']); ?></code></div>
          <div><span class="text-secondary">التاريخ:</span> <?= h((string)$invoice['created_at']); ?></div>
          <div><span class="text-secondary">الدفع:</span> <?= h(payment_type_label((string)($invoice['payment_type'] ?? 'cash'))); ?><?php if (!empty($invoice['electronic_method']) && (string)($invoice['payment_type'] ?? '') !== 'cash'): ?> — <?= h(electronic_method_label((string)$invoice['electronic_method'])); ?><?php endif; ?></div>
          <div class="mt-2">
            <?php if ($is_canceled): ?>
              <span class="badge text-bg-danger">ملغاة</span>
            <?php else: ?>
              <span class="badge text-bg-success">فعّالة</span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <?php if ($is_canceled): ?>
        <div class="alert alert-warning mt-3">
          تم إلغاء هذه الفاتورة.
          <?php if (!empty($invoice['canceled_at'])): ?>
            <div class="small text-secondary mt-1">تاريخ الإلغاء: <?= h((string)$invoice['canceled_at']); ?> — بواسطة: <?= h((string)($invoice['canceled_by_name'] ?? '')); ?></div>
          <?php endif; ?>
          <?php if (!empty($invoice['cancel_note'])): ?>
            <div class="small mt-1">السبب: <?= h((string)$invoice['cancel_note']); ?></div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <hr>

      <div class="table-responsive">
        <table class="table align-middle">
          <thead>
            <tr>
              <th>#</th>
              <th>رقم القطعة</th>
              <th>اسم القطعة</th>
              <th>النوع/الماركة</th>
              <th class="text-end">سعر الوحدة</th>
              <th class="text-center">الكمية</th>
              <th class="text-end">الإجمالي</th>
            </tr>
          </thead>
          <tbody>
            <?php $i=1; foreach ($items as $it): ?>
            <tr>
              <td><?= $i++; ?></td>
              <td><code><?= h((string)$it['part_no_snapshot']); ?></code></td>
              <td><?= h((string)$it['name_snapshot']); ?></td>
              <td class="text-secondary small"><?= h((string)($it['car_type_snapshot'] ?? '')); ?><?= !empty($it['car_brand_snapshot']) ? ' — ' . h((string)$it['car_brand_snapshot']) : ''; ?></td>
              <td class="text-end"><?= money_fmt($it['unit_price_snapshot']); ?></td>
              <td class="text-center"><?= (int)$it['qty']; ?></td>
              <td class="text-end fw-semibold"><?= money_fmt($it['line_total']); ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="row justify-content-end">
        <div class="col-12 col-md-5">
          <div class="p-3 border rounded bg-body">
            <div class="d-flex justify-content-between">
              <span class="text-secondary">الإجمالي</span>
              <span class="fw-bold"><?= money_fmt($invoice['subtotal']); ?></span>
            </div>
            <div class="d-flex justify-content-between mt-1">
              <span class="text-secondary">خصم</span>
              <span><?= money_fmt($invoice['discount']); ?></span>
            </div>
            <div class="d-flex justify-content-between mt-1">
              <span class="text-secondary">ضريبة</span>
              <span><?= money_fmt($invoice['tax']); ?></span>
            </div>
            <hr>
            <div class="d-flex justify-content-between">
              <span class="fw-bold">الصافي</span>
              <span class="fw-bold"><?= money_fmt($invoice['total']); ?></span>
            </div>

            <hr>

            <div class="d-flex justify-content-between">
              <span class="text-secondary">طريقة الدفع</span>
              <span class="fw-semibold"><?= h(payment_type_label($ptype)); ?></span>
            </div>

            <?php if ($ptype !== 'cash' && $emethod !== ''): ?>
              <div class="d-flex justify-content-between mt-1">
                <span class="text-secondary">الطريقة الإلكترونية</span>
                <span><?= h(electronic_method_label($emethod)); ?></span>
              </div>
            <?php endif; ?>

            <div class="d-flex justify-content-between mt-1">
              <span class="text-secondary">مدفوع كاش</span>
              <span><?= money_fmt($invoice['paid_cash'] ?? 0); ?></span>
            </div>
            <div class="d-flex justify-content-between mt-1">
              <span class="text-secondary">مدفوع إلكتروني</span>
              <span><?= money_fmt($invoice['paid_electronic'] ?? 0); ?></span>
            </div>
            <div class="d-flex justify-content-between mt-1">
              <span class="text-secondary">المتبقي</span>
              <span class="<?= (abs($remain) > 0.01) ? 'text-danger fw-bold' : 'text-success fw-semibold'; ?>"><?= money_fmt($remain); ?></span>
            </div>

          </div>
        </div>
      </div>

      <div class="text-center text-secondary small mt-4">شكراً لتعاملكم معنا</div>
    </div>
  </div>
</div>

<!-- قالب الإيصال (مخصص لمقاسات 210×71.2 / 210×80 / 210×90) -->
<div class="receipt-box invoice-receipt">
  <div class="card shadow-sm">
    <div class="card-body receipt-content">
      <div class="text-center mb-2">
        <div class="fw-bold"><?= h(STORE_NAME); ?></div>
        <div class="small">فاتورة: <code><?= h((string)$invoice['invoice_no']); ?></code></div>
        <div class="small text-secondary"><?= h((string)$invoice['created_at']); ?></div>
      </div>

      <?php if (!empty($invoice['customer_name'])): ?>
        <div class="small"><span class="text-secondary">العميل:</span> <span class="fw-semibold"><?= h((string)$invoice['customer_name']); ?></span></div>
      <?php endif; ?>

      <div class="small text-secondary mt-1">أنشأها: <?= h((string)($invoice['created_by_name'] ?? '')); ?></div>

      <?php if ($is_canceled): ?>
        <div class="alert alert-warning py-2 px-2 mt-2 mb-2">
          <div class="small">تم إلغاء هذه الفاتورة.</div>
          <?php if (!empty($invoice['cancel_note'])): ?>
            <div class="small mt-1">السبب: <?= h((string)$invoice['cancel_note']); ?></div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <hr class="my-2">

      <table class="table table-sm table-borderless receipt-table mb-2">
        <thead>
          <tr>
            <th>الصنف</th>
            <th class="text-center">ك</th>
            <th class="text-end">س</th>
            <th class="text-end">ج</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $it): ?>
            <tr>
              <td>
                <div class="fw-semibold"><?= h((string)$it['name_snapshot']); ?></div>
                <div class="text-secondary small"><code><?= h((string)$it['part_no_snapshot']); ?></code></div>
              </td>
              <td class="text-center"><?= (int)$it['qty']; ?></td>
              <td class="text-end"><?= money_fmt($it['unit_price_snapshot']); ?></td>
              <td class="text-end"><?= money_fmt($it['line_total']); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <hr class="my-2">

      <div class="d-flex justify-content-between"><span class="text-secondary">الإجمالي</span><span><?= money_fmt($invoice['subtotal']); ?></span></div>
      <div class="d-flex justify-content-between"><span class="text-secondary">خصم</span><span><?= money_fmt($invoice['discount']); ?></span></div>
      <div class="d-flex justify-content-between"><span class="text-secondary">ضريبة</span><span><?= money_fmt($invoice['tax']); ?></span></div>
      <hr class="my-2">
      <div class="d-flex justify-content-between"><span class="fw-bold">الصافي</span><span class="fw-bold"><?= money_fmt($invoice['total']); ?></span></div>

      <hr class="my-2">

      <div class="d-flex justify-content-between"><span class="text-secondary">الدفع</span><span class="fw-semibold"><?= h(payment_type_label($ptype)); ?></span></div>
      <?php if ($ptype !== 'cash' && $emethod !== ''): ?>
        <div class="d-flex justify-content-between"><span class="text-secondary">الطريقة</span><span><?= h(electronic_method_label($emethod)); ?></span></div>
      <?php endif; ?>
      <div class="d-flex justify-content-between"><span class="text-secondary">مدفوع كاش</span><span><?= money_fmt($invoice['paid_cash'] ?? 0); ?></span></div>
      <div class="d-flex justify-content-between"><span class="text-secondary">مدفوع إلكتروني</span><span><?= money_fmt($invoice['paid_electronic'] ?? 0); ?></span></div>
      <div class="d-flex justify-content-between"><span class="text-secondary">المتبقي</span><span class="<?= (abs($remain) > 0.01) ? 'text-danger fw-bold' : 'text-success fw-semibold'; ?>"><?= money_fmt($remain); ?></span></div>

      <div class="text-center text-secondary small mt-2">شكراً لتعاملكم معنا</div>
    </div>
  </div>
</div>

<!-- أزرار الطباعة (مقاسات الإيصال) -->
<div class="mt-4 d-flex flex-wrap gap-2 no-print align-items-center">
  <button class="btn btn-dark" type="button" onclick="printReceipt(71.2, 210)"><i class="bi bi-printer"></i> طباعة 210×71.2</button>
  <button class="btn btn-dark" type="button" onclick="printReceipt(80, 210)"><i class="bi bi-printer"></i> طباعة 210×80</button>
  <button class="btn btn-dark" type="button" onclick="printReceipt(90, 210)"><i class="bi bi-printer"></i> طباعة 210×90</button>
  <button class="btn btn-outline-primary" type="button" onclick="printA4()"><i class="bi bi-printer"></i> طباعة A4</button>
  <a class="btn btn-outline-secondary" href="invoices.php"><i class="bi bi-arrow-return-right"></i> رجوع</a>
</div>

<script class="no-print">
(function(){
  const pageStyle = document.getElementById('pageSizeStyle');

  function setReceipt(widthMm, heightMm){
    document.body.classList.add('receipt-mode');
    const w = parseFloat(widthMm);
    const h = parseFloat(heightMm);
    const width = (!isNaN(w) && w > 0) ? w : 80;
    const height = (!isNaN(h) && h > 0) ? h : 210;

    document.documentElement.style.setProperty('--receipt-width', width + 'mm');
    document.documentElement.style.setProperty('--receipt-height', height + 'mm');

    if(pageStyle){
      // CSS @page expects: width height
      pageStyle.textContent = '@media print{ @page{ size:' + width + 'mm ' + height + 'mm; margin:4mm; } }';
    }
  }

  function setA4(){
    // Ensure normal (invoice-standard) template
    document.body.classList.remove('receipt-mode');
    document.documentElement.style.removeProperty('--receipt-width');
    document.documentElement.style.removeProperty('--receipt-height');
    if(pageStyle){
      pageStyle.textContent = '@media print{ @page{ size:A4; margin:10mm; } }';
    }
  }

  // دالة طباعة الإيصال بمقاس محدد
  window.printReceipt = function(widthMm, heightMm){
    setReceipt(widthMm, heightMm);
    requestAnimationFrame(function(){ window.print(); });
  };

  // دالة طباعة A4
  window.printA4 = function(){
    setA4();
    requestAnimationFrame(function(){ window.print(); });
  };

  // بعد إغلاق نافذة الطباعة: ارجع للوضع العادي (عرض الشاشة)
  window.addEventListener('afterprint', function(){
    try {
      document.body.classList.remove('receipt-mode');
      document.documentElement.style.removeProperty('--receipt-width');
      document.documentElement.style.removeProperty('--receipt-height');
      if(pageStyle) pageStyle.textContent = '';
    } catch(e) {}
  });

  // افتح نافذة اختيار المقاس بعد حفظ فاتورة جديدة
  document.addEventListener('DOMContentLoaded', function(){
    const modalEl = document.getElementById('printSizeModal');
    if(modalEl && window.bootstrap){
      try {
        const m = new bootstrap.Modal(modalEl);
        m.show();

        const btns = modalEl.querySelectorAll('[data-print-size]');
        btns.forEach(function(btn){
          btn.addEventListener('click', function(){
            try { m.hide(); } catch(e) {}
            const sizeAttr = (btn.getAttribute('data-print-size') || '').trim();
            if(sizeAttr.toUpperCase() === 'A4'){
              window.printA4();
              return;
            }
            const w = parseFloat(sizeAttr);
            const h = parseFloat(btn.getAttribute('data-print-height') || '210');
            window.printReceipt(w, h);
          });
        });
      } catch(e) {}
    }
  });
})();
</script>

<?php include __DIR__ . '/footer.php'; ?>

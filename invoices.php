<?php
require_once __DIR__ . '/config.php';
require_login($pdo);
require_role($pdo, ['مدير','كاشير']);

$title = 'إدارة الفواتير';

// ===== إلغاء فاتورة =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = $_POST['action'] ?? '';

  if ($action === 'cancel') {
    $invoice_id = (int)($_POST['invoice_id'] ?? 0);
    $note = trim($_POST['cancel_note'] ?? '');

    if ($invoice_id <= 0) {
      flash_set('warning', 'طلب غير صحيح.');
      header('Location: invoices.php');
      exit;
    }

    // جلب الفاتورة
    $stmt = $pdo->prepare("SELECT id, invoice_no, status, created_by FROM invoices WHERE id = ?");
    $stmt->execute([$invoice_id]);
    $inv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$inv) {
      flash_set('danger', 'الفاتورة غير موجودة.');
      header('Location: invoices.php');
      exit;
    }

    if ((string)$inv['status'] === 'canceled') {
      flash_set('info', 'الفاتورة ملغاة مسبقاً.');
      header('Location: invoices.php');
      exit;
    }

    $uid = user_id($pdo);
    $is_manager = is_manager($pdo);
    if (!$is_manager && (int)($inv['created_by'] ?? 0) !== (int)$uid) {
      flash_set('danger', 'لا تملك صلاحية إلغاء هذه الفاتورة.');
      header('Location: invoices.php');
      exit;
    }

    $pdo->beginTransaction();
    try {
      $items = $pdo->prepare("SELECT product_id, qty FROM invoice_items WHERE invoice_id = ?");
      $items->execute([$invoice_id]);
      $rows = $items->fetchAll(PDO::FETCH_ASSOC);

      $getQty = $pdo->prepare("SELECT quantity FROM products WHERE id = ?");
      $updQty = $pdo->prepare("UPDATE products SET quantity = quantity + ?, updated_at = ? WHERE id = ?");

      $now = now_ts();
      $invNo = (string)$inv['invoice_no'];

      foreach ($rows as $r) {
        $pid = (int)$r['product_id'];
        $qty = (int)$r['qty'];
        if ($qty <= 0) continue;

        $getQty->execute([$pid]);
        $before = (int)$getQty->fetchColumn();
        $after = $before + $qty;

        $updQty->execute([$qty, $now, $pid]);
        add_movement($pdo, $pid, 'CANCEL', $qty, $before, $after, 'إلغاء فاتورة وإرجاع مخزون', $uid, $invoice_id, $invNo);
      }

      $stmt = $pdo->prepare("UPDATE invoices SET status='canceled', canceled_at=?, canceled_by=?, cancel_note=? WHERE id=?");
      $stmt->execute([$now, $uid, $note !== '' ? $note : null, $invoice_id]);

      $pdo->commit();
      flash_set('success', 'تم إلغاء الفاتورة وإرجاع المخزون.');
    } catch (Throwable $e) {
      $pdo->rollBack();
      flash_set('danger', 'فشل إلغاء الفاتورة.');
    }

    header('Location: invoices.php');
    exit;
  }
}

// ===== فلاتر البحث =====
$q = trim($_GET['q'] ?? '');
$status = trim($_GET['status'] ?? ''); // posted|canceled|''
$start = trim($_GET['start'] ?? '');
$end = trim($_GET['end'] ?? '');

$conditions = [];
$params = [];

if ($q !== '') {
  $conditions[] = "(invoice_no LIKE ? OR customer_name LIKE ?)";
  $like = '%' . $q . '%';
  $params[] = $like;
  $params[] = $like;
}
if ($status === 'posted' || $status === 'canceled') {
  $conditions[] = "status = ?";
  $params[] = $status;
}
if ($start !== '') {
  $conditions[] = "created_at >= ?";
  $params[] = $start . " 00:00:00";
}
if ($end !== '') {
  $conditions[] = "created_at <= ?";
  $params[] = $end . " 23:59:59";
}

$where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

$stmt = $pdo->prepare(
  "SELECT i.id, i.invoice_no, i.customer_name, i.total, i.payment_type, i.electronic_method, i.status, i.created_at, u.username AS created_by_name
   FROM invoices i
   LEFT JOIN users u ON u.id = i.created_by
   $where
   ORDER BY i.created_at DESC
   LIMIT 200"
);
$stmt->execute($params);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/header.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
  <h1 class="h4 m-0">إدارة الفواتير</h1>
  <a class="btn btn-primary" href="sales.php"><i class="bi bi-plus-lg"></i> فاتورة جديدة</a>
</div>

<div class="card shadow-sm mb-3">
  <div class="card-body">
    <form class="row g-2" method="get">
      <div class="col-12 col-lg-4">
        <input class="form-control" name="q" value="<?= h($q); ?>" placeholder="بحث برقم الفاتورة أو اسم العميل">
      </div>
      <div class="col-6 col-lg-2">
        <select class="form-select" name="status">
          <option value="" <?= $status===''?'selected':''; ?>>كل الحالات</option>
          <option value="posted" <?= $status==='posted'?'selected':''; ?>>فعّالة</option>
          <option value="canceled" <?= $status==='canceled'?'selected':''; ?>>ملغاة</option>
        </select>
      </div>
      <div class="col-6 col-lg-2">
        <input class="form-control" type="date" name="start" value="<?= h($start); ?>">
      </div>
      <div class="col-6 col-lg-2">
        <input class="form-control" type="date" name="end" value="<?= h($end); ?>">
      </div>
      <div class="col-6 col-lg-2 d-grid">
        <button class="btn btn-outline-primary"><i class="bi bi-funnel"></i> تطبيق</button>
      </div>
    </form>
    <div class="form-text mt-2">يتم عرض آخر 200 فاتورة كحد أقصى.</div>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-body">
    <?php if ($invoices): ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead>
          <tr>
            <th>رقم الفاتورة</th>
            <th>التاريخ</th>
            <th>العميل</th>
            <th>أنشأها</th>
            <th class="text-end">الإجمالي</th>
            <th>الدفع</th>
            <th class="text-center">الحالة</th>
            <th class="text-end">إجراءات</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($invoices as $inv):
            $st = (string)$inv['status'];
            $badge = $st === 'canceled' ? 'danger' : 'success';
          ?>
          <tr>
            <td><code><?= h((string)$inv['invoice_no']); ?></code></td>
            <td class="text-secondary small"><?= h((string)$inv['created_at']); ?></td>
            <td><?= h((string)($inv['customer_name'] ?? '')); ?></td>
            <td class="text-secondary"><?= h((string)($inv['created_by_name'] ?? '')); ?></td>
            <td class="text-end fw-bold"><?= money_fmt($inv['total']); ?></td>
            <td class="text-secondary small">
              <?= h(payment_type_label((string)($inv['payment_type'] ?? 'cash'))); ?>
              <?php if (!empty($inv['electronic_method']) && (string)($inv['payment_type'] ?? '') !== 'cash'): ?>
                <div class="small"><?= h(electronic_method_label((string)$inv['electronic_method'])); ?></div>
              <?php endif; ?>
            </td>
            <td class="text-center"><span class="badge text-bg-<?= h($badge); ?>"><?= $st === 'canceled' ? 'ملغاة' : 'فعّالة'; ?></span></td>
            <td class="text-end">
              <a class="btn btn-sm btn-outline-dark" href="invoice_print.php?id=<?= (int)$inv['id']; ?>"><i class="bi bi-printer"></i> طباعة</a>

              <?php if ($st !== 'canceled'): ?>
                <button class="btn btn-sm btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#cancelModal<?= (int)$inv['id']; ?>">
                  <i class="bi bi-x-circle"></i> إلغاء
                </button>

                <!-- Cancel Modal -->
                <div class="modal fade" id="cancelModal<?= (int)$inv['id']; ?>" tabindex="-1" aria-hidden="true">
                  <div class="modal-dialog">
                    <div class="modal-content">
                      <form method="post">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="action" value="cancel">
                        <input type="hidden" name="invoice_id" value="<?= (int)$inv['id']; ?>">
                        <div class="modal-header">
                          <h5 class="modal-title">إلغاء الفاتورة <?= h((string)$inv['invoice_no']); ?></h5>
                          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                          <label class="form-label">سبب الإلغاء (اختياري)</label>
                          <input class="form-control" name="cancel_note" placeholder="مثال: خطأ في الإدخال">
                          <div class="form-text mt-2">سيتم إرجاع المخزون تلقائياً.</div>
                        </div>
                        <div class="modal-footer">
                          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إغلاق</button>
                          <button class="btn btn-danger">تأكيد الإلغاء</button>
                        </div>
                      </form>
                    </div>
                  </div>
                </div>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
      <div class="text-secondary">لا توجد فواتير مطابقة.</div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>

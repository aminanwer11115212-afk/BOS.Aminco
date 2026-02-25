<?php
require_once __DIR__ . '/config.php';
require_login($pdo);
require_role($pdo, ['مدير','مخزن']);

$title = 'حركة المخزون';

// ===== إنشاء حركة =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = $_POST['action'] ?? '';

  if ($action === 'create') {
    $product_id = (int)($_POST['product_id'] ?? 0);
    $type = (string)($_POST['movement_type'] ?? '');
    $qty = (int)($_POST['qty'] ?? 0);
    $note = trim($_POST['note'] ?? '');

    if ($product_id <= 0 || $qty <= 0) {
      flash_set('warning', 'اختر قطعة وأدخل كمية صحيحة (> 0).');
      header('Location: movements.php');
      exit;
    }

    if (!in_array($type, ['IN','OUT','RETURN'], true)) {
      flash_set('warning', 'نوع حركة غير صحيح.');
      header('Location: movements.php');
      exit;
    }

    $stmt = $pdo->prepare("SELECT id, name, product_no, quantity FROM products WHERE id = ?");
    $stmt->execute([$product_id]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p) {
      flash_set('danger', 'القطعة غير موجودة.');
      header('Location: movements.php');
      exit;
    }

    $before = (int)$p['quantity'];
    $change = ($type === 'OUT') ? -$qty : $qty;
    $after = $before + $change;

    if ($after < 0) {
      flash_set('danger', 'لا يمكن صرف كمية أكبر من المتوفر.');
      header('Location: movements.php?product_id=' . $product_id);
      exit;
    }

    $now = now_ts();
    $uid = user_id($pdo);

    $pdo->beginTransaction();
    try {
      $upd = $pdo->prepare("UPDATE products SET quantity = ?, updated_at = ? WHERE id = ?");
      $upd->execute([$after, $now, $product_id]);

      $label = $type === 'IN' ? 'إضافة مخزون' : ($type === 'OUT' ? 'صرف مخزون' : 'مرتجع مخزون');
      add_movement($pdo, $product_id, $type, $change, $before, $after, ($note !== '' ? $note : $label), $uid, null, null);

      $pdo->commit();
      flash_set('success', 'تم تسجيل حركة المخزون.');
    } catch (Throwable $e) {
      $pdo->rollBack();
      flash_set('danger', 'فشل تسجيل حركة المخزون.');
    }

    header('Location: movements.php?product_id=' . $product_id);
    exit;
  }
}

// ===== بحث عن القطع لاختيارها =====
$search_q = trim($_GET['q'] ?? '');
$selected_id = (int)($_GET['product_id'] ?? 0);

$search_results = [];
if ($search_q !== '') {
  $stmt = $pdo->prepare("SELECT id, name, product_no, quantity, min_qty FROM products WHERE (name LIKE ? OR product_no LIKE ?) ORDER BY updated_at DESC LIMIT 50");
  $like = '%' . $search_q . '%';
  $stmt->execute([$like, $like]);
  $search_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$selected_product = null;
if ($selected_id > 0) {
  $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
  $stmt->execute([$selected_id]);
  $selected_product = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ===== سجل الحركات (مع فلاتر) =====
$log_q = trim($_GET['log_q'] ?? '');
$log_type = trim($_GET['log_type'] ?? '');
$log_start = trim($_GET['log_start'] ?? '');
$log_end = trim($_GET['log_end'] ?? '');

$conds = [];
$params = [];
if ($log_q !== '') {
  $conds[] = "(p.name LIKE ? OR p.product_no LIKE ?)";
  $like = '%' . $log_q . '%';
  $params[] = $like;
  $params[] = $like;
}
if (in_array($log_type, ['IN','OUT','RETURN','SALE','CANCEL','ADJUST'], true)) {
  $conds[] = "m.movement_type = ?";
  $params[] = $log_type;
}
if ($log_start !== '') {
  $conds[] = "m.created_at >= ?";
  $params[] = $log_start . " 00:00:00";
}
if ($log_end !== '') {
  $conds[] = "m.created_at <= ?";
  $params[] = $log_end . " 23:59:59";
}

$where = $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';

$stmt = $pdo->prepare(
  "SELECT m.*, p.name AS product_name, p.product_no, u.username AS user_name
   FROM inventory_movements m
   JOIN products p ON p.id = m.product_id
   LEFT JOIN users u ON u.id = m.created_by
   $where
   ORDER BY m.created_at DESC, m.id DESC
   LIMIT 200"
);
$stmt->execute($params);
$movements = $stmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/header.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
  <h1 class="h4 m-0">حركة المخزون</h1>
  <a class="btn btn-outline-secondary" href="products.php"><i class="bi bi-box-seam"></i> المخزن</a>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-5">
    <div class="card shadow-sm">
      <div class="card-body">
        <h2 class="h6 mb-3">اختيار قطعة</h2>

        <form class="d-flex gap-2 mb-3" method="get">
          <input class="form-control" name="q" value="<?= h($search_q); ?>" placeholder="بحث بالاسم أو رقم القطعة">
          <button class="btn btn-outline-primary"><i class="bi bi-search"></i></button>
        </form>

        <?php if ($search_q !== ''): ?>
          <?php if ($search_results): ?>
            <div class="table-responsive">
              <table class="table table-sm align-middle">
                <thead>
                  <tr>
                    <th>القطعة</th>
                    <th class="text-center">الكمية</th>
                    <th class="text-end">اختيار</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($search_results as $p):
                    $qty = (int)$p['quantity'];
                    $min = (int)$p['min_qty'];
                    $badge = 'success';
                    if ($qty === 0) $badge = 'danger';
                    else if ($qty <= $min) $badge = 'warning';
                  ?>
                    <tr>
                      <td>
                        <div class="fw-semibold"><?= h($p['name']); ?></div>
                        <div class="text-secondary small"><code><?= h($p['product_no']); ?></code></div>
                      </td>
                      <td class="text-center"><span class="badge text-bg-<?= h($badge); ?>"><?= $qty; ?></span></td>
                      <td class="text-end"><a class="btn btn-sm btn-primary" href="movements.php?product_id=<?= (int)$p['id']; ?>"><i class="bi bi-check2"></i></a></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="text-secondary">لا توجد نتائج.</div>
          <?php endif; ?>
        <?php else: ?>
          <div class="text-secondary">اكتب للبحث ثم اختر قطعة لتسجيل حركة.</div>
        <?php endif; ?>

      </div>
    </div>

    <div class="card shadow-sm mt-3">
      <div class="card-body">
        <h2 class="h6 mb-3">تسجيل حركة</h2>

        <?php if ($selected_product): ?>
          <div class="p-3 border rounded bg-body mb-3">
            <div class="fw-semibold"><?= h((string)$selected_product['name']); ?></div>
            <div class="text-secondary small"><code><?= h((string)$selected_product['product_no']); ?></code></div>
            <div class="mt-2">الكمية الحالية: <span class="badge text-bg-dark"><?= (int)$selected_product['quantity']; ?></span></div>
          </div>

          <form method="post">
            <?= csrf_field(); ?>
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="product_id" value="<?= (int)$selected_product['id']; ?>">

            <div class="row g-2">
              <div class="col-12 col-md-4">
                <label class="form-label">نوع الحركة</label>
                <select class="form-select" name="movement_type" required>
                  <option value="IN">إضافة</option>
                  <option value="OUT">صرف</option>
                  <option value="RETURN">مرتجع</option>
                </select>
              </div>
              <div class="col-12 col-md-4">
                <label class="form-label">الكمية</label>
                <input class="form-control" name="qty" type="number" min="1" required>
              </div>
              <div class="col-12 col-md-4">
                <label class="form-label">ملاحظة</label>
                <input class="form-control" name="note" placeholder="اختياري">
              </div>
            </div>

            <div class="mt-3 d-grid">
              <button class="btn btn-success"><i class="bi bi-save2"></i> حفظ الحركة</button>
            </div>
          </form>
        <?php else: ?>
          <div class="text-secondary">اختر قطعة أولاً من البحث بالأعلى.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-7">
    <div class="card shadow-sm">
      <div class="card-body">
        <h2 class="h6 mb-3">سجل الحركات (Audit)</h2>

        <form class="row g-2 mb-3" method="get">
          <div class="col-12 col-lg-5">
            <input class="form-control" name="log_q" value="<?= h($log_q); ?>" placeholder="بحث بالاسم أو رقم القطعة">
          </div>
          <div class="col-6 col-lg-2">
            <select class="form-select" name="log_type">
              <option value="" <?= $log_type===''?'selected':''; ?>>كل الأنواع</option>
              <option value="IN" <?= $log_type==='IN'?'selected':''; ?>>إضافة</option>
              <option value="OUT" <?= $log_type==='OUT'?'selected':''; ?>>صرف</option>
              <option value="RETURN" <?= $log_type==='RETURN'?'selected':''; ?>>مرتجع</option>
              <option value="SALE" <?= $log_type==='SALE'?'selected':''; ?>>بيع</option>
              <option value="CANCEL" <?= $log_type==='CANCEL'?'selected':''; ?>>إلغاء فاتورة</option>
              <option value="ADJUST" <?= $log_type==='ADJUST'?'selected':''; ?>>ضبط</option>
            </select>
          </div>
          <div class="col-6 col-lg-2">
            <input class="form-control" type="date" name="log_start" value="<?= h($log_start); ?>">
          </div>
          <div class="col-6 col-lg-2">
            <input class="form-control" type="date" name="log_end" value="<?= h($log_end); ?>">
          </div>
          <div class="col-6 col-lg-1 d-grid">
            <button class="btn btn-outline-primary"><i class="bi bi-funnel"></i></button>
          </div>
        </form>

        <?php if ($movements): ?>
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead>
                <tr>
                  <th>التاريخ</th>
                  <th>القطعة</th>
                  <th class="text-center">النوع</th>
                  <th class="text-center">التغيير</th>
                  <th class="text-center">قبل</th>
                  <th class="text-center">بعد</th>
                  <th>ملاحظة</th>
                  <th>المستخدم</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($movements as $m): ?>
                  <tr>
                    <td class="text-secondary small"><?= h((string)$m['created_at']); ?></td>
                    <td>
                      <div class="fw-semibold"><?= h((string)$m['product_name']); ?></div>
                      <div class="text-secondary small"><code><?= h((string)$m['product_no']); ?></code></div>
                      <?php if (!empty($m['ref_invoice_no'])): ?>
                        <div class="small"><a href="invoice_print.php?id=<?= (int)($m['ref_invoice_id'] ?? 0); ?>"><?= h((string)$m['ref_invoice_no']); ?></a></div>
                      <?php endif; ?>
                    </td>
                    <td class="text-center"><span class="badge text-bg-secondary"><?= h((string)$m['movement_type']); ?></span></td>
                    <td class="text-center"><?= (int)$m['qty_change']; ?></td>
                    <td class="text-center"><?= (int)$m['qty_before']; ?></td>
                    <td class="text-center"><?= (int)$m['qty_after']; ?></td>
                    <td class="text-secondary"><?= h((string)($m['note'] ?? '')); ?></td>
                    <td class="text-secondary"><?= h((string)($m['user_name'] ?? '')); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="text-secondary">لا توجد حركات.</div>
        <?php endif; ?>

        <div class="form-text mt-2">يتم عرض آخر 200 حركة كحد أقصى.</div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>

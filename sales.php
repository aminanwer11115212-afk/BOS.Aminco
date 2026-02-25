<?php
require_once __DIR__ . '/config.php';
require_login($pdo);
require_role($pdo, ['مدير','كاشير']);

$title = 'فاتورة جديدة';

// ===== سلة الفاتورة (في الجلسة) =====
if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
  $_SESSION['cart'] = [];
}
$cart = &$_SESSION['cart']; // product_id => qty

function fetch_product(PDO $pdo, int $id): ?array {
  $stmt = $pdo->prepare("SELECT id, name, product_no, car_type, car_brand, quantity, min_qty, sell_price FROM products WHERE id = ?");
  $stmt->execute([$id]);
  $p = $stmt->fetch(PDO::FETCH_ASSOC);
  return $p ?: null;
}

// ===== Actions (POST) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = $_POST['action'] ?? '';

  if ($action === 'add') {
    $product_id = (int)($_POST['product_id'] ?? 0);
    $add_qty = (int)($_POST['qty'] ?? 1);
    if ($add_qty <= 0) $add_qty = 1;

    $p = fetch_product($pdo, $product_id);
    if (!$p) {
      flash_set('danger', 'القطعة غير موجودة.');
      header('Location: sales.php');
      exit;
    }

    $available = (int)$p['quantity'];
    $min = (int)$p['min_qty'];
    $price = (float)$p['sell_price'];

    if ($price <= 0) {
      flash_set('warning', 'لا يمكن بيع هذه القطعة لأن سعر البيع غير مُعرّف (0). قم بتعديل المنتج وإضافة سعر بيع.');
      header('Location: sales.php?q=' . urlencode($_GET['q'] ?? ''));
      exit;
    }
    if ($available <= 0) {
      flash_set('danger', 'المخزون منتهي لهذه القطعة.');
      header('Location: sales.php?q=' . urlencode($_GET['q'] ?? ''));
      exit;
    }

    $current = (int)($cart[$product_id] ?? 0);
    if ($current + $add_qty > $available) {
      flash_set('danger', 'الكمية المطلوبة أكبر من المتوفر في المخزن.');
      header('Location: sales.php?q=' . urlencode($_GET['q'] ?? ''));
      exit;
    }

    $cart[$product_id] = $current + $add_qty;
    if ($available <= $min) {
      flash_set('warning', 'تنبيه: هذه القطعة منخفضة المخزون (<= حد التنبيه).');
    } else {
      flash_set('success', 'تمت إضافة القطعة إلى الفاتورة.');
    }

    header('Location: sales.php?q=' . urlencode($_GET['q'] ?? ''));
    exit;
  }

  if ($action === 'update') {
    $product_id = (int)($_POST['product_id'] ?? 0);
    $qty = (int)($_POST['qty'] ?? 0);

    if ($product_id <= 0) {
      flash_set('warning', 'طلب غير صحيح.');
      header('Location: sales.php');
      exit;
    }

    if ($qty <= 0) {
      unset($cart[$product_id]);
      flash_set('info', 'تم حذف السطر من الفاتورة.');
      header('Location: sales.php');
      exit;
    }

    $p = fetch_product($pdo, $product_id);
    if (!$p) {
      unset($cart[$product_id]);
      flash_set('warning', 'تم حذف قطعة غير موجودة من الفاتورة.');
      header('Location: sales.php');
      exit;
    }

    $available = (int)$p['quantity'];
    if ($qty > $available) {
      flash_set('danger', 'الكمية المطلوبة أكبر من المتوفر.');
    } else {
      $cart[$product_id] = $qty;
      flash_set('success', 'تم تحديث الكمية.');
    }

    header('Location: sales.php');
    exit;
  }

  if ($action === 'remove') {
    $product_id = (int)($_POST['product_id'] ?? 0);
    unset($cart[$product_id]);
    flash_set('info', 'تم حذف الصنف من الفاتورة.');
    header('Location: sales.php');
    exit;
  }

  if ($action === 'clear') {
    $cart = [];
    flash_set('info', 'تم تفريغ الفاتورة.');
    header('Location: sales.php');
    exit;
  }

  if ($action === 'checkout') {
    $customer_name = trim($_POST['customer_name'] ?? '');
    $discount = (float)($_POST['discount'] ?? 0);
    $tax = (float)($_POST['tax'] ?? 0);

    if ($discount < 0 || $tax < 0) {
      flash_set('warning', 'الخصم/الضريبة يجب أن تكون >= 0');
      header('Location: sales.php');
      exit;
    }

    if (empty($cart)) {
      flash_set('warning', 'الفاتورة فارغة.');
      header('Location: sales.php');
      exit;
    }

    // جلب المنتجات الموجودة في السلة
    $ids = array_keys($cart);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, name, product_no, car_type, car_brand, quantity, sell_price FROM products WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // map by id
    $map = [];
    foreach ($products as $p) { $map[(int)$p['id']] = $p; }

    // تحقق من كل عنصر
    $subtotal = 0.0;
    foreach ($cart as $pid => $qty) {
      if (!isset($map[$pid])) {
        flash_set('danger', 'يوجد صنف في الفاتورة لم يعد موجوداً.');
        header('Location: sales.php');
        exit;
      }
      $p = $map[$pid];
      $available = (int)$p['quantity'];
      $unit = (float)$p['sell_price'];
      if ($unit <= 0) {
        flash_set('danger', 'يوجد صنف سعره غير مُعرّف (0) داخل الفاتورة. عدّل المنتج أولاً.');
        header('Location: sales.php');
        exit;
      }
      if ((int)$qty <= 0) {
        flash_set('danger', 'يوجد كمية غير صحيحة داخل الفاتورة.');
        header('Location: sales.php');
        exit;
      }
      if ($available < (int)$qty) {
        flash_set('danger', 'لا يوجد مخزون كافٍ لإتمام البيع (قطعة: ' . h($p['product_no']) . ').');
        header('Location: sales.php');
        exit;
      }
      $subtotal += $unit * (int)$qty;
    }

    $total = max(0.0, $subtotal - $discount + $tax);

    // ===== الدفع (كاش / إلكتروني / مختلط) =====
    $payment_type = (string)($_POST['payment_type'] ?? 'cash');
    $electronic_method = trim((string)($_POST['electronic_method'] ?? ''));
    $paid_cash_in = (float)($_POST['paid_cash'] ?? 0);
    $paid_electronic_in = (float)($_POST['paid_electronic'] ?? 0);

    if (!in_array($payment_type, (array)PAYMENT_TYPES, true)) {
      $payment_type = 'cash';
    }

    // التعامل بالـ "قرش" لتفادي مشاكل الكسور العشرية
    $total_c = (int)round($total * 100);
    $paid_cash_c = (int)round($paid_cash_in * 100);
    $paid_electronic_c = (int)round($paid_electronic_in * 100);

    $methods = (array)ELECTRONIC_METHODS;

    if ($payment_type === 'cash') {
      $electronic_method = '';
      $paid_electronic_c = 0;
      $paid_cash_c = $total_c;
    } elseif ($payment_type === 'electronic') {
      if ($electronic_method === '' || !isset($methods[$electronic_method])) {
        flash_set('warning', 'اختر طريقة الدفع الإلكتروني (بنكك/فوري/كاش).');
        header('Location: sales.php');
        exit;
      }
      $paid_cash_c = 0;
      $paid_electronic_c = $total_c;
    } else { // mixed
      if ($electronic_method === '' || !isset($methods[$electronic_method])) {
        flash_set('warning', 'اختر طريقة الدفع الإلكتروني.');
        header('Location: sales.php');
        exit;
      }
      if ($paid_cash_c < 0 || $paid_electronic_c < 0) {
        flash_set('warning', 'مبالغ الدفع يجب أن تكون >= 0');
        header('Location: sales.php');
        exit;
      }
      if ($paid_cash_c + $paid_electronic_c !== $total_c) {
        flash_set('danger', 'مجموع المدفوع (كاش + إلكتروني) يجب أن يساوي إجمالي الفاتورة.');
        header('Location: sales.php');
        exit;
      }
    }

    $paid_cash = $paid_cash_c / 100;
    $paid_electronic = $paid_electronic_c / 100;

    $pdo->beginTransaction();
    try {
      $tmpNo = 'TMP-' . bin2hex(random_bytes(6));
      $now = now_ts();
      $uid = user_id($pdo);

      $stmt = $pdo->prepare("INSERT INTO invoices (invoice_no, customer_name, subtotal, discount, tax, total, payment_type, electronic_method, paid_cash, paid_electronic, status, created_at, created_by)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'posted', ?, ?)");
      $stmt->execute([$tmpNo, $customer_name !== '' ? $customer_name : null, $subtotal, $discount, $tax, $total, $payment_type, ($electronic_method !== '' ? $electronic_method : null), $paid_cash, $paid_electronic, $now, $uid]);
      $invoice_id = (int)$pdo->lastInsertId();
      $invoice_no = 'INV-' . str_pad((string)$invoice_id, 6, '0', STR_PAD_LEFT);

      // إدخال الأصناف + تحديث المخزون + Audit
      $itemStmt = $pdo->prepare("INSERT INTO invoice_items (invoice_id, product_id, part_no_snapshot, name_snapshot, car_type_snapshot, car_brand_snapshot, unit_price_snapshot, qty, line_total)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
      $updStmt = $pdo->prepare("UPDATE products SET quantity = quantity - ?, updated_at = ? WHERE id = ?");
      $getQtyStmt = $pdo->prepare("SELECT quantity FROM products WHERE id = ?");

      foreach ($cart as $pid => $qty) {
        $p = $map[(int)$pid];
        $unit = (float)$p['sell_price'];
        $line = $unit * (int)$qty;

        // before qty
        $getQtyStmt->execute([(int)$pid]);
        $before = (int)$getQtyStmt->fetchColumn();
        $after = $before - (int)$qty;
        if ($after < 0) {
          throw new RuntimeException('Stock would go negative');
        }

        $itemStmt->execute([$invoice_id, (int)$pid, (string)$p['product_no'], (string)$p['name'], (string)($p['car_type'] ?? ''), (string)($p['car_brand'] ?? ''), $unit, (int)$qty, $line]);
        $updStmt->execute([(int)$qty, $now, (int)$pid]);

        add_movement($pdo, (int)$pid, 'SALE', -((int)$qty), $before, $after, 'بيع عبر فاتورة', $uid, $invoice_id, $invoice_no);
      }

      // تحديث الفاتورة النهائية
      $stmt = $pdo->prepare("UPDATE invoices SET invoice_no = ?, subtotal = ?, total = ?, payment_type = ?, electronic_method = ?, paid_cash = ?, paid_electronic = ? WHERE id = ?");
      $stmt->execute([$invoice_no, $subtotal, $total, $payment_type, ($electronic_method !== '' ? $electronic_method : null), $paid_cash, $paid_electronic, $invoice_id]);

      $pdo->commit();

      // تفريغ الفاتورة
      $cart = [];
      flash_set('success', 'تم حفظ الفاتورة بنجاح.');
      // بعد الحفظ مباشرة: افتح صفحة الطباعة مع نافذة اختيار مقاس (حسب طلب المستخدم)
      header('Location: invoice_print.php?id=' . $invoice_id . '&new=1');
      exit;
    } catch (Throwable $e) {
      $pdo->rollBack();
      flash_set('danger', 'فشل حفظ الفاتورة. تأكد من المخزون وحاول مرة أخرى.');
      header('Location: sales.php');
      exit;
    }
  }

  // Action غير معروف
  flash_set('warning', 'طلب غير معروف.');
  header('Location: sales.php');
  exit;
}

// ===== بحث المنتجات (GET) =====
$q = trim($_GET['q'] ?? '');
$results = [];
if ($q !== '') {
  $stmt = $pdo->prepare("SELECT id, name, product_no, car_type, car_brand, quantity, min_qty, sell_price FROM products
                         WHERE (name LIKE ? OR product_no LIKE ?)
                         ORDER BY updated_at DESC LIMIT 30");
  $like = '%' . $q . '%';
  $stmt->execute([$like, $like]);
  $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===== تحميل بيانات السلة لعرضها =====
$cart_rows = [];
$subtotal = 0.0;
if (!empty($cart)) {
  $ids = array_keys($cart);
  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $stmt = $pdo->prepare("SELECT id, name, product_no, car_type, car_brand, quantity, sell_price FROM products WHERE id IN ($placeholders)");
  $stmt->execute($ids);
  $prods = $stmt->fetchAll(PDO::FETCH_ASSOC);
  $map = [];
  foreach ($prods as $p) { $map[(int)$p['id']] = $p; }

  foreach ($cart as $pid => $qty) {
    if (!isset($map[$pid])) continue;
    $p = $map[$pid];
    $unit = (float)$p['sell_price'];
    $line = $unit * (int)$qty;
    $subtotal += $line;
    $cart_rows[] = [
      'id' => (int)$pid,
      'name' => (string)$p['name'],
      'product_no' => (string)$p['product_no'],
      'car_type' => (string)($p['car_type'] ?? ''),
      'car_brand' => (string)($p['car_brand'] ?? ''),
      'available' => (int)$p['quantity'],
      'unit_price' => $unit,
      'qty' => (int)$qty,
      'line_total' => $line,
      'price_ok' => ($unit > 0),
    ];
  }
}

include __DIR__ . '/header.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
  <h1 class="h4 m-0">فاتورة جديدة</h1>
  <?php if (!empty($cart)): ?>
    <form method="post" class="no-print">
      <?= csrf_field(); ?>
      <input type="hidden" name="action" value="clear">
      <button class="btn btn-outline-danger"><i class="bi bi-x-circle"></i> تفريغ الفاتورة</button>
    </form>
  <?php endif; ?>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-5">
    <div class="card shadow-sm">
      <div class="card-body">
        <h2 class="h6 mb-3">بحث وإضافة قطعة</h2>

        <form class="d-flex gap-2 mb-3" method="get">
          <input class="form-control" name="q" value="<?= h($q); ?>" placeholder="اكتب اسم القطعة أو رقمها..." autofocus>
          <button class="btn btn-outline-primary"><i class="bi bi-search"></i></button>
        </form>

        <?php if ($q !== ''): ?>
          <?php if ($results): ?>
            <div class="table-responsive">
              <table class="table table-sm align-middle">
                <thead>
                  <tr>
                    <th>القطعة</th>
                    <th class="text-center">المتوفر</th>
                    <th class="text-end">سعر البيع</th>
                    <th class="text-end">إضافة</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($results as $p):
                    $avail = (int)$p['quantity'];
                    $min = (int)$p['min_qty'];
                    $price = (float)$p['sell_price'];
                    $statusBadge = 'success';
                    if ($avail === 0) $statusBadge = 'danger';
                    else if ($avail <= $min) $statusBadge = 'warning';
                  ?>
                  <tr>
                    <td>
                      <div class="fw-semibold"><?= h($p['name']); ?></div>
                      <div class="text-secondary small"><code><?= h($p['product_no']); ?></code></div>
                      <?php if (!empty($p['car_type']) || !empty($p['car_brand'])): ?>
                        <div class="text-secondary small"><?= h((string)$p['car_type']); ?><?= !empty($p['car_brand']) ? ' — ' . h((string)$p['car_brand']) : ''; ?></div>
                      <?php endif; ?>
                      <?php if ($avail <= $min && $avail > 0): ?>
                        <span class="badge text-bg-warning">منخفض</span>
                      <?php elseif ($avail === 0): ?>
                        <span class="badge text-bg-danger">نفد</span>
                      <?php endif; ?>
                      <?php if ($price <= 0): ?>
                        <span class="badge text-bg-secondary">سعر غير مُعرّف</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-center"><span class="badge text-bg-<?= h($statusBadge); ?>"><?= $avail; ?></span></td>
                    <td class="text-end"><?= money_fmt($price); ?></td>
                    <td class="text-end">
                      <form method="post" class="d-inline">
                        <?= csrf_field(); ?>
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="product_id" value="<?= (int)$p['id']; ?>">
                        <button class="btn btn-sm btn-primary" <?= ($avail<=0 || $price<=0) ? 'disabled' : ''; ?>><i class="bi bi-plus-lg"></i></button>
                      </form>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="text-secondary">لا توجد نتائج.</div>
          <?php endif; ?>
        <?php else: ?>
          <div class="text-secondary">ابحث ثم اضغط إضافة.</div>
        <?php endif; ?>

      </div>
    </div>
  </div>

  <div class="col-12 col-lg-7">
    <div class="card shadow-sm">
      <div class="card-body">
        <h2 class="h6 mb-3">عناصر الفاتورة</h2>

        <?php if ($cart_rows): ?>
          <div class="table-responsive">
            <table class="table align-middle">
              <thead>
                <tr>
                  <th>القطعة</th>
                  <th class="text-end">سعر الوحدة</th>
                  <th class="text-center">الكمية</th>
                  <th class="text-end">الإجمالي</th>
                  <th class="text-end">حذف</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($cart_rows as $r): ?>
                <tr>
                  <td>
                    <div class="fw-semibold"><?= h($r['name']); ?></div>
                    <div class="text-secondary small">
                      <code><?= h($r['product_no']); ?></code>
                      <?php if (!empty($r['car_type']) || !empty($r['car_brand'])): ?>
                        — <?= h((string)$r['car_type']); ?><?= !empty($r['car_brand']) ? ' — ' . h((string)$r['car_brand']) : ''; ?>
                      <?php endif; ?>
                      — المتوفر: <?= (int)$r['available']; ?>
                    </div>
                  </td>
                  <td class="text-end"><?= money_fmt($r['unit_price']); ?></td>
                  <td class="text-center">
                    <form method="post" data-autosubmit class="d-inline">
                      <?= csrf_field(); ?>
                      <input type="hidden" name="action" value="update">
                      <input type="hidden" name="product_id" value="<?= (int)$r['id']; ?>">
                      <input class="form-control form-control-sm" style="max-width:110px; margin:0 auto" name="qty" type="number" min="1" max="<?= (int)$r['available']; ?>" value="<?= (int)$r['qty']; ?>">
                    </form>
                  </td>
                  <td class="text-end fw-bold"><?= money_fmt($r['line_total']); ?></td>
                  <td class="text-end">
                    <form method="post" class="d-inline">
                      <?= csrf_field(); ?>
                      <input type="hidden" name="action" value="remove">
                      <input type="hidden" name="product_id" value="<?= (int)$r['id']; ?>">
                      <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                    </form>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <hr>

          <form method="post" class="row g-2">
            <?= csrf_field(); ?>
            <input type="hidden" name="action" value="checkout">

            <div class="col-12 col-md-6">
              <label class="form-label">اسم العميل (اختياري)</label>
              <input class="form-control" name="customer_name" placeholder="مثال: محمد أحمد">
            </div>

            <div class="col-6 col-md-2">
              <label class="form-label">خصم</label>
              <input class="form-control" name="discount" type="number" min="0" step="0.01" value="0">
            </div>

            <div class="col-6 col-md-2">
              <label class="form-label">ضريبة</label>
              <input class="form-control" name="tax" type="number" min="0" step="0.01" value="0">
            </div>

            <div class="col-12 col-md-2 d-grid align-self-end">
              <button class="btn btn-success"><i class="bi bi-receipt"></i> حفظ الفاتورة</button>
            </div>

            <div class="col-12">
              <div class="p-3 rounded border bg-body">
                <div class="d-flex justify-content-between">
                  <span class="text-secondary">الإجمالي قبل الخصم/الضريبة</span>
                  <span class="fw-bold" id="subtotalValue" data-subtotal="<?= h(number_format($subtotal, 2, '.', '')); ?>"><?= money_fmt($subtotal); ?></span>
                </div>
                <div class="d-flex justify-content-between mt-1">
                  <span class="text-secondary">الإجمالي النهائي</span>
                  <span class="fw-bold" id="totalPreview"><?= money_fmt($subtotal); ?></span>
                </div>
                <div class="text-secondary small mt-1">يتغير مباشرة عند تعديل الخصم/الضريبة.</div>
              </div>
            </div>

            <div class="col-12">
              <div class="p-3 rounded border bg-body">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                  <div class="fw-semibold">بيانات الدفع</div>
                  <div class="text-secondary small">كاش / إلكتروني / مختلط</div>
                </div>

                <div class="row g-2">
                  <div class="col-12 col-md-4">
                    <label class="form-label">نوع الدفع</label>
                    <select class="form-select" name="payment_type" id="paymentType">
                      <option value="cash">كاش</option>
                      <option value="electronic">إلكتروني</option>
                      <option value="mixed">مختلط (كاش + إلكتروني)</option>
                    </select>
                  </div>

                  <div class="col-12 col-md-8" id="electronicMethodWrap">
                    <label class="form-label">طريقة الدفع الإلكتروني</label>
                    <select class="form-select" name="electronic_method" id="electronicMethod">
                      <option value="">اختر الطريقة...</option>
                      <?php foreach ((array)ELECTRONIC_METHODS as $k => $lbl): ?>
                        <option value="<?= h((string)$k); ?>"><?= h((string)$lbl); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="col-12">
                    <div class="form-text" id="payHelp">
                      <div class="small">- في حالة <strong>كاش</strong>: يتم ضبط المدفوع كاش تلقائياً.</div>
                      <div class="small">- في حالة <strong>إلكتروني</strong>: اختر الطريقة وسيتم ضبط المبلغ تلقائياً.</div>
                      <div class="small">- في حالة <strong>مختلط</strong>: أدخل أحد المبلغين وسيتم حساب الآخر تلقائياً.</div>
                    </div>
                    <div class="text-danger small mt-1" id="payError" style="display:none"></div>
                  </div>

                  <!-- تفاصيل الدفع (تظهر بشكل مناسب خصوصاً في وضع المختلط) -->
                  <div class="col-12" id="mixedPaidWrap" style="display:none">
                    <div class="row g-2">
                      <div class="col-6 col-md-6">
                        <label class="form-label">مدفوع كاش</label>
                        <input class="form-control" name="paid_cash" id="paidCash" type="number" min="0" step="0.01" value="0">
                      </div>

                      <div class="col-6 col-md-6">
                        <label class="form-label">مدفوع إلكتروني</label>
                        <input class="form-control" name="paid_electronic" id="paidElectronic" type="number" min="0" step="0.01" value="0">
                      </div>
                    </div>
                  </div>
                </div>

              </div>
            </div>

            <script class="no-print">
            (function(){
              const subtotalEl = document.getElementById('subtotalValue');
              if(!subtotalEl) return;

              const currency = <?= json_encode(CURRENCY); ?>;
              const subtotal = parseFloat(subtotalEl.dataset.subtotal || '0') || 0;

              const discountEl = document.querySelector('input[name="discount"]');
              const taxEl = document.querySelector('input[name="tax"]');

              const typeEl = document.getElementById('paymentType');
              const methodWrap = document.getElementById('electronicMethodWrap');
              const methodEl = document.getElementById('electronicMethod');
              const cashEl = document.getElementById('paidCash');
              const elecEl = document.getElementById('paidElectronic');


              const mixedWrap = document.getElementById('mixedPaidWrap');
              const totalPreviewEl = document.getElementById('totalPreview');
              const errEl = document.getElementById('payError');

              let lock = false;

              function fmtMoney(v){
                let s = (Math.round(v*100)/100).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
                if(currency) s += ' ' + currency;
                return s;
              }

              function calcTotal(){
                const discount = parseFloat(discountEl.value || '0') || 0;
                const tax = parseFloat(taxEl.value || '0') || 0;
                let t = subtotal - discount + tax;
                if(t < 0) t = 0;
                return Math.round(t * 100) / 100;
              }

              function showError(msg){
                if(!errEl) return;
                if(!msg){
                  errEl.style.display = 'none';
                  errEl.textContent = '';
                } else {
                  errEl.style.display = '';
                  errEl.textContent = msg;
                }
              }

              function updatePreview(){
                const total = calcTotal();
                if(totalPreviewEl) totalPreviewEl.textContent = fmtMoney(total);
                return total;
              }

              function enforceMode(){
                const total = updatePreview();
                const t = typeEl.value;

                if(t === 'cash'){
                  if(methodWrap) methodWrap.style.display = 'none';
                  if(mixedWrap) mixedWrap.style.display = 'none';
                  if(methodEl) methodEl.value = '';
                  cashEl.readOnly = true;
                  elecEl.readOnly = true;
                  cashEl.value = total.toFixed(2);
                  elecEl.value = '0.00';
                  showError('');
                  return;
                }

                // إلكتروني أو مختلط
                if(methodWrap) methodWrap.style.display = '';
                if(t === 'electronic'){
                  if(mixedWrap) mixedWrap.style.display = 'none';
                  cashEl.readOnly = true;
                  elecEl.readOnly = true;
                  cashEl.value = '0.00';
                  elecEl.value = total.toFixed(2);

                  if(!methodEl.value){
                    showError('اختر طريقة الدفع الإلكتروني.');
                  } else {
                    showError('');
                  }
                  return;
                }

                // mixed
                if(mixedWrap) mixedWrap.style.display = '';
                cashEl.readOnly = false;
                elecEl.readOnly = false;

                const cash = parseFloat(cashEl.value || '0') || 0;
                let elec = total - cash;
                if(elec < 0){
                  elec = 0;
                  cashEl.value = total.toFixed(2);
                }
                elecEl.value = elec.toFixed(2);

                if(!methodEl.value){
                  showError('اختر طريقة الدفع الإلكتروني.');
                } else {
                  showError('');
                }
              }

              function syncFromCash(){
                if(lock) return;
                lock = true;
                const total = updatePreview();
                const cash = parseFloat(cashEl.value || '0') || 0;
                let elec = total - cash;
                if(elec < 0) elec = 0;
                elecEl.value = elec.toFixed(2);
                lock = false;
              }

              function syncFromElec(){
                if(lock) return;
                lock = true;
                const total = updatePreview();
                const elec = parseFloat(elecEl.value || '0') || 0;
                let cash = total - elec;
                if(cash < 0) cash = 0;
                cashEl.value = cash.toFixed(2);
                lock = false;
              }

              // Events
              typeEl.addEventListener('change', enforceMode);
              discountEl.addEventListener('input', enforceMode);
              taxEl.addEventListener('input', enforceMode);
              cashEl.addEventListener('input', function(){
                if(typeEl.value === 'mixed') syncFromCash();
              });
              elecEl.addEventListener('input', function(){
                if(typeEl.value === 'mixed') syncFromElec();
              });
              if(methodEl){
                methodEl.addEventListener('change', enforceMode);
              }

              // init
              enforceMode();
            })();
            </script>

          </form>
        <?php else: ?>
          <div class="text-secondary">لا توجد أصناف في الفاتورة حالياً.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>

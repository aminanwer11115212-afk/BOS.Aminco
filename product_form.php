<?php
require_once __DIR__ . '/config.php';
require_login($pdo);
require_role($pdo, ['مدير','مخزن']);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$is_edit = $id > 0;
$title = $is_edit ? 'تعديل منتج' : 'إضافة منتج';

$product = null;
if ($is_edit) {
  $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
  $stmt->execute([$id]);
  $product = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$product) {
    flash_set('danger', 'المنتج غير موجود.');
    header('Location: products.php');
    exit;
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  $name = trim($_POST['name'] ?? '');
  $product_no = trim($_POST['product_no'] ?? '');
  $car_type = trim($_POST['car_type'] ?? '');
  $car_brand = trim($_POST['car_brand'] ?? '');
  $quantity = (int)($_POST['quantity'] ?? 0);
  $cost_price = (float)($_POST['cost_price'] ?? 0);
  $sell_price = (float)($_POST['sell_price'] ?? 0);
  $min_qty = (int)($_POST['min_qty'] ?? DEFAULT_MIN_QTY);

  if ($name === '' || $product_no === '' || $car_type === '' || $car_brand === '') {
    flash_set('warning', 'كل الحقول مطلوبة (عدا الكمية يمكن 0).');
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
  }
  if ($quantity < 0) {
    flash_set('warning', 'الكمية يجب أن تكون رقم صحيح >= 0.');
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
  }
  if ($cost_price < 0 || $sell_price < 0) {
    flash_set('warning', 'الأسعار يجب أن تكون >= 0.');
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
  }
  if ($min_qty < 0) {
    flash_set('warning', 'حد التنبيه يجب أن يكون >= 0.');
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
  }

  $now = now_ts();

  try {
    $pdo->beginTransaction();

    if ($is_edit) {
      $old_qty = (int)($product['quantity'] ?? 0);

      $stmt = $pdo->prepare("UPDATE products SET name=?, product_no=?, car_type=?, car_brand=?, quantity=?, cost_price=?, sell_price=?, min_qty=?, updated_at=? WHERE id=?");
      $stmt->execute([$name, $product_no, $car_type, $car_brand, $quantity, $cost_price, $sell_price, $min_qty, $now, $id]);

      // Audit حركة ضبط إذا تغيرت الكمية
      $diff = $quantity - $old_qty;
      if ($diff !== 0) {
        add_movement(
          $pdo,
          $id,
          'ADJUST',
          $diff,
          $old_qty,
          $quantity,
          'ضبط كمية من صفحة المنتج',
          user_id($pdo),
          null,
          null
        );
      }

      $pdo->commit();
      flash_set('success', 'تم تعديل المنتج بنجاح.');
    } else {
      $stmt = $pdo->prepare("INSERT INTO products (name, product_no, car_type, car_brand, quantity, cost_price, sell_price, min_qty, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
      $stmt->execute([$name, $product_no, $car_type, $car_brand, $quantity, $cost_price, $sell_price, $min_qty, $now, $now]);
      $new_id = (int)$pdo->lastInsertId();

      if ($quantity > 0) {
        add_movement(
          $pdo,
          $new_id,
          'IN',
          $quantity,
          0,
          $quantity,
          'إضافة منتج مع كمية افتتاحية',
          user_id($pdo),
          null,
          null
        );
      }

      $pdo->commit();
      flash_set('success', 'تمت إضافة المنتج بنجاح.');
    }

    header('Location: products.php');
    exit;
  } catch (PDOException $e) {
    $pdo->rollBack();
    // غالباً خطأ Unique على product_no
    flash_set('danger', 'حصل خطأ أثناء الحفظ. تأكد أن رقم القطعة غير مكرر.');
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
  }
}

include __DIR__ . '/header.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
  <h1 class="h4 m-0"><?= h($title); ?></h1>
  <a class="btn btn-outline-secondary" href="products.php">رجوع للمخزن</a>
</div>

<div class="card shadow-sm">
  <div class="card-body">
    <form method="post">
      <?= csrf_field(); ?>

      <div class="row g-3">
        <div class="col-12 col-md-6">
          <label class="form-label">اسم القطعة</label>
          <input class="form-control" name="name" required value="<?= h($product['name'] ?? ''); ?>">
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">رقم القطعة</label>
          <input class="form-control" name="product_no" required value="<?= h($product['product_no'] ?? ''); ?>">
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">نوع السيارة</label>
          <input class="form-control" name="car_type" required value="<?= h($product['car_type'] ?? ''); ?>">
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">ماركة السيارة</label>
          <input class="form-control" name="car_brand" required value="<?= h($product['car_brand'] ?? ''); ?>">
        </div>

        <div class="col-12 col-md-4">
          <label class="form-label">الكمية الحالية</label>
          <input class="form-control" name="quantity" type="number" min="0" value="<?= (int)($product['quantity'] ?? 0); ?>">
        </div>

        <div class="col-12 col-md-4">
          <label class="form-label">سعر الشراء</label>
          <input class="form-control" name="cost_price" type="number" min="0" step="0.01" value="<?= h((string)($product['cost_price'] ?? 0)); ?>">
        </div>

        <div class="col-12 col-md-4">
          <label class="form-label">سعر البيع</label>
          <input class="form-control" name="sell_price" type="number" min="0" step="0.01" value="<?= h((string)($product['sell_price'] ?? 0)); ?>">
          <div class="form-text">إذا كان 0 لن يتم السماح ببيع القطعة.</div>
        </div>

        <div class="col-12 col-md-4">
          <label class="form-label">حد التنبيه (أقل كمية)</label>
          <input class="form-control" name="min_qty" type="number" min="0" value="<?= (int)($product['min_qty'] ?? DEFAULT_MIN_QTY); ?>">
        </div>
      </div>

      <div class="mt-4 d-flex gap-2">
        <button class="btn btn-primary">حفظ</button>
        <a class="btn btn-outline-secondary" href="products.php">إلغاء</a>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>

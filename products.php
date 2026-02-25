<?php
require_once __DIR__ . '/config.php';
require_login($pdo);
require_role($pdo, ['مدير','مخزن']);

$title = 'المخزن';

$q = trim($_GET['q'] ?? '');
$show_low = (($_GET['low'] ?? '') === '1');
$show_out = (($_GET['out'] ?? '') === '1');

$sql = "SELECT id, name, product_no, car_type, car_brand, quantity, cost_price, sell_price, min_qty, updated_at
        FROM products WHERE 1=1";
$params = [];
if ($q !== '') {
  $sql .= " AND (name LIKE ? OR product_no LIKE ?)";
  $like = '%' . $q . '%';
  $params[] = $like;
  $params[] = $like;
}
if ($show_low) {
  $sql .= " AND quantity <= min_qty";
}
if ($show_out) {
  $sql .= " AND quantity = 0";
}
$sql .= " ORDER BY updated_at DESC LIMIT 500";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/header.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
  <h1 class="h4 m-0">المخزن</h1>
  <a class="btn btn-primary" href="product_form.php"><i class="bi bi-plus-lg"></i> إضافة قطعة</a>
</div>

<div class="card shadow-sm mb-3">
  <div class="card-body">
    <form class="row g-2" method="get">
      <div class="col-12 col-lg-6">
        <input class="form-control" name="q" value="<?= h($q); ?>" placeholder="بحث بالاسم أو رقم القطعة...">
      </div>
      <div class="col-12 col-lg-6 d-flex flex-wrap gap-2 align-items-center">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="low" value="1" id="low" <?= $show_low ? 'checked' : ''; ?>>
          <label class="form-check-label" for="low">تحت الحد</label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="out" value="1" id="out" <?= $show_out ? 'checked' : ''; ?>>
          <label class="form-check-label" for="out">نفد</label>
        </div>
        <button class="btn btn-outline-primary"><i class="bi bi-search"></i> بحث</button>
        <a class="btn btn-outline-secondary" href="products.php">إعادة ضبط</a>
      </div>
    </form>
    <div class="form-text mt-2">يُعرض آخر 500 نتيجة كحد أقصى لتحسين السرعة.</div>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-body">
    <?php if ($products): ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead>
          <tr>
            <th>اسم القطعة</th>
            <th>رقم القطعة</th>
            <th>نوع السيارة</th>
            <th>الماركة</th>
            <th class="text-center">الكمية</th>
            <th class="text-center">حد التنبيه</th>
            <th class="text-end">سعر الشراء</th>
            <th class="text-end">سعر البيع</th>
            <th class="text-end">إجراءات</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($products as $p):
            $qty = (int)$p['quantity'];
            $min = (int)$p['min_qty'];
            $rowClass = '';
            $badge = 'success';
            $label = 'متوفر';
            if ($qty === 0) { $rowClass = 'row-out'; $badge='danger'; $label='نفد'; }
            else if ($qty <= $min) { $rowClass = 'row-low'; $badge='warning'; $label='منخفض'; }
          ?>
          <tr class="<?= h($rowClass); ?>">
            <td class="fw-semibold"><?= h($p['name']); ?></td>
            <td><code><?= h($p['product_no']); ?></code></td>
            <td><?= h($p['car_type']); ?></td>
            <td><?= h($p['car_brand']); ?></td>
            <td class="text-center">
              <span class="badge text-bg-<?= h($badge); ?>" title="<?= h($label); ?>"><?= $qty; ?></span>
            </td>
            <td class="text-center"><?= $min; ?></td>
            <td class="text-end"><?= money_fmt($p['cost_price']); ?></td>
            <td class="text-end"><?= money_fmt($p['sell_price']); ?></td>
            <td class="text-end">
              <a class="btn btn-sm btn-outline-secondary" href="product_form.php?id=<?= (int)$p['id']; ?>"><i class="bi bi-pencil"></i> تعديل</a>

              <form class="d-inline" method="post" action="product_delete.php" onsubmit="return confirm('هل أنت متأكد من حذف القطعة؟');">
                <?= csrf_field(); ?>
                <input type="hidden" name="id" value="<?= (int)$p['id']; ?>">
                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> حذف</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
      <div class="text-center text-secondary py-4">
        لا توجد نتائج.
      </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>

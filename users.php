<?php
require_once __DIR__ . '/config.php';
require_login($pdo);
require_role($pdo, ['مدير']);

$title = 'المستخدمون';

$roles = ['مدير','كاشير','مخزن'];

function count_managers(PDO $pdo): int {
  return (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='مدير' AND is_active=1")->fetchColumn();
}

// ===== Actions =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = $_POST['action'] ?? '';

  if ($action === 'create') {
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $role = (string)($_POST['role'] ?? 'كاشير');

    if ($username === '' || $password === '') {
      flash_set('warning', 'اسم المستخدم وكلمة المرور مطلوبان.');
      header('Location: users.php');
      exit;
    }
    if (!in_array($role, $roles, true)) {
      flash_set('warning', 'دور غير صحيح.');
      header('Location: users.php');
      exit;
    }
    $now = now_ts();
    try {
      $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role, is_active, created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?)");
      $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role, $now, $now]);
      flash_set('success', 'تم إنشاء المستخدم.');
    } catch (Throwable $e) {
      flash_set('danger', 'فشل إنشاء المستخدم (قد يكون الاسم مستخدم مسبقاً).');
    }
    header('Location: users.php');
    exit;
  }

  if ($action === 'update_role') {
    $uid = (int)($_POST['user_id'] ?? 0);
    $role = (string)($_POST['role'] ?? '');

    if ($uid <= 0 || !in_array($role, $roles, true)) {
      flash_set('warning', 'طلب غير صحيح.');
      header('Location: users.php');
      exit;
    }

    // منع إسقاط آخر مدير
    $stmt = $pdo->prepare("SELECT id, role, is_active FROM users WHERE id=?");
    $stmt->execute([$uid]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
      flash_set('warning', 'مستخدم غير موجود.');
      header('Location: users.php');
      exit;
    }

    if ((int)$u['id'] === (int)user_id($pdo) && $role !== 'مدير') {
      flash_set('danger', 'لا يمكنك تغيير دورك من مدير.');
      header('Location: users.php');
      exit;
    }

    if ((string)$u['role'] === 'مدير' && $role !== 'مدير' && (int)$u['is_active'] === 1) {
      if (count_managers($pdo) <= 1) {
        flash_set('danger', 'لا يمكن إزالة آخر مدير فعال في النظام.');
        header('Location: users.php');
        exit;
      }
    }

    $stmt = $pdo->prepare("UPDATE users SET role=?, updated_at=? WHERE id=?");
    $stmt->execute([$role, now_ts(), $uid]);
    flash_set('success', 'تم تحديث الدور.');
    header('Location: users.php');
    exit;
  }

  if ($action === 'toggle_active') {
    $uid = (int)($_POST['user_id'] ?? 0);
    if ($uid <= 0) {
      flash_set('warning', 'طلب غير صحيح.');
      header('Location: users.php');
      exit;
    }

    if ($uid === (int)user_id($pdo)) {
      flash_set('danger', 'لا يمكنك تعطيل حسابك.');
      header('Location: users.php');
      exit;
    }

    $stmt = $pdo->prepare("SELECT role, is_active FROM users WHERE id=?");
    $stmt->execute([$uid]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
      flash_set('warning', 'مستخدم غير موجود.');
      header('Location: users.php');
      exit;
    }

    $newActive = ((int)$u['is_active'] === 1) ? 0 : 1;

    // منع تعطيل آخر مدير
    if ((string)$u['role'] === 'مدير' && (int)$u['is_active'] === 1 && $newActive === 0) {
      if (count_managers($pdo) <= 1) {
        flash_set('danger', 'لا يمكن تعطيل آخر مدير فعال.');
        header('Location: users.php');
        exit;
      }
    }

    $stmt = $pdo->prepare("UPDATE users SET is_active=?, updated_at=? WHERE id=?");
    $stmt->execute([$newActive, now_ts(), $uid]);
    flash_set('success', 'تم تحديث حالة المستخدم.');
    header('Location: users.php');
    exit;
  }

  if ($action === 'reset_password') {
    $uid = (int)($_POST['user_id'] ?? 0);
    $password = (string)($_POST['new_password'] ?? '');

    if ($uid <= 0 || $password === '') {
      flash_set('warning', 'أدخل كلمة مرور جديدة.');
      header('Location: users.php');
      exit;
    }

    $stmt = $pdo->prepare("UPDATE users SET password_hash=?, updated_at=? WHERE id=?");
    $stmt->execute([password_hash($password, PASSWORD_DEFAULT), now_ts(), $uid]);
    flash_set('success', 'تم إعادة تعيين كلمة المرور.');
    header('Location: users.php');
    exit;
  }

  if ($action === 'delete') {
    $uid = (int)($_POST['user_id'] ?? 0);
    if ($uid <= 0) {
      flash_set('warning', 'طلب غير صحيح.');
      header('Location: users.php');
      exit;
    }

    if ($uid === (int)user_id($pdo)) {
      flash_set('danger', 'لا يمكنك حذف حسابك.');
      header('Location: users.php');
      exit;
    }

    $stmt = $pdo->prepare("SELECT role, is_active FROM users WHERE id=?");
    $stmt->execute([$uid]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
      flash_set('warning', 'مستخدم غير موجود.');
      header('Location: users.php');
      exit;
    }

    if ((string)$u['role'] === 'مدير' && (int)$u['is_active'] === 1 && count_managers($pdo) <= 1) {
      flash_set('danger', 'لا يمكن حذف آخر مدير فعال.');
      header('Location: users.php');
      exit;
    }

    try {
      $stmt = $pdo->prepare("DELETE FROM users WHERE id=?");
      $stmt->execute([$uid]);
      flash_set('info', 'تم حذف المستخدم.');
    } catch (Throwable $e) {
      flash_set('danger', 'لا يمكن حذف المستخدم (قد يكون مرتبطاً بعمليات).');
    }

    header('Location: users.php');
    exit;
  }

  flash_set('warning', 'طلب غير معروف.');
  header('Location: users.php');
  exit;
}

$users = $pdo->query("SELECT id, username, role, is_active, created_at FROM users ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/header.php';
?>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
  <h1 class="h4 m-0">إدارة المستخدمين</h1>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-4">
    <div class="card shadow-sm">
      <div class="card-body">
        <h2 class="h6 mb-3">إنشاء مستخدم</h2>
        <form method="post">
          <?= csrf_field(); ?>
          <input type="hidden" name="action" value="create">

          <div class="mb-2">
            <label class="form-label">اسم المستخدم</label>
            <input class="form-control" name="username" required>
          </div>
          <div class="mb-2">
            <label class="form-label">كلمة المرور</label>
            <input class="form-control" name="password" type="password" required>
          </div>
          <div class="mb-3">
            <label class="form-label">الدور</label>
            <select class="form-select" name="role">
              <option value="كاشير">كاشير</option>
              <option value="مخزن">مخزن</option>
              <option value="مدير">مدير</option>
            </select>
          </div>
          <button class="btn btn-primary w-100"><i class="bi bi-person-plus"></i> إنشاء</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-8">
    <div class="card shadow-sm">
      <div class="card-body">
        <h2 class="h6 mb-3">قائمة المستخدمين</h2>

        <?php if ($users): ?>
          <div class="table-responsive">
            <table class="table align-middle">
              <thead>
                <tr>
                  <th>#</th>
                  <th>المستخدم</th>
                  <th>الدور</th>
                  <th class="text-center">الحالة</th>
                  <th class="text-end">إجراءات</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($users as $u):
                  $active = (int)$u['is_active'] === 1;
                  $badge = $active ? 'success' : 'danger';
                ?>
                <tr class=\"<?= $active ? '' : 'row-disabled'; ?>\">
                  <td><?= (int)$u['id']; ?></td>
                  <td class="fw-semibold"><?= h((string)$u['username']); ?></td>
                  <td>
                    <form method="post" class="d-flex gap-2 align-items-center">
                      <?= csrf_field(); ?>
                      <input type="hidden" name="action" value="update_role">
                      <input type="hidden" name="user_id" value="<?= (int)$u['id']; ?>">
                      <select class="form-select form-select-sm" name="role" style="max-width:150px">
                        <?php foreach ($roles as $r): ?>
                          <option value="<?= h($r); ?>" <?= ((string)$u['role']===$r)?'selected':''; ?>><?= h($r); ?></option>
                        <?php endforeach; ?>
                      </select>
                      <button class="btn btn-sm btn-outline-primary">حفظ</button>
                    </form>
                  </td>
                  <td class="text-center"><span class="badge text-bg-<?= h($badge); ?>"><?= $active ? 'فعّال' : 'موقوف'; ?></span></td>
                  <td class="text-end">
                    <form method="post" class="d-inline" onsubmit="return confirm('تأكيد تغيير الحالة؟');">
                      <?= csrf_field(); ?>
                      <input type="hidden" name="action" value="toggle_active">
                      <input type="hidden" name="user_id" value="<?= (int)$u['id']; ?>">
                      <button class="btn btn-sm <?= $active ? 'btn-outline-danger' : 'btn-outline-success'; ?>"><i class="bi bi-shield"></i> <?= $active ? 'تعطيل' : 'تفعيل'; ?></button>
                    </form>

                    <button class="btn btn-sm btn-outline-dark" type="button" data-bs-toggle="modal" data-bs-target="#reset<?= (int)$u['id']; ?>">
                      <i class="bi bi-key"></i> كلمة مرور
                    </button>

                    <form method="post" class="d-inline" onsubmit="return confirm('تأكيد حذف المستخدم؟');">
                      <?= csrf_field(); ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="user_id" value="<?= (int)$u['id']; ?>">
                      <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> حذف</button>
                    </form>

                    <!-- Reset password modal -->
                    <div class="modal fade" id="reset<?= (int)$u['id']; ?>" tabindex="-1" aria-hidden="true">
                      <div class="modal-dialog">
                        <div class="modal-content">
                          <form method="post">
                            <?= csrf_field(); ?>
                            <input type="hidden" name="action" value="reset_password">
                            <input type="hidden" name="user_id" value="<?= (int)$u['id']; ?>">
                            <div class="modal-header">
                              <h5 class="modal-title">تعيين كلمة مرور جديدة: <?= h((string)$u['username']); ?></h5>
                              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                              <label class="form-label">كلمة المرور الجديدة</label>
                              <input class="form-control" name="new_password" type="password" required>
                            </div>
                            <div class="modal-footer">
                              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button>
                              <button class="btn btn-dark">حفظ</button>
                            </div>
                          </form>
                        </div>
                      </div>
                    </div>

                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="text-secondary">لا يوجد مستخدمون.</div>
        <?php endif; ?>

        <div class="form-text mt-2">تنبيه: يُمنع حذف/تعطيل آخر مدير فعال.</div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>

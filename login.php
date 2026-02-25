<?php
require_once __DIR__ . '/config.php';

$title = 'تسجيل الدخول';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  $username = trim($_POST['username'] ?? '');
  $password = $_POST['password'] ?? '';

  $stmt = $pdo->prepare('SELECT id, username, password_hash, role, is_active FROM users WHERE username = ?');
  $stmt->execute([$username]);
  $u = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$u || !password_verify($password, (string)$u['password_hash'])) {
    flash_set('danger', 'بيانات الدخول غير صحيحة.');
  } else if ((int)($u['is_active'] ?? 1) !== 1) {
    flash_set('warning', 'الحساب موقوف.');
  } else {
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$u['id'];
    flash_set('success', 'مرحباً ' . $u['username'] . ' 👋');
    header('Location: index.php');
    exit;
  }
}

include __DIR__ . '/header.php';
?>

<div class="row justify-content-center">
  <div class="col-12 col-md-6 col-lg-4">
    <div class="card shadow-sm">
      <div class="card-body">
        <h1 class="h4 mb-3">تسجيل الدخول</h1>

        <form method="post">
          <?= csrf_field(); ?>

          <div class="mb-3">
            <label class="form-label">اسم المستخدم</label>
            <input class="form-control" name="username" required autofocus autocomplete="username">
          </div>

          <div class="mb-3">
            <label class="form-label">كلمة المرور</label>
            <input class="form-control" name="password" type="password" required autocomplete="current-password">
          </div>

          <button class="btn btn-primary w-100">دخول</button>
        </form>

        <hr>
        <div class="small text-secondary">
          إذا كانت هذه أول مرة: افتح <code>init_db.php</code> مرة واحدة لتجهيز قاعدة البيانات.
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>

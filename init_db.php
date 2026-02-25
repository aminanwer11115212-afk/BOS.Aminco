<?php
// init_db.php - تجهيز قاعدة البيانات وإنشاء مستخدم admin افتراضي (تشغيل اختياري)
require_once __DIR__ . '/config.php';

// إنشاء مستخدم admin إذا لا يوجد مستخدمين
$count = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
if ($count === 0) {
  $now = now_ts();
  $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role, is_active, created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?)");
  $stmt->execute([
    'admin',
    password_hash('admin123', PASSWORD_DEFAULT),
    'مدير',
    $now,
    $now,
  ]);
}

echo "<meta charset='utf-8'>";
echo "<div style='font-family:Tahoma,Arial; direction:rtl; padding:16px'>";
echo "<h2>تم تجهيز قاعدة البيانات ✅</h2>";
echo "<p>بيانات الدخول الافتراضية: <code>admin / admin123</code></p>";
echo "<p><a href='login.php'>اذهب لتسجيل الدخول</a></p>";
echo "</div>";

<?php
require_once __DIR__ . '/config.php';
require_login($pdo);
require_role($pdo, ['مدير','مخزن']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit;
}
csrf_verify();

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  flash_set('warning', 'طلب غير صحيح.');
  header('Location: products.php');
  exit;
}

try {
  $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
  $stmt->execute([$id]);
  flash_set('info', 'تم حذف المنتج.');
} catch (PDOException $e) {
  flash_set('warning', 'لا يمكن حذف المنتج لأنه مرتبط بفواتير/حركات مخزون.');
}

header('Location: products.php');
exit;

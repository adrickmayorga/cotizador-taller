<?php
declare(strict_types=1);
require_once __DIR__ . '/config/database.php';
$id=(int)($_GET['id']??0);
if($id>0){
  $pdo=srnu_db();
  $stmt=$pdo->prepare('DELETE FROM cotizaciones WHERE id=?');
  $stmt->execute([$id]);
}
header('Location: cotizaciones.php');
exit;

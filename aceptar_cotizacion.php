<?php
declare(strict_types=1);require_once __DIR__.'/config/database.php';$id=(int)($_GET['id']??0);if($id>0){$pdo=srnu_db();$pdo->prepare("UPDATE cotizaciones SET estado='Aprobada',aceptada_at=COALESCE(aceptada_at,NOW()),updated_at=NOW() WHERE id=?")->execute([$id]);}header('Location: cotizaciones.php');exit;

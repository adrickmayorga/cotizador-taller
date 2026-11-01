<?php
declare(strict_types=1);
require_once __DIR__.'/config/database.php';
$pdo=srnu_db();
$count=(int)$pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
if($count>0){header('Location: login.php');exit;}
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!srnu_auth_check_csrf($_POST['csrf']??null))$error='El formulario expiró. Recarga la página.';
 else{
  $nombre=trim((string)($_POST['nombre']??''));$usuario=trim((string)($_POST['usuario']??''));$p=(string)($_POST['password']??'');$p2=(string)($_POST['password2']??'');
  if(strlen($nombre)<3)$error='Escribe el nombre del administrador.';
  elseif(!preg_match('/^[A-Za-z0-9._-]{3,40}$/',$usuario))$error='El usuario debe tener 3 a 40 caracteres: letras, números, punto, guion o guion bajo.';
  elseif(strlen($p)<10)$error='Usa una contraseña de al menos 10 caracteres.';
  elseif($p!==$p2)$error='Las contraseñas no coinciden.';
  else{$s=$pdo->prepare("INSERT INTO usuarios(nombre,usuario,password_hash,rol,activo) VALUES(?,?,?,'admin',1)");$s->execute([$nombre,$usuario,password_hash($p,PASSWORD_DEFAULT)]);$id=(int)$pdo->lastInsertId();$u=$pdo->query('SELECT * FROM usuarios WHERE id='.$id)->fetch();srnu_auth_login($u);header('Location: dashboard.php');exit;}
 }
}
function sh($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Primer acceso · SRNU</title><link rel="icon" href="favicon.png"><style>*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:22px;background:#080d18;color:#e5e7eb;font-family:system-ui}.box{width:min(560px,100%);background:#111827;border:1px solid #334155;border-radius:22px;padding:30px}.brand{display:flex;gap:12px;align-items:center}.brand img{width:46px;height:46px}h1{margin:22px 0 8px}.muted{color:#94a3b8;line-height:1.55}.field{display:block;font-size:12px;font-weight:800;margin:15px 0 6px}.input{width:100%;padding:12px;background:#0b1220;color:#fff;border:1px solid #334155;border-radius:11px;font:inherit}.btn{width:100%;padding:14px;border:0;border-radius:11px;background:#2563eb;color:white;font-weight:900;margin-top:20px}.bad{background:#451a1a;color:#fecaca;padding:12px;border-radius:10px}</style></head><body><main class="box"><div class="brand"><img src="favicon.png"><strong>SRNU CUSTOM</strong></div><h1>Crear administrador</h1><p class="muted">Este paso aparece únicamente cuando todavía no existe ningún usuario. No hay contraseñas predeterminadas dentro del proyecto.</p><?php if($error):?><div class="bad"><?=sh($error)?></div><?php endif;?><form method="post"><input type="hidden" name="csrf" value="<?=sh(srnu_auth_csrf())?>"><label class="field">Nombre</label><input class="input" name="nombre" required placeholder="Ej. Ricardo Núñez"><label class="field">Usuario</label><input class="input" name="usuario" required autocomplete="username" placeholder="ricardo"><label class="field">Contraseña (mínimo 10 caracteres)</label><input class="input" type="password" name="password" required autocomplete="new-password"><label class="field">Repetir contraseña</label><input class="input" type="password" name="password2" required autocomplete="new-password"><button class="btn">Crear administrador y entrar</button></form></main></body></html>

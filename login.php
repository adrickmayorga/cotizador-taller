<?php
declare(strict_types=1);
require_once __DIR__.'/config/database.php';
$pdo=srnu_db();
$count=(int)$pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
if($count===0){header('Location: configurar_acceso.php');exit;}
if(srnu_auth_logged_in()){header('Location: dashboard.php');exit;}
$error='';
$next=srnu_auth_safe_next($_GET['next'] ?? $_POST['next'] ?? 'dashboard.php');
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!srnu_auth_check_csrf($_POST['csrf']??null)){$error='La sesión del formulario expiró. Intenta nuevamente.';}
    else{
        $usuario=trim((string)($_POST['usuario']??''));$password=(string)($_POST['password']??'');
        $s=$pdo->prepare('SELECT * FROM usuarios WHERE usuario=? AND activo=1 LIMIT 1');$s->execute([$usuario]);$u=$s->fetch();
        if(!$u || !password_verify($password,(string)$u['password_hash'])){$error='Usuario o contraseña incorrectos.';usleep(250000);}
        else{srnu_auth_login($u);$pdo->prepare('UPDATE usuarios SET ultimo_acceso=NOW() WHERE id=?')->execute([(int)$u['id']]);header('Location: '.$next);exit;}
    }
}
function lh($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Acceso · SRNU CUSTOM</title><link rel="icon" href="favicon.png"><style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:22px;background:radial-gradient(circle at 20% 10%,#17305c 0,#0b1120 34%,#05070b 100%);font-family:Inter,system-ui,-apple-system,Segoe UI,Arial;color:#e5e7eb}.box{width:min(440px,100%);background:rgba(15,23,42,.95);border:1px solid #334155;border-radius:24px;padding:30px;box-shadow:0 28px 80px rgba(0,0,0,.45)}.brand{display:flex;align-items:center;gap:13px;margin-bottom:25px}.brand img{width:50px;height:50px;object-fit:contain}.brand strong{font-size:20px;letter-spacing:.04em}.brand span{display:block;color:#94a3b8;font-size:12px;margin-top:3px}h1{font-size:25px;margin:0 0 7px}.muted{color:#94a3b8;font-size:13px;line-height:1.5}.field{display:block;font-size:12px;font-weight:800;margin:17px 0 7px}.input{width:100%;padding:13px 14px;background:#0b1220;border:1px solid #334155;border-radius:12px;color:#fff;font:inherit;outline:none}.input:focus{border-color:#60a5fa;box-shadow:0 0 0 3px rgba(96,165,250,.13)}.btn{width:100%;margin-top:20px;border:0;border-radius:12px;padding:14px;background:#2563eb;color:#fff;font-weight:900;font-size:15px;cursor:pointer}.bad{background:#451a1a;border:1px solid #7f1d1d;color:#fecaca;padding:12px;border-radius:12px;margin:15px 0}.ok{background:#052e16;border:1px solid #166534;color:#bbf7d0;padding:12px;border-radius:12px;margin:15px 0}.foot{text-align:center;margin-top:20px;font-size:11px;color:#64748b}</style></head><body><main class="box"><div class="brand"><img src="favicon.png" alt="SRNU"><div><strong>SRNU CUSTOM</strong><span>ERP · Acceso protegido</span></div></div><h1>Iniciar sesión</h1><p class="muted">Accede con tu cuenta del taller. Los enlaces públicos de firma funcionan por separado mediante token.</p><?php if(isset($_GET['expirada'])):?><div class="ok">Tu sesión anterior terminó por inactividad. Inicia sesión nuevamente.</div><?php endif;?><?php if($error):?><div class="bad"><?=lh($error)?></div><?php endif;?><form method="post" autocomplete="on"><input type="hidden" name="csrf" value="<?=lh(srnu_auth_csrf())?>"><input type="hidden" name="next" value="<?=lh($next)?>"><label class="field">Usuario</label><input class="input" name="usuario" autocomplete="username" required autofocus><label class="field">Contraseña</label><input class="input" type="password" name="password" autocomplete="current-password" required><button class="btn">Entrar a SRNU</button></form><div class="foot">SRNU Custom · Acceso interno</div></main></body></html>

<?php
declare(strict_types=1);

/** Las migraciones se ejecutan una vez por revisión, no en cada petición. */
function srnu_esquema_actual(PDO $pdo, string $revision): bool
{
    try {
        $st = $pdo->prepare('SELECT valor FROM configuracion_sistema WHERE clave=? LIMIT 1');
        $st->execute(['srnu_schema_revision']);
        return hash_equals($revision, (string)$st->fetchColumn());
    } catch (PDOException $e) {
        if (($e->errorInfo[0] ?? (string)$e->getCode()) !== '42S02') throw $e;
        return false;
    }
}

function srnu_actualizar_esquema_si_necesario(PDO $pdo): void
{
    $revision = hash('sha256', (string)hash_file('sha256', __DIR__.'/database.php') . (string)hash_file('sha256', __FILE__));
    if (srnu_esquema_actual($pdo, $revision)) return;
    $nombreLock = 'srnu_schema_' . substr(hash('sha256', SRNU_DB_HOST.'|'.SRNU_DB_NAME), 0, 40);
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 15)');
    $lock->execute([$nombreLock]);
    if ((int)$lock->fetchColumn() !== 1) {
        throw new RuntimeException('Se está actualizando la base de datos. Vuelve a intentar en unos segundos.');
    }
    try {
        if (srnu_esquema_actual($pdo, $revision)) return;
        srnu_migrar_esquema($pdo);
        $st = $pdo->prepare('INSERT INTO configuracion_sistema(clave,valor) VALUES(?,?) ON DUPLICATE KEY UPDATE valor=?');
        $st->execute(['srnu_schema_revision', $revision, $revision]);
    } finally {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$nombreLock]);
    }
}

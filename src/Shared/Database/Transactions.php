<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Database;

use Hoelzle\Core\Database\DB;

/** Transaktion und Namenssperre fuer Schreibaktionen: der eine gemeinsame Helfer, damit nicht jedes Modul seinen eigenen baut. */
final class Transactions
{
    /**
     * Fuehrt $work in einer Transaktion aus: Commit bei Erfolg, sonst Rollback und dieselbe Ausnahme wieder nach oben.
     *
     * @template T
     * @param \Closure():T $work
     * @return T
     */
    public static function run(\Closure $work): mixed
    {
        $pdo = DB::getInstance();
        $pdo->beginTransaction();
        try {
            $result = $work();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Wie {@see self::run()}, aber unter der benannten MySQL-Sperre `$lock`: zwei gleichzeitige Anfragen kommen
     * nacheinander dran und sehen jeweils den Stand der anderen. Wird die Sperre nicht binnen `$waitSeconds` frei, wirft
     * `$busy()` die Ausnahme des Aufrufers (so bleibt der Fehlercode beim Fachmodul).
     *
     * @template T
     * @param \Closure():\Throwable $busy
     * @param \Closure():T $work
     * @return T
     */
    public static function underLock(string $lock, int $waitSeconds, \Closure $busy, \Closure $work): mixed
    {
        $pdo = DB::getInstance();
        $acquire = $pdo->prepare('SELECT GET_LOCK(?, ?)');
        $acquire->execute([$lock, $waitSeconds]);
        if ((int) $acquire->fetchColumn() !== 1) {
            throw $busy();
        }
        try {
            return self::run($work);
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
        }
    }
}

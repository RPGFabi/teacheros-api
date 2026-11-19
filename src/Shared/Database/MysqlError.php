<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Database;

/** Die MySQL-Fehlernummern (`PDOException::errorInfo[1]`), auf die die Fachlogik reagiert, mit sprechenden Namen. */
final class MysqlError
{
    /** Doppelter Wert in einem eindeutigen Schluessel (ER_DUP_ENTRY). */
    public const DUPLICATE_ENTRY = 1062;
    /** Eine Zeile soll geloescht werden, auf die andere Zeilen noch per Fremdschluessel verweisen (ER_ROW_IS_REFERENCED_2). */
    public const ROW_IS_REFERENCED = 1451;
    /** Eine Zeile verweist per Fremdschluessel auf eine Zeile, die es nicht (mehr) gibt (ER_NO_REFERENCED_ROW_2). */
    public const NO_REFERENCED_ROW = 1452;

    /** Die MySQL-Fehlernummer der Ausnahme, oder null, wenn der Treiber keine liefert. */
    public static function of(\PDOException $e): ?int
    {
        $number = $e->errorInfo[1] ?? null;
        return is_int($number) ? $number : null;
    }
}

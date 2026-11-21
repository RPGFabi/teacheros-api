<?php

declare(strict_types=1);

namespace TeacherOS\Modules\TeachingGroups;

use TeacherOS\Shared\Exceptions\CodedException;

/**
 * Der Einwand eines Moduls gegen das Entfernen einer Klasse aus einer Fachgruppe: maschinenlesbarer Code und Meldung fuer
 * Menschen. Er wird zu `409` mit diesem Code ({@see self::exception()}).
 */
final class TeachingGroupClassRemovalObjection
{
    public function __construct(
        public readonly string $code,
        public readonly string $message,
    ) {
    }

    /** Die Ablehnung, die der Aufrufer wirft: 409 mit Code und Meldung des Einwands. */
    public function exception(): CodedException
    {
        return new CodedException($this->code, 409, $this->message);
    }
}

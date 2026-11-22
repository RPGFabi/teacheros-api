<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Assignments;

/**
 * Der Verweis auf die Lehrkraft in den Ausgaben bestehender Zuordnungen (Datensparsamkeit): nur Kennung des Kontos und
 * Anmeldename, weder E-Mail noch Kontostatus; wer Zuordnungen nur lesen darf, bekommt beides nicht. Ein Konto, das es nicht mehr
 * gibt, erscheint mit leerem Anmeldenamen.
 */
final class TeacherRef
{
    public function __construct(
        public readonly string $id,
        public readonly string $loginName,
    ) {
    }

    /** @return array{id:string,login_name:string} die JSON-Form der API */
    public function toArray(): array
    {
        return ['id' => $this->id, 'login_name' => $this->loginName];
    }
}

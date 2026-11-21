<?php

declare(strict_types=1);

namespace TeacherOS\Modules\SchoolSettings;

/**
 * Die Obergrenzen der Schule fuer Lehrkraft-Zuordnungen, schreibgeschuetzt: der schmale Dienst, ueber den andere Module
 * (Fachgruppen, Klassenleitungen) die Schuleinstellungen lesen, ohne deren Speicherung zu kennen. Ohne gespeicherten Wert
 * gilt der Standard ({@see SchoolSettingKey::default()}). Jeder Aufruf liest den aktuellen Stand; ein verschaerfter Wert gilt
 * nur fuer Neues, Bestehendes prueft dieser Dienst nicht.
 */
final class SchoolLimits
{
    public function __construct(private readonly SchoolSettingRepository $repository = new SchoolSettingRepository())
    {
    }

    /** Wie viele Lehrkraefte eine Fachgruppe hoechstens zugleich hat. */
    public function maxTeachersPerTeachingGroup(): int
    {
        return $this->value(SchoolSettingKey::MaxTeachersPerTeachingGroup);
    }

    /** Wie viele Klassenleitungen eine Klasse hoechstens zugleich hat. */
    public function maxClassLeadersPerClass(): int
    {
        return $this->value(SchoolSettingKey::MaxClassLeadersPerClass);
    }

    private function value(SchoolSettingKey $key): int
    {
        return $this->repository->stored()[$key->value] ?? $key->default();
    }
}

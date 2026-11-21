<?php

declare(strict_types=1);

namespace TeacherOS\Modules\SchoolSettings;

/**
 * Die Schuleinstellungen, die es gibt (GLOSSARY.md: Schuleinstellung), mit Standardwert und zulaessigem Bereich. Alle
 * sind ganze Zahlen und gelten fuer die ganze Schulinstanz, gleich fuer alle Schuljahre. Der Schluessel ist zugleich der
 * Name in der API, in der Speicherung und im Audit-Eintrag.
 */
enum SchoolSettingKey: string
{
    /** Wie viele Lehrkraefte eine Fachgruppe hoechstens zugleich hat. */
    case MaxTeachersPerTeachingGroup = 'max_teachers_per_teaching_group';
    /** Wie viele Klassenleitungen eine Klasse hoechstens zugleich hat. */
    case MaxClassLeadersPerClass = 'max_class_leaders_per_class';

    public function default(): int
    {
        return 2;
    }

    public function min(): int
    {
        return 1;
    }

    public function max(): int
    {
        return match ($this) {
            self::MaxTeachersPerTeachingGroup => 50,
            self::MaxClassLeadersPerClass => 10,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::MaxTeachersPerTeachingGroup => 'Lehrkräfte je Fachgruppe',
            self::MaxClassLeadersPerClass => 'Klassenleitungen je Klasse',
        };
    }
}

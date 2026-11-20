<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Modules;

/**
 * Modulverzeichnis: alle Module, die diese Codebasis kennt (Source of Truth 4.5). Ein Modul ohne Eintrag gibt es nicht,
 * weder in der Freigabe noch an einer Route. `core` ist immer aktiv und nicht abschaltbar; alle anderen Module haengen
 * nur von `core` ab und brauchen eine Modulfreigabe der Schulinstanz ({@see ModuleEntitlements}).
 */
final class ModuleCatalog
{
    public const CORE = 'core';
    public const OBSERVE = 'observe';
    public const GRADE = 'grade';
    public const CLASSROOM = 'class';
    public const ATTENDANCE = 'attendance';
    public const CONNECT = 'connect';
    public const FORMS = 'forms';
    public const FINANCE = 'finance';
    public const PLANNER = 'planner';
    public const SUBSTITUTE = 'substitute';

    /**
     * Katalogreihenfolge = Anzeigereihenfolge.
     *
     * @return list<array{id:string,name:string}>
     */
    public static function entries(): array
    {
        return [
            ['id' => self::CORE, 'name' => 'Grundfunktionen'],
            ['id' => self::OBSERVE, 'name' => 'Beobachtungen'],
            ['id' => self::GRADE, 'name' => 'Leistungen und Noten'],
            ['id' => self::CLASSROOM, 'name' => 'Unterricht und Klassenbuch'],
            ['id' => self::ATTENDANCE, 'name' => 'Anwesenheit'],
            ['id' => self::CONNECT, 'name' => 'Kommunikation'],
            ['id' => self::FORMS, 'name' => 'Umfragen und Rückläufer'],
            ['id' => self::FINANCE, 'name' => 'Zahlungen'],
            ['id' => self::PLANNER, 'name' => 'Stundenplanung'],
            ['id' => self::SUBSTITUTE, 'name' => 'Vertretungsplanung'],
        ];
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_column(self::entries(), 'id');
    }

    public static function exists(string $moduleId): bool
    {
        return in_array($moduleId, self::all(), true);
    }

    /** Module, die eine Freigabe brauchen: alle ausser `core`. @return list<string> */
    public static function switchable(): array
    {
        return array_values(array_diff(self::all(), [self::CORE]));
    }
}

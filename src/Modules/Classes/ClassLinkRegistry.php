<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Classes;

/**
 * Sammelt die {@see ClassLinkCheck}s aller Module. Module melden sich beim Start an der gemeinsamen Instanz
 * ({@see self::shared()}) an, etwa in ihrer Routenregistrierung. Ohne Anmeldung gilt eine Klasse als unverknuepft.
 */
final class ClassLinkRegistry
{
    private static ?self $shared = null;

    /** @var list<ClassLinkCheck> */
    private array $checks = [];

    public static function shared(): self
    {
        return self::$shared ??= new self();
    }

    public function register(ClassLinkCheck $check): void
    {
        $this->checks[] = $check;
    }

    /**
     * Bezeichnungen aller Verknuepfungen, die an der Klasse haengen; leer, wenn sie nirgends verknuepft ist.
     *
     * @return list<string>
     */
    public function linksOf(string $classId): array
    {
        $labels = [];
        foreach ($this->checks as $check) {
            if ($check->isLinked($classId)) {
                $labels[] = $check->label();
            }
        }
        return $labels;
    }
}

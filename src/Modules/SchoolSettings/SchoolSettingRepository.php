<?php

declare(strict_types=1);

namespace TeacherOS\Modules\SchoolSettings;

use Hoelzle\Core\Database\DB;

/** Liest und schreibt die Tabelle `school_settings`. Standardwerte und Grenzen liegen nicht hier ({@see SchoolSettingKey}). */
final class SchoolSettingRepository
{
    /**
     * Alle gespeicherten Werte nach Schluessel; was nie geaendert wurde, fehlt.
     *
     * @return array<string,int>
     */
    public function stored(): array
    {
        $rows = DB::getInstance()->query('SELECT `setting_key`, `value` FROM `school_settings`')->fetchAll(\PDO::FETCH_KEY_PAIR);
        return array_map('intval', $rows);
    }

    public function store(SchoolSettingKey $key, int $value): void
    {
        DB::getInstance()
            ->prepare('INSERT INTO `school_settings` (`setting_key`, `value`) VALUES (:key, :value) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')
            ->execute(['key' => $key->value, 'value' => $value]);
    }
}

<?php

declare(strict_types=1);

namespace TeacherOS\Modules\SchoolSettings;

use TeacherOS\Shared\Audit\Actor;
use TeacherOS\Shared\Audit\AuditLog;
use TeacherOS\Shared\Database\Transactions;
use TeacherOS\Shared\Exceptions\CodedException;

/**
 * Schuleinstellungen ansehen und aendern (Ticket #66, GLOSSARY.md: Schuleinstellung). Die Verwaltung der Schule nutzt
 * diesen Dienst ueber die Endpunkte; andere Module lesen die Werte ueber {@see SchoolLimits}.
 */
final class SchoolSettings
{
    private const LOCK = 'teacheros.school_settings';
    private const LOCK_WAIT_SECONDS = 10;

    public function __construct(
        private readonly SchoolSettingRepository $repository = new SchoolSettingRepository(),
        private readonly AuditLog $audit = new AuditLog(),
    ) {
    }

    /**
     * Alle Schuleinstellungen mit aktuellem Wert, Standard und zulaessigem Bereich.
     *
     * @return list<array{key:string,value:int,default:int,min:int,max:int}>
     */
    public function list(): array
    {
        $stored = $this->repository->stored();
        $settings = [];
        foreach (SchoolSettingKey::cases() as $key) {
            $settings[] = [
                'key' => $key->value,
                'value' => $stored[$key->value] ?? $key->default(),
                'default' => $key->default(),
                'min' => $key->min(),
                'max' => $key->max(),
            ];
        }
        return $settings;
    }

    /**
     * Aendert einen oder mehrere Werte. Alles wird vor dem Schreiben geprueft; wirklich geaenderte Werte bekommen je einen
     * Audit-Eintrag `school_setting.updated` (Objekt: Schluessel, Kontext: old_value, value), unveraenderte keinen.
     *
     * @param array<mixed> $changes Schluessel => neuer Wert
     * @return list<array{key:string,value:int,default:int,min:int,max:int}>
     * @throws CodedException `invalid_request` (400), `school_settings_busy` (503)
     */
    public function update(Actor $actor, array $changes): array
    {
        $validated = self::validated($changes);
        Transactions::underLock(
            self::LOCK,
            self::LOCK_WAIT_SECONDS,
            static fn (): \Throwable => new CodedException('school_settings_busy', 503, 'Die Schuleinstellungen werden gerade geändert. Bitte versuchen Sie es gleich noch einmal.'),
            function () use ($actor, $validated): void {
                $stored = $this->repository->stored();
                foreach ($validated as [$key, $value]) {
                    $old = $stored[$key->value] ?? $key->default();
                    $this->repository->store($key, $value);
                    if ($old !== $value) {
                        $this->audit->record($actor, AuditLog::SCHOOL_SETTING_UPDATED, 'school_setting', $key->value, ['old_value' => $old, 'value' => $value]);
                    }
                }
            },
        );

        return $this->list();
    }

    /**
     * @param array<mixed> $changes
     * @return list<array{0:SchoolSettingKey,1:int}>
     * @throws CodedException `invalid_request` (400)
     */
    private static function validated(array $changes): array
    {
        if ($changes === []) {
            throw new CodedException('invalid_request', 400, 'Bitte mindestens eine Schuleinstellung angeben.');
        }
        $validated = [];
        foreach ($changes as $name => $value) {
            $key = SchoolSettingKey::tryFrom((string) $name);
            if ($key === null) {
                throw new CodedException('invalid_request', 400, 'Diese Schuleinstellung gibt es nicht.');
            }
            if (!is_int($value) || $value < $key->min() || $value > $key->max()) {
                throw new CodedException('invalid_request', 400, "{$key->label()}: Bitte eine ganze Zahl von {$key->min()} bis {$key->max()} angeben.");
            }
            $validated[] = [$key, $value];
        }
        return $validated;
    }
}

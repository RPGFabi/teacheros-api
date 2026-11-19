<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

/**
 * Die Aktionen am Konto selbst (`account_actions` der Lesesicht, Tickets #41 bis #43). Alle verlangen das Recht
 * `accounts.deactivate`; die Texte der gemeinsamen Regeln ({@see AccountActionRules}) hangen am Verb.
 */
enum AccountAction: string
{
    case Deactivate = 'deactivate';
    case Reactivate = 'reactivate';
    /** "Abgleich wiederholen" (Ticket #42): braucht nur das Recht, keine weiteren Regeln. */
    case ResyncLogin = 'resync_login';

    /** Verb in Meldungen: "Konten zu deaktivieren", "Sie dürfen es deshalb nicht deaktivieren". */
    public function verb(): string
    {
        return match ($this) {
            self::Reactivate => 'reaktivieren',
            self::Deactivate, self::ResyncLogin => 'deaktivieren',
        };
    }

    /** Fehlercode, wenn der Akteur die Aktion am eigenen Konto ausfuehren will; nur Deaktivieren und Reaktivieren kennen ihn. */
    public function selfCode(): string
    {
        return match ($this) {
            self::Deactivate => 'self_deactivation',
            self::Reactivate => 'self_reactivation',
            self::ResyncLogin => throw new \LogicException('Der Abgleich kennt keine Regel gegen sich selbst.'),
        };
    }
}

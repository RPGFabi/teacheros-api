<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

/**
 * Die Aktionen am Konto selbst (`account_actions` der Lesesicht, Tickets #41 bis #43, #47, #48). Jede verlangt ihr eigenes
 * Recht ({@see self::right()}), ausser "Abgleich wiederholen": das verlangt eines der Konto-Rechte
 * ({@see PermissionCatalog::ACCOUNT_ACTION_RIGHTS}). Die Texte der gemeinsamen Regeln ({@see AccountActionRules}) haengen an der Aktion.
 */
enum AccountAction: string
{
    case Deactivate = 'deactivate';
    case Reactivate = 'reactivate';
    /** "Abgleich wiederholen" (Tickets #42, #47): holt alle offenen Aufrufe nach; braucht nur ein Konto-Recht, keine weiteren Regeln. */
    case ResyncLogin = 'resync_login';
    /** Sitzungswiderruf durch die Verwaltung (Ticket #48): beendet alle Sitzungen eines aktiven Kontos. */
    case RevokeSessions = 'revoke_sessions';

    /** Das Recht, das die Aktion verlangt (ohne `accounts.view`, das die Route zusaetzlich verlangt); null beim Abgleich: dort genuegt eines der Konto-Rechte. */
    public function right(): ?string
    {
        return match ($this) {
            self::Deactivate, self::Reactivate => PermissionCatalog::ACCOUNTS_DEACTIVATE,
            self::RevokeSessions => PermissionCatalog::ACCOUNTS_REVOKE_SESSIONS,
            self::ResyncLogin => null,
        };
    }

    /** Fehlercode, wenn der Akteur die Aktion am eigenen Konto ausfuehren will; der Abgleich kennt ihn nicht. */
    public function selfCode(): string
    {
        return match ($this) {
            self::Deactivate => 'self_deactivation',
            self::Reactivate => 'self_reactivation',
            self::RevokeSessions => 'self_revocation',
            self::ResyncLogin => throw new \LogicException('Der Abgleich kennt keine Regel gegen sich selbst.'),
        };
    }

    /** "Dafuer fehlt Ihnen das Recht, ...": die Taetigkeit im Infinitiv. */
    public function missingRightMessage(): string
    {
        return 'Dafür fehlt Ihnen das Recht, ' . match ($this) {
            self::Deactivate => 'Konten zu deaktivieren',
            self::Reactivate => 'Konten zu reaktivieren',
            self::RevokeSessions => 'die Sitzungen von Konten zu beenden',
            self::ResyncLogin => 'den Abgleich mit dem Anmeldedienst zu wiederholen',
        } . '.';
    }

    /** Meldung beim Versuch am eigenen Konto ({@see self::selfCode()}). */
    public function selfMessage(): string
    {
        return match ($this) {
            self::Deactivate => 'Sie können sich nicht selbst deaktivieren.',
            self::Reactivate => 'Sie können sich nicht selbst reaktivieren.',
            self::RevokeSessions => 'Für die eigenen Sitzungen gibt es „Mein Konto“; die Verwaltung beendet nur die Sitzungen anderer.',
            self::ResyncLogin => throw new \LogicException('Der Abgleich kennt keine Regel gegen sich selbst.'),
        };
    }

    /** Meldung, wenn P2 greift (der Akteur besitzt nicht alle Rechte des Zielkontos). */
    public function permissionNotHeldMessage(): string
    {
        return match ($this) {
            self::Deactivate => 'Sie besitzen nicht alle Rechte dieses Kontos und dürfen es deshalb nicht deaktivieren.',
            self::Reactivate => 'Sie besitzen nicht alle Rechte dieses Kontos und dürfen es deshalb nicht reaktivieren.',
            self::RevokeSessions => 'Sie besitzen nicht alle Rechte dieses Kontos und dürfen deshalb seine Sitzungen nicht beenden.',
            self::ResyncLogin => throw new \LogicException('Der Abgleich kennt keine Regel P2.'),
        };
    }
}

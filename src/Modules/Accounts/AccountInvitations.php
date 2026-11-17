<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Accounts;

use Hoelzle\Core\Database\DB;
use TeacherOS\Shared\Audit\Actor;
use TeacherOS\Shared\Audit\AuditLog;
use TeacherOS\Shared\AuthService\AuthServiceClient;
use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Security\AccessAdministration;
use TeacherOS\Shared\Security\Account;
use TeacherOS\Shared\Security\AccountRepository;

/**
 * Einladung (GLOSSARY.md): der einzige Weg, auf dem ein Konto entsteht (ADR 0002 G5).
 *
 * Ablauf: zuerst der idempotente Aufruf des Anmeldedienstes, danach Konto, Rolle und Audit-Eintrag
 * in einer DB-Transaktion. Scheitert der Anmeldedienst, ist lokal nichts passiert; scheitert danach
 * die Transaktion, bringt die Wiederholung denselben Endzustand (zweite Mail als Nebenwirkung).
 */
final class AccountInvitations
{
    public function __construct(
        private readonly AuthServiceClient $authService = new AuthServiceClient(),
        private readonly AccountRepository $accounts = new AccountRepository(),
        private readonly AccessAdministration $access = new AccessAdministration(),
        private readonly AuditLog $audit = new AuditLog(),
    ) {
    }

    /**
     * Laedt den ersten Schuladmin ein (CLI, Akteur `cli`). Ohne $allowAdditionalAdmin lehnt der Aufruf
     * ab (`admin_exists`), sobald es ein aktives Konto mit der Rolle Schuladmin gibt. Eine noch nicht
     * angenommene Einladung eines Schuladmins darf ohne Option erneut ergehen, auch an eine andere
     * Adresse: die frueheren Einladungen werden dann ersetzt (beim Anmeldedienst geloescht, lokal entfernt).
     *
     * @throws CodedException `already_active`, `account_inactive`, `username_taken`, `email_taken`, `admin_exists`
     * @throws \TeacherOS\Shared\AuthService\AuthServiceUnavailableException
     */
    public function inviteFirstSchuladmin(?string $email, ?string $loginName, bool $allowAdditionalAdmin = false): InvitationResult
    {
        $actor = Actor::cli();
        $target = $this->findTarget($email, $loginName);

        $replaced = [];
        if (!$allowAdditionalAdmin) {
            if ($this->accounts->existsActiveWithRole(AccessAdministration::ROLE_SCHULADMIN)) {
                throw new CodedException('admin_exists', 409, 'Es gibt schon einen aktiven Schuladmin (Option --allow-additional-admin, um einen weiteren einzuladen).');
            }
            $replaced = array_values(array_filter(
                $this->accounts->invitedWithRole(AccessAdministration::ROLE_SCHULADMIN),
                static fn (string $id) => $id !== $target?->publicUserId,
            ));
        }

        $invite = $this->authService->invite($email, $loginName);
        foreach ($replaced as $oldId) {
            $this->authService->deleteUser($oldId);
        }
        $name = $invite->username ?? $target?->loginName ?? $loginName ?? $email;

        $pdo = DB::getInstance();
        $pdo->beginTransaction();
        try {
            foreach ($replaced as $oldId) {
                $this->access->revokeAllRoles($oldId, $actor);
                $this->accounts->delete($oldId);
            }
            $reinvited = $target !== null || $replaced !== [];
            if ($target !== null) {
                $this->accounts->markInvitedNow($target->publicUserId);
            } else {
                $this->accounts->create($invite->publicUserId, 'invited', $email, $name);
            }
            $this->audit->record(
                $actor,
                $reinvited ? AuditLog::ACCOUNT_REINVITED : AuditLog::ACCOUNT_INVITED,
                'account',
                $invite->publicUserId,
                $replaced === [] ? null : ['replaces_invitations' => count($replaced)],
            );
            $this->access->grantInitialAdmin($invite->publicUserId, $actor);
            $pdo->commit();
        } catch (\PDOException $e) {
            $pdo->rollBack();
            throw $this->conflictFrom($e) ?? $e;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return new InvitationResult($invite->publicUserId, $email, $name, $reinvited, $invite->inviteCode);
    }

    /**
     * Laedt ein Konto per E-Mail und/oder Anmeldename ein (Web, Akteur ist das einladende Konto). Gehoert die E-Mail schon einem
     * noch nicht angenommenen Konto (ohne E-Mail: der Anmeldename), ist das eine erneute Einladung desselben
     * Kontos (Wiederholung gefahrlos). Ohne E-Mail liefert das Ergebnis einmalig den Einladungscode.
     * Rollen werden hier nicht vergeben.
     *
     * @throws CodedException `already_active`, `account_inactive`, `username_taken`, `email_taken`
     * @throws \TeacherOS\Shared\AuthService\AuthServiceUnavailableException
     */
    public function invite(?string $email, ?string $loginName, Actor $actor): InvitationResult
    {
        return $this->send($this->findTarget($email, $loginName), $email, $loginName, $actor);
    }

    /**
     * Laedt ein noch nicht angenommenes Konto erneut ein; die vorige Einladung wird dadurch ungueltig.
     *
     * @throws CodedException `account_not_found` (404), `already_active`, `account_inactive`
     * @throws \TeacherOS\Shared\AuthService\AuthServiceUnavailableException
     */
    public function reinvite(string $publicUserId, Actor $actor): InvitationResult
    {
        $target = $this->accounts->findByPublicUserId($publicUserId)
            ?? throw new CodedException('account_not_found', 404, 'Das Konto gibt es nicht.');
        $this->assertInvitable($target);
        $ownName = strcasecmp($target->loginName, (string) $target->email) === 0 ? null : $target->loginName;

        return $this->send($target, $target->email, $ownName, $actor);
    }

    /** Anmeldedienst, dann Konto und Audit-Eintrag in einer Transaktion (ohne Rolle). */
    private function send(?Account $target, ?string $email, ?string $loginName, Actor $actor): InvitationResult
    {
        $invite = $this->authService->invite($email, $loginName);
        $name = $invite->username ?? $target?->loginName ?? $loginName ?? $email;

        $this->transaction(function () use ($target, $invite, $email, $name, $actor): void {
            if ($target !== null) {
                $this->accounts->markInvitedNow($target->publicUserId);
            } else {
                $this->accounts->create($invite->publicUserId, 'invited', $email, $name);
            }
            $this->audit->record(
                $actor,
                $target !== null ? AuditLog::ACCOUNT_REINVITED : AuditLog::ACCOUNT_INVITED,
                'account',
                $invite->publicUserId,
            );
        });

        return new InvitationResult($invite->publicUserId, $email, $name, $target !== null, $invite->inviteCode);
    }

    /** Fuehrt $work in einer DB-Transaktion aus; Eindeutigkeitsverletzungen werden zu `email_taken`/`username_taken`. */
    private function transaction(callable $work): void
    {
        $pdo = DB::getInstance();
        $pdo->beginTransaction();
        try {
            $work();
            $pdo->commit();
        } catch (\PDOException $e) {
            $pdo->rollBack();
            throw $this->conflictFrom($e) ?? $e;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Das lokale Konto, das diese Einladung erneuert (nach E-Mail, sonst nach Anmeldename), oder null.
     * Aktive Konten und fremde Namen werden hier abgewiesen, bevor der Anmeldedienst gefragt wird.
     */
    private function findTarget(?string $email, ?string $loginName): ?Account
    {
        $byEmail = $email !== null ? $this->accounts->findByEmail($email) : null;
        $byName = $loginName !== null ? $this->accounts->findByLoginName($loginName) : null;
        $target = $email !== null ? $byEmail : $byName;

        if ($email !== null && $byName !== null && $byName->publicUserId !== $byEmail?->publicUserId) {
            throw new CodedException('username_taken', 409, 'Der Anmeldename ist schon vergeben.');
        }
        if ($byEmail !== null && $loginName !== null && strcasecmp($loginName, $byEmail->loginName) !== 0) {
            throw new CodedException('email_taken', 409, 'Die E-Mail-Adresse gehoert schon zu einem anderen Konto.');
        }
        if ($target !== null) {
            $this->assertInvitable($target);
        }
        return $target;
    }

    private function assertInvitable(Account $account): void
    {
        if ($account->status === 'active') {
            throw new CodedException('already_active', 409, 'Das Konto ist schon aktiv.');
        }
        if ($account->status === 'inactive') {
            throw new CodedException('account_inactive', 409, 'Das Konto ist deaktiviert und kann nicht eingeladen werden.');
        }
    }

    private function conflictFrom(\PDOException $e): ?CodedException
    {
        if ((string) $e->getCode() !== '23000') {
            return null;
        }
        return str_contains($e->getMessage(), 'uq_accounts_email')
            ? new CodedException('email_taken', 409, 'Die E-Mail-Adresse ist schon vergeben.')
            : (str_contains($e->getMessage(), 'uq_accounts_login_name')
                ? new CodedException('username_taken', 409, 'Der Anmeldename ist schon vergeben.')
                : null);
    }
}

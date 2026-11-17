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
        if ($target?->status === 'active') {
            throw new CodedException('already_active', 409, 'Das Konto ist schon aktiv.');
        }
        if ($target?->status === 'inactive') {
            throw new CodedException('account_inactive', 409, 'Das Konto ist deaktiviert und kann nicht eingeladen werden.');
        }
        return $target;
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

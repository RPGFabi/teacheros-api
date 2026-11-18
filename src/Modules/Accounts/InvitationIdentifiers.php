<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Accounts;

use TeacherOS\Shared\Exceptions\CodedException;

/**
 * E-Mail-Adresse und Anmeldename einer Einladung, geprueft und normalisiert (getrimmt, kleingeschrieben,
 * ADR 0002 G6). Einzige Stelle dafuer: Web-Endpunkt und CLI nutzen sie beide.
 */
final class InvitationIdentifiers
{
    private function __construct(
        public readonly ?string $email,
        public readonly ?string $loginName,
    ) {
    }

    /**
     * @param mixed $email Rohwert (z. B. aus dem Request-Body); alles ausser einem nicht leeren Text gilt als nicht angegeben
     * @param mixed $loginName wie $email
     * @throws CodedException `identifier_required` (weder E-Mail noch Anmeldename), `invalid_email` (beide 400)
     */
    public static function from(mixed $email, mixed $loginName): self
    {
        $email = self::text($email);
        $loginName = self::text($loginName);
        if ($email === null && $loginName === null) {
            throw new CodedException('identifier_required', 400, 'Eine E-Mail-Adresse oder ein Anmeldename ist erforderlich.');
        }
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new CodedException('invalid_email', 400, 'Die E-Mail-Adresse ist ungueltig.');
        }

        return new self($email === null ? null : strtolower($email), $loginName === null ? null : strtolower($loginName));
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}

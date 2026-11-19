<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

/** Wer einen Sitzungswiderruf ausgeloest hat: steht als `initiated_by` im Audit-Eintrag `account.sessions_revoked` (ADR 0002 Nachtrag #16). */
enum RevocationInitiator: string
{
    /** Die Verwaltung (Ticket #48). */
    case Administration = 'admin';
    /** Das Konto selbst auf "Mein Konto" (Ticket #50). */
    case Owner = 'self';
}

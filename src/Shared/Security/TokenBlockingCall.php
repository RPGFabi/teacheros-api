<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Security;

/**
 * Markiert einen {@see OpenCallHandler}, dessen offener Aufruf das Konto sperrt (ADR 0002 Nachtrag #16, Ticket #48):
 * Solange er offen ist, hat der Anmeldedienst die Refresh-Tokens des Kontos noch nicht widerrufen (Sitzungswiderruf, G3)
 * oder das TOTP noch nicht zurueckgesetzt (G1). B2 sperrt aber nur Tokens bis zur Sitzungsgrenze, ein Refresh-Token koennte
 * neue Tokens erzeugen. Darum lehnt die Authentifizierung ({@see AuthenticationMiddleware}) **jedes** Token des Kontos mit
 * 401 `session_revoked` ab, solange ein solcher Aufruf offen ist, und versucht beim ersten Token nach der Grenze, ihn
 * nachzuholen ({@see OpenCalls::releaseTokens()}). Wer einen neuen blockierenden Aufruf einfuehrt (TOTP-Zuruecksetzung, #49),
 * markiert seinen Handler mit dieser Schnittstelle und traegt ihn in {@see OpenCalls::standardHandlers()} ein; sonst ist nichts zu tun.
 */
interface TokenBlockingCall extends OpenCallHandler
{
}

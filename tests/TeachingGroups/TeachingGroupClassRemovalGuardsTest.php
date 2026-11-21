<?php

declare(strict_types=1);

namespace TeacherOS\Tests\TeachingGroups;

use PHPUnit\Framework\TestCase;
use TeacherOS\Modules\TeachingGroups\TeachingGroupClassRemovalGuard;
use TeacherOS\Modules\TeachingGroups\TeachingGroupClassRemovalGuards;
use TeacherOS\Modules\TeachingGroups\TeachingGroupClassRemovalObjection;
use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Extension\ExtensionPoints;

/** Der Erweiterungspunkt gegen das Entfernen einer Klasse aus einer Fachgruppe (Ticket #65) als Instanz, ohne Datenbank und ohne HTTP. */
final class TeachingGroupClassRemovalGuardsTest extends TestCase
{
    /** @param list<array{string,string}> $blocked Paare aus Fachgruppe und Klasse */
    private static function guard(string $code, array $blocked): TeachingGroupClassRemovalGuard
    {
        return new class ($code, $blocked) implements TeachingGroupClassRemovalGuard {
            /** @param list<array{string,string}> $blocked */
            public function __construct(private readonly string $code, private readonly array $blocked)
            {
            }

            public function objectionToRemoval(string $teachingGroupId, string $classId): ?TeachingGroupClassRemovalObjection
            {
                return in_array([$teachingGroupId, $classId], $this->blocked, true)
                    ? new TeachingGroupClassRemovalObjection($this->code, 'Einwand ' . $this->code)
                    : null;
            }
        };
    }

    public function testOhneAnmeldungHatNiemandEtwasEinzuwenden(): void
    {
        (new TeachingGroupClassRemovalGuards())->assertRemovable('g1', 'c1');

        $this->addToAssertionCount(1);
    }

    public function testDerErsteEinwandGewinntAlsConflictMitCodeUndMeldung(): void
    {
        $guards = new TeachingGroupClassRemovalGuards();
        $guards->register(self::guard('first', [['g1', 'c1']]));
        $guards->register(self::guard('second', [['g1', 'c1'], ['g1', 'c2']]));

        try {
            $guards->assertRemovable('g1', 'c1');
            $this->fail('Einwand erwartet');
        } catch (CodedException $e) {
            $this->assertSame('first', $e->errorCode);
            $this->assertSame(409, $e->getHttpCode());
            $this->assertSame('Einwand first', $e->getMessage());
        }
        try {
            $guards->assertRemovable('g1', 'c2');
            $this->fail('Einwand erwartet');
        } catch (CodedException $e) {
            $this->assertSame('second', $e->errorCode);
        }
    }

    public function testDerEinwandBetrifftNurDieseFachgruppeUndDieseKlasse(): void
    {
        $guards = new TeachingGroupClassRemovalGuards();
        $guards->register(self::guard('blocked', [['g1', 'c1']]));

        $guards->assertRemovable('g1', 'c9');
        $guards->assertRemovable('g9', 'c1');
        $this->addToAssertionCount(2);
    }

    public function testErweiterungspunkteSindProInstanz(): void
    {
        $one = new ExtensionPoints();
        $one->get(TeachingGroupClassRemovalGuards::class)->register(self::guard('blocked', [['g1', 'c1']]));

        $this->expectException(CodedException::class);
        try {
            (new ExtensionPoints())->get(TeachingGroupClassRemovalGuards::class)->assertRemovable('g1', 'c1');
        } catch (CodedException) {
            $this->fail('eine andere Instanz kennt die Anmeldung nicht');
        }
        $one->get(TeachingGroupClassRemovalGuards::class)->assertRemovable('g1', 'c1');
    }
}

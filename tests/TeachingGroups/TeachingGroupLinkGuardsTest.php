<?php

declare(strict_types=1);

namespace TeacherOS\Tests\TeachingGroups;

use PHPUnit\Framework\TestCase;
use TeacherOS\Modules\TeachingGroups\TeachingGroupLinkGuard;
use TeacherOS\Modules\TeachingGroups\TeachingGroupLinkGuards;
use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Extension\ExtensionPoints;

/** Der Erweiterungspunkt der Fachgruppen (Ticket #65) als Instanz, ohne Datenbank und ohne HTTP. */
final class TeachingGroupLinkGuardsTest extends TestCase
{
    /** @param list<string> $linkedGroupIds */
    private static function guard(string $label, array $linkedGroupIds): TeachingGroupLinkGuard
    {
        return new class ($label, $linkedGroupIds) implements TeachingGroupLinkGuard {
            /** @param list<string> $linked */
            public function __construct(private readonly string $label, private readonly array $linked)
            {
            }

            public function label(): string
            {
                return $this->label;
            }

            public function isLinked(string $teachingGroupId): bool
            {
                return in_array($teachingGroupId, $this->linked, true);
            }
        };
    }

    public function testOhneAnmeldungIstEineFachgruppeUnverknuepft(): void
    {
        $guards = new TeachingGroupLinkGuards();

        $this->assertSame([], $guards->linksOf('g1'));
        $guards->assertDeletable('g1');
        $this->addToAssertionCount(1);
    }

    public function testLinksOfNenntAlleVerknuepfungenInDerReihenfolgeDerAnmeldung(): void
    {
        $guards = new TeachingGroupLinkGuards();
        $guards->register(self::guard('Lehrkraft-Zuordnung', ['g1', 'g2']));
        $guards->register(self::guard('Testverknuepfung', ['g2']));

        $this->assertSame(['Lehrkraft-Zuordnung'], $guards->linksOf('g1'));
        $this->assertSame(['Lehrkraft-Zuordnung', 'Testverknuepfung'], $guards->linksOf('g2'));
        $this->assertSame([], $guards->linksOf('g3'));
    }

    public function testEineVerknuepfteFachgruppeLaesstSichNichtLoeschenDieMeldungNenntDieVerknuepfungen(): void
    {
        $guards = new TeachingGroupLinkGuards();
        $guards->register(self::guard('Lehrkraft-Zuordnung', ['g1']));
        $guards->register(self::guard('Testverknuepfung', ['g1']));

        try {
            $guards->assertDeletable('g1');
            $this->fail('teaching_group_linked erwartet');
        } catch (CodedException $e) {
            $this->assertSame('teaching_group_linked', $e->errorCode);
            $this->assertSame(409, $e->getHttpCode());
            $this->assertStringContainsString('Lehrkraft-Zuordnung, Testverknuepfung', $e->getMessage());
            $this->assertStringContainsString('archivieren', $e->getMessage());
        }
        $guards->assertDeletable('g9');
        $this->addToAssertionCount(1);
    }

    public function testErweiterungspunkteSindProInstanz(): void
    {
        $one = new ExtensionPoints();
        $one->get(TeachingGroupLinkGuards::class)->register(self::guard('Lehrkraft-Zuordnung', ['g1']));

        $this->assertSame(['Lehrkraft-Zuordnung'], $one->get(TeachingGroupLinkGuards::class)->linksOf('g1'));
        $this->assertSame([], (new ExtensionPoints())->get(TeachingGroupLinkGuards::class)->linksOf('g1'));
    }
}

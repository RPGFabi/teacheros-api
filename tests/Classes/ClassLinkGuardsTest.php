<?php

declare(strict_types=1);

namespace TeacherOS\Tests\Classes;

use PHPUnit\Framework\TestCase;
use TeacherOS\Modules\Classes\ClassLinkGuard;
use TeacherOS\Modules\Classes\ClassLinkGuards;
use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Extension\ExtensionPoints;

/** Der Erweiterungspunkt der Klassen (Ticket #57) als Instanz, ohne Datenbank und ohne HTTP. */
final class ClassLinkGuardsTest extends TestCase
{
    /** @param list<string> $linkedClassIds */
    private static function guard(string $label, array $linkedClassIds): ClassLinkGuard
    {
        return new class ($label, $linkedClassIds) implements ClassLinkGuard {
            /** @param list<string> $linked */
            public function __construct(private readonly string $label, private readonly array $linked)
            {
            }

            public function label(): string
            {
                return $this->label;
            }

            public function isLinked(string $classId): bool
            {
                return in_array($classId, $this->linked, true);
            }
        };
    }

    public function testOhneAnmeldungIstEineKlasseUnverknuepft(): void
    {
        $guards = new ClassLinkGuards();

        $this->assertSame([], $guards->linksOf('c1'));
        $guards->assertDeletable('c1');
        $this->addToAssertionCount(1);
    }

    public function testLinksOfNenntAlleVerknuepfungenInDerReihenfolgeDerAnmeldung(): void
    {
        $guards = new ClassLinkGuards();
        $guards->register(self::guard('Stundenplan', ['c1', 'c2']));
        $guards->register(self::guard('Schüler', ['c2']));

        $this->assertSame(['Stundenplan'], $guards->linksOf('c1'));
        $this->assertSame(['Stundenplan', 'Schüler'], $guards->linksOf('c2'));
        $this->assertSame([], $guards->linksOf('c3'));
    }

    public function testEineVerknuepfteKlasseLaesstSichNichtLoeschenDieMeldungNenntDieVerknuepfungen(): void
    {
        $guards = new ClassLinkGuards();
        $guards->register(self::guard('Stundenplan', ['c1']));
        $guards->register(self::guard('Schüler', ['c1']));

        try {
            $guards->assertDeletable('c1');
            $this->fail('class_linked erwartet');
        } catch (CodedException $e) {
            $this->assertSame('class_linked', $e->errorCode);
            $this->assertSame(409, $e->getHttpCode());
            $this->assertStringContainsString('Stundenplan, Schüler', $e->getMessage());
            $this->assertStringContainsString('archivieren', $e->getMessage());
        }
        $guards->assertDeletable('c9');
        $this->addToAssertionCount(1);
    }

    public function testErweiterungspunkteSindProInstanz(): void
    {
        $one = new ExtensionPoints();
        $one->get(ClassLinkGuards::class)->register(self::guard('Stundenplan', ['c1']));

        $this->assertSame(['Stundenplan'], $one->get(ClassLinkGuards::class)->linksOf('c1'));
        $this->assertSame([], (new ExtensionPoints())->get(ClassLinkGuards::class)->linksOf('c1'));
    }
}

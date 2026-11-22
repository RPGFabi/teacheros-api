<?php

declare(strict_types=1);

namespace TeacherOS\Tests\Subjects;

use PHPUnit\Framework\TestCase;
use TeacherOS\Modules\Subjects\SubjectLinkGuard;
use TeacherOS\Modules\Subjects\SubjectLinkGuards;
use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Extension\ExtensionPoints;

/** Der Erweiterungspunkt der Fächer (Ticket #63) als Instanz, ohne Datenbank und ohne HTTP. */
final class SubjectLinkGuardsTest extends TestCase
{
    /** @param list<string> $linkedSubjectIds */
    private static function guard(string $label, array $linkedSubjectIds): SubjectLinkGuard
    {
        return new class ($label, $linkedSubjectIds) implements SubjectLinkGuard {
            /** @param list<string> $linked */
            public function __construct(private readonly string $label, private readonly array $linked)
            {
            }

            public function label(): string
            {
                return $this->label;
            }

            public function isLinked(string $subjectId): bool
            {
                return in_array($subjectId, $this->linked, true);
            }
        };
    }

    public function testOhneAnmeldungIstEinFachUnverknuepft(): void
    {
        $guards = new SubjectLinkGuards();

        $this->assertSame([], $guards->linksOf('s1'));
        $guards->assertDeletable('s1');
        $this->addToAssertionCount(1);
    }

    public function testLinksOfNenntAlleVerknuepfungenInDerReihenfolgeDerAnmeldung(): void
    {
        $guards = new SubjectLinkGuards();
        $guards->register(self::guard('Unterrichtsplanung', ['s1', 's2']));
        $guards->register(self::guard('Fachgruppe', ['s2']));

        $this->assertSame(['Unterrichtsplanung'], $guards->linksOf('s1'));
        $this->assertSame(['Unterrichtsplanung', 'Fachgruppe'], $guards->linksOf('s2'));
        $this->assertSame([], $guards->linksOf('s3'));
    }

    public function testEinVerknuepftesFachLaesstSichNichtLoeschenDieMeldungNenntDieVerknuepfungen(): void
    {
        $guards = new SubjectLinkGuards();
        $guards->register(self::guard('Unterrichtsplanung', ['s1']));
        $guards->register(self::guard('Fachgruppe', ['s1']));

        try {
            $guards->assertDeletable('s1');
            $this->fail('subject_linked erwartet');
        } catch (CodedException $e) {
            $this->assertSame('subject_linked', $e->errorCode);
            $this->assertSame(409, $e->getHttpCode());
            $this->assertStringContainsString('Unterrichtsplanung, Fachgruppe', $e->getMessage());
            $this->assertStringContainsString('archivieren', $e->getMessage());
        }
        $guards->assertDeletable('s9');
        $this->addToAssertionCount(1);
    }

    public function testErweiterungspunkteSindProInstanz(): void
    {
        $one = new ExtensionPoints();
        $one->get(SubjectLinkGuards::class)->register(self::guard('Unterrichtsplanung', ['s1']));

        $this->assertSame(['Unterrichtsplanung'], $one->get(SubjectLinkGuards::class)->linksOf('s1'));
        $this->assertSame([], (new ExtensionPoints())->get(SubjectLinkGuards::class)->linksOf('s1'));
    }
}

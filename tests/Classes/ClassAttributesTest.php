<?php

declare(strict_types=1);

namespace TeacherOS\Tests\Classes;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TeacherOS\Modules\Classes\ClassAttributes;
use TeacherOS\Shared\Exceptions\CodedException;

/**
 * Regeln fuer Klassenstufe und Bezeichnung (Review #17): Die Bezeichnung wird Unicode-bewusst getrimmt (auch geschuetzte
 * und andere Leerzeichen, Zeilenumbrueche, unsichtbare Zeichen am Rand) und, wenn ext-intl da ist, in NFC normalisiert,
 * bevor sie gespeichert und verglichen wird.
 */
final class ClassAttributesTest extends TestCase
{
    /** @return iterable<string,array{string,string}> */
    public static function getrimmteBezeichnungen(): iterable
    {
        yield 'Leerzeichen' => ['  7a  ', '7a'];
        yield 'geschuetztes Leerzeichen' => ["\u{00A0}7a\u{00A0}", '7a'];
        yield 'Tabulator und Zeilenumbruch' => ["\t7a\r\n", '7a'];
        yield 'ideographisches Leerzeichen' => ["\u{3000}7a\u{3000}", '7a'];
        yield 'schmales geschuetztes Leerzeichen' => ["\u{202F}7a\u{202F}", '7a'];
        yield 'Zeilentrenner' => ["\u{2028}7a\u{2029}", '7a'];
        yield 'Zero-Width-Space' => ["\u{200B}7a\u{200B}", '7a'];
        yield 'Byte-Order-Mark' => ["\u{FEFF}7a", '7a'];
        yield 'Innenraum bleibt' => ["7\u{00A0}a", "7\u{00A0}a"];
    }

    #[DataProvider('getrimmteBezeichnungen')]
    public function testBezeichnungWirdUnicodeBewusstGetrimmt(string $input, string $expected): void
    {
        $this->assertSame($expected, ClassAttributes::fromInput(7, $input)->name);
    }

    /** @return iterable<string,array{mixed}> */
    public static function leereBezeichnungen(): iterable
    {
        yield 'leer' => [''];
        yield 'Leerzeichen' => ['   '];
        yield 'nur geschuetzte Leerzeichen' => ["\u{00A0}\u{00A0}"];
        yield 'nur ideographische Leerzeichen' => ["\u{3000}"];
        yield 'nur unsichtbare Zeichen' => ["\u{200B}\u{FEFF}"];
        yield 'Zeilenumbrueche' => ["\r\n\t"];
        yield 'ungueltiges UTF-8' => ["7\xFFa"];
        yield 'kein Text' => [7];
        yield 'null' => [null];
    }

    #[DataProvider('leereBezeichnungen')]
    public function testBezeichnungNurAusLeerraumIstUngueltig(mixed $input): void
    {
        try {
            ClassAttributes::fromInput(7, $input);
            $this->fail('invalid_request erwartet');
        } catch (CodedException $e) {
            $this->assertSame('invalid_request', $e->errorCode);
            $this->assertSame(400, $e->getHttpCode());
        }
    }

    public function testLaengeZaehltNachDemTrimmen(): void
    {
        $this->assertSame(20, mb_strlen(ClassAttributes::fromInput(7, "\u{00A0}" . str_repeat('ä', 20) . "\u{00A0}")->name));
        $this->expectException(CodedException::class);
        ClassAttributes::fromInput(7, "\u{00A0}" . str_repeat('ä', 21));
    }

    public function testBezeichnungWirdNachNfcNormalisiert(): void
    {
        if (!class_exists(\Normalizer::class)) {
            $this->markTestSkipped('ext-intl fehlt: ohne sie wird nicht normalisiert (docs/workspace.md).');
        }
        $composed = ClassAttributes::fromInput(7, "7\u{00E4}");
        $decomposed = ClassAttributes::fromInput(7, "7a\u{0308}");

        $this->assertSame("7\u{00E4}", $decomposed->name, 'gespeichert wird die NFC-Form');
        $this->assertSame($composed->name, $decomposed->name);
        $this->assertSame($composed->nameKey(), $decomposed->nameKey());
    }

    public function testNameKeyIgnoriertGrossUndKleinschreibungNichtAberAkzente(): void
    {
        $this->assertSame(ClassAttributes::fromInput(7, '7A')->nameKey(), ClassAttributes::fromInput(7, '7a')->nameKey());
        $this->assertNotSame(ClassAttributes::fromInput(7, '7ä')->nameKey(), ClassAttributes::fromInput(7, '7a')->nameKey());
    }

    public function testLaengeZaehltNachNormalisierung(): void
    {
        if (!class_exists(\Normalizer::class)) {
            $this->markTestSkipped('ext-intl fehlt');
        }
        $decomposed = str_repeat("a\u{0308}", 20); // 40 Codepunkte, nach NFC 20 Zeichen
        $this->assertSame(20, mb_strlen(ClassAttributes::fromInput(7, $decomposed)->name));
    }
}

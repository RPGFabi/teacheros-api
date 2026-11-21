<?php

declare(strict_types=1);

namespace TeacherOS\Modules\Subjects;

use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Text\Text;

/**
 * Geprueftes Anlegen eines Faches (GLOSSARY.md: Fach). Kuerzel: nach dem Trimmen 1 bis 10 Zeichen, ohne Leerraum im
 * Inneren, nur Buchstaben (auch Umlaute), Ziffern, "-" und "_"; die Schreibung bleibt, die Eindeutigkeit gilt ohne
 * Gross-/Kleinschreibung ({@see self::codeKey()}). Bezeichnung: nach dem Trimmen 1 bis 60 Zeichen, nicht eindeutig.
 * Getrimmt wird Unicode-bewusst und nach NFC normalisiert wie bei der Klassenbezeichnung ({@see Text}).
 */
final class SubjectInput
{
    public const MAX_CODE_LENGTH = 10;
    public const MAX_NAME_LENGTH = 60;

    private function __construct(
        public readonly string $code,
        public readonly string $name,
    ) {
    }

    /** @throws CodedException `invalid_request` (400) */
    public static function fromInput(mixed $code, mixed $name): self
    {
        $code = is_string($code) ? Text::clean($code) : '';
        $length = mb_strlen($code);
        if ($length < 1 || $length > self::MAX_CODE_LENGTH || preg_match('/^[\p{L}\p{Nd}_-]+$/u', $code) !== 1) {
            throw new CodedException('invalid_request', 400, 'Bitte ein Kürzel mit 1 bis ' . self::MAX_CODE_LENGTH . ' Zeichen angeben: nur Buchstaben, Ziffern, "-" und "_", ohne Leerzeichen.');
        }
        $name = is_string($name) ? Text::clean($name) : '';
        $length = mb_strlen($name);
        if ($length < 1 || $length > self::MAX_NAME_LENGTH) {
            throw new CodedException('invalid_request', 400, 'Bitte eine Bezeichnung mit 1 bis ' . self::MAX_NAME_LENGTH . ' Zeichen angeben.');
        }
        return new self($code, $name);
    }

    /** Schluessel der Eindeutigkeit: das Kuerzel ohne Ruecksicht auf Gross-/Kleinschreibung. */
    public function codeKey(): string
    {
        return Text::normalized(mb_strtolower($this->code));
    }
}

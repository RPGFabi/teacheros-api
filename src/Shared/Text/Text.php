<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Text;

/**
 * Gemeinsame Textregeln fuer Eingaben von Stammdaten (Klassenbezeichnung, Fach): Unicode-bewusstes Trimmen und NFC.
 */
final class Text
{
    /**
     * Ohne Leerraum am Rand: Leerzeichen jeder Art (\p{Z}), Steuer- und Formatzeichen (\p{Cc}, \p{Cf}: Tabulator, Zeilenumbruch,
     * Zero-Width-Space, BOM). Ungueltiges UTF-8 ergibt "".
     */
    public static function trimmed(string $text): string
    {
        return mb_check_encoding($text, 'UTF-8') ? (string) preg_replace('/^[\p{Z}\p{Cc}\p{Cf}]+|[\p{Z}\p{Cc}\p{Cf}]+$/u', '', $text) : '';
    }

    /** NFC, sofern ext-intl vorhanden ist; sonst unveraendert. */
    public static function normalized(string $text): string
    {
        return class_exists(\Normalizer::class) ? (\Normalizer::normalize($text, \Normalizer::FORM_C) ?: $text) : $text;
    }

    /** Getrimmt und normalisiert: die Fassung, die gespeichert wird. */
    public static function clean(string $text): string
    {
        return self::normalized(self::trimmed($text));
    }
}

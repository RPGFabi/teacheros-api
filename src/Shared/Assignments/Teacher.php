<?php

declare(strict_types=1);

namespace TeacherOS\Shared\Assignments;

/**
 * Eine waehlbare Lehrkraft, wie sie die Liste der waehlbaren Lehrkraefte zeigt (GLOSSARY.md: Lehrkraft): das Konto mit Kennung,
 * Anmeldename, E-Mail (falls bekannt) und Kontostatus (`invited`, `active`). E-Mail und Status stehen nur hier, fuer die
 * Verwaltung beim Zuordnen (Recht `teacher_assignments.manage`); die Ausgaben bestehender Zuordnungen nennen nur {@see TeacherRef}.
 * Die Zuordnung haengt vorerst am Konto (ADR 0007).
 */
final class Teacher
{
    public function __construct(
        public readonly string $id,
        public readonly string $loginName,
        public readonly ?string $email,
        public readonly string $status,
    ) {
    }

    /** @param array{id:string,email:?string,login_name:string,status:string} $row */
    public static function fromRow(array $row): self
    {
        return new self($row['id'], $row['login_name'], $row['email'], $row['status']);
    }

    /** @return array{id:string,login_name:string,email:?string,status:string} die JSON-Form der Liste der waehlbaren Lehrkraefte */
    public function toArray(): array
    {
        return ['id' => $this->id, 'login_name' => $this->loginName, 'email' => $this->email, 'status' => $this->status];
    }
}

<?php

declare(strict_types=1);

namespace TeacherOS\Modules\TeacherAssignments;

/**
 * Eine Lehrkraft, wie sie die Ausgaben zeigen (GLOSSARY.md: Lehrkraft): das Konto mit Kennung, Anmeldename, E-Mail (falls
 * bekannt) und Kontostatus (`invited`, `active`, `inactive`). Die Zuordnung hing vorerst am Konto (ADR 0007).
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

    /** @return array{id:string,login_name:string,email:?string,status:string} die JSON-Form der API */
    public function toArray(): array
    {
        return ['id' => $this->id, 'login_name' => $this->loginName, 'email' => $this->email, 'status' => $this->status];
    }
}

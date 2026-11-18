<?php

declare(strict_types=1);

use TeacherOS\Shared\Audit\Actor;
use TeacherOS\Shared\Security\StandardRoles;

// Die sechs Standardrollen jeder Schulinstanz (GLOSSARY.md, ADR 0002 B4 Nachtrag #11), idempotent.
// Nur beim tatsaechlichen Anlegen entsteht ein Audit-Eintrag `role.created` (Akteur `cli`).
(new StandardRoles())->ensureAll(Actor::cli());

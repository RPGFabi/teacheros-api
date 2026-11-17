---
status: accepted
date: 2026-11-17
---

# Backend auf hoelzle/php-core statt Slim, PHP ≥ 8.4

Das TeacherOS-Backend nutzt `hoelzle/php-core` (Router, DB, Config, Validierung, Setup-Runner) wie die EAW-API und der Auth-Dienst. Slim aus dem ursprünglichen Gerüst wird entfernt. Gründe: Source of Truth 4.1 verlangt die Wiederverwendung vorhandener PHP-Pakete, und ein einheitliches Fundament über alle eigenen Dienste senkt den Pflegeaufwand. Auf PSR-7/15-Middleware und das breitere Slim-Ökosystem wird dafür verzichtet. Mindestversion ist PHP 8.4 (Source of Truth 4.1). Lokal läuft 8.4 parallel zu 8.3, damit EAW unberührt bleibt.

## Consequences

Migrationen laufen nur vorwärts über den Setup-Runner von `php-core`, nach dem Muster erst hinzufügen, später entfernen (expand/contract). Alte und neue Backend-Version müssen mit jedem Zwischenstand des Schemas laufen. Ein Rollback ist ein Ausgleichs-Migrationsskript. Vor jeder Produktivmigration wird ein Backup erstellt. Down-Skripte werden bewusst nicht gebaut.

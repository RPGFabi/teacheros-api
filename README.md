# TeacherOS API

This backend delivers the core service layer for TeacherOS.

## Goals

- authenticated access and role checks
- school-year and class data management
- domain services for observation, assessment, planning, and communication
- clear separation between core and module APIs

## Local startup

```bash
composer install
php -S 127.0.0.1:8000 public/index.php
```

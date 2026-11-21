<?php

declare(strict_types=1);

namespace TeacherOS\Tests\Support;

/**
 * Startet pro Request einen php-cgi gegen public/index.php und migriert
 * eine eigene Testdatenbank (teacheros_test_<zufall>) ueber den Setup-Runner.
 * Konfiguration per Umgebung: TEST_DB_HOST/PORT/USER/PASSWORD, TEST_PHP_CGI.
 */
final class HttpTestApp
{
    private static ?self $instance = null;

    private function __construct(
        public readonly string $dbName,
        private readonly string $root,
    ) {
    }

    public static function boot(): self
    {
        if (self::$instance === null) {
            $app = new self('teacheros_test_' . bin2hex(random_bytes(4)), dirname(__DIR__, 2));
            self::$instance = $app;
            $app->migrate();
            register_shutdown_function(static fn () => $app->dropDatabase());
        }
        return self::$instance;
    }

    /** @return array{status:int, body:string, json:?array} */
    public function request(string $method, string $uri, array $env = [], ?array $json = null): array
    {
        return $this->requestConcurrently([[$method, $uri, $env, $json]])[0];
    }

    /**
     * Startet alle Requests gleichzeitig (je ein eigener php-cgi-Prozess) und wartet auf alle Antworten.
     * Fuer Nebenlaeufigkeitstests; die Antworten kommen in der Reihenfolge der Aufrufe.
     *
     * @param list<array{0:string,1:string,2?:array<string,string>,3?:?array}> $calls je [Methode, URI, Umgebung, JSON]
     * @return list<array{status:int, body:string, json:?array}>
     */
    public function requestConcurrently(array $calls): array
    {
        $running = array_map(fn (array $call): array => $this->startRequest(...$call), $calls);
        return array_map($this->readResponse(...), $running);
    }

    /**
     * Startet einen Request, wartet, bis er mit einer SQL-Anweisung an einer Sperre haengt (Text der Anweisung beginnt
     * mit $statementPrefix), ruft dann $release auf (z. B. Commit der Transaktion des Tests, die die Sperre haelt) und
     * liefert die Antwort. Damit laesst sich ein Rennen zwischen Pruefung und Schreiben ohne Zufall nachstellen.
     *
     * @param array<string,string> $env
     * @return array{status:int, body:string, json:?array}
     */
    public function requestReleasedWhenBlocked(string $statementPrefix, callable $release, string $method, string $uri, array $env = [], ?array $json = null): array
    {
        $process = $this->startRequest($method, $uri, $env, $json);
        $stmt = $this->pdo()->prepare('SELECT 1 FROM `information_schema`.`PROCESSLIST` WHERE `DB` = ? AND `INFO` LIKE ? AND `COMMAND` = \'Query\'');
        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            $stmt->execute([$this->dbName, $statementPrefix . '%']);
            if ($stmt->fetchColumn() !== false) {
                break;
            }
            usleep(50_000);
        }
        $release();
        return $this->readResponse($process);
    }

    /** @return array{resource, array<int,resource>} */
    private function startRequest(string $method, string $uri, array $env = [], ?array $json = null): array
    {
        $cgi = getenv('TEST_PHP_CGI') ?: dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php-cgi.exe' : 'php-cgi');
        $body = $json === null ? '' : json_encode($json, JSON_THROW_ON_ERROR);
        $env = array_merge($this->env(), [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
            'QUERY_STRING' => (string) parse_url($uri, PHP_URL_QUERY),
            'SCRIPT_FILENAME' => $this->root . '/public/index.php',
            'REDIRECT_STATUS' => '200',
            'GATEWAY_INTERFACE' => 'CGI/1.1',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
        ], $body === '' ? [] : [
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => (string) strlen($body),
        ], $env);
        return $this->start([$cgi, '-q'], $env, $body);
    }

    /**
     * @param array{resource, array<int,resource>} $process
     * @return array{status:int, body:string, json:?array}
     */
    private function readResponse(array $process): array
    {
        // Nur stdout ist die HTTP-Antwort; Warnungen von php-cgi auf stderr (z. B. JIT neben Xdebug) duerfen sie nicht verfaelschen.
        [$p, $pipes] = $process;
        $out = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        proc_close($p);
        [$head, $body] = array_pad(preg_split("/\r?\n\r?\n/", $out, 2), 2, '');
        $status = 200;
        if (preg_match('/^Status:\s*(\d{3})/mi', $head, $m)) {
            $status = (int) $m[1];
        }
        $json = json_decode($body, true);
        return ['status' => $status, 'body' => $body, 'json' => is_array($json) ? $json : null];
    }

    public function migrate(): void
    {
        [$code, $out] = $this->run([PHP_BINARY, $this->root . '/setup/run.php', '--mode=migrate'], $this->env());
        if ($code !== 0) {
            throw new \RuntimeException("Migration der Testdatenbank fehlgeschlagen:\n$out");
        }
        // Tabelle der Test-Verknuepfung von Klassen (tests/Support/module-probe-routes.php); kein Teil des Schemas.
        $this->pdo()->exec('CREATE TABLE IF NOT EXISTS `test_class_links` (`class_id` char(36) NOT NULL PRIMARY KEY)');
        // Ebenso fuer die Test-Pruefung der Schuljahre: welche Aktion (update/delete) an welchem Schuljahr gesperrt ist.
        $this->pdo()->exec('CREATE TABLE IF NOT EXISTS `test_school_year_blocks` (`school_year_id` char(36) NOT NULL, `action` varchar(10) NOT NULL, PRIMARY KEY (`school_year_id`, `action`))');
    }

    private function dropDatabase(): void
    {
        try {
            $pdo = $this->serverPdo();
            $pdo->exec('DROP DATABASE IF EXISTS `' . $this->dbName . '`');
        } catch (\Throwable) {
        }
    }

    private function serverPdo(): \PDO
    {
        $e = $this->env();
        return new \PDO("mysql:host={$e['DB_HOST']};port={$e['DB_PORT']}", $e['DB_USER'], $e['DB_PASSWORD'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    /** @return array<string,string> */
    public function env(): array
    {
        return [
            'APP_ENV' => 'test',
            'LOG_LEVEL' => 'off',
            'CORS_ENABLED' => '0',
            'DB_HOST' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'DB_PORT' => getenv('TEST_DB_PORT') ?: '3306',
            'DB_USER' => getenv('TEST_DB_USER') ?: 'teacheros',
            'DB_PASSWORD' => getenv('TEST_DB_PASSWORD') ?: 'teacheros',
            'DB_NAME' => $this->dbName,
            'AUTH_PUBLIC_KEY_PATH' => TestTokens::publicKeyPath(),
            'AUTH_TOKEN_ISSUER' => TestTokens::ISSUER,
            'AUTH_TOKEN_AUDIENCE' => TestTokens::AUDIENCE,
            'TEST_ROUTES' => $this->root . '/tests/Support/module-probe-routes.php',
        ];
    }

    public function pdo(): \PDO
    {
        $e = $this->env();
        return new \PDO("mysql:host={$e['DB_HOST']};port={$e['DB_PORT']};dbname={$this->dbName};charset=utf8mb4", $e['DB_USER'], $e['DB_PASSWORD'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    public function resetAccounts(): void
    {
        $this->pdo()->exec('DELETE FROM `permission_user_roles`');
        $this->pdo()->exec('DELETE FROM `permission_user_overrides`');
        $this->pdo()->exec('DELETE FROM `accounts`');
        $this->pdo()->exec('DELETE FROM `audit_log`');
    }

    /** Entfernt alle gespeicherten Schuleinstellungen (Testaufbau): danach gelten die Standardwerte. */
    public function resetSchoolSettings(): void
    {
        $this->pdo()->exec('DELETE FROM `school_settings`');
    }

    /** @return list<array<string,mixed>> Audit-Eintraege `school_setting.updated` in Reihenfolge */
    public function schoolSettingAudit(): array
    {
        return $this->pdo()->query("SELECT * FROM `audit_log` WHERE `action` = 'school_setting.updated' ORDER BY `id`")->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Entfernt alle Klassen (Testaufbau). */
    public function resetClasses(): void
    {
        $this->pdo()->exec('DELETE FROM `test_class_links`');
        $this->pdo()->exec('DELETE FROM `classes`');
    }

    /** Verknuepft eine Klasse mit der Test-Verknuepfung (stellvertretend fuer ein spaeteres Modul wie #18/#19). */
    public function linkClass(string $classId): void
    {
        $this->pdo()->prepare('INSERT INTO `test_class_links` (`class_id`) VALUES (?)')->execute([$classId]);
    }

    /** @return list<array<string,mixed>> nach Anlegezeitpunkt */
    public function classes(): array
    {
        return $this->pdo()->query('SELECT * FROM `classes` ORDER BY `created_at`, `id`')->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Entfernt alle Schuljahre samt ihrer Klassen und Sperren (Testaufbau). */
    public function resetSchoolYears(): void
    {
        $this->resetClasses();
        $this->pdo()->exec('DELETE FROM `test_school_year_blocks`');
        $this->pdo()->exec('DELETE FROM `school_years`');
    }

    /** Laesst die Test-Pruefung der Schuljahre die Aktion `update` oder `delete` an diesem Schuljahr ablehnen (Einwand eines anderen Moduls). */
    public function blockSchoolYear(string $schoolYearId, string $action): void
    {
        $this->pdo()->prepare('INSERT INTO `test_school_year_blocks` (`school_year_id`, `action`) VALUES (?, ?)')->execute([$schoolYearId, $action]);
    }

    /** @return list<array<string,mixed>> nach Beginn */
    public function schoolYears(): array
    {
        return $this->pdo()->query('SELECT * FROM `school_years` ORDER BY `starts_on`')->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Entfernt alle Modulfreigaben (Testaufbau): danach ist nur `core` aktiv. */
    public function resetModules(): void
    {
        $this->pdo()->exec('DELETE FROM `module_entitlements`');
    }

    /** Schreibt eine Freigabe-Version direkt in die DB (Testaufbau, z. B. mit Beginn in der Vergangenheit). */
    public function insertEntitlement(string $moduleId, bool $enabled, string $validFromUtc): void
    {
        $this->pdo()->prepare('INSERT INTO `module_entitlements` (`module_id`, `enabled`, `valid_from`) VALUES (?, ?, ?)')
            ->execute([$moduleId, $enabled ? 1 : 0, $validFromUtc]);
    }

    /** @return list<array<string,mixed>> */
    public function moduleEntitlements(): array
    {
        return $this->pdo()->query('SELECT * FROM `module_entitlements` ORDER BY `id`')->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Vergibt eine vorhandene Rolle direkt in der DB (Testaufbau, nicht der fachliche Weg). */
    public function assignRole(string $publicUserId, string $roleKey): void
    {
        $this->pdo()->prepare('INSERT INTO `permission_user_roles` (`user_uuid`, `role_id`) SELECT ?, `id` FROM `permission_roles` WHERE `role_key` = ?')->execute([$publicUserId, $roleKey]);
    }

    /** Gibt einem Konto ein einzelnes Recht direkt (Testaufbau, nicht der fachliche Weg). */
    public function grantPermission(string $publicUserId, string $permission): void
    {
        $this->pdo()->prepare("INSERT INTO `permission_user_overrides` (`user_uuid`, `permission`, `mode`) VALUES (?, ?, 'added')")->execute([$publicUserId, $permission]);
    }

    /** Setzt die Migrationen dieser Rechte-Grundlage zurueck und fuehrt sie erneut aus (Wiederholbarkeit). */
    public function rerunMigrations(string ...$files): void
    {
        $stmt = $this->pdo()->prepare('DELETE FROM `_setup_migrations` WHERE `filename` = ?');
        foreach ($files as $file) {
            $stmt->execute([$file]);
        }
        $this->migrate();
    }

    public function createAccount(string $publicUserId, string $status = 'active', ?string $email = null, ?string $loginName = null): void
    {
        $this->pdo()->prepare('INSERT INTO `accounts` (`public_user_id`, `status`, `email`, `login_name`) VALUES (?, ?, ?, ?)')
            ->execute([$publicUserId, $status, $email, $loginName ?? $email ?? $publicUserId]);
    }

    /**
     * Die offenen Aufrufe eines Kontos als [Aufruf => Ablehnungscode] ("Abgleich offen", Ticket #47), in Eintragsreihenfolge.
     *
     * @return array<string,?string>
     */
    public function openCalls(string $publicUserId): array
    {
        $stmt = $this->pdo()->prepare('SELECT `operation`, `rejection_code` FROM `account_open_calls` WHERE `account_id` = ? ORDER BY `id`');
        $stmt->execute([$publicUserId]);
        return $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
    }

    /** Tragt einen offenen Aufruf direkt ein (Testaufbau, nicht der fachliche Weg). */
    public function addOpenCall(string $publicUserId, string $operation, ?string $rejectionCode = null): void
    {
        $this->pdo()->prepare('INSERT INTO `account_open_calls` (`account_id`, `operation`, `rejection_code`) VALUES (?, ?, ?)')
            ->execute([$publicUserId, $operation, $rejectionCode]);
    }

    /** @return list<array<string,mixed>> */
    public function accounts(): array
    {
        return $this->pdo()->query('SELECT * FROM `accounts` ORDER BY `id`')->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function auditLog(): array
    {
        return $this->pdo()->query('SELECT * FROM `audit_log` ORDER BY `id`')->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Fuehrt ein CLI-Skript der API (z. B. bin/invite-first-admin.php) als Unterprozess
     * gegen die Test-DB aus; $env ergaenzt/ueberschreibt die Umgebung (z. B. AuthStub::env()).
     *
     * @param list<string> $args
     * @return array{code:int, out:string, err:string}
     */
    public function cli(string $script, array $args = [], array $env = []): array
    {
        $full = array_merge(getenv(), $this->env(), $env);
        $p = proc_open([PHP_BINARY, $this->root . '/' . $script, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root, $full);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        return ['code' => proc_close($p), 'out' => $out, 'err' => $err];
    }

    /**
     * Rueckt die Sitzungsgrenze eines Kontos in die Vergangenheit. So kann ein Test-Token entstehen, das nach der Grenze, aber
     * vor "jetzt" ausgestellt wurde (wie ein Token einer neuen Anmeldung, bevor der erste Request die Grenze beim Nachholen
     * auf "jetzt" setzt); ein Token aus der Zukunft der Datenbankuhr gibt es im Betrieb nicht.
     */
    public function ageSessionBoundary(string $publicUserId, int $seconds = 10): void
    {
        $this->pdo()->prepare('UPDATE `accounts` SET `sessions_valid_after` = `sessions_valid_after` - INTERVAL ? SECOND WHERE `public_user_id` = ?')->execute([$seconds, $publicUserId]);
    }

    public function setAccountStatus(string $publicUserId, string $status): void
    {
        $this->pdo()->prepare('UPDATE `accounts` SET `status` = ? WHERE `public_user_id` = ?')->execute([$status, $publicUserId]);
    }

    /** @return array{int,string} */
    private function run(array $cmd, array $env, string $stdin = ''): array
    {
        return $this->finish($this->start($cmd, $env, $stdin));
    }

    /** @return array{resource, array<int,resource>} */
    private function start(array $cmd, array $env, string $stdin): array
    {
        $full = array_merge(getenv(), $env);
        $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root, $full);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        return [$p, $pipes];
    }

    /**
     * @param array{resource, array<int,resource>} $process
     * @return array{int,string}
     */
    private function finish(array $process): array
    {
        [$p, $pipes] = $process;
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        return [proc_close($p), $out];
    }
}

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
    public function request(string $method, string $uri, array $env = []): array
    {
        $cgi = getenv('TEST_PHP_CGI') ?: dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php-cgi.exe' : 'php-cgi');
        $env = array_merge($this->env(), [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
            'SCRIPT_FILENAME' => $this->root . '/public/index.php',
            'REDIRECT_STATUS' => '200',
            'GATEWAY_INTERFACE' => 'CGI/1.1',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
        ], $env);
        [$code, $out] = $this->run([$cgi, '-q'], $env);
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
        ];
    }

    public function pdo(): \PDO
    {
        $e = $this->env();
        return new \PDO("mysql:host={$e['DB_HOST']};port={$e['DB_PORT']};dbname={$this->dbName}", $e['DB_USER'], $e['DB_PASSWORD'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    public function resetAccounts(): void
    {
        $this->pdo()->exec('DELETE FROM `permission_user_roles`');
        $this->pdo()->exec('DELETE FROM `permission_user_overrides`');
        $this->pdo()->exec('DELETE FROM `accounts`');
        $this->pdo()->exec('DELETE FROM `audit_log`');
    }

    /** Vergibt eine vorhandene Rolle direkt in der DB (Testaufbau, nicht der fachliche Weg). */
    public function assignRole(string $publicUserId, string $roleKey): void
    {
        $this->pdo()->prepare('INSERT INTO `permission_user_roles` (`user_uuid`, `role_id`) SELECT ?, `id` FROM `permission_roles` WHERE `role_key` = ?')->execute([$publicUserId, $roleKey]);
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

    public function setAccountStatus(string $publicUserId, string $status): void
    {
        $this->pdo()->prepare('UPDATE `accounts` SET `status` = ? WHERE `public_user_id` = ?')->execute([$status, $publicUserId]);
    }

    /** @return array{int,string} */
    private function run(array $cmd, array $env): array
    {
        $full = array_merge(getenv(), $env);
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root, $full);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        return [proc_close($p), $out];
    }
}

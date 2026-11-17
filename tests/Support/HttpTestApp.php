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
        ];
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

<?php

declare(strict_types=1);

namespace TeacherOS\Tests\Support;

/**
 * Auth-Stub an der Netzwerkgrenze: startet {@see AuthStub/router.php} per `php -S`
 * auf einem freien Port. Die API erreicht ihn ueber AUTH_SERVICE_URL, genau wie den
 * echten Anmeldedienst.
 *
 *   $stub = AuthStub::start();
 *   $stub->script('invite', ['status' => 500]);   // naechste Antwort auf /internal/users/invite
 *   $stub->script('invite', ['delay' => 2.0]);    // antwortet erst nach 2 s (Timeout-Test; API-Timeout im Test: 0,5 s)
 *   $stub->script('invite', ['status' => 200, 'raw' => '<html>']);  // Antwort, die kein JSON ist
 *   $stub->script('invite', ['body' => ['ok' => true, 'public_user_id' => '...', 'created' => true, 'already_active' => true]]);
 *   $stub->calls();                               // aufgezeichnete Aufrufe inkl. signature_valid
 *   $app->cli(..., $stub->env());                 // AUTH_SERVICE_URL + Secret fuer die API
 *
 * Ohne Skript verhaelt er sich wie der echte Dienst (neu -> created, bekannt -> erneut eingeladen).
 */
final class AuthStub
{
    public const SECRET = 'stub-webhook-secret';

    /** @var resource */
    private $process;

    /** @param resource $process */
    private function __construct($process, public readonly int $port, private readonly string $stateFile)
    {
        $this->process = $process;
    }

    public static function start(): self
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $err);
        if ($socket === false) {
            throw new \RuntimeException("Kein freier Port: $err");
        }
        $port = (int) explode(':', (string) stream_socket_get_name($socket, false))[1];
        fclose($socket);

        $state = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'teacheros_auth_stub_' . bin2hex(random_bytes(4)) . '.json';
        $env = array_merge(getenv(), ['AUTH_STUB_STATE' => $state, 'AUTH_STUB_SECRET' => self::SECRET]);
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', __DIR__ . '/AuthStub', __DIR__ . '/AuthStub/router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes,
            null,
            $env,
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Auth-Stub konnte nicht gestartet werden');
        }
        $stub = new self($process, $port, $state);
        for ($i = 0; $i < 50; $i++) {
            $probe = @fsockopen('127.0.0.1', $port, $errno, $err, 0.2);
            if ($probe !== false) {
                fclose($probe);
                return $stub;
            }
            usleep(100_000);
        }
        $stub->stop();
        throw new \RuntimeException('Auth-Stub antwortet nicht');
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        @unlink($this->stateFile);
    }

    public function url(): string
    {
        return "http://127.0.0.1:{$this->port}";
    }

    /** @return array<string,string> Umgebung, mit der die API den Stub als Anmeldedienst nutzt. */
    public function env(): array
    {
        return [
            'AUTH_SERVICE_URL' => $this->url(),
            'AUTH_SERVICE_WEBHOOK_SECRET' => self::SECRET,
            'AUTH_SERVICE_TIMEOUT_SECONDS' => '0.5',
        ];
    }

    /** Haengt die naechste Antwort fuer `invite`, `delete`, `disable-login` oder `enable-login` an ({status, body, delay}); je Aufruf wird eine verbraucht. */
    public function script(string $endpoint, array $response): void
    {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode([$endpoint => [$response]]),
        ]]);
        file_get_contents($this->url() . '/__stub/script', false, $ctx);
    }

    /** @return list<array{path:string, body:?array, signature_valid:bool, content_type:string}> */
    public function calls(): array
    {
        return json_decode((string) file_get_contents($this->url() . '/__stub/calls'), true)['calls'];
    }

    /** @return list<array{path:string, body:?array, signature_valid:bool, content_type:string}> */
    public function inviteCalls(): array
    {
        return $this->callsTo('/internal/users/invite');
    }

    /** @return list<array{path:string, body:?array, signature_valid:bool, content_type:string}> */
    public function disableLoginCalls(): array
    {
        return $this->callsTo('/internal/users/disable-login');
    }

    /** @return list<array{path:string, body:?array, signature_valid:bool, content_type:string}> */
    public function enableLoginCalls(): array
    {
        return $this->callsTo('/internal/users/enable-login');
    }

    /** @return list<array{path:string, body:?array, signature_valid:bool, content_type:string}> */
    private function callsTo(string $path): array
    {
        return array_values(array_filter($this->calls(), static fn ($c) => $c['path'] === $path));
    }
}

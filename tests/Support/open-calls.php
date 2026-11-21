<?php

declare(strict_types=1);

// Test-Hilfsskript (Ticket #47): holt die offenen Aufrufe eines Kontos mit eigenen Handlern nach. Der Widerruf gibt es seit #48, die Zuruecksetzung
// noch nicht; so lassen sich Menge, feste Reihenfolge und Ablehnung belegen, wie sie dann gelten.
//   php tests/Support/open-calls.php <public_user_id> '<json>' [release]
// <json> ist die Handler-Liste in der Reihenfolge des Nachholens: [{"op":"a","outcome":"ok"}, ...]; mit "blocking":true ist der Handler ein
// TokenBlockingCall. Ergebnis `outcome`: `ok`, `reject:<code>:<status>`, `unavailable` oder `obsolete`. Ohne `release` laeuft "Abgleich
// wiederholen" (`resync`), mit `release` die Selbstheilung der Middleware (`releaseTokens`).
// Ausgabe: {"calls":[...],"thrown":null|{"code":...,"status":...}}.

use Hoelzle\Core\Application;
use Hoelzle\Core\Config\Config;
use TeacherOS\Shared\AuthService\AuthServiceClient;
use TeacherOS\Shared\AuthService\AuthServiceUnavailableException;
use TeacherOS\Shared\Exceptions\CodedException;
use TeacherOS\Shared\Security\Account;
use TeacherOS\Shared\Security\AccountRepository;
use TeacherOS\Shared\Security\OpenCallHandler;
use TeacherOS\Shared\Security\OpenCalls;
use TeacherOS\Shared\Security\TokenBlockingCall;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

Application::initialize(dirname(__DIR__, 2));
Config::load();

class ScriptedHandler implements OpenCallHandler
{
    public function __construct(private readonly string $op, private readonly string $outcome, private readonly ArrayObject $calls)
    {
    }

    public function operation(): string
    {
        return $this->op;
    }

    public function isObsolete(Account $account): bool
    {
        return $this->outcome === 'obsolete';
    }

    public function execute(Account $account, AuthServiceClient $authService): void
    {
        $this->calls->append($this->op);
        if ($this->outcome === 'unavailable') {
            throw new AuthServiceUnavailableException('test');
        }
        if (str_starts_with($this->outcome, 'reject:')) {
            [, $code, $status] = explode(':', $this->outcome);
            throw new CodedException($code, (int) $status);
        }
    }
}

final class ScriptedBlockingHandler extends ScriptedHandler implements TokenBlockingCall
{
}

$calls = new ArrayObject();
$handlers = array_map(
    static fn (array $spec) => ($spec['blocking'] ?? false) ? new ScriptedBlockingHandler($spec['op'], $spec['outcome'], $calls) : new ScriptedHandler($spec['op'], $spec['outcome'], $calls),
    json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR),
);

$thrown = null;
try {
    $openCalls = new OpenCalls(new AuthServiceClient(), new AccountRepository(), $handlers);
    ($argv[3] ?? '') === 'release' ? $openCalls->releaseTokens($argv[1]) : $openCalls->resync($argv[1]);
} catch (CodedException $e) {
    $thrown = ['code' => $e->errorCode, 'status' => $e->getHttpCode()];
}
echo json_encode(['calls' => $calls->getArrayCopy(), 'thrown' => $thrown]) . "\n";

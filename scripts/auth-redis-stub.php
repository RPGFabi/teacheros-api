<?php
// Nur fuer die lokale Dev-Auth-Instanz ohne Redis (scripts/auth-instance.sh, AUTH_REDIS_MODE=fake).
// Wird per auto_prepend_file geladen und ersetzt die Redis-Verbindung des Rate Limitings durch
// einen Ersatz, der jeden Hit erlaubt. Keine Rate Limits: niemals in Produktion verwenden.

$root = getenv('AUTH_APP_ROOT') ?: getcwd();
require_once $root . '/vendor/autoload.php';

eval('namespace TeacherOSDev; final class AllowAllRedis extends \Predis\Client {
  public function __construct() {}
  public function eval(string $script, int $numKeys, string $key, int $max, int $windowSeconds): array { return [1, max(0, $max - 1), 0]; }
  public function __call($command, $arguments) { throw new \LogicException("Dev-Stub unterstuetzt $command nicht"); }
}');

\Closure::bind(static function (): void {
  \Hoelzle\RateLimit\Redis\RedisConnection::$instance = new \TeacherOSDev\AllowAllRedis();
}, null, \Hoelzle\RateLimit\Redis\RedisConnection::class)();

<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Strips any log context entry whose key names a token, a Join_Code or a secret
 * (Req 10.5), as the second line of defence behind `GameEventLogger`'s typed
 * arguments.
 *
 * The key is compared, never the value: a value-based scan would need to know what
 * a Player_Token looks like, and a 64-character hex string is indistinguishable
 * from a SHA-256 hash that is safe to log. Matching is on the key normalised to
 * lower-case alphanumerics — so `token`, `Player_Token`, `x-token`, `joinCode`,
 * `join_code` and `apiSecret` all match — and by substring rather than equality,
 * because the leaks worth catching are prefixed and suffixed variants nobody
 * thought to enumerate.
 *
 * Entries are removed rather than replaced with a placeholder, so a redacted record
 * carries no trace of the key at all; nested arrays are walked, so an entry cannot
 * be smuggled one level down.
 *
 * Attached to the `game_events` channel in `config/logging.php`. It protects that
 * channel only, which is the whole of the log output Requirement 10.3 mandates. Do
 * not rely on it as the primary control: `GameEventLogger` never puts a secret in
 * a record, and this exists so that a future writer who does is caught rather than
 * trusted.
 */
final class RedactSecrets implements ProcessorInterface
{
    /**
     * Substrings of a normalised key that mean "do not log this". Task 10.2 names
     * these three; `joincode` is `join_code` after normalisation.
     *
     * @var list<string>
     */
    private const array FORBIDDEN = ['token', 'joincode', 'secret'];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(context: $this->stripped($record->context));
    }

    /**
     * `$context` with every forbidden key removed, at any depth.
     *
     * @param  array<array-key, mixed>  $context
     * @return array<array-key, mixed>
     */
    private function stripped(array $context): array
    {
        $kept = [];

        foreach ($context as $key => $value) {
            if (is_string($key) && $this->isForbidden($key)) {
                continue;
            }

            $kept[$key] = is_array($value) ? $this->stripped($value) : $value;
        }

        return $kept;
    }

    private function isForbidden(string $key): bool
    {
        $normalised = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $key));

        foreach (self::FORBIDDEN as $forbidden) {
            if (str_contains($normalised, $forbidden)) {
                return true;
            }
        }

        return false;
    }
}

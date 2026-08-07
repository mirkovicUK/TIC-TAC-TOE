<?php

declare(strict_types=1);

namespace App\Games;

/**
 * One Join_Code: ten Crockford base32 characters carrying 50 bits of entropy
 * (Req 1.3), in both of the two forms it is ever seen in.
 *
 * The two forms are not interchangeable. `stored` is the ten characters with no
 * hyphen — what `games.join_code` holds and what the unique index compares
 * (Req 1.4). `display()` is the same ten characters as `XXXXX-XXXXX`, the form a
 * player reads aloud and a Join_Link carries.
 *
 * The stored form must be the unhyphenated one because `JoinGame` strips hyphens
 * from a submitted code before looking it up: a normalised ten-character input
 * could never match an eleven-character stored value. Store the hyphen and the
 * join path breaks for every code.
 *
 * Both directions live here so the group size, separator and folding table
 * cannot be changed on one side only — the failure mode is codes that are
 * displayed but can never be joined.
 *
 * This class performs no lookup. `parse()` answering null means "not a well
 * formed Join_Code", not "no Game has this Join_Code"; `JoinGame` collapses both
 * to `not_recognised` (Req 2.2).
 */
final readonly class JoinCode
{
    /**
     * Crockford's base32 symbol set: ten digits and twenty-two letters,
     * excluding I, L and O (misread as 1, 1 and 0) and U (obscenity).
     *
     * The order is Crockford's and is load-bearing for `parse()`: folding I and L
     * to 1 and O to 0 is only correct because the symbol at each of those
     * positions is the digit they are misread as.
     */
    public const string ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** Ten symbols × 5 bits = 50 bits, above Requirement 1.3's 48-bit floor. */
    public const int LENGTH = 10;

    /** Where `display()` puts its separator: `XXXXX-XXXXX`. */
    private const int GROUP = 5;

    /**
     * Private, so every instance came through `generate()` or `parse()` and holds
     * exactly `LENGTH` symbols drawn from `ALPHABET`. That is what lets
     * `display()` slice without checking and lets a caller pass `$code->stored`
     * to a query without revalidating it.
     */
    private function __construct(
        /**
         * The ten characters as `games.join_code` holds them: upper case, no
         * hyphen, no ambiguous symbol.
         */
        public string $stored,
    ) {}

    /**
     * A fresh Join_Code: 50 bits from `random_bytes()`, rendered as ten
     * Crockford symbols (Req 1.3).
     *
     * Seven bytes are 56 uniform bits; ten disjoint 5-bit fields are cut from
     * them, so each symbol is uniform over all 32 independently of the others.
     * The six unused low bits are discarded rather than folded back in, which
     * would correlate two symbols for no gain.
     *
     * Do not rewrite this as one byte per symbol reduced `% 32`. That form is
     * unbiased only because 256 = 8 × 32; the same line with `% 26` or `% 62` is
     * biased, and it spends eight bits to produce five. Bit extraction needs no
     * divisibility argument.
     *
     * `random_bytes()` is the CSPRNG Requirement 1.3 asks for. `Str::random()`,
     * `mt_rand()`, `rand()` and `shuffle()` are not, and may not be substituted.
     */
    public static function generate(): self
    {
        $bytes = random_bytes(7);

        $bits = 0;

        for ($index = 0; $index < 7; $index++) {
            $bits = ($bits << 8) | ord($bytes[$index]);
        }

        $code = '';

        // The high 50 of the 56 bits, five at a time: shifts 51, 46, ... 6.
        for ($position = 0; $position < self::LENGTH; $position++) {
            $code .= self::ALPHABET[($bits >> (51 - 5 * $position)) & 0b11111];
        }

        return new self($code);
    }

    /**
     * `$candidate` as a Join_Code, or null if it cannot be one.
     *
     * The inverse of `display()`, and the normalisation `JoinGame` applies before
     * it looks a submitted code up: trimmed, upper-cased, hyphens stripped
     * wherever they fall, and Crockford's folding applied — I and L to `1`, O to
     * `0`. So `4K7PZ-9QZR3`, `4k7pz9qzr3` and a code read as `l` where `1` was
     * written are all the same Join_Code.
     *
     * U is not folded: Crockford excludes it from the symbol set rather than
     * treating it as a variant, so a code containing U answers null. `generate()`
     * can never emit one.
     *
     * A value read back out of `games.join_code` is already normalised, so every
     * step below leaves it untouched and it parses to itself. One parser serves
     * both callers rather than a lenient one for input and a trusting one for
     * storage that could disagree about what a valid code is.
     */
    public static function parse(string $candidate): ?self
    {
        $folded = strtr(strtoupper(trim($candidate)), [
            '-' => '',
            'I' => '1',
            'L' => '1',
            'O' => '0',
        ]);

        if (strlen($folded) !== self::LENGTH) {
            return null;
        }

        // `strspn` counts the leading run drawn from the alphabet, so a full
        // count means every character is a legal symbol. It reads `ALPHABET`
        // directly and so cannot disagree with it, unlike a regex.
        if (strspn($folded, self::ALPHABET) !== self::LENGTH) {
            return null;
        }

        return new self($folded);
    }

    /**
     * The form a player sees: `XXXXX-XXXXX`.
     *
     * The hyphen is presentation only — `parse()` strips it — and is never
     * stored; see the class docblock for why the column holds the unhyphenated
     * form.
     */
    public function display(): string
    {
        return substr($this->stored, 0, self::GROUP).'-'.substr($this->stored, self::GROUP);
    }
}

<?php

declare(strict_types=1);

namespace App\Games;

/**
 * One Join_Code: ten Crockford base32 characters carrying 50 bits of entropy
 * (Req 1.3), in both of the two forms it is ever seen in.
 *
 * THE TWO FORMS ARE NOT INTERCHANGEABLE, AND THAT IS WHY THIS TYPE EXISTS.
 *
 *   - `stored` is the ten characters with NO hyphen. It is what
 *     `games.join_code` holds and what the unique index compares (Req 1.4).
 *   - `display()` is the same ten characters as `XXXXX-XXXXX`. It is what the
 *     design's `props.game.joinCode` carries, what a Join_Link contains, and
 *     what a player reads aloud.
 *
 * The stored form must be the unhyphenated one, and that follows from
 * `JoinGame`'s side of the contract rather than from taste: task 5.4 normalises
 * a submitted code by stripping hyphens before it looks the code up, so a
 * normalised ten-character input could never match an eleven-character stored
 * value. Store the hyphen and the join path stops working for every code.
 *
 * BOTH DIRECTIONS LIVE HERE ON PURPOSE. `GameRepresentation` (task 5.5) needs
 * stored → display; `JoinGame` (task 5.4) needs submitted → stored. They are
 * inverses, and inverses implemented in two files drift: a change to the group
 * size, the separator or the folding table would have to be made twice, and the
 * failure mode of getting it wrong is that codes are generated and displayed
 * that can never be joined. `parse()` and `display()` sit ten lines apart so
 * that they cannot disagree unnoticed.
 *
 * WHAT THIS CLASS DOES NOT DO. It performs no lookup and knows nothing about
 * `games`. `parse()` answering null means "this string is not a well formed
 * Join_Code", which is a different fact from "no Game has this Join_Code";
 * `JoinGame` reports both as `not_recognised` (Req 2.2), and that collapsing is
 * its decision to make, not this type's.
 */
final readonly class JoinCode
{
    /**
     * Crockford's base32 symbol set, verified against his specification: ten
     * digits and twenty-two letters, EXCLUDING I, L, O and U. I, L and O are
     * excluded because they are misread as 1, 1 and 0; U is excluded to avoid
     * accidental obscenity.
     *
     * The exclusions are the entire reason this alphabet is used instead of hex
     * or base62 — a Join_Code is read off one screen and typed into another, or
     * spoken down a phone, so the characters that survive that trip are the
     * ones worth having. Thirty-two symbols is also exactly five bits, which is
     * what makes ten characters exactly 50 bits with no remainder to explain.
     *
     * The ORDER is Crockford's too, and it is load-bearing for `parse()`: the
     * folding of I and L to 1 and of O to 0 is only correct because the symbol
     * at each of those positions is the digit they are misread as.
     */
    public const string ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** Ten symbols × 5 bits = 50 bits, above Requirement 1.3's 48-bit floor. */
    public const int LENGTH = 10;

    /** Where `display()` puts its separator: `XXXXX-XXXXX`. */
    private const int GROUP = 5;

    /**
     * Private, so that every instance in existence came through `generate()` or
     * `parse()` and therefore holds exactly `LENGTH` characters drawn from
     * `ALPHABET`. That invariant is what lets `display()` slice the string
     * without checking it, and what lets a caller pass `$code->stored` to a
     * query without validating it again.
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
     * THE CONSTRUCTION IS UNBIASED, and the argument is worth stating because
     * the shape of it is where this normally goes wrong. Seven bytes from
     * `random_bytes()` are 56 uniform, independent bits. Ten DISJOINT 5-bit
     * fields are cut from them, and a uniform independent bit string restricted
     * to any fixed set of positions is uniform over that set's values — so each
     * of the ten symbols is uniform over all 32, independently of the others.
     * That is 50 bits of entropy exactly, not approximately: the six unused low
     * bits are discarded rather than folded back in, because reusing them would
     * make two symbols correlated for no gain.
     *
     * ON `% 32`, WHICH IS THE MISTAKE THIS AVOIDS THE GENERAL FORM OF. Taking
     * one byte per symbol and reducing it modulo 32 is, in this specific case,
     * unbiased — 256 = 8 × 32, so every residue has exactly eight preimages and
     * the map is exactly eight-to-one. The reasoning matters more than the
     * verdict: the same line with `% 26`, `% 10` or `% 62` IS biased, because
     * 256 is not a multiple of those and the low residues get one extra
     * preimage each. It also costs eight bits per symbol to produce five, so it
     * would consume 80 bits of randomness to deliver 50. Bit extraction needs no
     * divisibility argument at all, which is why it is used here.
     *
     * `random_bytes()` is the CSPRNG Requirement 1.3 asks for. `Str::random()`
     * is not — it is base62 and not documented as cryptographically secure —
     * and `mt_rand()`, `rand()` and `shuffle()` are not either. None of them may
     * be substituted here.
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
     * THE INVERSE OF `display()`, and the normalisation task 5.4 performs before
     * it looks a submitted code up: surrounding whitespace trimmed, upper-cased,
     * hyphens stripped wherever they fall, and the ambiguous characters folded
     * the way Crockford's decoder folds them — I and L to `1`, O to `0`. A code
     * transcribed as `4K7PZ-9QZR3` and one typed as `4k7pz9qzr3` are the same
     * Join_Code, and one read off a screen as `l` when it was written `1` still
     * is.
     *
     * U IS NOT FOLDED, and there is nothing to fold it to: Crockford excludes it
     * from the symbol set outright rather than treating it as a variant of
     * another symbol, so a submitted code containing U is not a Join_Code and
     * answers null. `generate()` can never emit one.
     *
     * ALSO USED FOR THE STORED FORM, by `GameRepresentation` (task 5.5): a value
     * read back out of `games.join_code` is already upper case, hyphen-free and
     * free of ambiguous symbols, so every step below leaves it untouched and it
     * parses to itself. That is deliberate — one parser, used by the two callers
     * that need a `JoinCode` from a string, rather than a lenient one for input
     * and a trusting one for storage that could disagree about what a valid code
     * is.
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
        // count means every character is a legal symbol. Cheaper than a regex
        // and it cannot disagree with `ALPHABET`, since it reads it directly.
        if (strspn($folded, self::ALPHABET) !== self::LENGTH) {
            return null;
        }

        return new self($folded);
    }

    /**
     * The form a player sees: `XXXXX-XXXXX`.
     *
     * The hyphen is presentation and carries no information — `parse()` strips
     * it — so it exists purely to make ten characters readable in two glances
     * instead of one. It is never stored; see the class docblock for why the
     * column must hold the unhyphenated form.
     */
    public function display(): string
    {
        return substr($this->stored, 0, self::GROUP).'-'.substr($this->stored, self::GROUP);
    }
}

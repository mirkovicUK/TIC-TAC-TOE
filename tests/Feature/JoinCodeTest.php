<?php

declare(strict_types=1);

use App\Games\JoinCode;

// Feature: remote-tic-tac-toe
//
// Validates: Requirements 1.3, 1.4
//
/*
 * The Join_Code value object, separately from the row it lands on.
 *
 * A Feature test only because `tests/Pest.php` binds `Tests\TestCase` to `Feature` and
 * `tests/Unit` is reserved for the framework-free domain layer (Req 14.1, ADR-003).
 * Nothing here touches the database or the session.
 *
 * Requirement 1.3's cryptographically secure source is not observable from outside;
 * its consequences are: ten symbols from a 32-symbol alphabet, every position
 * exercising the whole alphabet, and no two codes alike. A generator changed to
 * `Str::random()`, `mt_rand()` or a subset of the alphabet fails one of these. That the
 * source is `random_bytes()` and the extraction unbiased is carried by `generate()`.
 */

/**
 * `$count` freshly generated codes in their stored form.
 *
 * @return list<string>
 */
function joinCodes(int $count): array
{
    $codes = [];

    for ($index = 0; $index < $count; $index++) {
        $codes[] = JoinCode::generate()->stored;
    }

    return $codes;
}

/*
 * Pinned as a literal rather than derived: every other assertion in this file reads
 * `JoinCode::ALPHABET`, so a wrong constant would pass them all. This is the one place
 * the expected value is written independently.
 *
 * I, L and O are misread as 1, 1 and 0, and U is excluded to avoid accidental
 * obscenity, so the four exclusions are asserted individually.
 */
it('uses Crockford base32: thirty-two symbols with no I, L, O or U', function () {
    expect(JoinCode::ALPHABET)->toBe('0123456789ABCDEFGHJKMNPQRSTVWXYZ')
        ->and(strlen(JoinCode::ALPHABET))->toBe(32, 'base32 needs exactly 32 symbols, or a symbol is not five bits')
        ->and(count(array_unique(str_split(JoinCode::ALPHABET))))->toBe(32, 'the alphabet repeats a symbol');

    foreach (['I', 'L', 'O', 'U'] as $excluded) {
        expect(str_contains(JoinCode::ALPHABET, $excluded))
            ->toBeFalse("Crockford base32 excludes {$excluded}");
    }

    // Ten symbols of five bits: 50 bits, above Req 1.3's 48-bit floor.
    expect(JoinCode::LENGTH * 5)->toBeGreaterThanOrEqual(48);
});

/*
 * Not implied by the alphabet test above: that asserts what the constant says, this
 * what `generate()` emits. A generator indexing a different alphabet satisfies the
 * first and fails this.
 */
it('generates ten Crockford symbols and never an excluded letter', function () {
    foreach (joinCodes(200) as $code) {
        expect(strlen($code))->toBe(JoinCode::LENGTH, "generated code {$code} is not ten characters")
            ->and(strspn($code, JoinCode::ALPHABET))->toBe(JoinCode::LENGTH, "generated code {$code} contains a symbol outside the alphabet");

        foreach (['I', 'L', 'O', 'U'] as $excluded) {
            expect(str_contains($code, $excluded))->toBeFalse("generated code {$code} contains {$excluded}");
        }
    }
});

/*
 * At 50 bits, 500 draws collide with probability around 500² / (2 × 2^50) ≈ 10^-10, so
 * a repeat is a broken generator rather than bad luck.
 */
it('generates a different code every time', function () {
    $codes = joinCodes(500);

    expect(count(array_unique($codes)))->toBe(count($codes), 'two generated Join_Codes were identical');
});

/*
 * The distribution check, which catches a generator that is nearly right: five bits
 * from the wrong offset, a mask of `& 0b1111` confining every position to the first
 * sixteen symbols, a byte reduced modulo something other than 32, or one draw reused
 * across positions. None of those fail the assertions above.
 *
 * Neither bound is flaky. Over 3,000 draws a symbol is absent from a given position
 * with probability (31/32)^3000 ≈ 10^-41; the 1.5% and 5% share bounds sit roughly
 * sixteen and eighteen standard deviations from the expected 1/32 = 3.125%.
 */
it('spreads every position across the whole alphabet', function () {
    $codes = joinCodes(3000);
    $symbols = str_split(JoinCode::ALPHABET);

    /** @var array<string, int> $overall */
    $overall = array_fill_keys($symbols, 0);

    for ($position = 0; $position < JoinCode::LENGTH; $position++) {
        /** @var array<string, int> $seen */
        $seen = array_fill_keys($symbols, 0);

        foreach ($codes as $code) {
            $seen[$code[$position]]++;
            $overall[$code[$position]]++;
        }

        $used = count(array_filter($seen, static fn (int $count): bool => $count > 0));

        expect($used)->toBe(
            32,
            "position {$position} used only {$used} of the 32 symbols across ".count($codes).' codes; the generator is confined to a subset',
        );
    }

    $draws = count($codes) * JoinCode::LENGTH;

    foreach ($overall as $symbol => $count) {
        $share = $count / $draws;

        expect($share)->toBeGreaterThan(0.015, "symbol {$symbol} took only ".round($share * 100, 3).'% of all draws; expected about 3.125%')
            ->and($share)->toBeLessThan(0.05, "symbol {$symbol} took ".round($share * 100, 3).'% of all draws; expected about 3.125%');
    }
});

/*
 * The stored form is ten characters because a submitted code is normalised with hyphens
 * stripped before lookup, so an eleven-character stored value could never match. The
 * displayed form carries the hyphen, which is what the design's wire example
 * (`{"join_code": "4K7P2-9QZR3"}`) and `props.game.joinCode` show.
 */
it('stores ten characters, displays them hyphenated, and round-trips between the two', function () {
    foreach (joinCodes(50) as $stored) {
        $code = JoinCode::parse($stored);

        expect($code)->not->toBeNull("the stored form {$stored} did not parse")
            ->and($code?->stored)->toBe($stored)
            ->and(strlen($stored))->toBe(10);

        $displayed = $code?->display() ?? '';

        expect($displayed)->toMatch('/^[0-9A-HJKMNP-TV-Z]{5}-[0-9A-HJKMNP-TV-Z]{5}$/', "the displayed form {$displayed} is not XXXXX-XXXXX")
            ->and(strlen($displayed))->toBe(11)
            ->and($displayed)->toBe(substr($stored, 0, 5).'-'.substr($stored, 5))
            ->and(JoinCode::parse($displayed)?->stored)->toBe($stored, 'the displayed form did not parse back to the stored form');
    }
});

/*
 * The folding is Crockford's decoder: I and L become 1, O becomes 0, case is irrelevant
 * and hyphens are insignificant wherever they fall. U is not folded — Crockford excludes
 * it from the symbol set rather than treating it as a variant — so a code containing U
 * is unparseable and a join reports `not_recognised` like any other unmatched code.
 */
it('normalises case, hyphens and the ambiguous characters, and rejects anything else', function () {
    expect(JoinCode::parse('4K7P2-9QZR3')?->stored)->toBe('4K7P29QZR3')
        ->and(JoinCode::parse('4k7p29qzr3')?->stored)->toBe('4K7P29QZR3', 'a lower-case code did not normalise')
        ->and(JoinCode::parse('  4K7P2-9QZR3  ')?->stored)->toBe('4K7P29QZR3', 'surrounding whitespace was not trimmed')
        ->and(JoinCode::parse('4-K-7-P-2-9-Q-Z-R-3')?->stored)->toBe('4K7P29QZR3', 'hyphens are insignificant wherever they fall')
        ->and(JoinCode::parse('IL0O123456')?->stored)->toBe('1100123456', 'I and L must fold to 1 and O to 0');

    expect(JoinCode::parse(''))->toBeNull()
        ->and(JoinCode::parse('4K7P29QZR'))->toBeNull('a nine-character code was accepted')
        ->and(JoinCode::parse('4K7P29QZR33'))->toBeNull('an eleven-character code was accepted')
        ->and(JoinCode::parse('4K7P29QZRU'))->toBeNull('U is not in Crockford base32 and must not be folded')
        ->and(JoinCode::parse('4K7P29QZR!'))->toBeNull('a punctuation character was accepted');
});

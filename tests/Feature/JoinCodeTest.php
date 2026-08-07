<?php

declare(strict_types=1);

use App\Games\JoinCode;

// Feature: remote-tic-tac-toe
//
// Validates: Requirements 1.3, 1.4
//
/*
 * Task 5.2 — the Join_Code itself, separately from the row it lands on.
 *
 * A Feature test only because `tests/Pest.php` binds `Tests\TestCase` to
 * `Feature` and `tests/Unit` is reserved for the framework-free domain layer
 * (Req 14.1, ADR-003). Nothing here touches the database or the session:
 * `JoinCode` is a value object, and no `RefreshDatabase` is applied.
 *
 * WHAT THIS FILE IS FOR. Requirement 1.3 asks for a cryptographically secure
 * source and at least 48 bits of entropy, and neither of those is directly
 * observable from outside. What *is* observable is the consequence: ten symbols
 * drawn from a 32-symbol alphabet, every position exercising the whole alphabet,
 * and no two codes alike. A generator that had been quietly changed to
 * `Str::random()`, to `mt_rand()`, or to a subset of the alphabet would fail one
 * of the assertions below. The claim that the source is `random_bytes()` and that
 * the extraction is unbiased is carried by reading `generate()`, and the argument
 * is written out there.
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
 * The alphabet is Crockford's, character for character.
 *
 * Pinned as a literal rather than derived, because every other assertion in this
 * file reads `JoinCode::ALPHABET` — so if the constant were wrong, they would all
 * agree with it and pass. This is the one place the expected value is written
 * independently.
 *
 * The four exclusions are asserted individually, since they are the whole reason
 * for choosing this alphabet: I, L and O are misread as 1, 1 and 0, and U is
 * excluded to avoid accidental obscenity.
 */
it('uses Crockford base32: thirty-two symbols with no I, L, O or U', function () {
    expect(JoinCode::ALPHABET)->toBe('0123456789ABCDEFGHJKMNPQRSTVWXYZ')
        ->and(strlen(JoinCode::ALPHABET))->toBe(32, 'base32 needs exactly 32 symbols, or a symbol is not five bits')
        ->and(count(array_unique(str_split(JoinCode::ALPHABET))))->toBe(32, 'the alphabet repeats a symbol');

    foreach (['I', 'L', 'O', 'U'] as $excluded) {
        expect(str_contains(JoinCode::ALPHABET, $excluded))
            ->toBeFalse("Crockford base32 excludes {$excluded}");
    }

    // Ten symbols of five bits each. This is the entropy claim of Requirement
    // 1.3, restated as arithmetic: 50 bits, above the 48-bit floor.
    expect(JoinCode::LENGTH * 5)->toBeGreaterThanOrEqual(48);
});

/*
 * A generated code is ten legal symbols, and contains none of the four excluded
 * letters.
 *
 * The exclusion check is not implied by the alphabet check above: that one
 * asserts what the constant says, this one asserts what `generate()` actually
 * emits. A generator indexing a different alphabet would satisfy the first and
 * fail this.
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
 * Distinct codes. At 50 bits, 500 draws collide with probability around
 * 500² / (2 × 2^50) ≈ 10^-10, so a repeat here is a broken generator rather than
 * bad luck.
 *
 * This is also the assertion that would fail on the crudest possible mistake — a
 * constant Join_Code — which the per-position spread test below covers more
 * thoroughly but more slowly.
 */
it('generates a different code every time', function () {
    $codes = joinCodes(500);

    expect(count(array_unique($codes)))->toBe(count($codes), 'two generated Join_Codes were identical');
});

/*
 * THE DISTRIBUTION SANITY CHECK. Every one of the ten positions must exercise the
 * whole alphabet, and no symbol may dominate.
 *
 * This is what catches a generator that is *nearly* right: five bits taken from
 * the wrong offset, a mask of `& 0b1111` instead of `& 0b11111` (which would
 * confine every position to the first sixteen symbols), a byte reduced modulo a
 * number that is not 32, or a loop that reuses one draw for several positions.
 * None of those would fail the length or alphabet assertions above.
 *
 * The floor is "all 32 symbols present at every position", which is safe rather
 * than flaky: over 3,000 draws a particular symbol is absent from a particular
 * position with probability (31/32)^3000, which is about 10^-41.
 *
 * The share bounds are the other half. Each symbol should take 1/32 = 3.125% of
 * the 30,000 draws; 1.5% and 5% are roughly sixteen and eighteen standard
 * deviations out, so they are unreachable by chance and yet close enough to catch
 * a generator weighted towards a subset.
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
 * STORED VERSUS DISPLAYED, AND THE ROUND TRIP BETWEEN THEM.
 *
 * The stored form is the ten characters, because task 5.4 strips hyphens from a
 * submitted code before looking it up — an eleven-character stored value could
 * never be matched by a normalised ten-character input. The displayed form
 * carries the hyphen, because that is what the design's wire example
 * (`{"join_code": "4K7P2-9QZR3"}`) and `props.game.joinCode` show.
 *
 * The round trip is the assertion that keeps the two directions honest: parsing
 * what `display()` produced must give back exactly what was stored.
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
 * Normalisation, which task 5.4 performs before its lookup and which lives here
 * so that it cannot drift from `display()`.
 *
 * The folding is Crockford's decoder: I and L become 1, O becomes 0, case is
 * irrelevant and hyphens are insignificant wherever they fall. U is NOT folded —
 * Crockford excludes it from the symbol set rather than treating it as a variant
 * — so a code containing U is unparseable, which task 5.4 reports as
 * `not_recognised` like any other unmatched code.
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

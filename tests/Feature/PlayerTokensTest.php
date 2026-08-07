<?php

declare(strict_types=1);

use App\Domain\TicTacToe\Mark;
use App\Games\GameState;
use App\Games\MintedToken;
use App\Games\PlayerTokens;
use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

// Feature: remote-tic-tac-toe, Property 8: The Player_Token alone determines the acting Mark
//
// Validates: Requirements 3.1, 3.2, 3.8, 8.7
//
/*
 * Task 5.1 — `PlayerTokens`, the whole of the credential scheme (ADR-005).
 *
 * A Feature test rather than a Unit one, for two reasons that are not
 * negotiable: `issue()` writes to the session, and the token hash is a column on
 * a `games` row. `tests/Pest.php` binds `Tests\TestCase` to `Feature` only, and
 * `tests/Unit` deliberately boots no framework (Req 14.1), so there is nowhere
 * else this file could live. `phpunit.xml` sets `SESSION_DRIVER=array`, so the
 * session here is in-memory and per-test; `RefreshDatabase` supplies the schema,
 * which `DB_DATABASE=:memory:` otherwise leaves absent, exactly as
 * `SchemaConstraintTest` does.
 *
 * The surface is four methods, not three: `mint()` and `remember()` split
 * `issue()`'s two effects apart so that `JoinGame` (5.4) can obtain a hash for
 * its guarded UPDATE *before* the affected-row count tells it whether it won,
 * and write the session only if it did. The last test in this file is that
 * sequence, and it is the reason the split exists — a losing join must leave no
 * orphan credential, and it now leaves none because nothing wrote one, not
 * because something cleaned one up.
 *
 * The half of Property 8 this file can carry, and the half it cannot. Property 8
 * says the Mark attributed to an accepted Move equals the Mark bound to the
 * presented token, *and* that the token resolves to no Mark on any other Game.
 * The second clause is entirely about this class and is asserted below at its
 * strongest — issue on A, present to B, expect null (Req 3.4). The first clause
 * needs `SubmitMove` and a route to be an end-to-end claim, and is asserted where
 * those exist; what is established here is the premise it rests on, that
 * `resolve()` is the only source of a Mark and answers from the token alone.
 */

uses(RefreshDatabase::class);

/**
 * A saved `games` row to hold token hashes. Fixture, not the subject.
 *
 * `active` rather than `waiting_for_opponent` so that the O slot may be
 * populated: the schema's one-directional CHECK
 * (`state <> 'waiting_for_opponent' OR o_token_hash IS NULL`) forbids an
 * occupied O slot while a Game waits for an opponent. A `join_code` because
 * `join_code IS NOT NULL OR rematch_of_game_id IS NOT NULL` keeps every Game
 * reachable. Attributes are assigned one by one because mass assignment is
 * closed on this model.
 */
function tokenGame(string $joinCode): Game
{
    $game = new Game;
    $game->id = Str::uuid7()->toString();
    $game->join_code = $joinCode;
    $game->state = GameState::Active;
    $game->version_counter = 0;
    $game->last_activity_at = now();
    $game->save();

    return $game;
}

/**
 * A saved `games` row still waiting for an opponent, so that `JoinGame`'s
 * guarded UPDATE has something to win against.
 *
 * The O slot is left NULL, which is what `waiting_for_opponent` means (Req 2.1)
 * and what the schema's one-directional CHECK insists on. Only the last test
 * uses this; the rest need a row whose O slot may be filled.
 */
function waitingTokenGame(string $joinCode): Game
{
    $game = new Game;
    $game->id = Str::uuid7()->toString();
    $game->join_code = $joinCode;
    $game->state = GameState::WaitingForOpponent;
    $game->version_counter = 0;
    $game->last_activity_at = now();
    $game->save();

    return $game;
}

/**
 * Issues a token and returns the raw value the session now holds, saving the row
 * because `issue()` deliberately does not (the caller owns persistence).
 *
 * The `is_string` guard is not defensive noise: `heldFor()` returns `?string`, and
 * every caller below feeds the result to `hash()` or a string comparison. Failing
 * loudly here names the actual fault — `issue()` wrote no session entry — instead
 * of letting a null propagate into an unrelated assertion.
 */
function tokenIssued(PlayerTokens $tokens, Game $game, Mark $mark): string
{
    $tokens->issue($game, $mark);
    $game->save();

    $raw = $tokens->heldFor($game->id);

    if (! is_string($raw)) {
        throw new RuntimeException('issue() placed no raw Player_Token in the session');
    }

    return $raw;
}

/*
 * Req 3.8: at least 128 bits from a cryptographically secure source. 32 bytes
 * from `random_bytes()` rendered as hex is 64 hex characters and 256 bits, so the
 * length assertion below *is* the entropy assertion — given the source, which
 * only reading `issue()` can establish and no test can.
 *
 * The character-class assertion is not decoration either. It is what would fail
 * if `bin2hex(random_bytes(32))` were ever replaced by something of the same
 * length drawn from a wider or a non-random alphabet — `Str::random(64)`, for
 * instance, which is 64 characters of base62 and not cryptographically secure.
 */
it('mints 256 bits rendered as hex, and never the same token twice', function () {
    $tokens = new PlayerTokens;

    $first = tokenIssued($tokens, tokenGame('ENTROPY-1'), Mark::X);
    $second = tokenIssued($tokens, tokenGame('ENTROPY-2'), Mark::X);

    expect($first)->toHaveLength(64, 'a Player_Token is 32 bytes rendered as hex, which is 64 characters (Req 3.8)')
        ->and($first)->toMatch('/^[0-9a-f]{64}$/')
        ->and($second)->toHaveLength(64)
        ->and($second)->not->toBe($first, 'two successive issues produced the same token');
});

/*
 * `mint()` on its own: the same 64 hex characters and the matching digest,
 * asserted directly rather than through the session.
 *
 * The digest is compared to `hash('sha256', $raw)` and not merely to "something
 * that is not the raw value", because the two fields of a `MintedToken` are not
 * independent — a raw paired with any other digest is a credential that matches
 * nothing, permanently, and the one producer of the pair is this method.
 * Distinctness is asserted on BOTH fields: equal digests from unequal raws would
 * be a broken hash, and equal raws would be a broken generator.
 */
it('mints a raw token of 64 hex characters with its matching digest', function () {
    $tokens = new PlayerTokens;

    $first = $tokens->mint();
    $second = $tokens->mint();

    expect($first)->toBeInstanceOf(MintedToken::class)
        ->and($first->raw)->toHaveLength(64, 'a Player_Token is 32 bytes rendered as hex (Req 3.8)')
        ->and($first->raw)->toMatch('/^[0-9a-f]{64}$/')
        ->and($first->hash)->toBe(hash('sha256', $first->raw))
        ->and($second->hash)->toBe(hash('sha256', $second->raw))
        ->and($second->raw)->not->toBe($first->raw, 'two mints produced the same raw token')
        ->and($second->hash)->not->toBe($first->hash, 'two mints produced the same hash');
});

/*
 * `mint()` HAS NO SIDE EFFECTS, which is the property that makes it safe for
 * `JoinGame` to call before it knows whether its guarded UPDATE will win.
 *
 * Both effects `issue()` has are asserted absent: nothing is assigned to either
 * token column of the model, and the session holds nothing for the game. A
 * `mint()` that touched either would put `JoinGame` back where it started,
 * needing to retract something on the losing path.
 */
it('mints without touching the game row or the session', function () {
    $tokens = new PlayerTokens;
    $game = tokenGame('MINT-ONLY');

    $token = $tokens->mint();

    expect($token->raw)->not->toBe('')
        ->and($game->x_token_hash)->toBeNull('mint() assigned a hash to the X slot')
        ->and($game->o_token_hash)->toBeNull('mint() assigned a hash to the O slot')
        ->and($game->isDirty('x_token_hash'))->toBeFalse('mint() is not free of side effects: it dirtied x_token_hash')
        ->and($game->isDirty('o_token_hash'))->toBeFalse('mint() is not free of side effects: it dirtied o_token_hash')
        ->and($tokens->heldFor($game->id))->toBeNull('mint() wrote a session entry')
        ->and(Session::get('player_tokens.'.$game->id))->toBeNull('mint() wrote a session entry');
});

/*
 * `remember()` writes the raw value under `player_tokens.{id}` and nothing else.
 *
 * "Nothing else" is asserted three ways, because each covers a different
 * mistake: the row is untouched (it writes no hash), the digest appears under no
 * session key at all (it writes the wrong field nowhere), and the whole
 * `player_tokens` bag is exactly one entry keyed by this Game_Id (it does not
 * also write under some other id or some other shape of key). The key spelling
 * itself is pinned because `GameResolver` (5.3) and `heldFor()` both depend on it
 * literally.
 *
 * THE DOT IN THE KEY IS NOT PART OF THE KEY, as far as the session store is
 * concerned. `Session::put('player_tokens.'.$id, ...)` writes through Laravel's
 * dot notation, so the stored shape is a nested array under one top-level
 * `player_tokens` key, not a flat key containing a dot — which is why the bag is
 * read as a whole below rather than by filtering `Session::all()` for keys with
 * the prefix, a filter that would find nothing and pass vacuously. The design's
 * `session('player_tokens.'.$game->id)` spelling is unaffected: it reads back
 * through the same notation, which is what `heldFor()` does.
 */
it('remembers the raw token under the session key for the game id, and writes nothing else', function () {
    $tokens = new PlayerTokens;
    $game = tokenGame('REMEMBER');

    $token = $tokens->mint();
    $tokens->remember($game->id, $token);

    expect(Session::get('player_tokens.'.$game->id))->toBe($token->raw, 'the session key is not the one the design specifies')
        ->and($tokens->heldFor($game->id))->toBe($token->raw)
        ->and(Session::get('player_tokens'))->toBe([$game->id => $token->raw], 'remember() wrote more than the one entry for this game')
        ->and($game->isDirty())->toBeFalse('remember() touched the game row; it writes the session and nothing else')
        ->and($game->x_token_hash)->toBeNull('remember() assigned a hash to the row')
        ->and($game->o_token_hash)->toBeNull('remember() assigned a hash to the row');

    // `str_contains` rather than `toContain()`, which takes variadic needles
    // and no message argument — a message passed there is silently asserted as
    // a second needle.
    foreach (Session::all() as $key => $value) {
        expect(str_contains(is_string($value) ? $value : (string) json_encode($value), $token->hash))
            ->toBeFalse("session key {$key} holds the token hash; the hash belongs on the row, not in the session");
    }
});

/*
 * The stored value is the SHA-256, and the raw value is nowhere in the row.
 *
 * Both halves are asserted because either alone is weak: equality with the digest
 * would still pass if the digest happened to equal the raw value under some
 * future encoding change, and inequality with the raw value would pass for any
 * transformation at all, including a reversible one.
 */
it('stores the hash of the token, never the token', function () {
    $tokens = new PlayerTokens;
    $game = tokenGame('HASHED');

    $raw = tokenIssued($tokens, $game, Mark::X);

    expect($game->x_token_hash)->not->toBe($raw, 'the raw Player_Token was stored on the game row')
        ->and($game->x_token_hash)->toBe(hash('sha256', $raw))
        ->and(DB::table('games')->where('id', $game->id)->value('x_token_hash'))->toBe(hash('sha256', $raw));
});

/*
 * Req 8.7, at the storage layer: no column of the row holds the raw token.
 *
 * Every column is scanned rather than the two token columns, because the leak
 * this guards against is not "the hash column holds the raw value" — the test
 * above covers that — but a raw token copied somewhere incidental. There is no
 * column here it would belong in today, which is the point: the assertion is
 * over the whole row so that a column added later is covered without anyone
 * remembering to extend it.
 */
it('leaves the raw token in no column of the game row', function () {
    $tokens = new PlayerTokens;
    $game = tokenGame('NO-LEAK');

    $rawX = tokenIssued($tokens, $game, Mark::X);
    $rawO = tokenIssued($tokens, $game, Mark::O);

    $row = (array) DB::table('games')->where('id', $game->id)->first();

    expect($row)->not->toBeEmpty('the game row was not found, so this test asserts nothing');

    // `str_contains` rather than `toContain()`, which takes variadic needles
    // and no message argument — a message passed there is silently asserted as
    // a second needle.
    foreach ($row as $column => $value) {
        $value = is_string($value) ? $value : (string) json_encode($value);

        expect(str_contains($value, $rawX))->toBeFalse("column {$column} contains the raw X Player_Token (Req 8.7)")
            ->and(str_contains($value, $rawO))->toBeFalse("column {$column} contains the raw O Player_Token (Req 8.7)");
    }
});

/*
 * `issue()` does not persist the row: the caller does.
 *
 * Pinned as behaviour rather than left to the docblock because two callers depend
 * on it. `CreateGame` (5.2) assigns the rest of the row and saves once, and
 * `JoinGame` (5.4) claims the O slot with a conditional UPDATE whose affected-row
 * count decides the outcome — a `save()` inside `issue()` would be a second,
 * unguarded write racing the guarded one.
 */
it('assigns the hash without saving the row, leaving persistence to the caller', function () {
    $tokens = new PlayerTokens;
    $game = tokenGame('UNSAVED');

    $tokens->issue($game, Mark::X);

    expect($game->x_token_hash)->toBeString('issue() did not assign the hash to the model')
        ->and($game->isDirty('x_token_hash'))->toBeTrue('issue() saved the row; the caller owns persistence')
        ->and(DB::table('games')->where('id', $game->id)->value('x_token_hash'))->toBeNull('issue() wrote the hash to the database itself');

    $game->save();

    expect(DB::table('games')->where('id', $game->id)->value('x_token_hash'))->toBeString();
});

/*
 * Req 3.1, 3.2: each slot binds one token to one Mark, and `resolve()` returns
 * that Mark from the token alone.
 *
 * Both slots on ONE game, because two games would not distinguish
 * "bound to a Mark" from "bound to a row".
 */
it('binds each slot to its own mark on one game', function () {
    $tokens = new PlayerTokens;
    $game = tokenGame('BOTH-SLOTS');

    $rawX = tokenIssued($tokens, $game, Mark::X);
    $rawO = tokenIssued($tokens, $game, Mark::O);

    expect($rawO)->not->toBe($rawX)
        ->and($game->x_token_hash)->toBeString()
        ->and($game->o_token_hash)->toBeString()
        ->and($game->o_token_hash)->not->toBe($game->x_token_hash)
        ->and($tokens->resolve($game, $rawX))->toBe(Mark::X, 'the X token did not resolve to X (Req 3.2)')
        ->and($tokens->resolve($game, $rawO))->toBe(Mark::O, 'the O token did not resolve to O (Req 3.2)');
});

/*
 * REQUIREMENT 3.4, AND THE CORE OF THE LOCATION-BINDING DESIGN. A token minted
 * for game A resolves to no Mark on game B.
 *
 * Nothing in the token says which Game it belongs to; the binding is *where its
 * hash is stored*, so `resolve()` on B looks in B's row and simply cannot see a
 * hash held on A's. This is the assertion that would fail first if anyone
 * "improved" the scheme by putting a Game_Id inside the token value and comparing
 * it — and it is asserted in both directions so that neither row is privileged.
 */
it('resolves a token to no mark on any other game', function () {
    $tokens = new PlayerTokens;
    $a = tokenGame('GAME-A');
    $b = tokenGame('GAME-B');

    $rawA = tokenIssued($tokens, $a, Mark::X);
    $rawB = tokenIssued($tokens, $b, Mark::X);

    expect($tokens->resolve($a, $rawA))->toBe(Mark::X)
        ->and($tokens->resolve($b, $rawB))->toBe(Mark::X)
        ->and($tokens->resolve($b, $rawA))->toBeNull("game A's token resolved to a mark on game B (Req 3.4)")
        ->and($tokens->resolve($a, $rawB))->toBeNull("game B's token resolved to a mark on game A (Req 3.4)");
});

/*
 * The three ways a request can present nothing usable, all answered `null`.
 *
 * The well-formed-but-wrong case matters most: 64 hex characters that were never
 * issued are indistinguishable in shape from a real token, so a `resolve()` that
 * validated shape instead of comparing hashes would pass every other test in this
 * file and fail this one. The empty and null cases are asserted against a game
 * whose O slot is NULL as well, because `o_token_hash IS NULL` *is* "no second
 * player" (Req 2.1) and must not be reachable by presenting nothing.
 */
it('resolves nothing for an absent, empty or unissued token', function () {
    $tokens = new PlayerTokens;
    $game = tokenGame('REJECTED');

    tokenIssued($tokens, $game, Mark::X);

    $unissued = bin2hex(random_bytes(32));

    expect($game->o_token_hash)->toBeNull('the fixture already has an O token, so the null-slot half asserts nothing')
        ->and($tokens->resolve($game, null))->toBeNull('a request presenting no token resolved to a mark')
        ->and($tokens->resolve($game, ''))->toBeNull('a request presenting an empty token resolved to a mark')
        ->and($tokens->resolve($game, $unissued))->toBeNull('a well-formed token that was never issued resolved to a mark');
});

/*
 * `heldFor()` takes a Game_Id string, not a `Game`, because `GameResolver` (5.3)
 * must ask this question about an id whose row no longer exists — the expired
 * rows of its visibility table (Req 13.6, 13.8). So it is exercised here with a
 * bare id that was never a Game, alongside the id of a real Game with no token,
 * and both answer null.
 */
it('reports the token held for a game id, and null for any other', function () {
    $tokens = new PlayerTokens;
    $game = tokenGame('HELD');
    $untokened = tokenGame('NOT-HELD');

    $raw = tokenIssued($tokens, $game, Mark::X);

    expect($tokens->heldFor($game->id))->toBe($raw)
        ->and(Session::get('player_tokens.'.$game->id))->toBe($raw, 'the session key is not the one the design specifies')
        ->and($tokens->heldFor($untokened->id))->toBeNull('a token was reported for a game this session never joined')
        ->and($tokens->heldFor(Str::uuid7()->toString()))->toBeNull('a token was reported for an id that was never a game');
});

/*
 * `issue()` STILL DOES ALL THREE THINGS, so `CreateGame` (5.2) needs no change.
 *
 * The composition is now `mint()` + assign + `remember()`, and this test is what
 * says the refactoring did not quietly turn `issue()` into one of its halves. All
 * three effects are asserted together in one call, because that togetherness is
 * the whole value of the method: `CreateGame` inserts a fresh row with no
 * competing writer, so it has no losing path and wants the composed form.
 *
 * The hash is compared against the digest of the raw value the session holds,
 * which is the only assertion that shows the two effects are of the SAME token.
 * Two independently minted tokens would satisfy every other assertion here.
 */
it('issues by minting, assigning the hash and remembering the raw value in one call', function () {
    $tokens = new PlayerTokens;
    $game = tokenGame('COMPOSED');

    $tokens->issue($game, Mark::O);

    $raw = $tokens->heldFor($game->id);

    expect($raw)->toBeString('issue() wrote no session entry')
        ->and($game->o_token_hash)->toBe(hash('sha256', (string) $raw), 'the hash on the row is not the digest of the token in the session')
        ->and($game->x_token_hash)->toBeNull('issue() filled the slot of the other mark')
        ->and($game->isDirty('o_token_hash'))->toBeTrue('issue() saved the row; the caller owns persistence')
        ->and($tokens->resolve($game, (string) $raw))->toBe(Mark::O, 'the issued token did not resolve to its mark (Req 3.2)');
});

/*
 * THE POINT OF THE WHOLE SPLIT: A LOSING JOIN LEAVES NO ORPHAN CREDENTIAL, AND A
 * WINNING ONE LEAVES A COMPLETE PAIR.
 *
 * This is `JoinGame`'s sequence (task 5.4) exercised through `PlayerTokens`
 * alone, before `JoinGame` exists: mint, run the guarded UPDATE, and let the
 * affected-row count decide whether `remember()` is ever called. The statement is
 * the one the task specifies —
 * `WHERE id = ? AND state = 'waiting_for_opponent' AND o_token_hash IS NULL` —
 * so the loser's zero is produced by the same guard that will produce it in
 * production, not by a flag in the test.
 *
 * WHAT THIS COULD NOT ASSERT BEFORE THE SPLIT. With only `issue()`, obtaining the
 * hash for that statement meant writing the session first, so the losing branch
 * would hold a raw token for a slot it never claimed and would have to retract
 * it. The assertion below — `heldFor()` is null after a losing attempt — is not
 * "the cleanup worked"; it is that there was nothing to clean up. That is what
 * makes "no orphan credential exists" structural.
 *
 * Deliberately sequential, no parallelism and no sleeps: the loser's path is
 * reached by running the guarded statement second against a row the first has
 * already claimed, which is exactly what a concurrent loser observes. The
 * concurrency half of Property 13 lives in `ConcurrencyTest` (5.8) against
 * `JoinGame` itself.
 */
it('writes no session entry for a join that loses the guarded update, and one for a join that wins', function () {
    $tokens = new PlayerTokens;
    $game = waitingTokenGame('RACE-1');

    $claim = static fn (MintedToken $token): int => DB::update(
        'UPDATE games SET state = ?, o_token_hash = ?, version_counter = version_counter + 1, last_activity_at = ?'
        .' WHERE id = ? AND state = ? AND o_token_hash IS NULL',
        [
            GameState::Active->value,
            $token->hash,
            now()->toDateTimeString(),
            $game->id,
            GameState::WaitingForOpponent->value,
        ],
    );

    // The winner. Hash computed before the statement, session written after,
    // because the count is what authorises the write.
    $winner = $tokens->mint();

    expect($claim($winner))->toBe(1, 'the first claim on a waiting game did not win');

    $tokens->remember($game->id, $winner);

    // The loser. It mints — it must, the statement needs a hash — runs the same
    // guarded statement, is told nothing changed, and never calls remember().
    $loser = $tokens->mint();

    expect($claim($loser))->toBe(0, 'a second claim on an already-joined game won; the guard is not doing its work');

    $row = DB::table('games')->where('id', $game->id)->first();

    // Thrown rather than asserted, so the failure names the actual fault instead
    // of letting a null propagate into every column assertion below.
    if (! $row instanceof stdClass) {
        throw new RuntimeException('the game row vanished, so this test asserts nothing');
    }

    expect($row->o_token_hash)->toBe($winner->hash, "the loser's hash landed on the row")
        ->and($row->state)->toBe(GameState::Active->value)
        ->and($row->version_counter)->toBe(1, 'the losing statement incremented the Version_Counter')
        ->and($tokens->heldFor($game->id))->toBe($winner->raw, 'the winning join left no session entry')
        ->and($tokens->heldFor($game->id))->not->toBe($loser->raw, "the loser's raw token is held for this game");

    // And the loser holds nothing anywhere: no orphan credential exists, because
    // nothing wrote one.
    // `str_contains` rather than `toContain()`, which takes variadic needles
    // and no message argument — a message passed there is silently asserted as
    // a second needle.
    foreach (Session::all() as $key => $value) {
        expect(str_contains(is_string($value) ? $value : (string) json_encode($value), $loser->raw))
            ->toBeFalse("session key {$key} holds the losing join's raw token (no orphan credential may exist)");
    }

    expect((array) $row)->not->toBeEmpty('the row has no columns, so the scan below asserts nothing');

    foreach ((array) $row as $column => $value) {
        expect(str_contains(is_string($value) ? $value : (string) json_encode($value), $loser->hash))
            ->toBeFalse("column {$column} holds the losing join's hash");
    }

    // The freshly minted-and-discarded token resolves to no mark, which is the
    // same statement from the verification side.
    $reread = Game::query()->findOrFail($game->id);

    expect($tokens->resolve($reread, $loser->raw))->toBeNull('the discarded token resolved to a mark (Req 3.4)')
        ->and($tokens->resolve($reread, $winner->raw))->toBe(Mark::O, 'the winning token did not resolve to O');
});

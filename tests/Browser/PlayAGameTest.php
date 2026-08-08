<?php

declare(strict_types=1);

use App\Games\JoinCode;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Browser\Support\FreshSessionStorePerRequest;

// Feature: remote-tic-tac-toe
//
// Validates: Requirements 14.5, 8.2
//
/*
 * The one end-to-end browser test: two Player_Sessions from creation to a
 * Terminal_State (Req 14.5), and the observational check on Requirement 8.2's
 * three-second budget — the opponent's Move has to arrive unaided.
 *
 * Facts the code below cannot tell you:
 *
 *  - Every `visit()` builds its own Playwright browser context — `buildAwaitablePage()`
 *    calls `$browser->newContext()` in
 *    `vendor/pestphp/pest-plugin-browser/src/Api/PendingAwaitablePage.php` — and a
 *    context is Playwright's cookie-jar boundary, so the two pages below present no
 *    cookie to each other. The different-Marks expectation is what proves the Player_
 *    Sessions are separate: were they one, `App\Games\JoinGame` would short-circuit on
 *    the token the session already holds and hand the joiner X back.
 *  - Browser assertions retry until `Playwright::timeout()`, 5 s by default, through
 *    `.../src/Api/AwaitableWebpage.php`, and `useGamePolling` polls every 2000 ms. That
 *    pairing is the wait: an accepted Move appears within one poll.
 *
 * Do not reload a page to make an assertion pass. The polling hook arriving unaided is
 * exactly what Requirement 8.2 asks for, and a reload would retire it while leaving
 * the test green. Do not add a second browser test either (ADR-008).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // `phpunit.xml` pins `SESSION_DRIVER=array`, Laravel's null handler, which keeps
    // nothing between requests — a Player_Token issued by one request would be gone by
    // the next. The plugin's server handles requests in this process on this
    // connection (`.../src/Drivers/LaravelHttpServer.php`), so `RefreshDatabase` still
    // rolls the rows back.
    config(['session.driver' => 'database']);

    // Without this the two isolated browsers would share one Player_Session, and the
    // cookies are not why — the reason, and the two observations that established it,
    // are in the middleware's own docblock.
    app(Kernel::class)->prependMiddleware(FreshSessionStorePerRequest::class);
});

it('drives two isolated sessions from creation to a win with no manual refresh', function (): void {
    $creator = visit('/');
    $creator->assertSee('Start a game')->click('Create a game');
    $creator->assertSee('Waiting for a second player');

    // Read from the rendered page, not from the row, so this is the Join_Code a player
    // would copy. Well-formedness first: an empty or malformed read would otherwise
    // reach the join form and be refused as `not_recognised`, which reads like a join
    // defect rather than a selector that matched nothing.
    $code = trim((string) $creator->text('[aria-labelledby="join-code-label"]'));

    expect($code)->toMatch(
        '/^['.JoinCode::ALPHABET.']{5}-['.JoinCode::ALPHABET.']{5}$/',
        "the Join_Code read from the page is not a displayed Join_Code: [{$code}]",
    );

    $joiner = visit('/join');
    $joiner->assertSee('Join a game')->type('join_code', $code)->click('Join game');
    $joiner->assertSee('You are playing');

    // `main > p:has(span)` is Game.tsx's "You are playing <span>{yourMark}</span>." and
    // only that: `OutcomeMessage` is the other direct-child paragraph and carries no
    // span, and every other paragraph sits inside a section or a div.
    $creatorMark = trim((string) $creator->text('main > p:has(span)'));
    $joinerMark = trim((string) $joiner->text('main > p:has(span)'));

    expect($creatorMark)->toBe('You are playing x.', "the creator was not assigned X: [{$creatorMark}]")
        ->and($joinerMark)->toBe('You are playing o.', "the joiner was not assigned O: [{$joinerMark}]")
        ->and($joinerMark)->not->toBe($creatorMark, 'the two sessions resolved to one Mark, so they are one Player and not two');

    // The creator learns the Game started from the poll; nothing here reloads.
    $creator->assertSee('that is you.');

    $sessions = [$creator, $joiner];
    $awaited = null;

    // X takes the top row, O the left column beneath it: X0 O3 X1 O4 X2.
    //
    // The labels are `Cell.tsx`'s and its case is uneven on purpose: an unoccupied cell
    // reads `empty` in lower case while an occupied one reads `X` or `O` in upper. A CSS
    // attribute selector matches values case-sensitively, and a selector matching nothing
    // makes the plugin wait rather than fail, so `EMPTY` here hangs instead of reporting.
    foreach ([[0, 'top left'], [1, 'middle left'], [0, 'top centre'], [1, 'centre'], [0, 'top right']] as [$turn, $cell]) {
        $page = $sessions[$turn];

        // The opponent's Move has to be on screen before this side can move, so waiting
        // for it is both the turn gate and the check on Requirement 8.2.
        if ($awaited !== null) {
            $page->assertVisible('[aria-label="'.$awaited.'"]');
        }

        $page->click('[aria-label="'.$cell.', empty"]');

        $awaited = $cell.', '.($turn === 0 ? 'X' : 'O');
    }

    foreach ($sessions as $page) {
        $page->assertSee('X won this game.')
            ->assertVisible('[aria-label="top left, X, in a winning line"]')
            ->assertCount('[aria-label$=", in a winning line"]', 3);
    }
})->group('browser');

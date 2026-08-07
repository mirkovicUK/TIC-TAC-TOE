<?php

use Tests\TestCase;

/*
 * The single directive below is scoped to `Feature` on purpose.
 *
 * `Tests\TestCase` extends `Illuminate\Foundation\Testing\TestCase`, which boots
 * the whole application once per test. `Unit` is deliberately absent and must
 * stay absent: Requirement 14.1 requires the domain unit tests to run without a
 * booted framework, and ADR-003 / Requirement 11.9 require the domain layer to
 * reach neither persistence, session nor transport.
 *
 * The mechanism is worth stating plainly. With no container, an accidental
 * `now()` or `config()` inside `App\Domain\TicTacToe` throws immediately in the
 * unit tests. With a booted application it silently works, every test still
 * passes, and the domain layer's purity is broken with no signal. Excluding
 * `Unit` is what makes framework coupling fail at the moment it is written.
 *
 * Two edits would undo that: adding `->in('Unit')`, and a bare
 * `uses(TestCase::class)` with no `->in()` at all, which applies globally. The
 * second is the easier mistake to make.
 *
 * `tests/Unit/Domain/ArchitectureTest.php` asserts this. It reflects on its own
 * generated class and requires `Tests\TestCase` to be absent from the ancestry —
 * coverage that depends on that test living inside `tests/Unit/Domain/`, so
 * moving it elsewhere would remove the guard without failing anything.
 */

uses(TestCase::class)->in('Feature');

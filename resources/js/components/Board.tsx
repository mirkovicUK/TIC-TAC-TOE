import { router } from '@inertiajs/react';
import Cell from '@/components/Cell';
import type { GameProps } from '@/pages/Game';

/*
 * The nine Cells, and the one place a Move is submitted.
 *
 * THE DISABLED CONDITION IS `!isYourTurn || state !== 'active'`, AND BOTH HALVES ARE
 * REQUIRED. `markToMove` is total over Move_List length by Requirement 4.1 — it is
 * defined in a Terminal_State too, where it names who *would* have moved next — and
 * `GameRepresentation` emits it unconditionally, with `isYourTurn` as
 * `markToMove === yourMark` and nothing more. Two consequences, and each is what one
 * half of the condition is for:
 *
 *   - On a board X won at Sequence_Index 4, `markToMove` is `O`, so `isYourTurn` is
 *     TRUE for the O Player. `!isYourTurn` alone would leave that finished board
 *     clickable.
 *   - While `waiting_for_opponent` the Move_List is empty, so `markToMove` is `X`
 *     and `isYourTurn` is TRUE for the Creator, who is the only person who can see
 *     the page at all. `!isYourTurn` alone would leave that board clickable too.
 *
 * In both cases the server is safe — `SubmitMove` answers `game_ended` and
 * `game_not_started` respectively and changes nothing — so the defect is not a
 * corrupted board but a UI that appears to accept a click and then flashes an error,
 * which is worse than an inert board. Worth stating at the line rather than in a
 * commit message, because the single browser test (task 12.5) stops at asserting the
 * winning Mark and the highlight and never clicks a terminal board, so nothing in the
 * suite would catch the second half being dropped.
 *
 * The mirror-image mistake is `state !== 'active'` alone, which would let either
 * Player move on the other's turn; the server answers `not_your_turn` and again
 * nothing breaks, but the board would offer a move that cannot be made.
 *
 * WINNING CELLS ARE THE FLATTENED `winningLines` (Req 6.5). `winningLines` is a list
 * of lines and each line is its three Cell_Indexes, because a double win is reachable
 * in legal play and Requirement 6.3 has the server send *every* completed line.
 * Flattening is what makes a double line highlight both: a cell is highlighted if it
 * appears in any line, and the Set removes the duplicate at the intersection.
 *
 * `router.post` RATHER THAN `useForm`. Nine cells are nine values of one field, so a
 * form's `data` would be state this component would have to set before posting and
 * then keep in step with the board — the local state `Game.tsx` deliberately does not
 * have. `router.post` goes through the same Inertia request path as `useForm().post()`
 * and therefore carries the CSRF token the same way (Req 10.9). `cell_index` is the
 * whole payload: the acting Mark comes from the Player_Token in the session, and a
 * `mark` in the body would be ignored (Req 3.6), so none is sent.
 *
 * `preserveScroll` keeps the page where it is; there is no `only` here because the
 * response to a Move is a 303 and the following GET is a full visit carrying the
 * flashed outcome as well as the game prop.
 */

type BoardProps = {
    game: GameProps;
};

export default function Board({ game }: BoardProps) {
    const disabled = ! game.isYourTurn || game.state !== 'active';

    const winningCells = new Set(game.winningLines.flat());

    const select = (cellIndex: number) => {
        router.post(`/games/${game.id}/moves`, { cell_index: cellIndex }, { preserveScroll: true });
    };

    return (
        <div role="group" aria-label="Board" className="grid w-fit grid-cols-3 gap-2">
            {game.board.map((occupant, index) => (
                <Cell
                    key={index}
                    index={index}
                    occupant={occupant}
                    winning={winningCells.has(index)}
                    disabled={disabled}
                    onSelect={select}
                />
            ))}
        </div>
    );
}

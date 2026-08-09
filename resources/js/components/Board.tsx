import { router } from '@inertiajs/react';
import Cell from '@/components/Cell';
import type { GameProps } from '@/pages/Game';

/*
 * The nine Cells, and the one place a Move is submitted.
 *
 * The disabled condition is `!isYourTurn || state !== 'active'` and both halves are
 * required, because `markToMove` is total over Move_List length (Req 4.1) and stays
 * defined in a Terminal_State, where it names who *would* have moved next:
 *
 *   - On a board X won at Sequence_Index 4, `markToMove` is `O`, so `isYourTurn` is TRUE
 *     for the O Player, and `!isYourTurn` alone leaves a finished board clickable.
 *   - While `waiting_for_opponent` the Move_List is empty, so `markToMove` is `X` and
 *     `isYourTurn` is TRUE for the Creator, the only person who can see the page.
 *
 * The mirror-image mistake is `state !== 'active'` alone, which lets either Player move on
 * the other's turn. In every case the server refuses and nothing is corrupted, but the
 * board would offer a move that cannot be made. Nothing in the suite would catch it: the
 * browser test never clicks a terminal board.
 *
 * Winning cells are the flattened `winningLines` (Req 6.5), because a double win is
 * reachable in legal play and Requirement 6.3 has the server send *every* completed line;
 * the Set removes the duplicate at the intersection.
 *
 * `router.post` rather than `useForm`: a form's `data` would be local state this component
 * would have to keep in step with the board, and `router.post` carries the CSRF token the
 * same way (Req 10.9). `cell_index` is the whole payload — the acting Mark comes from the
 * Player_Token in the session and a `mark` in the body would be ignored (Req 3.6).
 *
 * There is no `only` here because the response to a Move is a 303 and the following GET is
 * a full visit carrying the flashed outcome as well as the game prop.
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
        // The well the cells sit in: an inset shadow makes the tray look recessed, which is
        // what gives the raised cells something to be raised from. `mx-auto` because the
        // grid is `w-fit` inside a flex column that would otherwise pin it left.
        <div
            role="group"
            aria-label="Board"
            className="mx-auto grid w-fit grid-cols-3 gap-3 rounded-2xl bg-surface-deep p-3 shadow-[inset_0_2px_8px_0_rgba(0,0,0,0.55),0_1px_0_0_rgba(255,255,255,0.05)]"
        >
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

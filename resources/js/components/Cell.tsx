/*
 * One of the nine Cells: its occupant, whether it is part of a completed
 * Winning_Line, and whether it can be played.
 *
 * `aria-disabled` rather than the native `disabled` attribute, deliberately. A natively
 * disabled button leaves the tab order, so on a finished board — or on any board while it
 * is the opponent's turn — a keyboard user would find the nine cells gone and could not
 * read the result off them. The cost is that the attribute does not block activation, so
 * the click handler checks `disabled` itself: that check is the real gate and the attribute
 * is what tells the player about it. `Board.tsx` owns the condition.
 *
 * The winning-line highlight is in the label as well as the colour (Req 6.5), for a player
 * who is not reading the colour.
 *
 * The glyph is `aria-hidden` because the occupant is already in the accessible name;
 * exposing the text node would have a screen reader read "top left, X" and then "X" again.
 */

const POSITIONS = [
    'top left',
    'top centre',
    'top right',
    'middle left',
    'centre',
    'middle right',
    'bottom left',
    'bottom centre',
    'bottom right',
] as const;

type CellProps = {
    index: number;
    occupant: 'x' | 'o' | null;
    winning: boolean;
    disabled: boolean;
    onSelect: (cellIndex: number) => void;
};

export default function Cell({ index, occupant, winning, disabled, onSelect }: CellProps) {
    const position = POSITIONS[index] ?? `cell ${index}`;
    const occupancy = occupant === null ? 'empty' : occupant.toUpperCase();
    const label = winning ? `${position}, ${occupancy}, in a winning line` : `${position}, ${occupancy}`;

    return (
        <button
            type="button"
            aria-label={label}
            aria-disabled={disabled}
            onClick={() => {
                if (! disabled) {
                    onSelect(index);
                }
            }}
            className={[
                'flex h-20 w-20 items-center justify-center rounded border-2 text-4xl font-semibold',
                winning ? 'border-green-600 bg-green-50 text-green-900' : 'border-gray-300 bg-white text-gray-900',
                disabled ? 'cursor-not-allowed opacity-70' : 'cursor-pointer hover:bg-indigo-50',
            ].join(' ')}
        >
            <span aria-hidden="true">{occupant === null ? '' : occupant.toUpperCase()}</span>
        </button>
    );
}

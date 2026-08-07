/*
 * One of the nine Cells: its occupant, whether it is part of a completed
 * Winning_Line, and whether it can be played.
 *
 * IT IS A `<button>`, WHICH IS THE ACCESSIBILITY DECISION THE REST FOLLOWS FROM. A
 * div with an `onClick` would be invisible to the keyboard and unnamed to a screen
 * reader; a button is focusable, activates on Enter and Space, and takes an
 * accessible name from `aria-label`.
 *
 * `aria-disabled` RATHER THAN THE NATIVE `disabled` ATTRIBUTE, deliberately. A
 * natively disabled button is removed from the tab order, so on a finished board —
 * or on any board while it is the opponent's turn — a keyboard user would find the
 * nine cells simply gone and could not read the result off them. `aria-disabled`
 * keeps every cell reachable and announced, and has the state announced with it, so
 * the unavailability is conveyed to assistive technology rather than only by the
 * dimmed styling. The cost is that the attribute does not block activation, so the
 * click handler checks `disabled` itself; that check is the real gate and the
 * attribute is what tells the player about it. `Board.tsx` owns the condition.
 *
 * THE WINNING-LINE HIGHLIGHT IS IN THE LABEL AS WELL AS IN THE COLOUR (Req 6.5).
 * A ring and a background tell a sighted player which three cells won; the label
 * suffix is the same fact for a player who is not reading the colour. This is also
 * why a double win needs nothing special here — `Board.tsx` flattens every
 * completed line, so a cell in either line arrives with `winning` true.
 *
 * THE GLYPH IS `aria-hidden`. The occupant is already in the accessible name, and
 * leaving the text node exposed would have a screen reader read "top left, X" and
 * then "X" again.
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

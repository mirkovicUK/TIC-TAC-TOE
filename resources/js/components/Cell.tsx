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
                // The face. `shadow-[…]` carries three layers: a hard bottom edge that is
                // the depth, an ambient drop, and an inset top highlight that reads as light
                // from above. Tailwind has no scale for that combination, hence the literal.
                'relative flex h-20 w-20 items-center justify-center rounded-xl text-4xl font-bold',
                'transition-[transform,box-shadow,background-color] duration-100 ease-out',
                'focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:ring-offset-surface focus-visible:outline-none',
                winning
                    ? 'bg-win-face text-win shadow-[0_4px_0_0_var(--color-win-face),0_6px_14px_-4px_rgba(0,0,0,0.6),inset_0_1px_0_0_rgba(255,255,255,0.16)]'
                    : 'bg-elevated shadow-[0_4px_0_0_var(--color-surface-deep),0_6px_14px_-4px_rgba(0,0,0,0.6),inset_0_1px_0_0_rgba(255,255,255,0.08)]',
                // Colour by mark, on top of the glyph rather than instead of it: the letter is
                // the accessible name, so a player who cannot tell the hues apart loses nothing.
                occupant === 'x' ? 'text-mark-x' : occupant === 'o' ? 'text-mark-o' : '',
                // Lift on hover, press on click — but only when the cell is playable, so an
                // inert board does not invite a move the server would refuse.
                disabled
                    ? 'cursor-not-allowed'
                    : 'cursor-pointer hover:-translate-y-0.5 hover:bg-panel active:translate-y-0.5 active:shadow-[0_1px_0_0_var(--color-surface-deep),0_2px_6px_-2px_rgba(0,0,0,0.6)]',
            ].join(' ')}
        >
            <span aria-hidden="true" className="drop-shadow-[0_1px_2px_rgba(0,0,0,0.45)]">
                {occupant === null ? '' : occupant.toUpperCase()}
            </span>
        </button>
    );
}

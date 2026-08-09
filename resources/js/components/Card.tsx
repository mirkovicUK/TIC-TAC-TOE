import type { ReactNode } from 'react';

/*
 * A raised panel. Four places were repeating the same border, radius, background and
 * padding, and they had already drifted by a rounding step; this is that surface once.
 *
 * `as` exists because two of the callers are landmarks — `JoinCodePanel` needs `section`
 * with its `aria-labelledby`, and a `div` there would drop the heading association a
 * screen reader uses to announce the region. Extra props are spread through for the same
 * reason, so a caller can pass `aria-labelledby` without this component knowing about it.
 *
 * The top hairline is `inset 0 1px 0` rather than a border, so it lights only the top edge.
 * A full border reads as an outline; one lit edge reads as a surface with light above it,
 * which is the same trick the cells use.
 */

type CardProps = {
    children: ReactNode;
    as?: 'div' | 'section';
    className?: string;
} & Record<string, unknown>;

export default function Card({ children, as = 'div', className = '', ...rest }: CardProps) {
    const Tag = as;

    return (
        <Tag
            {...rest}
            className={[
                'rounded-2xl border border-hairline/60 bg-panel p-5',
                'shadow-[0_1px_0_0_rgba(255,255,255,0.06)_inset,0_10px_30px_-12px_rgba(0,0,0,0.7)]',
                className,
            ].join(' ')}
        >
            {children}
        </Tag>
    );
}

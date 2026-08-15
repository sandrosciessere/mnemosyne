import { SVGAttributes } from 'react';

/**
 * Mnemosyne mark: a geometric "M" whose central valley continues into a
 * vertical spine, reading as an open book seen from the front (two pages
 * meeting at the spine). Drawn with currentColor so it works on any
 * light or dark surface; legible down to 16px.
 */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg">
            <g fill="none" stroke="currentColor" strokeWidth="4.5" strokeLinecap="round" strokeLinejoin="round">
                <path d="M8 31V10l12 11 12-11v21" />
                <path d="M20 21v10" />
            </g>
        </svg>
    );
}

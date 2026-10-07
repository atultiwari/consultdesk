/**
 * The ConsultDesk mark: a consultation bubble holding a small week of slots, one of them booked.
 * Drawn in the site's own colours (brand and accent), so it follows the preset and dark mode.
 */
export function BrandMark({ size = 28, className }: { size?: number; className?: string }) {
  return (
    <svg
      className={className ? `brand-mark ${className}` : 'brand-mark'}
      width={size}
      height={size}
      viewBox="0 0 64 64"
      aria-hidden="true"
      focusable="false"
    >
      <rect className="brand-mark__tile" width="64" height="64" rx="15" />
      <path
        className="brand-mark__bubble"
        d="M16 15h32a6 6 0 0 1 6 6v18a6 6 0 0 1-6 6H30l-10 9v-9h-4a6 6 0 0 1-6-6V21a6 6 0 0 1 6-6z"
      />
      <g className="brand-mark__free">
        <rect x="16" y="22" width="8" height="6" rx="1.8" />
        <rect x="28" y="22" width="8" height="6" rx="1.8" />
        <rect x="16" y="32" width="8" height="6" rx="1.8" />
        <rect x="40" y="32" width="8" height="6" rx="1.8" />
      </g>
      <rect className="brand-mark__booked" x="40" y="22" width="8" height="6" rx="1.8" />
      <rect className="brand-mark__taken" x="28" y="32" width="8" height="6" rx="1.8" />
    </svg>
  );
}

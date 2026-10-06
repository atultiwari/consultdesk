import { encode } from 'uqr';

/** QR code drawn as SVG rectangles from the encoded matrix (no HTML injection). */
export function Qr({ value, label, size = 196 }: { value: string; label: string; size?: number }) {
  const { data } = encode(value, { ecc: 'M', border: 2 });
  const cells = data.length;

  return (
    <svg
      className="qr"
      viewBox={`0 0 ${cells} ${cells}`}
      width={size}
      height={size}
      role="img"
      aria-label={label}
      shapeRendering="crispEdges"
    >
      <rect width={cells} height={cells} fill="#ffffff" />
      {data.flatMap((row, y) =>
        row.map((on, x) =>
          on ? <rect key={`${x}-${y}`} x={x} y={y} width={1} height={1} fill="#111111" /> : null,
        ),
      )}
    </svg>
  );
}

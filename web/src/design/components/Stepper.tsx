export function Stepper({ steps, current }: { steps: string[]; current: number }) {
  return (
    <ol className="stepper" aria-label="Booking steps">
      {steps.map((label, index) => {
        const state = index < current ? 'done' : index === current ? 'current' : 'todo';
        return (
          <li
            key={label}
            className={`stepper__item stepper__item--${state}`}
            aria-current={state === 'current' ? 'step' : undefined}
          >
            <span className="stepper__bar" aria-hidden="true" />
            <span>
              <span className="visually-hidden">{`Step ${index + 1} of ${steps.length}: `}</span>
              {label}
              {state === 'done' && <span className="visually-hidden"> (done)</span>}
            </span>
          </li>
        );
      })}
    </ol>
  );
}

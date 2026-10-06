import { useEffect, useState } from 'react';
import { applyTheme, readThemeChoice, saveThemeChoice, type ThemeChoice } from './theme';

export function ThemeToggle() {
  const [choice, setChoice] = useState<ThemeChoice>(readThemeChoice);

  useEffect(() => {
    applyTheme(choice);
    const media = window.matchMedia?.('(prefers-color-scheme: dark)');
    const onChange = () => applyTheme(choice);
    media?.addEventListener('change', onChange);
    return () => media?.removeEventListener('change', onChange);
  }, [choice]);

  const next: Record<ThemeChoice, ThemeChoice> = { system: 'light', light: 'dark', dark: 'system' };
  const label: Record<ThemeChoice, string> = { system: 'Auto', light: 'Light', dark: 'Dark' };

  return (
    <button
      type="button"
      className="theme-toggle"
      onClick={() => {
        const value = next[choice];
        saveThemeChoice(value);
        setChoice(value);
      }}
      aria-label={`Colour theme: ${label[choice]}. Change theme`}
    >
      <span aria-hidden="true" className={`theme-toggle__icon theme-toggle__icon--${choice}`} />
      {label[choice]}
    </button>
  );
}

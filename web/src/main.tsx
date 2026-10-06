import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import App from './app/App';
import { applyTheme, readCachedPreset, readThemeChoice } from './app/theme';
import { loadPresetFonts } from './design/fonts';
import './design/tokens.css';
import './design/base.css';
import './design/components/components.css';

// Before the first paint: no light flash for dark-mode visitors, and a returning visitor's preset
// (and its fonts) applies at once instead of after the API answers.
applyTheme(readThemeChoice());
const cachedPreset = readCachedPreset();
if (cachedPreset) {
  document.documentElement.setAttribute('data-preset', cachedPreset);
  void loadPresetFonts(cachedPreset);
}

const root = document.getElementById('root');
if (!root) {
  throw new Error('Root element #root not found');
}

createRoot(root).render(
  <StrictMode>
    <App />
  </StrictMode>,
);

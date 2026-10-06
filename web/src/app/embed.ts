/** True when the booking pages run inside the embed.js iframe (or with ?embed=1). */
export function isEmbedded(): boolean {
  try {
    return window.self !== window.top || new URLSearchParams(window.location.search).has('embed');
  } catch {
    return true; // cross-origin parent
  }
}

export const RESIZE_MESSAGE = 'consultdesk:height';
export const CLOSE_MESSAGE = 'consultdesk:close';

/** The embedding page's origin, so messages go only there (falls back to "*" when unknown). */
function parentOrigin(): string {
  try {
    return document.referrer ? new URL(document.referrer).origin : '*';
  } catch {
    return '*';
  }
}

/**
 * Tells the embedding page how tall the content is (so the iframe never scrolls inside a scroll), and
 * asks it to close the modal on Escape, since key presses inside the iframe never reach the host page.
 * Messages carry only a height or a close request.
 */
export function connectToHost(): () => void {
  if (window.parent === window) return () => undefined;
  const target = parentOrigin();
  const send = () =>
    window.parent.postMessage(
      { type: RESIZE_MESSAGE, height: document.documentElement.scrollHeight },
      target,
    );
  const onKey = (event: KeyboardEvent) => {
    if (event.key === 'Escape') window.parent.postMessage({ type: CLOSE_MESSAGE }, target);
  };
  const observer = new ResizeObserver(send);
  observer.observe(document.body);
  document.addEventListener('keydown', onKey);
  document.documentElement.setAttribute('data-embedded', '');
  send();
  return () => {
    observer.disconnect();
    document.removeEventListener('keydown', onKey);
  };
}

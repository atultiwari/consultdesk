/** True when the booking pages run inside the embed.js iframe (or with ?embed=1). */
export function isEmbedded(): boolean {
  try {
    return window.self !== window.top || new URLSearchParams(window.location.search).has('embed');
  } catch {
    return true; // cross-origin parent
  }
}

export const RESIZE_MESSAGE = 'consultdesk:height';

/** Tells the embedding page how tall the content is, so the iframe never scrolls inside a scroll. */
export function reportHeight(): () => void {
  if (window.parent === window) return () => undefined;
  const send = () =>
    window.parent.postMessage(
      { type: RESIZE_MESSAGE, height: document.documentElement.scrollHeight },
      '*',
    );
  const observer = new ResizeObserver(send);
  observer.observe(document.body);
  send();
  return () => observer.disconnect();
}

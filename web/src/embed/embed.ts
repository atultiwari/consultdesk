// <script src="https://book.example.com/embed.js" data-provider="atul" data-service="research-guidance"
//         data-label="Book a session" async></script>
// Adds a "Book a session" button; clicking it opens the booking pages in an accessible modal iframe
// that resizes itself to its content. Plain DOM, no framework, so it stays tiny.

const SLUG = /^[a-z0-9-]{1,64}$/;
const HEIGHT_MESSAGE = 'consultdesk:height';
const CLOSE_MESSAGE = 'consultdesk:close';
const CSS =
  '.cdk-btn{font:600 16px/1.2 system-ui,sans-serif;padding:12px 20px;border:0;border-radius:999px;background:#40297a;color:#fff;cursor:pointer;min-height:44px}' +
  '.cdk-btn:focus-visible,.cdk-x:focus-visible{outline:3px solid #2f6bff;outline-offset:2px}' +
  '.cdk-ov{position:fixed;inset:0;z-index:2147483000;background:rgba(15,12,25,.55);display:flex;align-items:flex-start;justify-content:center;overflow:auto;padding:24px 12px}' +
  '.cdk-box{position:relative;width:100%;max-width:960px;background:#fff;border-radius:16px;box-shadow:0 24px 64px rgba(0,0,0,.3);animation:cdk-in .32s cubic-bezier(.22,1,.36,1)}' +
  '.cdk-box iframe{display:block;width:100%;min-height:560px;border:0;border-radius:16px}' +
  '.cdk-x{position:absolute;top:8px;right:8px;width:44px;height:44px;border:0;border-radius:50%;background:rgba(0,0,0,.06);font:24px/1 system-ui;cursor:pointer}' +
  '@keyframes cdk-in{from{opacity:0;transform:translateY(12px)}}@media (prefers-reduced-motion:reduce){.cdk-box{animation:none}}';

function addStyles(): void {
  if (document.head.querySelector('style[data-consultdesk]')) return;
  const style = document.createElement('style');
  style.setAttribute('data-consultdesk', '');
  style.textContent = CSS;
  document.head.append(style);
}

function bookingUrl(origin: string, provider: string | null, service: string | null): string {
  const path = provider ? `/p/${provider}${service ? `/${service}` : ''}` : '/';
  return `${origin}${path}?embed=1`;
}

function open(url: string, origin: string, label: string, opener: HTMLElement): void {
  const overlay = document.createElement('div');
  overlay.className = 'cdk-ov';
  const box = document.createElement('div');
  box.className = 'cdk-box';
  box.setAttribute('role', 'dialog');
  box.setAttribute('aria-modal', 'true');
  box.setAttribute('aria-label', label);
  const close = document.createElement('button');
  close.className = 'cdk-x';
  close.type = 'button';
  close.setAttribute('aria-label', 'Close booking');
  close.textContent = '×';
  const frame = document.createElement('iframe');
  frame.src = url;
  frame.title = label;
  frame.setAttribute('allow', 'clipboard-write');
  box.append(close, frame);
  overlay.append(box);

  const previousOverflow = document.body.style.overflow;
  // Everything else on the host page is made inert while the dialog is open, so Tab cannot leave it.
  const background = [...document.body.children].filter((el) => !el.hasAttribute('inert'));
  const onMessage = (event: MessageEvent) => {
    if (event.origin !== origin || event.source !== frame.contentWindow) return;
    const data = event.data as { type?: unknown; height?: unknown } | null;
    if (data?.type === CLOSE_MESSAGE) dismiss();
    if (data?.type === HEIGHT_MESSAGE && typeof data.height === 'number') {
      frame.style.height = `${Math.max(400, Math.min(Math.round(data.height), 20000))}px`;
    }
  };
  // Escape inside the iframe arrives as a CLOSE_MESSAGE; this catches it on the host page.
  const onKey = (event: KeyboardEvent) => {
    if (event.key === 'Escape') dismiss();
  };
  function dismiss(): void {
    window.removeEventListener('message', onMessage);
    document.removeEventListener('keydown', onKey);
    background.forEach((el) => el.removeAttribute('inert'));
    overlay.remove();
    document.body.style.overflow = previousOverflow;
    opener.focus();
  }

  close.addEventListener('click', dismiss);
  overlay.addEventListener('click', (event) => {
    if (event.target === overlay) dismiss();
  });
  window.addEventListener('message', onMessage);
  document.addEventListener('keydown', onKey);
  document.body.style.overflow = 'hidden';
  background.forEach((el) => el.setAttribute('inert', ''));
  document.body.append(overlay);
  close.focus();
}

export function init(script: HTMLScriptElement): void {
  const provider = script.getAttribute('data-provider');
  const service = script.getAttribute('data-service');
  if ((provider !== null && !SLUG.test(provider)) || (service !== null && !SLUG.test(service)))
    return;

  const origin = new URL(script.src).origin;
  const label = script.getAttribute('data-label') || 'Book a session';
  addStyles();

  const button = document.createElement('button');
  button.type = 'button';
  button.className = 'cdk-btn';
  button.textContent = label;
  button.addEventListener('click', () =>
    open(bookingUrl(origin, provider, service), origin, label, button),
  );
  script.after(button);
}

if (document.currentScript instanceof HTMLScriptElement) {
  init(document.currentScript);
}

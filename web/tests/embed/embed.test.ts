import { init } from '../../src/embed/embed';

function script(attrs: Record<string, string>): HTMLScriptElement {
  const el = document.createElement('script');
  el.src = 'https://book.example.test/embed.js';
  for (const [k, v] of Object.entries(attrs)) el.setAttribute(k, v);
  document.body.append(el);
  return el;
}

function message(origin: string, data: unknown) {
  window.dispatchEvent(new MessageEvent('message', { origin, data }));
}

afterEach(() => {
  document.body.innerHTML = '';
  document.head.querySelectorAll('style[data-consultdesk]').forEach((s) => s.remove());
  document.body.style.overflow = '';
});

describe('embed', () => {
  it('adds a button after the script tag with the given label', () => {
    const el = script({
      'data-provider': 'atul',
      'data-service': 'research-guidance',
      'data-label': 'Book research guidance',
    });
    init(el);

    const button = el.nextElementSibling as HTMLButtonElement;
    expect(button.tagName).toBe('BUTTON');
    expect(button.textContent).toBe('Book research guidance');
    expect(document.head.querySelectorAll('style[data-consultdesk]')).toHaveLength(1);
  });

  it('opens an accessible dialog with the booking page and closes on Escape, restoring focus', () => {
    const el = script({ 'data-provider': 'atul', 'data-service': 'research-guidance' });
    init(el);
    const button = el.nextElementSibling as HTMLButtonElement;
    button.focus();
    button.click();

    const dialog = document.querySelector('[role="dialog"]') as HTMLElement;
    expect(dialog.getAttribute('aria-modal')).toBe('true');
    const iframe = dialog.querySelector('iframe') as HTMLIFrameElement;
    expect(iframe.src).toBe('https://book.example.test/p/atul/research-guidance?embed=1');
    expect(iframe.title).toBe('Book a session');
    expect(document.activeElement?.getAttribute('aria-label')).toBe('Close booking');
    expect(document.body.style.overflow).toBe('hidden');

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    expect(document.querySelector('[role="dialog"]')).toBeNull();
    expect(document.activeElement).toBe(button);
    expect(document.body.style.overflow).toBe('');
  });

  it('only resizes for messages from the booking site', () => {
    const el = script({ 'data-provider': 'atul' });
    init(el);
    (el.nextElementSibling as HTMLButtonElement).click();
    const iframe = document.querySelector('iframe') as HTMLIFrameElement;
    expect(iframe.src).toBe('https://book.example.test/p/atul?embed=1');

    message('https://evil.example', { type: 'consultdesk:height', height: 9999 });
    expect(iframe.style.height).toBe('');

    message('https://book.example.test', { type: 'consultdesk:height', height: 812 });
    expect(iframe.style.height).toBe('812px');

    message('https://book.example.test', { type: 'consultdesk:height', height: 'tall' });
    expect(iframe.style.height).toBe('812px');
  });

  it('defaults to the provider list and refuses unsafe slugs', () => {
    const plain = script({});
    init(plain);
    (plain.nextElementSibling as HTMLButtonElement).click();
    expect((document.querySelector('iframe') as HTMLIFrameElement).src).toBe(
      'https://book.example.test/?embed=1',
    );

    document.body.innerHTML = '';
    const bad = script({ 'data-provider': '../admin' });
    init(bad);
    expect(bad.nextElementSibling).toBeNull();
  });
});

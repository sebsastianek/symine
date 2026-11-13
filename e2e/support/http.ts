/**
 * CSRF-aware HTTP helpers sharing the browser session (cookies of page.context()).
 *
 * Use them to *arrange* state quickly or to hit endpoints the UI does not expose (e.g. asserting a 403/422);
 * user-visible flows should go through the UI.
 *
 *   const http = new SessionHttp(page);
 *   await http.post('/projects/ecommerce/news', { 'news[title]': 'x', 'news[description]': 'y' });
 */
import { expect, type APIResponse, type Page } from '@playwright/test';

export class SessionHttp {
  private token?: { param: string; value: string };

  constructor(readonly page: Page) {}

  /** Reads the csrf-param/csrf-token meta tags of a page (Symine: _token, Redmine: authenticity_token). */
  async csrf(fromPath = '/my/page'): Promise<{ param: string; value: string }> {
    if (this.token) return this.token;
    const res = await this.page.request.get(fromPath);
    const html = await res.text();
    const param = html.match(/<meta name="csrf-param" content="([^"]+)"/)?.[1];
    const value = html.match(/<meta name="csrf-token" content="([^"]+)"/)?.[1];
    if (!param || !value) throw new Error(`No CSRF meta tags on ${fromPath} (status ${res.status()})`);
    this.token = { param, value };
    return this.token;
  }

  get(path: string): Promise<APIResponse> {
    return this.page.request.get(path, { maxRedirects: 0 });
  }

  /** Form POST (application/x-www-form-urlencoded) with the CSRF token; `_method` may emulate PATCH/PUT/DELETE. */
  async post(path: string, form: Record<string, string | number | boolean> = {}, method?: 'patch' | 'put' | 'delete'): Promise<APIResponse> {
    const { param, value } = await this.csrf();
    const data: Record<string, string> = { [param]: value };
    for (const [k, v] of Object.entries(form)) data[k] = String(v);
    if (method) data._method = method;
    return this.page.request.post(path, { form: data, maxRedirects: 0 });
  }

  /** XHR-style request (X-CSRF-Token header + X-Requested-With), like rails-ujs remote links. */
  async xhr(method: 'get' | 'post' | 'patch' | 'put' | 'delete', path: string, form?: Record<string, string>): Promise<APIResponse> {
    const { value } = await this.csrf();
    return this.page.request.fetch(path, {
      method: method.toUpperCase(),
      form,
      headers: { 'X-CSRF-Token': value, 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/javascript, application/javascript, */*' },
      maxRedirects: 0,
    });
  }

  async expectStatus(path: string, status: number): Promise<void> {
    const res = await this.get(path);
    expect(res.status(), `GET ${path}`).toBe(status);
  }
}

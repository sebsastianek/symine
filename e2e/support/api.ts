/**
 * REST API helpers authenticated with the users' API keys (created by global setup, REST API enabled).
 *
 *   const api = await apiAs('jsmith');
 *   const issue = await api.createIssue({ project_id: 1, subject: 'From API' });
 *   await api.dispose();
 */
import { expect, request, type APIRequestContext } from '@playwright/test';
import * as fs from 'fs';
import { API_KEYS_FILE, BASE_URL, type UserLogin } from './env';

export function apiKey(login: UserLogin): string {
  const keys = JSON.parse(fs.readFileSync(API_KEYS_FILE(), 'utf8')) as Record<string, string>;
  if (!keys[login]) throw new Error(`No API key for ${login}`);
  return keys[login];
}

export class RedmineApi {
  constructor(readonly ctx: APIRequestContext) {}

  async get<T = any>(path: string): Promise<T> {
    const res = await this.ctx.get(path);
    expect(res.status(), `GET ${path}: ${await res.text().catch(() => '')}`).toBe(200);
    return (await res.json()) as T;
  }

  async post<T = any>(path: string, body: unknown, expected = 201): Promise<T> {
    const res = await this.ctx.post(path, { data: body });
    expect(res.status(), `POST ${path}: ${await res.text().catch(() => '')}`).toBe(expected);
    const text = await res.text();
    return (text ? JSON.parse(text) : {}) as T;
  }

  async put(path: string, body: unknown): Promise<void> {
    const res = await this.ctx.put(path, { data: body });
    expect(res.status(), `PUT ${path}: ${await res.text().catch(() => '')}`).toBe(204);
  }

  async delete(path: string): Promise<void> {
    const res = await this.ctx.delete(path);
    expect(res.status(), `DELETE ${path}`).toBe(204);
  }

  async createIssue(fields: Record<string, unknown>): Promise<{ id: number; subject: string; [k: string]: any }> {
    const { issue } = await this.post('/issues.json', { issue: fields });
    return issue;
  }

  async issue(id: number, include = ''): Promise<any> {
    return (await this.get(`/issues/${id}.json${include ? `?include=${include}` : ''}`)).issue;
  }

  async updateIssue(id: number, fields: Record<string, unknown>): Promise<void> {
    await this.put(`/issues/${id}.json`, { issue: fields });
  }

  async createProject(fields: Record<string, unknown>): Promise<any> {
    return (await this.post('/projects.json', { project: fields })).project;
  }

  async createWikiPage(project: string, title: string, text: string): Promise<void> {
    const res = await this.ctx.put(`/projects/${project}/wiki/${encodeURIComponent(title)}.json`, { data: { wiki_page: { text } } });
    expect([201, 204], `PUT wiki ${title}`).toContain(res.status());
  }

  async logTime(fields: Record<string, unknown>): Promise<any> {
    return (await this.post('/time_entries.json', { time_entry: fields })).time_entry;
  }

  dispose(): Promise<void> {
    return this.ctx.dispose();
  }
}

/** New API context authenticated as `login` (X-Redmine-API-Key). Remember to dispose() it (the fixture does). */
export async function apiAs(login: UserLogin): Promise<RedmineApi> {
  const ctx = await request.newContext({
    baseURL: BASE_URL,
    extraHTTPHeaders: { 'X-Redmine-API-Key': apiKey(login), Accept: 'application/json' },
  });
  return new RedmineApi(ctx);
}

/** Anonymous API context (no key). */
export async function apiAnonymous(): Promise<RedmineApi> {
  return new RedmineApi(await request.newContext({ baseURL: BASE_URL, extraHTTPHeaders: { Accept: 'application/json' } }));
}

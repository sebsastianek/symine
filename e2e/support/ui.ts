/**
 * Page helpers for Redmine's common UI. The markup is Redmine's, so the same selectors work on Symine and on the
 * reference Redmine (http://localhost:3300).
 */
import { expect, type Locator, type Page } from '@playwright/test';

export class RedmineUI {
  constructor(readonly page: Page) {}

  // ---------------------------------------------------------------- layout / menus
  /** Top menu (#top-menu): home, my_page, projects, administration, help, my_account, logout... */
  topMenu(cssClass: string): Locator {
    return this.page.locator(`#top-menu a.${cssClass}`);
  }

  /** Main (project/application) menu item by its Redmine css class, e.g. 'issues', 'new-issue', 'settings', 'wiki'. */
  mainMenu(cssClass: string): Locator {
    return this.page.locator(`#main-menu a.${cssClass}`);
  }

  async openMainMenu(cssClass: string): Promise<void> {
    await this.mainMenu(cssClass).click();
    await expect(this.mainMenu(cssClass)).toHaveClass(/selected/);
  }

  /** Admin menu (#admin-menu) item, e.g. 'users', 'roles', 'trackers', 'settings'. */
  adminMenu(cssClass: string): Locator {
    return this.page.locator(`#sidebar #admin-menu a.${cssClass}`);
  }

  /** Contextual links (div.contextual) at the top of the content. */
  contextual(name: string | RegExp): Locator {
    return this.page.locator('#content > .contextual, #content .contextual').getByRole('link', { name }).first();
  }

  /**
   * Opens an "Actions" (...) dropdown (Stimulus controller, loaded async as an ES module: a click right after the
   * navigation can be lost, so retry). `scope` defaults to the first one of the content's contextual links
   * (e.g. pass a journal locator for note actions). Returns the visible dropdown content.
   */
  async openActionsMenu(scope?: Locator): Promise<Locator> {
    const root = scope ?? this.page.locator('#content > .contextual').first();
    const trigger = root.locator('.dropdown-trigger').first();
    const menu = root.locator('.dropdown-content').first();
    await expect(async () => {
      if (!(await menu.isVisible())) await trigger.click();
      await expect(menu).toBeVisible({ timeout: 1_000 });
    }).toPass({ timeout: 10_000 });
    return menu;
  }

  heading(): Locator {
    return this.page.locator('#content h2').first();
  }

  /** "@login" of the logged-in user (inside the account dropdown, hidden until opened: use toHaveText). */
  loggedAs(): Locator {
    return this.page.locator('#account .user-login');
  }

  /** Opens the account dropdown (avatar, top right) and returns its menu. */
  async openAccountMenu(): Promise<Locator> {
    return this.openActionsMenu(this.page.locator('#account'));
  }

  // ---------------------------------------------------------------- flash / errors
  flashNotice(): Locator {
    return this.page.locator('#flash_notice');
  }

  flashError(): Locator {
    return this.page.locator('#flash_error');
  }

  /** Validation errors box (#errorExplanation). */
  errors(): Locator {
    return this.page.locator('#errorExplanation');
  }

  async expectNotice(text: string | RegExp = /./): Promise<void> {
    await expect(this.flashNotice()).toContainText(text);
  }

  async expectError(text: string | RegExp = /./): Promise<void> {
    await expect(this.page.locator('#flash_error, #errorExplanation').first()).toContainText(text);
  }

  // ---------------------------------------------------------------- dialogs
  /** jQuery UI modal opened by Redmine's showModal() (#ajax-modal). */
  modal(): Locator {
    return this.page.locator('#ajax-modal');
  }

  async expectModal(title?: string | RegExp): Promise<Locator> {
    const modal = this.modal();
    await expect(modal).toBeVisible();
    if (title) await expect(this.page.locator('.ui-dialog-title').last()).toHaveText(title);
    return modal;
  }

  /** Accepts the next window.confirm() (data-confirm links); the fixtures accept dialogs by default anyway. */
  acceptNextDialog(): void {
    this.page.once('dialog', (d) => void d.accept());
  }

  // ---------------------------------------------------------------- context menu
  /**
   * Right-clicks a list row (tr.hascontextmenu) and returns the #context-menu. Redmine ignores right-clicks on links
   * and inputs, so the first cell without a link/input is used.
   */
  async openContextMenu(row: Locator): Promise<Locator> {
    const index = await row.locator('td').evaluateAll((tds) =>
      tds.findIndex((td) => !td.classList.contains('checkbox') && !td.querySelector('a, input, select') && (td.textContent || '').trim() !== ''),
    );
    await row.locator('td').nth(Math.max(index, 0)).click({ button: 'right' });
    const menu = this.page.locator('#context-menu');
    await expect(menu).toBeVisible();
    return menu;
  }

  /** Hovers a folder (submenu) of the context menu, e.g. 'Status', then clicks the item. */
  async contextMenuPick(folder: string | RegExp, item: string | RegExp): Promise<void> {
    const menu = this.page.locator('#context-menu');
    const li = menu.locator('li.folder').filter({ has: this.page.locator('> a', { hasText: folder }) });
    await li.hover();
    await li.locator('ul a', { hasText: item }).first().click();
  }

  /** Issue (or time entry...) rows of the current list. */
  listRows(): Locator {
    return this.page.locator('table.list tbody tr.hascontextmenu');
  }

  issueRow(id: number): Locator {
    return this.page.locator(`tr#issue-${id}`);
  }

  // ---------------------------------------------------------------- query filters form
  filtersFieldset(): Locator {
    return this.page.locator('#filters');
  }

  /** Expands a collapsed fieldset of the query form ('Filters' | 'Options'). */
  async expandFieldset(legend: string | RegExp): Promise<void> {
    const fs = this.page.locator('fieldset.collapsible').filter({ has: this.page.locator('> legend', { hasText: legend }) }).first();
    if (await fs.evaluate((el) => el.classList.contains('collapsed'))) await fs.locator('> legend').click();
    await expect(fs).not.toHaveClass(/collapsed/);
  }

  /** Adds a filter through the "Add filter" select (value is the filter name, e.g. 'tracker_id', 'cf_1'). */
  async addFilter(field: string): Promise<void> {
    await this.page.locator('#add_filter_select').selectOption(field);
    await expect(this.page.locator(`#tr_${field}`)).toBeVisible();
  }

  async setFilter(field: string, operator: string, values: string[] = []): Promise<void> {
    if (!(await this.page.locator(`#cb_${field}`).isChecked().catch(() => false))) await this.addFilter(field);
    await this.page.locator(`#operators_${field}`).selectOption(operator);
    if (values.length === 0) return;
    // list/date/number filters use #values_<field>_1 (and _2), string/text filters a single #values_<field>
    const select = this.page.locator(`#values_${field}_1, #values_${field}`).first();
    const tag = await select.evaluate((el) => el.tagName.toLowerCase());
    if (tag === 'select') {
      if (values.length > 1) {
        // multiple values need the multi-select toggle
        const multiple = await select.evaluate((el) => (el as HTMLSelectElement).multiple);
        if (!multiple) await this.page.locator(`#tr_${field} .toggle-multiselect`).click();
      }
      await select.selectOption(values);
    } else {
      await select.fill(values[0]);
      if (values[1] !== undefined) await this.page.locator(`#values_${field}_2`).fill(values[1]);
    }
  }

  /** Clicks "Apply" of the query form and waits for the reloaded list (works for repeated applies too). */
  async applyFilters(): Promise<void> {
    const apply = this.page.locator('#query_form p.buttons a.icon-checked').first();
    await Promise.all([
      this.page.waitForResponse((r) => r.request().isNavigationRequest() && r.request().method() === 'GET' && /[?&]set_filter=1/.test(r.url())),
      apply.click(),
    ]);
    await this.page.waitForLoadState('domcontentloaded');
  }

  // ---------------------------------------------------------------- attachments (attachments.js)
  /**
   * Selects a file in an attachments widget (input.file_selector) and waits until attachments.js finished the ajax
   * upload (the hidden token field is filled). `scope` defaults to the page; pass a form to disambiguate.
   */
  async attachFile(file: { name: string; mimeType?: string; buffer: Buffer | string }, scope?: Locator): Promise<void> {
    const root = scope ?? this.page.locator('body');
    const before = await root.locator('.attachments_fields input.token').count();
    await root.locator('input[type=file].file_selector').first().setInputFiles({
      name: file.name,
      mimeType: file.mimeType ?? 'text/plain',
      buffer: typeof file.buffer === 'string' ? Buffer.from(file.buffer) : file.buffer,
    });
    const token = root.locator('.attachments_fields input.token').nth(before);
    await expect(token).toHaveValue(/\S+/);
    // upload finished on the client side too (progress bar removed, submit buttons enabled again)
    await expect(token.locator('xpath=..')).not.toHaveClass(/ajax-(loading|waiting)/);
    await expect(token.locator('xpath=..').locator('.ui-progressbar')).toHaveCount(0);
  }

  // ---------------------------------------------------------------- autocomplete / misc
  /** Picks an entry of a jQuery UI autocomplete list after typing into `input`. */
  async autocomplete(input: Locator, text: string, pick: string | RegExp): Promise<void> {
    await input.fill('');
    await input.pressSequentially(text, { delay: 30 });
    const item = this.page.locator('ul.ui-autocomplete:visible li', { hasText: pick }).first();
    await item.click();
  }

  /** Clicks a project in the project jump box (#project-jump). */
  async jumpToProject(name: string): Promise<void> {
    await this.page.locator('#project-jump .drdn-trigger').click();
    const search = this.page.locator('#project-jump input.autocomplete');
    await search.fill(name);
    await this.page.locator('#project-jump .drdn-items a', { hasText: name }).first().click();
  }

  /** Quick search box of the header (#q). */
  async quickSearch(q: string): Promise<void> {
    await this.page.locator('#quick-search #q').fill(q);
    await this.page.locator('#quick-search #q').press('Enter');
  }

  /** Toggles the collapsible sidebar (Redmine 6+). */
  sidebarToggle(): Locator {
    return this.page.locator('#sidebar-switch-button');
  }
}

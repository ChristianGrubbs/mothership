const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.BASE_URL || 'http://127.0.0.1:8000';

/**
 * A typed password must never be readable from the serialized DOM.
 *
 * Vue 3.5 mirrors a bound input's live `value` into the DOM attribute on
 * every patch, so without a guard the plaintext password appears in
 * outerHTML — readable by extensions, "save page", and any DOM-capturing
 * error reporter, whichever state the visibility toggle is in. The
 * `v-no-value-attr` directive strips that reflection for password controls.
 * Reset-password controls are not covered here: no reset-token fixture exists.
 */
test.describe('Security: password value stays out of the DOM attribute', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  for (const viewport of [{ width: 1280, height: 720 }, { width: 375, height: 812 }]) {
    test.describe(`${viewport.width}x${viewport.height}`, () => {
      test.use({ viewport });

      for (const form of [
        { route: 'login', fields: [{ name: 'password', toggle: 'visibilityIcon' }] },
      ]) {
        test(`${form.route} password stays private through keyboard and pointer toggles`, async ({ page }) => {
          const response = await page.goto(`${BASE_URL}/admin/${form.route}`);

          expect(response?.status(), `${form.route} page must return HTTP 200`).toBe(200);
          await expect(page).toHaveURL(`${BASE_URL}/admin/${form.route}`);

          // Fill every password control so toggling one also checks its sibling's privacy.
          for (const field of form.fields) {
            await page.locator(`input[name="${field.name}"]`).fill('Aa1!');
          }

          const expectPrivateValues = async () => {
            for (const field of form.fields) {
              const input = page.locator(`input[name="${field.name}"]`);

              await expect(input).toHaveValue('Aa1!');
              await expect(input).not.toHaveAttribute('value', /./);
            }
          };

          await expectPrivateValues();

          for (const field of form.fields) {
            const input = page.locator(`input[name="${field.name}"]`);
            const toggle = page.locator(`#${field.toggle}`);

            await expect(toggle).toHaveRole('button');
            await expect(toggle).toHaveAccessibleName(/\S/);
            await expect(toggle).toHaveAttribute('type', 'button');
            await expect(toggle).toHaveAttribute('aria-controls', field.name);
            await expect(toggle).toHaveAttribute('aria-pressed', 'false');
            await expect(toggle).toHaveClass(/\bicon-view\b/);
            await expect(toggle).not.toHaveClass(/\bicon-view-close\b/);
            await expect(input).toHaveAttribute('type', 'password');

            await input.focus();
            await page.keyboard.press('Tab');
            await expect(toggle).toBeFocused();

            // Exercise both keys in both directions, then retain pointer coverage.
            for (const [key, shown] of [
              ['Enter', true], ['Space', false], ['Space', true], ['Enter', false],
              [null, true], [null, false],
            ]) {
              await test.step(`${field.name}: ${key || 'pointer'} ${shown ? 'reveals' : 'masks'}`, async () => {
                if (key) {
                  await page.keyboard.press(key);
                } else {
                  await toggle.click();
                }

                await expect(input).toHaveAttribute('type', shown ? 'text' : 'password');
                await expect(toggle).toHaveAttribute('aria-pressed', String(shown));
                await expect(toggle).toHaveClass(shown ? /\bicon-view-close\b/ : /\bicon-view\b/);
                await expect(toggle).not.toHaveClass(shown ? /\bicon-view(?=\s|$)/ : /\bicon-view-close\b/);
                await expect(toggle).toBeFocused();
                await expect(page).toHaveURL(`${BASE_URL}/admin/${form.route}`);
                await expectPrivateValues();
              });
            }
          }
        });
      }
    });
  }
});

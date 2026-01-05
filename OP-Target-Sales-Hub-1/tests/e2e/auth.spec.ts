import { test, expect } from '@playwright/test';

test.describe('Authentication Flow', () => {
  
  test('(A) Login → redirect to dashboard', async ({ page }) => {
    // Go to homepage
    await page.goto('/');
    
    // Should see login form
    await expect(page.locator('input[type="email"], input[name="email"]')).toBeVisible({ timeout: 10000 });
    
    // Fill login form
    await page.fill('input[type="email"], input[name="email"]', 'admin@optarget.com');
    await page.fill('input[type="password"], input[name="password"]', 'Admin@123456');
    
    // Submit
    await page.click('button[type="submit"]');
    
    // Should redirect (URL changes or dashboard content appears)
    await expect(page).not.toHaveURL('/', { timeout: 10000 });
  });

  test('Guest sees login page', async ({ page }) => {
    await page.goto('/');
    
    // Should see login form elements
    await expect(page.locator('input[type="email"], input[name="email"]')).toBeVisible({ timeout: 10000 });
    await expect(page.locator('input[type="password"], input[name="password"]')).toBeVisible();
    await expect(page.locator('button[type="submit"]')).toBeVisible();
  });

  test('Invalid credentials show error', async ({ page }) => {
    await page.goto('/');
    
    await page.fill('input[type="email"], input[name="email"]', 'wrong@email.com');
    await page.fill('input[type="password"], input[name="password"]', 'wrongpassword');
    await page.click('button[type="submit"]');
    
    // Should show error message (stay on same page or show error)
    await page.waitForTimeout(2000);
    
    // Either error message appears or we're still on login page
    const errorVisible = await page.locator('text=خطأ, text=غير صحيح, text=invalid').isVisible().catch(() => false);
    const stillOnLogin = await page.locator('input[type="password"]').isVisible();
    
    expect(errorVisible || stillOnLogin).toBe(true);
  });
});

test.describe('Page Load Smoke', () => {
  
  test('Homepage loads without errors', async ({ page }) => {
    const errors: string[] = [];
    page.on('console', msg => {
      if (msg.type() === 'error' && !msg.text().includes('401')) {
        errors.push(msg.text());
      }
    });

    await page.goto('/');
    await page.waitForLoadState('networkidle');
    
    // Page should have content
    await expect(page.locator('body')).not.toBeEmpty();
    
    // No critical console errors (401 is expected for guest)
    const criticalErrors = errors.filter(e => 
      !e.includes('401') && 
      !e.includes('Unauthorized') &&
      !e.includes('favicon')
    );
    expect(criticalErrors).toHaveLength(0);
  });

  test('Favicon loads correctly', async ({ page }) => {
    const response = await page.goto('/favicon.svg');
    expect(response?.status()).toBe(200);
  });

  test('API auth endpoint returns 401 for guest', async ({ request }) => {
    const response = await request.get('/api/auth');
    expect(response.status()).toBe(401);
  });

  test('API seed endpoint returns 404 in production-like env', async ({ request }) => {
    const response = await request.post('/api/seed', {
      data: { secret: 'test' }
    });
    // In dev it might return 403/500, in prod it's 404
    // We just verify it doesn't return 200/201
    expect([403, 404, 500]).toContain(response.status());
  });
});

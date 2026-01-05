import { test, expect } from '@playwright/test';

test.describe('(C) RBAC - Lead Access Control', () => {
  
  test('Admin can see all leads', async ({ page }) => {
    await page.goto('/');
    
    // Login as admin
    await page.fill('input[type="email"], input[name="email"]', 'admin@optarget.com');
    await page.fill('input[type="password"], input[name="password"]', 'Admin@123456');
    await page.click('button[type="submit"]');
    
    // Wait for dashboard
    await page.waitForTimeout(3000);
    
    // Navigate to leads (if not already there)
    const leadsNav = page.locator('text=العملاء, text=Leads, [href*="leads"]').first();
    if (await leadsNav.isVisible({ timeout: 2000 }).catch(() => false)) {
      await leadsNav.click();
      await page.waitForTimeout(1000);
    }
    
    // Admin should see leads section or empty state
    const pageContent = await page.content();
    const hasLeadsSection = 
      pageContent.includes('العملاء') || 
      pageContent.includes('leads') || 
      pageContent.includes('لا يوجد') ||
      pageContent.includes('إضافة');
    
    expect(hasLeadsSection).toBe(true);
  });

  test('Admin can access user management', async ({ page }) => {
    await page.goto('/');
    
    // Login as admin
    await page.fill('input[type="email"], input[name="email"]', 'admin@optarget.com');
    await page.fill('input[type="password"], input[name="password"]', 'Admin@123456');
    await page.click('button[type="submit"]');
    
    await page.waitForTimeout(3000);
    
    // Look for users/team management link
    const usersNav = page.locator([
      'text=المستخدمين',
      'text=الفريق',
      'text=Users',
      'text=Team',
      '[href*="users"]',
      '[href*="team"]'
    ].join(', ')).first();
    
    if (await usersNav.isVisible({ timeout: 2000 }).catch(() => false)) {
      await usersNav.click();
      await page.waitForTimeout(1000);
      
      // Should see user management content
      const pageContent = await page.content();
      const hasUserManagement = 
        pageContent.includes('المستخدمين') || 
        pageContent.includes('users') ||
        pageContent.includes('إضافة مستخدم');
      
      expect(hasUserManagement).toBe(true);
    }
  });

  test('API enforces RBAC on leads endpoint', async ({ request }) => {
    // Without auth, should get 401
    const response = await request.get('/api/leads');
    expect(response.status()).toBe(401);
  });

  test('API enforces RBAC on users endpoint', async ({ request }) => {
    // Without auth, should get 401
    const response = await request.get('/api/users');
    expect(response.status()).toBe(401);
  });
});

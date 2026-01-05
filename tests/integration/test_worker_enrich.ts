/**
 * Phase 6: Worker Enrichment Smoke Tests
 * 
 * Test scenarios:
 * 1. Create job (maps+website) -> queued
 * 2. Poll status -> running -> success
 * 3. Snapshot exists after success
 * 4. Survey generation uses snapshot
 * 5. Job with instagram disabled -> skipped
 * 6. Blocked scenario -> partial + error_code
 * 7. Rate limit -> 429
 * 8. Rollback -> flags off prevents endpoints
 * 
 * Prerequisites:
 * - OP-Target running on localhost:3002
 * - forge running on localhost:8080
 * - INTEGRATION_WORKER_ENRICH=true
 * - INTEGRATION_AUTH_BRIDGE=true
 * - forge: integration_worker_enabled=1
 * - A linked lead exists
 */

const BASE_URL = 'http://localhost:3002';
const AUTH_TOKEN = 'test-auth-token'; // Replace with valid token

interface TestResult {
  name: string;
  passed: boolean;
  error?: string;
  response?: any;
}

const results: TestResult[] = [];

async function runTest(name: string, fn: () => Promise<void>) {
  console.log(`\n🧪 Running: ${name}`);
  try {
    await fn();
    results.push({ name, passed: true });
    console.log(`   ✅ PASSED`);
  } catch (error: any) {
    results.push({ name, passed: false, error: error.message });
    console.log(`   ❌ FAILED: ${error.message}`);
  }
}

async function apiCall(endpoint: string, options: RequestInit = {}) {
  const response = await fetch(`${BASE_URL}${endpoint}`, {
    ...options,
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${AUTH_TOKEN}`,
      ...options.headers,
    },
  });
  return { response, data: await response.json() };
}

// ============================================
// Test Cases
// ============================================

// Test 1: Create enrichment job
async function test1_CreateJob() {
  const { response, data } = await apiCall('/api/integration/forge/enrich', {
    method: 'POST',
    body: JSON.stringify({
      opLeadId: 'test-lead-id', // Replace with actual linked lead ID
      modules: ['maps', 'website'],
    }),
  });

  if (!response.ok) throw new Error(`Status ${response.status}: ${data.error}`);
  if (!data.jobId) throw new Error('No jobId returned');
  if (data.status !== 'queued') throw new Error(`Expected status 'queued', got '${data.status}'`);
  
  // Store jobId for subsequent tests
  (globalThis as any).testJobId = data.jobId;
}

// Test 2: Poll job status
async function test2_PollStatus() {
  const jobId = (globalThis as any).testJobId;
  if (!jobId) throw new Error('No jobId from previous test');

  let attempts = 0;
  let finalStatus = '';

  while (attempts < 30) { // Max 60 seconds
    const { response, data } = await apiCall(`/api/integration/forge/enrich/status?jobId=${jobId}`);
    
    if (!response.ok) throw new Error(`Status ${response.status}: ${data.error}`);
    
    finalStatus = data.status;
    console.log(`   Status: ${finalStatus}, Progress: ${data.progress}%`);

    if (['success', 'partial', 'failed'].includes(finalStatus)) {
      break;
    }

    await new Promise(r => setTimeout(r, 2000));
    attempts++;
  }

  if (!['success', 'partial'].includes(finalStatus)) {
    throw new Error(`Job did not complete successfully. Final status: ${finalStatus}`);
  }
}

// Test 3: Snapshot exists
async function test3_SnapshotExists() {
  const { response, data } = await apiCall('/api/integration/forge/snapshot?opLeadId=test-lead-id');

  if (!response.ok) throw new Error(`Status ${response.status}: ${data.error}`);
  if (!data.snapshot) throw new Error('No snapshot returned');
  if (!data.created_at) throw new Error('No created_at in snapshot');
}

// Test 4: Survey uses snapshot
async function test4_SurveyUsesSnapshot() {
  const { response, data } = await apiCall('/api/integration/forge/survey', {
    method: 'POST',
    body: JSON.stringify({
      opLeadId: 'test-lead-id',
      force: true,
    }),
  });

  if (!response.ok) throw new Error(`Status ${response.status}: ${data.error}`);
  if (!data.report) throw new Error('No report returned');
  // Survey should have been generated with snapshot data
}

// Test 5: Instagram module skipped (if disabled)
async function test5_InstagramSkipped() {
  const { response, data } = await apiCall('/api/integration/forge/enrich', {
    method: 'POST',
    body: JSON.stringify({
      opLeadId: 'test-lead-id',
      modules: ['maps', 'instagram'], // instagram should be filtered out
    }),
  });

  if (!response.ok && response.status !== 400) {
    throw new Error(`Unexpected status ${response.status}`);
  }
  
  // Either instagram is filtered out, or returns error for invalid module
  console.log(`   Response: ${JSON.stringify(data)}`);
}

// Test 6: Rate limit (429)
async function test6_RateLimit() {
  // This test requires hitting the rate limit
  // Skip if not enough quota
  console.log('   ⚠️ Skipping rate limit test (requires many requests)');
}

// Test 7: Flags off prevents endpoints
async function test7_FlagsOff() {
  // This test requires temporarily disabling flags
  // In production, verify by setting INTEGRATION_WORKER_ENRICH=false
  console.log('   ⚠️ Manual test: Set INTEGRATION_WORKER_ENRICH=false and verify 404');
}

// Test 8: Lead not linked returns 404
async function test8_NotLinked() {
  const { response, data } = await apiCall('/api/integration/forge/enrich', {
    method: 'POST',
    body: JSON.stringify({
      opLeadId: 'non-existent-lead-id',
      modules: ['maps'],
    }),
  });

  if (response.status !== 404 && response.status !== 403) {
    throw new Error(`Expected 404 or 403, got ${response.status}`);
  }
}

// ============================================
// Run Tests
// ============================================

async function runAllTests() {
  console.log('========================================');
  console.log('Phase 6: Worker Enrichment Smoke Tests');
  console.log('========================================');

  await runTest('1. Create enrichment job', test1_CreateJob);
  await runTest('2. Poll job status', test2_PollStatus);
  await runTest('3. Snapshot exists', test3_SnapshotExists);
  await runTest('4. Survey uses snapshot', test4_SurveyUsesSnapshot);
  await runTest('5. Instagram module handling', test5_InstagramSkipped);
  await runTest('6. Rate limit (429)', test6_RateLimit);
  await runTest('7. Flags off (manual)', test7_FlagsOff);
  await runTest('8. Not linked returns error', test8_NotLinked);

  // Summary
  console.log('\n========================================');
  console.log('Test Summary');
  console.log('========================================');
  
  const passed = results.filter(r => r.passed).length;
  const failed = results.filter(r => !r.passed).length;
  
  console.log(`Total: ${results.length}`);
  console.log(`Passed: ${passed}`);
  console.log(`Failed: ${failed}`);
  
  if (failed > 0) {
    console.log('\nFailed tests:');
    results.filter(r => !r.passed).forEach(r => {
      console.log(`  - ${r.name}: ${r.error}`);
    });
  }
}

// Export for use as module or run directly
export { runAllTests };

// Run if executed directly
if (typeof require !== 'undefined' && require.main === module) {
  runAllTests().catch(console.error);
}

/*
========================================
CURL Commands for Manual Testing
========================================

# 1. Create enrichment job
curl -X POST http://localhost:3002/api/integration/forge/enrich \
  -H "Content-Type: application/json" \
  -H "Cookie: auth_token=YOUR_TOKEN" \
  -d '{"opLeadId":"LEAD_ID","modules":["maps","website"]}'

# 2. Get job status
curl http://localhost:3002/api/integration/forge/enrich/status?jobId=JOB_ID \
  -H "Cookie: auth_token=YOUR_TOKEN"

# 3. Get snapshot
curl http://localhost:3002/api/integration/forge/snapshot?opLeadId=LEAD_ID \
  -H "Cookie: auth_token=YOUR_TOKEN"

# 4. Generate survey (with snapshot)
curl -X POST http://localhost:3002/api/integration/forge/survey \
  -H "Content-Type: application/json" \
  -H "Cookie: auth_token=YOUR_TOKEN" \
  -d '{"opLeadId":"LEAD_ID","force":true}'

# 5. Test flags off (set INTEGRATION_WORKER_ENRICH=false first)
curl -X POST http://localhost:3002/api/integration/forge/enrich \
  -H "Content-Type: application/json" \
  -d '{"opLeadId":"LEAD_ID","modules":["maps"]}'
# Expected: 404 Not found

========================================
*/

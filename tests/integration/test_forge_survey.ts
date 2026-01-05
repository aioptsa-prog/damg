/**
 * Forge Survey Integration Tests
 * 
 * Run: npx ts-node tests/integration/test_forge_survey.ts
 * 
 * Tests:
 * 1. Success - generate survey from linked forge lead
 * 2. Not linked - lead without forge link
 * 3. Invalid token - auth failure
 * 4. Idempotency - same lead returns cached report
 * 5. Force regenerate - force=true bypasses cache
 * 
 * @since Phase 3
 */

// Note: This is a test specification file
// Actual tests should be run via curl or test framework

export const TEST_CASES = {
  /**
   * Test 1: Success - Generate survey from linked forge lead
   * 
   * Prerequisites:
   * - INTEGRATION_SURVEY_FROM_LEAD=true
   * - Valid user session (JWT cookie)
   * - Lead linked to forge via /api/integration/forge/link
   * 
   * curl -X POST http://localhost:3002/api/integration/forge/survey \
   *   -H "Content-Type: application/json" \
   *   -H "Cookie: auth_token=<jwt>" \
   *   -d '{"opLeadId": "<lead-uuid>"}'
   * 
   * Expected: 201 { ok: true, cached: false, report: {...} }
   */
  success: {
    method: 'POST',
    path: '/api/integration/forge/survey',
    body: { opLeadId: '<lead-uuid>' },
    expectedStatus: 201,
    expectedBody: { ok: true, cached: false },
  },

  /**
   * Test 2: Not linked - Lead without forge link
   * 
   * curl -X POST http://localhost:3002/api/integration/forge/survey \
   *   -H "Content-Type: application/json" \
   *   -H "Cookie: auth_token=<jwt>" \
   *   -d '{"opLeadId": "<unlinked-lead-uuid>"}'
   * 
   * Expected: 404 { ok: false, error: "Lead not linked to forge" }
   */
  notLinked: {
    method: 'POST',
    path: '/api/integration/forge/survey',
    body: { opLeadId: '<unlinked-lead-uuid>' },
    expectedStatus: 404,
    expectedBody: { ok: false, error: 'Lead not linked to forge' },
  },

  /**
   * Test 3: Invalid token - No auth cookie
   * 
   * curl -X POST http://localhost:3002/api/integration/forge/survey \
   *   -H "Content-Type: application/json" \
   *   -d '{"opLeadId": "<lead-uuid>"}'
   * 
   * Expected: 401 { ok: false, error: "Unauthorized" }
   */
  invalidToken: {
    method: 'POST',
    path: '/api/integration/forge/survey',
    body: { opLeadId: '<lead-uuid>' },
    noAuth: true,
    expectedStatus: 401,
    expectedBody: { ok: false, error: 'Unauthorized' },
  },

  /**
   * Test 4: Idempotency - Same lead returns cached report
   * 
   * # First call - generates new report
   * curl -X POST http://localhost:3002/api/integration/forge/survey \
   *   -H "Content-Type: application/json" \
   *   -H "Cookie: auth_token=<jwt>" \
   *   -d '{"opLeadId": "<lead-uuid>"}'
   * 
   * # Second call - returns cached
   * curl -X POST http://localhost:3002/api/integration/forge/survey \
   *   -H "Content-Type: application/json" \
   *   -H "Cookie: auth_token=<jwt>" \
   *   -d '{"opLeadId": "<lead-uuid>"}'
   * 
   * Expected (second call): 200 { ok: true, cached: true, report: {...} }
   */
  idempotency: {
    method: 'POST',
    path: '/api/integration/forge/survey',
    body: { opLeadId: '<lead-uuid>' },
    expectedStatus: 200,
    expectedBody: { ok: true, cached: true },
  },

  /**
   * Test 5: Force regenerate - force=true bypasses cache
   * 
   * curl -X POST http://localhost:3002/api/integration/forge/survey \
   *   -H "Content-Type: application/json" \
   *   -H "Cookie: auth_token=<jwt>" \
   *   -d '{"opLeadId": "<lead-uuid>", "force": true}'
   * 
   * Expected: 201 { ok: true, cached: false, report: {...} }
   */
  forceRegenerate: {
    method: 'POST',
    path: '/api/integration/forge/survey',
    body: { opLeadId: '<lead-uuid>', force: true },
    expectedStatus: 201,
    expectedBody: { ok: true, cached: false },
  },

  /**
   * Test 6: Flag disabled - returns 404
   * 
   * # With INTEGRATION_SURVEY_FROM_LEAD=false
   * curl -X POST http://localhost:3002/api/integration/forge/survey \
   *   -H "Content-Type: application/json" \
   *   -H "Cookie: auth_token=<jwt>" \
   *   -d '{"opLeadId": "<lead-uuid>"}'
   * 
   * Expected: 404 { ok: false, error: "Not found" }
   */
  flagDisabled: {
    method: 'POST',
    path: '/api/integration/forge/survey',
    body: { opLeadId: '<lead-uuid>' },
    flagDisabled: true,
    expectedStatus: 404,
    expectedBody: { ok: false, error: 'Not found' },
  },
};

/**
 * Manual Test Script (Bash)
 * 
 * Save as test_forge_survey.sh and run:
 * chmod +x test_forge_survey.sh && ./test_forge_survey.sh
 */
export const BASH_TEST_SCRIPT = `#!/bin/bash
# Forge Survey Integration Tests
# Prerequisites:
# - OP-Target running on localhost:3002
# - forge running on localhost:8080
# - INTEGRATION_SURVEY_FROM_LEAD=true
# - Valid JWT token in AUTH_TOKEN env var
# - Linked lead ID in LEAD_ID env var

BASE_URL="http://localhost:3002"
AUTH_TOKEN="\${AUTH_TOKEN:-your_jwt_token_here}"
LEAD_ID="\${LEAD_ID:-your_lead_id_here}"

echo "=== Forge Survey Integration Tests ==="
echo ""

# Test 1: Success
echo "Test 1: Generate survey (should succeed)"
curl -s -X POST "\$BASE_URL/api/integration/forge/survey" \\
  -H "Content-Type: application/json" \\
  -H "Cookie: auth_token=\$AUTH_TOKEN" \\
  -d "{\\"opLeadId\\": \\"\$LEAD_ID\\"}" | jq .
echo ""

# Test 2: Idempotency (second call should be cached)
echo "Test 2: Idempotency (should return cached)"
curl -s -X POST "\$BASE_URL/api/integration/forge/survey" \\
  -H "Content-Type: application/json" \\
  -H "Cookie: auth_token=\$AUTH_TOKEN" \\
  -d "{\\"opLeadId\\": \\"\$LEAD_ID\\"}" | jq .cached
echo ""

# Test 3: Force regenerate
echo "Test 3: Force regenerate (should not be cached)"
curl -s -X POST "\$BASE_URL/api/integration/forge/survey" \\
  -H "Content-Type: application/json" \\
  -H "Cookie: auth_token=\$AUTH_TOKEN" \\
  -d "{\\"opLeadId\\": \\"\$LEAD_ID\\", \\"force\\": true}" | jq .cached
echo ""

# Test 4: No auth
echo "Test 4: No auth (should fail 401)"
curl -s -X POST "\$BASE_URL/api/integration/forge/survey" \\
  -H "Content-Type: application/json" \\
  -d "{\\"opLeadId\\": \\"\$LEAD_ID\\"}" | jq .error
echo ""

# Test 5: Unlinked lead
echo "Test 5: Unlinked lead (should fail 404)"
curl -s -X POST "\$BASE_URL/api/integration/forge/survey" \\
  -H "Content-Type: application/json" \\
  -H "Cookie: auth_token=\$AUTH_TOKEN" \\
  -d "{\\"opLeadId\\": \\"non-existent-lead-id\\"}" | jq .error
echo ""

echo "=== Tests Complete ==="
`;

console.log('Forge Survey Test Cases defined.');
console.log('Run manual tests using curl commands in TEST_CASES or BASH_TEST_SCRIPT.');

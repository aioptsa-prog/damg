/**
 * WhatsApp Send Integration Tests
 * 
 * Tests 8 scenarios for /api/integration/forge/whatsapp/send
 * 
 * @since Phase 4
 */

export const TEST_SCENARIOS = {
  /**
   * Scenario 1: Success - Send message from report
   * 
   * Prerequisites:
   * - INTEGRATION_SEND_FROM_REPORT=true
   * - Lead linked to forge with phone
   * - Report exists with suggested_message
   * 
   * curl -X POST http://localhost:3002/api/integration/forge/whatsapp/send \
   *   -H "Content-Type: application/json" \
   *   -H "Cookie: auth_token=<jwt>" \
   *   -d '{"opLeadId": "<lead-uuid>"}'
   * 
   * Expected: 200 { ok: true, sent: true, phone: "966...", ... }
   */
  success: {
    name: 'Success - Send message',
    method: 'POST',
    body: { opLeadId: '<lead-uuid>' },
    expectedStatus: 200,
    expectedBody: { ok: true, sent: true },
  },

  /**
   * Scenario 2: Message Override - Custom message instead of suggested
   * 
   * curl -X POST http://localhost:3002/api/integration/forge/whatsapp/send \
   *   -H "Content-Type: application/json" \
   *   -H "Cookie: auth_token=<jwt>" \
   *   -d '{"opLeadId": "<lead-uuid>", "message": "رسالة مخصصة"}'
   * 
   * Expected: 200 { ok: true, sent: true }
   */
  messageOverride: {
    name: 'Message Override',
    method: 'POST',
    body: { opLeadId: '<lead-uuid>', message: 'رسالة مخصصة للاختبار' },
    expectedStatus: 200,
    expectedBody: { ok: true, sent: true },
  },

  /**
   * Scenario 3: Invalid Token - No auth cookie
   * 
   * curl -X POST http://localhost:3002/api/integration/forge/whatsapp/send \
   *   -H "Content-Type: application/json" \
   *   -d '{"opLeadId": "<lead-uuid>"}'
   * 
   * Expected: 401 { ok: false, error: "Unauthorized" }
   */
  invalidToken: {
    name: 'Invalid Token',
    method: 'POST',
    body: { opLeadId: '<lead-uuid>' },
    noAuth: true,
    expectedStatus: 401,
    expectedBody: { ok: false, error: 'Unauthorized' },
  },

  /**
   * Scenario 4: Not Linked - Lead without forge link
   * 
   * curl -X POST http://localhost:3002/api/integration/forge/whatsapp/send \
   *   -H "Content-Type: application/json" \
   *   -H "Cookie: auth_token=<jwt>" \
   *   -d '{"opLeadId": "<unlinked-lead-uuid>"}'
   * 
   * Expected: 404 { ok: false, error: "Lead not linked to forge" }
   */
  notLinked: {
    name: 'Not Linked',
    method: 'POST',
    body: { opLeadId: '<unlinked-lead-uuid>' },
    expectedStatus: 404,
    expectedBody: { ok: false, error: 'Lead not linked to forge' },
  },

  /**
   * Scenario 5: No Report - Lead linked but no report generated
   * 
   * curl -X POST http://localhost:3002/api/integration/forge/whatsapp/send \
   *   -H "Content-Type: application/json" \
   *   -H "Cookie: auth_token=<jwt>" \
   *   -d '{"opLeadId": "<linked-no-report-uuid>"}'
   * 
   * Expected: 404 { ok: false, error: "No report found" }
   */
  noReport: {
    name: 'No Report',
    method: 'POST',
    body: { opLeadId: '<linked-no-report-uuid>' },
    expectedStatus: 404,
    expectedBody: { ok: false, error: 'No report found' },
  },

  /**
   * Scenario 6: Duplicate - Same message within 10 minutes
   * 
   * # First call succeeds
   * curl -X POST http://localhost:3002/api/integration/forge/whatsapp/send \
   *   -H "Content-Type: application/json" \
   *   -H "Cookie: auth_token=<jwt>" \
   *   -d '{"opLeadId": "<lead-uuid>"}'
   * 
   * # Second call blocked
   * curl -X POST http://localhost:3002/api/integration/forge/whatsapp/send \
   *   -H "Content-Type: application/json" \
   *   -H "Cookie: auth_token=<jwt>" \
   *   -d '{"opLeadId": "<lead-uuid>"}'
   * 
   * Expected (second): 409 { ok: false, error: "Duplicate send blocked", dedupe_blocked: true }
   */
  duplicate: {
    name: 'Duplicate Blocked',
    method: 'POST',
    body: { opLeadId: '<lead-uuid>' },
    expectedStatus: 409,
    expectedBody: { ok: false, dedupe_blocked: true },
  },

  /**
   * Scenario 7: Forge Down - forge server unreachable
   * 
   * # With forge server stopped
   * curl -X POST http://localhost:3002/api/integration/forge/whatsapp/send \
   *   -H "Content-Type: application/json" \
   *   -H "Cookie: auth_token=<jwt>" \
   *   -d '{"opLeadId": "<lead-uuid>"}'
   * 
   * Expected: 502 { ok: false, error: "Failed to obtain forge token" }
   */
  forgeDown: {
    name: 'Forge Down',
    method: 'POST',
    body: { opLeadId: '<lead-uuid>' },
    forgeDown: true,
    expectedStatus: 502,
    expectedBody: { ok: false },
  },

  /**
   * Scenario 8: Dry Run - Preview without sending
   * 
   * curl -X POST http://localhost:3002/api/integration/forge/whatsapp/send \
   *   -H "Content-Type: application/json" \
   *   -H "Cookie: auth_token=<jwt>" \
   *   -d '{"opLeadId": "<lead-uuid>", "dryRun": true}'
   * 
   * Expected: 200 { ok: true, dry_run: true, phone: "966...", message_preview: "..." }
   */
  dryRun: {
    name: 'Dry Run',
    method: 'POST',
    body: { opLeadId: '<lead-uuid>', dryRun: true },
    expectedStatus: 200,
    expectedBody: { ok: true, dry_run: true },
  },
};

/**
 * Bash Test Script
 */
export const BASH_TEST_SCRIPT = `#!/bin/bash
# WhatsApp Send Integration Tests
# Prerequisites:
# - OP-Target running on localhost:3002
# - forge running on localhost:8080
# - INTEGRATION_SEND_FROM_REPORT=true
# - AUTH_TOKEN and LEAD_ID env vars set

BASE_URL="http://localhost:3002"
AUTH_TOKEN="\${AUTH_TOKEN:-your_jwt_token}"
LEAD_ID="\${LEAD_ID:-your_linked_lead_id}"
UNLINKED_LEAD_ID="\${UNLINKED_LEAD_ID:-unlinked_lead_id}"

echo "=== WhatsApp Send Integration Tests ==="
echo ""

# Test 1: Dry Run (safe to run)
echo "Test 1: Dry Run"
curl -s -X POST "\$BASE_URL/api/integration/forge/whatsapp/send" \\
  -H "Content-Type: application/json" \\
  -H "Cookie: auth_token=\$AUTH_TOKEN" \\
  -d "{\\"opLeadId\\": \\"\$LEAD_ID\\", \\"dryRun\\": true}" | jq '{ok, dry_run, phone, message_preview}'
echo ""

# Test 2: No Auth
echo "Test 2: No Auth (should fail 401)"
curl -s -X POST "\$BASE_URL/api/integration/forge/whatsapp/send" \\
  -H "Content-Type: application/json" \\
  -d "{\\"opLeadId\\": \\"\$LEAD_ID\\"}" | jq '{ok, error}'
echo ""

# Test 3: Not Linked
echo "Test 3: Not Linked (should fail 404)"
curl -s -X POST "\$BASE_URL/api/integration/forge/whatsapp/send" \\
  -H "Content-Type: application/json" \\
  -H "Cookie: auth_token=\$AUTH_TOKEN" \\
  -d "{\\"opLeadId\\": \\"\$UNLINKED_LEAD_ID\\"}" | jq '{ok, error}'
echo ""

# Test 4: Message Override (dry run)
echo "Test 4: Message Override (dry run)"
curl -s -X POST "\$BASE_URL/api/integration/forge/whatsapp/send" \\
  -H "Content-Type: application/json" \\
  -H "Cookie: auth_token=\$AUTH_TOKEN" \\
  -d "{\\"opLeadId\\": \\"\$LEAD_ID\\", \\"message\\": \\"رسالة اختبار مخصصة\\", \\"dryRun\\": true}" | jq '{ok, dry_run, message_preview}'
echo ""

echo "=== Safe Tests Complete ==="
echo ""
echo "To run actual send tests (will send real messages):"
echo "  Remove dryRun: true from the requests"
echo ""
`;

console.log('WhatsApp Send Test Scenarios defined.');
console.log('8 scenarios: success, override, invalidToken, notLinked, noReport, duplicate, forgeDown, dryRun');

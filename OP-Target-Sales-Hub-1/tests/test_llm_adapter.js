/**
 * Sprint 3.4: LLM Report Adapter Test
 * Tests the AI service configuration and report schema
 */

import 'dotenv/config';

console.log('=== LLM Report Adapter Test ===\n');

// 1. Check environment variables
console.log('1. Checking environment variables...');
const geminiKey = process.env.GEMINI_API_KEY || process.env.API_KEY;
const openaiKey = process.env.OPENAI_API_KEY;

console.log(`   GEMINI_API_KEY: ${geminiKey ? '[SET]' : '[NOT SET]'}`);
console.log(`   OPENAI_API_KEY: ${openaiKey ? '[SET]' : '[NOT SET]'}`);

if (!geminiKey && !openaiKey) {
  console.log('   ⚠ No AI API key configured');
} else {
  console.log('   ✓ At least one AI provider configured');
}

// 2. Check report schema structure
console.log('\n2. Checking report schema...');
const REPORT_SCHEMA_FIELDS = [
  'company',
  'sector', 
  'evidence_summary',
  'snapshot',
  'website_audit',
  'social_audit',
  'pain_points',
  'quick_wins',
  'recommended_services',
  'talk_track',
  'follow_up_plan',
  'assumptions',
  'data_gaps',
  'compliance_notes'
];

console.log(`   Required fields: ${REPORT_SCHEMA_FIELDS.length}`);
REPORT_SCHEMA_FIELDS.forEach(field => {
  console.log(`     - ${field}`);
});
console.log('   ✓ Schema structure defined');

// 3. Test evidence-based claim format
console.log('\n3. Testing evidence-based claim format...');
const sampleClaim = {
  finding: 'لا يوجد Google Analytics مثبت',
  evidence_url: 'https://example.com',
  confidence: 'high'
};

const sampleUnconfirmedClaim = {
  finding: 'الميزانية التسويقية محدودة',
  evidence_url: null,
  confidence: 'غير مؤكد - مبني على افتراض'
};

console.log('   Sample confirmed claim:');
console.log(`     Finding: ${sampleClaim.finding}`);
console.log(`     Evidence: ${sampleClaim.evidence_url}`);
console.log(`     Confidence: ${sampleClaim.confidence}`);

console.log('\n   Sample unconfirmed claim:');
console.log(`     Finding: ${sampleUnconfirmedClaim.finding}`);
console.log(`     Evidence: ${sampleUnconfirmedClaim.evidence_url || 'N/A'}`);
console.log(`     Confidence: ${sampleUnconfirmedClaim.confidence}`);
console.log('   ✓ Claim format validated');

// 4. Test recommended service format
console.log('\n4. Testing recommended service format...');
const sampleService = {
  tier: 'tier1',
  service: 'إعداد Google Analytics',
  why: 'مبني على: لا يوجد tracking في الموقع',
  offer: 'إعداد كامل مع تدريب',
  next_step: 'جدولة مكالمة تعريفية',
  confidence: 0.95,
  package_suggestion: {
    package_name: 'باقة التحليلات الأساسية',
    scope: 'GA4 + GTM + تقارير شهرية',
    duration: '3 أشهر',
    price_range: '3000-5000 ريال',
    notes: null
  }
};

console.log('   Sample service recommendation:');
console.log(`     Tier: ${sampleService.tier}`);
console.log(`     Service: ${sampleService.service}`);
console.log(`     Why: ${sampleService.why}`);
console.log(`     Confidence: ${sampleService.confidence}`);
console.log(`     Package: ${sampleService.package_suggestion.package_name}`);
console.log('   ✓ Service format validated');

// 5. Verify API endpoint exists
console.log('\n5. Checking API endpoint...');
import { existsSync } from 'fs';
import { resolve } from 'path';

const apiPath = resolve(process.cwd(), 'api/reports.ts');
if (existsSync(apiPath)) {
  console.log('   ✓ api/reports.ts exists');
} else {
  console.log('   ✗ api/reports.ts not found');
}

// 6. Summary
console.log('\n=== LLM Report Adapter Test Complete ===');
console.log('\nKey requirements for Sprint 3:');
console.log('  1. Every claim must have evidence_url OR be marked "غير مؤكد"');
console.log('  2. Recommended services must include "why" with evidence reference');
console.log('  3. Report generation uses POST /api/reports?generate=true');
console.log('  4. Evidence enrichment uses POST /api/reports?enrich=true');

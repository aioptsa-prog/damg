/**
 * اختبار Lead Enrichment Engine
 */

import { LeadEnricher } from './index.js';

async function test() {
  console.log('=== Lead Enrichment Engine Test ===\n');

  const enricher = new LeadEnricher({
    headless: false, // عرض المتصفح للمشاهدة
    delayBetweenSearches: 2500
  });

  try {
    // عميل للاختبار
    const lead = {
      name: 'البرجر الوطني',
      city: 'الرياض',
      phone: '966598801238',
      category: 'مطعم'
    };

    console.log('Lead to enrich:', lead);
    console.log('\nStarting enrichment...\n');

    const results = await enricher.enrich(lead);

    console.log('\n=== Results ===\n');
    
    // Website
    if (results.enriched.website) {
      console.log('📌 Website:');
      console.log(`   URL: ${results.enriched.website.url}`);
      console.log(`   Confidence: ${(results.enriched.website.confidence * 100).toFixed(1)}%`);
    } else {
      console.log('📌 Website: Not found');
    }

    // Social Media
    console.log('\n📱 Social Media:');
    const social = results.enriched.socialMedia;
    if (Object.keys(social).length > 0) {
      for (const [platform, data] of Object.entries(social)) {
        console.log(`   ${platform}: @${data.handle} (${(data.confidence * 100).toFixed(1)}%)`);
        console.log(`      ${data.url}`);
      }
    } else {
      console.log('   No social accounts found');
    }

    // Maps
    console.log('\n🗺️ Maps:');
    if (results.enriched.maps) {
      const maps = results.enriched.maps;
      console.log(`   Name: ${maps.name}`);
      console.log(`   Address: ${maps.address}`);
      console.log(`   Phone: ${maps.phone}`);
      console.log(`   Website: ${maps.website}`);
      console.log(`   Rating: ${maps.rating} (${maps.reviewCount} reviews)`);
      console.log(`   Confidence: ${(maps.confidence * 100).toFixed(1)}%`);
    } else {
      console.log('   No maps data found');
    }

    // Summary
    console.log('\n📊 Summary:');
    console.log(`   Total Confidence: ${(results.summary.totalConfidence * 100).toFixed(1)}%`);
    console.log(`   Fields Enriched: ${results.summary.fieldsEnriched.join(', ')}`);
    console.log(`   Searches Performed: ${results.summary.searchesPerformed}`);
    console.log(`   Duration: ${results.summary.duration}ms`);
    console.log(`   Verdict: ${results.summary.verdict}`);

    // Errors
    if (results.errors.length > 0) {
      console.log('\n⚠️ Errors:');
      for (const err of results.errors) {
        console.log(`   ${err.layer}: ${err.error}`);
      }
    }

  } catch (err) {
    console.error('Test failed:', err);
  } finally {
    await enricher.close();
  }
}

test();

#!/usr/bin/env node
/**
 * CLI wrapper for Lead Enricher
 * Usage: node cli.js '{"name": "...", "city": "..."}'
 */

import { LeadEnricher } from './index.js';
import { Aggregator } from './aggregator.js';

async function main() {
  const leadJson = process.argv[2];
  
  if (!leadJson) {
    console.error(JSON.stringify({ ok: false, error: 'No lead data provided' }));
    process.exit(1);
  }

  let lead;
  try {
    lead = JSON.parse(leadJson);
  } catch (e) {
    console.error(JSON.stringify({ ok: false, error: 'Invalid JSON: ' + e.message }));
    process.exit(1);
  }

  const enricher = new LeadEnricher({ 
    headless: true,
    delayBetweenSearches: 2000
  });
  
  const aggregator = new Aggregator();

  try {
    const results = await enricher.enrich(lead);
    const response = aggregator.toApiResponse(results);
    console.log(JSON.stringify(response));
  } catch (err) {
    console.error(JSON.stringify({ ok: false, error: err.message }));
    process.exit(1);
  } finally {
    await enricher.close();
  }
}

main();

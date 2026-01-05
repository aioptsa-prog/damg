/**
 * Integration Job Runner
 * Phase 6: Polls and processes integration enrichment jobs
 * 
 * This runs alongside the main worker, polling for integration jobs
 * and executing modules sequentially.
 */

import fetch from 'node-fetch';
import { runModules } from './integration_modules.js';

// Configuration
const POLL_INTERVAL_MS = parseInt(process.env.INTEGRATION_POLL_MS || '10000', 10);
const MAX_RETRIES = 2;
const RETRY_DELAY_MS = 5000;

let BASE_URL = '';
let INTERNAL_SECRET = '';
let isRunning = false;
let currentJob = null;

/**
 * Initialize the integration runner
 */
export function initIntegrationRunner(baseUrl, secret) {
  BASE_URL = baseUrl.replace(/\/+$/, '');
  INTERNAL_SECRET = secret;
  console.log('[INTEGRATION] Runner initialized, base:', BASE_URL);
}

/**
 * Start polling for integration jobs
 */
export function startIntegrationRunner(browserContext) {
  if (isRunning) {
    console.log('[INTEGRATION] Runner already started');
    return;
  }
  
  isRunning = true;
  console.log('[INTEGRATION] Starting job runner, poll interval:', POLL_INTERVAL_MS, 'ms');
  
  pollLoop(browserContext);
}

/**
 * Stop the integration runner
 */
export function stopIntegrationRunner() {
  isRunning = false;
  console.log('[INTEGRATION] Runner stopped');
}

/**
 * Get current job status
 */
export function getIntegrationStatus() {
  return {
    running: isRunning,
    currentJob: currentJob ? {
      id: currentJob.id,
      forgeLeadId: currentJob.forgeLeadId,
      modules: currentJob.modules
    } : null
  };
}

/**
 * Main polling loop
 */
async function pollLoop(browserContext) {
  while (isRunning) {
    try {
      await processNextJob(browserContext);
    } catch (err) {
      console.error('[INTEGRATION] Poll error:', err.message);
    }
    
    // Wait before next poll
    await sleep(POLL_INTERVAL_MS);
  }
}

/**
 * Fetch and process next available job
 */
async function processNextJob(browserContext) {
  // Fetch next job from API
  const response = await fetch(`${BASE_URL}/v1/api/integration/jobs/process.php`, {
    method: 'GET',
    headers: {
      'X-Internal-Secret': INTERNAL_SECRET,
      'X-Worker-Secret': INTERNAL_SECRET
    }
  });
  
  if (!response.ok) {
    console.error('[INTEGRATION] Failed to fetch job:', response.status);
    return;
  }
  
  const data = await response.json();
  
  if (!data.ok || !data.job) {
    // No jobs available
    return;
  }
  
  const job = data.job;
  currentJob = job;
  
  console.log(`[INTEGRATION] Processing job ${job.id} for lead ${job.forgeLeadId}, modules:`, job.modules);
  
  try {
    // Create a new page for this job
    const page = await browserContext.newPage();
    
    try {
      // Process each module
      const results = await runModules(page, job.lead || {}, job.modules, job.options || {});
      
      // Report module results
      for (const [moduleName, moduleResult] of Object.entries(results.modules)) {
        if (moduleResult.success) {
          await reportModuleStatus(job.id, 'module_success', moduleName, moduleResult.data);
        } else if (moduleResult.error?.code === 'unknown_module') {
          await reportModuleStatus(job.id, 'module_skipped', moduleName);
        } else {
          await reportModuleStatus(job.id, 'module_failed', moduleName, null, 
            moduleResult.error?.code, moduleResult.error?.message);
        }
      }
      
      // Determine final status
      let finalStatus = 'failed';
      if (results.success) {
        finalStatus = 'success';
      } else if (results.partial) {
        finalStatus = 'partial';
      }
      
      // Report job completion with snapshot
      await reportJobComplete(job.id, finalStatus, results.snapshot);
      
      console.log(`[INTEGRATION] Job ${job.id} completed with status: ${finalStatus}`);
      
    } finally {
      await page.close();
    }
    
  } catch (err) {
    console.error(`[INTEGRATION] Job ${job.id} failed:`, err.message);
    await reportJobComplete(job.id, 'failed', null, err.message);
  }
  
  currentJob = null;
}

/**
 * Report module status to API
 */
async function reportModuleStatus(jobId, action, module, output = null, errorCode = null, errorMessage = null) {
  const body = {
    action,
    jobId,
    module
  };
  
  if (output) body.output = output;
  if (errorCode) body.errorCode = errorCode;
  if (errorMessage) body.errorMessage = errorMessage;
  
  try {
    await fetch(`${BASE_URL}/v1/api/integration/jobs/process.php`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Internal-Secret': INTERNAL_SECRET,
        'X-Worker-Secret': INTERNAL_SECRET
      },
      body: JSON.stringify(body)
    });
  } catch (err) {
    console.error('[INTEGRATION] Failed to report module status:', err.message);
  }
}

/**
 * Report job completion to API
 */
async function reportJobComplete(jobId, status, snapshot = null, lastError = null) {
  const body = {
    action: 'job_complete',
    jobId,
    status,
    snapshot: snapshot || {},
    lastError
  };
  
  try {
    await fetch(`${BASE_URL}/v1/api/integration/jobs/process.php`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Internal-Secret': INTERNAL_SECRET,
        'X-Worker-Secret': INTERNAL_SECRET
      },
      body: JSON.stringify(body)
    });
  } catch (err) {
    console.error('[INTEGRATION] Failed to report job completion:', err.message);
  }
}

/**
 * Sleep helper
 */
function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

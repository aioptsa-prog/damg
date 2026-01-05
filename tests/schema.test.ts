
// Fix: Added missing imports for test functions to resolve compilation errors
import { describe, test, expect } from 'vitest';
import { REPORT_SCHEMA } from '../services/aiService';

describe('AI Report Schema Validation', () => {
  test('Should contain all required top-level keys', () => {
    const required = ['company', 'sector', 'snapshot', 'pain_points', 'recommended_services', 'talk_track', 'follow_up_plan'];
    required.forEach(key => {
      // @ts-ignore - Accessing schema properties dynamically for validation
      expect(REPORT_SCHEMA.properties).toHaveProperty(key);
    });
  });

  test('Should strictly enforce service output structure', () => {
    // @ts-ignore - Deep nested property access on inferred schema object
    const serviceProps = (REPORT_SCHEMA.properties as any).recommended_services.items.properties;
    expect(serviceProps).toHaveProperty('service');
    expect(serviceProps).toHaveProperty('package_suggestion');
  });
});

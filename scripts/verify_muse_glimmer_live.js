#!/usr/bin/env node
/**
 * verify_muse_glimmer_live.js
 * 
 * Live end-to-end verification probe of the Kiribati AI Fisheries stack
 * focusing on Meta Muse Glimmer vision model integration, health checks,
 * authentication, species catalog grounding, and identification fallback.
 */

const BASE_URL = process.env.API_BASE_URL || 'http://127.0.0.1:8000';

async function run() {
  console.log(`\n======================================================`);
  console.log(`🐟 Kiribati AI Fisheries Assistant — Live Stack Probe`);
  console.log(`Target: ${BASE_URL}`);
  console.log(`======================================================\n`);

  let passed = 0;
  let total = 0;

  function assert(condition, message, details = '') {
    total++;
    if (condition) {
      passed++;
      console.log(`  ✔ [PASS] ${message}`);
      if (details) console.log(`           ${details}`);
    } else {
      console.error(`  ❌ [FAIL] ${message}`);
      if (details) console.error(`           ${details}`);
    }
  }

  // 1. Health check
  console.log(`1. Testing /api/health ...`);
  try {
    const res = await fetch(`${BASE_URL}/api/health`);
    const data = await res.json();
    assert(res.status === 200 && data.status === 'ok', 'API Health Check returns HTTP 200 OK', `Service: ${data.service}`);
  } catch (err) {
    assert(false, 'API Health Check returns HTTP 200 OK', err.message);
  }

  // 2. Admin Authentication
  console.log(`\n2. Testing Admin Authentication (/api/auth/login) ...`);
  let token = null;
  try {
    const res = await fetch(`${BASE_URL}/api/auth/login`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({
        email: 'admin@fisheries.gov.ki',
        password: 'KiribatiAdmin2026!'
      })
    });
    const data = await res.json();
    token = data.token;
    assert(res.status === 200 && Boolean(token), 'Admin login succeeds and issues Sanctum token', `User: ${data.user?.name} (${data.user?.role})`);
  } catch (err) {
    assert(false, 'Admin login succeeds and issues Sanctum token', err.message);
  }

  // 3. Meta AI Health & Muse Glimmer Capabilities
  console.log(`\n3. Testing Meta AI Health & Muse Glimmer (/api/ai/meta-health) ...`);
  try {
    const res = await fetch(`${BASE_URL}/api/ai/meta-health`, {
      headers: {
        'Accept': 'application/json',
        'Authorization': `Bearer ${token}`
      }
    });
    const data = await res.json();
    assert(res.status === 200, 'Meta Health Check endpoint returns HTTP 200 for Admin');
    assert(data.muse_glimmer_multimodal_vision === true, 'Muse Glimmer multimodal vision modality is reported as active');
    assert(data.muse_glimmer_primary_classifier === true, 'Muse Glimmer is designated as the primary online vision classifier');
    assert(data.muse_glimmer_vision_model === 'muse-glimmer', 'Muse Glimmer vision model key is muse-glimmer', `Model: ${data.muse_glimmer_vision_model}`);
    assert(typeof data.has_api_key === 'boolean' && !data.api_key && !data.key, 'Secret isolation verified: has_api_key is boolean, raw secret is never leaked');
  } catch (err) {
    assert(false, 'Meta Health Check endpoint responds properly', err.message);
  }

  // 4. Grounding Catalog Inspection
  console.log(`\n4. Inspecting Laravel DB Grounding Species Catalog (/api/species) ...`);
  try {
    const res = await fetch(`${BASE_URL}/api/species`, {
      headers: { 'Accept': 'application/json' }
    });
    const data = await res.json();
    const count = Array.isArray(data) ? data.length : (data.data?.length || 0);
    assert(res.status === 200 && count >= 9, `Species catalog returns grounded Kiribati species (${count} total entries in DB)`);
  } catch (err) {
    assert(false, 'Species catalog query succeeds', err.message);
  }

  // 5. Live AI Fish Identification Probe with Multimodal Specimen Payload
  console.log(`\n5. Testing Live AI Fish Identification (/api/ai/identify-fish) ...`);
  // Minimal valid 1x1 transparent PNG data URL to test multimodal ingestion pipeline
  const testDataUrl = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
  try {
    const res = await fetch(`${BASE_URL}/api/ai/identify-fish`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'Authorization': `Bearer ${token}`
      },
      body: JSON.stringify({
        image_url: testDataUrl,
        species_hint: 'Te Bwere'
      })
    });
    const data = await res.json();
    assert(res.status === 200, 'Identify-fish endpoint responds HTTP 200 OK');
    assert(data.status === 'ok', 'Identification status is ok');
    assert(Boolean(data.identification?.kiribati_name), `Identified fish has authentic Kiribati vernacular name: "${data.identification?.kiribati_name}"`);
    assert(Boolean(data.identification?.english_name), `Identified fish has English name: "${data.identification?.english_name}"`);
    assert(Boolean(data.identification?.scientific_name), `Identified fish has scientific taxonomy: "${data.identification?.scientific_name}"`);
    assert(data.requires_human_confirmation === true, 'ADR-002 Compliance: requires_human_confirmation remains true');
    assert(Boolean(data.ciguatera_warning), 'Safety Compliance: Ciguatera advisory warning is included');
    assert(Boolean(data.meta_model?.glimmer_vision), 'Meta Muse Glimmer vision report block is present in response', `Model status: ${data.meta_model?.glimmer_vision?.status}`);
  } catch (err) {
    assert(false, 'Live AI fish identification probe succeeds', err.message);
  }

  console.log(`\n======================================================`);
  console.log(`Live Probe Summary: ${passed} / ${total} tests passed (${Math.round((passed / total) * 100)}%)`);
  console.log(`======================================================\n`);

  if (passed === total) {
    process.exit(0);
  } else {
    process.exit(1);
  }
}

run().catch(err => {
  console.error('Fatal probe error:', err);
  process.exit(1);
});

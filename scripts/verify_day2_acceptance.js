/**
 * Kiribati AI Fisheries Assistant — Day 2 Acceptance Test Suite
 * Tests all 8 fish poisoning advisory zone criteria against the live Laravel API.
 */

const http = require('http');

const PORT = process.env.PORT || 8000;
const API_BASE = `http://127.0.0.1:${PORT}/api`;

async function fetchJson(url, options = {}) {
  return new Promise((resolve, reject) => {
    const parsedUrl = new URL(url);
    const reqOptions = {
      hostname: parsedUrl.hostname,
      port: parsedUrl.port,
      path: parsedUrl.pathname + parsedUrl.search,
      method: options.method || 'GET',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        ...(options.headers || {}),
      },
    };

    const req = http.request(reqOptions, (res) => {
      let data = '';
      res.on('data', (chunk) => (data += chunk));
      res.on('end', () => {
        try {
          const json = data ? JSON.parse(data) : {};
          resolve({ status: res.statusCode, data: json });
        } catch (e) {
          resolve({ status: res.statusCode, raw: data });
        }
      });
    });

    req.on('error', (err) => reject(err));

    if (options.body) {
      req.write(typeof options.body === 'string' ? options.body : JSON.stringify(options.body));
    }
    req.end();
  });
}

function assert(condition, message) {
  if (!condition) {
    console.error(`❌ FAILED: ${message}`);
    process.exit(1);
  }
  console.log(`  ✓ ${message}`);
}

async function runTests() {
  console.log('========================================================================');
  console.log('🐟 KIRIBATI AI FISHERIES ASSISTANT — DAY 2 ACCEPTANCE TEST SUITE');
  console.log('🐡 TOPIC: FISH POISONING ADVISORY ZONES, GIS VALIDATION & RBAC WORKFLOW');
  console.log('========================================================================\n');

  // Step 0: Check health
  console.log('Checking API health endpoint...');
  const health = await fetchJson(`${API_BASE}/health`);
  assert(health.status === 200, 'Health endpoint responds with 200 OK');

  // Login accounts: Admin, Reviewer, Fisher
  console.log('\nAuthenticating test actors...');
  const adminLogin = await fetchJson(`${API_BASE}/auth/login`, {
    method: 'POST',
    body: { email: 'admin@fisheries.gov.ki', password: 'KiribatiAdmin2026!' },
  });
  assert(adminLogin.status === 200, 'Admin login succeeded');
  const adminToken = adminLogin.data.token;

  const reviewerLogin = await fetchJson(`${API_BASE}/auth/login`, {
    method: 'POST',
    body: { email: 'reviewer@fisheries.gov.ki', password: 'ReviewerPass2026!' },
  });
  assert(reviewerLogin.status === 200, 'Reviewer login succeeded');
  const reviewerToken = reviewerLogin.data.token;

  const fisherLogin = await fetchJson(`${API_BASE}/auth/login`, {
    method: 'POST',
    body: { email: 'teititeki@fisher.ki', password: 'FisherPass2026!' },
  });
  assert(fisherLogin.status === 200, 'Fisher login succeeded');
  const fisherToken = fisherLogin.data.token;

  // Retrieve species for tests
  const speciesRes = await fetchJson(`${API_BASE}/species`, {
    headers: { Authorization: `Bearer ${adminToken}` },
  });
  assert(speciesRes.status === 200 && speciesRes.data.data.length >= 2, 'Fetched species catalog');
  const speciesA = speciesRes.data.data[0]; // e.g. Te Atun or Te Bwere
  const speciesB = speciesRes.data.data[1]; // e.g. Te Bwere or Te Ikari

  // -------------------------------------------------------------------------
  // ACCEPTANCE TEST 1: An authorized editor creates a valid polygon
  // -------------------------------------------------------------------------
  console.log('\n[Test 1] An authorized editor creates a valid polygon');
  const validPolygonPayload = {
    title: '[DEMO - SYNTHETIC] North Tarawa Barrier Reef Caution',
    description: 'Precautionary ciguatoxin monitoring zone created by Marine Biologist.',
    concern: 'Ciguatera fish poisoning / benthic dinoflagellate monitoring',
    evidence: 'Clinical presentation of temperature reversal and tingling reported by Betio clinic.',
    classification: 'under_investigation',
    status: 'draft',
    source: 'MFMRD Marine Division',
    review_date: '2026-11-25',
    polygon_geojson: {
      type: 'Polygon',
      coordinates: [[
        [172.9500, 1.3800],
        [172.9700, 1.3800],
        [172.9700, 1.4000],
        [172.9500, 1.4000],
        [172.9500, 1.3800],
      ]],
    },
    species_ids: [speciesA.id],
  };

  const createRes = await fetchJson(`${API_BASE}/advisory-zones`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${reviewerToken}` },
    body: validPolygonPayload,
  });

  assert(createRes.status === 201, 'Authorized editor (Reviewer) created zone with HTTP 201');
  assert(createRes.data.data.title === validPolygonPayload.title, 'Created zone matches title');
  assert(createRes.data.data.status === 'draft', 'New zone status defaults to draft');
  assert(createRes.data.data.polygon_geojson.type === 'Polygon', 'Polygon GeoJSON preserved properly');
  const createdZoneId = createRes.data.data.id;

  // Non-editor (fisher) attempt to create is blocked
  const fisherCreate = await fetchJson(`${API_BASE}/advisory-zones`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${fisherToken}` },
    body: validPolygonPayload,
  });
  assert(fisherCreate.status === 403, 'Unauthorized fisher cannot create advisory zone (HTTP 403)');

  // -------------------------------------------------------------------------
  // ACCEPTANCE TEST 2: Multiple species can be assigned to one zone
  // -------------------------------------------------------------------------
  console.log('\n[Test 2] Multiple species can be assigned to one zone');
  const multiSpeciesPayload = {
    title: '[DEMO - SYNTHETIC] Central Lagoon Shoal Multi-Species Risk',
    concern: 'High CFP risk in multiple reef predators',
    classification: 'authority-confirmed',
    status: 'draft',
    polygon_geojson: {
      type: 'Polygon',
      coordinates: [[
        [172.9600, 1.3700],
        [172.9800, 1.3700],
        [172.9800, 1.3900],
        [172.9600, 1.3900],
        [172.9600, 1.3700],
      ]],
    },
    species_ids: [speciesA.id, speciesB.id],
  };

  const multiRes = await fetchJson(`${API_BASE}/advisory-zones`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${adminToken}` },
    body: multiSpeciesPayload,
  });

  assert(multiRes.status === 201, 'Advisory zone created with multiple species (HTTP 201)');
  assert(multiRes.data.data.species.length === 2, `Confirmed 2 species attached to zone`);
  const attachedSpeciesIds = multiRes.data.data.species.map((s) => s.id);
  assert(attachedSpeciesIds.includes(speciesA.id) && attachedSpeciesIds.includes(speciesB.id), 'Both species present in response');

  // -------------------------------------------------------------------------
  // ACCEPTANCE TEST 3: Invalid GeoJSON is rejected
  // -------------------------------------------------------------------------
  console.log('\n[Test 3] Invalid GeoJSON is rejected');
  // 3a: Non-polygon type
  const badTypeRes = await fetchJson(`${API_BASE}/advisory-zones`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${adminToken}` },
    body: {
      title: '[DEMO] Invalid Geometry Type',
      concern: 'Test',
      classification: 'suspected',
      polygon_geojson: { type: 'LineString', coordinates: [[172.9, 1.3], [172.91, 1.31]] },
    },
  });
  assert(badTypeRes.status === 422, 'Non-Polygon GeoJSON rejected with HTTP 422');
  assert(badTypeRes.data.code === 'INVALID_GEOJSON', 'Returns error code INVALID_GEOJSON');

  // 3b: Unclosed ring
  const unclosedRes = await fetchJson(`${API_BASE}/advisory-zones`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${adminToken}` },
    body: {
      title: '[DEMO] Unclosed Ring',
      concern: 'Test',
      classification: 'suspected',
      polygon_geojson: {
        type: 'Polygon',
        coordinates: [[
          [172.91, 1.35],
          [172.93, 1.35],
          [172.93, 1.37],
          [172.91, 1.37], // Missing closing vertex [172.91, 1.35]
        ]],
      },
    },
  });
  assert(unclosedRes.status === 422, 'Unclosed linear ring rejected with HTTP 422');

  // 3c: Out of bounds longitude
  const outOfBoundsRes = await fetchJson(`${API_BASE}/advisory-zones`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${adminToken}` },
    body: {
      title: '[DEMO] Out Of Bounds',
      concern: 'Test',
      classification: 'suspected',
      polygon_geojson: {
        type: 'Polygon',
        coordinates: [[
          [195.0, 1.35],
          [172.93, 1.35],
          [172.93, 1.37],
          [195.0, 1.35],
        ]],
      },
    },
  });
  assert(outOfBoundsRes.status === 422, 'Out-of-bounds coordinates (>180°) rejected with HTTP 422');

  // -------------------------------------------------------------------------
  // ACCEPTANCE TEST 4: An authorized approver publishes an advisory
  // -------------------------------------------------------------------------
  console.log('\n[Test 4] An authorized approver publishes an advisory');
  const publishRes = await fetchJson(`${API_BASE}/advisory-zones/${createdZoneId}/publish`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${adminToken}` },
  });
  assert(publishRes.status === 200, 'Administrator successfully published advisory (HTTP 200)');
  assert(publishRes.data.data.status === 'published', 'Zone status updated to published');
  assert(Boolean(publishRes.data.data.published_at), 'published_at timestamp populated');
  assert(Boolean(publishRes.data.data.approved_by_user_id), 'approved_by_user_id assigned to admin');

  // -------------------------------------------------------------------------
  // ACCEPTANCE TEST 5: Unauthorized publication is rejected
  // -------------------------------------------------------------------------
  console.log('\n[Test 5] Unauthorized publication is rejected');
  // Draft zone created by reviewer
  const draftZoneRes = await fetchJson(`${API_BASE}/advisory-zones`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${reviewerToken}` },
    body: {
      title: '[DEMO] Unapproved Draft Zone',
      concern: 'CFP observation',
      classification: 'suspected',
      status: 'draft',
      polygon_geojson: {
        type: 'Polygon',
        coordinates: [[
          [172.90, 1.30],
          [172.92, 1.30],
          [172.92, 1.32],
          [172.90, 1.32],
          [172.90, 1.30],
        ]],
      },
    },
  });
  const draftZoneId = draftZoneRes.data.data.id;

  // Reviewer attempt to publish is forbidden
  const reviewerPublish = await fetchJson(`${API_BASE}/advisory-zones/${draftZoneId}/publish`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${reviewerToken}` },
  });
  assert(reviewerPublish.status === 403, 'Reviewer publication attempt rejected with HTTP 403');
  assert(reviewerPublish.data.code === 'ADMIN_APPROVAL_REQUIRED', 'Error code specifies ADMIN_APPROVAL_REQUIRED');

  // Fisher attempt to publish is forbidden
  const fisherPublish = await fetchJson(`${API_BASE}/advisory-zones/${draftZoneId}/publish`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${fisherToken}` },
  });
  assert(fisherPublish.status === 403, 'Fisher publication attempt rejected with HTTP 403');

  // -------------------------------------------------------------------------
  // ACCEPTANCE TEST 6: Published zones appear on the administrator map and active queries;
  // drafts are hidden from regular fishers
  // -------------------------------------------------------------------------
  console.log('\n[Test 6] Published zones appear on map and active queries');
  const fisherZones = await fetchJson(`${API_BASE}/advisory-zones`, {
    headers: { Authorization: `Bearer ${fisherToken}` },
  });
  assert(fisherZones.status === 200, 'Fisher query returns HTTP 200');
  const allFisherZonesPublished = fisherZones.data.data.every((z) => z.status === 'published');
  assert(allFisherZonesPublished, 'All zones returned to fisher are active published advisories');
  const fisherHasDraft = fisherZones.data.data.some((z) => z.id === draftZoneId);
  assert(!fisherHasDraft, 'Confidential draft zone is NOT exposed to ordinary fishers');

  const adminZones = await fetchJson(`${API_BASE}/advisory-zones`, {
    headers: { Authorization: `Bearer ${adminToken}` },
  });
  assert(adminZones.status === 200, 'Admin query returns HTTP 200');
  const adminHasDraft = adminZones.data.data.some((z) => z.id === draftZoneId);
  const adminHasPublished = adminZones.data.data.some((z) => z.id === createdZoneId);
  assert(adminHasDraft && adminHasPublished, 'Admin map query receives both draft and published zones for management');

  // -------------------------------------------------------------------------
  // ACCEPTANCE TEST 7: Inside and outside coordinate tests return correct results
  // -------------------------------------------------------------------------
  console.log('\n[Test 7] Inside and outside coordinate tests return correct results');
  // createdZoneId was published above:
  // Coordinates: Lng [172.9500 to 172.9700], Lat [1.3800 to 1.4000]

  // Coordinate 1: Point INSIDE zone (Lat: 1.3900, Lng: 172.9600)
  const insideCoord = await fetchJson(`${API_BASE}/advisory-zones/check-point`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${fisherToken}` },
    body: {
      latitude: 1.3900,
      longitude: 172.9600,
    },
  });
  assert(insideCoord.status === 200, 'Check point query returns HTTP 200');
  assert(insideCoord.data.inside_advisory_zone === true, 'Coordinate correctly detected as INSIDE advisory zone');
  assert(insideCoord.data.matching_zones.some((z) => z.id === createdZoneId), 'Correct matching zone identified');

  // Coordinate 2: Point OUTSIDE zone (Lat: 1.2500, Lng: 172.9000 - Deep ocean south of Tarawa)
  const outsideCoord = await fetchJson(`${API_BASE}/advisory-zones/check-point`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${fisherToken}` },
    body: {
      latitude: 1.2500,
      longitude: 172.9000,
    },
  });
  assert(outsideCoord.status === 200, 'Outside check point returns HTTP 200');
  assert(outsideCoord.data.inside_advisory_zone === false, 'Coordinate correctly detected as OUTSIDE all active advisory zones');
  assert(outsideCoord.data.matching_zones.length === 0, 'Zero matching zones returned for safe coordinates');

  // -------------------------------------------------------------------------
  // ACCEPTANCE TEST 8: Changes are recorded in the audit log
  // -------------------------------------------------------------------------
  console.log('\n[Test 8] Changes are recorded in the audit log');
  // Fetch audit logs for createdZoneId (which was created, then published)
  const logsRes = await fetchJson(`${API_BASE}/advisory-zones/${createdZoneId}/audit-logs`, {
    headers: { Authorization: `Bearer ${adminToken}` },
  });
  assert(logsRes.status === 200, 'Audit logs endpoint returned HTTP 200');
  const logs = logsRes.data.audit_logs;
  assert(logs.length >= 2, `Found ${logs.length} audit log entries for zone`);

  const hasCreatedLog = logs.some((l) => l.action === 'created');
  const hasPublishedLog = logs.some((l) => l.action === 'published');
  assert(hasCreatedLog, 'Audit log contains "created" action with reviewer attribution');
  assert(hasPublishedLog, 'Audit log contains "published" action with admin approval attribution');

  // Cleanup created test zones
  try {
    await fetchJson(`${API_BASE}/advisory-zones/${createdZoneId}`, {
      method: 'DELETE',
      headers: { Authorization: `Bearer ${adminToken}` },
    });
    await fetchJson(`${API_BASE}/advisory-zones/${draftZoneId}`, {
      method: 'DELETE',
      headers: { Authorization: `Bearer ${adminToken}` },
    });
  } catch (_) {}

  console.log('\n========================================================================');
  console.log('🎉 ALL 8 DAY 2 ADVISORY ZONE ACCEPTANCE CRITERIA PASSED SUCCESSFULLY!');
  console.log('========================================================================\n');
}

runTests().catch((err) => {
  console.error('Fatal test error:', err);
  process.exit(1);
});

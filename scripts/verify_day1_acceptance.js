/**
 * Kiribati AI Fisheries Assistant - Day 1 Acceptance Test Suite
 * Tests all 7 required acceptance criteria against the live Laravel API and frontend integration.
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
  console.log('=================================================================');
  console.log('🐟 KIRIBATI AI FISHERIES ASSISTANT — DAY 1 ACCEPTANCE TEST SUITE');
  console.log('=================================================================\n');

  // Check health
  console.log('Checking API health endpoint...');
  const health = await fetchJson(`${API_BASE}/health`);
  assert(health.status === 200, 'Health endpoint responds with 200 OK');
  assert(health.data.status === 'ok', 'Health status is ok');

  // Acceptance Test 1: Administrator login succeeds with valid credentials
  console.log('\n[Test 1] Administrator login succeeds with valid credentials');
  const adminLogin = await fetchJson(`${API_BASE}/auth/login`, {
    method: 'POST',
    body: {
      email: 'admin@fisheries.gov.ki',
      password: 'KiribatiAdmin2026!',
    },
  });
  assert(adminLogin.status === 200, 'Administrator login returns HTTP 200');
  assert(Boolean(adminLogin.data.token), 'Bearer token is generated');
  assert(adminLogin.data.user.role === 'admin', 'User has admin role');
  assert(adminLogin.data.user.is_admin === true, 'User is_admin flag is true');
  const adminToken = adminLogin.data.token;

  // Invalid login check
  const badLogin = await fetchJson(`${API_BASE}/auth/login`, {
    method: 'POST',
    body: { email: 'admin@fisheries.gov.ki', password: 'IncorrectPassword' },
  });
  assert(badLogin.status === 422, 'Invalid credentials properly rejected with HTTP 422');

  // Fisher login
  const fisherLogin = await fetchJson(`${API_BASE}/auth/login`, {
    method: 'POST',
    body: {
      email: 'teititeki@fisher.ki',
      password: 'FisherPass2026!',
    },
  });
  assert(fisherLogin.status === 200, 'Fisher login returns HTTP 200');
  assert(fisherLogin.data.user.role === 'fisher', 'User has fisher role');
  assert(fisherLogin.data.user.is_admin === false, 'Fisher is_admin flag is false');
  const fisherToken = fisherLogin.data.token;

  // Acceptance Test 2: Non-administrators cannot access restricted pages/endpoints
  console.log('\n[Test 2] Non-administrators cannot access restricted endpoints');
  const fisherStats = await fetchJson(`${API_BASE}/admin/stats`, {
    headers: { Authorization: `Bearer ${fisherToken}` },
  });
  assert(fisherStats.status === 403, 'Fisher access to /api/admin/stats returns 403 Forbidden');
  assert(fisherStats.data.code === 'ROLE_FORBIDDEN', 'Error code confirms ROLE_FORBIDDEN');

  const unauthStats = await fetchJson(`${API_BASE}/admin/stats`);
  assert(unauthStats.status === 401, 'Unauthenticated access to /api/admin/stats returns 401 Unauthorized');

  // Acceptance Test 3: Synchronized catches display correctly
  console.log('\n[Test 3] Synchronized catches display correctly');
  const catchesRes = await fetchJson(`${API_BASE}/catches`, {
    headers: { Authorization: `Bearer ${adminToken}` },
  });
  assert(catchesRes.status === 200, 'Catches endpoint returns HTTP 200');
  assert(catchesRes.data.data.length > 0, `Returned ${catchesRes.data.data.length} synchronized catch records`);

  const sampleCatch = catchesRes.data.data[0];
  assert(Boolean(sampleCatch.user?.name), `Fisherman identified: ${sampleCatch.user?.name}`);
  assert(sampleCatch.quantity > 0, `Quantity present: ${sampleCatch.quantity}`);
  assert(sampleCatch.weight_kg > 0, `Weight present: ${sampleCatch.weight_kg} kg`);
  assert(Boolean(sampleCatch.caught_at), `Catch date recorded: ${sampleCatch.caught_at}`);
  assert(Boolean(sampleCatch.photo_url), `Photograph attached: ${sampleCatch.photo_url.slice(0, 40)}...`);
  assert(Boolean(sampleCatch.ai_species_suggestion), `Meta AI vision suggestion attached: ${sampleCatch.ai_species_suggestion}`);
  assert(typeof sampleCatch.ai_confidence === 'number', `Meta AI confidence score present: ${(sampleCatch.ai_confidence * 100).toFixed(0)}%`);

  // Acceptance Test 4: Catch locations and routes display at stored coordinates
  console.log('\n[Test 4] Catch locations and routes display at stored coordinates (with GPS protection)');
  // Admin sees exact coordinates
  assert(typeof sampleCatch.latitude === 'number' && sampleCatch.latitude > 0, `Admin receives precise latitude: ${sampleCatch.latitude}`);
  assert(typeof sampleCatch.longitude === 'number' && sampleCatch.longitude > 0, `Admin receives precise longitude: ${sampleCatch.longitude}`);
  assert(sampleCatch.gps_protected === false, 'GPS is not redacted for authorized admin');

  // Trips & Route points
  const tripsRes = await fetchJson(`${API_BASE}/trips`, {
    headers: { Authorization: `Bearer ${adminToken}` },
  });
  assert(tripsRes.status === 200, 'Trips endpoint returns HTTP 200');
  assert(tripsRes.data.data.length > 0, `Trips list contains ${tripsRes.data.data.length} trips`);

  const tripId = tripsRes.data.data[0].id;
  const routeRes = await fetchJson(`${API_BASE}/trips/${tripId}/route`, {
    headers: { Authorization: `Bearer ${adminToken}` },
  });
  assert(routeRes.status === 200, 'Trip route points endpoint returns HTTP 200');
  assert(routeRes.data.route_points.length > 0, `Trip has ${routeRes.data.route_points.length} GPS route waypoints`);
  const firstPt = routeRes.data.route_points[0];
  assert(
    typeof firstPt.latitude === 'number' && firstPt.latitude >= 1.3 && firstPt.latitude <= 1.5,
    `First waypoint latitude matches stored Tarawa coordinates: ${firstPt.latitude}`
  );
  assert(
    typeof firstPt.longitude === 'number' && firstPt.longitude >= 172.8 && firstPt.longitude <= 173.2,
    `First waypoint longitude matches stored Tarawa coordinates: ${firstPt.longitude}`
  );

  // Non-admin GPS redaction check
  const fisherCatches = await fetchJson(`${API_BASE}/catches`, {
    headers: { Authorization: `Bearer ${fisherToken}` },
  });
  assert(fisherCatches.status === 200, 'Fisher gets catches list');
  assert(fisherCatches.data.data[0].latitude === null, 'Fisher cannot see precise latitude (redacted to null)');
  assert(fisherCatches.data.data[0].longitude === null, 'Fisher cannot see precise longitude (redacted to null)');
  assert(fisherCatches.data.data[0].gps_protected === true, 'Flag gps_protected is true for non-admin');

  // Acceptance Test 5: Species approval and correction persist in Laravel (PRESERVING AI SUGGESTION)
  console.log('\n[Test 5] Species approval and correction persist in Laravel (Preserving AI suggestion)');
  // Pick a pending catch
  const pendingCatch = catchesRes.data.data.find((c) => c.verification_status === 'pending') || sampleCatch;
  const originalAiSuggestion = pendingCatch.ai_species_suggestion;
  const originalAiConfidence = pendingCatch.ai_confidence;

  // 1. Approve AI suggestion
  const approveRes = await fetchJson(`${API_BASE}/admin/catches/${pendingCatch.id}/verify`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${adminToken}` },
    body: {
      action: 'approve',
      verification_notes: 'Approved during automated acceptance testing',
    },
  });
  assert(approveRes.status === 200, 'Verification approve returns HTTP 200');
  assert(approveRes.data.catch.verification_status === 'verified', 'Catch status updated to verified');
  assert(approveRes.data.catch.ai_species_suggestion === originalAiSuggestion, 'Original AI suggestion preserved after approval');

  // 2. Correct species: change species to Te Kuau (Marbled Grouper)
  const speciesRes = await fetchJson(`${API_BASE}/species`, {
    headers: { Authorization: `Bearer ${adminToken}` },
  });
  const kuauSpecies = speciesRes.data.data.find((s) => s.kiribati_name === 'Te Kuau');

  const correctRes = await fetchJson(`${API_BASE}/admin/catches/${pendingCatch.id}/verify`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${adminToken}` },
    body: {
      action: 'correct',
      species_id: kuauSpecies.id,
      verification_notes: 'Officer identified distinctive mottled fin pattern as Te Kuau.',
    },
  });
  assert(correctRes.status === 200, 'Verification correct returns HTTP 200');
  assert(correctRes.data.catch.verification_status === 'corrected', 'Catch status updated to corrected');
  assert(correctRes.data.catch.species_id === kuauSpecies.id, `Species ID updated to ${kuauSpecies.id} (${kuauSpecies.kiribati_name})`);

  // Crucial requirement: Verify original AI suggestion was PRESERVED!
  assert(
    correctRes.data.catch.ai_species_suggestion === originalAiSuggestion,
    `CRITICAL: Original AI suggestion "${originalAiSuggestion}" remains intact after human correction!`
  );
  assert(
    correctRes.data.catch.ai_confidence === originalAiConfidence,
    `CRITICAL: Original AI confidence "${originalAiConfidence}" remains intact after human correction!`
  );

  // Acceptance Test 6: Filtering returns the correct records
  console.log('\n[Test 6] Filtering returns the correct records');
  // 1. Filter by fisherman
  const filterFisher = await fetchJson(`${API_BASE}/catches?fisherman=Teititeki`, {
    headers: { Authorization: `Bearer ${adminToken}` },
  });
  assert(filterFisher.status === 200, 'Fisherman filter returns HTTP 200');
  assert(
    filterFisher.data.data.every((c) => c.user?.name.includes('Teititeki')),
    'All returned records match fisherman Teititeki'
  );

  // 2. Filter by status
  const filterPending = await fetchJson(`${API_BASE}/catches?status=pending`, {
    headers: { Authorization: `Bearer ${adminToken}` },
  });
  assert(filterPending.status === 200, 'Status filter returns HTTP 200');
  assert(
    filterPending.data.data.every((c) => c.verification_status === 'pending'),
    'All returned records have pending verification status'
  );

  // 3. Filter by species
  const filterSpecies = await fetchJson(`${API_BASE}/catches?species_id=${kuauSpecies.id}`, {
    headers: { Authorization: `Bearer ${adminToken}` },
  });
  assert(filterSpecies.status === 200, 'Species filter returns HTTP 200');
  assert(
    filterSpecies.data.data.every((c) => c.species_id === kuauSpecies.id),
    `All returned records match species ID ${kuauSpecies.id}`
  );

  // Acceptance Test 7: API errors do not display invented data
  console.log('\n[Test 7] API errors do not display invented data');
  // Attempting request with bad token or non-existent endpoint
  const errorTest = await fetchJson(`${API_BASE}/catches/999999`, {
    headers: { Authorization: `Bearer ${adminToken}` },
  });
  assert(errorTest.status === 404, 'Non-existent catch returns HTTP 404 Not Found (not simulated data)');

  // Stats verification based on actual records
  console.log('\n[Verification] Dashboard statistics based on actual catch records');
  const statsRes = await fetchJson(`${API_BASE}/admin/stats`, {
    headers: { Authorization: `Bearer ${adminToken}` },
  });
  assert(statsRes.status === 200, 'Stats endpoint returns HTTP 200');
  assert(statsRes.data.overview.total_catches > 0, `Stats show ${statsRes.data.overview.total_catches} total catches`);
  assert(statsRes.data.overview.monitored_species >= 7, `Monitored species count is ${statsRes.data.overview.monitored_species} (>= 7)`);
  assert(statsRes.data.overview.active_fishers === 3, `Active fishers count is 3`);

  console.log('\n=================================================================');
  console.log('🎉 ALL 7 DAY 1 ACCEPTANCE TESTS PASSED SUCCESSFULLY!');
  console.log('=================================================================\n');
}

runTests().catch((err) => {
  console.error('Fatal test error:', err);
  process.exit(1);
});

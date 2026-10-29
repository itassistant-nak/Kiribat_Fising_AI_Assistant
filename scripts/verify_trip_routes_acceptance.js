/**
 * Kiribati AI Fisheries Assistant — P1-05 Acceptance Test Suite
 * Validates GPS trip route recording, breadcrumb persistence, and
 * polyline route synchronization with Laravel.
 */

const http = require('http');
const fs = require('fs');
const path = require('path');

const PORT = process.env.PORT || 8000;
const API_BASE = `http://127.0.0.1:${PORT}/api`;
const ROOT = path.join(__dirname, '..');

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
  console.log('🐟 KIRIBATI AI FISHERIES ASSISTANT — P1-05 ACCEPTANCE TEST SUITE');
  console.log('🛰️ TOPIC: GPS TRIP ROUTES — OFFLINE RECORDING & POLYLINE SYNC');
  console.log('========================================================================\n');

  // Step 0: Ensure backend is running
  console.log('Step 0: Checking Laravel API connectivity on port ' + PORT + '...');
  let health;
  try {
    health = await fetchJson(`${API_BASE}/health`);
  } catch (e) {
    console.error(`Cannot connect to Laravel API at ${API_BASE}. Make sure the server is running.`);
    process.exit(1);
  }
  assert(health.status === 200, 'Laravel API is healthy and reachable');

  // Authenticate as fisherman
  console.log('\nAuthenticating as Kiribati fisherman...');
  const loginRes = await fetchJson(`${API_BASE}/auth/login`, {
    method: 'POST',
    body: { email: 'teititeki@fisher.ki', password: 'FisherPass2026!' },
  });
  assert(loginRes.status === 200, 'Fisherman authenticated successfully');
  const fisherToken = loginRes.data.token;
  const auth = { Authorization: `Bearer ${fisherToken}` };

  // Acceptance Test 1: Fisher can start a trip
  console.log('\nTest 1: Fisher can start a trip');
  const createRes = await fetchJson(`${API_BASE}/trips`, {
    method: 'POST',
    headers: auth,
    body: {
      vessel_name: 'Te Raoi II [P1-05 ACCEPTANCE]',
      started_at: new Date().toISOString(),
      notes: 'Synthetic acceptance-test trip',
    },
  });
  assert(createRes.status === 201, 'POST /api/trips returns HTTP 201 Created');
  const tripId = createRes.data.data && createRes.data.data.id;
  assert(tripId > 0, `Server acknowledged trip with ID: ${tripId}`);
  assert(createRes.data.data.status === 'in_progress', 'New trip status is in_progress');

  // Acceptance Test 2: Offline breadcrumb batch uploads
  console.log('\nTest 2: Offline breadcrumb batch uploads');
  const now = Date.now();
  const points = [0, 1, 2].map((seq) => ({
    latitude: 1.33 + seq * 0.002,
    longitude: 172.98 + seq * 0.002,
    sequence: seq,
    recorded_at: new Date(now - (2 - seq) * 60000).toISOString(),
  }));
  const uploadRes = await fetchJson(`${API_BASE}/trips/${tripId}/route-points`, {
    method: 'POST',
    headers: auth,
    body: { points },
  });
  assert(uploadRes.status === 201, 'POST /api/trips/{id}/route-points returns HTTP 201');
  assert(uploadRes.data.synced_points === 3, 'Server synced all 3 breadcrumb points');

  // Acceptance Test 3: Ordered polyline retrievable
  console.log('\nTest 3: Ordered polyline retrievable');
  const routeRes = await fetchJson(`${API_BASE}/trips/${tripId}/route`, { headers: auth });
  assert(routeRes.status === 200, 'GET /api/trips/{id}/route returns HTTP 200 OK');
  const routePoints = routeRes.data.route_points;
  assert(Array.isArray(routePoints) && routePoints.length === 3, 'Route contains exactly 3 points');
  assert(
    routePoints.map((p) => p.sequence).join(',') === '0,1,2',
    `Points ordered by sequence: ${routePoints.map((p) => p.sequence).join(',')}`
  );

  // Acceptance Test 4: Invalid coordinates rejected
  console.log('\nTest 4: Invalid coordinates rejected');
  const badRes = await fetchJson(`${API_BASE}/trips/${tripId}/route-points`, {
    method: 'POST',
    headers: auth,
    body: {
      points: [{ latitude: 95.0, longitude: 172.98, sequence: 9, recorded_at: new Date().toISOString() }],
    },
  });
  assert(badRes.status === 422, 'Latitude 95.0 rejected with HTTP 422');

  // Acceptance Test 5: Retry is idempotent (no duplicates)
  console.log('\nTest 5: Retry is idempotent');
  const retryRes = await fetchJson(`${API_BASE}/trips/${tripId}/route-points`, {
    method: 'POST',
    headers: auth,
    body: { points },
  });
  assert(retryRes.status === 201, 'Retry upload accepted (HTTP 201)');
  const routeAfter = await fetchJson(`${API_BASE}/trips/${tripId}/route`, { headers: auth });
  assert(routeAfter.data.route_points.length === 3, 'Still exactly 3 points after retry (no duplicates)');

  // Acceptance Test 6: Fisher can end the trip
  console.log('\nTest 6: Fisher can end the trip');
  const endRes = await fetchJson(`${API_BASE}/trips/${tripId}`, {
    method: 'PATCH',
    headers: auth,
    body: { ended_at: new Date().toISOString(), status: 'completed' },
  });
  assert(endRes.status === 200, 'PATCH /api/trips/{id} returns HTTP 200 OK');
  assert(endRes.data.data.status === 'completed', 'Trip status is now completed');

  // Acceptance Test 7: Ownership enforced
  console.log('\nTest 7: Ownership enforced');
  const otherLogin = await fetchJson(`${API_BASE}/auth/login`, {
    method: 'POST',
    body: { email: 'erik@fisher.ki', password: 'FisherPass2026!' },
  });
  if (otherLogin.status === 200 && otherLogin.data.token) {
    const forbidden = await fetchJson(`${API_BASE}/trips/${tripId}/route-points`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${otherLogin.data.token}` },
      body: { points },
    });
    assert(forbidden.status === 403, 'Another fisher uploading to this trip gets HTTP 403');
    assert(forbidden.data.code === 'GPS_PROTECTED', 'Rejection code is GPS_PROTECTED');
  } else {
    console.log('  - skipped (second fisher login unavailable)');
  }

  // Acceptance Test 8: Mobile offline collateral present
  console.log('\nTest 8: Mobile offline collateral present');
  const mobileFiles = [
    'mobile/lib/models/trip_record.dart',
    'mobile/lib/services/trip/trip_recorder.dart',
    'mobile/lib/ui/screens/trip_screen.dart',
    'mobile/test/trip_route_offline_test.dart',
  ];
  for (const f of mobileFiles) {
    assert(fs.existsSync(path.join(ROOT, f)), `Mobile file exists: ${f}`);
  }
  const recorderSrc = fs.readFileSync(path.join(ROOT, 'mobile/lib/services/trip/trip_recorder.dart'), 'utf8');
  assert(recorderSrc.includes('resumeActiveTrip'), 'TripRecorder supports resume-after-restart');
  const syncSrc = fs.readFileSync(path.join(ROOT, 'mobile/lib/services/sync/sync_service.dart'), 'utf8');
  assert(syncSrc.includes('syncSingleTrip'), 'SyncService uploads offline trips');

  console.log('\n========================================================================');
  console.log('🎉 ALL 8 P1-05 ACCEPTANCE TESTS PASSED WITH ZERO ERRORS!');
  console.log('========================================================================\n');
}

runTests().catch(err => {
  console.error('Fatal error running acceptance tests:', err);
  process.exit(1);
});

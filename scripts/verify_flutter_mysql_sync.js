const http = require('http');

function request(method, path, body = null, token = null) {
  return new Promise((resolve, reject) => {
    const payload = body ? JSON.stringify(body) : null;
    const req = http.request({
      hostname: '127.0.0.1',
      port: 8000,
      path: path,
      method: method,
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        ...(payload ? { 'Content-Length': Buffer.byteLength(payload) } : {}),
        ...(token ? { 'Authorization': `Bearer ${token}` } : {})
      }
    }, res => {
      let data = '';
      res.on('data', chunk => data += chunk);
      res.on('end', () => {
        try {
          resolve({ status: res.statusCode, body: JSON.parse(data) });
        } catch (e) {
          resolve({ status: res.statusCode, body: data });
        }
      });
    });
    req.on('error', reject);
    if (payload) req.write(payload);
    req.end();
  });
}

async function run() {
  console.log('--- 1. Testing Fisher Authentication on Laravel API ---');
  const loginRes = await request('POST', '/api/auth/login', {
    email: 'teititeki@fisher.ki',
    password: 'FisherPass2026!'
  });
  if (loginRes.status !== 200 || !loginRes.body.token) {
    console.error('FAILED: Login failed', loginRes);
    process.exit(1);
  }
  const token = loginRes.body.token;
  console.log('PASS: Logged in successfully. Token acquired. User:', loginRes.body.user.name);

  console.log('--- 2. Inbound Sync: Fetching Advisory Zones ---');
  const zonesRes = await request('GET', '/api/advisory-zones', null, token);
  if (zonesRes.status !== 200) {
    console.error('FAILED: Fetch advisory zones', zonesRes);
    process.exit(1);
  }
  console.log(`PASS: Fetched ${zonesRes.body.data.length} published advisory zones for offline cache.`);

  console.log('--- 3. Inbound Sync: Fetching Species Catalog ---');
  const speciesRes = await request('GET', '/api/species', null, token);
  if (speciesRes.status !== 200) {
    console.error('FAILED: Fetch species', speciesRes);
    process.exit(1);
  }
  console.log(`PASS: Fetched ${speciesRes.body.data.length} species items from MySQL reference database.`);

  console.log('--- 4. Outbound Sync: Uploading Offline Catch to MySQL ---');
  const testUuid = 'flutter-offline-' + Date.now();
  const catchRes = await request('POST', '/api/catches', {
    client_uuid: testUuid,
    species_name_raw: 'Te Atun',
    weight_kg: 5.2,
    quantity: 2,
    latitude: 1.3450,
    longitude: 172.9650,
    captured_at: new Date().toISOString()
  }, token);

  if (catchRes.status !== 201 && catchRes.status !== 200) {
    console.error('FAILED: Catch upload', catchRes);
    process.exit(1);
  }
  const catchId = catchRes.body.catch?.id || catchRes.body.id;
  console.log(`PASS: Catch uploaded successfully to MySQL. Server Catch ID: ${catchId}, UUID: ${testUuid}`);

  console.log('--- 5. Duplicate Prevention: Retrying Same Catch UUID ---');
  const retryRes = await request('POST', '/api/catches', {
    client_uuid: testUuid,
    species_name_raw: 'Te Atun',
    weight_kg: 5.2,
    quantity: 2,
    latitude: 1.3450,
    longitude: 172.9650,
    captured_at: new Date().toISOString()
  }, token);

  if (retryRes.status !== 200 || !retryRes.body.idempotent_replay) {
    console.error('FAILED: Idempotent retry expected status 200 idempotent_replay, got:', retryRes);
    process.exit(1);
  }
  console.log('PASS: Replayed catch recognized idempotently without duplicating MySQL row.');

  console.log('--- 6. Trip and Route Breadcrumbs Sync ---');
  const tripRes = await request('POST', '/api/trips', {
    vessel_name: 'Tarawa Outrigger Canoe',
    started_at: new Date().toISOString(),
    notes: 'Artisanal reef fishing trip'
  }, token);

  if (tripRes.status !== 201 && tripRes.status !== 200) {
    console.error('FAILED: Trip creation', tripRes);
    process.exit(1);
  }
  const tripId = tripRes.body.data?.id || tripRes.body.trip?.id || tripRes.body.id;
  console.log(`PASS: Fishing trip created on MySQL. Trip ID: ${tripId}`);

  const pointsRes = await request('POST', `/api/trips/${tripId}/route-points`, {
    points: [
      { latitude: 1.3501, longitude: 172.9501, sequence: 1, recorded_at: new Date().toISOString() },
      { latitude: 1.3520, longitude: 172.9550, sequence: 2, recorded_at: new Date().toISOString() }
    ]
  }, token);

  if (pointsRes.status !== 200 && pointsRes.status !== 201) {
    console.error('FAILED: Route points sync', pointsRes);
    process.exit(1);
  }
  console.log('PASS: GPS route breadcrumbs synced to MySQL.');

  console.log('\n=== ALL FLUTTER <-> LARAVEL / MYSQL SYNCHRONIZATION STEPS VERIFIED LIVE ===');
}

run().catch(err => {
  console.error('ERROR in verification:', err);
  process.exit(1);
});

import type { NextConfig } from "next";

// BACKEND_URL is set in Vercel env vars to point at the Railway Laravel API.
// Falls back to local development server when not set.
const backendUrl = process.env.BACKEND_URL || "http://127.0.0.1:8000";

const nextConfig: NextConfig = {
  /* config options here */
  allowedDevOrigins: ['127.0.0.1', 'localhost', '127.0.0.1:3030', 'localhost:3030', '127.0.0.1:3000', 'localhost:3000'],
  cacheComponents: true,
  partialPrefetching: true,
  turbopack: {
    rules: {
      "*.css": {
        loaders: ["@tailwindcss/turbopack"],
        as: "*.css",
      },
    },
  },
  async rewrites() {
    return [
      {
        source: '/api/:path*',
        destination: `${backendUrl}/api/:path*`,
      },
      // Proxy uploaded photos and storage files from Laravel backend.
      // photo_url from the API returns absolute http://127.0.0.1:8000/uploads/...
      // URLs. The frontend rewrites them to relative /uploads/... paths so they
      // route through Next.js (no CORS, no cross-port issues).
      {
        source: '/uploads/:path*',
        destination: `${backendUrl}/uploads/:path*`,
      },
      {
        source: '/storage/:path*',
        destination: `${backendUrl}/storage/:path*`,
      },
    ];
  },
};

export default nextConfig;

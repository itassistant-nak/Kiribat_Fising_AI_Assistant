import type { NextConfig } from "next";

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
        destination: 'http://127.0.0.1:8000/api/:path*',
      },
      // Proxy uploaded photos and storage files from Laravel backend.
      // photo_url from the API returns absolute http://127.0.0.1:8000/uploads/...
      // URLs. The frontend rewrites them to relative /uploads/... paths so they
      // route through Next.js (no CORS, no cross-port issues).
      {
        source: '/uploads/:path*',
        destination: 'http://127.0.0.1:8000/uploads/:path*',
      },
      {
        source: '/storage/:path*',
        destination: 'http://127.0.0.1:8000/storage/:path*',
      },
    ];
  },
};

export default nextConfig;

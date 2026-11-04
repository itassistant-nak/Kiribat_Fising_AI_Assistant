#!/usr/bin/env python3
"""
Serve Flutter web build with correct MIME types (including application/wasm)
and proxy media uploads to the Laravel API.
Usage: python3 scripts/serve_flutter.py [PORT] [DIRECTORY]
"""
import http.server
import sys
import os
import urllib.request
import urllib.error

PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 8080
DIRECTORY = os.path.abspath(sys.argv[2] if len(sys.argv) > 2 else os.path.join(
    os.path.dirname(os.path.abspath(__file__)), '..', 'mobile', 'build', 'web'
))
BACKEND_ORIGIN = os.environ.get('BACKEND_ORIGIN', 'http://127.0.0.1:8000')


class WasmHandler(http.server.SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=os.path.abspath(DIRECTORY), **kwargs)

    extensions_map = {
        **http.server.SimpleHTTPRequestHandler.extensions_map,
        '.wasm': 'application/wasm',
        '.js':   'application/javascript',
        '.json': 'application/json',
        '.html': 'text/html',
        '.css':  'text/css',
        '.png':  'image/png',
        '.ico':  'image/x-icon',
        '.otf':  'font/otf',
        '.ttf':  'font/ttf',
    }

    def end_headers(self):
        self.send_header('Access-Control-Allow-Origin', '*')
        self.send_header('Access-Control-Allow-Methods', 'GET, HEAD, OPTIONS')
        self.send_header('Access-Control-Allow-Headers', '*')
        # Prevent caching of shell files during development and testing
        clean_path = self.path.split('?')[0]
        if clean_path in ('/', '/index.html', '/flutter_bootstrap.js', '/version.json'):
            self.send_header('Cache-Control', 'no-cache, no-store, must-revalidate')
            self.send_header('Pragma', 'no-cache')
            self.send_header('Expires', '0')
        super().end_headers()

    def do_OPTIONS(self):
        self.send_response(204)
        self.end_headers()

    def do_GET(self):
        # Transparently proxy uploaded fish photos to Laravel API
        if self.path.startswith('/uploads/') or self.path.startswith('/storage/'):
            backend_url = f"{BACKEND_ORIGIN}{self.path}"
            try:
                req = urllib.request.Request(backend_url)
                with urllib.request.urlopen(req, timeout=5) as resp:
                    self.send_response(resp.status)
                    for k, v in resp.headers.items():
                        if k.lower() in ('content-type', 'content-length', 'last-modified', 'cache-control'):
                            self.send_header(k, v)
                    self.end_headers()
                    self.wfile.write(resp.read())
                    return
            except urllib.error.HTTPError as e:
                self.send_error(e.code, f"Backend file error: {e.reason}")
                return
            except Exception as e:
                self.send_error(502, f"Backend connection failed: {e}")
                return

        return super().do_GET()

    def log_message(self, format, *args):
        print(f"  {self.address_string()} {format % args}")

if __name__ == '__main__':
    os.chdir(DIRECTORY)
    print(f"Flutter web server running at http://localhost:{PORT}")
    print(f"Serving from: {os.path.abspath(DIRECTORY)}")
    print(f"Proxying /uploads/* and /storage/* to: {BACKEND_ORIGIN}")
    print("Press Ctrl+C to stop.\n")
    with http.server.HTTPServer(('127.0.0.1', PORT), WasmHandler) as httpd:
        httpd.serve_forever()

#!/usr/bin/env python3
"""
Serve Flutter web build with correct MIME types (including application/wasm).
Usage: python3 scripts/serve_flutter.py
"""
import http.server
import sys
import os

PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 8080
DIRECTORY = sys.argv[2] if len(sys.argv) > 2 else os.path.join(
    os.path.dirname(os.path.abspath(__file__)), '..', 'mobile', 'build', 'web'
)

class WasmHandler(http.server.SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=DIRECTORY, **kwargs)

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

    def log_message(self, format, *args):
        print(f"  {self.address_string()} {format % args}")

if __name__ == '__main__':
    os.chdir(DIRECTORY)
    print(f"Flutter web server running at http://localhost:{PORT}")
    print(f"Serving from: {os.path.abspath(DIRECTORY)}")
    print("Press Ctrl+C to stop.\n")
    with http.server.HTTPServer(('0.0.0.0', PORT), WasmHandler) as httpd:
        httpd.serve_forever()

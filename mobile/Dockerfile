# ── Stage 1: Build Flutter web ───────────────────────────────────────────────
FROM ghcr.io/cirruslabs/flutter:stable AS builder

WORKDIR /app

# Cache pub dependencies separately from source
COPY pubspec.yaml pubspec.lock ./
RUN flutter pub get

# Copy source and build
COPY . .

# API_BASE_URL is passed as a Docker build arg from Railway Build Variables.
# It is baked into the Flutter binary at compile time via --dart-define.
ARG API_BASE_URL=http://127.0.0.1:8000
RUN flutter build web --release \
    --dart-define=API_BASE_URL=${API_BASE_URL} \
    --no-tree-shake-icons

# ── Stage 2: Serve with nginx ─────────────────────────────────────────────────
FROM nginx:1.27-alpine

COPY --from=builder /app/build/web /usr/share/nginx/html
COPY nginx.conf /etc/nginx/templates/default.conf.template

# Railway injects $PORT at runtime; nginx envsubst replaces it in the template
ENV PORT=8080
EXPOSE 8080

CMD ["/bin/sh", "-c", \
    "envsubst '$PORT' < /etc/nginx/templates/default.conf.template > /etc/nginx/conf.d/default.conf && nginx -g 'daemon off;'"]

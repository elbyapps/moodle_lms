# syntax=docker/dockerfile:1

# Stage 1: fetch Moodle core + plugins (same git refs as docker/php/Dockerfile).
# For dev bind-mount workflows pass SKIP_CLONE=true to skip the fetch.
FROM alpine:3.20 AS moodle_fetch
RUN apk add --no-cache git jq bash openssh-client && \
    mkdir -p -m 0700 /root/.ssh && \
    ssh-keyscan github.com >> /root/.ssh/known_hosts
ENV GIT_TERMINAL_PROMPT=0
WORKDIR /build
COPY build.sh moodle-config.json ./
RUN chmod +x build.sh
ARG SKIP_CLONE=false
RUN --mount=type=ssh if [ "$SKIP_CLONE" = "false" ]; then \
        ./build.sh; \
    else \
        echo "SKIP_CLONE=true, creating empty public/ for dev bind mount"; \
        mkdir -p moodle_app/public; \
    fi

# Stage 2: nginx runtime with Moodle's public tree baked in.
# nginx serves static assets directly (theme CSS/JS/images) and hands PHP off via FastCGI.
FROM nginx:alpine
COPY --from=moodle_fetch /build/moodle_app/public /var/www/html/moodle_app/public

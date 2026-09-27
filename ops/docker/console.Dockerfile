# The organizer console: a static application behind nginx.
#
# No server rendering, because nothing here is ever shared or crawled — every
# page needs a session and none of them has a link anybody else follows.
FROM node:22-alpine AS build

WORKDIR /repo

COPY package.json package-lock.json ./
COPY packages ./packages
COPY apps/organizer-web/package.json ./apps/organizer-web/package.json

RUN npm ci

COPY apps/organizer-web ./apps/organizer-web

RUN npm run build --workspace apps/organizer-web

# --- what actually ships --------------------------------------------------------
FROM nginx:alpine AS runtime

COPY --from=build /repo/apps/organizer-web/dist/organizer-web/browser /usr/share/nginx/html
COPY ops/docker/console.nginx.conf /etc/nginx/conf.d/default.conf
COPY ops/docker/console-entrypoint.sh /docker-entrypoint.d/40-stamp-addresses.sh

# Line endings stripped because a checkout on Windows writes CRLF, and a
# shebang ending in a carriage return is a script that will not run. It also
# writes the API's origin into the Content-Security-Policy (console.nginx.conf).
RUN sed -i 's/\r$//' /docker-entrypoint.d/40-stamp-addresses.sh \
    && chmod +x /docker-entrypoint.d/40-stamp-addresses.sh

EXPOSE 80

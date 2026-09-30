# The organizer console: a static application behind nginx.
#
# No server rendering, because nothing here is ever shared or crawled — every
# page needs a session and none of them has a link anybody else follows.
#
# And so no time-zone data, unlike the site's image and the API's: nothing in
# this image works out a time. The build prerenders no page, and nginx only
# serves files. Every time the console shows is worked out in the browser,
# from the browser's own copy of the zone rules (docs/OPERATIONS.md, "Time
# zones").
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

# Which build this is, stamped into the page with the DSN so every error
# report names it: the tag the images are built with (compose.prod.yml).
ARG RELEASE=
ENV SENTRY_RELEASE=${RELEASE}

EXPOSE 80

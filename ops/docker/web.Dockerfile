# The public site, which renders on a server.
#
# It has to: an event link shared into a group chat must unfurl with a title,
# an image and a price, and a client-rendered page unfurls as nothing. That is
# the primary sales channel of this platform, so this is a Node process and not
# a bucket of static files.
FROM node:22-alpine AS build

WORKDIR /repo

# The workspace root first, so an install layer survives a change to a
# component. The site depends on two local packages, which is why the whole
# workspace is here rather than one app.
COPY package.json package-lock.json ./
COPY packages ./packages
COPY apps/web/package.json ./apps/web/package.json

RUN npm ci

COPY apps/web ./apps/web

RUN npm run build --workspace apps/web

# --- what actually ships --------------------------------------------------------
FROM node:22-alpine AS runtime

ENV NODE_ENV=production

WORKDIR /app

COPY --from=build /repo/apps/web/dist/web ./

# Nothing here writes to disk, and nothing should be able to.
USER node

EXPOSE 4000

# ALLOWED_HOSTS, API_BASE_URL, CONSOLE_URL and PUBLIC_URL come from the
# environment. The first is not optional: Angular refuses to render for an
# unrecognised Host and falls back to client rendering silently, which is
# exactly the failure this whole process exists to avoid.
CMD ["node", "server/server.mjs"]

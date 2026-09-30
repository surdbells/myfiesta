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

# Which build this is, stamped into every page for the error reporter: the tag
# the images are built with (compose.prod.yml passes TAG).
ARG RELEASE=
ENV SENTRY_RELEASE=${RELEASE}

WORKDIR /app

# The time-zone rules. Every event time on the site is worked out here, by
# ICU inside Node, from ICU's own copy of IANA's database: not Alpine's tzdata
# package, which Node never reads, so installing it would change nothing. That
# copy is whatever edition was current when this Node was released, and the
# API's PHP reads another. They disagreed about a Vancouver night once British
# Columbia stopped changing its clocks. ICU reads newer zone files from
# ICU_TIMEZONE_FILES_DIR in place of its own, so these are ICU's files for the
# edition in ops/docker/tzdata-edition, the one the API reads too
# (api.Dockerfile). They come from the icu-data commit named in
# ops/docker/icu-timezones and must match the hashes there, so every build of
# a release gets the same bytes. The build stops unless Node then reports that
# edition: ICU falls back to its own copy, silently, when the files are not
# usable. The carriage returns go because a checkout on Windows writes them,
# and one left on the commit or a file name is a URL or a name that is wrong.
ENV ICU_TIMEZONE_FILES_DIR=/usr/local/share/icu/timezones
COPY ops/docker/tzdata-edition ops/docker/icu-timezones ops/docker/tz-version.mjs ./
RUN edition=$(grep -v '^#' tzdata-edition | tr -d '[:space:]') \
    && sed -i 's/\r$//' icu-timezones \
    && commit=$(awk '$1 == "commit" { print $2 }' icu-timezones) \
    && mkdir -p "$ICU_TIMEZONE_FILES_DIR" \
    && for file in zoneinfo64.res timezoneTypes.res metaZones.res windowsZones.res; do \
         wget -q -O "$ICU_TIMEZONE_FILES_DIR/$file" \
           "https://raw.githubusercontent.com/unicode-org/icu-data/$commit/tzdata/icunew/$edition/44/le/$file" \
         && want=$(awk -v file="$file" '$2 == file { print $1 }' icu-timezones) \
         && echo "$want  $ICU_TIMEZONE_FILES_DIR/$file" | sha256sum -c - \
         || { echo "$file for $edition, icu-data $commit: not the file ops/docker/icu-timezones names" >&2; exit 1; }; \
       done \
    && node tz-version.mjs "$edition"

COPY --from=build /repo/apps/web/dist/web ./

# Nothing here writes to disk, and nothing should be able to.
USER node

EXPOSE 4000

# ALLOWED_HOSTS, API_BASE_URL, CONSOLE_URL and PUBLIC_URL come from the
# environment, and SENTRY_DSN and SENTRY_ENVIRONMENT when errors are reported. The first is not optional: Angular refuses to render for an
# unrecognised Host and falls back to client rendering silently, which is
# exactly the failure this whole process exists to avoid.
CMD ["node", "server/server.mjs"]

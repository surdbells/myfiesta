# The API's nginx, as an image of its own.
#
# It used to be stock nginx with the config mounted in, which left it two
# things short. It had none of the API's public files, so the admin panel's
# scripts, styles and fonts went to PHP and came back 404 — an unstyled panel
# that could not run. And it had nowhere to learn which addresses are the load
# balancer, so it believed X-Forwarded-For from everybody.
#
# Built from the repository root, like the others:
#   docker build -f ops/docker/api-web.Dockerfile .
FROM nginx:alpine

# Only what is served as a file. index.php is here so try_files can find it;
# PHP itself runs in the api container, which has its own copy.
COPY apps/api/public /var/www/api/public

COPY ops/docker/api.nginx.conf /etc/nginx/conf.d/default.conf
COPY ops/docker/api-web-entrypoint.sh /docker-entrypoint.d/15-trusted-proxies.sh

# Line endings stripped because a checkout on Windows writes CRLF, and a
# shebang ending in a carriage return is a script that will not run.
RUN sed -i 's/\r$//' /docker-entrypoint.d/15-trusted-proxies.sh \
    && chmod 0755 /docker-entrypoint.d/15-trusted-proxies.sh

EXPOSE 80

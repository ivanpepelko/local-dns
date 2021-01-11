FROM composer AS deps

WORKDIR /app
COPY . /app
RUN rm -f composer.lock
RUN composer install --optimize-autoloader --prefer-dist

FROM php:8-cli-alpine

WORKDIR /app
COPY --from=deps /app /app

EXPOSE 53
ENTRYPOINT /app/run.php

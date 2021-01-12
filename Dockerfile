FROM php:8-cli-alpine

RUN docker-php-ext-install pcntl

WORKDIR /app
COPY . /app

RUN wget https://raw.githubusercontent.com/composer/getcomposer.org/76a7060ccb93902cd7576b67264ad91c8a2700e2/web/installer -O - -q | php -- --quiet

RUN rm -f composer.lock
RUN php composer.phar install --optimize-autoloader --prefer-dist

EXPOSE 53/udp
ENTRYPOINT /app/run.php

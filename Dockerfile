FROM php:8.1-cli

WORKDIR /app
COPY . .

RUN docker-php-ext-install pdo_mysql

EXPOSE 8080

CMD php -S 0.0.0.0:8080
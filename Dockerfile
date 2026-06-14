FROM php:8.2-cli
RUN apt-get update && apt-get install -y curl libcurl4-openssl-dev && docker-php-ext-install curl
WORKDIR /app
COPY . /app
CMD ["php", "-S", "0.0.0.0:8080", "-t", "/app"]

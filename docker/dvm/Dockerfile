# syntax=docker/dockerfile:1
ARG NODE_IMAGE=node:22-bookworm-slim
ARG PHP_IMAGE=php:8.4-apache-bookworm
FROM ${NODE_IMAGE} AS node
FROM ${PHP_IMAGE}
ARG DVM_SOURCE
ARG DVM_VERSION=2026.11.05-1862
LABEL org.opencontainers.image.title="DVM – DeinVereinsManager" \
      org.opencontainers.image.source="https://github.com/Slinghorse-Ship/DVM" \
      org.opencontainers.image.version="${DVM_VERSION}" \
      de.dvm.package.source="${DVM_SOURCE}"
COPY --from=node /usr/local/bin/node /usr/local/LICENSE /opt/dvm/node/
COPY . /opt/dvm/build/

# The optional secret is a public CA bundle for a build proxy, never an app key.
RUN --mount=type=secret,id=proxy_ca \
    set -eu; \
    if [ -f /run/secrets/proxy_ca ]; then export SSL_CERT_FILE=/run/secrets/proxy_ca; fi; \
    apt-get ${SSL_CERT_FILE:+-o Acquire::https::CaInfo=$SSL_CERT_FILE} update; \
    apt-get ${SSL_CERT_FILE:+-o Acquire::https::CaInfo=$SSL_CERT_FILE} install -y --no-install-recommends \
      libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev libicu-dev libzip-dev \
      python3 python3-venv python3-pil python3-wheel python3-charset-normalizer \
      libc-client2007e-dev libkrb5-dev libssl-dev; \
    if [ -n "${SSL_CERT_FILE:-}" ]; then export CURL_CA_BUNDLE="$SSL_CERT_FILE"; fi; \
    curl --fail --silent --show-error --location https://pecl.php.net/get/imap-1.0.3.tgz -o /tmp/imap.tgz; \
    echo '0c2c0b1f94f299004be996b85a424e3d11ff65ac0a3c980db3213289a4a3faaf  /tmp/imap.tgz' | sha256sum -c -; \
    docker-php-source extract; mkdir -p /usr/src/php/ext/imap; \
    tar -xzf /tmp/imap.tgz -C /usr/src/php/ext/imap --strip-components=1; \
    docker-php-ext-configure imap --with-kerberos --with-imap-ssl; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j2 pdo_mysql gd intl zip imap; \
    a2enmod rewrite headers setenvif; \
    mkdir -p /usr/share/doc/php-imap /usr/share/doc/nodejs; \
    mv /tmp/imap.tgz /usr/share/doc/php-imap/imap-1.0.3.tgz; \
    mv /opt/dvm/node/LICENSE /usr/share/doc/nodejs/LICENSE; \
    mv /opt/dvm/node/node /usr/local/bin/node; rmdir /opt/dvm/node; \
    rm -rf /var/lib/apt/lists/* /usr/src/php; \
    php -r 'foreach(["pdo_mysql","curl","mbstring","openssl","sodium","gd","intl","dom","xmlreader","xmlwriter","zip","zlib","fileinfo","imap"]as$e)if(!extension_loaded($e))throw new RuntimeException("Required extension missing: ".$e);'; \
    echo '05b762b77bcb9dcb4a7c91fcf5dded585b25bee7269ab3d3001d7c55fa1b324b  /opt/dvm/build/pypdf-6.18.0-py3-none-any.whl' | sha256sum -c -; \
    python3 -m venv --system-site-packages /opt/dvm/python; \
    /opt/dvm/python/bin/pip install --no-index --no-deps /opt/dvm/build/pypdf-6.18.0-py3-none-any.whl; \
    echo '7cf487764294ee791a4781f5a157bebce262a666ae4bbb87786760a9676c9378  /opt/dvm/build/reportlab-4.4.9.tar.gz' | sha256sum -c -; \
    /opt/dvm/python/bin/pip install --no-index --no-deps --no-build-isolation /opt/dvm/build/reportlab-4.4.9.tar.gz; \
    /opt/dvm/python/bin/python3 -c 'import reportlab,PIL,pypdf,pathlib;assert reportlab.Version=="4.4.9";assert all((pathlib.Path(reportlab.__file__).parent/"fonts"/n).is_file()for n in ["Vera.ttf","VeraBd.ttf","VeraIt.ttf","VeraBI.ttf"])'; \
    mv /opt/dvm/build/seed /opt/dvm/seed; \
    mv /opt/dvm/build/initialize.php /opt/dvm/build/apache-config.php /opt/dvm/; \
    mv /opt/dvm/build/entrypoint.sh /usr/local/bin/dvm-entrypoint; \
    mv /opt/dvm/build/dvm /usr/local/bin/dvm; \
    mv /opt/dvm/build/php.ini /usr/local/etc/php/conf.d/dvm.ini; \
    rm -rf /opt/dvm/build; \
    chmod 0755 /usr/local/bin/dvm-entrypoint /usr/local/bin/dvm; \
    mkdir -p /var/lib/dvm; chmod 0700 /var/lib/dvm
ENV VEREIN_PYTHON=/opt/dvm/python/bin/python3 VEREIN_PHOTO_NODE=/usr/local/bin/node
VOLUME ["/var/lib/dvm"]
EXPOSE 80
ENTRYPOINT ["dvm-entrypoint"]
CMD ["apache2-foreground"]

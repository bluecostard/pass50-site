FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        python3 \
        sudo \
        php-cli \
        php-mysql \
        php-curl \
        php-gd \
        php-mbstring \
        php-xml \
        php-sqlite3 \
        mariadb-server \
        mariadb-client \
    && rm -rf /var/lib/apt/lists/* \
    && id -u ubuntu >/dev/null 2>&1 || useradd -m -s /bin/bash ubuntu \
    && echo 'ubuntu ALL=(ALL) NOPASSWD:ALL' > /etc/sudoers.d/ubuntu \
    && chmod 0440 /etc/sudoers.d/ubuntu

USER ubuntu
WORKDIR /workspace

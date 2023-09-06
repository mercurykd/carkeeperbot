FROM alpine:latest
RUN apk add --no-cache --update php82 \
    php82-curl \
    php82-session \
    php82-pdo \
    php82-pdo_sqlite \
    php82-zip \
    openssh \
    curl \
    ffmpeg \
    && ln -s /usr/bin/php82 /usr/bin/php
RUN ssh-keygen -m PEM -t rsa -f /root/.ssh/id_rsa -N '' \
    && cat /root/.ssh/id_rsa.pub > /root/.ssh/authorized_keys

FROM alpine:3.19.1
RUN apk add --no-cache --update php82 \
    php82-curl \
    php82-session \
    php82-pdo \
    php82-pdo_sqlite \
    php82-zip \
    php82-pecl-ssh2 \
    openssh \
    curl \
    ffmpeg \
    && ssh-keygen -m PEM -t rsa -f /root/.ssh/id_rsa -N '' \
    && cat /root/.ssh/id_rsa.pub > /root/.ssh/authorized_keys \
    && mkdir /var/sync

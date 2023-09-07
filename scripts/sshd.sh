if [ $(cat /configs/pswd | wc -c) -eq 0 ]
then
    date +%s | sha256sum | base64 | head -c 32 > /configs/pswd
fi
echo "root:$(cat /configs/pswd)"|chpasswd
/usr/sbin/sshd -h /root/.ssh/id_rsa -D -e "$@" &
php cron.php

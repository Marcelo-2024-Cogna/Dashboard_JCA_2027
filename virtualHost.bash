<VirtualHost *:80>
    ServerName metricas.cogna.com.br
    Redirect permanent / https://metricas.cogna.com.br/
</VirtualHost>

<VirtualHost *:443>
    ServerName metricas.cogna.com.br
    DocumentRoot /apps/metricas_jca

    <Directory /apps/metricas_jca>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    SSLEngine on
    SSLCertificateFile /etc/ssl/certs/ssl-cert-snakeoil.pem
    SSLCertificateKeyFile /etc/ssl/private/ssl-cert-snakeoil.key

    ErrorLog ${APACHE_LOG_DIR}/metricas_jca_error.log
    CustomLog ${APACHE_LOG_DIR}/metricas_jca_access.log combined
</VirtualHost>
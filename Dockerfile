FROM ghcr.io/nightmeer/apachephp8:latest

LABEL org.opencontainers.image.title="WishVault" \
      org.opencontainers.image.description="Spielwünsche & LAN-Konfigurator für GameVault"

RUN apt-get update \
 && apt-get install -y --no-install-recommends cron tzdata \
 && rm -rf /var/lib/apt/lists/*

# Apache: kein Verzeichnis-Listing, ServerName gegen Startwarnung
COPY docker/apache-wishvault.conf /etc/apache2/conf-available/wishvault.conf
RUN a2enconf wishvault >/dev/null

# Anwendung ins Image kopieren
COPY app/ /var/www/app/
COPY public/ /var/www/html/
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh \
 && mkdir -p /var/www/data /etc/wishvault \
 && chown -R www-data:www-data /var/www/data

VOLUME /var/www/data
EXPOSE 80
HEALTHCHECK --interval=60s --timeout=5s --start-period=20s CMD php -r 'exit(@file_get_contents("http://127.0.0.1/assets/wishvault.svg") === false ? 1 : 0);'

CMD ["/entrypoint.sh"]

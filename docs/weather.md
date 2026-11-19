# Wetter auf der Umgebungsseite

Die Umgebungsseite ruft `api/weather.php` erst beim Annähern an den Wetterbereich ab. Der Browser kommuniziert dafür nur mit der eigenen Website. Der Server verwendet feste Koordinaten und übermittelt weder Besucher-IP noch den Gerätestandort an MET Norway. Die Ortszeit berechnet der Browser in `Europe/Lisbon`, einschließlich der portugiesischen Sommerzeit.

## MET Norway aktivieren

1. Die verantwortliche Person liest und akzeptiert die [MET Norway API-Nutzungsbedingungen](https://api.met.no/doc/TermsOfService). MET verlangt die menschliche Zustimmung vor der Nutzung. Die Daten dürfen unter der verlinkten offenen Lizenz mit Quellenangabe auch kommerziell verwendet werden.
2. PHP 8.1 oder neuer mit cURL und HTTPS-Zugriff auf `api.met.no` bereitstellen. Im PHP-Webprozess `CASA_WEATHER_MET_ENABLED=1` setzen. Die Anfragen identifizieren die Website und ihre Kontaktadresse im `User-Agent`. MET benötigt dafür keinen API-Schlüssel.
3. Das vorhandene private Verzeichnis `storage` neben dem Webroot für PHP les- und schreibbar machen. Falls es woanders liegt, `CASA_WEATHER_STORAGE_DIR` im PHP-Webprozess auf den absoluten Pfad außerhalb des Webroots setzen. `open_basedir` muss Zugriff auf das Verzeichnis zulassen.

Locationforecast und Sunrise werden getrennt gespeichert. Der Server berücksichtigt `Expires`, `Last-Modified` und Wartezeiten nach Fehlern. Prognosewerte gelten für die ausgewiesene Modellzeit. Die Tagesvorschau fasst die Modellwerte nach Lissabonner Kalendertagen zusammen.

## Inbetriebnahme prüfen

`/api/weather.php` im Browser aufrufen. Nach Aktivierung von MET sollten `current`, `forecast` und `sun` befüllt sein. Fehlende Teilbereiche werden auf der Umgebungsseite als nicht verfügbar gekennzeichnet. Die Ortszeit bleibt nutzbar.

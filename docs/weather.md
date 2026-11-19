# Wetter auf der Umgebungsseite

Die Umgebungsseite ruft `api/weather.php` erst beim Annähern an den Wetterbereich ab. Der Browser kommuniziert dafür nur mit der eigenen Website. Der Server verwendet feste Koordinaten und übermittelt weder Besucher-IP noch den Gerätestandort an die Datenanbieter. Die Ortszeit berechnet der Browser in `Europe/Lisbon`, einschließlich der portugiesischen Sommerzeit.

## MET Norway aktivieren

1. Die verantwortliche Person liest und akzeptiert die [MET Norway API-Nutzungsbedingungen](https://api.met.no/doc/TermsOfService). MET verlangt die menschliche Zustimmung vor der Nutzung. Die Daten dürfen unter der verlinkten offenen Lizenz mit Quellenangabe auch kommerziell verwendet werden.
2. PHP 8.1 oder neuer mit cURL und HTTPS-Zugriff auf `api.met.no` bereitstellen. Im PHP-Webprozess `CASA_WEATHER_MET_ENABLED=1` setzen. Die Anfragen identifizieren die Website und ihre Kontaktadresse im `User-Agent`. MET benötigt dafür keinen API-Schlüssel.
3. Das vorhandene private Verzeichnis `storage` neben dem Webroot für PHP les- und schreibbar machen. Falls es woanders liegt, `CASA_WEATHER_STORAGE_DIR` im PHP-Webprozess auf den absoluten Pfad außerhalb des Webroots setzen. `open_basedir` muss Zugriff auf das Verzeichnis zulassen.

Locationforecast und Sunrise werden getrennt gespeichert. Der Server berücksichtigt `Expires`, `Last-Modified` und Wartezeiten nach Fehlern. Prognosewerte gelten für die ausgewiesene Modellzeit. Die Tagesvorschau fasst die Modellwerte nach Lissabonner Kalendertagen zusammen.

## Copernicus Marine einrichten

Die Meerestemperatur ist die tägliche satellitengestützte ODYSSEA-Analyse für die nächstgelegene gültige Meereszelle vor Carvoeiro. Der Aktualisierungsjob lädt dafür einen kleinen Ausschnitt, prüft die Landmaske und wandelt die Temperatur von Kelvin in Grad Celsius um. Der [Produktdatensatz mit DOI](https://doi.org/10.48670/moi-00152) beschreibt die Tagesauflösung. Die Website zeigt den Datentag an.

1. Ein [kostenloses Copernicus Marine-Konto](https://help.marine.copernicus.eu/en/articles/4220332-how-to-register-for-copernicus-marine-service) einrichten. Der Dienst erlaubt auch kommerzielle Nutzung mit [Quellenangabe](https://help.marine.copernicus.eu/en/articles/4444611-citing-copernicus-marine-products-and-services).
2. Auf dem Server oder einem Rechner mit Zugriff auf denselben privaten Speicher [Copernicus Marine Toolbox](https://help.marine.copernicus.eu/en/articles/8287609-copernicus-marine-toolbox-api-open-a-dataset-or-read-a-dataframe-remotely) in einer Python-3.10+-Umgebung installieren. In einer virtuellen Umgebung genügt `python -m pip install 'copernicusmarine>=2.3,<3'`. Der Browser und PHP brauchen Python dafür nicht.
3. Unter dem Konto des Aktualisierungsjobs `copernicusmarine login` ausführen. Die [Zugangsdaten](https://help.marine.copernicus.eu/en/articles/8185007-copernicus-marine-toolbox-credentials-configuration) im privaten Benutzerverzeichnis mit restriktiven Rechten hinterlegen. Die Zugangsdaten gehören weder ins Repository noch in die PHP-Umgebung noch in die Cron-Zeile.
4. Mit der Python-Umgebung aus Schritt 2 einmal `python bin/update-sea-temperature.py` ausführen und anschließend etwa alle zwölf Stunden per Cron starten. Falls der Speicherpfad abweicht, dem Job denselben `CASA_WEATHER_STORAGE_DIR` wie PHP geben. Der Job erzeugt `sea-temperature.json` atomar im privaten Speicher. PHP benötigt Lesezugriff auf die Datei. Bei getrennten Benutzern die Übergabe des Snapshots im privaten Speicher mit dem Hostinganbieter einrichten.

Eine beispielhafte Cron-Zeile für Linux mit virtueller Umgebung außerhalb des Webroots lautet:

```cron
23 8,20 * * * /usr/bin/timeout 5m /pfad/private/weather-venv/bin/python /pfad/public_html/bin/update-sea-temperature.py
```

Wenn das Hosting keine Python-Jobs unterstützt, kann der Job extern laufen. Nur die daraus erzeugte Datei `sea-temperature.json` wird privat an den Server übertragen. Bei einem fehlgeschlagenen Abruf bleibt der letzte Snapshot erhalten. Nach vier Tagen zeigt der Wetterbereich die Meerestemperatur als nicht verfügbar an.

## Inbetriebnahme prüfen

`/api/weather.php` im Browser aufrufen. Nach Aktivierung von MET sollten `current`, `forecast` und `sun` befüllt sein. Nach einem erfolgreichen Copernicus-Job enthält `sea` eine Celsius-Temperatur und den UTC-Zeitpunkt der Analyse. Fehlende Teilbereiche werden auf der Umgebungsseite als nicht verfügbar gekennzeichnet. Die Ortszeit bleibt nutzbar.

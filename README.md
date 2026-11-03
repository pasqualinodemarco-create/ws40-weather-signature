# WS40 Weather Signature

Plugin WordPress per creare firme meteo dinamiche per siti, forum ed email.

Versione importata: **0.4.4**. Sorgenti e risorse originali del pacchetto allegato, senza modifiche funzionali.

## Download e installazione

Scarica [lo ZIP installabile v0.4.4](dist/ws40-weather-signature-v0.4.4.zip), poi caricalo da **WordPress → Plugin → Aggiungi plugin → Carica plugin**.

Lo ZIP generico «Code → Download ZIP» contiene anche documentazione e strumenti di sviluppo: per installare usa il pacchetto nella cartella `dist`.

La cartella del plugin rimane `ws40-weather-signature`.

## Funzioni

- Light Breeze, Midnight Weather, Boletus Forest e Custom / Personalizzabile.
- Banner 900 × 105 px e card 560 × 280 px.
- Dati da database locale WS40 oppure endpoint JSON configurabile.
- Rendering PNG con GD e alternativa SVG.
- Shortcode, codici HTML per email e BBCode per forum.

```text
[ws40_weather_signature layout="compact" style="minimal"]
[ws40_weather_signature layout="card" style="custom"]
[ws40_weather_signature_live refresh="300"]
```

## Requisiti dichiarati

WordPress 5.8+, PHP 7.4+. GD consigliata per PNG; font TrueType e mbstring migliorano il rendering.

Apri il menu **Weather Signature**, configura sorgente dati e stile, quindi esegui **Test JSON Connection**.
L'endpoint predefinito è quello pubblico di Cervignano Meteo: configurarlo per la propria stazione.

## Sviluppo

`python3 scripts/build.py` genera lo ZIP del plugin usando esclusivamente i file distribuibili.
Il controllo GitHub Actions verifica la sintassi PHP 7.4, 8.0, 8.2 e 8.3; non include verifiche per l'invio alla directory WordPress.org.

Vedi [la verifica del pacchetto](REVIEW.md) per risultati e limiti.

## Licenza e attribuzione

Il pacchetto dichiara GPL-2.0-or-later. L'header originale riporta **Author: John Martin**: attribuzione conservata, da verificare prima di modificarla.

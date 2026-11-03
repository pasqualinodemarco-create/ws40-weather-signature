# WS40 Weather Signature

A WordPress plugin that creates live weather signatures for websites, forums and email.

Current version: **0.4.5**. All built-in interface labels and documentation are in English.

## Download and installation

Download the [installable v0.4.5 ZIP](dist/ws40-weather-signature-v0.4.5.zip), then upload it through **WordPress → Plugins → Add New Plugin → Upload Plugin**.

The generic **Code → Download ZIP** archive also includes repository documentation and development tools. Use the package in `dist` to install the plugin.

The plugin directory remains `ws40-weather-signature`. When updating, confirm replacement of the existing installation and remove any duplicate copies.

## Features

- Light Breeze, Midnight Weather, Boletus Forest and Custom designs.
- A 900 × 105 px banner and a 560 × 280 px card.
- Weather readings from a local WS40 database or a configurable JSON endpoint.
- PNG rendering with GD, with an SVG fallback.
- WordPress shortcodes, email HTML and forum BBCode.

```text
[ws40_weather_signature layout="compact" style="minimal"]
[ws40_weather_signature layout="card" style="custom"]
[ws40_weather_signature_live refresh="300"]
```

## Requirements and setup

Declared minimum requirements: WordPress 5.8 and PHP 7.4. GD is recommended for PNG output; TrueType fonts and mbstring improve text rendering.

Open **Weather Signature**, configure the weather data source and design, save your settings, then run **Test JSON Connection** if using JSON.
The default endpoint is the public Cervignano Meteo endpoint. Configure the endpoint and field mappings for your own station.

The English update preserves existing user-supplied station names, captions, URLs, settings and data mappings.

## Development

Run `python3 scripts/build.py` to generate an installable ZIP containing only the plugin distribution files.
The GitHub Actions workflow is configured to check PHP syntax on PHP 7.4, 8.0, 8.2 and 8.3. It does not perform WordPress.org directory submission checks.

See the [package review](REVIEW.md) for findings and validation limits.

## Changes in 0.4.5

- Standardized the Custom theme label in English throughout the interface and plugin documentation.
- Translated the repository README and review report into English.
- Updated version metadata and rebuilt the installable package.

The original v0.4.4 import remains available in Git history.

## License and attribution

The package declares GPL-2.0-or-later. The original plugin header lists **Author: John Martin**. This attribution is preserved and should be verified before changing it.

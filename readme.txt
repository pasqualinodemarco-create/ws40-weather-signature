=== WS40 Weather Signature ===
Contributors: ws40
Tags: weather, signature, forum, email, weather station
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 0.4.4
License: GPLv2 or later

Live, dynamically updated weather signature images for WordPress, forums, and email.

== Description ==
Generate a 900 × 105 horizontal banner or a 560 × 280 weather card from local WS40 records or an external JSON weather API. Choose Light Breeze, Midnight Weather, Boletus Forest, or Custom / Personalizzabile.

== Features ==
* Four designs, each available in compact and card formats.
* Custom theme lets you change site/domain text, color palette, header and weather-panel colors, footer color, opacity, labels, backgrounds, and visible measurements.
* Custom theme can use a solid color, transparent background, or an HTTPS image URL (maximum 1.5 MB and 6 megapixels). WordPress safe HTTP requests block unsafe remote targets.
* Temperature, humidity, wind direction/speed, gusts, timestamp, and station status are read dynamically.
* WS40 local database or configurable external JSON endpoint.
* Built-in JSON connection diagnostics, manual cache refresh and field mapping repair for the Cervignano Meteo endpoint.
* Responsive WordPress shortcode, static image URLs, forum BBCode, email HTML, and a live HTML shortcode.
* Shortcode: [ws40_weather_signature layout="compact" style="custom"]
* Persistent plugin directory: ws40-weather-signature.

== Installation ==
1. Back up the current plugin and settings. Remove any duplicate WS40 Weather Signature installations before updating.
2. Upload the ZIP through WordPress Plugins > Add New > Upload Plugin and confirm replacement of the existing ws40-weather-signature folder.
3. Open the Weather Signature top-level menu.
4. Select one of the four designs and click Save Weather Signature Settings.
5. For Custom, configure colors, image URL, opacity, captions, and visible weather fields; save again and inspect its preview.
6. Set the JSON endpoint or database fields and use Test JSON Connection.

== Shortcodes ==
[ws40_weather_signature]
[ws40_weather_signature layout="card"]
[ws40_weather_signature style="light"]
[ws40_weather_signature style="dark"]
[ws40_weather_signature style="minimal"]
[ws40_weather_signature style="custom"]
[ws40_weather_signature style="custom" link="no"]
[ws40_weather_signature_live refresh="300"]

Image endpoint examples:
?ws40_weather_signature=compact&style=custom
?ws40_weather_signature=card&style=minimal

The older style=classic and style=scenic URLs are mapped to Light Breeze and Boletus Forest, respectively. No new installations of Classic/Sky Panorama are provided.

== Important ==
* PHP GD produces PNG images; servers without GD receive a self-contained SVG fallback.
* A system or theme TrueType font improves PNG typography. No font files are bundled.
* Images in external forums and email can be cached by third parties independently of WordPress.
* Do not use a private/local network URL as the Custom background. The plugin requires HTTPS and validates the remote response, image dimensions, and file type.
* Existing user options and data mappings are retained. No uninstall script deletes them.
* The plugin does not alter the WS40 weather database.

== Changelog ==
= 0.4.4 =
* Added a fourth Custom / Personalizzabile design with individual colors, opacity, optional remote image, and weather captions.
* Added PNG and SVG renderers dedicated to the Custom theme.
* Removed unused Classic rendering methods and their obsolete helpers, and dropped an unused older minimal graphic asset.
* Retained existing shortcode names, image URLs, WS40 data mapping, and stable directory.

= 0.4.3 =
* Rebuilt Boletus Forest with photographic mushrooms, live overlaid readings, and WS40 logo.

= 0.4.2 =
* Reduced banner height to 105 pixels; retired the Classic and Sky Panorama gallery entries while retaining URL compatibility.

= 0.4.1 =
* Compact theme sizing and spacing improvements.

= 0.3.9 =
* Repaired historical Cervignano JSON mapping aliases.

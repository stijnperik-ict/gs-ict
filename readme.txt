=== GS ICT ===
Contributors: gsict
Tags: security, two-factor, 2fa, totp, updates
Requires at least: 6.4
Requires PHP: 7.4
Stable tag: 0.4.0
License: GPLv2 or later

GS ICT voegt beheerfuncties toe voor WordPress: TOTP-2FA voor administratoraccounts en centrale controle over automatische updates.

== Functies ==

* TOTP-2FA per administratoraccount.
* Werkt met gangbare authenticator-apps die standaard TOTP ondersteunen.
* 8 eenmalige herstelcodes na activering.
* 2FA-geheim wordt versleuteld opgeslagen met Sodium of AES-256-GCM/OpenSSL.
* Beperking van foutieve 2FA-pogingen.
* Bescherming tegen direct hergebruik van dezelfde TOTP-code.
* Automatische achtergrondupdates voor core, plugins, thema's en vertalingen centraal uitschakelen.
* Geplande handmatige onderhoudsdatum met WordPress-beheermelding.
* Handmatige updates blijven beschikbaar via Dashboard > Updates.
* Security-dashboard met WordPress/PHP-status, openstaande updates en 2FA-dekking.
* Beveiligingslogboek voor logins, 2FA, rolwijzigingen, pluginacties en updates.
* Optioneel 2FA verplicht voor alle administrators.
* Herstelcodes opnieuw genereren na verificatie met de huidige authenticatorcode.

== Installatie ==

1. Upload de map `gs-ict` naar `/wp-content/plugins/` of upload het ZIP-bestand via Plugins > Nieuwe plugin > Plugin uploaden.
2. Activeer `GS ICT`.
3. Open in WordPress-beheer het menu `GS ICT`.
4. Stel het updatebeleid in.
5. Activeer 2FA voor je eigen administratoraccount en bewaar de herstelcodes.

== Belangrijk ==

Maak vooraf een back-up en test de plugin bij voorkeur eerst op staging. Als je het enige administratoraccount hebt, bewaar je herstelcodes buiten WordPress.

== Changelog ==

= 0.4.0 =
* Security-dashboard en beveiligingslogboek toegevoegd.
* Globale 2FA-verplichting voor alle administrators toegevoegd.
* Herstelcodes kunnen veilig opnieuw worden gegenereerd.
* Belangrijke beheer- en 2FA-gebeurtenissen worden maximaal 90 dagen gelogd.

= 0.3.0 =
* GitHub Releases-updater toegevoegd voor normale WordPress update-meldingen en handmatige updates via de pluginlijst.
* Updatepakket wordt als gs-ict.zip uit een GitHub Release opgehaald.
* Bestaande 2FA- en update-instellingen blijven behouden.

= 0.2.0 =
* Administrators kunnen 2FA verplichten voor andere administratoraccounts.
* Verplicht ingestelde gebruikers worden na login naar een eigen 2FA-installatiescherm gestuurd.
* QR-code plus handmatige TOTP-sleutel toegevoegd aan de setup.
* Bestaande 2FA-instellingen uit 0.1.0 blijven behouden.

= 0.1.0 =
* Eerste versie met TOTP-2FA en beheer van automatische updates.

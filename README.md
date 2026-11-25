osTicket-telegram
==============
An plugin for [osTicket](https://osticket.com) work for version 1.18+ which posts notifications to a [Telegram](https://telegram.org) channel/chat/group using Telegram Bot.

Install
--------
1. Clone this repo or download the zip file and place the contents into your `include/plugins` folder.
2. Install the plugin in osTicket and open its configuration.
3. Set **Bot Token** (from @BotFather) and **Chat ID** (user, group, channel id, or `@channelname`).
4. Choose which help topics and departments send a notification, and which fields are included in the message. Leave the lists empty to send every new ticket.

For more information about Telegram Bot, see: https://core.telegram.org/bots/api

Info
------
This plugin uses CURL and tested on osTicket (v1.17.3) and (v1.18)
This Plugin sent Data From Custom Form "Ticket Details". You can adjust it by yourself

Based on [(https://github.com/foamrider/osticket-telegram)]

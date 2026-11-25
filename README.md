# osTicket Telegram Bot

A plugin for [osTicket](https://osticket.com) 1.18+ that sends a [Telegram](https://telegram.org) message when a new ticket is opened. Replies, status changes, and other later updates are not sent.

All settings live in the plugin instance. Nothing has to be edited in the PHP files.

This repository is a fork of [haekalsutrisna/telegram-bot-osticketplugin](https://github.com/haekalsutrisna/telegram-bot-osticketplugin) by Muhammad Haekal Sutrisna. That project is based on [foamrider/osticket-telegram](https://github.com/foamrider/osticket-telegram).

## Requirements

- osTicket 1.18 or newer
- The PHP cURL extension, with outbound HTTPS access to `api.telegram.org`
- A Telegram bot token and a chat the bot is allowed to post in

## Install

1. Copy the `telegrambot` directory into the osTicket `include/plugins` folder. The plugin file must be `include/plugins/telegrambot/plugin.php`.
2. In the staff panel, open **Admin Panel → Manage → Plugins → Add New Plugin**.
3. Install **Telegram Bot Notification**, then open it and add an instance.
4. Fill in the instance, enable it, and save.

A disabled instance does not send anything. Create a ticket afterward to confirm that a message arrives.

## Connect the bot

Create a bot with [@BotFather](https://t.me/BotFather) and copy the token into **Bot Token**. The token looks like `123456789:AAExampleToken`. Treat it as a password: anyone who has it can send messages as the bot.

**Chat ID** is the destination. Accepted values:

| Destination | What to enter | What the bot needs |
| --- | --- | --- |
| Private chat with the bot | Numeric id, for example `123456789` | The user has opened the bot and sent `/start` |
| Group or supergroup | Numeric id, usually negative, for example `-1001234567890` | The bot is a member of the group |
| Public channel | `@channelname` | The bot is an administrator of the channel |
| Private channel | Numeric id, usually starting with `-100` | The bot is an administrator of the channel |

To read a numeric id, send a message in that chat (or `/start` in a private chat), then open:

`https://api.telegram.org/bot<token>/getUpdates`

Use the `chat.id` value from the response. Group and channel ids start with `-`. A public channel can use `@channelname` instead of a number. The name must be 5–32 characters, start with a letter, and contain only letters, digits, and underscores.

## Choose which tickets are sent

Two filters run together. A ticket is sent only when it passes both.

- **Help topic filter** is either **Only these help topics** or **All help topics except these**.
- **Department filter** works the same way for departments.

Leave **Help topics** or **Departments** empty to ignore that filter. With both lists empty, every new ticket is sent. A list takes effect only after at least one value is selected.

Example: help topics set to “only Billing” and departments set to “all except Internal” sends a Billing ticket that is not in Internal, and skips every other combination.

The lists are filled from the help topics and departments already defined in osTicket.

## Choose what the message contains

Each part has its own switch. Defaults are on, except the ticket message, which is off.

| Setting | Default | Contents |
| --- | --- | --- |
| Include ticket link | On | Staff-panel link to `scp/tickets.php?id=…`. The reader must be logged in. |
| Include requester name | On | Name on the ticket |
| Include requester email | On | Email on the ticket. Shown next to the name when both are on. |
| Include subject | On | Ticket subject, or “No subject” |
| Include help topic | On | Help topic name, or “No help topic” |
| Include ticket message | Off | Plain text of the opening message. HTML tags are removed. |
| Include form fields | On | Filled-in fields from the ticket forms |
| Form fields | Empty | Labels to keep, one per line. Empty means every filled-in field. |
| Debug message in error.log | Off | Extra lines for skipped tickets and successful sends |

**Form fields** matches labels and ignores case. Empty answers are left out. A ticket form with Priority, Serial number, and an empty Phone, and this list:

```
Priority
Serial number
```

sends Priority and Serial number only.

A message looks like this. Lines for disabled or empty parts are omitted.

```
New Ticket: #100234
Created by: Ada Lovelace (ada@example.com)
Subject: VPN access for the new laptop
Help Topic: Access request

Ticket Details:
Priority: High
Serial number: NX-2048

Message:
I cannot connect from the home office.
```

When the ticket link is enabled, the ticket number is a link. Telegram’s link preview is disabled. Messages longer than 4000 characters are cut and end with `…`.

## Check that it works

Open a ticket that matches the filters. The message should arrive in the configured chat within a few seconds.

Failures are written to the PHP error log as `Telegram plugin (…)`, including a missing token, a rejected chat id, a cURL error, or a non-200 response from Telegram. The bot token is shortened in those lines.

Turn on **Debug message in error.log** while setting the plugin up. Debug also records tickets skipped by a filter and each successful send. Turn it off again once delivery is confirmed.

If nothing arrives and the log is quiet, confirm the instance is enabled and that the ticket passed both filters. If Telegram returns “chat not found” or “bot is not a member”, add the bot to that group or make it a channel administrator, then create another ticket.

Telegram Bot API reference: https://core.telegram.org/bots/api

This fork is maintained by [Logimaxx](https://logimaxx.eu).

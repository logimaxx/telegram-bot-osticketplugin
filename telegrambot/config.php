<?php

require_once INCLUDE_DIR . 'class.plugin.php';

class TelegramPluginConfig extends PluginConfig {
    function getOptions() {
        return array(
            'telegram' => new SectionBreakField(array(
                'label' => 'Telegram Bot Notification',
                'hint' => 'Create a bot with @BotFather, then paste the token and the destination chat here.',
            )),
            'telegram-bot-token' => new TextboxField(array(
                'label' => 'Bot Token',
                'required' => true,
                'hint' => 'Token from @BotFather, for example 123456789:AAExampleToken',
                'configuration' => array('size'=>80, 'length'=>200),
            )),
            'telegram-chat-id' => new TextboxField(array(
                'label' => 'Chat ID',
                'required' => true,
                'hint' => 'User, group, or channel id (for example -1001234567890), or a public @channelname',
                'configuration' => array('size'=>40, 'length'=>64),
            )),
            'telegram-include-body' => new BooleanField(array(
                'label' => 'Include ticket message',
                'default' => 0,
            )),
            'debug' => new BooleanField(array(
                'label' => 'Debug message in error.log',
                'default' => 0,
            )),
        );
    }
}

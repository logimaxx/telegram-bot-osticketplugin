<?php

require_once INCLUDE_DIR . 'class.plugin.php';

class TelegramChoiceField extends ChoiceField {
    function getChoices($verbose=false, $options=array()) {
        if ($this->_choices === null || $verbose) {
            $choices = $this->get('choices');
            if (is_callable($choices)) {
                try {
                    $choices = call_user_func($choices);
                } catch (Throwable $e) {
                    $choices = array();
                }
            }
            $this->_choices = is_array($choices) ? $choices : array();
        }
        return $this->_choices;
    }
}

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
                // osTicket otherwise rejects values starting with - or @.
                'validator' => 'regex',
                'hint' => 'User, group, or channel id (for example -1001234567890), or a public @channelname',
                'configuration' => array(
                    'size'=>40,
                    'length'=>64,
                    'regex' => '/^(-?\d+|@[A-Za-z][A-Za-z0-9_]{4,31})$/',
                    'validator-error' => 'Enter a numeric chat id (group ids start with -) or a public @channelname',
                ),
            )),
            'filters' => new SectionBreakField(array(
                'label' => 'Which tickets are sent',
                'hint' => 'Leave a list empty to ignore that filter. Every new ticket is sent until you select at least one help topic or department.',
            )),
            'telegram-topic-mode' => new ChoiceField(array(
                'label' => 'Help topic filter',
                'choices' => array(
                    'include' => 'Only these help topics',
                    'exclude' => 'All help topics except these',
                ),
                'default' => 'include',
                'configuration' => array('multiselect' => false),
            )),
            'telegram-topic-ids' => new TelegramChoiceField(array(
                'label' => 'Help topics',
                'choices' => function () {
                    return $this->helpTopicChoices();
                },
                'configuration' => array('multiselect' => true),
                'hint' => 'Applies only when at least one help topic is selected.',
            )),
            'telegram-dept-mode' => new ChoiceField(array(
                'label' => 'Department filter',
                'choices' => array(
                    'include' => 'Only these departments',
                    'exclude' => 'All departments except these',
                ),
                'default' => 'include',
                'configuration' => array('multiselect' => false),
            )),
            'telegram-dept-ids' => new TelegramChoiceField(array(
                'label' => 'Departments',
                'choices' => function () {
                    return $this->departmentChoices();
                },
                'configuration' => array('multiselect' => true),
                'hint' => 'Applies only when at least one department is selected.',
            )),
            'message' => new SectionBreakField(array(
                'label' => 'What is included in the message',
            )),
            'telegram-include-link' => new BooleanField(array(
                'label' => 'Include ticket link',
                'default' => true,
            )),
            'telegram-include-name' => new BooleanField(array(
                'label' => 'Include requester name',
                'default' => true,
            )),
            'telegram-include-email' => new BooleanField(array(
                'label' => 'Include requester email',
                'default' => true,
            )),
            'telegram-include-subject' => new BooleanField(array(
                'label' => 'Include subject',
                'default' => true,
            )),
            'telegram-include-topic' => new BooleanField(array(
                'label' => 'Include help topic',
                'default' => true,
            )),
            'telegram-include-body' => new BooleanField(array(
                'label' => 'Include ticket message',
                'default' => false,
            )),
            'telegram-include-form' => new BooleanField(array(
                'label' => 'Include form fields',
                'default' => true,
            )),
            'telegram-form-fields' => new TextareaField(array(
                'label' => 'Form fields',
                'hint' => 'Field labels to include, one per line. Leave empty to send every filled-in form field. Matching ignores case.',
                'configuration' => array('rows'=>6, 'cols'=>60, 'html'=>false),
            )),
            'debug' => new BooleanField(array(
                'label' => 'Debug message in error.log',
                'default' => false,
            )),
        );
    }

    function helpTopicChoices() {
        try {
            if (class_exists('Topic') && method_exists('Topic', 'getHelpTopics')) {
                $topics = Topic::getHelpTopics(false, true);
                if (is_array($topics)) {
                    return $topics;
                }
            }
        } catch (Throwable $e) {
        }
        return array();
    }

    function departmentChoices() {
        try {
            if (class_exists('Dept') && method_exists('Dept', 'getDepartments')) {
                $depts = Dept::getDepartments(array('manager' => null));
                if (is_array($depts)) {
                    return $depts;
                }
            }
        } catch (Throwable $e) {
        }
        return array();
    }
}

<?php

require_once INCLUDE_DIR.'class.plugin.php';

try {
    require_once __DIR__ . '/config.php';
} catch (Throwable $e) {
    error_log('Telegram plugin: unable to load configuration class');
}

if (!class_exists('TelegramPluginConfig', false)) {
    class TelegramPluginConfig extends PluginConfig {
        function getOptions() {
            return array();
        }
    }
}

class TelegramPlugin extends Plugin {
    var $config_class = "TelegramPluginConfig";

    function bootstrap() {
        try {
            $this->runIsolated('bootstrap', function () {
                require_once INCLUDE_DIR.'class.signal.php';
                if (!class_exists('Signal', false)) {
                    return;
                }

                $plugin = $this;
                Signal::connect('ticket.created', function (...$args) use ($plugin) {
                    try {
                        $ticket = (isset($args[0]) && is_object($args[0])) ? $args[0] : null;
                        $plugin->onTicketCreated($ticket);
                    } catch (Throwable $e) {
                        $plugin->logFailure('ticket.created', $e);
                    }
                }, 'Ticket');
            });
        } catch (Throwable $e) {
            $this->logFailure('bootstrap', $e);
        }
    }

    function onTicketCreated($ticket = null, $data = null) {
        $this->runIsolated('ticket.created', function () use ($ticket) {
            $this->deliverNotification($ticket);
        });
    }

    function deliverNotification($ticket) {
        if (!is_object($ticket)) {
            return;
        }

        $config = $this->getConfig();
        $token = trim((string) $config->get('telegram-bot-token'));
        $chatId = trim((string) $config->get('telegram-chat-id'));
        $includeBody = $this->isEnabled($config->get('telegram-include-body'));
        $debug = $this->isEnabled($config->get('debug'));

        if ($token === '' || $chatId === '') {
            $this->logFailure('ticket.created', 'notification skipped, bot token or chat id is not set');
            return;
        }
        if (!$this->isValidToken($token) || !$this->isValidChatId($chatId)) {
            $this->logFailure('ticket.created', 'notification skipped, bot token or chat id has an invalid format');
            return;
        }

        $ticketLink = $this->ticketUrl($ticket);
        $ticketNumber = method_exists($ticket, 'getNumber') ? (string) $ticket->getNumber() : '';
        $title = (method_exists($ticket, 'getSubject') ? $ticket->getSubject() : '') ?: 'No subject';
        $createdBy = trim(
            (method_exists($ticket, 'getName') ? (string) $ticket->getName() : '')
            . ' ('
            . (method_exists($ticket, 'getEmail') ? (string) $ticket->getEmail() : '')
            . ')'
        );
        $helpTopic = 'No help topic';
        if (method_exists($ticket, 'getTopic') && $ticket->getTopic()) {
            $helpTopic = $ticket->getTopic()->getName();
        }

        $text = "<b>New Ticket:</b> <a href=\"" . $this->escapeHtml($ticketLink) . "\">#" . $this->escapeHtml($ticketNumber) . "</a>\n"
            . "<b>Created by:</b> " . $this->escapeHtml($createdBy) . "\n"
            . "<b>Subject:</b> " . $this->escapeHtml($title) . "\n"
            . "<b>Help Topic:</b> " . $this->escapeHtml($helpTopic) . "\n";

        $formData = $this->getTicketFormData($ticket);
        if ($formData !== '') {
            $text .= "\n<b>Ticket Details:</b>\n" . $formData;
        }

        if ($includeBody) {
            $body = $this->ticketBody($ticket);
            if ($body !== '') {
                $text .= "<b>Message:</b>\n" . $body;
            }
        }

        $this->sendToTelegram($token, array(
            'chat_id' => $chatId,
            'text' => $this->limitText($text),
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ), $debug, $ticketNumber);
    }

    function ticketUrl($ticket) {
        try {
            global $ost;
            if (!isset($ost) || !is_object($ost) || !method_exists($ost, 'getConfig')) {
                return '';
            }
            $config = $ost->getConfig();
            if (!is_object($config) || !method_exists($config, 'getUrl') || !method_exists($ticket, 'getId')) {
                return '';
            }
            return (string) $config->getUrl() . 'scp/tickets.php?id=' . $ticket->getId();
        } catch (Throwable $e) {
            $this->logFailure('ticket url', $e);
            return '';
        }
    }

    function ticketBody($ticket) {
        try {
            if (!method_exists($ticket, 'getLastMessage')) {
                return '';
            }
            $messageObj = $ticket->getLastMessage();
            if (!is_object($messageObj) || !method_exists($messageObj, 'getMessage')) {
                return '';
            }
            return $this->escapeHtml(strip_tags((string) $messageObj->getMessage()));
        } catch (Throwable $e) {
            $this->logFailure('message body', $e);
            return '';
        }
    }

    function sendToTelegram($token, $payload, $debug, $ticketNumber) {
        if (!function_exists('curl_init')) {
            $this->logFailure('send', 'cURL is not available');
            return;
        }

        $data = json_encode($payload);
        if ($data === false) {
            $this->logFailure('send', 'failed to encode the notification');
            return;
        }

        $ch = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
        if ($ch === false) {
            $this->logFailure('send', 'failed to initialize cURL');
            return;
        }

        try {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);

            $result = curl_exec($ch);
            if ($result === false) {
                $this->logFailure('send', 'request failed: ' . curl_error($ch));
                return;
            }

            $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($statusCode !== 200) {
                $description = $this->telegramError($result);
                $this->logFailure('send', 'HTTP ' . $statusCode . ($description !== '' ? ' ' . $description : ''));
                return;
            }

            if ($debug) {
                $this->logFailure('send', 'notification sent for ticket #' . $ticketNumber);
            }
        } catch (Throwable $e) {
            $this->logFailure('send', $e);
        } finally {
            try {
                curl_close($ch);
            } catch (Throwable $e) {
                $this->logFailure('send', $e);
            }
        }
    }

    function getTicketFormData($ticket) {
        $formData = '';

        try {
            if (!class_exists('DynamicFormEntry') || !method_exists('DynamicFormEntry', 'forTicket') || !method_exists($ticket, 'getId')) {
                return '';
            }

            $entries = DynamicFormEntry::forTicket($ticket->getId());
            if (!is_array($entries) && !($entries instanceof Traversable)) {
                return '';
            }

            foreach ($entries as $entry) {
                if (!is_object($entry) || !method_exists($entry, 'getAnswers')) {
                    continue;
                }
                $answers = $entry->getAnswers();
                if (!is_array($answers) && !($answers instanceof Traversable)) {
                    continue;
                }
                foreach ($answers as $answer) {
                    try {
                        if (!is_object($answer) || !method_exists($answer, 'getField') || !method_exists($answer, 'getValue')) {
                            continue;
                        }
                        $field = $answer->getField();
                        if (!$field || !method_exists($field, 'get')) {
                            continue;
                        }
                        $label = trim((string) $field->get('label'));
                        $value = trim($this->stringifyValue($answer->getValue()));
                        if ($label === '' || $value === '') {
                            continue;
                        }
                        $formData .= '<b>' . $this->escapeHtml($label) . ':</b> ' . $this->escapeHtml($value) . "\n";
                    } catch (Throwable $e) {
                        $this->logFailure('form field', $e);
                    }
                }
            }
        } catch (Throwable $e) {
            $this->logFailure('form data', $e);
        }

        return $formData;
    }

    function stringifyValue($value) {
        if (is_array($value)) {
            $parts = array();
            foreach ($value as $item) {
                $part = trim($this->stringifyValue($item));
                if ($part !== '') {
                    $parts[] = $part;
                }
            }
            return implode(', ', $parts);
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return (string) $value;
            }
            return '';
        }
        if ($value === null) {
            return '';
        }
        return (string) $value;
    }

    function escapeHtml($text) {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    function limitText($text) {
        $max = 4000;
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text, 'UTF-8') <= $max) {
                return $text;
            }
            $text = mb_substr($text, 0, $max, 'UTF-8');
            $lastOpen = mb_strrpos($text, '<', 0, 'UTF-8');
            $lastClose = mb_strrpos($text, '>', 0, 'UTF-8');
            if ($lastOpen !== false && ($lastClose === false || $lastOpen > $lastClose)) {
                $text = mb_substr($text, 0, $lastOpen, 'UTF-8');
            }
            return rtrim($text) . "\n…";
        }

        if (strlen($text) <= $max) {
            return $text;
        }
        return rtrim(substr($text, 0, $max)) . "\n…";
    }

    function isEnabled($value) {
        return $value === true || $value === 1 || $value === '1';
    }

    function isValidToken($token) {
        return (bool) preg_match('/^\d+:[A-Za-z0-9_-]+$/', $token);
    }

    function isValidChatId($chatId) {
        return (bool) preg_match('/^(-?\d+|@[A-Za-z][A-Za-z0-9_]{4,31})$/', $chatId);
    }

    function telegramError($result) {
        $decoded = json_decode((string) $result, true);
        if (is_array($decoded) && isset($decoded['description'])) {
            return (string) $decoded['description'];
        }
        return '';
    }

    /**
     * Run plugin work without affecting the osTicket request.
     * Signal handlers are invoked by osTicket with no try/catch of its own.
     */
    function runIsolated($label, $callback) {
        $handlerInstalled = false;
        $level = 0;
        $handling = false;

        try {
            $level = ob_get_level();
        } catch (Throwable $e) {
            $level = 0;
        }

        try {
            set_error_handler(function ($severity, $message, $file, $line) use (&$handling, $label) {
                if ($handling) {
                    return true;
                }
                $handling = true;
                if ($severity & (E_WARNING | E_USER_WARNING | E_RECOVERABLE_ERROR | E_USER_ERROR)) {
                    $this->logFailure($label, $message . ' in ' . $file . ':' . $line);
                }
                $handling = false;
                return true;
            });
            $handlerInstalled = true;
        } catch (Throwable $e) {
            $this->logFailure($label, $e);
        }

        try {
            @ob_start();
        } catch (Throwable $e) {
        }

        try {
            $callback();
        } catch (Throwable $e) {
            $this->logFailure($label, $e);
        }

        try {
            while (ob_get_level() > $level) {
                @ob_end_clean();
            }
        } catch (Throwable $e) {
            $this->logFailure($label, $e);
        }

        if ($handlerInstalled) {
            try {
                restore_error_handler();
            } catch (Throwable $e) {
                $this->logFailure($label, $e);
            }
        }
    }

    function logFailure($context, $error) {
        try {
            $message = ($error instanceof Throwable) ? $error->getMessage() : (string) $error;
            $message = str_replace(array("\r", "\n"), ' ', $message);
            if (strlen($message) > 500) {
                $message = substr($message, 0, 500);
            }
            error_log('Telegram plugin (' . $context . '): ' . $message);
        } catch (Throwable $e) {
        }
    }
}

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
        // osTicket side-loads this instance's config before bootstrap(), then
        // clears it. A later getConfig() call builds an empty namespace and
        // cannot see the saved token or chat id.
        try {
            $config = $this->getConfig();
        } catch (Throwable $e) {
            $this->logFailure('bootstrap', $e);
            $config = null;
        }

        try {
            $this->runIsolated('bootstrap', function () use ($config) {
                require_once INCLUDE_DIR.'class.signal.php';
                if (!class_exists('Signal', false) || !is_object($config)) {
                    return;
                }

                $plugin = $this;
                Signal::connect('ticket.created', function (...$args) use ($plugin, $config) {
                    try {
                        $ticket = (isset($args[0]) && is_object($args[0])) ? $args[0] : null;
                        $plugin->onTicketCreated($ticket, $config);
                    } catch (Throwable $e) {
                        $plugin->logFailure('ticket.created', $e);
                    }
                }, 'Ticket');
            });
        } catch (Throwable $e) {
            $this->logFailure('bootstrap', $e);
        }
    }

    function onTicketCreated($ticket = null, $config = null) {
        $this->runIsolated('ticket.created', function () use ($ticket, $config) {
            $this->deliverNotification($ticket, $config);
        });
    }

    function deliverNotification($ticket, $config = null) {
        if (!is_object($ticket)) {
            return;
        }
        if (!is_object($config)) {
            $this->logFailure('ticket.created', 'notification skipped, plugin instance configuration was not loaded');
            return;
        }
        $token = trim((string) $config->get('telegram-bot-token'));
        $chatId = trim((string) $config->get('telegram-chat-id'));
        $debug = $this->isEnabled($config->get('debug'));

        if ($token === '' || $chatId === '') {
            $this->logFailure('ticket.created', 'notification skipped, bot token or chat id is not set');
            return;
        }
        if (!$this->isValidToken($token) || !$this->isValidChatId($chatId)) {
            $this->logFailure('ticket.created', 'notification skipped, bot token or chat id has an invalid format');
            return;
        }

        $ticketNumber = method_exists($ticket, 'getNumber') ? (string) $ticket->getNumber() : '';
        if (!$this->passesFilter($this->ticketTopicId($ticket), $config->get('telegram-topic-ids'), $config->get('telegram-topic-mode'))) {
            if ($debug) {
                $this->logFailure('filter', 'ticket #' . $ticketNumber . ' skipped by help topic filter');
            }
            return;
        }
        if (!$this->passesFilter($this->ticketDeptId($ticket), $config->get('telegram-dept-ids'), $config->get('telegram-dept-mode'))) {
            if ($debug) {
                $this->logFailure('filter', 'ticket #' . $ticketNumber . ' skipped by department filter');
            }
            return;
        }

        $includeLink = $this->isEnabled($config->get('telegram-include-link'), true);
        $includeName = $this->isEnabled($config->get('telegram-include-name'), true);
        $includeEmail = $this->isEnabled($config->get('telegram-include-email'), true);
        $includeSubject = $this->isEnabled($config->get('telegram-include-subject'), true);
        $includeTopic = $this->isEnabled($config->get('telegram-include-topic'), true);
        $includeBody = $this->isEnabled($config->get('telegram-include-body'));
        $includeForm = $this->isEnabled($config->get('telegram-include-form'), true);

        $ticketLink = $includeLink ? $this->ticketUrl($ticket) : '';
        $number = $this->escapeHtml($ticketNumber);
        if ($ticketLink !== '') {
            $text = "<b>New Ticket:</b> <a href=\"" . $this->escapeHtml($ticketLink) . "\">#" . $number . "</a>\n";
        } else {
            $text = "<b>New Ticket:</b> #" . $number . "\n";
        }

        $createdBy = $this->requesterLine($ticket, $includeName, $includeEmail);
        if ($createdBy !== '') {
            $text .= "<b>Created by:</b> " . $this->escapeHtml($createdBy) . "\n";
        }

        if ($includeSubject) {
            $title = (method_exists($ticket, 'getSubject') ? $ticket->getSubject() : '') ?: 'No subject';
            $text .= "<b>Subject:</b> " . $this->escapeHtml($title) . "\n";
        }

        if ($includeTopic) {
            $helpTopic = 'No help topic';
            if (method_exists($ticket, 'getTopic') && $ticket->getTopic()) {
                $helpTopic = $ticket->getTopic()->getName();
            }
            $text .= "<b>Help Topic:</b> " . $this->escapeHtml($helpTopic) . "\n";
        }

        if ($includeForm) {
            $formData = $this->getTicketFormData($ticket, $this->formFieldAllowList($config->get('telegram-form-fields')));
            if ($formData !== '') {
                $text .= "\n<b>Ticket Details:</b>\n" . $formData;
            }
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

        $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
        $ch = curl_init($url);
        if ($ch === false) {
            $this->logFailure('send', 'failed to initialize cURL');
            return;
        }

        try {
            $result = false;
            $statusCode = 0;
            for ($redirects = 0; $redirects < 4; $redirects++) {
                if ($redirects > 0) {
                    curl_close($ch);
                    $ch = curl_init($url);
                    if ($ch === false) {
                        $this->logFailure('send', 'failed to initialize cURL');
                        return;
                    }
                }

                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
                curl_setopt($ch, CURLOPT_TIMEOUT, 8);

                $result = curl_exec($ch);
                if ($result === false) {
                    $this->logFailure('send', 'request failed: ' . curl_error($ch));
                    return;
                }

                $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if (!in_array($statusCode, array(301, 302, 303, 307, 308), true)) {
                    break;
                }

                $next = $this->telegramRedirect($url, (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL));
                if ($next === '' || $next === $url) {
                    break;
                }
                $url = $next;
            }

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
                if ($ch) {
                    curl_close($ch);
                }
            } catch (Throwable $e) {
                $this->logFailure('send', $e);
            }
        }
    }

    function getTicketFormData($ticket, $allowedLabels = null) {
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
                        if (is_array($allowedLabels) && !isset($allowedLabels[$this->normalizeLabel($label)])) {
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

    function requesterLine($ticket, $includeName, $includeEmail) {
        $name = '';
        $email = '';
        if ($includeName && method_exists($ticket, 'getName')) {
            $name = trim((string) $ticket->getName());
        }
        if ($includeEmail && method_exists($ticket, 'getEmail')) {
            $email = trim((string) $ticket->getEmail());
        }
        if ($name !== '' && $email !== '') {
            return $name . ' (' . $email . ')';
        }
        if ($name !== '') {
            return $name;
        }
        return $email;
    }

    function ticketTopicId($ticket) {
        try {
            if (method_exists($ticket, 'getTopicId')) {
                return (string) $ticket->getTopicId();
            }
            if (method_exists($ticket, 'getTopic')) {
                $topic = $ticket->getTopic();
                if (is_object($topic) && method_exists($topic, 'getId')) {
                    return (string) $topic->getId();
                }
            }
        } catch (Throwable $e) {
            $this->logFailure('filter', $e);
        }
        return '';
    }

    function ticketDeptId($ticket) {
        try {
            if (method_exists($ticket, 'getDeptId')) {
                return (string) $ticket->getDeptId();
            }
            if (method_exists($ticket, 'getDept')) {
                $dept = $ticket->getDept();
                if (is_object($dept) && method_exists($dept, 'getId')) {
                    return (string) $dept->getId();
                }
            }
        } catch (Throwable $e) {
            $this->logFailure('filter', $e);
        }
        return '';
    }

    function passesFilter($id, $selected, $mode) {
        $keys = $this->choiceKeys($selected);
        if (!$keys) {
            return true;
        }
        $found = in_array((string) $id, $keys, true);
        if ($this->filterMode($mode) === 'exclude') {
            return !$found;
        }
        return $found;
    }

    function filterMode($value) {
        if (is_array($value)) {
            $keys = $this->choiceKeys($value);
            $value = isset($keys[0]) ? $keys[0] : 'include';
        }
        return ((string) $value === 'exclude') ? 'exclude' : 'include';
    }

    function choiceKeys($value) {
        if ($value === null || $value === '' || $value === false) {
            return array();
        }
        if (!is_array($value)) {
            return is_scalar($value) ? array((string) $value) : array();
        }
        if (!$value) {
            return array();
        }

        $keys = array_keys($value);
        $isList = ($keys === range(0, count($value) - 1));
        $result = array();
        foreach ($value as $key => $item) {
            if ($isList) {
                if (is_scalar($item) && (string) $item !== '') {
                    $result[] = (string) $item;
                }
            } elseif ((string) $key !== '') {
                $result[] = (string) $key;
            }
        }
        return $result;
    }

    function formFieldAllowList($value) {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $allowed = array();
        foreach (preg_split('/\r\n|\r|\n/', $value) as $line) {
            $label = $this->normalizeLabel($line);
            if ($label !== '') {
                $allowed[$label] = true;
            }
        }
        return $allowed ? $allowed : null;
    }

    function normalizeLabel($label) {
        $label = trim((string) $label);
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($label, 'UTF-8');
        }
        return strtolower($label);
    }

    function isEnabled($value, $default = false) {
        if ($value === null || $value === '') {
            return $default;
        }
        return $value === true || $value === 1 || $value === '1';
    }

    function isValidToken($token) {
        return (bool) preg_match('/^\d+:[A-Za-z0-9_-]+$/', $token);
    }

    function isValidChatId($chatId) {
        return (bool) preg_match('/^(-?\d+|@[A-Za-z][A-Za-z0-9_]{4,31})$/', $chatId);
    }

    function telegramRedirect($current, $location) {
        $location = trim($location);
        if ($location === '') {
            return '';
        }

        if (!preg_match('#^https?://#i', $location)) {
            $parts = parse_url($current);
            if (!is_array($parts) || empty($parts['host'])) {
                return '';
            }
            $origin = (isset($parts['scheme']) ? $parts['scheme'] : 'https') . '://' . $parts['host'];
            if (!empty($parts['port'])) {
                $origin .= ':' . $parts['port'];
            }
            if ($location[0] !== '/') {
                $path = isset($parts['path']) ? $parts['path'] : '/';
                $location = rtrim(dirname($path), '/') . '/' . $location;
            }
            $location = $origin . $location;
        }

        if (strtolower((string) parse_url($location, PHP_URL_SCHEME)) !== 'https') {
            return '';
        }
        return $location;
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
                $inPlugin = ($file !== '' && strpos((string) $file, __DIR__) === 0);
                if ($inPlugin && ($severity & (E_WARNING | E_USER_WARNING | E_RECOVERABLE_ERROR | E_USER_ERROR))) {
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

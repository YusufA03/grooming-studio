<?php
// Keep the same clock as the rest of the system.
require_once __DIR__ . '/../config/timezone.php';

use PHPMailer\PHPMailer\PHPMailer;

// Sends the emails the system needs to send.
// Every method returns true if the email was handed to the mail server and
// false if not. None of them ever crashes the page: a failed email must not
// undo a saved booking or a cancellation.
class Mailer
{
    // Emails a booking confirmation to the client. When $rescheduled is true
    // it is the "updated confirmation" sent after the client moves the booking.
    public static function sendBookingConfirmation(array $booking, string $token, bool $rescheduled = false): bool
    {
        if (empty($booking['client_email'])) {
            return false; // this client has no email address
        }

        $config = self::loadConfig();
        if ($config === null) {
            return false;
        }

        $link = rtrim($config['site_url'], '/') . '/booking_confirmed.php?token=' . $token;

        $rows = self::detailRows($booking);
        if (!empty($booking['notes'])) {
            $rows['Your notes'] = $booking['notes'];
        }
        $rows['Reminder'] = 'We will email you a reminder about an hour before your appointment.';

        $heading = $rescheduled ? 'Your booking has been rescheduled' : 'Your booking is confirmed';
        $intro   = $rescheduled
            ? 'Your appointment with Executive Grooming Studio has been moved. Here are your new details:'
            : 'Thank you for booking with Executive Grooming Studio. Here are your details:';

        [$html, $text] = self::buildMessage(
            $heading,
            $booking['client_name'],
            $intro,
            $rows,
            'View or change your booking',
            $link
        );

        return self::send($config, $booking['client_email'], $booking['client_name'],
            $heading . ' - Executive Grooming Studio', $html, $text);
    }

    // Emails the client to say an appointment has been cancelled. $byClient
    // is true when the client cancelled it themselves, false when the salon did.
    public static function sendBookingCancellation(array $booking, bool $byClient = false): bool
    {
        if (empty($booking['client_email'])) {
            return false; // this client has no email address
        }

        $config = self::loadConfig();
        if ($config === null) {
            return false;
        }

        $link = rtrim($config['site_url'], '/') . '/index.php';

        [$html, $text] = self::buildMessage(
            'Your appointment has been cancelled',
            $booking['client_name'],
            $byClient
                ? 'As you asked, your appointment with Executive Grooming Studio has been cancelled. These were the details:'
                : 'We are sorry, but your appointment with Executive Grooming Studio has been cancelled. These were the details:',
            self::detailRows($booking),
            'Book a new appointment',
            $link
        );

        return self::send($config, $booking['client_email'], $booking['client_name'],
            'Your appointment has been cancelled - Executive Grooming Studio', $html, $text);
    }

    // Emails the client a reminder that their appointment is coming up.
    public static function sendBookingReminder(array $booking, string $token): bool
    {
        if (empty($booking['client_email'])) {
            return false; // this client has no email address
        }

        $config = self::loadConfig();
        if ($config === null) {
            return false;
        }

        $link  = rtrim($config['site_url'], '/') . '/booking_confirmed.php?token=' . $token;
        $start = new DateTimeImmutable($booking['start_time']);

        $rows = self::detailRows($booking);
        if (!empty($booking['notes'])) {
            $rows['Your notes'] = $booking['notes'];
        }

        [$html, $text] = self::buildMessage(
            'Your appointment is coming up',
            $booking['client_name'],
            'This is a friendly reminder that your appointment with Executive Grooming Studio starts in about an hour.',
            $rows,
            'View your booking',
            $link
        );

        return self::send($config, $booking['client_email'], $booking['client_name'],
            'Reminder: your appointment at ' . $start->format('H:i') . ' - Executive Grooming Studio', $html, $text);
    }

    // The lines of booking details shown in an email.
    private static function detailRows(array $booking): array
    {
        $start = new DateTimeImmutable($booking['start_time']);
        $end   = new DateTimeImmutable($booking['end_time']);

        return [
            'Reference' => '#' . (int) $booking['booking_id'],
            'Service'   => $booking['service_name'],
            'Stylist'   => $booking['stylist_name'],
            'Date'      => $start->format('l, j F Y'),
            'Time'      => $start->format('H:i') . ' to ' . $end->format('H:i'),
            'Price'     => 'R' . number_format((float) $booking['price'], 2) . ' (paid in the salon)',
        ];
    }

    // Builds the HTML and plain-text versions of a simple email.
    // Returns [html, text]. Everything is made safe before it goes into the HTML.
    private static function buildMessage(string $heading, string $clientName, string $intro, array $rows, string $linkText, string $link): array
    {
        $h = function (string $text): string {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        };

        $html  = '<div style="font-family: Arial, Helvetica, sans-serif; max-width: 520px; color: #222;">';
        $html .= '<h2 style="color: #14213d;">' . $h($heading) . '</h2>';
        $html .= '<p>Hi ' . $h($clientName) . ',</p>';
        $html .= '<p>' . $h($intro) . '</p>';
        $html .= '<table style="border-collapse: collapse; width: 100%;">';
        foreach ($rows as $label => $value) {
            $html .= '<tr>'
                   . '<td style="padding: 6px 12px 6px 0; font-weight: bold; vertical-align: top;">' . $h($label) . '</td>'
                   . '<td style="padding: 6px 0;">' . nl2br($h($value)) . '</td>'
                   . '</tr>';
        }
        $html .= '</table>';
        $html .= '<p><a href="' . $h($link) . '" style="color: #14213d;">' . $h($linkText) . '</a></p>';
        $html .= '<p style="color: #666;">Executive Grooming Studio</p>';
        $html .= '</div>';

        $text  = $heading . "\n\n";
        $text .= 'Hi ' . $clientName . ",\n\n" . $intro . "\n\n";
        foreach ($rows as $label => $value) {
            $text .= $label . ': ' . $value . "\n";
        }
        $text .= "\n" . $linkText . ': ' . $link . "\n\nExecutive Grooming Studio\n";

        return [$html, $text];
    }

    // Loads PHPMailer and the email settings. Returns the settings, or null
    // (after logging why) if either is missing.
    private static function loadConfig(): ?array
    {
        $autoloadFile = __DIR__ . '/../vendor/autoload.php';
        $configFile   = __DIR__ . '/../config/mail_config.php';

        if (!is_file($autoloadFile) || !is_file($configFile)) {
            error_log('Email not sent: PHPMailer (vendor/) or config/mail_config.php is missing.');
            return null;
        }
        require_once $autoloadFile;

        return require $configFile;
    }

    // Hands one email to the mail server.
    private static function send(array $config, string $toEmail, string $toName, string $subject, string $html, string $text): bool
    {
        try {
            $mail = new PHPMailer(true); // true = report problems as exceptions
            $mail->isSMTP();
            $mail->Host    = $config['host'];
            $mail->Port    = (int) $config['port'];
            $mail->Timeout = 10; // give up connecting after 10 seconds

            // PHPMailer also waits up to 300 seconds for each reply from the
            // mail server. Shorten that too, so a silent server cannot freeze
            // the page.
            $mail->getSMTPInstance()->Timelimit = 10;
            $mail->CharSet = 'UTF-8';

            if ($config['username'] !== '') {
                $mail->SMTPAuth = true;
                $mail->Username = $config['username'];
                $mail->Password = $config['password'];
            } else {
                $mail->SMTPAuth = false;
            }

            if ($config['encryption'] === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($config['encryption'] === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure  = '';
                $mail->SMTPAutoTLS = false;
            }

            $mail->setFrom($config['from_email'], $config['from_name']);
            $mail->addAddress($toEmail, $toName);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $html;
            $mail->AltBody = $text;
            $mail->send();

            return true;
        } catch (Throwable $ex) {
            error_log('Could not send email: ' . $ex->getMessage());
            return false;
        }
    }
}

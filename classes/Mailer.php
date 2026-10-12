<?php
use PHPMailer\PHPMailer\PHPMailer;

// Sends the emails the system needs to send.
class Mailer
{
    // Emails a booking confirmation to the client.
    // Returns true if the email was handed to the mail server, false if not.
    // It never crashes the page: a failed email must not undo a saved booking.
    public static function sendBookingConfirmation(array $booking, string $token): bool
    {
        $autoloadFile = __DIR__ . '/../vendor/autoload.php';
        $configFile   = __DIR__ . '/../config/mail_config.php';

        if (!is_file($autoloadFile) || !is_file($configFile)) {
            error_log('Confirmation email not sent: PHPMailer (vendor/) or config/mail_config.php is missing.');
            return false;
        }
        require_once $autoloadFile;
        $config = require $configFile;

        $start = new DateTimeImmutable($booking['start_time']);
        $end   = new DateTimeImmutable($booking['end_time']);
        $link  = rtrim($config['site_url'], '/') . '/booking_confirmed.php?token=' . $token;
        $price = number_format((float) $booking['price'], 2);

        // Makes text safe to put inside the HTML email.
        $h = function (string $text): string {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        };

        $rows = [
            'Reference' => '#' . (int) $booking['booking_id'],
            'Service'   => $booking['service_name'],
            'Stylist'   => $booking['stylist_name'],
            'Date'      => $start->format('l, j F Y'),
            'Time'      => $start->format('H:i') . ' to ' . $end->format('H:i'),
            'Price'     => 'R' . $price . ' (paid in the salon)',
        ];
        if (!empty($booking['notes'])) {
            $rows['Your notes'] = $booking['notes'];
        }

        // The HTML version of the email.
        $html  = '<div style="font-family: Arial, Helvetica, sans-serif; max-width: 520px; color: #222;">';
        $html .= '<h2 style="color: #14213d;">Your booking is confirmed</h2>';
        $html .= '<p>Hi ' . $h($booking['client_name']) . ',</p>';
        $html .= '<p>Thank you for booking with Executive Grooming Studio. Here are your details:</p>';
        $html .= '<table style="border-collapse: collapse; width: 100%;">';
        foreach ($rows as $label => $value) {
            $html .= '<tr>'
                   . '<td style="padding: 6px 12px 6px 0; font-weight: bold; vertical-align: top;">' . $h($label) . '</td>'
                   . '<td style="padding: 6px 0;">' . nl2br($h($value)) . '</td>'
                   . '</tr>';
        }
        $html .= '</table>';
        $html .= '<p><a href="' . $h($link) . '" style="color: #14213d;">View your booking</a></p>';
        $html .= '<p style="color: #666;">Executive Grooming Studio</p>';
        $html .= '</div>';

        // The plain-text version (for email programs that do not show HTML).
        $text  = "Your booking is confirmed\n\n";
        $text .= 'Hi ' . $booking['client_name'] . ",\n\n";
        $text .= "Thank you for booking with Executive Grooming Studio. Here are your details:\n\n";
        foreach ($rows as $label => $value) {
            $text .= $label . ': ' . $value . "\n";
        }
        $text .= "\nView your booking: " . $link . "\n\nExecutive Grooming Studio\n";

        try {
            $mail = new PHPMailer(true); // true = report problems as exceptions
            $mail->isSMTP();
            $mail->Host    = $config['host'];
            $mail->Port    = (int) $config['port'];
            $mail->Timeout = 10; // give up connecting after 10 seconds

            // PHPMailer also waits up to 300 seconds for each reply from the
            // mail server. Shorten that too, so a silent server cannot freeze
            // the booking page.
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
            $mail->addAddress($booking['client_email'], $booking['client_name']);
            $mail->isHTML(true);
            $mail->Subject = 'Your booking is confirmed - Executive Grooming Studio';
            $mail->Body    = $html;
            $mail->AltBody = $text;
            $mail->send();

            return true;
        } catch (Throwable $ex) {
            error_log('Could not send confirmation email: ' . $ex->getMessage());
            return false;
        }
    }
}

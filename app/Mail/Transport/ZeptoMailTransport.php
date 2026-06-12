<?php

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;

class ZeptoMailTransport extends AbstractTransport
{
    public function __construct(
        private readonly string $token,
        private readonly string $endpoint = 'https://api.zeptomail.com/v1.1/email',
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        if ($this->token === '') {
            throw new TransportException(
                'ZeptoMail token is not configured. Set ZEPTOMAIL_TOKEN in .env.'
            );
        }

        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $payload = $this->buildPayload($email);

        $response = Http::timeout(30)
            ->withHeaders([
                'Accept' => 'application/json',
                'Authorization' => 'Zoho-enczapikey '.$this->token,
            ])
            ->post($this->endpoint, $payload);

        if (! $response->successful()) {
            throw new TransportException(
                sprintf('ZeptoMail API error (%s): %s', $response->status(), $response->body())
            );
        }
    }

    private function buildPayload(Email $email): array
    {
        $from = $email->getFrom()[0] ?? null;

        if ($from === null) {
            throw new TransportException('ZeptoMail requires a from address.');
        }

        $payload = [
            'from' => [
                'address' => $from->getAddress(),
                'name' => $from->getName() ?: config('mail.from.name', ''),
            ],
            'to' => $this->formatRecipients($email->getTo()),
            'subject' => $email->getSubject() ?? '',
        ];

        if ($email->getHtmlBody()) {
            $payload['htmlbody'] = $email->getHtmlBody();
        } elseif ($email->getTextBody()) {
            $payload['textbody'] = $email->getTextBody();
        } else {
            throw new TransportException('ZeptoMail requires email body content.');
        }

        if ($email->getCc()) {
            $payload['cc'] = $this->formatRecipients($email->getCc());
        }

        if ($email->getBcc()) {
            $payload['bcc'] = $this->formatRecipients($email->getBcc());
        }

        if ($email->getReplyTo()) {
            $payload['reply_to'] = array_map(
                fn (Address $address) => [
                    'address' => $address->getAddress(),
                    'name' => $address->getName() ?: '',
                ],
                $email->getReplyTo()
            );
        }

        return $payload;
    }

    /**
     * @param  Address[]  $addresses
     * @return array<int, array<string, array<string, string>>>
     */
    private function formatRecipients(array $addresses): array
    {
        return array_map(
            fn (Address $address) => [
                'email_address' => [
                    'address' => $address->getAddress(),
                    'name' => $address->getName() ?: '',
                ],
            ],
            $addresses
        );
    }

    public function __toString(): string
    {
        return 'zeptomail';
    }
}

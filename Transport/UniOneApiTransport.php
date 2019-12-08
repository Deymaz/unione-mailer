<?php

namespace Symfony\Component\Mailer\Bridge\UniOne\Transport;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractApiTransport;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Component\Mailer\Exception\HttpTransportException;

class UniOneApiTransport extends AbstractApiTransport
{
    private const HOST = 'one.unisender.com';
    private const METHOD = '/transactional/api/v1/email/send.json';
    private const DEFAULT_LOCALE = 'en';

    /**
     * @var string
     */
    private $apiKey;

    /**
     * @var string
     */
    private $username;

    /**
     * @var string
     */
    private $locale;

    public function __construct(
        string $apiKey,
        string $username,
        string $locale = null,
        HttpClientInterface $client = null,
        EventDispatcherInterface $dispatcher = null,
        LoggerInterface $logger = null
    ) {
        $this->apiKey = $apiKey;
        $this->username = $username;
        $this->locale = $locale ?? self::DEFAULT_LOCALE;

        parent::__construct($client, $dispatcher, $logger);
    }

    public function __toString(): string
    {
        return sprintf("unione+api://%s", $this->getEndpoint());
    }

    private function getEndpoint(): string
    {
        return sprintf('%s/%s%s',self::HOST, $this->locale, self::METHOD);
    }

    protected function doSendApi(SentMessage $sentMessage, Email $email, Envelope $envelope): ResponseInterface
    {
        $response = $this->client->request('POST', 'https://' . $this->getEndpoint(), [
            'json' => $this->getPayload($email, $envelope),
        ]);

        $result = $response->toArray(false);
        if (200 !== $response->getStatusCode()) {
            if ('error' === ($result['status'] ?? false)) {
                throw new HttpTransportException(
                    sprintf('Unable to send an email: %s (code %s).', $result['message'], $result['code']),
                    $response
                );
            }
            throw new HttpTransportException(sprintf('Unable to send an email (code %s).', $result['code']), $response);
        }
        $sentMessage->setMessageId($result['job_id']);

        return $response;
    }

    private function getPayload(Email $email, Envelope $envelope): array
    {
        $payload = [
            'api_key' => $this->apiKey,
            'username' => $this->username,
            'message' => [
                'body' => [
                    'html' => $email->getHtmlBody(),
                    'text' => $email->getTextBody(),
                    ],
                'subject' => $email->getSubject(),
                'from_email' => $envelope->getSender()->getAddress(),
            ],
        ];

        if ('' !== $envelope->getSender()->getName()) {
            $payload['message']['from_name'] = $envelope->getSender()->getName();
        }

        foreach ($this->getRecipients($email, $envelope) as $recipient) {
            $payload['message']['recipients'][] = ['email' => $recipient['email']];
        }

        foreach ($email->getAttachments() as $attachment) {
            $headers = $attachment->getPreparedHeaders();
            $disposition = $headers->getHeaderBody('Content-Disposition');

            $att = [
                'content' => $attachment->bodyToString(),
                'type' => $headers->get('Content-Type')->getBody(),
                'name' => ''//$this->getFileNameFromContentType($headers->get('Content-Type')->getBodyAsString()),
            ];

            if ('inline' === $disposition) {
                $payload['message']['images'][] = $att;
            } else {
                $payload['message']['attachments'][] = $att;
            }
        }
        $headersToBypass = ['from', 'to', 'cc', 'bcc', 'subject', 'content-type'];

        foreach ($email->getHeaders()->all() as $name => $header) {
            if (\in_array($name, $headersToBypass, true)) {
                continue;
            }
            $payload['message']['headers'][] = $name . ': ' . $header->toString();
        }
        return $payload;
    }

    protected function getRecipients(Email $email, Envelope $envelope): array
    {
        $recipients = [];
        foreach ($envelope->getRecipients() as $recipient) {
            $type = 'to';
            if (\in_array($recipient, $email->getBcc(), true)) {
                $type = 'bcc';
            } elseif (\in_array($recipient, $email->getCc(), true)) {
                $type = 'cc';
            }
            $recipientPayload = [
                'email' => $recipient->getAddress(),
                'type' => $type,
            ];
            if ('' !== $recipient->getName()) {
                $recipientPayload['name'] = $recipient->getName();
            }
            $recipients[] = $recipientPayload;
        }

        return $recipients;
    }

    private function getFileNameFromContentType(string $contentTypeBody): ?string
    {
        preg_match('/name[^;\n=]*=(([\'"]).*?\2|[^;\n]*)/', $contentTypeBody, $mathes);

        if (false === isset($mathes[0])) {
            return null;
        }

        return  str_replace('name=', '', $mathes[0]);
    }
}

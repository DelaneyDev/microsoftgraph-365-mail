<?php

namespace LLoadout\Microsoftgraph\MailManager;

use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Header\HeaderInterface;
use Illuminate\Support\Facades\Log;
use LLoadout\Microsoftgraph\Traits\Authenticate;
use LLoadout\Microsoftgraph\Traits\Connect;

class MicrosoftGraphTransport extends AbstractTransport
{
    use Authenticate, Connect;

    public function __toString(): string
    {
        return 'microsoftgraph://';
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $email = MessageConverter::toEmail($message);
        $envelope = $envelope ?? Envelope::create($email);

        $html = $email->getHtmlBody();
        $attachments = $this->prepareAttachments($email);

        $payload = [
            'message' => [
                'subject' => $email->getSubject(),
                'body' => [
                    'contentType' => $html ? 'HTML' : 'Text',
                    'content' => $html ?: $email->getTextBody(),
                ],
                'toRecipients' => $this->transformAddresses($envelope->getRecipients()),
                'ccRecipients' => $this->transformAddresses($email->getCc()),
                'bccRecipients' => $this->transformAddresses($email->getBcc()),
                'replyTo' => $this->transformAddresses($email->getReplyTo()),
                'from' => $this->transformAddress($envelope->getSender()),
                'attachments' => $attachments,
            ],
            'saveToSentItems' => false,
        ];

        if ($headers = $this->extractCustomHeaders($email)) {
            $payload['message']['internetMessageHeaders'] = $headers;
        }

        // Actually send via Microsoft Graph API
        $this->post('/me/sendMail', $payload);

        return new SentMessage($message, $envelope);
    }

    protected function prepareAttachments(Email $email): array
    {
        $attachments = [];

        foreach ($email->getAttachments() as $attachment) {
            try {
                $headers = $attachment->getPreparedHeaders();
                $filename = $headers->getHeaderParameter('Content-Disposition', 'filename') ?? 'attachment';
                $content = (string) $attachment->getBody();
                $mime = $attachment->getMediaType() . '/' . $attachment->getMediaSubtype();

                $attachments[] = [
                    '@odata.type' => '#microsoft.graph.fileAttachment',
                    'name' => $filename,
                    'contentType' => $mime,
                    'contentBytes' => base64_encode($content),
                    'contentId' => $filename,
                    'isInline' => $headers->getHeaderBody('Content-Disposition') === 'inline',
                ];
            } catch (\Throwable $e) {
                Log::error("Attachment processing failed: " . $e->getMessage(), [
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        Log::debug("Prepared attachments", ['attachments' => $attachments]);

        return $attachments;
    }

    protected function transformAddresses(array $addresses): array
    {
        return array_map([$this, 'transformAddress'], $addresses);
    }

    protected function transformAddress(Address $address): array
    {
        return [
            'emailAddress' => [
                'address' => $address->getAddress(),
                'name' => $address->getName(),
            ],
        ];
    }

    protected function extractCustomHeaders(Email $email): array
    {
        return collect($email->getHeaders()->all())
            ->filter(fn (HeaderInterface $h) => str_starts_with($h->getName(), 'X-'))
            ->map(fn (HeaderInterface $h) => ['name' => $h->getName(), 'value' => $h->getBodyAsString()])
            ->values()
            ->all();
    }
}

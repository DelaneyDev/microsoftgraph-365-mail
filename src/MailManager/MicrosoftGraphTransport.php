<?php

namespace LLoadout\Microsoftgraph\MailManager;

use Illuminate\Support\Facades\Log;
use LLoadout\Microsoftgraph\Traits\Authenticate;
use LLoadout\Microsoftgraph\Traits\Connect;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Header\HeaderInterface;

class MicrosoftGraphTransport extends AbstractTransport
{
    use Authenticate, Connect;

    public function __toString(): string
    {
        return 'microsoftgraph://';
    }

    public function send(SentMessage $message, Envelope $envelope = null): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $html = $email->getHtmlBody() ?: $email->getTextBody();
        [$attachments, $html] = $this->prepareAttachments($email, $html);

        $payload = [
            'message' => [
                'subject' => $email->getSubject(),
                'body' => [
                    'contentType' => $email->getHtmlBody() ? 'HTML' : 'Text',
                    'content' => $html,
                ],
                'from' => $this->formatRecipients($email->getFrom())[0] ?? null,
                'sender' => $this->formatRecipients($email->getFrom())[0] ?? null,
                'toRecipients' => $this->formatRecipients($email->getTo()),
                'ccRecipients' => $this->formatRecipients($email->getCc()),
                'bccRecipients' => $this->formatRecipients($email->getBcc()),
                'replyTo' => $this->formatRecipients($email->getReplyTo()),
                'attachments' => $attachments,
                'internetMessageHeaders' => $this->getInternetMessageHeaders($email),
            ],
            'saveToSentItems' => config('mail.mailers.microsoftgraph.save_to_sent_items', false),
        ];

        $this->post('/me/sendMail', $payload);
    }

    protected function formatRecipients(array $recipients): array
    {
        return array_map(function (Address $address) {
            return [
                'emailAddress' => [
                    'name' => $address->getName(),
                    'address' => $address->getAddress(),
                ],
            ];
        }, $recipients);
    }

    protected function prepareAttachments(Email $email, ?string $html): array
    {
        $attachments = [];

        foreach ($email->getAttachments() as $attachment) {
            if (! $attachment instanceof DataPart) {
                Log::warning('Skipping unexpected attachment type', ['class' => get_class($attachment)]);
                continue;
            }

            $filename = $attachment->getFilename() ?? 'attachment';
            $raw = (string) $attachment->getBody();
            $mime = $attachment->getMediaType() . '/' . $attachment->getMediaSubtype();

            $attachments[] = [
                '@odata.type'  => '#microsoft.graph.fileAttachment',
                'name'         => $filename,
                'contentId'    => uniqid('', true) . '@lloadout.graph',
                'contentBytes' => base64_encode($raw),
                'contentType'  => $mime,
                'size'         => strlen($raw),
                'isInline'     => true,
            ];
        }

        return [$attachments, $html];
    }

    protected function getInternetMessageHeaders(Email $email): array
    {
        return collect($email->getHeaders()->all())
            ->filter(fn (HeaderInterface $header) => str_starts_with($header->getName(), 'X-'))
            ->map(fn (HeaderInterface $header) => [
                'name' => $header->getName(),
                'value' => $header->getBodyAsString(),
            ])
            ->values()
            ->all();
    }
}

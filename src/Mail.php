<?php

namespace LLoadout\Microsoftgraph;

use Illuminate\Support\Collection;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\HeaderInterface;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\RawMessage;

use Traits\Authenticate;
use Traits\Connect;

class Mail
{
    use Authenticate, Connect;

    /**
     * Send an email using Microsoft Graph API
     *
     * @param RawMessage $message
     * @param Envelope|null $envelope
     * @return void
     */
    public function sendMail(RawMessage $message, ?Envelope $envelope = null): void
    {
        $email = MessageConverter::toEmail($message);
        $envelope ??= new Envelope($email->getFrom()[0]);

        [$attachments, $html] = $this->prepareAttachments($email);

        $payload = [
            'message' => [
                'subject' => $email->getSubject(),
                'body' => [
                    'contentType' => $html === null ? 'Text' : 'HTML',
                    'content' => $html ?: $email->getTextBody(),
                ],
                'toRecipients' => $this->formatAddresses($this->getToRecipients($email, $envelope)),
                'ccRecipients' => $this->formatAddresses(collect($email->getCc())),
                'bccRecipients' => $this->formatAddresses(collect($email->getBcc())),
                'replyTo' => $this->formatAddresses(collect($email->getReplyTo())),
                'sender' => $this->formatAddress($envelope->getSender()),
                'attachments' => $attachments,
            ],
            'saveToSentItems' => config('mail.mailers.microsoftgraph.save_to_sent_items', false),
        ];

        if ($headers = $this->getCustomHeaders($email)) {
            $payload['message']['internetMessageHeaders'] = $headers;
        }

        $this->post('/me/sendMail', $payload);
    }

    protected function prepareAttachments(Email $email): array
    {
        $attachments = [];
        $html = $email->getHtmlBody();

        foreach ($email->getAttachments() as $attachment) {
            $headers = $attachment->getPreparedHeaders();
            $fileName = $headers->getHeaderParameter('Content-Disposition', 'filename');

            $attachments[] = [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => $fileName,
                'contentType' => $attachment->getMediaType() . '/' . $attachment->getMediaSubtype(),
                'contentBytes' => base64_encode((string) $attachment->getBody()),
                'contentId' => $fileName,
                'isInline' => $headers->getHeaderBody('Content-Disposition') === 'inline',
            ];
        }

        return [$attachments, $html];
    }

    protected function getToRecipients(Email $email, Envelope $envelope): Collection
    {
        return collect($envelope->getRecipients())
            ->reject(fn(Address $r) => in_array($r, array_merge($email->getCc(), $email->getBcc()), true));
    }

    protected function formatAddress(Address $address): array
    {
        return [
            'emailAddress' => [
                'address' => $address->getAddress(),
                'name' => $address->getName() ?? '',
            ],
        ];
    }

    protected function formatAddresses(Collection $addresses): array
    {
        return $addresses->map(fn(Address $a) => $this->formatAddress($a))->values()->all();
    }

    protected function getCustomHeaders(Email $email): ?array
    {
        return collect($email->getHeaders()->all())
            ->filter(fn(HeaderInterface $header) => str_starts_with($header->getName(), 'X-'))
            ->map(fn(HeaderInterface $header) => [
                'name' => $header->getName(),
                'value' => $header->getBodyAsString(),
            ])
            ->values()
            ->all() ?: null;
    }
}

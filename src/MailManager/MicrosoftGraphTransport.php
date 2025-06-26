<?php

namespace LLoadout\Microsoftgraph\MailManager;

use Illuminate\Support\Collection;
use LLoadout\Microsoftgraph\Traits\Connect;
use LLoadout\Microsoftgraph\Traits\Authenticate;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\HeaderInterface;
use Symfony\Component\Mime\MessageConverter;

class MicrosoftGraphTransport extends AbstractTransport
{
    use Connect, Authenticate;

    public function __construct(
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null
    ) {
        parent::__construct($dispatcher, $logger);
    }

    public function __toString(): string
    {
        return 'microsoft+graph+api://';
    }

    protected function doSend(SentMessage $message): void
    {
        $email    = MessageConverter::toEmail($message->getOriginalMessage());
        $envelope = $message->getEnvelope();

        $html = $email->getHtmlBody();
        [$attachments, $html] = $this->prepareAttachments($email, $html);

        $payload = [
            'message' => [
                'subject'        => $email->getSubject(),
                'body'           => [
                    'contentType' => $html === null ? 'Text' : 'HTML',
                    'content'     => $html ?: $email->getTextBody(),
                ],
                'toRecipients'   => $this->transformEmailAddresses($this->getRecipients($email, $envelope)),
                'ccRecipients'   => $this->transformEmailAddresses(collect($email->getCc())),
                'bccRecipients'  => $this->transformEmailAddresses(collect($email->getBcc())),
                'replyTo'        => $this->transformEmailAddresses(collect($email->getReplyTo())),
                'sender'         => $this->transformEmailAddress($envelope->getSender()),
                'attachments'    => $attachments,
            ],
            'saveToSentItems' => config('mail.mailers.microsoft-graph.save_to_sent_items', false),
        ];

        if ($headers = $this->getInternetMessageHeaders($email)) {
            $payload['message']['internetMessageHeaders'] = $headers;
        }

        $this->post('/me/sendMail', $payload);
    }

    protected function prepareAttachments(Email $email, ?string $html): array
    {
        $attachments = [];

        foreach ($email->getAttachments() as $attachment) {
            $headers  = $attachment->getPreparedHeaders();
            $filename = $headers->getHeaderParameter('Content-Disposition', 'filename');

            $attachments[] = [
                '@odata.type'  => '#microsoft.graph.fileAttachment',
                'name'         => $filename,
                'contentType'  => $attachment->getMediaType().'/'.$attachment->getMediaSubtype(),
                'contentBytes' => base64_encode($attachment->getBody()),
                'contentId'    => $filename,
                'isInline'     => $headers->getHeaderBody('Content-Disposition') === 'inline',
            ];
        }

        return [$attachments, $html];
    }

    protected function transformEmailAddresses(Collection $recipients): array
    {
        return $recipients
            ->map(fn(Address $r) => $this->transformEmailAddress($r))
            ->toArray();
    }

    protected function transformEmailAddress(Address $address): array
    {
        return [
            'emailAddress' => [
                'address' => $address->getAddress(),
            ],
        ];
    }

    protected function getRecipients(Email $email, Envelope $envelope): Collection
    {
        return collect($envelope->getRecipients())
            ->filter(fn(Address $addr) => ! in_array($addr, array_merge($email->getCc(), $email->getBcc()), true));
    }

    protected function getInternetMessageHeaders(Email $email): ?array
    {
        $headers = collect($email->getHeaders()->all())
            ->filter(fn(HeaderInterface $h) => str_starts_with($h->getName(), 'X-'))
            ->map(fn(HeaderInterface $h) => [
                'name'  => $h->getName(),
                'value' => $h->getBodyAsString(),
            ])
            ->values()
            ->all();

        return $headers ?: null;
    }
}

<?php

namespace LLoadout\Microsoftgraph\MailManager;

use Illuminate\Support\Collection;
use LLoadout\Microsoftgraph\Traits\Connect;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;

class MicrosoftGraphTransport extends AbstractTransport
{
    use Connect;

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

    protected function doSend(SentMessage $sentMessage): void
    {
        $email     = MessageConverter::toEmail($sentMessage->getOriginalMessage());
        $envelope  = $sentMessage->getEnvelope();
        $html      = $email->getHtmlBody();

        // Grab only the explicit attachments from the original message
        /** @var DataPart[] $rawAttachments */
        $rawAttachments = $sentMessage
            ->getOriginalMessage()
            ->getAttachments();

        [$attachments, $html] = $this->prepareAttachmentsFromRaw($rawAttachments, $html);

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

        $this->post('/me/sendMail', $payload);
    }

    /**
     * Prepare Microsoft Graph fileAttachment payloads from the Mailable’s explicit attachments.
     *
     * @param  DataPart[]     $rawAttachments
     * @param  string|null    $html
     * @return array{0: array, 1: string|null}
     */
    protected function prepareAttachmentsFromRaw(array $rawAttachments, ?string $html): array
    {
        $attachments = [];

        foreach ($rawAttachments as $part) {
            // These are only the parts you explicitly attached via Attachment::fromPath()/fromData()
            $filename     = $part->getName() ?? 'attachment';
            $contentBytes = base64_encode($part->getBody());
            $contentType  = "{$part->getMediaType()}/{$part->getMediaSubtype()}";

            $attachments[] = [
                '@odata.type'   => '#microsoft.graph.fileAttachment',
                'name'          => $filename,
                'contentType'   => $contentType,
                'contentBytes'  => $contentBytes,
                'contentId'     => $filename,
                'isInline'      => false,
            ];
        }

        return [$attachments, $html];
    }

    protected function transformEmailAddresses(Collection $recipients): array
    {
        return $recipients->map(fn($r) => $this->transformEmailAddress($r))->toArray();
    }

    protected function transformEmailAddress($address): array
    {
        return ['emailAddress' => ['address' => $address->getAddress()]];
    }

    protected function getRecipients($email, Envelope $envelope): Collection
    {
        return collect($envelope->getRecipients())
            ->filter(fn($addr) => ! in_array($addr, array_merge($email->getCc(), $email->getBcc()), true));
    }
}

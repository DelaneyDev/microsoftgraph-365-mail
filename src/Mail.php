<?php

namespace LLoadout\Microsoftgraph;

use Illuminate\Support\Carbon;
use LLoadout\Microsoftgraph\Traits\Authenticate;
use LLoadout\Microsoftgraph\Traits\Connect;
use Symfony\Component\Mime\Part\DataPart;

class Mail
{
    use Authenticate, Connect;

    /**
     * Send an email using Microsoft Graph API
     *
     * @param mixed $mailable The mailable object containing email details
     */
    public function sendMail($mailable): void
    {
        $this->post('/me/sendMail', $this->getBody($mailable));
    }

    /**
     * Build the Graph API payload
     *
     * @param mixed $mailable
     * @return array
     */
    protected function getBody($mailable): array
    {
        $html    = $mailable->getHtmlBody();
        $from    = $mailable->getFrom();
        $to      = $mailable->getTo();
        $cc      = $mailable->getCc();
        $bcc     = $mailable->getBcc();
        $replyTo = $mailable->getReplyTo();
        $subject = $mailable->getSubject();

        return array_filter([
            'message' => [
                'subject'      => $subject,
                'sender'       => $this->formatRecipients($from)[0] ?? null,
                'from'         => $this->formatRecipients($from)[0] ?? null,
                'replyTo'      => $this->formatRecipients($replyTo),
                'toRecipients' => $this->formatRecipients($to),
                'ccRecipients' => $this->formatRecipients($cc),
                'bccRecipients'=> $this->formatRecipients($bcc),
                'body'         => $this->getContent($html),
                'attachments'  => $this->toAttachmentCollection($mailable->getAttachments()),
            ],
        ]);
    }

    /**
     * Wrap the HTML body for Graph
     */
    private function getContent(?string $html): array
    {
        return [
            'contentType' => 'html',
            'content'     => $html,
        ];
    }

    /**
     * Turn Laravel/Symfony recipients into Graph format
     *
     * @param mixed $recipients
     * @return array
     */
    protected function formatRecipients($recipients): array
    {
        $addresses = [];

        if (! $recipients) {
            return $addresses;
        }

        if (! is_countable($recipients)) {
            $recipients = [$recipients];
        }

        foreach ($recipients as $address) {
            $addresses[] = [
                'emailAddress' => [
                    'name'    => $address->getName(),
                    'address' => $address->getAddress(),
                ],
            ];
        }

        return $addresses;
    }

    /**
     * Convert attachments into Microsoft Graph API format
     *
     * @param iterable<DataPart|array{file:string}> $attachments
     * @return array<int,array{name:string,contentId:string,contentBytes:string,contentType:string,size:int,'@odata.type':string,isInline:bool}>
     */
    protected function toAttachmentCollection(iterable $attachments): array
    {
        $collection = [];

        foreach ($attachments as $item) {
            // New Symfony 6+ DataPart
            if ($item instanceof DataPart) {
                $filename = $item->getFilename() ?: 'attachment';
                $raw      = (string) $item->getBody();
                $mime     = $item->getMediaType() . '/' . $item->getMediaSubtype();
            }
            // Legacy array format: ['file' => '/path/to/file']
            elseif (is_array($item) && isset($item['file'])) {
                $file     = new \SplFileObject($item['file'], 'r');
                $raw      = $file->fread($file->getSize());
                $mime     = mime_content_type($item['file']) ?: 'application/octet-stream';
                $filename = $file->getFilename();
            }
            else {
                // skip anything unexpected
                continue;
            }

            $collection[] = [
                '@odata.type'  => '#microsoft.graph.fileAttachment',
                'name'         => $filename,
                'contentId'    => uniqid('', true) . '@lloadout.graph',
                'contentBytes' => base64_encode($raw),
                'contentType'  => $mime,
                'size'         => strlen($raw),
                'isInline'     => true,
            ];
        }

        return $collection;
    }

    /**
     * Get all mail folders for the authenticated user
     *
     * @return array Mail folders
     */
    public function getMailFolders()
    {
        $url ='/me/mailfolders';

        return $this->get($url);
    }

    /**
     * Get subfolders for a specific mail folder
     *
     * @param string $id Parent folder ID
     * @return array Subfolders
     */
    public function getSubFolders($id)
    {
        $url = '/me/mailfolders/' . $id . '/childFolders';

        return $this->get($url);
    }

    /**
     * Get messages from a specific mail folder
     *
     * @param string $folder Folder name (default: 'inbox')
     * @param bool $isRead Include read messages (default: true)
     * @param int $skip Number of messages to skip (default: 0)
     * @param int $limit Maximum number of messages to return (default: 20)
     * @return array Messages
     */
    public function getMailMessagesFromFolder($folder = 'inbox', $isRead = true, $skip = 0, $limit = 20)
    {
        $url = '/me/mailfolders/' . $folder . '/messages?$select=Id,ReceivedDateTime,Subject,Sender,ToRecipients,From,Body,HasAttachments,InternetMessageHeaders&$skip='.$skip.'&$top='.$limit;
        if (! $isRead) {
            $url .= '&$filter=isRead ne true';
        }

        $response = $this->get($url);

        $mails    = [];
        foreach ($response as $mail) {
            $to = optional(collect($mail['internetMessageHeaders'])->keyBy('name')->get('X-Rcpt-To'))['value'];

            $mails[] = [
                'id'           => $mail['id'],
                'date'         => Carbon::parse($mail['receivedDateTime'])->format('d-m-Y H:i'),
                'subject'      => $mail['subject'],
                'from'         => $mail['from']['emailAddress'],
                'to'           => ! blank($to) ? $to : optional($mail['toRecipients'])[0]['emailAddress']['address'],
                'attachements' => $mail['hasAttachments'],
                'body'         => $mail['body']['content'],
            ];
        }

        return $mails;
    }

    /**
     * Update a message
     *
     * @param string $id Message ID
     * @param array $data Update data
     * @return mixed API response
     */
    public function updateMessage($id, $data)
    {
        $url = '/me/messages/' . $id;

        return $this->patch($url, $data);
    }

    /**
     * Move a message to a different folder
     *
     * @param string $id Message ID
     * @param string $destinationId Destination folder ID
     * @return mixed API response
     */
    public function moveMessage($id, $destinationId)
    {
        $url = '/me/messages/' . $id . '/move';

        return $this->post($url, ['destinationId' => $destinationId]);
    }

    /**
     * Get a specific message by ID
     *
     * @param string $id Message ID
     * @return mixed Message details
     */
    public function getMessage($id)
    {
        $url = config('socialite.office365.api_url') . '/me/messages/' . $id . '?$select=Id,ReceivedDateTime,createdDateTime,Subject,Sender,ToRecipients,From,HasAttachments,InternetMessageHeaders&$top=10&$skip=0';

        return $this->get($url);
    }

    /**
     * Get attachments for a specific message
     *
     * @param string $id Message ID
     * @return mixed Message attachments
     */
    public function getMessageAttachements($id)
    {
        $url = '/me/messages/' . $id . '/attachments';

        return $this->get($url);
    }
}

<?php

namespace Gentor\LaravelElasticEmail;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Illuminate\Mail\Transport\Transport;
use Swift_Mime_SimpleMessage;

/**
 * Class ElasticTransport
 * @package Gentor\LaravelElasticEmail
 */
class ElasticTransport extends Transport
{

    /**
     * Guzzle client instance.
     *
     * @var \GuzzleHttp\ClientInterface
     */
    protected $client;

    /**
     * The Elastic Email API key.
     *
     * @var string
     */
    protected $key;

    /**
     * The Elastic Email username.
     *
     * @var string
     */
    protected $account;

    /**
     * THe Elastic Email API end-point.
     *
     * @var string
     */
    protected $url = 'https://api.elasticemail.com/v2/email/send';

    /**
     * Create a new Elastic Email transport instance.
     *
     * @param \GuzzleHttp\ClientInterface $client
     * @param string $key
     * @param $account
     */
    public function __construct(ClientInterface $client, $key, $account)
    {
        $this->client = $client;
        $this->key = $key;
        $this->account = $account;
    }

    /**
     * @return array
     */
    public function getCredentials()
    {
        return [
            'key' => $this->key,
            'account' => $this->account
        ];
    }

    /**
     * @param array $credentials
     */
    public function setCredentials(array $credentials)
    {
        $this->key = $credentials['key'] ?? $this->key;
        $this->account = $credentials['account'] ?? $this->account;
    }

    /**
     * {@inheritdoc}
     */
    public function send(Swift_Mime_SimpleMessage $message, &$failedRecipients = null)
    {
        $this->beforeSendPerformed($message);

        $data = [
            'api_key' => $this->key,
            'account' => $this->account,
            'msgTo' => $this->getEmailAddresses($message),
            'msgCC' => $this->getEmailAddresses($message, 'getCc'),
            'msgBcc' => $this->getEmailAddresses($message, 'getBcc'),
            'msgFrom' => $this->getFromAddress($message)['email'],
            'msgFromName' => $this->getFromAddress($message)['name'],
            'from' => $this->getFromAddress($message)['email'],
            'fromName' => $this->getFromAddress($message)['name'],
            'replyTo' => $this->getReplyToAddress($message)['email'],
            'replyToName' => $this->getReplyToAddress($message)['name'],
//            'to' => $this->getEmailAddresses($message),
            'subject' => $message->getSubject(),
            'body_html' => $message->getBody(),
            'body_text' => $this->getText($message),
            'postBack' => optional($message->getHeaders()->get('X-Post-Back'))->getFieldBody(),
            'isTransactional' => optional($message->getHeaders()->get('X-Transactional'))->getFieldBody() ?: true,
        ];

        $attachments = $message->getChildren();
        $result = $this->sendRequest($data, $attachments);

        $message->getHeaders()->addTextHeader('X-Msg-ID', $result->messageid);
        $message->getHeaders()->addTextHeader('X-Job-ID', $result->transactionid);

        $this->sendPerformed($message);

        return $this->numberOfRecipients($message);
    }

    /**
     * Get the plain text part.
     *
     * @param \Swift_Mime_Message $message
     * @return text|null
     */
    protected function getText(Swift_Mime_SimpleMessage $message)
    {
        $text = null;

        foreach ($message->getChildren() as $child) {
            if ($child->getContentType() == 'text/plain') {
                $text = $child->getBody();
            }
        }

        return $text;
    }

    /**
     * @param \Swift_Mime_Message $message
     *
     * @return array
     */
    protected function getFromAddress(Swift_Mime_SimpleMessage $message)
    {
        return [
            'email' => array_keys($message->getFrom())[0],
            'name' => array_values($message->getFrom())[0],
        ];
    }

    /**
     * @param \Swift_Mime_Message $message
     *
     * @return array
     */
    protected function getReplyToAddress(Swift_Mime_SimpleMessage $message)
    {
        if (!$message->getReplyTo()) {
            return $this->getFromAddress($message);
        }

        return [
            'email' => array_keys($message->getReplyTo())[0],
            'name' => array_values($message->getReplyTo())[0],
        ];
    }

    /**
     * @param Swift_Mime_SimpleMessage $message
     * @param string $method
     * @return string
     */
    protected function getEmailAddresses(Swift_Mime_SimpleMessage $message, $method = 'getTo')
    {
        $data = call_user_func([$message, $method]);

        if (is_array($data)) {
            return implode(',', array_keys($data));
        }
        return '';
    }

    /**
     * @param array $data
     * @param array $attachments
     * @return mixed
     * @throws \Exception
     */
    protected function sendRequest(array $data, array $attachments = [])
    {
        $options = [];

        if (!empty($attachments)) {
            $options['multipart'] = $this->parseMultipart($attachments, $data);
        } else {
            $options['form_params'] = $data;
        }

        try {
            $response = $this->client->request('POST', $this->url, $options);
            $resp = json_decode($response->getBody()->getContents());
        } catch (\Exception $e) {
            throw $e;
        }

        if (!$resp->success) {
            throw new \Exception($resp->error);
        }

        if (isset($resp->data) && $resp->data) {
            return $resp->data;
        }

        return $resp;
    }

    /**
     * @param array $attachments
     * @param array $params
     * @return array
     */
    private function parseMultipart(array $attachments, array $params): array
    {
        $result = [];

        foreach ($attachments as $key => $attachment) {
            if ($attachment instanceof \Swift_Attachment) {
                $result[] = [
                    'name' => 'file_' . $key,
                    'contents' => $attachment->getBody(),
                    'filename' => $attachment->getFilename()
                ];
            }
        }

        foreach ($params as $key => $param) {
            $result[] = [
                'name' => $key,
                'contents' => $param
            ];
        }

        return $result;
    }

}

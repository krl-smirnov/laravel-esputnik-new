<?php
declare(strict_types=1);
/**
 * This file is part of ESputnik API connector
 *
 * @package ESputnik
 * @license MIT
 * @author Dmytro Kulyk <lnkvisitor.ts@gmail.com>
 */

namespace ESputnik;

use ESputnik\Types;
use ESputnik\Types\ImportSessionStatus;
use ESputnik\Types\InterfaceDto;
use ESputnik\Types\SendMessageResultDto;
use ESputnik\Types\SMSMessage;
use ESputnik\Types\SubscribeContact;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\RequestOptions;

/**
 * Class ESputnik
 *
 * @link http://esputnik.com.ua/api/application.wadl
 */
class ESputnik
{
    /**
     * Global ESputnik instance
     *
     * @var ESputnik
     */
    protected static $_id;
    /**
     * @var int|null
     */
    private $book;

    /**
     * Get global/initialize ESputnik instance
     *
     * @param string   $user
     * @param string   $password
     * @param int|null $book
     *
     * @return ESputnik
     */
    public static function instance(string $user = '', string $password = '', int $book = null): ESputnik
    {
        if (static::$_id === null) {
            static::$_id = new static($user, $password, $book);
        }
        return static::$_id;
    }

    /**
     * cURL handle
     *
     * @var resource
     */
    protected $client;

    /**
     * Last response http code
     *
     * @var int
     */
    protected $httpCode;

    /**
     * Last response body
     *
     * @var string
     */
    protected $httpResponse;

    /**
     * ESputnik constructor
     *
     * @param string   $user
     * @param string   $password
     * @param int|null $book
     */
    public function __construct(string $user, string $password, int $book = null)
    {
        $this->client = new Client([
            'base_uri' => 'https://esputnik.com.ua/api/',
            RequestOptions::HEADERS => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ],
            RequestOptions::AUTH => [$user, $password],
            RequestOptions::CONNECT_TIMEOUT => 2,
        ]);

        $this->book = $book;
    }

    /**
     * Get the version of the protocol.
     *
     * @return Types\Version
     * @throws ESException
     */
    public function getVersion(): Types\Version
    {
        return new Types\Version($this->request('GET', 'v1/version'));
    }

    /**
     * Get a list of directories.
     *
     * @return Types\AddressBook
     * @throws ESException
     */
    public function getAddressBooks(): Types\AddressBook
    {
        $response = $this->request('GET', 'v1/addressbooks');
        return new Types\AddressBook($response['addressBook']);
    }

    /**
     * Get the balance of the organization.
     *
     * @return Types\Balance
     * @throws ESException
     */
    public function getUserOrganisationBalance(): Types\Balance
    {
        $response = $this->request('GET', 'v1/balance');
        return new Types\Balance($response['addressBook']);
    }

    /**
     * Get statistics sms-mailing.
     *
     * @param int $offset
     * @param int $limit
     *
     * @return Types\CallOut[]
     * @throws ESException
     */
    public function getCallOutsSms(int $offset = 0, int $limit = 10): array
    {
        $response = $this->request('GET', 'v1/callouts/sms', [
            'startindex' => $offset + 1,
            'maxrows' => $limit
        ]);

        return \array_map(function ($row) {
            return new Types\CallOut($row);
        }, $response);
    }

    /**
     * Search for contacts.
     *
     * @param int   $offset
     * @param int   $limit
     * @param array $params
     *
     * @return Types\Contacts
     * @throws ESException
     */
    public function searchContacts(int $offset = 0, int $limit = 500, array $params = []): Types\Contacts
    {
        $response = $this->request('GET', 'v1/contacts', \array_merge($params, [
            'startindex' => $offset + 1,
            'maxrows' => $limit
        ]), null, $headers);

        return new Types\Contacts([
            'totalCount' => $headers['TotalCount'],
            'contacts' => $response
        ]);
    }

    /**
     * Add/update contacts.
     *
     * @param Types\ContactsBulkUpdate|\stdClass $contacts
     *
     * @return mixed
     * @throws ESException
     * @todo tested only stdClass
     */
    public function contactsBulkUpdate($contacts)
    {
        return $this->request('POST', 'v1/contacts', [], $contacts);
    }

    /**
     * Get an email to the contact ID.
     *
     * @param int[] $ids
     *
     * @return mixed
     * @throws ESException
     * @todo untested
     */
    public function getContactEmails(array $ids)
    {
        $response = $this->request('GET', 'v1/contacts/email', ['ids' => \implode(',', $ids)]);

        return \array_reduce($response['results'], function ($result, $item) {
            $result[$item['contactId']] = $item['email'];
            return $result;
        }, []);
    }

    /**
     * Get contact.
     *
     * @param int $id
     *
     * @return Types\Contact|null
     * @throws ESException
     */
    public function getContact(int $id): ?Types\Contact
    {
        try {
            return new Types\Contact($this->request('GET', 'v1/contact/' . $id));
        } catch (ESException $exception) {
            if ($exception->getCode() === 404) {
                return null;
            }
            throw $exception;
        }
    }

    /**
     * Add contact.
     *
     * @param Types\Contact $contact
     *
     * @return boolean
     * @throws ESException
     */
    public function addContact(Types\Contact $contact): bool
    {
        if ($this->book !== null && $contact->addressBookId === null) {
            $contact->addressBookId = $this->book;
        }
        $result = $this->request('POST', 'v1/contact', [], $contact);
        if (\is_array($result) && \array_key_exists('id', $result)) {
            $contact->id = $result['id'];
            return true;
        }
        return false;
    }

    /**
     * Update contact.
     *
     * @param Types\Contact $contact
     *
     * @return boolean
     * @throws ESException
     */
    public function updateContact(Types\Contact $contact): bool
    {
        $response = $this->request('PUT', 'v1/contact/' . $contact->id, [], $contact);

        if ($this->httpCode === 404) {
            return false;
        }

        return $response !== false;
    }

    /**
     * Subscribe contact
     *
     * @param SubscribeContact $subscribeContact
     *
     * @return bool
     * @throws ESException
     */
    public function subscribeContact(SubscribeContact $subscribeContact): bool
    {
        if ($this->book !== null && $subscribeContact->contact->addressBookId === null) {
            $subscribeContact->contact->addressBookId = $this->book;
        }
        $response = $this->request('POST', 'v1/contact/subscribe', [], $subscribeContact);

        return $response !== false;
    }

    /**
     * Remove contact.
     *
     * @param int $contact
     *
     * @return boolean
     * @throws ESException
     */
    public function deleteContact(int $contact): bool
    {
        if ($contact instanceof Types\Contact) {
            $contact = $contact->id;
        }

        $response = $this->request('DELETE', 'v1/contact/' . $contact);

        if ($this->httpCode === 404) {
            return false;
        }

        return $response !== false;
    }

    /**
     * Add email-in to the list unsubscribe.
     *
     * @param string[] $emails
     *
     * @return bool
     * @throws ESException
     * @todo untested
     */
    public function addToUnsubscribed(array $emails): bool
    {
        return $this->request('POST', 'v1/emails/unsubscribed/add', [], ['emails' => $emails]) !== false;
    }

    /**
     * Remove email-s unsubscribe from the list.
     *
     * @param string[] $emails
     *
     * @return bool
     * @throws ESException
     * @todo untested
     */
    public function deleteFromUnsubscribed(array $emails): bool
    {
        return $this->request('POST', 'v1/emails/unsubscribed/delete', [], ['emails' => $emails]) !== false;
    }

    /**
     * Add New Event.
     *
     * @param Types\EventDto $event
     *
     * @return boolean
     * @throws ESException
     * @todo untested
     */
    public function registerEvent(Types\EventDto $event): bool
    {
        return $this->request('POST', 'v1/event', [], $event) !== false;
    }

    /**
     * @param int $eventTypeId
     * @param int $start
     * @param int $end
     *
     * @return todo
     * @throws ESException
     * @todo
     */
    public function resendEvents(int $eventTypeId, int $start, int $end)
    {
        $this->request('GET', 'v1/event', array(
            'eventTypeId' => $eventTypeId,
            'start' => $start,
            'end' => $end
        ));
    }

    /**
     * Search groups.
     *
     * @param string $name
     * @param int    $offset
     * @param int    $limit
     *
     * @return Types\Group[]
     * @throws ESException
     */
    public function searchGroups(string $name = '', int $offset = 0, int $limit = 500): array
    {
        $response = $this->request('GET', 'v1/groups', array(
            'startindex' => $offset + 1,
            'maxrows' => $limit,
            'name' => $name
        ));

        return array_map(function ($group) {
            return new Types\Group($group);
        }, $response);
    }

    /**
     * Search from all contacts in the group.
     *
     * @param int|Types/Group $group
     * @param int $offset
     * @param int $limit
     *
     * @return Types\Contacts
     * @throws ESException
     */
    public function getGroupContacts($group, int $offset = 0, int $limit = 500): Types\Contacts
    {
        if ($group instanceof Types\Group) {
            $group = $group->id;
        }

        $response = $this->request('GET', 'v1/group/' . $group . '/contacts', array(
            'startindex' => $offset + 1,
            'maxrows' => $limit
        ), null, $headers);

        return new Types\Contacts(array(
            'totalCount' => $headers['TotalCount'],
            'contacts' => $response
        ));
    }

    /**
     * @param string $from
     * @param string $subject
     * @param string $htmlText
     * @param string $plaintText
     * @param $emails
     * @param array $tags
     * @param int $campaignId
     * @param string $externalRequestId
     * @param bool $skipPersonalisation
     *
     * @return array|SendMessageResultDto
     * @throws ESException
     */
    public function sendEmail(string $from, string $subject, string $htmlText, string $plaintText, $emails, array $tags = [], int $campaignId = null, string $externalRequestId = null, bool $skipPersonalisation = false)
    {
        $emails = (is_array($emails)) ? $emails : [$emails];

        $params = [
            "from" => $from,
            "subject" => $subject,
            "htmlText" => $htmlText,
            "plainText" => $plaintText,
            "emails" => $emails,
            "tags" => $tags,
            "campaignId" => $campaignId,
            "externalRequestId" => $externalRequestId,
            "skipPersonalisation" => $skipPersonalisation,
        ];

        $response = $this->request('POST', 'v1/message/email', [], $params);

        if(!isset($response['results']))
            return new SendMessageResultDto($response);

        $response = $response['results'];

        if(isset($response['locator']))
            return new SendMessageResultDto($response);

        return \array_map(function ($response) {
            return new SendMessageResultDto($response);
        }, $response);
    }

    /**
     * @param array $ids
     *
     * @return array
     * @throws ESException
     */
    public function getInstantEmailStatus(array $ids) : array
    {
        return $this->getInstantMessagesStatus($ids);
    }

    /**
     * Add email-message.
     *
     * @param Types\EmailMessage $message
     *
     * @return boolean
     * @throws ESException
     */
    public function addEmail(Types\EmailMessage $message): bool
    {
        $response = $this->request('POST', 'v1/messages/email', [], $message);

        if (\is_array($response) && \array_key_exists('id', $response)) {
            $message->id = $response['id'];
            return true;
        }

        return false;
    }

    /**
     * Search email-messages on the part of the name or label.
     *
     * @param string $search
     * @param int    $offset
     * @param int    $limit
     *
     * @return Types\EmailMessage[]
     * @throws ESException
     */
    public function searchEmails(string $search = '', int $offset = 0, int $limit = 500)
    {
        $response = $this->request('GET', 'v1/messages/email', [
            'startindex' => $offset + 1,
            'maxrows' => $limit,
            'search' => $search
        ]);

        return \array_map(function ($message) {
            return new Types\EmailMessage($message);
        }, $response);
    }

    /**
     * Get email-message.
     *
     * @param int $id
     *
     * @return Types\EmailMessage|null
     * @throws ESException
     */
    public function getEmail(int $id): ?Types\EmailMessage
    {
        $response = $this->request('GET', 'v1/messages/email/' . $id);

        if ($this->httpCode === 404) {
            return null;
        }

        return new Types\EmailMessage($response);
    }

    /**
     * Update email-message.
     *
     * @param Types\EmailMessage $message
     *
     * @return boolean
     * @throws ESException
     */
    public function updateMessage(Types\EmailMessage $message): bool
    {
        $response = $this->request('PUT', 'v1/messages/email/' . $message->id, [], $message);

        if ($this->httpCode === 404) {
            return false;
        }

        return $response !== false;
    }

    /**
     * Remove email-message.
     *
     * @param int|Types\EmailMessage $message
     *
     * @return boolean
     * @throws ESException
     */
    public function deleteEmail($message): bool
    {
        if ($message instanceof Types\EmailMessage) {
            $message = $message->id;
        }

        $response = $this->request('DELETE', 'v1/messages/email/' . $message);

        if ($this->httpCode === 404) {
            return false;
        }

        return $response !== false;
    }

    /**
     * @param array $ids
     *
     * @return Types\InstantMessageStatusDto[]
     * @throws ESException
     */
    public function getInstantMessagesStatus(array $ids): array
    {
        $response = $this->request('GET', 'v1/message/status', array(
            'ids' => implode(',', $ids),
        ))['results'];

        return array_key_exists('status', $response)
            ? [new Types\InstantMessageStatusDto($response)]
            : array_map(function ($response) {
                new Types\InstantMessageStatusDto($response);
            }, $response);
    }

    /**
     * @param array|string $numbers
     * @param string $text
     * @param string $from
     * @param array $tags
     * @param int|null $groupId
     * @param string|null $externalRequestId
     *
     * @return array|SendMessageResultDto
     * @throws ESException
     */
    public function sendSMS($numbers, $text, $from = 'reklama', array $tags = [], int $groupId = null, string $externalRequestId = null)
    {
        $numbers = (is_array($numbers)) ? $numbers : [$numbers];

        $params = [
            "phoneNumbers" => $numbers,
            "text" => $text,
            "from" => $from,
            "tags" => $tags,
            "groupId" => $groupId,
            "externalRequestId" => $externalRequestId,
        ];

        $response = $this->request('POST', 'v1/message/sms', [], $params);


        if(!isset($response['results']))
            return new SendMessageResultDto($response);

        $response = $response['results'];

        if(isset($response['locator']))
            return new SendMessageResultDto($response);

        return \array_map(function ($response) {
            return new SendMessageResultDto($response);
        }, $response);
    }

    /**
     * @param array $ids
     *
     * @return array
     * @throws ESException
     */
    public function getInstantSmsStatus(array $ids) : array
    {
        return $this->getInstantMessagesStatus($ids);
    }

    /**
     * @param string $search
     * @param int $offset
     * @param int $limit
     *
     * @return array
     * @throws ESException
     */
    public function searchSms(string $search = '', int $offset = 0, int $limit = 500)
    {
        $response = $this->request('GET', 'v1/messages/sms', [
            'startindex' => $offset + 1,
            'maxrows' => $limit,
            'search' => $search
        ]);

        return \array_map(function ($message) {
            return new SMSMessage($message);
        }, $response);
    }

    /**
     * @param string $sessionId
     *
     * @return ImportSessionStatus
     * @throws ESException
     */
    public function getImportSessionStatus(string $sessionId)
    {
        $response = $this->request('GET', 'v1/importstatus/' . $sessionId);

        return new ImportSessionStatus($response);
    }

    /**
     * @return array
     * @throws ESException
     */
    public function getSmsInterfaces()
    {
        $response = $this->request('GET', 'v1/interfaces/sms');

        return \array_map(function ($response) {
            return new InterfaceDto($response);
        }, $response);
    }

    /**
     * @return array
     * @throws ESException
     */
    public function getEmailInterfaces()
    {
        $response = $this->request('GET', 'v1/interfaces/email');

        return \array_map(function ($response) {
            return new InterfaceDto($response);
        }, $response);
    }

    /**
     * @param int $id
     * @param array $params
     * @param string|array $recipients
     * @param int|null $groupId
     * @param int|null $contactId
     * @param string|null $fromName
     * @param int|null $campaignId
     * @param bool $allowUnconfirmed
     * @param string|null $externalRequestId
     *
     * @return array|bool|SendMessageResultDto
     * @throws ESException
     */
    public function sendPreparedMessage(int $id, array $params, $recipients, int $groupId = null, int $contactId = null, string $fromName = null, int $campaignId = null, bool $allowUnconfirmed = false, string $externalRequestId = null)
    {
        $messageParams = [
            "groupId" => $groupId,
            "contactId" => $contactId,
            "params" => $params,
            "recipients" => (is_array($recipients)) ? $recipients : [$recipients],
            "fromName" => $fromName,
            "campaignId" => $campaignId,
            "allowUnconfirmed" => $allowUnconfirmed,
            "externalRequestId" => $externalRequestId,
        ];

        $response = $this->request('POST', "v1/message/{$id}/send", [], $messageParams)['results'];

        if ($this->httpCode === 404) {
            return false;
        }

        return isset($response['locator'])
            ? [new SendMessageResultDto($response)]
            : array_map(function ($response) {
                return new SendMessageResultDto($response);
            }, $response);
    }

    /**
     * @param int                 $id
     * @param Types\MessageParams $messageParams
     *
     * @return Types\SendMessageResultDto[]|null|bool
     * @throws ESException
     */
    public function sendExtendedPreparedMessage(int $id, Types\MessageParams $messageParams): ?array
    {
        $response = $this->request('POST', "v1/message/{$id}/smartsend", [], $messageParams)['results'];

        if ($this->httpCode === 404) {
            return false;
        }

        return isset($response['locator'])
            ? [new Types\SendMessageResultDto($response)]
            : array_map(function ($response) {
                return new Types\SendMessageResultDto($response);
            }, $response);
    }

    /**
     * Get last response http code
     *
     * @return int
     */
    public function getResponseHTTPCode(): int
    {
        return $this->httpCode;
    }

    /**
     * Get last response body
     *
     * @return string
     */
    public function getResponseBody()
    {
        return $this->httpResponse;
    }

    /**
     * Make request to ESputnik API
     *
     * @param       $method
     * @param       $action
     * @param array $query
     * @param mixed $data
     * @param array $headers [optional]
     *
     * @return mixed
     * @throws ESException
     */
    public function request(string $method, string $action, array $query = array(), $data = null, &$headers = null)
    {
        try {
            $response = $this->client->request($method, $action . '?' . http_build_query($query), [
                RequestOptions::JSON => $data
            ]);
            $this->httpCode = $response->getStatusCode();

            $headers = $response->getHeaders();

            return \GuzzleHttp\json_decode($response->getBody()->getContents(), true);
        } catch (ClientException $exception) {
            $response = $exception->getResponse();
            if ($response === null) {
                throw $exception;
            }
            switch ($response->getStatusCode()) {
                case 404:
                    throw new ESException('Not found', 404, $exception);
                case 401:
                    throw new ESException('Unauthorized', 401, $exception);
                case 400:
                    throw new ESException('Request error: ' . $response->getBody(), 400, $exception);
            }
            throw $exception;
        }
    }
}

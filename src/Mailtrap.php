<?php

/*
 * This file is part of the Mailtrap service provider for the Codeception Email Testing Framework.
 * (c) 2015-2016 Eric Martel
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Codeception\Module;

use Codeception\Module;
use GuzzleHttp\Exception\ClientException;

class Mailtrap extends Module
{
  use \Codeception\Email\TestsEmails;

  use \Codeception\Email\EmailServiceProvider;

  /**
   * HTTP Client to interact with Mailtrap
   *
   * @var \GuzzleHttp\Client
   */
  protected $mailtrap;

  /**
   * Raw email header data converted to JSON
   *
   * @var array
   */
  protected $fetchedEmails;

  /**
   * Currently selected set of email headers to work with
   *
   * @var array
   */
  protected $currentInbox;

  /**
   * Starts as the same data as the current inbox, but items are removed as they're used
   *
   * @var array
   */
  protected $unreadInbox;

  /**
   * Contains the currently open email on which test operations are conducted
   *
   * @var mixed
   */
  protected $openedEmail;

  /**
   * Codeception exposed variables
   *
   * @var array
   */
  protected $config = array('api_token', 'inbox_id', 'guzzleRequestOptions', 'deleteEmailsAfterScenario');

  /**
   * Codeception required variables
   *
   * @var array
   */
  protected $requiredFields = array('api_token', 'inbox_id');

  public function _initialize()
  {
    $url = "https://mailtrap.io/";

    $overrideOptions = [];
    if (isset($this->config['guzzleRequestOptions'])) {
      foreach ($this->config['guzzleRequestOptions'] as $option => $value) {
        $overrideOptions[$option] = $value;
      }
    }

    $this->mailtrap = new \GuzzleHttp\Client(array_merge(['base_uri' => $url, 'timeout' => 1.0], $overrideOptions));
  }

  /** 
   * Method executed after each scenario
   */
  public function _after(\Codeception\TestCase $test)
  {
    if(isset($this->config['deleteEmailsAfterScenario']) && $this->config['deleteEmailsAfterScenario'])
    {
      $this->deleteAllEmails();
    }
  }

  protected function sendRequest($verb, $resource)
  {
    return $this->mailtrap->request($verb, $resource, ['headers' => ['Api-Token' => $this->config['api_token']]]);
  }

  /** 
   * Delete All Emails
   *
   * Accessible from tests, deletes all emails 
   */
  public function deleteAllEmails()
  {
    try
    {
      $this->sendRequest('PATCH', "api/v1/inboxes/{$this->config['inbox_id']}/clean");
    }
    catch(Exception $e)
    {
      $this->fail('Exception: ' . $e->getMessage());
    }
  }

  /** 
   * Fetch Emails
   *
   * Accessible from tests, fetches all emails 
   */
  public function fetchEmails()
  {
    $this->fetchedEmails = array();

    try
    {
      $response = $this->sendRequest('GET', "/api/v1/inboxes/{$this->config['inbox_id']}/messages");
      $this->fetchedEmails = json_decode($response->getBody());
    }
    catch(Exception $e)
    {
      $this->fail('Exception: ' . $e->getMessage());
    }

    $this->sortEmails($this->fetchedEmails);

    // by default, work on all emails
    $this->setCurrentInbox($this->fetchedEmails);
  }

  /**
   * Get Headers
   * 
   * Fetch the header for a given email and return it
   * 
   * @param string $id Email identifier
   * @return mixed Header content
   */
  protected function getHeaders($id)
  {
    $response = $this->sendRequest('GET', "/api/v1/inboxes/{$this->config['inbox_id']}/messages/{$id}/mail_headers");
    return json_decode($response->getBody());
  }

  /** 
   * Access Inbox For
   * 
   * Filters emails to only keep those that are received by the provided address
   *
   * @param string $address Recipient address' inbox
   */
  public function accessInboxFor($address)
  {
    $inbox = array();
    $addressPlusDelimiters = $address;
    foreach($this->fetchedEmails as &$email)
    {
      $email->Headers = $this->getHeaders($email->id)->headers;
      if(!isset($email->Headers->bcc))
      {
        if(strpos($email->Headers->to, $addressPlusDelimiters) !== false || strpos($email->Headers->cc, $addressPlusDelimiters) !== false)
        {
          array_push($inbox, $email);
        }
      }
      else if(strpos($email->Headers->bcc, $addressPlusDelimiters) !== false)
      {
        array_push($inbox, $email);
      }
    }
    $this->setCurrentInbox($inbox);
  }

  /** 
   * Open Next Unread Email
   *
   * Pops the most recent unread email and assigns it as the email to conduct tests on
   */
  public function openNextUnreadEmail()
  {
    $this->openedEmail = $this->getMostRecentUnreadEmail();
  }

  /**
   * Get Opened Email
   *
   * Main method called by the tests, providing either the currently open email or the next unread one
   *
   * @param bool $fetchNextUnread Goes to the next Unread Email
   * @return mixed Returns a JSON encoded Email
   */
  protected function getOpenedEmail($fetchNextUnread = FALSE)
  {
    if($fetchNextUnread || $this->openedEmail == NULL)
    {
      $this->openNextUnreadEmail();
    }

    return $this->openedEmail;
  }

  /**
   * Get Most Recent Unread Email
   * 
   * Pops the most recent unread email, fails if the inbox is empty
   * 
   * @return mixed Returns a JSON encoded Email
   */
  protected function getMostRecentUnreadEmail()
  {
    if(empty($this->unreadInbox))
    {
      $this->fail('Unread Inbox is Empty');
    }

    $email = array_shift($this->unreadInbox);
    $content = $this->getFullEmail($email->id);
    $content->Headers = $this->getHeaders($email->id)->headers;
    return $content;
  }

  /**
   * Get Full Email
   * 
   * Returns the full content of an email
   *
   * @param string $id ID from the header
   * @return mixed Returns a JSON encoded Email
   */
  protected function getFullEmail($id)
  {
    try
    {
      $response = $this->sendRequest('GET', "/api/v1/inboxes/{$this->config['inbox_id']}/messages/{$id}");
    }
    catch(Exception $e)
    {
      $this->fail('Exception: ' . $e->getMessage());
    }
    $fullEmail = json_decode($response->getBody());
    return $fullEmail;
  }

  /**
   * Get Email Subject
   *
   * Returns the subject of an email
   *
   * @param mixed $email Email
   * @return string Subject
   */
  protected function getEmailSubject($email)
  {
    return $email->subject;
  }

  /**
   * Get Email Body
   *
   * Returns the body of an email
   *
   * @param mixed $email Email
   * @return string Body
   */
  protected function getEmailBody($email)
  {
    try{
      if(!isset($email->htmlBody))
      {
          try {
            $response = $this->sendRequest('GET', $email->html_source_path);
            $email->htmlBody = $response->getBody()->getContents();
          } catch(ClientException $exc){ }          
      }

      if(isset($email->htmlBody) && strlen($email->htmlBody) > 0)
      {
        return $email->htmlBody;
      }
      
      if(!isset($email->textBody))
      {
        $response = $this->sendRequest('GET', $email->txt_path);
        $email->textBody = $response->getBody()->getContents();
      }
    }
    catch(Exception $e)
    {
      $this->fail('Exception: ' . $e->getMessage());
    }

    return $email->textBody;
  }

  /**
   * Get Email To
   *
   * Returns the string containing the persons included in the To field
   *
   * @param mixed $email Email
   * @return string To
   */
  protected function getEmailTo($email)
  {
    return $email->Headers->to;
  }

  /**
   * Get Email CC
   *
   * Returns the string containing the persons included in the CC field
   *
   * @param mixed $email Email
   * @return string CC
   */
  protected function getEmailCC($email)
  {
    return $email->Headers->cc;
  }

  /**
   * Get Email BCC
   *
   * Returns the string containing the persons included in the BCC field
   *
   * @param mixed $email Email
   * @return string BCC
   */
  protected function getEmailBCC($email)
  {
    if(isset($email->Headers->bcc) && $email->Headers->bcc != NULL)
    {
      return $email->Headers->bcc;
    }
    return "";
  }

  /**
   * Get Email Recipients
   *
   * Returns the string containing all of the recipients, such as To, CC and if provided BCC
   *
   * @param mixed $email Email
   * @return string Recipients
   */
  protected function getEmailRecipients($email)
  {
    $recipients = $email->Headers->to . ' ' .
                  $email->Headers->cc;
    if(isset($email->Headers->bcc) && $email->Headers->bcc != NULL)
    {
      $recipients .= ' ' . $email->Headers->bcc;  
    }

    return $recipients;
  }

  /**
   * Get Email Sender
   *
   * Returns the string containing the sender of the email
   *
   * @param mixed $email Email
   * @return string Sender
   */
  protected function getEmailSender($email)
  {
    return $email->Headers->from;
  }

  /**
   * Get Email Reply To
   *
   * Returns the string containing the address to reply to
   *
   * @param mixed $email Email
   * @return string ReplyTo
   */
  protected function getEmailReplyTo($email)
  {
    return $email->Headers->reply_to;
  }

  /**
   * Get Email Priority
   * 
   * Returns the priority of the email
   * 
   * @param mixed $email Email
   * @return string Priority
   */
  protected function getEmailPriority($email)
  {
    $response = $this->sendRequest('GET', "/api/v1/inboxes/{$this->config['inbox_id']}/messages/{$email->id}/body.raw");
    return $this->textAfterString($response->getBody(), 'X-Priority: ');
  }

  /**
   * Text After String
   * 
   * Returns the text after the given string, if found
   * 
   * @param string $haystack
   * @param string $needle
   * @return string Found string
   */
  protected function textAfterString($haystack, $needle)
  {
    $result = "";
    $needleLength = strlen($needle);

    if($needleLength > 0 && preg_match("#$needle([^\r\n]+)#i", $haystack, $match))
    {
      $result = trim(substr($match[0], -(strlen($match[0]) - $needleLength)));
    }

    return $result;
  }

  /**
   * Set Current Inbox
   *
   * Sets the current inbox to work on, also create a copy of it to handle unread emails
   *
   * @param array $inbox Inbox
   */
  protected function setCurrentInbox($inbox)
  {
    $this->currentInbox = $inbox;
    $this->unreadInbox = $inbox;
  }

  /**
   * Get Current Inbox
   *
   * Returns the complete current inbox
   *
   * @return array Current Inbox
   */
  protected function getCurrentInbox()
  {
    return $this->currentInbox;
  }

  /**
   * Get Unread Inbox
   *
   * Returns the inbox containing unread emails
   *
   * @return array Unread Inbox
   */
  protected function getUnreadInbox()
  {
    return $this->unreadInbox;
  }

  /**
   * Sort Emails
   *
   * Sorts the inbox based on the timestamp
   *
   * @param array $inbox Inbox to sort
   */
  protected function sortEmails($inbox)
  {
    usort($inbox, array($this, 'sortEmailsByCreationDatePredicate'));
  }

  /**
   * Get Email To
   *
   * Returns the string containing the persons included in the To field
   *
   * @param mixed $emailA Email
   * @param mixed $emailB Email
   * @return int Which email should go first
   */
  static function sortEmailsByCreationDatePredicate($emailA, $emailB) 
  {
    $sortKeyA = $emailA->sent_at;
    $sortKeyB = $emailB->sent_at;
    return ($sortKeyA > $sortKeyB) ? -1 : 1;
  }
}
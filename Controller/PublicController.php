<?php

namespace MauticPlugin\MauticAWSBundle\Controller;

use Doctrine\Persistence\ManagerRegistry;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use Mautic\CoreBundle\Controller\FormController as CommonFormController;
use Mautic\CoreBundle\Factory\MauticFactory;
use Mautic\CoreBundle\Factory\ModelFactory;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Service\FlashBag;
use Mautic\CoreBundle\Translation\Translator;
use Mautic\EmailBundle\Model\TransportCallback;
use Mautic\FormBundle\Helper\FormFieldHelper;
use Mautic\LeadBundle\Entity\DoNotContact;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PublicController extends CommonFormController
{
    public function __construct(
        FormFactoryInterface        $formFactory,
        FormFieldHelper             $fieldHelper,
        ManagerRegistry             $managerRegistry,
        MauticFactory               $factory,
        ModelFactory                $modelFactory,
        UserHelper                  $userHelper,
        CoreParametersHelper        $coreParametersHelper,
        EventDispatcherInterface    $dispatcher,
        Translator                  $translator,
        FlashBag                    $flashBag,
        RequestStack                $requestStack,
        CorePermissions             $security,
        protected LoggerInterface   $logger,
        protected Client            $httpClient,
        protected TransportCallback $transportCallback
    )
    {
        parent::__construct($formFactory, $fieldHelper, $managerRegistry, $factory, $modelFactory, $userHelper, $coreParametersHelper, $dispatcher, $translator, $flashBag, $requestStack, $security);
    }

    /**
     * Handles mailer transport webhook post.
     *
     * @param $transport
     *
     * @return Response
     */
    public function mailerCallbackAction(Request $request, $transport)
    {
        $this->processCallbackRequest($request);

        return new Response('success');
    }

    /**
     * Handle bounces & complaints from Amazon.
     *
     * @return array
     */
    public function processCallbackRequest(Request $request): void
    {
        $this->logger->debug('Receiving webhook from Amazon');

        $payload = json_decode($request->getContent(), true);

        if (0 !== json_last_error()) {
            throw new HttpException(400, 'AmazonCallback: Invalid JSON Payload');
        }

        if (!isset($payload['Type']) && !isset($payload['eventType'])) {
            throw new HttpException(400, "Key 'Type' not found in payload ");
        }

        // determine correct key for message type (global or via ConfigurationSet)
        $type = (array_key_exists('Type', $payload) ? $payload['Type'] : $payload['eventType']);

        $this->processJsonPayload($payload, $type);
    }

    /**
     * Process json request from Amazon SES.
     *
     * http://docs.aws.amazon.com/ses/latest/DeveloperGuide/best-practices-bounces-complaints.html
     *
     * @param array $payload from Amazon SES
     */
    public function processJsonPayload(array $payload, $type): void
    {
        switch ($type) {
            case 'SubscriptionConfirmation':
                // Confirm Amazon SNS subscription by calling back the SubscribeURL from the playload
                try {
                    $response = $this->httpClient->get($payload['SubscribeURL']);
                    if (200 == $response->getStatusCode()) {
                        $this->logger->info('Callback to SubscribeURL from Amazon SNS successfully');
                        break;
                    }

                    $reason = 'HTTP Code ' . $response->getStatusCode() . ', ' . $response->getBody();
                } catch (TransferException $e) {
                    $reason = $e->getMessage();
                }

                $this->logger->error('Callback to SubscribeURL from Amazon SNS failed, reason: ' . $reason);
                break;
            case 'Notification':
                $message = json_decode($payload['Message'], true);

                $this->processJsonPayload($message, $message['notificationType']);
                break;
            case 'Complaint':
                foreach ($payload['complaint']['complainedRecipients'] as $complainedRecipient) {
                    $reason = null;
                    if (isset($payload['complaint']['complaintFeedbackType'])) {
                        // http://docs.aws.amazon.com/ses/latest/DeveloperGuide/notification-contents.html#complaint-object
                        switch ($payload['complaint']['complaintFeedbackType']) {
                            case 'abuse':
                                $reason = $this->translator->trans('mautic.email.complaint.reason.abuse');
                                break;
                            case 'fraud':
                                $reason = $this->translator->trans('mautic.email.complaint.reason.fraud');
                                break;
                            case 'virus':
                                $reason = $this->translator->trans('mautic.email.complaint.reason.virus');
                                break;
                        }
                    }

                    if (null == $reason) {
                        $reason = $this->translator->trans('mautic.email.complaint.reason.unknown');
                    }

                    $this->transportCallback->addFailureByAddress($complainedRecipient['emailAddress'], $reason, DoNotContact::UNSUBSCRIBED);

                    $this->logger->debug("Unsubscribe email '" . $complainedRecipient['emailAddress'] . "'");
                }

                break;
            case 'Bounce':
                if ('Permanent' == $payload['bounce']['bounceType']) {
                    $emailId = null;

                    if (isset($payload['mail']['headers'])) {
                        foreach ($payload['mail']['headers'] as $header) {
                            if ('X-EMAIL-ID' === $header['name']) {
                                $emailId = $header['value'];
                            }
                        }
                    }

                    // Get bounced recipients in an array
                    $bouncedRecipients = $payload['bounce']['bouncedRecipients'];
                    foreach ($bouncedRecipients as $bouncedRecipient) {
                        $bounceCode = array_key_exists('diagnosticCode', $bouncedRecipient) ? $bouncedRecipient['diagnosticCode'] : 'unknown';
                        $this->transportCallback->addFailureByAddress($bouncedRecipient['emailAddress'], $bounceCode, DoNotContact::BOUNCED, $emailId);
                        $this->logger->debug("Mark email '" . $bouncedRecipient['emailAddress'] . "' as bounced, reason: " . $bounceCode);
                    }
                }
                break;
            default:
                $this->logger->warning("Received SES webhook of type '$payload[Type]' but couldn't understand payload");
                $this->logger->debug('SES webhook payload: ' . json_encode($payload));
                break;
        }
    }
}
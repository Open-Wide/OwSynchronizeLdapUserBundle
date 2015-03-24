<?php

namespace Ow\SynchronizeLdapUserBundle\EventListener;

use eZ\Publish\API\Repository\UserService;
use eZ\Publish\Core\MVC\Symfony\Event\InteractiveLoginEvent;
use eZ\Publish\Core\MVC\Symfony\MVCEvents;
use IMAG\LdapBundle\Event\LdapUserEvent;
use IMAG\LdapBundle\Event\LdapEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Ow\SynchronizeLdapUserBundle\Helper\UserHelper;
use Monolog\Logger;


/**
 * Performs logic before the user is found to LDAP
 */
class LdapSecuritySubscriber implements EventSubscriberInterface {

    /**
     * @var $userService \eZ\Publish\Core\Repository\UserService
     */
    private $userService;

    /**
     * @var $userHelper \Ow\SynchronizeLdapUserBundle\Helper\UserHelper
     */
    private $userHelper;
    private $username;
    private $logger;
    private $synchronize;
    private $defaultUser;

    public function __construct(UserService $userService, UserHelper $userHelper, $synchronize,$defaultUser, Logger $logger) {
        $this->userService = $userService;
        $this->userHelper = $userHelper;
        $this->synchronize = $synchronize;
        $this->defaultUser = $defaultUser;
        $this->logger = $logger;
    }

    public static function getSubscribedEvents() {
        return array(
            LdapEvents::POST_BIND => 'onPostBind',
            MVCEvents::INTERACTIVE_LOGIN => 'onInteractiveLogin'
        );
    }

    /**
     * This event is triggered when a non EZ user is authenticated
     * I send username in ez security context
     * @param InteractiveLoginEvent $event
     */
    public function onInteractiveLogin(InteractiveLoginEvent $event) {
        if ($this->getUsername() != "") {
            $this->info("LdapSecuritySubscriber event onInteractiveLogin : ".$this->getUsername()); 
            
            if($this->synchronize){
                if($this->userHelper->synchronizeUserAndGroup($this->getUsername())){
                    $event->setApiUser($this->userService->loadUserByLogin($this->getUsername()));
                }
            }else{
                if(isset($this->defaultUser) && !empty($this->defaultUser)){
                    $event->setApiUser($this->userService->loadUserByLogin($this->defaultUser));
                }else{
                    $event->setApiUser($this->userService->loadUserByLogin($this->getUsername()));
                }
            }
        }
    }

    /**
     * This event is triggered when an LDAP user is authenticated
     * @param \IMAG\LdapBundle\Event\LdapUserEvent $event
     */
    public function onPostBind(LdapUserEvent $event) {
        /* @var $user \IMAG\LdapBundle\User\LdapUser */
        $user = $event->getUser();
        if ($user->getUsername() != "") {
            $this->info("SYNCHRONIZE :: LdapSecuritySubscriber event onPostBind : ".$user->getUsername()); 
            $this->setUsername($user->getUsername());
        }
    }

    /**
     * 
     * @return type
     */
    public function getUsername() {
        return $this->username;
    }

    /**
     * 
     * @param type $username
     */
    public function setUsername($username) {
        $this->username = $username;
    }

    /**
     * Log un message de type INFO
     * @param type $message
     */
    private function info($message) {
        if ($this->logger) {
            $this->logger->info($message);
        }
    }
    /**
     * Log un message de type ERREUR
     * @param type $message
     */
    private function err($message) {
        if ($this->logger) {
            $this->logger->err($message);
        }
    }
}

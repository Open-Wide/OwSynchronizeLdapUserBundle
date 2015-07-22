<?php

namespace OpenWide\SynchronizeLdapUserBundle\EventListener;

use eZ\Publish\API\Repository\UserService;
use eZ\Publish\Core\MVC\Symfony\Event\InteractiveLoginEvent;
use eZ\Publish\Core\MVC\Symfony\MVCEvents;
use IMAG\LdapBundle\Event\LdapUserEvent;
use IMAG\LdapBundle\Event\LdapEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use OpenWide\SynchronizeLdapUserBundle\Helper\UserHelper;
use Monolog\Logger;
use Symfony\Component\Security\Core\Exception\DisabledException;
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
    private $logger;
    private $synchronize;
    private $defaultUser;
    private $strtolower;
    

    public function __construct(UserService $userService, $userHelper, $synchronize, $defaultUser, $strtolower, Logger $logger) {
        $this->userService = $userService;
        $this->userHelper = $userHelper;
        $this->synchronize = $synchronize;
        $this->defaultUser = $defaultUser;
        $this->strtolower = $strtolower;
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
        /* @var $UsernamePasswordToken Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken */
        $UsernamePasswordToken = $event->getAuthenticationToken();
        $username = $UsernamePasswordToken->getUsername();
        
        // On test si un pasword existe en POST
        // @ voir comment récupérer proprement le password
        if(isset($_POST['_password']) && !empty($_POST['_password'])){
            $password = $_POST['_password'];
        }else{
            $password = null;
        }        
        
        if ($username != "") {
            $this->info("LdapSecuritySubscriber event onInteractiveLogin : " . $username." ".  strtolower($username));
            
            if($this->strtolower){
                // On force le username en minuscule
                $username = strtolower($username);
            }

            if ($this->synchronize) {
                if ($this->userHelper->synchronizeUserAndGroup($username,$password)) {
                    $event->setApiUser($this->userService->loadUserByLogin($username));
                } else {
                    throw new DisabledException();
                }
            } else {
                if (isset($this->defaultUser) && !empty($this->defaultUser)) {
                    $event->setApiUser($this->userService->loadUserByLogin($this->defaultUser));
                } else {
                    $event->setApiUser($this->userService->loadUserByLogin($username));
                }
            }
        } else {
            throw new DisabledException();
        }
    }

    /**
     * This event is triggered when an LDAP user is authenticated
     * @param \IMAG\LdapBundle\Event\LdapUserEvent $event
     */
    public function onPostBind(LdapUserEvent $event) {
        /* @var $user \IMAG\LdapBundle\User\LdapUser */
        $user = $event->getUser();
        $this->info("SYNCHRONIZE :: LdapSecuritySubscriber event onPostBind : " . $user->getUsername());
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

<?php

namespace Ow\SynchronizeLdapUserBundle\Helper;

use eZUser;
use eZ\Publish\API\Repository\Repository;
use eZ\Publish\API\Repository\Values\Content\Query;
use eZ\Publish\API\Repository\Values\Content\Query\Criterion;
use eZ\Publish\API\Repository\Values\User\UserUpdateStruct;
use eZ\Publish\Core\Repository\UserService;
use Monolog\Logger;
use IMAG\LdapBundle\Manager\LdapConnection;
use Exception;

class UserHelper {

    /**
     * @var \eZ\Publish\API\Repository\Repository
     */
    private $repository;
    private $kernel;

    /**
     * @var $userService \eZ\Publish\Core\Repository\UserService
     */
    private $userService;

    /**
     * @var $ldapService \IMAG\LdapBundle\Manager\LdapConnection
     */
    private $ldapService;
    private $logger;
    private $groupLdapLocationId;
    private $groupLdapContentId;
    private $password;
    private $fieldsUserLdap;
    private $fieldsUserEz;
    private $fieldsGroupLdap;
    private $fieldsGroupEz;
    private $mode;
    private $baseDn;
    private $filterUser;
    private $filterGroup;
    private $verbose;
    private $adminId;
    private $infoUserLdap;
    private $infoGroupLdap;
    private $infoGroupLdapWithoutArray;
    private $APIuser;
    private $groupEz;
    private $groupEzName;
    private $posEz;

    public function __construct(Repository $repository, $kernel, UserService $userService, LdapConnection $ldapService, Logger $logger, $groupLdapLocationId, $groupLdapContentId, $password, $fieldsUserLdap, $fieldsUserEz, $fieldsGroupLdap, $fieldsGroupEz, $mode, $baseDn, $filterUser, $filterGroup, $verbose, $adminId) {
        $this->repository = $repository;
        $this->kernel = $kernel;
        $this->userService = $userService;
        $this->ldapService = $ldapService;
        $this->logger = $logger;
        $this->groupLdapLocationId = $groupLdapLocationId;
        $this->groupLdapContentId = $groupLdapContentId;
        $this->password = $password;
        $this->fieldsUserLdap = $fieldsUserLdap;
        $this->fieldsUserEz = $fieldsUserEz;
        $this->fieldsGroupLdap = $fieldsGroupLdap;
        $this->fieldsGroupEz = $fieldsGroupEz;
        $this->mode = $mode;
        $this->baseDn = $baseDn;
        $this->filterUser = $filterUser;
        $this->filterGroup = $filterGroup;
        $this->verbose = $verbose;
        $this->adminId = $adminId;
    }

    /**
     * Synchonize user data between the LDAP and EZ base
     * @param type $username
     * @return type
     */
    public function synchronizeUserAndGroup($username) {

        try {
            $userAdmin = $this->userService->loadUser($this->adminId);
            $this->repository->setCurrentUser($userAdmin);

            $this->searchInfo($username);

            $parentGroup = $this->userService->loadUserGroup($this->groupLdapContentId);

            if (!($user = $this->findUserByDn($this->infoUserLdap['dn']))) {
                $this->APIuser = $this->newUser(array($parentGroup), $username, $this->password, "fre-FR", $this->infoUserLdap);
                $this->info("Add user EZ " . $username);
            } else {
                if ($this->mode == "update") {
                    $this->APIuser = $this->updateUser($user->id, $this->infoUserLdap);
                    $this->info("Update user EZ " . $username);
                }
                $this->APIuser = $this->userService->loadUser($user->id);
            }

            $this->findGroupEz();
            $this->addGroups($this->groupEzName, $this->infoGroupLdap);
            $this->findGroupEz();
            $this->findMultiPositionEz($this->APIuser);
            $this->addMissingPositions($this->infoGroupLdapWithoutArray, $this->posEzName);
            $this->deleteTooPosition($this->posEzName, $this->infoGroupLdapWithoutArray);

            return true;
        } catch (Exception $e) {
            $this->err("synchronizeUserAndGroup: " . $e->getMessage());
            return false;
        }

        return false;
    }

    /**
     * Seeking a user's info in ldap
     * @param type $username
     * @return boolean
     */
    private function searchInfo($username) {
        try {
            $this->infoUserLdap = $this->searchInfoUser($username);
            $this->infoGroupLdap = $this->searchGroupUser($username);
            $this->infoGroupLdapWithoutArray = $this->getInfoGroupWithoutArray($this->infoGroupLdap);
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Seeking a user's info in ldap
     * @param type $username
     * @return boolean
     */
    private function searchInfoUser($username) {

        try {
            $ldapInfoUser = $this->ldapService->search(array(
                'base_dn' => $this->baseDn,
                'filter' => $this->getFilterUser($username),
            ));
        } catch (Exception $e) {
            $this->err("searchInfoUser: " . $e->getMessage());
            throw $e;
        }

        if ($ldapInfoUser['count'] == 0) {
            throw new Exception('No LDAP account matching the search');
        }
        if ($ldapInfoUser['count'] > 1) {
            throw new Exception('More than one LDAP account match the search');
        }
        $this->info("Authentification de " . $username);
        return $this->getInfoUser($ldapInfoUser);
    }

    /**
     * Seeking groups of a user in ldap
     * @param type $username
     * @return boolean
     */
    private function searchGroupUser($username) {
        try {
            $ldapInfoGroup = $this->ldapService->search(array(
                'base_dn' => $this->baseDn,
                'filter' => $this->getFilterGroup($username),
            ));

            return $this->getInfoGroup($ldapInfoGroup);
        } catch (Exception $e) {
            $this->err("searchGroupUser: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Formating info user ldap
     * @param type $infoUserLdap
     * @return type
     */
    private function getInfoUser($infoUserLdap) {
        $liste = array();
        for ($i = 0; $i < $infoUserLdap['count']; $i++) {
            foreach ($this->fieldsUserLdap as $field) {
                $liste[$field] = $this->getFieldLdap($infoUserLdap, $i, $field);
            }
        }
        return $liste;
    }

    /**
     * Formating info group ldap
     * @param type $infoGroupLdap
     * @return type
     */
    public function getInfoGroup($infoGroupLdap) {
        $fields = array_unique($this->fieldsGroupLdap);
        $liste = array();
        for ($i = 0; $i < $infoGroupLdap['count']; $i++) {
            foreach ($fields as $field) {
                $liste[$i][$field] = $this->getFieldLdap($infoGroupLdap, $i, $field);
            }
        }
        return $liste;
    }

    /**
     * Formating info group ldap
     * @param type $infoGroup
     * @return type
     */
    private function getInfoGroupWithoutArray($infoGroup) {
        $liste = array();
        foreach ($infoGroup as $group) {
            $liste[] = $group['ou'];
        }
        return $liste;
    }

    /**
     * Formating info ldap
     * @param type $infoLdap
     * @param type $index
     * @param type $field
     * @return string
     */
    private function getFieldLdap($infoLdap, $index, $field) {
        if (isset($infoLdap[$index][$field])) {
            if ($field == "dn") {
                return $infoLdap[$index][$field];
            } else {
                return $infoLdap[$index][$field][0];
            }
        } else {
            return "";
        }
    }

    /**
     * Return true if user ez math width dn
     * @param type $dn
     * @return boolean
     */
    private function findUserByDn($dn) {

        if (empty($this->groupLdapLocationId)) {
            throw new Exception('The location of the parent group is not defined.');
        }
        if (empty($dn)) {
            throw new Exception('The dn is not defined.');
        }

        $criteria = array(
            new Criterion\ParentLocationId($this->groupLdapLocationId),
            new Criterion\ContentTypeIdentifier(array('user')),
            new Criterion\Visibility(Criterion\Visibility::VISIBLE),
            new Criterion\Field('dn', Criterion\Operator::EQ, $dn),
        );

        $query = new Query();
        $query->filter = new Criterion\LogicalAnd($criteria);
        $searchResult = $this->repository->getSearchService()->findContent($query);

        if (count($searchResult->searchHits) == 1) {
            return $searchResult->searchHits[0]->valueObject;
        } else {
            return false;
        }
    }

    /**
     * Return true if user match width login
     * @param type $login
     * @return boolean
     */
    private function findUserBylogin($login) {
        $legacyKernelClosure = $this->kernel;
        $loginExist = $legacyKernelClosure()->runCallback(
                function () use ( $login ) {
            return eZUser::fetchByName($login);
        }
        );

        if ($loginExist instanceof eZUser) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Add a new user in ez base
     * @param type $parentContentIds
     * @param type $userName
     * @param type $password
     * @param type $mainLanguageCode
     * @param type $fields
     * @return type
     */
    private function newUser($parentContentIds = array(), $userName, $password = "", $mainLanguageCode = "fre-FR", $fields) {
        try {
            $newUserCreateStruct = $this->userService->newUserCreateStruct($userName, $fields['mail'], $password, $mainLanguageCode, $contentType = null);

            foreach ($this->fieldsUserLdap as $key => $value) {
                $newUserCreateStruct->setField($this->fieldsUserEz[$key], $fields[$value]);
            }
            return $this->userService->createUser($newUserCreateStruct, $parentContentIds);
        } catch (Exception $e) {
            $this->err("Unable to create new user : " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update User
     * @param $userId
     * @param type $fields
     * @throws Exception
     */
    private function updateUser($userId, $fields) {
        try {
            $contentInfo = $this->repository->getContentService()->loadContentInfo($userId);
            $contentDraft = $this->repository->getContentService()->createContentDraft($contentInfo);
            $contentUpdateStruct = $this->repository->getContentService()->newContentUpdateStruct();
            $contentUpdateStruct->initialLanguageCode = 'fre-FR';

            foreach ($this->fieldsUserLdap as $key => $value) {
                if (isset($this->fieldsUserEz[$key]) && isset($fields[$value])) {
                    $contentUpdateStruct->setField($this->fieldsUserEz[$key], $fields[$value]);
                }
            }
            $contentDraft = $this->repository->getContentService()->updateContent($contentDraft->versionInfo, $contentUpdateStruct);
            $content = $this->repository->getContentService()->publishVersion( $contentDraft->versionInfo );
        } catch (Exception $e) {
            $this->err("Unable to update user : " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Seeking existing user groups in eZpublish
     * @return type
     */
    private function findGroupEz() {

        if (empty($this->groupLdapLocationId)) {
            throw new Exception('The location of parent group is not defined.');
        }

        $criteria = array(
            new Criterion\ParentLocationId($this->groupLdapLocationId),
            new Criterion\ContentTypeIdentifier(array('user_group')),
            new Criterion\Visibility(Criterion\Visibility::VISIBLE),
        );

        $query = new Query();
        $query->filter = new Criterion\LogicalAnd($criteria);
        $searchResult = $this->repository->getSearchService()->findContent($query);

        $groups = array();
        foreach ($searchResult->searchHits as $content) {
            $groups[] = $content->valueObject->getField('name')->value->text;
        }
        $this->groupEz = $searchResult->searchHits;
        $this->groupEzName = $groups;
    }

    /**
     * Add Ldap group in Ez group with multi locating
     * @param type $groupEz
     * @param type $groupLdap
     */
    private function addGroups($groupEz, $groupLdap) {

        if (!is_array($groupEz)) {
            throw new Exception('Ez groups must be an array.');
        }
        if (!is_array($groupLdap)) {
            throw new Exception('Ldap groups must be an array.');
        }

        try {
            foreach ($groupLdap as $group) {
                if (!in_array($group['ou'], $groupEz)) {
                    $this->info("Creating a new user group : " . $group['ou']);
                    $this->newUserGroup($group);
                } else {
                    $this->info("The " . $group['ou'] . " group already exists.");
                }
            }
        } catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Creating a new user group
     * @param type $parentContentId
     * @param type $userGroupeName
     */
    private function newUserGroup($group, $groupLdapContentId = null) {

        try {
            if (!$groupLdapContentId) {
                $groupLdapContentId = $this->groupLdapContentId;
            }
            // Load the parent group
            $parentUserGroup = $this->userService->loadUserGroup($groupLdapContentId);
            // Instantiate a new group create struct
            $newUserGroupCreateStruct = $this->userService->newUserGroupCreateStruct('fre-FR');

            foreach ($this->fieldsGroupLdap as $key => $value) {
                $newUserGroupCreateStruct->setField($this->fieldsGroupEz[$key], $group[$value]);
            }

            // This call will fail with an "UnauthorizedException"
            $this->userService->createUserGroup($newUserGroupCreateStruct, $parentUserGroup);
        } catch (Exception $e) {
            $this->err("Unable to create the new user group : " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Search user's Location
     * @param type $APIuser
     */
    private function findMultiPositionEz(\eZ\Publish\API\Repository\Values\User\User $APIuser) {

        $posEzs = $this->userService->loadUserGroupsOfUser($APIuser);

        $positions = array();
        $positionsName = array();
        foreach ($posEzs as $content) {
            if ($content->id != $this->groupLdapContentId) {
                $positions[] = $content;
                $positionsName[] = $content->getFieldValue('name')->__toString();
            }
        }
        $this->posEz = $positions;
        $this->posEzName = $positionsName;
    }

    /**
     * Adds the location of the current user in groups
     * @param type $infoGroupLdapWithoutArray
     * @param type $posEzName
     */
    private function addMissingPositions($infoGroupLdapWithoutArray, $posEzName) {
        $positionManquantes = array_diff($infoGroupLdapWithoutArray, $posEzName);
        if (is_array($positionManquantes) && count($positionManquantes) > 0) {
            foreach ($positionManquantes as $positionManquante) {
                foreach ($this->groupEz as $groupEz) {
                    if ($groupEz->valueObject->getField('name')->value->text == $positionManquante) {
                        $userGroup = $this->userService->loadUserGroup($groupEz->valueObject->id);
                        $this->userService->assignUserToUserGroup($this->APIuser, $userGroup);
                    }
                }
            }
        }
    }

    /**
     * Removes the user's current location in groups
     * @param type $posEzName
     * @param type $infoGroupLdapWithoutArray
     */
    private function deleteTooPosition($posEzName, $infoGroupLdapWithoutArray) {
        $positionEnTrops = array_diff($posEzName, $infoGroupLdapWithoutArray);
        if (is_array($positionEnTrops) && count($positionEnTrops) > 0) {
            foreach ($positionEnTrops as $positionEnTrop) {
                foreach ($this->groupEz as $groupEz) {
                    if ($groupEz->valueObject->getField('name')->value->text == $positionEnTrop) {
                        $userGroup = $this->userService->loadUserGroup($groupEz->valueObject->id);
                        $this->userService->unAssignUserFromUserGroup($this->APIuser, $userGroup);
                    }
                }
            }
        }
    }

    /**
     * Return a filter USER
     * @param type $username
     * @return type
     * @throws Exception
     */
    private function getFilterUser($username) {
        if (empty($username)) {
            throw new Exception('You must specify an user to use the filter.');
        }
        if (empty($this->filterUser)) {
            throw new Exception('You must specify a filter.');
        }
        return str_replace("**USERNAME**", $username, $this->filterUser);
    }

    /**
     * Return a filter GROUP
     * @param type $username
     * @return type
     * @throws Exception
     */
    private function getFilterGroup($username) {
        if (empty($username)) {
            throw new Exception('You must specify an user to use the filter.');
        }
        if (empty($this->filterGroup)) {
            throw new Exception('You must specify a filter.');
        }
        return str_replace("**USERNAME**", $username, $this->filterGroup);
    }

    /**
     * Log an info message
     * @param type $message
     */
    public function info($message) {
        if ($this->logger && $this->verbose) {
            $this->logger->info($message);
        }
    }

    /**
     * Log an error message
     * @param type $message
     */
    private function err($message) {
        if ($this->logger) {
            $this->logger->err($message);
        }
    }

    /**
     * Easy debug
     * @param type $var
     */
    private function debug($var) {
        print "<pre>" . print_r($var, true) . "</pre>";
    }

}

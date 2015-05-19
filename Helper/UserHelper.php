<?php

namespace OpenWide\SynchronizeLdapUserBundle\Helper;

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
    protected $repository;
    protected $kernel;

    /**
     * @var $userService \eZ\Publish\Core\Repository\UserService
     */
    protected $userService;

    /**
     * @var $ldapService \IMAG\LdapBundle\Manager\LdapConnection
     */
    protected $ldapService;
    protected $logger;
    protected $groupLdapLocationId;
    protected $groupLdapContentId;
    protected $password;
    protected $fieldsUserLdap;
    protected $fieldsUserEz;
    protected $fieldsGroupLdap;
    protected $fieldsGroupEz;
    protected $mode;
    protected $baseDn;
    protected $filterUser;
    protected $filterAllUser;
    protected $filterMemberOf;
    protected $fieldMemberOf;
    protected $verbose;
    protected $adminId;
    protected $infoUserLdap;
    protected $infoUserLdapOrigin;
    protected $infoGroupLdap;
    protected $infoGroupLdapWithoutArray;
    protected $APIuser;
    protected $groupEz;
    protected $groupEzName;
    protected $posEz;

    public function __construct(Repository $repository, $kernel, UserService $userService, LdapConnection $ldapService, Logger $logger, $groupLdapLocationId, $groupLdapContentId, $password, $fieldsUserLdap, $fieldsUserEz, $fieldsGroupLdap, $fieldsGroupEz, $mode, $baseDn, $filterUser, $filterAllUser, $filterMemberOf, $fieldMemberOf, $verbose, $adminId) {
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
        $this->filterAllUser = $filterAllUser;
        $this->filterMemberOf = $filterMemberOf;
        $this->fieldMemberOf = $fieldMemberOf;
        $this->verbose = $verbose;
        $this->adminId = $adminId;
    }

    /**
     * Synchonize user data between the LDAP and EZ base
     * @param type $username
     * @return type
     */
    public function synchronizeUserAndGroup($username, $password = null) {

        if ($password) {
            $this->password = $password;
        }

        try {
            $userAdmin = $this->userService->loadUser($this->adminId);
            $this->repository->setCurrentUser($userAdmin);

            $this->searchInfo($username);

            $parentGroup = $this->userService->loadUserGroup($this->groupLdapContentId);

            if (!($user = $this->findUserByDn($this->infoUserLdap['distinguishedname']))) {
                $this->APIuser = $this->newUser(array($parentGroup), $username, $this->password, "fre-FR", $this->infoUserLdap);
                $this->info("Add user EZ " . $username);
            } else {
                if ($this->mode == "password") {
                    $this->APIuser = $this->updatePassword($user->id, $username, $this->password);
                    $this->info("Update password EZ " . $username);
                }                
                if ($this->mode == "update") {
                    $this->APIuser = $this->updateUser($user->id, $username, $this->password, $this->infoUserLdap);
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
    protected function searchInfo($username) {
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
    protected function searchInfoUser($username) {

        try {
            $this->infoUserLdapOrigin = $this->ldapService->search(array(
                'base_dn' => $this->baseDn,
                'filter' => $this->getFilterUser($username),
            ));
        } catch (Exception $e) {
            $this->err("searchInfoUser: " . $e->getMessage());
            throw $e;
        }
        //For test if non connexion width ldap 
        //$this->infoUserLdapOrigin = $this->getValueTest($username);

        if ($this->infoUserLdapOrigin['count'] == 0) {
            throw new Exception('No LDAP account matching the search');
        }
        if ($this->infoUserLdapOrigin['count'] > 1) {
            throw new Exception('More than one LDAP account match the search');
        }
        $this->info("Authentification de " . $username);
        return $this->getInfoUser($this->infoUserLdapOrigin);
    }

    /**
     * Seeking groups of a user in ldap
     * @param type $username
     * @return boolean
     */
    protected function searchGroupUser($username) {
        try {
            $groupUser = array();
            if (is_array($this->infoUserLdapOrigin) && isset($this->infoUserLdapOrigin['count']) && isset($this->infoUserLdapOrigin[0][$this->fieldMemberOf])) {
                foreach ($this->infoUserLdapOrigin[0][$this->fieldMemberOf] as $key => $value) {
                    if ($key != "count") {
                        if (strstr($value, $this->filterMemberOf)) {
                            $groupUser[] = array(
                                'name' => substr($value, 3, (strlen($this->filterMemberOf) + 1) * (-1)),
                                'dn' => $value
                            );
                        }
                    }
                }
            }

            if (count($groupUser) == 0) {
                throw new Exception('The user must be linked to a group.');
            }

            return $groupUser;
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
    protected function getInfoUser($infoUserLdap) {
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
     * @param type $infoGroup
     * @return type
     */
    protected function getInfoGroupWithoutArray($infoGroup) {
        $liste = array();
        foreach ($infoGroup as $group) {
            $liste[] = $group['name'];
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
    protected function getFieldLdap($infoLdap, $index, $field) {
        if (isset($infoLdap[$index][$field])) {
            if ($field == "dn") {
                return $infoLdap[$index][$field][0];
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
    protected function findUserByDn($dn) {

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

        if ($searchResult->totalCount == 1) {
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
    protected function findUserBylogin($login) {
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
    protected function newUser($parentContentIds = array(), $userName, $password = "", $mainLanguageCode = "fre-FR", $fields) {
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
    protected function updateUser($userId, $username, $password, $fields) {
        try {
            
            $this->updatePassword($userId, $username, $password);
            
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
            $content = $this->repository->getContentService()->publishVersion($contentDraft->versionInfo);
            
        } catch (Exception $e) {
            $this->err("Unable to update user : " . $e->getMessage());
            throw $e;
        }
    }

    protected function updatePassword($userId,$username, $password) {

        try {
            $legacyKernelClosure = $this->kernel;
            $loginExist = $legacyKernelClosure()->runCallback(
                    function () use ( $userId,$username, $password ) {
                $ezUser = eZUser::fetch($userId);            
                $ezUser->setAttribute('password_hash', md5("$username\n$password"));
                $ezUser->store();
            }
            );
        } catch (Exception $e) {
            $this->err("Unable to update password user : " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Seeking existing user groups in eZpublish
     * @return type
     */
    protected function findGroupEz() {

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
    protected function addGroups($groupEz, $groupLdap) {

        if (!is_array($groupEz)) {
            throw new Exception('Ez groups must be an array.');
        }
        if (!is_array($groupLdap)) {
            throw new Exception('Ldap groups must be an array.');
        }

        try {
            foreach ($groupLdap as $group) {

                if (!in_array($group['name'], $groupEz)) {
                    $this->info("Creating a new user group : " . $group['dn']);
                    $this->newUserGroup($group);
                } else {
                    $this->info("The " . $group['dn'] . " group already exists.");
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
    protected function newUserGroup($group, $groupLdapContentId = null) {

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
    protected function findMultiPositionEz(\eZ\Publish\API\Repository\Values\User\User $APIuser) {

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
    protected function addMissingPositions($infoGroupLdapWithoutArray, $posEzName) {

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
    protected function deleteTooPosition($posEzName, $infoGroupLdapWithoutArray) {
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
    protected function getFilterUser($username) {
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
    protected function getFilterGroup($username) {
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
    protected function info($message) {
        if ($this->logger && $this->verbose) {
            $this->logger->info($message);
        }
    }

    /**
     * Log an error message
     * @param type $message
     */
    protected function err($message) {
        if ($this->logger) {
            $this->logger->err($message);
        }
    }

    /**
     * Easy debug
     * @param type $var
     */
    protected function debug($var) {
        print "<pre>" . print_r($var, true) . "</pre>";
    }

    public function getAllUserLdap() {

        return $this->searchInfoAllUser();
    }

    /**
     * Seeking a user's info in ldap
     * @param type $username
     * @return boolean
     */
    protected function searchInfoAllUser() {

        try {
            $ldapInfoUser = $this->ldapService->search(array(
                'base_dn' => $this->baseDn,
                'filter' => $this->filterAllUser,
            ));
        } catch (Exception $e) {
            $this->err("searchInfoUser: " . $e->getMessage());
            throw $e;
        }

        if ($ldapInfoUser['count'] == 0) {
            throw new Exception('No LDAP account matching the search');
        }
        return $ldapInfoUser;
    }

    /**
     * test value 
     * @return type
     */
    private function getValueTest($username) {
        if ($username == "gauss")
            return array(
                'count' => 1,
                '0' => array(
                    'objectclass' => array(
                        'count' => 4,
                        '0' => 'top',
                        '1' => 'person',
                        '2' => 'organizationalPerson',
                        '3' => 'user',
                    ),
                    '0' => 'objectclass',
                    'cn' => array(
                        'count' => 1,
                        '0' => 'DEDE Jack',
                    ),
                    '1' => 'cn',
                    'sn' => array(
                        'count' => 1,
                        '0' => 'DEDE',
                    ),
                    '2' => 'sn',
                    'postofficebox' => array(
                        'count' => 1,
                        '0' => '22222',
                    ),
                    '3' => 'postofficebox',
                    'givenname' => array(
                        'count' => 1,
                        '0' => 'Jack',
                    ),
                    '4' => 'givenname',
                    'distinguishedname' => array(
                        'count' => 1,
                        '0' => 'CN=DEDE Jack,OU=Utilisateurs,OU=CG85,DC=cg85,DC=fr',
                    ),
                    '5' => 'distinguishedname',
                    'instancetype' => array(
                        'count' => 1,
                        '0' => 4,
                    ),
                    '6' => 'instancetype',
                    'displayname' => array(
                        'count' => 1,
                        '0' => 'DEDE Jack',
                    ),
                    '7' => 'displayname',
                    'memberof' => array(
                        'count' => 3,
                        '0' => 'CN=intranet,OU=Droits INTRANET,OU=Groupes Applications,OU=Groupes,OU=CG85,DC=cg85,DC=fr',
                        '1' => 'CN=intranet.contributeur,OU=Droits INTRANET,OU=Groupes Applications,OU=Groupes,OU=CG85,DC=cg85,DC=fr',
                        '2' => 'CN=intranet.di,OU=Droits INTRANET,OU=Groupes Applications,OU=Groupes,OU=CG85,DC=cg85,DC=fr',
                    ),
                    '8' => 'memberof',
                    'mail' => array(
                        'count' => 1,
                        '0' => 'jack.dede@test.fr',
                    ),
                    '10' => 'mail',
                    'extensionattribute12' => array(
                        'count' => 1,
                        '0' => 'extensionattribute12',
                    ),
                    '11' => 'extensionattribute12',
                    'extensionattribute13' => array(
                        'count' => 1,
                        '0' => 'extensionattribute13',
                    ),
                    '12' => 'extensionattribute13',
                    'extensionattribute14' => array(
                        'count' => 1,
                        '0' => 'extensionattribute14',
                    ),
                    '13' => 'extensionattribute14',
                    'extensionattribute15' => array(
                        'count' => 1,
                        '0' => 'extensionattribute15',
                    ),
                    '14' => 'extensionattribute15',
                    'l' => array(
                        'count' => 1,
                        '0' => 'lyon',
                    ),
                    '15' => 'l',
                    'othertelephone' => array(
                        'count' => 1,
                        '0' => 'othertelephone',
                    ),
                    '16' => 'othertelephone',
                    'physicaldeliveryofficename' => array(
                        'count' => 1,
                        '0' => 'physicaldeliveryofficename',
                    ),
                    '17' => 'physicaldeliveryofficename',
                    'postalcode' => array(
                        'count' => 1,
                        '0' => '69100',
                    ),
                    '18' => 'postalcode',
                    'streetaddress' => array(
                        'count' => 1,
                        '0' => '6 avenue Salvador allede',
                    ),
                    '19' => 'streetaddress',
                    'telephonenumber' => array(
                        'count' => 1,
                        '0' => '7896451565',
                    ),
                    '20' => 'telephonenumber',
                    'title' => array(
                        'count' => 1,
                        '0' => '06774125896',
                    ),
                    '21' => 'title',
                    'samaccountname' => array(
                        'count' => 1,
                        '0' => 'dedejack',
                    ),
                    '22' => 'samaccountname',
                    'employeeid' => array(
                        'count' => 1,
                        '0' => '2001',
                    ),
                    '23' => 'employeeid',
                )
            );
        else
            return array(
                'count' => 1,
                '0' => array(
                    'objectclass' => array(
                        'count' => 4,
                        '0' => 'top',
                        '1' => 'person',
                        '2' => 'organizationalPerson',
                        '3' => 'user',
                    ),
                    '0' => 'objectclass',
                    'cn' => array(
                        'count' => 1,
                        '0' => 'Newton Boby',
                    ),
                    '1' => 'cn',
                    'sn' => array(
                        'count' => 1,
                        '0' => 'Newton',
                    ),
                    '2' => 'sn',
                    'postofficebox' => array(
                        'count' => 1,
                        '0' => '22222',
                    ),
                    '3' => 'postofficebox',
                    'givenname' => array(
                        'count' => 1,
                        '0' => 'Boby',
                    ),
                    '4' => 'givenname',
                    'distinguishedname' => array(
                        'count' => 1,
                        '0' => 'CN=Newton Boby,OU=Utilisateurs,OU=CG85,DC=cg85,DC=fr',
                    ),
                    '5' => 'distinguishedname',
                    'instancetype' => array(
                        'count' => 1,
                        '0' => 4,
                    ),
                    '6' => 'instancetype',
                    'displayname' => array(
                        'count' => 1,
                        '0' => 'Newton Boby',
                    ),
                    '7' => 'displayname',
                    'memberof' => array(
                        'count' => 3,
                        '0' => 'CN=intranet,OU=Droits INTRANET,OU=Groupes Applications,OU=Groupes,OU=CG85,DC=cg85,DC=fr',
                        '1' => 'CN=intranet.testeur,OU=Droits INTRANET,OU=Groupes Applications,OU=Groupes,OU=CG85,DC=cg85,DC=fr',
                        '2' => 'CN=intranet.di,OU=Droits INTRANET,OU=Groupes Applications,OU=Groupes,OU=CG85,DC=cg85,DC=fr',
                    ),
                    '8' => 'memberof',
                    'mail' => array(
                        'count' => 1,
                        '0' => 'boby.newton@test.fr',
                    ),
                    '10' => 'mail',
                    'extensionattribute12' => array(
                        'count' => 1,
                        '0' => 'extensionattribute12',
                    ),
                    '11' => 'extensionattribute12',
                    'extensionAttribute13' => array(
                        'count' => 1,
                        '0' => 'extensionattribute13',
                    ),
                    '12' => 'extensionattribute13',
                    'extensionAttribute14' => array(
                        'count' => 1,
                        '0' => 'extensionattribute14',
                    ),
                    '13' => 'extensionattribute14',
                    'extensionAttribute15' => array(
                        'count' => 1,
                        '0' => 'extensionattribute15',
                    ),
                    '14' => 'extensionattribute15',
                    'l' => array(
                        'count' => 1,
                        '0' => 'lyon',
                    ),
                    '15' => 'l',
                    'othertelephone' => array(
                        'count' => 1,
                        '0' => 'othertelephone',
                    ),
                    '16' => 'othertelephone',
                    'physicaldeliveryofficename' => array(
                        'count' => 1,
                        '0' => 'physicaldeliveryofficename',
                    ),
                    '17' => 'physicaldeliveryofficename',
                    'postalcode' => array(
                        'count' => 1,
                        '0' => '69100',
                    ),
                    '18' => 'postalcode',
                    'streetaddress' => array(
                        'count' => 1,
                        '0' => '6 avenue Salvador allede',
                    ),
                    '19' => 'streetaddress',
                    'telephoneNumber' => array(
                        'count' => 1,
                        '0' => '7896451565',
                    ),
                    '20' => 'telephonenumber',
                    'title' => array(
                        'count' => 1,
                        '0' => '06774125896',
                    ),
                    '21' => 'title',
                    'samaccountname' => array(
                        'count' => 1,
                        '0' => 'newton',
                    ),
                    '22' => 'samaccountname',
                    'employeeid' => array(
                        'count' => 1,
                        '0' => '2004',
                    ),
                    '23' => 'employeeid',
                )
            );
    }

}

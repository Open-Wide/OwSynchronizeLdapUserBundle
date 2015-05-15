====================================
OpenWideSynchronizeLdapUser for eZ Publish 
====================================

.. image:: https://github.com/Open-Wide/OWNewsletter/raw/master/doc/images/Open-Wide_logo.png
    :align: center

:Extension: OpenWideSynchronizeLdapUser v1.0
:Requires: eZ Publish 5.3.x or newer  and  IMAG/LDAP bundle 
:Author: Open Wide http://www.openwide.fr



Presentation
============

This bundle connect ldap user and create user ldap in ezpublish if there is no.

Licence
=======

This eZ Publish extension is provided as is, in GPL v3 (see LICENCE)

What do you need ?
==================

EzPublish 5.3 or 5.4
IMAG/LDAP Bundle
Configure security.yml and config.yml

Configure IMAG/LDAP Bundle
==========================

See https://github.com/BorisMorel/LdapBundle


Get the Bundle
==============

Composer
--------
Add OpenWideSynchronizeLdapUser in your project's `composer.json`

.. code-block:: json

    {
        "require": {
            "open-wide/synchronizeldapuser-bundle": "dev-master"
        }
    }


Enable the Bundle
-----------------

.. code-block:: php

    <?php
    // ezpublish/EzPublishKernel.php

    public function registerBundles()
    {
        $bundles = array(
            // ...
            new OpenWide\SynchronizeLdapUserBundle\OpenWideSynchronizeLdapUserBundle(),
        );
    }



Configure ezpublish class user and user group
---------------------------------------------

You must create fields in your user and user group classes
Exmple : mail, cn, sn, uid ......
Fields declared in config.yml must exist.

Configure user group
--------------------

Create an user group
Exemple UserLDAP
Configure content_id and location_id in parent_group_content_id and parent_group_location_id.
Ldap users will be created in this group.

Configure yml
-------------

First configure security.yml. 

.. code-block:: yml

    security:
        encoders:
            Symfony\Component\Security\Core\User\User: plaintext
            IMAG\LdapBundle\User\LdapUser: plaintext

        role_hierarchy:
            ROLE_USER:          IS_AUTHENTICATED_ANONYMOUSLY
            ROLE_INSCRIT:       ROLE_USER

        providers:
            chain_provider:
                chain: 
                    providers: [ldap,ezpublish]
            ezpublish:
                id: ezpublish.security.user_provider
            ldap:    
                id: imag_ldap.security.user.provider

        firewalls:
            dev:
                pattern: ^/(_(profiler|wdt)|css|images|js)/
                security: false

            ezpublish_setup:
                pattern: ^/ezsetup
                security: false

            ezpublish_rest:
                pattern: ^/api/ezp/v2
                stateless: true
                ezpublish_http_basic:
                    realm: eZ Publish REST API

            ezpublish_front:
                pattern: ^/
                anonymous: ~
                imag_ldap:
                    provider: chain_provider            
                form_login:
                    require_previous_session: false
                    always_use_default_target_path: false
                    default_target_path: /           

    security:
        access_control:

            # Routes exceptions sans accès loggué
            - { path: ^/login$, role: IS_AUTHENTICATED_ANONYMOUSLY }

            # Routes avec accès loggué
            - { path: ^/, role: [ROLE_USER] }


Then configures config.yml.

.. code-block:: php

    openwide_synchronize_ldap_user:
        enabled: true
        synchronize: true
        parent_group_content_id: 223
        parent_group_location_id: 218
        mode: update
        verbose: true
        #you must define at least one field in user and group (example dn) 
        fields:
            user:
                dn: { value: dn }
                first_name: { value: sn}
                last_name: { value: sn}
                mail: { value: mail }
                cn: { value: cn }
                sn: { value: sn }
                uid: { value: uid }
                givenname: { value: givenName }
            group:
                name: { value: ou }
                ou: { value: ou }
                cn: { value: cn }
                dn: { value: dn }
        ldap:
            base_dn: dc=example,dc=com
            filter_user: '(&(objectclass=person)(uid=**USERNAME**))'
            filter_group: '(&(objectclass=groupOfUniqueNames)(uniquemember=uid=**USERNAME**,dc=example,dc=com))'

DOC
===

*enabled*
    active or not the bundle

*synchronize*
    If enabled, the bundle synchronize users
    It copies the users and groups in the EZ base

*parent_group_content_id*
    User group content identifier in which all users will be copied

*parent_group_location_id*
    User group location identifier in which all users will be copied

*mode*
    add: users are created on the first connection
    update: users are created in the first connection and then modified

*verbose*
    If enabled, you write more info in log file

*fields*
    List of fields copied for user and user group
    Syntax:  <Ez Field>: { value: <LDAP field>}

*ldap*
    Configuring ldap search filters


